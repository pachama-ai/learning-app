-- ==========================================================================
-- Migration: category content lives in the database
-- ==========================================================================
--
-- Run this ONCE, by hand (phpMyAdmin or the mysql client). The application never
-- runs it on its own, and nothing here is executed by Copilot.
--
-- Command line:
--   mysql -u <user> -p learning_app < database/add_category_content.sql
--
-- What it does
--   Adds the columns `categories` needs so that colour, icon, bilingual name and
--   bilingual description are data instead of something the frontend guesses:
--
--     color           the hex colour the tile and the bar segment use
--     icon_svg        the sanitised SVG source of the category drawing
--     icon_scale      per drawing size correction (the wind turbine and the globe
--                     are drawn smaller than the other two, which the frontend
--                     currently compensates for with a fixed table)
--     name_en/_de     the display name per language
--     description_en/_de  the description per language
--
-- What it does NOT do
--   * no DROP, no RENAME, no TRUNCATE, no DELETE, no UPDATE of existing values
--   * the existing `name` column keeps its data and stays NOT NULL, so the
--     application keeps working while these columns are still empty
--   * no other table is touched: `users`, `cards` and `user_card_progress` are
--     left exactly as they are
--
-- Safety
--   Every added column is NULL-able (icon_scale has a default) and the whole
--   statement carries "IF NOT EXISTS", which MariaDB 10.0+ supports, so running
--   the file twice changes nothing the second time.
--
-- Rollback (only if you ever want the old state back)
--   ALTER TABLE `categories`
--       DROP COLUMN `color`, DROP COLUMN `icon_svg`, DROP COLUMN `icon_scale`,
--       DROP COLUMN `name_en`, DROP COLUMN `name_de`,
--       DROP COLUMN `description_en`, DROP COLUMN `description_de`;
-- ==========================================================================

ALTER TABLE `categories`
    ADD COLUMN IF NOT EXISTS `color` VARCHAR(7) NULL
        COMMENT 'Hex like #8E9AB0; NULL means the built-in palette is used' AFTER `name`,
    ADD COLUMN IF NOT EXISTS `icon_svg` TEXT NULL
        COMMENT 'Sanitised SVG source; NULL means the neutral fallback icon is used' AFTER `color`,
    ADD COLUMN IF NOT EXISTS `icon_scale` DECIMAL(3,2) NOT NULL DEFAULT 1.00
        COMMENT 'Size correction of the drawing, 1.00 = exactly as drawn' AFTER `icon_svg`,
    ADD COLUMN IF NOT EXISTS `name_en` VARCHAR(100) NULL
        COMMENT 'English display name; NULL means `name` is shown' AFTER `icon_scale`,
    ADD COLUMN IF NOT EXISTS `name_de` VARCHAR(100) NULL
        COMMENT 'German display name; NULL means `name` is shown' AFTER `name_en`,
    ADD COLUMN IF NOT EXISTS `description_en` TEXT NULL
        COMMENT 'English description; NULL means no description is shown' AFTER `name_de`,
    ADD COLUMN IF NOT EXISTS `description_de` TEXT NULL
        COMMENT 'German description; NULL means no description is shown' AFTER `description_en`;

-- ==========================================================================
-- Check afterwards. Expected: the four old columns plus the seven new ones,
-- and the row count of `categories` must be unchanged.
-- ==========================================================================

-- DESCRIBE `categories`;
-- SELECT COUNT(*) FROM `categories`;
-- SELECT id, parent_id, name, color, icon_scale, name_en, name_de FROM `categories` ORDER BY id;
