# FeedIt Next Gen: an RSS reader that asks instead of trains

> Status: design plan, September 2026. Written inside the `feedit.sk` repo so it can be compared
> against the first prototype (this repo, PHP + MongoDB) and the follow-up
> ([DreamCatcher](https://github.com/martinambrus/DreamCatcher), TypeScript + Kafka + Postgres).
> The plan is meant to move into its own repository later.
>
> **Decisions taken (2026-09-24):** TypeScript monorepo; multi-tenant from day one; a generative LLM is
> allowed only as the fallback decision engine and for translation; no data or accounts migrate from
> FeedIt.sk, and everything is trained or fine-tuned from scratch; self-hosted on one box with minimal
> spend and no GPU; invite-only signups at launch; translation via local Ollama/GLM is acceptable at lower
> quality. See §7.6.
>
> Companion document: [`jev-questions.md`](./jev-questions.md) holds the concrete Jev question sets,
> request shapes and cost math. [`laya-multilingual.md`](./laya-multilingual.md) evaluates Laya, the
> open-weights Jev alternative, for EN + SK + CZ fine-tuning and self-hosting.

---

## 0. TL;DR

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

## 1. What we learned from the two predecessors

### 1.1 FeedIt.sk (this repo): keep the UX, drop the scoring engine

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

### 1.2 DreamCatcher: keep the ingestion pipeline, cut the ceremony

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
  Replace it with a canonical-URL + content-hash unique key and cross-feed story clustering (§5.3).
- **One partition per feed per month** will explode. Partition `articles` by month only, or not at
  all until volume demands it.
- **In-memory retry maps and debounce timers** lose state on restart. Keep all retry state in the job
  queue.
- `rejectUnauthorized: false` everywhere: remove it, and allow it only per feed as an explicit opt-in.
- The RAG layer (chunks, `vector(768)`, hybrid search) is **not needed for classification** in this
  design. Keep it as an optional later feature for "search my archive / ask about my reading",
  not on the scoring path.
- Many seed feeds are **Slovak/Czech**. See §7.2: this is the biggest open technical risk with Jev.

### 1.3 The Jev demos: what they show us

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

## 2. Product vision

**One sentence:** a fast, mobile-first RSS reader that shows each person what they care about the moment
they subscribe, gets sharper with every thumbs-up or thumbs-down, and can always tell you *why* an
article is shown, hidden or uncertain.

**Primary user stories**

1. *As a new user* I add feeds (or import OPML) and write 1–5 interests in plain words, or pick them
   from suggestions. My first page is already sorted by relevance.
2. *As a reader* I swipe 👍/👎. On 👎 I can tap one reason. The ranking visibly adapts within seconds.
3. *As a reader* I see three lanes:
   - **For you**: high P(like).
   - **Maybe**: uncertain. This is where the app asks for my opinion.
   - **Everything else**: collapsed, never silently lost. Hiding is only allowed for a high-confidence no.
4. *As a reader* I can open "Why this?" on any article and see which interest cards matched, what the
   article was classified as (topic, depth, clickbait, promo) and which of my rules applied.
5. *As a power user* I define labels ("Worth sharing with the team", "Deals under 100 €") as short
   descriptions. The app auto-suggests them on new articles.
6. *As a power user* I mute keywords or stories temporarily ("mute this story for 3 days") and boost
   sources.
7. *As a reader of aggregators* (Google News and the like) I see one story once, with "3 more sources" folded
   underneath.

**Non-goals for v1:** summaries or AI-written text (Jev can't write, and the LLM is approved only
for fallback and translation), social features, a full-text search engine, and native apps. A PWA covers mobile.

---

## 3. The classification design in detail

### 3.1 Two kinds of Jev calls

Jev is priced per input token, and the state is ingested once per call no matter how many questions there
are. The design therefore minimizes the number of distinct *states* and packs questions into them.

**Call A: Enrichment (once per article, global).** The state is the article and nothing else:

```json
{
  "article": {
    "title": "…",
    "feed": { "title": "Ars Technica", "site": "arstechnica.com" },
    "author": "…",
    "categories": ["…"],
    "excerpt": "first ~600 chars of RSS description, HTML-stripped",
    "body_lead": "first ~1,500 chars of extracted body (if available)",
    "word_count_bucket": "long"           // computed in code, never asked
  }
}
```

The questions are the same for every article and are defined in [`jev-questions.md`](./jev-questions.md):

| key | type | purpose |
|---|---|---|
| `content_type` | Choice | news report / analysis / opinion / tutorial / review / listicle / press-release / deal / job / event / podcast-video / other |
| `topic_l1` | Choice | top-level topic taxonomy, about 20 nodes, with subtrees shown as criteria values ("walking a taxonomy") |
| `topic_l2` | Choice | asked in a second call only for the top 1–2 `topic_l1` branches (beam search, as in the hierarchical-classification cookbook) |
| `depth` | Score (5) | a headline rewrite, brief, standard, in-depth, deep investigation |
| `clickbait` | Noul | does the title withhold or exaggerate what the article delivers |
| `promotional` | Noul | press release, sponsored post or vendor marketing |
| `time_sensitive` | Noul | only relevant for a few days (breaking news, deals, events) |
| `evergreen` | Noul | still useful months later |
| `local_scope` | Choice | global / country / region / city, useful for Slovak local news |
| `sentiment_tone` | Score (5) | alarming … upbeat (optional, for "less doom" users) |
| `paywalled_or_teaser` | Noul | the excerpt is a teaser for paywalled content |

Estimated size is 1.5–2.5k input tokens, so **≈ $0.0001 per article**. 20,000 new articles a day costs about $2/day for
*all* users together.

**Call B: Matching (once per article × the distinct interest cards on that feed).** The state is the same
article (smaller: title, excerpt, body lead). The questions are one Noul per **interest card** that any
subscriber of the feed holds, namespaced by card id:

```json
{
  "state": { "article": { … } },
  "questions": {
    "card_812": { "type": "noul",
      "instructions": { "question": "Does `article` match this reader interest?",
                        "interest": "New battery chemistry for EVs (solid-state, sodium-ion …)",
                        "not_for": "Stock-price moves or car launch PR without battery detail" },
      "criteria": { "true":  { "what": "The article's main subject is the interest", "examples": ["…liked title 1…", "…liked title 2…"] },
                    "false": { "what": "Mentions it only in passing, or matches a not_for", "examples": ["…disliked title…"] } } },
    "card_977": { … }
  }
}
```

- **Cards are deduplicated globally.** Card text is normalized and hashed, so if 300 users pick the library
  card "Rust programming language" it is *one* question. Cost grows with the number of *distinct
  interests on a feed*, not with users. This is the multi-tenancy win that DreamCatcher's shared store
  made possible but never used.
- **Budgeting:** a card question is about 150–400 tokens. The 64k per-request budget fits about 100–150 cards
  per call, and larger sets are split across several calls with the same state.
- **Prefiltering (optional, once card counts grow):** each card carries the `topic_l1` nodes it belongs
  to. Call B is only asked for cards whose topics have probability ≥ 0.05 in the article's Call A answer.
  Asking extra questions is cheap, and fan-out beats cleverness until the numbers say otherwise.
- **Personal examples are what make a card personal.** Adding liked and disliked titles to
  `criteria.true/false.examples` is how the model gets few-shot context without training.
  - A shared library card uses the library's curated examples.
  - As soon as a user adds personal examples, a **fork** of the card is created (a new hash).
  - This is the one place where cost scales per user, and it scales with *engaged* users. That is
    acceptable, and it can be capped (e.g. max 5 examples per side, rotating the most recent/most
    informative ones).

**Why not one Choice "which of my interests does this match?"** Because a Choice is *relative*: it always
picks something. TypeSafe's jaggedness notes make exactly this point, that a Choice settles "which" while
independent Nouls "can be low for all of them". A reader needs the absolute answer, so each card is its
own Noul. A Choice is used only where exactly one answer is correct (content type, topic node, label
picking from a closed set).

### 3.2 Turning answers into a ranking (per user, in code)

For user *u* and article *a* the ranker computes, in this order:

1. **Hard rules** (deterministic, applied first, recorded in `rules_fired`):
   - Muted keyword or story match → hidden (with an expiry for temporary mutes).
   - A blocked source or author → hidden.
   - A pinned or boosted source → floor at the "For you" lane.
   - Duplicate of an already-read story (§5.3) → folded.
2. **Interest match** `m = max over u's cards of P(card)`. The card's user-set strength (*must / love /
   like*) acts as a multiplier, and a matched **anti-interest** card ("never show me crypto") acts as a veto
   if P ≥ 0.7.
3. **Quality modifiers** from Call A, only where the user opted in or has *demonstrated* a preference
   (see 4): e.g. clickbait P ≥ 0.8 or promotional P ≥ 0.8 → demote by one lane.
4. **Learned personal model** (phase 3, once the user has ≥ ~30 labelled items): a regularized logistic
   regression per user over a fixed feature vector, described below. The model's output *replaces* steps 2–3
   as the score, but hard rules still apply first.

   The feature vector:
   - all card P's for that user's cards
   - `topic_l1`/`topic_l2` probabilities
   - `content_type` probabilities
   - `depth` (expected level) and its uncertainty
   - the clickbait / promotional / time_sensitive / evergreen / paywalled P's
   - feed id (one-hot or target-encoded)
   - author (hashed)
   - word-count bucket
   - article age at read time
   - story-cluster size

   This is TypeSafe's own "Jev answers → features → classical model" recipe, in the same shape as their
   CatBoost cookbook but smaller. It trains in milliseconds and runs in microseconds. The coefficients
   are *learned*, not hand-set, which directly retires FeedIt's constant-tuning problem, and its output
   is P(like) and can be recalibrated per user (Platt scaling on held-out ratings).

**Lanes / tiers.** The final number is always a probability, so the 1–5 tier slider maps to fixed bands
such as tier 5 = P ≥ 0.85, tier 4 ≥ 0.65, tier 3 ≥ 0.45, tier 2 ≥ 0.25, tier 1 = everything. The bands mean
the same thing for every user and every feed, and none of it depends on 200 trained articles.

**Uncertainty drives the "Maybe" lane.** An article lands in *Maybe* when any of these hold:

- the best card P is between 0.35 and 0.65
- Jev's confidence on the deciding answer is low (threshold tuned on our data, starting around 0.5)
- the personal model's P is near 0.5

Following newsjack's asymmetric-cost rule, **only a confident "no" hides anything**. Everything else
stays reachable.

### 3.3 Learning from the user

| Signal | Captured as | Used for |
|---|---|---|
| 👍 / 👎 (swipe, keyboard, button) | label ±1 | personal model training; candidate examples for card criteria |
| 👎 + reason chip | label −1 + reason | maps to a feature. "Off-topic" → nearest card's `not_for` examples; "clickbait" → learns a demotion for `clickbait`; "already seen" → cluster fold; "too shallow" → `depth`; "promo" → `promotional` |
| 👍 + "more like this" | label +1 | offers to create/strengthen a card (see below) |
| opened + dwell ≥ N s, then returned | weak positive (weight 0.3) | personal model |
| "Did you like it?" prompt after return (from FeedIt `todo.txt`) | label | asked mainly for *Maybe*-lane items, since that is where a label is worth the most |
| marked read without opening | weak negative (weight 0.1) | personal model (optional, off by default) |
| bookmark / share / label assigned | strong positive | personal model |

**Proposing new interest cards without text generation.** Jev can't write card text. Generative LLMs are approved only for fallback and translation
(§7.6), so card proposals come from the library and from the user:

1. **Library pick (Jev only).** Keep a curated library of a few hundred cards organized by the same topic
   taxonomy. When a user likes several articles no current card explains (all their card P's < 0.3), run
   one Choice with the library cards under the matching `topic_l1` branch as options. The top 1–3 become
   suggestions: "Looks like you enjoy *Space launches*. Add as an interest?"
2. **"Make a card from this" (user-written).** On a liked article that no card explains, the UI opens a
   card editor prefilled with the article's topic path (from Call A) and its title as the first
   `examples_yes`. The user writes or edits the one-line interest. A new card is backfilled over recent
   articles, so its effect shows immediately.
3. **Library growth.** Popular user-written cards (identical normalized text held by several users, or
   close variants merged by an admin) are promoted into the shared library. The library grows from real
   use, with no generative model involved.

LLM-drafted card suggestions were considered and set aside. They can come back if the LLM policy in §7.6
changes.

### 3.4 Labels

A label is a user-authored description, e.g. *"Worth sending to the team: concrete, technical, about our
stack (Postgres, TypeScript)"*. Each label becomes a Noul in Call B, handled exactly like an interest
card, and is suggested when P ≥ 0.8 (FeedIt's existing "tap to make permanent" UI). Once a user has
assigned a label several times, the most recent assignments become its examples. This replaces word-overlap
label prediction.

### 3.5 What stays deterministic (and must not be asked of Jev)

Following the jaggedness list: dates, ages, counting, word counts, prices and currency comparisons ("deals
under 100 €"), language detection, dedup keys, and time-based mutes are all **computed in code** and, where
useful, passed *into* state as typed facts (`"price_eur": 89`, `"is_under_user_budget": true`). The old
FeedIt number-and-unit merging ("5kg") and currency ideas live here as extractors, not as model questions.
If a price has to be *found* in messy text, the pre-parsed-value cookbook pattern applies: a regex finds the
candidates and a Jev Choice picks the right one.

---

## 4. Architecture

### 4.1 Stack (decided: TypeScript monorepo)

- **TypeScript on Node 22, as a monorepo** (pnpm workspaces). This continues DreamCatcher's language, and the
  official `@typesafe-ai/sdk` is TS/JS.
- **PostgreSQL 16** as the only stateful service at the start. Includes the job queue (`pg-boss`), full-text
  search (tsvector with `simple` + language-specific configs), and optional `pgvector` later.
- **API:** Fastify (or Hono) with typed routes, and passwordless email codes as in FeedIt.
- **Web client:** a PWA (Ionic or plain web components, as in FeedIt; React/Solid are fine too). Keep the
  swipe and keyboard training and the lane UI. Mobile-first.
- **Observability:** OpenTelemetry (kept from DreamCatcher) plus a `jev_calls` table that is the audit log.
- **Deployment:** a single `docker compose` with postgres, api, worker(s) and web. Scale workers
  horizontally. Introduce Kafka/Redis only if a measured bottleneck requires it.
- **Monorepo tooling:** pnpm workspaces + Turborepo, TypeScript project references, Vitest, ESLint +
  Prettier, and Drizzle (or Prisma) with **one** schema and migrations owned by `packages/db`. This avoids
  DreamCatcher's vendored-copy drift.

**Monorepo layout (decided):**

```
apps/
  api/          Fastify: auth, feeds, subscriptions, reading, feedback, cards, labels, admin
  web/          PWA: lanes, swipe/keyboard rating, "Why this?", card editor, settings
  worker/       all pipeline stages as pg-boss job handlers (one image, stage chosen by env or queue)
  eval/         CLI: golden-set replay, engine comparison, threshold tuning, cost reports
packages/
  db/           schema, migrations, typed queries, tenant-scoped repositories
  engine/       DecisionEngine interface + TypeSafeEngine, GatewayEngine, DegradedEngine, LlmFallbackEngine, LayaEngine
  questions/    versioned question sets (Call A, Call B builders, cluster check) + sha256 hashing
  feeds/        fetch, parse (RSS/Atom/JSON Feed), canonicalize, dedup, extract (Readability)
  translate/    optional translation step (LLM or MT), cached per article
  ranker/       post-rules, lane assignment, per-user logistic model (train + score), calibration
  shared/       types, config, logging/OTel, errors
```

### 4.2 Services (logical stages, which can run in one process at first)

```
scheduler ──► fetch ──► ingest ──► extract ──► enrich(A) ──► match(B) ──► rank-cache
    ▲            │         │           │            │             │            │
    │            ▼         ▼           ▼            ▼             ▼            ▼
    └────── feed stats   articles   article_body  article_facets  card_answers  user_article_scores
```

| Stage | Responsibility | Ported from |
|---|---|---|
| `scheduler` | pick due feeds (subscribed only), enqueue `fetch` jobs | DreamCatcher ControlCenter + `fetchable_feeds` |
| `fetch` | HTTP with lock, charset, redirects, RSS/Atom/JSON Feed parse; adaptive interval update | DreamCatcher `rss_fetch` + SQL interval functions (and FeedIt's SimplePie tolerance as test fixtures) |
| `ingest` | canonicalize URL (strip utm_*, AMP, Google News wrappers), content hash, **unique insert**, story-cluster candidate lookup | new; FeedIt's title+description+image dedup rules as fallbacks |
| `extract` | full-text extraction (use Mozilla Readability instead of the jQuery `:contains()` heuristic), language detection (per article) | DreamCatcher `rss_links_fetch`, improved |
| `translate` | *only if the Phase 0 language test selects it* (§7.2): translate title + excerpt (+ body lead) of non-English articles to English with the LLM, once per article, cached in `article_translations` | new |
| `enrich` | Jev Call A; store answers + model version + question-set hash | new |
| `match` | Jev Call B for cards and labels relevant to the feed's subscribers; also on card creation, a backfill for recent articles | new |
| `rank` | per-user score and lane, computed on demand for the visible page and cached; recomputed on feedback or card change | replaces FeedIt `links-trainer` + `updateMany` fan-out |
| `learn` | refit per-user logistic model after N new labels (debounced) | new |
| `housekeeping` | archive, retention, cap unread per feed, delete old answers of inactive cards | FeedIt `auto-archive*`, `auto-remove` |

**Ordering and latency.** An article is visible (unranked, in "New") the moment it is ingested. Enrich and match
normally finish within about a second, and the item then moves into its lane. The UI never blocks on Jev.

### 4.3 Data model (Postgres, abbreviated)

```sql
-- shared, global
feeds(id, url UNIQUE, site_url, title, lang_hint, fetch_interval_min, next_fetch_at, last_fetch_at,
      error_count, quarantined_until, stats JSONB, subscriber_count)
articles(id, feed_id, canonical_url, url, title, author, categories TEXT[], excerpt, img,
         published_at, fetched_at, lang, word_count, content_hash,
         story_cluster_id, UNIQUE(feed_id, canonical_url))
article_bodies(article_id PK, body_text, extracted_at, extractor_version)
article_translations(article_id, target_lang, title, excerpt, body_lead, engine, created_at,
                     PRIMARY KEY(article_id, target_lang))
story_clusters(id, representative_article_id, created_at)

-- Jev results (append-only, versioned)
question_sets(id, kind 'enrich'|'match', sha256, definition JSONB, created_at)
jev_calls(id, kind, article_id, question_set_id, model_version, input_tokens, latency_ms,
          status, error, created_at)
article_facets(article_id, question_set_id, model_version, answers JSONB,   -- raw Call A answers
               PRIMARY KEY(article_id, question_set_id))
interest_cards(id, text_hash UNIQUE, body JSONB, topic_nodes TEXT[], origin 'library'|'user'|'fork',
               parent_card_id, created_by)
card_answers(article_id, card_id, p REAL, model_version, created_at, PRIMARY KEY(article_id, card_id))

-- tenancy + per-user
users(id, email_hash, locale, tz, created_at, plan)
subscriptions(user_id, feed_id, title_override, folder, allow_duplicates, PRIMARY KEY(user_id, feed_id))
user_cards(user_id, card_id, strength 'must'|'love'|'like'|'never', created_at)
user_labels(user_id, label_card_id, name)
user_rules(user_id, kind 'mute_keyword'|'mute_story'|'block_source'|'boost_source', value, expires_at)
user_article(user_id, article_id, read_at, rating SMALLINT, reason TEXT, dwell_ms, bookmarked,
             labels TEXT[], lane, p_like REAL, rules_fired TEXT[], scored_at,
             PRIMARY KEY(user_id, article_id))
user_models(user_id, version, coef JSONB, calibration JSONB, n_labels, trained_at)
```

- Rows are created lazily: a `user_article` row exists only once a user sees or acts on an article. The
  per-user unread view is a query over `articles` joined to the user's `subscriptions`.
- `article_facets` and `card_answers` keep raw answers, so **changing a threshold never needs an API call**.
  Changing a *question* creates a new `question_set` and triggers a (cheap) re-enrichment of recent articles.

### 4.4 A decision-engine abstraction (vendor risk)

Jev is new: early access, "rate limits adjusting dynamically", signups paused and resumed in September 2026.
All Jev access therefore goes through one package:

```ts
interface DecisionEngine {
  ask<Q extends Questions>(state: JsonValue, questions: Q, opts?: {model?: string; signal?: AbortSignal})
    : Promise<{ answers: AnswersFor<Q>; model: string; usage: { inputTokens: number }; latencyMs: number }>;
}
```

- **Implementations:**
  - `TypeSafeEngine`: the SDK, direct API.
  - `GatewayEngine`: the same model via Vercel AI Gateway (`typesafe-ai/jev`) or OpenRouter (`typesafe/jev-1.13`). Useful for zero data retention and as a failover path.
  - `DegradedEngine`: no model at all. Keyword-baseline ranking, everything goes to *Maybe*, and articles are queued for re-scoring. This is the default fallback on the CPU-only box (§4.6).
  - `LlmFallbackEngine`: turns Choice/Score/Noul into a strict JSON schema for any structured-output LLM (Ollama/GLM locally, or a paid API with a spend cap). Newsjack has this adapter. It is slower, so on a CPU-only box it is used only for a trickle of articles (§4.6).
  - `LayaEngine`: the open-weights Laya model, self-hosted through the Jev-compatible ONNX port `receptron/laya`. It needs fine-tuning before it is useful; it is a candidate for SK/CZ enrichment. See [`laya-multilingual.md`](./laya-multilingual.md).
- **Operational rules copied from newsjack:**
  - a concurrency pool
  - 4 attempts with exponential backoff on 429/5xx, honouring `retry-after`
  - a failed article goes to the *Maybe* lane, never hidden
  - more than 20 % failures in a window, or an exhausted daily spend budget (§4.6), trips a circuit breaker to the fallback chain
- **Pin the model version** (`jev-1.13.0`, not `jev-latest`) in production. On a new release, replay the eval
  set (§6) before switching.

### 4.5 Multi-tenancy from day one

The shared article layer (feeds, articles, facets, card answers, translations) is global by design. Only
what a user *does* is tenant data. What that requires from day one:

- **Isolation.**
  - Every per-user table carries `user_id` in its primary key, and all access goes through tenant-scoped
    repositories in `packages/db`, never raw queries in route handlers.
  - Postgres **row-level security** on per-user tables serves as a second line of defence (`SET app.user_id`
    per request/transaction).
  - Workers that touch per-user data (ranker, learner) take the `user_id` from the job payload, and the same
    policies apply to them.
- **Card privacy.**
  - Card dedup is by normalized-text hash, so two users can share the same card *row*. They never see each
    other's cards, examples or strengths.
  - A card with personal examples is always a private fork. Its examples (the user's liked titles) are
    used only in that user's questions.
  - Library cards are public. A user card is promoted to the library only through an explicit admin step
    and only when several users hold it.
- **Quotas per plan** (enforced in the API, with counts stored on `users`):
  - feeds per user
  - cards and labels per user
  - personal-example forks per user
  - the minimum fetch interval of feeds they add
  - OPML import size

  These bound the only per-user Jev costs: card forks and backfills.
- **Fairness in the queue.**
  - pg-boss jobs carry `tenant_id` where per-user (backfills, model refits), with per-tenant concurrency
    limits, so one user importing 500 feeds or creating 50 cards can't starve everyone else.
  - Shared stages (fetch, enrich, match) are keyed by feed or article, not by user.
- **Cost attribution.**
  - Every `jev_calls` row records which cards were asked. Shared Call A cost is platform overhead. Call B
    cost is split across the subscribers holding each card.
  - Card-fork and backfill costs go to the owning user.
  - This gives per-tenant $/day for plan design and abuse detection.
- **A safe fetcher.** Users can add arbitrary URLs, so the fetcher is an SSRF risk from day one.
  - Resolve DNS and block private, link-local and metadata IP ranges, including after redirects.
  - Cap response size and time, and allow only http(s).
  - Keep TLS verification on (DreamCatcher's `rejectUnauthorized: false` must not return).
  - Rate-limit feed additions per user.
- **Auth and accounts.**
  - Passwordless email codes with rate limiting, per-device session tokens, and account deletion that
    removes all per-user rows plus private card forks.
  - Data export (OPML, cards, ratings) covers GDPR access and portability.
- **Personal models** are stored per user (`user_models`) and trained only on that user's labels. There is
  no cross-user learning in v1, with one exception: *anonymized aggregate* counts may later help order the
  card library.
- **Invite-only signup (launch decision).**
  - Invites are admin-issued or come from existing users (N invites each, configurable). An `invites` table
    records code, inviter, invitee, used_at and expires_at, and a public waitlist form feeds the admin queue.
  - Invites are the main cost-control lever. The number of active users bounds the number of distinct cards
    and forks, and so the Jev and translation spend.
  - Opening signup later is a config flag, not a redesign.
- **Admin surface** (in `apps/api`, role-gated): the card library, feed health, the engine circuit-breaker
  state, and per-tenant usage.

### 4.6 Hosting: one self-hosted box, minimal spend, no GPU

**Target machine.** One dedicated-CPU server or VPS with **8 vCPU / 16–32 GB RAM / NVMe** (a few tens of €
per month at budget providers). It runs:

- Postgres
- `api`, `worker` and `web`
- the translation service (if used)
- later, Laya through ONNX

Everything runs under one `docker compose`. There's no Kafka, Redis, Elasticsearch or GPU. Backups go
to object storage (`pg_dump` + WAL archiving).

**What "no GPU" rules out, and what it doesn't:**

| Component | CPU-only verdict |
|---|---|
| Jev (Call A/B, clusters) | ✅ remote API: nothing runs locally |
| Readability extraction, dedup, ranker, personal logistic models | ✅ trivial on CPU |
| Dedicated MT models (OPUS-MT / Argos / LibreTranslate) | ✅ designed for CPU. See the translation table below |
| Laya-multilingual through ONNX | ⚠️ about 140–460 ms per call on a decent CPU is fine at invite-only scale, but a small 4 vCPU VPS measured **49 s per call**, so it needs a real 8-core-class CPU. Fine-tuning uses free Kaggle GPUs and never touches the server |
| A local generative LLM (Ollama + GLM) for **bulk** translation or as the fallback engine | ❌ too slow for per-article work on CPU. It is fine for occasional, small jobs (below) |

**Translation backends** (only needed if Phase 0 selects option (c), §7.2):

| Backend | Runs on | SK/CZ → EN quality | Throughput on the box | Cost | Verdict |
|---|---|---|---|---|---|
| **OPUS-MT** `Helsinki-NLP/opus-mt-sk-en` + `opus-mt-cs-en` (Marian, via CTranslate2 int8) or **LibreTranslate/Argos** (has `sk→en`, `cs→en` packages) | CPU, about 300 MB–1 GB RAM per model | adequate: literal, sometimes clumsy, but the *gist* survives | tens to hundreds of ms per title + excerpt; thousands of articles/hour | €0 | **Default.** Built for exactly this on CPU |
| **Ollama + GLM**: `glm4:9b` (about 5.5 GB) or `glm-4.7-flash` (MoE, about 19 GB download) | CPU | better than OPUS-MT on idioms and headlines | a 200-token output takes tens of seconds per article on CPU, so about 6–15 CPU-hours/day for one heavy user; it would also compete with Postgres for RAM | €0 plus RAM | Only for small, rare jobs (e.g. re-translating the few articles that land in *Maybe*), not the bulk path |
| **Claude Haiku 4.5 via the API** ($1 / $5 per MTok; Message Batches −50 %) | remote | best | unlimited | title + excerpt is about 250 in + 200 out tokens → about $0.0006/article with batching. At about 1,000 SK/CZ articles/day that's about $0.6/day (more than Jev's own cost for a single user) | **Quality ceiling in the Phase 0 eval**, and an optional paid tier later |
| Claude Code subscription | — | — | — | — | ❌ Not for this. A personal subscription is meant for interactive use by its owner, not for serving a multi-tenant pipeline. Automated, product-side calls belong on the API with its own key and billing |

**Key point for Phase 0:** translation quality is measured by its **effect on classification**, not by
reading the translations. Run the golden set's SK/CZ articles through Jev three ways (native, OPUS-MT → EN,
Haiku → EN) and compare AUC and accuracy. If OPUS-MT is within a point or two of Haiku, cheap MT wins, and
the likely outcome is that Jev only needs the gist.

**The fallback engine on a CPU-only box.** A local LLM can't carry the full per-article load, so the
fallback becomes a chain:

1. **`DegradedEngine` (default):**
   - rank with the keyword baseline (BM25 of card text against title + excerpt), which already exists for evaluation
   - put everything in the *Maybe* lane rather than hiding anything
   - queue the articles for re-scoring when Jev is back

   It costs nothing and is always available.
2. **`LlmFallbackEngine` (optional, configured per deployment):**
   - either Ollama/GLM on CPU for a trickle (e.g. only articles a user is actively viewing)
   - or a paid API model with a daily spend cap

   Off by default.
3. **`LayaEngine`**, once fine-tuned (`laya-multilingual.md`), becomes the main fallback for Call A.

**Spend guard.** A daily budget, in `$` from `jev_calls.input_tokens` (and translation calls if they go to a
paid API). When it's exhausted, new articles go to `DegradedEngine` until midnight UTC, and an admin alert
fires. At invite-only scale, Jev costs dollars per day (see `jev-questions.md` §5), so the cap is a safety
net rather than a normal operating mode.

---

## 5. Specific product mechanics

### 5.1 Onboarding: useful from the first minute

1. Add feeds (URL, site discovery, OPML import) or pick starter bundles.
2. "What are you here for?" offers library interest chips grouped by the topic taxonomy, plus free-text
   interests. Optionally, "never show me…" anti-interests.
3. Articles already in the global store for those feeds are ranked immediately. Call A answers exist
   already, and Call B runs only for cards not yet asked, a few seconds of backfill.
4. The first session shows a *calibration round*: 10 Maybe-lane articles to swipe. That replaces FeedIt's
   "train 200 articles before tiers work".

### 5.2 "Why this?" (the successor to the detailed-training modal)

It shows:

- the matched cards with their probabilities (as bars)
- article facets (type, topic path, depth, clickbait, promo)
- the rules that fired
- for learned users, the top 3 contributing features

Every line is actionable:

- "not really about *EV batteries*" adds the article to that card's `not_for` examples
- "never show promo" creates a rule
- "boost this source"

This keeps FeedIt's best idea, direct manipulation of *why* an article scores, without exposing raw word
weights.

### 5.3 Duplicates and story clustering

1. **Exact:** the canonical URL + content hash unique key.
2. **Near-duplicate candidates:** a title-trigram similarity (Postgres `pg_trgm`) or MinHash within a 72 h
   window, top 5 candidates.
3. **Verification:** one Jev call per new article, with state `{new, candidates[]}`. Questions:
   - a Choice "which candidate reports the same story" (options = candidate ids + `none`)
   - a Noul "same event, not merely same topic"

   This follows the re-ranking / entity-alignment cookbook pattern: cheap retrieval first, Jev judges.
4. The UI folds clusters: "+3 sources". "Mute this story" mutes the cluster, which covers FeedIt's
   temporary-keyword-mute todo.

### 5.4 Per-feed settings that survive

Allow duplicates, language override, title override, folder, and *feed-scoped cards* (a card that applies
only to one feed, e.g. a classifieds feed (bazos.sk) → "road bikes, size L, under 800 €", with the price
checked in code).

---

## 6. Evaluation: know it works before trusting it

The cost of Jev calls is small enough that evaluation can be continuous:

- **Golden set, built from scratch.** No FeedIt data is reused (§7.6), so Phase 0 builds a new one:
  - Ingest about 2 weeks of articles from a real mix of EN/SK/CZ feeds.
  - Recruit **3–5 raters** (Martin plus a few testers with different tastes; multi-tenant means the ranking must work for more than one person's taste). Each writes 5–10 interest cards *before* rating, then rates about 300 articles in a bare-bones rating page (`apps/eval`), about 100 per language where their feeds allow.
  - Separately, hand-label about 100 articles per language for the Call A questions (content type, clickbait, promo, depth, topic). This is the accuracy check for enrichment, and the clean test set for Laya later.
  - Freeze it as `golden-v1` and grow it later with opted-in, anonymized production feedback.
- **What to measure on it:**
  - AUC and precision@k of card-based ranking vs. two cheap baselines: **chronological** order and a **keyword baseline** (BM25 of card text against title + excerpt). Jev has to clearly beat keyword matching to justify itself.
  - learning curves of the personal model (how many labels to reach X)
  - calibration (reliability diagram of P(like) vs. actual like rate)
  - the same metrics split **per language** (EN / SK / CZ)
- **Per-release replay.** Store `question_set.sha256` + `model_version` with every answer. Any change to
  questions, thresholds or model version runs the golden set and posts a diff.
- **Online metrics:**
  - like rate per lane (it should be monotonic with the tier)
  - Maybe-lane size (it should shrink over time)
  - share of hidden articles later found and liked (the *regret rate*, which must stay very low)
  - Jev p50/p95 latency, error rate, $/day
- **Criteria-wording iteration** (newsjack doctrine): when a card misfires, fix the card text and examples,
  not the thresholds.

---

## 7. Risks and open questions

### 7.1 Vendor/model risk

Jev is new and single-vendor. The mitigations are the `DecisionEngine` abstraction, the LLM fallback, pinned
versions, stored raw answers, and the fact that no user data is locked into the vendor (cards are plain text).

### 7.2 Language (important for Slovak/Czech feeds)

TypeSafe states that English is the primary training language and other languages are lower-accuracy. The
options, to be decided by the eval (§6), not upfront:

- **(a)** Send native text and rely on it. Measure on the golden set first (§6), which is deliberately
  balanced across EN/SK/CZ.
- **(b)** Keep the questions and card text in English but the state in the native language. The model then
  handles cross-lingual matching. It is often better than fully native prompts, but that needs to be
  measured.
- **(c)** Translate the title + excerpt (+ body lead) to English before Call A/B, and store the translation
  (`article_translations`). Translation happens once per article and is shared by all tenants. On the
  CPU-only box the bulk path is a **dedicated MT model** (OPUS-MT / LibreTranslate, free and fast on CPU).
  Ollama/GLM handles only small jobs, and Claude Haiku is the quality ceiling in the eval. Backends and
  numbers are in §4.6.

- **(d)** Fine-tune the open-weights **Laya-multilingual** model (Apache 2.0, mmBERT-base) on EN/SK/CZ
  data labelled by a teacher, and route SK/CZ articles to it for the fixed enrichment questions. Laya is
  near random zero-shot, so it can't replace Jev for free-form interest cards without further work. The
  full analysis is in [`laya-multilingual.md`](./laya-multilingual.md).

The recommendation is to prototype (a) and (b) on the golden set in week 1, with Laya zero-shot as a
baseline. Adopt (c) if both underperform, and start (d) in parallel only if SK/CZ accuracy stays
clearly below EN.

### 7.3 Adversarial or promotional content

Article text can argue for its own classification. The mitigations:

- Jev only sees data in state
- post-rules never *hide* on a single low-confidence answer
- promotional content is explicitly modelled

### 7.4 Cost at scale

Cost scales with articles × distinct cards per feed, not with users. §5 of `jev-questions.md` has the
numbers, which are cents to single dollars per day for thousands of users. Personal example forks are the
one place where cost grows with each user, so cap them.

### 7.5 Privacy

Card texts and ratings are personal data. The article content sent to Jev is public. Only card text and
example titles leave our system. Vercel AI Gateway supports zero data retention, and TypeSafe offers ZDR on
enterprise plans.

### 7.6 Decisions (taken 2026-09-24)

| # | Question | Decision | Consequence in this plan |
|---|---|---|---|
| 1 | Stack | **TypeScript monorepo** | Layout in §4.1 |
| 2 | Single-user first or multi-tenant? | **Multi-tenant from day one** | §4.5 covers isolation, quotas, fairness, cost attribution and the SSRF-safe fetcher |
| 3 | Generative LLM use | **Allowed only as the fallback decision engine and for translation** | `LlmFallbackEngine` + `translate` stage. No LLM card authoring (§3.3), no summaries, and no LLM as a direct labelling teacher for Laya (see `laya-multilingual.md` §3) |
| 4 | Migration from FeedIt.sk | **None. Everything is trained or fine-tuned from scratch** | New golden set built in Phase 0 (§6). No account import. The old code stays only as a design reference |

| 5 | Hosting | **Self-hosted, minimal spend, no GPU** | One 8 vCPU / 16–32 GB box (§4.6). No local bulk LLM. Laya only through ONNX on CPU, fine-tuned on free Kaggle GPUs |
| 6 | Signups at launch | **Invite-only** | Invites + waitlist in §4.5, which doubles as cost control |
| 7 | LLM provider | **Claude gives the best translations but costs the most; Ollama + GLM is acceptable at lower quality** | Bulk translation uses a dedicated CPU MT model (OPUS-MT / LibreTranslate), because GLM on CPU is too slow per article. Ollama/GLM handles small jobs and the optional fallback. Claude Haiku through the **API** (not a Claude Code subscription) is the eval quality ceiling and an optional paid upgrade. Phase 0 picks by the effect on classification accuracy (§4.6) |

---

## 8. Roadmap

**Phase 0: Spike (1 week).** No UI; the goal is to prove the core bet.
- Minimal ingestion script (no pipeline yet) over a real EN/SK/CZ feed mix, and the bare-bones rating page in `apps/eval`.
- Build `golden-v1` (§6): 3–5 raters write cards, then rate; hand-label the Call A questions.
- Script: Call A + Call B for those articles. Compute AUC vs. ratings and compare with the chronological and keyword baselines.
- Test the language options (a)/(b)/(c) on Slovak/Czech items, with Laya-multilingual zero-shot as a baseline (§7.2). For (c), compare OPUS-MT, Ollama/GLM and Claude Haiku *by their effect on classification* (§4.6). Measure latency, CPU time and $ on the target box size.
- **Exit criterion:** card-based ranking clearly beats the keyword baseline for every rater, with zero training, and a language option exists where SK/CZ is within about 5 AUC points of EN.
- Phase 0 now takes about **2 weeks** rather than 1, because the golden set has to be built.

**Phase 1: Ingestion core (2–3 weeks).**
- Monorepo, Postgres schema, pg-boss.
- Port DreamCatcher fetch + adaptive interval + extraction (with Readability).
- Canonical-URL + hash dedup. OPML import. Tests using real-world broken feeds (reuse FeedIt's hard cases).

**Phase 2: Jev enrichment + matching + basic reader (3–4 weeks).**
- The `DecisionEngine` package with a TypeSafe impl, retries, circuit breaker and call logging.
- Call A and Call B with global card dedup. The interest card library (≈150 cards).
- A PWA with feeds, lanes, swipe/keyboard rating with reason chips, "Why this?", mutes and boosts.
- Passwordless auth, tenant-scoped API and the quotas from §4.5.
- `DegradedEngine`, the spend guard and the invite flow.
- The `translate` stage (OPUS-MT / LibreTranslate container), if Phase 0 selected translation. `LlmFallbackEngine` is optional.

**Phase 3: Personal learning (2 weeks).**
- Per-user logistic model + calibration, and the implicit signals (dwell, return prompt).
- Maybe-lane active learning, and library card suggestions from unexplained likes.

**Phase 4: Clusters, labels, polish (2–3 weeks).**
- Story clustering with Jev verification, fold UI, story mutes.
- Labels as Nouls with suggestions.
- Archive/retention jobs, and the eval dashboard (online metrics, replay on version change).

**Phase 5: Optional extras.**
- Fine-tuned Laya-multilingual for SK/CZ enrichment, if Phase 0 showed a language gap (`laya-multilingual.md`).
- RAG "ask my archive" using DreamCatcher's hybrid search.
- Native wrappers, premium plans (fetch interval, feed count, card count). Summaries only if the LLM policy (§7.6) is widened.

---

## 9. Sources

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
