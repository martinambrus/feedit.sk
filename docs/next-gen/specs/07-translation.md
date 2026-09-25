# Spec 07: Translation (`packages/translate`)

Status: **binding**. **Intent:** if Jev classifies Slovak/Czech text worse than English (measured at
gate G1), give it an English *gist* of each non-English article. Do it once per article, for free on
the box's CPU, and fall back to Ollama Cloud GLM only when the free translation is broken or weak.
Claude is not used, for cost reasons (a locked decision).

---

## 1. When translation runs

- `settings['language_modes']` (default from `LANGUAGE_MODES` env): a map `lang → 'native' | 'translate'`.
  The default is `native` for everything until G1 decides.
- Article translation requires both `modes[article.lang] === 'translate'` and current manual/active
  inference authorization (spec 04 §1.1). With eligible demand, native mode proceeds to enrich. With
  no demand, the pipeline stops after read/extraction; neither free MT nor paid inference runs.
- A subscription begins `off`; manual training authorizes exactly the selected article snapshot, and
  activating automatic inference applies only to new items after activation. Disabling or changing
  its inference version cancels unadmitted translation/escalation work. Merely opening/bookmarking
  an article or another subscriber's activation does not authorize this user's inference.
- `CARD_TEXT_MODE` (`settings['card_text_mode']`, `'as_written' | 'english'`) decides whether card
  texts are translated to English at creation (spec 05 §5.1). It is set at G1 as well.

---

## 2. Tiers

| Tier | Engine | Used for | Cost |
|---|---|---|---|
| 1 | **LibreTranslate** (self-hosted container with Argos models `sk→en`, `cs→en`, plus `en` for card text) | eligible manual/active articles in a `translate` language, and explicit card authoring in `english` mode | €0 (CPU) |
| 2 | **Ollama Cloud**, `OLLAMA_MODEL_FAST` (`glm-5.3-flash`); `OLLAMA_MODEL_STRONG` (`glm-5.3`) only for the admin "hard feed" flag | tier-1 `fail`, tier-1 `weak` on a `maybe`-lane article, and feeds flagged `translate_strong` | ≈ $0.00014 per article (flash) |

The container runs with `LT_LOAD_ONLY=en,sk,cs` and `LT_DISABLE_WEB_UI=true`. Its API port is only
reachable on the compose network. Pin the container and installed Argos package versions/checksums;
do not download a moving model index at each startup. Read `/languages` and verify `sk→en` and
`cs→en` with fixture translations before G1. A language being detectable does not mean a translation
model is installed. Unsupported tier-1 source languages yield `fail` with a reason and may use
tier 2 under the existing policy; unknown (`und`) text stays native without a provider request.
Never silently treat Slovak as Czech. `en` is passed through, never translated.

**Daily cap for tier 2:** `settings['translate.tier2_daily_cap']`, default **300** calls. The eval CLI
ignores this cap (spec 10 §3).

**Logging and budget:** `packages/translate` only makes HTTP calls. The `article.translate` handler
records every call through `EngineRouter.recordExternalCall` (spec 04 §1):
- tier 1 → `engine_calls` with `engine = 'libretranslate'`, `kind = 'translate'`, cost 0
- tier 2 → `engine = 'llm'`, `kind = 'translate'`, the cost from token usage

Before any article translation attempt, recheck the inference witness/revision (including CPU
tier 1). Before each tier-2 HTTP attempt resolve the active Ollama credential through spec 04 §1.2
and call `router.reserveExternalCall({..., authorization})` to atomically reserve the estimated
spend and a tier-2 daily-cap slot through the shared budget store (spec 04 §6). `canSpend` alone is advisory, not a
concurrency-safe admission check. Reconcile token usage after the response; a timeout still consumes
the attempt slot and retains conservative cost accounting. Calls, retries and failed responses are
all logged once with `logicalRequestId`, `attempt`, and `billing: known|uncertain`, without article
text or API keys; reconcile through `recordExternalCall(call, reservationId)`. Tier 1 has bounded CPU concurrency shared by API
card translations and workers; a busy container must not exhaust all API connections.

---

## 3. Handler `article.translate {articleId, forceTier2?}`

1. Load `title`, `excerpt` (first 600 chars) and `body_lead` (≤ 1,500) plus `lang` and
   `content_revision` plus the server-produced inference authorization. Off/untrained demand is a
   no-op, not a failed translation. Manual requests use exactly their frozen source fields/hash.
   Translation rows are valid only for that revision. Replayed jobs reuse a
   completed current-revision row; an article edit makes earlier rows ineligible. A late result may
   only enter a shared article cache if the article still has the revision read at dispatch (spec 02).
   A still-authorized manual request may instead complete its own frozen result when the live article
   advances; never install that historical translation into a newer article revision.
