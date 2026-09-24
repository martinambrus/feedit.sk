# FeedIt Next Gen: execution plan

> **What this is.** The build plan for FeedIt Next Gen, an RSS reader that classifies articles with
> TypeSafe's **Jev** decision model against each reader's plain-language **interest cards**, instead
> of a hand-tuned word-scoring engine. It is written so that Claude Code can execute it milestone by
> milestone as `/goal`s, with minimal interpretation.
>
> **Document set** (copy the whole folder into the new repository's `docs/`):
>
> | File | Role |
> |---|---|
> | `PLAN.md` (this file) | goals, milestones, tasks, order, parallelism, acceptance criteria |
> | [`specs/01-architecture.md`](./specs/01-architecture.md) | stack, repo layout, conventions, config, CI, deviation process |
> | [`specs/02-data-model.md`](./specs/02-data-model.md) | full PostgreSQL schema, roles, RLS, SQL functions |
> | [`specs/03-ingestion.md`](./specs/03-ingestion.md) | queues, safe fetcher, parsing, dedup, extraction, language, fetch schedule |
> | [`specs/04-decision-engine.md`](./specs/04-decision-engine.md) | Jev client, router, retries, breaker, spend guard, LLM fallback, Laya |
> | [`specs/05-classification.md`](./specs/05-classification.md) | question sets, taxonomy, interest cards, matching, clustering, suggestions, library |
> | [`specs/06-ranking-learning.md`](./specs/06-ranking-learning.md) | rules, lanes, tiers, explain, rank handler, personal model, BM25 |
> | [`specs/07-translation.md`](./specs/07-translation.md) | LibreTranslate tier 1, Ollama Cloud tier 2, quality checks |
> | [`specs/08-api.md`](./specs/08-api.md) | HTTP API, auth, invites, quotas, admin |
> | [`specs/09-web-app.md`](./specs/09-web-app.md) | PWA screens, interactions, keyboard, onboarding |
> | [`specs/10-evaluation.md`](./specs/10-evaluation.md) | golden set, experiments, metrics, gate G1, replay |
> | [`specs/11-operations.md`](./specs/11-operations.md) | deploy, housekeeping jobs, backups, alerts, security, launch |
> | [`background.md`](./background.md) | why: lessons from FeedIt.sk and DreamCatcher, Jev research, risks |
> | [`laya-multilingual.md`](./laya-multilingual.md) | evaluation of the open-weights Laya model (optional M9) |
>
> Specs are **binding** and are the source of truth for behaviour. This file is the source of truth for
> *order* and *done-ness*.

---

## 0. How to run this plan with Claude Code

### 0.1 One milestone = one `/goal`

Claude Code's `/goal <condition>` keeps a session working, turn after turn, until a separate evaluator
model judges the condition met. The evaluator reads **only the conversation transcript**. It does not
run commands or open files. Every milestone below therefore has a ready-made **goal text** (under
4,000 characters, as `/goal` requires) that:

- points at the milestone section and the specs to follow
- states constraints (locked decisions, no scope creep)
- defines "done" as **evidence printed in the transcript**: a milestone report with every task ticked
  and its commit hash, plus the verbatim tail of the verification commands and their exit code
- bounds the run with "or stop after N turns"

### 0.2 Procedure for each milestone

1. **Start a fresh Claude Code session** in the new repository. A goal is session-scoped, one per
   session.
2. Switch to **auto mode** so goal turns run unattended (a goal does not change permission mode).
3. Create the milestone branch, e.g. `git switch -c m1-ingestion`. Parallel milestones use separate
   **git worktrees** (`git worktree add ../feedit-ng-m2 -b m2-classification`), one session each.
4. Paste the milestone's goal text after `/goal `.
5. Claude then:
   - reads this milestone section and the referenced spec sections
   - creates one task per row of the task table (TaskCreate), in dependency order
   - runs tasks from **different lanes in parallel with subagents** when their dependencies are met
     (lanes touch disjoint directories)
   - commits once per task as `<task-id>: <summary>`
   - runs the verification commands and prints the **milestone report** (§0.4)
6. Review the report, then merge the branch (a PR if you prefer) before starting dependent milestones.

### 0.3 Global rules for every goal

- Follow [`specs/01-architecture.md`](./specs/01-architecture.md) conventions. Read the spec sections
  a task cites **before** coding it.
- **Locked decisions (§2) are never changed by the implementer.** If one blocks progress, stop and
  report.
- **Spec deviations:** allowed only through spec 01 §9. Log each in `docs/DECISIONS.md` and update the
  spec in the same commit.
- **Tests:** no live third-party calls; use `packages/testing` fixtures. Live calls are allowed only
  in M3b and in explicitly marked manual checks.
- **Scope:** do not implement other milestones' tasks. Stubs and interfaces that later milestones
  fill in are fine when a task says so.
- **Verification commands** (the "full check"):
  `pnpm typecheck && pnpm lint && pnpm test && pnpm test:int`. From M6 on, also
  `pnpm --filter web e2e`.

### 0.4 Milestone report format (printed at the end of every goal)

```
## M<n> report
Branch: m<n>-<slug>   Last commit: <sha>
| Task | Status | Commit | Evidence |
| M<n>-T1 … | ✓ | abc1234 | tests: packages/feeds/test/safe-fetch.test.ts (24 passed) |
…
### Milestone "Done when"
- [x] <item> — <evidence: command + result, file path, or test name>
### Verification
$ pnpm typecheck && pnpm lint && pnpm test && pnpm test:int
<last ~15 lines of output>
exit code: 0
### Deviations logged
D-3 (M1-T6): … / none
### Notes for the next milestone
…
```

---

## 1. Product in one page

**Promise:** "Show me what I care about from the moment I subscribe, learn from my 👍/👎, and always
tell me *why*."

**How:**
1. **Ingest** every subscribed feed once for all users, deduplicate, extract text and detect the
   language.
2. **Call A (enrich)**, once per article: content type, topic, depth, clickbait, promo, time
   sensitivity, … (Jev).
3. **Call B (match)**, once per article × each distinct **interest card** on its feeds: an absolute
   yes/no probability (Jev).
4. **Rank** per user, in code: rules (mutes, blocks, boosts) → card score → quality demotions → lanes
   **For you / Maybe / Everything else** plus tiers 1–5, with an `explain` record for "Why this?".
5. **Learn:** 👍/👎 with reasons, dwell and bookmarks train a tiny per-user logistic model on top of
   the Jev features. Maybe-lane items are the ones the app asks about (active learning).
6. **Degrade safely:** if Jev is unavailable or over budget, the app falls back to keyword ranking in
   the Maybe lane and never hides anything.

**v1 non-goals:** AI summaries or any generated text, social features, a full-text search engine,
native apps (the PWA covers mobile), and migrating FeedIt.sk data.

Background and rationale: [`background.md`](./background.md).

---

## 2. Locked decisions

| # | Topic | Decision |
|---|---|---|
| 1 | Stack | **TypeScript monorepo** (pnpm + Turborepo; Node 22; Fastify; Drizzle; pg-boss; React PWA). Details in spec 01 |
| 2 | Tenancy | **Multi-tenant from day one**: a shared article layer, per-user data under Postgres RLS |
| 3 | Generative LLM | **Only** as the fallback decision engine and for translation. No LLM card authoring, summaries or labelling teachers |
| 4 | Migration | **None.** No FeedIt.sk accounts or data. Everything is trained or fine-tuned from scratch; the golden set is built new |
| 5 | Hosting | **Self-hosted on one box, minimal spend, no GPU** |
| 6 | Signup | **Invite-only** at launch, with a waitlist |
| 7 | Translation / LLM provider | Tier 1 = **free CPU machine translation** (LibreTranslate/Argos). Tier 2 and the LLM fallback = **Ollama Cloud GLM** (`glm-5.3-flash`, `glm-5.3`). **No Claude** (too expensive for the quality needed) |
| 8 | Decision model | **Jev** (TypeSafe) through its HTTP API, pinned version (`jev-1.13.0`). Laya is an optional later engine |

**Decided by measurement at gate G1 (M3b), with defaults until then:**
- language mode per language (default `native`)
- card text mode (default `as_written`)
- lane and tier thresholds (spec 06 §11 defaults)
- the daily budget (default $2)

---

## 3. Glossary

| Term | Meaning |
|---|---|
| **Interest card** | A plain-language description of what a reader wants ("EV battery chemistry, not stock news"). Shared when the text is identical; a **fork** when it has personal examples. Strength: must / love / like / never |
| **Label** | A user-defined tag with a definition; asked like a card, suggested when p ≥ 0.8 |
| **Call A / enrich** | Jev call with the fixed question set `enrich-v1` about one article |
| **Call B / match** | Jev call with one yes/no question per pending card (plus level-2 topic questions) about one article |
| **Lane** | `new`, `for_you`, `maybe`, `everything`, `hidden` (spec 06 §6) |
| **Tier** | 1–5 from P(like); FeedIt's slider |
| **Degraded** | No model answer available; BM25 ranking, Maybe lane only |
| **Golden set** | The rated articles and facet labels collected in M3a; used for G1 and every later replay |
| **G1** | The gate after M3b that sets language modes, card text mode and thresholds, or stops the project |
| **Engine router** | The only entry point to decision models (spec 04) |

---

## 4. Milestone map

```
M0 Foundations
 ├─► M1 Ingestion ─────────┐
 └─► M2 Classification ────┼─► M3a Eval tooling ─► [humans rate ~1–2 weeks] ─► M3b G1 gate ─┐
                           ├─► M4 API ──────────┐                                            │
                           └─► M5 Ranking ──────┴─► M6 Web app ─► M7 Learning ─► M8 Ops & launch ─► 🚀 invite-only beta
                                                                                              ▲
                                                               (M3b decisions applied before)─┘
M9 Optional extensions (Laya, image proxy, …): after launch
```

| Milestone | Depends on (merged) | Can run in parallel with | Autonomous? | Size |
|---|---|---|---|---|
| **M0** Foundations | — | — | yes | M |
| **M1** Ingestion core | M0 | M2 | yes (the fixture server is local) | L |
| **M2** Decision engine & classification | M0 | M1 | yes (fixtures only) | L |
| **M3a** Evaluation tooling & golden-set collection | M1, M2 | M4, M5 | yes, then a **human step** | M |
| **M3b** Run gate G1 | M3a + human ratings | M6, M7 | yes (needs API keys and network) | S |
| **M4** HTTP API | M0, M2 (for `packages/questions`) | M3a, M5 | yes | L |
| **M5** Ranking & lanes | M2 | M3a, M4 | yes | M |
| **M6** Web app (PWA) | M4, M5 | M3b | yes | L |
| **M7** Personal learning & suggestions | M4, M5 (M6 for UI hooks) | M8 | yes | M |
| **M8** Operations & launch readiness | M4, M5, M6 (and M3b applied) | M7 | mostly (one-time host setup is manual) | M |
| **M9** Optional extensions | launch | — | per item | — |

**Shared files between parallel milestones:**
- `apps/worker/src/pipeline.ts` and `apps/worker/src/main.ts` are created in M0 with a stub for every
  stage. M1 fills extract/fetch, M2 fills translate/enrich/match/cluster, M5 fills rank.
- Queue registration is table-driven (`handlers/index.ts` exports one map), so parallel branches only
  add entries.
- Merge conflicts in that map are trivial and resolved at merge time.

---

## 5. M0: Foundations

**Outcome:** an empty repository becomes a working monorepo. It has tooling, CI, config, the full
database schema with RLS, test infrastructure, and runnable (stub) apps, so every later milestone only
adds code.

**Read first:** spec 01 (all), spec 02 (all), spec 03 §2 (queue names), spec 08 §6 (plans), §3.1
(preferences).

**Goal text** (paste after `/goal `):

```
Complete milestone M0 "Foundations" exactly as specified in docs/PLAN.md §5, following docs/specs/01-architecture.md and docs/specs/02-data-model.md. Start by reading PLAN.md §0–§5 and those specs, then create one task per row of the M0 task table and implement them in dependency order, using subagents for tasks in different lanes whose dependencies are met. Commit each task separately as "<task-id>: <summary>". Constraints: do not change locked decisions in PLAN.md §2; log any spec deviation in docs/DECISIONS.md and update the spec in the same commit; no live third-party calls in tests; do not implement later milestones beyond the stubs M0 asks for. The goal is met only when the transcript shows (1) a final "M0 report" in the format of PLAN.md §0.4 listing M0-T1…M0-T8 each with ✓, commit hash and evidence, and every M0 "Done when" item checked with evidence, (2) the output of `pnpm typecheck && pnpm lint && pnpm test && pnpm test:int` run after the last commit, ending with exit code 0, and (3) `git status --short` printing nothing. Or stop after 120 turns and print the report with the unfinished items.
```

**Tasks**

| ID | Task | Needs | Lane | Specs |
|---|---|---|---|---|
| M0-T1 | Monorepo scaffold and tooling | — | A | 01 §1–2, §5–6 |
| M0-T2 | `packages/shared` | T1 | A | 01 §3, §5; 08 §3.1, §6 |
| M0-T3 | Infra: compose files, Postgres init, Caddy skeleton | T1 | B | 01 §4, §8; 02 §1; 11 §2 |
| M0-T4 | `packages/db`: schema, migrations, RLS, functions, `withTenant` | T2, T3 | A | 02 all |
| M0-T5 | `packages/testing`: test DB helper, factories, fixture server | T1 | C | 01 §5–6 |
| M0-T6 | Seed and settings mechanism | T4 | A | 02 §2; 05 §2 (mechanism only) |
| M0-T7 | App skeletons (api, worker, web, eval) and `pipeline.ts` stubs | T2 | B | 01 §2, §4; 03 §1–2; 08 §10 |
| M0-T8 | CI workflow and `CLAUDE.md` | T1 | C | 01 §7, §10 |

**Done when** (per task):

- **T1:**
  - `pnpm i` succeeds.
  - Every app and package from spec 01 §2 exists with `package.json`, `tsconfig.json`, `src/index.ts`
    and one placeholder test.
  - Strict TS flags are set.
  - The ESLint boundary rules are configured, and a test asserts that a forbidden import (e.g.
    `packages/ranker` → `packages/db`) is reported.
  - `docs/` contains this plan.
- **T2:**
  - The config loader covers **every** variable in spec 01 §3, with defaults and zod validation.
  - Tests: missing `DATABASE_URL` fails; `FETCH_ALLOW_PRIVATE=true` with `NODE_ENV=production` fails.
  - Logger factory, `AppError` with codes (spec 08 §1), UUID v7 and bigint-string helpers, an
    injectable clock, `plans.ts`, and the `UserPreferences` schema with defaults (unit-tested).
  - `normalizeText` (spec 03 §6.1) lives here, because both `feeds` and `ranker` use it. It has tests
    with Slovak diacritics.
- **T3:**
  - `infra/compose.dev.yml`: postgres, and libretranslate under the `translate` profile.
  - `infra/compose.test.yml`.
  - `infra/postgres/init.sql` creates the extensions and the three roles.
  - `docker compose -f infra/compose.test.yml up -d` works, and a `psql` check lists the `citext`,
    `pg_trgm` and `pgcrypto` extensions and the roles.
- **T4:**
  - Drizzle schema plus SQL migrations reproduce spec 02 §2–§6 exactly, including indexes, checks,
    grants, RLS policies, and `refresh_feed_cards` / `refresh_feed_subscribers`.
  - `pnpm db:migrate` works on an empty DB.
  - `withTenant(db, userId, fn)` and the branded `TenantTx` type exist.
  - Integration tests:
    - for **every** per-user table: no `app.user_id` → 0 rows; user A cannot select, insert, update
      or delete B's rows
    - the refresh functions produce the expected rows for a seeded scenario
- **T5:**
  - A test DB helper: migrate once, truncate between tests.
  - Factories for user, feed, article, card, subscription.
  - A local HTTP fixture server helper (serves files and scripted redirects/status codes), used by T4
    or a smoke test.
- **T6:**
  - `pnpm db:seed` runs idempotently.
  - It inserts default `settings` rows (budget, language modes, card text mode, question_sets.active
    placeholder).
  - It provides hooks that M2 fills (topics, question sets, library).
  - Running it twice changes nothing (tested).
- **T7:**
  - `apps/api` `buildServer()` with `/api/v1/healthz` and `/readyz` (inject tests).
  - `apps/worker`:
    - boots pg-boss
    - registers handlers from a table-driven map with a **stub for every queue** in spec 03 §2 (each
      stub logs and completes)
    - `pipeline.ts` has the `after(stage, …)` function with the stage order of spec 03 §1
  - `apps/web`: a Vite + React + TanStack Router + Tailwind + i18next shell rendering a localized
    "FeedIt" page.
  - `apps/eval`: a commander CLI with `--help`.
  - `pnpm dev` starts api, worker and web (logs shown).
- **T8:**
  - `.github/workflows/ci.yml` per spec 01 §7 (without Playwright).
  - `CLAUDE.md` with the exact text of spec 01 §10.
  - The CI commands run locally and succeed (output shown).

**Milestone done when:** all tasks are done; the full check passes; `pnpm dev` serves `GET /api/v1/readyz → 200`;
the RLS test covers all tables in spec 02 §4.

---

## 6. M1: Ingestion core

**Outcome:** subscribed feeds are fetched safely on an adaptive schedule, and articles are stored
once, deduplicated across feeds, with extracted text and detected language. The pipeline stops after
`extract` until M2 adds later stages.

**Read first:** spec 03 (all), spec 02 §3, spec 01 §5.

**Goal text:**

```
Complete milestone M1 "Ingestion core" exactly as specified in docs/PLAN.md §6, following docs/specs/03-ingestion.md, docs/specs/02-data-model.md §3 and the conventions in docs/specs/01-architecture.md. Read PLAN.md §0, §2, §6 and the referenced specs first, create one task per row of the M1 task table, and implement them in dependency order, running tasks from different lanes in parallel with subagents when their dependencies are met. Commit each task as "<task-id>: <summary>". Constraints: locked decisions in PLAN.md §2 are not changed; spec deviations go to docs/DECISIONS.md with the spec updated in the same commit; tests use local fixtures only (no internet); do not implement M2+ stages beyond calling the existing pipeline stubs. The goal is met only when the transcript shows (1) a final "M1 report" in the format of PLAN.md §0.4 listing M1-T1…M1-T10 each with ✓, commit hash and evidence, and every M1 "Done when" item checked with evidence, (2) the output of `pnpm typecheck && pnpm lint && pnpm test && pnpm test:int` run after the last commit, ending with exit code 0, including the M1 end-to-end ingestion test by name, and (3) `git status --short` printing nothing. Or stop after 200 turns and print the report with the unfinished items.
```

**Tasks**

| ID | Task | Needs | Lane | Specs |
|---|---|---|---|---|
| M1-T1 | `safeFetch` (SSRF guard, redirects, limits, charset decoding) | — | A | 03 §4 |
| M1-T2 | `canonicalizeUrl`, `url_key`, tracking-param list | — | B | 03 §5 |
| M1-T3 | `parseFeed`, `normalizeItem`, sanitizing, `title_norm`, `content_hash`, feed fixtures | — | B | 03 §6, §12 |
| M1-T4 | `nextSchedule` adaptive interval, with simulations | — | C | 03 §9 |
| M1-T5 | `detectLanguage` | — | C | 03 §8.3 |
| M1-T6 | Extraction: skip list, robots, Readability, body lead, canonical detection, politeness limiter | T1 | A | 03 §8 |
| M1-T7 | Feed discovery and OPML parse/export | T1, T3 | A | 03 §10–11 |
| M1-T8 | Worker handlers: `feed.schedule`, `feed.fetch` (ingest §7, redirects/merge), `article.extract` (alias/merge) | T1–T6 | D | 03 §1–3, §7–9 |
| M1-T9 | End-to-end ingestion integration test | T8 | D | 03 all |
| M1-T10 | Dev CLI: `feeds:add`, `feeds:fetch-now`, `feeds:show` | T8 | D | 03 §10 |

**Done when:**

- **T1:**
  - Tests cover every blocked IPv4/IPv6 range, a redirect from a public URL into `127.0.0.1` (blocked),
    a > 5 hop chain, a disallowed port, the decompressed-size cap, and a timeout.
  - A windows-1250 feed and a `<meta charset>` page decode correctly.
  - Returns the result union and never throws for network or HTTP errors.
- **T2:** ≥ 40 table-driven cases pass (IDN, repeated params, fragments/hash-bang, tracking params, AMP
  unchanged, linkless `urn:feedit` keys).
- **T3:**
  - Every fixture in spec 03 §12 parses to the expected `NormalizedItem`s (snapshots).
  - The lenient XML retry fixes the unescaped-`&` fixture.
  - Sanitizer tests pass: scripts stripped, links rewritten, pixels removed.
- **T4:**
  - Unit tests cover every branch of spec 03 §9.
  - The 60-day simulations for the four archetypes assert interval bounds: a busy feed stays
    ≤ 30 min, a daily blog ≤ 12 h, a weekly podcast reaches ≥ 24 h, and a broken feed is quarantined
    and then recovers.
- **T5:** ≥ 30 labelled samples (10 each EN/SK/CZ) with ≥ 90 % accuracy; the Slovak/Czech tie-break with
  a hint is tested; short text uses the hint.
- **T6:**
  - HTML fixtures: normal article, paywall teaser, AMP with `rel=canonical`, windows-1250 meta, list
    page (→ `no_content`).
  - Each produces the expected status, `body_lead` (≤ 1,500 chars, sentence-cut) and word count.
  - robots disallow → `blocked`.
  - A limiter test proves ≤ 2 concurrent requests and ≥ 1 s spacing per origin (fake timers).
- **T7:**
  - Discovery covers: a direct feed, HTML with `<link rel=alternate>` (one or several → candidates), a
    fallback path probe, and not-a-feed.
  - OPML import covers nested folders, duplicates and invalid lines; the export round-trips.
- **T8:**
  - Handlers implement spec 03 §7 (exact/guid/near-duplicate matching, stale marking, title-change
    re-processing) and §9 feed updates, including the permanent-redirect merge.
  - `article.extract` performs the redirect/`rel=canonical` alias and merge, then calls
    `pipeline.after('extract')`.
- **T9:** a single named test, `ingestion.e2e.test.ts`, uses the local fixture server: 3 feeds (RSS,
  Atom, JSON Feed) where 2 share an article, plus article pages, and asserts:
  - the articles are unique and `feed_items` link both feeds
  - bodies are extracted and `lang` is set
  - feed stats and `next_fetch_at` are updated
  - a Google-News-style redirect merges into the existing article
  - stale items are not extracted
- **T10:** the CLI commands work against the dev DB (output shown on a fixture feed).

**Milestone done when:** all tasks are done, the full check passes, and coverage of `packages/feeds`
is ≥ 80 % (shown).

---

## 7. M2: Decision engine and classification

**Outcome:**
- Articles are enriched (Call A) and matched against interest cards (Call B) through a budgeted,
  breaker-protected engine router, with optional translation.
- Story clustering runs.
- The card lifecycle, library seed and ranker bootstrap (card score + BM25) exist.
- Everything is tested against recorded fixtures, with no live calls.

**Read first:** spec 04 (all), spec 05 (all), spec 07 (all), spec 06 §4.1 and §9, spec 02 §3.1.

**Goal text:**

```
Complete milestone M2 "Decision engine and classification" exactly as specified in docs/PLAN.md §7, following docs/specs/04-decision-engine.md, 05-classification.md, 07-translation.md, and 06-ranking-learning.md §4.1 and §9, with the conventions of docs/specs/01-architecture.md. Read PLAN.md §0, §2, §7 and those specs first, create one task per row of the M2 task table, and implement them in dependency order, using subagents for independent lanes. Commit each task as "<task-id>: <summary>". Constraints: Jev is called only through its documented HTTP API from packages/engine; no live calls to TypeSafe, Ollama or LibreTranslate in tests (use recorded fixtures and fake servers); locked decisions in PLAN.md §2 unchanged; deviations logged in docs/DECISIONS.md with the spec updated. The goal is met only when the transcript shows (1) a final "M2 report" in the format of PLAN.md §0.4 listing M2-T1…M2-T11 each with ✓, commit hash and evidence, and every M2 "Done when" item checked with evidence, (2) the output of `pnpm typecheck && pnpm lint && pnpm test && pnpm test:int` run after the last commit, ending with exit code 0, including the named tests classification.e2e.test.ts and engine-breaker.int.test.ts, and (3) `git status --short` printing nothing. Or stop after 220 turns and print the report with the unfinished items.
```

**Tasks**

| ID | Task | Needs | Lane | Specs |
|---|---|---|---|---|
| M2-T1 | Engine types and answer normalization | — | A | 04 §1–2 |
| M2-T2 | `TypeSafeEngine` HTTP client, status handling, fixtures | T1 | A | 04 §3, §10 |
| M2-T3 | `EngineRouter`: retries, priority semaphore, rate limiter, breaker, spend guard, logging, `usage_daily` | T2 | A | 04 §4–7 |
| M2-T4 | `LlmFallbackEngine` (Ollama Cloud), off by default | T1 | B | 04 §8 |
| M2-T5 | `packages/questions`: canonical JSON/hash, builders, taxonomy, `enrich-v1`, `cluster-v1`, `suggest-v1`, card and label builders, packing, `flattenFacets` | — | C | 05 §2–6 |
| M2-T6 | Card library seed (≥ 150 cards) plus seeding of topics, question sets and library | T5 | C | 05 §8, §2 |
| M2-T7 | `packages/translate`: LibreTranslate and Ollama translators, `assessTranslation`, best-row selection | — | B | 07 |
| M2-T8 | Card lifecycle repository (create/adopt/fork/edit/strength/scope/delete, `feed_cards` refresh, backfill enqueue) | T5 | C | 05 §5.1, §5.3 |
| M2-T9 | Worker handlers: `article.translate`, `article.enrich`, `article.match`, `card.backfill`, `article.cluster`, `house.rescore-degraded` | T3, T5, T7, T8 | D | 05 §3–6; 07 §3; 04 §5 |
| M2-T10 | Ranker bootstrap: `cardScore` and BM25 in `packages/ranker` | T5 | E | 06 §4.1, §9 |
| M2-T11 | Integration tests: classification end-to-end, breaker, budget, degraded path | T9 | D | 04 §10; 05 §11 |

**Done when:**

- **T1:** normalization tests cover probabilities not summing to 1 (renormalize inside tolerance,
  reject outside), missing or mistyped keys, score recomputation, and entropy confidence.
- **T2:**
  - Fixture-based tests for 200 (all three answer types), 401, 422 and 429 (with `Retry-After`).
  - The request body matches spec 04 §3 exactly (snapshot).
  - Cost = input tokens × price.
- **T3:**
  - Fake-timer tests of the retry schedule.
  - Breaker transitions (closed → open → half-open → closed), with the doubling open duration.
  - Auth mode.
  - Spend guard including the 10 % interactive allowance.
  - Rate-limiter waits.
  - One `engine_calls` row per logical call.
  - Upserts into `usage_daily` (integration).
- **T4:** schema generation tests for noul/choice/score; post-processing normalizes; malformed JSON
  fixture → `error`; only used when enabled, interactive and under the daily cap (router test).
- **T5:**
  - Hash stability across key order.
  - `ENRICH_V1` passes the TypeSafe limits (a test).
  - Taxonomy integrity (20 L1, unique ids, parents exist).
  - Card/label question snapshots.
  - Packing with 500 synthetic cards respects both token limits and the count limit.
  - `flattenFacets` snapshot.
  - The cluster fold truth table.
- **T6:**
  - ≥ 150 library cards, ≥ 5 per L1 (except `other`), ≥ 15 SK/CZ-specific, including the 10 examples
    of spec 05 §8.
  - A validation test enforces the authoring rules (length, no "not" in `interest`, topic ids exist).
  - `pnpm db:seed` inserts them idempotently.
- **T7:** fake LibreTranslate server (ok/weak/fail/timeout); `assessTranslation` truth table; tier
  escalation and daily cap; best-row selection.
- **T8:** repository integration tests for every lifecycle action in spec 05 §5.1, including fork
  privacy (another user never receives a fork), `feed_cards` refreshed in the same transaction, and
  backfill queue rows (priorities 2/6, the cap of 500).
- **T9:**
  - Handlers implement spec 05 §5.5 exactly (claim with `SKIP LOCKED`, skip answered, prefilter,
    L2 questions, packing, upserts, attempts).
  - Degraded enrich sets `pipeline_state='degraded'`.
  - `pipeline.after` wiring runs extract → (translate) → enrich → cluster + match.
  - `house.rescore-degraded` re-enqueues degraded and `llm`-answered articles when the breaker is
    closed.
- **T10:** unit tests for the strength weights, scope and missing answers; BM25 ordering on a
  hand-made corpus; `P = 1 − exp(−s/3)`.
- **T11:**
  - `classification.e2e.test.ts`: a seeded article, 3 cards and 1 label, with a fake TypeSafe server
    → `article_facets`, `card_answers`, `article_topics_l2`, and `pipeline_state='matched'`.
  - `engine-breaker.int.test.ts`: 30 % 503s → breaker opens → `circuit_open` → recovery closes it.
  - A budget-exceeded test.
  - A backfill test that drains in ≤ 2 calls.

**Milestone done when:** all tasks are done, the full check passes, and coverage of `packages/engine`,
`packages/questions` and `packages/ranker` is ≥ 80 % (shown).

---

## 8. M3a: Evaluation tooling and golden-set collection

**Outcome:**
- `apps/eval` can ingest a real EN/SK/CZ sample, serve the blind rating and facet-labelling pages to
  raters, run all experiments, compute every metric, and generate the G1 report and `config/g1.json`.
- This is proven end-to-end on **synthetic** data.
- The real sample is ingested and the rater URLs are ready. The humans then rate.

**Read first:** spec 10 (all), spec 02 §7, spec 06 §4 and §9, spec 05 §3, spec 07.

**Goal text:**

```
Complete milestone M3a "Evaluation tooling and golden-set collection" exactly as specified in docs/PLAN.md §8, following docs/specs/10-evaluation.md and the conventions of docs/specs/01-architecture.md. Read PLAN.md §0, §2, §8 and the referenced specs first, create one task per row of the M3a task table, and implement them in dependency order, using subagents for independent lanes. Commit each task as "<task-id>: <summary>". Constraints: no Jev/Ollama calls are made in this milestone (experiments are exercised with the fixture engine and synthetic raters only); the real feed sample may be fetched from the internet by the ingest-sample command; locked decisions unchanged; deviations logged in docs/DECISIONS.md. The goal is met only when the transcript shows (1) a final "M3a report" in the format of PLAN.md §0.4 listing M3a-T1…M3a-T9 each with ✓, commit hash and evidence, and every M3a "Done when" item checked with evidence, (2) the output of `pnpm typecheck && pnpm lint && pnpm test && pnpm test:int` run after the last commit, ending with exit code 0, (3) the synthetic dry-run report path and its decision table printed, and (4) `git status --short` printing nothing. Or stop after 180 turns and print the report with the unfinished items.
```

**Tasks**

| ID | Task | Needs | Lane | Specs |
|---|---|---|---|---|
| M3a-T1 | `eval` schema migration and the eval system user | — | A | 02 §7 |
| M3a-T2 | CLI skeleton, `feeds-golden.txt` (≈ 60 feeds, EN/SK/CZ), `ingest-sample`, stratified sampling | T1 | A | 10 §2.1 |
| M3a-T3 | Rating server: rater add/token, card-writing step, feed picking, blind rating UI | T1 | B | 10 §2.2, §2.4 |
| M3a-T4 | Facet labelling page | T3 | B | 10 §2.3 |
| M3a-T5 | Metrics library | — | C | 10 §4 |
| M3a-T6 | Experiment runner (cache, cost estimate and confirmation), experiments B0, B1, E1–E4 (E5 stub) | T1 | D | 10 §3 |
| M3a-T7 | Report generator, decision rules, `config/g1.json`, `apply-g1` | T5, T6 | C | 10 §1, §5 |
| M3a-T8 | Synthetic dry run: simulated raters + fixture engine → full report | T3–T7 | D | 10 all |
| M3a-T9 | Real sample ingested and rater onboarding kit | T2, T3 | A | 10 §2 |

**Done when:**

- **T1:** the migration applies; the eval user exists; the worker runs with `EVAL_INGEST_ONLY=true` and
  stops after extract (test).
- **T2:**
  - `feeds-golden.txt` has ≈ 20 feeds per language and the category mix of spec 10 §2.1.
  - `ingest-sample --dry-run` lists the feeds and their reachability.
  - Sampling produces up to 500/500/500 stratified article ids (integration test on fixture data).
- **T3:**
  - Rating flow integration tests: card-writing gate (can't rate before ≥ 5 cards), feed picking
    (≥ 10), blind page (no model fields in the HTML), keyboard handlers, rating persistence and change.
  - Token cookie auth.
  - The pages work at a 375 px width (Playwright screenshot or DOM test).
- **T4:** the labelling page stores all six fields; a second labeller's overlap subset is selected
  deterministically.
- **T5:** AUC matches known Mann–Whitney values including ties; bootstrap CI determinism with a seed;
  P@k; ECE; macro-F1; Spearman; Cohen's κ; isotonic regression. All unit-tested.
- **T6:**
  - Each experiment is a config object.
  - Engine calls go through `EngineRouter` with a JSON cache keyed by `sha256(model+state+questions)`.
  - A cost estimate is printed and confirmation required above $1.
  - `eval.runs` / `eval.run_answers` are written.
  - Re-running uses the cache, shown by a test with a counting fake engine.
- **T7:**
  - The report renders every table of spec 10 §4 and one reliability SVG per language.
  - The decision rules of §5 are implemented as pure functions with unit tests for pass/fail branches.
  - `config/g1.json` validates against a zod schema.
  - `apply-g1` writes the `settings` rows.
- **T8:**
  - `eval dry-run` generates 4 synthetic raters whose ratings follow a hidden per-rater interest
    model, and a fixture engine returning noisy but informative answers.
  - It runs every experiment, produces `reports/DRYRUN-<date>.md` and a `g1.json`, and prints the
    decision table.
  - A test asserts that E1 beats B0 on the synthetic data.
- **T9:**
  - `ingest-sample` ran on the real feed list (the command output shows per-language article counts).
  - `docs/eval/RATERS.md` explains the rating task in English and Slovak.
  - `eval rater add` printed working URLs for the owner (the raters themselves are added by the owner).

**Milestone done when:** all tasks are done, the full check passes, the dry-run report exists, and the
real sample has ≥ 300 articles per language (or the report explains a shortfall).

### 8.1 Human step (between M3a and M3b, about 1–2 weeks, in parallel with M4–M6)

1. Start the rating server (`pnpm --filter eval cli serve-rating`) on a reachable host, e.g. a small
   temporary VPS or a tunnel.
2. Add 3–5 raters (`eval rater add --name … --langs …`) and send them their URLs and `RATERS.md`.
3. Each rater writes 5–10 interests, picks ≥ 10 feeds, and rates ≥ 250 articles.
4. The owner labels facets for 100 articles per language (a second person labels 50 if possible).
5. Check progress with `eval status`, which prints per-rater counts. Continue with M3b once every rater
   has ≥ 250 ratings.

---

## 9. M3b: Run gate G1

**Outcome:** the experiments run on the real golden set with live Jev, LibreTranslate and Ollama. The
G1 report and `config/g1.json` are committed. Either the core bet passes and the settings are
applied, or the build stops with a clear report for the owner.

**Needs:** `TYPESAFE_API_KEY`, `OLLAMA_API_KEY`, LibreTranslate running (`--profile translate`), and
network access.

**Goal text:**

```
Complete milestone M3b "Run gate G1" as specified in docs/PLAN.md §9 and docs/specs/10-evaluation.md §3–§5. Read those sections first and create one task per row of the M3b task table. Live calls to TypeSafe Jev, Ollama Cloud and the local LibreTranslate are allowed in this milestone; keep total spend under $10 (print the estimate before each experiment and the actual cost after). Constraints: do not change the decision rules or thresholds of spec 10 §5 to get a different outcome; do not change locked decisions; commit the report and config as "M3b-T<n>: <summary>". The goal is met only when the transcript shows (1) the printed G1 decision table from the committed report apps/eval/reports/G1-<date>.md with every rule's inputs and result, (2) either "CORE BET: PASS" with config/g1.json committed and `eval apply-g1` output showing the settings written, or "CORE BET: FAIL" with the failure summary of spec 10 §5 rule 1 printed for the owner, (3) the total actual spend printed, and (4) `git status --short` printing nothing. Or stop after 80 turns and print what is missing.
```

**Tasks**

| ID | Task | Needs | Specs |
|---|---|---|---|
| M3b-T1 | Preflight: `eval status` shows ≥ 250 ratings for every rater and the facet labels present; keys and LibreTranslate healthy; cost estimate printed | ratings | 10 §2–3 |
| M3b-T2 | Run B0, B1, E1, E2, E3, E3b, E4 (E5 only if Laya is installed) | T1 | 10 §3 |
| M3b-T3 | Generate the report, apply the decision rules, write `config/g1.json`, commit | T2 | 10 §1, §4–5 |
| M3b-T4 | On PASS: `apply-g1` to dev settings; update `DAILY_BUDGET_USD` guidance in `docs/DECISIONS.md`. On FAIL: write `docs/G1-FAIL.md` with the rule 1 details and the 20 worst-ranked liked articles | T3 | 10 §5 |

**Milestone done when:** the report is committed and the goal evidence is printed. **If G1 fails, do
not proceed to M6–M8 until the owner decides.**

---

## 10. M4: HTTP API

**Outcome:** the complete `/api/v1` of spec 08:
- auth with invites
- tenancy with RLS per request
- subscriptions with discovery and OPML
- cards, library and labels
- reading and feedback
- rules
- admin
- quotas, rate limits, CSRF and OpenAPI

**Read first:** spec 08 (all), spec 02 §4–5, spec 05 §5.1, spec 06 §6–7 (the explain and lane fields
it returns), spec 03 §10–11.

**Goal text:**

```
Complete milestone M4 "HTTP API" exactly as specified in docs/PLAN.md §10 and docs/specs/08-api.md, using the existing packages (db, questions, feeds, ranker types) and the conventions of docs/specs/01-architecture.md. Read PLAN.md §0, §2, §10 and the referenced specs first, create one task per row of the M4 task table, and implement them in dependency order, using subagents for independent lanes. Commit each task as "<task-id>: <summary>". Constraints: every per-user query runs through withTenant/TenantTx under RLS; no live third-party calls in tests (emails use MAIL_TRANSPORT=log); locked decisions unchanged; deviations logged in docs/DECISIONS.md with the spec updated. The goal is met only when the transcript shows (1) a final "M4 report" in the format of PLAN.md §0.4 listing M4-T1…M4-T11 each with ✓, commit hash and evidence, and every M4 "Done when" item checked with evidence, (2) the output of `pnpm typecheck && pnpm lint && pnpm test && pnpm test:int` run after the last commit, ending with exit code 0, including the named test api-rls-isolation.int.test.ts, and (3) `git status --short` printing nothing. Or stop after 220 turns and print the report with the unfinished items.
```

**Tasks**

| ID | Task | Needs | Lane | Specs |
|---|---|---|---|---|
| M4-T1 | Plugins: errors, tenant, session auth, CSRF, rate limit, zod provider, swagger | — | A | 08 §1, §11 |
| M4-T2 | Auth, email (templates en/sk, `MAIL_TRANSPORT`), invites, waitlist, `ADMIN_EMAILS` | T1 | A | 08 §2 |
| M4-T3 | Me, preferences, sessions, export, delete/restore | T1 | B | 08 §3 |
| M4-T4 | Subscriptions: discovery, OPML, quotas, `min_interval_s`, refresh functions, backfill enqueue | T1 | B | 08 §4, §6; 03 §10–11 |
| M4-T5 | Cards, library, suggestions, labels, topics (using the M2 lifecycle repository) | T1 | C | 08 §7; 05 §5.1 |
| M4-T6 | `GET /articles` (lanes, folding, sorts, cursor), counts, details, `GET /articles/calibration` | T1 | D | 08 §5.1–5.2; 06 §10 |
| M4-T7 | Article actions and `feedback_events`; learn and rank triggers through the `enqueueRank`/`enqueueLearn` service | T6 | D | 08 §5.3 |
| M4-T8 | Rules endpoints | T1 | C | 08 §8; 06 §3 |
| M4-T9 | Admin endpoints, `ops-event`, `/metrics`, test-only `/dev/last-email` | T1 | E | 08 §9–10 |
| M4-T10 | Plans and quotas enforcement across endpoints | T4, T5, T8 | B | 08 §6 |
| M4-T11 | RLS isolation suite, CSRF test, OpenAPI snapshot | T2–T10 | E | 08 §12 |

**Done when:**

- **T1:** error mapping table tests; tenant plugin sets `app.user_id` per request (integration);
  unauthenticated → 401; a missing `X-FeedIt-Client` on a mutation → 403; rate-limit headers present.
- **T2:**
  - Every row of the `request-code` decision table is tested.
  - Code TTL and the attempt limit.
  - Signup consumes the invite.
  - The admin role comes from `ADMIN_EMAILS`.
  - Cookie attributes; session sliding and revocation.
  - The waitlist upsert.
- **T3:** the preferences deep-merge is validated; export contains every section; delete → login
  within 7 days restores.
- **T4:**
  - Discovery with candidates.
  - OPML import report and export round-trip.
  - The quota errors carry details.
  - Subscribe triggers `refresh_*` and a backfill job (asserted in the pg-boss table).
- **T5:** every card endpoint maps to the lifecycle actions (an id change on edit is returned); library
  localization (sk); suggestions dismiss.
- **T6:**
  - Lanes and statuses filter correctly.
  - Cluster folding picks the highest-p member.
  - Each sort order is stable under cursor pagination (a property test over 200 seeded items).
  - The counts endpoint agrees with the list totals.
- **T7:**
  - Every action is tested, including un-rate, hide, bulk rate, prompt answer, and mute-story
    creating a cluster when missing.
  - The 10th explicit label enqueues `user.learn`.
  - `feedback_events` rows written.
- **T8:** value validation per kind; creating or deleting a rule enqueues `user.rank {full:true}`.
- **T9:** admin-only (403 for users); settings allow-list with zod per key; breaker reset; promote needs
  ≥ 3 holders; the `ops-event` token check.
- **T10:** each limit has one test at the boundary (max OK, max+1 → `QUOTA_EXCEEDED`).
- **T11:**
  - `api-rls-isolation.int.test.ts` calls **every** GET endpoint as user A with user B's data seeded
    and asserts no leakage, including shared cards with B's private fork.
  - The OpenAPI snapshot is committed.

**Milestone done when:** all tasks are done, the full check passes, and the OpenAPI document lists
every endpoint of spec 08 (a count is printed).

---

## 11. M5: Ranking and lanes

**Outcome:** each user's articles get lanes, tiers, rules, demotions, label suggestions and `explain`
through the `user.rank` handler, triggered by matching and by user actions. Weak translations
escalate.

**Read first:** spec 06 §1–7, §9–11; spec 05 §3.4; spec 07 §3.

**Goal text:**

```
Complete milestone M5 "Ranking and lanes" exactly as specified in docs/PLAN.md §11 and docs/specs/06-ranking-learning.md §1–§7 and §9–§11, with the conventions of docs/specs/01-architecture.md. Read PLAN.md §0, §2, §11 and the referenced specs first, create one task per row of the M5 task table, and implement them in dependency order, using subagents for independent lanes. Commit each task as "<task-id>: <summary>". Constraints: packages/ranker stays pure (no I/O; enforced by the boundary lint); no live third-party calls; locked decisions unchanged; deviations logged in docs/DECISIONS.md with the spec updated. The goal is met only when the transcript shows (1) a final "M5 report" in the format of PLAN.md §0.4 listing M5-T1…M5-T6 each with ✓, commit hash and evidence, and every M5 "Done when" item checked with evidence, (2) the output of `pnpm typecheck && pnpm lint && pnpm test && pnpm test:int` run after the last commit, ending with exit code 0, including the named test ranking.e2e.test.ts, and (3) `git status --short` printing nothing. Or stop after 150 turns and print the report with the unfinished items.
```

**Tasks**

| ID | Task | Needs | Lane | Specs |
|---|---|---|---|---|
| M5-T1 | `RankerConfig`, settings override loader, `RANKER_VERSION` | — | A | 06 §11 |
| M5-T2 | `rankArticle`: rules, card score, never/must/boost, demotions, lanes/tiers, story rule, label suggestions, `Explain` | T1 | A | 06 §1–6 |
| M5-T3 | Property tests and truth tables | T2 | B | 06 §12 |
| M5-T4 | `user.rank` handler: context loading, dirty set, batch loads, upserts; `enqueueRank` service used by the API and match | T2 | C | 06 §7 |
| M5-T5 | Weak-translation escalation and `house.expire-rules` | T4 | C | 06 §7; 07 §3; 11 §6 |
| M5-T6 | `ranking.e2e.test.ts`: fetch → enrich → match → rank with fixture engines for two users | T4 | C | 06 all |

**Done when:**

- **T1:** defaults exactly as spec 06 §11; settings overrides validated (invalid JSON → defaults + a
  warning).
- **T2:** every rule code in spec 06 §3.2 is produced by at least one test; `Explain` snapshots for the
  cards, degraded and none sources.
- **T3:** fast-check monotonicity; lane and tier boundary tables; demotion activation tri-state.
- **T4:**
  - The dirty-set SQL covers all four conditions (a test for each).
  - `full` re-ranks the window.
  - Reader-state columns are never modified (asserted).
  - A second run with nothing dirty writes 0 rows.
- **T5:** a `maybe` item with a weak tier-1 translation enqueues `article.translate {forceTier2:true}`
  once; expired rules are removed and trigger a rank.
- **T6:** two users with different cards on the same feeds get different lanes as expected; a `never`
  card hides; a mute hides; a degraded article ends in `maybe` with source `degraded`.

**Milestone done when:** all tasks are done, the full check passes, and coverage of `packages/ranker`
is ≥ 80 %.

---

## 12. M6: Web app (PWA)

**Outcome:** the full reader experience of spec 09: login and onboarding; lanes with swipe and
keyboard training; "Why this?"; the feeds, interests, labels, rules and settings screens; admin; PWA
offline; E2E smoke tests in CI.

**Read first:** spec 09 (all), spec 08 (the DTOs), spec 06 §6.2 (`Explain`), spec 06 §10.

**Goal text:**

```
Complete milestone M6 "Web app" exactly as specified in docs/PLAN.md §12 and docs/specs/09-web-app.md, using the API DTOs from packages/shared and the conventions of docs/specs/01-architecture.md. Read PLAN.md §0, §2, §12 and the referenced specs first, create one task per row of the M6 task table, and implement them in dependency order, using subagents for independent lanes. Commit each task as "<task-id>: <summary>". Constraints: all server state via TanStack Query with optimistic updates as specified; English and Slovak strings for every screen; no live third-party calls (E2E uses the fixture engine and a local fixture feed server); locked decisions unchanged; deviations logged in docs/DECISIONS.md with the spec updated. The goal is met only when the transcript shows (1) a final "M6 report" in the format of PLAN.md §0.4 listing M6-T1…M6-T9 each with ✓, commit hash and evidence, and every M6 "Done when" item checked with evidence, (2) the output of `pnpm typecheck && pnpm lint && pnpm test && pnpm test:int && pnpm --filter web e2e` run after the last commit, ending with exit code 0, listing the six smoke scenarios of spec 09 §9 as passed, and (3) `git status --short` printing nothing. Or stop after 250 turns and print the report with the unfinished items.
```

**Tasks**

| ID | Task | Needs | Lane | Specs |
|---|---|---|---|---|
| M6-T1 | App shell: API client, router, i18n en/sk, theme, layout, auth guard, login/join/waitlist | — | A | 09 §1–2 |
| M6-T2 | Reader: lanes, counts, list items, tier slider, sorts, mark-all-read, Simple mode, clusters, optimistic updates, undo | T1 | A | 09 §3.1–3.3 |
| M6-T3 | Swipe gestures, reason bar, keyboard shortcuts and overlay | T2 | B | 09 §3.3–3.4 |
| M6-T4 | "Why this?" drawer and its actions; the "Did you like it?" prompt | T2 | B | 09 §3.5–3.6 |
| M6-T5 | Onboarding wizard (feeds, bundles, interests, calibration round) | T2 | C | 09 §4 |
| M6-T6 | Feeds manager, Interests (cards, library, suggestions, editor), Labels, Rules, Settings | T1 | C | 09 §5–7 |
| M6-T7 | Admin UI | T1 | D | 09 §8 |
| M6-T8 | PWA: manifest, service worker, offline list cache, background sync; accessibility pass | T2 | D | 09 §1 |
| M6-T9 | Playwright smoke suite in CI | T2–T6 | E | 09 §9 |

**Done when:**

- **T1:** login with a code works against the dev API; `/join?code=` flows to signup; every string is
  in `en.json` and `sk.json` (a test checks key parity).
- **T2:** component tests for the list item (all states); optimistic rating with a simulated server
  error rolls back and shows the toast; the tier slider persists to preferences.
- **T3:** swipe thresholds (15 % feedback, 35 % commit) are tested with synthetic pointer events; every
  shortcut in the spec 09 §3.4 table has a test.
- **T4:** the drawer renders all `Explain` variants (snapshots); "Not really about this" calls the
  examples endpoint and shows the fork confirmation; the prompt appears after `dwell` returns
  `prompt:true`.
- **T5:** the wizard enforces ≥ 1 feed; bundles load; the calibration step polls the counts and
  continues on ≥ 10 items or after 60 s (fake timers).
- **T6:** the card editor shows the authoring hints and enforces limits; OPML import shows the report;
  settings edit every preference field.
- **T7:** admin routes are hidden for non-admins; settings JSON editors validate before saving.
- **T8:** Lighthouse PWA installability passes (`pnpm --filter web lighthouse` output); offline shows
  cached items; axe-core finds no serious or critical violations on the reader, the Why-this drawer and
  onboarding.
- **T9:** the six scenarios of spec 09 §9 pass in CI (a Playwright job is added to `ci.yml`).

**Milestone done when:** all tasks are done and the full check, including E2E, passes.

---

## 13. M7: Personal learning and suggestions

**Outcome:** per-user logistic models trained on Jev features and feedback, calibrated and
auto-activated when they beat the card baseline, with explained contributions; card suggestions from
unexplained likes; learning curves verified on the golden set.

**Read first:** spec 06 §8, §10; spec 05 §7; spec 10 §3.

**Goal text:**

```
Complete milestone M7 "Personal learning and suggestions" exactly as specified in docs/PLAN.md §13, docs/specs/06-ranking-learning.md §8 and §10, and docs/specs/05-classification.md §7, with the conventions of docs/specs/01-architecture.md. Read PLAN.md §0, §2, §13 and the referenced specs first, create one task per row of the M7 task table, and implement them in dependency order, using subagents for independent lanes. Commit each task as "<task-id>: <summary>". Constraints: training code in packages/ranker stays pure; no live third-party calls in tests; the learning-curve check uses cached golden-set answers only (no new API spend); locked decisions unchanged; deviations logged in docs/DECISIONS.md with the spec updated. The goal is met only when the transcript shows (1) a final "M7 report" in the format of PLAN.md §0.4 listing M7-T1…M7-T7 each with ✓, commit hash and evidence, and every M7 "Done when" item checked with evidence, (2) the output of `pnpm typecheck && pnpm lint && pnpm test && pnpm test:int` run after the last commit, ending with exit code 0, and (3) the learning-curve table from M7-T7 printed, and (4) `git status --short` printing nothing. Or stop after 150 turns and print the report with the unfinished items.
```

**Tasks**

| ID | Task | Needs | Lane | Specs |
|---|---|---|---|---|
| M7-T1 | `FEATURE_SPEC_V1` feature builder and sha | — | A | 06 §8.1 |
| M7-T2 | Label extraction from `user_article` and `feedback_events` | — | B | 06 §8.2 |
| M7-T3 | `trainUserModel`: IRLS, L2, CV, Platt, activation, contributions | T1 | A | 06 §8.3 |
| M7-T4 | `user.learn` handler, version retention, triggers (every 10th label, nightly) | T2, T3 | C | 06 §8.4; 11 §6 |
| M7-T5 | Model scoring in `rankArticle` plus `Explain.model`; the LLM-answer exclusion rule | T3 | A | 06 §2, §8.1 |
| M7-T6 | `user.suggest` handler and nightly scheduling | — | B | 05 §7 |
| M7-T7 | Learning-curve check on golden-v1 (cached answers) | T3 | D | 06 §8.3; 10 §3 |

**Done when:**

- **T1:** a feature vector snapshot for a seeded item; the sha changes when the spec changes (test).
- **T2:** the signal table of spec 06 §8.2 is implemented, and "the latest explicit signal wins" is
  tested.
- **T3:**
  - On synthetic data the model recovers the weight signs and reaches AUC ≥ 0.9.
  - Platt scaling lowers ECE on over-confident scores.
  - The activation rule is tested on each branch.
  - Contributions pick the top 3 by |value|.
- **T4:** the 10th label enqueues learn; activation enqueues `user.rank {full}` and `user.suggest`;
  only 3 versions are kept.
- **T5:** `scoreSource='model'` when active; demotions are skipped; never/must/rules still apply;
  `llm`-answered items are scored via cards.
- **T6:** the suggestion flow works end-to-end with a fixture engine; dismissed cards are excluded for
  90 days.
- **T7:** `eval learning-curve` simulates each rater's ratings arriving in order: train on the first n
  (n = 10, 20, 30, 50, 100), evaluate on the rest, and print per-rater AUC of model vs cards-only. The
  table is printed and saved to `apps/eval/reports/`. If the model does not beat cards-only at n = 50
  for most raters, `docs/DECISIONS.md` gets an entry proposing a threshold change. Thresholds are not
  changed silently.

**Milestone done when:** all tasks are done, the full check passes, and the learning-curve table is
printed.

---

## 14. M8: Operations and launch readiness

**Outcome:** the production stack runs on the target box with all housekeeping jobs, backups (with a
tested restore), alerts, the security checklist and the launch checklist completed, ready for the
invite-only beta.

**Read first:** spec 11 (all), spec 04 §6–7, spec 10 §7.

**Goal text:**

```
Complete milestone M8 "Operations and launch readiness" exactly as specified in docs/PLAN.md §14 and docs/specs/11-operations.md, with the conventions of docs/specs/01-architecture.md. Read PLAN.md §0, §2, §14 and the referenced specs first, create one task per row of the M8 task table, and implement them in dependency order, using subagents for independent lanes. Commit each task as "<task-id>: <summary>". Constraints: no secrets committed (only .env.example); locked decisions unchanged (single self-hosted box, no GPU); deviations logged in docs/DECISIONS.md with the spec updated; tasks that need the real host (M8-T8, M8-T9) may be completed against a local production-like compose stack if no host access is available, and the report must say which. The goal is met only when the transcript shows (1) a final "M8 report" in the format of PLAN.md §0.4 listing M8-T1…M8-T9 each with ✓, commit hash and evidence, and every M8 "Done when" item checked with evidence, (2) the output of `pnpm typecheck && pnpm lint && pnpm test && pnpm test:int && pnpm --filter web e2e` run after the last commit, ending with exit code 0, (3) the launch checklist of spec 11 §9 printed with each item's status, and (4) `git status --short` printing nothing. Or stop after 180 turns and print the report with the unfinished items.
```

**Tasks**

| ID | Task | Needs | Lane | Specs |
|---|---|---|---|---|
| M8-T1 | Production `compose.yml`, Dockerfiles, Caddyfile with security headers, `deploy.sh` | — | A | 11 §2–3, §7 |
| M8-T2 | Housekeeping jobs (all of spec 11 §6 not yet implemented) with idempotency tests | — | B | 11 §5–6 |
| M8-T3 | Alerts (`house.alerts`, email de-duplication), `ops-event` integration | T2 | B | 11 §6.1 |
| M8-T4 | Online metrics job (`house.metrics`) and admin display | T2 | C | 10 §7 |
| M8-T5 | Backup and restore-test scripts plus a documented cron | T1 | A | 11 §4 |
| M8-T6 | Security checklist verification (headers, CSP, SSRF suite, sanitization, `pnpm audit`, RLS suite) | T1 | C | 11 §7 |
| M8-T7 | Privacy page, starter bundles verified, SMTP deliverability notes | — | D | 09 §4; 11 §9 |
| M8-T8 | Load sanity test (20 users × 50 feeds × 10 cards, 1 h) on the box or a local production-like stack | T1–T4 | D | 11 §9 |
| M8-T9 | Launch checklist run and `docs/RUNBOOK.md` (deploy, rollback, restore, breaker reset, budget raise, invite batch, model upgrade) | all | A | 11 §3, §8–9 |

**Done when:**

- **T1:** `docker compose -f infra/compose.yml config` validates; images build; `deploy.sh`'s smoke
  step checks `/readyz`; a header check (curl) shows every header of spec 11 §7.
- **T2:** each job in the spec 11 §6 table exists, is scheduled, and has an idempotency test (run
  twice → the same result) and a retention test at the day boundaries.
- **T3:** each alert rule fires in a test and is de-duplicated for 6 h; a resolved condition re-arms it.
- **T4:** the metrics JSON is stored per day; the admin overview shows the like-rate per lane.
- **T5:** `backup.sh` and `restore-test.sh` run successfully against the local stack (output shown);
  the retention pruning logic is tested with fake dates.
- **T6:** a checklist file `docs/SECURITY-CHECK.md` with each item, how it was verified, and the result;
  `pnpm audit --prod` has no high or critical issues.
- **T7:** `/privacy` exists in en/sk; every starter-bundle feed fetches, with the report shown.
- **T8:** a report with CPU, memory, p95 `GET /articles` latency and queue depths meets the spec 11 §9
  targets (or deviations are logged).
- **T9:** `RUNBOOK.md` covers every procedure named; the launch checklist is printed with statuses.
  Items needing the owner (DNS, SMTP provider, invites) are marked "owner".

**Milestone done when:** all tasks are done, the full check passes, and the launch checklist has no
unchecked items except those marked "owner".

---

## 15. M9: Optional extensions (after launch; each item can become its own goal)

| Item | Trigger | Where specified |
|---|---|---|
| **Laya engine** for SK/CZ enrichment | G1 set `laya_track_recommended = true`, or Jev availability or cost becomes a problem | spec 04 §9; [`laya-multilingual.md`](./laya-multilingual.md) (fine-tuning happens on free Kaggle GPUs, outside the server) |
| **Image proxy** for feed images (privacy, HTTPS) | after launch | FeedIt todo; spec 11 §7 note |
| **Opted-in anonymized feedback** to grow the golden set | after launch | spec 10 §7 |
| **Search over my archive** (Postgres FTS; later hybrid search as in DreamCatcher) | user demand | background §2.2 |
| **Open signup and plans** | when costs and moderation are understood | spec 08 §2, §6 |

---

## 16. Change log

| Date | Change |
|---|---|
| 2026-09-24 | Plan restructured into goal-ready milestones and binding specs; decisions 1–8 locked |
