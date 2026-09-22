# Project brief — Learning App (Lernkartei)

**Read this first if you are an AI assistant (or a new developer) who has to work
on this repository.** It describes the whole project: the stack, every folder,
the database down to the column, every endpoint, the services, the frontend, the
learning algorithm, the conventions and the known gaps.

Everything in here was verified against the running project. Where something is
*not* verified, it says so.

---

## 0. Quick facts

| | |
| --- | --- |
| What it is | A flashcard web app. All users share ONE pool of cards; every user has their OWN progress per card. |
| Stack | PHP 8.5.4 (no framework), MySQL 8.4.11, HTML, CSS, vanilla JavaScript. No Node, no bundler, no build step. |
| Database | `learning_app` — 4 tables: `users`, `categories`, `cards`, `user_card_progress` |
| Web root | `public/` — the only directory that may be reachable from the browser |
| Start it | `./start-dev.sh` → <http://127.0.0.1:8081/> (four worker processes) |
| Language of the code | English, everywhere, including comments and commit messages. Only the text the user sees is translated (German/English). |
| Interface | Light + dark theme, German + English, persisted in `localStorage` |
| Login | Works. `public/api/auth.php` (sign in / sign out / register) with `src/services/user_service.php`; `users` holds one account with a `password_hash`. Progress is stored per user. |
| Card images | Optional `cards.map_region` (`DE:…`, `EU:…`, `WORLD:…`). All three maps (`germany.svg`, `europe.svg`, `world.svg`) are fetched at run time. Since 2026-09-22 all three are active; the picker offers exactly the areas of `config.maps`. |
| Category icons | The four drawings exist **only** in `categories.icon_svg`. No file in this repository contains them, so they survive only in the database and in the safety copy below. |
| Safety copy of the database | `/home/user/backups/learning_app_vollstaendig_20260922_162310.sql`, 375 185 bytes, outside the repository and not committed. See §17. |
| Git | `main`, pushed to `origin` = <https://github.com/pachama-ai/learning-app> |

---

## 1. What the application does

Three levels, one tree:

```
Learning area (category, parent_id IS NULL)      e.g. "Energy"
   └── Subcategory (category, parent_id = area)  e.g. "Power grid and transmission"
          └── Flashcard (card.category_id)       front / back / optional both ways
```

Pages:

1. **Start page** (`index.php`) — the learning areas as a horizontally scrollable
   row of tiles, with a scroll line that doubles as the hairline above the footer.
2. **Area page** (`index.php?category=3`) — the subcategories of one area as rows.
3. **Subcategory page** (`index.php?category=50`) — the **card list**: a header
   with a distribution bar, the counts, a search field and the main button
   "Study", then one row per card with its status dot.
4. **Study session** — a full screen view inside the same page: flip a card, rate
   it "Again / Hard / Good / Easy", end with a summary.

All content rows are rendered **by JavaScript from the API**. PHP only writes the
HTML shell. That is why the browser never contains database rows in its markup.

---

## 2. Rules that must not be broken

These come from `.github/copilot-instructions.md` and are mandatory for any
change. Read that file before editing anything.

**Database**

- Never change the structure without asking first. No `ALTER`, `DROP`, `TRUNCATE`,
  `RENAME`, no new/renamed/removed tables, columns, indexes or constraints.
- Never delete existing data. No `DELETE` and no overwriting `UPDATE` unless the
  user explicitly asked for that exact operation.
- Never create fake, sample, demo or seed data.
- Never assume a column name. `DESCRIBE <table>` first, then use the real name.
- Schema changes are proposed as a reviewable SQL file in `database/`, for the
  user to run by hand. They are never executed automatically.

**Code**

- PHP + PDO with **prepared statements only**. Never build SQL by concatenating
  user input, not even for an `id`.
- No frontend framework, no jQuery, no Node, no bundler. Vanilla JavaScript.
- All data access goes through PHP. The browser never talks to MySQL and never
  sees credentials, connection strings or file paths.
- Escape every HTML output (`htmlspecialchars`, `ENT_QUOTES`, UTF-8).
- JSON envelope for every API answer: `{"success":true,"data":…}` or
  `{"success":false,"error":{"code":…,"message":…}}`, with `Content-Type:
  application/json` and `JSON_UNESCAPED_UNICODE`.
- Errors never leak SQL, credentials or stack traces. Details go to the error log.
- Keep it understandable for a beginner: clear names, short functions, comments
  where a decision is not obvious.

**Working style**

- One feature at a time. Explain which files will change before changing several.
- Never overwrite a file silently.
- Every user-visible string goes through the translation lookup. Never hard-code
  a German or English sentence in a template or in JavaScript.
- Use the SVG icons in `public/assets/icons/` — no emoji, no icon font.

---

## 3. Folder layout

```
learning-app/
├── start-dev.sh            the development server with four workers
├── README.md               what the project is and how to run it
├── public/                 THE WEB ROOT (DocumentRoot)
│   ├── index.php           the one front controller: builds the HTML shell
│   ├── .htaccess           gzip + cache hints, only effective under Apache
│   ├── api/                one file per endpoint, thin: validate → service → JSON
│   └── assets/
│       ├── css/app.css     every style
│       ├── js/app.js       the whole frontend, one IIFE
│       ├── fonts/          inter-tight-latin-wght-normal.woff2 (the only face)
│       ├── icons/          browser_icon.svg - the favicon, the only file here
│       ├── maps/           germany.svg, world.svg, europe.svg (loaded at run time)
│       └── samples/        cards-import-sample.csv (the template the import dialog offers)
├── src/                    NOT reachable from the browser
│   ├── config/             database.php (loader) + database.local.php (secret)
│   ├── helpers/            small, stateless functions
│   └── services/           business logic and ALL PDO queries
├── bin/                    command line tools, NOT reachable from the browser
│   └── import_energy_cards.php   the CSV importer (CLI only, `--file=` required)
├── database/               SQL the user runs by hand - nothing else
├── docs/                   project-brief.md, verification.md, migrations.md
└── (root)                  only start-dev.sh and README.md - the scratch scripts are gone
```

There is no `database/import/` any more: the card CSV files were removed on
2026-09-22, because their content is in the database (see §17). The CLI importer
that used to sit next to them now lives in `bin/`, so `database/` holds nothing
but SQL files for the user to run by hand (see §17 as well).

### Files and their size (verified)

