# Spec 06: Ranking, lanes and personal learning (`packages/ranker`)

Status: **binding**. **Intent:** turn stored model answers into a per-user probability of "you'll like
this", put each article into a lane, and always be able to say why. Only a confident "no" may hide
anything. Personal learning *refines* the card-based score. It never gates the first useful result.

`packages/ranker` is **pure**: every function takes plain inputs and returns plain outputs. The worker
handler `user.rank` does the I/O (§7). The same functions run inside `apps/eval`.

---

## 1. Inputs to `rankArticle(ctx, item, now)`

```ts
interface UserRankContext {
  userId: string;
  cards: { cardId: string; title: string; strength: 'must'|'love'|'like'|'never'; scopeFeedId?: string }[];
  labels: { cardId: string; name: string }[];
  rules: { kind: RuleKind; value: string; expiresAt?: Date }[];          // non-expired only
  prefs: UserPreferences;                                                  // spec 08 §5.2
  reasonCounts90d: Record<'clickbait'|'promo'|'shallow'|'seen'|'off_topic'|'other', number>;
  model?: ActiveModel;                                                     // §8
  subscriptions: { feedId: string; allowDuplicates: boolean }[];
  readClusterIds: Set<string>;                                             // clusters with a read member
}
interface RankItem {
  articleId: string; feedIds: string[]; domain: string; author: string | null;
  titleNorm: string; excerptNorm: string; translatedTitleNorm?: string;
  firstSeenAt: Date; wordCount: number | null; hasImage: boolean; lang: string;
  clusterId?: string; clusterSize: number;
  pipelineState: string;
  facets?: Record<string, number>;                 // article_facets.features (spec 05 §3.4)
  cardAnswers: Record<string, { p: number; engine: 'typesafe'|'llm'|'laya'|'prefilter' }>;
  translation?: { engine: string; quality: string };
}
interface RankResult {
  lane: 'new'|'for_you'|'maybe'|'everything'|'hidden';
  tier: 1|2|3|4|5 | null; pLike: number | null;
  scoreSource: 'none'|'cards'|'model'|'degraded';
  rulesFired: string[]; explain: Explain; labelSuggestions: string[];
}
```

---

## 2. Order of evaluation

1. **Hard rules** (§3.1). A hide rule returns `hidden` immediately. Boosts set a floor, applied at step 6.
2. **Base probability `P`**:
   - active personal model → `P = model(x)` (§8), `scoreSource = 'model'`
   - otherwise, cards with answers → `P = cardScore` (§4), `scoreSource = 'cards'`
   - otherwise, the article is degraded and the user has cards → BM25 (§9), `scoreSource = 'degraded'`
   - otherwise → `lane = 'new'`, `P = null`, `scoreSource = 'none'`
3. **Anti-interest cards** (§4.2): hide, or cap at `maybe`.
4. **Quality demotions** (§5). Only when `scoreSource = 'cards'`, because the model learns these itself.
5. **Lane from `P`** (§6.1), then the caps: `never_soft`, degraded → `maybe`, an `llm`-answered deciding
   card → `for_you` only if `P ≥ 0.85`.
6. **Floors:** a `must` card with `p ≥ 0.5`, or a boost rule → at least `for_you`.
7. **Story rule:** if the article's cluster has a member the user has read → at most `everything`,
   with rule `seen_story`.
8. **Tier** from `P` (§6.1). **Label suggestions** (§6.3). **Explain** (§6.2).

Every rule that changes the outcome appends a code to `rulesFired` (§3.2).

---

## 3. Rules

### 3.1 Semantics

| Kind | `value` | Matches when | Effect |
|---|---|---|---|
| `mute_keyword` | a keyword or phrase | `normalizeText(value)` (same as `title_norm`) occurs as a whole-word sequence in `titleNorm`, `excerptNorm` or `translatedTitleNorm` | hidden |
| `mute_story` | cluster id | `item.clusterId === value` | hidden |
| `block_feed` | feed id | every one of `item.feedIds` is blocked by the user | hidden |
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
| `stale` | `facets.time_sensitive ≥ 0.7` **and** article age > 72 h | — (always "auto on" once the user has 3 dislikes of any reason on stale items; otherwise off) |

A flag is **active** for the user if `prefs.demote[flag] === 'on'`, or if it is `'auto'` (the default)
and the user has ≥ 3 dislikes with the matching reason in the last 90 days.

Each active and triggered flag multiplies `P` by **0.6** and fires `demote:<flag>`.

