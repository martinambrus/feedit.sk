# Spec 10: Evaluation, golden set and gate G1 (`apps/eval`)

Status: **binding**. **Intent:** prove the core bet before building on it. The bet: interest cards +
Jev rank articles clearly better than keyword matching, with zero training, in EN, SK and CZ.
Measurement, not opinion, sets the open parameters: language modes, card text mode and lane
thresholds. Later, every change to questions, thresholds or the model version is replayed against the
same **frozen inputs**. Development selection and locked holdout reporting are separate; repeatedly
tuning against the holdout turns it into development data and requires a new holdout.

No FeedIt.sk data is used (locked decision). The golden set is built from scratch. The owner can
start alone, including several real topic-specific reading contexts. This is a valid **owner pilot**;
personas do not turn one human into independent testers. Under the owner's resolved Q13 decision
(PLAN §17), a **passed owner pilot is sufficient evaluation evidence for the initial invite-only
beta and production settings**. Quality, coverage, cost and operational gates still apply. Wider
multi-person validation is an optional later profile, not a recruitment prerequisite for launch.

Notation: `eval <command>` below is short for the root script `pnpm evaluate <command>` (spec 01 §2).

---

## 1. Outputs of gate G1

1. `apps/eval/reports/G1-<date>.md`: the human-readable report (the tables in §4, the decisions in §5,
   costs).
2. `apps/eval/config/g1.json`:

   ```ts
   { "language_modes": { "en": "native", "sk": "…", "cs": "…" },
     "card_text_mode": "as_written" | "english",
     "ranker_thresholds": { …deep partial of RankerConfig… },
     "recommended_daily_budget_usd": 2.0,
     "translate_tier2_daily_cap": 300 | 1000,
     "laya_track_recommended": false,
     "runs": { "B0": "11", "B1": "12", "E1": "13", … },      // eval.runs ids used for the decisions (M7-T7 reads them)
     "notes": "…",
     "dataset": { "version": "golden-v1", "snapshotSha": "…", "splitSha": "…" },
     "selection": { "developmentRunIds": ["11","12","13"], "lockedAt": "ISO timestamp", "configSha": "…" },
     "gate": { "profile": "owner_pilot" | "multi_person_beta", "participants": 1,
               "status": "pass" | "fail" | "needs_more_data", "reportSha": "…" } }
   ```

3. `pnpm evaluate apply-g1 apps/eval/config/g1.json` (paths are relative to the repository root; the
   CLI resolves them from there) writes the settings below to the dev DB. In production, an admin
   applies the same values through `PATCH /admin/settings`.

   | `g1.json` field | `settings` key |
   |---|---|
   | `language_modes` | `language_modes` |
   | `card_text_mode` | `card_text_mode` |
   | `ranker_thresholds` | `ranker.thresholds` (and bump `ranker.settings_version`) |
   | `recommended_daily_budget_usd` | `engine.daily_budget_usd` |
   | `translate_tier2_daily_cap` | `translate.tier2_daily_cap` |
   | `laya_track_recommended`, `runs`, `notes`, `dataset`, `selection`, `gate` | not settings; kept in the report and in git |

`g1.json` itself is valid JSON (the example above is a schema sketch). Database bigint ids in
config/reports/CLI JSON are decimal strings, including run ids; participant counts remain numbers. Validate it with the shared
`RankerConfig` schema/defaults from M0 and pure score/lane/tier helpers from the M2-T10 bootstrap;
G1 does not depend on the full M5 rank worker. `apply-g1` rejects missing/incomplete runs, hash
mismatches and a gate other than `pass`, applies settings in one transaction, and bumps the version
only when relevant values changed. A complete passed `owner_pilot` artifact is accepted for both
development and production settings under the resolved Q13 launch policy, through the same normal
admin deployment/application flow as a passed `multi_person_beta` artifact. No development-only flag
or second approval is required merely because the pilot has one participant. Preserve
`profile='owner_pilot'`/`participants=1` in the applied report; acceptance never means multi-person
validation. Dry-run, failed, incomplete or hash-mismatched artifacts cannot authorize production
settings, regardless of profile.