| File | Lines | Bytes | Role |
| --- | ---: | ---: | --- |
| `public/index.php` | 631 | 32 230 | Front controller. Prints all visible strings through `t()`, hands the translations and the endpoint URLs to JavaScript as JSON. Touches no database. |
| `public/api/auth.php` | 119 | 4 402 | GET who is signed in (+ CSRF token) / POST sign in, register, sign out |
| `public/api/categories.php` | 167 | 6 537 | GET list / GET one / POST create |
| `public/api/category.php` | 198 | 7 912 | PATCH edit / DELETE (needs the spoken confirmation flag) |
| `public/api/cards.php` | 140 | 5 358 | GET the cards of a category with progress / POST create |
| `public/api/card.php` | 141 | 4 918 | PATCH edit / DELETE a card |
| `public/api/category_icon.php` | 95 | 3 853 | Serves one stored SVG with ETag + `immutable` caching |
| `public/api/import_cards.php` | 167 | 6 304 | Uploads a CSV file from the import dialog |
| `public/api/review.php` | 203 | 7 470 | The learning session: GET queue, POST rate / undo |
| `public/api/health.php` | 47 | 1 666 | Is the database reachable? |
| `public/assets/js/app.js` | 6 167 | 228 978 | The whole frontend in one IIFE |
| `public/assets/css/app.css` | 5 075 | 134 027 | Every style, including both themes |
| `src/helpers/html.php` | 20 | 590 | `escape_html()` |
| `src/helpers/json_response.php` | 78 | 2 312 | `send_json`, `send_json_success`, `send_json_error` |
| `src/helpers/request_input.php` | 283 | 8 269 | Reading + validating request bodies and query strings |
| `src/helpers/svg_sanitizer.php` | 327 | 10 510 | The icon sanitiser (DOMDocument based) |
| `src/helpers/session_user.php` | 129 | 4 411 | Which user is signed in |
| `src/helpers/translations.php` | 801 | 46 947 | All interface strings, German + English |
| `src/services/user_service.php` | 461 | 15 788 | Accounts: sign in, register, CSRF, sessions |
| `src/services/category_service.php` | 713 | 24 152 | Categories: read, create, edit, delete a tree |
| `src/services/card_service.php` | 646 | 20 521 | Cards: read, create, edit, delete |
| `src/services/card_import_service.php` | 576 | 19 104 | The CSV import behind the dialog |
| `src/services/review_service.php` | 853 | 30 491 | The card box: state, interval, due date |
| `src/config/database.php` | 74 | 2 692 | `create_database_connection()` |
| `src/config/database.local.php` | 24 | 697 | The real credentials. **Not in git.** |
| `src/config/database.example.php` | 35 | 1 040 | Template for the file above |
| `docs/verification.md` | 402 | 16 983 | Manual test procedure |
| `docs/migrations.md` | – | – | The migration files and what each one did |
| `bin/import_energy_cards.php` | 1 039 | 37 564 | CLI importer for a CSV file; `--file=` is required. Outside the web root, no URL, `PHP_SAPI` guard. |
| `database/*.sql` | – | – | Reviewable SQL for the user to run by hand. The application never runs them itself. |

---

## 4. The database

Database name: **`learning_app`**. Connection: `localhost:3306`, charset
`utf8mb4`, configured in `src/config/database.local.php`.

### `users` — 1 row, with a real password hash (verified 2026-09-22)

| Column | Type | Notes |
| --- | --- | --- |
| `id` | int unsigned, PK, auto_increment | |
| `name` | varchar(100) NOT NULL | UNIQUE (`uniq_users_name`) |
| `email` | varchar(190) NULL | UNIQUE (`uniq_users_email`) |
| `password_hash` | varchar(255) NULL | `password_hash()`, never the password itself |
| `created_at` | datetime NOT NULL default CURRENT_TIMESTAMP | |
| `role` | varchar(30) NOT NULL | the service writes `learner` |

The three columns after `name` come from `database/add_user_auth.sql`; the signs
in and out live in `public/api/auth.php` and `src/services/user_service.php`.

### `categories` — 21 rows (verified 2026-09-22)

| Column | Type | Notes |
| --- | --- | --- |
| `id` | int unsigned, PK, auto_increment | |
| `parent_id` | int unsigned NULL | NULL = learning area, otherwise the parent. Indexed. |
| `name` | varchar(100) NOT NULL | English name, the one the code uses |
| `color` | varchar(7) NULL | `#RRGGBB`, may be NULL |
| `icon_svg` | **mediumtext** NULL | The stored drawing, up to 350 KB |
| `icon_scale` | decimal(3,2) NOT NULL default 1.00 | |
| `name_en`, `name_de` | varchar(100) NULL | The names the interface shows |
| `description_en`, `description_de` | text NULL | **Not read or written any more** (the columns stay, the code ignores them) |

Current data: 4 areas (Mathematics 2, Energy 3, Geography 4, English 5), 6
subcategories of Energy (53–58), 3 of Geography (59–61) and 8 of Mathematics
(62–69). All 17 subcategories carry `name_de`; the 13 whose English name was
missing got one on 2026-09-22 (see §17). The four areas carry a drawing in
`icon_svg`; the subcategories do not need one.

### `cards` — 265 rows, all of them bilingual (verified 2026-09-22)

| Column | Type | Notes |
| --- | --- | --- |
| `id` | int unsigned, PK, auto_increment | |
| `category_id` | int unsigned NOT NULL | FK `fk_cards_category` → `categories.id`, **ON DELETE RESTRICT**. Indexed. |
| `front` | text NOT NULL | The German question, kept in step with `front_de`. |
| `back` | text NOT NULL | The German answer, kept in step with `back_de`. |
| `front_de`, `back_de` | text NULL | The German question and answer |
| `front_en`, `back_en` | text NULL | The English question and answer |
| `map_region` | varchar(40) NULL | `DE:<id>`, `EU:<code>` or `WORLD:<code>`, or NULL. The region id has to exist in the matching SVG. |
| `is_bidirectional` | tinyint(1) NOT NULL default 0 | Practise in both directions (there is no `is_two_sided`) |

Max length enforced by the app: `CARD_MAX_TEXT_LENGTH = 2000` characters.
`front` and `back` stay because they are NOT NULL and the app still reads the
German language from them; the language columns are what the interface uses.
34 of the 56 Geography cards carry a `map_region`, the other cards do not.

### `user_card_progress` — 0 rows

