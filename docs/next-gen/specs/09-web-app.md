# Spec 09: Web app (PWA, `apps/web`)

Status: **binding** for behaviour; the visual details are guidance. **Intent:** FeedIt's fast,
swipe-and-keyboard reading experience, rebuilt around lanes, interest cards and "Why this?". It is
mobile-first, installable, works in English and Slovak, and never makes the reader wait for the model.

---

## 1. Technical frame

- React 19, Vite, TanStack Router (file routes), TanStack Query (all server state), Tailwind CSS 4,
  `@use-gesture/react` for swipes, i18next (`en`, `sk`).
- **i18n files** are **per feature**, as i18next namespaces: `src/features/<feature>/i18n/en.json` and
  `sk.json`, plus `src/i18n/common.{en,sk}.json`. Parallel work on different features never edits the
  same file. A test asserts key parity between `en` and `sk` for every namespace.
- The API client is generated from the zod DTOs in `packages/shared` (a thin typed `fetch` wrapper).
  It sends same-origin credentials and `X-FeedIt-Client: web`; authenticated mutations carry the
  durable `Idempotency-Key` and reader `stateVersion` required by spec 08. Validate response DTOs
  at the boundary. All query keys include the account id and normalized reader filters.
- Optimistic updates for every reader action (read, rate, bookmark, label), rolled back on error with
  a toast ("Couldn't save — retry"). This is FeedIt's "undo on failure" todo.
- **PWA (vite-plugin-pwa):**
  - manifest (name "FeedIt", theme colour, icons)
  - service worker precaches versioned, public app-shell assets only
  - explicitly persist an allowlisted projection of the last 200 list items and already opened
    sanitized detail texts in IndexedDB, keyed by account id, with 24-hour expiry and a 10 MiB cap;
    list summaries alone cannot provide full offline reading. An uncached detail says "Connect to
    load this article". Do not wildcard-cache `/articles*`: counts, calibration, explanations,
    exports, auth, admin responses and session data are excluded
  - reader mutations made offline are queued in the same account-scoped store with original
    mutation id, expected state version, creation time and optimistic before-state. Only read/unread,
    rating, bookmark and existing-label actions can queue offline; bulk operations, edits to
    interests/feeds/settings, login and account deletion require a connection
  - replay runs on startup, `online` and foreground return; Background Sync is optional acceleration,
    not a dependency. Replay at most 24 hours after creation, in order per article, with one elected
    tab/service-worker dispatcher. Serialize dependent versions after acknowledged local actions;
    stop on a cross-device stale version and offer refresh/review. Retry network/5xx/429 with bounded
    backoff and Retry-After, never blindly retry 400/403/404/409. Freeze on 401 pending reauthentication
  - reconnect verifies `/me` before replay; work queued for A is never submitted under B. Logout,
    account deletion or account switch immediately clears in-memory queries, private IndexedDB,
    pending mutations and any legacy private caches and broadcasts the reset to all tabs. Explicit
    logout while offline clears local data immediately and completes server revocation when online;
    a local signed-out marker blocks automatic `/me` sign-in and queue replay until that pending
    logout completes. Process it before any new login, so it cannot revoke a later account's session.
    Never imply the server session was already revoked
  - offline cached content is available only for the last locally selected account within its cache
    lifetime; explain this local-device persistence in settings (privacy defaults: PLAN §17).
    Session tokens never enter IndexedDB/localStorage. A service-worker update preserves queued
    records via versioned migrations; prompt before reloading an actively used reader
- **Accessibility:**
  - every swipe action also has a button
  - keyboard navigation throughout
  - focus rings, `aria-live` for toasts
  - colour contrast AA
  - honours `prefers-reduced-motion`
  - labelled controls, ≥44px touch targets, semantic list landmarks, sensible tab order and focus
    restoration/trapping for dialogs and sheets; do not convey lane/rating status by colour alone
  - the disappearing row transfers focus to a predictable adjacent item; undo restores focus.
    Drag reordering and swipes have keyboard/button alternatives. Timed feedback remains available
    through the action menu after the toast disappears, and visible timers pause while focused
- **Theme:** light and dark, following the system, with a manual override in Settings.

