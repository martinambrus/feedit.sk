# Spec 01: Architecture, repository layout and conventions

Status: **binding**. Everything built for FeedIt Next Gen follows this spec unless a later spec
explicitly overrides a point. Deviations are allowed only through the process in §9.

---

## 1. Stack (locked)

| Concern | Choice | Notes |
|---|---|---|
| Runtime | **Node.js 22 LTS** | ESM only (`"type": "module"`) |
| Language | **TypeScript 5.x**, `strict: true`, `noUncheckedIndexedAccess: true`, `exactOptionalPropertyTypes: true` | no `any` without an `// eslint-disable-next-line` and a reason |
| Package manager | **pnpm 9** workspaces | `packageManager` field pinned in root `package.json` |
| Build orchestration | **Turborepo** | tasks: `build`, `typecheck`, `lint`, `test`, `test:int`, `dev` |
| Database | **PostgreSQL 16** with extensions `citext`, `pg_trgm`, `pgcrypto` | the only stateful service |
| DB access | **Drizzle ORM** + `drizzle-kit` generated SQL migrations, checked in | RLS policies and functions are hand-written SQL migrations |
| Job queue | **pg-boss 10** (Postgres-backed) | queue names in [spec 03 §2](./03-ingestion.md) |
| HTTP API | **Fastify 5** + `fastify-type-provider-zod` + `zod` | OpenAPI generated with `@fastify/swagger` |
| Validation / DTOs | **zod** schemas in `packages/shared` | shared by the API and the web client |
| Outbound HTTP | **undici** with a custom SSRF-safe dispatcher ([spec 03 §4](./03-ingestion.md)) | never `node-fetch`, never `rejectUnauthorized: false` |
| Feed parsing | **rss-parser** (RSS 0.9x/1.0/2.0, Atom) + an in-house JSON Feed 1.0/1.1 parser | a fixture suite decides edge cases |
| Article extraction | **@mozilla/readability** on **linkedom** | fall back to `jsdom` only if the fixtures demand it |
| HTML sanitizing | **sanitize-html** | excerpts are stored as plain text plus a sanitized HTML variant |
| Language detection | **franc** (full build), restricted whitelist; `detectLanguage` lives in `packages/shared` ([spec 03 §8.3](./03-ingestion.md)) | |
| Decision model | **Jev over its documented HTTP API** (`POST /v1/systemone`), with a small client of our own in `packages/engine` | the official SDK is not used, so retries, budgets and logging stay under our control ([spec 04](./04-decision-engine.md)) |
| Generative LLM (fallback + translation tier 2) | **Ollama Cloud** HTTP API, `glm-5.3-flash` / `glm-5.3` | plain `undici` calls; no SDK |
| Machine translation (tier 1) | **LibreTranslate** container (Argos models `sk→en`, `cs→en`) | [spec 07](./07-translation.md) |
| Email | **nodemailer** over SMTP | |
| Web client | **React 19 + Vite + TanStack Router + TanStack Query + Tailwind CSS 4**, **vite-plugin-pwa**, **i18next** (en, sk) | gestures: `@use-gesture/react` |
| Unit / integration tests | **Vitest** | integration tests use a real Postgres from `docker compose -f compose.test.yml` |
| E2E tests | **Playwright** (Chromium) | smoke flows only |
| Logging | **pino** (JSON to stdout) | `pino-pretty` in dev |
| Metrics / traces | Prometheus text endpoint `/metrics` (`prom-client`); OpenTelemetry traces **optional** (env-gated) | |
| Reverse proxy / TLS | **Caddy 2** | serves the web build and proxies `/api` |
| Deployment | **docker compose** on one box (8 vCPU / 16–32 GB, no GPU) | [spec 11](./11-operations.md) |

---

## 2. Repository layout

The next-gen app lives in its **own repository** (working name `feedit-ng`). The paths below are
relative to that repository's root. M0 starts from an empty repository that contains only this plan,
copied to `docs/` (`docs/PLAN.md`, `docs/specs/`, `docs/background.md`, `docs/laya-multilingual.md`).

