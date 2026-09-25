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
  billing: 'known'|'uncertain';
  logicalRequestId: string; reservationId?: string; articleRevision?: string; stateSha256?: string;
  attempts: number; status: CallStatus; error?: string; createdAt: Date; // attempts = attempt ordinal
  // One row per wire attempt; reservationId is unique for idempotent settlement.
  // attempts increases monotonically for this engine across ALL subpacks of logicalRequestId.
}
export interface UsageRow {                    // mirrors usage_daily
  day: string /* YYYY-MM-DD, UTC */; userId: string /* or the platform sentinel */;
  engine: string; kind: CallKind; calls: number; inputTokens: number; outputTokens: number; costUsd: number;
}
export interface ExternalCall {                // non-engine calls logged through the router (translation, spec 07 §2)
  engine: 'libretranslate' | 'llm'; kind: 'translate' | 'eval'; model?: string; articleId?: string;
  inputTokens: number; outputTokens: number; costUsd: number; latencyMs: number;
  status: CallStatus; error?: string; billing: 'known'|'uncertain';
  logicalRequestId: string; attempt: number; articleRevision?: string; stateSha256?: string;
}
export interface EngineStore {                 // implemented in packages/db
  reserveSpend(input: { day: string; engine: string; kind: CallKind; userId?: string;
    estimateUsd: number; priority: 'interactive'|'bulk'; callCap?: number }): Promise<string | null>;
  settleReservation(id: string, call: EngineCallRow, usage: UsageRow,
    billing: 'known'|'uncertain'): Promise<void>; // one transaction: call + rollup + reservation
  insertCall(row: EngineCallRow): Promise<void>; // zero-cost calls only; idempotent
  upsertUsage(row: UsageRow): Promise<void>;      // used inside settlement, never independently for paid calls
  spendSince(fromUtc: Date, opts: { excludeKinds: CallKind[] | 'none' }): Promise<number>;
  getBudgetSnapshot(dayUtc: string, opts: { excludeKinds: CallKind[] | 'none' }): Promise<{
    settledUsd: number; reservedUsd: number; uncertainUsd: number;
    callsByEngineKind: Record<string, number>; // keys `${engine}:${kind}`; each attempt once
  }>;
  // Snapshot reads actual settled cost plus separate outstanding/uncertain reservation amounts;
  // never count one reservation and its audit row twice. Counts include admitted in-flight
  // attempts. Production status/caps exclude eval; admission still locks/reserves atomically.
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
  kind: Exclude<CallKind, 'translate'>;     // translation uses ExternalCall
  state: JsonValue;
  questions: Record<string, Question>;     // keys: [a-zA-Z0-9_.-]{1,64}
  questionSetId?: string;                  // for logging
  questionSetSha: string;
  articleId?: string;
  articleRevision?: string;               // immutable snapshot; decimal bigint string
  stateSha256: string;                    // hash of the exact canonical serialized state
  cardIds?: string[];
  userId?: string;                         // cost attribution
  priority: Priority;
}

export type EngineOutcome =
  | { ok: true; engine: EngineName; model: string; answers: Record<string, Answer>;
      usage: { inputTokens: number; outputTokens: number }; costUsd: number; latencyMs: number }
  | { ok: false; reason: 'no_key' | 'budget' | 'circuit_open' | 'error' | 'invalid_request'; detail?: string; retryAt?: Date };

export type EngineAttempt =
  | Extract<EngineOutcome, {ok: true}>
  | { ok: false; status: Exclude<CallStatus, 'ok'>; retryable: boolean;
      retryAfterMs?: number; detail?: string;
      usage?: {inputTokens: number; outputTokens: number};
      billing: 'known'|'uncertain' };
export interface DecisionEngine {                // adapters perform exactly ONE attempt
  readonly name: EngineName;
  ask(req: EngineRequest, signal: AbortSignal): Promise<EngineAttempt>;
}

export interface RouterStatus {
  breakers: { typesafe: BreakerState; llm: BreakerState };  // BreakerState as in settings['engine.circuit'] (spec 02 §2)
  spendTodayUsd: number; budgetUsd: number; llmCallsToday: number;
}

export interface EngineRouter {                  // the ONLY thing handlers use
  ask(req: EngineRequest, signal?: AbortSignal): Promise<EngineOutcome>;
  status(): Promise<RouterStatus>;
  canSpend(estimateUsd: number, priority: Priority): Promise<boolean>; // advisory only, never authorization to send
  reserveExternalCall(input: {engine: ExternalCall['engine']; kind: ExternalCall['kind'];
    estimateUsd: number; priority: Priority; userId?: string}): Promise<string | null>;
  recordExternalCall(call: ExternalCall, reservationId?: string): Promise<void>;
  // Paid external calls MUST reserve before HTTP. A failed attempt also settles conservatively.
}

