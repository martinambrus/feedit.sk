# Spec 02: Data model (PostgreSQL 16)

Status: **binding**. **Intent:** the shared article layer (feeds, articles, model answers) is global and
paid for once. Everything a person *does* is tenant data, protected by row-level security. Raw model
answers are kept so that changing a threshold never needs a new API call.

Conventions: `snake_case`; `bigint GENERATED ALWAYS AS IDENTITY` primary keys (users use UUID v7);
`timestamptz` everywhere, defaulting to `now()`. Enumerations are `text` columns with `CHECK`
constraints, not Postgres enums, so they are easy to migrate. Every foreign key states its `ON DELETE`
behaviour.

The Drizzle schema in `packages/db/src/schema/*.ts` must produce exactly this DDL. RLS policies,
grants, functions and trigram indexes are hand-written SQL migrations. The database, roles and
extensions come from `infra/postgres/init.sh` (§1.1).

**Schema parity test.** `packages/db/test/schema-parity.int.test.ts` reads the migrated catalog and
compares it with the checked-in `packages/db/test/expected-schema.json`:
- tables, columns, types, nullability, defaults
- check constraints, indexes, foreign keys with their `ON DELETE`
- RLS policies, grants, functions

The JSON is written once, by hand, from this spec. Any later schema change updates it in the same
commit.

---

## 1. Bootstrap, roles and privileges

### 1.1 Cluster bootstrap (`infra/postgres/init.sh`)

