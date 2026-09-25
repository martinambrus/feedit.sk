# Spec 05: Classification: question sets, interest cards, matching, clustering

Status: **binding**. **Intent:** understand each article with authorized inference demand once for
every eligible reader (Call A). Then ask one absolute yes/no question per *distinct authorized*
interest card for that article (Call B), packing as many
questions as possible into each authorized batch, because Jev bills per input token and reads the
state once per call. Keep normalized current answers and versioned evaluation snapshots so ranking
can change without new calls; production caches are not an unlimited answer-history archive.

Code: `packages/questions` (pure: question sets, builders, taxonomy, set hashing, packing, library seed data),
and `apps/worker/src/handlers/article-{enrich,match,cluster}.ts`, `analysis-process.ts`,
`card-backfill.ts`, `user-suggest.ts`.

---

## 1. Calls at a glance

| Call | When | State | Questions | Stored in |
|---|---|---|---|---|
| **A: enrich** | once per demanded non-stale article revision; off feeds alone never trigger it | the article (§3.1) | fixed set `enrich-v1` (§3.3) | `article_facets` |
| **B: match** | once per demanded article per batch of authorized pending cards | the article (smaller) | one Noul per card/label (§5.2), plus L2 topic Choices (§4) | `card_answers`, `article_topics_l2` |
| **cluster** | after demanded enrich, only if authorized candidates exist | new article + ≤ 5 candidates | fixed set `cluster-v1` (§6) | `articles.story_cluster_id` |
| **suggest** | after authorized learning, at most daily per user; no automatic calls for off-only users | ≤ 5 liked articles | one Choice over library cards (§7) | `card_suggestions` |

### 1.1 Inference admission (binding; user decision Q1)

RSS fetching, parsing and safe extraction continue for ordinary reading. Provider inference is
separately authorized **per user subscription**, never by the global feed alone:

| `subscriptions.inference_mode` | Authorized articles for that user/feed |
|---|---|
| `off` (default) | None. Do not translate, enrich, match, cluster, suggest or classify automatically on this user's behalf |
| `training` | Only article ids/revisions explicitly selected through `POST /subscriptions/:feedId/analyze`; process slowly on the bulk queue |
| `active` | New feed items whose `feed_items.first_seen_at ≥ inference_activated_at`, plus explicitly selected historical articles |

`active` is enabled explicitly per feed (PLAN §17 Q11 resolved); a feedback count/model activation
does **not** graduate a feed automatically. This is the approved v1 behavior. Activation never sweeps
an existing backlog. Historical analysis/backfill needs an explicit bounded user request, represented
by exact `analysis_requests` rows; adding a card, changing scope or subscribing cannot create that
permission. Mode changes increment `inference_version` and rerank the affected user's items.

**Single admission predicate:** an article/card pair has demand iff at least one non-deleted current
holder has a carrying subscription that is active for that article's arrival, or a current selected
`analysis_requests` authorization in training/active mode, and the held card's scope includes that
same feed. Check the requested frozen article revision and subscription inference version; using a
request result to rank a current article additionally requires the same current article revision. Off cancels that user's
pending/running selections and disables classification use; returning to training requires selection
again. A mode/version change invalidates leases/commits for obsolete user demand, not another user's
still-authorized shared work. An explicit current completed selection permits use of its answers;
new content requires current authorization rather than silently analyzing a later revision.

Compute the union of authorized **article/card** pairs, not user×article calls. Enrichment, L2 and
translation are shared prerequisites for at least one admitted pair; cache-compatible existing
answers cost no new call. An active subscriber may already have paid for the same article/card;
a later authorized trainee reuses it, without revealing anyone's interests or ratings. Off users do
not become automatically classified just because a shared cache happens to exist.

Every producer **and** worker execution rechecks admission before reserving spend or calling a
provider, including translation, L2-only, clustering, set/model upgrades, recovery and suggestions.
No demand → no inference call, no provider retry, no new backfill. Preserve valid shared caches for
other authorized users. For a request already on the wire, record its actual cost, but do not apply
its result to a user who revoked demand. Global manual-reader rules still work without inference.

**Selected training before slow inference:** capture immutable `analysis_requests.input_snapshot`
and `input_sha` before applying an attached first rating. Include article text/revision, effective
card/question/state/translation manifests, source timestamps and the user's feature context; never
include the rating in a model question. A feedback event may reference that request. Deferred work
may derive its feature vector from those frozen inputs after the rating arrives (spec 06 §8.2), so
the first rating is useful without future-card/content leakage. Never replace the snapshot with
current text/cards during retry; cancellation/reselection creates a new request. Features from an
identical current cache are reusable with the same exact provenance. Training requests do not turn
on continuous inference for their feed.

`analysis.process {analysisRequestId}` owns selected-request work (spec 03). On completion write normalized
facets/card answers/features to immutable `result_snapshot` with `result_sha`, linked to `input_sha`.
A later live article edit does not invalidate the authorized historical training result: it stays
request-specific. Populate shared current caches only if the live revision and all input manifests
still match. Mode-version mismatch, cancellation or subscription deletion prevents publishing the
request result. Automatic article workers below must never substitute a newer shared input for the
manual frozen path. This distinction also applies to translation/clustering prerequisites. The result
includes all normalized model inputs/features and their provenance required by spec 06; never write
historical answers into newer `article_facets`/`card_answers`. Completion records debounced `user.learn`
when a surviving rating references this request, plus a rank intent for still-current eligible content,
in the same outbox transaction. This makes an inaugural rating learnable when slow processing finishes.

---

## 2. Question sets: versioning and hashing

- Every static question set is a TypeScript constant in `packages/questions/src/sets/<name>.ts`,
  exporting `{ kind, version, questions }`.
- `canonicalJson(x)`: JSON with object keys sorted recursively, no whitespace, arrays in order.
  `sha256(canonicalJson({kind, version, questions}))` is the set's `sha256`.
- **Dynamic sets** (`match-v1`, `cluster-v1`, `suggest-v1`) are hashed over their **template**: the
  builder applied to fixed placeholder inputs. For example,
  `definition = { kind: 'match', version: 'match-v1', card: cardQuestion(PLACEHOLDER_CARD, 'as_written'), label: labelQuestion(PLACEHOLDER_LABEL, 'as_written'), l2: l2Question(PLACEHOLDER_L1) }`
  with `PLACEHOLDER_CARD = { interest: '{{interest}}', not_for: '{{not_for}}', examples_yes: ['{{yes}}'], examples_no: ['{{no}}'] }`.
  Cluster options are `c1…cN` plus `none` (N ≤ 5), and the template uses N = 5.
  - Any change to a builder changes the template and therefore the sha. A test asserts that the stored
    sha equals the computed one.
  - A template hash alone is insufficient for cache validity. A current answer must match article
    `content_revision`, the exact state's `state_sha256`, active `question_set_sha`, active engine/model
    policy, and (for cards) `card_input_sha256 = sha256(canonicalJson(actualBuiltQuestion))`.
    This includes card text mode and translations; changing a translation must not reuse an answer
    generated from the old wording. Card ids are immutable source content (§5.1).
  - A placeholder snapshot is a regression test, not proof that all builder paths are unchanged.
    Version builders explicitly and test each optional-field/language/example path.
- **Seeding:** `pnpm db:seed` (root script → `pnpm --filter @feedit/worker seed`, i.e.
  `apps/worker/src/seed.ts`, because only apps may import every package) upserts every set into
  `question_sets`. The worker also checks this at startup.
  - It **fails** if a `version` already exists with a different `sha256`. Changing wording requires
    bumping the version (`enrich-v2`).
  - It sets `settings['question_sets.active'][kind]` **only when that kind is absent**.
