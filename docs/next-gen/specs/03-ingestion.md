# Spec 03: Ingestion pipeline (feeds → articles)

Status: **binding**. **Intent:** fetch every subscribed feed once for all users, politely and safely.
Store each article once, however many feeds carry it. Hand clean, deduplicated, language-tagged text to
the classification stages, and never let one broken feed or page stall the pipeline.

Code lives in `packages/feeds` (pure logic and the safe HTTP client) and in `apps/worker/src/handlers`
(I/O wiring).

---

## 1. Pipeline overview

```
feed.schedule (cron, every minute)
   └─► feed.fetch {feedId}                       fetch + parse + ingest in one handler
          └─► article.extract {articleId}        for each NEW article that is not stale
                 └─► article.translate {id}      only if LANGUAGE_MODES[lang] = 'translate' (spec 07)
                        └─► article.enrich {id}  Call A (spec 05)
                               ├─► article.cluster {id}   story clustering (spec 05 §6)
                               └─► article.match {id}     Call B for pending match_queue rows (spec 05 §5)
                                      └─► user.rank {userId, reason}       (spec 06)
```

`apps/worker/src/pipeline.ts` is the **only** place that decides the next stage. Each handler ends by
calling `pipeline.after(<stage>, articleId, outcome)`.

**Degradation rules.** A failing stage never blocks the next one:

| Stage fails | Next stage runs with |
|---|---|
| extract | no body (`article_bodies.status = failed/skipped`); enrich uses title + excerpt |
| translate (both tiers) | native text (`state_variant = 'native'`) |
| enrich (engine unavailable) | `pipeline_state = 'degraded'`; no match; `user.rank` ranks with the BM25 fallback; `house.rescore-degraded` retries later (spec 04 §5) |
| cluster | article stays unclustered |
| match | the rows stay in `match_queue` with `attempts + 1`; ranking uses whatever answers exist |

---

## 2. Queues (pg-boss)

| Queue | Payload (zod) | Producer | Concurrency / process | Retry | Singleton key | Notes |
|---|---|---|---|---|---|---|
| `feed.schedule` | `{}` | cron `* * * * *` | 1 | none | — | enqueues due feeds (§3) |
| `feed.fetch` | `{feedId: string}` | schedule, subscribe API | 16 | 0 (failures are accounted for in the feed row) | `feed:<id>` | `expireInSeconds: 120` |
| `article.extract` | `{articleId: string}` | fetch | 8 | 2, backoff 30 s | `extract:<id>` | per-host politeness (§8.2) |
| `article.translate` | `{articleId: string, forceTier2?: boolean}` | extract, ranker (spec 07 §5) | 4 | 1 | `translate:<id>` | |
| `article.enrich` | `{articleId: string, priority?: 'interactive'\|'bulk'}` | extract/translate, rescore | 8 (shares the engine semaphore) | 1 | `enrich:<id>` | |
| `article.cluster` | `{articleId: string}` | enrich | 4 | 1 | `cluster:<id>` | |
| `article.match` | `{articleId: string}` | enrich, card.backfill | 8 | 1 | `match:<id>` | drains all queued cards for the article |
| `card.backfill` | `{userId: string, cardIds: string[], feedIds?: string[]}` | API (card or subscription change) | 2 | 2 | — | spec 05 §5.4 |
| `user.rank` | `{userId: string, reason: string, full?: boolean}` | match, API, learn | 4 | 2 | `rank:<userId>` with `singletonSeconds: 3` | debounced; the handler finds dirty articles itself (spec 06 §7) |
| `user.learn` | `{userId: string}` | API (ratings), cron | 2 | 1 | `learn:<userId>` with `singletonSeconds: 60` | spec 06 §8 |
| `user.suggest` | `{userId: string}` | learn, nightly cron | 1 | 1 | `suggest:<userId>` | spec 05 §7 |
| `house.*` | `{}` | cron | 1 | 1 | — | spec 11 §6 |

Payload IDs are strings (bigint-safe). Handlers validate payloads with zod and drop invalid jobs with an
error log. Invalid payloads are never retried.

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

