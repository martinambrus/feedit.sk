# Spec 08: HTTP API (`apps/api`)

Status: **binding**. **Intent:** a small, typed JSON API that the PWA (and later other clients) uses.
Every per-user query runs under row-level security, and every limit that bounds cost is enforced here.

---

## 1. General

- **Base path:** `/api/v1`. JSON in and out (`application/json; charset=utf-8`), except the OPML
  upload/download and the export download.
- **Schemas:** all request and response bodies are zod schemas in `packages/shared/src/dto/` (one file
  per area), registered with `fastify-type-provider-zod`. OpenAPI JSON is served at
  `/api/v1/openapi.json` (admin session required in production).
- **Data formats:** IDs are strings. Timestamps are ISO 8601 UTC strings. Enumerations are lower-case
  strings exactly as in [spec 02](./02-data-model.md).
- **Errors:** `{ "error": { "code": "QUOTA_EXCEEDED", "message": "human text (en)", "details": {…} } }`
  with the HTTP status from the table below. The web client localizes by `code`.

  | Code | HTTP |
  |---|---|
  | `VALIDATION_FAILED` | 400 |
  | `INVALID_CODE` | 400 |
  | `UNAUTHENTICATED` | 401 |
  | `FORBIDDEN` | 403 |
  | `NOT_FOUND` | 404 |
  | `CONFLICT` | 409 |
  | `STALE_CURSOR` | 409 |
  | `STALE_STATE` | 409 |
  | `IDEMPOTENCY_CONFLICT` | 409 |
  | `QUOTA_EXCEEDED` | 409 |
  | `INVITE_REQUIRED` | 403 |
  | `RATE_LIMITED` | 429 |
  | `FEED_*` (spec 03 §4) | 422 |
  | `ENGINE_UNAVAILABLE` | 503 (only admin endpoints surface it) |
  | `INTERNAL` | 500 |

- **Pagination:** cursor-based. `?cursor=<opaque>&limit=<1..100>` (default 30). Paginated responses
  have `{items, nextCursor: string | null}`. Cursors contain a version, complete sort-key tuple
  (including null ordering and final id), normalized filter hash, user id and expiry. They are
  base64url encoded and HMAC authenticated; reject a cursor for another user/query or an invalid
  signature with `VALIDATION_FAILED`. A cursor is never an authorization grant. Article-list
  consistency across reranking is defined in §5.1. Quota-bounded lists explicitly shown as arrays
  remain arrays; admin users/feeds/invites/waitlist and library searches use the paginated envelope.
- **Tenancy:**
  - The `tenant` plugin exposes `req.withTx(fn)`. It opens a transaction **lazily**, on first use, runs
    `set_config('app.user_id', …, true)`, and hands `fn` a `TenantTx`. Route handlers must use it for
    per-user tables.
  - Slow outbound work (feed discovery, OPML validation, translation of card text) runs **before** the
    transaction is opened, so no pooled connection is held during network I/O.
  - Persist state changes and typed job intents in `job_outbox` in the **same transaction**; the
    relay publishes through `packages/shared/src/jobs.ts` (specs 02/03). No acknowledged mutation may
    depend on an in-memory callback that could be lost after commit.
- **Authorization:** all routes require an active, unrevoked session and a non-deleted user except
  request-code, verify, waitlist, healthz and readyz (and the separately protected metrics/ops routes).
  Admin roles are read from the current DB row on every request. Per-user RLS does not protect shared
  article/feed ids or auth/control-plane tables by itself: repositories must additionally constrain
  every detail, action, bulk member, example and rule reference to the authenticated user's owned
  subscription/bookmark/card/label. Foreign or inaccessible ids return `404` without revealing
  existence; mixed-access bulk requests fail atomically. UUID/bigint strings are validated losslessly.
- **CSRF:**
  - Every non-GET request must carry the header `X-FeedIt-Client: web`. Browsers cannot send a custom
    header cross-site without a CORS preflight, and preflights are refused.
  - If an `Origin` header is present, it must equal `new URL(PUBLIC_BASE_URL).origin`; `null` and
    malformed origins fail. Reject `Sec-Fetch-Site: cross-site`. GET/HEAD/OPTIONS never mutate user
    state except bounded session activity and the documented asynchronous rank-refresh intent.
  - **Exempt:** requests authenticated with the `METRICS_TOKEN` bearer (`POST /admin/ops-event`,
    `GET /metrics`). These come from host scripts, not browsers, and carry no cookie.
- **CORS:** disabled. The web app is same-origin through Caddy.
- **Validation and privacy:** reject unknown request keys, invalid enum/range values and unbounded
  nested JSON. JSON bodies are limited to 1 MiB, multipart OPML to its separate cap. Authenticated
  responses use `Cache-Control: private, no-store`; the explicit per-user offline projection in
  spec 09 is the only persistent client copy. Never log codes, cookies, authorization headers, invite
  tokens, raw emails, or article/private-card bodies. Return a request id with generic 500 messages.

### 1.1 Atomic mutations, idempotency and concurrency

- Every authenticated data mutation carries `Idempotency-Key: <UUID>`. A client retries the same
  logical operation with the same key; a new intentional operation gets a new key. In the state
  transaction, reserve the `(user_id, key)` in `api_mutations` (spec 02), bind it to method, canonical
  route and canonical body hash, and save the response, feedback and outbox effects before commit.
  Concurrent duplicates serialize and return the saved status/body without another event/job; a
  reused key with a different request returns `409 IDEMPOTENCY_CONFLICT`. No partial receipt remains
  after rollback. Receipts are retained for 7 days; offline replay stops after 24 hours (spec 09).
  Authentication is checked before receipt replay. Auth login/logout and bearer ops events use their
  own flow and are exempt. External discovery/translation may precede the transaction, but quotas and
  ownership must be checked again under lock before persisting anything.
