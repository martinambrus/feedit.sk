# Spec 10: Evaluation, golden set and gate G1 (`apps/eval`)

Status: **binding**. **Intent:** prove the core bet before building on it. The bet: interest cards +
Jev rank articles clearly better than keyword matching, with zero training, in EN, SK and CZ.
Measurement, not opinion, sets the open parameters: language modes, card text mode and lane
thresholds. Later, every change to questions, thresholds or the model version is replayed against the
same data.

No FeedIt.sk data is used (locked decision). The golden set is built from scratch.

---

## 1. Outputs of gate G1

1. `apps/eval/reports/G1-<date>.md`: the human-readable report (the tables in §4, the decisions in §5,
   costs).
2. `config/g1.json`:

   ```json
   { "language_modes": { "en": "native", "sk": "…", "cs": "…" },
     "card_text_mode": "as_written" | "english",
     "ranker_thresholds": { …RankerConfig overrides… },
     "recommended_daily_budget_usd": 2.0,
     "laya_track_recommended": false,
     "notes": "…" }
   ```

3. `pnpm --filter eval cli apply-g1 config/g1.json` writes these into `settings` (dev DB). In
   production, an admin pastes them in through `PATCH /admin/settings`.

---

## 2. Building the golden set `golden-v1`

### 2.1 Feeds and articles

- **Feed list:** `apps/eval/data/feeds-golden.txt`, about 60 feeds: about 20 English, 20 Slovak and
  20 Czech. It mixes news, tech, science, sport, lifestyle, local and classifieds, and includes at least
  one Google News feed and one feed with poor excerpts.
- **`eval ingest-sample --feeds data/feeds-golden.txt --days 14`:**
  - subscribes an internal `eval` system user to the feeds
  - runs the real ingestion pipeline (M1) with enrich and match **disabled**
    (`EVAL_INGEST_ONLY=true` in the worker), so there are no Jev costs yet
  - waits until 14 days of items exist, using feed history where available and continued fetching
    otherwise
- **Sample:** 1,500 non-stale articles, stratified 500/500/500 by detected language (fewer if a
  language runs short), recorded in `eval.assignments` per rater (§2.2).

### 2.2 Raters (3–5 people: the owner plus testers with different tastes)

- **`eval rater add --name <n> --langs sk,en`** creates `eval.raters` and prints a private URL with a
  token: `http://<eval-host>:5180/r?t=<token>`.
- **Step 1 in the rating app: write interests first.** Before seeing any article, each rater writes
  **5–10 interest cards** (and optionally 1–3 "never" cards) in their own words, in their preferred
  language. Stored as real `interest_cards` (visibility `shared`) and `eval.rater_cards`. The spec 05
  authoring rules are shown as hints.
- **Step 2: pick feeds.** The rater ticks the golden feeds they would actually subscribe to (at least
  10) → `eval.rater_feeds`.
- **Step 3: rate.** The rater gets **300 articles** from their feeds, shuffled, with language shares
  following their `langs`.
  - **Blind:** no model output is shown.
  - The page shows the feed, title, excerpt (≤ 600 chars) and "open original".
  - Buttons: 👍 "I'd want to read this" / 👎 "Not for me", plus an optional reason (the spec 09 reason set).
  - Keyboard: `+`/`-`, `1`–`6`, `j`/`k`.
  - Progress is saved on every click (`eval.ratings`) and ratings can be changed.
  - A rater may skip an article, and skips are not stored. Goal: ≥ 250 ratings per rater.

### 2.3 Facet labels (the enrichment accuracy check)

- **`eval serve-rating`** also serves `/facets?t=<token>`: 100 articles per language (sampled from the
  1,500).
- A labeller sets `content_type`, `topic_l1`, `depth` (0–4), `clickbait` (y/n), `promotional` (y/n),
  `time_sensitive` (y/n).
- At least one labeller (the owner). A second labeller does 50 of the same articles so human agreement
  (Cohen's κ) serves as the ceiling.

### 2.4 Rating app (`apps/eval/src/rating-server`)

- Fastify on port 5180, with vanilla HTML + CSS + a small ES-module script (no framework).
- Mobile-friendly and keyboard-driven.
- Token auth through the query string, which sets a cookie.
- Uses `DATABASE_URL_WORKER`.
- Not deployed to production. It runs on the dev box or a temporary VPS during M3a/M3b.

---

## 3. Experiments (`eval run --experiment <id> [--langs] [--raters]`)

Each run:
- writes `eval.runs` (config, git sha) and `eval.run_answers`
- caches every engine call in `apps/eval/.cache/<sha256(model + state + questions)>.json`, so re-runs
  and report tweaks cost nothing
- uses the production packages: `questions`, `engine`, `translate`, `ranker`

| Id | State variant | Card text | Purpose |
|---|---|---|---|
| **B0** chrono | — | — | baseline: newer = higher |
| **B1** BM25 | native (translated text for SK/CZ in B1-T) | as written | baseline: keyword match (spec 06 §9) |
| **E1** native | native | as written | the core bet in its simplest form |
| **E2** native + EN cards | native | cards translated to English by LibreTranslate | does English card text help? |
| **E3** LT | translated with LibreTranslate | as written | free translation |
| **E3b** LT + EN cards | translated with LibreTranslate | English | both |
| **E4** GLM | translated with Ollama `glm-5.3-flash` | the better of as-written/English from E2 vs E1 | quality ceiling of the fallback translation (SK/CZ only) |
| **E5** Laya zero-shot (optional) | native | as written | expected near random; recorded only as the M10 baseline. Skip if `laya` is not installed |

**Per article and rater:**
- Call A (`enrich-v1`) once per article and variant.
- Call B with the **rater's cards** (all raters' cards can share one call per article: namespaced
  keys, exactly like production).