```
feedit-ng/
├── apps/
│   ├── api/                      # Fastify HTTP API (spec 08)
│   │   ├── src/
│   │   │   ├── server.ts         # buildServer(): registers plugins + routes; no listen()
│   │   │   ├── main.ts           # reads config, builds server, listen(), graceful shutdown
│   │   │   ├── plugins/          # auth.ts, tenant.ts (RLS), rate-limit.ts, errors.ts, swagger.ts, csrf.ts
│   │   │   ├── routes/           # auth.ts, me.ts, invites.ts, subscriptions.ts, cards.ts, library.ts,
│   │   │   │                     # labels.ts, articles.ts, rules.ts, admin/*.ts, health.ts
│   │   │   └── services/         # thin orchestration over packages/* (no SQL here)
│   │   └── test/
│   ├── worker/                   # pg-boss consumers + cron (specs 03–07, 11)
│   │   ├── src/
│   │   │   ├── main.ts           # starts pg-boss, registers handlers by WORKER_QUEUES env
│   │   │   ├── handlers/         # one file per queue: feed-schedule.ts, feed-fetch.ts, article-extract.ts,
│   │   │   │                     # article-translate.ts, article-enrich.ts, article-cluster.ts,
│   │   │   │                     # article-match.ts, user-rank.ts, user-learn.ts, card-backfill.ts,
│   │   │   │                     # house-*.ts
│   │   │   ├── pipeline.ts       # helpers to enqueue the next stage (single source of truth for ordering)
│   │   │   ├── seed.ts           # `pnpm db:seed`: settings defaults, taxonomy, question sets, card library
│   │   │   └── cli.ts            # dev CLI: feeds:add, feeds:fetch-now, feeds:show (M1-T9)
│   │   └── test/
│   ├── web/                      # PWA (spec 09)
│   │   ├── src/
│   │   │   ├── routes/           # TanStack Router file routes
│   │   │   ├── components/
│   │   │   ├── features/         # reader/, onboarding/, cards/, labels/, feeds/, rules/, settings/, admin/, auth/
│   │   │   ├── api/              # typed client generated from packages/shared DTOs
│   │   │   ├── i18n/             # common.en.json, common.sk.json (feature strings live in features/<f>/i18n/)
│   │   │   └── sw/               # service-worker extras
│   │   └── e2e/                  # Playwright *.pw.ts
│   └── eval/                     # evaluation CLI + rating web pages (spec 10)
│       ├── src/
│       │   ├── cli.ts            # commander entry: ingest-sample, sample, status, rater, serve-rating, run,
│       │   │                     # report, apply-g1, dry-run, replay, learning-curve
│       │   ├── rating-server/    # tiny Fastify app + static HTML for raters and facet labelling
│       │   ├── experiments/      # one file per experiment (B0, B1, E1–E5)
│       │   ├── metrics/          # auc.ts, precision-at-k.ts, calibration.ts, bootstrap.ts
│       │   └── report/           # markdown + HTML report writer
│       └── reports/              # generated reports (git-ignored except committed decision reports)
├── packages/
│   ├── shared/                   # config (zod env, per process), settings registry (settings.ts), DTO schemas
│   │                             # (incl. dto/explain.ts), jobs.ts (queues, payloads, enqueue helpers), plans.ts,
│   │                             # error types, ids, time utils, logger factory, mail/ (mailer + templates),
│   │                             # text utils: normalizeText, detectLanguage, canonicalJson, sha256Hex
│   ├── db/                       # Drizzle schema, migrations, repositories, RLS helpers (spec 02)
│   ├── feeds/                    # fetch (SSRF-safe), parse, canonicalize, dedup keys, extract, OPML (spec 03)
│   ├── engine/                   # DecisionEngine, EngineRouter, TypeSafe/LlmFallback/Laya engines, breaker,
│   │                             # budget; persistence only through the EngineStore port (spec 04)
│   ├── questions/                # question sets, builders, taxonomy, card library seed, hashing (spec 05)
│   ├── translate/                # LibreTranslate + Ollama Cloud translators, quality heuristics (spec 07)
│   ├── ranker/                   # rules, lanes, BM25 baseline, features, logistic model, calibration (spec 06)
│   └── testing/                  # fixtures (feeds, HTML pages, Jev responses), factories, per-worktree test DBs,
│                                 # fixture feed server, fake TypeSafe server (spec 04 §10), feed generator
├── infra/
│   ├── compose.yml               # production stack (spec 11)
│   ├── compose.dev.yml           # dev overrides (ports, volumes, hot reload)
│   ├── compose.test.yml          # Postgres for integration tests
│   ├── caddy/Caddyfile
│   ├── postgres/init.sh          # database, roles, extensions (spec 02 §1.1)
│   └── scripts/                  # deploy.sh, backup.sh, restore-test.sh
├── docs/                         # copied from this plan: PLAN.md, specs/, DECISIONS.md
├── .github/workflows/ci.yml
├── turbo.json, pnpm-workspace.yaml, tsconfig.base.json, eslint.config.js, .prettierrc, .env.example
└── CLAUDE.md                     # agent working agreement (§10)
```

