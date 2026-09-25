# Spec 11: Operations: deploy, housekeeping, backups, monitoring, security

Status: **binding**. **Intent:** run the whole service on one self-hosted box with no GPU and minimal
spend. Keep the data safe with tested backups. Clean up after itself automatically, and tell the admin
when something needs a human, before users notice.

---

## 1. Target host

- **Size:** 8 vCPU (dedicated), 16–32 GB RAM, ≥ 160 GB NVMe, Ubuntu LTS. Budget provider; a few tens
  of € per month.
- **Open ports:** only 22 (SSH, key-only), 80 and 443. `ufw` default-deny inbound, plus verified
  Docker firewall rules: a published container port can bypass ordinary host firewall assumptions.
  Database, translation, API and worker metrics ports are internal-only; test from outside the host.
- **Host packages:** Docker Engine + Compose plugin, `unattended-upgrades`, `rclone` + `age` (backups),
  `fail2ban` (SSH), synchronized system clock. Host cron explicitly sets `CRON_TZ=UTC`.

---

## 2. Compose stack (`infra/compose.yml`)

| Service | Image | Memory limit | Notes |
|---|---|---|---|
| `postgres` | `postgres:16` | 6 GB | volume `pgdata`; `infra/postgres/init.sh` mounted into `/docker-entrypoint-initdb.d/` (spec 02 §1.1) with `POSTGRES_PASSWORD` and `FEEDIT_{OWNER,APP,WORKER}_PASSWORD` from `.env`. Config: `shared_buffers=1536MB`, `work_mem=8MB`, `maintenance_work_mem=256MB`, `max_connections=100`, `wal_compression=on`. Not published to the host network |
| `migrate` | dedicated migration target, command `pnpm db:migrate` | 512 MB | includes migration CLI + SQL artifacts; runs once per deploy with restart `"no"`; `api`/`worker` depend on successful completion |
| `api` | built from `apps/api` | 768 MB | healthcheck `GET /api/v1/readyz` on its internal port |
| `worker` | built from `apps/worker` | 1.5 GB (3.5 GB once Laya is enabled) | healthcheck on its metrics port |
| `libretranslate` | `libretranslate/libretranslate:latest` (pin a digest) | 3 GB | `LT_LOAD_ONLY=en,sk,cs`, `LT_DISABLE_WEB_UI=true`. Compose profile `translate`, started only when some language mode is `translate` or `card_text_mode = english` |
| `caddy` | `caddy:2` | 256 MB | TLS (Let's Encrypt), serves `apps/web/dist`, reverse-proxies `/api/*` → `api:3000`, security headers (§7) |

- **Logging:** the Docker `json-file` driver with `max-size=20m`, `max-file=5` for every service.
- **Restart policy:** `unless-stopped` for long-lived services; `"no"` for migrations/seeding. Pin
  every production image by digest and retain the previous release's image digests.
- **Resources:** `work_mem` is per sort/hash operation, not a per-server cap. Reserve ≥ 3 GB for host,
  filesystem cache and deploy/backup overhead; prove the enabled profile fits 16 GB. Two workers plus
  translation/Laya require the 32 GB profile or reduced limits. Each API pool starts at max 10,
  each worker at max 24 (including its feed-lock connections); account separately for pg-boss and
  migration/backup connections and keep their **combined** maximum below 80. Add an explicit total
  pool budget before enabling a second worker. No long-lived job lock may exhaust the query pool.
- **Persistence:** named volumes for Postgres, Caddy `/data` and `/config` (certificates), and pinned
  translation models. Healthchecks gate dependency startup; readiness includes migrations, DB and
  worker queue/outbox initialization, not successful external model calls. Run app containers as
  non-root, with a read-only filesystem except explicit tmp/model volumes, and no Docker socket.
- **Compose project name:** every compose file sets a top-level `name:` (`feedit-prod`, `feedit-dev`,
  `feedit-test`), and host ports come from env, so stacks never replace each other's containers.
- **Secrets:** protected host secret files/environment (mode 600) contain database-role passwords,
  SMTP/session/metrics secrets, backup encryption/bucket settings and the provider master-key ring
  `PROVIDER_MASTER_KEY_ID` / `PROVIDER_MASTER_KEYS` (specs01/04). Provider API keys are encrypted in
  `provider_credentials`; `TYPESAFE_API_KEY` / `OLLAMA_API_KEY` are optional bootstrap inputs, not an
  admin-readable plaintext settings store. Explicitly disabled credentials never reactivate through
  env fallback. Admin key submission is write-only and must be redacted before logging.
- Compose passes each service only its declared variables; the API never receives owner/worker
  database or backup credentials. Authorized API encryption and worker decryption get only the
  required host keyring; browser/build/export/logs get none of it. Keep encryption keys out of database
  dumps and back them up separately with offline access. Retain old key IDs for every still-retained
  backup that needs them, and drill rotation/decryption/revocation against fake providers. Application
  revocation disables local use; revocation at the provider requires its own dashboard/API operation.
  Never echo interpolated Compose config or put secrets in command arguments.

---

## 3. Deploy (`infra/scripts/deploy.sh`)

1. Resolve the approved release tag `vYYYY.MM.DD-N` to a commit, acquire a deploy lock and verify a
   clean checkout. Build immutable API/worker/migration/Caddy images with the frozen lockfile; static
   web assets are inside the Caddy image, not overwritten in a shared volume during a live deploy.
2. Validate required secrets and the enabled profiles, check free disk/connection budget, and record
   the previous image digests, schema version and a fresh successful encrypted pre-deploy backup.
3. Start healthy Postgres, then run the one-off migration image. Migrations acquire a database
   advisory lock and use bounded `lock_timeout`/`statement_timeout`. They must be **backward
   compatible** with the previous release: expand/contract, never drop a column in the release that
   stops using it. Failure stops deployment before application replacement. Run the seed command
   with its explicitly scoped role; runtime images must contain the command/artifacts they invoke.
   Seeding is idempotent: insert missing topics/question sets/library cards, but never overwrite
   admin settings or switch an existing active question set merely because a release was deployed.
4. Stop consumption and outbox claiming in the old worker, drain bounded in-flight work, then replace
   application containers. On SIGTERM API stops accepting requests, workers stop claiming new jobs,
   and both close pools after drain. Compose `stop_grace_period` exceeds the documented longest
   graceful deadline; forced shutdown relies on leases/idempotency, never on assumed exactly-once
   completion. Start the translation profile when required, then API/worker/Caddy.
5. Verify `/api/v1/readyz`, static asset routing, login-page delivery, worker heartbeat, outbox
   dispatch and one fixture-backed queue traversal. Wait up to 120 s for readiness. Record release
   SHA/digests and smoke results in the deploy log. The single-host beta allows a brief maintenance
   window; do not promise zero downtime.
6. If smoke fails, restore previous **application images** while retaining compatible expanded
   schema; do not blindly reverse migrations or restore the database over writes made after deploy.
   If compatibility cannot be demonstrated, remain in maintenance mode and use the recovery runbook.

**Proxy paths:** Caddy preserves `/api/v1/*` when proxying to the API (no accidental prefix stripping),
serves hashed assets with immutable caching, and serves `index.html`/service worker with revalidation.
SPA fallback must not turn missing API/assets into HTML 200 responses. `/metrics` is authenticated and
internal by default; `/readyz` exposes no secrets. `/bot`, `/privacy` and the support contact exist.

---

## 4. Backups (`infra/scripts/backup.sh`, host cron at 02:30)

**Beta recovery targets:** recovery-point objective (RPO) ≤ 24 h and recovery-time objective
(RTO) ≤ 4 h, **accepted by the owner** and verified on the target-size dataset. Tighter RPO would
require WAL archiving/PITR and is not provided by a daily dump. Benchmark restore with the expected
retained-bookmark corpus, since saved full content persists beyond the ordinary article-body window. A healthy daily backup meets the stated recovery target; a missed
backup immediately increases exposure and must alert rather than being reported as covered.

- Under a host lock, use `pg_dump -U postgres -Fc feedit` with a PostgreSQL 16 client and superuser
  access, preserving every schema and all RLS-protected rows (`pgboss` and `eval` included). Capture
  `pg_dumpall --globals-only --no-role-passwords` as well: a single-database dump does not include
  cluster roles. Restore role passwords from the separately encrypted deployment secrets.
  See [PostgreSQL 16 pg_dump](https://www.postgresql.org/docs/16/app-pgdump.html) and
  [pg_dumpall](https://www.postgresql.org/docs/16/app-pg-dumpall.html).
- Stream dump, globals and a manifest into `age` encryption; do not retain an unencrypted dump on
  disk. The manifest includes UTC start/end, commit/image digests, schema version, backup ID and
  ciphertext checksums. Use `set -euo pipefail`, temp files + atomic rename, and restrictive modes.
  An incomplete dump is never named or advertised as a valid backup.
- Upload encrypted artifacts to an S3-compatible bucket and verify object size/checksum before
  emitting `backup_ok`. Keep encryption recovery keys outside this host/bucket and test recovery
  from those keys. Back up encrypted deployment secrets on change, including every provider
  master-key version needed by retained backups, plus deploy/restore instructions; Caddy state
  can be backed up or deliberately recreated from documented DNS/TLS configuration.
- **Retention:** 7 daily, 4 weekly (Sunday), 6 monthly (1st), enforced locally and remotely by UTC
  backup IDs; one backup may satisfy multiple buckets. Prune only after a verified replacement,
  never delete the last known-good restore-tested backup. Bucket permissions isolate routine writer
  and retention-delete credentials. An off-site successful upload, not a local file, meets the RPO.
- **Weekly restore test** (`restore-test.sh`, Sunday 04:00 UTC): provision an isolated empty Postgres
  16 instance, restore roles/bootstrap ownership, create the database, then `pg_restore
  --exit-on-error` with ownership/ACLs intact. Use `psql -v ON_ERROR_STOP=1` for SQL/bootstrap scripts.
  See [PostgreSQL 16 pg_restore](https://www.postgresql.org/docs/16/app-pgrestore.html).
  The test has no production network egress, SMTP, provider keys or exposed public API.
- Verify expected schemas/extensions/migration version, FK validity, sequences, active-set seeds,
  restored RLS/role separation with two fixture tenants, and data bounds recorded in the manifest.
  A legitimately empty or quiet installation passes; “latest article < 24 h old” is not a backup
  integrity condition. Start the saved release against the restore with fake mail/providers and run
  login/read/rank/outbox smoke tests. Include a cold bookmarked snapshot whose publisher fixture
  returns 404: authorized full-text/HTML reads and exports must match the pre-backup checksum, with
  no refetch. Verify encrypted provider credentials against a fake provider using the separately
  restored key material, never production inference. Record restore duration and backup age.
- Scripts emit bounded redacted `ops-event`s and nonzero exit codes on failure. Alerts are sent only
  by `house.alerts`; an **independent external heartbeat monitor** also checks backup completion and
  public readiness so host/DB/worker failure cannot silence its own alarm.

### 4.1 Actual recovery runbook

1. Put public traffic in maintenance mode; stop workers and deny outbound provider/mail traffic.
2. Select and verify an off-site backup, decrypt with the offline recovery key, restore globals,
   roles/secrets and database into a new volume, then run the same verification as the weekly drill.
3. Apply the separately retained deletion ledger (§5.1) before serving any restored user data.
   Revoke restored sessions/login codes so old revocations cannot be undone by restoring a snapshot.
4. Do not immediately resume restored `pgboss` jobs: cancel/expire stale active leases through the
   pinned queue API, reset outbox/origin leases, and run reconciliation against current domain rows.
   Rebuild eligible work, skipping deleted users, superseded revisions and no-longer-authorized
   subscription inference generations; rebuild pending local bookmark captures separately. Disable delivery of old
   authentication/operational emails; re-enable only newly generated messages after recovery.
5. Restore model spend conservatively: remote provider usage after the backup still occurred. Keep
   paid inference paused until daily spent/reserved totals are reconciled or the UTC budget resets.
6. Re-enable workers and egress, verify read/write/queue/backup checks, reopen traffic, and record the
   actual loss window and recovery duration. A database restore is an operator action, never an
   automatic response to a transient application error.

---

## 5. Retention (enforced by housekeeping, §6)

| Data | Kept |
|---|---|
| `articles` (+ feed_items, facets, answers) | 90 days after `first_seen_at`, **unless** any user has bookmarked, rated or labelled it (then kept while that user exists) |
| unprotected hot `article_bodies.body_text` / `body_html` | 30 days; clear only after bookmarked content has a durable snapshot, and never clear content needed by a pending capture; `body_lead` remains |
| `article_snapshots` full saved text/HTML | indefinitely while at least one live bookmark references that immutable snapshot; cold after 30 days, never shortened or replaced by a lead |
| `article_translations` | with the article |
| `engine_calls` | 180 days (aggregated into `usage_daily` live) |
| `usage_daily` | aggregate totals forever; user attribution is removed on hard deletion (§5.1) |
| `user_article` reader state | while both user and article exist; unprotected articles expire after 90 days. Read items archived after 31 days; unread cap 1,000 per user per feed, subject to protected-state rules below |
| `analysis_requests` frozen inputs/results | 180 days from creation, covering the matching learning window; terminal/cancelled requests beyond that window are purged after dependent training/receipt references are handled |
| `feedback_events` | up to 365 days, or until the associated unprotected article/user is deleted |
| delivered `job_outbox` rows | 7 days; pending/failed intents remain until resolved or their domain entity is deleted |
| completed/failed pg-boss jobs | 7/30 days respectively using pinned pg-boss maintenance settings; failures counted before removal |
| `login_codes` | 1 day after expiry |
| `sessions` | 30 days after expiry or revocation |
| deleted users | hard-deleted 7 days after `DELETE /me`; encrypted historic backups age out within 6 months and are never served directly |
| `eval` schema | while needed as the golden set, subject to rater erasure (§5.1) |
| `metrics.daily` settings | 90 days of aggregate snapshot metrics (spec 10) |

**Retention precedence:** bookmarks, their retained snapshots/unexpired Undo pins, current non-null
ratings, explicit labels and golden-set references protect an article from the 90-day purge; historic undone feedback does not. Retention
windows are maxima, not promises that child rows survive deletion of their parent. Extraction after
30 days does not run solely to repopulate deliberately purged unbookmarked full bodies. An explicit
bookmark capture/retry may fetch missing content; a saved snapshot never depends on refetch. `body_lead` and translated
lead remain while the article exists. Rate/label examples needed for future training use current
`user_article` truth; they must not depend on an event older than the event-retention window.

Archive is reversible presentation state, not deletion. Exclude bookmarked/rated/explicitly labelled
rows from automatic archive. Count distinct unread article IDs per subscribed feed, newest by
`coalesce(published_at, first_seen_at), id`; archive only rows that lie beyond 1,000 in **every** feed
through which that user receives them. Since archive state is per article, applying each feed's cap
independently would wrongly hide a newer item in another feed. Protected rows may exceed the cap.

Delete eligible snapshot rows before their owning article; the snapshot article FK deliberately
blocks deleting saved content accidentally. Unreferenced snapshots still within their grace can
therefore temporarily extend the parent article's lifetime.

All purge jobs use short committed batches with keyset pagination and a per-run 5-minute work budget;
unfinished work continues next run. Recheck protection with article row locks before deleting. User
bookmark/rating/label mutations lock the same article row, so a concurrent save cannot lose its
article after reporting success. Count and alert on persistent FK failures rather than skipping them
forever. Cluster sizes/representatives, feed counts and ranking caches are reconciled after deletes.

### 5.1 Account erasure and restore safety

Soft deletion immediately revokes sessions and suppresses user work, recommendations and outbound
mail; the 7-day undo via re-authentication is documented in the UI/privacy page. Hard deletion locks
the user and rechecks `deleted_at` so a concurrent restore cannot be deleted accidentally. Enumerate
and test the deletion graph: subscriptions/cards/labels/rules, reader state/events/models/suggestions,
private-card answers/examples, bookmark capture state/snapshot references/Undo pins, per-feed media settings,
sessions/codes, user-specific outbox/jobs and cost attribution. Remove a snapshot only after its last
bookmark owner releases it; another user's preserved copy must survive this user's deletion. Remove
user holdings before private cards with RESTRICT references. Shared public feed/article rows remain.

Before erasing the account, write an encrypted off-site deletion ledger entry `{userId, deletedAt}`
that survives database restore; do not store email or content in the ledger. Use an idempotent entry
key and retain it through the longest backup lifetime plus 7 days. Failure defers hard deletion and
alerts the operator; a committed user purge must never lack its recovery tombstone. Account restore
and ledger creation are serialized by the same per-user lock. Recovery replays ledger entries before
public access, deletes personal rows again and clears restored queued work. Ledger storage access is
operator-only and is included in the restore drill.

Null user attribution in engine audit rows, remove personal prompt text/examples from any retained
payload, and fold the user's `usage_daily` amounts into the platform bucket transactionally before
deleting attributed rows. Keep spend totals correct; do not retain a permanently linkable deleted
user UUID under “anonymous statistics”. Delete golden-set rater data on a separately documented
rater-erasure request; “golden forever” does not authorize retaining personal identifiers forever.

### 5.2 Lossless saved-content storage and cold maintenance

Bookmark preservation takes precedence over the 30-day hot-body cleanup. Accepted Q14 limits saved
content to full available readable text and sanitized HTML; image/attachment/other asset binaries
are excluded. External image display continues to use the per-user-feed preference and does not
create an archival guarantee. Saved snapshot payloads
are immutable, with separate per-user ownership references; article revisions, feed unsubscribe,
model changes and source disappearance cannot overwrite them. Removing the last bookmark permits
snapshot garbage collection after a 7-day unreferenced grace, and only after all unexpired
`bookmark_snapshot_pins` for exact Undo are gone. A new reference during that interval cancels collection. Account erasure removes personal ownership immediately at hard deletion; shared
content still owned by another user remains. Do not impose an undocumented automatic bookmark TTL.

Use ordinary PostgreSQL `text` columns for snapshot text and sanitized HTML, `STORAGE EXTENDED`, and
per-column `COMPRESSION lz4` when the pinned PostgreSQL 16 build supports it; otherwise explicitly
select `pglz` and record that capability in deployment diagnostics. Compression/decompression is
lossless and transparent to SQL reads/exports. PostgreSQL chooses whether compression is worthwhile;
small or incompressible values need not be compressed. Do not promise a compression ratio or a
separate gzip blob for every row. See [PostgreSQL 16 TOAST](https://www.postgresql.org/docs/16/storage-toast.html).

Compression can apply from first insertion. At snapshot age 30 days, `house.purge-bodies` verifies the
snapshot checksum/reference under the shared article lock, marks it cold, and removes only redundant
hot full-body copies whose pending captures are satisfied. Cold is a retention/storage-lifecycle
marker, not removal of archived payload or an instruction to fetch it again. Keep the full logical
content and all required rendering/export metadata. List/rank queries never select full snapshot
columns; retrieve them only for authorized article reading/export.

Setting column compression changes future writes, **not** existing stored values; copying a value
with `INSERT ... SELECT` can retain its previous compression. The schema's compression setting is
therefore not proof that every existing snapshot was recompressed. M8 records the actual supported
method and tests representative compressible and incompressible payloads with byte-identical reads;
any optional recompression migration must rewrite in small verified batches with enough disk for
WAL/temp copies. See [PostgreSQL 16 ALTER TABLE](https://www.postgresql.org/docs/16/sql-altertable.html).

Snapshot collection, unbookmark and capture completion lock in the same order (article, reader rows,
snapshot IDs). Set `unreferenced_at` when the last bookmark/Undo reference is released; clear it when
any reference is restored. Before removal expire elapsed Undo pins, then recheck **all** live user
references, remaining pins, pending capture generations and `unreferenced_at ≤ now() - 7 days`.
FKs must reject accidental deletion of referenced data. A pending/failed capture
must never be represented as successful permanent preservation. Capacity alerts include snapshot
logical/stored bytes, growth and backup/restore size; approaching the disk limit alerts the owner
and stops optional new captures cleanly before exhaustion rather than deleting existing bookmarks.

### 5.3 User choice and publication lifecycle

Housekeeping/re-enrichment/model upgrades never turn off/training subscriptions into active ones or
queue a historical feed-wide inference batch implicitly. They obey the same persisted user demand,
activation time and generation as normal ingestion (specs03/05). Explicit selected-article requests
have their own bounded lifecycle; reconciling them never expands their one-article authorization.
Cancel unresolved requests when their 180-day input-retention window expires; preserve their bounded
failure/cancellation record until the purge transaction removes related personal inputs. Save/extract, immutable archive reads
and compression are local operations and cannot trigger a provider merely because content exists.
Publication or retirement follows specs05/08: an eligible user-created card may be published with
creator approval, or after 30 days of creator inactivity under the accepted Q12 moderation policy.
Inactivity is measured from the creator's last activity, not a seven-day consent-request timeout.
Private training/examples are not automatically promoted into the public library, and operational
retention must not erase still-owned private cards or snapshots while publishing copies.

---

## 6. Scheduled jobs (pg-boss cron, all UTC; handlers in `apps/worker/src/handlers/house-*.ts`)

| Job | Schedule | Does |
|---|---|---|
| `feed.schedule` | every minute | spec 03 §3 |
| `house.rescore-degraded` | `*/10 * * * *` | recover the supported 14-day horizon fairly with persisted cursors/budget limits (spec 04 §5), including eligible deferred/exhausted match work and fallback answers; reuse current Call A, and reset terminal attempts only after their blocker changes |
| `house.expire-rules` | `5 * * * *` | delete expired rules; `user.rank {full}` for the affected users |
| `house.purge-auth` | `20 * * * *` | expired login codes and sessions per §5 |
| `house.reconcile` | `*/10 * * * *` | bounded repair of still-authorized inference, due pending/expired-lease `analysis_requests`, pending bookmark capture/outbox/match work and orphaned leases; enqueue rank for due `user_article.next_rank_at`; nightly UTC window also refreshes feed subscribers/cards, cluster counts and `lang_hint` with persistent progress cursors |
| `house.archive` | `15 3 * * *` | archive unprotected read items older than 31 days and enforce the shared-article unread-cap rules in §5 |
| `house.purge-articles` | `30 3 * * *` | delete unreferenced articles older than 90 days (§5), in batches of 5,000. Articles referenced from `eval.*` are never purged |
| `house.purge-bodies` | `45 3 * * *` | §5.2: preserve/verify owned full snapshots, mark eligible snapshots cold, clear redundant unprotected hot text/HTML after 30 days, and collect snapshots unreferenced for 7 days; never erase pending capture inputs |
| `house.purge-engine-calls` | `0 4 * * *` | delete `engine_calls` and expired terminal `analysis_requests` older than 180 days, and `feedback_events` older than 365 days; clean delivered outbox rows and invoke bounded queue-history maintenance per §5 |
| `house.retire-cards` | `30 4 * * *` | set `retired_at` on cards with `visibility <> 'public'` (library and promoted cards are never retired) that have no holders (`user_cards`/`user_labels`) and are not referenced by `eval.rater_cards`; delete their `card_answers` 30 days later |
| `house.purge-users` | `0 5 * * *` | hard-delete users past the 7-day grace period |
| `house.nightly-learn` | `0 1 * * *` | enqueue `user.learn` when the effective training-input hash changed since the last **attempt** (implicit signals, undo/unrate, context and 180-day expiry included); `user.suggest` for active users, per spec 06 (**built in M7-T4**) |
| `house.metrics` | `10 0 * * *` | online metrics (spec 10 §7) |
| `house.alerts` | `*/5 * * * *` | evaluate the alert rules (§6.1) and send **all** operational emails. Other components only record state: `engine.circuit`, `engine.budget_alerts`, `ops.events` |
| `house.reenrich` | on demand (admin changes the active enrich set) | re-enqueue still-demanded articles first seen in the last 7 days, batches of 200 within budget; never opt users in or treat a model upgrade as permission for bulk untrained-feed inference |
| `house.translate-cards` | on demand (`card_text_mode` switched to `english`) | spec 07 §5 |

Every job is idempotent, logs `{job, durationMs, affected, remaining}`, and exposes a counter. Persist
last successful completion/cursor in `settings['house.progress']`. On worker startup, enqueue overdue
housekeeping once per job using its singleton key; cron alone does not replay work missed while the
host was down. Never use an unbounded scan/transaction for all feeds/users/articles. All resulting
rank/learn/re-enrich work uses the transactional outbox, and model-triggering scans respect budget,
active-user/retention checks and current per-user-feed inference demand. Bookmark capture is a
separate local queue and remains available when all inference modes are off.

### 6.1 Alerts (emails to `ADMIN_EMAILS`, de-duplicated: each alert at most once per 6 h until resolved)

`house.alerts` also records resolved transitions and sends one recovery notification per active
incident. Mail transport failure leaves the notification pending; mark `lastSentAt` only after SMTP
acceptance. Bound and redact details, retry with backoff, and keep reporting failures measurable.
A retry may duplicate an email after an uncertain SMTP acknowledgment; do not promise exactly once.

`house.alerts` keeps its state in `settings['alerts.state']` (`{[alertKey]: {firstAt, lastSentAt, active}}`).
It sends through the shared mailer (`packages/shared/src/mail/`, which uses `SMTP_URL`, `MAIL_FROM` and
`MAIL_TRANSPORT`). The worker therefore needs the mail settings too (spec 01 §3).

| Alert | Condition |
|---|---|
| Engine breaker open | `settings['engine.circuit'].typesafe` is `open` with `openedAt` more than 30 min ago, or it is `auth` (immediately) |
| Budget | `settings['engine.budget_alerts']` has `p80At` or `p100At` for today (spec 04 §6) |
| Pipeline backlog | any queue > 5,000 due waiting jobs, oldest due job > 30 min, pending outbox > 5 min, or rising terminal failures; intentional future cooldown jobs are excluded |
| Service health | missing worker/housekeeping heartbeat, repeated OOM/restarts, exhausted DB pool, or external readiness failure; external monitor covers full-host failure |
| Degraded share | more than 20 % of the last hour's new articles degraded |
| Feed failures | more than 10 % of active subscribed feeds errored in the last 24 h |
| Disk | filesystem containing Postgres or backup staging > 80 % full, < 10 GB free, or inode exhaustion; host script reports structured `host_health` capacity/heartbeat events via the bounded ops-event channel, without mounting PGDATA into an app container |
| Backups | the backup script or the restore test reported failure through `POST /api/v1/admin/ops-event` (stored in `settings['ops.events']`), or no `backup_ok` event in the last 26 h |
| Metrics | `for_you` like-rate below `maybe` like-rate for 3 consecutive days (spec 10 §7) |

---

## 7. Security checklist (verified in M8-T6)

- **TLS:** Caddy-managed certificates with HSTS (`max-age=31536000`).
- **Headers** set by Caddy:
  - CSP: `default-src 'self'; img-src 'self' https:; style-src 'self' 'unsafe-inline'; script-src 'self'; connect-src 'self'; object-src 'none'; frame-src 'none'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'`
  - `X-Content-Type-Options: nosniff`
  - `Referrer-Policy: strict-origin-when-cross-origin`
  - `Permissions-Policy: camera=(), microphone=(), geolocation=()`
- **Third-party images:** default blocked; do not create remote `src`/CSS URLs (including feed icons)
  until the user explicitly allows them. Remember an “always allow images from this feed” choice
  per user+feed; it neither enables images for another user nor becomes a global feed property.
  Apply the canonical media-policy precedence and bookmark-origin rules in specs08/09, including
  remembered choice after unsubscribe. Explain that loading discloses IP/request
  metadata to the publisher. Opted-in images use `referrerpolicy="no-referrer"` and `loading="lazy"`,
  HTTPS only, with local placeholders; no-referrer alone is not tracking protection. An authenticated,
  SSRF-safe image proxy is a separate future feature. Login/privacy pages load no third-party media.
- **Session cookie and CSRF:** spec 08 §1–2.
- **Rate limits:** spec 08 §11.
- **SSRF-safe fetcher:** spec 03 §4, with the test suite covering every blocked range and redirects
  into private space.
- **HTML handling:** all third-party HTML is sanitized (spec 03 §6.3). The web client never uses
  `dangerouslySetInnerHTML` except with sanitized fields, and those are sanitized **again** client-side
  with DOMPurify.
- **Database:**
  - the API uses the `feedit_app` role (RLS)
  - the worker's BYPASSRLS role is never used by the API
  - the RLS isolation suite passes (spec 08 §12)
- **Logs:** no secrets, codes, tokens, raw URL queries, provider prompt/response text, login/email
  bodies or exported user data. Redact `Authorization`, cookies and SMTP/DB URLs centrally. Record
  correlation IDs, entity IDs, model/version hashes and bounded error codes; hash/omit user emails.
- **Dependencies:** `pnpm audit --prod` in CI (high/critical fail the build) and Renovate weekly.
- **Model input:** article text sent to Jev and Ollama is public content. Card texts and example titles
  are personal. Document this in the privacy page (`/privacy`, a static page linked from login).

---

## 8. Model and question-set upgrades

1. Run `eval replay` with the new `TYPESAFE_MODEL` or question set (spec 10 §6). It must pass.
2. Set the env or settings value, then restart the worker.
3. New answers carry the new model id. Old answers stay valid until the articles age out.
4. Verify whether feature meanings and answer vocabulary remain compatible. If `feature_spec_sha`
   changes, deactivate incompatible user models and rerank with cards/degraded scoring until retrained;
   a stable feature name alone does not establish compatible semantics. Publish the new active set
   and its outbox work atomically; in-flight old-set answers cannot become active after the switch.
5. A new **enrich** question set triggers `house.reenrich` for still-authorized demand in the last
   7 days, within budget. New model availability never implicitly enables an untrained feed.

---

## 9. Launch checklist (invite-only beta, end of M8)

- [ ] All milestones through M8 are done. G1 decisions are applied in production settings.
- [ ] G1 has an `owner_pilot` or `multi_person_beta` PASS under spec 10 (accepted Q13). A passing
      owner-only pilot is sufficient for this initial beta; additional raters are not a prerequisite. All existing
      quality, cost, security, operational and recovery gates still pass.
- [ ] `TYPESAFE_MODEL` is pinned. The daily budget is set from the G1 recommendation.
- [ ] Off-site encrypted backups and offline keys restore successfully, including roles/RLS,
      deletion-ledger replay and queue recovery; measured RPO/RTO accepted. Every alert and recovery
      transition fired in a drill, including a stopped worker and an unreachable host.
- [ ] The admin account exists. 10 invites were created for testers. The waitlist form works.
- [ ] Starter bundles verified (every feed fetches, languages are correct).
- [ ] Privacy page describes providers, remote images, deletion grace, backup expiry and export;
      contact and `/bot` page published. SMTP SPF/DKIM/DMARC and delivered login mail verified.
- [ ] Load sanity (M8-T8): 20 simulated users × 50 feeds × 10 cards for 1 hour.
      - **Setup:** a generated fixture feed server (1,000 feeds, 5 new items per feed per hour, from
        `packages/testing`) and the fake TypeSafe server with `latencyMs: 300`. The API runs with
        `NODE_ENV=test`, `RATE_LIMITS_ENABLED=false` and `FETCH_ALLOW_PRIVATE=true` in a separate test
      Compose project/DB; never relax production config. `autocannon` simulates list/open/rate traffic.
      - **On the target box:** CPU < 70 %, p95 `GET /articles` < 300 ms, no queue-backlog alert.
      - **On a local production-like stack** (when there is no host access): the same p95 and backlog
        criteria. CPU is reported but not gated.


- [ ] Deploy/rollback drill preserves live reader state; full host reboot starts healthy dependencies,
      catches up missed cron work and drains old outbox intents without duplicate side effects.
- [ ] Two workers pass concurrent ingestion, merge, revision, quota, budget and origin-throttle tests;
      load test uses the actual configured pool/memory budget, and at least one high-latency/failing
      provider scenario proves degraded reading stays available.
- [ ] Production DNS/TLS, system time synchronization, external uptime/backup monitor, alert recipient,
      release owner, incident contact and the maintenance/restore instructions are recorded.
- [ ] Fresh-instance bootstrap and upgrade from the previous schema both pass migration/seed tests;
      runtime images contain required CLIs/artifacts and no service has another role's credentials.

- [ ] User-selected training infers only selected articles, active feeds infer new arrivals, and
      all-off feeds run ingestion/reading/bookmark capture with zero article-provider calls.
- [ ] A bookmarked article's full available text/HTML survives source deletion, cold maintenance,
      unsubscribe and backup restore; export checksum matches, and failed/partial capture is honest.
- [ ] Per-user-feed image opt-in survives reload/unsubscribe without changing another user's policy;
      archived content never fetches remote media automatically just because it was previously saved.
