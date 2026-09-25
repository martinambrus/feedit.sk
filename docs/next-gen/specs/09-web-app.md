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
  - private offline storage requires an explicit device-local choice (default disabled on a new
    browser), explaining shared-device access and the 24-hour limit. With it disabled the shell
    works offline, but private article storage and durable offline actions are unavailable; show
    that state rather than silently writing private IndexedDB records
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
    lifetime; explain this local-device persistence in settings. Server bookmark mirrors remain
    retained indefinitely independently of this small, expiring local cache; an offline queued
    bookmark is "Waiting to sync", never falsely "Article saved in full" before capture succeeds.
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
and favicons are network requests to publishers: global `loadRemoteImages=false` by default, then
apply the remembered per-user-feed `inherit|allow|block` override returned by the API. Explicit source
allow overrides global block, including on another device or after unsubscribe in Bookmarks. Omit
`src` until `effectiveImagesAllowed` is true; placeholders must not trigger fetches. Offer
"Always show images from this feed", "Always block" and "Use global setting", persisted through
the feed-preferences API. The decision follows `mediaPolicyFeedId`, never whichever duplicate source
happens to allow loading. When enabled use `referrerPolicy="no-referrer"` and lazy loading; never
fetch active SVG/HTML as embedded documents. Sanitized HTML uses inert image placeholders; hydrate
their validated URLs only through the same policy. Text-mirror retention does not promise archived
images or attachments; those may still depend on the publisher until Q14 is resolved.

**Refresh and errors:** poll list/counts every 5 seconds only while a visible page has
`rankingPending`/explicit pending analysis requests, back off to 30 seconds when idle, and pause
offline/background polling. New items in off/unselected training feeds are not unfinished jobs and
must not cause endless polling or "AI is working" messages.
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
  - Per-feed classification badge: **Off**, **Training: selected articles**, or **Active: new
    articles**. Off is the default; subscribing/importing/adding interests does not enable inference.
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
- **Untrained items:** show "Not analyzed" and retain normal read/rate/bookmark actions. In a feed
  set to Training, offer a selection checkbox and "Analyze selected N articles" (at most 20 per
  submission), with exact selected titles visible before submission. Do not auto-select a calibration
  batch, all visible items or future arrivals. Cached answers may satisfy the request immediately;
  queued work shows its actual request status. Active feeds also allow explicit selection of old
  backlog; the normal active switch does not analyze it automatically.
  In an off feed, "Start training and analyze these N" may use the explicit combined
  `startTraining:true` action. Store the returned request ids with the selected items and send the
  matching `analysisRequestId` with their ratings, including offline replay; never attach a later
  unrelated request implicitly. Direct off-feed views remain neutral even when a duplicate item
  already has cached scores through another active source.
- **Saved bookmarks:** show capture status "Saving article", "Full text saved", "Partial text
  saved" or "Could not capture article". Open the saved snapshot by default from Bookmarks, with its
  capture date and a separate original-source link when available. Saved text remains accessible
  after unsubscribe/source failure and after 30-day compression. Never replace an older saved
  version silently with live text. Partial/failed capture offers an explicit "Retry capture" and
  explains teaser/paywall/unavailable-source limitations. Keep any previous saved text readable
  during retry. Images obey the saved origin feed's remembered preference; missing remote assets
  do not erase the retained text. Export includes saved full/partial content and truthful status.

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
  This is a rating convenience only: it does not submit an analysis request, activate inference or
  authorize the unselected remainder of a feed. The UI never labels rating alone as turning AI on.
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
- only if `preferences.implicitFeedback` is enabled; otherwise do not measure/post dwell or show a
  dwell-triggered prompt. The setting explains this optional behavioral learning separately from
  explicit thumbs-up/down and neutral organizational labels
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
   - every new subscription starts with classification Off. Explain that feed fetching and reading
     work without inference. Offer explicit Training for a chosen feed; adding a starter bundle or
     importing OPML must not enable AI for all of its feeds