export function createEngineRouter(deps: {
  config: EngineConfig; store: EngineStore; logger: Logger; clock: Clock;
  engines?: Partial<Record<EngineName, DecisionEngine>>;       // inject fakes (tests, E2E, eval dry run)
  budgetOverrideUsd?: number;                                    // eval only; see below
  ignoreDailyCaps?: boolean;                                     // eval: ignore llm/tier-2 daily caps
  requiredEngine?: EngineName;                                  // eval: pin, no automatic fallback
}): EngineRouter;
```

**Eval routers.** A router created with `budgetOverrideUsd`:
- records **every** call, engine and external (translation), with `kind = 'eval'`, so eval spend never
  counts against the production daily budget (spec 04 §6 excludes `kind = 'eval'`)
- enforces its own budget: cumulative settled/uncertain spend plus all in-flight reservations since
  creation must stay ≤ `budgetOverrideUsd`. Reserve under a router mutex before every attempt,
  including retries and external translations; there is no 10% interactive allowance for eval
- uses one router per `eval run` invocation, so `--max-usd` is a per-invocation cap (spec 10 §3)
- `requiredEngine` pins the requested comparison engine and disables fallback. A failed Jev sample
  remains a failed/missing Jev observation; it never quietly becomes an LLM sample. Manifest records
  the selected engine, model, capability flags and price/normalization versions. Child evaluation
  adapters share the same invocation budget authority, not independently reset $ limits.

Handlers never see HTTP errors. They get `ok: false` and apply their degraded behaviour.

---

## 2. Answer normalization (all engines)

Every engine converts its raw output into the `Answer` union above and validates it:

- Validate outbound keys, shapes, nonempty questions, option/level counts and configured request byte
  limits before spending. All numeric answers and usage counts must be finite; token counts must
  be nonnegative integers. All probabilities and confidence values must be within [0,1].
- Exactly the requested keys are present, with the requested `type`. Missing, additional or mistyped
  keys make the whole response `invalid_response`. Use own-property-safe maps for untrusted JSON.
- `noul.p ∈ [0, 1]`.
- Choice `probabilities` has exactly the option keys, and the values sum to 1 ± 0.02. Renormalize
  within tolerance, reject outside it. `choice` is the argmax; ties use the request's stable option order.
- Score `probabilities` is an array of length `levels` (converted from TypeSafe's string-keyed object).
  Validate the same bounds and sum tolerance as Choice. `score = Σ i·p_i` is recomputed and must
  match TypeSafe's value within 0.02; keys must be exactly `0` through `levels − 1`.
- `confidence` comes from the engine when provided (Jev). Otherwise
  `confidence = 1 − H(p) / ln(k)`, using `0·ln(0) = 0`. This is our proxy, not a claim that it is
  Jev's confidence formula. Confidence is not a probability of correctness; calibrate engines separately.

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
| 400 / 413 / 422 | invalid request or size | no blind retry/fallback. Outcome `invalid_request`; log set sha, request hash and a redacted, length-capped error code (provider errors can echo private inputs) |
| 429 | rate limited | retry (§4). Feeds the client-side limiter |
| 5xx (including 529), network error, timeout | transient | retry (§4) |
| Other 4xx | unsupported model/endpoint or permanent request error | no retry; alert on model/configuration failures |

- Per-attempt timeout: 30 s.
- **Model pinning:** the `model` field is always `TYPESAFE_MODEL` (default `jev-1.13.0`), never an
  alias, in production.
- The response's `model` is stored with every answer. A different model from the requested pin is
  an `invalid_response` in production; `jev-fake` is allowed only by an explicit test configuration.
- **Cost:** `input_tokens × TYPESAFE_PRICE_PER_MTOK_USD / 1e6`. Output is free.

**Client-side rate limiter:** token buckets at **1,000 requests/min** and **200,000 input tokens/s**
per API account across the deployment. Allocate static per-process shares whose sum is no greater
than those limits (API translation does not consume the Jev buckets); do not give every worker the
full account allowance. These are configurable defaults below published limits, not a guarantee.
Token cost is estimated before each attempt (§6.1); 429 lowers capacity temporarily. Waiting is
bounded by the job deadline, cancellation works while queued, and aging prevents bulk starvation.

---

## 4. Retries and concurrency (router level)

- **Single retry owner:** adapters make one wire attempt; disable SDK automatic retries. The router
  allows at most **4 TypeSafe attempts**, or **2 LLM attempts**, per logical request. Workers must
  not restart this retry loop immediately on deferred outcomes (spec 05 §5.5).
- **Delay before attempt n (n ≥ 2):** `500 ms × 2^(n−2)` ± 20% jitter. Parse `Retry-After` as
  either seconds or an HTTP date. Never retry sooner than a valid server delay. If that delay is
  longer than the remaining job deadline, return a deferred outcome with `retryAt` for the queue.
- **Retry on:** 429, 5xx, network errors, timeouts, and at most one `invalid_response`. Cancellation,
  auth errors and invalid requests are not retried. Reacquire limiter capacity and spend reservation
  before every attempt and before entering fallback.
- **Concurrency:** a process-wide semaphore of `ENGINE_CONCURRENCY` (default 8); the LLM also uses
  `OLLAMA_MAX_CONCURRENCY`. Backoff does not hold a semaphore slot. Requests and responses have
  bounded bytes; abort transport and release capacity on timeout/cancellation.
- **Logging:** one `engine_calls` row per **wire attempt**, grouped by `logical_request_id`, with
  `attempts` as a monotonically increasing ordinal for that engine across **all subpacks and retries**
  in the logical request (do not restart at 1 for each split pack). Per-subpack retry limits are
  enforced separately in memory. A fallback has its own engine rows under the same
  logical id. Sum usage/cost across all attempts, even invalid answers; do not log only the successful
  final attempt. `usage_daily.calls` counts wire attempts; reliability dashboards group by logical id.
- Responses with unknown billable usage (e.g. timed out after sending) retain their reservation as
  uncertain spend; they are not assumed free. Retrying may be billed again. Do not claim exactly-once
  provider execution unless a provider documents an idempotency-key contract.

---

## 5. Circuit breaker and fallback chain

**Breaker** (local failure windows, shared authoritative state per engine):

- **Rolling window:** last 5 minutes of logical requests, counted once per provider after retries.
  Open after ≥20 requests and >20% failures. Final `error`/`timeout`/`rate_limited`/
  `invalid_response` count as failures; budget/cancellation/invalid-request do not.
- **Open duration:** 2 min, doubling per consecutive re-open, capped at 30 min. A 401/403 instead
  sets `auth` until explicit admin reset; restarting a process must not clear a bad-key incident.
- `settings['engine.circuit']` is authoritative. Update an engine entry atomically under a row lock,
  preserving the other engine and reset fields. Routers poll at most every 10s, check shared state
  before paid attempts, and admin/house jobs read the same state. Do not overwrite the object from
  a stale process-local copy.
- **Half-open:** after `openUntil`, acquire one shared probe lease (`probeToken`, `probeUntil`) in
  that same transaction. A successful probe closes and resets doubling; a failed one reopens.
  Only the lease holder may complete that transition; an expired probe can be reclaimed after crash.
- **Reset:** `POST /admin/engine/reset-breaker {engine}` records a timestamp and atomically closes
  that engine/reset counter; routers discard older local state within one poll. State changes and
  polling are bounded and tested across two router instances.

**Fallback chain** in `EngineRouter.ask(req)`:

1. Validate the request. In dev without a primary key return `no_key` (unless an injected test engine
   exists). Production boot validates required primary credentials; missing key is not a normal outage.
2. Check breaker and reserve the selected provider's estimated spend (§6) before each send.
3. TypeSafe breaker closed or half-open → TypeSafeEngine. On success, return.
4. If TypeSafe failed or its breaker is open:
   - if `LLM_FALLBACK_ENABLED` **and** `req.priority === 'interactive'` **and** the LLM daily call cap
     (`settings['engine.llm_daily_cap']`, default 200) is not reached **and** the LLM breaker is
     closed or an acquired half-open probe → LlmFallbackEngine (§8). Reserve LLM input plus bounded
     output at its own price; a cheap Jev reservation never authorizes an expensive fallback. The
     router may split a Jev pack into smaller LLM subrequests, reusing the same immutable state; it
     returns ok only after every original key has one valid answer, otherwise callers keep the work
     pending. Each subrequest/attempt is separately reserved and logged
   - (M9) if a fine-tuned Laya checkpoint is configured for `req.kind` → LayaEngine (§9)
5. Otherwise return `budget`, `circuit_open` or `error` with a retry time when known. Never route an
   invalid request into another provider. Optional Laya has an explicit eligible-kind/language and
   engine-precedence policy; paid-provider budget exhaustion must not disable eligible local inference.

**Degraded handling is the caller's job** (spec 03 §1, spec 05, spec 06).
`house.rescore-degraded` (every 10 min, spec 11 §6) re-enqueues `article.enrich` for articles with
`pipeline_state = 'degraded'` within the full supported **14-day** ranking/backfill window, using
feed membership time for newly subscribed/deduplicated items, while the primary engine is available
and the budget allows. Use persisted keyset cursors and bounded pages with priority aging, so new
arrivals do not starve older recoverable work. Answers produced by the LLM fallback are **replaced** by Jev answers
when the article is re-processed, because personal models must learn from one engine
(spec 06 §8.1). `house.rescore-degraded` therefore also re-enqueues articles whose `enrich_engine = 'llm'`, **and**
requeues current LLM card/L2 answers even when Call A already succeeded with Jev. Recovery consults
pending/unavailable pairs in spec 05 §5.5 and stays bounded; it does not repeatedly rebill a fresh Call A.

---

## 6. Spend guard

- **Budget:** `settings['engine.daily_budget_usd']`, then `DAILY_BUDGET_USD` (default $2.00), by UTC
  day. Interactive requests have a 10% allowance; bulk requests stop at 100%. Paid retries, fallback,
  translations and API/worker processes all share this guard.
- **Atomic reservation:** immediately before each paid wire attempt, reserve its estimated upper
  cost in `engine_reservations` under a UTC-day advisory lock/transaction (spec 02). Admission uses
  settled `engine_calls.cost_usd` plus outstanding reserved/uncertain amounts. Reserve call-cap slots
  in that same operation. A 60s cache is useful for display only, never admission.
- **Settlement:** insert the attempt row, update `usage_daily` and settle its reservation in one DB
  transaction. `reservation_id` is unique so crash retries cannot double-charge internal totals.
  Known usage replaces the reserve; unknown billing keeps it `uncertain` (unknown billed cost is
  not invented in the call row; spend UI includes the reserve separately). Count each reservation
  once, not both its outstanding estimate and settled call cost. Lease expiry alone must
  not refund a request that may already have reached the provider. Reconcile uncertain spend from
  provider usage or retain it for that budget day; the next UTC day has a separate allowance.
  Attribute an attempt and its usage to `reservation.day` (UTC at send/admission), even if settlement
  crosses midnight. `engine_calls.created_at` is the send timestamp, not completion time; reserve
  each retry on its own actual UTC send day. Budget queries join reservation day, avoiding charges
  disappearing from yesterday or being counted twice today during late settlement.
- **Cap meaning:** with estimated tokenization this is a conservative application budget, not a
  mathematically exact provider invoice cap. Actual usage above a reserve stops further calls and
  alerts. Set a provider-side hard spend limit when available. Never fabricate exact accounting for
  network timeouts or unavailable usage.
- **Crossings:** atomically mark 80% and 100% crossings in `settings['engine.budget_alerts']` once
  per UTC day; `house.alerts` alone sends notifications. Budget-blocked work remains queued until a
  usable budget or next UTC day; it does not consume failure attempts.
- **Evaluation:** `kind='eval'` is excluded from production spend and uses its own synchronized
  per-invocation cap (§1), including uncertainty and all concurrent attempts.

### 6.1 Token and output estimation

`estimateTokens(state, questions) = ceil(len(JSON.stringify(state)) / 3.5) + Σ ceil(len(JSON.stringify(q)) / 3.5) + 20`.

This is a planning heuristic, **not** a conservative bound for every Unicode language. Use provider
counts to record `actual / estimated` by language and request kind. Before a verified tokenizer is
available, apply a safety multiplier learned from the smoke-test fixtures and fall back to serialized
UTF-8 byte length plus overhead as the conservative bound for unfamiliar scripts. Hard byte caps,
per-engine context caps and single-question overflow rejection still apply. The same estimator and
safety policy are used by packing in spec 05 §5.2.

LLM admission also reserves its enforced output-token maximum (including thinking tokens when billed).
Configure/verify the provider output-limit option and include the schema/system prompt in input
estimates. If the provider cannot enforce a bounded output, leave that fallback disabled until an
explicit cost policy is recorded. Clipped/truncated output is an invalid answer, never partial success.

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
  "options": { "temperature": 0 },
  "messages": [
    { "role": "system", "content": "<SYSTEM_PROMPT plus JSON schema>" },
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

**Cloud capability:** Ollama's [structured-output documentation](https://docs.ollama.com/capabilities/structured-outputs)
states that Cloud does not currently support constrained structured outputs (checked 2026-09-25).
Do **not** send `format: <schema>` by default. Put the schema in the trusted system prompt and strictly
validate unconstrained JSON; malformed responses consume the bounded retry allowance. Only enable
a `format` capability flag after a successful, recorded probe against the selected endpoint/model.
The [Cloud docs](https://docs.ollama.com/cloud) require actual API model ids from `/api/tags`.

**Schema generation:** a closed object (`additionalProperties: false` at every level) with one required
property per question key. Every probability has `minimum: 0, maximum: 1`.
- `noul` → `{type:'object', properties:{p:{type:'number', minimum:0, maximum:1}}, required:['p']}`
- `choice` → `{type:'object', properties:{probabilities:{type:'object', properties:{<opt>:{type:'number'}…}, required:[…all options]}}, required:['probabilities']}`
- `score` → the same shape with keys `"0"…"n-1"`.

**Post-processing:**
- Parse `message.content` as JSON.
- Reject non-finite/out-of-range values, missing/extra keys, zero-sum distributions and truncated
  responses. Do not clamp invalid values or invent uniform answers; use §2's small sum tolerance.
- Then run §2 normalization. Article/card/example strings are untrusted data: the system prompt
  explicitly forbids following instructions embedded in them. No tool execution or generated text
  is exposed to users. Prompt injection robustness is evaluated, not assumed.

**Usage and cost:**
- `prompt_eval_count` and `eval_count` from the response give the token counts.
- Cost comes from the model price table in config: `glm-5.3-flash` $0.15 in / $0.50 out per MTok,
  `glm-5.3` $1.40 / $4.40. These rates are confirmed by the [Ollama pricing page](https://ollama.com/pricing)
  on 2026-09-25; pin the price-table version and recheck before G1. Use peak uncached rates for
  admission, account for billed thinking, and obey the subscribed account's concurrency limit.

**Limits:**
- Timeout 60 s, 2 attempts.
- Concurrency `OLLAMA_MAX_CONCURRENCY`.
- A separate breaker instance with the same parameters as §5.
- Answers are stored with `engine = 'llm'`.

---

## 9. LayaEngine (M9, optional)

- Before M9, verify checkpoint license, Node port version, supported question types, tensor shapes,
  tokenizer limits and RAM against the actual artifact; pin checksums. The following is a prototype
  integration target, not evidence that an untested checkpoint is deployable.
- Loads a fine-tuned **Laya-multilingual** ONNX checkpoint through the Jev-compatible Node port
  `receptron/laya` (`Laya.load({subfolder})` → `laya.systemOne(state, questions)`).
- Runs in-process in a dedicated worker (`WORKER_QUEUES=article.enrich.laya`), because it needs
  about 2 GB of RAM.
- Enabled only for the kinds and languages configured in `settings['engine.laya']`, e.g.
  `{"enrich": ["sk","cs"]}`.
- Limits: ≤ 20 options per Choice. Topic questions must use the two-level walk (spec 05 §3.2).
- Per-question-type temperature calibration (fitted in the fine-tuning notebook) is applied in
  `normalize()`. Calibration version and checkpoint hash are part of the answer provenance.
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
- two routers reserving the last budget simultaneously; retries/fallback/translation charge their own
  attempts; failed settlement replay is idempotent; uncertain usage and UTC rollover
- Retry-After HTTP dates, delays longer than job deadline, cancellation while waiting, no nested retries
- malformed finite/range/key data, mismatched model pins, prompt injection fixtures and output truncation
- shared breaker lost-update, probe lease expiry and reset across process instances

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


### 10.1 Provider contract gate (M3b, before quality evaluation)

Record a sanitized smoke-test report for the configured endpoints, credentials, pinned models, all
three question types, error shape, usage counters, input limits, output limits and the Cloud JSON
capability. Keep offline fixtures as contract examples; fake-server success alone does not establish
provider compatibility. Paid probes count against `--max-usd`. If a required feature is unavailable,
stop that integration and report the smallest concrete decision to the owner. Do not silently switch
provider, use an alias or expand the cost limit.

Reference contracts checked 2026-09-25: [TypeSafe API](https://docs.typesafe.ai/api),
[models and limits](https://docs.typesafe.ai/models),
[confidence semantics](https://docs.typesafe.ai/confidence),
and the Ollama links in §8. No source documents guarantee that a general classifier is immune to
adversarial feed text; add those cases to the golden evaluation.