**Package names** are `@feedit/<directory>` (`@feedit/api`, `@feedit/shared`, `@feedit/web`, …). The
root `package.json` defines these shortcut scripts, and every doc uses them:

| Root script | Runs |
|---|---|
| `pnpm dev` | `turbo run dev --filter=@feedit/api --filter=@feedit/worker --filter=@feedit/web` |
| `pnpm typecheck`, `pnpm lint`, `pnpm test`, `pnpm test:int`, `pnpm build` | `turbo run <task>` |
| `pnpm e2e` | `pnpm --filter @feedit/web e2e` (Playwright, spec 09 §9) |
| `pnpm db:migrate` | `pnpm --filter @feedit/db migrate` |
| `pnpm db:seed` | `pnpm --filter @feedit/worker seed` |
| `pnpm evaluate <command> …` | `pnpm --filter @feedit/eval cli <command> …` (spec 10) |
| `pnpm worker-cli <command> …` | `pnpm --filter @feedit/worker cli <command> …` (M1-T9) |

**Dependency rules** (enforced with `eslint-plugin-boundaries` or `dependency-cruiser` in CI):

- `apps/*` may import any `packages/*`. Apps never import each other.
- `packages/shared` imports nothing internal.
- `packages/db` imports only `shared`. It implements ports defined elsewhere (e.g. `EngineStore`) by
  importing their **types** from `shared` (the port interfaces live in `shared/src/ports.ts`).
- `packages/feeds`, `packages/translate` and `packages/questions` import only `shared`.
- `packages/engine` imports `shared` and `questions` (types only). It persists nothing itself: calls,
  usage and settings go through the injected `EngineStore` (spec 04 §1).
- `packages/ranker` imports `shared` and `questions` (types only). It is **pure**: no I/O, no DB access,
  all inputs passed in.
- `packages/testing` may import any package (it is only used by tests).
- **Repositories return effects, apps enqueue jobs.** `packages/db` never imports pg-boss. Repository
  functions return effect descriptors (e.g. `{refreshFeedIds, backfill, rankFull}`). App services
  enqueue jobs after commit through `shared/src/jobs.ts`.
- Only `packages/engine` talks to Jev, Ollama Cloud (as a decision engine) or Laya. Only `packages/translate` talks to LibreTranslate and to Ollama Cloud for translation.

---

## 3. Configuration

All configuration comes from environment variables, parsed once at startup by
`loadConfig({ process: 'api' | 'worker' | 'eval' | 'migrate' | 'test' })` in
`packages/shared/src/config.ts` with zod. "Required" in the table means required **for the processes
listed under "Used by"**. An invalid config makes the process exit with a readable error.
`.env.example` lists every variable with a comment.

