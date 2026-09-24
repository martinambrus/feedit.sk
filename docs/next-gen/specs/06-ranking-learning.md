# Spec 06: Ranking, lanes and personal learning (`packages/ranker`)

Status: **binding**. **Intent:** turn stored model answers into a per-user probability of "you'll like
this", put each article into a lane, and always be able to say why. Only a confident "no" may hide
anything. Personal learning *refines* the card-based score. It never gates the first useful result.

`packages/ranker` is **pure**: every function takes plain inputs and returns plain outputs. The worker
handler `user.rank` does the I/O (§7). The same functions run inside `apps/eval`.

---

## 1. Inputs to `rankArticle(ctx, item, now)`

```ts
type Strength = 'must' | 'love' | 'like' | 'never';
type Reason = 'clickbait' | 'promo' | 'shallow' | 'seen' | 'off_topic' | 'other';
interface UserRankContext {
  userId: string;
  cards: { cardId: string; title: string; strength: Strength; scopeFeedId?: string;
           interest: string; interestEn?: string }[];                    // texts are needed by BM25 (§9)
  labels: { cardId: string; name: string }[];
  rules: { id: string; kind: RuleKind; value: string; expiresAt?: Date }[];   // non-expired only
  prefs: UserPreferences;                                                  // spec 08 §3.1
  reasonCounts90d: Record<Reason, number>;
  staleDislikes90d: number;        // dislikes (any reason) on items that were stale when rated (§5)
  model?: ActiveModel;                                                     // §8
  subscriptions: { feedId: string; allowDuplicates: boolean }[];
  readClusterIds: Set<string>;                                             // clusters with a member read in the window
  bm25: Bm25Corpus;                                                        // document frequencies over the user's whole window (§9)
}
interface RankItem {
  articleId: string;
  feedIds: string[];               // the article's feeds ∩ the user's subscriptions
  domain: string; author: string | null;
  titleNorm: string; excerptNorm: string; translatedTitleNorm?: string; translatedExcerptNorm?: string;
  firstSeenAt: Date; wordCount: number | null; hasImage: boolean; lang: string;
  clusterId?: string; clusterSize: number;
  pipelineState: string;
  facets?: Record<string, number>;                 // article_facets.features (spec 05 §3.4)
  facetsEngine?: 'typesafe' | 'llm' | 'laya';      // articles.enrich_engine
  cardAnswers: Record<string, { p: number; engine: 'typesafe'|'llm'|'laya'|'prefilter' }>;  // the user's interest AND label cards
  labelIds: string[];                              // labels already assigned (user_article.label_ids)
  translation?: { engine: string; quality: string };
}
interface RankResult {
  lane: 'new'|'for_you'|'maybe'|'everything'|'hidden';
  tier: 1|2|3|4|5 | null; pLike: number | null;
  scoreSource: 'none'|'cards'|'model'|'degraded';
  rulesFired: string[]; explain: Explain; labelSuggestions: string[];
}
```

`Explain` is defined once, as a zod schema, in `packages/shared/src/dto/explain.ts`. The ranker imports
that type, and the API and web client read it (§6.2).

---

## 2. Order of evaluation (normative)

Lane order for "at most" and "at least": `hidden < everything < maybe < for_you`.

```
rankArticle(ctx, item, now):
  1. if a hide rule matches (§3.1)                   → RETURN 'hidden' (fire its code)
  2. if item.pipelineState == 'stale'                → RETURN lane 'new', P null, source 'none'
  3. if a never-card has p ≥ never.hide (§4.2)       → RETURN 'hidden' (fire never:<id>)
  4. base probability P:
       a. active model AND item.facets present AND pipelineState ∉ {'degraded','failed'}
          AND item.facetsEngine ≠ 'llm' AND no card answer has engine 'llm'
                                                     → P = model(x), source 'model' (§8)
       b. else, some positive card of the user is answered → P = cardScore (§4), source 'cards';
          apply quality demotions (§5); remember the deciding card
       c. else, pipelineState ∈ {'degraded','failed'} and the user has positive cards
                                                     → P = bm25P (§9), source 'degraded'
       d. else                                        → RETURN lane 'new', P null, source 'none'
                                                        (label suggestions are still computed)
     Steps 5–7 run only when P is set.
  5. lane = laneFromP(P) (§6.1)
  6. precedence of modifiers, the first that applies wins:
       i.   seen_story: the item's cluster is in readClusterIds → lane = min(lane, 'everything')
       ii.  source 'degraded'                              → lane = 'maybe' (floors are ignored)
       iii. floor: a 'must' card with p ≥ mustFloor, or a boost_feed/boost_domain rule
                                                       → lane = 'for_you' and P = max(P, lanes.forYou)
       iv.  caps (both may apply): a never-card with never.soft ≤ p < never.hide → lane = min(lane, 'maybe');
            the deciding card's answer engine is 'llm' and P < llmForYouMin → lane = min(lane, 'maybe')
  7. tier = tierFromP(P); labelSuggestions (§6.3); explain (§6.2)
```