- Serialize quota-affecting writes on the caller's `users` row: count, insert/re-point, counter update
  and outbox intent are one transaction. Existing subscriptions/holdings are idempotent no-ops, not
  additional quota usage. Lock referenced rows in deterministic id order; use bounded retries for
  deadlock/serialization errors, retaining the same idempotency key.
- Reader writes use `user_article.state_version` (a decimal string in JSON). Clients submit the
  expected version for each target; zero denotes an absent row. Update reader state and increment
  the version atomically, or return `409 STALE_STATE` with the current readable item. Ranking-only
  writes never increment this version. Return the committed item(s), version(s) and `mutationId`.
  Bulk reader writes are all-or-nothing, including version checks. A stale offline write is surfaced
  for review; it must never silently overwrite a newer action from another device.
- Preference PATCHes merge only supplied leaves under the user-row lock; arrays are replaced, not
  concatenated. Other PATCHes update only supplied columns. Missing means unchanged; `null` clears
  only explicitly nullable fields. Empty PATCHes and unauthorized nested ids fail validation.

---

## 2. Authentication, signup and invites

### 2.1 Flow

| Endpoint | Body | Behaviour |
|---|---|---|
| `POST /auth/request-code` | `{email, inviteCode?, locale?}` | **Always** `202 {next: 'check_email'}` (no user enumeration). The email content decides what happens next (below) |
| `POST /auth/verify` | `{email, code}` | On success: create the user if this is a signup, create a session, set the cookie → `200 {user: Me}`. Otherwise `400 INVALID_CODE` |
| `POST /auth/logout` | — | Revoke the current session, clear the cookie → `204` |
| `GET /auth/sessions` | — | The user's active sessions `[{id, userAgent, ip, createdAt, lastSeenAt, current}]` |
| `DELETE /auth/sessions/:id` | — | Revoke one session → `204` |

**`request-code` decision table:**

| Situation | Email sent |
|---|---|
| a `users` row exists (active, or soft-deleted and not yet purged) | login code (verifying restores a soft-deleted account) |
| unknown email listed in `ADMIN_EMAILS`, mode ≠ `closed` | signup code (**admin bootstrap**: the first admin needs no invite) |
| unknown email, `SIGNUP_MODE=open` | signup code |
| unknown email, `SIGNUP_MODE=invite`, valid invite (unused, unexpired, email-bound invites must match) | signup code (invite remembered on the code row) |
| unknown email, `SIGNUP_MODE=invite`, no or invalid invite | "FeedIt is invite-only" email with a waitlist link |
| `SIGNUP_MODE=closed`, unknown email | nothing |

**Codes:**
- Trim and case-normalize emails consistently with `citext`; validate syntax/length without
  provider-specific dot/plus rewriting. Requested locales are only `en`/`sk`, with fallback `en`.
- 6 digits from a CSPRNG, stored as `HMAC-SHA256(SESSION_PEPPER, challenge_nonce || email || code)`
  using an unambiguous canonical encoding, TTL **10 minutes**, at most
  **5** verify attempts per code.
  `challenge_nonce` is a fresh UUID generated before insert, not the database identity id.
- The requested `locale` and the `inviteCode` are stored on the `login_codes` row.
- A new request invalidates older unconsumed codes for the email.
- Serialize requests/verifications for a normalized email (transaction advisory lock plus challenge
  row lock). Compare digests in constant time; atomically consume the code, create/restore the user,
  consume any invite and create the session. Concurrent correct verifications cannot create two
  sessions from one code. Failed-attempt increments must commit even when the API returns
  `INVALID_CODE`; do not throw an exception that rolls back the attempt counter.
- Recheck invite expiry, unused state and email binding under lock at **verification**, and recheck
  signup eligibility then; a code is not a reserved invite. Use the same generic error for expired,
  consumed, incorrect or ineligible codes. A login code created for an existing user must not become
  a signup authorization if that user was purged in the meantime.