The official `postgres:16` image runs `*.sh` files from `/docker-entrypoint-initdb.d/` as the `postgres`
superuser on first start. (A plain `.sql` file cannot receive psql variables.) The script takes the
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
GRANT CONNECT ON DATABASE feedit TO feedit_app, feedit_worker;
SQL
```

`feedit_owner` must have `BYPASSRLS`. The SECURITY DEFINER functions in §6 run as the owner and must
see every tenant's rows. `FORCE ROW LEVEL SECURITY` would otherwise apply to the owner too.

**Test databases** (`infra/compose.test.yml` runs the same `init.sh`). The helper in
`packages/testing` connects with `TEST_ADMIN_DATABASE_URL` (the superuser):

1. **Template per schema version:** `feedit_template_<h>`, where `h` = the first 12 hex digits of
   `sha256(migration journal file + pinned pg-boss version)`. A changed migration therefore yields a new
   template, and parallel branches with different migrations never share one.
2. **Creation** runs under `pg_advisory_lock(hashtext('feedit_template'))`, so parallel test runs never
   race. If the template is missing:
   - `CREATE DATABASE feedit_template_<h> OWNER feedit_owner`
   - as the superuser, `CREATE EXTENSION IF NOT EXISTS citext, pg_trgm, pgcrypto` in it (a new database
     has no extensions)
   - the full **migrate job** as `feedit_owner`: Drizzle migrations, the pg-boss schema and the queues
     (§1.2)
3. **Per worktree and package:** `feedit_test_<worktree-hash>_<package>`, created with
   `CREATE DATABASE … TEMPLATE feedit_template_<h> OWNER feedit_owner` (dropped and recreated per run).
4. **E2E and eval dry-run databases** are created the same way, from the template for the current
   journal, and are then **seeded** (`pnpm db:seed`) before any process uses them.

Parallel sessions and packages therefore never share a test database (spec 01 §6).

### 1.2 Privileges (first migration, run as `feedit_owner`)

**Defaults**, which cover every later migration automatically:

```sql
GRANT USAGE ON SCHEMA public TO feedit_app, feedit_worker;
ALTER DEFAULT PRIVILEGES FOR ROLE feedit_owner IN SCHEMA public GRANT SELECT ON TABLES TO feedit_app;
ALTER DEFAULT PRIVILEGES FOR ROLE feedit_owner IN SCHEMA public GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO feedit_worker;
ALTER DEFAULT PRIVILEGES FOR ROLE feedit_owner IN SCHEMA public GRANT USAGE, SELECT ON SEQUENCES TO feedit_app, feedit_worker;
ALTER DEFAULT PRIVILEGES FOR ROLE feedit_owner REVOKE EXECUTE ON FUNCTIONS FROM PUBLIC;
```

**Explicit write privileges for `feedit_app`.** Every migration that adds a table appends its row here
(and to the migration):

| Table | `feedit_app` may | Needed for |
|---|---|---|
| `users` | `INSERT, UPDATE` | signup, `PATCH /me`, soft delete, `invites_left`, `last_active_at`, admin edits |
| `settings` | `INSERT, UPDATE` | admin settings, breaker reset request, alert state |
| `login_codes`, `sessions`, `invites`, `waitlist` | `INSERT, UPDATE, DELETE` | auth, invites |
| `feeds` | `INSERT`; `UPDATE (min_interval_s, fetch_options, status, consecutive_errors, first_error_at, quarantined_until, quarantine_count, next_fetch_at, subscriber_count, updated_at)` | subscribe, admin reset |
| `story_clusters` | `INSERT, UPDATE` | mute-story creates a cluster |
| `articles` | `UPDATE (story_cluster_id)` | mute-story |
| `interest_cards` | `INSERT`; `UPDATE (retired_at, title, topic_ids, i18n, slug, visibility)` | card create/reuse (un-retire), admin library and promotion (`shared` → `public`). **Never** the text or examples: cards are immutable (spec 05 §5.1) |
| `feedback_events` | `INSERT` | reader actions (append-only; rows disappear only through `ON DELETE CASCADE` when the worker purges a user) |
| every **other** per-user table (§4) | `INSERT, UPDATE, DELETE` | RLS applies |
| `drizzle.__drizzle_migrations` | `SELECT` (with `USAGE ON SCHEMA drizzle`) | `/readyz` |

**pg-boss** (pg-boss 10; the schema is created by a migration and never by a running process):

```sql
-- migration: execute the SQL returned by PgBoss.getConstructionPlans('pgboss'), then:
GRANT USAGE ON SCHEMA pgboss TO feedit_app, feedit_worker;
GRANT SELECT, INSERT ON ALL TABLES IN SCHEMA pgboss TO feedit_app;                 -- send() and backlog stats
GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA pgboss TO feedit_worker;
GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA pgboss TO feedit_app, feedit_worker;
GRANT EXECUTE ON ALL FUNCTIONS IN SCHEMA pgboss TO feedit_app, feedit_worker;
ALTER DEFAULT PRIVILEGES FOR ROLE feedit_owner IN SCHEMA pgboss GRANT SELECT, INSERT ON TABLES TO feedit_app;
ALTER DEFAULT PRIVILEGES FOR ROLE feedit_owner IN SCHEMA pgboss GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO feedit_worker;
```

**Queues:**
- Created by the migrate job (as the owner) with `createQueue(name, options)` for every entry of
  `packages/shared/src/jobs.ts` (spec 03 §2), so per-queue partitions are owned by `feedit_owner` and
  covered by the default privileges.
- Every process starts pg-boss with `migrate: false`. The API also uses
  `supervise: false, schedule: false` (send only). The worker supervises and runs the cron schedules.
- If the pinned pg-boss version differs in these mechanics, keep the requirement: the owner creates the
  schema and queues, the API can only send and read counts, and the worker does everything else. Log
  the adaptation (spec 01 §9).

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
  invites_left    int  NOT NULL DEFAULT 3,
  preferences     jsonb NOT NULL DEFAULT '{}',    -- schema: spec 08 §3.1
  created_at      timestamptz NOT NULL DEFAULT now(),
  last_active_at  timestamptz NULL,
  deleted_at      timestamptz NULL                -- soft delete for 7 days, then hard delete (spec 11)
);

CREATE TABLE login_codes (
  id            bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  email         citext NOT NULL,
  code_hash     text NOT NULL,                    -- sha256(code + pepper)
  invite_code   text NULL,
  locale        text NULL,                        -- locale requested at signup
  expires_at    timestamptz NOT NULL,
  attempts      int NOT NULL DEFAULT 0,
  consumed_at   timestamptz NULL,
  requested_ip  inet NULL,
  created_at    timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX login_codes_email_idx ON login_codes (email, created_at DESC);

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
  locale       text NOT NULL DEFAULT 'en',
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
| `engine.circuit` | `{typesafe: Breaker, llm: Breaker, resetRequested: {typesafe?: iso, llm?: iso}}` with `Breaker = {state: 'closed'\|'open'\|'half_open'\|'auth', openedAt?, openUntil?, reopenCount}` | all closed | worker routers (state); admin (reset request only) |
| `engine.budget_alerts` | `{day: 'YYYY-MM-DD', p80At?: iso, p100At?: iso}` | — | worker router (records crossings only; spec 04 §6) |
| `engine.laya` | `{enrich?: string[]}` (language codes) | `{}` | admin (M9) |
| `language_modes` | `{[lang]: 'native'\|'translate'}` | env `LANGUAGE_MODES` | admin, `apply-g1` |
| `card_text_mode` | `'as_written'\|'english'` | `'as_written'` | admin, `apply-g1` |
| `translate.tier2_daily_cap` | int | 300 | admin, `apply-g1` |
| `ranker.thresholds` | deep partial of `RankerConfig` (spec 06 §11) | `{}` | admin, `apply-g1` |
| `ranker.settings_version` | int | 0 | bumped by the API on ranking-relevant settings changes, and by `eval apply-g1` (spec 06 §7) |
| `question_sets.active` | `{enrich?: id, match?: id, cluster?: id, suggest?: id}` | `{}` | seed (only when a kind is absent), admin |
| `signup_mode` | `'invite'\|'open'\|'closed'` | env `SIGNUP_MODE` | admin |
| `ops.events` | `[{kind, detail, at}]`, the last 50 | `[]` | `POST /admin/ops-event` |
| `alerts.state` | `{[alertKey]: {firstAt, lastSentAt, active}}` | `{}` | `house.alerts` |
| `metrics.daily.<YYYY-MM-DD>` | metrics JSON (spec 10 §7) | — | `house.metrics` |
| `worker.heartbeat` | `{[processId]: {at: iso, queues: string[], evalIngestOnly: boolean}}` | `{}` | every worker process, every 30 s (entries older than 1 h are pruned). `eval ingest-sample` needs an entry younger than 90 s with `evalIngestOnly = true` (spec 10 §2.1) |

`pnpm db:seed` inserts **only** `card_text_mode` and `question_sets.active = {}` when they are missing.
Keys with an env fallback are never seeded, so the env default stays effective until an admin sets a
value.

---

## 3. Shared article layer (no RLS)

```sql
CREATE TABLE feeds (
  id                 bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  url                text NOT NULL UNIQUE,        -- canonical feed URL (spec 03 §5)
  site_url           text NULL,
  title              text NULL,
  description        text NULL,
  icon_url           text NULL,
  lang_hint          text NULL,                   -- ISO 639-1, from <language> or majority of detected items
  status             text NOT NULL DEFAULT 'active'
                       CHECK (status IN ('active','quarantined','dead','paused')),
  etag               text NULL,
  last_modified      text NULL,
  fetch_interval_s   int  NOT NULL DEFAULT 900,
  min_interval_s     int  NOT NULL DEFAULT 900,   -- min over subscribers' plans (spec 08 §6)
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
  subscriber_count   int NOT NULL DEFAULT 0,
  publish_stats      jsonb NOT NULL DEFAULT '{}', -- {recent_gaps_s: int[≤20], items_7d: int}
  fetch_options      jsonb NOT NULL DEFAULT '{}', -- {user_agent?: string, translate_strong?: boolean}
  created_at         timestamptz NOT NULL DEFAULT now(),
  updated_at         timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX feeds_due_idx ON feeds (next_fetch_at) WHERE subscriber_count > 0 AND status IN ('active','quarantined');

CREATE TABLE story_clusters (
  id                         bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  representative_article_id  bigint NULL,          -- FK added after articles exists
  size                       int NOT NULL DEFAULT 1,
  created_at                 timestamptz NOT NULL DEFAULT now(),
  updated_at                 timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE articles (
  id               bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  url              text NOT NULL,                  -- as published (best link)
  canonical_url    text NOT NULL,                  -- spec 03 §5
  url_key          text NOT NULL UNIQUE,           -- canonical_url without scheme; 'urn:feedit:<feed_id>:<sha1(guid)>' if linkless
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
  lang_confidence  real NULL,
  word_count       int NULL,
  content_hash     text NOT NULL,                  -- spec 03 §6.2
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
CREATE INDEX feed_items_feed_time_idx ON feed_items (feed_id, first_seen_at DESC);
CREATE UNIQUE INDEX feed_items_guid_idx ON feed_items (feed_id, guid) WHERE guid IS NOT NULL;

CREATE TABLE article_aliases (                     -- other URLs that resolve to the same article
  url_key     text PRIMARY KEY,
  article_id  bigint NOT NULL REFERENCES articles(id) ON DELETE CASCADE,
  source      text NOT NULL CHECK (source IN ('feed_link','redirect','rel_canonical','near_duplicate'))
);

CREATE TABLE article_bodies (
  article_id         bigint PRIMARY KEY REFERENCES articles(id) ON DELETE CASCADE,
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

### 3.1 Model answers (append-only, versioned)

```sql
CREATE TABLE question_sets (
  id          bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  kind        text NOT NULL CHECK (kind IN ('enrich','match','cluster','suggest')),
  version     text NOT NULL,                     -- e.g. 'enrich-v1'
  sha256      text NOT NULL UNIQUE,              -- of the canonical JSON definition (spec 05 §2)
  definition  jsonb NOT NULL,                    -- static part; match sets store the builder template
  created_at  timestamptz NOT NULL DEFAULT now()
);
-- settings key 'question_sets.active' = {"enrich": <id>, "match": <id>, "cluster": <id>, "suggest": <id>}

CREATE TABLE engine_calls (
  id              bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  engine          text NOT NULL CHECK (engine IN ('typesafe','llm','laya','libretranslate')),
  kind            text NOT NULL CHECK (kind IN ('enrich','match','cluster','suggest','translate','eval')),
  model           text NULL,
  article_id      bigint NULL REFERENCES articles(id) ON DELETE SET NULL,
  question_set_id bigint NULL REFERENCES question_sets(id),
  card_ids        bigint[] NULL,
  user_id         uuid NULL,                     -- set for user-attributed calls (backfills, suggest)
  n_questions     int NOT NULL DEFAULT 0,
  input_tokens    int NOT NULL DEFAULT 0,
  output_tokens   int NOT NULL DEFAULT 0,
  cost_usd        numeric(12,8) NOT NULL DEFAULT 0,
  latency_ms      int NULL,
  attempts        int NOT NULL DEFAULT 1,
  status          text NOT NULL CHECK (status IN ('ok','error','timeout','rate_limited','invalid_request',
                                                  'invalid_response','auth_error')),
  error           text NULL,
  created_at      timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX engine_calls_created_idx ON engine_calls (created_at);
CREATE INDEX engine_calls_article_idx ON engine_calls (article_id);

CREATE TABLE article_facets (                     -- Call A answers
  article_id       bigint NOT NULL REFERENCES articles(id) ON DELETE CASCADE,
  question_set_id  bigint NOT NULL REFERENCES question_sets(id),
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
  parent_id    text NULL REFERENCES topics(id),
  level        smallint NOT NULL CHECK (level IN (1,2)),
  name_en      text NOT NULL,
  name_sk      text NOT NULL,
  description  text NOT NULL,
  sort         int NOT NULL DEFAULT 0
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
  question_set_sha  text NOT NULL,
  state_variant     text NOT NULL CHECK (state_variant IN ('native','translated')),
  answered_at       timestamptz NOT NULL DEFAULT now(),   -- set to now() on every insert AND upsert
  PRIMARY KEY (article_id, card_id)
);
CREATE INDEX card_answers_card_idx ON card_answers (card_id);

CREATE TABLE article_topics_l2 (                  -- Call B side-questions: level-2 topic answers
  article_id  bigint NOT NULL REFERENCES articles(id) ON DELETE CASCADE,
  l1_id       text NOT NULL REFERENCES topics(id),
  answer      jsonb NOT NULL,                     -- normalized Choice answer over the L1's children
  created_at  timestamptz NOT NULL DEFAULT now(),
  PRIMARY KEY (article_id, l1_id)
);

CREATE TABLE match_queue (                        -- pending (article, card) questions; coalesced per article
  article_id   bigint NOT NULL REFERENCES articles(id) ON DELETE CASCADE,
  card_id      bigint NOT NULL REFERENCES interest_cards(id) ON DELETE CASCADE,
  priority     smallint NOT NULL DEFAULT 5,       -- 1 = interactive … 9 = bulk
  user_id      uuid NULL,                         -- who caused it (backfills), for cost attribution
  attempts     smallint NOT NULL DEFAULT 0,
  enqueued_at  timestamptz NOT NULL DEFAULT now(),
  PRIMARY KEY (article_id, card_id)
);
CREATE INDEX match_queue_order_idx ON match_queue (priority, enqueued_at);

CREATE TABLE feed_cards (                         -- which cards to ask for articles of a feed
  feed_id  bigint NOT NULL REFERENCES feeds(id) ON DELETE CASCADE,
  card_id  bigint NOT NULL REFERENCES interest_cards(id) ON DELETE CASCADE,
  holders  int NOT NULL,
  PRIMARY KEY (feed_id, card_id)
);

CREATE TABLE usage_daily (                        -- cost attribution rollup (spec 04 §7)
  day            date NOT NULL,
  user_id        uuid NOT NULL,                   -- '00000000-0000-0000-0000-000000000000' = platform
  engine         text NOT NULL,
  kind           text NOT NULL,
  calls          int NOT NULL DEFAULT 0,
  input_tokens   bigint NOT NULL DEFAULT 0,
  output_tokens  bigint NOT NULL DEFAULT 0,
  cost_usd       numeric(14,8) NOT NULL DEFAULT 0,
  PRIMARY KEY (day, user_id, engine, kind)
);
```

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
  card_id        bigint NOT NULL REFERENCES interest_cards(id) ON DELETE RESTRICT,
  strength       text NOT NULL CHECK (strength IN ('must','love','like','never')),
  scope_feed_id  bigint NULL REFERENCES feeds(id) ON DELETE CASCADE,   -- feed-scoped card
  title_override text NULL,                        -- the user's own name for the card (cards are shared and immutable)
  created_at     timestamptz NOT NULL DEFAULT now(),
  updated_at     timestamptz NOT NULL DEFAULT now(),
  PRIMARY KEY (user_id, card_id)
);
CREATE INDEX user_cards_card_idx ON user_cards (card_id);

CREATE TABLE user_labels (
  user_id     uuid NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  card_id     bigint NOT NULL REFERENCES interest_cards(id) ON DELETE RESTRICT,  -- kind = 'label'
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
  p_like             real NULL,
  score_source       text NOT NULL DEFAULT 'none'
                       CHECK (score_source IN ('none','cards','model','degraded')),
  rules_fired        text[] NOT NULL DEFAULT '{}',
  explain            jsonb NULL,                  -- spec 06 §6
  label_suggestions  bigint[] NOT NULL DEFAULT '{}',
  score_version      int NOT NULL DEFAULT 0,
  scored_at          timestamptz NULL,
  -- reader state (written by the API)
  opened_at          timestamptz NULL,
  read_at            timestamptz NULL,
  rating             smallint NULL CHECK (rating IN (-1, 1)),
  reason             text NULL CHECK (reason IN ('off_topic','clickbait','seen','shallow','promo','other')),
  rated_at           timestamptz NULL,
  dwell_ms           int NULL,
  bookmarked_at      timestamptz NULL,
  archived_at        timestamptz NULL,
  label_ids          bigint[] NOT NULL DEFAULT '{}',
  feedback_prompted_at timestamptz NULL,
  PRIMARY KEY (user_id, article_id)
);
CREATE INDEX user_article_lane_idx ON user_article (user_id, lane, p_like DESC)
  WHERE archived_at IS NULL AND read_at IS NULL;
CREATE INDEX user_article_bookmarks_idx ON user_article (user_id, bookmarked_at DESC)
  WHERE bookmarked_at IS NOT NULL;

CREATE TABLE feedback_events (                    -- append-only training log
  id          bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  user_id     uuid NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  article_id  bigint NOT NULL REFERENCES articles(id) ON DELETE CASCADE,
  kind        text NOT NULL CHECK (kind IN ('rate','unrate','open','read','unread','dwell','prompt_answer',
                                            'bookmark','unbookmark','label','unlabel','mark_read','hide')),
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

CREATE TABLE card_suggestions (
  user_id       uuid NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  card_id       bigint NOT NULL REFERENCES interest_cards(id) ON DELETE CASCADE,
  score         real NOT NULL,
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
- `interest_cards` with `visibility = 'private'` are readable through the API only when
  `owner_user_id` equals the tenant. This is enforced in the repository, not by RLS, because the
  table is shared.

---

## 6. SQL functions (SECURITY DEFINER, owned by the BYPASSRLS `feedit_owner`)

```sql
-- Recompute feed_cards for the given feeds: cards and labels held by any (non-deleted) subscriber,
-- respecting card scope.
CREATE FUNCTION refresh_feed_cards(p_feed_ids bigint[]) RETURNS void
LANGUAGE sql SECURITY DEFINER SET search_path = public, pg_temp AS $$
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
$$;

-- Keep feeds.subscriber_count and feeds.min_interval_s in sync.
-- p_plan_min_interval = {"beta": 900, "admin": 300}, built from packages/shared/src/plans.ts.
CREATE FUNCTION refresh_feed_subscribers(p_feed_ids bigint[], p_plan_min_interval jsonb) RETURNS void
LANGUAGE sql SECURITY DEFINER SET search_path = public, pg_temp AS $$
  UPDATE feeds f
     SET subscriber_count = coalesce(x.cnt, 0),
         min_interval_s   = coalesce(x.min_iv, 900),
         updated_at       = now()
    FROM (SELECT unnest(p_feed_ids) AS feed_id) ids
    LEFT JOIN (
      SELECT s.feed_id, count(*) AS cnt,
             min(coalesce((p_plan_min_interval ->> u.plan)::int, 900)) AS min_iv
      FROM subscriptions s JOIN users u ON u.id = s.user_id AND u.deleted_at IS NULL
      WHERE s.feed_id = ANY(p_feed_ids)
      GROUP BY s.feed_id) x ON x.feed_id = ids.feed_id
   WHERE f.id = ids.feed_id;
$$;

-- Admin statistics: how many users hold each card (as interest or label).
CREATE FUNCTION admin_card_holders(p_card_ids bigint[]) RETURNS TABLE (card_id bigint, holders int)
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public, pg_temp AS $$
  SELECT c.id,
         ((SELECT count(*) FROM user_cards uc WHERE uc.card_id = c.id)
        + (SELECT count(*) FROM user_labels ul WHERE ul.card_id = c.id))::int
  FROM unnest(p_card_ids) AS c(id);
$$;

-- Admin usage: per-user attributed cost over the last p_days UTC days.
-- direct = user-attributed usage_daily rows (backfills, suggestions, fork questions);
-- shared = platform 'match' cost × (Σ over the user's (feed, card) holdings of 1/holders) / count(feed_cards).
CREATE FUNCTION admin_usage_attribution(p_days int)
RETURNS TABLE (user_id uuid, direct_usd numeric, shared_usd numeric)
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public, pg_temp AS $$
  WITH win AS (SELECT * FROM usage_daily WHERE day > (now() AT TIME ZONE 'UTC')::date - p_days),
  direct AS (SELECT w.user_id, sum(w.cost_usd) AS usd FROM win w
             WHERE w.user_id <> '00000000-0000-0000-0000-000000000000' GROUP BY w.user_id),
  m AS (SELECT coalesce(sum(cost_usd), 0) AS usd FROM win
        WHERE user_id = '00000000-0000-0000-0000-000000000000' AND kind = 'match'),
  tot AS (SELECT greatest(count(*), 1) AS n FROM feed_cards),
  holding AS (
    SELECT s.user_id, fc.holders
    FROM feed_cards fc
    JOIN subscriptions s ON s.feed_id = fc.feed_id
    JOIN (SELECT user_id, card_id, scope_feed_id FROM user_cards
          UNION ALL SELECT user_id, card_id, NULL::bigint FROM user_labels) x
      ON x.user_id = s.user_id AND x.card_id = fc.card_id
     AND (x.scope_feed_id IS NULL OR x.scope_feed_id = fc.feed_id)),
  shared AS (SELECT h.user_id, sum(1.0 / h.holders) AS share FROM holding h GROUP BY h.user_id)
  SELECT coalesce(d.user_id, sh.user_id), coalesce(d.usd, 0),
         coalesce(sh.share, 0) * (SELECT usd FROM m) / (SELECT n FROM tot)
  FROM direct d FULL JOIN shared sh ON sh.user_id = d.user_id;
$$;

REVOKE EXECUTE ON FUNCTION refresh_feed_cards(bigint[]), refresh_feed_subscribers(bigint[], jsonb),
  admin_card_holders(bigint[]), admin_usage_attribution(int) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION refresh_feed_cards(bigint[]), refresh_feed_subscribers(bigint[], jsonb),
  admin_card_holders(bigint[]), admin_usage_attribution(int) TO feedit_app, feedit_worker;
```

**Callers:**
- **The refresh functions** are called by the API **in the same transaction** as the change that
  affects them: subscribe/unsubscribe, card add/remove/scope change, label add/remove, account delete
  and restore. `house.reconcile` (spec 11) also runs them nightly for all feeds.
- **The `admin_*` functions** are called only from admin routes, after the role check (spec 08 §9).

**Tests** (M0-T5). Both refresh functions give correct rows when called:
- (a) as `feedit_app` inside `withTenant(A)`, with users A and B both subscribed and holding different
  cards
- (b) as `feedit_worker` with no `app.user_id`

Neither may drop B's rows.

---

## 7. Evaluation schema (`eval`, created in M3)

```sql
CREATE SCHEMA eval;
CREATE TABLE eval.raters (id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY, name text NOT NULL,
  token_hash text NOT NULL UNIQUE, langs text[] NOT NULL, created_at timestamptz NOT NULL DEFAULT now());
CREATE TABLE eval.rater_cards (rater_id bigint REFERENCES eval.raters(id) ON DELETE CASCADE,
  card_id bigint REFERENCES interest_cards(id), strength text NOT NULL, PRIMARY KEY (rater_id, card_id));
CREATE TABLE eval.rater_feeds (rater_id bigint REFERENCES eval.raters(id) ON DELETE CASCADE,
  feed_id bigint REFERENCES feeds(id), PRIMARY KEY (rater_id, feed_id));
CREATE TABLE eval.assignments (rater_id bigint REFERENCES eval.raters(id) ON DELETE CASCADE,
  article_id bigint REFERENCES articles(id), position int NOT NULL, PRIMARY KEY (rater_id, article_id));
CREATE TABLE eval.ratings (rater_id bigint REFERENCES eval.raters(id) ON DELETE CASCADE,
  article_id bigint REFERENCES articles(id), rating smallint NOT NULL CHECK (rating IN (-1, 1)),
  reason text NULL, created_at timestamptz NOT NULL DEFAULT now(), PRIMARY KEY (rater_id, article_id));
CREATE TABLE eval.facet_labels (labeler text NOT NULL, article_id bigint REFERENCES articles(id),
  question_key text NOT NULL, value text NOT NULL, created_at timestamptz NOT NULL DEFAULT now(),
  PRIMARY KEY (article_id, question_key, labeler));
CREATE TABLE eval.sample (article_id bigint PRIMARY KEY REFERENCES articles(id), lang text NOT NULL,
  created_at timestamptz NOT NULL DEFAULT now());
CREATE TABLE eval.runs (id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY, experiment text NOT NULL,
  config jsonb NOT NULL, git_sha text NOT NULL, started_at timestamptz NOT NULL DEFAULT now(),
  finished_at timestamptz NULL, results jsonb NULL);
CREATE TABLE eval.run_answers (run_id bigint REFERENCES eval.runs(id) ON DELETE CASCADE,
  article_id bigint NOT NULL, card_id bigint NULL, question_key text NOT NULL, answer jsonb NOT NULL);
CREATE INDEX run_answers_run_idx ON eval.run_answers (run_id, article_id);
GRANT USAGE ON SCHEMA eval TO feedit_worker;
GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA eval TO feedit_worker;
GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA eval TO feedit_worker;
ALTER DEFAULT PRIVILEGES FOR ROLE feedit_owner IN SCHEMA eval GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO feedit_worker;
```

- The `eval` schema is accessed only by `apps/eval`, through `DATABASE_URL_WORKER`.
- Rows referenced from `eval.*` (articles in `eval.sample`/`assignments`/`ratings`/`facet_labels`, and
  cards in `eval.rater_cards`) are exempt from purging and retiring (spec 11 §5–6).
