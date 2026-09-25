# Spec 02: Data model (PostgreSQL 16)

Status: **binding**. **Intent:** the shared article layer (feeds, articles, model answers) is global and
paid for once. Reader state is tenant data protected by row-level security; auth/control-plane
exceptions are explicit in §1.2. Normalized model answers are retained within the data-retention window
so changing a ranking threshold does not itself require a new model call.

Conventions: `snake_case`; `bigint GENERATED ALWAYS AS IDENTITY` primary keys (users use UUID v7);
`timestamptz` everywhere, defaulting to `now()`. Enumerations are `text` columns with `CHECK`
constraints, not Postgres enums, so they are easy to migrate. Every foreign key states its `ON DELETE`
behaviour.

**Wire types and update semantics.** Every `bigint`/identity/UUID is a string in JavaScript DTOs;
never pass a database ID through `Number`. SQL timestamps use UTC; display timezone is a preference.
`DEFAULT now()` applies only on insert: each owning repository explicitly sets its `updated_at` or
`answered_at` on update. JSON objects have shared zod schemas, with finite numeric values, size limits
and unknown-key rejection on writes; SQL checks below are the database floor, not a replacement.

The Drizzle schema in `packages/db/src/schema/*.ts` must produce exactly this DDL. RLS policies,
grants, functions and trigram indexes are hand-written SQL migrations. The database, roles and
extensions come from `infra/postgres/init.sh` (§1.1).

**Schema parity test.** `packages/db/test/schema-parity.int.test.ts` reads the migrated catalog and
compares it with the checked-in `packages/db/test/expected-schema.json`:
- tables, columns, types, nullability, defaults
- check constraints, indexes, foreign keys with their `ON DELETE`
- RLS policies, grants, functions, constraint triggers and generated identity properties

The JSON is written once, by hand, from this spec. Any later schema change updates it in the same
commit.

---

## 1. Bootstrap, roles and privileges

### 1.1 Cluster bootstrap (`infra/postgres/init.sh`)

The official `postgres:16` image runs `*.sh` files from `/docker-entrypoint-initdb.d/` as the `postgres`
superuser on first start. (The shell wrapper supplies environment values as psql variables.) The script takes the
role passwords from the env vars `FEEDIT_OWNER_PASSWORD`, `FEEDIT_APP_PASSWORD` and
`FEEDIT_WORKER_PASSWORD` and runs:

```bash
psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" \
     -v owner_pw="$FEEDIT_OWNER_PASSWORD" -v app_pw="$FEEDIT_APP_PASSWORD" -v worker_pw="$FEEDIT_WORKER_PASSWORD" <<'SQL'
CREATE ROLE feedit_owner  LOGIN PASSWORD :'owner_pw'  BYPASSRLS;  -- runs migrations; owns every object and the SECURITY DEFINER functions (§6)
CREATE ROLE feedit_app    LOGIN PASSWORD :'app_pw';               -- API; RLS enforced
CREATE ROLE feedit_worker LOGIN PASSWORD :'worker_pw' BYPASSRLS;  -- worker, eval CLI, housekeeping
CREATE DATABASE feedit OWNER feedit_owner;
\connect feedit
CREATE EXTENSION IF NOT EXISTS citext;
CREATE EXTENSION IF NOT EXISTS pg_trgm;
CREATE EXTENSION IF NOT EXISTS pgcrypto;
REVOKE CREATE ON SCHEMA public FROM PUBLIC;
ALTER SCHEMA public OWNER TO feedit_owner;
REVOKE ALL ON DATABASE feedit FROM PUBLIC;
GRANT CONNECT ON DATABASE feedit TO feedit_app, feedit_worker;
SQL
```

`feedit_owner` must have `BYPASSRLS`. The SECURITY DEFINER functions in §6 run as the owner and must
see every tenant's rows. `FORCE ROW LEVEL SECURITY` would otherwise apply to the owner too.

**Test databases** (`infra/compose.test.yml` runs the same `init.sh`). The helper in
`packages/testing` connects with `TEST_ADMIN_DATABASE_URL` (the superuser):

1. **Template per schema version:** `feedit_template_<h>`, where `h` = the first 12 hex digits of
   `sha256(sorted migration paths + their bytes + journal bytes + pinned pg-boss version)`. A changed migration therefore yields a new
   template, and parallel branches with different migrations never share one.
2. **Creation** runs under `pg_advisory_lock(hashtext('feedit_template'))`, so parallel test runs never
   race. If the template is missing:
   - `CREATE DATABASE feedit_template_<h> OWNER feedit_owner`
   - as the superuser, run three statements: `CREATE EXTENSION IF NOT EXISTS citext;`,
     `CREATE EXTENSION IF NOT EXISTS pg_trgm;`, `CREATE EXTENSION IF NOT EXISTS pgcrypto;`, then
     apply the same schema ownership, database ACL and `public` CREATE revocation as production
   - the full **migrate job** as `feedit_owner`: Drizzle migrations, the pg-boss schema and the queues
     (§1.2)
3. **Per worktree and package:** `feedit_test_<worktree-hash>_<package>_<run-id>`, created with
   `CREATE DATABASE … TEMPLATE feedit_template_<h> OWNER feedit_owner` (dropped and recreated per run).
4. **E2E and eval dry-run databases** are created the same way, from the template for the current
   journal, and are then **seeded** (`pnpm db:seed`) before any process uses them.

The template lock is held on a dedicated live connection across creation and migration; `CREATE
DATABASE` runs outside a transaction. Disconnect every template connection before cloning. Publish a
ready marker only after successful migration; delete an incomplete template before retrying. Names
are sanitized, bounded to PostgreSQL's 63-byte identifier limit and quoted as identifiers. Cleanup may
drop only databases created by this test run. Parallel sessions/packages never share a database.

### 1.2 Privileges (first migration, run as `feedit_owner`)

**Defaults**, which cover every later migration automatically:

```sql
GRANT USAGE ON SCHEMA public TO feedit_app, feedit_worker;
-- No default table privileges for feedit_app: every new API-visible table is reviewed explicitly.
ALTER DEFAULT PRIVILEGES FOR ROLE feedit_owner IN SCHEMA public GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO feedit_worker;
ALTER DEFAULT PRIVILEGES FOR ROLE feedit_owner IN SCHEMA public GRANT USAGE, SELECT ON SEQUENCES TO feedit_worker;
ALTER DEFAULT PRIVILEGES FOR ROLE feedit_owner REVOKE EXECUTE ON FUNCTIONS FROM PUBLIC;
```

**Explicit SELECT allowlist for `feedit_app`:** `users`, `settings`, `login_codes`, `sessions`,
`invites`, `waitlist`, `origin_fetch_state`, `feeds`, `story_clusters`, `articles`, `feed_items`, `article_aliases`,
`article_bodies`, `article_translations`, `question_sets`, `article_facets`, `topics`, `interest_cards`,
`card_answers`, `article_topics_l2`, `usage_daily`, and the tables in §4. Add grants only after the
corresponding table and its required RLS policies exist, in the same migration transaction. The API
has no direct read/write access to `engine_calls`, `engine_reservations`, `match_queue` or `feed_cards`;
admin summaries use the approved SQL functions/aggregates. No sensitive prompt text goes in logs.

Grant `USAGE` on identity sequences only for tables the API may insert into below; new worker-only
sequences have no default API grant.

**Explicit write privileges for `feedit_app`.** Every migration that adds a table appends its row here
(and to the migration):

| Table | `feedit_app` may | Needed for |
|---|---|---|
| `users` | `INSERT, UPDATE` | signup, `PATCH /me`, soft delete, `invites_left`, `last_active_at`, admin edits |
| `settings` | `INSERT, UPDATE` | admin settings, breaker reset request, alert state |
| `login_codes`, `sessions`, `invites`, `waitlist` | `INSERT, UPDATE, DELETE` | auth, invites |
| `origin_fetch_state` | `INSERT, UPDATE` | safeFetch repository alone coordinates per-origin limits; never exposed by API |
| `feeds` | `INSERT`; `UPDATE (min_interval_s, fetch_options, status, consecutive_errors, first_error_at, quarantined_until, quarantine_count, next_fetch_at, subscriber_count, updated_at)` | subscribe, admin reset |
| `story_clusters` | `INSERT, UPDATE` | mute-story creates a cluster |
| `articles` | `UPDATE (story_cluster_id)` | mute-story |
| `interest_cards` | `INSERT`; `UPDATE (retired_at, title, topic_ids, i18n, slug, visibility)` | card create/reuse (un-retire), admin library and promotion (`shared` → `public`). **Never** the text or examples: cards are immutable (spec 05 §5.1) |
| `feedback_events` | `INSERT` | reader actions (append-only; rows disappear only through `ON DELETE CASCADE` when the worker purges a user) |
| `subscriptions`, `user_cards`, `user_labels`, `user_rules`, `api_mutations` | `INSERT, UPDATE, DELETE` | RLS applies; idempotency records are repository-internal |
| `user_article` | `INSERT (user_id, article_id, opened_at, read_at, rating, reason, rated_at, dwell_ms, bookmarked_at, archived_at, label_ids, feedback_prompted_at, state_version)`; `UPDATE` on those reader-state columns except the primary key, plus `label_suggestions` | rating/read/bookmark actions; ranking columns are worker-only |
| `card_suggestions` | `UPDATE (dismissed_at)` | dismiss suggestion |
| `user_models` | no writes | worker alone trains and activates models |
| `job_outbox` | `INSERT` only | durable job intent; requester RLS (§5), no API relay privileges |
| `drizzle.__drizzle_migrations` | `SELECT` (with `USAGE ON SCHEMA drizzle`) | `/readyz` |