- **Switching sets:** `settings['question_sets.active']` names the active set per kind. Switching
  `enrich` to a new set (`PATCH /admin/settings`) enqueues `house.reenrich {since: now − 7 days}`,
  which re-enqueues `article.enrich` only for those articles still admitted by §1.1, in batches within
  the budget. A match-set,
  card-text-mode or model-pin change similarly queues bounded rematching and full reranks. Old
  incompatible answers cannot satisfy current cache lookups while replacement is pending. Store
  exact set/model/input provenance with golden runs; changing a set does not mutate frozen runs.

---

## 3. Call A: enrichment

### 3.1 State builder (`buildArticleState(article, variant, opts)`)

```ts
type Variant = 'native' | 'translated';
// native
{ article: {
    title: string,
    feed: { title: string | null, site: string | null },     // site = registrable domain
    author: string | null,
    categories: string[],                                      // ≤ 8
    excerpt: string | null,                                    // ≤ 600 chars, cut at a word boundary
    body_lead: string | null,                                  // ≤ 1,500 chars (Call A) / ≤ 1,000 (Call B)
    length: 'short' | 'medium' | 'long' | 'very_long' | 'unknown',   // word_count <150 / <600 / <1500 / ≥1500 / null
    language: string                                           // English name, e.g. "Slovak"
} }
// translated: the same shape, with title/excerpt/body_lead from article_translations (best quality row),
// plus  original_title: string  and  language: "Slovak (machine-translated to English)"
```

- The variant is chosen per article from `settings['language_modes'][article.lang]` (env default
  `LANGUAGE_MODES`; spec 07 §1). An unknown
  language uses `'native'`.
- If the mode is `translate` but no usable translation exists (both tiers failed), the builder falls
  back to `'native'`.
- The variant used is stored (`state_variant`).
- Do not add numeric timestamps, prices or age calculations as extra model reasoning tasks. Preserve
  dates/numbers already present in article text (battery capacity or product version can determine
  relevance); age and price comparison logic happens in code (spec 06).
- Use shared publisher metadata, never a subscriber's feed-title override, folder, identity or rating.
  For a multi-feed article pick its canonical feed deterministically (oldest `feed_items.first_seen_at`,
  then feed id); hash that concrete state. Bound title, author, category and feed-field lengths too.
- Truncated body excerpts cannot prove whole-article depth or absence of a topic. Golden evaluation
  must include long articles whose relevant content appears after the lead, paywall teasers and
  missing-body cases; mark source completeness for explanations rather than promising full reading.

### 3.2 Topic taxonomy (`packages/questions/src/taxonomy.ts`, seeded into `topics`)

Level-1 ids are plain. Level-2 ids are `<l1>.<l2>`. The list below is the v1 taxonomy. Every topic has
`name_en`, `name_sk` and a one-line English `description` used in the criteria.

| L1 id | EN / SK | Description | L2 children (id: EN / SK) |
|---|---|---|---|
| `technology` | Technology / Technológie | Software, hardware, the internet, AI and the tech industry | `software_dev`: Software development / Vývoj softvéru · `ai_ml`: AI and machine learning / Umelá inteligencia · `hardware_gadgets`: Hardware and gadgets / Hardvér a zariadenia · `cybersecurity`: Cybersecurity / Kybernetická bezpečnosť · `internet_platforms`: Internet platforms and social media / Internetové platformy a sociálne siete · `telecom`: Telecom and connectivity / Telekomunikácie · `open_source`: Open source / Open source · `tech_industry`: Tech companies and industry / Technologické firmy |
| `science` | Science / Veda | Scientific discoveries and research | `space`: Space and astronomy / Vesmír a astronómia · `physics_chemistry`: Physics and chemistry / Fyzika a chémia · `life_sciences`: Biology and life sciences / Biológia · `earth_science`: Earth and climate science / Vedy o Zemi a klíme · `research_academia`: Research and academia / Výskum a akadémia |
| `health` | Health / Zdravie | Medicine, fitness, nutrition and wellbeing | `medicine`: Medicine and healthcare / Medicína a zdravotníctvo · `fitness_exercise`: Fitness and exercise / Fitness a pohyb · `nutrition`: Nutrition and diet / Výživa · `mental_health`: Mental health / Duševné zdravie · `public_health`: Public health / Verejné zdravie |
| `business` | Business / Biznis | Companies, markets, money and work | `companies`: Companies and industry / Firmy a priemysel · `startups`: Startups and venture capital / Startupy a rizikový kapitál · `markets_investing`: Markets and investing / Trhy a investovanie · `personal_finance`: Personal finance / Osobné financie · `real_estate`: Real estate / Nehnuteľnosti · `careers_work`: Careers and work / Kariéra a práca · `crypto`: Crypto and blockchain / Kryptomeny a blockchain |
| `economy` | Economy / Ekonomika | The economy as a whole and economic policy | `macro`: Macroeconomy / Makroekonomika · `monetary_policy`: Central banks and inflation / Centrálne banky a inflácia · `labor_market`: Labour market / Trh práce · `trade`: Trade and tariffs / Obchod a clá · `public_finance`: Taxes and public finance / Dane a verejné financie |
| `politics` | Politics / Politika | Government, elections, law and diplomacy | `domestic`: Domestic politics / Domáca politika · `elections`: Elections / Voľby · `international_relations`: International relations / Medzinárodné vzťahy · `law_justice`: Law and justice / Právo a justícia · `policy_regulation`: Government policy and regulation / Vládna politika a regulácia |
| `world` | World news / Svet | Events in other regions of the world | `europe`: Europe / Európa · `americas`: The Americas / Amerika · `asia_pacific`: Asia and the Pacific / Ázia a Tichomorie · `middle_east_africa`: Middle East and Africa / Blízky východ a Afrika · `conflicts`: War and armed conflicts / Vojny a konflikty |
| `local` | Slovakia and Czechia / Slovensko a Česko | News specifically about Slovakia or Czechia | `slovakia`: Slovakia / Slovensko · `czechia`: Czechia / Česko · `regional_city`: Regional and city news / Regionálne a mestské správy |
| `environment` | Environment / Životné prostredie | Climate, energy, nature and pollution | `climate_policy`: Climate policy / Klimatická politika · `energy`: Energy and the energy transition / Energetika · `nature`: Nature and wildlife / Príroda a zvieratá · `pollution_waste`: Pollution and waste / Znečistenie a odpad |
| `transport` | Cars and transport / Autá a doprava | Vehicles, mobility and travel infrastructure | `cars`: Cars / Autá · `ev`: Electric vehicles / Elektromobily · `public_transport_rail`: Public transport and rail / Verejná doprava a železnice · `aviation`: Aviation / Letectvo · `cycling_micromobility`: Cycling and micromobility / Cyklistika a mikromobilita |
| `culture` | Culture and arts / Kultúra a umenie | Film, music, books and the arts | `film_tv`: Film and TV / Film a televízia · `music`: Music / Hudba · `books`: Books and literature / Knihy a literatúra · `visual_arts_design`: Visual arts and design / Výtvarné umenie a dizajn · `performing_arts`: Theatre and performing arts / Divadlo a scénické umenie |
| `entertainment` | Entertainment / Zábava | Celebrities, streaming, humour and events | `celebrities`: Celebrities / Celebrity · `streaming_video`: Streaming and online video / Streaming a online video · `humor_viral`: Humour and viral content / Humor a virálny obsah · `events_festivals`: Events and festivals / Podujatia a festivaly |
| `gaming` | Gaming / Hry | Video, board and competitive games | `video_games`: Video games / Videohry · `tabletop`: Board and tabletop games / Stolové hry · `esports`: Esports / E-športy · `game_industry`: Game industry / Herný priemysel |
| `sports` | Sports / Šport | Competitive sport | `football`: Football / Futbal · `ice_hockey`: Ice hockey / Hokej · `tennis`: Tennis / Tenis · `motorsport`: Motorsport / Motoršport · `cycling_sport`: Cycling (sport) / Cyklistika (šport) · `winter_sports`: Winter sports / Zimné športy · `other_sports`: Other sports / Ostatné športy |
| `lifestyle` | Lifestyle / Životný štýl | Food, travel, home, fashion and family | `food_cooking`: Food and cooking / Jedlo a varenie · `travel`: Travel / Cestovanie · `fashion_beauty`: Fashion and beauty / Móda a krása · `home_garden`: Home and garden / Domov a záhrada · `family_parenting`: Family and parenting / Rodina a výchova · `relationships`: Relationships / Vzťahy |
| `education` | Education / Vzdelávanie | Schools, universities and learning | `schools`: Schools / Školy · `higher_education`: Universities / Vysoké školy · `learning_skills`: Learning and skills / Učenie a zručnosti |
| `society` | Society / Spoločnosť | Social issues, religion, crime, history and media | `social_issues`: Social issues / Spoločenské témy · `religion`: Religion / Náboženstvo · `crime_safety`: Crime and public safety / Kriminalita a bezpečnosť · `history`: History / História · `media_journalism`: Media and journalism / Médiá a žurnalistika |
| `shopping` | Shopping and deals / Nákupy a zľavy | Buying things: deals, classifieds and buying advice | `deals`: Deals and discounts / Zľavy a akcie · `classifieds`: Classifieds and second-hand / Inzeráty a bazár · `buying_guides`: Product reviews and buying guides / Recenzie a nákupné rady |
| `diy` | DIY and making / Urob si sám | Building, repairing and making things | `electronics_diy`: Electronics and maker projects / Elektronika a maker projekty · `printing_3d`: 3D printing / 3D tlač · `crafts_woodworking`: Crafts and woodworking / Remeslá a drevo · `home_improvement`: Home improvement / Rekonštrukcie a opravy |
| `other` | Other / Iné | None of the above | — |

