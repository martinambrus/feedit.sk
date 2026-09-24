# Spec 09: Web app (PWA, `apps/web`)

Status: **binding** for behaviour; the visual details are guidance. **Intent:** FeedIt's fast,
swipe-and-keyboard reading experience, rebuilt around lanes, interest cards and "Why this?". It is
mobile-first, installable, works in English and Slovak, and never makes the reader wait for the model.

---

## 1. Technical frame

- React 19, Vite, TanStack Router (file routes), TanStack Query (all server state), Tailwind CSS 4,
  `@use-gesture/react` for swipes, i18next (`en`, `sk`; key files in `src/i18n/`).
- The API client is generated from the zod DTOs in `packages/shared` (a thin typed `fetch` wrapper).
  It always sends `X-FeedIt-Client: web`.
- Optimistic updates for every reader action (read, rate, bookmark, label), rolled back on error with
  a toast ("Couldn't save — retry"). This is FeedIt's "undo on failure" todo.
- **PWA (vite-plugin-pwa):**
  - manifest (name "FeedIt", theme colour, icons)
  - service worker precaches the app shell
  - runtime cache (network-first, 24 h) for `GET /articles*`, holding the last 200 list items for
    offline reading
  - mutations made while offline are queued (Background Sync) and replayed
- **Accessibility:**
  - every swipe action also has a button
  - keyboard navigation throughout
  - focus rings, `aria-live` for toasts
  - colour contrast AA
  - honours `prefers-reduced-motion`
- **Theme:** light and dark, following the system, with a manual override in Settings.

---

## 2. Routes