2. **Tier 1** (unless `forceTier2`): `POST {LIBRETRANSLATE_URL}/translate` with
   `{ q: [title, excerpt, body_lead].filter(Boolean), source: lang, target: 'en', format: 'text' }`.
   - Keep an explicit ordered `{field, text}` list: filtering absent fields must not shift an excerpt
     into the title column. Require the returned array length to match and all entries to be strings.
   - Timeout 30 s, at most 2 HTTP attempts. Retry transient network/429/5xx failures only, with bounded
     backoff; validation/unsupported-language failures are terminal for this revision.
   - Store an `article_translations` row with `engine='libretranslate'` and `quality` from §4.
3. **Tier 2** is wanted if tier-1 quality is `fail`, or `forceTier2` is set, or the feed has
   `fetch_options.translate_strong`. It is **allowed** if the credential resolver supplies an enabled
   active Ollama key (encrypted DB, or permitted bootstrap env source), demand remains eligible, the daily cap is not
   reached, and `router.reserveExternalCall(...)` succeeds for this HTTP attempt. The reservation
   atomically checks both cap and spend; an earlier advisory `canSpend` result is insufficient.

   If it is wanted but not allowed, store an `ollama` row for the current revision with `quality = 'fail'` and
   `quality_detail = {skipped: 'no_key'|'cap'|'budget', credentialVersion?: string}`. The attempt is on record, so the ranker's
   escalation (spec 06 §7) never re-enqueues it.

   Call Ollama `/api/chat` with:

   ```json
   { "model": "glm-5.3-flash", "stream": false,
     "options": { "temperature": 0, "num_predict": 2048 },
     "messages": [
       { "role": "system", "content": "You are a professional translator. Translate every field of the user's JSON from <Language> to English. Keep names, numbers, product names and quotes' meaning. Do not add or remove information. Output only JSON with the same keys." },
       { "role": "user", "content": "{\"title\":\"…\",\"excerpt\":\"…\",\"body_lead\":\"…\"}" } ] }
   ```

   Ollama Cloud structured `format` is not assumed supported (spec 04); request JSON by prompt and
   validate it locally. Use the configured fast/strong model rather than hard-coding the example's
   model name. JSON field
   values are untrusted text, never instructions: no tools, URL following or executable output.
   Require exactly the three string keys, bound each output length and the HTTP response bytes, and
   reject malformed or extra fields. One bounded repair retry is allowed under a new budget
   reservation; no nested package/job retry loops. A terminal provider failure stores `fail` and
   falls back to native text rather than blocking ingestion. Store an `engine='ollama'` row with its
   own quality, `article_revision`, `source_sha256`, model and translation-policy version (the latter
   in `quality_detail`). A skipped row may be replaced
   only by an explicit administrative reprocess, not by ordinary queue redelivery.
4. **Best translation for state builders:** among current-revision rows, the highest quality
   (`ok` > `weak` > `fail`).
   Ties prefer `ollama`. If only `fail` rows exist, the state is built from native text.
5. For the initial pipeline, atomically set `pipeline_state = 'translated'` and persist the enrich
   job intent in the outbox. A late duplicate never moves an already enriched/matched article
   backwards. Re-translation uses the separate rules below.

**Re-translation of weak items** (triggered by `user.rank`, spec 06 §7 step 6):
- `article.translate {forceTier2: true}` runs step 3 only after revalidating live manual/active demand.
  A Maybe lane caused by untrained/off state never authorizes translation.
- Recompute the selected best row under step 4 (including its Ollama tie-break). If the effective
  model input changes, atomically install the selected translation through
  `resetArticleAnswers` (spec 05 §5.6), which advances the revision **and preserves that translation
  under the new revision**, invalidates answers and persists the enrich intent. Identical effective
  text is a no-op. Comparing quality grades alone would miss a different tie-winning translation.
- Otherwise nothing else happens.
- This runs once per article content revision: the current-revision `ollama` row, even a skipped
  one, prevents repeats. Budget reset alone does not retry skipped items; an explicit reprocess or
  new content revision may. This bounds costs even when many users place the same article in Maybe.

---

## 4. Quality heuristics (`assessTranslation(source, output, sourceLang)`, pure)

First validate the response shape. Any nonempty source field translated to an empty/non-string field
is `fail`, even when the source is shorter than 20 characters. An absent source field remains empty
and is excluded from quality scoring. An entirely empty input is skipped, not reported as `ok`.

Then, per field with source length ≥ 20 chars:

| Check | Result |
|---|---|
| empty output | `fail` |
| length ratio `len(out)/len(src)` outside [0.4, 2.5] | `fail` |
| the same 3-gram repeated more than 4 times (a model loop) | `fail` |
| share of output tokens (len ≥ 4, normalized) that also appear in the source > 0.5 | `weak` (untranslated) |
| `detectLanguage(out)` returns a known non-English language with confidence ≥ 0.1 | `weak` |

