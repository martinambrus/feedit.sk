# FeedIt Next Gen: background and rationale

> This file explains *why* the plan looks the way it does. It covers lessons from the two earlier
> prototypes, what the Jev demos and docs taught us, and the risks. It is reference material. The
> executable plan is [`PLAN.md`](./PLAN.md), and the detailed specs are in [`specs/`](./specs/).
> Nothing here overrides a spec. If they disagree, the spec wins.

## 1. The idea in one page

FeedIt had one core promise: **"show me only the articles I care about, and learn what that means
from my likes and dislikes."** Both earlier attempts tried to deliver this by building a scoring
engine by hand:

- **FeedIt.sk** scored title words, trigrams, authors, categories and phrases per user and per feed. It combined
  them with hand-tuned constants (`+1`, `+25`, `0.1`, `0.01`, a "well-trained" threshold of `163`, tier
  cut-offs of 5/10/30/50 %).
- **DreamCatcher** planned embeddings, hybrid RAG search and a slow generative-LLM pass that returned a
  JSON score, tags and reasons for every article. That pass was never built.

The next generation replaces the hand-built scoring with **TypeSafe's Jev**, a "System One" decision
model. You send Jev a *state* (the article) and a set of typed *questions* (Choice / Score / Noul). It
returns calibrated probabilities plus a confidence value in about 70–500 ms. It costs $0.042 per million input
tokens, and output is free. It cannot generate text, so it cannot hallucinate free-form answers. It is the
"smart if-statement" this product always needed.

The design in one picture:

```
            ┌───────────── once per article (shared by ALL users) ─────────────┐
 feed ─►  fetch ─► normalize/dedup ─► extract ─► ENRICH (Jev call A)          │
            │                                     "what is this article?"    │
            └──────────────────────────────────────────────────────────────────┘
                                                   │  topic probs, content type,
                                                   │  depth, clickbait, promo, …
            ┌──── once per article × *distinct interest cards* on that feed ───┐
            │  MATCH (Jev call B): "does it satisfy interest card X?" (Nouls)  │
            └──────────────────────────────────────────────────────────────────┘
                                                   │
            ┌──────────────── per user, in code, microseconds ─────────────────┐
            │  RANK: rules (mutes/boosts) + interest match + tiny learned model │
            │  → calibrated "P(you'll like it)" → tiers / Maybe lane / hidden   │
            └──────────────────────────────────────────────────────────────────┘
                                                   │
                     user feedback (👍/👎 + one-tap *reason*) ──► updates the tiny model,
                     proposes new interest cards, feeds the eval set
```

Five ideas carry most of the design:

1. **Interest cards instead of word weights.** A user describes what they want in plain language
   ("new EV battery chemistry, not stock-price news"). The system also offers cards from a shared
   library. Jev evaluates every new article against every card. There is nothing to train before the
   first useful result, which removes FeedIt's "train 200 articles first" wall.
2. **Understand each article once, for everyone.** The per-article enrichment call is shared across
   tenants. DreamCatcher's global article store already works this way, so reuse it.
3. **The model's calibration replaces hand balancing.** Jev's probabilities are trained to be calibrated.
   Tiers become probability buckets instead of hand-tuned percent thresholds. When per-user learning
   is needed, a tiny logistic model learns the weights, so nobody has to tune them.
4. **Confidence is a product feature.** High-confidence "no" items get hidden. Low-confidence items go
   to a *Maybe* lane and are the articles the app asks you to rate. This is active learning: you only
   train where the model is unsure.
5. **Feedback carries reasons, not just a sign.** A 👎 comes with an optional one-tap reason
   ("off-topic", "clickbait", "already seen this story", "too shallow", "promo"). Each reason maps onto a
   Jev question that already exists, so a dislike becomes negative evidence. The old engine could never
   use dislikes that way.

---

## 2. What we learned from the two predecessors

### 2.1 FeedIt.sk (this repo): keep the UX, drop the scoring engine

**Worth keeping**

- The whole interaction model, which is the reason the product exists:
  - swipe or keyboard like/dislike (`js/train-on-swipe.js`, CTRL+PLUS/MINUS)
  - "train the whole feed" in one action
  - Simple Mode
  - a 1–5 tier slider with "Hide non-interesting"
  - sort by score or by date
  - status filters: unread, untrained, trained+, all
  - bookmarks protecting items from archiving
  - labels with *suggested* labels you tap to make permanent
  - per-feed settings: duplicates allowed, language, manual priorities, adjustment phrases