**pg-boss** (pg-boss 10; the schema is created by a migration and never by a running process):

```sql
-- migration: execute the construction plans for the pinned pg-boss version as feedit_owner, then:
GRANT USAGE ON SCHEMA pgboss TO feedit_worker;
GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA pgboss TO feedit_worker;
GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA pgboss TO feedit_worker;
GRANT EXECUTE ON ALL FUNCTIONS IN SCHEMA pgboss TO feedit_worker;
ALTER DEFAULT PRIVILEGES FOR ROLE feedit_owner IN SCHEMA pgboss
  GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO feedit_worker;
ALTER DEFAULT PRIVILEGES FOR ROLE feedit_owner IN SCHEMA pgboss
  GRANT USAGE, SELECT ON SEQUENCES TO feedit_worker;
ALTER DEFAULT PRIVILEGES FOR ROLE feedit_owner IN SCHEMA pgboss
  GRANT EXECUTE ON FUNCTIONS TO feedit_worker;
```

The API writes `job_outbox`, never pg-boss tables or queue payloads. Readiness/backlog queries use a
restricted SECURITY DEFINER function returning only queue name and aggregate state counts, implemented
against the pinned pg-boss catalog at M0 and covered by parity tests; no job payload leaves it.

**Queues:**
- Created by the migrate job (as the owner) with `createQueue(name, options)` for every entry of
  `packages/shared/src/jobs.ts` (spec 03 §2), so per-queue partitions are owned by `feedit_owner` and
  covered by the default privileges.
- The worker starts pg-boss with `migrate: false`, supervises and runs cron schedules plus the
  outbox relay. The API has no pg-boss client or credentials beyond its own database role.
- If the pinned pg-boss version differs in these mechanics, keep the requirement: the owner creates the
  schema and queues, the API can only write authorized outbox intents and read aggregate counts, and
  the worker alone relays, consumes and supervises jobs. Log
  the adaptation (spec 01 §9).

The API is a trusted server, not a database client exposed to browsers. Auth/bootstrap tables
(`users`, `login_codes`, `sessions`, `invites`, `waitlist`) and control-plane tables (`settings`,
`usage_daily`) intentionally have no tenant RLS: login must resolve a token before a tenant exists.
Only named auth/admin repositories can access them, with explicit ownership/role checks and DTO
allowlists. This is an exception to the per-user RLS claim, not permission to use generic CRUD.
Neither a request body nor arbitrary SQL may supply `app.user_id`; derive it from the verified session.
`feedit_app`/`feedit_worker` are never members of `feedit_owner` and cannot create roles or databases.

## 2. Settings and accounts

```sql
CREATE TABLE settings (
  key         text PRIMARY KEY,               -- see the key registry below
  value       jsonb NOT NULL,
  updated_at  timestamptz NOT NULL DEFAULT now(),
  updated_by  uuid NULL
);

CREATE TABLE users (
  id              uuid PRIMARY KEY,           -- UUID v7, generated in code
  email           citext NOT NULL UNIQUE,
  display_name    text NULL,
  locale          text NOT NULL DEFAULT 'en' CHECK (locale IN ('en','sk')),
  timezone        text NOT NULL DEFAULT 'Europe/Bratislava',
  role            text NOT NULL DEFAULT 'user' CHECK (role IN ('user','admin')),
  plan            text NOT NULL DEFAULT 'beta',   -- key into the plan table in spec 08 §6
  invites_left    int  NOT NULL DEFAULT 3 CHECK (invites_left >= 0),
  rank_revision   bigint NOT NULL DEFAULT 0 CHECK (rank_revision >= 0),
  suggest_lease_token uuid NULL,
  suggest_lease_until timestamptz NULL,
  last_suggested_at timestamptz NULL,              -- last admitted attempt, not only success
  preferences     jsonb NOT NULL DEFAULT '{}',    -- schema: spec 08 §3.1
  created_at      timestamptz NOT NULL DEFAULT now(),
  last_active_at  timestamptz NULL,
  deleted_at      timestamptz NULL,               -- soft delete for 7 days, then hard delete (spec 11)
  CHECK ((suggest_lease_token IS NULL) = (suggest_lease_until IS NULL))
);

ALTER TABLE settings ADD CONSTRAINT settings_updated_by_fk
  FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL;

CREATE TABLE login_codes (
  id            bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  email         citext NOT NULL,
  challenge_nonce uuid NOT NULL UNIQUE,            -- random UUID generated before hashing/inserting
  code_hash     text NOT NULL,                    -- HMAC-SHA256(pepper, canonical nonce/email/code tuple); spec 08
  purpose       text NOT NULL CHECK (purpose IN ('login','signup')),
  login_user_id uuid NULL REFERENCES users(id) ON DELETE CASCADE,
  invite_code   text NULL,
  locale        text NULL,                        -- locale requested at signup
  expires_at    timestamptz NOT NULL,
  attempts      int NOT NULL DEFAULT 0 CHECK (attempts BETWEEN 0 AND 5),
  consumed_at   timestamptz NULL,
  requested_ip  inet NULL,
  created_at    timestamptz NOT NULL DEFAULT now(),
  CHECK ((purpose = 'login') = (login_user_id IS NOT NULL)),
  CHECK (expires_at > created_at)
);
CREATE INDEX login_codes_email_idx ON login_codes (email, created_at DESC);
CREATE UNIQUE INDEX login_codes_active_email_idx ON login_codes (email) WHERE consumed_at IS NULL;

CREATE TABLE sessions (
  id            bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  user_id       uuid NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  token_hash    text NOT NULL UNIQUE,             -- sha256 of the 32-byte random token
  user_agent    text NULL,
  ip            inet NULL,
  created_at    timestamptz NOT NULL DEFAULT now(),
  last_seen_at  timestamptz NOT NULL DEFAULT now(),
  expires_at    timestamptz NOT NULL,
  revoked_at    timestamptz NULL
);
CREATE INDEX sessions_user_idx ON sessions (user_id);

CREATE TABLE invites (
  code        text PRIMARY KEY,                   -- 10 chars, Crockford base32
  created_by  uuid NULL REFERENCES users(id) ON DELETE SET NULL,
  email       citext NULL,                        -- optional: bound to one address
  note        text NULL,
  created_at  timestamptz NOT NULL DEFAULT now(),
  expires_at  timestamptz NOT NULL,
  used_by     uuid NULL REFERENCES users(id) ON DELETE SET NULL,
  used_at     timestamptz NULL
);

CREATE TABLE waitlist (
  id           bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  email        citext NOT NULL UNIQUE,
  locale       text NOT NULL DEFAULT 'en' CHECK (locale IN ('en','sk')),
  note         text NULL,
  created_at   timestamptz NOT NULL DEFAULT now(),
  invited_at   timestamptz NULL,
  invite_code  text NULL REFERENCES invites(code) ON DELETE SET NULL
);
```


**Settings key registry.** Every key has a zod schema in `packages/shared/src/settings.ts`. Readers
fall back to the listed default when the row is missing. Only the keys marked *admin* can be written
through `PATCH /admin/settings`.

| Key | Value | Default when missing | Written by |
|---|---|---|---|
| `engine.daily_budget_usd` | number | env `DAILY_BUDGET_USD` | admin, `eval apply-g1` |
| `engine.llm_daily_cap` | int | 200 | admin |
| `engine.prefilter_enabled` | boolean | false | admin, only after G1 recall validation |
| `engine.circuit` | `{typesafe: Breaker, llm: Breaker, resetRequested: {typesafe?: iso, llm?: iso}}` with `Breaker = {state: 'closed'\|'open'\|'half_open'\|'auth', openedAt?, openUntil?, reopenCount, probeToken?: uuid, probeUntil?: iso}` | all closed | worker routers (state); admin (reset request only) |
| `engine.budget_alerts` | `{day: 'YYYY-MM-DD', p80At?: iso, p100At?: iso}` | — | worker router (records crossings only; spec 04 §6) |
| `engine.laya` | `{enrich?: string[]}` (language codes) | `{}` | admin (M9) |
| `language_modes` | `{[lang]: 'native'\|'translate'}` | env `LANGUAGE_MODES` | admin, `apply-g1` |
| `card_text_mode` | `'as_written'\|'english'` | `'as_written'` | admin, `apply-g1` |
| `translate.tier2_daily_cap` | int | 300 | admin, `apply-g1` |
| `ranker.thresholds` | deep partial of `RankerConfig` (spec 06 §11) | `{}` | admin, `apply-g1` |
| `ranker.settings_version` | int | 0 | bumped by the API on ranking-relevant settings changes, and by `eval apply-g1` (spec 06 §7) |
| `question_sets.active` | `{enrich?: stringId, match?: stringId, cluster?: stringId, suggest?: stringId}` | `{}` | seed (only when a kind is absent), admin |
| `signup_mode` | `'invite'\|'open'\|'closed'` | env `SIGNUP_MODE` | admin |
| `ops.events` | `[{kind, detail, at}]`, the last 50 | `[]` | `POST /admin/ops-event` |
| `house.progress` | `{[job]: {cursor?: JsonValue, updatedAt: iso, version: int, completedAt?: iso}}`; per-job cursor schema registered in shared jobs; `completedAt` advances only after a full successful pass | `{}` | housekeeping jobs; startup catch-up (spec 11 §6) |
| `alerts.state` | `{[alertKey]: {firstAt, lastSentAt, active}}` | `{}` | `house.alerts` |
| `metrics.daily.<YYYY-MM-DD>` | metrics JSON (spec 10 §7) | — | `house.metrics` |
| `worker.heartbeat` | `{[processId]: {at: iso, queues: string[], evalIngestOnly: boolean}}` | `{}` | every worker process, every 30 s (entries older than 1 h are pruned). `eval ingest-sample` needs an entry younger than 90 s with `evalIngestOnly = true` (spec 10 §2.1) |

