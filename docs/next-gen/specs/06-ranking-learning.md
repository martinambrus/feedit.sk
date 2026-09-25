# Spec 06: Ranking, lanes and personal learning (`packages/ranker`)

Status: **binding**. **Intent:** turn stored model answers into a per-user probability of "you'll like
this", put each article into a lane, and always be able to say why. Card/BM25 scores are
heuristic confidence scores, not calibrated probabilities; only an evaluated personal model may
claim calibration. Only a confident "no" may hide anything. Personal learning *refines* the card-based score. It never gates the first useful result.

`packages/ranker` is **pure**: every function takes plain inputs and returns plain outputs. The worker
handler `user.rank` does the I/O (§7). The same functions run inside `apps/eval`.

---

## 1. Inputs to `rankArticle(ctx, item, now)`

```ts
type Strength = 'must' | 'love' | 'like' | 'never';
type Reason = 'clickbait' | 'promo' | 'shallow' | 'seen' | 'off_topic' | 'other';
interface UserRankContext {
  userId: string;
  rankRevision: string;                 // bigint decimal; invalidates user-specific ranking inputs
  modelContextSha: string;              // canonical manifest hash (§8.1)
  config: RankerConfig;                 // fully validated shared defaults + settings overrides
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
  firstSeenAt: Date; publishedAt?: Date; contentRevision: string; wordCount: number | null; hasImage: boolean; lang: string;
  clusterId?: string; clusterSize: number;
  pipelineState: string;
  matchCoverage: 'complete'|'pending'|'unavailable'; // this user's applicable positives (§2)
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

Before evaluation, discard answers for a different content revision, state/question manifest or
inactive card. Apply scope to **all** card operations (including never, must, explanations, BM25 and
training): only cards whose `scopeFeedId` is absent or in `item.feedIds` are applicable. A `prefilter`
result is a provisional non-match, not negative evidence; it does not count as an answered card.
`matchCoverage` is derived from the applicable cards' work status (spec 05), never inferred solely
from the global article pipeline state. With no applicable positive cards, return `new` after explicit
hide checks. Incomplete positives must not quietly fall into Everything because unanswered ≠ no.

Lane order for "at most" and "at least": `hidden < everything < maybe < for_you`.

```
rankArticle(ctx, item, now):
  1. if a hide rule matches (§3.1)                   → RETURN 'hidden' (fire its code)
  2. if item.pipelineState == 'stale'                → RETURN lane 'new', P null, source 'none'
  3. if a never-card has p ≥ never.hide (§4.2)       → RETURN 'hidden' (fire never:<id>)
  4. base probability P:
       a. compatible active model (§8.1), complete matchCoverage AND item.facets present AND pipelineState ∉ {'degraded','failed'}
          AND item.facetsEngine ≠ 'llm' AND no applicable interest answer has engine 'llm'
                                                     → P = model(x), source 'model' (§8)
       b. else, some applicable positive card has a usable answer → P = cardScore (§4), source 'cards';
          apply quality demotions (§5); remember the deciding card
       c. else, matchCoverage = 'unavailable' and there are applicable positive cards
                                                     → P = bm25P (§9), source 'degraded'
       d. else                                        → RETURN lane 'new', P null, source 'none'
                                                        (label suggestions are still computed)
     Steps 5–8 run only when P is set.
  5. lane = laneFromP(P) (§6.1)
  6. precedence of modifiers, the first that applies wins:
       i.   seen_story: the item's cluster is in readClusterIds → lane = min(lane, 'everything')
       ii.  source 'degraded'                              → lane = 'maybe' (floors are ignored)
       iii. floor: a 'must' card with p ≥ mustFloor, or a boost_feed/boost_domain rule
                                                       → lane = 'for_you' and P = max(P, lanes.forYou)
       iv.  caps (both may apply): a never-card with never.soft ≤ p < never.hide → lane = min(lane, 'maybe');
            the deciding card's answer engine is 'llm' and P < llmForYouMin → lane = min(lane, 'maybe')
  7. if matchCoverage != 'complete' AND lane = 'everything' AND NOT seen_story
                                                     → lane = 'maybe', fire 'pending_cards'
  8. tier = tierFromP(P); labelSuggestions (§6.3); explain (§6.2)