- Emails come from localized templates (en/sk) in `packages/shared/src/mail/templates/` (shared with
  the worker's alert emails), with a plain-text and an HTML part.
- Public responses disclose neither account existence nor SMTP outcome. After committing the code,
  attempt synchronous SMTP delivery with a bounded timeout and keep plaintext only in request
  memory. This is the explicit auth-mail exception to durable jobs: if the process/SMTP fails, the
  user requests a new code; do not imply guaranteed delivery or persist plaintext in the outbox.
  Bound response timing independently of account existence. A per-email throttled request retains the same 202;
  IP abuse can return 429 without an account-existence signal.

**The signup mode** is `settings['signup_mode']` if set, otherwise `SIGNUP_MODE` (spec 02 §2).

**On signup:**
- insert `users`:
  - UUID v7
  - locale from `login_codes.locale`, else `Accept-Language`
  - `invites_left` from the plan
- mark the invite used with a conditional `used_at IS NULL AND expires_at > now()` update
- if the email is on the waitlist, set `waitlist.invited_at`

**On every successful verify:**
- set `role = 'admin'` if the email is in `ADMIN_EMAILS`, so the list can be extended later
- only when restoring within the 7-day window: clear `deleted_at`, call
  `refresh_feed_subscribers`/`refresh_feed_cards`, advance `rank_revision` and enqueue
  `user.rank {full: true}`. Ordinary logins need only normal freshness catch-up. A delayed purge must
  not extend the restore window; verify and purge lock the user row to resolve their race.
- update `last_active_at`

A soft-deleted account keeps its `users` row until `house.purge-users` removes it after 7 days, so an
email never hits the unique constraint through the signup path.

**Session cookie:** `fi_sid` = 32 random bytes, base64url, stored hashed.
- Attributes: `HttpOnly`, `Secure` (production), `SameSite=Lax`, `Path=/`, `Max-Age = SESSION_TTL_DAYS`.
- Sliding: `sessions.last_seen_at`, `sessions.expires_at` **and `users.last_active_at`** are refreshed
  at most every 5 minutes, with a matching refreshed cookie Max-Age. "Active in the last N days"
  everywhere means `users.last_active_at`. Check expiration/revocation on every request, reject
  deleted users, and clear cookies with the same Path/attributes on logout. Session ids exposed by
  `/auth/sessions` are database ids, never token hashes or tokens. Do not bind sessions rigidly to IP.
  Admin edits cannot revoke an `ADMIN_EMAILS` bootstrap role permanently: the UI must explain that
  the deployment allowlist must also be changed before demotion can persist.

### 2.2 Invites and waitlist

| Endpoint | Body | Behaviour |
|---|---|---|
| `GET /invites` | — | My invites `[{code, email, createdAt, expiresAt, usedAt, url}]` and `invitesLeft` |
| `POST /invites` | `{email?, note?}` | Atomically requires/decrements `invites_left > 0` under the user lock. Creates a CSPRNG code (10 chars, Crockford base32, expires in 30 days; retry unique collisions), and queues email if `email` is given → `201 {code, url: PUBLIC_BASE_URL + '/join?code=' + code}` |
| `POST /waitlist` | `{email, locale?, note?}` | Public. Upsert → `202`. Rate-limited per IP |

---

## 3. Me and preferences

| Endpoint | Body | Behaviour |
|---|---|---|
| `GET /me` | — | `Me` = `{id, email, displayName, locale, timezone, role, plan, invitesLeft, preferences, quotas: {used, limits}}` |
| `PATCH /me` | `{displayName?, locale?, timezone?, preferences?}` | Partial update. `preferences` is deep-merged and validated (§3.1), timezone must be an accepted IANA identifier. Ranking-relevant changes advance `rank_revision` and enqueue `user.rank {full:true}` (spec 06) |
| `DELETE /me` | — | Sets `deleted_at`, revokes all sessions, and calls `refresh_feed_subscribers` and `refresh_feed_cards` for the user's feeds (soft-deleted users no longer count) → `204`. `house.purge-users` hard-deletes after 7 days |
| `GET /me/export` | — | Stream a consistent read-only snapshot as `application/json` attachment: `{schemaVersion: 1, exportedAt, user, subscriptions, opml, cards, labels, rules, ratings: [{url, title, rating, reason, ratedAt}], bookmarks: [{url, title, bookmarkedAt}]}`. Exclude session/code/token hashes and every other user's data; cancel on disconnect and rate-limit large exports |

### 3.1 `UserPreferences` (stored in `users.preferences`; zod defaults applied on read)

```ts
{
  defaultTier: 1 | 2 | 3 | 4 | 5,              // default 1
  hideEverything: boolean,                      // default false: collapse the "Everything else" lane
  simpleMode: boolean,                          // default false (FeedIt Simple Mode)
  sort: 'score' | 'date',                       // default 'score'
  markReadOnExpand: boolean,                    // default true
  markReadOnRate: boolean,                      // default true
  feedbackPrompt: 'often' | 'occasionally' | 'never',   // default 'occasionally'
  demote: { clickbait: Tri, promotional: Tri, shallow: Tri, stale: Tri },  // Tri = 'auto'|'on'|'off', default 'auto'
  implicitNegative: boolean,                    // default false
  swipe: { left: 'dislike' | 'read' | 'none', right: 'like' | 'bookmark' | 'none' },   // default dislike / like
  theme: 'system' | 'light' | 'dark',          // default 'system'
  loadRemoteImages: boolean,                    // default false: publisher images/icons require opt-in
  folderOrder: string[],                        // default []: folder names in sidebar order
  onboardingCompletedAt: string | null,         // ISO; default null → the web app shows onboarding
}
```

---

## 4. Subscriptions and feeds

| Endpoint | Body / query | Behaviour |
|---|---|---|
| `GET /subscriptions` | — | `[{feed: FeedInfo, titleOverride, folder, allowDuplicates, hidden, unread: {forYou, maybe, everything, new}}]` |
| `POST /subscriptions` | `{url, folder?}` | Discovery (spec 03 §10). One feed → `201 {subscription}`, plus a backfill of the user's cards for that feed **and** `user.rank {full: true}` (answers may already exist, so ranking alone makes the feed appear). Several → `200 {status: 'choose', candidates: [{url, title, type}]}` (the client posts again with the chosen URL). Errors `FEED_*` (422). Quota `maxFeeds` |
| `PATCH /subscriptions/:feedId` | `{titleOverride?, folder?, allowDuplicates?, hidden?}` | Update |
| `DELETE /subscriptions/:feedId` | — | Delete, then `refresh_feed_subscribers`, `refresh_feed_cards`, `user.rank {full}` |
| `POST /subscriptions/:feedId/mark-read` | `{olderThan, datasetVersion}` | Same operation as `/articles/mark-read` with `filter.feedId` fixed by the path; same scope, cap, receipt and undo contract |
| `POST /subscriptions/import-opml` | multipart `file` (≤ 1 MB) | spec 03 §11. `200 {added, existing, invalid: [...]}`. Enqueues one backfill for the new feeds and `user.rank {full: true}`. Quota `opmlMaxFeeds` and `maxFeeds` |
| `GET /subscriptions/export-opml` | — | `text/x-opml` attachment |
| `POST /subscriptions/folders/rename` | `{from, to}` | Rename a folder across the user's subscriptions and in `preferences.folderOrder` → `200 {count}` |

`FeedInfo = {id, url, siteUrl, title, iconUrl, status, lastSuccessAt, lastErrorCode, lastErrorAt}`.
`PATCH` returns `200 {subscription}` and delete returns `204`. Existing subscriptions return the
current object without changing the user's folder/preferences implicitly. Hidden means excluded
from aggregate reader/sidebar selection; it does not unsubscribe, stop shared fetches or disable
direct feed view. Subscription changes call both refresh functions in the state transaction and
invalidate affected ranking; folder/duplicate visibility changes also invalidate list cursors.
Errors exposed to users are sanitized error codes, never upstream credentials or response bodies.

---

## 5. Reading and feedback

### 5.1 `GET /articles`

**Query:**
- `lane` ∈ `for_you | maybe | everything | new | all | bookmarks | hidden` (default `for_you`)
- `feedId?`, `folder?`, `labelId?`
- `status` ∈ `unread | all` (default `unread`; default `all` for bookmarks/hidden)
- `minTier?` (1–5, default `prefs.defaultTier`; applies to `for_you` and `maybe`)
- `sort` ∈ `score | date` (default: `prefs.sort` for `for_you`; uncertainty order for `maybe`, spec 06 §10;
  `date` otherwise)
- `cursor`, `limit`

**Semantics, in this order:**
1. **Candidate set:** distinct articles carried by the user's subscriptions (excluding `hidden`
   feeds unless `feedId` is given) with `first_seen_at ≥ asOf − 14 days AND first_seen_at ≤ asOf`.
   Apply `feedId`/`folder`/`labelId` scope **before folding**, using EXISTS predicates rather than
   fan-out joins. A feed/folder intersection with no owned subscription is empty. For
   `lane = bookmarks`: every personally bookmarked article, without window/subscription requirement;
   only owned labels and accessible feed metadata are exposed. Explicit bookmarks are not suppressed
   by ranking `hidden` or `archived_at` and are not folded together: each saved article is retrievable.
   `lane=hidden` is an explicit recovery view for window articles with `ua.lane='hidden'` **or**
   `archived_at IS NOT NULL`; it includes subscribed hidden feeds, does not fold and ignores minTier.
   Other scopes/access checks still apply. It is never included implicitly by `lane=all`.
