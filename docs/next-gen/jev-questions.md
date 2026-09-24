# Jev question sets, request shapes and cost math

This is the companion to [`PLAN.md`](./PLAN.md). Everything here is a **starting draft**. The wording is
expected to be iterated against the golden set (PLAN §6). The newsjack doctrine applies: when an answer is
wrong, fix the wording and the examples before touching thresholds.

API facts used below (jev-1.13, docs reviewed September 2026):

- **Endpoint:** `POST https://api.typesafe.ai/v1/systemone` with `Authorization: Bearer $TYPESAFE_API_KEY`.
- **Body:** `{ model, state, questions }`.
- **SDKs:** `@typesafe-ai/sdk` (`choice()`, `score()`, `noul()`, `client.systemOne()`) and `typesafe-sdk` (Python).
- **Answer fields:**
  - Choice returns `choice`, `probabilities`, `confidence`.
  - Score returns a probability-weighted index in `score`, plus `probabilities`, `confidence` and `legend`.
  - Noul returns `noul` = P(yes).
- **Price and limits:** $0.042 per 1M input tokens; output is free. 64k tokens per request; 32k for state
  + the longest question. 250k tok/s and 1,200 req/min (both "adjusting dynamically").

---

## 1. Call A: article enrichment (global, once per article)

### 1.1 State builder (code)

```ts
function enrichState(a: Article, body?: string) {
  return {
    article: {
      title: a.title,
      feed: { title: a.feedTitle, site: a.feedHost },
      author: a.author ?? null,
      categories: a.categories.slice(0, 8),
      excerpt: stripHtml(a.excerpt).slice(0, 600),
      body_lead: body ? body.slice(0, 1500) : null,   // keep state small: context rot is real
      length: wordCountBucket(a.wordCount),            // "short" | "medium" | "long" | "very_long" (computed, never asked)
      lang: a.lang,                                    // detected in code
    },
  };
}
```

### 1.2 Questions

```ts
import { choice, noul, score } from "@typesafe-ai/sdk";

export const ENRICH_V1 = {
  content_type: choice("What kind of piece is `article`?", {
    news_report:   { what: "Reports a specific recent event, announcement, release, ruling or result", examples: ["Company X recalls 40,000 cars over brake fault"] },
    analysis:      { what: "Explains causes, context or implications of events, based on reporting or data" },
    opinion:       { what: "Argues the author's personal view; column, editorial, commentary" },
    tutorial:      { what: "Teaches how to do something step by step" },
    review:        { what: "Evaluates a specific product, book, film, game, place or service" },
    listicle:      { what: "Organized as a numbered or bulleted list of items (\"10 best…\")" },
    press_release: { what: "Written by the organization it is about; announcement in its own voice" },
    deal_or_ad:    { what: "Sells something: discount, offer, classified ad, sponsored product placement" },
    job_or_event:  { what: "A job posting, event listing or call for participants" },
    media:         { what: "Mainly a podcast episode, video or photo gallery with little text" },
    other: null,
  }),

  topic_l1: choice(
    { question: "Which top-level topic is `article` primarily about?",
      focus: "Pick the main subject, not every topic mentioned." },
    TOPIC_TREE_L1   // { technology: { software: [...], hardware: [...], ... }, science: {...}, ... , other: null }
  ),

  depth: score("How much substance does `article` offer beyond its headline?", [
    "Headline only or a one-paragraph rewrite of another source",
    "Short brief: the basic facts, little context",
    "Standard article: facts plus some context or quotes",
    "In-depth: detailed explanation, data, multiple sources or perspectives",
    "Deep dive or investigation: original research, extensive detail",
  ]),

  clickbait: noul("Does the title of `article` withhold or exaggerate what the article actually delivers?", {
    true:  { what: "Curiosity gap, sensational framing, or promise not met by the excerpt", examples: ["You won't believe what this app does", "This one trick…"] },
    false: { what: "Title plainly states what the article is about" },
  }),

  promotional: noul("Is `article` a press release, sponsored post, affiliate roundup or vendor marketing rather than independent content?"),

  time_sensitive: noul("Will `article` lose most of its value within a few days (breaking news, expiring deal, upcoming event)?"),

  evergreen: noul("Would `article` still be useful to a reader six months from now?"),

  local_scope: choice("What geographic scope does `article` concern?", {
    global: "Relevant regardless of country",
    national: "Mainly about one country",
    regional_or_city: "Mainly about a region, city or town",
    not_geographic: null,
  }),

  tone: score("What is the emotional tone of `article`?", [
    "Alarming or distressing", "Negative", "Neutral", "Positive", "Upbeat or celebratory",
  ]),

  paywall_teaser: noul("Does `article`'s excerpt read like a teaser for content behind a paywall or login?"),
};
```

Notes:

- `topic_l1` uses the "walking a taxonomy" pattern: option values are subtrees, so the model sees what lives
  under each branch. `topic_l2` is a second call, asked for the one or two best `topic_l1` branches (beam
  search) with that branch's children as options. The second call can be merged into Call B, since the
  state is identical, to save one call per article.
- Every Noul is phrased so that **high P = yes = the thing named**. Never invert.
- `score` answers are for ranking and thresholds, **not magnitudes**: 2.4 does not mean "between standard and
  in-depth".

---

## 2. Call B: interest-card and label matching

### 2.1 Card format (stored in `interest_cards.body`)

```json
{
  "interest": "New battery chemistry for electric vehicles (solid-state, sodium-ion, LFP improvements)",
  "not_for": "Stock-price moves, car launch PR without battery detail",
  "examples_yes": ["Toyota's solid-state pilot line hits 1,000 cycles", "…"],
  "examples_no":  ["Tesla shares slide 4% after delivery miss"]
}
```

