# Spec 03: Ingestion pipeline (feeds → articles)

Status: **binding**. **Intent:** fetch every subscribed feed once for all users, politely and safely.
Store each article once, however many feeds carry it. Hand clean, deduplicated, language-tagged text to
classification only when an eligible user requests it. Preserve bookmarked content independently of
publisher availability, and never let one broken feed or page stall the pipeline.

Code lives in `packages/feeds` (pure logic and the safe HTTP client) and in `apps/worker/src/handlers`
(I/O wiring).

---

## 1. Pipeline overview

```
feed.schedule (cron, every minute)
   └─► feed.fetch {feedId}                       fetch + parse + ingest in one handler
          └─► article.extract {articleId}        for each NEW article that is not stale
                 └─► demand gate                stop here if no eligible user demand (§1.1)
                        └─► article.translate {articleId}   only if required (spec 07 §1)
                               └─► article.enrich {articleId}  Call A (spec 05)
                                      ├─► article.cluster {articleId}   spec 05 §6
                                      └─► article.match {articleId}     authorized match demand only
                                             └─► user.rank {userId, reason}   spec 06
```

`apps/worker/src/pipeline.ts` is the **only** place that decides the next stage. Each handler ends by
calling `pipeline.after(<stage>, articleId, outcome, tx)`. Bookmark capture is a separate local
`article.capture-bookmark` path (§8.5); it does not enter the classification graph.

### 1.1 Per-user-feed inference demand

Ingestion and model inference have different eligibility rules. All public subscribed feeds continue
to fetch once globally, and local parsing/extraction/detection can run to support reading and saved
content. Merely adding a feed, card, bookmark, label or opening an article never enables model calls.

| `subscriptions.inference_mode` | Automatic article inference | Explicit training |
|---|---|---|
| `off` (default) | none | explicit `startTraining: true` consent with the selected-article request atomically changes this subscription to `training`; without consent the request is rejected, not silently enabled |
| `training` | none, including newly arriving articles | only durable requests for the individual articles the user selected |
| `active` | new feed-item associations first seen at/after `inference_activated_at`, within the supported age window | individually selected historical articles; a broader backfill requires a separate explicit request |

Until Q11 defines automatic graduation, the user explicitly enables `active`; a certain number of
ratings or a learned-model threshold cannot silently enable it. Mode changes increment the
subscription's `inference_version`. Disabling, unsubscribe or account deletion invalidates its
outstanding demand; job payloads alone never recreate authorization. A subscription in training does
not cause all its feed articles to be queued. Local preference learning from already recorded user
feedback is separate from paid article inference.

`pipeline.after`, each provider-call boundary, backfill/recovery, clustering and translation retries
all call the same `eligibleInferenceDemand(articleId, tx)` repository contract (spec 05). It joins
current active accounts, subscriptions, mode/generation, activation time and explicit training/backfill
requests. `feed_cards` is only the automatic **active-subscription** union; per-item training cards
come from durable `analysis_requests` selected-article demand. Enqueue matching only for authorized card/article pairs.
If no demand remains, the article stops at its available local stage, with no provider call and no
“degraded” failure: inference was intentionally off. Reading/ranking uses the mode-specific rules of
spec 06 and must not claim the article is awaiting inference that was never requested.

If several users demand the same public article, extraction, translation, Call A and identical Call B
inputs share caches and in-flight work by content revision/question set/model, not by user identity.
One eligible user may cause a shared result to exist; that is not permission to create extra calls
or activate subscriptions for others. Reuse safe shared results without duplicate provider requests,
while keeping private card text and user demand out of other users' API responses. A queued job whose
last requester opted out becomes a no-op; one still demanded by another user may finish and be reused.

**Degradation rules.** A terminal stage failure does not hide a readable article. Transient failures
use bounded retries first; the terminal handler advances once only while eligible demand remains:

| Stage fails | Next stage runs with |
|---|---|
| extract | no body (`article_bodies.status = failed/skipped`); enrich uses title + excerpt |
| translate (both tiers) | native text (`state_variant = 'native'`) |
| enrich: engine unavailable (`budget`, `circuit_open`, `error`, `no_key`) | `pipeline_state = 'degraded'`; no match; `pipeline.after` enqueues `user.rank` for **all subscribers** of the article's feeds, applying BM25 fallback only where their inference mode authorizes it (spec06); `house.rescore-degraded` retries later (spec 04 §5) |
| enrich: `invalid_request` (a bug in a question set) | `pipeline_state = 'failed'`; ranked like degraded; an error log with the question-set sha; never retried automatically |
| cluster | article stays unclustered |
| match | the rows stay in `match_queue` with `attempts + 1`; ranking uses whatever answers exist; exhausted rows retained after 5 attempts rank like degraded; only relevant blocker recovery resets them (spec 05 §5.5) |

---

## 2. Queues (pg-boss 10)

**Single source:** `packages/shared/src/jobs.ts` exports, for every queue, its name, zod payload
schema, `createQueue` options and typed enqueue helpers (`enqueueFetch`, `enqueueRank`,
`enqueueLearn`, `enqueueBackfill`, …) over a small `JobSender` interface.
- The migrate job creates every queue (spec 02 §1.2).
- The API and the worker both import these helpers, so payloads and keys never diverge.

| Queue | Payload (zod) | Producer | Concurrency / process | Retry | Queue options and send semantics |
|---|---|---|---|---|---|
| `feed.schedule` | `{}` | cron `* * * * *` | 1 | none | `policy: 'standard'` |
| `feed.fetch` | `{feedId, force?: boolean}` | schedule, subscribe API | 16 | 0 (failures are accounted for in the feed row) | `policy: 'stately'`, `singletonKey: feed:<id>` (at most one queued plus one running per feed), `expireInSeconds: 120` |
| `article.extract` | `{articleId}` | fetch | 8 | 2, backoff 30 s | `stately`, key `extract:<id>` |
| `analysis.process` | `{analysisRequestId: string}` | explicit selected-article training API, reconcile/retry | 4 (shares provider semaphore) | 1 queue retry; request/provider attempt ceilings still apply | `stately`, key `analysis:<requestId>`; immutable request snapshot, token-fenced publication (§2.2) |
| `article.capture-bookmark` | `{articleId}` | bookmark action, explicit capture retry | 4 | 2, backoff 30 s | `stately`, key `capture-bookmark:<id>`; coalesced local capture, requester generations rechecked; never model inference |
| `article.translate` | `{articleId, forceTier2?: boolean}` | extract, `user.rank` (spec 07 §3) | 4 | 1 | `stately`, key `translate:<id>` |
| `article.enrich` | `{articleId, priority?: 'interactive'\|'bulk'}` | extract/translate, rescore, reenrich | 8 (shares the engine semaphore) | 1 | `stately`, key `enrich:<id>` |
| `article.cluster` | `{articleId}` | enrich | 4 | 1 | `stately`, key `cluster:<id>` |
| `article.match` | `{articleId}` | enrich, `card.backfill`, itself (when rows remain) | 8 | 1 | `stately`, key `match:<id>`; drains all queued cards for the article |
| `card.backfill` | `{userId, cardIds: string[], feedIds?: string[], snapshotAt?: iso, cursor?: {firstSeenAt: iso, articleId: string}, processedCount?: int}` | API (card or subscription change) | 2 | 2 | `standard` |
| `user.rank` | `{userId, reason, full?: boolean}` | match, enrich (degraded), ingest, API, learn | 4 | 2 | queue `policy: 'stately'`. Incremental: `sendDebounced('user.rank', data, {}, 3, 'rank:<userId>')`. Full: `send('user.rank', {…, full: true}, {singletonKey: 'rank-full:<userId>'})`. Different keys mean a full request is never swallowed by a pending incremental one, while equivalent duplicates of each are suppressed; durable dirty state preserves later changes (spec 06 §7) |
| `user.learn` | `{userId}` | API (ratings), `house.nightly-learn` | 2 | 1 | `sendDebounced(…, 60 s, key learn:<userId>)` |
| `user.suggest` | `{userId}` | learn, `house.nightly-learn` | 1 | 1 | `sendThrottled(…, 86,400 s, key suggest:<userId>)`: at most daily |
| `house.rescore-degraded`, `house.expire-rules`, `house.purge-auth`, `house.reconcile`, `house.archive`, `house.purge-articles`, `house.purge-bodies`, `house.purge-engine-calls`, `house.retire-cards`, `house.purge-users`, `house.nightly-learn`, `house.metrics`, `house.alerts` | `{}` | cron (spec 11 §6) | 1 | 1 | `policy: 'singleton'` (never two runs at once) |
| `provider.validate` | `{provider: 'typesafe'\|'ollama', candidateVersion: string}` | explicit admin validation | 1 | 0 implicit retries | `stately`, key `provider-validate:<provider>:<candidateVersion>`; bounded synthetic credential probe under spec04 budget, no secret in payload |
| `house.reenrich`, `house.translate-cards` | `{since?: iso}` | admin action (spec 05 §2, spec 07 §5) | 1 | 1 | `singleton` |