2. **Cluster folding**, applied to the scoped candidate set before lane/status filtering:
   - An article is foldable if **any** subscription carrying it has `allow_duplicates = false`.
   - Foldable articles sharing a `story_cluster_id` collapse into one row: the member with the highest
     `p_like`, then the earliest `first_seen_at`, computed with
     `row_number() OVER (PARTITION BY story_cluster_id ORDER BY p_like DESC NULLS LAST,
     first_seen_at ASC, id ASC) = 1`. Hidden/archived articles are excluded before choosing a
     representative (except in bookmarks/hidden), so a hidden sibling cannot suppress visible stories.
   - That row reports `cluster.size` and the other **accessible** members' feed titles. Hidden
     subscriptions and unrelated subscribers' private feed names are never returned.
   - Non-foldable and unclustered articles are their own rows.
3. **Filters:**
   - lane = `coalesce(ua.lane, 'new')`, where `all` means every lane except `hidden`; `hidden` is
     excluded outside the explicit bookmarks/hidden views
   - `status = unread` means `ua.read_at IS NULL AND ua.archived_at IS NULL` in ordinary lanes;
     bookmark/hidden views filter only `read_at` when explicitly requesting unread
   - `minTier` on `for_you`/`maybe` only (also when those lanes occur in `all`)
4. Sort, then paginate.

`GET /articles/counts` accepts the same scope/status/minTier and `asOf` inputs (no cursor/sort).
It uses the same query builder and per-lane predicates as the list, so counts equal list totals
for the same dataset version. `bookmarks` is computed separately using bookmark-view semantics.
Folder/feed counts are scoped counts, not sums of globally folded lane counts.

**Outdated scores:** if any eligible row has a missing/stale `score_version` or `rank_revision`
(spec 06 §7), request a debounced full rank via the outbox. Taking only the newest version would
miss partially updated batches. Serve existing scores immediately and return `rankingPending`.
- **Sort keys:**
  - score: `(p_like DESC NULLS LAST, first_seen_at DESC, id DESC)`
  - maybe: `(abs(p_like − 0.5) ASC, first_seen_at DESC, id DESC)`
  - date: `(coalesce(published_at, first_seen_at) DESC, id DESC)`

**Pagination under change:** the first page fixes `asOf` and returns `datasetVersion`, a digest of
the ordered eligible/folded ids and all mutable filter/sort fields, plus relevant user rank/settings
revisions. Evaluate the page and digest in one consistent read transaction. The signed cursor
contains these values. If the same query's digest differs on the next page, return
`409 STALE_CURSOR`; the client refreshes from page one and de-duplicates ids. This explicitly handles
reranking, cluster changes, read/label changes and unsubscribe without silent skips or repeats.
Do not promise an immutable reading snapshot while users are taking actions. Cap cursor validity
at 15 minutes; new articles after `asOf` appear on refresh.