3. **Interests:**
   - library chips grouped by topic (localized)
   - a free-text "Describe something you want to read about" field with examples
   - "Never show me…" chips (anti-interests, optional)
   - at least 1 interest is recommended; a skip is allowed with a warning
4. **Calibration round:**
   - "Choose articles to teach FeedIt" (spec 06 §10). Present local feed titles for explicit selection;
     only the ids the user confirms go to `/subscriptions/:feedId/analyze`. Changing to Training or
     simply rendering this screen does not submit any analysis requests
   - after selection, show real request completion plus "38 scored of 120 available" from counts
     without claiming all available items are processing. `/articles/calibration` offers only
     already authorized eligible items; it never expands the selected set or authorizes new work
   - user-selected articles may be rated while requests run. After 60 s, with no usable feed items,
     or on deliberate skip, continue with the available sample (including zero). Do not trap the
     user waiting for ten items. Persist completion once and allow calibration later
   - offer the separate explicit "Enable automatic classification for new articles" control for
     a chosen feed; explain that historical backlog remains manual. Never activate by reaching a
     hidden number of ratings (provisional Q11 policy, spec 08 §4.1)
5. **Done** → For you when personalized results exist, otherwise New with readable untrained items.

---

## 5. Feeds manager

- **List:** folders (drag to reorder → `preferences.folderOrder`; rename → `POST /subscriptions/folders/rename`), feeds with their health status, last success, error
  text, and a per-feed settings drawer (title override, folder, allow duplicates, hide from sidebar,
  image override, inference mode, unsubscribe).
- **Classification controls:** Off → explicit Training enables only the selection UI; the user then
  requests analysis for particular articles. "Enable automatic classification" explicitly sets
  Active for new arrivals and shows the activation time. Switching back to Training/Off stops new
  automatic demand; explain that already running shared work can finish for another authorized
  subscriber. Show failures and retry only the chosen requests, never a hidden feed-wide retry.
- **Images:** remember Inherit / Always allow / Always block per account and feed. Selecting Always
  allow works even while the global switch is off. Preserve the choice across logout/login,
  another device, unsubscribe/re-add and bookmarked snapshots; account deletion removes it.
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
  - **Library updates:** show old/new semantic text side by side and the version; actions are
    "Apply this update", "Keep my current version" and "Customize instead". Applying explicitly
    replaces only an unchanged held library card and preserves strength/scope/title override.
    Private/custom forks and examples never change automatically; the editor must show/review them
    during customization. A new version badge is informational, not consent to migrate
  - **Publication requests:** notify the original creator of an exact proposed public card,
    including text, translated title and topics; offer Approve / Decline. Explain internal reuse
    separately from public listing. Approval of one version does not authorize later semantic edits;
    no response is not approval and no countdown implies publication by timeout