| Column | Type | Notes |
| --- | --- | --- |
| `user_id` | int unsigned, PK part | FK `fk_progress_user` → `users.id`, ON DELETE CASCADE |
| `card_id` | int unsigned, PK part | FK `fk_progress_card` → `cards.id`, **ON DELETE CASCADE**, indexed |
| `state` | tinyint unsigned NOT NULL default 0 | 0 = new, 1 = learning, 2 = known |
| `due_at` | datetime NULL | When the card comes back |
| `last_reviewed_at` | datetime NULL | |
| `repetitions` | int unsigned NOT NULL default 0 | Correct answers so far |
| `lapses` | int unsigned NOT NULL default 0 | "Again" answers so far |
| `stability` | decimal(10,4) NULL | Memory strength **in days** = the interval |
| `difficulty` | decimal(6,3) NULL | 1.0 … 10.0 |

The primary key `(user_id, card_id)` is what makes the write an upsert — one row
per user and card, forever. There is **no** `ease`, `interval_days`, `status`,
`correct_count` or `wrong_count`.

---

## 5. Request flow

```
Browser
  │  1. GET index.php                     → HTML shell + translations JSON, no DB
  │  2. fetch api/categories.php          → the areas / subcategories / one category
  │     fetch api/cards.php?category_id=N → the cards + status + counts
  │     fetch api/category_icon.php?id=N  → one stored SVG (ETag, immutable)
  │  3. click "Study"
  │     fetch api/review.php?category_id=N → the ordered queue + interval previews
  │  4. answer a card
  │     POST api/review.php {action:"rate"} → service → user_card_progress
  │  5. end of session → "Done" → the list is fetched again from api/cards.php
  ▼
PHP                                  PDO (exception mode, native prepares)
  public/api/<file>.php   ── thin: validate → call a service → send JSON
  src/services/*.php      ── all business logic and every query
  src/helpers/*.php       ── stateless helpers
  src/config/database.php ── the one connection factory
```

Nothing else is in the browser's path: no CDN, no external font, no analytics.

---

## 6. The API

Every answer is JSON with the envelope from §2. HTTP codes used: `200`, `201`,
`400`, `403`, `404`, `405`, `409`, `500`.

### `GET api/health.php`
No parameters. `{"success":true,"data":{"database":"learning_app","message":"Database connection works"}}`

### `GET api/categories.php`
| Parameter | Meaning |
| --- | --- |
| *(none)* | all learning areas (`parent_id IS NULL`) |
| `parent_id=N` | the subcategories of N |
| `id=N` | one category, **plus** `delete_preview` |
| `POST` (body) | `{parent_id?, name, name_en?, name_de?, icon_svg?, icon_scale?}` → 201 |

Each row carries: `id`, `parent_id`, `name`, `icon_url` (with a content
fingerprint in the query string), `icon_scale`, `name_en`, `name_de`,
`subcategory_count`, `own_card_count`, `card_count`.

`icon_svg` is **never** part of a list answer: a drawing may be 350 KB. The row
only carries `icon_url`; the browser fetches the drawing once through
`api/category_icon.php` and keeps it.

### `PATCH api/category.php?id=N` / `DELETE api/category.php?id=N`
- PATCH body: any of `name`, `name_en`, `name_de`, `icon_svg` (empty string removes the icon), `icon_scale`.
- DELETE needs `{"confirm":true}`. The delete removes the whole subtree, in the
  order the foreign keys demand: progress rows, cards, then categories deepest
  first. `409` when the row changed in the meantime.

### `GET api/cards.php?category_id=N`
```json
{"success":true,"data":{
  "cards":[{"id":100,"category_id":50,"front":"…","back":"…","is_bidirectional":false,
            "progress":{"status":"new","state":0,"due_at":null,"last_reviewed_at":null,
                        "repetitions":0,"lapses":0,"stability":null,"difficulty":null,
                        "is_due":false,"interval_days":null}}],
  "summary":{"total":34,"new":34,"unsure":0,"known":0,"due":0},
  "has_user":false}}
```
`has_user` is false while nobody is signed in; then every card is honestly "new".

### `POST api/cards.php`
`{"category_id":50,"front":"…","back":"…","is_bidirectional":false}` → 201 with the card.

### `PATCH api/card.php?id=N` / `DELETE api/card.php?id=N`
Patch any of `front`, `back`, `is_bidirectional`. DELETE needs no body.

### `GET api/category_icon.php?id=N`
The stored SVG with `Content-Type: image/svg+xml`, `ETag`, `Cache-Control:
public, max-age=604800, immutable`, a sandboxing `Content-Security-Policy` and
`X-Content-Type-Options: nosniff`. Answers `304` when the browser sends the same
`If-None-Match`, `404` when the category has no icon.

### `GET api/review.php?category_id=N[&mode=all|difficult]`
```json
{"success":true,"data":{
  "category_id":50,"mode":"all","has_user":false,
  "summary":{"total":34,"new":34,"unsure":0,"known":0,"due":0},
  "counts":{"due":0,"new":34,"unsure":0,"known":0,"cards":34},
  "queue":[{"card_id":100,"direction":"forward","front":"…","back":"…",
            "status":"new","is_due":false,"is_bidirectional":false,
            "preview_minutes":{"again":10,"hard":1152,"good":2304,"easy":4608}}]}}
```
A bidirectional card appears **twice**: once `forward`, once `reverse` with the
sides swapped, both with the same `card_id`. The direction is session state; no
column exists for it and none is needed.

The interval previews come from the same scheduler that will store the answer, so
what the button promises is what happens.

### `POST api/review.php`
```json
{"action":"rate","category_id":50,"card_id":100,"rating":3}
{"action":"undo","category_id":50,"card_id":100,"stored":{…},"previous":{…}|null}
```
`rating` is 1 = Again, 2 = Hard, 3 = Good, 4 = Easy.
The rate answer contains the new `progress`, `status`, `interval_days`, `due_at`,
the `previous` row and the `stored` values — the last two are what the undo needs.
`403 no_user_session` while nobody is signed in; `409 undo_conflict` when the row
changed since (e.g. another tab rated the same card).

### Error codes that exist
`invalid_request_body`, `invalid_id`, `invalid_parent_id`, `invalid_category_id`,
`invalid_card_id`, `invalid_name`, `invalid_icon`, `invalid_icon_scale`,
`invalid_front`, `invalid_back`, `invalid_rating`, `invalid_action`,
`invalid_mode`, `icon_too_large`, `category_exists`, `category_not_found`,
`card_not_found`, `card_not_in_category`, `confirm_required`,
`category_delete_conflict`, `undo_conflict`, `method_not_allowed`,
`json_encoding_failed`, `no_user_session`, plus one `*_failed` /
`*_unavailable` code per endpoint for real errors.