**Untrusted content:** render ordinary strings via React escaping; only the server-sanitized fragment
may enter the HTML sink. No scripts, inline handlers, forms, iframes or active URL schemes from feeds.
External links use `rel="noopener noreferrer"`; opening a non-null source URL is a direct user gesture so
popup blockers do not reject it while an `/open` request is awaiting a response. Remote thumbnails
and favicons are network requests to publishers: `loadRemoteImages=false` by default, omit image
`src` entirely until the user opts in (a placeholder alone must not still trigger a request).
When enabled use `referrerPolicy="no-referrer"` and lazy loading; never fetch active SVG/HTML as
embedded documents. Sanitized excerpt HTML contains no automatically loaded remote images.

**Refresh and errors:** poll list/counts every 5 seconds only while a visible page has
`rankingPending`/New items, back off to 30 seconds when idle, and pause offline/background polling.
Cancel obsolete queries on route changes. No SSE infrastructure is required for the first version.
Refresh from page one on `STALE_CURSOR`; de-duplicate ids and preserve selection/scroll by id.
Every screen has loading, empty, offline, recoverable error and quota-limit states; 401 navigates to
login without leaking the previous account's UI. Slow classification leaves articles readable in New.

---

## 2. Routes

| Route | Screen |
|---|---|
| `/login` | existing users: email → code (two steps) |
| `/join?code=…` | invite landing: explains invite-only, shows the prefilled invite code, asks for the email, then continues with the same code step as `/login` (sending `inviteCode`) |
| `/waitlist` | public waitlist form |
| `/onboarding` | the first-run wizard (§4); shown while `preferences.onboardingCompletedAt` is null. Finishing or skipping sets it |
| `/` → `/read/for_you` | the reader (§3) |
| `/read/:lane` | lanes: `for_you`, `maybe`, `everything`, `new`, `bookmarks`, and the explicit recovery view `hidden` |
| `/read/feed/:feedId`, `/read/folder/:name`, `/read/label/:labelId` | filtered reader |
| `/interests` | interest cards: mine, library browser, suggestions (§6) |
| `/labels` | labels manager |
| `/feeds` | subscriptions manager: add, OPML import/export, folders, per-feed settings, health |
| `/rules` | mutes, blocks, boosts |
| `/settings` | profile, language, preferences (spec 08 §3.1), sessions, invites, export, delete account |
| `/admin/*` | overview, usage, settings, feeds, library, users, invites and waitlist (admins only) |

---

## 3. Reader

### 3.1 Layout

- **Desktop (≥ 1024 px):** a left sidebar (lanes with unread counts, folders → feeds, labels,
  bookmarks), a centre list and a detail pane.
- **Mobile:** a single column with a top bar (lane switcher + counts) and a bottom sheet for details.
- **Sidebar feed rows:**
  - Feeds with `status = quarantined` show a warning dot; `dead` feeds show a red banner in the feed view.
  - Sidebar lanes: **For you**, **Maybe** (badge: "help me learn"), **Everything else** (collapsible,
    hidden when `prefs.hideEverything`), **New** (not scored yet), **Bookmarks**.
  - A visible "Show hidden" entry in the reader's More menu opens `/read/hidden`. It shows Why this?
    and distinguishes "Hidden by you" (`archivedAt`) from Never/block/mute/demotion rules. "Unhide"
    clears only the explicit archive via `/unhide`; rule-driven exclusions link to the responsible
    rule/card/preference for an intentional edit. Hidden recovery never changes normal lane defaults.
