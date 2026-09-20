-- ============================================================================
-- Learning App - add an optional color to categories
--
-- Adds one nullable column: `categories.color` (VARCHAR(7), e.g. "#8E9AB0").
--
-- This file is NOT executed automatically. Run it manually in phpMyAdmin:
--   database `learning_app` -> tab "SQL" -> paste -> Go.
--
-- It changes nothing else: no other table, no column is renamed or removed and
-- no row is touched. All existing data stays exactly as it is.
--
-- The application works without this column. When `color` is missing or empty
-- or not a "#RRGGBB" value, the frontend falls back to its own palette, so this
-- migration is optional. Without it every category simply gets a palette colour.
--
-- Run it only once. MySQL reports "Duplicate column name 'color'" on a second
-- run, which is harmless.
-- ============================================================================

ALTER TABLE `categories`
    ADD COLUMN `color` VARCHAR(7) NULL DEFAULT NULL AFTER `name`;