- **Card editor:**
  - title (optional), "I want to read about…" (interest), "…but not about" (not_for), strength, scope
  - live guidance: the authoring rules from spec 05 §8 as hints ("Describe one topic", "Avoid 'not' in
    the main text; use the 'but not' field")
  - character counters
- **Labels page:** name, definition, "but not", colour. The list shows how many articles carry each
  label. Explain that labels organize content and never mean "like" or "dislike"; assigning one does
  not activate inference or train positive preference. Explicit label examples teach label meaning
  only for already selected/authorized work.
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
The offline-storage choice is per browser/device and distinct from server bookmark retention.
Global remote images links to remembered per-feed exceptions. Optional implicit-feedback learning
is off by default with a visible toggle; explicit ratings remain usable while it is off. Export
reports capture failures/partial snapshots and includes retained bookmark bodies after decompression.

---

## 8. Admin UI

Plain tables and forms, no polish needed:
- **Overview:** tiles and queue depths; a breaker status badge with a Reset button.
- **Usage:** a spend chart (last 30 days) and top users.
- **Settings:** JSON editors with validation per key.
- **Provider accounts:** dedicated Jev and Ollama credential panels, separate from JSON settings.
  Show only configured source, active/candidate version, validation status and sanitized capabilities;
  never reveal a stored key, suffix or ciphertext. A password input stages a replacement (clear it
  immediately on submit/navigation), then explicit Validate and Activate steps preserve the old
  working key until success. Validation explains that it makes a small provider call under budget.
  Support owner personal accounts. Show stale-version/invalid-key/rate-limit/model-capability errors
  without echoing submitted text. Rotation/deactivation refreshes status; disabling explains that
  provider-side key revocation must also be done in the provider account. Do not persist keys in
  browser storage, query caches, analytics, crash reports or URL parameters
- **Feeds:** filter by status; reset.
- **Library:** metadata management, immutable semantic versions and promotion candidates. Request
  creator approval for the exact proposed listing before exposing Promote. Show Pending/Approved/
  Declined/Held for inactive creator; no-response/inactive policy remains held pending Q12. A fresh
  payload requires a new approval; an admin cannot impersonate the creator. Public semantic updates
  advertise an opt-in successor instead of editing all readers' cards in place.
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
2. **Add a feed** (a local fixture feed server) → its mode is Off and items appear in New; polling,
   reading and adding a card make zero article-inference calls.
3. **Select training:** explicitly select one article and request analysis; only that request runs,
   an authorized item appears in For you → open Why this? → press "Not really about this" → the card
   becomes a private fork. A selected rating carries its exact request id. Siblings remain untrained.
4. **Dislike with a reason** → the item leaves the list → undo restores it.
5. **Keyboard:** `j`, `k`, `+`, `-`, `b` work on the list.
6. **Mobile viewport:** a swipe right likes the item.
7. **Automatic mode:** explicit enable applies to new arrivals only. Old backlog stays unselected;
   switch Off while a request is queued and verify stale work cannot restart inference for that user.
8. **Bookmark mirror:** capture a fixture's full body, unsubscribe, make its original URL fail and
   simulate snapshot compression after 30 days. Saved view and export retain the same text/HTML;
   partial/failed capture remains visibly distinct, and another account cannot read the snapshot.
9. **Remembered images:** global images off, per-feed Always allow → images load in list/detail;
   reload/login on another context and unsubscribe → bookmarked saved view keeps that source choice.
   Always block overrides global on. Inherit resets to the global value; no blocked placeholder
   secretly triggers a network request.
10. **Credentials:** admin stages a fixture key, validation fails and the old key remains active;
    a valid candidate can activate, stale validation cannot. No secret appears in subsequent UI,
    routes, browser stores, generated reports or API responses; disabled mode cannot fall back to env.
11. **Cards and labels:** original creator approves an exact proposed public version; silence,
    unrelated adopter approval or changed metadata cannot publish. A library update changes only a
    holder who explicitly applies it and never silently modifies a private fork. Assigning a neutral
    label does not count as a positive rating or enable inference.

**PWA check** (M6-T8, a separate `pwa.pw.ts`; Lighthouse ≥ 12 no longer has a PWA category). It uses
`chromium.launchPersistentContext(tmpDir, { channel: 'chromium' })`, because the default headless
shell and incognito contexts report `in-incognito` installability errors:
- `navigator.serviceWorker.ready` resolves
- the manifest link is present and valid
- the Chrome DevTools Protocol call `Page.getInstallabilityErrors` returns `[]`
- offline: after explicit local offline-storage opt-in and one online visit, `context.setOffline(true)` and a reload still render the cached
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
- private storage remains empty before device opt-in; disabling implicit feedback prevents dwell
  requests and inferred behavior-learning events while explicit thumbs-up/down still work

Browser capability reference (checked 2026-09-25):
[MDN Background Synchronization API](https://developer.mozilla.org/en-US/docs/Web/API/Background_Synchronization_API)
documents limited browser support; foreground replay is therefore part of the acceptance gate.