**Response:** `{ items: ArticleListItem[], nextCursor, asOf, datasetVersion, rankingPending }`, where

```ts
ArticleListItem = { id, title, url /* string | null: linkless feeds are valid */,
  feed: {id, title, iconUrl} | null, author, publishedAt, firstSeenAt,
  excerpt /* ≤ 300 chars */, imageUrl, lang, lane, tier, pLike, topReason: TopReason | null,
  labelIds, labelSuggestions, rating, reason, readAt, bookmarkedAt, archivedAt,
  stateVersion /* decimal string, '0' when no user_article row */,
  contentRevision /* articles.content_revision, decimal string */,
  translationAvailable: boolean,
  cluster: { id, size, otherFeeds: string[] } | null }

TopReason =                                        // structured; the web client localizes it
  | { kind: 'card'; cardId: string; title: string; p: number }
  | { kind: 'rule'; code: string; ruleId?: string }
  | { kind: 'model'; feature: string; label: string }
  | { kind: 'keyword' }                            // degraded (BM25)
```

Select the display feed from the user's in-scope subscriptions deterministically (requested feed
first, otherwise smallest feed id), applying that user's title override. A bookmarked article with
no remaining source relation has `feed:null`; the UI shows "Saved article" rather than linking to
an inaccessible subscription. Optional metadata and `pLike`/`tier` for unscored items are explicitly
nullable in the DTO; clients must not coerce null probabilities to zero.

`topReason` is derived from `explain`:
- a fired floor or cap rule → `rule`
- otherwise, source `cards` → the deciding card (`explain.decidingCardId`)
- otherwise, source `model` → its top contribution
- otherwise, source `degraded` → `keyword`

`GET /articles/counts` → `{forYou, maybe, everything, new, bookmarks, hidden, scored, total, asOf,
datasetVersion, rankingPending}` using the requested status (default unread; bookmark badge counts
all saved items). `total = forYou + maybe + everything + new`; `scored = forYou + maybe + everything`.
These are visible reader counts, not engine-job progress: filtering, deduplication and actions can
change them. Onboarding must say "38 scored of 120 available", not claim that 38 jobs completed.
The opt-in `hidden` count is separate and excluded from `total`/`scored`, as bookmarks are.

`GET /articles/calibration` → `{ items: ArticleListItem[] }`: up to 10 unrated articles for the
calibration round (selection rules in spec 06 §10). Used by onboarding and the weekly "Tune your feed"
card.

### 5.2 `GET /articles/:id`

Returns `ArticleListItem` plus:
- `excerptHtml`
- `bodyLead` (when available)
- `explain`
- `translation: {title, excerpt, engine, quality} | null`
- `clusterMembers: [{id, title, feedTitle, url}]`

`404` unless the article is carried by one of the user's subscriptions **or** the user has bookmarked
it. Bookmarks of unsubscribed feeds stay readable.
The same access predicate applies to every action/example, including articles outside the 14-day
list window. `clusterMembers` includes only members independently accessible to this user; do not
leak global cluster membership. `excerptHtml` is sanitized server-side (spec 03); plain text and
translations are escaped by the client. Detail GET has no read/open side effect.

### 5.3 Actions (all `POST` unless a method is shown)

Single-article bodies additionally require `stateVersion` and `contentRevision` from the displayed
item; DELETE actions carry them as query parameters. A changed article revision returns `STALE_STATE`
rather than assigning feedback to content the reader did not see. Bulk target objects carry both
versions too. All use §1.1 and return `200 {item: ArticleListItem, mutationId}` unless noted.

| Endpoint | Body | Effect |
|---|---|---|
| `/articles/:id/read` | `{}` | Set `read_at` (expand in the list, when `markReadOnExpand`) |
| `/articles/:id/unread` | `{}` | Clear `read_at` and `archived_at`, record `unread`; invalidate cluster seen-story ranking where needed |
| `/articles/:id/unhide` | `{}` | Clear only `archived_at`, record `unhide`, preserve read/rating state and rerank. A Never card or block/mute rule may still hide it; expose that explanation rather than silently deleting the user's rule |
| `/articles/:id/open` | `{}` | Require a non-null safe original URL; otherwise `400 VALIDATION_FAILED`. Set `opened_at` and `read_at`, and record a `feedback_events` `open` (the user opened the original URL) |
| `/articles/:id/dwell` | `{ms}` | Integer 0..1,800,000; require a prior open, clamp to elapsed time since it. Set `dwell_ms = max(existing, ms)` and record `dwell`. Includes `prompt: boolean` from spec 06 §10; atomically set `feedback_prompted_at` when true so two tabs cannot prompt twice. Dwell is only a weak signal, never proof of reading |
| `/articles/:id/rating` | `{rating: 1 \| -1 \| null, reason?, hide?: boolean}` | Set explicit state, never toggle server-side. Reason enum is `off_topic\|clickbait\|seen\|shallow\|promo\|other`, allowed only for -1; null/+1 clears it. Null also clears `rated_at` and leaves read/archive state alone; only non-null ratings mark read if `markReadOnRate`. `hide:true` sets `archived_at`; false/absent does not clear it. Records `rate`/`unrate`, applies spec 06 §8.4 |
| `/articles/:id/prompt-answer` | `{liked: boolean}` | Store as a rating (`rating = liked ? 1 : -1`, `rated_at = now`), record `prompt_answer`, and apply the learn trigger |
| `/articles/:id/bookmark` / `DELETE` of the same path | `{}` | Set or clear `bookmarked_at`, and record the event |
| `/articles/:id/labels` | `{labelId}` | Add to `label_ids`, remove from `label_suggestions`, record `label`. **Cards are not changed** (spec 05 §5.1). Label examples are explicit (§7) |
| `DELETE /articles/:id/labels/:labelId` | — | Remove it, record `unlabel` |
| `/articles/:id/mute-story` | `{days: 1\|3\|7\|30}` | Create a cluster for the article if it has none, create a `mute_story` rule with `expires_at`, enqueue `user.rank {full}` → `201 {rule}` |
| `/articles/mark-read` | `{targets: [{id, stateVersion, contentRevision}]}` **or** `{filter: {lane, feedId?, folder?, labelId?, minTier?, olderThan}, datasetVersion}` | Explicit targets ≤500; filter uses §5.1 query semantics and an inclusive `first_seen_at` cutoff captured when confirming. Materialize/lock the displayed representatives once; no later arrivals. Maximum 5,000 targets; reject an oversized set rather than silently truncate. A changed dataset returns `STALE_STATE`. → `200 {count, mutationId}` |
| `/articles/rate-bulk` | `{targets: [{id, stateVersion, contentRevision}][1..200], rating: 1 \| -1 \| null}` | One transaction using single-rating semantics, per-item feedback snapshots and one coalesced learn/rank intent → `200 {count, mutationId, items}`. Explicit un-rate is supported; exact undo uses the endpoint below |
| `/articles/undo` | `{mutationId}` | Restore the original mutation's captured reader fields (§5.4) → `200 {count, mutationId, items}` |