Enqueue `feed.fetch` for each id, with singleton key `feed:<id>` so a slow fetch is never doubled.
`paused` and `dead` feeds are never scheduled. A `quarantined` feed becomes due at `quarantined_until`
(the fetch handler sets `next_fetch_at = quarantined_until`).

---

## 4. Safe HTTP client (`packages/feeds/src/http/`)

All outbound HTTP for feeds, pages, discovery and robots.txt goes through `safeFetch(url, opts)`.

**Intent:** a user-supplied URL must never reach internal services or hang a worker.

1. **Schemes:** only `http:` and `https:`. **Ports:** only 80, 443, 8080, 8443 (others fail with
   `FEED_BLOCKED_ADDRESS`).
2. **DNS pinning:** an undici `Agent` with `connect.lookup = safeLookup`. `safeLookup` resolves with
   `dns.lookup(host, {all: true})`. If **any** resolved address is in a blocked range it rejects;
   otherwise it returns the first allowed address to the connector, which prevents DNS rebinding.
   Blocked ranges (use `ipaddr.js` `range()` plus explicit CIDRs):
   - **IPv4:**
     - `0.0.0.0/8`, `10.0.0.0/8`, `100.64.0.0/10`, `127.0.0.0/8`, `169.254.0.0/16`, `172.16.0.0/12`
     - `192.0.0.0/24`, `192.0.2.0/24`, `192.88.99.0/24`, `192.168.0.0/16`, `198.18.0.0/15`
     - `198.51.100.0/24`, `203.0.113.0/24`, `224.0.0.0/4`, `240.0.0.0/4`
   - **IPv6:**
     - `::/128`, `::1/128`, `fc00::/7`, `fe80::/10`, `ff00::/8`, `64:ff9b::/96`, `2001:db8::/32`
     - `::ffff:0:0/96`: check the embedded IPv4 address against the IPv4 list
3. **Redirects:** `maxRedirections: 0`. The client follows redirects itself, up to **5 hops**,
   re-validating scheme, port and address on every hop. It records the final URL and whether any hop
   was a `301`/`308` (permanent).
4. **Limits:**
   - headers timeout 10 s; total timeout `FETCH_TIMEOUT_MS` (20 s)
   - body capped at `FETCH_MAX_BYTES` (5 MB), counted on the **decompressed** stream; abort with
     `TOO_LARGE` when exceeded
   - accepted encodings: gzip, deflate, br
5. **Request headers:**
   - `User-Agent: FETCH_USER_AGENT` (a per-feed override is allowed from `feeds.fetch_options.user_agent`)
   - `Accept` appropriate to the purpose
   - `If-None-Match` / `If-Modified-Since` from `feeds.etag` / `feeds.last_modified` for feed fetches
6. **TLS:** verification is always on. There is no insecure option.
7. **Result type:**
   `{ ok: true, status, finalUrl, permanentRedirect, headers, bodyBytes: Uint8Array } | { ok: false, code, status?, message }`.
   It never throws for network or HTTP errors.
8. **Error codes:** `FEED_BLOCKED_ADDRESS`, `FEED_DNS_ERROR`, `FEED_TIMEOUT`, `FEED_TLS_ERROR`,
   `FEED_CONNECTION_ERROR`, `FEED_TOO_LARGE`, `FEED_HTTP_<status>`, `FEED_TOO_MANY_REDIRECTS`.
9. **Testing escape hatch:** `FETCH_ALLOW_PRIVATE=true` disables the address check so local fixture
   servers work. Config validation **rejects** this flag when `NODE_ENV=production`.

**Charset decoding** (`decodeBody(bytes, contentType)`):
1. Use the charset from the `Content-Type` header.
2. Else use the XML declaration or HTML `<meta charset>` / `http-equiv` within the first 2 KB.
3. Else check for a UTF-8 BOM.
4. Else try UTF-8; if decoding produces more than 0.5 % replacement characters, detect with `chardet`.

Decode with `iconv-lite`.

---

## 5. URL canonicalization (`canonicalizeUrl`, pure)

**Intent:** the same article under different URLs maps to one key; different articles never collide.

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
5. Sort the remaining query parameters by key (a stable sort keeps the order of repeated keys). Drop an
   empty `?`.
