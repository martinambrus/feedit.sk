# Spec 02: Data model (PostgreSQL 16)

Status: **binding**. **Intent:** the shared article layer (feeds, articles, model answers) is global and
paid for once. Everything a person *does* is tenant data, protected by row-level security. Raw model
answers are kept so that changing a threshold never needs a new API call.

Conventions: `snake_case`; `bigint GENERATED ALWAYS AS IDENTITY` primary keys (users use UUID v7);
`timestamptz` everywhere, defaulting to `now()`. Enumerations are `text` columns with `CHECK`
constraints, not Postgres enums, so they are easy to migrate. Every foreign key states its `ON DELETE`
behaviour.

The Drizzle schema in `packages/db/src/schema/*.ts` must produce exactly this DDL. RLS policies,
roles, functions and trigram indexes are hand-written SQL migrations.

---

## 1. Roles and extensions (`infra/postgres/init.sql` + first migration)

```sql
CREATE EXTENSION IF NOT EXISTS citext;
CREATE EXTENSION IF NOT EXISTS pg_trgm;
CREATE EXTENSION IF NOT EXISTS pgcrypto;

CREATE ROLE feedit_owner  LOGIN PASSWORD :'owner_pw';           -- owns schema, runs migrations
CREATE ROLE feedit_app    LOGIN PASSWORD :'app_pw';             -- API; RLS enforced
CREATE ROLE feedit_worker LOGIN PASSWORD :'worker_pw' BYPASSRLS; -- worker; shared stages need cross-user reads
```

Grants (in the migration, after the tables exist):

- `feedit_app`:
  - `SELECT` on all tables.
  - `INSERT, UPDATE, DELETE` on the per-user tables (§4) and on `sessions`, `login_codes`, `invites`, `waitlist`.
  - `INSERT` on `feeds`, `interest_cards`, `match_queue`, `feedback_events`.
  - `UPDATE (subscriber_count)` on `feeds`.
  - `EXECUTE` on the functions in §6.
  - `USAGE` on all sequences.
- `feedit_worker`: `SELECT, INSERT, UPDATE, DELETE` on all tables; `USAGE` on all sequences.
- pg-boss creates its own `pgboss` schema, owned by `feedit_worker`.

---

## 2. Settings and accounts

```sql
CREATE TABLE settings (
  key         text PRIMARY KEY,               -- e.g. 'engine.daily_budget_usd', 'language_modes', 'ranker.thresholds'
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
  preferences     jsonb NOT NULL DEFAULT '{}',    -- schema: spec 08 §5.2
  created_at      timestamptz NOT NULL DEFAULT now(),
  last_active_at  timestamptz NULL,
  deleted_at      timestamptz NULL                -- soft delete for 7 days, then hard delete (spec 11)
);

CREATE TABLE login_codes (
  id            bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  email         citext NOT NULL,
  code_hash     text NOT NULL,                    -- sha256(code + pepper)
  invite_code   text NULL,
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
  title           text NOT NULL,                  -- short display name (≤ 60 chars)
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
  created_at        timestamptz NOT NULL DEFAULT now(),
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
  allow_duplicates  boolean NOT NULL DEFAULT false,  -- false = fold story clusters (spec 06 §3)
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
  kind        text NOT NULL CHECK (kind IN ('rate','unrate','open','read','dwell','prompt_answer',
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
- `feedit_worker` bypasses RLS and is used only by worker handlers and admin jobs.
- `interest_cards` with `visibility = 'private'` are readable through the API only when
  `owner_user_id` equals the tenant. This is enforced in the repository, not by RLS, because the
  table is shared.

---

## 6. SQL functions

```sql
-- Recompute feed_cards for the given feeds (cards held by any subscriber, respecting scope; plus labels).
CREATE FUNCTION refresh_feed_cards(p_feed_ids bigint[]) RETURNS void
LANGUAGE sql SECURITY DEFINER SET search_path = public AS $$
  DELETE FROM feed_cards WHERE feed_id = ANY(p_feed_ids);
  INSERT INTO feed_cards (feed_id, card_id, holders)
  SELECT s.feed_id, x.card_id, count(DISTINCT s.user_id)
  FROM subscriptions s
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
CREATE FUNCTION refresh_feed_subscribers(p_feed_ids bigint[]) RETURNS void
LANGUAGE sql SECURITY DEFINER SET search_path = public AS $$
  UPDATE feeds f SET subscriber_count = coalesce(x.cnt, 0), updated_at = now()
  FROM (SELECT unnest(p_feed_ids) AS feed_id) ids
  LEFT JOIN (SELECT feed_id, count(*) AS cnt FROM subscriptions
             WHERE feed_id = ANY(p_feed_ids) GROUP BY feed_id) x ON x.feed_id = ids.feed_id
  WHERE f.id = ids.feed_id;
$$;
```

Both are called by the API **in the same transaction** as the change that affects them:
subscribe/unsubscribe, card add/remove/scope change, label add/remove. `house.reconcile` (spec 11) also
runs them nightly for all feeds. `min_interval_s` is set by application code from the subscribers'
plans (spec 08 §6) in the same place.

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
CREATE TABLE eval.runs (id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY, experiment text NOT NULL,
  config jsonb NOT NULL, git_sha text NOT NULL, started_at timestamptz NOT NULL DEFAULT now(),
  finished_at timestamptz NULL, results jsonb NULL);
CREATE TABLE eval.run_answers (run_id bigint REFERENCES eval.runs(id) ON DELETE CASCADE,
  article_id bigint NOT NULL, card_id bigint NULL, question_key text NOT NULL, answer jsonb NOT NULL);
CREATE INDEX run_answers_run_idx ON eval.run_answers (run_id, article_id);
```

The `eval` schema is only accessed by `apps/eval` through `DATABASE_URL_WORKER`.