**Rules:**
- Payload IDs are strings (bigint-safe).
- Handlers validate payloads with zod and drop invalid jobs with an error log. Invalid payloads are
  never retried.
- Pin the exact pg-boss 10 release and test its actual policies, payload array shape, retry and
  debounce behavior. Queue deduplication suppresses duplicate jobs; it does **not** merge payloads,
  guarantee exactly-once effects, or serialize jobs under different keys. Register N consumers with
  `batchSize: 1` to achieve the table's concurrency; await each handler before acknowledging it.
- Unimplemented milestone handlers remain unavailable and keep pending intents (`stage_unavailable`);
  never register a stub that logs and acknowledges real jobs.
- A handler re-reads current authoritative rows; a deleted entity is a successful no-op. It checks
  active user demand (§1.1), not merely subscriber count, before every model call. Never trust old job
  payloads as authorization or as a copy of current article content.

### 2.1 Durable handoffs and stale work

Every phrase “enqueue after a change” in this plan means: insert a validated `job_outbox` row
(spec 02) **in the same database transaction** as the change. `pipeline.after(stage, id, outcome, tx)`
records the next stage through this helper before commit. No network request is made inside that
transaction. API requests use the same helper. Rolled-back changes must produce no jobs.

The worker's outbox relay runs continuously (poll at most every second): claim at most 100 due,
undelivered rows with `FOR UPDATE SKIP LOCKED`, set a fresh lease token and a 60-second lease, commit,
then enqueue through the typed pg-boss helpers. Mark delivered only using the same unexpired lease
token. A crash after send may send twice; domain handlers must therefore be idempotent. On failure,
clear the lease, increment attempts, record a redacted error and retry with capped exponential delay;
alert on a row pending more than 5 minutes. Do not discard failed outbox rows automatically. Production
uses one relay loop per worker, and abandoned leases can be reclaimed safely.

Coalescing is allowed only for equivalent requests: keys include the content revision, target
question-set identity and force/full flags when relevant. Standard `card.backfill` payloads with
different card IDs are never silently replaced. Backfill captures `snapshotAt` on its first page,
then persists that timestamp, processed distinct-article count and last `(firstSeenAt, articleId)` key
in each continuation intent. Use the maximum eligible carrier `feed_items.first_seen_at` as an
article's order key; the first-50 priority boost applies to the whole request, not each page.
Use the distinct-article eligibility/order from spec 05; never restart from page one. Newer arrivals
are covered by normal ingestion or a later backfill. Every continuation rechecks current card scope,
subscription generation and authorized demand; an off/training subscription is not a bulk-work grant. A pg-boss `null` send result is not proof that a
required stronger payload was queued: retain the outbox intent until an equivalent job has been
accepted or a handler has observed that revision. Bound attempts do not discard such intent.

Article handlers snapshot `articles.content_revision` and the selected question-set/model identity
before external work, then lock/recheck the article before publishing results. A revision or active
set change discards the stale result and records work for the current revision; it must not regress
`pipeline_state`, overwrite newer bodies/answers, or invalidate newer ranking results. Re-running an
already completed stage for the same revision is a no-op unless an explicit upgrade requests it.
Queue expiration must exceed the stage's bounded wall time plus shutdown margin; a feed fetch gets
120 s, extraction 180 s, and model stages use their full provider timeout/retry bounds (spec 04).

`house.reconcile` repairs missing **still-authorized** stage work and eligible `match_queue` work,
and pending bookmark captures, in bounded batches from persisted state, so a worker crash, expired job or terminal queue failure cannot strand an article.
Permanent `invalid_request` failures are excluded until an admin fixes the question set. Tests crash
at commit/send/ack boundaries and run duplicate handlers with two workers; one current result and
all required downstream work must remain.


### 2.2 Selected-article orchestration (`analysis.process`)

The manual training API atomically creates `analysis_requests` with its immutable pre-feedback input
manifest and an `analysis.process {analysisRequestId}` outbox intent. It must not replace this with a
plain `article.enrich {articleId}` job that later reads different live article/card inputs. Automatic
new-arrival work continues through the article pipeline; a manual request has its own result lifetime.

The handler claims a due pending request, or reclaims an expired running lease, under a row lock;
sets `status='running'`, a fresh lease token and bounded expiry; commits before external work. Recheck
active user/subscription, `inference_version`, explicit request eligibility and frozen manifest/hash
before every provider admission. Cancellation/revocation invalidates the token. Renew the lease while
live; queue expiration exceeds the bounded end-to-end stages and cannot allow two current owners.

Execute the frozen input's required translation/enrichment/matching stages under the declared
question/card/model context (spec05), reusing exact snapshot-state cache results and shared in-flight
work. Every network attempt still reserves budget. A shared compatible live answer may satisfy the
request, but a newer incompatible article/card result cannot substitute for the frozen one. If the
requested provider/context is unavailable, retain a bounded retriable/failed request with an honest
reason; do not invent values or silently use a different snapshot.

Publish `result_snapshot`, `result_sha`, completion time/status and downstream learning/rank intent
in one transaction guarded by request ID, lease token, current mode/version and unmodified input hash.
Article content may have advanced since selection: retain this result for the selected historical
training event, without overwriting current article facets/translations/card answers with it. A
result may also populate a shared **current** cache only when every current revision/state/context
check matches. Training feedback/result association is defined in specs05/06.

Transient failures release the lease and set `next_attempt_at` with bounded backoff; persistent
invalid input becomes terminal and does not loop through reconciliation. An opt-out marks cancelled
and cannot be changed to complete by a late worker; still-account for any upstream spend. Duplicate
jobs after completion are no-ops. `house.reconcile` resumes due pending/expired running requests from
their immutable snapshots without applying the automatic feed-arrival age cutoff. Request retention
is spec11; no completed selection creates continuing authorization for sibling or future articles.

---

## 3. Scheduling (`feed.schedule`)

Every minute:

```sql
SELECT id FROM feeds
WHERE subscriber_count > 0
  AND status IN ('active','quarantined')
  AND next_fetch_at <= now()
ORDER BY next_fetch_at
LIMIT 300;
```

Record `feed.fetch` intents for each ID. The handler uses a dedicated connection and a
session advisory lock keyed by feed ID for the whole fetch; release it in `finally` (connection loss
also releases it). Re-read `next_fetch_at`, status and subscriber count after acquiring the lock;
a stale scheduled job is a no-op. Manual refresh carries an explicit force flag but still observes
origin cooldowns. This lock is required across both worker processes; queue keys alone are not a
business lock. Follow `merged_into_id` to a live feed before scheduling; detect corrupt cycles.
`paused` and `dead` feeds are never scheduled. A `quarantined` feed becomes due at `quarantined_until`
(the fetch handler sets `next_fetch_at = quarantined_until`).