6. Collapse repeated slashes in the path. Do **not** change trailing slashes or case in the path.
7. `canonical_url` = the result. `url_key` = `canonical_url` without `scheme:`, e.g.
   `//example.com/a?b=1`.
8. **Linkless items** (no link, or no URL-like guid): `url_key = 'urn:feedit:' + feedId + ':' + sha1(guid ?? title + published)`.

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
- Malformed XML: before failing with `FEED_PARSE_ERROR`, retry once after a lenient cleanup that strips
  control characters, fixes unescaped `&`, and removes a BOM or leading junk before `<`.

**Per item → `NormalizedItem`:**

| Field | Rule |
|---|---|
| `title` | strip HTML, decode entities, collapse whitespace, trim. Fall back to the first 80 chars of the excerpt, else `"(untitled)"`. Max 500 chars |
| `link` | `item.link` → the guid if it is an absolute http(s) URL → the first enclosure URL. Resolved against the feed URL |
| `guid` | `item.guid ?? item.id ?? null`, max 500 chars |
| `published_at` | `isoDate` → `pubDate` → `dc:date` → `null`. If it is more than 1 day in the future, clamp to now |
| `author` | `creator ?? author ?? dc:creator ?? itunes:author`, as text, max 200 chars |
| `categories` | flattened strings, trimmed, deduplicated case-insensitively, max 16 entries of up to 64 chars each |
| `excerpt_html` | `content:encoded ?? content ?? summary ?? description`, sanitized (§6.3), max 10,000 chars |
| `excerpt` | plain text of the excerpt HTML, whitespace-collapsed, max 2,000 chars |
| `image_url` | the first of: enclosure with `image/*` type → `media:content` (medium=image) → `media:thumbnail` → the first `<img src>` in the content. Resolved and must be http(s) |

Each fetch processes at most **200 items**, newest first by `published_at` (items without a date go last).

### 6.1 `title_norm`

`normalizeText()` lives in `packages/shared`, because `feeds` and `ranker` both use it. It applies NFKD, then remove combining marks (diacritics), lower-case, replace non-alphanumeric runs with one
space, trim. Used for trigram similarity and near-duplicate checks. The same function is used for
keyword mutes (spec 06 §3.1).

### 6.2 `content_hash`

`sha256(title_norm + '\n' + first 500 chars of normalized excerpt)`, hex.

### 6.3 Sanitizing

`sanitize-html` with an allow-list:
- tags: `p, br, b, strong, i, em, u, a, ul, ol, li, blockquote, code, pre, h2, h3, h4, img, figure, figcaption`
- attributes: `a[href]`, `img[src|alt]`

Additional rules:
- `href` and `src` must be http(s), and are resolved to absolute URLs.
- `a` gets `rel="noopener noreferrer nofollow" target="_blank"`.
- Remove tracking pixels (an `img` with width or height ≤ 2).

---

## 7. Ingest (inside `feed.fetch`)

For one fetch, in **one transaction per item**, so one bad item doesn't roll back the rest:

1. Compute `canonical_url` and `url_key`.
2. **Exact match:** look up `articles.url_key = url_key` or `article_aliases.url_key = url_key`.
   - **Found:**
     - Upsert `feed_items (feed_id, article_id, guid)`.
     - If `content_hash` differs and `title_norm` changed: update title/excerpt/hash, set
       `pipeline_state = 'ingested'` and enqueue extract again. This counts as a new article for the
       pipeline, so it is re-enriched and re-matched.
     - If only the excerpt changed: update the excerpt fields and nothing else.
     - Not new.
3. **Guid match within the same feed** (`feed_items.guid`): treat it like found (the URL changed).
   Insert an alias with source `feed_link`.