---

## 7. The services and helpers

### `category_service.php` (20 functions)
`category_columns` / `category_columns_from_metadata` — the names of the real
columns, read from the metadata of one `SELECT * … LIMIT 0` (cheaper than
`SHOW COLUMNS`, which is kept as a fallback). Everything else about the shape of
the table hangs off this, so the code works with or without the optional columns.
`category_column_available`, `category_select_sql` (the shared SELECT with the
three counts), `find_main_categories`, `find_subcategories`, `find_category`,
`category_exists`, `category_sibling_name_exists`, `category_name_exists`,
`create_main_category`, `create_category`, `update_category`,
`category_subtree_ids`, `category_subtree_stats`, `category_delete_dependents`,
`delete_category_tree`, `normalize_category_rows`, `normalize_category_row`,
`normalize_optional_text`.

The counts in `category_select_sql`: `subcategory_count` (direct children),
`own_card_count` (cards in this row), `card_count` (own cards **plus** the cards
of the direct children). `card_count` is deliberately written as two counted
reads; an `OR` with a subquery would stop MySQL using the index on
`cards.category_id` and walk the whole index per row.

### `card_service.php`
`normalize_card_row`, `normalize_card_rows`, `find_card`, `update_card`,
`delete_card`, `create_card_translated`, `card_read_columns`,
`delete_progress_of_categories`, `delete_cards_of_categories` (the last two are
what the category delete and the importer reuse).

`find_cards`, `create_card` and `card_count_for_category` were removed on
2026-09-22: nothing called them any more (see §17).

### `review_service.php` (18 functions) — the card box
See §8. This is the only place that decides state, interval and due date.

### `stats_service.php` — removed on 2026-09-22
The file held one function, `get_overview_stats`, whose endpoint no longer
existed. The figures a page shows today come from `subcategory_count` and
`card_count` in the answer of `api/categories.php`.

### `user_service.php`
Accounts and sessions: `user_find`, `user_register`, `user_sign_in`,
`user_sign_in_session`, `user_sign_out_session`, `user_csrf_token`,
`user_csrf_valid`, `user_public_data`, `user_initials`.

### Helpers
- `html.php` — `escape_html($value)`.
- `json_response.php` — `send_json`, `send_json_success`, `send_json_error`. They
  `exit` on purpose: an endpoint must never print anything after its JSON.
- `request_input.php` — `read_json_object`, `clean_input_text`,
  `require_input_text`, `optional_input_text`, `optional_positive_id`,
  `optional_icon_scale`, `optional_flag`, `optional_svg_icon`, `require_query_id`.
  Every one of them ends the request with a JSON error when the value is not
  usable, so an endpoint reads its fields in a straight line. The size check of an
  upload lives here, before the sanitiser.
- `svg_sanitizer.php` — see §9.
- `session_user.php` — see §10.
- `translations.php` — `learning_app_translations()` and `t($locale, $key)`. A
  missing key returns the key itself, so a forgotten translation shows up on the
  page instead of becoming an empty space.

---

## 8. The learning logic (the card box)

`src/services/review_service.php` owns all of it. The browser never calculates an
interval or a due date — it only shows what came back.

### States

| `state` | Meaning | Shown as |
| --- | --- | --- |
| `0` | never learned | "New" |
| `1` | in the learning phase | "Unsure" |
| `2` | repeated successfully | "Known" (or "Unsure" again once it is due) |

Status of a card, in this order:
1. no progress row, or `state = 0` → **new**
2. `state = 1` → **unsure**
3. `state = 2` and `due_at` in the past (or NULL) → **unsure**
4. otherwise → **known**

Due = `state >= 1` and (`due_at` is NULL or `due_at <= now`).

### The calculation, per rating

`stability` **is** the interval in days. `review_calculate()` is a pure function
of the previous row, the rating and the moment.

| Rating | Counts | First time | Later | Due | `difficulty` |
| --- | --- | --- | --- | --- | --- |
| 1 Again | `lapses + 1` | 0.20 d | `× 0.20` | **now + 10 minutes** | +0.6 |
| 2 Hard | `repetitions + 1` | 0.80 d | `× 1.20` | now + interval | +0.2 |
| 3 Good | `repetitions + 1` | 1.60 d | `× 2.20` | now + interval | 0 |
| 4 Easy | `repetitions + 1` | 3.20 d | `× 3.00` | now + interval | −0.3 |

- `stability` never falls below `REVIEW_MIN_STABILITY = 0.2`.
- `difficulty` starts at 5.0 and is clamped to 1.0 … 10.0.
- A card becomes **known** only when the new interval reaches one day
  (`REVIEW_KNOWN_MIN_STABILITY = 1.0`); "Again" always leaves it in the learning
  state.
- All the numbers are constants at the top of the file — that is the whole tuning.

### The queue of one session

1. cards that are due or overdue,
2. then cards that were never learned,
3. **and only when those two are empty**, the cards that are not due yet.

`mode=difficult` returns only the cards with `state = 1` — exactly the ones that
were answered "Again" or "Hard".

### Inside a session (frontend, `learnSession`)

- Answering "Again" puts the turn back at the end of the queue, **twice at most**
  per card, so a card that is simply not there yet cannot keep the session alive
  forever.
- Every answer is stored **immediately**, never at the end of the session.
- The last answer can be taken back (ArrowLeft). The browser sends the values the
  API wrote and the values that were there before; the service reads the row again
  and refuses the undo (`409`) if anything changed in between. A card that had no
  progress before the rating gets that row deleted again — it was created by the
  rating being taken back.
- Escape asks for confirmation only when something was already stored.

---

## 9. Security model

- **PDO**: `ERRMODE_EXCEPTION`, `FETCH_ASSOC`, `EMULATE_PREPARES = false`. Because
  emulation is off, a named placeholder may appear **only once** per statement —
  a repeated `:id` raises `Invalid parameter number`.
- **Prepared statements for everything**, including ids. Where an `IN (…)` list is
  needed, the placeholders are built from the *count* of the values and every
  value is still bound.
- **No credentials in the web root.** They live in `src/config/database.local.php`
  (git-ignored); `database.example.php` is the template. `public/` never sees them.
- **Escaping**: `escape_html()` (i.e. `htmlspecialchars` with `ENT_QUOTES`, UTF-8)
  for every PHP output; `textContent` (never `innerHTML`) for every value that
  comes from the database in JavaScript.