---

## 4. Safe HTTP client (`packages/feeds/src/http/`)

All outbound HTTP for feeds, pages, discovery and robots.txt goes through `safeFetch(url, opts)`.
`packages/feeds` receives an injected `OriginLimiter` port; its PostgreSQL implementation lives in
`packages/db` and apps wire it in, preserving the package dependency rules. Tests use a fake clock
and limiter, except the two-process integration test. Robots requests do not recursively check their
own robots policy; article redirects invoke the extraction policy callback before requesting a page.

**Intent:** a user-supplied URL must never reach internal services or hang a worker.

**Public-feed boundary (accepted owner decision Q3):** this shared article architecture accepts
only public, unauthenticated feeds. Reject URL userinfo and known credential parameters (`token`,
`access_token`, `api_key`, `auth`, `password`); warn that opaque subscription URLs can still contain
secrets the service cannot recognize and require confirmation that the feed is public. Do not accept
cookies/auth headers or private-content assertions. Private/paywalled personal feeds require a
separate tenant-isolated design before support, not an exception to this fetcher.

1. **Schemes:** only `http:` and `https:`. Reject username/password URL components and URLs longer than 8,192 bytes; never log raw URL queries (they can contain secrets). **Ports:** only 80, 443, 8080, 8443 (others fail with
   `FEED_BLOCKED_ADDRESS`).