The "deciding card" is the positive card achieving `cardScore`, stored as `explain.decidingCardId`.
Every rule that changes the outcome appends its code to `rulesFired` (§3.2).

---

## 3. Rules

### 3.1 Semantics

| Kind | `value` | Matches when | Effect |
|---|---|---|---|
| `mute_keyword` | a keyword or phrase | `normalizeText(value)` (same as `title_norm`) occurs as a whole-word sequence in `titleNorm`, `excerptNorm` or `translatedTitleNorm` | hidden |
| `mute_story` | cluster id | `item.clusterId === value` | hidden |
| `block_feed` | feed id | **every** feed in `item.feedIds` (the user's subscribed feeds carrying it) is blocked | hidden |
| `block_domain` | registrable domain | `item.domain === value` | hidden |
| `block_author` | author name | case- and diacritic-insensitive equality | hidden |
| `boost_feed` | feed id | any feed matches | floor `for_you` |
| `boost_domain` | domain | domain matches | floor `for_you` |

Expired rules are excluded when the context is loaded. A mute created from "mute this story for N days"
has `expires_at = now + N days`, with N ∈ {1, 3, 7, 30}.

### 3.2 Rule codes (stable strings, shown by "Why this?")

`mute_keyword:<value>`, `mute_story`, `block_feed`, `block_domain`, `block_author`, `boost_feed`,
`boost_domain`, `never:<cardId>`, `never_soft:<cardId>`, `must:<cardId>`, `demote:clickbait`,
`demote:promotional`, `demote:shallow`, `demote:stale`, `degraded`, `llm_answer`, `seen_story`.

---

## 4. Card score

### 4.1 Positive cards

`w = { must: 1.0, love: 1.0, like: 0.8 }`.

`cardScore = max over positive cards c with an answer of (w[c.strength] × p_c)`.

- A missing answer means unknown and is ignored.
- `prefilter` answers (p = 0) count as known zeros.
- If **no** positive card has an answer yet (answers pending), the lane is `new`.
- If the user has no positive cards at all, the lane is `new` and the UI prompts for interests
  (spec 09 §4).
- Scope: a card with `scopeFeedId` only counts for items carried by that feed.

### 4.2 Anti-interest cards (`strength = 'never'`)

For each never-card with an answer:
- `p ≥ 0.7` → `hidden` with `never:<id>`
- `0.5 ≤ p < 0.7` → cap the lane at `maybe` with `never_soft:<id>`

These apply even when the personal model is active.

---

## 5. Quality demotions (only when `scoreSource = 'cards'`)

| Flag | Triggered when | Driven by dislike reason |
|---|---|---|
| `clickbait` | `facets.clickbait ≥ 0.8` | `clickbait` |
| `promotional` | `facets.promotional ≥ 0.8` | `promo` |
| `shallow` | `facets.depth ≤ 0.25` (depth level ≤ 1) | `shallow` |
| `stale` | `facets.time_sensitive ≥ 0.7` **and** article age > 72 h | — ("auto" turns on when `staleDislikes90d ≥ 3`, i.e. three dislikes, of any reason, on items that met this condition when rated) |

A flag is **active** for the user if `prefs.demote[flag] === 'on'`, or if it is `'auto'` (the default)
and the user has ≥ 3 dislikes with the matching reason in the last 90 days.

Each active and triggered flag multiplies `P` by **0.6** and fires `demote:<flag>`.

---

## 6. Lanes, tiers, explanations, labels

### 6.1 Lanes and tiers

| Lane | Condition |
|---|---|
| `hidden` | a hide rule or `never` hard rule fired |
| `for_you` | `P ≥ 0.65`, or raised by a floor (§2 step 6iii, which also raises `P` to at least 0.65 so the tier matches) |
| `maybe` | `0.35 ≤ P < 0.65`, or capped to `maybe` |
| `everything` | `P < 0.35` |
| `new` | no score yet |

**Tier:** 5 if `P ≥ 0.85`, 4 if `≥ 0.65`, 3 if `≥ 0.45`, 2 if `≥ 0.25`, otherwise 1. `null` when `P` is
null. The web client's tier slider (FeedIt's 1–5) filters `for_you` + `maybe` by minimum tier.