- **SVG sanitiser** (`svg_sanitizer.php`): a real XML parser (`DOMDocument`,
  `LIBXML_NONET`), not text patterns. It refuses `<!doctype` and `<!entity` up
  front, removes blocked elements (script, foreignObject, use-with-external-href,
  …), strips every `on*` attribute and `javascript:`/`vbscript:`/`data:text/html`
  URLs, and only ever writes back `saveXML(root)`. Limit:
  `SVG_MAX_UPLOAD_BYTES = SVG_MAX_STORED_BYTES = 358400` (350 KB), checked before
  sanitising; a bigger file gets the code `icon_too_large`.
- **Errors**: the client only ever gets a generic, translated sentence. The real
  reason goes to `error_log`.
- **Sessions**: `session_user.php` is the only file that reads `$_SESSION`,
  with `httponly`, `samesite=Lax` and `use_strict_mode`. It is careful on purpose:
  a session that cannot be started is not a fatal error, it just means "nobody".

---

## 10. Current state: what works, what is missing

### Verified working

- The whole read path: start page, area page, subcategory page, icons.
- The card layer: status dots with words, distribution bar, counts, search (from
  15 cards on), the card dialog with a live preview, "Save & next card".
- The study session: flip by click / space / Enter, the four ratings by button and
  by keys 1–4, the interval under each button, the swipe (left = Again, right =
  Good), the undo, Escape, the reduced-motion fallback.
- Written by hand and machine: shell, endpoints, area page (6 rows), card list
  (34 rows), status words, bar widths, search filter, dialog preview, session
  start, flipping, refusal without a user, Escape. 0 console errors.
- The scheduler: 60+ assertions (calculation for all four ratings on a new and an
  experienced card, the limits, the status mapping, the queue order, the
  bidirectional doubling, the write path incl. upsert and undo) — all green.
  Those tests lived in `/tmp` and are **not committed**; ask if you want them in
  the repository as `tests/`.

### The login (solved on 2026-09-22)

- `public/api/auth.php` answers GET (who is signed in, plus a CSRF token) and
  POST (`sign_in`, `register`, `sign_out`). `src/services/user_service.php` holds
  the rules: name 3–100 characters, password at least 8, `password_hash()`,
  `session_regenerate_id(true)` after signing in, a 400 ms delay after a failed
  attempt, and one placeholder per value so `EMULATE_PREPARES = false` is happy.
- `users` holds one account with `email` and `password_hash`.
- Every POST needs the CSRF token that GET hands out, so a form on another page
  cannot write through the API.
- `current_user_id()` verifies that the id in the session really is in `users`, so
  a stale session cannot break the foreign key.
- `user_card_progress` is still empty: nobody has studied yet.

### Dead code and leftovers — cleaned up on 2026-09-22

Everything below was removed after a search for callers that found none (the
evidence is in §17):

- `public/api/add_category.php` (the legacy endpoint) and with it
  `create_main_category` and `category_name_exists`, which existed only for it
- `src/services/stats_service.php`
- `review_find_progress_for_cards`, `review_count_ratings`, `find_cards`,
  `create_card`, `card_count_for_category`
- `app.js`: `buildAddRow` and `learnIsOpen`
- `app.css`: 24 rules whose class names appear nowhere outside the stylesheet,
  the dead entries `.stats__value`, `.stats__label` and `.stat-pill` in two shared
  selector lists, and one stale comment about a `.has-cat` rule that does not
  exist

What is left, deliberately **not** touched:

| What | State |
| --- | --- |
| `docs/verification.md` | section 0 is current; the rest still describes an older data set |
| No card has `is_bidirectional = 1` | the doubling rule is verified by tests, not by real data |
| `--cat-default`, `--cat-reserve-rose` in the stylesheet | tokens of the category-colour mechanism, which nothing uses any more |

### Known cosmetic gap

Long backs are cut to one line with an ellipsis in the card list (`.row__back`).
Measured earlier: 24 of 32 rows in one subcategory, 34 of 34 in another (longest
back 282 characters). Deliberately unchanged because the design was not to be
touched.

---

## 11. Frontend in detail (`public/assets/js/app.js`)

One IIFE, `'use strict'`, no modules, no bundler. Four parts:

### 11.1 Configuration and state

At the top the script reads `#app-config` (a JSON block written by `index.php`)
into `config`. It contains:

- `endpoints` — `categories`, `category`, `cards`, `card`, `review`
- `storageKeys` — `lernkartei.theme`, `lernkartei.language`
- `limits` — `name: 100`, `cardText: 2000`, `iconBytes: 358400` (must match the server)
- `defaultLocale`, `supportedLocales`
- `categoryId` — the id from the query string, `null` on the start page
- `translations` — both languages, so switching needs no reload

Then `elements` (one object with every DOM lookup, ~70 entries), the
`responseCache`, and the state: `theme`, `locale`, `currentEntry`,
`currentEntryCards`, `openCards`, `openCardSummary`, `cardSearchQuery`,
`cardSearchMin = 15`, `openMenu`, `pendingDelete` (the undo window is
`UNDO_WINDOW_MS = 6500`), the `dialog*` variables, `cardSubmitCategoryId`,
`learnSession`, `learnTimer`, `tileNavigationFrame`.

### 11.2 Render flow

`render()` → `renderHome()` or `renderDetail(categoryId)`.

`renderDetail()` starts **four requests at the same time** (`Promise.all`): all
areas (for the sidebar), the category itself, its subcategories and its cards.
The sidebar, the breadcrumb, the head, the figures and the list are then built
from the answers. A subcategory renders the card tools, the card rows and the
"add card" row; an area renders its subcategory rows.

Every value that came from the database is written with `textContent`.

### 11.3 The dialog system

**One** `<dialog>` serves every form and every question of the app. `addField()`
builds the fields (`textarea` with auto-grow, text input, checkbox, file), the
translations group, the icon field and the live preview; `submitDialog()` is the
one place that talks to the API for a dialog and maps error codes back onto the
fields; `openDialog`/`closeDialog` remember the opener so the focus returns to it.
`dialogKind` is `'category' | 'card' | 'delete'`.

Deleting a card or an empty category happens immediately and offers an "Undo"
window; a category that still contains something is asked about with real numbers.

### 11.4 The card list and the study session

- `cardStatusMeta(card)` maps the server status to the word, the class and the
  tooltip. **The colour is never the only carrier of the meaning.**
- `renderCardTools(summary)` fills the bar, the counts and the legend, and decides
  whether the search field appears (from `cardSearchMin = 15` cards).