2. **Address checks** happen on **every hop**, in two places, because Node never calls `lookup` for
   IP-literal hosts:
   - **(a) IP-literal hosts.** Before connecting, if the URL host is an IP literal, validate it directly
     against the blocked ranges. Cover bracketed IPv6, IPv4-mapped IPv6, and the decimal, octal and hex
     IPv4 forms that WHATWG `URL` normalizes (e.g. `http://2130706433/` becomes `127.0.0.1`).
   - **(b) Hostnames.** Use an undici `Agent` with `connect.lookup = safeLookup`.
     - `safeLookup(hostname, options, cb)` resolves through an **injectable resolver** (default
       `dns.lookup(host, {all: true})`).
     - It rejects if **any** resolved address is blocked.
     - It honours `options.all`: with `all: true` (Node 22's `autoSelectFamily` asks for that), return
       the full array of allowed addresses; otherwise return the first.
     - The connection uses exactly the validated addresses, which prevents DNS rebinding.
   - `safeFetch(url, { resolver })` accepts the resolver for tests, so SSRF tests can map
     `evil.example` to `127.0.0.1` without touching real DNS.

   Blocked ranges (use `ipaddr.js` `range()` plus explicit CIDRs):
   - **IPv4:**
     - `0.0.0.0/8`, `10.0.0.0/8`, `100.64.0.0/10`, `127.0.0.0/8`, `169.254.0.0/16`, `172.16.0.0/12`
     - `192.0.0.0/24`, `192.0.2.0/24`, `192.88.99.0/24`, `192.168.0.0/16`, `198.18.0.0/15`
     - `198.51.100.0/24`, `203.0.113.0/24`, `224.0.0.0/4`, `240.0.0.0/4`
   - **IPv6:**
     - `::/128`, `::1/128`, `fc00::/7`, `fe80::/10`, `ff00::/8`, `64:ff9b::/96`, `64:ff9b:1::/48`, `2001:db8::/32`, `2002::/16` (6to4), `2001::/32` (Teredo)
     - `::ffff:0:0/96`: check the embedded IPv4 address against the IPv4 list
   Permit only global unicast destinations; maintain a pinned special-purpose range table, with
   tests for IPv4-compatible IPv6, scope IDs and transition ranges as well as the list above. Reject
   DNS answers with no usable addresses and re-validate on every new connection, including pooled
   connection replacement. This client never uses an environment HTTP proxy implicitly.
3. **Redirects:** no automatic redirect following. undici's `request` does not follow redirects by
   default, and no redirect interceptor is installed. The client follows `Location` itself, up to
   **5 hops**, re-validating scheme, port and address (2a/2b) on every hop. It records the final URL and
   each redirect's status/from/to. `permanentRedirect` is true only when **every hop from the original
   URL to the adopted target** is 301/308. A permanent hop followed by a temporary hop must not
   rewrite a feed's stored URL to that temporary destination. Reject redirect loops and missing or
   invalid `Location`; strip conditional validators on a change of request URL and never forward
   credentials/cookies. Article extraction checks robots for each destination before fetching it.
4. **Limits:**
   - headers timeout 10 s; total timeout `FETCH_TIMEOUT_MS` (20 s) for the entire redirect chain, DNS, decoding and body consumption, using one abort deadline
   - body capped at `FETCH_MAX_BYTES` (5 MB), on **both compressed and decompressed** bytes; abort with
     `FEED_TOO_LARGE` when either is exceeded. Limit headers to 32 KiB and destroy/drain every response.
   - accepted encodings: gzip, deflate, br; explicitly decode the undici `request` stream once. Reject
     unsupported/corrupt encodings; never assume this low-level API decompresses automatically
5. **Request headers:**
   - `User-Agent: FETCH_USER_AGENT` (a per-feed override is allowed from `feeds.fetch_options.user_agent`)
   - `Accept` appropriate to the purpose
   - `If-None-Match` / `If-Modified-Since` from `feeds.etag` / `feeds.last_modified` for feed fetches
6. **TLS:** verification is always on. There is no insecure option.
7. **Result type:**
   `{ ok: true, status, finalUrl, permanentRedirect, headers, bodyBytes: Uint8Array } | { ok: false, code, status?, message }`.
   It never throws for network or HTTP errors.
8. **Error codes:** `FEED_BLOCKED_ADDRESS`, `FEED_DNS_ERROR`, `FEED_TIMEOUT`, `FEED_TLS_ERROR`,
   `FEED_CONNECTION_ERROR`, `FEED_TOO_LARGE`, `FEED_HTTP_<status>`, `FEED_TOO_MANY_REDIRECTS`,
   `FEED_INVALID_URL`, `FEED_DECODE_ERROR`. A 304 is a successful bodyless result; HTTP failures retain
   the bounded response headers needed for `Retry-After`, without retaining or logging error bodies.
9. **Testing escape hatch:** `FETCH_ALLOW_PRIVATE=true` disables **both** the address checks and the
   port allow-list, so local fixture servers on random ports work (M1-T8, E2E). Config validation
   **rejects** this flag when `NODE_ENV=production`. SSRF tests always run with the hatch **off**, using
   the injected resolver and IP-literal URLs: `127.0.0.1`, `[::1]`, `[::ffff:127.0.0.1]`, `2130706433`,
   `0x7f.1`.

**Charset decoding** (`decodeBody(bytes, contentType)`):
1. Detect UTF-8/UTF-16 BOMs and XML byte signatures before searching declarations; decode the probe
   with that encoding so UTF-16 XML is not mistaken for an empty feed.
2. Use a supported explicit HTTP charset; otherwise the detected BOM, then the XML declaration or
   HTML `<meta charset>` / `http-equiv` in the first 2 KiB, then UTF-8.
3. For undeclared legacy feeds only, if UTF-8 produces more than 0.5 % replacement characters,
   detect with `chardet`; an unsupported encoding becomes `FEED_DECODE_ERROR`.

Decode with `iconv-lite`, strip only a leading BOM, and fixture-test conflicting declarations. JSON
Feed is UTF-8. The lenient XML pass may repair syntax but must not change the chosen encoding.

---

## 5. URL canonicalization (`canonicalizeUrl`, pure)

**Intent:** normalize provably equivalent URL syntax. Redirects and declared canonicals supply additional identity evidence; arbitrary URL rewriting must not merge distinct articles.

1. Parse with WHATWG `URL`, resolved against the base (feed URL or page URL). Reject non-http(s).
2. Lower-case the scheme and host, strip a trailing dot from the host, drop the default port.
3. Remove the fragment, except hash-bang fragments (`#!…`).
4. Remove tracking parameters:
   - case-insensitive prefix: `utm_`
   - exact names: `fbclid`, `gclid`, `dclid`, `gbraid`, `wbraid`, `msclkid`, `yclid`, `mc_cid`,
     `mc_eid`, `_hsenc`, `_hsmi`, `mkt_tok`, `igshid`, `ref_src`, `ref_url`, `cmpid`, `s_cid`, `spm`,
     `ncid`, `sr_share`, `at_medium`, `at_campaign`, `xtor`, `__twitter_impression`, `_ga`, `_gl`,
     `oly_enc_id`, `oly_anon_id`, `vero_id`, `wickedid`

   The list is a constant in `packages/feeds/src/canonical/tracking-params.ts`.
5. Preserve the order and raw encoding of remaining query pairs, including repeated keys; some
   publishers sign URLs or assign meaning to their order. Do not run an unrelated query through
   `URLSearchParams.toString()` merely to remove a tracking key. Drop an empty `?`.
6. Preserve repeated slashes, trailing slashes, percent-encoded reserved characters and path case.
7. `canonical_url` = the result; `url_key = canonical_url`, **including the scheme**. HTTP and HTTPS
   are unified only by a validated redirect/canonical relationship, not by assumption. Feed identity
   uses this same conservative normalization. Never strip arbitrary `id`, `page`, `ref` or `source`
   parameters. Keep the original fetch URL separately so removing a tracking parameter does not
   invalidate a signed request.
8. **Linkless items** have `articles.url = NULL` and
   `canonical_url = url_key = 'urn:feedit:' + feedId + ':' + sha256Hex(identity)`, where `identity` is
   the full nonempty GUID/Atom ID/JSON Feed ID, or canonical JSON of `[title, published_at, excerpt]`
   if no identifier exists. Never substitute fetch time for missing publication time. Such items
   display feed content, skip page fetching and have no “open original” action. Editing all fields
   of an identifier-less item cannot be reliably recognized; prefer an extra item to a false merge.

Unit tests cover at least 40 cases, including IDN hosts, repeated params, AMP URLs (left unchanged,
because only `rel=canonical` fixes AMP), Google News wrappers and hash-bang URLs.

---

## 6. Parsing and normalizing items (`parseFeed`, `normalizeItem`, pure)

**Parsing:**
- `rss-parser` handles RSS 0.9x/1.0/2.0 and Atom. Custom fields: `media:content`, `media:thumbnail`,
  `dc:creator`, `dc:date`, `content:encoded`, `sy:updatePeriod`, `sy:updateFrequency`.
- JSON Feed 1.0/1.1 is detected by the `version` URL. It uses a zod-validated in-house parser.
- Feed-level fields: title, `link` (site_url), description, language (→ `lang_hint`), image/icon,
  `ttl`, `sy:*`.
- Feed type is sniffed from `Content-Type` and the first non-whitespace bytes (`<rss`, `<feed`,
  `<rdf:RDF`, `{`).
- Disable external entities, DTD loading, network access, XInclude and entity expansion in **all**
  feed/OPML parsers. Reject `DOCTYPE`/`ENTITY` declarations before the lenient pass. Enforce depth
  ≤ 64, ≤ 10,000 source items/outlines and a parser CPU deadline of 2 s (terminate a parser worker
  thread on overrun); the 200 stored-item cap alone is not an input complexity limit.
- Malformed XML: before failing with `FEED_PARSE_ERROR`, retry once after a lenient cleanup that strips
  XML-forbidden control characters, fixes only bare `&` outside CDATA/comments and existing valid
  entities, and removes a BOM or bounded leading junk before the root. Never strip markup until a
  document happens to parse. A valid zero-item feed is a success; an HTML error page is not.

**Per item → `NormalizedItem`:**

| Field | Rule |
|---|---|
| `title` | strip HTML, decode entities, collapse whitespace, trim. Fall back to the first 80 chars of the excerpt, else `"(untitled)"`. Max 500 chars |
| `link` | RSS link → RSS guid only when `isPermaLink` is not false and it is an absolute http(s) URL; Atom `link[rel=alternate]` with HTML type (or omitted type); JSON Feed `url` then `external_url`. Resolve `xml:base` chains against the final feed URL. Enclosures/attachments are not article links |
| `guid` | RSS guid / Atom id / JSON Feed id are opaque, case-sensitive identifiers scoped to this feed; preserve the complete string. Empty → null; > 4,096 chars → invalid item, never silently truncate identity |
| `published_at` | RSS `isoDate` / `pubDate` / `dc:date`, Atom `published` then `updated`, JSON Feed `date_published` then `date_modified`; validate as a finite instant and normalize to UTC, otherwise null. Dates > 1 day ahead are treated as unknown (null), never advanced again on each poll |
| `author` | `creator ?? author ?? dc:creator ?? itunes:author`, as text, max 200 chars |
| `categories` | flattened strings, trimmed, deduplicated case-insensitively, max 16 entries of up to 64 chars each |
| `excerpt_html` | RSS `content:encoded` / description, Atom content (respect `type=text/html/xhtml`) / summary, JSON Feed `content_html` or escaped `content_text` / summary. Never fetch Atom `content[src]`. Bound input first, then sanitize (§6.3); output ≤ 10,000 chars without cutting through markup |
| `excerpt` | plain text of the excerpt HTML, whitespace-collapsed, max 2,000 chars |
| `feed_body_text`, `feed_body_html` | full readable publisher text and sanitized HTML before excerpt/model truncation, within the 10 MiB combined extraction-output safety limit; preserve completeness/provenance for bookmark capture, never active HTML |
| `image_url` | the first of: enclosure with `image/*` type → `media:content` (medium=image) → `media:thumbnail` → the first `<img src>` in the content. Resolved and must be http(s) |

Each fetch processes at most **200 valid items**, newest first by `published_at` (unknown dates keep
publisher order and sort last). A per-item error is counted and skipped, not a whole-feed failure.
Log only bounded item indexes/error codes. Report `items_truncated` when the feed exceeds the cap;
this beta does not follow Atom/JSON pagination or promise complete archives for feeds publishing
more than 200 items between polls. Preserve normalized full-feed text for linkless/full-content
feeds as the body fallback before truncating the display excerpt.

### 6.1 `title_norm`

`normalizeText()` lives in `packages/shared`, because `feeds` and `ranker` both use it. It applies NFKD, then remove combining marks (diacritics), lower-case, replace non-alphanumeric runs with one
space, trim. Use Unicode letters/numbers (`\p{L}`, `\p{N}`), not ASCII-only `\w`, so non-Latin text does not collapse to empty strings. Used for trigram similarity and near-duplicate checks. The same function is used for
keyword mutes (spec 06 §3.1).

### 6.2 `content_hash`

SHA-256 of canonical JSON containing the cleaned **case-preserving** title, plain excerpt,
author, sorted categories, selected link and SHA-256 of `feed_body_text` when present. It detects changes to model inputs, not article
identity; diacritic/case changes and corrections beyond character 500 must invalidate answers.
Extraction/body and translation hashes are separate revisioned derivatives (specs 02, 05, 07).

### 6.3 Sanitizing

`sanitize-html` with an allow-list:
- tags: `p, br, b, strong, i, em, u, a, ul, ol, li, blockquote, code, pre, h2, h3, h4, img, figure, figcaption`
- attributes: `a[href]`, `img[src|alt]`

Additional rules:
- `href` and `src` must be http(s), and are resolved to absolute URLs.
- `a` gets `rel="noopener noreferrer nofollow" target="_blank"`.
- Remove tracking pixels (inspect source width/height/style before stripping those attributes;
  remove an `img` with either known dimension ≤ 2). Strip `srcset`, inline styles and event handlers.
- Embedded images are removed from the stored display HTML for the beta. Keep at most the selected
  safe `image_url` as metadata; the UI's media policy (spec 11 §7) controls whether it is requested.
  This prevents an excerpt from causing uncontrolled third-party requests or hidden tracking pixels.

---

## 7. Ingest (inside `feed.fetch`)

For one fetch, in **one transaction per item**, so one bad item doesn't roll back the rest:

1. Compute `canonical_url` and `url_key`.
2. **Exact match:** look up `articles.url_key = url_key` or `article_aliases.url_key = url_key`.
   - **Found:**
     - Upsert `feed_items (feed_id, article_id, guid)`.
     - If `content_hash` differs: update classification inputs and invoke the shared
       `resetArticleAnswers` contract once to increment `content_revision` and invalidate old
       body/translation/active facet and match derivatives (spec 05 §5.6), and record extraction work for the new revision.
       Excerpt-only, category and author corrections also count. Preserve bookmarks, ratings,
       explicit labels and their immutable saved snapshots; source updates never rewrite saved content. A stale article stays stale unless an explicit reprocess requests otherwise.
     - Multiple feeds may publish different summaries of one URL. The source is the earliest
       `feed_items.first_seen_at` (tie: feed ID); only that feed updates shared title/excerpt/author
       inputs. A later syndicated excerpt must not repeatedly invalidate the article. Extraction
       can independently upgrade the body. `n_new` counts new feed-item associations, not revisions.
     - Not a new article.
3. **GUID match within the same feed** (`feed_items.guid`): treat it like found when no other article
   owns the new URL; insert an alias with source `feed_link`. If URL identity and GUID identity
   point to different articles, flag `identity_conflict` and retain the URL-owned article; do not
   merge automatically (publishers sometimes reuse GUIDs). Keep the existing GUID mapping and
   record the conflicting feed association with null GUID. Never overwrite a different article's
   unique GUID. For multiple GUIDs at an already-known URL keep the first non-null GUID.
4. **Near-duplicate candidate**: equal non-placeholder `title_norm` within the same feed in 7 days,
   plus a nonempty matching excerpt prefix or image, is a clustering hint, **not sufficient evidence
   for destructive identity merge**. Generic titles, empty excerpts and reused site logos otherwise
   delete distinct articles. Store separate articles and let story clustering fold presentation.
5. **Otherwise insert** the article:
   - `pipeline_state = 'stale'` if `published_at` is older than `INGEST_MAX_AGE_DAYS` (default 14).
     Stored and visible; automatic inference is skipped. An explicitly selected training request may
     process a retained older article under spec 05's interactive demand contract.
   - Otherwise `'ingested'`, and the article is new.
6. Insert `feed_items`. If a new/current source item supplies publisher body content, store its full
   readable text, sanitized HTML, completeness/provenance and bounded model lead as the revisioned
   fallback in `article_bodies` (`extractor_version = 'feed-v1'`, status `ok`). Linkless items advance extraction without HTTP; linked items may replace this body
   only after successful page extraction. No early skip/error discards a valid feed body.

**A feed newly carrying an already-processed article** applies to found articles in steps 2–3, and to the extract
merge (§8.1 step 4) and feed merge (§9) whenever a `feed_items` row is **newly inserted** for an
article whose `pipeline_state` is `enriched`, `matched` or `degraded`. In the same transaction:
- resolve eligible demand for this **new association**, including activation-time/generation
  checks; `feed_cards` alone cannot authorize historical arrivals or disabled users
- upsert `match_queue` only for that eligible card union, with `article_revision = $currentRevision`
- record `article.match` work only when authorized pairs need current answers; reuse valid answers
- enqueue an incremental `user.rank` for the feed's subscribers

Otherwise readers who follow only this feed (typical for Google-News-style aggregators) would never
get answers for the article.

**Concurrency:** exact keys and aliases share one identity namespace. Lock all candidate URL keys
with transaction advisory locks in lexical order, then recheck `articles` and `article_aliases` inside
the item transaction. Article updates/merges lock IDs in ascending order; on unique conflict or
serialization/deadlock failure, retry the whole short transaction up to 3 times with jitter. Never
use “lookup then insert” without a conflict path. A canonical key must never name A in `articles`
and B in `article_aliases`. Alias inserts that conflict with a different owner invoke the guarded
merge/conflict path, not `ON CONFLICT DO NOTHING` with silent data loss.

After all items:
- Update the feed row (§9).
- Update `feeds.publish_stats.recent_gaps_s` with the gaps between the newest ≤ 20 `published_at`
  values.
- The item transaction already recorded `article.extract` for each new/revised non-stale article;
  this final feed-stat transaction does not own its only enqueue. A crash after item commit cannot
  lose work merely because the fetch did not reach its “after all items” block.
- Record subscriber rank intents for new associations including stale/failed articles, which need
  no paid pipeline stage to become readable.

A new subscription's first fetch ingests up to 200 items. Its default `off` mode costs **zero
article model calls**, even for recent items. Automatic active processing starts with newly arriving
eligible feed items; training requests authorize only their selected article, not this initial batch.

---

## 8. Extraction (`article.extract`)

**Intent:** get enough clean text (the body lead) to classify well, without hammering sites or
fetching non-HTML media.

### 8.1 Steps

1. **Skip list** (status `skipped`, no fetch):
   - hosts `youtube.com`, `youtu.be`, `vimeo.com`, `x.com`, `twitter.com`, `instagram.com`,
     `facebook.com`, `tiktok.com`, `open.spotify.com`, `podcasts.apple.com`, `soundcloud.com`
   - paths ending in `.pdf`, `.mp3`, `.m4a`, `.mp4`, `.mov`, `.zip`, `.jpg`, `.png`, `.gif`, `.webp`
   - a chosen article URL that is itself an audio/video enclosure; a podcast entry with a normal
     HTML page is still extractable. Match host suffixes on label boundaries and file extensions
     case-insensitively on the pathname, not on query strings. Linkless entries use feed text
2. **robots.txt:**
   - Fetch `/robots.txt` per origin through `safeFetch` and cache it in an in-memory LRU
     (5,000 origins, 24 h TTL).
   - Parse with `robots-parser`.
   - If our UA (product token `FeedItBot`) is disallowed for the path, set status `blocked` and skip.
   - 404/410 and other unavailable 4xx count as allow-all; 401/403 are conservatively disallowed.
     429 observes the origin cooldown. Network/DNS/timeouts and 5xx are **unreachable**, not allow-all:
     use an unexpired cached rule or disallow this attempt. Cache temporary failures for 5 minutes,
     not 24 h. Re-check robots on redirect destinations before fetching their article paths.
     These semantics follow [RFC 9309 §2.3](https://www.rfc-editor.org/rfc/rfc9309.html#section-2.3).
3. **Fetch** the article URL with `Accept: text/html,application/xhtml+xml`.
   - A non-HTML `Content-Type` → `not_html`.
   - Record `resolved_url` (the final URL after redirects).
4. **Alias and merge by redirect:** if `canonicalize(resolved_url)` has a different `url_key`:
   - If another article already owns that key, **merge**:
     - call the atomic `mergeArticles(sourceId, targetId)` contract in §8.4; never delete an
       article merely after moving `feed_items`
   - Otherwise add an alias with source `redirect`.
5. **Parse:**
   - `linkedom` `parseHTML`, then `new Readability(document, { charThreshold: 200 }).parse()`.
   - `rel=canonical`: if the page declares `<link rel="canonical">` on the **same registrable domain**
     (use `tldts` with the private suffix list, and require exact host equality if no registrable
     domain exists), and it resolves to an allowed http(s) URL without credentials, apply §8.4 with
     source `rel_canonical`. Reject multiple conflicting canonicals, home/list-page targets and
     known cross-article conflicts. A canonical is identity evidence, not permission to bypass
     `safeFetch`, robots or the fetch limits. Parse inertly: no scripts or automatic resource loads.
   - No result, or text shorter than 200 chars → status `failed` with error `no_content`.
6. **Store:**
   - `body_text` = full Readability `textContent`, with readable paragraph boundaries retained.
     `body_html` = the full sanitized readable article fragment, with §6.3 active-content/media rules.
     Do not apply the excerpt's 10,000-character or model's lead limit to these archive source fields.
   - Fetched/decompressed input remains capped at 5 MiB. Extracted text + HTML is capped at 10 MiB
     total UTF-8 bytes with well-formed truncation; record partial/limit status rather than falsely
     claiming a full capture. Preserve all readable content available within these safety limits.
   - Store completeness and provenance: page versus feed content, whether output was truncated, and
     known teaser/paywall/blocked/no-content reason. A short result or a successful HTTP 200 does not
     prove access to the full publisher article. No model invents or reconstructs unavailable text.
   - `body_lead` = the first 1,500 chars, cut at the last sentence end (`. ! ? …`) after char 1,000 if
     there is one.
   - `word_count` = whitespace token count of `body_text`, or of the excerpt if there is no body.
7. **Language detection** (§8.3). Set `articles.lang` and `lang_confidence`. A genuinely changed
   body/language uses `resetArticleAnswers` once, installing that new body at the incremented revision
   and checking current demand before choosing enrichment/translation as the next stage (do not
   enqueue extraction recursively). No demand means successful local completion.
8. For every terminal status, including skipped/blocked/not_html/failed, upsert `article_bodies`,
   run language detection on available feed text, CAS the input revision, and advance through
   `pipeline.after('extract', ..., tx)`. An early return must not strand the article as `ingested`.
   Preserve a previously good body until a replacement succeeds; never replace it with an empty
   timeout result for the same revision.

### 8.2 Politeness

- The safe client uses one shared PostgreSQL-backed per-origin throttle across API discovery,
  worker feed fetch, robots and page extraction: at most **2 concurrent** requests and **1 s between
  starts**. Short transactions on an `origin_fetch_state` row reserve start time and expiring request
  leases; never hold that transaction while making HTTP requests. All redirects reserve against
  their destination origin too. Lease expiry exceeds the total request deadline and is reclaimed
  after a crashed process. Production scaling to two workers must not double these limits.
- 429 or 503 with `Retry-After` persists an origin cooldown (parse seconds or HTTP-date, ignore
  invalid values, clamp to 24 h). A missing header uses at least 60 s. Jobs record delayed outbox
  intents with `available_at` rather than sleeping inside a worker or consuming a transient retry
  as a permanent extraction failure. Jitter must never shorten the server-requested delay.
- The origin row stores `next_start_at`, `blocked_until` and a bounded JSON array of at most two
  `{token, expires_at}` leases (spec 02). Reserving removes expired leases under a row lock. Release
  the exact token in `finally`; a long publisher cooldown releases the queue consumer promptly.

### 8.3 Language detection (`detectLanguage`, pure, in `packages/shared`)

It lives in `packages/shared`, because `translate`, `feeds` and the API (card text) all use it.
Signature: `detectLanguage(text, { hint?, minLength? = 40 })`.

**Input:** `title + ' ' + excerpt + ' ' + body_lead.slice(0, 1000)`.

1. If the text is shorter than `minLength` chars: `lang = hint ?? 'und'`, confidence 0. For articles, the
   hint is `feed.lang_hint`; for card texts, it is the user's locale, with `minLength: 10` (spec 07 §5).
2. Otherwise run `francAll(text, { only: [eng, slk, ces, deu, pol, hun, fra, spa, ita, por, nld, ukr, rus], minLength: Math.min(20, minLength) })`.
3. `francAll` returns `[iso639_3, score]` tuples. Read the best two valid tuples explicitly and
   handle `und`/an empty or single-result list. `conf = top[1] - second[1]` (0 if no comparison).
   This is a relative separation score, **not** calibrated probability of correctness. Normalize
   BCP 47 hints such as `sk-SK`/`cs_CZ` to base language before matching.
4. If `conf < 0.05` and a hint is set, use the hint.
5. **Slovak/Czech tie-break:** if the top two are {slk, ces} and `conf < 0.15`, use the hint when it is
   `sk` or `cs`. Otherwise keep `top`.
6. Map ISO 639-3 to 639-1 (eng→en, slk→sk, ces→cs, …). If nothing matches, `lang = 'und'`.

A language outside this detector whitelist can be misidentified as a supported language. The beta
must expose `und`/unsupported results without silently translating as English; preserve publisher
language hints outside the whitelist and use native/degraded handling (spec 07). Broader language
coverage requires detector fixtures and a G1 decision, not just adding a translation mode.

`feeds.lang_hint`:
- Set from `<language>` when present.
- Otherwise, after 20 articles, set to the majority detected language if it covers ≥ 70 % of them.

### 8.4 Article merge without losing reader data

Acquire both article row locks in ascending ID order and recheck identity/revisions in one short
transaction. The existing owner of the final URL survives. If either article is referenced by
`eval.sample`, assignments, ratings or facet labels, defer destructive merge, log an admin conflict,
and keep both identities; golden labels must not be silently rewritten.

- Move feed associations and all URL aliases, resolving duplicate feed associations by earliest
  first-seen time and preserving a nonconflicting GUID. Enforce the cross-table identity invariant.
- For each user, move a source-only `user_article` row. On a collision: union explicit `label_ids`;
  keep a bookmark if either is bookmarked (earliest timestamp); keep latest opened/read timestamps;
  select rating/reason by latest `rated_at` (target wins ties); keep maximum dwell; unarchive if
  either copy is unarchived. Clear cached ranking/explanation and enqueue a full rank for affected
  active users. These rules preserve current positive state; feedback history is also retained.
- Repoint **all** `feedback_events` to the survivor without dropping events. Learning collapses
  explicit labels by current article identity and latest event; a merged story must not become two
  training examples. Repoint `analysis_requests.article_id` before deleting the source article so
  completed frozen training history survives; keep immutable input/results unchanged. Cancel old
  pending/running request generations and their leases rather than letting stale jobs publish to a
  different live identity. Move outstanding match-card requests, keeping minimum priority, oldest enqueue
  time and conservative attempt count. Reconcile story-cluster representative/count fields.
- Move snapshot associations without rewriting their captured bytes or URLs. If the same user has
  independently saved **different** snapshots of both articles, defer the destructive merge and keep
  both article identities until a version-preserving merge UI/contract exists; never choose one
  snapshot arbitrarily and delete the other. Unexpired Undo snapshot pins also block any destructive
  merge that would make exact Undo impossible. Identical snapshot checksums can share storage.
- Keep the target's source metadata and any valid target body; take the source body only when the
  target lacks a successful extraction. Use `resetArticleAnswers` once to increment the surviving
  content revision, retain the chosen body at that revision, clear incompatible translations/active
  answers/features, and schedule current-revision enrichment/matching only for eligible demand. Provider
  audit rows remain audit rows; no stale cached result becomes active by virtue of the merge.
- Only after every foreign-key dependent has an explicit move/reset policy may the source row be
  deleted. Its queued jobs become successful no-ops. Insert aliases for every former canonical key,
  so subsequent ingests resolve to the survivor. Run the new-feed fan-out in §7 for moved associations.

Tests merge two articles with conflicting ratings, independent bookmarks/labels, feedback, aliases,
body/translation rows, immutable bookmark snapshots/Undo pins, selected-analysis requests, match
work and clusters while extraction is in flight; no reader data is lost
and a stale worker cannot recreate the deleted article or overwrite the survivor.

### 8.5 Durable bookmark capture (`article.capture-bookmark`)

A bookmark retains the **full available readable text and sanitized HTML**, not just `body_lead`,
the short excerpt, a URL or a fresh fetch on every read. `article_snapshots` and the per-user capture
reference/status are defined in spec 02. The frozen payload includes captured title/author,
publication time/source URL, source revision, capture time, extractor version, completeness/reason and SHA-256 of canonical
snapshot content. `user_article.bookmark_snapshot_id` is the ownership reference;
`bookmark_capture_generation`, `bookmark_capture_status` and `bookmark_capture_error_code` fence
and describe pending capture, while `bookmark_origin_feed_id` fixes the user's source-media policy.
It remains readable if the source changes or disappears, the feed is unsubscribed,
inference is off, or the current body is purged. External images/attachments are not mirrored under
this contract; Q14 decides that additional scope, and the UI must not promise a complete media mirror.

1. The authorized bookmark mutation locks the article and current reader row. In the same transaction
   it preserves any already available full body through
   `capture_bookmark_snapshot(articleId, originFeedId)` (spec02), binds the immutable snapshot to
   this user's bookmark, advances the capture generation and records local capture work if content
   is absent/partial. If a partial snapshot exists while a fetch is queued, retain that binding with
   status `pending`; set terminal `partial` only after the attempt cannot improve it. A browser cannot submit arbitrary shared snapshot
   content or attach another user's snapshot. Capture from trusted existing rows does not require a
   network request. A body awaiting this atomic snapshot step cannot be purged or overwritten first.
2. A repeated bookmark request is idempotent. Unbookmark increments the capture generation and
   releases that user's live snapshot reference; a `bookmark_snapshot_pins` row protects the exact
   old snapshot through the ten-minute Undo window (specs02/08). Rebookmark is a new generation. HTTP success means the
   bookmark is saved, not that a still-pending source fetch has succeeded. DTOs distinguish `pending`,
   `saved`, `partial` and `failed`, and include a bounded capture reason/time.
3. The worker gathers current pending bookmark generations for the article. Prefer an already
   captured matching full snapshot, then a current full body, before network fetch. If needed, run
   safe local extraction with the same robots/SSRF/timeout/size/politeness limits as §8.1; this path
   can capture an older retained article but never creates training or other model demand. Fetch
   once for coalesced requests. Only bounded transient retries are automatic; an explicit user retry
   can try again after a terminal blocked/missing/partial result.
4. Freeze the best available content before reporting a terminal capture outcome. A feed summary or
   paywall teaser remains a `partial` snapshot; a failed page fetch cannot replace an existing full
   snapshot. A complete result means the available readable extraction was retained without a known
   omission, not a claim to content behind a paywall. On no readable content keep the bookmark and
   metadata with `failed`, never fabricate saved body text.
5. In a short completion transaction lock the article then user rows, recheck bookmark existence and
   each captured generation and input article revision, insert/reuse the immutable snapshot and bind
   only still-pending matching generations/revision. If current source revision changed, preserve
   existing saved content and retry capture from current source rather than mislabeling stale input. No late worker can resurrect an unbookmark or change a newer successful capture.
   Once a full snapshot is bound, source revisions cannot silently replace it. A later successful
   retry of a partial capture may bind a new immutable snapshot, preserving other users' references.
6. Snapshot reads/exports are authorized through the requesting user's current bookmark reference,
   never merely an article's globally shared ID. Sanitize HTML again when rendering and load no
   external media automatically. A JSON attachment exports the exact stored full text + already
   sanitized HTML as inert data, checksum, provenance and partial/failure status; it does not execute
   HTML or silently rewrite the archived bytes. If a future rendered export re-sanitizes content,
   distinguish its derived-output checksum from the immutable stored checksum. Compression is transparent.

Cold storage and retention are spec 11 §5.2. Integration tests cover source deletion after bookmark,
100k+ character content (no old truncation), capture failure/teaser, unbookmark/rebookmark during
fetch, two users sharing one snapshot, source edits, body purge, merge conflict, unsubscribe, export
and a backup/restore round-trip. Full saved content and checksum must survive every permitted path.

---

## 9. Adaptive fetch interval (`nextSchedule`, pure)

**Intent:** fetch busy feeds often and quiet feeds rarely. Never let a daily feed drift to days
between fetches. Back off hard on errors.

**Inputs:** the feed row, the fetch outcome (`new_items`, `not_modified`, or an `error` with HTTP
status and `Retry-After`), `now`, and the feed hints (`ttl` in minutes, `sy:updatePeriod/Frequency`,
`Cache-Control: max-age`).

```
MIN   = feed.min_interval_s                       (≥ 300)
MAX   = 86_400 if last_new_item_at within 30 days or feed age < 30 days, else 864_000     (24 h / 10 days)

on success (a successfully parsed HTTP 200, or a valid conditional 304):
  consecutive_errors = 0; first_error_at = null; status = 'active'; quarantine_count = 0; quarantined_until = null; last_success_at = now
  if n_new > 0:
      factor   = n_new >= 5 ? 0.5 : n_new >= 2 ? 0.75 : 1.0
      interval = interval * factor
      consecutive_empty = 0; last_new_item_at = now
  else:
      consecutive_empty += 1; total_empty += 1
      interval = interval + max(300, round(interval * 0.2))
  hint     = min(MAX, max(ttl_s, sy_period_s, min(cache_max_age_s, 21_600)))   (missing hints count as 0)
  if publish_stats.recent_gaps_s has ≥ 5 entries and last_new_item_at within 7 days:
      G = median(recent_gaps_s)
      interval = min(interval, max(MIN, G / 2))          # a daily feed stays ≤ ~12 h
  interval = clamp(round_to_60(max(interval, hint)), MIN, MAX)
  next_fetch_at = now + max(MIN, hint, interval * (1 + jitter))          # jitter ∈ [-0.1, 0.1], deterministic from hash(feed.id, now_day)

on error:
  consecutive_errors += 1; total_errors += 1; first_error_at ??= now
  last_error_code/last_error/last_error_at = …
  if http_status == 410: status = 'dead'; stop (never overwrite dead with quarantined)
  backoff = min(MIN * 2^min(consecutive_errors - 1, 16), 86_400)
  if Retry-After present: backoff = max(backoff, min(retry_after_s, 86_400))
  if consecutive_errors >= 10:
      quarantine_count += 1
      status = 'quarantined'
      quarantined_until = now + min(2 days * 2^min(quarantine_count - 1, 3), 16 days)
      next_fetch_at = quarantined_until
  else:
      next_fetch_at = now + backoff
  if now - first_error_at >= 30 days: status = 'dead'

always: total_fetches += 1; last_fetch_at = now
on parsed 200: replace etag/last_modified with returned values, clearing absent ones
on valid 304: retain missing validators; update any returned ones; do not parse or ingest a body
```

`sy_period_s` is period duration divided by valid positive frequency; ignore invalid/negative TTL,
frequency and cache hints. Compute publication gaps from distinct valid dates, excluding zero/negative
gaps. A 304 without previously established validators is retried once unconditionally. A parse error
never installs its validators (otherwise a broken body can be hidden forever behind 304 responses).
A feed with no prior new-item timestamp uses the 24-hour MAX until it has actually been quiet 30 days.

**Permanent redirect of the feed URL** (301/308 on the feed fetch):
- If no other feed has the new canonical URL, update `feeds.url`.
- If another feed has it, **merge** into the surviving feed in one transaction (as `feedit_worker`):
  - lock feed IDs in ascending order and recheck the target; move subscriptions and feed items
  - create target subscriptions before repointing scoped cards, then remove source subscriptions
    only after the `(user_id, scope_feed_id)` foreign key has been moved; otherwise ON DELETE CASCADE
    would erase those cards
  - on duplicate subscriptions, keep target title/folder preferences, earliest created time,
    `hidden = source.hidden AND target.hidden`, `allow_duplicates = source OR target`; preserve
    user data rather than relying on arbitrary `ON CONFLICT DO NOTHING`
  - on duplicate inference settings use the more restrictive mode (`off`, then `training`, then
    `active`); if both remain active use the later activation timestamp. Advance `inference_version`
    beyond both prior versions. A source-only subscription retains its mode/activation boundary
    with a new version; merging cannot implicitly enable or backfill inference
  - move selected `analysis_requests` before deleting the source subscription, preserving completed
    training history; cancel old pending/running generations and rebuild automatic work only from
    current eligibility. An explicit fresh selection is required to reauthorize cancelled manual work
  - re-point `user_cards.scope_feed_id`, feed rule values and eval feed references; deduplicate
    identical rules and apply §7 demand-aware fan-out for newly moved article associations
  - remap `user_feed_preferences` and `bookmark_origin_feed_id`; conflicting image settings choose
    `block` over `allow` over `inherit`, preserving the strictest explicit privacy choice
  - set `old.merged_into_id = survivor.id`, mark old feed `dead`, clear its validators; redirects to
    old IDs resolve through this pointer, and old subscriptions are not recreated on retry
  - refresh feed subscribers/cards for both IDs and record a full rank for every affected subscriber
  - if a feed GUID collision points to different articles, retain both feed associations with only
    the established GUID mapping, and report the conflict rather than deleting an article

`dead` feeds without a merge target are shown with a banner (spec 09) and may be reset by an admin.
A merged tombstone cannot be revived independently; admin requests resolve to its survivor.

Unit tests cover every branch, including a simulated 60-day sequence for four archetypes: a busy news
site, a daily blog, a weekly podcast, and a feed that breaks and recovers.

---

## 10. Feed discovery (`discoverFeed`, used by `POST /subscriptions`)

1. Normalize the input. If there is no scheme, try `https://` and then `http://`.
2. `safeFetch` the URL. If the body sniffs as a feed (§6) → candidate `{url: finalUrl, title}`.
3. Else, if it is HTML:
   - collect `<link rel="alternate">` with type `application/rss+xml`, `application/atom+xml`,
     `application/feed+json` or `application/json` (resolved hrefs)
   - if there are none, probe up to 6 common paths on the same origin: `/feed`, `/rss`, `/rss.xml`,
     `/atom.xml`, `/feed.xml`, `/index.xml`
   - each probe must sniff as a feed
4. Result:
   - no candidate → `FEED_NOT_A_FEED`
   - one → subscribe
   - several → return them for the user to choose (spec 08 §4)
5. The chosen URL is canonicalized. An existing `feeds` row with that `url` is reused.
6. **First fetch:** discovery/validation has one shared 20-second request deadline (all candidates,
   probes and redirects), at most 10 total HTTP requests and 2 concurrent probes. Fetch and parse the
   chosen candidate to validate it; use that result for the title rather than downloading it a second
   time synchronously. Recheck the user's remaining quota inside the subscription transaction, then
   atomically insert the subscription, refresh materializations and record `feed.fetch` work. Item
   ingestion runs in the worker. HTTPS-to-HTTP fallback is allowed only for transport failure, never
   to bypass TLS certificate errors or address policy. Candidate count is capped at 20, deduplicated
   and every selected URL is revalidated; HTML `rel=alternate` alone is not proof of a valid feed.

---

## 11. OPML

**Import** (`parseOpml`, pure; `POST /subscriptions/import-opml`):
- Parse with `fast-xml-parser` under the same XML safety rules as §6; upload ≤ 1 MiB. Collect every
  `outline` with an `xmlUrl`, recursively. Reject an unsafe document before any database mutation.
- `folder` = the nearest ancestor outline's `text`/`title`.
- Cap additions at the **remaining** plan quota (spec 08 §6), under the per-user quota lock; duplicate
  subscriptions consume no quota. Canonicalize and validate scheme/credentials/port and IP literals
  without network access; the safe client validates DNS at first fetch. Report excess items as
  `quota_exceeded`, never silently truncate the report.
- Create or reuse the `feeds` rows (**no fetch**, `next_fetch_at = now()`) and the subscriptions.
- Call `refresh_feed_subscribers`/`refresh_feed_cards` once for all of them.
- Return a report `{added, existing, invalid: [{index, url, reason}]}`, where `index` is the outline's
  position in document order. XML parsers don't expose line numbers.
- Feeds that fail their first fetch show their error status in the feed list.

**Export:** OPML 2.0 with one `outline` per folder and `text`, `title`, `type="rss"`, `xmlUrl`,
`htmlUrl` per subscription (title override if set). Escape XML attributes, omit absent URLs and
export the canonical survivor for merged feeds, including paused/dead subscriptions; folder/title strings are text, never injected XML.

---

## 12. Fixtures (`packages/testing/fixtures/feeds/`)

At least:
- RSS 2.0, Atom, RSS 1.0 (RDF), JSON Feed 1.1
- a feed with CDATA-wrapped HTML
- windows-1250 (a Slovak site) and ISO-8859-2 encodings
- a BOM-prefixed feed
- a feed with unescaped `&`
- relative links and images
- `media:*` images
- future dates and missing dates
- a Google News RSS sample
- a huge feed (> 5 MB, for the size cap)
- a redirect chain fixture (served by a local test server)

HTML page fixtures:
- a normal article
- a paywall teaser
- an AMP page with `rel=canonical`
- a page declaring windows-1250 in `<meta>`
- a non-article (a list page)

The old FeedIt prototype's hard cases (it "parses even the most impossible feeds") belong here as they
are found.


## 13. Failure and concurrency acceptance cases (M1, extended in M2/M8)

- Two feeds concurrently insert the same canonical URL; only one article survives, with both feed
  associations and downstream work. Conflicting GUIDs and changed URL/unchanged GUID retain identity.
- Atom alternate/enclosure/xml:base/type=text, JSON Feed text/HTML/attachments, unknown dates,
  linkless IDs, UTF-16, malformed dates and long opaque GUIDs have explicit snapshots.
- HTTP/HTTPS, repeated path slashes and signed/reordered queries remain distinct absent evidence.
  Empty excerpts, `(untitled)` and generic logos never merge distinct articles.
- A title/excerpt edit while extract/translate/enrich is in flight cannot publish stale derivatives.
  Terminal extraction failure still ranks the item. Durable handoff crash points lose no work.
- Two worker processes plus API discovery share origin limits; 429 cooldown survives restart.
  Robots network error is not allow-all; compressed bombs and slow redirects hit the same deadline.
- A feed returns invalid XML with an ETag, then recovers; stale validators do not trap recovery. A
  410 stays dead, quarantine caps at 16 days, and jitter cannot fetch before MIN/server Retry-After.
- Backup/recovery checks in spec 11 resume the same pipeline without replaying deleted-user work.

Follow-up acceptance cases:
- An all-off feed with 200 fresh items, card additions, source edits and repeated recovery passes
  makes zero article-provider calls. Local read/extraction/bookmark operations still work.
- Training two specifically selected articles does not process their siblings. Enable-active uses
  the activation boundary; queued stale-generation work after opt-out cannot start provider calls.
- Two authorized users demanding the same article share one current provider result; one user's
  training/activation never activates another subscriber or exposes private demand/card text.
- Saved full content survives publisher 404, 30-day body maintenance and restored backups, with the
  same checksum; cold storage never converts it into lead-only text.
