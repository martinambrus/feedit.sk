# Spec 10: Evaluation, golden set and gate G1 (`apps/eval`)

Status: **binding**. **Intent:** prove the core bet before building on it. The bet: interest cards +
Jev rank articles clearly better than keyword matching, with zero training, in EN, SK and CZ.
Measurement, not opinion, sets the open parameters: language modes, card text mode and lane
thresholds. Later, every change to questions, thresholds or the model version is replayed against the
same data.

No FeedIt.sk data is used (locked decision). The golden set is built from scratch.

Notation: `eval <command>` below is short for the root script `pnpm evaluate <command>` (spec 01 §2).

---

## 1. Outputs of gate G1

1. `apps/eval/reports/G1-<date>.md`: the human-readable report (the tables in §4, the decisions in §5,
   costs).
2. `apps/eval/config/g1.json`:

   ```json
   { "language_modes": { "en": "native", "sk": "…", "cs": "…" },
     "card_text_mode": "as_written" | "english",
     "ranker_thresholds": { …deep partial of RankerConfig… },
     "recommended_daily_budget_usd": 2.0,
     "translate_tier2_daily_cap": 300 | 1000,
     "laya_track_recommended": false,
     "runs": { "B0": 11, "B1": 12, "E1": 13, … },      // eval.runs ids used for the decisions (M7-T7 reads them)
     "notes": "…" }
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
   | `laya_track_recommended`, `runs`, `notes` | not settings; kept in the report and in git |

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
    Jev costs
  - fetches each feed once immediately, waits until the `article.extract` queue for these articles has
    drained, and prints per-language article counts
  - **`--watch`** keeps the eval user subscribed and prints counts every 10 minutes, until stopped
  - between the first run and rating, the normal schedule keeps fetching, because the eval user is a
    subscriber
- **Sample (`eval sample`):** up to 1,500 non-stale articles, 500/500/500 by detected language (fewer
  if a language runs short), preferring the most recent. Stored in `eval.sample`.
- **Status (`eval status`):** per-language sample counts, then per rater: cards written, feeds picked,
  assigned, rated, skipped. Also facet-label counts per language.

### 2.2 Raters (3–5 people: the owner plus testers with different tastes)

- **`eval rater add --name <n> --langs sk,en`** creates `eval.raters` and prints a private URL with a
  token: `${EVAL_PUBLIC_URL}/r?t=<token>`. `EVAL_PUBLIC_URL` defaults to `http://localhost:5180`.
- **Step 1 in the rating app: write interests first.** Before seeing any article, each rater writes
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
  - shuffled deterministically (seeded by the rater id)
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
- Not deployed to production. It runs **on the dev box** (the same DB as M3b), exposed to raters
  through a tunnel (e.g. `cloudflared tunnel --url http://localhost:5180`). `golden-v1` therefore lives
  in the dev database that M3b and M7-T7 use. Back it up with `pg_dump -n eval` after collection.

---

## 3. Experiments (`eval run --experiment <id> [--langs] [--raters]`)

Each run:
- writes `eval.runs` (config, git sha) and `eval.run_answers`
- caches every engine call in `${EVAL_CACHE_DIR}/<sha256(model + state + questions)>.json`
  (default `~/.cache/feedit-eval`, outside the repository and shared by worktrees), so re-runs and
  report tweaks cost nothing
- uses the production packages: `questions`, `engine`, `translate`, `ranker`
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
| **B1** BM25 | native (translated text for SK/CZ in B1-T) | as written | baseline: keyword match (spec 06 §9) |
| **E1** native | native | as written | the core bet in its simplest form |
| **E2** native + EN cards | native | cards translated to English by LibreTranslate | does English card text help? |
| **E3** LT | translated with LibreTranslate | as written | free translation |
| **E3b** LT + EN cards | translated with LibreTranslate | English | both |
| **E4** GLM | translated with Ollama `glm-5.3-flash` | the better of as-written/English from E2 vs E1 | quality ceiling of the fallback translation (SK/CZ only) |
| **E5** Laya zero-shot (optional) | native | as written | expected near random; recorded only as the M9 baseline. Skip if `laya` is not installed |

**Per article and rater:**
- Call A (`enrich-v1`) once per article and variant.
- Call B with the **rater's cards** (all raters' cards can share one call per article: namespaced
  keys, exactly like production).
- The score is computed with `packages/ranker` card scoring (spec 06 §4.1). No demotions and no model:
  the eval measures the zero-training case. A rater's never-card with `p ≥ 0.7` sets the score to 0 (the
  item would be hidden). Soft never-matches are ignored.

**Estimated G1 cost:**
1,500 articles × ≈ 5.5k tokens × 6 variants ≈ 50M tokens ≈ **$2.1**, plus GLM translation of about
1,000 SK/CZ articles ≈ **$0.15**.

**Other commands:**
- **`eval dry-run`:** runs the whole pipeline on synthetic data (M3a-T8) in a **separate database**
  `feedit_eval_dryrun`, freshly created from the current template **and seeded** (spec 02 §1.1). The
  eval process connects through `TEST_ADMIN_DATABASE_URL` only to create it. It writes
  `reports/DRYRUN-<date>.md` and `reports/DRYRUN-<date>.g1.json`, both git-ignored, and never touches
  the real `eval` tables.
- **`eval replay`:** §6. Implemented in M3a-T6, first used after G1.
- **`eval learning-curve`:** M7-T7. Reads `eval.run_answers` of the runs listed in `apps/eval/config/g1.json`
  `runs`.

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

1. **Core bet.** Let E* be the best of {E1, E2, E3, E3b} by macro AUC over all raters and all
   languages. E4 and E5 cover only part of the data and are not eligible. **Pass** requires:
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
   - `translate_tier2_daily_cap` = **1000** if E4 AUC(lang) ≥ E3 AUC(lang) + **0.05** for sk or cs;
     otherwise **300**. Tier 2 stays a fallback (locked decision).
3. **Card text mode:** `english` if (E2 − E1) or (E3b − E3) ≥ **0.02** macro AUC measured on
   non-English cards; otherwise `as_written`.
4. **Thresholds.** Pool the rater data per language, using the experiment rules 2–3 chose for that
   language:
   - `native` + `as_written` → E1
   - `native` + `english` → E2
   - `translate` + `as_written` → E3
   - `translate` + `english` → E3b

   Then, over the pooled items:
   - `lanes.forYou` = the smallest t ∈ [0.50, 0.85] (step 0.05) where the like-rate among items with
     P ≥ t is **≥ 0.70**; if none, 0.85
   - `lanes.maybe` = the largest t ∈ [0.20, 0.50] where the like-rate among items with P < t is
     **≤ 0.15**; if none, 0.35
   - `tiers`: keep the defaults if ECE ≤ 0.10. Otherwise fit isotonic regression of like-rate on P and
     set each tier cut point at the smallest P where the fitted like-rate reaches 0.2 / 0.4 / 0.6 / 0.8.
     A level the fit never reaches keeps its default. The four cut points must end up strictly
     increasing; otherwise all four keep their defaults.
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

---

## 8. Growing the golden set after launch (M9, optional)

- A user setting "Help improve FeedIt: share my ratings anonymously for evaluation" (off by default)
  lets the nightly job copy that user's explicit ratings of the last 30 days into a new golden version
  (`golden-v2`, with a new `eval.raters` row per consenting user and no email or name).
- The user's cards are copied as `eval.rater_cards` references (text only, no user id).
- Replays (§6) then run on both `golden-v1` and `golden-v2`.
- This needs a privacy-policy update before it is enabled.