All thresholds come from `RankerConfig` (§11), which gate G1 may override.

### 6.2 Explain (`user_article.explain`, version 1; zod schema in `packages/shared/src/dto/explain.ts`)

```ts
interface Explain {
  v: 1;
  source: 'cards'|'model'|'degraded'|'none';
  p: number | null; lane: Lane; tier: number | null;
  decidingCardId?: string;                         // source 'cards': the card achieving cardScore (spec 08 §5.1 topReason)
  cards: { id: string; title: string; strength: Strength; p: number; engine: string }[];   // the user's cards with answers, p desc, ≤ 10
  facets?: { contentType: { choice: string; p: number }; topic: { l1: string; p: number; l2?: string };
             depth: number; clickbait: number; promotional: number; timeSensitive: number; evergreen: number };
  rules: { code: string; ruleId?: string; cardId?: string; detail?: string }[];   // ids let the UI offer "undo"
  model?: { version: number; top: { feature: string; label: string; contribution: number }[] };   // top 3 by |contribution|
  translation?: { engine: string; quality: string };
  cluster?: { id: string; size: number };
}
```

Labels in `explain` use the English names; the web client localizes the topic ids with `topics.name_sk`.

### 6.3 Label suggestions

For each of the user's labels with an answer `p ≥ 0.8` that is not already in `item.labelIds`, add the
label to `labelSuggestions`. The UI shows them as tappable chips ("tap to keep", as in FeedIt).

---

## 7. The `user.rank` handler

**Versioning:** `score_version = RANKER_VERSION * 10000 + settings['ranker.settings_version']`.
- `RANKER_VERSION` and `scoreVersion(settingsVersion)` are exported by `packages/ranker`. They are
  created in the ranker bootstrap (M2-T10), so both the API (M4) and the rank handler (M5) can use
  them. The constant is bumped whenever the ranking semantics change.
- The API bumps `ranker.settings_version` on every change to `ranker.thresholds` (and to any future
  ranking-relevant key), and then enqueues `user.rank {full: true}` for users active in the last 7 days.
- Inactive users catch up on their next visit: `GET /articles` enqueues a full rank when the user's
  newest `score_version` is outdated.

**Steps:**

1. **Load `UserRankContext`**, including:
   - `readClusterIds`: clusters with any member read by the user in the window
   - `bm25`: document frequencies over **all** window articles, not just the dirty ones (§9)