| Variable | Default | Used by | Meaning |
|---|---|---|---|
| `NODE_ENV` | `development` | all | `development` / `test` / `production` |
| `DATABASE_URL` | — (required) | all | connection as role `feedit_app` (RLS enforced) |
| `DATABASE_URL_WORKER` | — (required) | worker, eval | connection as role `feedit_worker` (BYPASSRLS) |
| `DATABASE_URL_MIGRATE` | — (required) | migrate | connection as role `feedit_owner` |
| `TEST_ADMIN_DATABASE_URL` | `postgres://postgres:postgres@localhost:${PG_TEST_PORT}/postgres` | test | superuser connection used only to create per-worktree test databases (spec 02 §1.1) |
| `POSTGRES_PASSWORD`, `FEEDIT_OWNER_PASSWORD`, `FEEDIT_APP_PASSWORD`, `FEEDIT_WORKER_PASSWORD` | — | compose / `init.sh` | database bootstrap only; never read by the apps |
| `PUBLIC_BASE_URL` | `http://localhost:5173` | api, email | used in links and the fetcher User-Agent |
| `API_PORT` | `3000` | api | |
| `SESSION_COOKIE_NAME` | `fi_sid` | api | |
| `SESSION_TTL_DAYS` | `60` | api | sliding expiry |
| `SESSION_PEPPER` | — (required) | api | secret mixed into login-code hashes |
| `MAIL_TRANSPORT` | `smtp` (`log` in development/test) | api, worker | `log` writes emails to the console and keeps the last one for the test-only endpoint |
| `SMTP_URL` | — (required in production) | api, worker | `smtp://user:pass@host:587` (the worker sends alerts, spec 11 §6.1) |
| `MAIL_FROM` | `FeedIt <no-reply@localhost>` | api, worker | |
| `SIGNUP_MODE` | `invite` | api | `invite` / `open` / `closed`; `settings['signup_mode']` overrides it |
| `RATE_LIMITS_ENABLED` | `true` | api | `false` only for E2E/load tests with `NODE_ENV=test`; refused in production |
| `TYPESAFE_API_KEY` | — | worker, eval | Jev key; if missing, the engine runs in Degraded mode |
| `TYPESAFE_MODEL` | `jev-1.13.0` | worker, eval | always a pinned version in production |
| `TYPESAFE_BASE_URL` | `https://api.typesafe.ai` | worker, eval | |
| `TYPESAFE_PRICE_PER_MTOK_USD` | `0.042` | worker | cost accounting |
| `ENGINE_CONCURRENCY` | `8` | worker | max in-flight Jev calls per worker process |
| `DAILY_BUDGET_USD` | `2.00` | worker | spend guard ([spec 04 §6](./04-decision-engine.md)) |
| `OLLAMA_API_KEY` | — | worker, eval | Ollama Cloud; if missing, tier-2 translation and LLM fallback are disabled |
| `OLLAMA_BASE_URL` | `https://ollama.com` | worker, eval | |
| `OLLAMA_MODEL_FAST` | `glm-5.3-flash` | worker | |
| `OLLAMA_MODEL_STRONG` | `glm-5.3` | worker | |
| `OLLAMA_MAX_CONCURRENCY` | `1` | worker | match the Ollama plan (Free 1, Pro 3, Max 10) |
| `LLM_FALLBACK_ENABLED` | `false` | worker | enables `LlmFallbackEngine` in the fallback chain |
| `LIBRETRANSLATE_URL` | `http://libretranslate:5000` | api, worker, eval | tier-1 translation (the API translates card texts, spec 07 §5) |
| `LANGUAGE_MODES` | `{"en":"native","sk":"native","cs":"native"}` | worker | JSON; per-language classification mode, set from the G1 result ([spec 07 §1](./07-translation.md)) |
| `FETCH_USER_AGENT` | `FeedItBot/1.0 (+${PUBLIC_BASE_URL}/bot)` | worker | honest UA; per-feed override allowed |
| `FETCH_MAX_BYTES` | `5242880` | worker | 5 MB for feeds and pages |
| `FETCH_TIMEOUT_MS` | `20000` | worker | |
| `FETCH_ALLOW_PRIVATE` | `false` | worker | tests only; refused when `NODE_ENV=production` (spec 03 §4) |
| `INGEST_MAX_AGE_DAYS` | `14` | worker | older items are stored as `stale` and never enriched |
| `PREFILTER_MIN_CARDS` | `60` | worker | spec 05 §5.5 |
| `EVAL_INGEST_ONLY` | `false` | worker | M3a: stop the pipeline after extract (spec 10 §2.1) |
| `EVAL_PUBLIC_URL` | `http://localhost:5180` | eval | base URL printed in rater links (spec 10 §2.2) |
| `EVAL_CACHE_DIR` | `~/.cache/feedit-eval` | eval | engine-call cache shared by all worktrees (spec 10 §3) |
| `WORKER_QUEUES` | `*` | worker | comma list of queue names this process consumes |
| `LOG_LEVEL` | `info` | all | |
| `METRICS_TOKEN` | — | api, worker, scripts | bearer token for `/metrics` and `POST /admin/ops-event` |
| `WORKER_METRICS_PORT` | `9101` | worker | |
| `OTEL_EXPORTER_OTLP_ENDPOINT` | — | all | tracing is enabled only when set |
| `ADMIN_EMAILS` | — | api, worker | comma list: these users may sign up without an invite and get `role=admin` on every login (spec 08 §2.1); alert recipients |

