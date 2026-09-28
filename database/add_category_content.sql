-- ==========================================================================
-- Migration: der Inhalt einer Kategorie liegt in der Datenbank
-- ==========================================================================
--
-- EINMAL ausführen, von Hand (phpMyAdmin oder der mysql-Client). Die Anwendung
-- führt sie nie von selbst aus, und Copilot führt hier nichts aus.
--
-- Auf der Kommandozeile:
--   mysql -u <Benutzer> -p learning_app < database/add_category_content.sql
--
-- Was sie tut
--   Fügt die Spalten hinzu, die `categories` braucht, damit Farbe, Symbol,
--   zweisprachiger Name und zweisprachige Beschreibung Daten sind und nicht
--   etwas, das die Oberfläche errät:
--
--     color           die Hex-Farbe, die die Kachel und das Bandsegment benutzen
--     icon_svg        der entschärfte SVG-Quelltext der Kategoriezeichnung
--     icon_scale      Größenkorrektur je Zeichnung (die Windräder und der Globus
--                     sind kleiner gezeichnet als die anderen zwei, was die
--                     Oberfläche im Moment mit einer festen Tabelle ausgleicht)
--     name_en/_de     der Anzeigename je Sprache
--     description_en/_de  die Beschreibung je Sprache
--
-- Was sie NICHT tut
--   * kein DROP, kein RENAME, kein TRUNCATE, kein DELETE, kein UPDATE
--     vorhandener Werte
--   * die vorhandene Spalte `name` behält ihre Daten und bleibt NOT NULL, die
--     Anwendung arbeitet also weiter, solange diese Spalten noch leer sind
--   * keine andere Tabelle wird angefasst: `users`, `cards` und
--     `user_card_progress` bleiben genau, wie sie sind
--
-- Sicherheit
--   Jede hinzugefügte Spalte darf NULL sein (icon_scale hat eine Vorgabe), und
--   die ganze Anweisung trägt "IF NOT EXISTS", was MariaDB 10.0+ unterstützt,
--   ein zweiter Lauf ändert beim zweiten Mal also nichts.
--
-- Zurücknehmen (nur falls du den alten Zustand je zurückhaben willst)
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
-- Danach nachsehen. Erwartet: die vier alten Spalten plus die sieben neuen,
-- und die Zeilenzahl von `categories` muss unverändert sein.
-- ==========================================================================

-- DESCRIBE `categories`;
-- SELECT COUNT(*) FROM `categories`;
-- SELECT id, parent_id, name, color, icon_scale, name_en, name_de FROM `categories` ORDER BY id;
