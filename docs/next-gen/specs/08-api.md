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
  | `QUOTA_EXCEEDED` | 409 |
  | `INVITE_REQUIRED` | 403 |
  | `RATE_LIMITED` | 429 |
  | `FEED_*` (spec 03 §4) | 422 |
  | `ENGINE_UNAVAILABLE` | 503 (only admin endpoints surface it) |
  | `INTERNAL` | 500 |

- **Pagination:** cursor-based. `?cursor=<opaque>&limit=<1..100>` (default 30). The response has
  `nextCursor: string | null`. The cursor is base64url JSON of the sort-key tuple.
- **Tenancy:**
  - The `tenant` plugin exposes `req.withTx(fn)`. It opens a transaction **lazily**, on first use, runs
    `set_config('app.user_id', …, true)`, and hands `fn` a `TenantTx`. Route handlers must use it for
    per-user tables.
  - Slow outbound work (feed discovery, OPML validation, translation of card text) runs **before** the
    transaction is opened, so no pooled connection is held during network I/O.
  - Jobs are enqueued after commit through `packages/shared/src/jobs.ts`.
- **CSRF:**
  - Every non-GET request must carry the header `X-FeedIt-Client: web`. Browsers cannot send a custom
    header cross-site without a CORS preflight, and preflights are refused.
  - If an `Origin` header is present, it must equal `PUBLIC_BASE_URL`.
  - **Exempt:** requests authenticated with the `METRICS_TOKEN` bearer (`POST /admin/ops-event`,
    `GET /metrics`). These come from host scripts, not browsers, and carry no cookie.
- **CORS:** disabled. The web app is same-origin through Caddy.

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
- 6 digits from a CSPRNG, stored as `sha256(code + SESSION_PEPPER)`, TTL **10 minutes**, at most
  **5** verify attempts per code.
- The requested `locale` and the `inviteCode` are stored on the `login_codes` row.
- A new request invalidates older unconsumed codes for the email.
- Emails come from localized templates (en/sk) in `packages/shared/src/mail/templates/` (shared with
  the worker's alert emails), with a plain-text and an HTML part.

**The signup mode** is `settings['signup_mode']` if set, otherwise `SIGNUP_MODE` (spec 02 §2).

**On signup:**
- insert `users`:
  - UUID v7
  - locale from `login_codes.locale`, else `Accept-Language`
  - `invites_left` from the plan
- mark the invite used
- if the email is on the waitlist, set `waitlist.invited_at`

**On every successful verify:**
- set `role = 'admin'` if the email is in `ADMIN_EMAILS`, so the list can be extended later
- clear `deleted_at` (restoring a soft-deleted account), then call `refresh_feed_subscribers` and
  `refresh_feed_cards` for the user's feeds and enqueue `user.rank {full: true}`
- update `last_active_at`

A soft-deleted account keeps its `users` row until `house.purge-users` removes it after 7 days, so an
email never hits the unique constraint through the signup path.

**Session cookie:** `fi_sid` = 32 random bytes, base64url, stored hashed.
- Attributes: `HttpOnly`, `Secure` (production), `SameSite=Lax`, `Path=/`, `Max-Age = SESSION_TTL_DAYS`.
- Sliding: `sessions.last_seen_at`, `sessions.expires_at` **and `users.last_active_at`** are refreshed
  at most every 5 minutes. "Active in the last N days" everywhere means `users.last_active_at`.

### 2.2 Invites and waitlist

| Endpoint | Body | Behaviour |
|---|---|---|
| `GET /invites` | — | My invites `[{code, email, createdAt, expiresAt, usedAt, url}]` and `invitesLeft` |
| `POST /invites` | `{email?, note?}` | Requires `invites_left > 0`. Creates a code (10 chars, Crockford base32, expires in 30 days), decrements the counter, and emails it if `email` is given → `201 {code, url: PUBLIC_BASE_URL + '/join?code=' + code}` |
| `POST /waitlist` | `{email, locale?, note?}` | Public. Upsert → `202`. Rate-limited per IP |

---

## 3. Me and preferences

| Endpoint | Body | Behaviour |
|---|---|---|
| `GET /me` | — | `Me` = `{id, email, displayName, locale, timezone, role, plan, invitesLeft, preferences, quotas: {used, limits}}` |
| `PATCH /me` | `{displayName?, locale?, timezone?, preferences?}` | Partial update. `preferences` is deep-merged and validated (§3.1). A change to `demote` enqueues `user.rank {full:true}` |
| `DELETE /me` | — | Sets `deleted_at`, revokes all sessions, and calls `refresh_feed_subscribers` and `refresh_feed_cards` for the user's feeds (soft-deleted users no longer count) → `204`. `house.purge-users` hard-deletes after 7 days |
| `GET /me/export` | — | `application/json` attachment: `{user, subscriptions, opml, cards, labels, rules, ratings: [{url, title, rating, reason, ratedAt}], bookmarks: [{url, title, bookmarkedAt}]}` |

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
| `POST /subscriptions/:feedId/mark-read` | `{olderThan?}` | Mark all unread items of the feed read |
| `POST /subscriptions/import-opml` | multipart `file` (≤ 1 MB) | spec 03 §11. `200 {added, existing, invalid: [...]}`. Enqueues one backfill for the new feeds and `user.rank {full: true}`. Quota `opmlMaxFeeds` and `maxFeeds` |
| `GET /subscriptions/export-opml` | — | `text/x-opml` attachment |
| `POST /subscriptions/folders/rename` | `{from, to}` | Rename a folder across the user's subscriptions and in `preferences.folderOrder` → `200 {count}` |

`FeedInfo = {id, url, siteUrl, title, iconUrl, status, lastSuccessAt, lastErrorCode, lastErrorAt}`.

---

## 5. Reading and feedback

### 5.1 `GET /articles`

**Query:**
- `lane` ∈ `for_you | maybe | everything | new | all | bookmarks` (default `for_you`)
- `feedId?`, `folder?`, `labelId?`
- `status` ∈ `unread | all` (default `unread`; default `all` when `lane = bookmarks`)
- `minTier?` (1–5, default `prefs.defaultTier`; applies to `for_you` and `maybe`)
- `sort` ∈ `score | date` (default: `score` for `for_you`; uncertainty order for `maybe`, spec 06 §10;
  `date` otherwise)
- `cursor`, `limit`

**Semantics, in this order:**
1. **Candidate set:** articles carried by the user's subscriptions (excluding `hidden` feeds unless
   `feedId` is given) with `first_seen_at ≥ now − 14 days`. For `lane = bookmarks`: every article with
   `bookmarked_at` set, with no window and no subscription requirement.