`pnpm db:seed` inserts **only** `card_text_mode` and `question_sets.active = {}` when they are missing.
Keys with an env fallback are never seeded, so the env default stays effective until an admin sets a
value.

---

## 3. Shared article layer (private card exceptions use RLS)

```sql
CREATE TABLE origin_fetch_state (                 -- shared safe-fetch coordination, spec 03
  origin        text PRIMARY KEY,                 -- normalized scheme://host:port
  next_start_at timestamptz NOT NULL DEFAULT now(),
  blocked_until timestamptz NULL,
  leases        jsonb NOT NULL DEFAULT '[]',       -- [{token: uuid, expires_at: iso}], at most two
  CHECK (jsonb_typeof(leases) = 'array' AND jsonb_array_length(leases) <= 2)
);

CREATE TABLE feeds (
  id                 bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  url                text NOT NULL UNIQUE,        -- canonical feed URL (spec 03 §5)
  merged_into_id     bigint NULL REFERENCES feeds(id) ON DELETE RESTRICT, -- retired feed identity
  site_url           text NULL,
  title              text NULL,
  description        text NULL,
  icon_url           text NULL,
  lang_hint          text NULL,                   -- ISO 639-1, from <language> or majority of detected items
  status             text NOT NULL DEFAULT 'active'
                       CHECK (status IN ('active','quarantined','dead','paused')),
  etag               text NULL,
  last_modified      text NULL,
  fetch_interval_s   int  NOT NULL DEFAULT 900 CHECK (fetch_interval_s > 0),
  min_interval_s     int  NOT NULL DEFAULT 900 CHECK (min_interval_s > 0),   -- min over subscribers' plans (spec 08 §6)
  next_fetch_at      timestamptz NOT NULL DEFAULT now(),
  last_fetch_at      timestamptz NULL,
  last_success_at    timestamptz NULL,
  last_new_item_at   timestamptz NULL,
  consecutive_errors int NOT NULL DEFAULT 0,
  consecutive_empty  int NOT NULL DEFAULT 0,
  quarantine_count   int NOT NULL DEFAULT 0,
  total_fetches      int NOT NULL DEFAULT 0,
  total_errors       int NOT NULL DEFAULT 0,
  total_empty        int NOT NULL DEFAULT 0,
  last_error_code    text NULL,
  last_error         text NULL,
  last_error_at      timestamptz NULL,
  first_error_at     timestamptz NULL,            -- start of the current error streak
  quarantined_until  timestamptz NULL,
  subscriber_count   int NOT NULL DEFAULT 0 CHECK (subscriber_count >= 0),
  publish_stats      jsonb NOT NULL DEFAULT '{}', -- {recent_gaps_s: int[≤20], items_7d: int}
  fetch_options      jsonb NOT NULL DEFAULT '{}', -- {user_agent?: string, translate_strong?: boolean}
  created_at         timestamptz NOT NULL DEFAULT now(),
  updated_at         timestamptz NOT NULL DEFAULT now(),
  CHECK (merged_into_id IS NULL OR (merged_into_id <> id AND status = 'dead'))
);
CREATE INDEX feeds_due_idx ON feeds (next_fetch_at) WHERE subscriber_count > 0 AND status IN ('active','quarantined');

CREATE TABLE story_clusters (
  id                         bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  representative_article_id  bigint NULL,          -- FK added after articles exists
  size                       int NOT NULL DEFAULT 1 CHECK (size >= 0),
  created_at                 timestamptz NOT NULL DEFAULT now(),
  updated_at                 timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE articles (
  id               bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  url              text NULL,                      -- navigable HTTP(S) best link; null for linkless items
  canonical_url    text NOT NULL,                  -- spec 03 §5
  url_key          text NOT NULL UNIQUE,           -- canonical_url WITH scheme; stable urn:feedit:<feed_id>:<sha256(identity)> if linkless
  title            text NOT NULL,
  title_norm       text NOT NULL,                  -- spec 03 §6.1
  author           text NULL,
  categories       text[] NOT NULL DEFAULT '{}',
  excerpt          text NULL,                      -- plain text, ≤ 2,000 chars
  excerpt_html     text NULL,                      -- sanitized, ≤ 10,000 chars
  image_url        text NULL,
  published_at     timestamptz NULL,
  first_seen_at    timestamptz NOT NULL DEFAULT now(),
  lang             text NULL,                      -- ISO 639-1 or 'und'
  lang_confidence  real NULL CHECK (lang_confidence BETWEEN 0 AND 1),
  word_count       int NULL CHECK (word_count >= 0),
  content_hash     text NOT NULL,                  -- spec 03 §6.2
  content_revision bigint NOT NULL DEFAULT 1 CHECK (content_revision > 0),
  story_cluster_id bigint NULL REFERENCES story_clusters(id) ON DELETE SET NULL,
  pipeline_state   text NOT NULL DEFAULT 'ingested'
                     CHECK (pipeline_state IN ('ingested','stale','extracted','translated','enriched',
                                               'matched','degraded','failed')),
  enrich_engine    text NULL,                      -- engine that produced the active facets
  updated_at       timestamptz NOT NULL DEFAULT now()
);
ALTER TABLE story_clusters ADD CONSTRAINT story_clusters_rep_fk
  FOREIGN KEY (representative_article_id) REFERENCES articles(id) ON DELETE SET NULL;
CREATE INDEX articles_first_seen_idx ON articles (first_seen_at DESC);
CREATE INDEX articles_title_trgm_idx ON articles USING gin (title_norm gin_trgm_ops);
CREATE INDEX articles_cluster_idx ON articles (story_cluster_id) WHERE story_cluster_id IS NOT NULL;
CREATE INDEX articles_state_idx ON articles (pipeline_state, first_seen_at);

CREATE TABLE feed_items (                          -- which feeds carried which article
  feed_id        bigint NOT NULL REFERENCES feeds(id) ON DELETE CASCADE,
  article_id     bigint NOT NULL REFERENCES articles(id) ON DELETE CASCADE,
  guid           text NULL,
  first_seen_at  timestamptz NOT NULL DEFAULT now(),
  PRIMARY KEY (feed_id, article_id)
);
CREATE INDEX feed_items_article_idx ON feed_items (article_id);
CREATE INDEX feed_items_feed_time_idx ON feed_items (feed_id, first_seen_at DESC);
CREATE UNIQUE INDEX feed_items_guid_idx ON feed_items (feed_id, guid) WHERE guid IS NOT NULL;

CREATE TABLE article_aliases (                     -- other URLs that resolve to the same article
  url_key     text PRIMARY KEY,
  article_id  bigint NOT NULL REFERENCES articles(id) ON DELETE CASCADE,
  source      text NOT NULL CHECK (source IN ('feed_link','redirect','rel_canonical','near_duplicate'))
);

CREATE TABLE article_bodies (
  article_id         bigint PRIMARY KEY REFERENCES articles(id) ON DELETE CASCADE,
  article_revision   bigint NOT NULL CHECK (article_revision > 0),
  resolved_url       text NULL,
  status             text NOT NULL CHECK (status IN ('ok','skipped','failed','blocked','too_large','not_html')),
  http_status        int NULL,
  body_text          text NULL,                    -- ≤ 100,000 chars; purged after 30 days (spec 11)
  body_lead          text NULL,                    -- ≤ 1,500 chars; kept
  extractor_version  text NOT NULL,
  error              text NULL,
  extracted_at       timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE article_translations (
  article_id   bigint NOT NULL REFERENCES articles(id) ON DELETE CASCADE,
  article_revision bigint NOT NULL CHECK (article_revision > 0),
  source_sha256 text NOT NULL,                     -- exact source text sent to translation
  target_lang  text NOT NULL DEFAULT 'en',
  engine       text NOT NULL CHECK (engine IN ('libretranslate','ollama')),
  model        text NULL,
  source_lang  text NOT NULL,
  title        text NULL,
  excerpt      text NULL,
  body_lead    text NULL,
  quality      text NOT NULL CHECK (quality IN ('ok','weak','fail')),
  quality_detail jsonb NOT NULL DEFAULT '{}',
  created_at   timestamptz NOT NULL DEFAULT now(),
  PRIMARY KEY (article_id, target_lang, engine)
);
```

### 3.1 Model answers (versioned caches and immutable call audit)