- Passwordless email-code login.
- The "detailed training" modal, which *explains* what the system thinks about an article. The concept
  stays, but it now shows interest-card matches and article facets instead of word weights.
- Duplicate detection: FeedIt already compared link, title + first 80 characters of the description,
  and image. DreamCatcher only dedups per feed, so this is a regression to fix.
- Ideas from `todo.txt` that the new design makes cheap:
  - temporary keyword muting (e.g. for a Google News story you have already read)
  - the "did you like it?" prompt after returning from an article
  - article-length buckets
  - per-article language
  - linked feeds / copying training between feeds. With interest cards this comes free, because cards
    are not tied to a feed.
  - OPML import and export

**What went wrong, and why the new design avoids it**

| FeedIt.sk problem | Root cause | Next-gen answer |
|---|---|---|
| Only the **title** is scored. The description and body are ignored. | Word statistics need clean, short text. | Jev reads title, excerpt and the start of the body as structured state. |
| **Dislikes teach almost nothing.** They only raise `weightings`, which dilutes the interest %. | Positive-evidence-only word counting. | A dislike + reason is a labelled example for the per-user model and maps to explicit negative features. |
| Magic constants: `+25` trigrams, `0.1` authors, `0.01` categories, `163`, 5/10/30/50 %, ±300/±3000 boosts that push interest % into the thousands. | Hand-balancing heterogeneous signals. | Calibrated probabilities. Where weights are needed, they are *learned*. |
| Cold start: a feed needs ≥200 articles, 32 % trained and ≥4 % liked before tiers work. The "well-trained" flag is never re-evaluated. | Nothing works until statistics accumulate. | Interest cards work on article #1. Learning only *refines* them. |
| Every vote runs `updateMany` over every unread article containing the word. | Scores are denormalized into each article row. | Jev answers are stored once. Ranking is a cheap per-user function evaluated at read time or incrementally. |
| Per-user collections (`words-<id>`, `training-<id>` …), and crons process only the first 100 accounts (`limit => 100`, sorted on a non-existent field). | Tenancy bolted onto per-user collections. | Proper relational multi-tenancy with a shared article layer and per-user state tables. |
| Labels are predicted by word overlap with previously labelled titles. | Nothing better was available. | A label *is* a question: user-defined labels become Nouls or a Choice over label descriptions. |
| Training is per feed and cannot be shared (`todo.txt`: "mighty complicated due to all dependencies of dependencies"). | Word weights are tied to feed vocabularies. | Interest cards are feed-independent by construction. |

### 2.2 DreamCatcher: keep the ingestion pipeline, cut the ceremony

**Worth keeping** (this is the solid part and should be ported almost verbatim):

- A global, shared feed and article store. Each feed is fetched once for all subscribers, and fetching
  only happens while a feed has subscribers.
- The adaptive fetch-interval algorithm (`update_feed_after_fetch_success` / `_failed`):
  - 5-minute steps
  - a 20 h grace period for daily feeds
  - a 10-day cap
  - quarantine after repeated errors
- Robust fetching: per-feed distributed lock, keep-alive + DNS cache, charset guessing, URL repair,
  following redirect pages (Google News), JSON Feed support, and image extraction from enclosure,
  media:thumbnail or the first `<img>`.
- Full-article extraction, with the raw title, description and body kept "so we can re-train later".
  That turns out to be exactly what allows **re-asking Jev** when the question set changes.
- Crash-replay of in-flight jobs and OpenTelemetry tracing across stages.

**What to change**

- **Too much infrastructure for the stage the product is at.** Three-node Postgres with repmgr, three Kafka nodes,
  Redis Sentinel and Elasticsearch are not needed before there are users. Start with **one
  Postgres** plus a Postgres-backed job queue (e.g. `pg-boss` or `graphile-worker`) and keep the *stage
  boundaries* so a broker can be swapped in later.
- **Vendored shared libraries synced by GitHub Actions** caused schema drift. Use a monorepo with
  workspace packages (`packages/db`, `packages/jev`, …) and one Prisma/Drizzle schema.
- **Dedup is per-feed, check-then-insert, with no unique constraint** (partitioning prevented one).
  Replace it with a canonical-URL + content-hash unique key and cross-feed story clustering (spec 03 §6, spec 05 §6).
- **One partition per feed per month** will explode. Partition `articles` by month only, or not at
  all until volume demands it.
- **In-memory retry maps and debounce timers** lose state on restart. Keep all retry state in the job
  queue.
