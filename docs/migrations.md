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

That folder holds 19 CSV files. They are the source of the imported cards and they
**are** part of the repository. The importer scripts in `bin/` read them and write
the cards into the database.

## Tables that no file here creates

There is no `CREATE TABLE` for `users`, `cards` and `user_card_progress`: those were
made by hand in phpMyAdmin and are the oldest part of the schema. The same goes for
the columns `cards.front_de`, `back_de`, `front_en`, `back_en` and `map_region`,
which were added by hand as well.