Changing the taxonomy means a new `enrich` set version. Old facets stay readable, because features are
keyed by id.

### 3.3 Question set `enrich-v1`

```ts
import { choice, noul, score } from '../builders';   // tiny local helpers producing Question objects

export const ENRICH_V1 = {
  kind: 'enrich', version: 'enrich-v1',
  questions: {
    content_type: choice({ question: 'What kind of piece is `article`?',
                           focus: 'Judge the form of the piece, not its topic.' }, {
      news_report:   { what: 'Reports a specific recent event, announcement, release, ruling or result', examples: ['Company X recalls 40,000 cars over brake fault'] },
      analysis:      { what: 'Explains causes, context or implications of events, based on reporting or data' },
      opinion:       { what: "Argues the author's personal view: column, editorial, commentary" },
      tutorial:      { what: 'Teaches how to do something step by step' },
      review:        { what: 'Evaluates one specific product, book, film, game, place or service' },
      listicle:      { what: 'Organized as a numbered or bulleted list of items ("10 best …")' },
      press_release: { what: 'Written by the organization it is about, announcing something in its own voice' },
      deal_or_ad:    { what: 'Sells something: discount, offer, classified ad, sponsored product placement' },
      job_or_event:  { what: 'A job posting, event listing or call for participants' },
      media:         { what: 'Mainly a podcast episode, video or photo gallery with little text' },
      interview:     { what: 'Mostly questions and answers with one person' },
      other: null,
    }),
    topic_l1: choice({ question: 'Which top-level topic is `article` primarily about?',
                       focus: 'Pick the main subject, not every topic mentioned.' },
                     taxonomyL1Criteria()),          // { <l1 id>: { what: description, includes: [L2 EN names] }, other: null }
    depth: score('How much substance does `article` offer beyond its headline?', [
      'Headline only, or a one-paragraph rewrite of another source',
      'Short brief: the basic facts, little context',
      'Standard article: facts plus some context or quotes',
      'In-depth: detailed explanation, data, several sources or perspectives',
      'Deep dive or investigation: original research, extensive detail',
    ]),
    clickbait: noul('Does the title of `article` withhold or exaggerate what the article actually delivers?', {
      true:  { what: 'Curiosity gap, sensational framing, or a promise that the excerpt does not meet',
               examples: ["You won't believe what this app does", 'This one trick …'] },
      false: { what: 'The title plainly states what the article is about' },
    }),
    promotional: noul('Is `article` a press release, sponsored post, affiliate roundup or vendor marketing rather than independent content?'),
    time_sensitive: noul('Will `article` lose most of its value within a few days (breaking news, an expiring deal, an upcoming event)?'),
    evergreen: noul('Would `article` still be useful to a reader six months from now?'),
    local_scope: choice('What geographic scope does `article` concern?', {
      global: 'Relevant regardless of country',
      national: 'Mainly about one country',
      regional_or_city: 'Mainly about a region, city or town',
      not_geographic: null,
    }),
    tone: score('What is the emotional tone of `article`?', [
      'Alarming or distressing', 'Negative', 'Neutral', 'Positive', 'Upbeat or celebratory',
    ]),
    paywall_teaser: noul("Does `article`'s text read like a teaser for content behind a paywall or login?"),
  },
} as const;
```

Rules that hold for every question (from TypeSafe's jaggedness notes): a high Noul probability always
means "yes, the named thing". There are no negated instructions, no arithmetic, and no dates.

### 3.4 Features (`flattenFacets(answers, l2Answers)` → `article_facets.features`)

| Feature key | Value |
|---|---|
| `ct.<option>` | Choice probability for each of the 12 `content_type` options |
| `t1.<l1>` | Choice probability for each of the 20 L1 options |
| `t2.<l1>.<l2>` | `P(L1) × P(L2 | L1)` for a current asked branch (§4); absent numeric value = 0 |
| `t2_asked.<l1>` | 1 for a valid current branch answer, otherwise 0 (unasked is not observed negative evidence) |
| `depth`, `depth_conf` | `score / 4`, confidence |
| `clickbait`, `promotional`, `time_sensitive`, `evergreen`, `paywall_teaser` | Noul p |
| `scope.<option>` | `local_scope` probabilities (4) |
| `tone` | `score / 4` |

`features` is recomputed (and the row updated) when L2 answers arrive after Call B. Include the
feature-builder version in the model feature-schema fingerprint; changing L2 semantics invalidates
personal-model compatibility. Do not combine L2 from an old revision/set/state with current facets.

---

## 4. Level-2 topics (asked inside Call B)

For each article, pick the L1 branches with `p ≥ 0.15` among the top 2 (excluding `other`), then add
one Choice per branch:

```ts
questions[`t2_${l1}`] = choice(
  { question: `Which ${L1.name_en} subtopic is \`article\` primarily about?` },
  { ...Object.fromEntries(L1.children.map(c => [c.id.split('.')[1], { what: c.name_en }])), none_of_these: null },
);
```

The answers are stored in `article_topics_l2`. If an article has no cards to match, a match call is
made for missing L2 questions **only if** current authorized active-arrival or exact selected-training
demand remains under §1.1. A subscriber alone, an off feed or an unselected training article is
insufficient; reuse compatible cached L2 answers without a call.
Use deterministic branch ordering (probability descending, then id). Each selected branch is checked
separately for a current answer; one cached L2 row must not suppress the other missing branch. Zero
selected branches is a completed empty result, not a reason for endless retries. L2-only work follows
the same revision and durable-enqueue rules as card work. Cost depends on the actual packed state.

---

## 5. Call B: interest cards and labels

### 5.1 Cards

A card's `body`:

```json
{ "interest": "New battery chemistry for electric vehicles (solid-state, sodium-ion, LFP improvements)",
  "not_for": "Stock-price moves, car launch PR without battery detail",
  "interest_en": null, "not_for_en": null,
  "examples_yes": ["Toyota's solid-state pilot line hits 1,000 cycles"],
  "examples_no":  ["Tesla shares slide 4% after delivery miss"] }
