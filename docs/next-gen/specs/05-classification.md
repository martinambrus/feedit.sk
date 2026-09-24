# Spec 05: Classification: question sets, interest cards, matching, clustering

Status: **binding**. **Intent:** understand each article once for everyone (Call A). Then ask one
absolute yes/no question per *distinct* interest card on the article's feeds (Call B), packing as many
questions as possible into each call, because Jev bills per input token and reads the state once per
call. Keep every raw answer so ranking can change without new calls.

Code: `packages/questions` (pure: question sets, builders, taxonomy, hashing, packing, library seed),
and `apps/worker/src/handlers/article-{enrich,match,cluster}.ts`, `card-backfill.ts`, `user-suggest.ts`.

---

## 1. Calls at a glance

| Call | When | State | Questions | Stored in |
|---|---|---|---|---|
| **A: enrich** | once per non-stale article (and again if its title changes) | the article (§3.1) | fixed set `enrich-v1` (§3.3) | `article_facets` |
| **B: match** | once per article per batch of pending cards | the article (smaller) | one Noul per card/label (§5.2), plus L2 topic Choices (§4) | `card_answers`, `article_topics_l2` |
| **cluster** | after enrich, only if candidates exist | new article + ≤ 5 candidates | fixed set `cluster-v1` (§6) | `articles.story_cluster_id` |
| **suggest** | after learning, at most daily per user | ≤ 5 liked articles | one Choice over library cards (§7) | `card_suggestions` |

---

## 2. Question sets: versioning and hashing

- Every static question set is a TypeScript constant in `packages/questions/src/sets/<name>.ts`,
  exporting `{ kind, version, questions }`.
- `canonicalJson(x)`: JSON with object keys sorted recursively, no whitespace, arrays in order.
  `sha256(canonicalJson({kind, version, questions}))` is the set's `sha256`.
- For the **match** set, the hash covers the builder *template*: the instruction wrappers and criteria
  scaffolding with placeholders. Individual card questions are then identified by
  `(question_set_sha, card.text_hash)`.
- `pnpm db:seed` (and a startup check in the worker) upserts every set into `question_sets`. It
  **fails** if a `version` already exists with a different `sha256`. Changing wording requires bumping
  the version (`enrich-v2`).
- `settings['question_sets.active']` names the active set per kind. Switching `enrich` to a new set is
  an admin action that enqueues re-enrichment of articles first seen in the last 7 days (a
  `house.reenrich` one-off job, capped by the budget).

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

- The variant is chosen per article from `LANGUAGE_MODES[article.lang]` (spec 07 §2). An unknown
  language uses `'native'`.
- If the mode is `translate` but no usable translation exists (both tiers failed), the builder falls
  back to `'native'`.
- The variant used is stored (`state_variant`).
- Dates and numbers are **not** put in the state. Age and price logic happens in code (spec 06).

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
| `t2.<l1>.<l2>` | L2 probability for asked branches (§4); absent = 0 |
| `depth`, `depth_conf` | `score / 4`, confidence |
| `clickbait`, `promotional`, `time_sensitive`, `evergreen`, `paywall_teaser` | Noul p |
| `scope.<option>` | `local_scope` probabilities (4) |
| `tone` | `score / 4` |

`features` is recomputed (and the row updated) when L2 answers arrive after Call B.

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
still made for the L2 questions **only if** the article's feeds have at least one subscriber. That
costs about $0.00004 and keeps features complete for suggestions and personal models.

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
text_hash = sha256(canonicalJson({ kind, interest: norm(interest), not_for: norm(not_for ?? ''),
                                   examples_yes: examples_yes ?? [], examples_no: examples_no ?? [],
                                   owner: visibility === 'private' ? owner_user_id : null }))