---

## 6. Lanes, tiers, explanations, labels

### 6.1 Lanes and tiers

| Lane | Condition |
|---|---|
| `hidden` | a hide rule or `never` hard rule fired |
| `for_you` | `P ≥ 0.65` (after caps and floors) |
| `maybe` | `0.35 ≤ P < 0.65`, or capped to `maybe` |
| `everything` | `P < 0.35` |
| `new` | no score yet |

**Tier:** 5 if `P ≥ 0.85`, 4 if `≥ 0.65`, 3 if `≥ 0.45`, 2 if `≥ 0.25`, otherwise 1. `null` when `P` is
null. The web client's tier slider (FeedIt's 1–5) filters `for_you` + `maybe` by minimum tier.

All thresholds come from `RankerConfig` (§11), which gate G1 may override.

### 6.2 Explain (`user_article.explain`, version 1)

```ts
interface Explain {
  v: 1;
  source: 'cards'|'model'|'degraded'|'none';
  p: number | null; lane: Lane; tier: number | null;
  cards: { id: string; title: string; strength: Strength; p: number; engine: string }[];   // the user's cards with answers, p desc, ≤ 10
  facets?: { contentType: { choice: string; p: number }; topic: { l1: string; p: number; l2?: string };
             depth: number; clickbait: number; promotional: number; timeSensitive: number; evergreen: number };
  rules: { code: string; detail?: string }[];
  model?: { version: number; top: { feature: string; label: string; contribution: number }[] };   // top 3 by |contribution|
  translation?: { engine: string; quality: string };
  cluster?: { id: string; size: number };
}
```

Labels in `explain` use the English names; the web client localizes the topic ids with `topics.name_sk`.

### 6.3 Label suggestions

For each of the user's labels with an answer `p ≥ 0.8` that is not already in `label_ids`, add the label
to `labelSuggestions`. The UI shows them as tappable chips ("tap to keep", as in FeedIt).

---

## 7. The `user.rank` handler

1. **Load `UserRankContext`**, including `readClusterIds` (clusters of articles the user read in the
   last 14 days).
2. **Dirty set** (SQL, window `RankerConfig.windowDays` = 14, capped at 5,000, newest first). Articles
   from the user's subscriptions with `first_seen_at ≥ now − 14 days`, not archived for the user,
   where any of these holds:
   - no `user_article` row
   - `ua.score_version < RANKER_VERSION`
   - `ua.scored_at` is older than the newest of `article_facets.created_at` and
     `card_answers.created_at` for the user's cards
   - the payload says `full: true`, which re-ranks the whole window

   Stale articles (`pipeline_state = 'stale'`) are ranked as `new` without a score.
3. **Batch-load** facets, card answers (only the user's cards), translations, clusters, feed ids, and the
   domain (via `tldts`).
4. Run `rankArticle` for each item.
5. **Upsert** the ranking columns of `user_article` in batches of 500:
   `lane, tier, p_like, score_source, rules_fired, explain, label_suggestions, score_version, scored_at`.
   Reader-state columns are never touched.
6. **Weak-translation escalation** (spec 07 §3): for items newly placed in `maybe` whose only
   translation is a tier-1 `weak` one, enqueue `article.translate {forceTier2: true}` once per article.
7. `RANKER_VERSION` is a constant in `packages/ranker`. Bumping it makes everything dirty. Bump it
   whenever the ranking semantics or the config change.

**Enqueued by:**

| Event | `full`? |
|---|---|
| match finished | no |
| card or label added/removed/strength/scope changed | yes |
| rule created/deleted/expired | yes (expiry is picked up by the nightly `house.expire-rules`) |
| preferences changed (`demote`, thresholds) | yes |
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
| Source | `feed.h<k>`, one-hot with k = murmur3(feedId) mod 32 (the first feed carrying the item). `author.h<k>`, one-hot with k = murmur3(normalized author) mod 16 (none if there is no author) |

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
- Keep the last 3 versions per user. Delete older ones.

### 8.4 When to train (`user.learn {userId}`)

- **Enqueued:**
  - by the API after every 10th new explicit label since the active model's `trained_at` (debounced,
    singleton 60 s)
  - by the nightly cron for users with any new labels
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
- **Corpus statistics:** the candidate set being ranked in this run (IDF with +0.5 smoothing).
  `k1 = 1.2`, `b = 0.75`.
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
  - only if `dwell_ms ≥ 6,000`, the article is unrated, and it has not been prompted before
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