- `renderCardList(categoryId)` filters `openCards` **locally** — a search never
  builds a query and never asks the server.
- `buildCardRow()` builds the row: number, front, back, optional "both ways"
  badge, status (dot + word), and the three-dots menu as a **sibling** of the row
  button, so the menu can never open the dialog as well.
- `learnSession` holds the queue, the position, the flipped flag, the results, the
  ratings, how often each card came back, and the undo record. The functions:
  `startLearning(mode)`, `renderLearnCard()`, `buildLearnButtons(entry)`,
  `flipLearnCard()`, `rateLearnCard(rating)`, `moveToNextLearnCard()`,
  `undoLearnRating()`, `showLearnSummary()`, `closeLearnView()`,
  `askBeforeClosingLearn()`, `wireLearning()`, `wireLearnKeyboard()`,
  `wireLearnSwipe()`.

Keyboard inside a session: `Space`/`Enter` flips, `1`–`4` answer, `ArrowLeft`
undoes, `Escape` ends (with a question when something was stored). The focus stays
**on the card** while it is turned over — if it jumped to the first answer button,
the space bar would press that answer instead of turning the card back.

### 11.5 Theme, language, tile scrolling

- The theme is applied by a small inline script in `<head>` **before the first
  paint**, so the wrong theme never flashes; `applyTheme()` swaps it and persists.
- `applyLocale()` translates the static text through `data-i18n`,
  `data-i18n-label` and `data-i18n-placeholder` attributes and re-renders; the
  language switch needs no reload.
- `wireTileNavigation()` drives the tile row: scroll listener, drag, the two
  arrows, the `ResizeObserver` and a `requestAnimationFrame` collector that runs
  the update once per frame instead of once per event.

---

## 12. Stylesheet (`public/assets/css/app.css`)

4 174 lines, 482 balanced rule blocks, one file, no preprocessor.

### Design tokens (the light theme defines them, the dark theme overrides them)

| Token | Light | Dark |
| --- | --- | --- |
| `--bg` | `#EDEAE4` | `#20243A` |
| `--bg-image` | `none` | `linear-gradient(180deg, #282D44, #20243A)` |
| `--text` | `#2B2622` | `#F1F2F8` |
| `--muted` | `#6B645C` | `#BCC1D6` |
| `--line` / `--line-strong` | `rgba(43,38,34,.16)` / `.38` | `rgba(241,242,248,.20)` / `.32` |
| `--tile-bg` / `--tile-bg-hover` | `rgba(43,38,34,.06)` / `.10` | `rgba(255,255,255,.09)` / `.14` |
| `--icon-circle` | `rgba(43,38,34,.09)` | `rgba(255,255,255,.10)` |
| `--accent` / `--accent-ink` | `#2B2622` / `#EDEAE4` | `#8FD3E6` / `#1A2238` |
| `--panel` | `#FBF8F3` | `#2A3049` |
| `--panel-shadow` | `0 14px 32px rgba(43,38,34,.16)` | `0 14px 32px rgba(8,10,20,.34)` |
| `--dialog-shadow` | `0 28px 70px rgba(43,38,34,.22)` | `0 28px 70px rgba(6,8,16,.55)` |
| `--backdrop` | `rgba(43,38,34,.26)` | `rgba(8,10,20,.42)` |
| `--cat-default` | `#C6BFB2` | `#A9AFC6` |
| `--palette-1…8` | `#9BAE9F #9DA6BE #C99790 #D4A94A #92B5AD #B3A2BF #C48E6E #B2B27C` | same |
| `--icon-filter` | `brightness(0) invert(17%)` | `brightness(0) invert(1)` |
| `--font-sans` / `--font-mono` | `"Inter Tight", system-ui, …` / a system monospace stack | same |
| `--ease` | `cubic-bezier(0.2, 0.7, 0.2, 1)` | same |
| `--max-width` / `--page-margin` | `1280px` / `clamp(24px, 5vw, 80px)` | same |
| `--radius-control` / `--radius-tile` | `2px` / `12px` | same |
| `--grain-image` | inline SVG noise data URI (film grain) | same |

**Only one font file exists** (`inter-tight-latin-wght-normal.woff2`). The two
faces the design originally asked for (Instrument Sans, IBM Plex Mono) are
deliberately **not** declared any more: their files were never added, so declaring
them only produced a 404 on every page load. Monospace slots use a system stack.

Colours of the new card layer: `--status-new` (= `--muted`), `--status-unsure`,
`--status-known`, defined per theme and all above 4.5:1 contrast on the page
background (measured: 4.85 / 5.23 / 5.74 light, 4.97 / 6.65 / 6.36 dark).

### Sections

1. `@font-face` + a long comment about the missing faces
2. Design tokens (`:root` and `[data-theme="dark"]`)
3. `html`, `body`, the grain layer, the background pools (`.bg-layer`, `.bg-blob--a|b|c`,
   `filter: blur(40px)`, a 46 s drift animation)
4. Layout: `.page`, `.site-header`, `.site-footer`, `.main`, the views
5. Tiles and the area grid, the scroll line, the rows (`.row`, `.row--card`)
6. The sidebar, the detail head, the figures, the add row
7. The dialog, the menu, the feedback pill
8. State classes (`.state`, `--error`, `.notice`), skeletons, reveal animations
9. **New:** the card list of a subcategory and the study session (status dots, the
   distribution bar, the search, the card preview, `.learn*` — the full screen
   session, the flip, the four answers, the summary, the question before leaving,
   the mobile layout, `prefers-reduced-motion`)

---

## 13. Translations

- `src/helpers/translations.php` → `learning_app_translations()` returns
  `['en' => [...], 'de' => [...]]`, **201 keys each**, verified to be identical in
  both languages.
- `t($locale, $key, $replacements)` substitutes `{name}`-style placeholders.
- The frontend uses the same keys: `t(key, replacements)` looks them up in the
  `translations` object that came from `index.php`.
- Static text in the markup carries `data-i18n`, `data-i18n-label` or
  `data-i18n-placeholder`; `translateStaticText()` re-translates on a switch.

**To add a string:** add it to *both* language arrays, use it through `t()` or a
`data-i18n` attribute, and check that the two key sets still match (a small
diff of `array_keys($en)` against `array_keys($de)` is enough).

---

## 14. Performance, as it stands

Measured on this machine (WSL, 4 cores, MariaDB/MySQL on the same box).