2. **Cluster folding**, applied to the **whole candidate set before any lane or status filter**:
   - An article is foldable if **any** subscription carrying it has `allow_duplicates = false`.
   - Foldable articles sharing a `story_cluster_id` collapse into one row: the member with the highest
     `p_like`, then the earliest `first_seen_at`, computed with
     `row_number() OVER (PARTITION BY story_cluster_id ORDER BY …) = 1`.
   - That row reports `cluster.size` and the other members' feed titles.
   - Non-foldable and unclustered articles are their own rows.
3. **Filters:**
   - lane = `coalesce(ua.lane, 'new')`, where `all` means every lane except `hidden`; `hidden` is never
     listed
   - `status = unread` means `ua.read_at IS NULL AND ua.archived_at IS NULL`
   - `minTier`, `feedId`, `folder`, `labelId`
4. Sort, then paginate.

`GET /articles/counts` applies steps 1–3 without the lane filter and groups by lane, so the counts
always equal the list totals.

**Outdated scores:** if the user's newest `score_version` is older than the current one (spec 06 §7),
the request enqueues a full rank. The list is served immediately from the existing scores.
- **Sort keys:**
  - score: `(p_like DESC NULLS LAST, first_seen_at DESC, id DESC)`
  - maybe: `(abs(p_like − 0.5) ASC, first_seen_at DESC, id DESC)`
  - date: `(coalesce(published_at, first_seen_at) DESC, id DESC)`

**Response:** `{ items: ArticleListItem[], nextCursor }`, where

```ts
ArticleListItem = { id, title, url, feed: {id, title, iconUrl}, author, publishedAt, firstSeenAt,
  excerpt /* ≤ 300 chars */, imageUrl, lang, lane, tier, pLike, topReason: TopReason | null,
  labelIds, labelSuggestions, rating, reason, readAt, bookmarkedAt,
  cluster: { id, size, otherFeeds: string[] } | null }

TopReason =                                        // structured; the web client localizes it
  | { kind: 'card'; cardId: string; title: string; p: number }
  | { kind: 'rule'; code: string; ruleId?: string }
  | { kind: 'model'; feature: string; label: string }
  | { kind: 'keyword' }                            // degraded (BM25)
```