```sql
CREATE TABLE question_sets (
  id          bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  kind        text NOT NULL CHECK (kind IN ('enrich','match','cluster','suggest')),
  version     text NOT NULL UNIQUE,              -- e.g. 'enrich-v1'; never reuse for new wording
  sha256      text NOT NULL UNIQUE,              -- of the canonical JSON definition (spec 05 §2)
  definition  jsonb NOT NULL,                    -- static part; match sets store the builder template
  created_at  timestamptz NOT NULL DEFAULT now()
);
-- settings key 'question_sets.active': IDs are decimal strings, validated against kind + question_sets

CREATE TABLE engine_reservations (
  id              uuid PRIMARY KEY,              -- one reservation per outbound wire attempt
  day             date NOT NULL,                 -- UTC budget day
  engine          text NOT NULL,
  kind            text NOT NULL,
  user_id         uuid NULL REFERENCES users(id) ON DELETE SET NULL,
  reserved_usd    numeric(14,8) NOT NULL CHECK (reserved_usd >= 0),
  reserved_calls  int NOT NULL DEFAULT 1 CHECK (reserved_calls > 0),
  status          text NOT NULL CHECK (status IN ('reserved','settled','uncertain')),
  actual_usd      numeric(14,8) NULL CHECK (actual_usd >= 0),
  created_at      timestamptz NOT NULL DEFAULT now(),
  settled_at      timestamptz NULL,
  expires_at      timestamptz NOT NULL,
  CHECK ((status = 'settled') = (settled_at IS NOT NULL))
);
CREATE INDEX engine_reservations_day_idx ON engine_reservations (day, status);

CREATE TABLE engine_calls (
  id              bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  engine          text NOT NULL CHECK (engine IN ('typesafe','llm','laya','libretranslate')),
  kind            text NOT NULL CHECK (kind IN ('enrich','match','cluster','suggest','translate','eval')),
  model           text NULL,
  article_id      bigint NULL REFERENCES articles(id) ON DELETE SET NULL,
  question_set_id bigint NULL REFERENCES question_sets(id) ON DELETE RESTRICT,
  reservation_id  uuid NULL UNIQUE REFERENCES engine_reservations(id) ON DELETE SET NULL,
  logical_request_id uuid NOT NULL,
  article_revision bigint NULL CHECK (article_revision > 0),
  state_sha256    text NULL,
  card_ids        bigint[] NULL,
  user_id         uuid NULL REFERENCES users(id) ON DELETE SET NULL, -- cost attribution only
  n_questions     int NOT NULL DEFAULT 0 CHECK (n_questions >= 0),
  input_tokens    int NOT NULL DEFAULT 0 CHECK (input_tokens >= 0),
  output_tokens   int NOT NULL DEFAULT 0 CHECK (output_tokens >= 0),
  cost_usd        numeric(12,8) NOT NULL DEFAULT 0 CHECK (cost_usd >= 0),
  billing         text NOT NULL DEFAULT 'known' CHECK (billing IN ('known','uncertain')),
  latency_ms      int NULL CHECK (latency_ms >= 0),
  attempts        int NOT NULL DEFAULT 1 CHECK (attempts > 0), -- per-engine ordinal within logical request
  status          text NOT NULL CHECK (status IN ('ok','error','timeout','rate_limited','invalid_request',
                                                  'invalid_response','auth_error')),
  error           text NULL,
  created_at      timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX engine_calls_created_idx ON engine_calls (created_at);
CREATE INDEX engine_calls_article_idx ON engine_calls (article_id);
CREATE UNIQUE INDEX engine_calls_attempt_idx ON engine_calls (logical_request_id, engine, attempts);

CREATE TABLE article_facets (                     -- Call A answers
  article_id       bigint NOT NULL REFERENCES articles(id) ON DELETE CASCADE,
  question_set_id  bigint NOT NULL REFERENCES question_sets(id) ON DELETE RESTRICT,
  article_revision bigint NOT NULL CHECK (article_revision > 0),
  state_sha256     text NOT NULL,
  engine           text NOT NULL,
  model            text NULL,
  state_variant    text NOT NULL CHECK (state_variant IN ('native','translated')),
  answers          jsonb NOT NULL,               -- normalized answers (spec 04 §2)
  features         jsonb NOT NULL,               -- flattened numeric features (spec 05 §3.4)
  created_at       timestamptz NOT NULL DEFAULT now(),
  updated_at       timestamptz NOT NULL DEFAULT now(),   -- bumped whenever answers or features change (e.g. L2 topics arrive)
  PRIMARY KEY (article_id, question_set_id)
);

CREATE TABLE topics (                             -- taxonomy (spec 05 §3.2), seeded
  id           text PRIMARY KEY,                  -- 'technology' or 'technology.ai_ml'
  parent_id    text NULL REFERENCES topics(id) ON DELETE RESTRICT,
  level        smallint NOT NULL CHECK (level IN (1,2)),
  name_en      text NOT NULL,
  name_sk      text NOT NULL,
  description  text NOT NULL,
  sort         int NOT NULL DEFAULT 0,
  CHECK ((level = 1 AND parent_id IS NULL) OR (level = 2 AND parent_id IS NOT NULL)),
  CHECK (parent_id IS NULL OR parent_id <> id)
);

CREATE TABLE interest_cards (
  id              bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  kind            text NOT NULL CHECK (kind IN ('interest','label')),
  slug            text NULL UNIQUE,               -- library cards only; stable seed identity (spec 05 §8)
  title           text NOT NULL,                  -- default display name (≤ 60 chars); per-user override in user_cards.title_override
  body            jsonb NOT NULL,                 -- {interest, not_for?, interest_en?, not_for_en?, examples_yes?: string[], examples_no?: string[]}
  text_hash       text NOT NULL UNIQUE,           -- spec 05 §5.1
  lang            text NOT NULL DEFAULT 'en',
  topic_ids       text[] NOT NULL DEFAULT '{}',   -- topics(id) values; used for prefiltering and suggestions
  origin          text NOT NULL CHECK (origin IN ('library','user','fork')),
  visibility      text NOT NULL CHECK (visibility IN ('public','shared','private')),
  parent_card_id  bigint NULL REFERENCES interest_cards(id) ON DELETE SET NULL,
  owner_user_id   uuid NULL REFERENCES users(id) ON DELETE CASCADE,  -- set for private forks only
  i18n            jsonb NOT NULL DEFAULT '{}',    -- {"sk": {"title": "…", "interest": "…"}} for library cards
  created_at      timestamptz NOT NULL DEFAULT now(),
  retired_at      timestamptz NULL,
  CHECK ((visibility = 'private') = (owner_user_id IS NOT NULL))
);
CREATE INDEX interest_cards_topics_idx ON interest_cards USING gin (topic_ids);

CREATE TABLE card_answers (                       -- Call B answers
  article_id        bigint NOT NULL REFERENCES articles(id) ON DELETE CASCADE,
  card_id           bigint NOT NULL REFERENCES interest_cards(id) ON DELETE CASCADE,
  p                 real NOT NULL CHECK (p >= 0 AND p <= 1),
  engine            text NOT NULL CHECK (engine IN ('typesafe','llm','laya','prefilter')),
  model             text NULL,
  question_set_sha  text NOT NULL REFERENCES question_sets(sha256) ON DELETE RESTRICT,
  article_revision  bigint NOT NULL CHECK (article_revision > 0),
  state_sha256      text NOT NULL,
  card_input_sha256 text NOT NULL,
  state_variant     text NOT NULL CHECK (state_variant IN ('native','translated')),
  answered_at       timestamptz NOT NULL DEFAULT now(),   -- set to now() on every insert AND upsert
  PRIMARY KEY (article_id, card_id)
);
CREATE INDEX card_answers_card_idx ON card_answers (card_id);

CREATE TABLE article_topics_l2 (                  -- Call B side-questions: level-2 topic answers
  article_id  bigint NOT NULL REFERENCES articles(id) ON DELETE CASCADE,
  l1_id       text NOT NULL REFERENCES topics(id) ON DELETE RESTRICT,
  article_revision bigint NOT NULL CHECK (article_revision > 0),
  question_set_sha text NOT NULL REFERENCES question_sets(sha256) ON DELETE RESTRICT,
  state_sha256 text NOT NULL,
  engine      text NOT NULL,
  model       text NULL,
  state_variant text NOT NULL CHECK (state_variant IN ('native','translated')),
  answer      jsonb NOT NULL,                     -- normalized Choice answer over the L1's children
  created_at  timestamptz NOT NULL DEFAULT now(),
  PRIMARY KEY (article_id, l1_id)
);

CREATE TABLE match_queue (                        -- pending (article, card) questions; coalesced per article
  article_id   bigint NOT NULL REFERENCES articles(id) ON DELETE CASCADE,
  card_id      bigint NOT NULL REFERENCES interest_cards(id) ON DELETE CASCADE,
  article_revision bigint NOT NULL CHECK (article_revision > 0),
  lease_token  uuid NULL,
  lease_until  timestamptz NULL,
  next_attempt_at timestamptz NOT NULL DEFAULT now(),
  last_error   text NULL,
  priority     smallint NOT NULL DEFAULT 5 CHECK (priority BETWEEN 1 AND 9),       -- 1 = interactive … 9 = bulk
  user_id      uuid NULL REFERENCES users(id) ON DELETE SET NULL, -- backfill cost attribution
  attempts     smallint NOT NULL DEFAULT 0 CHECK (attempts >= 0),
  enqueued_at  timestamptz NOT NULL DEFAULT now(),
  PRIMARY KEY (article_id, card_id),
  CHECK ((lease_token IS NULL) = (lease_until IS NULL))
);
CREATE INDEX match_queue_order_idx ON match_queue (priority, enqueued_at);

CREATE TABLE feed_cards (                         -- which cards to ask for articles of a feed
  feed_id  bigint NOT NULL REFERENCES feeds(id) ON DELETE CASCADE,
  card_id  bigint NOT NULL REFERENCES interest_cards(id) ON DELETE CASCADE,
  holders  int NOT NULL CHECK (holders > 0),
  PRIMARY KEY (feed_id, card_id)
);

CREATE TABLE usage_daily (                        -- cost attribution rollup (spec 04 §7)
  day            date NOT NULL,
  user_id        uuid NOT NULL,                   -- '00000000-0000-0000-0000-000000000000' = platform
  engine         text NOT NULL,
  kind           text NOT NULL,
  calls          int NOT NULL DEFAULT 0 CHECK (calls >= 0),
  input_tokens   bigint NOT NULL DEFAULT 0 CHECK (input_tokens >= 0),
  output_tokens  bigint NOT NULL DEFAULT 0 CHECK (output_tokens >= 0),
  cost_usd       numeric(14,8) NOT NULL DEFAULT 0 CHECK (cost_usd >= 0),
  PRIMARY KEY (day, user_id, engine, kind)
);
```