```

**Validation:**
- `title`: 1–60 chars (defaults to the first 60 chars of `interest`)
- `interest`: 3–300 chars
- `not_for`: ≤ 300 chars
- examples: ≤ 5 per side, each ≤ 200 chars
- kind `label` uses the same body, where `interest` is the label definition

**`text_hash`:**

```
norm(s) = NFC, trim, collapse whitespace, lower-case
// cardTextHash(card) in packages/shared, so the packages/db card repository can use it (spec 01 §2)
text_hash = sha256Hex(canonicalJson({ kind, interest: norm(interest), not_for: norm(not_for ?? ''),
                                   title: kind === 'label' ? norm(title) : null,
                                   examples_yes: examples_yes ?? [], examples_no: examples_no ?? [],
                                   owner: visibility === 'private' ? owner_user_id : null }))
// canonicalJson, sha256Hex, normalizeText and cardTextHash live in packages/shared (spec 01 §2)
```

**Immutability** (the rule that keeps answers valid):
- A card row's `kind`, `interest`, `not_for`, examples and `text_hash` **never change** after insert.
  Every change to text or examples creates, or reuses by `text_hash`, **another** card row. So
  `card_answers` for a `card_id` always describe exactly that text.
- The only in-place updates are:
  - `retired_at` (a card reused while retired is un-retired)
  - admin library fields (`title` only for `kind=interest`, `topic_ids`, `i18n`, `slug`, `visibility`
    on promotion from shared to public). A label title is semantic content and is never edited in place
  - derived translations `interest_en`/`not_for_en`: initial fill when null, or an **explicit audited
    retranslation** by the trusted worker (spec 07 §5). Replace the complete validated pair atomically,
    record old/new digests plus translator version, invalidate/rematch affected card answers and
    rerank holders in the same transaction/outbox. Do not change the original text/hash or silently
    overwrite a valid translation during seed/ordinary retries. Effective translated content is
    part of `card_input_sha256`; incompatible personal models remain inactive until retrained
- `interest_cards.title` is only a default name. Each holder's own name lives in
  `user_cards.title_override`.
- For **labels**, the title is part of the label's meaning: `text_hash` includes `title` for
  `kind = 'label'`, so differently named labels are different cards.

**Forks:**
- `parent_card_id` always points to the original `shared`/`library` card: for a fork of a fork,
  `parent = old.parent_card_id ?? old.id`.
- `owner_user_id` is the user, and `visibility = 'private'`.
- The fork's `text_hash` includes the owner.
- Interest-card forks count toward `maxForks`. Label forks do not; they are bounded by `maxLabels`.

**Lifecycle** (`packages/db` card repository). Each function runs in the caller's `TenantTx` and
returns **effects** `{ refreshFeedIds: string[], backfill?: {cardIds, feedIds?}, rankFull: boolean, labelIdChange?: {from, to} }`:
- The repository runs `refresh_feed_cards` itself, inside the transaction.
- The repository/service records required backfill/rank job intents in `job_outbox` in the same
  transaction as the mutation. The dispatcher publishes after commit; retries are idempotent.
  Reconciliation is a repair mechanism, not the only protection against losing a committed edit.
- Revalidate ownership, card kind, subscription scope and quotas inside the locked transaction;
  translations/network calls occur before opening it. Use `INSERT ... ON CONFLICT` for concurrent
  identical hashes, then select and verify the immutable row. Reusing an existing holder row is
  idempotent only when requested strength/scope agree; otherwise return a documented conflict.
- Shared means reusable classification text, **not** public discovery: only public library rows and
  a user's own held/shared or owned/private rows are exposed through the API. Keep owner identities,
  holdings and examples private. Cross-tenant FK/kind rules are enforced in DB writes too. Never
  promote a private fork or move its examples to a public row. Text sharing/reuse
  is authorized, but library publication follows the creator-approval or approved 30-day-inactivity
  procedure (§8.1).
  `creator_user_id` records the immutable original creator when a new shared row is first inserted;
  it is separate from private `owner_user_id`. Reusing a text hash, adopting or renaming a card never
  transfers authorship. Deleted/unknown creators do not grant presumed publication permission.

| Action | Effect |
|---|---|
| **Create** (text only; API `POST /cards`) | Compute `text_hash`. Reuse a `public`/`shared` card with that hash (un-retire it if retired), or insert `origin='user', visibility='shared', creator_user_id=me, title`. Insert `user_cards` (strength, scope; `title_override` = the given title if it differs from the card's). Effects: refresh, admitted-demand backfill only (§1.1), rank full |
| **Adopt** a library card | Insert `user_cards`. Same effects |
| **Make a card from an article** (`POST /cards/from-article`) | Create or reuse the shared text-only card for `{interest, not_for}`, then **fork** it with the article title in `examples_yes`. The user holds the fork |
| **Add or remove an example** (interest card) | Build the new body: the current examples ± this one, newest 5 per side. Create or reuse the private fork with that body (hash includes the owner). Re-point the user's `user_cards` row to it (keep strength, scope, `title_override`). The previous fork, if any, is left for `house.retire-cards` |
| **Edit text** (`PATCH /cards/:id` with `interest`/`not_for`) | As Create for the new text (a user with examples gets a fork of the new text carrying the same examples). Re-point `user_cards`. The old card keeps its answers for other holders |
| **Rename** (`PATCH /cards/:id {title}`) | Set `user_cards.title_override`. No card change, no model calls |
| **Change strength** | Update `user_cards.strength`. Effect: rank full. No model calls |
| **Change scope** | Validate the new feed subscription, update scope, refresh the union of old/new feeds, backfill already-authorized articles in newly included feeds (including feed A → feed B); do not authorize historical/off-feed work, rank full |
| **Delete** | Delete the `user_cards` row. Effects: refresh, rank full. Card rows are never deleted by the API; `house.retire-cards` retires unheld non-library cards (spec 11 §6) |
| **Create a label** (`POST /labels`) | As Create with `kind='label'` (the hash includes the title). Insert `user_labels (card_id, name = title, color)` |
| **Assign or unassign a label on an article** | **Does not touch cards.** It only updates `user_article.label_ids`/`label_suggestions` and records `label`/`unlabel`; labels are neutral organization and never personal-interest training evidence (spec 08 §5.3) |
| **Add or remove a label example** (`POST /labels/:id/examples`) | Fork the label (as for interest cards). Re-point `user_labels`. In the same transaction, `UPDATE user_article SET label_ids = array_replace(label_ids, old, new), label_suggestions = array_replace(label_suggestions, old, new) WHERE user_id = me`. Effects: refresh, backfill, `labelIdChange` |
| **Rename or redefine a label** (`PATCH /labels/:id`) | A new label card by hash, re-pointed with the same `array_replace` |

Explicit card authoring may still use the free tier-1 text translator; that user-requested card
operation does not authorize article inference for any off/training feed.

**`CARD_TEXT_MODE = 'english'`** (a setting, decided at gate G1). The **API service** handles
translation, before the repository inserts a new card:
- detect the card text's language (spec 07 §5)
- if it is not English, call the tier-1 translator
- store `interest_en`/`not_for_en` and `lang` in the new row

The builder uses a complete valid translated interest/not-for pair when available, otherwise the
original pair; never mix a translated interest with a failed untranslated exclusion. Examples and
semantic label titles remain as written in v1 and are explicitly tested at G1. The fingerprint
captures those choices. Users always see what they wrote. Existing cards are
translated by the one-off `house.translate-cards` job when the mode is switched on (spec 07 §5).

### 5.2 Question builders

```ts
export function cardQuestion(card: CardBody, mode: CardTextMode): NoulQuestion {
  const translated = mode === 'english' && !!card.interest_en &&
                     (!card.not_for || !!card.not_for_en);
  const interest = translated ? card.interest_en! : card.interest;
  const notFor = translated ? card.not_for_en : card.not_for;
  return noul(
    { question: 'Would a reader with this interest want to read `article`?',
      interest, ...(notFor ? { not_for: notFor } : {}),
      focus: "Judge the article's main subject, not passing mentions." },
    { true:  { what: "The article's main subject falls within `interest`", ...(card.examples_yes?.length ? { examples: card.examples_yes } : {}) },
      false: { what: 'Only mentions it in passing, or falls under `not_for`', ...(card.examples_no?.length ? { examples: card.examples_no } : {}) } },
  );
}

