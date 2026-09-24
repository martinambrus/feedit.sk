# Spec 04: Decision engine (`packages/engine`)

Status: **binding**. **Intent:** one narrow door to every decision model. Jev is primary, but it is
new, early-access and rate-limited, so the rest of the system must neither know nor care which engine
answered. Every call is budgeted, retried sensibly, logged with its cost, and degrades to "no answer"
instead of failing the pipeline.

---

## 1. Public API

```ts
// ── packages/shared/src/ports.ts  (shared, because packages/db implements EngineStore; engine re-exports) ──
export type EngineName = 'typesafe' | 'llm' | 'laya';
export type CallKind = 'enrich' | 'match' | 'cluster' | 'suggest' | 'translate' | 'eval';
export type CallStatus = 'ok' | 'error' | 'timeout' | 'rate_limited' | 'invalid_request' | 'invalid_response' | 'auth_error';

export interface EngineCallRow {               // mirrors engine_calls (spec 02 §3.1)
  engine: EngineName | 'libretranslate'; kind: CallKind; model?: string; articleId?: string;
  questionSetId?: string; cardIds?: string[]; userId?: string; nQuestions: number;
  inputTokens: number; outputTokens: number; costUsd: number; latencyMs?: number;
  attempts: number; status: CallStatus; error?: string; createdAt: Date;
}
export interface UsageRow {                    // mirrors usage_daily
  day: string /* YYYY-MM-DD, UTC */; userId: string /* or the platform sentinel */;
  engine: string; kind: CallKind; calls: number; inputTokens: number; outputTokens: number; costUsd: number;
}
export interface ExternalCall {                // non-engine calls logged through the router (translation, spec 07 §2)
  engine: 'libretranslate' | 'llm'; kind: 'translate' | 'eval'; model?: string; articleId?: string;
  inputTokens: number; outputTokens: number; costUsd: number; latencyMs: number;
  status: CallStatus; error?: string;
}
export interface EngineStore {                 // implemented in packages/db
  insertCall(row: EngineCallRow): Promise<void>;                 // engine_calls
  upsertUsage(row: UsageRow): Promise<void>;                     // usage_daily
  spendSince(fromUtc: Date, opts: { excludeKinds: CallKind[] | 'none' }): Promise<number>;
  getSetting<T>(key: string): Promise<T | undefined>;
  setSetting<T>(key: string, value: T): Promise<void>;
}

// ── packages/engine/src/types.ts ──
export type { EngineName, CallKind, CallStatus, EngineCallRow, UsageRow, ExternalCall, EngineStore } from '@feedit/shared';
export type Priority = 'interactive' | 'bulk';

export type Criteria = string | JsonObject | JsonArray | null;          // TypeSafe "EntryType"
export type NoulQuestion   = { type: 'noul';   instructions: Criteria; criteria?: { true?: Criteria; false?: Criteria } };
export type ChoiceQuestion = { type: 'choice'; instructions: Criteria; criteria: Record<string, Criteria> };   // 2..255 options
export type ScoreQuestion  = { type: 'score';  instructions: Criteria; criteria: Criteria[] };                 // 2..10 levels
export type Question = NoulQuestion | ChoiceQuestion | ScoreQuestion;

export type NoulAnswer   = { type: 'noul';   p: number };
export type ChoiceAnswer = { type: 'choice'; choice: string; probabilities: Record<string, number>; confidence: number };
export type ScoreAnswer  = { type: 'score';  score: number; probabilities: number[]; confidence: number; levels: number };
export type Answer = NoulAnswer | ChoiceAnswer | ScoreAnswer;

export interface EngineRequest {
  kind: CallKind;                          // never 'translate' (that is ExternalCall only)
  state: JsonValue;
  questions: Record<string, Question>;     // keys: [a-zA-Z0-9_.-]{1,64}
  questionSetId?: string;                  // for logging
  questionSetSha: string;
  articleId?: string;
  cardIds?: string[];
  userId?: string;                         // cost attribution
  priority: Priority;
}

export type EngineOutcome =
  | { ok: true; engine: EngineName; model: string; answers: Record<string, Answer>;
      usage: { inputTokens: number; outputTokens: number }; costUsd: number; latencyMs: number }
  | { ok: false; reason: 'no_key' | 'budget' | 'circuit_open' | 'error' | 'invalid_request'; detail?: string };

export interface DecisionEngine {                // implemented by each engine
  readonly name: EngineName;
  ask(req: EngineRequest, signal: AbortSignal): Promise<EngineOutcome>;
}

export interface RouterStatus {
  breakers: { typesafe: BreakerState; llm: BreakerState };  // BreakerState as in settings['engine.circuit'] (spec 02 §2)
  spendTodayUsd: number; budgetUsd: number; llmCallsToday: number;
}

export interface EngineRouter {                  // the ONLY thing handlers use
  ask(req: EngineRequest): Promise<EngineOutcome>;
  status(): RouterStatus;
  canSpend(estimateUsd: number, priority: Priority): boolean;   // spend-guard check for non-engine paid calls (tier-2 translation)
  recordExternalCall(call: ExternalCall): Promise<void>;         // logs translation calls through the same store and budget
}

export function createEngineRouter(deps: {
  config: EngineConfig; store: EngineStore; logger: Logger; clock: Clock;
  engines?: Partial<Record<EngineName, DecisionEngine>>;       // inject fakes (tests, E2E, eval dry run)
  budgetOverrideUsd?: number;                                    // eval only; see below
  ignoreDailyCaps?: boolean;                                     // eval: ignore llm/tier-2 daily caps
}): EngineRouter;
```