### 3.2 Durable asynchronous work

```sql
CREATE TABLE job_outbox (
  id            bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  queue         text NOT NULL,                   -- shared jobs registry; no arbitrary queue names
  payload       jsonb NOT NULL,
  dedupe_key    text NULL,                       -- includes revision + every semantic payload option
  user_id       uuid NULL REFERENCES users(id) ON DELETE SET NULL, -- requester; null for worker work
  created_at    timestamptz NOT NULL DEFAULT now(),
  available_at  timestamptz NOT NULL DEFAULT now(),
  delivered_at  timestamptz NULL,
  attempts      int NOT NULL DEFAULT 0 CHECK (attempts >= 0),
  lease_token   uuid NULL,
  lease_until   timestamptz NULL,
  last_error    text NULL,
  CHECK ((lease_token IS NULL) = (lease_until IS NULL)),
  CHECK (jsonb_typeof(payload) = 'object')
);
CREATE INDEX job_outbox_pending_idx ON job_outbox (available_at, id) WHERE delivered_at IS NULL;
CREATE UNIQUE INDEX job_outbox_dedupe_idx ON job_outbox (queue, dedupe_key)
  WHERE delivered_at IS NULL AND dedupe_key IS NOT NULL;
```

- The state change and required job intent commit together, using the same `TenantTx`/worker
  transaction. API validation authorizes the actual target user, feeds and cards before insertion;
  RLS authenticates the requester but cannot infer authorization from arbitrary JSON. Only typed
  enqueue helpers can construct an intent. Required follow-on jobs from workers also use the outbox.
- `dedupe_key` may collapse **identical pending work only**; use a fingerprint of the complete
  validated payload and revision. A full rank or force-tier2 request cannot be consumed by a weaker
  request. NULL is allowed where coalescing is unnecessary. Lease/in-flight work cannot swallow a
  later revision; serialize the producer's conflict handling and relay completion on the outbox row.
- A worker relay claims due rows in a short `FOR UPDATE SKIP LOCKED` transaction, writes a random
  token and expiry, commits, sends the job, then marks delivered **only with that token**. Expired
  leases are reclaimable; no database transaction spans network/queue waiting. Failed sends retain
  their intent with bounded exponential backoff and an operational alert, never silent deletion.
- Crash after send and before marking delivered means duplicate delivery: consumers are idempotent
  and use revision/fencing checks (§3.3). A broker singleton conflict counts as delivery only when the
  broker contract proves equivalent work is pending; otherwise retain and retry the intent.
- Relay interval ≤ 1 second while work exists. Purge delivered intents after 7 days; never purge
  undelivered work merely because it is old. Redact/purge user identifiers in job payloads on account
  erasure; consumed stale jobs must check that their target user/article still exists and is active.

### 3.3 Cache identity, leases and consistency

The model audit is append-only during its retention period. `article_facets`, `card_answers`,
`article_topics_l2`, `article_bodies` and `article_translations` are **current-result caches**, which
may be replaced; they are not an unlimited historical archive. Question-set definitions are immutable.

- `articles.content_revision` is a monotonically increasing fencing token. A change to the actual
  article source, selected extracted text or selected translation increments it in the same transaction as invalidation and
  the new outbox intent. Preserve the source text hash separately. Any extraction/translation row
  retained across that transaction is deliberately stamped with the new revision only when it is
  still the valid input; do not accidentally make the just-selected translation immediately stale.
- Every async handler captures the input revision and selected question set/model/card mode before
  leaving the transaction. On completion it locks/rechecks the article and writes outputs only when
  the revision still matches and the claimed lease token is still owned. Discard stale results
  (keeping billed-call audit); schedule the current revision if not already pending. Cluster results
  also recheck all candidate revisions and membership under ordered row locks.
- Cache reads require matching `article_revision`, question-set identity, selected model/engine
  policy and `state_sha256 = sha256(canonicalJson(actual model state))`. Card answers additionally
  require `card_input_sha256` over the exact rendered question, card text mode, derived translations,
  label title and examples. SQL existence alone is never proof of a current answer. `source_sha256`
  in translation rows fingerprints actual submitted source fields. Never hash secrets into metadata.
- A reset removes old current facets, L2 topics and matches (or renders them ineligible by revision),
  replaces pending match rows at the new revision, clears their leases/attempts and records the next
  pipeline intent atomically. A delayed old worker cannot delete a new queue row: completion includes
  `(article_id, card_id, article_revision, lease_token)` predicates.
- `match_queue` claims commit before model calls. Select due rows with `attempts < 5`, acquire a
  token/expiry, renew while live and release only rows with that token. Exhausted rows remain with
  `last_error` for bounded operator/housekeeping recovery; missing answers affect only the relevant
  user-card pair, not all readers of an article. Lease expiry alone does not prove an upstream call
  was unbilled. Failed claims and budget deferrals have distinct retry accounting (spec 05).
- Spend admission serializes reservations under a UTC-day transaction advisory lock before every
  billed attempt; settle call audit, usage rollup and reservation once in one transaction. A UNIQUE
  reservation ID prevents double settlement. `reserved`/`uncertain` amounts continue to consume the
  day budget; a timeout/crash does not release a possibly billed reservation. Spec 04 defines cost
  estimates, retry accounting and evaluation isolation. `usage_daily`'s zero UUID is a platform
  sentinel, intentionally without a user FK; purge/reassign personal rows on account erasure.
- `user.suggest` admission atomically locks/updates the user row: active user, last admitted attempt
  older than 24 hours (or null), and lease absent/expired. Claim a fresh token/expiry; after candidate
  selection and budget admission, stamp the attempt time under that token **before the first wire
  call**. No candidates or budget denial releases the lease without advancing the timestamp.
  Completion/renewal must match the token and recheck the
  user's input revision; duplicate jobs cannot bypass the 24-hour admission limit. A crash may forgo
  that day's suggestion, but never creates an unbounded retry bill. Clear only the owned lease.
- JSON settings read/modify/write locks the setting row (or performs a single conditional SQL
  update); concurrent breaker resets, heartbeat keys, counters and setting-version increments must
  not overwrite each other. For a missing key, insert default `ON CONFLICT DO NOTHING` before locking.
- Cluster `size` is the actual count of current members, not an increment-only counter. Update it and
  the representative after merges/deletes in the same transaction; a representative must belong to
  the cluster. Feed merge pointers form an acyclic chain; lock both feed IDs in ascending order and
  resolve to the live root before changing subscriptions. See spec 03 for full merge semantics.
- Global canonical URL/alias identity is serialized under sorted transaction advisory locks keyed by
  the exact `url_key`, followed by rechecking **both** identity tables; uniqueness in either table
  alone cannot prevent cross-table duplicates. Feed items retain the first non-null GUID per pair;
  alternate GUIDs that arrive with the same URL do not replace that stable identifier.


---

## 4. Per-user tables (RLS enforced)

Every table in this section has `user_id uuid NOT NULL REFERENCES users(id) ON DELETE CASCADE` and the
policy from §5.