- `rejectUnauthorized: false` everywhere: remove it, and allow it only per feed as an explicit opt-in.
- The RAG layer (chunks, `vector(768)`, hybrid search) is **not needed for classification** in this
  design. Keep it as an optional later feature for "search my archive / ask about my reading",
  not on the scoring path.
- Many seed feeds are **Slovak/Czech**. See §3.2: this is the biggest open technical risk with Jev.

### 2.3 The Jev demos: what they show us

**elvisun/newsjack** (a news-relevance filter, the closest analogue to our problem):

- **A cascade.**
  - Layer A runs once per headline: an `is_news` Noul, a `desk` Choice, a `story_type` Choice and six 5-level Scores.
  - It gates on `is_news >= 0.5`.
  - Layer B then asks **one namespaced question set for all 15 clients in a single call**
    (`"${clientId}.${key}"`, 90 questions, ~10k tokens, ~320 ms).
  - This is the model for our per-interest-card matching.
- **Facts separate from verdicts.** A `decision` Choice (keep / monitor_only / reject) comes with supporting
  Nouls (`is_news`, `profile_bridge`, `promotional`, `safety_risk`). **Deterministic post-rules in code** then apply
  floors, e.g. a reject with confidence < 0.55 becomes monitor_only. Every fired rule is recorded.
- **Asymmetric error costs**, written into the instructions: *"a false positive is cheap, a dropped real
  opportunity is expensive. When in doubt, keep."* For a reader the same holds. Hiding a great article is
  worse than showing a mediocre one.
- **Fallback labels are read from the probability distribution** instead of re-asking.
- **Operations.**
  - Worker pool of 8, 4 attempts with exponential backoff on 429/5xx.
  - A failed item degrades to "monitor", never "drop".
  - If more than 20 % of calls fail, fall back to an LLM engine.
  - The sha256 of the question set is stored with each result, and raw answers are kept so results can be
    re-scored offline without new API calls.
- **Measured numbers.** 384 headlines in 24.9 s for $0.19. On 176 signals: 8.3 s, $0.013, p50 ≈ 260 ms,
  and 76.7 % agreement with a Haiku-based filter.
- **Their doctrine:** tune the *criteria wording*, not the post-rules.

**fhshaik/typesafe-mario** (Jev plays Super Mario from RAM-derived JSON):

- **State is structured JSON grouped by meaning, never prose.** Exact arithmetic is done in code and
  passed as typed booleans (`jump_must_start_this_decision`). The model only interprets.
- **Question criteria are built per call** from the currently allowed actions. Our equivalent is building
  criteria from the user's own interest cards and labels.
- **Everything is logged:** state, probabilities, confidence and latency, as JSONL. That log is the eval set.

**TypeSafe docs, the parts that matter for us** (`docs.typesafe.ai`, jev-1.13, reviewed 2026-09-17):

- Three primitives:
  - **Choice**: up to 255 options, returns probabilities + confidence.
  - **Score**: 2–10 ordered levels, returns a probability-weighted index + confidence.
  - **Noul**: returns P(yes).
- `instructions` and `criteria` accept **structured JSON**. Option descriptions can include `what`,
  `not_for` and **`examples`**. *This is how per-user examples ("articles I liked") get in without
  fine-tuning.*
- **Limits.**
  - 64k tokens per request (state + all questions).
  - 32k for state + the longest question.
  - Rate limit of 250k tokens/s and 1,200 req/min, "adjusting dynamically".
  - Text only.
  - **English is the primary language**; other languages are "handled but not equally well".
- **Speculative fan-out:** ask *all* questions in one call. In their test, 13 questions in one call cost 12.2× less and ran 10× faster than 13
  sequential calls.
- **Known weak spots, all avoidable by design:**
  - counting and math
  - date comparison
  - multi-hop indirection
  - a large state full of irrelevant text ("context rot")
  - adversarial content
  - Nouls where "true" means "no"
  - comparing thresholds across different question types
- **Jev is never fine-tuned per customer.** Customization happens only through state, instructions and
  criteria. Their own cookbook ("Autoresearch feature discovery") shows the pattern we adopt for
  personalization: **Jev answers → numeric features → a small classical model trained on labels.**
- Confidence is *not* the top probability. It measures how concentrated the distribution is, and in
  practice it runs lower. Newsjack saw a median confidence of 0.53 against a median top probability of 0.68.
  Thresholds must be tuned on our own data.

---

## 3. Risks

### 3.1 Vendor/model risk