The article's quality is the worst field result, and the per-field details go in `quality_detail`.
Names and brands legitimately survive translation, so the 0.5 share is deliberately lenient.
Short text and detector `und` are inconclusive; neither alone proves success or failure. These
checks catch obvious breakage, not semantic accuracy: G1 must include translation error review.

---

## 5. Card text translation (`CARD_TEXT_MODE = 'english'`)

Explicit card create/edit is a separate user-authored operation; its free tier-1 text translation
does not authorize analysis of any feed article. Do not translate every card solely because an
untrained feed was added. On card create or edit, the **API service** (it has `LIBRETRANSLATE_URL`, spec 01 §3) does this before
inserting a new card row:
1. `lang = detectLanguage(interest + ' ' + (not_for ?? ''), { hint: user.locale, minLength: 10 })`
   (spec 03 §8.3), stored in `interest_cards.lang`.
2. If `lang` is a known non-English language supported by the installed tier-1 model, translate
   `interest` and `not_for` into `interest_en` and `not_for_en`. `en`, `und` and unsupported card
   languages keep original text without a provider request.
   A locale hint is only a fallback for ambiguous short text, not proof of language. Preserve the
   original and never translate English brand-only phrases just because the UI locale is Slovak.
3. On `fail` or `weak`, keep the original text only and return a non-blocking translation status.
   There is no tier 2 for cards; users can rephrase. Validate both fields and map omitted `not_for`
   explicitly, just as for articles. Translation must not change the card's original text/hash.

**Switching the mode on later:**
- `PATCH /admin/settings {card_text_mode: 'english'}` enqueues the one-off `house.translate-cards`.
- It fills a missing `interest_en`/`not_for_en` pair only for non-retired cards with current authorized
  manual/active inference demand, after rechecking that demand at dispatch and commit. The source
  language must be known, non-English and supported by the installed tier-1 model; skip `en`, `und`
  and unsupported languages without a provider request. Off/untrained holdings alone are ineligible.
  These derived fields are the only mutable part of the card body (spec 05 §5.1); the worker may
  also replace them through the explicit audited retranslation flow below, under the same demand
  and supported-language checks. Original card text/hash remain immutable.
- Publish translated fields atomically as one validated pair; on partial/weak/failed translation
  use the original pair. Card answers bind to `card_input_sha256`, the exact rendered question
  (specs 02/05), which includes the selected effective text. Earlier answers remain historical but
  are no longer current once that digest changes. Enqueue bounded rematching/backfill, advance
  affected users' rank revisions and invalidate incompatible personal models. Rematch only pairs
  with existing authorized manual/active demand; a mode change never enables an off feed. Switching
  back to `as_written` follows the same digest/invalidation policy; never mix answers made with different
  effective text under an unchanged cache identity.
- Changing translator/model versions does not overwrite already valid translated text silently.
  An explicit, audited retranslation may atomically replace the valid derived pair, computes a new
  digest and follows the same rematch policy. API card
  translation calls use the narrow external-call accounting functions (specs 02/04), including
  failures; they cannot obtain worker credentials. Card creation remains possible during a tier-1
  outage with original text and a non-blocking translation status.

Library cards already ship with English text (`lang = 'en'`).

---

## 6. Tests

- A fake LibreTranslate server serves ok, weak (echo), fail (empty) and timeout cases.
- The Ollama fixture serves a happy path and malformed JSON.
- `assessTranslation` truth table.
- The tier escalation and cap logic.
- The state builder picks the best row.
- Missing/short fields retain their field mapping; wrong array length/extra keys and hostile JSON
  never become article text. Known names and unknown language are covered.
- Duplicate and stale-revision jobs do not repeat calls or regress pipeline state; tier-2 cap and
  spend reservations remain correct under concurrent workers. Budget-skipped attempts do not loop.
- Off/untrained articles make zero tier-1/tier-2 calls; manual selection covers only its frozen
  snapshot; stale inference-version/credential-version completions cannot revive cancelled demand.
- Active encrypted Ollama credential is used without env API key; tombstone/rotation and no-key
  failures preserve reading and never leak keys in errors, outbox or accounting.
- A card mode/text-digest change rematches only authorized eligible pairs and invalidates model compatibility;
  unchanged translated text does not trigger unnecessary work. API card-call accounting works as
  `feedit_app` without worker credentials.

Capability references (checked 2026-09-25):
[LibreTranslate language pairs](https://docs.libretranslate.com/guides/supported_languages/) and
[`GET /languages`](https://docs.libretranslate.com/api/operations/languages/).