- The score is computed with `packages/ranker` card scoring (spec 06 §4). No demotions and no model:
  the eval measures the zero-training case.

**Estimated G1 cost:**
1,500 articles × ≈ 5.5k tokens × 6 variants ≈ 50M tokens ≈ **$2.1**, plus GLM translation of about
1,000 SK/CZ articles ≈ **$0.15**. The eval CLI prints the estimate and asks for confirmation before
spending more than $1.

---

## 4. Metrics (`apps/eval/src/metrics`)

**Ranking** (per rater, per language, per experiment):
- **ROC AUC** of the score vs the rating (Mann–Whitney U, ties counted as ½), with a **95 % CI** from
  1,000 bootstrap resamples of that rater's articles.
- **P@10, P@20:** the like-rate among the top k by score.
- **Macro averages** across raters; per-language splits (EN / SK / CZ).
- **Win count:** for how many raters the experiment beats B1.

**Calibration** (card score P vs like-rate): a 10-bin reliability table and **ECE**.

**Enrichment accuracy** (Call A vs facet labels, per language):
- `content_type`: accuracy and macro-F1
- `topic_l1`: top-1 and top-2 accuracy
- `depth`: MAE (levels) and Spearman ρ
- `clickbait`, `promotional`, `time_sensitive`: AUC
- the human κ (if available) as the reference ceiling

**Operations:** tokens, $ per 1,000 articles, and p50/p95 latency per call kind.

The report renders these as tables, plus one reliability plot (SVG) per language.

---

## 5. Decision rules for G1 (apply mechanically; the report shows each rule's inputs)

1. **Core bet.** Let E* be the best experiment by macro AUC. **Pass** requires:
   - E* macro AUC ≥ B1 macro AUC + **0.05**
   - E* macro AUC ≥ **0.70**
   - E* wins against B1 for **every** rater (the point estimate is enough)

   **Fail** → stop the build after M3 and report to the owner, with the per-rater breakdown and the 20
   worst-ranked liked articles. Likely remedies: card-writing guidance and examples (spec 05 §5.1), the
   Laya track, or an LLM classifier. The owner decides.
2. **Language mode** for `sk` and `cs`, each separately:
   - if E1 AUC(lang) ≥ E1 AUC(en) − **0.05** → `native`
   - otherwise, if E3 AUC(lang) ≥ E1 AUC(lang) + **0.02** → `translate`
   - otherwise keep `native` and set `laya_track_recommended = true`
   - if E4 AUC(lang) ≥ E3 AUC(lang) + **0.05**, note it in the report and set
     `settings['translate.tier2_daily_cap']` higher (e.g. 1,000). Tier 2 stays a fallback (locked
     decision).
3. **Card text mode:** `english` if (E2 − E1) or (E3b − E3) ≥ **0.02** macro AUC measured on
   non-English cards; otherwise `as_written`.
4. **Thresholds** (computed on pooled rater data of the chosen variant):
   - `lanes.forYou` = the smallest t ∈ [0.50, 0.85] (step 0.05) where the like-rate among items with
     P ≥ t is **≥ 0.70**; if none, 0.85
   - `lanes.maybe` = the largest t ∈ [0.20, 0.50] where the like-rate among items with P < t is
     **≤ 0.15**; if none, 0.35
   - `tiers`: keep the defaults if ECE ≤ 0.10. Otherwise fit isotonic regression of like-rate on P and
     set the tier cut points at the P values where the fitted like-rate crosses 0.2 / 0.4 / 0.6 / 0.8.
5. **Budget:** `recommended_daily_budget_usd` = the measured $/1,000 articles × the expected daily
   articles for the invite-only beta (spec 05 §9) × 2, rounded up to $0.50, with a minimum of $1.

The report ends with a filled-in decision table and a list of anomalies (e.g. a question with
`invalid_response`, a feed with bad excerpts).

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
- **Pass rule:** no rater's AUC drops by more than 0.03, and the macro AUC does not drop.
- The report is committed to `apps/eval/reports/`.

---

## 7. Online metrics (computed nightly by `house.metrics`, shown in admin; spec 11 §6)

- **Like-rate per lane and tier:** it must be monotonic. Alert if `for_you` falls below `maybe`.
- **Maybe-lane share of scored unread items:** should fall as users rate.
- **Regret rate:** the share of articles in `everything`/`hidden` that the user later opened, rated +1
  or bookmarked. Target < 2 %.
- **Personal models:** activation rate and median `cv_auc − baseline_auc`.
- **Engine:** p50/p95 latency, error rate, degraded share, $/day.

Stored in `settings['metrics.daily.<date>']` (small JSON). No separate metrics DB.
