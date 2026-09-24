# Laya: an open-weights Jev alternative for EN + SK + CZ?

This is a companion to [`PLAN.md`](./PLAN.md), researched in September 2026. The question: Jev is English-first
and many FeedIt feeds are Slovak or Czech. Could we use **Laya**, the open-source "Jev-compatible" model, and
fine-tune it on EN + SK + CZ instead?

**Short answer.** Laya is a good fit as a *fine-tuned, self-hosted engine for a fixed set of questions*,
such as the per-article enrichment questions. It is **not** a drop-in replacement for Jev's zero-shot
"ask anything" behaviour, and that zero-shot behaviour is what the interest-card idea depends on.

The plan should be a **hybrid**:

- Keep Jev, plus translation if the spike shows it's needed, for free-form interest cards.
- Fine-tune Laya-multilingual on EN/SK/CZ for the fixed enrichment questions, and later possibly for card
  matching.
- Decide by running the golden set against all the options, not by reading benchmark claims.

---

## 1. What Laya is (facts as published)

- **Maker and licence:** Convai Innovations, released 2026-09-18 under **Apache 2.0**. Weights are at
  [`convaiinnovations/laya`](https://huggingface.co/convaiinnovations/laya) on Hugging Face, and the Python
  package is `pip install laya`.
- **What it does:** a non-generative "System 1" decision model. It takes the same inputs as Jev (state plus
  Choice / Score / Noul questions) and returns a probability distribution for each question in one forward
  pass.
- **Training:** "RLCD", reinforcement learning against strictly proper scoring rules. This follows the idea
  TypeSafe describes; no TypeSafe weights are involved.
- **Checkpoints:**

  | Checkpoint | Backbone | Params | Context | Notes |
  |---|---|---|---|---|
  | `laya` (root) | ModernBERT-large (English) | 421M | 512 tokens (about 320 left for state) | English only; confidently wrong on other scripts |
  | `laya-multilingual` | mmBERT-base (256k vocab) | 322M | 1,024 (up to 8k) | "100+ languages", about 2.2× faster |
  | `laya-typed-decisions` | ModernBERT-large | 421M | 1,024 | fine-tuned on the benchmark's own training split |

- **Router:** `laya.Router` detects the script (about 0.1–0.7 ms) and sends non-English text to the multilingual
  checkpoint. It detects scripts, not languages, so Slovak and Czech (Latin script) are **not** told apart
  from English that way. We would route by our own per-article language detection.
- **Node/TypeScript:** [`receptron/laya`](https://github.com/receptron/laya) runs it through ONNX Runtime
  with a **Jev-compatible `systemOne(state, questions)` API**. Its outputs match the Python version to 4
  decimal places. The fp32 weights are about 1.7 GB and need about 2 GB of RAM, and a call takes about 140 ms
  for 3 questions on an Apple-silicon CPU. Other community ports: GGUF, MLX, a q8 web build.

### Published numbers, and how far to trust them

| | Laya | Jev 1.13 | Caveat |
|---|---|---|---|
| typed-decisions accuracy | **0.766 fine-tuned** / **0.362 zero-shot** (random = 0.318) | 0.727 | Laya's 0.766 comes from training on that benchmark's own training split |
| multilingual zero-shot (same benchmark) | 0.342 | n/a | near random |
| Banking77 (77 labels) | 0.425 | 0.870 | Laya degrades beyond about 20 options |
| AG News (4 labels) | 0.950 | 0.910 | |
| ECE (calibration error) | 0.081 *after* per-question-type temperature fit (0.466 before) | 0.246 | Laya "ships over-confident" |
| Latency, 1 question | 32.8–39.5 ms on a T4 GPU | 236–276 ms (API) | CPU: 193–464 ms in the model card, and a 49.4 s warm median on a 4 vCPU / 7 GB VPS (Flowtivity) |
| Latency, 10 questions | 72.3 ms on a T4 | about 1,500 ms | |

- Convai compared its own runs against Jev figures that third parties published; the two were **not measured
  in the same run**. systemonemodels.org says to treat the comparison as "indicative".
- **No Slovak or Czech results are published.** Of 51 MASSIVE languages, 45 reach more than 3× random, but
  the report gives no breakdown for Slavic languages. mmBERT's pre-training corpus is broadly multilingual
  and should include both languages, but that has to be *measured on our data*.
- Known issues listed in the model card:
  - "`noul` can follow option labels instead of state", which can produce stuck answers. This matters because
    our card matching is mostly Nouls.
  - Score questions are the weakest type (SST-5: 0.372).
  - Accuracy drops above about 20 Choice options, and each question's options must fit in about 192 tokens.

---

## 2. How Laya fits the FeedIt design

| Plan component | Needs | Jev | Laya (base) | Laya (fine-tuned on our data) |
|---|---|---|---|---|
| Call A: fixed enrichment questions (content type, clickbait, promo, depth, topic) | the same ~10 questions for every article | ✅ zero-shot | ❌ near random | ✅ **good fit**: fixed questions are what fine-tuning is for |
| Topic taxonomy | Choice, ideally with subtrees | ✅ up to 255 options | ⚠️ ≤ 20 options | ⚠️ walk the tree with ≤ 20 options per level (the plan already does two levels) |
| Call B: user-written interest cards | *new* free-text criteria every time | ✅ zero-shot | ❌ | ❓ only if fine-tuned *generically* on many (article, card) pairs and shown to work on cards it never saw (§4) |
| Story-cluster verification | Choice over ≤ 6 candidates + a Noul | ✅ | ❌ | ✅ likely a good fit (fixed task, small option set) |
| Labels | like cards | ✅ | ❌ | ❓ as for cards |
| SK/CZ text | | ⚠️ "lower accuracy", unmeasured | ⚠️ unmeasured | ✅ **the main advantage**: we control the language data |
| Long state (body lead) | ~700+ tokens | ✅ 32k | ⚠️ 1,024 multilingual, including questions | ⚠️ title + excerpt only, unless we test the 8k mode |
| Cost | | $0.042/M tokens, about $0.29/day for one user | $0 plus hardware | $0 plus hardware plus labelling effort |
| Operations | | early access, dynamic rate limits | self-hosted, no vendor risk, data stays local | as base, plus retraining |

**The core tension.** The main idea of the plan is "ask instead of train": interest cards work on day one
with no labelled data. Laya reverses that. It is "a fast base to specialise, not a zero-shot decision
engine", and the guidance is to "plan to fine-tune on a few thousand labelled examples". So Laya can't
replace Jev in the *user-facing* flexible part unless we make it generalize to new card texts through
training.

---

## 3. Where the training data comes from

Fine-tuning needs a few thousand labelled examples per decision type. There are three sources, and none
requires hand-labelling thousands of items.

1. **Teacher labels (distillation).** Take about 5–10k articles from the shared store, balanced across
   EN/SK/CZ; DreamCatcher's seed feeds already mix these. Label them with a strong teacher using the
   **same question set** (`jev-questions.md` §1). The teacher can be:
   - **Jev on English**: native English articles, plus SK/CZ articles machine-translated to English. Jev
     returns probabilities, which make better soft targets for calibration than hard labels.
   - ~~A generative LLM as a direct teacher reading SK/CZ natively.~~ **Not used**: the LLM is approved only
     for fallback and translation (PLAN §7.6). Its approved role here is **translating** SK/CZ articles so
     that Jev can act as the teacher. Translation comes from OPUS-MT / LibreTranslate first, with Ollama Cloud
     GLM as the fallback (PLAN §4.6).

   ⚠️ **Check the teacher's terms first.** Some providers restrict using outputs to train models that compete
   with them. Read the TypeSafe and LLM-provider terms before distilling.
2. **Real user ratings.** Nothing is migrated from FeedIt (PLAN §7.6). Ratings come from the new
   `golden-v1` set built in Phase 0 (PLAN §6), and later from opted-in production feedback. They can't
   supervise the enrichment questions, but they are the **ground truth for the end-to-end ranking eval**,
   and paired with the raters' cards they are positive and negative examples for card matching.
3. **Human spot checks.** The hand-labelled Call A items in `golden-v1` (about 100 per language), grown to
   about 200–300 per language before fine-tuning, are the clean test set. Never train on these.

---

## 4. Fine-tuning plan (if the spike says go)

**Stage 1: enrichment (fixed questions).**

- **Data:** about 6k articles, roughly EN 2k / SK 2k / CZ 2k, each with teacher answers for the ~10 Call A
  questions.
- **State:** `{title, feed, categories, excerpt}` with the excerpt trimmed so state plus questions fit in
  1,024 tokens. Use the *same* serialization at training time and at inference time; the ONNX port warns
  that token-level JSON formatting must match.
- **Training:** Convai's Kaggle notebook (2× T4, about 4–5 h). Start from `laya-multilingual`.
- **Calibration:** fit one temperature scalar per question type on a held-out split (0.466 → 0.081 ECE in
  their report).
- **Evaluation:**
  - agreement with the teacher, per language
  - accuracy on the human test set
  - ECE per question
  - **SK/CZ vs. EN gap**
- **Ship rule:** use Laya for Call A on SK/CZ articles if it matches or beats Jev on the human SK/CZ test set.

**Stage 2: generic card matching (experimental).**

- **Data:** (article, interest card) → P(match) pairs. Use about 300–500 diverse cards: the library cards
  plus variations in EN, SK and CZ. That gives about 20–50k pairs, labelled by the teacher, with hard
  negatives (same topic, wrong angle).
- **Critical split:** hold out *whole cards*, not random pairs. The test is whether the model works on
  interest descriptions it has never seen, because that is what users will write.
- Watch for the known "Noul follows the labels instead of the state" failure. Check the spread of P across
  articles for each card. A card that returns about the same P for every article is broken.
- **Ship rule:** use Laya for Call B only if unseen-card AUC is close to Jev's. Otherwise keep Jev for cards.

**Retraining:** only when the question set changes (a new `question_set.sha256`) or when drift shows up in
the online metrics. It is not per user. Per-user personalization stays in the tiny logistic model, which
sits on top of whichever engine produced the features.

---

## 5. Hosting and throughput

- **Single user or self-hosted** (about 1,500 articles/day, about 3k calls):
  - A modern desktop or server CPU through ONNX (about 140–460 ms per call) handles this in minutes a day.
  - The 49 s/call result on a small 4 vCPU VPS means **cheap VPSs are not enough**. Use a real CPU, Apple
    silicon, or quantized weights (GGUF / q8, which is still untested).
- **Decided hosting is CPU-only** (PLAN §4.6, no GPU). At invite-only scale that's fine on an 8-core-class
  box. The GPU numbers below matter only if the service ever grows far beyond that.
- **Large multi-tenant** (about 150k articles/day, hypothetical):
  - One T4-class GPU at about 72 ms per 10 questions gives more than 10 calls/s, which is plenty.
  - Compare that with about $79/day on Jev at that scale (`jev-questions.md` §5). Laya's savings only become
    significant at large scale.
  - For one user, Jev costs about $0.29/day. At that size the case for Laya is **language, privacy and vendor
    independence**, not price.
- **Integration:** it plugs in as `LayaEngine` behind the plan's `DecisionEngine` interface (PLAN §4.4),
  using `receptron/laya` from Node. The request shape is the same, so the question sets are reused as they
  are. Differences to plan for:
  - ≤ 20 options per Choice
  - a smaller state
  - per-question-type temperature calibration applied in our code
- **Routing by language:**
  - `lang ∈ {sk, cs}` → Laya (fine-tuned) for Call A.
  - Anything else → Jev.
  - Card matching → Jev (with translation if needed) until Stage 2 passes.
  - Store the engine and version with every answer, because the personal model's features must come from a
    consistent engine.

---

## 6. Recommendation

1. **Extend the Phase 0 spike** (PLAN §8) with Laya. It's cheap, since everything runs on the new
   `golden-v1` set. Compare, per language (EN / SK / CZ):
   - Jev with native text
   - Jev with English questions and native state
   - Jev with a machine-translated state
   - Laya-multilingual zero-shot (expected to be near random; this is a baseline)
2. **If Jev handles SK/CZ well enough** (within about 5 AUC points of EN): stay on Jev. Laya becomes the
   offline or fallback engine and can wait.
3. **If not:**
   - Short term, use Jev on translated SK/CZ text.
   - In parallel, build the Stage 1 dataset and fine-tune Laya-multilingual for enrichment.
   - Attempt Stage 2 (cards) only after Stage 1 works.
4. **Don't build the product on Laya's zero-shot behaviour.** The base checkpoints score near random on typed
   decisions, and the published Laya-vs-Jev figures weren't measured in the same run.
5. Revisit in about 3 months. Both projects are weeks old (Laya went public 2026-09-18, and Jev 1.13 is
   early access). Things will move quickly: a Jev multilingual release, Laya community fine-tunes, or
   independent benchmarks.

## Sources

- [Flowtivity: Laya, an open-source Jev alternative](https://flowtivity.ai/blog/laya-open-source-jev-alternative/)
- [convaiinnovations/laya model card](https://huggingface.co/convaiinnovations/laya)
- [Laya project site](https://laya.convaiinnovations.com/)
- [systemonemodels.org: Laya](https://systemonemodels.org/models/laya/)
- [receptron/laya: Node/TS ONNX port](https://github.com/receptron/laya)
- [AI Weekly: Convai ships Laya](https://aiweekly.co/alerts/convai-ships-laya-a-421m-modernbert-decision-model-apache-20)
- [TypeSafe Models page: language support](https://docs.typesafe.ai/models.md)