| Measure | Value |
| --- | --- |
| `GET /api/categories.php` | ~8 ms median (was ~22 ms before the fixes below) |
| `GET /api/cards.php?category_id=50` | ~9 ms for 34 cards |
| The four parallel calls of one detail page | 38 ms fastest / 50 ms typical with four workers, 83 / 119 ms with one |
| Cold page load, uncompressed | 320 KB (31 KB HTML + 109 KB CSS + 160 KB JS + 45 KB font + 41 KB icon) |
| The same, gzipped | 112 KB |

What was already done:

- `category_columns()` reads the column names from the result metadata of one
  query instead of `SHOW COLUMNS` (1.2 ms vs 3.5 ms per request).
- `card_count` counts with two indexed reads instead of an `OR` + subquery
  (6.8 ms → 2.2 ms for the area list).
- The tile-line update runs once per animation frame and only writes styles whose
  value changed.
- `start-dev.sh` starts four workers, which matters because a detail page fires
  four API calls at once and the built-in server is single-threaded otherwise.
- `public/.htaccess` compresses text and SVG and lets the browser keep the font and
  the icons for a week. **Ignored by `php -S`, only effective under Apache.** CSS
  and JavaScript are deliberately not cached long, because the app is being
  developed.

Measured and rejected: **OPcache makes no difference here** (`opcache.enable_cli`
is on by default for nobody; enabling it changed nothing, because the time is
spent in the database, not in the parser). Not done on purpose: persistent PDO
connections (they do not reset the session state, so a request that died inside a
transaction could leak it into the next one) and a cheaper connection (the ~5 ms
per request is the floor; `host=localhost` has no Unix socket on this machine).

Remaining known cost: the animated, blurred background pools plus the three
`backdrop-filter` elements are the only continuous work the browser does. Changing
them means changing the look, which is out of bounds.

---

## 15. How to work on this project

### Start and check

```bash
./start-dev.sh                  # four workers on 127.0.0.1:8081
PORT=8082 ./start-dev.sh        # another port
```

`docs/verification.md` holds the manual pass (section 0 explains the start
command). Apache also runs in this distro but serves `/var/www/html` and has
nothing to do with this project.

### Editing safely in THIS environment

The repository lives in WSL and is opened from Windows over
`\\wsl.localhost\Ubuntu\…`. Two things have bitten repeatedly:

1. **`replace_string_in_file` can silently do nothing** on this path (it has
   reported success while the file on disk kept its old content). After every
   edit, verify on disk — `grep` in a terminal, or a tiny PHP script.
2. Editors may write **CRLF**. A shell script must be LF only, and a multi-line
   search string containing `\r\n` will never match an LF file.

The reliable pattern used throughout this project's history: write a small PHP
*patcher* into `/tmp`, make it
(a) read the file, (b) `str_replace("\r\n", "\n", …)` on both sides,
(c) assert `substr_count($text, $from) === 1` for every pair and abort before
writing if anything does not match, (d) write back only when everything matched.
Then verify with `grep`/`php -l`/`node --check`.

Also: `read_file` and `grep_search` have served stale content for these files
before; the terminal (`sed`, `grep`, `php -l`) is the ground truth.

### Recipes

**Add an endpoint** — new file in `public/api/`, with the route in a docblock at
the top. Require `database.php`, `json_response.php`, `request_input.php` and the
services it needs. Reject every method but the allowed ones with `405`. Wrap the
work in `try { … } catch (Throwable $error) { error_log(…); send_json_error(…500); }`.
Then add its URL to `endpoints` in `public/index.php`.

**Add a query** — only in `src/services/`, prepared, bound, one placeholder per
occurrence. Never in `public/`.

**Add a user-visible string** — a key in both languages in
`src/helpers/translations.php`, then `t('…')` or a `data-i18n` attribute.

**Add a style** — append to `app.css` with new class names and the tokens from
§12. Do not invent token names: a `var(--not-defined, #fallback)` silently ignores
the theme (that mistake was made once in this project and is fixed now).

### Testing without a browser framework

There is no test runner. What has worked well:

- small PHP scripts that call the services directly and assert with `===`
  (the scheduler was checked this way, 60+ assertions),
- a PHP script that posts to the endpoints with a stream context and prints the
  status and the body,
- **headless Chrome** from Windows for the DOM:
  `chrome --headless=new --virtual-time-budget=20000 --dump-dom <url>`, and for
  interactions a temporary probe page in `public/` that drives the real page in a
  same-origin iframe and writes what it finds into a `<pre>` (delete the probe
  afterwards!). This is how the card list and the whole study session were
  verified. Note that headless Chrome cannot reach `127.0.0.1:9222` from WSL, so
  the probe-page trick is the practical way in.

### Git

History is readable and each commit explains the *why*, not just the *what*:

```
254dbaf Start the development server with four workers, drop the unused endpoint
d29caba Add the card layer and the study mode
44edc5c Read the column names once and count the cards with the index
4adb6dd One-off import of the energy flashcards (161 cards, 6 subcategories)
f25c7ae Uploads up to 350 KB, and the drawings stay out of every answer
f132c01 One line per area in the dialogs, no description, a calmer subcategory page
e11d9c3 Add a command line importer for the energy flashcards
2ff9710 Document the delete procedure and the detail view
5b3ee25 Commit the detail page rework: one head, one list, one way in
7da1e9a Delete without typing a name, with a way back
13a0a18 Polish the shared dialog surface and its forms
e302c83 Fix the category delete: it now really removes the row
```

Note: some patch passes normalised a file's line endings to LF, so a diff can look
bigger than the change (compare with `git diff --ignore-all-space`).

---

## 16. If you are an AI picking this up: the short version

1. **Ask before touching the database structure or any existing data.** Never
   invent a default user, never seed data.
2. **English names everywhere**, translations only in `translations.php`.
3. **All SQL in `src/services/`**, prepared, bound, one placeholder per occurrence.
4. **All answers through the JSON envelope**, all errors generic to the client.
5. The **card box** lives in `review_service.php` and nowhere else; the browser
   only shows what it returns.
6. A **login exists** (`api/auth.php`). Still: never invent a default user and
   never create test data. `user_card_progress` is empty because nobody has
   studied yet.
7. Verify on disk after every edit, and prefer a PHP patcher with assertions over
   a direct string replacement.

---

## 17. What changed on 2026-09-22

### The two corrections (data only, no commit)

**1a - the eight Mathematics subcategories were missing.** `initial_categories.sql`
lists them, but only the four areas had ever been created. They were inserted with
`parent_id = 2` as ids 62–69:

| id | name (English) | name_de |
| ---: | --- | --- |
| 62 | Number systems | Zahlensysteme |
| 63 | Basic arithmetic | Grundrechenarten |
| 64 | Fractions and powers | Brüche und Potenzen |
| 65 | Percentages and interest | Prozent- und Zinsrechnung |
| 66 | Equations and inequalities | Gleichungen und Ungleichungen |
| 67 | Geometry and trigonometry | Geometrie und Trigonometrie |
| 68 | Statistics and probability | Statistik und Wahrscheinlichkeit |
| 69 | Differential calculus | Differentialrechnung |

`name` and `name_en` carry the English name from the SQL file, `name_de` the
German one, so both interface languages show their own wording. No card was
invented for them.

**1b - the nine subcategories without an English name.** In English mode the
interface had been falling back to `name`, which is German for these rows. Filled
in with one `UPDATE` per row:

| id | name | name_en |
| ---: | --- | --- |
| 53 | Strom und Elektrotechnik | Electricity and Electrical Engineering |
| 54 | Energieträger und Stromerzeugung | Energy Sources and Power Generation |
| 55 | Stromnetz und Übertragungsnetz | Power Grid and Transmission Grid |
| 56 | Strommarkt und Marktkommunikation | Electricity Market and Market Communication |
| 57 | Systembetrieb, Regelenergie und Redispatch | System Operation, Balancing Energy and Redispatch |
| 58 | Energiegeschichte, Mobilität und Energiewende | Energy History, Mobility and Energy Transition |
| 59 | Deutschland | Germany |
| 60 | Europa | Europe |
| 61 | Welt | World |

Both changes are rows and values only: no column, index or constraint was touched
and no card was changed.

### The energy cards were replaced from the CSV (also database only)

The 209 rows of `energie_gesamt_import_final.csv` are in the database, all of them
bilingual: 40 / 46 / 29 / 44 / 31 / 19 across the six subcategories. The 161 cards
that were still single-language were deleted and re-inserted inside one
transaction, after a check proved that `user_card_progress` held no row for them.

### Files removed (commit `0fe2a5c`)

- 29 scratch files in the root (`patch_*.php`, `norm*.php`, `_patch*.php`,
  `check_*.php`, `fix_eol.php`, `schema_report.php`, `new_grid.css`)
- `bin/import_flashcards.php` and the then empty `bin/` folder
- `database/bilingual_cards.sql`, `database/icon_svg_mediumtext.md`,
  `database/add_category_color.sql` (each documented a step that was already done)
- `database/import/` with the three card CSV files, once their content was proven
  to be in the database, plus the `*:Zone.Identifier` Windows leftovers
- `import_energy_cards.php` now **requires** `--file=`, so it cannot point at a
  file that no longer exists

### Code with no caller removed (commit `169191f`)

519 lines deleted, none added: `public/api/add_category.php` together with
`create_main_category()` and `category_name_exists()`, `stats_service.php`,
`review_find_progress_for_cards()`, `review_count_ratings()`, `find_cards()`,
`create_card()`, `card_count_for_category()`, `buildAddRow()`, `learnIsOpen()`,
24 CSS rules and 3 entries in shared selector lists.

The evidence: every project file was searched for each name, and each name
appeared in exactly one place - its own definition. The two service functions of
the legacy endpoint had exactly one caller, that endpoint, which itself had none.

### The safety copy

`/home/user/backups/learning_app_vollstaendig_20260922_162310.sql` - a full
`mysqldump` of `learning_app` (structure **and** data, all four tables, 375 185
bytes), written before anything was deleted. It sits outside the repository on
purpose and is not committed. From Windows it is reachable as
`\\wsl.localhost\Ubuntu\home\user\backups\learning_app_vollstaendig_20260922_162310.sql`.

Besides the database itself, this dump is the only place that still holds the four
category drawings.

### Two leftovers of the clean-up

This is the one commit of this list whose hash is **not** written down here, for a
simple reason: writing it down would change it. It is the newest commit, titled
*Move the CLI importer to `bin/` and drop the empty-folder placeholders*;
`git log --oneline` shows it.

**The importer moved from `database/` to `bin/`.** It was the only program in a
folder whose purpose is "SQL for the user to run by hand", so it now sits in
`bin/`, which was recreated for it. The script itself needed no change: it finds
the project root with `dirname(__DIR__)`, and from `bin/` that is the repository
root exactly as it was from `database/`. The two `require`s and every relative
`--file=` path therefore keep working unchanged. Only its docblock and its usage
text now name the new path.

**Nine `.gitkeep` files were removed.** `.github/`, `database/`, `docs/`,
`public/api/`, `public/assets/fonts/`, `public/assets/icons/`, `src/config/`,
`src/helpers/` and `src/services/` all hold real files, so the placeholders that
once kept the empty folders in git had done their job. No other `.gitkeep` was
touched, and no file was deleted besides these nine empty ones.

### The Europe map is active again, and three defects behind it

The Europe map had been switched off in the display only. Bringing it back showed
that it was not merely hidden - it was broken, and the switch also hid a data-loss
bug in the card dialog.

**`europe.svg` had no working `viewBox`.** The file wrote the attribute as
`viewbox` with a lower-case `b`, and SVG attribute names are case sensitive, so the
browser ignored it and the drawing was not scaled at all: a 1000 x 684 drawing was
drawn 1:1 into a 92 px wide box and clipped to its top-left corner (measured: the
content stuck 1556 px out of its frame, while `germany.svg` and `world.svg` were at
0). One letter was changed in the file; it is the only difference, and the file
keeps its byte size. `germany.svg` and `world.svg` were always correct.

**The picker could not show a stored region and then threw it away.**
`dialogMapValue()` returned `null` whenever the two selects could not represent the
stored value, and `null` means "no map" to the API, so the column was emptied. The
field now remembers the value the dialog was opened with (`stored`) and whether the
user changed anything (`touched`): as long as he did not, that value is handed back
unchanged. The note above the preview also names the kept region instead of looking
like a card without a map. This protects every area, not just Europe - switching a
map off can never again delete data.

**The picker no longer keeps its own list of areas.** It is built from
`config.maps` in `public/index.php`, so an area with a file is always offered and
an area without a file never is; the two lists can no longer drift apart. That is
what made the old state possible: the picker offered two areas while the
configuration had two as well, and the third one silently lost its region.

Along the way `regionsOfArea()` skips a country code that the file holds twice
(`europe.svg` draws Portugal as the mainland and as the islands). A picker must not
offer the same value twice. What is still true: marking `PT` highlights the first
of those two shapes, the mainland.