| Route | Screen |
|---|---|
| `/login` | email → code (two steps). `?code=` prefill for invite links (`/join?code=` redirects here with the invite) |
| `/join` | invite landing: explains invite-only, email field (+ the invite code) |
| `/waitlist` | public waitlist form |
| `/onboarding` | the first-run wizard (§4); shown until it is completed or skipped |
| `/` → `/read/for_you` | the reader (§3) |
| `/read/:lane` | lanes: `for_you`, `maybe`, `everything`, `new`, `bookmarks` |
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
- **List header:**
  - the tier slider (1–5, FeedIt's) filtering `for_you`/`maybe` by minimum tier; its value is saved to
    `prefs.defaultTier`
  - sort toggle (score/date)
  - "Mark all read" (with confirmation) and the "Simple mode" toggle

### 3.2 List item

- **Content:** title, feed icon and title, relative time, excerpt (2 lines; hidden in Simple mode),
  thumbnail (lazy).
- **`topReason` chip:** e.g. "EV battery tech · 0.91", or "Muted-soft", or "Keyword match (degraded)".
- **Label suggestion chips:** dashed outline; tap to assign.
- **Cluster badge:** "+3 sources", which expands to the other members.
- **Rating state:** 👍/👎 highlighted.
- **Expanding** an item shows the sanitized `excerptHtml` or `bodyLead`, a "Read original" button (opens
  the URL in a new tab and calls `/open`), and the action bar: 👍 👎 🔖 label, Why this?, Mute story,
  more (block feed/domain/author, boost feed).
- **Translated items:** a subtle "translated" marker. A toggle shows the English translation of the
  title and excerpt when available.

### 3.3 Rating and training interactions (from FeedIt, adapted)

- **Swipe right** = `prefs.swipe.right` (default like). **Swipe left** = `prefs.swipe.left` (default
  dislike).
  - A swipe must travel ≥ 35 % of the item width. There is visual feedback (a coloured background with
    an icon) past 15 %.
  - Release below the threshold → snap back.
- **Dislike opens a reason bar** for 5 s: `Off-topic · Clickbait · Seen it · Too shallow · Promo ·
  Other`. Tapping one sends the reason. Ignoring it keeps the rating without a reason.
- **Rating** marks read (if `markReadOnRate`) and moves the item out of unread lists after a 400 ms
  animation. **SHIFT + rate** or a long-press + rate also hides the item (`hide: true`).
- **Rating an already-rated item** in the same direction un-rates it (FeedIt behaviour).
- **"Train the whole feed"** (feed view menu): like or dislike all visible unread items, with
  confirmation. Uses `POST /articles/rate-bulk`.
- **Undo toast** after every rating or bulk action (5 s).

### 3.4 Keyboard shortcuts (desktop; `?` shows the overlay)

| Key | Action |
|---|---|
| `j` / `k` | next / previous item (auto-expands if `markReadOnExpand`) |
| `o` or `Enter` | open the original (new tab) |
| `+` / `=` / `Ctrl+Plus` | like (FeedIt: CTRL+PLUS) |
| `-` / `Ctrl+Minus` | dislike, then `1`–`6` picks a reason |
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

### 3.5 "Why this?" drawer (successor of FeedIt's detailed-training modal)

Rendered from `explain` (spec 06 §6.2):

1. **Verdict line:** "For you · 91 % · tier 5", with the source (cards / your personal model /
   keyword fallback).
2. **Your interests:** the cards with probability bars, sorted by p. Each row has:
   - **Not really about this** → `POST /cards/:id/examples {side:'no'}`, which forks the card and shows
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
- the client measures dwell time and posts `/dwell`
- if the response says `prompt: true`, show a bottom sheet "Did you like *<title>*?" with 👍 / 👎 /
  "Ask less often" (sets `feedbackPrompt` one step lower)

---

## 4. Onboarding (first run)

1. **Welcome:** a 3-line explanation (interests, not training; lanes; Why this?).
2. **Add feeds:**
   - paste a URL (discovery with candidate selection)
   - import OPML
   - pick from starter bundles: a static list in `apps/web/src/onboarding/bundles.ts` with ~10 bundles
     (Slovak news, Czech news, Tech, Science, …), each with 3–8 feed URLs, curated before launch
   - at least 1 feed is required to continue
3. **Interests:**
   - library chips grouped by topic (localized)
   - a free-text "Describe something you want to read about" field with examples
   - "Never show me…" chips (anti-interests, optional)
   - at least 1 interest is recommended; a skip is allowed with a warning
4. **Calibration round:**
   - "Rate 10 articles so FeedIt gets you faster" (spec 06 §10)
   - if the items aren't ranked yet (backfill running), show a progress indicator ("Reading your
     feeds… 38/120") by polling `/articles/counts`, and continue when ≥ 10 `maybe`/`for_you` items exist
     or after 60 s
5. **Done** → For you.

---

## 5. Feeds manager

- **List:** folders (drag to reorder, rename), feeds with their health status, last success, error
  text, and a per-feed settings drawer (title override, folder, allow duplicates, hide from sidebar,
  unsubscribe).
- **Add feed:** URL input with discovery (candidate chooser).
- **OPML:** import (with the result report) and export.
- A **dead** feed shows "This feed stopped working on <date>: <reason>" with Unsubscribe / Keep trying
  (admins can reset).

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

Profile (name, language, timezone, theme) · Reading preferences (all fields of spec 08 §3.1 with
explanations) · Sessions (devices, revoke) · Invites (left, create, list, copy link) · Export data ·
Delete account (typed confirmation).

---

## 8. Admin UI

Plain tables and forms, no polish needed:
- **Overview:** tiles and queue depths; a breaker status badge with a Reset button.
- **Usage:** a spend chart (last 30 days) and top users.
- **Settings:** JSON editors with validation per key.
- **Feeds:** filter by status; reset.
- **Library:** CRUD; promote from shared cards (holders ≥ 3).
- **Users:** plan, role, invites.
- **Invites and waitlist.**

---

## 9. E2E smoke tests (Playwright)

1. **Login:** request a code → read it from the dev mail log endpoint (`GET /api/v1/dev/last-email`,
   available only when `NODE_ENV=test`) → verify → onboarding.
2. **Add a feed** (a local fixture feed server) → the items appear in New.
3. **Add a card** → wait for ranking with the fixture engine → an item appears in For you → open Why
   this? → press "Not really about this" → the card becomes a private fork.
4. **Dislike with a reason** → the item leaves the list → undo restores it.
5. **Keyboard:** `j`, `k`, `+`, `-`, `b` work on the list.
6. **Mobile viewport:** a swipe right likes the item.
