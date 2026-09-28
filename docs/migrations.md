# The database structure, its history and the CSV files in `database/`

## The structure lives in one file now: `database/schema.sql`

`database/schema.sql` is the complete description of the database `learning_app`: every
table, column, type, default, index and foreign key. It was read out of the running
database itself (`SHOW CREATE TABLE`), not put together from the earlier migration
steps - there are columns that were added by hand in phpMyAdmin and appear in none of
those steps, so a file assembled from them would be incomplete.

To build a fresh database:

1. Create the database (utf8mb4 / utf8mb4_unicode_ci).
2. Run `database/schema.sql` on it - in phpMyAdmin: select the database, tab "SQL",
   paste, Go. Or with the client:
   `mysql -u BENUTZER -p learning_app < database/schema.sql`.
3. Bring in the contents: the categories and the cards. Either from the dump below or
   through the import.

`schema.sql` only creates structure. Every statement carries `IF NOT EXISTS` and there
is no `DROP` and no `ALTER`, so running it on a database that already exists changes
nothing.

## The earlier migration steps (history)

Until 2026-09-28 there was one file per step in `database/`. They have been replaced by
`schema.sql`. They are listed here because the reason for a column is often easier to
find in the step that introduced it than in the finished schema - and because the
paragraphs further down still refer to them.

| # | Step | Date | What it did |
| ---: | --- | --- | --- |
| 1 | `initial_categories.sql` | before 2026-09-22 | Created the four learning areas (Mathematics, Energy, Geography, English) and the eight Mathematics subcategories. Pure `INSERT`, idempotent. |
| 2 | `add_category_content.sql` | before 2026-09-22 | Added the seven content columns to `categories`: `color`, `icon_svg`, `icon_scale`, `name_en`, `name_de`, `description_en`, `description_de`. |
| 3 | `add_user_auth.sql` | 2026-09-22 | Added `email` (varchar 190), `password_hash` (varchar 255) and `created_at` (datetime, default `CURRENT_TIMESTAMP`) to `users`, plus the unique keys `uniq_users_name` and `uniq_users_email`. This is what makes signing in possible. |
| 4 | (no file) | 2026-09-22 | `icon_svg` was changed from `TEXT` to `MEDIUMTEXT` by hand, because an uploaded drawing may be up to 350 KB and `TEXT` holds only 64 KB. The statement was `ALTER TABLE categories MODIFY COLUMN icon_svg MEDIUMTEXT NULL`. `schema.sql` carries the correct type. |
| 5 | `add_category_owner.sql` | 2026-09-26 | Added `categories.owner_user_id` with a foreign key to `users(id)` and the index `idx_categories_owner`. Since then every category belongs to one account. |
| 6 | `assign_category_owner.sql` | 2026-09-26 | **Data change, not structure:** filled `owner_user_id` for all existing categories. |
| 7 | `add_study_sessions.sql` | 2026-09-27 | Created the table `study_sessions` (start, end, counters) as the basis of the day streak. |
| 8 | `add_card_exercises.sql` | 2026-09-27 | Created the table `card_exercises`: at most one exercise per card. |
| 9 | `add_exercise_params.sql` | 2026-09-27 | Added `card_exercises.exercise_params` (JSON) for the numbers the exercise is built from. |
| 10 | `add_session_category.sql` | 2026-09-27 | Added `study_sessions.category_id` with an index and a foreign key (`ON DELETE SET NULL`), so the streak can also be calculated per subcategory. |

Every one of these steps is in the Git history; the file was deleted in the commit that
introduced `schema.sql`.

## Row changes that are not migrations

These changed **data**, not the structure. That is why no extra SQL file exists for
them - the data belongs in the database, and the dump below holds it:

| Date | Change |
| --- | --- |
| 2026-09-22 | The eight Mathematics subcategories were created with `name`/`name_en` in English and `name_de` in German. Until then only the four areas existed, so Mathematics was empty. |
| 2026-09-22 | The nine Energy and Geography subcategories without an English name (ids 53-61) got `name_en`, so the English interface no longer falls back to the German name. |
| 2026-09-22 | The 209 energy cards were replaced by the content of `energie_gesamt_import_final.csv` in one transaction; all of them are bilingual now. |
| 2026-09-28 | The eight English-named Mathematics subcategories were replaced by German-named ones (ids 79-93 and 101). The English names (`Number systems`, `Basic arithmetic`, ...) no longer exist, so the old `initial_categories.sql` no longer describes the contents of `categories`. |

## Rebuilding a database from scratch

1. Create the database `learning_app` (utf8mb4 / utf8mb4_unicode_ci).
2. Run `database/schema.sql`. That creates all six tables with their indexes and
   foreign keys.
3. Import the contents. The dump
   `/home/user/backups/learning_app_vollstaendig_20260922_162310.sql` holds structure
   **and** all rows, including the five category drawings in `categories.icon_svg`,
   which exist nowhere else. It is from 2026-09-22, so it does not contain the later
   steps (items 5 to 10 above) - run `schema.sql` first, then the dump, and check the
   result.

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

## Tables that no step ever created

`users`, `categories`, `cards`, `user_card_progress` and also the columns
`cards.front_de`, `back_de`, `front_en`, `back_en`, `map_region` and `users.role` were
made by hand in phpMyAdmin and appear in no migration step. `database/schema.sql`
carries them all, so a fresh database gets them from there.