```

The "deciding card" is the positive card achieving `cardScore`, stored as `explain.decidingCardId`.
Every rule that changes the outcome appends its code to `rulesFired` (§3.2). Ties use the lowest
numeric card id. All exits return a valid `Explain` and label suggestions; hidden/new exits have null
P and tier. A lane cap does not rewrite the score: a high tier in Everything after `seen_story` is
intentional and is explained by the rule. `scoreSource = degraded` always fires `degraded`; actual
LLM evidence fires `llm_answer` even when no cap changes the lane. Reject out-of-range/nonfinite/malformed probabilities at the validated boundary;
invalid and missing values are unknown, never zero.

---

## 3. Rules

### 3.1 Semantics

| Kind | `value` | Matches when | Effect |
|---|---|---|---|
| `mute_keyword` | a keyword or phrase | `normalizeText(value)` (same as `title_norm`) occurs as a whole-word sequence in `titleNorm`, `excerptNorm` or `translatedTitleNorm` or `translatedExcerptNorm` | hidden |
| `mute_story` | cluster id | `item.clusterId === value` | hidden |
| `block_feed` | feed id | the non-empty `item.feedIds` set is a subset of the union of active block-feed rule values (all subscribed carriers are blocked) | hidden |
| `block_domain` | registrable domain | `item.domain === value` | hidden |
| `block_author` | author name | case- and diacritic-insensitive equality | hidden |
| `boost_feed` | feed id | any feed matches | floor `for_you` |
| `boost_domain` | domain | domain matches | floor `for_you` |

Expired rules are excluded when the context is loaded. A mute created from "mute this story for N days"
has `expires_at = now + N days`, with N ∈ {1, 3, 7, 30}.

### 3.2 Rule codes (stable strings, shown by "Why this?")

`mute_keyword:<value>`, `mute_story`, `block_feed`, `block_domain`, `block_author`, `boost_feed`,
`boost_domain`, `never:<cardId>`, `never_soft:<cardId>`, `must:<cardId>`, `demote:clickbait`,
`demote:promotional`, `demote:shallow`, `demote:stale`, `degraded`, `llm_answer`, `seen_story`, `pending_cards`.

---

## 4. Card score

### 4.1 Positive cards

`w = { must: 1.0, love: 1.0, like: 0.8 }`.

`cardScore = max over positive cards c with an answer of (w[c.strength] × p_c)`.

- A missing answer means unknown and is ignored.
- `prefilter` markers count as unknown, including in explanations and training.
- If **no** positive card has an answer yet (answers pending), the lane is `new`.
- If the user has no positive cards at all, the lane is `new` and the UI prompts for applicable interests
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

Reason counts count **current distinct disliked articles**, not append-only event counts: editing a
reason moves one count, and undo/unrate removes it. Use the feedback-time snapshot for stale status;
missing facets never trigger shallow or any other flag. Article age is
`now - min(publishedAt ?? firstSeenAt, firstSeenAt)` (floor at zero), so freshly fetched old news can
still be stale. `pipelineState = stale` is the ingestion/backfill eligibility state, not this quality
flag. Recompute auto activation when the 90-day boundary passes (§7).

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

All thresholds come from `RankerConfig` (§11), which gate G1 may override globally; v1 has no per-language threshold schema.

### 6.2 Explain (`user_article.explain`, version 1; zod schema in `packages/shared/src/dto/explain.ts`)

```ts
interface Explain {
  v: 1;
  inputs: { contentRevision: string; rankRevision: string; contextSha: string };
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

**Versioning:** `score_version = "<RANKER_VERSION>:<ranker.settings_version>"` (text equality,
never lexicographic or numeric ordering). This avoids collisions after 10,000 settings changes.
`users.rank_revision` is a monotonically increasing bigint changed in the same transaction as any
user-specific rank invalidation; `user_article.rank_revision` records the revision used. Queue
insertion goes through the transactional outbox (spec 03). Version numbers are serialized as strings.
- `RANKER_VERSION` and `scoreVersion(settingsVersion)` are exported by `packages/ranker`. They are
  created in the ranker bootstrap (M2-T10), so both the API (M4) and the rank handler (M5) can use
  them. The constant is bumped whenever the ranking semantics change.
- The API bumps `ranker.settings_version` on every change to `ranker.thresholds` (and to any future
  ranking-relevant key), and then enqueues `user.rank {full: true}` for users active in the last 7 days.
- Inactive users catch up on their next visit: `GET /articles` enqueues a full rank if **any** eligible
  row is missing/outdated, has an old rank revision, or has `next_rank_at ≤ now`. A MAX/newest-version
  check is insufficient after partial runs.

**Steps:**

1. **Load `UserRankContext`**, including:
   - `readClusterIds`: clusters with any member read by the user in the window
   - `bm25`: document frequencies over **all** window articles, not just the dirty ones (§9)
2. **Dirty set** (SQL, window `RankerConfig.windowDays` = 14; **5,000 is a batch size, not a total
   eligibility cap**). Iterate by stable `(first_seen_at, id)` keyset until every eligible item is
   considered; use one captured `now` for the run. Articles
   from the user's subscriptions with `first_seen_at ≥ now − 14 days`, not archived for the user,
   where any of these holds:
   - no `user_article` row
   - `ua.score_version != current score_version` or `ua.rank_revision != users.rank_revision`
   - `ua.next_rank_at ≤ now` or article content revision no longer matches `explain.inputs`
   - `ua.scored_at` is older than the newest of `article_facets.updated_at`,
     `card_answers.answered_at` (for the user's cards and labels) and `article_translations.created_at`
   - changes to cluster membership, read/unread/undo state, matching coverage, translations, or
     applicable feed membership since the previous input snapshot; these enqueue a full rank and
     increment `rank_revision`, so deleting evidence is detected too
   - BM25 corpus membership changed: rerank all degraded items, not just the newly arrived article
   - the payload says `full: true`, which re-ranks the whole window

   Stale articles (`pipeline_state = 'stale'`) are ranked as `new` without a score.
3. **Batch-load** facets, card answers (the user's cards and labels), translations, clusters, the
   user's `label_ids`, the feed ids (intersected with the subscriptions), and the domain (via `tldts`).
4. Run `rankArticle` for each item.
5. **Upsert** the ranking columns of `user_article` in batches of 500:
   `lane, tier, p_like, score_source, rules_fired, explain, label_suggestions, score_version,
   rank_revision, scored_at, next_rank_at`. `next_rank_at` is the earliest future freshness-bin,
   stale-quality, active-rule expiry or 90-day reason-count expiry that can change this result; null
   means there is no known clock deadline. A scheduled reconciliation picks due rows at least hourly,
   including active users who never reload the list.
   Serialize rank runs per user and compare captured `users.rank_revision` and article
   `content_revision` immediately before writes. If either changed, discard those results and enqueue
   a replacement; a slower old run cannot overwrite newer settings/content. Reader-state columns are
   never touched. Filter `label_suggestions` against current labels/assignments at write time so a
   concurrent label action is not undone. Commit batches plus a continuation through the outbox;
   completion is recorded only after the last batch, and a crash resumes safely.
6. **Weak-translation escalation** (spec 07 §3): for items newly placed in `maybe` whose best
   translation is a tier-1 `weak` one **and** that have no `ollama` translation row yet (a skipped
   attempt also leaves a row), enqueue `article.translate {forceTier2: true}`. This happens once per
   article.

**Enqueued by** (incremental runs are debounced; full runs use their own key, spec 03 §2):

| Event | `full`? |
|---|---|
| match finished; enrich degraded | no |
| a read, mark-read, open, unread or undo affecting a cluster | yes (invalidate both addition and removal of seen evidence) |
| card or label added/removed/strength/scope changed | yes |
| rule created/deleted, or expired (hourly `house.expire-rules`) | yes |
| preferences changed (`demote`, `implicitNegative`), feedback reason/count changed or aged out | yes |
| `ranker.thresholds` changed | yes (users active in 7 days; the others lazily, as above) |
| new active model | yes |
| subscription added/removed/duplicate policy changed; article joins/leaves a carrier or cluster | yes |
| freshness/expiry deadline reached | no (due rows plus user-wide auto-demotion invalidation) |

---

## 8. Personal model

### 8.1 Features and compatibility (`FEATURE_SPEC_V1`)

| Group | Features |
|---|---|
| Cards | `card.<id>` = p for the first 30 positive cards in the user manifest (numeric id order; fixed across items), with paired `known.<id>` masks. `never.<id>` plus known masks for never-cards. `other_cardmax` covers positive cards beyond 30; `cardmax` covers all positives. Missing values are 0 only with a 0 known mask; scope-excluded cards are missing |
| Facets | `ct.*` (12), `t1.*` (20), `depth`, `depth_conf`, `clickbait`, `promotional`, `time_sensitive`, `evergreen`, `paywall_teaser`, `tone`, `scope.*` (4) |
| Length | one-hot `len.short/medium/long/very_long/unknown`, boundaries from spec 05 §3.1 (<150 / <600 / <1500 / ≥1500 / null words) |
| Freshness | one-hot `age.lt6h/lt24h/lt72h/older`: disjoint [0,6h), [6h,24h), [24h,72h), [72h,∞), using age at snapshot for training and now for scoring (§5) |
| Language | one-hot `lang.en/sk/cs/other` |
| Other | `has_image`, `cluster_log = ln(1 + clusterSize)` |
| Source | `feed.h<k>`, one-hot with k = murmur3(feedId) mod 32, using the lowest id in `item.feedIds`. `author.h<k>`, one-hot with k = murmur3(`normalizeText(author)`) mod 16 (none if there is no author). murmur3 = **MurmurHash3 x86 32-bit, seed 0, over the UTF-8 bytes** of the decimal id string or the normalized author |

`feature_spec_sha` hashes the canonical feature algorithm and ordered feature names. In addition,
`metrics.context_sha` hashes the complete per-user input manifest: all interest card ids, strength
and scope (including cards outside the first 30), feature spec, active
question/state/translation manifests, ranking config and model engine/version. Display-only card
renames and ordinary subscription additions are excluded (source hash vocabulary is fixed). Store the manifest, scaler and dropped columns with the model. A mismatch
immediately disables model scoring until a compatible model is trained; positional vectors must
never silently bind to different cards.

Only validated current-revision answers from the **same pinned engine family/version and question
manifest** are used. `prefilter` is unknown, not a probability. If any applicable interest-card answer
or facet came from `llm`, the item is left out of training and follows the cards path until compatible
answers exist. Label-card engines do not affect interest-model eligibility. Laya/Jev feature families
must not be mixed without a new evaluated feature spec. Facet unknowns need masks just like cards.
Raw article/card text is not written into training event snapshots.

### 8.2 Labels and event-time snapshots

There is **one current sample per user/article**, never one per event. Use the latest explicit state
first; otherwise use the highest-priority surviving implicit signal below. Signals do not accumulate
weights. Current undo/unbookmark/unlabel state removes the corresponding evidence.

| Signal (priority order) | y | weight |
|---|---|---|
| rating +1 / −1, including prompt answers | 1 / 0 | 1.0 |
| bookmarked, not rated | 1 | 0.8 |
| label assigned, not rated, **only when `model.labelAssignmentsPositive` is enabled after owner approval** | 1 | 0.8 |
| opened with observed dwell ≥ 30 s, not rated | 1 | 0.3 |
| observed complete open/return session with dwell < 5 s, not rated | 0 | 0.2 |
| marked read without opening, not rated, and `prefs.implicitNegative` | 0 | 0.1 |

**Label meaning is an owner decision (PLAN §17 Q10).** Default `model.labelAssignmentsPositive=false`:
label assignments are neutral organization and do not train interest. Keep fixtures for the previously
proposed 0.8-positive behavior behind the explicit flag, but do not enable it in production until Q10
is resolved. A label example still refines its own label classifier, not personal interest directly.

Un-rating suppresses all earlier implicit evidence for that article until a new positive/negative
action occurs; it must not immediately turn an undone dislike into a bounce dislike. A missing return
beacon is unknown dwell, not zero. Off-site dwell measures elapsed time away, not observed reading;
keep its weak weight and show this limitation in the privacy/learning explanation. Bulk mark-read and
archive operations are housekeeping, not dislike evidence unless explicitly opted in.

**`feedback_events.value` v1:** every feedback mutation stores the signal payload and an immutable
`before` snapshot `{lane,pLike,tier,scoreVersion,rankRevision,scoredAt}` from the ranking seen before
that action, plus `staleAtFeedback` (boolean or null). Learning-relevant actions additionally record
`features: {specSha,contextSha,values,sourceManifest,snapshotAt}` when valid inputs exist. Build it
before applying the action, under the same content revision as the displayed score. Features include
known masks, all values needed for the cards-only baseline, and the story-group id at that time.
If inputs are absent/stale, leave `features` null: retain the explicit rating but omit it from training
until the user supplies a new compatible event. Never reconstruct historical training inputs from
future enrichments, later card examples, larger future clusters or current article age. Undo refers to
its original receipt/events and restores the prior effective signal instead of producing a fresh
training example. Events and snapshots remain private per-user data under RLS and account deletion.

Only surviving samples with snapshot and feedback timestamps within the last **180 days** are used.
The current `context_sha` must match; changing card definitions/strengths/scopes may therefore return
the user to cards-only ranking until enough compatible feedback exists.

### 8.3 Training (`trainUserModel(samples, now)`, pure)

- **Reproducibility:** stable sample order and seed derived from user id + context sha + effective
  feedback cutoff; include data/manifest hashes in model metrics. Repeated events are deduplicated.
- **Leak-free validation:** group samples by story cluster (unclustered = article id); keep all
  versions/members of a group in one fold. Choose deterministic group-stratified k-fold with
  `k = min(5, minority explicit-class group count)`, at least 3; reduce k if needed. Every validation
  fold and its training partition must contain both explicit classes. If impossible, record
  `insufficient_validation` and do not activate. Implicit samples from a held-out group are also held
  out. Fit standardization and zero-variance dropping **inside each training fold**.
- **Objective:** minimize weighted mean logistic negative log-likelihood plus
  `lambda/2 * sum(w_i²)`; intercept unregularized. λ defaults to 1.0. Fit by damped Newton/IRLS,
  at most 25 iterations, stopping at loss change < 1e-6. Use stable sigmoid/log-sum-exp, positive
  ridge on singular Hessians and backtracking if loss rises. Nonfinite/nonconverged fits are rejected,
  never serialized as active models.
- **Metrics:** out-of-fold `cv_auc` and `cv_logloss` use explicit examples only, one per article.
  `baseline_auc` and `baseline_logloss` use cards-only scores on exactly those snapshots and folds;
  report grouped bootstrap confidence intervals, class counts and skipped-sample reasons. Single-
  class AUC is null, never 0.5 or zero.
- **Calibration (Platt):** fit `P = sigmoid(a*z+b)` on out-of-fold explicit logits with a weak λ=0.01
  prior toward a=1,b=0 and constrain a>0. Calibration reporting uses nested folds: each outer
  validation fold uses a calibrator fitted only on inner out-of-fold predictions of its training
  partition. If inner data lacks both classes, use identity calibration for that fold. Fit the final
  calibrator on all out-of-fold logits and the final scaler/weights on all eligible training samples.
- **Activation** requires `n_explicit ≥ 30`, explicit positives ≥5 and negatives ≥5, valid grouped
  CV, `cv_auc ≥ 0.60`, `cv_auc ≥ baseline_auc − 0.02`, and calibrated `cv_logloss` no worse than
  `baseline_logloss`. An uncalibrated card score is a baseline, not ground truth probability.
  A failed candidate leaves the previous compatible active model in place, unless deletion/undo or
  context changes invalidated its training evidence. Activation and deactivation are one transaction
  under a user lock; the unique-active index must never transiently conflict.
- **Contributions:** explain `a*w_i*x_scaled_i`, top 3 by absolute value (stable feature-name tie
  break). Explain these as associations in this model, not causal reasons. Hash collisions make
  `feed.h*` mean "source group", not uniquely "this source". Include the calibrated intercept
  separately if needed; top three need not sum to the full score.
- **Retention:** keep the active version plus the 3 newest other versions. Store attempt cutoff and
  rejection reason even when activation fails; no change repeatedly retrains the same data.

### 8.4 When to train (`user.learn {userId}`)

- The API enqueues after **at least** `model.retrainEvery` (default 10) newly effective explicit
  feedback changes since the last processed cutoff, including a bulk operation that crosses the
  boundary. Do not use `n % 10 == 0`; it misses batches and concurrent updates. Count event ids with
  deterministic effective-state reduction, not only current non-null `rated_at` rows.
- Undo/unrate/deletion or a context change invalidates affected models immediately and enqueues learn
  even below ten. Changes to implicit evidence, preferences and 180-day retention also participate in
  the nightly trigger. No revoked evidence may remain silently active in a stored model.
- `house.nightly-learn` enqueues users whose effective input manifest differs from the last attempt,
  including implicit-only changes and expiry; users with no changed inputs do not retrain.
- Handler: under per-user serialization capture a committed feedback cutoff/context hash → build
  samples → train → compare current cutoff/context before activation → store candidate and attempt
  metadata → activate if eligible. If newer invalidating input appeared, keep the attempt inactive
  and enqueue another run. Activation/deactivation increments rank_revision, enqueues a full rank;
  activation also enqueues `user.suggest`. A failure to train must not suppress future retries after
  new evidence arrives.

---

## 9. Degraded ranking (BM25)

Used when the article has no facets or answers because the engine was unavailable, and by the eval as a
baseline (spec 10).

- **Tokenizer:** `normalizeText` (diacritics stripped, lower-case), split on non-alphanumerics, drop
  tokens shorter than 2 chars and stop-words (small built-in EN/SK/CZ lists, ~150 words each).
- **Document:** the title twice, then the excerpt (translated text instead, when a translation exists).
- **Query:** applicable card `interest`, using `interest_en` only with an English document; do not
  compare translated English documents to untranslated Slovak/Czech queries. Without a matching
  query translation, use the original document/query pair and report this in eval.
- **Corpus statistics:** document frequencies over **all** articles in the user's rank window (14 days
  of their subscriptions). They are computed once per `user.rank` run, so scores don't depend on how
  many items happen to be dirty. IDF uses +0.5 smoothing. `k1 = 1.2`, `b = 0.75`. The eval builds the
  corpus from the rater's assigned frozen articles (no rating information enters the corpus).
  Exact IDF is `ln(1 + (N-df+0.5)/(df+0.5))`; term contribution is
  `IDF * tf*(k1+1)/(tf + k1*(1-b + b*docLength/avgLength))`, summed over unique query terms.
  Empty corpus/document/query or zero average length yields score 0, never NaN.
- `s = max over positive cards of BM25(card, doc)`. `P = 1 − exp(−s / 3)`.
- The lane is **always `maybe`**: never hidden and never `for_you`, because keywords are not trusted to
  hide or promote. Explicit hide rules/never evidence still apply first, and `seen_story` may
  cap it to Everything under §2; “always Maybe” describes BM25 itself, not overriding those rules.

---

## 10. Active learning and feedback prompts

- **Maybe lane order:** by `|P − 0.5|` ascending (most uncertain first), then newest first.
- **Calibration round** (onboarding step, and a weekly "Tune your feed" card in the reader):
  - 10 unrated articles from `maybe`, at most 7 days old, at most 3 per feed
  - pick the most uncertain first
  - if `maybe` has fewer than 10, fill from `everything` with the highest P
  - one member per cluster; exclude explicit hides and archived items; preserve the at-most-three-
    per-feed cap in the fill step and break ties by numeric article id. Return fewer than ten if
    necessary. Do not reoffer already answered items. Record source lane and selection method so
    evaluation can separate deliberately uncertain examples from ordinary reading.
- **"Did you like it?" prompt** (from FeedIt's todo list), shown when the reader returns to the app
  after opening an article:
  - only if `dwell_ms ≥ 6,000`, the article is unrated, and `feedback_prompted_at` is null
  - whenever `POST /articles/:id/dwell` answers `prompt: true`, it also sets `feedback_prompted_at`, so
    an ignored prompt never returns
  - and either `lane = 'maybe'` or deterministic uniform hash(user, article, open-session) < f,
    sampled once per session, with f from `prefs.feedbackPrompt`:
    `often` = 1/5, `occasionally` = 1/20, `never` = 0
  - when the preference is `never`, no prompt is shown at all, even for Maybe items
  - checking and setting `feedback_prompted_at` is atomic; retries/concurrent dwell requests cannot
    issue two prompts or repeatedly roll the sampling probability

---

## 11. `RankerConfig` (defaults; `settings['ranker.thresholds']` overrides; gate G1 writes it)

The schema and defaults live in `packages/shared/src/ranker-config.ts` from M0-T2; the ranker
imports/re-exports them. M2-T10 exports pure card-score/lane/tier/policy-preview helpers for G1;
M5 adds the full worker/ranking orchestration. Do not create a shared→ranker dependency cycle.

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
           retrainEvery: 10, historyDays: 180, keepVersions: 3, labelAssignmentsPositive: false },
  bm25: { k1: 1.2, b: 0.75, scale: 3 },
} as const;
```

Validate the fully merged config: all numbers finite; `0 ≤ lanes.maybe < lanes.forYou ≤ 1`;
`tiers` exactly four strictly increasing values in (0,1); `0 ≤ never.soft < never.hide ≤ 1`;
all probabilities/weights/factors in [0,1]; window/history/count fields positive integers with
implementation bounds; BM25 scale/k1 positive and b in [0,1]. Reject invalid admin updates atomically.
The implementation derives all examples/tables above from these defaults, not duplicated literals.

---

## 12. Tests

**Unit:**
- truth tables for every rule kind, and for never/must/boost interplay
- the lane and tier boundaries
- demotion activation (auto vs on vs off)
- `seen_story`
- cards-only monotonicity (property test with `fast-check`): raising a positive card's p never
  lowers the base card score with everything else fixed. This is **not** guaranteed for a learned
  model with negative coefficients or for lane changes from a deciding-engine tie break
- logistic regression recovers the signs of known weights on synthetic data and reaches AUC ≥ 0.9
- Platt scaling reduces ECE on synthetic over-confident scores
- the activation rule
- BM25 ordering on a hand-made corpus
- `Explain` snapshots

**Integration:**
- `user.rank` on a seeded DB writes the expected lanes
- idempotency: a second run with nothing dirty writes nothing
- `full` re-rank after card strength/scope, unread/undo, reason change, rule expiry and model invalidation
- >5,000 eligible items all finish; crash/continuation and simultaneous old/new ranking runs cannot
  lose work or overwrite new input; old/new score versions mixed in one window trigger catch-up
- clock-only freshness and 90-day auto-demotion changes become visible without new articles
- partial/prefilter answers and scope-excluded never/must cards never produce false negative hides
- fold-local scalers, story grouping, nested calibration, single-class folds and nonconvergence
- event snapshot predates label; repeated dwell/rate/undo adds no duplicate sample; bulk 9→12 labels
  schedules training; revoked evidence cannot survive in an active model