```sql
CREATE TABLE subscriptions (
  user_id           uuid NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  feed_id           bigint NOT NULL REFERENCES feeds(id) ON DELETE CASCADE,
  title_override    text NULL,
  folder            text NULL,
  allow_duplicates  boolean NOT NULL DEFAULT false,  -- false = fold story clusters (spec 08 §5.1)
  hidden            boolean NOT NULL DEFAULT false,  -- hide feed from sidebar, keep ranking
  created_at        timestamptz NOT NULL DEFAULT now(),
  PRIMARY KEY (user_id, feed_id)
);
CREATE INDEX subscriptions_feed_idx ON subscriptions (feed_id);

CREATE TABLE user_cards (
  user_id        uuid NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  card_id        bigint NOT NULL REFERENCES interest_cards(id) ON DELETE NO ACTION DEFERRABLE INITIALLY DEFERRED,
  strength       text NOT NULL CHECK (strength IN ('must','love','like','never')),
  scope_feed_id  bigint NULL,                     -- composite FK below prevents scope outside subscriptions
  title_override text NULL,                        -- the user's own name for the card (cards are shared and immutable)
  created_at     timestamptz NOT NULL DEFAULT now(),
  updated_at     timestamptz NOT NULL DEFAULT now(),
  PRIMARY KEY (user_id, card_id),
  FOREIGN KEY (user_id, scope_feed_id) REFERENCES subscriptions(user_id, feed_id) ON DELETE CASCADE
);
CREATE INDEX user_cards_card_idx ON user_cards (card_id);

CREATE TABLE user_labels (
  user_id     uuid NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  card_id     bigint NOT NULL REFERENCES interest_cards(id) ON DELETE NO ACTION DEFERRABLE INITIALLY DEFERRED,  -- kind = 'label'
  name        text NOT NULL,
  color       text NOT NULL DEFAULT 'slate',
  created_at  timestamptz NOT NULL DEFAULT now(),
  PRIMARY KEY (user_id, card_id)
);

CREATE TABLE user_rules (
  id          bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  user_id     uuid NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  kind        text NOT NULL CHECK (kind IN ('mute_keyword','mute_story','block_feed','block_domain',
                                            'block_author','boost_feed','boost_domain')),
  value       text NOT NULL,                     -- keyword / cluster id / feed id / domain / author
  created_at  timestamptz NOT NULL DEFAULT now(),
  expires_at  timestamptz NULL
);
CREATE INDEX user_rules_user_idx ON user_rules (user_id);

CREATE TABLE user_article (
  user_id            uuid NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  article_id         bigint NOT NULL REFERENCES articles(id) ON DELETE CASCADE,
  -- ranking cache (written by user.rank; spec 06)
  lane               text NOT NULL DEFAULT 'new'
                       CHECK (lane IN ('new','for_you','maybe','everything','hidden')),
  tier               smallint NULL CHECK (tier BETWEEN 1 AND 5),
  p_like             real NULL CHECK (p_like BETWEEN 0 AND 1),
  score_source       text NOT NULL DEFAULT 'none'
                       CHECK (score_source IN ('none','cards','model','degraded')),
  rules_fired        text[] NOT NULL DEFAULT '{}',
  explain            jsonb NULL,                  -- spec 06 §6
  label_suggestions  bigint[] NOT NULL DEFAULT '{}',
  score_version      text NOT NULL DEFAULT '0:0', -- rankerVersion:settingsVersion; spec 06
  rank_revision      bigint NOT NULL DEFAULT 0 CHECK (rank_revision >= 0),
  next_rank_at       timestamptz NULL,
  scored_at          timestamptz NULL,
  -- reader state (written by the API)
  state_version      bigint NOT NULL DEFAULT 0 CHECK (state_version >= 0),
  opened_at          timestamptz NULL,
  read_at            timestamptz NULL,
  rating             smallint NULL CHECK (rating IN (-1, 1)),
  reason             text NULL CHECK (reason IN ('off_topic','clickbait','seen','shallow','promo','other')),
  rated_at           timestamptz NULL,
  dwell_ms           int NULL CHECK (dwell_ms >= 0),
  bookmarked_at      timestamptz NULL,
  archived_at        timestamptz NULL,
  label_ids          bigint[] NOT NULL DEFAULT '{}',
  feedback_prompted_at timestamptz NULL,
  CHECK ((rating IS NULL) = (rated_at IS NULL)),
  CHECK (reason IS NULL OR (rating IS NOT NULL AND rating = -1)),
  PRIMARY KEY (user_id, article_id)
);
CREATE INDEX user_article_lane_idx ON user_article (user_id, lane, p_like DESC NULLS LAST, article_id DESC)
  WHERE archived_at IS NULL AND read_at IS NULL;
CREATE INDEX user_article_bookmarks_idx ON user_article (user_id, bookmarked_at DESC)
  WHERE bookmarked_at IS NOT NULL;

CREATE TABLE feedback_events (                    -- append-only training log
  id          bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  user_id     uuid NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  article_id  bigint NOT NULL REFERENCES articles(id) ON DELETE CASCADE,
  kind        text NOT NULL CHECK (kind IN ('rate','unrate','open','read','unread','dwell','prompt_answer',
                                            'bookmark','unbookmark','label','unlabel','mark_read','hide','unhide','undo')),
  value       jsonb NOT NULL DEFAULT '{}',
  created_at  timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX feedback_events_user_idx ON feedback_events (user_id, created_at DESC);

CREATE TABLE user_models (
  user_id            uuid NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  version            int NOT NULL,
  feature_spec_sha   text NOT NULL,
  n_labels           int NOT NULL,
  n_pos              int NOT NULL,
  n_neg              int NOT NULL,
  weights            jsonb NOT NULL,             -- {feature_name: weight}
  intercept          real NOT NULL,
  scaler             jsonb NOT NULL,             -- {feature_name: [mean, std]}
  calibration        jsonb NOT NULL,             -- {a, b} Platt parameters
  metrics            jsonb NOT NULL,             -- {cv_auc, cv_logloss, baseline_auc}
  active             boolean NOT NULL DEFAULT false,
  trained_at         timestamptz NOT NULL DEFAULT now(),
  PRIMARY KEY (user_id, version)
);
CREATE UNIQUE INDEX user_models_active_idx ON user_models (user_id) WHERE active;

CREATE TABLE api_mutations (                      -- idempotency/undo; spec 08
  user_id      uuid NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  id           uuid NOT NULL,                     -- client's Idempotency-Key
  request_hash text NOT NULL,                     -- method + route + canonical validated body
  route        text NOT NULL,
  status       int NOT NULL CHECK (status BETWEEN 200 AND 499),
  response     jsonb NOT NULL,
  undo         jsonb NULL,                        -- allowlisted prior fields + expected versions
  created_at   timestamptz NOT NULL DEFAULT now(),
  expires_at   timestamptz NOT NULL,
  PRIMARY KEY (user_id, id),
  CHECK (expires_at >= created_at + interval '7 days')
);
CREATE INDEX api_mutations_expiry_idx ON api_mutations (expires_at);

CREATE TABLE card_suggestions (
  user_id       uuid NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  card_id       bigint NOT NULL REFERENCES interest_cards(id) ON DELETE CASCADE,
  score         real NOT NULL CHECK (score BETWEEN 0 AND 1),
  created_at    timestamptz NOT NULL DEFAULT now(),
  dismissed_at  timestamptz NULL,
  PRIMARY KEY (user_id, card_id)
);
```

---

## 5. Row-level security

For each per-user table `t` in §4:

```sql
ALTER TABLE t ENABLE ROW LEVEL SECURITY;
ALTER TABLE t FORCE ROW LEVEL SECURITY;
CREATE POLICY t_tenant ON t
  USING      (user_id = nullif(current_setting('app.user_id', true), '')::uuid)
  WITH CHECK (user_id = nullif(current_setting('app.user_id', true), '')::uuid);
```

- The API runs **every authenticated request** inside a transaction that first executes
  `SELECT set_config('app.user_id', $1, true)`. `packages/db` exposes
  `withTenant(db, userId, async (tx) => …)`, and repositories for per-user tables accept only a `tx`
  created that way (a branded `TenantTx` type).
- Without `app.user_id`, per-user tables return no rows. An integration test asserts this for every
  table in §4.
- `feedit_worker` bypasses RLS. It is used only by worker handlers, the eval CLI and housekeeping, and
  the API never holds its credentials. Cross-tenant reads that the API needs (feed-level refreshes,
  admin statistics) go through the SECURITY DEFINER functions of §6, which run as the BYPASSRLS owner.
- Shared tables that contain private-card material are not exempt from tenant isolation. Apply the
  policies below; repository DTO checks remain defense in depth. Private forks are visible only to
  their owner, including on admin card-list routes. `feed_cards` and `match_queue` stay worker-only.


### 5.1 Private cards and durable intents

```sql
ALTER TABLE interest_cards ENABLE ROW LEVEL SECURITY;
ALTER TABLE interest_cards FORCE ROW LEVEL SECURITY;
CREATE POLICY interest_cards_read ON interest_cards FOR SELECT TO feedit_app
  USING (visibility IN ('public','shared') OR
         owner_user_id = nullif(current_setting('app.user_id', true), '')::uuid);
CREATE POLICY interest_cards_create ON interest_cards FOR INSERT TO feedit_app
  WITH CHECK (nullif(current_setting('app.user_id', true), '') IS NOT NULL AND
    ((visibility = 'shared' AND origin = 'user' AND owner_user_id IS NULL) OR
     (visibility = 'private' AND origin = 'fork' AND
      owner_user_id = nullif(current_setting('app.user_id', true), '')::uuid) OR
     (visibility = 'public' AND origin = 'library' AND EXISTS
       (SELECT 1 FROM users WHERE id = nullif(current_setting('app.user_id', true), '')::uuid
          AND role = 'admin' AND deleted_at IS NULL))));
CREATE POLICY interest_cards_update ON interest_cards FOR UPDATE TO feedit_app
  USING (visibility IN ('public','shared') OR
         owner_user_id = nullif(current_setting('app.user_id', true), '')::uuid)
  WITH CHECK (visibility IN ('public','shared') OR
              owner_user_id = nullif(current_setting('app.user_id', true), '')::uuid);

ALTER TABLE card_answers ENABLE ROW LEVEL SECURITY;
ALTER TABLE card_answers FORCE ROW LEVEL SECURITY;
CREATE POLICY card_answers_read ON card_answers FOR SELECT TO feedit_app
  USING (EXISTS (SELECT 1 FROM interest_cards c WHERE c.id = card_id));

ALTER TABLE job_outbox ENABLE ROW LEVEL SECURITY;
ALTER TABLE job_outbox FORCE ROW LEVEL SECURITY;
CREATE POLICY job_outbox_requester ON job_outbox FOR INSERT TO feedit_app
  WITH CHECK (user_id = nullif(current_setting('app.user_id', true), '')::uuid);
```