### 2.2 Question builder (code)

```ts
function cardQuestion(card: CardBody) {
  return noul(
    {
      question: "Would a reader with this interest want to read `article`?",
      interest: card.interest,
      ...(card.not_for && { not_for: card.not_for }),
      focus: "Judge the article's main subject, not passing mentions.",
    },
    {
      true:  { what: "The article's main subject falls within `interest`", examples: card.examples_yes?.slice(0, 5) },
      false: { what: "Only mentions it in passing, or falls under `not_for`", examples: card.examples_no?.slice(0, 5) },
    },
  );
}

// One request per article per batch of ≤ ~120 cards; keys are namespaced card ids.
const questions = Object.fromEntries(cards.map(c => [`card_${c.id}`, cardQuestion(c.body)]));
```

- **Anti-interest cards** ("never show crypto") use the same builder. Their *meaning* is inverted only in the
  ranker, never in the question.
- **Labels** use the same builder with `question: "Does \`article\` fit this label?"` and the label description.

### 2.3 Why Nouls rather than one Choice

A Choice over cards always picks *something*: it answers "which of these" and never "none". Independent
Nouls are absolute and can all be low. TypeSafe's jaggedness page shows exactly this pattern, using both on a
shortlist. A Choice is useful for the **card suggestion** flow (PLAN §3.3), where the task really is
"which library card best explains these liked articles".

---

## 3. Story-cluster verification call

State: `{ "new": {title, excerpt, feed, published_at}, "candidates": [{id, title, excerpt, feed, published_at}, …≤5] }`.
The candidates come from `pg_trgm` / MinHash within 72 h.

```ts
const CLUSTER_V1 = {
  same_story: choice("Which item in `candidates` reports the same specific event as `new`?", {
    ...Object.fromEntries(cands.map(c => [c.id, null])),
    none: "No candidate reports the same specific event (same topic is not enough)",
  }),
  is_followup: noul("Is `new` a follow-up with substantial new developments rather than a re-report of an event already covered in `candidates`?"),
};
```

Folding rule, in code:

- Fold `new` under the chosen cluster when `same_story != none && probabilities[chosen] >= 0.7 && is_followup < 0.5`.
- Otherwise keep it separate, and link it as related when `same_story` has P ≥ 0.4.

---

## 4. Post-rules (deterministic, in the ranker)

Every rule that fires is stored in `user_article.rules_fired` and shown in "Why this?".

| # | Rule | Effect |
|---|---|---|
| R1 | muted keyword / story / blocked source | hide (respect `expires_at`) |
| R2 | anti-interest card P ≥ 0.7 | hide; if 0.5 ≤ P < 0.7 → Maybe |
| R3 | best card P ≥ 0.65 and confidence OK | lane "For you" |
| R4 | best card P in [0.35, 0.65) | lane "Maybe" |
| R5 | best card P < 0.35 | lane "Everything else" (collapsed, never deleted) |
| R6 | user has demonstrated dislike of clickbait (≥ 3 👎 with that reason) and `clickbait` ≥ 0.8 | demote one lane |
| R7 | same as R6 for `promotional`, `depth` ≤ 1, `time_sensitive` on stale articles (age computed in code) | demote one lane |
| R8 | Jev call failed | lane "Maybe", flag `engine_error` |
| R9 | personal model trained (n ≥ 30) | P(like) from the model replaces R3–R5 banding; R1, R2 and R8 still apply |

The thresholds are starting points. Tune them on the golden set. Don't carry a Noul-tuned threshold over to a
Choice.

---

## 5. Cost and throughput estimates

Assumptions:

- Call A is about 2.0k input tokens (state ≈ 700, questions ≈ 1.3k with the taxonomy).
- A card question is about 250 tokens.
- The Call B state is about 600 tokens.
- Price: $0.042 per 1M tokens.

| Scenario | Articles/day | Distinct cards per feed (avg) | Call A tokens | Call B tokens | $/day |
|---|---|---|---|---|---|
| Single user, 60 feeds | 1,500 | 8 | 3.0M | 1,500 × (600 + 2,000) = 3.9M | **≈ $0.29** |
| 100 users, 1,500 feeds | 20,000 | 20 | 40M | 20,000 × (600 + 5,000) = 112M | **≈ $6.4** |
| 5,000 users, 15,000 feeds | 150,000 | 40 | 300M | 150,000 × (600 + 10,000) = 1.59B | **≈ $79** |

- **Throughput:** 150k articles/day is about 1.7 articles/s. That is well under 1,200 req/min and far under
  250k tok/s. A worker pool of 8 with p50 ≈ 200–300 ms handles bursts of about 30 calls/s.
- **The biggest lever** is the Call B card count per feed: prefilter cards by topic once feeds have more than about 50
  distinct cards. The second lever is `body_lead` length in Call A.
- **Rough comparison:** DreamCatcher's planned per-article generative-LLM pass (prompt → JSON score/tags/reasons)
  would cost roughly 50–200× more per article and take seconds instead of about 200 ms. Newsjack measured 384
  headlines for $0.19 with Jev, against 4 headlines for $0.77 with a frontier LLM.

---

## 6. Logging (every call)

Store the following in `jev_calls` and the answer tables:

- `question_set.sha256`
- `model_version` (from the response `model` field, e.g. `jev-1.13.0`)
- `input_tokens`
- `latency_ms`
- the raw `answers` JSON

This is what makes offline re-scoring, per-release replay and cost dashboards possible without extra API spend.