**Row creation:** `user_article` rows are created on first action (upsert). All feedback writes also
append `feedback_events` with the event-time feature/provenance snapshot required by spec 06 §8;
retry replays append nothing. Do not let API upserts replace worker-owned ranking columns. Lock
ownership and expected-version checks in the same transaction. Label assignment/removal requires a
label held by the user; deleted/repointed labels must never be resurrected by an offline replay.
Rank-relevant mutations advance the user's `rank_revision` and enqueue appropriate rank/learn
effects per spec 06. Cluster actions never mark every sibling read implicitly; the seen-story rule
and folding provide deduplication.

### 5.4 Exact undo

The mutation receipt stores the target ids, affected reader fields' **before** values and resulting
state versions for read/unread/unhide/rating/bookmark/label and bulk actions. Undo is accepted for 10 minutes
after commit only if the receipt belongs to the user, has not already been undone and every target
still has the receipt's resulting state version. It restores only fields changed by that action,
increments state versions, records an `undo` event referencing the original mutation and requests
the same rank/learn invalidation. It never writes old ranking-cache fields or fabricates a rating.
An intervening device action/deleted label/inaccessible article returns `409 STALE_STATE` atomically;
the client refreshes and explains that newer changes were kept. This restores pre-existing ratings,
reasons, read state and SHIFT-hide correctly. Undo of a bulk mutation is one atomic operation, not
hundreds of independent requests. Receipts themselves are private, excluded from ordinary exports,
purged after their retention and inaccessible to other users.

---

## 6. Plans and quotas

`packages/shared/src/plans.ts` is the single source:

| Limit | `beta` | `admin` |
|---|---|---|
| `maxFeeds` | 200 | 2,000 |
| `maxCards` (interest cards incl. never) | 50 | 500 |
| `maxLabels` | 20 | 200 |
| `maxForks` (private cards) | 20 | 200 |
| `maxRules` | 200 | 2,000 |
| `opmlMaxFeeds` (per import) | 300 | 2,000 |
| `minFetchIntervalS` | 900 | 300 |
| `backfillDays` | 7 | 14 |
| `invitesOnSignup` | 3 | 50 |

- Exceeding a limit → `409 QUOTA_EXCEEDED {limit, used, max}`.
- `feeds.min_interval_s` = the minimum of `minFetchIntervalS` over the feed's subscribers. It is
  recomputed on subscribe/unsubscribe, plan changes, account deletion/restoration and purge; deleted
  users do not contribute. Reducing a plan does not silently delete data: existing over-quota rows
  remain readable/deletable, while additions exceeding the new limit are blocked.

---

## 7. Cards, library, suggestions, labels

```ts
Card = { id, kind: 'interest', title /* title_override ?? card.title */, interest, notFor, strength,
         scopeFeedId, origin, isPrivateFork, examplesYes, examplesNo, topicIds, lang, createdAt }
Label = { id /* card id */, name, color, definition, notFor, examplesYes, examplesNo, count /* articles labelled */ }
```

Library cards are localized to the user's locale from `i18n`. Cards are **immutable** (spec 05 §5.1),
so every endpoint that changes text or examples returns the card with its possibly **new id**. The web
client must replace its cached id.