Runtime-tunable settings (budget, thresholds, language modes, circuit state) are **also** stored in
the `settings` table ([spec 02](./02-data-model.md)). The table value wins over the env default, and
the admin UI edits the table.

---

## 4. Process model

| Process | Image | Scale | Consumes |
|---|---|---|---|
| `api` | `apps/api` | 1 (stateless; may run 2) | HTTP |
| `worker` | `apps/worker` | 1–2 | all queues by default (`WORKER_QUEUES=*`); split if needed |
| `web` | static build served by Caddy | — | — |
| `postgres` | `postgres:16` | 1 | — |
| `libretranslate` | `libretranslate/libretranslate` | 1 | only when any language mode is `translate` |
| `caddy` | `caddy:2` | 1 | TLS + reverse proxy |

A **migration job** (`pnpm db:migrate`) runs before `api` and `worker` start
(a compose `depends_on` with `condition: service_completed_successfully`).

---

## 5. Coding conventions

- **Modules:** named exports only. One public `index.ts` per package. Internal files are not imported
  from outside the package.
- **Naming:** files `kebab-case.ts`; types and classes `PascalCase`; functions and variables `camelCase`;
  DB columns `snake_case` (Drizzle maps to camelCase properties); queue names `dot.case`.
- **IDs:** users use UUID v7 (`uuidv7` package). All other tables use `bigint` identity columns. IDs
  travel as strings in JSON (never JS numbers for bigint).
- **Time:** all timestamps are `timestamptz` in UTC. Code uses `Date` objects or epoch ms. Formatting
  happens only in the web client.
- **Errors:** throw subclasses of `AppError` (`packages/shared/src/errors.ts`) with a stable `code`
  (e.g. `FEED_NOT_A_FEED`, `QUOTA_EXCEEDED`, `ENGINE_UNAVAILABLE`). The API maps them to HTTP status
  codes in one place (`apps/api/src/plugins/errors.ts`).
- **Validation:** every external input (HTTP body, query, job payload, env, third-party response) is
  parsed with zod at the boundary. Types are inferred from the schemas (`z.infer`), not duplicated.
- **Logging:** `logger.child({ component, jobId, userId, articleId })`. Never log secrets, email
  codes, session tokens, or full article bodies.
- **SQL:** prefer Drizzle query builders. Use raw SQL (`sql```) for window functions, `pg_trgm`, and
  bulk upserts. Every query that touches per-user tables goes through a repository in `packages/db`
  that takes a `TenantTx` ([spec 02 §5](./02-data-model.md)).
- **Pure logic is separated from I/O:** canonicalization, dedup keys, question building, ranking,
  model training and metrics are pure functions with unit tests. Handlers only wire I/O to them.
- **Time and randomness are injected:** functions that depend on time or randomness receive `now` /
  `rng` parameters, so tests are deterministic.
- **No network in unit tests:** Jev, Ollama, LibreTranslate and HTTP fetches are replaced with
  recorded fixtures (`packages/testing/fixtures/**`). A `RECORD=1` mode refreshes fixtures from real
  services for manual runs only.

---

## 6. Testing levels

| Level | Command | Files | What | Required for |
|---|---|---|---|---|
| Typecheck | `pnpm typecheck` | — | `tsc -b` across the workspace | every task |
| Lint | `pnpm lint` | — | ESLint + Prettier check + boundary rules | every task |
| Unit | `pnpm test` | `**/*.test.ts`, **excluding** `*.int.test.ts`, `*.e2e.test.ts` and `apps/web/e2e/**` | pure functions, handlers with fakes | every task |
| Integration | `pnpm test:int` | only `**/*.int.test.ts` and `**/*.e2e.test.ts` (backend end-to-end, e.g. `ingestion.e2e.test.ts`) | real Postgres (compose.test.yml), migrations applied, RLS on | tasks touching `db`, handlers, API routes |
| E2E | `pnpm e2e` | `apps/web/e2e/**/*.pw.ts` (Playwright) | browser smoke flows (spec 09 §9) | M6 and later |
| Eval | `pnpm evaluate …` | — | live Jev calls against the golden set | M3b, and any change to questions, thresholds or model |