The API has no SELECT policy/grant on outbox. Server-side helpers insert without `RETURNING`; the
requester's UUID is not a queue target authorization mechanism. Anonymous auth email is the explicit exception: commit the short-lived challenge, then perform
bounded synchronous SMTP delivery; failure leaves no delivery guarantee and the user can request a
replacement code (spec 08). Never invent a tenant or grant an API-wide BYPASSRLS role for mail. Codes,
SMTP credentials and session secrets never enter generic outbox payloads or logs.

### 5.2 Required integrity triggers and repositories

These are part of the M0 hand-written migrations and schema parity snapshot, even where Drizzle
cannot express them. PostgreSQL FKs bypass RLS, and array/JSON elements are not FKs, so API validation
alone does not satisfy these requirements.

| Invariant | Database enforcement and repository behavior |
|---|---|
| Card holdings cannot attach another tenant's private card | BEFORE INSERT/UPDATE trigger on `user_cards`, `user_labels`, `card_suggestions`: the referenced card exists, is public/shared or owned by `NEW.user_id`, and has kind `interest`, `label`, `interest` respectively. Check actual card ownership even for worker writes; raise generic constraint failure without private values |
| Immutable card identity | BEFORE UPDATE on `interest_cards`: reject changes to `kind`, `text_hash`, `lang`, ownership or base `body` text/examples. `interest_en`/`not_for_en` may each change only from absent/null to a validated translation, once. Label `title` cannot change because it is hashed. Private forks cannot be promoted. Shared→public metadata promotion requires admin; non-admin API writes can only un-retire an otherwise identical accessible row. Worker/owner metadata maintenance does not bypass identity invariants |
| Label assignment integrity | DEFERRABLE INITIALLY DEFERRED constraint triggers on changed `user_article.label_ids`/`label_suggestions` and `user_labels` removals/repointing validate the **final row state**: distinct non-null IDs, each present in that user's `user_labels`, and suggestions exclude assigned labels. Label deletion removes its IDs from both arrays in the same transaction; fork replacement uses deduplicated arrays. Serialize label changes and assignments on the owning user row |
| Scope owns a subscription | Composite FK on `(user_id, scope_feed_id)` removes a scoped holding when that subscription is deleted; it never silently widens it to every feed. Capture affected cards/feeds before deletion for cache refresh and outbox work |
| Topic references | Taxonomy seeding validates each level-2 parent is level 1 and every `interest_cards.topic_ids` entry exists. BEFORE INSERT/UPDATE trigger enforces this on admin/runtime card changes; seeded taxonomy IDs are never deleted while used by arrays or model definitions |
| Reader state and feedback agree | Lock the current `user_article` row (or conflict-safe insert), apply patch, increment `state_version`, append event and idempotency receipt, and write outbox intents in one transaction. Workers update only ranking-cache columns. Reject rating reasons unless the rating is -1 |
| One active personal model | Lock the `users` row before allocating a model version or switching `active`; deactivate old and activate new in one transaction. Partial unique index rejects dual activation; stale training input revision cannot activate a model |
| Retention and account erasure | Gather user feed IDs and private card IDs, delete/rewrite personal derived references and receipts, clear arrays, then delete the account, refresh feeds and commit. Deferred card FKs allow the user's cascading holds/forks to disappear in either FK execution order; another user's hold of a private fork is impossible. Revoke live sessions at soft deletion |

A tenant GUC is a trusted server context, not cryptographic authentication. RLS protects against missing
repository filters; it cannot protect a database role allowed to run arbitrary `SET app.user_id` SQL.
All values are bound parameters and no user can execute SQL or select the server's database role.


---

## 6. SQL functions (SECURITY DEFINER, owned by the BYPASSRLS `feedit_owner`)

```sql
-- Recompute feed_cards for the given feeds: cards and labels held by any (non-deleted) subscriber,
-- respecting card scope.
CREATE FUNCTION refresh_feed_cards(p_feed_ids bigint[]) RETURNS void
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path = pg_catalog, public, pg_temp AS $$
BEGIN
  -- Serialize overlapping feed refreshes; new snapshots after the wait see committed subscribers.
  PERFORM f.id FROM feeds f WHERE f.id = ANY(p_feed_ids) ORDER BY f.id FOR NO KEY UPDATE;
  DELETE FROM feed_cards WHERE feed_id = ANY(p_feed_ids);
  INSERT INTO feed_cards (feed_id, card_id, holders)
  SELECT s.feed_id, x.card_id, count(DISTINCT s.user_id)
  FROM subscriptions s
  JOIN users u ON u.id = s.user_id AND u.deleted_at IS NULL
  JOIN (
    SELECT user_id, card_id, scope_feed_id FROM user_cards
    UNION ALL
    SELECT user_id, card_id, NULL::bigint FROM user_labels
  ) x ON x.user_id = s.user_id AND (x.scope_feed_id IS NULL OR x.scope_feed_id = s.feed_id)
  JOIN interest_cards c ON c.id = x.card_id AND c.retired_at IS NULL
  WHERE s.feed_id = ANY(p_feed_ids)
  GROUP BY s.feed_id, x.card_id;
END;
$$;

-- Keep feeds.subscriber_count and feeds.min_interval_s in sync.
-- p_plan_min_interval = {"beta": 900, "admin": 300}, built from packages/shared/src/plans.ts.
CREATE FUNCTION refresh_feed_subscribers(p_feed_ids bigint[], p_plan_min_interval jsonb) RETURNS void
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path = pg_catalog, public, pg_temp AS $$
BEGIN
  PERFORM f.id FROM feeds f WHERE f.id = ANY(p_feed_ids) ORDER BY f.id FOR NO KEY UPDATE;
  UPDATE feeds f
     SET subscriber_count = coalesce(x.cnt, 0),
         min_interval_s   = coalesce(x.min_iv, 900),
         updated_at       = now()
    FROM (SELECT DISTINCT unnest(p_feed_ids) AS feed_id) ids
    LEFT JOIN (
      SELECT s.feed_id, count(*) AS cnt,
             min(coalesce((p_plan_min_interval ->> u.plan)::int, 900)) AS min_iv
      FROM subscriptions s JOIN users u ON u.id = s.user_id AND u.deleted_at IS NULL
      WHERE s.feed_id = ANY(p_feed_ids)
      GROUP BY s.feed_id) x ON x.feed_id = ids.feed_id
   WHERE f.id = ids.feed_id;
END;
$$;

-- True only for an active administrator session or an operational login.
-- session_user preserves the real login inside SECURITY DEFINER; test role connections separately.
CREATE FUNCTION admin_context_allowed() RETURNS boolean
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = pg_catalog, public, pg_temp AS $$
  SELECT session_user IN ('feedit_owner','feedit_worker') OR EXISTS (
    SELECT 1 FROM users u WHERE u.id = nullif(current_setting('app.user_id', true), '')::uuid
      AND u.role = 'admin' AND u.deleted_at IS NULL
  );
$$;
REVOKE EXECUTE ON FUNCTION admin_context_allowed() FROM PUBLIC;

-- Admin statistics: how many active users hold each card (as interest or label).
CREATE FUNCTION admin_card_holders(p_card_ids bigint[]) RETURNS TABLE (card_id bigint, holders int)
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = pg_catalog, public, pg_temp AS $$
  SELECT c.id,
         ((SELECT count(*) FROM user_cards uc JOIN users u ON u.id = uc.user_id
             WHERE uc.card_id = c.id AND u.deleted_at IS NULL)
        + (SELECT count(*) FROM user_labels ul JOIN users u ON u.id = ul.user_id
             WHERE ul.card_id = c.id AND u.deleted_at IS NULL))::int
  FROM unnest(p_card_ids) AS c(id) WHERE admin_context_allowed();
$$;

-- Admin usage: per-user attributed cost over the last p_days UTC days.
-- direct = user-attributed usage_daily rows (backfills, suggestions, fork questions);
-- shared = platform 'match' cost × (Σ over the user's (feed, card) holdings of 1/holders) / count(feed_cards).
CREATE FUNCTION admin_usage_attribution(p_days int)
RETURNS TABLE (user_id uuid, direct_usd numeric, shared_usd numeric)
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = pg_catalog, public, pg_temp AS $$
  WITH win AS (SELECT * FROM usage_daily WHERE day > (now() AT TIME ZONE 'UTC')::date - p_days
               AND day <= (now() AT TIME ZONE 'UTC')::date AND p_days BETWEEN 1 AND 366),
  direct AS (SELECT w.user_id, sum(w.cost_usd) AS usd FROM win w
             WHERE w.user_id <> '00000000-0000-0000-0000-000000000000' GROUP BY w.user_id),
  m AS (SELECT coalesce(sum(cost_usd), 0) AS usd FROM win
        WHERE user_id = '00000000-0000-0000-0000-000000000000' AND kind = 'match'),
  tot AS (SELECT greatest(count(*), 1) AS n FROM feed_cards),
  holding AS (
    SELECT DISTINCT s.user_id, fc.feed_id, fc.card_id, fc.holders
    FROM feed_cards fc
    JOIN subscriptions s ON s.feed_id = fc.feed_id
    JOIN users u ON u.id = s.user_id AND u.deleted_at IS NULL
    JOIN (SELECT user_id, card_id, scope_feed_id FROM user_cards
          UNION ALL SELECT user_id, card_id, NULL::bigint FROM user_labels) x
      ON x.user_id = s.user_id AND x.card_id = fc.card_id
     AND (x.scope_feed_id IS NULL OR x.scope_feed_id = fc.feed_id)),
  shared AS (SELECT h.user_id, sum(1.0 / h.holders) AS share FROM holding h GROUP BY h.user_id)
  SELECT coalesce(d.user_id, sh.user_id), coalesce(d.usd, 0),
         coalesce(sh.share, 0) * (SELECT usd FROM m) / (SELECT n FROM tot)
  FROM direct d FULL JOIN shared sh ON sh.user_id = d.user_id
  WHERE admin_context_allowed();
$$;

REVOKE EXECUTE ON FUNCTION refresh_feed_cards(bigint[]), refresh_feed_subscribers(bigint[], jsonb),
  admin_card_holders(bigint[]), admin_usage_attribution(int) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION refresh_feed_cards(bigint[]), refresh_feed_subscribers(bigint[], jsonb),
  admin_card_holders(bigint[]), admin_usage_attribution(int) TO feedit_app, feedit_worker;
```

