# Migrations in `database/`

Every file in this folder changes the **structure** of the database (one of them
also changes data, see the table). Copilot never runs them: they are handed to the
user, who runs them by hand in phpMyAdmin (database `learning_app` → tab "SQL" →
paste → Go) or with the `mysql` client.

They are kept as the schema history, so that a fresh database can be rebuilt and
the reason for every column can still be found later. Running one of them twice is
harmless: each file either carries `IF NOT EXISTS` or the server answers with a
duplicate warning and changes nothing.

## The migration files, in the order they were run

| # | File | Date | What it did |
| ---: | --- | --- | --- |
| 1 | `initial_categories.sql` | before 2026-09-22 | Created the four learning areas (Mathematics, Energy, Geography, English) and the eight Mathematics subcategories. Pure `INSERT`, idempotent. |
| 2 | `add_category_content.sql` | before 2026-09-22 | Added the seven content columns to `categories`: `color`, `icon_svg` (`TEXT`), `icon_scale`, `name_en`, `name_de`, `description_en`, `description_de`. |
| 3 | `add_user_auth.sql` | 2026-09-22 | Added `email` (varchar 190), `password_hash` (varchar 255) and `created_at` (datetime, default `CURRENT_TIMESTAMP`) to `users`, plus the unique keys `uniq_users_name` and `uniq_users_email`. This is what makes signing in possible. |
| 4 | (no file) | 2026-09-22 | `icon_svg` was changed from `TEXT` to `MEDIUMTEXT` by hand, because an uploaded drawing may be up to 350 KB and `TEXT` holds only 64 KB. The statement was `ALTER TABLE categories MODIFY COLUMN icon_svg MEDIUMTEXT NULL`; it was recorded in what was `database/icon_svg_mediumtext.md` until that note was deleted as finished documentation. |
| 5 | `add_category_owner.sql` | 2026-09-26 | Added `categories.owner_user_id` with a foreign key to `users(id)` and the index `idx_categories_owner`. Since then every category belongs to one account. |
| 6 | `assign_category_owner.sql` | 2026-09-26 | **Data change, not structure:** filled `owner_user_id` for all existing categories. The only file in this folder that changes rows instead of columns. |
| 7 | `add_study_sessions.sql` | 2026-09-27 | Created the table `study_sessions` (start, end, counters) as the basis of the day streak. |
| 8 | `add_card_exercises.sql` | 2026-09-27 | Created the table `card_exercises`: at most one exercise per card. |
| 9 | `add_exercise_params.sql` | 2026-09-27 | Added `card_exercises.exercise_params` (JSON) for the numbers the exercise is built from. |
| 10 | `add_session_category.sql` | 2026-09-27 | Added `study_sessions.category_id` with an index and a foreign key (`ON DELETE SET NULL`), so the streak can also be calculated per subcategory. |

Order matters only in one way: `initial_categories.sql` expects `categories` to
exist. Everything else touches a different table or only adds a column.

## Row changes that are not migrations

These changed **data**, not the structure. That is why no extra SQL file exists for
them - the data belongs in the database, and the safety copy below holds it:

| Date | Change |
| --- | --- |
| 2026-09-22 | The eight Mathematics subcategories from `initial_categories.sql` were actually created (ids 62-69) with `name`/`name_en` in English and `name_de` in German. Until then only the four areas existed, so Mathematics was empty. |
| 2026-09-22 | The nine Energy and Geography subcategories without an English name (ids 53-61) got `name_en`, so the English interface no longer falls back to the German name. |
| 2026-09-22 | The 209 energy cards were replaced by the content of `energie_gesamt_import_final.csv` in one transaction; all of them are bilingual now. |

## If a database has to be rebuilt from scratch

1. Create the database `learning_app` (utf8mb4).
2. Create the four tables `users`, `categories`, `cards`, `user_card_progress` with
   the columns the application expects.
3. Run `initial_categories.sql`, `add_category_content.sql`,
   `add_category_owner.sql`, `add_study_sessions.sql`,
   `add_card_exercises.sql`, `add_exercise_params.sql` and
   `add_session_category.sql` in this order. (`add_user_auth.sql` only makes sense
   after the table `users` exists; `assign_category_owner.sql` is a one-off data
   change and is not needed for a fresh database.)
4. Import `/home/user/backups/learning_app_vollstaendig_20260922_162310.sql`.
   That dump contains the structure **and** all rows - including the five category
   drawings in `categories.icon_svg`, which exist nowhere else. Note that the dump
   is from 2026-09-22 and therefore does not contain the later structure changes
   (items 5 to 10 above).

## The CSV files in `database/import/`

That folder holds **23** CSV files, and all 23 are tracked in the repository. They
are the source of the imported cards: the importer scripts in `bin/` read them and
write the cards into the database.

They come in two shapes, and the difference matters when you look for "the" source
of a topic:

* **The import format** - the header is
  `category,front,back,front_de,back_de,front_en,back_en,is_bidirectional`. This is
  the format `bin/import_cards_csv.php` reads, so these are the files that were
  actually imported.

* **The cleaned source lists** - the header is `English;Deutsch;Wortart;Level`. They
  carry two extra columns (word type and level) that are not part of the import
  format, and they are what the import files were built from.

For eight topics both shapes exist side by side, so a file and its `_bereinigt`
counterpart are **not** two versions of the same thing but the two steps of one
path - list first, then the converted import file:

| Cleaned source list | Import file that was read |
| --- | --- |
| `b1_vokabelliste_500_bereinigt.csv` | `b1_vokabelliste.csv` |
| `b2_vokabelliste_500_bereinigt.csv` | `b2_vokabelliste.csv` |
| `c1_vokabelliste_500_bereinigt.csv` | `c1_vokabelliste.csv` |
| `c2_vokabelliste_500_bereinigt.csv` | `c2_vokabelliste.csv` |
| `englische_redewendungen_200_bereinigt.csv` | `redewendungen.csv` |
| `unregelmaessige_verben_gesamt_bereinigt.csv` | `unregelmaessige_verben.csv` |
| `tennet_energie_fachvokabular_mit_kategorien_bereinigt.csv` | `energie_fachvokabular.csv` |
| `englische_zeiten_uebersicht_bereinigt.csv` | `zeitformen.csv` |

The other seven files stand alone and are already in the import format:
`betriebssysteme.csv`, `datenbank_konzepte.csv`, `excel_funktionen.csv`,
`git_github.csv`, `hardware_netzwerke.csv`, `linux_wsl.csv` and
`sql_grundlagen.csv`.

One note for a rebuild: the row-change table above names
`energie_gesamt_import_final.csv`, and **that file is not in this folder any more**.
Its content is in the database, and for a fresh database the cards come from the
import files listed here or from the dump described below.

## Tables that no file here creates

There is no `CREATE TABLE` for `users`, `cards` and `user_card_progress`: those were
made by hand in phpMyAdmin and are the oldest part of the schema. The same goes for
the columns `cards.front_de`, `back_de`, `front_en`, `back_en` and `map_region`,
which were added by hand as well.