---

## 2. Building the golden set `golden-v1`

### 2.1 Feeds and articles

- **Feed list:** `apps/eval/data/feeds-golden.txt`, 54–66 feeds: 18–22 each for English, Slovak and
  Czech. It mixes news, tech, science, sport, lifestyle, local and classifieds, and includes at least
  one Google News feed and one feed with poor excerpts.
- **`eval ingest-sample --feeds apps/eval/data/feeds-golden.txt`:**
  - creates or reuses the internal system user `eval@feedit.local` (role `user`, never logs in) and
    subscribes it to the feeds
  - **requires a running worker** with `EVAL_INGEST_ONLY=true`: the command checks
    `settings['worker.heartbeat']` for an entry younger than 90 s with `evalIngestOnly = true` (spec 02
    §2) and exits with instructions if there is none. So enrich and match never run, and there are no
    Jev costs. **Every live worker on that database** must have this flag; a heartbeat from just one
    ingest-only process is insufficient. Refuse collection if any non-ingest-only worker is live,
    and do not share the golden DB with concurrent API/E2E development workers
  - fetches each feed once immediately, waits until the `article.extract` queue for these articles has
    drained, and prints per-language article counts
  - **`--watch`** keeps the eval user subscribed and prints counts every 10 minutes, until stopped
  - between the first run and rating, the normal schedule keeps fetching, because the eval user is a
    subscriber
- **Sample (`eval sample`):** up to 1,500 non-stale articles, 500/500/500 by detected language (fewer
  if a language runs short), stratified across feeds and collection days. Cap any one feed at 10% of
  its language sample; report actual availability instead of quietly replacing source diversity
  with one prolific feed. Store sampling seed, timestamps and exclusions.
- **Freeze:** `eval.sample.snapshot` stores immutable article input (title, excerpt, body lead used by
  the classifier, language, timestamps, carrier feeds, content revision and story-group id), with
  `snapshot_sha`. Freeze rater cards/strengths and assignment membership before the first model run.
  Experiments/replays read these snapshots, never mutable live articles. Translations and exact
  request state/question manifests are frozen with the run, including failures and engine/version.