Jev is new and single-vendor. The mitigations are the `DecisionEngine` abstraction, the LLM fallback, pinned
versions, stored raw answers, and the fact that no user data is locked into the vendor (cards are plain text).

### 3.2 Language (important for Slovak/Czech feeds)

TypeSafe states that English is the primary training language and other languages are lower-accuracy. The
options, to be decided by the eval ([spec 10](./specs/10-evaluation.md)), not upfront:

- **(a)** Send native text and rely on it. Measure on the golden set first ([spec 10](./specs/10-evaluation.md)), which is deliberately
  balanced across EN/SK/CZ.
- **(b)** Keep the questions and card text in English but the state in the native language. The model then
  handles cross-lingual matching. It is often better than fully native prompts, but that needs to be
  measured.
- **(c)** Translate the title + excerpt (+ body lead) to English before Call A/B, and store the translation
  (`article_translations`). Translation happens once per article and is shared by all tenants. On the
  CPU-only box the primary path is a **dedicated MT model** (OPUS-MT / LibreTranslate, free and fast on
  CPU). **Ollama Cloud GLM** is the fallback for failures and weak translations. Backends and numbers are
  in [spec 07](./specs/07-translation.md).

- **(d)** Fine-tune the open-weights **Laya-multilingual** model (Apache 2.0, mmBERT-base) on EN/SK/CZ
  data labelled by a teacher, and route SK/CZ articles to it for the fixed enrichment questions. Laya is
  near random zero-shot, so it can't replace Jev for free-form interest cards without further work. The
  full analysis is in [`laya-multilingual.md`](./laya-multilingual.md).

The recommendation is to prototype (a) and (b) on the golden set in week 1, with Laya zero-shot as a
baseline. Adopt (c) if both underperform, and start (d) in parallel only if SK/CZ accuracy stays
clearly below EN.

### 3.3 Adversarial or promotional content

Article text can argue for its own classification. The mitigations:

- Jev only sees data in state
- post-rules never *hide* on a single low-confidence answer
- promotional content is explicitly modelled

### 3.4 Cost at scale

Cost scales with articles × distinct cards per feed, not with users. [`specs/05-classification.md`](./specs/05-classification.md) §9 has the
numbers, which are cents to single dollars per day for thousands of users. Personal example forks are the
one place where cost grows with each user, so cap them.

### 3.5 Privacy

Card texts and ratings are personal data. The article content sent to Jev is public. Only card text and
example titles leave our system. Vercel AI Gateway supports zero data retention, and TypeSafe offers ZDR on
enterprise plans.

---

## 4. Sources

- This repo (FeedIt.sk prototype): `cron/links-trainer.php`, `functions/functions-score-global.php`,
  `functions/functions-training.php`, `functions/functions-content.php`, `cron/tiers-training-check.php`,
  `cron/auto-archive.php`, `todo.txt`.
- DreamCatcher: `PLAN.md`, `infrastructure/postgre/init/sql_init.sql`, `workers/*`, `gpt.txt`, `ui/index.html`.
- [elvisun/newsjack](https://github.com/elvisun/newsjack): `apps/cli/cmd/newsjack/coarse_filter.go`,
  `coarse_filter_questions.json`, `demos/news-desk-dealer/src/engine/*`, `docs/2026-09-18-jev-coarse-filter-plan.md`.
- [fhshaik/typesafe-mario](https://github.com/fhshaik/typesafe-mario): `src/typesafe_mario/policy.py`, `state.py`, `runner.py`.
- TypeSafe docs: [index](https://docs.typesafe.ai/llms.txt), [State](https://docs.typesafe.ai/concepts/state.md),
  [Advanced structure](https://docs.typesafe.ai/primitives/advanced.md), [Models & limits](https://docs.typesafe.ai/models.md),
  [Jev 1.13 jaggedness](https://docs.typesafe.ai/model-jaggedness/jev-1.13.md), [Confidence](https://docs.typesafe.ai/confidence.md),
  [Speculative fan-out](https://docs.typesafe.ai/patterns/fan-out.md), [Composite scoring](https://docs.typesafe.ai/patterns/composite-scoring.md),
  [Autoresearch feature discovery](https://docs.typesafe.ai/cookbooks/autoresearch_feature_discovery.md),
  [Introducing System One models](https://typesafe.ai/blog/introducing-system-one-models-and-jev),
  [Jev on OpenRouter](https://openrouter.ai/docs/guides/community/jev), [jevai.net](https://jevai.net/) (third-party overview site).