| Endpoint | Body | Behaviour (spec 05 §5.1) |
|---|---|---|
| `GET /cards` | — | The user's interest cards |
| `POST /cards` | `{title?, interest, notFor?, strength, scopeFeedId?}` | Create or reuse → `201 {card}`. Quota `maxCards` |
| `PATCH /cards/:id` | `{title?, interest?, notFor?, strength?, scopeFeedId? \| null}` | `title` only sets the user's override. `interest`/`notFor` re-point to another card (new id) |
| `DELETE /cards/:id` | — | Remove from the user |
| `POST /cards/:id/examples` | `{articleId, side: 'yes' \| 'no'}` | Add the article title as an example (private fork, new id) → `{card}`. Quota `maxForks` |
| `POST /cards/:id/examples/remove` | `{side, text}` | Remove an example (new fork id) → `{card}` |
| `POST /cards/from-article` | `{articleId, interest, notFor?, title?, strength}` | Shared text card plus a private fork with the article title as `examples_yes` → `201 {card}`. Quotas `maxCards`, `maxForks` |
| `GET /library` | `?topic=&q=` | Public cards (`visibility = 'public'`), localized, grouped by L1 topic |
| `POST /library/:id/adopt` | `{strength}` | Hold a library card |
| `GET /cards/suggestions` | — | `[{card, score}]` (not dismissed) |
| `POST /cards/suggestions/:cardId/dismiss` | — | `204` |
| `GET /labels` | — | `Label[]` |
| `POST /labels` | `{name, definition, notFor?, color?}` | Create or reuse a label card (kind `label`; the hash includes the name) plus `user_labels` → `201 {label}`. Quota `maxLabels` |
| `PATCH /labels/:id` | `{name?, definition?, notFor?, color?}` | `color` changes in place. Name or definition re-points to a new card id, and `label_ids`/`label_suggestions` are migrated with `array_replace` |
| `POST /labels/:id/examples` | `{articleId, side: 'yes' \| 'no'}` | Add a label example (private label fork, new id, ids migrated). Not counted in `maxForks` |
| `POST /labels/:id/examples/remove` | `{side, text}` | Remove one (new id, migrated) |
| `DELETE /labels/:id` | — | Remove the label, and remove its id from the user's `label_ids`/`label_suggestions` |

`GET /topics` → the taxonomy (id, parent, names, level) for the web client.
PATCH/adopt/example routes return `200 {card}` or `200 {label}` with the final id; deletes return
`204`. `notFor:null` clears the field; `scopeFeedId:null` means all feeds. Fork/re-point transactions
return `idChange: {from, to} | null` so queued client references can be reconciled. A stale old id
not currently held by the user returns `404`; it must not create another implicit holding. Quotas
count resulting distinct holdings/forks, not historical retired rows. Label colour is a validated
hex value, never arbitrary CSS; text/example length and count limits come from spec 05.

---

## 8. Rules

| Endpoint | Body | Behaviour |
|---|---|---|
| `GET /rules` | — | `[{id, kind, value, displayValue, createdAt, expiresAt}]` |
| `POST /rules` | `{kind, value, expiresInDays?}` | Validate `value` per kind (feed id owned by a subscription, domain syntax, keyword 2–100 chars). Quota `maxRules`. Enqueue `user.rank {full}` → `201` |
| `DELETE /rules/:id` | — | Delete, rank full → `204` |

---

## 9. Admin (`role = 'admin'`; prefix `/admin`)

| Endpoint | Purpose |
|---|---|
| `GET /admin/overview` | users (total, active 7 d), feeds by status, articles ingested today, pipeline backlog per queue, engine status (breakers, spend today vs budget, LLM calls today), translation stats |
| `GET /admin/usage?days=30` | platform $/day by engine and kind (from `usage_daily`), and the top 20 users by attributed cost via `admin_usage_attribution(days)` (spec 02 §6, spec 04 §7) |
| `GET /admin/settings` / `PATCH /admin/settings` | Allow-listed keys only (spec 02 §2 registry): `engine.daily_budget_usd`, `engine.llm_daily_cap`, `language_modes`, `card_text_mode`, `ranker.thresholds`, `translate.tier2_daily_cap`, `question_sets.active`, `signup_mode`. Each key is validated by its zod schema. **Side effects:** `ranker.thresholds` bumps `ranker.settings_version` and enqueues `user.rank {full}` for users active in the last 7 days; `question_sets.active.enrich` enqueues `house.reenrich`; `card_text_mode = 'english'` enqueues `house.translate-cards`; `language_modes` applies to new articles only |
| `POST /admin/engine/reset-breaker` | `{engine}`: writes `engine.circuit.resetRequested[engine] = now`. Worker routers close that breaker (including auth mode) within 10 s (spec 04 §5) |
| `GET /admin/feeds?status=&q=` / `PATCH /admin/feeds/:id` / `POST /admin/feeds/:id/reset` | Feed health; edit `fetch_options`; clear quarantine/dead |
| `GET /admin/library/candidates?minHolders=3` | Promotion candidates: all non-retired `shared` interest cards, with holder counts from `admin_card_holders` (spec 02 §6), filtered to `holders ≥ minHolders`, sorted by holders descending, at most 100 |
| `GET /admin/library` / `POST /admin/library` / `PATCH /admin/library/:id` / `POST /admin/library/promote` | Manage library cards (`PATCH` edits only `title`, `topic_ids`, `i18n`; texts are immutable). `promote {cardId, title, titleSk, topicIds}` requires a `shared` card with ≥ 3 holders (`admin_card_holders`) and **updates it in place** to `visibility = 'public'` with that title, i18n and topics. The text and id don't change, so holders and answers stay valid |
| `GET /admin/users?q=` / `PATCH /admin/users/:id` | Role, plan, `invites_left` |
| `GET /admin/invites?status=unused\|used\|expired` / `POST /admin/invites` | List all invites; create `{count ≤ 50, email?, note?, expiresDays ≤ 90}` → codes |
| `GET /admin/waitlist` / `POST /admin/waitlist/:id/invite` | Create an invite and email it |
| `POST /admin/ops-event` | `{kind: 'backup_ok' \| 'backup_failed' \| 'restore_ok' \| 'restore_failed' \| 'host_health', detail?}`. `host_health` uses spec 11's bounded structured disk/inode/heartbeat payload. Authenticated with the `METRICS_TOKEN` bearer and exempt from the CSRF header (§1); used by the host scripts in spec 11 §4. It appends to `settings['ops.events']` (the last 50 kept), which `house.alerts` reads |

