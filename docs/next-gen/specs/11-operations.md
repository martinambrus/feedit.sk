# Spec 11: Operations: deploy, housekeeping, backups, monitoring, security

Status: **binding**. **Intent:** run the whole service on one self-hosted box with no GPU and minimal
spend. Keep the data safe with tested backups. Clean up after itself automatically, and tell the admin
when something needs a human, before users notice.

---

## 1. Target host

- **Size:** 8 vCPU (dedicated), 16–32 GB RAM, ≥ 160 GB NVMe, Ubuntu LTS. Budget provider; a few tens
  of € per month.
- **Open ports:** only 22 (SSH, key-only), 80 and 443. `ufw` default-deny inbound.
- **Host packages:** Docker Engine + Compose plugin, `unattended-upgrades`, `rclone` (backups),
  `fail2ban` (SSH).

---

## 2. Compose stack (`infra/compose.yml`)

| Service | Image | Memory limit | Notes |
|---|---|---|---|
| `postgres` | `postgres:16` | 6 GB | volume `pgdata`; `infra/postgres/init.sh` mounted into `/docker-entrypoint-initdb.d/` (spec 02 §1.1) with `POSTGRES_PASSWORD` and `FEEDIT_{OWNER,APP,WORKER}_PASSWORD` from `.env`. Config: `shared_buffers=4GB`, `work_mem=32MB`, `maintenance_work_mem=512MB`, `max_connections=100`, `wal_compression=on`. Not published to the host network |
| `migrate` | api image, command `pnpm db:migrate` | 512 MB | runs once per deploy; `api`/`worker` depend on its successful completion |
| `api` | built from `apps/api` | 768 MB | healthcheck `GET /readyz` |
| `worker` | built from `apps/worker` | 1.5 GB (3.5 GB once Laya is enabled) | healthcheck on its metrics port |
| `libretranslate` | `libretranslate/libretranslate:latest` (pin a digest) | 3 GB | `LT_LOAD_ONLY=en,sk,cs`, `LT_DISABLE_WEB_UI=true`. Compose profile `translate`, started only when some language mode is `translate` or `card_text_mode = english` |
| `caddy` | `caddy:2` | 256 MB | TLS (Let's Encrypt), serves `apps/web/dist`, reverse-proxies `/api/*` → `api:3000`, security headers (§7) |

- **Logging:** the Docker `json-file` driver with `max-size=20m`, `max-file=5` for every service.
- **Restart policy:** `unless-stopped`.
- **Compose project name:** every compose file sets a top-level `name:` (`feedit-prod`, `feedit-dev`,
  `feedit-test`), and host ports come from env, so stacks never replace each other's containers.
- **Secrets:** `/opt/feedit/.env` (mode 600): the superuser password and the passwords for the three roles, `TYPESAFE_API_KEY`,
  `OLLAMA_API_KEY`, `SMTP_URL`, `SESSION_PEPPER`, `METRICS_TOKEN`, `ADMIN_EMAILS`.

---

## 3. Deploy (`infra/scripts/deploy.sh`)

1. `git fetch && git checkout <tag>` (deploys are tags `vYYYY.MM.DD-N`).
2. `docker compose build` (api/worker/web images; the web build output is copied into the Caddy
   volume).
3. `docker compose run --rm migrate`. Migrations must be **backward compatible** with the previous
   release: expand/contract, never drop a column in the same release that stops using it.
4. `docker compose up -d api worker caddy`.
5. Smoke check: `curl -fsS https://<host>/api/v1/readyz`. On failure, roll back to the previous tag
   (step 1 with the old tag, then skip migrations).

---

## 4. Backups (`infra/scripts/backup.sh`, host cron at 02:30)

- `docker compose exec -T postgres pg_dump -U postgres -Fc feedit` (as the **superuser**, so every
  schema and all RLS-protected rows are included, `pgboss` and `eval` too) into `/opt/feedit/backups/`,
  then `rclone copy` to an S3-compatible bucket (off-site).
- **Retention:** 7 daily, 4 weekly (Sunday), 6 monthly (1st). Enforced both locally and in the bucket.
- **Weekly restore test** (`infra/scripts/restore-test.sh`, Sunday 04:00):
  - restore the latest dump into a throwaway `postgres:16` container
  - run a sanity query set: row counts of the main tables are > 0, and the latest `articles.first_seen_at`
    is < 24 h old
  - email the admins the result
- A failed backup or restore test → an alert (§6).
- Not in the database: `.env` is backed up separately, encrypted with `age`, to the same bucket on
  change.

---

## 5. Retention (enforced by housekeeping, §6)

| Data | Kept |
|---|---|
| `articles` (+ feed_items, facets, answers) | 90 days after `first_seen_at`, **unless** any user has bookmarked, rated or labelled it (then kept while that user exists) |
| `article_bodies.body_text` | 30 days (`body_lead` is kept with the article) |
| `article_translations` | with the article |
| `engine_calls` | 180 days (aggregated into `usage_daily` live) |
| `usage_daily` | forever (small) |
| `user_article` reader state | while the user exists. Read items are archived after 31 days; unread items are capped at 1,000 per user per feed (older ones archived) |
| `feedback_events` | 365 days |
| `login_codes` | 1 day after expiry |
| `sessions` | 30 days after expiry or revocation |
| deleted users | hard-deleted 7 days after `DELETE /me` |
| `eval` schema | forever (it is the golden set) |

---

## 6. Scheduled jobs (pg-boss cron, all UTC; handlers in `apps/worker/src/handlers/house-*.ts`)

| Job | Schedule | Does |
|---|---|---|
| `feed.schedule` | every minute | spec 03 §3 |
| `house.rescore-degraded` | `*/10 * * * *` | spec 04 §5 |
| `house.expire-rules` | `5 * * * *` | delete expired rules; `user.rank {full}` for the affected users |
| `house.purge-auth` | `20 * * * *` | expired login codes and sessions per §5 |
| `house.reconcile` | `0 2 * * *` | `refresh_feed_subscribers` + `refresh_feed_cards` for all feeds; recompute `feeds.lang_hint` |
| `house.archive` | `15 3 * * *` | archive read items older than 31 days (not bookmarked); enforce the 1,000 unread cap per user per feed |
| `house.purge-articles` | `30 3 * * *` | delete unreferenced articles older than 90 days (§5), in batches of 5,000. Articles referenced from `eval.*` are never purged |
| `house.purge-bodies` | `45 3 * * *` | `body_text = NULL` for extractions older than 30 days |
| `house.purge-engine-calls` | `0 4 * * *` | delete `engine_calls` older than 180 days |
| `house.retire-cards` | `30 4 * * *` | set `retired_at` on non-library cards with no holders (`user_cards`/`user_labels`) that are not referenced by `eval.rater_cards`; delete their `card_answers` 30 days later |
| `house.purge-users` | `0 5 * * *` | hard-delete users past the 7-day grace period |
| `house.nightly-learn` | `0 1 * * *` | enqueue `user.learn` for users with explicit labels newer than their last training, and `user.suggest` for users active in the last 7 days (**built in M7-T4**) |
| `house.metrics` | `10 0 * * *` | online metrics (spec 10 §7) |
| `house.alerts` | `*/5 * * * *` | evaluate the alert rules (§6.1) and send **all** operational emails. Other components only record state: `engine.circuit`, `engine.budget_alerts`, `ops.events` |
| `house.reenrich` | on demand (admin changes the active enrich set) | re-enqueue `article.enrich` for articles first seen in the last 7 days, in batches of 200, stopping while the budget is exhausted |
| `house.translate-cards` | on demand (`card_text_mode` switched to `english`) | spec 07 §5 |

Every job is idempotent, logs `{job, durationMs, affected}`, and exposes a counter.

### 6.1 Alerts (emails to `ADMIN_EMAILS`, de-duplicated: each alert at most once per 6 h until resolved)

`house.alerts` keeps its state in `settings['alerts.state']` (`{[alertKey]: {firstAt, lastSentAt, active}}`).
It sends through the shared mailer (`packages/shared/src/mail/`, which uses `SMTP_URL`, `MAIL_FROM` and
`MAIL_TRANSPORT`). The worker therefore needs the mail settings too (spec 01 §3).

| Alert | Condition |
|---|---|
| Engine breaker open | `settings['engine.circuit'].typesafe` is `open` with `openedAt` more than 30 min ago, or it is `auth` (immediately) |
| Budget | `settings['engine.budget_alerts']` has `p80At` or `p100At` for today (spec 04 §6) |
| Pipeline backlog | any queue with more than 5,000 waiting jobs, or its oldest job older than 30 min |
| Degraded share | more than 20 % of the last hour's new articles degraded |
| Feed failures | more than 10 % of active subscribed feeds errored in the last 24 h |
| Disk | Postgres volume more than 80 % full (checked from inside the worker with `statfs` on a mounted path) |
| Backups | the backup script or the restore test reported failure through `POST /api/v1/admin/ops-event` (stored in `settings['ops.events']`), or no `backup_ok` event in the last 26 h |
| Metrics | `for_you` like-rate below `maybe` like-rate for 3 consecutive days (spec 10 §7) |

---

## 7. Security checklist (verified in M8-T6)

- **TLS:** Caddy-managed certificates with HSTS (`max-age=31536000`).
- **Headers** set by Caddy:
  - CSP: `default-src 'self'; img-src 'self' https: data:; style-src 'self' 'unsafe-inline'; script-src 'self'; connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'`
  - `X-Content-Type-Options: nosniff`
  - `Referrer-Policy: strict-origin-when-cross-origin`
  - `Permissions-Policy: camera=(), microphone=(), geolocation=()`
- **Third-party images:** rendered with `referrerpolicy="no-referrer"` and `loading="lazy"`. An image
  proxy (FeedIt todo) is backlog.
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
- **Logs:** no secrets, codes, tokens or email bodies.
- **Dependencies:** `pnpm audit --prod` in CI (high/critical fail the build) and Renovate weekly.
- **Model input:** article text sent to Jev and Ollama is public content. Card texts and example titles
  are personal. Document this in the privacy page (`/privacy`, a static page linked from login).

---

## 8. Model and question-set upgrades

1. Run `eval replay` with the new `TYPESAFE_MODEL` or question set (spec 10 §6). It must pass.
2. Set the env or settings value, then restart the worker.
3. New answers carry the new model id. Old answers stay valid until the articles age out.
4. Personal models keep working: the feature spec is unchanged, and they retrain nightly.
5. A new **enrich** question set triggers `house.reenrich` for the last 7 days, within the budget.

---

## 9. Launch checklist (invite-only beta, end of M8)

- [ ] All milestones through M8 are done. G1 decisions are applied in production settings.
- [ ] `TYPESAFE_MODEL` is pinned. The daily budget is set from the G1 recommendation.
- [ ] Backups run, and one restore test passed. Every alert fired once in a drill (breaker, budget,
      backlog, backup).
- [ ] The admin account exists. 10 invites were created for testers. The waitlist form works.
- [ ] Starter bundles verified (every feed fetches, languages are correct).
- [ ] Privacy page and contact email published. SMTP SPF/DKIM verified (mail-tester score ≥ 9/10).
- [ ] Load sanity (M8-T8): 20 simulated users × 50 feeds × 10 cards for 1 hour.
      - **Setup:** a generated fixture feed server (1,000 feeds, 5 new items per feed per hour, from
        `packages/testing`) and the fake TypeSafe server with `latencyMs: 300`. The API runs with
        `RATE_LIMITS_ENABLED=false`, and `autocannon` scripts simulate readers (list, open, rate).
      - **On the target box:** CPU < 70 %, p95 `GET /articles` < 300 ms, no queue-backlog alert.
      - **On a local production-like stack** (when there is no host access): the same p95 and backlog
        criteria. CPU is reported but not gated.