```

**Lifecycle** (`packages/db` card repository, called by the API):

| Action | Effect |
|---|---|
| Create (text only) | Compute `text_hash`. Reuse an existing `public`/`shared` card with that hash, or insert `origin='user', visibility='shared'`. Insert `user_cards`. Refresh `feed_cards` for the user's feeds. Enqueue `card.backfill` |
| Adopt a library card | Insert `user_cards` for the library card. Refresh + backfill as above |
| Add an example (from "Why this?" or "make a card from this") | If the user's card is not their own private fork, create a fork: `origin='fork', visibility='private', owner_user_id=user, parent_card_id=old`, body with the new example (newest 5 per side). Re-point `user_cards` (delete old, insert new, same strength and scope). Refresh + backfill for the fork |
| Edit text | Same as create for the new text. Re-point `user_cards`. The old card keeps its answers for other holders |
| Change strength | Update `user_cards.strength`. Enqueue `user.rank {full: true}`. No model calls |
| Change scope | Update `scope_feed_id`, refresh `feed_cards`, backfill if the scope widened |
| Delete | Delete `user_cards`, refresh `feed_cards`, enqueue `user.rank {full:true}`. Cards are never deleted while answers exist; `house.retire-cards` retires cards with no holders that aren't in the library (spec 11) |

`CARD_TEXT_MODE = 'english'` (a setting, decided at gate G1): on create, if the card text's detected
language is not English, translate `interest` and `not_for` with tier-1 MT into `interest_en` and
`not_for_en`. The builder uses the `*_en` fields when present. Users always see what they wrote.

### 5.2 Question builders

```ts
export function cardQuestion(card: CardBody, mode: CardTextMode): NoulQuestion {
  const interest = (mode === 'english' && card.interest_en) || card.interest;
  const notFor   = (mode === 'english' && card.not_for_en)  || card.not_for;
  return noul(
    { question: 'Would a reader with this interest want to read `article`?',
      interest, ...(notFor ? { not_for: notFor } : {}),
      focus: "Judge the article's main subject, not passing mentions." },
    { true:  { what: "The article's main subject falls within `interest`", ...(card.examples_yes?.length ? { examples: card.examples_yes } : {}) },
      false: { what: 'Only mentions it in passing, or falls under `not_for`', ...(card.examples_no?.length ? { examples: card.examples_no } : {}) } },
  );
}

