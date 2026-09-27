-- ==========================================================================
-- The category a learning run belongs to
-- ==========================================================================
--
-- REVIEW THIS FIRST, THEN RUN IT BY HAND (phpMyAdmin or the mysql client).
-- Nothing in the application runs this file, and Copilot never executes a
-- structural change on its own.
--
-- Command line:
--   mysql -u <user> -p learning_app < database/add_session_category.sql
--
-- Why
--   The question "how many days in a row did I study THIS subcategory?" cannot
--   be answered today. study_sessions knows the person and the day, but not
--   where the run happened - so the tile on a subcategory page counts every day
--   this person studied anywhere, and shows the same number on every page.
--
--   One column, and it is NULLable on purpose: a run that was written before
--   this change has no category, and that stays visible as NULL instead of
--   being guessed.
--
-- What it does
--   * adds study_sessions.category_id (int unsigned, NULL, behind user_id)
--   * adds the index the per-category question needs
--   * adds a foreign key to categories (id)
--       ON DELETE SET NULL: deleting a category keeps the learning history and
--       only drops the link. CASCADE would silently delete study days, and
--       RESTRICT would stop you from deleting any category that was ever
--       studied in - neither is what this history is worth.
--
-- What it does NOT do
--   * no column is dropped or renamed, no type is changed
--   * no row is deleted, no value is overwritten
--   * no other table is touched: users, categories, cards, user_card_progress
--     and card_exercises keep their columns exactly as they are
--
-- After this file the application still works unchanged: it writes and reads the
-- new column only once its code does. Until then the streak stays the number for
-- the whole person.
--
-- --------------------------------------------------------------------------
-- Optional, and only if you agree - this is DATA, not structure
-- --------------------------------------------------------------------------
-- The single run that exists today is the test run of 2026-09-27 in
-- "Übungsaufgaben" (category 101). It has no category, so it counts for no
-- subcategory. If it should count there, run this one line as well:
--
--   UPDATE study_sessions SET category_id = 101 WHERE category_id IS NULL AND id = 7;
--
-- It changes exactly that one row and nothing else. Leave it out and the run
-- simply stays what it is: a day of learning without a place.
-- ==========================================================================

ALTER TABLE study_sessions
    ADD COLUMN category_id INT UNSIGNED NULL AFTER user_id;

ALTER TABLE study_sessions
    ADD INDEX idx_study_sessions_category (category_id);

ALTER TABLE study_sessions
    ADD CONSTRAINT fk_study_sessions_category
        FOREIGN KEY (category_id) REFERENCES categories (id)
        ON DELETE SET NULL;