**Integration isolation:**
- Each package's `test:int` uses its own database, `feedit_test_<worktree-hash>_<package>`, created from
  the migrated template (spec 02 §1.1). Parallel worktrees and packages never share data.
- Inside one package, Vitest runs with `fileParallelism: false` for `test:int`.
- `turbo.json` sets `"test:int": { "cache": false }`.

**Reporting named tests:** Turborepo's last lines are only a summary. When a goal asks for a named test,
also run `pnpm test:int 2>&1 | grep -E "✓|✗|FAIL|PASS" | grep <name>`, or the package's Vitest with
`--reporter=verbose` filtered to that file, and show the line.

Coverage target: **≥ 80 % lines** for `packages/feeds`, `packages/ranker`, `packages/questions`,
`packages/engine`. No target for apps. CI fails if coverage in those packages drops below the target.

---

## 7. CI (`.github/workflows/ci.yml`)

On every push and pull request:

1. `pnpm install --frozen-lockfile`
2. `pnpm typecheck && pnpm lint`
3. `pnpm audit --prod --audit-level high` (fails on high or critical advisories)
4. `pnpm test -- --coverage`
5. Start Postgres from `compose.test.yml` → `pnpm test:int`
6. `pnpm build`
7. (from M6 on) `pnpm e2e`. Playwright's `webServer` config starts the fixture feed
   server, the fake TypeSafe server, the api, the worker and `vite preview` against the test Postgres
   (spec 09 §9)

Secrets are never needed in CI. All third-party calls use fixtures.

---

## 8. Local development

- `pnpm i && docker compose -f infra/compose.dev.yml up -d postgres` (add `--profile translate` to
  also start LibreTranslate)
- `pnpm db:migrate && pnpm db:seed` (settings defaults, the taxonomy, question sets and the card
  library; no users)
- The first admin signs in with an address listed in `ADMIN_EMAILS`, which needs no invite
  (spec 08 §2.1)
- `pnpm dev` runs api, worker and web with watch mode through Turborepo
- Web on `http://localhost:5173` (Vite proxies `/api` to `:3000`)
- Email in dev: codes are logged to the api console (`MAIL_TRANSPORT=log`) instead of being sent

---

## 9. Deviating from a spec

The specs are meant to be complete. When implementation shows a spec is wrong or impossible
(a library behaves differently, a limit is lower than documented, a fixture proves a rule wrong):

1. Choose the smallest change that keeps the spec's **intent** (named at the top of each section).
2. Record it in `docs/DECISIONS.md` as `D-<n>: <date> <task id> <what changed> <why>`.
3. Update the affected spec text in the same commit.
4. Never silently diverge. A reviewer reading only the specs must be able to predict the code.

Changes that alter a locked decision (PLAN.md §2) are **not** made by the implementer. Stop and ask
the owner.

---

## 10. `CLAUDE.md` for the new repository (created in M0)

M0 writes this file verbatim, and later milestones append to its "Current state" section:

```markdown
# FeedIt Next Gen — working agreement

- Source of truth: docs/PLAN.md (goals, tasks, order) and docs/specs/*.md (behaviour). Read the spec
  sections a task references before writing code.
- Work in the order and parallel lanes given in PLAN.md. A task is done only when its "Done when" list
  is fully satisfied and `pnpm typecheck && pnpm lint && pnpm test` pass (plus `pnpm test:int` if the
  task touches db, handlers or routes).
- Parallel work (PLAN.md §0.3):
  - The lead session adds all new dependencies and shared registration points (handler maps, route
    registration, i18n namespaces, package exports) **before** starting subagents.
  - Subagents never run `pnpm add`, never edit the lockfile, and never commit.
  - The lead commits each task by path.
  - Only one active branch at a time may add database migrations.
- Commit per task: `<task-id>: <summary>` (e.g. `M1-T1: SSRF-safe fetch dispatcher`).
- Never call live third-party APIs in tests; use packages/testing fixtures.
- Deviations from a spec: follow docs/specs/01-architecture.md §9 and log them in docs/DECISIONS.md.
- Locked decisions in PLAN.md §2 are not changed without asking the owner.

## Current state
(append one line per completed milestone: date, milestone, notes)
```