export function labelQuestion(name: string, card: CardBody, mode: CardTextMode): NoulQuestion {
  // same shape with question: 'Does `article` fit this label?', label: name, definition: interest, not_for
}
```

- **Anti-interest cards** (`strength = 'never'`) use `cardQuestion` unchanged. Their meaning is
  inverted only in the ranker.
- **Keys:** `c<cardId>` for cards and labels, `t2_<l1>` for L2 topics.

**Packing** (`packRequests(stateTokens, questions)`, pure):
- Estimate tokens per question (spec 04 §6.1).
- Fill requests greedily in priority order (labels, then interactive cards, then the rest), subject to:
  - `stateTokens + Σ questionTokens ≤ 48,000`
  - `stateTokens + max(questionTokens) ≤ 28,000`
  - `count ≤ 200`
- One state per article, so every request for that article repeats the same state.

### 5.3 `feed_cards` maintenance

- `refresh_feed_cards(feed_ids)` (spec 02 §6) runs in the same transaction as any change to
  subscriptions, `user_cards`, `user_labels` or scope.
- The set of cards to ask for an article is the union of `feed_cards` over the article's `feed_items`.
- New articles get `match_queue` rows for that union right after enrich (priority 5).

### 5.4 Backfill (`card.backfill {userId, cardIds, feedIds?}`)

1. Feeds are `feedIds`, or else all the user's subscriptions. A card with `scope_feed_id` only uses
   that feed.
2. Articles are those from `feed_items` of those feeds with `first_seen_at ≥ now − plan.backfill_days`
   (default 7) and `pipeline_state IN ('enriched','matched')`, newest first, capped at **500** per
   request.
3. `INSERT INTO match_queue (article_id, card_id, priority, user_id)` with priority **2** for the newest
   50 articles and **6** for the rest, `ON CONFLICT DO NOTHING`. Skip pairs that already exist in
   `card_answers` with the current match set sha.
4. Enqueue `article.match` for each affected article (singleton per article).

A new subscription runs a backfill of all the user's cards for that feed.

### 5.5 Match handler (`article.match {articleId}`)

1. Claim the rows:
   `SELECT card_id, priority, user_id FROM match_queue WHERE article_id = $1 ORDER BY priority, enqueued_at FOR UPDATE SKIP LOCKED LIMIT 400`.
2. Drop retired cards, and cards that already have an answer with the current match set sha (delete
   those queue rows).
3. **Prefilter.** Only when the article has more than `PREFILTER_MIN_CARDS` (default 60) queued cards
   **and** has facets:
   - keep a card if `topic_ids` is empty, or if any of its L1s has `t1.<l1> ≥ 0.05`, or if it is a
     label
   - the rest get a `card_answers` row with `p = 0, engine = 'prefilter'`
4. Add the L2 questions (§4) if `article_topics_l2` has no rows for this article yet.
5. Build the state (Call B variant) and the questions, pack them (§5.2), and call
   `EngineRouter.ask` for each pack:
   - `kind: 'match'`
   - `priority: 'interactive'` if any row in the pack has priority ≤ 3, else `'bulk'`
   - `userId`: the single user if every row came from one user's backfill
6. **On ok:**
   - upsert `card_answers` (`p`, engine, model, sha, variant) and `article_topics_l2`
   - recompute `article_facets.features`
   - delete the answered queue rows
   - set `pipeline_state = 'matched'`
7. **On not ok:** `attempts += 1` on the rows. Rows with `attempts ≥ 5` are dropped with a warning,
   and the ranker treats a missing answer as unknown.
8. Enqueue `user.rank {userId, reason: 'match'}` for every user subscribed to any of the article's
   feeds (spec 06 §7 decides what is dirty).

---

## 6. Story clustering (`article.cluster {articleId}`)

1. **Candidates** (SQL):

   ```sql
   SELECT a.id, a.title, a.excerpt, a.first_seen_at, f.title AS feed
   FROM articles a JOIN feed_items fi ON fi.article_id = a.id JOIN feeds f ON f.id = fi.feed_id
   WHERE a.id <> $1
     AND a.first_seen_at BETWEEN $2 - interval '72 hours' AND $2 + interval '1 hour'
     AND similarity(a.title_norm, $3) >= 0.35
   ORDER BY similarity(a.title_norm, $3) DESC
   LIMIT 5;
   ```

   Prefer candidates from other feeds: when there are more than 5, keep at most 2 from the same feed.
2. No candidates → done (the article is a singleton, `story_cluster_id` stays null).
3. **State:**
   `{ new: {title, excerpt≤300, feed, published}, candidates: [{id: 'c1'…'c5', title, excerpt≤300, feed, published}] }`.
   `published` is a coarse string ("2 hours before `new`"), computed in code, so no date arithmetic is
   left to the model.
4. **Questions** (`cluster-v1`):

   ```ts
   same_story: choice({ question: 'Which item in `candidates` reports the same specific event as `new`?',
                        focus: 'Same topic is not enough; it must be the same event.' },
                      { c1: null, c2: null, …, none: 'No candidate reports the same specific event' }),
   is_followup: noul('Is `new` a follow-up with substantial new developments rather than a re-report of an event already covered in `candidates`?'),
   ```

5. **Fold rule (in code):** if `same_story ≠ none` **and** `probabilities[chosen] ≥ 0.7` **and**
   `is_followup < 0.5`:
   - put `new` in the chosen article's cluster
   - if that article has no cluster yet, create one with the older article as representative
   - increment `size`

   Otherwise leave it unclustered.
6. Engine not ok → skip silently. Clustering is best-effort.

---

## 7. Card suggestions (`user.suggest {userId}`)

**Trigger:** after `user.learn`, and by a nightly cron. At most once per user per 24 h.

1. **Unexplained likes:** articles the user rated +1 (or bookmarked) in the last 30 days where the max
   `p` over the user's own cards is < 0.3. Stop if there are fewer than 3.
2. Pick the L1 with the highest summed `t1.*` over those articles (excluding `other`).
3. **Options:** library cards whose `topic_ids` intersect that L1, excluding cards the user holds or
   dismissed in the last 90 days. At most 60, plus `none: 'None of these describe what the articles have in common'`.
4. **State:** `{ liked_articles: [≤ 5 × {title, excerpt ≤ 300}] }`, the most recent first.
5. **Question** (`suggest-v1`):
   `choice({ question: 'Which interest best describes what `liked_articles` have in common?' }, options)`,
   where each option's criteria is `{what: card.interest, not_for?: card.not_for}`.
6. Insert up to 3 options with probability ≥ 0.15 (not `none`) into `card_suggestions`.
7. Engine `kind: 'suggest'`, `priority: 'bulk'`, `userId` set.

---

## 8. Card library seed (`packages/questions/library/*.json`)

- One file per L1. Each entry:
  `{ slug, title, title_sk, interest, interest_sk?, not_for?, topic_ids: string[], examples_yes?: string[≤3], examples_no?: string[≤2] }`.
- `pnpm db:seed` upserts by `slug` into `interest_cards` (`origin='library', visibility='public'`,
  `i18n.sk` from the `*_sk` fields).
- **Size:** ≥ 150 cards in total and ≥ 5 per L1 (except `other`), including ≥ 15 cards specific to
  Slovakia/Czechia (e.g. Slovak domestic politics, Czech tech scene, Tatras hiking, Slovak football
  league).

**Authoring rules:**
- One concrete interest per card.
- Phrase it so that "yes" means the reader wants the article.
- Add `not_for` for the most likely confusion.
- No negations inside `interest`.
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

---

## 9. Cost math (the reference for budgets)

Assumptions:
- Call A ≈ 2.0k input tokens (state ≈ 700, questions ≈ 1.3k).
- Card question ≈ 250 tokens.
- Call B state ≈ 600 tokens.
- L2 questions ≈ 300 tokens.
- $0.042 per 1M input tokens.

| Scenario | Articles/day (non-stale) | Distinct cards per feed (avg) | Call A tokens | Call B tokens | $/day |
|---|---|---|---|---|---|
| Invite-only beta: 20 users, 400 feeds | 5,000 | 10 | 10M | 5,000 × (600 + 300 + 2,500) = 17M | **≈ $1.1** |
| 100 users, 1,500 feeds | 20,000 | 20 | 40M | 20,000 × (900 + 5,000) = 118M | **≈ $6.6** |
| 5,000 users, 15,000 feeds | 150,000 | 40 | 300M | 150,000 × (900 + 10,000) = 1.64B | **≈ $81** |

Clustering adds ≈ 1.5k tokens for about 30 % of articles, and suggestions are negligible.

The default `DAILY_BUDGET_USD = 2.00` fits the invite-only beta with headroom. Raise it as users are
invited: the admin usage page shows the trend.

---

## 10. Reproducibility and logging

Every stored answer carries:
- engine
- model (the versioned id, e.g. `jev-1.13.0`)
- question set sha
- state variant

Every call has an `engine_calls` row. Raw answers are never overwritten by a *different* engine
without a new row (`card_answers` is upserted, but only with the same or a better engine: `typesafe`
replaces `llm`/`prefilter`, never the reverse). The eval replay (spec 10 §7) depends on this.

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
- enrich → match → rank end-to-end for one article with 3 cards and 1 label
- a backfill creates the expected queue rows and drains them in ≤ 2 calls
- a prefilter case
- degraded enrich leaves `pipeline_state = 'degraded'`