export function labelQuestion(card: CardRow, mode: CardTextMode): NoulQuestion {
  // same shape with question: 'Does `article` fit this label?', label: card.title (the shared card's
  // title, which is part of the label's text_hash), definition: interest, not_for, examples as for cards.
  // user_labels.name is display-only and never sent to the model.
}
```

- **Anti-interest cards** (`strength = 'never'`) use `cardQuestion` unchanged. Their meaning is
  inverted only in the ranker.
- **Keys:** `c<cardId>` for cards and labels, `t2_<l1>` for L2 topics.

**Packing** (`packRequests(stateTokens, questions)`, pure):
- Estimate tokens per question (spec 04 §6.1).
- Partition shared/public questions from private questions, with one private owner per batch; put L2
  only in the shared batch. Opaque correlation keys must not contain user identities. An LLM must
  never receive multiple tenants' private examples in one context.
- Within each partition fill requests greedily in deterministic priority order (labels, interactive
  cards, then the rest; tie-break by queue time/card id), subject to:
  - `stateTokens + Σ questionTokens ≤ 48,000`
  - `stateTokens + max(questionTokens) ≤ 28,000`
  - `count ≤ 200`
- Include serialization overhead and the estimator safety factor (spec 04 §6.1). A question/state
  that cannot fit alone returns a typed overflow error; never emit an empty pack or silently omit it.
- One state per article, so every request repeats the same state. Repack for the selected fallback
  engine's smaller context/output limits rather than blindly forwarding a 200-question Jev pack.

### 5.3 `feed_cards` maintenance

- `refresh_feed_cards(feed_ids)` (spec 02 §6) runs in the same transaction as any change to
  subscriptions, `user_cards`, `user_labels` or scope.
- `feed_cards` is the automatic **active-subscription** candidate cache, not permission by itself.
  Intersect it with article arrival after each holder's activation and scope; add exact selected
  training/active requests separately. Off/training subscriptions do not populate automatic demand.
- The set of cards to ask for an article is the union remaining after §1.1 admission, including its
  authorized selected-request cards. Never use an unfiltered feed-level union for paid work.
- New admitted articles get `match_queue` rows for that union right after enrich (priority 5). Matching and
  L2-only job intents are committed through the outbox. A newly discovered `feed_items` association
  on an existing article queues newly applicable **authorized** cards and reranks affected subscribers;
  a new carrier does not authorize off subscribers or an old pre-activation feed item.
- Queue/cache membership is recomputed on execution. A queued pair with no active holders on any
  current authorized carrying feed/request is discarded, and a changed/deleted private card is never sent after its
  owner no longer authorizes that use.

### 5.4 Backfill (`card.backfill {userId, cardIds, feedIds?, snapshotAt?, cursor?, processedCount?}`)

1. On the first page fix `snapshotAt=now`, `processedCount=0`; carry both through continuation
   payloads (spec 03 §2). Revalidate that the user exists, still holds each card/label and subscribes
   to the feeds. Intersect `feedIds` with subscriptions, §1.1 admission and each interest card's scope.
   Card-maintenance backfill can refresh admitted active arrivals/current selected requests only;
   an explicit historical backfill must first materialize bounded `analysis_requests` for selected ids.
2. Select articles by eligible feed membership time in
   `[snapshotAt − plan.backfill_days, snapshotAt]`
   (default 7 days), not the possibly old globally deduplicated article timestamp. Include usable enriched/
   matched revisions; schedule prerequisite enrichment for missing/degraded current facets. A user
   subscribing to an old globally known item must not leave it permanently unclassified.
3. Process newest first in **pages of 500** distinct articles. For each article use the maximum
   eligible carrying feed's `feed_items.first_seen_at` as its page key (not the global article time).
   Order `(firstSeenAt, articleId)` descending and continue strictly below the saved cursor; bigint
   article ids compare numerically. Carry `{cursor:{firstSeenAt,articleId}, snapshotAt, processedCount}`
   in the outbox continuation, increasing `processedCount` by unique articles visited on this page.
   The cap is a page size, not silent loss after item 500; quotas/window bound the full snapshot.
4. Insert/update `match_queue` for unanswered current fingerprints (including old LLM/prefilter
   answers), priority **2** while `processedCount + pageIndex < 50`, **6** for the rest; the
   interactive allowance never restarts on another page.
   On conflict promote `priority = least(old,new)`, retain progress for the same revision, and reset
   stale revision/lease/attempt state. Multiple requesters make attribution platform-shared, not an
   arbitrary winner. Never reset a live current lease just because another user requested the pair.
5. Durably enqueue singleton `article.match` for each affected article and any backfill continuation.

A new subscription is `off` and creates **no inference backfill**. An active feed's admitted new
arrivals include the user's applicable interest **and label** cards. Adding a card can reuse/backfill
that existing admitted set; it does not expand historical authorization.
Subscription cancellation/removing a card during a backfill is harmless because each page revalidates.

### 5.5 Match handler (`article.match {articleId}`)

This is the **current shared article** worker. Selected frozen requests use `analysis.process`
(§1.1); share pure builders/router/cache code, not a mutable article-only job identity. A cache fill
from that request can satisfy this worker only when every current input fingerprint matches.

1. **Snapshot and lease, no transaction across HTTP.** In a short transaction select up to 400
   current due rows (`attempts < 5`, `next_attempt_at ≤ now`, lease absent/expired), ordered by
   priority, queue time and card id, using `FOR UPDATE SKIP LOCKED`. Stamp a fresh `lease_token`,
   `lease_until` and `article_revision`, then commit. Lease duration must cover the router deadline;
   renew or stop if ownership is lost. The article worker also serializes L2-only scheduling.
2. Drop retired/unheld/out-of-scope or no-longer-admitted pairs; recheck inference mode/version and
   selected request authorization from §1.1 before every outgoing pack. Delete already-satisfied rows only when a current answer
   matches **all** §2 fingerprints and an approved primary engine/model. LLM/prefilter answers are
   provisional and cannot suppress recovery. Read the active configuration once for this job snapshot.
3. **Prefilter (optional, disabled until G1 validates recall).** With more than
   `PREFILTER_MIN_CARDS` (60) and current primary facets, keep a card when `topic_ids` is empty,
   any topic's L1 (`topic_id.split('.')[0]`) has `t1.<l1> ≥ 0.05`, or the card is a label. Other pairs
   may receive `engine='prefilter', p=0` as a storage-compatible **unknown/provisional marker**.
   It is not a measured zero and never justifies hidden/Everything-else placement, anti-interest
   action, or learning. Ranking applies spec 06's incomplete-answer rules. Enabling prefilter is a
   versioned configuration change with measured false-negative recall, not an untested cost shortcut.
4. Add every selected L2 branch that lacks a current compatible answer (§4), once in the shared
   partition. This step also runs when there are zero card rows. Empty question sets finish without
   calling a model. Repeated dispatch must not regenerate an already-complete L2-only call.
5. Build the immutable state/question snapshots and fingerprints; pack (§5.2), then call the router
   for each pack with `kind='match'`, article id/revision and state hash. Priority is interactive when
   a row has priority ≤3. Set `userId` only for one owner's private or single-requester work; shared
   and L2 costs remain platform costs. A private-only pack never carries another user's examples.
6. **On ok**, in a short transaction compare current article revision, active set/mode/model policy,
   concrete input fingerprints, current inference demand/version and lease token. If any changed, discard outputs and durably enqueue
   current work; its already incurred cost is still logged. Otherwise upsert only the returned pack's
   answers and L2 rows with full provenance, rebuild compatible features, and delete only the rows
   leased and answered by this pack. A newer/primary answer cannot be overwritten by an older or
   fallback result for the same input. Do not delete rows another worker reclaimed.
7. **On deferred outcome** (`budget`, `no_key`, open breaker, provider `Retry-After`): release the
   lease, record `last_error`, set `next_attempt_at` (next UTC budget day, known retry time, or the
   10-minute recovery interval). Do not increment failure attempts or immediately re-enqueue a hot
   loop. Deferred service unavailability is visible to ranking, so readers get a useful Maybe fallback.
   **On `no_demand`:** cancel/drop only the now-unauthorized request/pair quietly, without a provider
   retry or charging a failed inference. Preserve other current holders' demand.
   **On actual retry exhaustion:** increment attempts once for that logical pack, schedule exponential
   job delay (1, 2, 4, 8 minutes), and retain rows at `attempts=5` as exhausted/unavailable. Permanent
   invalid requests are exhausted immediately and alert with redacted diagnostics. No unknown work
   is silently deleted. Recovery resets a bounded batch only after the relevant condition has changed.
8. `pipeline_state='matched'` means current facets, all required card pairs and selected L2 branches
   are complete (prefilter markers count only as completed provisional work). A single successful
   pack cannot mark the whole article complete. Service failure may mark the article degraded as an
   operational summary, but never invalidates another subscriber's already valid answers.
9. In the same DB transaction write outbox intents for affected users' incremental `user.rank` and
   any remaining due pages. The dispatcher must allow a follow-up after the active singleton job
   completes; retrying an enqueue that was suppressed by the current singleton is mandatory. Future
   due/exhausted rows are recovered by the scheduler, not busy-polled by the current handler.

**Reader-specific coverage contract (spec 06):** first check §1.1 inference eligibility; off/nonselected
items are unclassified, never degraded merely because analysis was not requested. For admitted
articles consider only the user's currently held positive cards scoped to an authorized carrying feed. A valid non-prefilter current answer is `answered`; missing work scheduled normally
is `pending`; no-key/budget/breaker/exhausted or prefiltered work is `unavailable`. Labels/never-cards
have independent coverage. Missing is never numerically equivalent to negative. Retain valid high
positive evidence; incomplete low positive evidence cannot send an item to Everything else. With no
usable positive answers, pending goes to New and unavailable uses BM25/Maybe. A real current never-card
answer can still apply its explicit rule. Reader coverage must be reevaluated on scope/config/revision
changes even when no `answered_at` changed.

### 5.6 `resetArticleAnswers(articleId)`

Used whenever classification inputs change: title/excerpt/body update, canonical publisher metadata,
language correction or a selected translation replacing the previous variant (specs 03 and 07).
In one transaction:

1. Increment `articles.content_revision` (decimal bigint) and invalidate current `article_facets`,
   `card_answers`, `article_topics_l2` and derived feature/cache pointers. Persist old evaluation runs
   separately. A title edit that needs extraction invalidates the old body/translation too; a new
   selected translation or extracted body is installed with the new revision atomically so it is
   not immediately lost. The invalidation routine accepts the new derivative and next-stage intent;
   it does not delete the very input that triggered the update.
2. Upsert the **distinct admitted** article/card union (§1.1), never resurrecting cancelled selections, into `match_queue`, replacing the revision and
   clearing old leases/attempts. Old in-flight jobs cannot commit because §5.5 compares revisions.
3. Set `pipeline_state='ingested'` when extraction is needed or `'translated'` when enrichment is
   next; clear stale operational error fields and invalidate stale clustering membership.
4. Record prerequisite extract and, only for admitted demand, enrich intents, plus affected-user
   rerank intents in `job_outbox`. Matching
   waits for current facets; it must not race ahead merely because queue rows already exist.

All producers use this one invalidation contract. Content equality/fingerprints avoid resetting on
identical fetches. Workers cannot resurrect deleted users/cards/articles during stale completion.

---

## 6. Story clustering (`article.cluster {articleId}`)

Require current article-level demand (§1.1) before candidate/model work. Only compare candidates
that already have compatible authorized classification; do not expand paid inference into unrelated
off feeds merely to create cluster context.

1. **Candidates** (SQL):

   ```sql
   SET LOCAL pg_trgm.similarity_threshold = 0.35;           -- makes `%` use the GIN trigram index
   SELECT * FROM (
     SELECT DISTINCT ON (a.id) a.id, a.title, a.excerpt, a.first_seen_at, f.id AS feed_id, f.title AS feed,
            similarity(a.title_norm, $3::text) AS sim
     FROM articles a
     JOIN feed_items fi ON fi.article_id = a.id
     JOIN feeds f ON f.id = fi.feed_id
     WHERE a.id <> $1
       AND a.title_norm % $3::text
       AND a.first_seen_at BETWEEN $2::timestamptz - interval '72 hours' AND $2::timestamptz + interval '1 hour'
       -- explicit casts: node-postgres sends parameters untyped
     ORDER BY a.id, fi.first_seen_at, f.id
   ) c
   ORDER BY sim DESC, id
   LIMIT 20;
   ```

   Then, in code: walk the 20 by similarity, skip a candidate once 2 from the same `feed_id` have been
   kept, and stop at 5.
2. No candidates → done (the article is a singleton, `story_cluster_id` stays null).
3. **State:**
   `{ new: {title, excerpt≤300, feed, published}, candidates: [{id: 'c1'…'c5', title, excerpt≤300, feed, published}] }`.
   `published` is a coarse string ("2 hours before `new`"), computed in code, so no date arithmetic is
   left to the model.
4. **Questions** (`cluster-v1`):

   ```ts
   same_story: choice({ question: 'Which item in `candidates` reports the same specific event as `new`?',
                        focus: 'Same topic is not enough; it must be the same event.' },
                      { c1: null, …, cN: null, none: 'No candidate reports the same specific event' }),   // N = number of candidates (1–5)
   is_followup: noul('Is `new` a follow-up with substantial new developments rather than a re-report of an event already covered in `candidates`?'),
   ```

5. **Fold rule (in code):** if `same_story ≠ none` **and** `probabilities[chosen] ≥ 0.7` **and**
   `is_followup < 0.5`:
   - put `new` in the chosen article's cluster
   - if that article has no cluster yet, create one with the older article as representative
   - perform membership changes in a short transaction with cluster/article rows locked in stable id
     order; reread both memberships after the model call, reject stale article revisions and avoid
     loops or racing A→B/B→A clusters
   - repeated delivery is idempotent: increment `size` only on new membership, or recompute it from
     members; choose the oldest `(first_seen_at,id)` as representative. Merge two existing clusters
     only under the same locking rule, reassigning all members and recomputing size

   Otherwise leave it unclustered.
6. Engine not ok → skip without blocking ingestion; record a metric. Clustering is best-effort.
   Ranking may select a representative only from articles the reader can access; global clustering
   must never expose unsubscribed/private material through a representative, title or explanation.

---

## 7. Card suggestions (`user.suggest {userId}`)

**Trigger:** after `user.learn`, and by a nightly cron. In a short transaction lock the user row and
claim `suggest_lease_token`/`suggest_lease_until` only if no live lease and `last_suggested_at` is null
or ≥24h old. Recheck ownership before sending, then stamp `last_suggested_at` immediately before
the first wire attempt, atomically with its successful spend reservation; unsuccessful provider
attempts still consume this 24h opportunity. Renew the lease for the bounded logical request and release it with a token predicate. No eligible
candidates or a budget deferral before sending releases the lease without consuming the opportunity.
Expired leases recover after a crash; queue throttling alone does not replace this durable claim.

1. Recheck that the user has active inference or explicitly selected training demand. An off-only
   user receives no suggestion call. Candidate source articles must be currently authorized for that
   user under §1.1; a historical rating alone is not inference permission.
   **Unexplained likes:** articles the user rated +1 (or bookmarked) in the last 30 days where the max
   `p` over the user's applicable positive cards is <0.3, using complete current primary-engine
   answers. Do not treat missing/prefilter/fallback or never-card answers as unexplained likes. With
   zero positive cards, onboarding supplies suggestions instead. Stop if fewer than 3 eligible items.
2. Pick the L1 with the highest summed current `t1.*` (excluding `other`, ties by id); then choose
   up to 5 eligible articles relevant to that branch. Do not ask what unrelated likes have in common.
3. **Options:** library cards whose `topic_ids` intersect that L1, excluding cards the user holds or
   dismissed in the last 90 days. At most 60, plus `none: 'None of these describe what the articles have in common'`.
4. **State:** `{ liked_articles: [≤ 5 × {title, excerpt ≤ 300}] }`, the most recent first.
5. **Question** (`suggest-v1`):
   `choice({ question: 'Which interest best describes what `liked_articles` have in common?' }, options)`,
   where each option's criteria is `{what: card.interest, not_for?: card.not_for}`.
6. If no candidate exists, skip (a one-option Choice is invalid). If `none` wins, insert nothing.
   Otherwise insert up to 3 non-none options with probability ≥0.15, ordered deterministically.
   Dedupe active `(user, card)` suggestions and recheck held/dismissed state on commit. Choice
   probabilities are relative to this candidate list, not absolute relevance probabilities.
7. Engine `kind: 'suggest'`, `priority: 'bulk'`, `userId` set.

---

## 8. Card library seed (`packages/questions/library/*.json`)

- One file per L1. Each entry:
  `{ slug, title, title_sk, interest, interest_sk?, not_for?, topic_ids: string[], examples_yes?: string[≤3], examples_no?: string[≤2] }`.
- **`pnpm db:seed`** (`apps/worker/src/seed.ts`, running as `feedit_worker`) upserts by `slug` into
  `interest_cards` (`origin='library', visibility='public'`, `i18n.sk` from the `*_sk` fields):
  - **Unchanged text** (same `text_hash`): update `title`, `topic_ids`, `i18n` in place.
  - **Changed semantic text:** create/reuse a new immutable public library version, move the
    discovery `slug` to it transactionally, and append
    `library_card_versions(library_slug,version,card_id,previous_card_id,created_at)` with monotonic
    version and the old card as `previous_card_id`. Library discovery shows the current version;
    older versions remain accessible to their holders and through exact update lineage.
    Keep the old card readable/answer-valid and held by existing users. Do **not** retire a held old
    version, re-point `user_cards`, rematch its holders, or change their model context automatically.
    A reused non-public shared hash must first satisfy the publication authorization policy (§8.1), so seed cannot bypass
    publication controls; hold that entry with a report rather than silently promote it.
  - Each seed entry/version-link transaction is idempotent. Cosmetic title/i18n/topic corrections are
    permitted in place only when they do not alter classification semantics; question/example/text
    changes always create a version. Private forks retain their historical immutable parent.
  - **User-controlled updates (Q9 resolved):** `GET /library/updates` returns exact old/new ids and
    semantic text/example differences for held old versions (and related private forks), without
    applying them. `POST /library/:id/updates/:newId/apply {expectedCurrentCardId}` verifies the
    advertised old→new lineage and current unforked holding inside one transaction:
    - explicitly switch only that holder to the proposed new card, preserving strength, scope and
      display override. If already holding the target with identical settings, coalesce idempotently;
      differing settings return a conflict and preserve all existing choices.
    - old private/custom forks remain unchanged. "Customize instead" opens the existing explicit
      card editor with the proposed semantic diff for review; normal immutable edit/example-fork
      rules apply to the user's submitted text/examples. Do not auto-merge upstream wording into a
      fork or silently drop/copy its private examples. Applying a shared update to a private current
      holding returns a conflict until the user explicitly edits it or removes/adopts a chosen card.
    - on accepted change: refresh active-demand membership, invalidate that user's compatible model
      context, enqueue admitted-demand backfill and full rerank via the outbox. Other holders, their
      scores and their selected old version remain unchanged.
    - ignoring an update means keeping the existing version indefinitely while held; no timeout,
      deployment or background seed interprets silence as acceptance. A later library update is a
      new explicit old→new offer, not permission to skip the user's choice.
- **Size:** ≥ 150 cards in total and ≥ 5 per L1 (except `other`), including ≥ 15 cards specific to
  Slovakia/Czechia (e.g. Slovak domestic politics, Czech tech scene, Tatras hiking, Slovak football
  league).

**Authoring rules:**
- One concrete interest per card.
- Phrase it so that "yes" means the reader wants the article.
- Add `not_for` for the most likely confusion.
- Avoid negated meaning inside `interest`; validate semantically, not with a substring ban that
  rejects words such as “notebooks”.
- ≤ 200 chars.
- English text; the Slovak translation goes in `*_sk` for display only.

**Examples** (the seed must include these):

| slug | title | interest | not_for | topic_ids |
|---|---|---|---|---|
| `ev-batteries` | EV battery tech | New battery chemistry and manufacturing for electric vehicles (solid-state, sodium-ion, LFP) | Stock-price moves; car launch PR without battery detail | `transport.ev`, `science.physics_chemistry` |
| `rust-lang` | Rust programming | The Rust programming language: releases, libraries, tooling and real-world use | Rust the video game; corrosion | `technology.software_dev` |
| `llm-research` | LLM research | Research results and technical deep-dives about large language models | Consumer product announcements without technical content | `technology.ai_ml`, `science.research_academia` |
| `sk-politics` | Slovak politics | Slovak domestic politics: government, parliament, parties and coalition disputes | Foreign politics mentioning Slovakia in passing | `local.slovakia`, `politics.domestic` |
| `cz-tech-scene` | Czech tech scene | Czech tech startups, funding rounds and technology companies | Global tech news without a Czech angle | `local.czechia`, `business.startups` |
| `tatras-hiking` | Hiking in the Tatras | Hiking routes, trail conditions and mountain safety in the High and Low Tatras | General travel deals | `lifestyle.travel`, `local.slovakia` |
| `nhl-slovaks` | Slovaks in the NHL | Slovak and Czech players in the NHL: games, trades and stats | Other leagues | `sports.ice_hockey` |
| `home-assistant` | Smart home DIY | Home Assistant, self-hosted home automation and smart-home hardware hacking | Commercial smart-speaker ads | `diy.electronics_diy`, `technology.hardware_gadgets` |
| `space-launches` | Space launches | Rocket launches, spacecraft missions and launch-industry news | Astrology; sci-fi films | `science.space` |
| `personal-finance-eu` | Personal finance (EU) | Saving, investing and pensions for individuals in the EU, especially Slovakia and Czechia | Corporate earnings | `business.personal_finance` |

### 8.1 Public promotion of a user-created shared card (Q2/Q12 resolved)

Sharing/reuse is allowed. Discoverable public-library publication is admin-controlled, requires the
existing **≥3 current holders** candidate threshold and unchanged exact immutable card id/text hash
and publication metadata, plus **one** authorization basis below. Private forks/examples cannot be
promoted or copied into public text. The original `creator_user_id` controls this policy; holding,
renaming or reusing the shared text hash never transfers authorship.

1. Admin records `card_publication_requests` through
   `POST /admin/library/promotion-requests`, binding card text hash, publication payload/hash and
   expected version. Resolve the original existing, non-deleted creator from recorded provenance;
   unknown/deleted creators or conflicting provenance stay on hold. Do not substitute another holder.
2. **Creator active within the preceding 30 days:** require affirmative approval for the exact
   request version through `GET /cards/publication-requests` and its `respond` action. No response
   while recently active leaves the card shared. Record `authorization_kind='creator_approval'`
   only for an actual affirmative response; preserve response time/version in the audit evidence.
3. **Creator inactive for at least 30 consecutive days:** admin may promote without an affirmative
   response under the owner-approved inactivity policy. At publication time compute
   `lastActivity = creator.last_active_at ?? creator.created_at`; use `created_at` only when it is a
   known trustworthy timestamp for that same existing creator. Require
   `lastActivity ≤ publishTime − interval '30 days'`. Missing/untrustworthy provenance remains on
   hold. This is measured from creator activity, **not** from card age or request age. Any intervening
   activity restarts the 30-day period. Record `authorization_kind='creator_inactive_30d'` and exact
   activity/evaluation timestamps, policy version, card/payload hashes and publishing admin in
   `authorization_evidence`; do not fabricate approval, `responded_at`, or a consent event.
4. **An explicit decline is a veto.** It cannot be overridden by inactivity or by creating another
   request for the same card. Only a later explicit creator approval that supersedes that decline
   can authorize publication; retain both events in the audit trail.
5. `POST /admin/library/promote {requestId,expectedVersion}` locks the original creator, card and
   request in a stable order and rechecks current activity, holder count, vetoes, provenance and
   exact payload before writing. Concurrent login/activity and publication must serialize on that
   creator row; a refreshed activity timestamp removes the inactivity basis. A changed payload
   cannot reuse old approval. Atomically set visibility/publication metadata, status `promoted`,
   `promoted_at`/`promoted_by` and the actual authorization evidence. Idempotent replay creates no
   duplicate publication and never rewrites the recorded basis.

Thirty days replaces the earlier seven-day activity rule. Eligibility does not auto-publish a card:
the existing admin curation/promotion action still decides whether to include it. Seed-authored
library cards are curated public inputs with seed provenance; collisions with user-created shared
cards must follow the same authorization policy. Audit records distinguish affirmative consent from
inactivity-based publication, admin curation and later semantic version proposals.

---

## 9. Cost math (the reference for budgets)

Forecast **authorized unique work**, not all fetched articles or a full call for every subscriber.
For each day let A be distinct article revisions admitted by §1.1 needing shared enrichment, and C_a
be distinct authorized unanswered card ids for article a. Off feeds contribute zero provider demand;
training contributes selected requests only; active feeds contribute new arrivals since activation.
Explicit historical selections/backfills are a separate bounded input. Existing compatible cache
answers reduce A/C_a before estimating spend. Multiple users of the same article/card share one call;
private owner partitions still require separate packs.

Illustrative token assumptions (verify at G1): Call A 2,000 input tokens, Call B state 600,
card question 250, selected L2 questions about 300. For each actual pack include its repeated state,
questions and serialization overhead. At the verified Jev rate of $0.042/M input tokens:

`estimated Jev cost = 0.042 × (2000*A + Σ actual-pack input tokens + clustering/suggestion tokens) / 1e6`.

Example: a 20-user beta may fetch 5,000 articles/day, but if only 1,000 revisions have active or
selected demand and each needs ten shared cards in one pack, A=1,000 and Call B≈3.4M tokens; A+B≈5.4M
input tokens, ≈$0.227/day before optional/extra costs. This is a demand-mix example, **not** a promise
that 20% of feeds will be active. If all 5,000 need the same work, the comparable base is ≈$1.134/day.
Record both the expected activation mix and an all-active upper scenario in G1.

Slow training requests use `priority:'bulk'` and the same budget/deferred recovery; they cannot
silently borrow automatic feed authorization. Include translation, fallback output, private packs,
retries/reclassification, explicitly requested history, and provider minimums separately. Suggestion
cost scales with eligible users, not all registered users. The daily cap and queue-age alert constrain
spend; track pending training age separately so "slow" never means lost work.

---

## 10. Reproducibility and logging

Every stored answer carries engine/model, active question-set provenance, article revision, exact
state hash and variant. Card answers also carry the exact built-question hash. Engine calls retain
bounded metadata and billing provenance, not unredacted private text or provider error echoes.

`card_answers`, `article_topics_l2` and `article_facets` are **current caches**; their upserts do not
constitute append-only history. Never promise a production replay from a row already replaced.
Frozen eval runs (spec 10) separately store their question definitions, inputs, answers and versions.
For identical valid inputs the approved primary answer outranks LLM/prefilter; an old primary answer
for different inputs must not block a new current fallback. Enabling Laya requires an explicit
per-kind engine precedence/calibration policy; it is not automatically interchangeable with Jev.

---

## 11. Tests

**Unit** (`packages/questions`):
- canonical JSON and hashing are stable across key order
- `ENRICH_V1` validates against the TypeSafe limits (≤ 255 options, 2–10 score levels)
- the taxonomy has 20 L1 entries, unique ids and every L2 parent exists
- card builder snapshots
- packing respects the limits with synthetic 500-card inputs
- `flattenFacets` snapshots
- the cluster fold rule truth table
- suggestion candidate selection

**Integration** (worker handlers with fixture engines):
- enrich → match → `user.rank` enqueued, end-to-end for one article with 3 cards and 1 label (the rank handler itself is M5)
- a backfill creates the expected queue rows and drains them in ≤ 2 calls
- a prefilter case
- degraded enrich leaves `pipeline_state = 'degraded'`


- two workers claiming/reclaiming a lease; no long transaction across the fake HTTP call
- old revision/old lease output cannot overwrite new facets, card or L2 answers
- >500-item backfill continuation, changed feed scope, duplicate card holdings, new feed association
- one successful/one failed pack leaves correct per-user coverage; missing never-card never hides
- budget/no-key/long Retry-After retain work without retry storms; crash recovery resumes outbox work
- LLM-only card recovery with a Jev-enriched article; current cache invalidated by card-text-mode change
- private-batch isolation, cross-tenant card ids rejected and label rename keeps semantic identity
- L2-only, zero selected branches and one cached/one missing branch; joint probability feature values
- a semantic seed update offers an immutable version without migrating any holder; explicit replace
  preserves settings, conflicting target holdings roll back, and private forks remain unchanged
  unless their owner explicitly submits a reviewed edit
- creator provenance survives hash reuse; consent is exact-version, another holder cannot approve,
  and an active creator without approval or deleted/unknown provenance stays on hold; exactly 30 days
  permits audited admin inactivity publication, concurrent renewed activity blocks it, and decline
  cannot be bypassed by the clock or by a fresh request
- off-only ingestion makes zero inference calls even when shared answers exist; training analyzes
  only selected ids; active admits new arrivals since activation without historical auto-backfill
- request cancellation/mode-version races revoke that user without breaking other active demand;
  two authorized users reuse one article/card answer and no gratuitous per-user provider calls occur
- first rating with slow analysis uses frozen pre-feedback inputs; retries never read later cards/text
- cluster delivery twice leaves size unchanged