- **List header:**
  - the tier slider (1–5, FeedIt's) filtering `for_you`/`maybe` by minimum tier; its value is saved to
    `prefs.defaultTier`
  - sort toggle (score/date)
  - "Mark all read" (with confirmation) and the "Simple mode" toggle

### 3.2 List item

- **Content:** title, feed icon and title, relative time, excerpt (2 lines; hidden in Simple mode),
  thumbnail (lazy).
- **`topReason` chip**, rendered from the structured `TopReason` (spec 08 §5.1) with localized
  strings: e.g. "EV battery tech · 0.91", "Boosted source", or "Keyword match (model unavailable)".
- **Label suggestion chips:** dashed outline; tap to assign.
- **Cluster badge:** "+3 sources", which expands to the other members.
- **Rating state:** 👍/👎 highlighted.
- **Expanding** an item shows the sanitized `excerptHtml` or `bodyLead`, a "Read original" button (opens
  the URL in a new tab and calls `/open`), and the action bar: 👍 👎 🔖 label, Why this?, Mute story,
  more (block feed/domain/author, boost feed).
- **Linkless items:** remain readable/rateable/bookmarkable; omit "Read original" and domain-based
  actions when no usable URL exists. Show missing-image/author/date fallbacks without broken controls.
- **Translated items:** a subtle "translated" marker. A toggle shows the English translation of the
  title and excerpt when available.

### 3.3 Rating and training interactions (from FeedIt, adapted)

- **Swipe right** = `prefs.swipe.right` (default like). **Swipe left** = `prefs.swipe.left` (default
  dislike).
  - A swipe must travel ≥ 35 % of the item width. There is visual feedback (a coloured background with
    an icon) past 15 %.
  - Release below the threshold → snap back.
- **Dislike opens a reason bar** for 5 s: `Off-topic · Clickbait · Seen it · Too shallow · Promo ·
  Other`. Keep one optimistic pending action until a reason is selected or the timer expires, then
  submit it once with its finalized body/idempotency key. Ignoring it saves the rating without a
  reason. Undo before submission cancels the pending action; after submission it uses the receipt.
  A later reason edit is a new mutation, never a changed body replayed under an old key.
- **Rating** marks read (if `markReadOnRate`) and moves the item out of unread lists after a 400 ms
  animation. **SHIFT + rate** or a long-press + rate also hides the item (`hide: true`).
- **Rating an already-rated item** in the same direction un-rates it (FeedIt behaviour).
- **"Train the whole feed"** (feed view menu): like or dislike all visible unread items, with
  confirmation showing exact count; freeze up to 200 loaded visible ids/versions at confirmation.
  Label it "Rate these N visible articles" so it never implies unloaded feed history will be rated.
  Uses `POST /articles/rate-bulk`.
- **Undo toast** after every rating or bulk action (5 s), also available in the recent-action menu
  for the API's 10-minute undo window. Call `POST /articles/undo {mutationId}`; never approximate a
  previous rating/read/archive state with `rating:null`. If a newer device action prevents undo,
  refresh and show that conflict. A failed optimistic update restores only that action's changes,
  not an old whole-query snapshot that could overwrite subsequent successful actions.

### 3.4 Keyboard shortcuts (desktop; `?` shows the overlay)

| Key | Action |
|---|---|
| `j` / `k` | next / previous item (auto-expands if `markReadOnExpand`) |
| `o` or `Enter` | open the original (new tab) |
| `+` / `=` | like |
| `-` | dislike, then `1`–`6` picks a reason |
| `Shift` + like/dislike | rate and hide |
| `b` | bookmark |
| `l` | label picker |
| `w` | Why this? |
| `m` | mute story (then `1` / `3` / `7` / `0`=30 days) |
| `x` | mark read / unread |
| `Shift+A` | mark all read (with confirmation) |
| `g` then `f`/`m`/`e`/`n`/`b` | go to For you / Maybe / Everything / New / Bookmarks |
| `s` | toggle Simple mode (FeedIt: CTRL+M also works) |
| `/` | focus the feed filter |

Disable reader shortcuts inside inputs, textareas, contenteditable regions, open modal controls and
during IME composition. Never intercept browser zoom (`Ctrl/Cmd` + `+`/`-`), navigation or assistive
technology shortcuts. Shortcut sequences expire after one second and announce pending mode.

### 3.5 "Why this?" drawer (successor of FeedIt's detailed-training modal)

Rendered from `explain` (spec 06 §6.2):

1. **Verdict line:** "For you · 91 % · tier 5", with the source (cards / your personal model /
   keyword fallback).
2. **Your interests:** the cards with probability bars, sorted by p. Each row has:
   - **Not really about this** → `POST /cards/:id/examples {articleId, side:'no'}`, which forks the card (new id: the client updates its cache) and shows
     "Learned: this isn't *EV battery tech*"
   - **Yes, exactly this** → examples yes
   - **Edit card**
3. **About the article:** content type, topic path (localized), depth (5 dots), and the clickbait,
   promotional and time-sensitive meters. Each meter offers "Never show me clickbait" and similar →
   `prefs.demote.<flag> = 'on'`.
4. **Rules applied:** the codes rendered as sentences, each with an "undo" (delete the rule / reset
   the preference).
5. **Personal model** (if any): the top 3 contributing factors in plain language ("You often like
   articles from this source").
6. **Actions:** make a card from this (prefilled editor, §6), boost/block source, block author, mute
   keyword (select a word from the title).

### 3.6 "Did you like it?" prompt

When the page becomes visible again after `/open`:
- record only the away interval correlated with that article's explicit open, once, with a maximum
  of 30 minutes; post `/dwell` on the next foreground return. Do not accumulate general background
  tab time or claim this proves reading. If several sources were opened without a clear correlation,
  omit dwell rather than attach it to an arbitrary item
- if the response says `prompt: true`, show a bottom sheet "Did you like *<title>*?" with 👍 / 👎 /
  "Ask less often" (sets `feedbackPrompt` one step lower)

---

## 4. Onboarding (first run)

1. **Welcome:** a 3-line explanation (interests, not training; lanes; Why this?).
2. **Add feeds:**
   - paste a URL (discovery with candidate selection)
   - import OPML
   - pick from starter bundles: a static list in `apps/web/src/features/onboarding/bundles.ts` with ~10 bundles
     (Slovak news, Czech news, Tech, Science, …), each with 3–8 feed URLs, curated before launch
   - at least 1 feed is required to continue
3. **Interests:**
   - library chips grouped by topic (localized)
   - a free-text "Describe something you want to read about" field with examples
   - "Never show me…" chips (anti-interests, optional)
   - at least 1 interest is recommended; a skip is allowed with a warning
4. **Calibration round:**
   - "Rate 10 articles so FeedIt gets you faster" (spec 06 §10)
   - if backfill is running, show "38 scored of 120 available" from `/articles/counts` without
     presenting it as pipeline-job completion. Poll `/articles/calibration` and begin with up to
     10 returned items (Maybe, then Everything per spec 06); For you count is not the readiness test
   - after 60 s, or with no usable feed items, allow continue/skip with the available sample, including
     zero. Do not trap the user waiting for ten items or a dead feed. Persist completed onboarding
     once and allow calibration later from the reader
5. **Done** → For you.

---

## 5. Feeds manager

- **List:** folders (drag to reorder → `preferences.folderOrder`; rename → `POST /subscriptions/folders/rename`), feeds with their health status, last success, error
  text, and a per-feed settings drawer (title override, folder, allow duplicates, hide from sidebar,
  unsubscribe).
- **Add feed:** URL input with discovery (candidate chooser).
- **OPML:** import (with the result report) and export.
- A **dead** feed shows "This feed stopped working on <date>: <reason>" with **Unsubscribe** or
  **Dismiss**. Dismiss only hides the banner, client-side in account-scoped `localStorage`; a new
  failure timestamp restores the banner. Clear this state on logout. Admins can reset the feed.

---

## 6. Interests, labels, rules

- **Interests page:**
  - **My interests:** cards with their strength selector (Must / Love / Like / Never) and scope
    selector (all feeds / one feed); edit; examples list (remove); delete.
  - **Suggestions:** Add / Dismiss.
  - **Library:** browse by topic, search, add with a strength.
- **Card editor:**
  - title (optional), "I want to read about…" (interest), "…but not about" (not_for), strength, scope
  - live guidance: the authoring rules from spec 05 §8 as hints ("Describe one topic", "Avoid 'not' in
    the main text; use the 'but not' field")
  - character counters
- **Labels page:** name, definition, "but not", colour. The list shows how many articles carry each
  label.
- **Rules page:** grouped by kind, with expiry countdowns and delete.

---

## 7. Settings

Profile (name, language, timezone, theme → `preferences.theme`) · Reading preferences (all fields of spec 08 §3.1 with
explanations) · Sessions (devices, revoke) · Invites (left, create, list, copy link) · Export data ·
Delete account (typed confirmation).
Explain the 7-day restore window before deletion, show queued-unsynced action count, and require an
online successful response before saying deletion completed. Export has a progress/cancel/error
state. Offline storage has a "Clear downloaded articles" control and displays its 24-hour limit;
clearing downloads does not silently discard unsent reader actions without confirmation.

---

## 8. Admin UI

Plain tables and forms, no polish needed:
- **Overview:** tiles and queue depths; a breaker status badge with a Reset button.
- **Usage:** a spend chart (last 30 days) and top users.
- **Settings:** JSON editors with validation per key.
- **Feeds:** filter by status; reset.
- **Library:** CRUD; a "Promotion candidates" list (`GET /admin/library/candidates`) with a Promote action (`POST /admin/library/promote`).
- **Users:** plan, role, invites.
- **Invites and waitlist.**

---

## 9. E2E smoke tests (Playwright)

**Environment** (`apps/web/playwright.config.ts`). Playwright starts the `webServer` entries **before**
`globalSetup`, so preparation happens in the first entry. The entries are, in order:
1. `pnpm --filter @feedit/testing e2e:prepare && pnpm --filter @feedit/testing fixtures:serve`.
   `e2e:prepare` creates `feedit_e2e_<runId>` from the current template (spec 02 §1.1) and runs
   `pnpm db:seed` against it. `fixtures:serve` then serves 3 feeds and their article pages, plus the
   **fake TypeSafe server** (spec 04 §10, `latencyMs: 50`) on fixed test ports.
2. `apps/api` and 3. `apps/worker`, each with:
   - `NODE_ENV=test`, `SIGNUP_MODE=open`, `RATE_LIMITS_ENABLED=false`, `FETCH_ALLOW_PRIVATE=true`
   - `MAIL_TRANSPORT=log`, `SESSION_PEPPER=test`
   - `TYPESAFE_API_KEY=test`, `TYPESAFE_BASE_URL=<fake server URL>`
   - `PUBLIC_BASE_URL=http://localhost:<preview port>`, so the browser's `Origin` passes the CSRF check
     (spec 08 §1)
   - `DATABASE_URL*` for `feedit_e2e_<runId>`
4. `pnpm --filter @feedit/web exec vite build && pnpm --filter @feedit/web exec vite preview --port <preview port>`,
   with the preview server proxying `/api` to the API.

Test files are named `*.pw.ts` so Vitest never picks them up (spec 01 §6).

**Scenarios:**

1. **Login:** request a code → read it from the dev mail log endpoint (`GET /api/v1/dev/last-email`,
   available only when `NODE_ENV=test`) → verify → onboarding.
2. **Add a feed** (a local fixture feed server) → the items appear in New.
3. **Add a card** → wait for ranking with the fixture engine → an item appears in For you → open Why
   this? → press "Not really about this" → the card becomes a private fork.
4. **Dislike with a reason** → the item leaves the list → undo restores it.
5. **Keyboard:** `j`, `k`, `+`, `-`, `b` work on the list.
6. **Mobile viewport:** a swipe right likes the item.

**PWA check** (M6-T8, a separate `pwa.pw.ts`; Lighthouse ≥ 12 no longer has a PWA category). It uses
`chromium.launchPersistentContext(tmpDir, { channel: 'chromium' })`, because the default headless
shell and incognito contexts report `in-incognito` installability errors:
- `navigator.serviceWorker.ready` resolves
- the manifest link is present and valid
- the Chrome DevTools Protocol call `Page.getInstallabilityErrors` returns `[]`
- offline: after one online visit, `context.setOffline(true)` and a reload still render the cached
  list and a previously opened detail; uncached detail is clearly unavailable
- queue a rating offline, reconnect and replay twice: exactly one feedback event and the correct
  state remain. Test replay without Background Sync (including Firefox), a 401 pause, expired queue
  entries, and a second device's conflicting edit
- logout/login as another fixture account in the same browser and confirm no prior list, detail,
  explanation, pending mutation or toast survives; account deletion clears every local private store
- exact undo restores an earlier opposite rating, reason, read status and SHIFT-hide; bulk undo
  refuses an intervening edit atomically
- an excluded article is recoverable via Show hidden with its explanation; unhide clears only a
  manual archive and does not remove a Never card or rule behind the user's back
- keyboard/screen-reader focus across row removal, reason bar, sheet, toast and undo; reduced-motion
  mode, browser zoom shortcuts and input/IME typing are unaffected

Browser capability reference (checked 2026-09-25):
[MDN Background Synchronization API](https://developer.mozilla.org/en-US/docs/Web/API/Background_Synchronization_API)
documents limited browser support; foreground replay is therefore part of the acceptance gate.