**Narrow API accounting helper.** The M0 hand-written migration also supplies
`record_card_translation(p_logical_request_id uuid, p_attempt int, p_latency_ms int, p_status text,
p_error_code text)` as SECURITY DEFINER with a fixed trusted search path, explicit revoke from PUBLIC
and execute only for `feedit_app`/`feedit_worker`. Require an active `app.user_id`; derive attribution
from it, hard-code `engine='libretranslate'`, `kind='translate'`, `cost_usd=0`, and omit article/card IDs
until a card exists. Validate the status/attempt/latency and a bounded error-code allowlist, never accept
raw text. Insert at most once per logical request/engine/attempt and increment zero-cost usage in the same
transaction. The API may use this only for its free tier-1 card translation (spec 07); paid reservation
and settlement remain worker-only. Record metadata, never translated private text, in audit rows.

**Callers:**
- **The refresh functions** are called by the API **in the same transaction** as the change that
  affects them: subscribe/unsubscribe, card add/remove/scope change, label add/remove, account delete
  and restore. `house.reconcile` (spec 11) also runs them nightly for all feeds.
- **The `admin_*` functions** are called only from admin routes, after the role check (spec 08 §9),
  and enforce the active admin context again in SQL. Invalid `p_days` is rejected by the API; direct
  SQL calls return no usage rows. Cost allocation is an estimate based on **current** holders, not
  historical billing; soft-deleted users and duplicate holdings never inflate the total.
- Mutations use READ COMMITTED and acquire all affected user rows in UUID order, then feed rows in
  numeric order (`FOR NO KEY UPDATE`), before changing subscriptions/holdings. Both refresh functions
  also acquire those feed locks defensively and keep them to commit. Call both with the complete
  affected feed set, including the old scope. This avoids duplicate inserts/lost counters in parallel
  subscribe/unsubscribe, and shared labels are counted once per user. Functions use a fixed trusted
  search path, bind arguments, and never interpolate dynamic SQL.

**Tests** (M0-T5). Both refresh functions give correct rows when called:
- (a) as `feedit_app` inside `withTenant(A)`, with users A and B both subscribed and holding different
  cards
- (b) as `feedit_worker` with no `app.user_id`

Neither may drop B's rows. Add two-connection tests for concurrent subscribe/unsubscribe and scope
changes, proving both materialized feed caches equal a fresh source-table aggregation at commit.

---

## 7. Evaluation schema (`eval`, created in M3)

```sql
CREATE SCHEMA eval;
CREATE TABLE eval.raters (id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY, name text NOT NULL,
  token_hash text NOT NULL UNIQUE, langs text[] NOT NULL, created_at timestamptz NOT NULL DEFAULT now());
CREATE TABLE eval.rater_cards (rater_id bigint REFERENCES eval.raters(id) ON DELETE CASCADE,
  card_id bigint REFERENCES interest_cards(id) ON DELETE RESTRICT,
  strength text NOT NULL CHECK (strength IN ('must','love','like','never')), PRIMARY KEY (rater_id, card_id));
CREATE TABLE eval.rater_feeds (rater_id bigint REFERENCES eval.raters(id) ON DELETE CASCADE,
  feed_id bigint REFERENCES feeds(id) ON DELETE RESTRICT, PRIMARY KEY (rater_id, feed_id));
CREATE TABLE eval.assignments (rater_id bigint REFERENCES eval.raters(id) ON DELETE CASCADE,
  article_id bigint REFERENCES articles(id) ON DELETE RESTRICT, position int NOT NULL CHECK (position >= 0),
  status text NOT NULL DEFAULT 'pending' CHECK (status IN ('pending','rated','skipped')),
  PRIMARY KEY (rater_id, article_id), UNIQUE (rater_id, position));
CREATE TABLE eval.ratings (rater_id bigint REFERENCES eval.raters(id) ON DELETE CASCADE,
  article_id bigint REFERENCES articles(id) ON DELETE RESTRICT, rating smallint NOT NULL CHECK (rating IN (-1, 1)),
  reason text NULL, created_at timestamptz NOT NULL DEFAULT now(), PRIMARY KEY (rater_id, article_id));
CREATE TABLE eval.facet_labels (labeler text NOT NULL, article_id bigint REFERENCES articles(id) ON DELETE RESTRICT,
  question_key text NOT NULL, value text NOT NULL, created_at timestamptz NOT NULL DEFAULT now(),
  PRIMARY KEY (article_id, question_key, labeler));
CREATE TABLE eval.sample (article_id bigint PRIMARY KEY REFERENCES articles(id) ON DELETE RESTRICT, lang text NOT NULL,
  snapshot jsonb NOT NULL, snapshot_sha text NOT NULL,
  split text NOT NULL CHECK (split IN ('dev','test')),
  created_at timestamptz NOT NULL DEFAULT now());
CREATE TABLE eval.runs (id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY, experiment text NOT NULL,
  config jsonb NOT NULL, git_sha text NOT NULL, started_at timestamptz NOT NULL DEFAULT now(),
  finished_at timestamptz NULL, results jsonb NULL);
CREATE TABLE eval.run_answers (run_id bigint NOT NULL REFERENCES eval.runs(id) ON DELETE CASCADE,
  article_id bigint NOT NULL REFERENCES articles(id) ON DELETE RESTRICT,
  card_id bigint NULL REFERENCES interest_cards(id) ON DELETE RESTRICT,
  question_key text NOT NULL, answer jsonb NOT NULL,
  UNIQUE NULLS NOT DISTINCT (run_id, article_id, card_id, question_key));
CREATE INDEX run_answers_run_idx ON eval.run_answers (run_id, article_id);
GRANT USAGE ON SCHEMA eval TO feedit_worker;
GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA eval TO feedit_worker;
GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA eval TO feedit_worker;
ALTER DEFAULT PRIVILEGES FOR ROLE feedit_owner IN SCHEMA eval GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO feedit_worker;
ALTER DEFAULT PRIVILEGES FOR ROLE feedit_owner IN SCHEMA eval GRANT USAGE, SELECT ON SEQUENCES TO feedit_worker;
```

- The `eval` schema is accessed only by `apps/eval`, through `DATABASE_URL_WORKER`.
- Evaluation uses a separate database with synthetic/consented card definitions. Never pin a
  production user's private fork against account erasure; remove such personal eval references before
  deletion if they exist. Frozen benchmark data does not override a personal-data deletion request.
- Rows referenced from `eval.*` (articles in `eval.sample`/`assignments`/`ratings`/`facet_labels`, and
  cards in `eval.rater_cards`/`run_answers`, and feeds in `eval.rater_feeds`) are exempt from purging and retiring (spec 11 §5–6).


## 8. Database acceptance cases (M0; eval-specific cases in M3a)

These are behavioral integration tests against the pinned PostgreSQL 16 image, in addition to schema
parity. A generated migration is not complete until these pass with actual role logins:

1. Fresh production bootstrap and migration, then upgrade/re-run, contain every table, explicit FK
   action, index, grant, function and integrity trigger. Template cloning works with two parallel runs
   and a changed migration file whose journal filename did not change.
2. Each tenant table fails closed with no context and across a reused pool connection. Tenant A cannot
   select, attach, mutate, infer a private fork through a join, or assign a label belonging to B.
   Non-admin shared-card updates cannot change another reader's text or promote a card. No API query
   can read worker-only queues, audit metadata or outbox payloads.
3. Concurrent subscription/card mutations preserve both users' `feed_cards` and subscriber counts.
   Account purge with a held private fork succeeds without FK-order dependence. Scope deletion,
   label replacement and user restoration satisfy the final-state invariants.
4. Crash before/after commit and before/after broker send preserves every required intent; duplicate
   delivery produces one logical mutation. A late lease holder cannot clear new work or publish stale
   source/model results. Exhausted match rows stay inspectable and budget deferrals do not lose work.
5. Concurrent budget reservations cannot pass a daily cap, retries each reserve, duplicate settlement
   cannot double usage, and timeout uncertainty remains charged against availability until resolved.
6. Concurrent reader patches, duplicate `Idempotency-Key`, conflicting request hashes, label edits,
   model activation and exact undo preserve versions, ownership and one active model. Ranking never
   changes reader fields; an old rank revision cannot overwrite a newer score.
7. Eval retries cannot duplicate answers (including rows with NULL card IDs); protected eval
   articles/cards/feeds survive retention and merge attempts, and frozen snapshots do not change when
   live source articles are edited.

Implementation references: PostgreSQL 16 [row security](https://www.postgresql.org/docs/16/ddl-rowsecurity.html),
[constraints](https://www.postgresql.org/docs/16/ddl-constraints.html),
[function visibility](https://www.postgresql.org/docs/16/spi-visibility.html).