- **Split before looking at outputs:** deterministic 70% development / 30% test, stratified by
  language and grouped by story (all duplicates and all raters' copies of a story stay together).
  Persist `eval.sample.split` and a split-manifest hash. Unclustered duplicates found later require a
  new split/version before G1; do not move selected difficult items across the split.
- Once frozen, additions, rating corrections or card edits create a new version manifest; they do
  not silently mutate a run's ground truth. `eval.runs.config` captures dataset/split hashes plus the
  exact rating/card snapshot used. Source rows may stay linked for browsing, but they are not the
  reproducibility boundary.
- **Status (`eval status`):** per-language sample counts, then per rater: cards written, feeds picked,
  assigned, rated, skipped. Also facet-label counts per language.

### 2.2 Participants and reading contexts (owner pilot first; beta expansion later)

- **Actual participant identity:** `eval.raters.participant_key` is an opaque random UUID grouping
  all contexts authored/rated by the same human. `context_name` describes a topic/reading purpose,
  not a fabricated person. `eval rater add --name <n> --langs sk,en [--participant <key>]
  [--context <label>]` creates `eval.raters` and prints the key (for adding another context) plus a
  private URL with a token: `${EVAL_PUBLIC_URL}/r?t=<token>`. `EVAL_PUBLIC_URL` defaults to `http://localhost:5180`.
- **Owner pilot:** start with one participant; the owner may define separate contexts such as web
  development, speech systems and local news **before seeing articles**. A context can express a
  genuine different reading goal, but every report identifies the same underlying participant.
  Prefer distinct topic/feed cohorts; shared articles/story groups keep one common dev/test split.
- **Optional later multi-person profile:** recruit 3–5 actual people with different tastes when
  useful for broader validation; this is not an initial beta requirement. Reusing the owner
  under different names/accounts/languages must never satisfy a multiple-participant requirement.
- **Step 1 in the rating app: write interests first.** Before seeing any article, each context writes
  **5–10 interest cards** (and optionally 1–3 "never" cards) in their own words, in their preferred
  language. Stored as real `interest_cards` (visibility `shared`) and `eval.rater_cards`. The spec 05
  authoring rules are shown as hints.
- **Step 2: pick feeds.** The rater ticks the golden feeds they would actually subscribe to (at least
  10) → `eval.rater_feeds`.
- **Step 3: rate.** On first entry, the app builds the rater's `eval.assignments`:
  - up to **300** articles from `eval.sample` carried by the rater's picked feeds
  - split **equally across the rater's `langs`**; a language short of its share is topped up from the
    others
  - if the sample has fewer than 300 for these feeds, all of them are assigned, topped up from
    non-sampled recent articles of the rater's feeds (which are then added to `eval.sample`)
  - every top-up receives the same frozen snapshot and story-group split before assignment
  - shuffled deterministically (seeded by the rater id)
  - **Blind:** no model output is shown.
  - The page shows the feed, title, excerpt (≤ 600 chars) and "open original".
  - Buttons: 👍 "I'd want to read this" / 👎 "Not for me", plus an optional reason (the spec 09 reason set).
  - Keyboard: `+`/`-`, `1`–`6`, `j`/`k`.
  - Progress is saved on every click (`eval.ratings`) and ratings can be changed.
  - A rater may skip an article; persist `eval.assignments.status = skipped` (and an optional reason).
    Rating sets `rated`, returning to a skipped article is supported, and pending remains distinct.
    Goal: ≥250 distinct article ratings per actual participant, with supported context/language
    cells (§5). Rating one article under three personas counts as one article toward participant
    readiness. Never silently convert a skip to a dislike. Report skip rates and
    language/source coverage alongside quality metrics.

### 2.3 Facet labels (the enrichment accuracy check)

- **`eval serve-rating`** also serves `/facets?t=<token>`: 100 articles per language (sampled from the
  1,500).
- A labeller sets `content_type`, `topic_l1`, `depth` (0–4), `clickbait` (y/n), `promotional` (y/n),
  `time_sensitive` (y/n).
- At least one labeller (the owner). A second labeller does 50 of the same articles so human agreement
  (Cohen's κ; weighted κ for ordinal depth) is a reference, not an absolute model-accuracy ceiling.
  Preserve both labels and use a predeclared adjudication step for disagreement; do not choose the
  label that agrees with a model. Include uncertain/not-applicable rather than forcing a false class.

### 2.4 Rating app (`apps/eval/src/rating-server`)

- Fastify on port 5180, with vanilla HTML + CSS + a small ES-module script (no framework).
- Mobile-friendly and keyboard-driven.
- Generate ≥128-bit random rater tokens and store only a cryptographic hash. Exchange the link token
  for an HttpOnly, SameSite cookie, then redirect to a token-free URL; Secure on the HTTPS tunnel.
  Set `Referrer-Policy: no-referrer`, no external resources/analytics, redact token-bearing URLs in
  logs, and rate-limit token exchange. Allow revocation and expiry. Every read/write is scoped to that
  rater's assignment; the worker DB role makes application-level ownership checks essential.
- Uses `DATABASE_URL_WORKER`.
- Not deployed to production. It runs **on the dev box** (the same DB as M3b), exposed to raters
  through an authenticated HTTPS tunnel. Bind the service to loopback; only the rating routes are
  exposed, with origin/CSRF checks on mutations. The dedicated golden database is shared by M3b and
  M7-T7 through explicit eval connection config, not by ordinary development workers. Back up with a
  **complete `pg_dump -Fc`** (or a tested self-contained snapshot export including referenced public
  articles/cards/feeds and frozen manifests); `pg_dump -n eval` alone cannot restore its foreign-key
  dependencies. Keep dumps/cache/rater tokens out of git and test restoring the golden set.

---

## 3. Experiments (`eval run --experiment <id> [--langs] [--raters]`)

Each run:
- writes `eval.runs` (config, git sha, dataset/split/config hashes, seed, provider/model, question and
  translation manifests, runtime/dependency versions) and `eval.run_answers`; answer keys are unique
  per run/article/card/question (variant is fixed by the run), so resume/upsert never duplicates results
- caches each **successful validated** engine/translation call in
  `${EVAL_CACHE_DIR}/<sha256(canonical request manifest)>.json`
  (default `~/.cache/feedit-eval`, outside the repository and shared by worktrees), so re-runs and
  report tweaks cost nothing. The canonical manifest includes provider, pinned model version,
  adapter/schema version, operation, exact state/questions/translation source and target, decoding
  settings and content revision; simple string concatenation is not a safe key. Writes are atomic,
  cache hits retain actual provenance, failures are not cached as answers
- uses the production packages: `questions`, `engine`, `translate`, `ranker`
- frozen assignments are **explicit evaluation demand**, isolated from production subscriptions.
  An eval run may process only assigned snapshots approved for that invocation, subject to its cost
  cap; subscribing the ingestion-only eval user does not make all collected articles inference-active.
  Production benchmarks separately exercise off, selected-training and active-new-arrival mixes
- builds its own `EngineRouter` (spec 04 §1, "Eval routers") with `budgetOverrideUsd = --max-usd`
  (default 10) and `ignoreDailyCaps: true`. Every call, translations included, is recorded as
  `kind = 'eval'`, so eval spend never touches the production daily budget or the tier-2 cap.
  `--max-usd` caps **this invocation**. To keep a total across several invocations, pass the remaining
  amount each time.
- prints the cost estimate first. Above $1 it asks for confirmation unless `--yes` is given. Unattended
  goals always pass `--yes --max-usd <n>`.

| Id | State variant | Card text | Purpose |
|---|---|---|---|
| **B0** chrono | — | — | baseline: newer = higher |
| **B1** BM25 | native | as written | keyword baseline (spec 06 §9) |
| **B1-T** translated BM25 | frozen tier-1 English text for SK/CZ | English query translations | controls for translation improving the keyword baseline too |
| **E1** native | native | as written | the core bet in its simplest form |
| **E2** native + EN cards | native | cards translated to English by LibreTranslate | does English card text help? |
| **E3** LT | translated with LibreTranslate | as written | free translation |
| **E3b** LT + EN cards | translated with LibreTranslate | English | both |
| **E4** GLM | translated with Ollama `glm-5.3-flash` | global card mode selected on development (§5) | measured alternative for the fallback translation (SK/CZ only) |
| **E5** Laya zero-shot (optional) | native | as written | measured M9 baseline without assuming an outcome. Skip if `laya` is not installed |

**Completeness:** a gate experiment pins its intended engine; an LLM fallback must not silently
become an E1–E4 Jev answer. Record unavailable cases and retry/resume within budget. Compare all
variants on the identical assigned/rated cohort; require ≥95% valid scoring coverage per language
and rater, and include conservative missing-output sensitivity (missing model score behaves as
unknown/degraded). Below that coverage the result is `needs_more_data`, not a pass on easy items only.

**Per article and rater:**
- Call A (`enrich-v1`) once per article and variant.
- Call B with the **rater's cards** (all raters' cards can share one call per article: namespaced
  keys, exactly like production).
- The score is computed with `packages/ranker` card scoring (spec 06 §4.1). No demotions and no model:
  the primary AUC measures the zero-training score. Record a second production-policy view using
  the pure lane/tier helpers, known never/must/floor/cap precedence and the selected config; it must
  report false hides and For You precision/coverage. A never hard match sets policy score to zero,
  and soft never/must effects are included in policy lane metrics. Record raw card scores separately
  so ranking quality cannot conceal a bad hide rule. `prefilter` is unknown, not a score of zero.

**G1 cost:** estimate from a small pilot with the actual packed questions, number of raters/cards,
repeated state, source/target translation tokens and the current provider price/configuration.
Report estimated and actual billed cost separately, including cache savings and failed-call charges.
Do not use a fixed historical dollar estimate as an executable budget guarantee. Abort safely at the
invocation budget, retain completed answers, and resume explicitly; partial runs cannot pass G1.

**Other commands:**
- **`eval dry-run`:** runs the whole pipeline on synthetic data (M3a-T8) in a **separate database**
  `feedit_eval_dryrun`, freshly created from the current template **and seeded** (spec 02 §1.1). The
  eval process connects through `TEST_ADMIN_DATABASE_URL` only to create it. It writes
  `reports/DRYRUN-<date>.md` and `reports/DRYRUN-<date>.g1.json`, both git-ignored, and never touches
  the real `eval` tables.
- **`eval gate --profile owner_pilot|multi_person_beta`:** validate the frozen dataset/run manifests,
  compute profile readiness, select on development, lock the profile/config hash, then reveal the
  test confirmation and write the scoped report plus `g1.json` (§5). `--profile` is mandatory and
  recorded before any scoring/selection metrics are exposed; a rerun cannot switch profile after
  seeing test results under the same manifest. `needs_more_data` writes an honest incomplete report,
  never fabricated values. The owner-pilot path does not require three humans.
- **`eval replay`:** §6. Implemented in M3a-T6, first used after G1.
- **`eval learning-curve`:** M7-T7; a complete owner-pilot artifact is sufficient for this offline check. Reads `eval.run_answers` of the runs listed in `apps/eval/config/g1.json`
  `runs`. For n=10/20/30/50/100, train on each rater's earliest **development** feedback by event time,
  with story groups disjoint from evaluation; evaluate every n against the same untouched test set.
  Frozen eval answers may construct synthetic event-time features for this offline simulation; mark
  them as eval data, never inject them into production feedback snapshots. Hold feature/card
  definitions fixed before the simulated feedback stream.
  Report eligible sample counts, activation/insufficient-data state, cards baseline, model AUC and
  logloss. Under activation minimums report cards-only plus an optional clearly marked research fit;
  do not pretend a production model exists after ten ratings. G1 test outcomes must not tune this
  learner; a proposed adjustment requires another holdout/version.

---

## 4. Metrics (`apps/eval/src/metrics`)

**Ranking** (per reading context and participant, language and experiment):
- **ROC AUC** of the score vs the rating (Mann–Whitney U, ties counted as ½), with a **95 % CI** from
  1,000 **paired story-group bootstrap** resamples; the same resampled groups compare candidate and
  baseline. Report ΔAUC and its CI directly. A group is resampled together across raters.
- **P@10, P@20:** like-rate among top k, with stable `(score DESC, firstSeenAt DESC, id DESC)` ties;
  report actual denominator and null when fewer than k eligible ratings exist.
- **Macro averages:** first aggregate supported contexts within each actual participant with equal
  context weights, then equally weight participants. Language summaries follow the same hierarchy.
  Bootstrap entire story groups across all copies/contexts; do not multiply effective sample size
  because one owner rated an article in several personas. Publish counts, class prevalence and both
  development/test tables. Never average a null single-class AUC as 0 or 0.5.
- **Win count:** actual participants beating the locked B1/B1-T baseline; context-level wins are
  separate diagnostics. The owner pilot always has one participant, however many contexts exist.

**Calibration** (heuristic card score P vs like-rate): ten fixed-width bins on [0,1], each bin's
count, mean score and positive fraction; ECE = Σ(n_bin/N)*|meanScore-positiveFraction|, plus Brier
score and logloss (clip only metric logarithms to [1e-6,1−1e-6]). Empty bins contribute zero. No fitted
calibration/threshold may use test labels. Report the full scoring pipeline and label prevalence.

**Enrichment accuracy** (Call A vs facet labels, per language):
- `content_type`: accuracy and macro-F1
- `topic_l1`: top-1 and top-2 accuracy
- `depth`: MAE (levels) and Spearman ρ
- `clickbait`, `promotional`, `time_sensitive`: AUC
- human κ (if available) as an agreement reference, with adjudication documented

**Policy:** For You precision and coverage, Maybe share, and hard-hide false negatives (liked items
hidden / all liked items), with denominators and uncertainty, separately by language/rater. Unknown
or failed answers are counted, not silently dropped.

**Operations:** tokens, $ per 1,000 **distinct processed articles at the measured card/holder mix**,
p50/p95 live-call latency by kind, coverage/failure/degraded rate. Cache lookup latency is separate;
cached calls are not counted as newly billed spend.

The report renders these as tables, plus one reliability plot (SVG) per language.

---

## 5. Decision rules for G1 (development selects, locked test confirms)

**Profile selection happens before scoring, not after a failed test.** Both profiles use the same
frozen grouped split, blind labels, cost limits, development-only parameter selection, paired
baselines and test confirmation below.

- **`owner_pilot`:** one actual participant with ≥250 distinct rated articles overall and ≥60 distinct
  held-out articles, including ≥10 likes and ≥10 dislikes. Each evaluated context/language cell needs
  ≥20 test articles and ≥5 of each class for its AUC; unsupported cells are explicitly unmeasured.
  Start with the owner's real language/topic coverage; missing Czech (or another target language)
  is a documented gap, not a made-up independent rater. A pilot pass applies only to the measured
  owner/context/language population. Keep default settings for unmeasured languages and mark them
  unvalidated in the production report; an initial owner-pilot beta must not claim validated quality
  for an unmeasured language.
- **`multi_person_beta` (optional later validation):** ≥3 **distinct actual participants**, each with ≥250 distinct non-skipped
  ratings and ≥60 held-out ratings/≥10 of each class; ≥50 test ratings/≥10 of each class per target
  language across participants. A reported participant-context-language cell needs ≥20 items/≥5 of
  each class. Count participant keys, never persona rows, in readiness and win requirements.

Incomplete engine variants or inadequate class support within a chosen profile yields
`needs_more_data` for that profile. With only the owner, produce the honest pilot report. Q13 has approved that narrower evidence for
the initial invite-only beta: `owner_pilot` + `pass` clears the evaluation launch gate and can be
applied to production. It still must satisfy every owner-pilot readiness/quality/coverage/cost rule;
adding personas never increases the actual participant count or repairs missing labels. There is no
additional ≥3-person requirement or unresolved owner waiver for this initial launch.

**Selection uses development only:**

1. Choose the better keyword baseline B1/B1-T by development macro AUC (ties choose native B1).
   Select E* from E1/E2/E3/E3b by development macro AUC; ties choose the cheaper native variant.
   E4/E5 are diagnostics/translation fallback evidence, not all-language core candidates.
2. Choose global card text mode: `english` if its paired development gain on non-English-card
   participant-contexts is ≥0.02 in the selected state family; otherwise `as_written`. If no eligible non-English
   card cohort exists, retain `as_written` and mark that decision unmeasured.
3. Choose `sk` and `cs` language modes **within that selected card text mode**. Compare native vs
   translated scores on the same language, raters and articles. Native suffices if its AUC is no
   more than 0.05 below English on bilingual-rater comparisons; choose translate only when it adds
   ≥0.02 AUC over native. If native falls short and translation does not help, retain native and
   recommend the Laya track. If bilingual comparison is unsupported, use the within-language
   translation gain and flag the English comparison as inconclusive. E4 must use the same selected
   card mode; tier-2 cap is 1000 only if its paired gain over tier 1 is ≥0.05 for SK or CS, else 300.
4. **One global threshold object:** pool development examples from the per-language variants chosen
   by steps 2–3. The schema has no per-language thresholds. Equal total weight per actual participant,
   then per supported context inside that participant, then per article inside that context; persona
   count cannot give the owner extra weight relative to independent testers.
   - `lanes.forYou`: smallest t on 0.50…0.85, step 0.05, with weighted like-rate among P≥t ≥0.70,
     at least 30 distinct items and at least 10% development coverage (beta also requires ≥2 actual
     participants; pilot uses its one identified participant); if unsupported, keep
     default 0.65 and mark the precision target **unmet**, not "achieved at 0.85".
   - `lanes.maybe`: largest t on 0.20…0.50, step 0.05, with weighted like-rate below t ≤0.15 and
     at least 30 distinct items (beta: ≥2 actual participants; pilot: one), also t<forYou. If unsupported keep default 0.35 (or the
     largest valid grid point below forYou) and mark target unmet.
   - `tiers`: retain defaults if development ECE≤0.10. Otherwise weighted isotonic regression of
     like-rate on raw score supplies the smallest score reaching 0.2/0.4/0.6/0.8. Require supported
     levels and four strictly increasing cuts in (0,1); otherwise retain all defaults. This adjusts
     tier boundaries only; it does not transform stored scores or justify probability wording.
5. Freeze the selected per-language composition, baseline, thresholds and run ids in a selection
   manifest **before the CLI reveals test metrics**. No output-derived retuning is permitted under
   the same test manifest.

**Confirmation uses the test only:** evaluate the actual selected per-language composition (not just
whichever single experiment won selection), against the locked B1/B1-T baseline on the same cohort.
Pass requires macro AUC≥baseline+0.05, macro AUC≥0.70, and a higher AUC than baseline for **every**
actual participant. In the owner pilot also report every supported context separately so averaging
does not hide a failing domain; a context is never claimed to be an independent human. Report paired
bootstrap CIs; point estimates determine this initial small-beta gate, so the
report must not claim population-level certainty. Also report all policy metrics and per-language
results. Hard-hide false negatives and unmet For You precision targets are explicit owner-review
items before launch; no threshold change is allowed to make those disappear from the report.

`fail` blocks claims of a passed gate for the selected profile and reports per-rater/language diagnostics and 20
worst-ranked liked articles (identify development vs test). The owner chooses remedies. Reusing
revealed test failures to change cards/questions/settings requires a new held-out golden version for
the next gate. M4–M7 work may continue after an unsuccessful experiment, but a failed/incomplete
owner-pilot artifact cannot clear the production gate. A passed one-person result is valid initial
launch evidence under Q13 and must still be described as one-person evidence.

**Budget:** measured production-policy $/1,000 **authorized uncached article revisions** ×
(expected daily authorized revisions **÷1000**) ×2,
rounded **up** to the next $0.50, minimum $1/day. Include translation/fallback and the expected
authorized card-holder mix once; use spec 05 §9's off/training/active demand model. The forecast
separates total fetched volume, exact selected-training count, newly active arrivals, explicitly
requested history and reusable cache hits. Document activation assumptions and an all-active/high-
volume sensitivity estimate; no implicit cost for all articles of untrained feeds. The
original price-per-1,000 figure must never be multiplied by raw article count without the divisor.

The report ends with readiness/selection/test tables, denominators, policy risks, applied defaults,
cost assumptions and anomalies. A passed and complete artifact from either profile can be applied
by `apply-g1` and the normal production settings flow (§1); owner-pilot scope remains visible.

---

## 6. Replay (after G1, required before risky changes)

`eval replay --against <runId> [--model jev-x.y.z] [--question-set enrich-v2] [--thresholds file.json]`

- Re-runs the G1 variant with the proposed change on `golden-v1`, cached where possible.
- **Reports:** ΔAUC per rater and language (with CIs), the mean |Δp| per question key, and the share of
  items changing lane.
- **Required** before:
  - changing `TYPESAFE_MODEL`
  - activating a new question set
  - changing `ranker.thresholds`
  - enabling Laya for a kind or language
- **Pass rule:** on the identical frozen cohort, no eligible rater/language AUC drops by more than
  0.03 and macro AUC does not drop. Threshold-only changes cannot be assessed by AUC (it is unchanged):
  also require no increase in hard-hide false-negative rate, no fall in For You precision >0.03,
  and report coverage/Maybe-share changes. Unsupported cells yield inconclusive, not pass.
- Dataset hashes, complete output coverage and the same paired bootstrap procedure are mandatory.
  Replays of a repeatedly viewed test set are regression checks, not new independent quality proof;
  use a fresh holdout for tuning/engine-selection claims.
- The report is committed to `apps/eval/reports/`.

---

## 7. Online metrics (nightly `house.metrics`, admin; spec 11 §6)

Use feedback-time `before` snapshots (spec 06 §8.2), never join feedback to the current lane/model,
which may have been changed by that very feedback. Reduce to one effective explicit rating per
user/article; undo/unrate removes it. Report trailing 7-day and 30-day windows with denominators,
source (`cards/model/degraded`), language, and prompt/calibration vs voluntary feedback splits.

- **Observed like-rate per lane/tier:** positive explicit ratings / all explicit ratings with a
  known pre-feedback lane. Treat monotonicity as a diagnostic; users choose what to rate and Maybe
  is sampled more. Alert on For You below Maybe only with ≥30 rated items in each lane and an
  uncertainty-supported gap persisting for three daily runs. It is not unbiased user satisfaction.
- **Maybe share:** Maybe / scored visible unread rows after the same folding/filter policy as the
  reader. Track with classifying backlog and source mix; a falling share alone does not prove
  improvement and is not a target to optimize blindly.
- **Observed recovery from Everything/Hidden:** distinct previously downranked articles later
  opened/liked/bookmarked, using pre-action score history. Report numerator and eligible denominator
  separately, and hide/unhide reasons. This is a lower-bound diagnostic: hidden articles are rarely
  exposed, so a low recovery rate **cannot** establish low false-negative regret. Use the blind
  golden set for that risk; no <2% production regret guarantee is inferred from unseen articles.
- **Personal models:** activation rate, effective sample/skip counts, median paired validation AUC
  and logloss change vs baseline, invalidation/failure rate and time back on cards-only ranking.
- **Engine:** p50/p95 live latency, errors, output coverage/degraded share and billed $/day, split
  by selected training vs active arrivals; off feeds must contribute zero authorized provider calls.
  Report shared cache savings and training queue age separately from all fetched article volume.

Store bounded aggregates in `settings['metrics.daily.<date>']`; retain 90 daily snapshots and no
article/card text or per-user traces in this shared settings object. Restrict it to admin responses.

---

## 8. Growing the golden set after launch (M9, optional)

This is **pseudonymous contribution**, not guaranteed anonymity: interests, private feed URLs and
article histories can identify a person even after removing an email. The feature stays disabled
until a separate schema/API/retention/consent design and privacy notice are approved.

- Explicit opt-in, off by default, describes the exact ratings, article metadata and interest text
  that will be copied, access, retention, external model processing and revocation behavior.
- Never retain `eval.rater_cards` references to production/private cards. Copy only consented,
  reviewed text snapshots into a separate version with random contributor ids; exclude tokens,
  credential-bearing URLs, names and private examples by default. Raw content is never committed to
  public git or published in reports.
- A restricted consent-to-contributor mapping allows withdrawal/account deletion to remove linked
  raw contributions and invalidate/rebuild derived evaluation versions. Do not promise both
  irreversible anonymization and individual deletion without a defined tradeoff.
- Keep train/development/test boundaries by user and story; production feedback is selection-biased
  and does not replace blind golden-v1 ratings. Replays run on both appropriate frozen versions.

## 9. Required evaluation tests

- Frozen article/card snapshots survive live content edits, purges and a full backup/restore.
- Story groups and rater copies never cross dev/test; test labels cannot be read during selection.
- Cache identity changes on provider/model/schema/state/questions/translation settings; failed calls
  do not poison cache and retries/resume do not duplicate answer rows or billing attribution.
- Skip counts, single-class metrics, empty bins/tails, short P@k and incomplete experiments never
  produce a fabricated pass; confidence intervals are paired and group-aware.
- Selected mixed-language deployment is tested, global thresholds remain valid, and budget units
  include the /1000 divisor. Threshold-only replay detects policy regressions despite unchanged AUC.
- Rating-token ownership/expiry/redaction and separate golden DB worker-mode guards are enforced.
- Learning curves use one unchanged holdout at every n and cannot tune on it; feedback-time online
  metrics survive reranking and undo without moving their original lane attribution.
- one owner with multiple topic contexts is one participant in readiness/macros/wins; owner-pilot
  PASS outputs support production settings and initial invite-only beta under Q13 without claiming
  multi-person evidence; failed/incomplete/dry-run outputs remain rejected
- absent language support is marked unmeasured; owner-only launch approval does not fabricate
  coverage or relax the chosen profile's quality/cost/holdout requirements
- cost forecasts use authorized uncached training/active demand, not all off-feed articles