2. **Dirty set** (SQL, window `RankerConfig.windowDays` = 14, capped at 5,000, newest first). Articles
   from the user's subscriptions with `first_seen_at ≥ now − 14 days`, not archived for the user,
   where any of these holds:
   - no `user_article` row
   - `ua.score_version < current score_version`
   - `ua.scored_at` is older than the newest of `article_facets.updated_at`,
     `card_answers.answered_at` (for the user's cards and labels) and `article_translations.created_at`
   - the article's cluster has a member whose `read_at` for this user is newer than `ua.scored_at`
     (the `seen_story` rule)
   - the payload says `full: true`, which re-ranks the whole window

   Stale articles (`pipeline_state = 'stale'`) are ranked as `new` without a score.
3. **Batch-load** facets, card answers (the user's cards and labels), translations, clusters, the
   user's `label_ids`, the feed ids (intersected with the subscriptions), and the domain (via `tldts`).
4. Run `rankArticle` for each item.
5. **Upsert** the ranking columns of `user_article` in batches of 500:
   `lane, tier, p_like, score_source, rules_fired, explain, label_suggestions, score_version, scored_at`.
   Reader-state columns are never touched.
6. **Weak-translation escalation** (spec 07 §3): for items newly placed in `maybe` whose best
   translation is a tier-1 `weak` one **and** that have no `ollama` translation row yet (a skipped
   attempt also leaves a row), enqueue `article.translate {forceTier2: true}`. This happens once per
   article.

**Enqueued by** (incremental runs are debounced; full runs use their own key, spec 03 §2):

| Event | `full`? |
|---|---|
| match finished; enrich degraded | no |
| a read, mark-read or open on an article that belongs to a cluster | no (the dirty set picks up `seen_story`) |
| card or label added/removed/strength/scope changed | yes |
| rule created/deleted, or expired (hourly `house.expire-rules`) | yes |
| preferences changed (`demote`) | yes |
| `ranker.thresholds` changed | yes (users active in 7 days; the others lazily, as above) |
| new active model | yes |
| subscription added/removed | yes |

---

## 8. Personal model

### 8.1 Features (`FEATURE_SPEC_V1`; its sha is stored in `user_models.feature_spec_sha`)

| Group | Features |
|---|---|
| Cards | `card.<id>` = p (0 if missing) for up to 30 positive cards (ordered by id). `never.<id>` for never-cards. `cardmax` = the §4.1 score |
| Facets | `ct.*` (12), `t1.*` (20), `depth`, `depth_conf`, `clickbait`, `promotional`, `time_sensitive`, `evergreen`, `paywall_teaser`, `tone`, `scope.*` (4) |
| Length | one-hot `len.short/medium/long/very_long/unknown` |
| Freshness | one-hot `age.lt6h/lt24h/lt72h/older`: age at the time of the label for training, now for scoring |
| Language | one-hot `lang.en/sk/cs/other` |
| Other | `has_image`, `cluster_log = ln(1 + clusterSize)` |
| Source | `feed.h<k>`, one-hot with k = murmur3(feedId) mod 32, using the lowest id in `item.feedIds`. `author.h<k>`, one-hot with k = murmur3(`normalizeText(author)`) mod 16 (none if there is no author). murmur3 = **MurmurHash3 x86 32-bit, seed 0, over the UTF-8 bytes** of the decimal id string or the normalized author |

Only answers from the **same engine family** are used. If a card answer or facet came from `llm`, the
item is left out of training and scored with the cards path until Jev re-answers it.

### 8.2 Labels (from `user_article` + `feedback_events`)

| Signal | y | weight |
|---|---|---|
| rating +1 / −1 | 1 / 0 | 1.0 |
| answer to the "Did you like it?" prompt | 1 / 0 | 1.0 |
| bookmarked, not rated | 1 | 0.8 |
| label assigned, not rated | 1 | 0.8 |
| opened with dwell ≥ 30 s, not rated | 1 | 0.3 |
| opened with dwell < 5 s (a bounce), not rated | 0 | 0.2 |
| marked read without opening, not rated (only if `prefs.implicitNegative`) | 0 | 0.1 |

The most recent explicit signal wins per article. Un-rating removes the label. Only items from the last
**180 days** are used.

### 8.3 Training (`trainUserModel(samples, now)`, pure)

- Standardize every feature (mean and std from the training data; features with std = 0 are dropped).
- **L2-regularized logistic regression:** λ = 1.0, intercept not regularized. Fit by Newton–Raphson
  (IRLS), at most 25 iterations, stopping when the loss changes by less than 1e-6. If the Hessian is
  not positive definite, add a 1e-6 ridge.
- **Cross-validation:** stratified k-fold with k = min(5, the minority class count), at least 3.
  Out-of-fold predictions give `cv_auc` and `cv_logloss`, computed on **explicit** labels only (weight
  1.0).
- **Baseline:** `baseline_auc` = the AUC of `cardmax` on the same explicit items.
- **Activation** (the new model becomes `active`) only if **all** of these hold:
  - `n_explicit ≥ 30`, `n_pos ≥ 5`, `n_neg ≥ 5`
  - `cv_auc ≥ 0.60`
  - `cv_auc ≥ baseline_auc − 0.02`

  Otherwise the previous active model stays, if it exists and its `feature_spec_sha` is current.
- **Calibration (Platt):** fit `a`, `b` on the out-of-fold logits with Newton, with a weak prior
  (λ = 0.01 towards a = 1, b = 0). Scoring is `P = σ(a · z + b)` with `z = w · x̃ + w0`.
- **Contributions** for "Why this?": `w_i · x̃_i`; the top 3 by absolute value, mapped to
  human-readable labels (`card.<id>` → the card title, `t1.x` → the topic name, `feed.h*` → "this
  source", `len.*` → "article length", and so on).
- **Retention:** keep the **active** version plus the 3 newest versions per user; delete the rest. The
  active version is never deleted.

### 8.4 When to train (`user.learn {userId}`)

- **Enqueued:**
  - **by the API.** After writing a rating or prompt answer, it counts
    `n = count(*) FROM user_article WHERE user_id = me AND rating IS NOT NULL AND rated_at > coalesce((SELECT max(trained_at) FROM user_models WHERE user_id = me), users.created_at)`,
    and enqueues when `n > 0 AND n % 10 = 0` (debounced, spec 03 §2). Prompt answers are stored as
    ratings (spec 08 §5.3), so they count.
  - **by `house.nightly-learn`** for users with any explicit label newer than their last training.
- **Handler:** build the samples → train → store the version → if it is activated, enqueue
  `user.rank {full: true}` and `user.suggest`.

---

## 9. Degraded ranking (BM25)

Used when the article has no facets or answers because the engine was unavailable, and by the eval as a
baseline (spec 10).

- **Tokenizer:** `normalizeText` (diacritics stripped, lower-case), split on non-alphanumerics, drop
  tokens shorter than 2 chars and stop-words (small built-in EN/SK/CZ lists, ~150 words each).
- **Document:** the title twice, then the excerpt (translated text instead, when a translation exists).
- **Query:** the card's `interest` (or `interest_en`).
- **Corpus statistics:** document frequencies over **all** articles in the user's rank window (14 days
  of their subscriptions). They are computed once per `user.rank` run, so scores don't depend on how
  many items happen to be dirty. IDF uses +0.5 smoothing. `k1 = 1.2`, `b = 0.75`. The eval builds the
  corpus from the rater's assigned articles.
- `s = max over positive cards of BM25(card, doc)`. `P = 1 − exp(−s / 3)`.
- The lane is **always `maybe`**: never hidden and never `for_you`, because keywords are not trusted to
  hide or promote.

---

## 10. Active learning and feedback prompts

- **Maybe lane order:** by `|P − 0.5|` ascending (most uncertain first), then newest first.
- **Calibration round** (onboarding step, and a weekly "Tune your feed" card in the reader):
  - 10 unrated articles from `maybe`, at most 7 days old, at most 3 per feed
  - pick the most uncertain first
  - if `maybe` has fewer than 10, fill from `everything` with the highest P
- **"Did you like it?" prompt** (from FeedIt's todo list), shown when the reader returns to the app
  after opening an article:
  - only if `dwell_ms ≥ 6,000`, the article is unrated, and `feedback_prompted_at` is null
  - whenever `POST /articles/:id/dwell` answers `prompt: true`, it also sets `feedback_prompted_at`, so
    an ignored prompt never returns
  - and either `lane = 'maybe'` or `random() < f`, with f from `prefs.feedbackPrompt`:
    `often` = 1/5, `occasionally` = 1/20, `never` = 0
  - when the preference is `never`, no prompt is shown at all, even for Maybe items

---

## 11. `RankerConfig` (defaults; `settings['ranker.thresholds']` overrides; gate G1 writes it)

```ts
export const DEFAULT_RANKER_CONFIG = {
  lanes: { forYou: 0.65, maybe: 0.35 },
  tiers: [0.25, 0.45, 0.65, 0.85],
  strengthWeights: { must: 1.0, love: 1.0, like: 0.8 },
  never: { hide: 0.7, soft: 0.5 },
  mustFloor: 0.5,
  llmForYouMin: 0.85,
  demotion: { factor: 0.6, clickbait: 0.8, promotional: 0.8, shallowDepth: 0.25,
              staleTimeSensitive: 0.7, staleAgeHours: 72, autoMinDislikes: 3, autoWindowDays: 90 },
  labelSuggest: 0.8,
  windowDays: 14,
  model: { lambda: 1.0, minExplicit: 30, minEachClass: 5, minCvAuc: 0.60, maxBaselineDrop: 0.02,
           retrainEvery: 10, historyDays: 180, keepVersions: 3 },
  bm25: { k1: 1.2, b: 0.75, scale: 3 },
} as const;
```

---

## 12. Tests

**Unit:**
- truth tables for every rule kind, and for never/must/boost interplay
- the lane and tier boundaries
- demotion activation (auto vs on vs off)
- `seen_story`
- monotonicity (property test with `fast-check`): raising a positive card's p never lowers `P` with
  everything else fixed
- logistic regression recovers the signs of known weights on synthetic data and reaches AUC ≥ 0.9
- Platt scaling reduces ECE on synthetic over-confident scores
- the activation rule
- BM25 ordering on a hand-made corpus
- `Explain` snapshots

**Integration:**
- `user.rank` on a seeded DB writes the expected lanes
- idempotency: a second run with nothing dirty writes nothing
- `full` re-rank after a card strength change