4. **Near-duplicate within the same feed** (FeedIt's rule): an article of this feed first seen in the
   last 7 days with an equal `title_norm` **and** (the same first 80 normalized excerpt chars **or** the
   same `image_url`) is treated as found, with an alias of source `near_duplicate`.
5. **Otherwise insert** the article:
   - `pipeline_state = 'stale'` if `published_at` is older than `INGEST_MAX_AGE_DAYS` (default 14).
     Stored, visible, never enriched.
   - Otherwise `'ingested'`, and the article is new.
6. Insert `feed_items`.

After all items:
- Update the feed row (§9).
- Update `feeds.publish_stats.recent_gaps_s` with the gaps between the newest ≤ 20 `published_at`
  values.
- Enqueue `article.extract` for each new non-stale article.

A new subscription's first fetch ingests up to 200 items. Only the non-stale ones (≤ 14 days) cost
model calls.

---

## 8. Extraction (`article.extract`)

**Intent:** get enough clean text (the body lead) to classify well, without hammering sites or
fetching non-HTML media.

### 8.1 Steps

1. **Skip list** (status `skipped`, no fetch):
   - hosts `youtube.com`, `youtu.be`, `vimeo.com`, `x.com`, `twitter.com`, `instagram.com`,
     `facebook.com`, `tiktok.com`, `open.spotify.com`, `podcasts.apple.com`, `soundcloud.com`
   - paths ending in `.pdf`, `.mp3`, `.m4a`, `.mp4`, `.mov`, `.zip`, `.jpg`, `.png`, `.gif`, `.webp`
   - enclosures whose type is audio or video
2. **robots.txt:**
   - Fetch `/robots.txt` per origin through `safeFetch` and cache it in an in-memory LRU
     (5,000 origins, 24 h TTL).
   - Parse with `robots-parser`.
   - If our UA (product token `FeedItBot`) is disallowed for the path, set status `blocked` and skip.
   - robots.txt errors (other than 5xx) count as allow-all. 5xx counts as disallow for this attempt.
3. **Fetch** the article URL with `Accept: text/html,application/xhtml+xml`.
   - A non-HTML `Content-Type` → `not_html`.
   - Record `resolved_url` (the final URL after redirects).
4. **Alias and merge by redirect:** if `canonicalize(resolved_url)` has a different `url_key`:
   - If another article already owns that key, **merge**:
     - move `feed_items` to the existing article (ignore conflicts)
     - add an alias for the current key
     - delete the current article (its pipeline ends; log `merged_into`)
   - Otherwise add an alias with source `redirect`.
5. **Parse:**
   - `linkedom` `parseHTML`, then `new Readability(document, { charThreshold: 200 }).parse()`.
   - `rel=canonical`: if the page declares `<link rel="canonical">` on the **same registrable domain**
     (use `tldts`), apply the same alias/merge logic with source `rel_canonical`.
   - No result, or text shorter than 200 chars → status `failed` with error `no_content`.
6. **Store:**
   - `body_text` = Readability `textContent`, whitespace-normalized, max 100,000 chars.
   - `body_lead` = the first 1,500 chars, cut at the last sentence end (`. ! ? …`) after char 1,000 if
     there is one.
   - `word_count` = whitespace token count of `body_text`, or of the excerpt if there is no body.
7. **Language detection** (§8.3). Set `articles.lang` and `lang_confidence`.
8. Set `pipeline_state = 'extracted'` and call `pipeline.after('extract')`.

### 8.2 Politeness

- An in-process per-origin limiter: at most **2 concurrent** requests and at least **1 s between
  request starts** per origin.
- A 429 or 503 with `Retry-After` blocks the origin for that long (up to 1 h) and re-queues the job
  with `startAfter`.

### 8.3 Language detection (`detectLanguage`, pure)

**Input:** `title + ' ' + excerpt + ' ' + body_lead.slice(0, 1000)`.

1. If the text is shorter than 40 chars: `lang = feed.lang_hint ?? 'und'`, confidence 0.
2. Otherwise run `francAll(text, { only: [eng, slk, ces, deu, pol, hun, fra, spa, ita, por, nld, ukr, rus], minLength: 20 })`.
3. `top` is the first result. `conf = top.score - second.score`. franc scores run 0..1, with the best
   at 1.
4. If `conf < 0.05` and `feed.lang_hint` is set, use `lang_hint`.
5. **Slovak/Czech tie-break:** if the top two are {slk, ces} and `conf < 0.15`, use `feed.lang_hint`
   when it is `sk` or `cs`. Otherwise keep `top`.
6. Map ISO 639-3 to 639-1 (eng→en, slk→sk, ces→cs, …). If nothing matches, `lang = 'und'`.

`feeds.lang_hint`:
- Set from `<language>` when present.
- Otherwise, after 20 articles, set to the majority detected language if it covers ≥ 70 % of them.

---

## 9. Adaptive fetch interval (`nextSchedule`, pure)

**Intent:** fetch busy feeds often and quiet feeds rarely. Never let a daily feed drift to days
between fetches. Back off hard on errors.

**Inputs:** the feed row, the fetch outcome (`new_items`, `not_modified`, or an `error` with HTTP
status and `Retry-After`), `now`, and the feed hints (`ttl` in minutes, `sy:updatePeriod/Frequency`,
`Cache-Control: max-age`).

```
MIN   = feed.min_interval_s                       (≥ 300)
MAX   = 86_400 if last_new_item_at within 30 days, else 864_000     (24 h / 10 days)

on success (HTTP 200/304):
  consecutive_errors = 0; first_error_at = null; status = 'active'; quarantine_count = 0
  if n_new > 0:
      factor   = n_new >= 5 ? 0.5 : n_new >= 2 ? 0.75 : 1.0
      interval = interval * factor
      consecutive_empty = 0; last_new_item_at = now
  else:
      consecutive_empty += 1; total_empty += 1
      interval = interval + max(300, round(interval * 0.2))
  hint     = max(ttl_s, sy_period_s, min(cache_max_age_s, 21_600))   (missing hints count as 0)
  interval = max(interval, hint)
  if publish_stats.recent_gaps_s has ≥ 5 entries and last_new_item_at within 7 days:
      G = median(recent_gaps_s)
      interval = min(interval, max(MIN, G / 2))          # a daily feed stays ≤ ~12 h
  interval = clamp(round_to_60(interval), MIN, MAX)
  next_fetch_at = now + interval * (1 + jitter)          # jitter ∈ [-0.1, 0.1], deterministic from hash(feed.id, now_day)

on error:
  consecutive_errors += 1; total_errors += 1; first_error_at ??= now
  last_error_code/last_error/last_error_at = …
  if http_status == 410: status = 'dead'
  backoff = min(300 * 2^(consecutive_errors - 1), 86_400)
  if Retry-After present: backoff = max(backoff, min(retry_after_s, 86_400))
  if consecutive_errors >= 10:
      quarantine_count += 1
      status = 'quarantined'
      quarantined_until = now + 2 days * 2^(quarantine_count - 1)   (2, 4, 8 … capped at 16 days)
      next_fetch_at = quarantined_until
  else:
      next_fetch_at = now + backoff
  if now - first_error_at >= 30 days: status = 'dead'

always: total_fetches += 1; last_fetch_at = now; store the new etag/last_modified on 200
```

**Permanent redirect of the feed URL** (301/308 on the feed fetch):
- If no other feed has the new canonical URL, update `feeds.url`.
- If another feed has it, **merge**: move the subscriptions (skip duplicates), then call
  `refresh_feed_subscribers` and `refresh_feed_cards` for both feeds, and mark the old feed `dead`.

`dead` feeds are shown to subscribers with a banner (spec 09). An admin can reset them.

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
6. **First fetch:** for a new feed row, `POST /subscriptions` runs one synchronous fetch (bounded 20 s)
   to get the title and fail fast. Item ingestion then goes through `feed.fetch`.

---

## 11. OPML

**Import** (`parseOpml`, pure; `POST /subscriptions/import-opml`):
- Parse with `fast-xml-parser`. Collect every `outline` with an `xmlUrl`, recursively.
- `folder` = the nearest ancestor outline's `text`/`title`.
- Cap at the plan limit (spec 08 §6). Skip duplicates.
- Create or reuse the `feeds` rows (**no fetch**, `next_fetch_at = now()`) and the subscriptions.
- Call `refresh_feed_subscribers`/`refresh_feed_cards` once for all of them.
- Return a report `{added, existing, invalid: [{line, url, reason}]}`.
- Feeds that fail their first fetch show their error status in the feed list.

**Export:** OPML 2.0 with one `outline` per folder and `text`, `title`, `type="rss"`, `xmlUrl`,
`htmlUrl` per subscription (title override if set).

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