`topReason` is derived from `explain`:
- a fired floor or cap rule → `rule`
- otherwise, source `cards` → the deciding card (`explain.decidingCardId`)
- otherwise, source `model` → its top contribution
- otherwise, source `degraded` → `keyword`

`GET /articles/counts` → `{forYou, maybe, everything, new, bookmarks, scored, total}` of unread items.
`scored` counts items with a lane other than `new`, and `total` counts all unread candidates, so
onboarding can show "Reading your feeds… 38/120".

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

### 5.3 Actions (all `POST`, all return `200 {item: ArticleListItem}` unless noted)

| Endpoint | Body | Effect |
|---|---|---|
| `/articles/:id/read` | `{}` | Set `read_at` (expand in the list, when `markReadOnExpand`) |
| `/articles/:id/unread` | `{}` | Clear `read_at`, record `unread` |
| `/articles/:id/open` | `{}` | Set `opened_at` and `read_at`, and record a `feedback_events` `open` (the user opened the original URL) |
| `/articles/:id/dwell` | `{ms}` | Set `dwell_ms = max(existing, ms)` and record `dwell`. The response includes `{prompt: boolean}` from the spec 06 §10 rule. When `prompt` is true, also set `feedback_prompted_at` |
| `/articles/:id/rating` | `{rating: 1 \| -1 \| null, reason?, hide?: boolean}` | Upsert `rating`/`reason`/`rated_at` (null = un-rate); set `read_at` if `markReadOnRate`; `hide` sets `archived_at` (FeedIt's SHIFT+rate). Records `rate`/`unrate`. Applies the learn trigger of spec 06 §8.4 |
| `/articles/:id/prompt-answer` | `{liked: boolean}` | Store as a rating (`rating = liked ? 1 : -1`, `rated_at = now`), record `prompt_answer`, and apply the learn trigger |
| `/articles/:id/bookmark` / `DELETE` of the same path | `{}` | Set or clear `bookmarked_at`, and record the event |
| `/articles/:id/labels` | `{labelId}` | Add to `label_ids`, remove from `label_suggestions`, record `label`. **Cards are not changed** (spec 05 §5.1). Label examples are explicit (§7) |
| `DELETE /articles/:id/labels/:labelId` | — | Remove it, record `unlabel` |
| `/articles/:id/mute-story` | `{days: 1\|3\|7\|30}` | Create a cluster for the article if it has none, create a `mute_story` rule with `expires_at`, enqueue `user.rank {full}` → `201 {rule}` |
| `/articles/mark-read` | `{articleIds?: string[≤500], lane?, feedId?, olderThan?}` | Bulk mark read (cap 5,000) → `200 {count}` |
| `/articles/rate-bulk` | `{articleIds: string[≤200], rating: 1 \| -1 \| null}` | FeedIt's "train the whole feed", as one transaction. `null` un-rates, which is what undo uses → `200 {count}` |

**Row creation:** `user_article` rows are created on first action (upsert). All feedback writes also
append `feedback_events`.

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
  recomputed on subscribe/unsubscribe.

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
| `POST /admin/ops-event` | `{kind: 'backup_ok' \| 'backup_failed' \| 'restore_ok' \| 'restore_failed', detail?}`. Authenticated with the `METRICS_TOKEN` bearer and exempt from the CSRF header (§1); used by the host scripts in spec 11 §4. It appends to `settings['ops.events']` (the last 50 kept), which `house.alerts` reads |

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
| `POST /cards`, `PATCH /cards/*`, `POST /cards/*/examples*`, `POST /labels*` | 60 / hour per user (each may trigger backfills) |

All limits are enforced unless `RATE_LIMITS_ENABLED=false` (spec 01 §3). Only E2E and load-test
environments with `NODE_ENV=test` set it to false. Config validation rejects `false` in production.

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
- **Enqueue:** an integration test proves the API role can `send()` to pg-boss, and that the admin
  bootstrap row of the §2.1 decision table works with an empty `invites` table.
