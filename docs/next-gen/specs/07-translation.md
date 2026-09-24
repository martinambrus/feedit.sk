# Spec 07: Translation (`packages/translate`)

Status: **binding**. **Intent:** if Jev classifies Slovak/Czech text worse than English (measured at
gate G1), give it an English *gist* of each non-English article. Do it once per article, for free on
the box's CPU, and fall back to Ollama Cloud GLM only when the free translation is broken or weak.
Claude is not used, for cost reasons (a locked decision).

---

## 1. When translation runs

- `settings['language_modes']` (default from `LANGUAGE_MODES` env): a map `lang → 'native' | 'translate'`.
  The default is `native` for everything until G1 decides.
- `article.translate` runs only when `modes[article.lang] === 'translate'`. Otherwise the pipeline goes
  straight from extract to enrich.
- `CARD_TEXT_MODE` (`settings['card_text_mode']`, `'as_written' | 'english'`) decides whether card
  texts are translated to English at creation (spec 05 §5.1). It is set at G1 as well.

---

## 2. Tiers

| Tier | Engine | Used for | Cost |
|---|---|---|---|
| 1 | **LibreTranslate** (self-hosted container with Argos models `sk→en`, `cs→en`, plus `en` for card text) | every article in a `translate` language, and card texts in `english` mode | €0 (CPU) |
| 2 | **Ollama Cloud**, `OLLAMA_MODEL_FAST` (`glm-5.3-flash`); `OLLAMA_MODEL_STRONG` (`glm-5.3`) only for the admin "hard feed" flag | tier-1 `fail`, tier-1 `weak` on a `maybe`-lane article, and feeds flagged `translate_strong` | ≈ $0.00014 per article (flash) |

The container runs with `LT_LOAD_ONLY=en,sk,cs` and `LT_DISABLE_WEB_UI=true`. Its API port is only
reachable on the compose network.

**Daily cap for tier 2:** `settings['translate.tier2_daily_cap']`, default **300** calls. Tier-2 cost is
counted in the spend guard (spec 04 §6) and logged in `engine_calls` with `engine = 'llm'`,
`kind = 'translate'`.

---

## 3. Handler `article.translate {articleId, forceTier2?}`

1. Load `title`, `excerpt` (first 600 chars) and `body_lead` (≤ 1,500) plus `lang`.
2. **Tier 1** (unless `forceTier2`): `POST {LIBRETRANSLATE_URL}/translate` with
   `{ q: [title, excerpt, body_lead].filter(Boolean), source: lang, target: 'en', format: 'text' }`.
   - Timeout 30 s, 2 attempts.
   - Store an `article_translations` row with `engine='libretranslate'` and `quality` from §4.
3. **Tier 2** if:
   - tier-1 quality is `fail`, or `forceTier2` is set, or the feed has `fetch_options.translate_strong`
   - and `OLLAMA_API_KEY` is set, the daily cap is not reached, and the budget allows

   Call Ollama `/api/chat` with:

   ```json
   { "model": "glm-5.3-flash", "stream": false, "options": { "temperature": 0 },
     "format": { "type": "object", "properties": { "title": {"type":"string"}, "excerpt": {"type":"string"}, "body_lead": {"type":"string"} },
                 "required": ["title","excerpt","body_lead"] },
     "messages": [
       { "role": "system", "content": "You are a professional translator. Translate every field of the user's JSON from <Language> to English. Keep names, numbers, product names and quotes' meaning. Do not add or remove information. Output only JSON with the same keys." },
       { "role": "user", "content": "{\"title\":\"…\",\"excerpt\":\"…\",\"body_lead\":\"…\"}" } ] }
   ```

   Store an `engine='ollama'` row with its own quality.
4. **Best translation for state builders:** the highest quality among the rows (`ok` > `weak` > `fail`).
   Ties prefer `ollama`. If only `fail` rows exist, the state is built from native text.
5. Set `pipeline_state = 'translated'` and hand over to enrich.

**Re-translation of weak items** (the ranker's side-effect, handled in `user.rank`): when an article
lands in `maybe` for any user, has only a tier-1 translation of quality `weak`, and has not been
re-translated, enqueue `article.translate {forceTier2: true}`. This is followed by re-enrich and
re-match (spec 05), once per article.

---

## 4. Quality heuristics (`assessTranslation(source, output, sourceLang)`, pure)

Per field with source length ≥ 20 chars:

| Check | Result |
|---|---|
| empty output | `fail` |
| length ratio `len(out)/len(src)` outside [0.4, 2.5] | `fail` |
| the same 3-gram repeated more than 4 times (a model loop) | `fail` |
| share of output tokens (len ≥ 4, normalized) that also appear in the source > 0.5 | `weak` (untranslated) |
| `detectLanguage(out)` ≠ `en` with confidence ≥ 0.1 | `weak` |

The article's quality is the worst field result, and the per-field details go in `quality_detail`.
Names and brands legitimately survive translation, so the 0.5 share is deliberately lenient.

---

## 5. Card text translation (`CARD_TEXT_MODE = 'english'`)

On card create or edit:
- if `detectLanguage(interest)` ∉ {`en`, `und`}, translate `interest` and `not_for` with tier 1 into
  `interest_en` and `not_for_en`
- on `fail`, keep the original text only (no tier 2 for cards; users can rephrase)

Library cards already ship with English text.

---

## 6. Tests

- A fake LibreTranslate server serves ok, weak (echo), fail (empty) and timeout cases.
- The Ollama fixture serves a happy path and malformed JSON.
- `assessTranslation` truth table.
- The tier escalation and cap logic.
- The state builder picks the best row.