**Admin write constraints:** validate every field with the shared schemas; no arbitrary JSON-to-SQL
updates. Feed fetch options use spec 03's allowlist and may not disable SSRF checks or smuggle auth
headers. User role is `user|admin`, plan is a defined plan key and invites_left is a nonnegative
bounded integer. Prevent accidental removal of the last active admin; revoke affected sessions on
role downgrade, recompute feed intervals on plan changes, and record actor/target/changed keys in
the audit log without secrets. Card promotion cannot bypass the sharing/consent policy (PLAN §17).
Settings patches validate the **merged** configuration, lock/version the settings rows and commit
all side effects through the outbox. Setting unchanged values is a no-op. Activating question sets
must validate existence/kind and apply spec 05's cache-invalidation/backfill policy for every changed
kind; the abbreviated table is not permission to activate a set without its lifecycle effects.
Admin queries default to 30 rows, limit at 100, validate `days` 1..90 and search `q` length ≤200.
Each admin route's complete request/success/error DTO is part of its implementation task and OpenAPI
contract, including explicit empty results; generic `any`/unbounded JSON is not an acceptable DTO.

---

## 10. Health and metrics

- `GET /healthz`: liveness, always `200` while the process runs.
- `GET /readyz`: `200` if the DB is reachable and migrations are current, otherwise `503`.
- `GET /dev/last-email`: **only when `NODE_ENV=test`** (the route is not registered otherwise). Returns the last email captured by `MAIL_TRANSPORT=log`, for E2E tests.
- `GET /metrics`: Prometheus text, protected by an admin session **or** a `METRICS_TOKEN` bearer.
  - Counters and histograms: HTTP requests, latency, errors by code.
  - The worker exposes its own `/metrics` on `WORKER_METRICS_PORT` (default 9101): queue depths,
    engine calls by status, spend, fetch outcomes, extract outcomes.

---

## 11. Rate limits (`@fastify/rate-limit`; keyed as noted)

| Route group | Limit |
|---|---|
| everything, per IP | 300 / min |
| `POST /auth/request-code` | 5 / hour per email, 20 / hour per IP |
| `POST /auth/verify` | 10 / 10 min per IP |
| `POST /waitlist` | 5 / hour per IP |
| authenticated mutations, per user | 120 / min |
| `POST /subscriptions` | 30 / hour per user |
| `POST /subscriptions/import-opml` | 5 / day per user |
| `GET /me/export` | 2 / hour per user |
| `POST /cards`, `PATCH /cards/*`, `POST /cards/*/examples*`, `POST /labels*` | 60 / hour per user (each may trigger backfills) |

All limits are enforced unless `RATE_LIMITS_ENABLED=false` (spec 01 §3). Only E2E and load-test
environments with `NODE_ENV=test` set it to false. Config validation rejects `false` in production.
Only Caddy's known internal proxy address/network is trusted for forwarded IP headers; never
`trustProxy: true` for arbitrary clients. Limits must be shared across API processes/restarts via
the DB-backed limiter, or deployment must enforce the documented single-API-instance limit.
Send `Retry-After` for 429. Public waitlist upserts never expose whether an address already exists.

---

## 12. Tests

- **Route tests** with `fastify.inject` against the integration DB:
  - every endpoint's happy path and validation failure
  - auth flows: login, signup with an invite, invite-only rejection email, code expiry, attempt limit
  - quotas
- **The RLS isolation suite:** seed users A and B, then call **every** GET endpoint as A and assert
  that nothing of B's appears. This includes cards (shared rows with private forks), labels, rules,
  bookmarks and exports.
- **CSRF:** a mutation without `X-FeedIt-Client` returns `403`.
- **Snapshots:** the OpenAPI document (a breaking change fails CI unless the snapshot is updated in
  the same commit).
- **Operation list:** `apps/api/test/expected-operations.txt` lists every `METHOD /path` of this spec
  (one per line, written by hand from §2–§10). A test asserts that the OpenAPI document contains exactly
  these operations, plus nothing undocumented.
- **Write-path grants:** every mutation endpoint succeeds against a database where the API connects as
  `feedit_app`. This catches missing grants (spec 02 §1.2).
- **Enqueue:** an integration test proves the API role can persist its job intent and the relay can
  deliver it to pg-boss, and that the admin
  bootstrap row of the §2.1 decision table works with an empty `invites` table.
- **Race/replay tests:** two verifies of one code yield one success; attempts persist after invalid
  verification; two invite uses/last quota-slot requests cannot both succeed; concurrent duplicate
  idempotency keys produce one mutation/event/outbox intent. Crash after state commit still delivers
  jobs. Stale expected versions never overwrite reader changes; exact bulk undo restores prior
  ratings/read/archive and refuses intervening edits.
- **Cross-user writes:** exercise every mutation with B's ids as A, including shared article detail,
  examples, cluster members, labels, bulk ids, receipts and sessions. RLS read tests alone are
  insufficient. Test old-session replay after deletion/demotion.
- **Reader query fixtures:** duplicate stories in two feeds/folders, hidden best member, bookmark
  after unsubscribe/block, tier-filtered counts, null scores, tie ids, reranking between pages and
  malformed/cross-user cursors. Assert list/count parity for identical query versions.