**Eval routers.** A router created with `budgetOverrideUsd`:
- records **every** call, engine and external (translation), with `kind = 'eval'`, so eval spend never
  counts against the production daily budget (spec 04 §6 excludes `kind = 'eval'`)
- enforces its own budget: the router's **cumulative spend since it was created** (kept in memory,
  starting at 0) plus the estimate must stay ≤ `budgetOverrideUsd`, otherwise the call returns `budget`
- uses one router per `eval run` invocation, so `--max-usd` is a per-invocation cap (spec 10 §3)

Handlers never see HTTP errors. They get `ok: false` and apply their degraded behaviour.

---

## 2. Answer normalization (all engines)

Every engine converts its raw output into the `Answer` union above and validates it:

- Every requested key is present, with the requested `type`. A missing or mistyped key makes the
  whole response `invalid_response`.
- `noul.p ∈ [0, 1]`.
- Choice `probabilities` has exactly the option keys, and the values sum to 1 ± 0.02. Renormalize
  within tolerance, reject outside it. `choice` is the argmax.
- Score `probabilities` is an array of length `levels` (converted from TypeSafe's string-keyed object).
  `score = Σ i·p_i` is recomputed and must match TypeSafe's value within 0.02.
- `confidence` comes from the engine when provided (Jev). Otherwise
  `confidence = 1 − H(p) / ln(k)`, where H is the Shannon entropy and k the number of options or levels.

The normalized answers are what is stored in `article_facets.answers`, `card_answers.p`,
`article_topics_l2.answer` and `eval.run_answers`.

---

## 3. TypeSafeEngine (Jev over HTTP)

**Request:** `POST {TYPESAFE_BASE_URL}/v1/systemone` with headers
`Authorization: Bearer {TYPESAFE_API_KEY}` and `Content-Type: application/json`.

```json
{ "model": "jev-1.13.0", "state": <state>, "questions": { "<key>": { "type": "noul|choice|score", "instructions": …, "criteria": … } } }
```

**Response** (documented shape, validated with zod):

```json
{ "model": "jev-1.13.0",
  "answers": {
    "<key>": { "type": "noul", "noul": 0.95 }
           | { "type": "choice", "choice": "billing", "probabilities": {"billing": 0.88, …}, "confidence": 0.81 }
           | { "type": "score", "score": 1.05, "legend": {"0": "…"}, "probabilities": {"0": 0.0, "1": 0.95, "2": 0.05}, "confidence": 0.92 } },
  "usage": { "input_tokens": 296, "output_tokens": 20 } }
```

**Status handling:**

| HTTP | Meaning | Action |
|---|---|---|
| 200 | ok | normalize (§2); `invalid_response` counts as a retryable error once |
| 401 / 403 | bad key | no retry. Outcome `error` with `auth_error`. Opens the breaker in **auth** mode (§5) and alerts admins |
| 422 | invalid request | no retry. Outcome `invalid_request`. Log the question-set sha and the response body. This is a bug and must surface in tests |
| 429 | rate limited | retry (§4). Feeds the client-side limiter |
| 5xx, network error, timeout | transient | retry (§4) |

- Per-attempt timeout: 30 s.
- **Model pinning:** the `model` field is always `TYPESAFE_MODEL` (default `jev-1.13.0`), never an
  alias, in production.
- The response's `model` is stored with every answer.
- **Cost:** `input_tokens × TYPESAFE_PRICE_PER_MTOK_USD / 1e6`. Output is free.

**Client-side rate limiter:** token buckets at **1,000 requests/min** and **200,000 input tokens/s**
per process. Both sit below the documented 1,200/min and 250k/s. Token cost is estimated before the
call (§6.1). Calls wait for capacity (bulk priority waits behind interactive).

---

## 4. Retries and concurrency (router level)

- **Attempts:** at most **4** per logical call.
- **Delay before attempt n (n ≥ 2):** `500 ms × 2^(n−2)` ± 20 % jitter. If the server sent
  `Retry-After`, use `max(delay, min(retryAfter, 30 s))`.
- **Retry on:** 429, 5xx, network errors, timeouts, and one `invalid_response`.
- **Concurrency:** a process-wide semaphore of `ENGINE_CONCURRENCY` (default 8) in-flight engine calls.
  `interactive` requests get priority in the semaphore queue.
- **Logging:** one `engine_calls` row per logical call (not per attempt), with `attempts`, the final
  `status`, latency (first send to final answer), tokens and cost.

---

## 5. Circuit breaker and fallback chain

**Breaker** (per engine and per worker process, in memory):

- **Rolling window:** the last 5 minutes of logical calls.
- **Opens** when the window has ≥ 20 calls **and** the failure share is > 20 %. Failures are final
  outcomes `error`/`timeout`/`rate_limited` after retries.
- **Open duration:** 2 min, doubling on each consecutive re-open, capped at 30 min.
- **Half-open:** one probe call is allowed. Success closes the breaker and resets the doubling. Failure
  re-opens it.
- **Auth mode:** a 401/403 opens the breaker until the process restarts or an admin presses "retry" in
  the admin UI. It does not self-heal.
- **Mirror:** every state change is written to `settings['engine.circuit']` (shape in spec 02 §2), and
  the worst state across processes wins. The admin UI and the house jobs (`house.rescore-degraded`,
  `house.alerts`) read **the mirror**, never their own process's breaker.
- **Reset:** `POST /admin/engine/reset-breaker {engine}` writes
  `engine.circuit.resetRequested[engine] = now`. Every router polls the key every 10 s. When the value
  is newer than its last reset, the router closes that breaker (including auth mode), resets the
  doubling, and writes the new state to the mirror.

**Fallback chain** in `EngineRouter.ask(req)`:

1. `no_key` if `TYPESAFE_API_KEY` is missing (dev without a key).
2. Spend guard (§6): `budget` if exceeded.
3. TypeSafe breaker closed or half-open → TypeSafeEngine. On success, return.
4. If TypeSafe failed or its breaker is open:
   - if `LLM_FALLBACK_ENABLED` **and** `req.priority === 'interactive'` **and** the LLM daily call cap
     (`settings['engine.llm_daily_cap']`, default 200) is not reached **and** the LLM breaker is
     closed → LlmFallbackEngine (§8)
   - (M9) if a fine-tuned Laya checkpoint is configured for `req.kind` → LayaEngine (§9)
5. Otherwise `circuit_open` or `error`.

**Degraded handling is the caller's job** (spec 03 §1, spec 05, spec 06).
`house.rescore-degraded` (every 10 min, spec 11 §6) re-enqueues `article.enrich` for articles with
`pipeline_state = 'degraded'` and `first_seen_at` in the last 72 h, but only while the TypeSafe breaker
is closed and the budget allows. Answers produced by the LLM fallback are **replaced** by Jev answers
when the article is re-processed, because personal models must learn from one engine
(spec 06 §8.1). `house.rescore-degraded` therefore also re-enqueues articles whose `enrich_engine = 'llm'`.

---

## 6. Spend guard

- **Budget:** `settings['engine.daily_budget_usd']`, falling back to `DAILY_BUDGET_USD` (default $2.00).
  The day is UTC.
- **Spend today:**
  `SELECT coalesce(sum(cost_usd), 0) FROM engine_calls WHERE created_at >= (date_trunc('day', now() AT TIME ZONE 'UTC') AT TIME ZONE 'UTC') AND kind <> 'eval'`.
  - Loaded at start, refreshed every 60 s, and incremented locally after each call.
  - Evaluation spend (`kind = 'eval'`) never consumes the production budget. Eval runs use their own cap
    (`budgetOverrideUsd`, spec 10 §3).
- **Before each call:** estimate the cost (§6.1). If `spend + estimate > budget`, return `budget`
  without calling.
- **Crossings:** when spend first crosses **80 %** or **100 %** of the budget on a UTC day, the router
  records it in `settings['engine.budget_alerts'] = {day, p80At?, p100At?}`. It sends **no** email:
  `house.alerts` (spec 11 §6.1) owns every notification.
- **Interactive allowance:** `interactive` requests may exceed the budget by at most 10 % so users can
  still add a card while the bulk backlog is paused. `bulk` requests stop at 100 %.

### 6.1 Token estimation

`estimateTokens(state, questions) = ceil(len(JSON.stringify(state)) / 3.5) + Σ ceil(len(JSON.stringify(q)) / 3.5) + 20`.

This is deliberately conservative. The same function is used for request packing (spec 05 §5.2). A
metric records `actual / estimated` so the divisor can be tuned later.

---

## 7. Cost attribution

After every call, upsert `usage_daily`:
- `user_id` = `req.userId` if set, otherwise the platform sentinel.
- Shared Call A and Call B costs are **platform** cost.

Per-user attribution for dashboards is computed on read by `admin_usage_attribution(days)`
(spec 02 §6):
- **direct:** the user-attributed rows
- **shared:** the platform `match` cost split by the user's share of `(feed, card)` holdings, each
  weighted `1/holders`

Exact per-question costs are not stored. The admin usage page (spec 08 §9) shows:
- platform $/day
- per-user attributed $/day (backfills, suggestions, private forks)
- the top 20 users by attributed cost

---

## 8. LlmFallbackEngine (Ollama Cloud)

**Request:** `POST {OLLAMA_BASE_URL}/api/chat` with header `Authorization: Bearer {OLLAMA_API_KEY}`.

```json
{
  "model": "glm-5.3-flash",
  "stream": false,
  "format": <JSON schema, below>,
  "options": { "temperature": 0 },
  "messages": [
    { "role": "system", "content": "<SYSTEM_PROMPT>" },
    { "role": "user", "content": "<JSON.stringify({ state, questions })>" }
  ]
}
```

`SYSTEM_PROMPT` (a constant, versioned with the engine):

> You are a careful classifier. You receive a JSON object with `state` (the content to judge) and
> `questions`. Answer every question about `state` only. For a question of type "noul", give the
> probability (0 to 1) that the answer is yes. For "choice", give a probability for every option; they
> must sum to 1. For "score", give a probability for every level index, from the first level (0) to the
> last; they must sum to 1. Be calibrated: use values near 0.5 when unsure. Output only JSON matching
> the schema.

**Schema generation:** an object with one required property per question key.
- `noul` → `{type:'object', properties:{p:{type:'number', minimum:0, maximum:1}}, required:['p']}`
- `choice` → `{type:'object', properties:{probabilities:{type:'object', properties:{<opt>:{type:'number'}…}, required:[…all options]}}, required:['probabilities']}`
- `score` → the same shape with keys `"0"…"n-1"`.

**Post-processing:**
- Parse `message.content` as JSON.
- Clamp values to [0, 1] and normalize; if the sum is 0, use a uniform distribution.
- Then run the §2 normalization.

**Usage and cost:**
- `prompt_eval_count` and `eval_count` from the response give the token counts.
- Cost comes from the model price table in config: `glm-5.3-flash` $0.15 in / $0.50 out per MTok,
  `glm-5.3` $1.40 / $4.40. Peak prices are used as the upper bound.

**Limits:**
- Timeout 60 s, 2 attempts.
- Concurrency `OLLAMA_MAX_CONCURRENCY`.
- A separate breaker instance with the same parameters as §5.
- Answers are stored with `engine = 'llm'`.

---

## 9. LayaEngine (M9, optional)

- Loads a fine-tuned **Laya-multilingual** ONNX checkpoint through the Jev-compatible Node port
  `receptron/laya` (`Laya.load({subfolder})` → `laya.systemOne(state, questions)`).
- Runs in-process in a dedicated worker (`WORKER_QUEUES=article.enrich.laya`), because it needs
  about 2 GB of RAM.
- Enabled only for the kinds and languages configured in `settings['engine.laya']`, e.g.
  `{"enrich": ["sk","cs"]}`.
- Limits: ≤ 20 options per Choice. Topic questions must use the two-level walk (spec 05 §3.2).
- Per-question-type temperature calibration (fitted in the fine-tuning notebook) is applied in
  `normalize()`.
- Background and the go/no-go criteria: [`../laya-multilingual.md`](../laya-multilingual.md).

---

## 10. Fixtures and tests

- `packages/testing/fixtures/typesafe/*.json`: request/response pairs, hand-written from the
  documented shapes in §3. They are replaced by real recordings (`RECORD=1`) once a key is available.
  They cover:
  - enrich-v1
  - a match call with 3 cards
  - a cluster call
  - a suggest call
  - 422 and 429 bodies
- `packages/testing/fixtures/ollama/*.json`: the fallback engine happy path, and malformed JSON.

**Unit tests:**
- normalization edge cases (probabilities not summing to 1, missing keys, score mismatch)
- the retry schedule (fake timers)
- breaker transitions
- spend guard with the interactive allowance
- rate limiter
- LLM schema generation
- attribution

**Deterministic fake TypeSafe server** (`packages/testing/src/fake-typesafe.ts`, built in M2-T2): an
HTTP server implementing `POST /v1/systemone` with the documented response shape, so any question set
gets a plausible, repeatable answer.

| Question type | Answer |
|---|---|
| **noul** about a card or label (instructions contain `interest`, or `definition` for labels) | `0.9` if any ≥ 4-char token of that text (normalized with `normalizeText`) occurs in the state's title or excerpt; otherwise `0.1`. If `not_for` tokens match instead, `0.2` |
| **other noul** | `0.3` |
| **choice** | the first option whose key or description shares a **≥ 4-char** normalized token with the state gets 0.7, the rest share 0.3 equally (with no match, a uniform distribution); `confidence` from §2 |
| **score** | probability 1.0 on the middle level |

- `usage.input_tokens` = the §6.1 estimate. `model` = `jev-fake`.
- **Options:** `latencyMs`, `failRate` (a share of *logical* requests, chosen deterministically by
  `sha256(body)` so every retry of that request also fails), `status` overrides, and `recordRequests`.
- It is used by M2-T11, M3a-T8, M5-T6, M6-T9 (E2E) and M8-T8 (load test). The worker points at it
  through `TYPESAFE_BASE_URL`, and in-process tests can inject it through `engines`.

**Integration test `engine-breaker.int.test.ts`:**
- The fake server fails **every attempt** of 30 % of logical calls (`failRate: 0.3`, status 503).
- With fake timers, 40 calls go through the router: the breaker opens, the next calls return
  `circuit_open` without reaching the server (the request counter is asserted), and the mirror in
  `settings['engine.circuit']` shows `open`.
- After `failRate` is set to 0 and the open duration elapses, the half-open probe succeeds and the
  breaker closes.
- A reset request (`resetRequested`) closes an auth-mode breaker within one polling interval.
