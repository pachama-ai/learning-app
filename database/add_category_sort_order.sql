-- ============================================================================
-- add_category_sort_order.sql - eine Reihenfolge fuer die Kategorien
--
-- Fuegt der Tabelle `categories` GENAU EINE Spalte hinzu: `sort_order`. Sonst
-- passiert nichts. Es wird keine bestehende Spalte geaendert, kein Index und kein
-- Fremdschluessel angefasst, und es wird nichts geloescht.
--
-- WOFUER
--
-- Die Liste der Unterkategorien war bisher nach `id` sortiert - die Reihenfolge hing
-- also daran, wann eine Kategorie angelegt wurde. Mit `sort_order` laesst sich eine
-- Kategorie nach oben stellen, ohne sie neu anzulegen:
--
--     kleinere Zahl -> weiter oben
--     1000 (Vorgabe) -> die normale Reihenfolge, entschieden durch die id
--     gleicher Wert -> es entscheidet die id
--
-- Damit steht "Grund- und Aufbauwortschatz" (Konto 6, unter "English") ganz oben in
-- der Liste; alle anderen Unterkategorien bleiben in ihrer bisherigen Reihenfolge.
--
-- OHNE DIESE MIGRATION
--
-- Die Anwendung liest die Spalte nur, wenn es sie wirklich gibt (siehe
-- category_order_sql in src/services/category_service.php). Laesst man diese Datei
-- weg, bleibt die Reihenfolge also genau wie vorher - nichts geht kaputt.
--
-- AUSFUEHREN VON HAND
--
--   mysql --default-character-set=utf8mb4 -u BENUTZER -p study < database/add_category_sort_order.sql
--
-- In phpMyAdmin: die Datenbank `study` waehlen, Reiter "SQL", den Inhalt dieser Datei
-- einfuegen und ausfuehren. Die Datei kann gefahrlos zweimal laufen: "IF NOT EXISTS"
-- beim Anlegen und die feste Bedingung beim UPDATE machen sie wiederholbar.
-- ============================================================================

SET NAMES utf8mb4;

-- 1. Die eine neue Spalte. Vorhandene Zeilen bekommen die Vorgabe 1000, das heißt
--    "normale Reihenfolge"; die id entscheidet dann unter ihnen.
ALTER TABLE `categories`
  ADD COLUMN IF NOT EXISTS `sort_order` int NOT NULL DEFAULT 1000
    COMMENT 'Reihenfolge unter Geschwistern, kleinere Zahl zuerst; 1000 = normale Reihenfolge nach id';

-- 2. "Grund- und Aufbauwortschatz" ganz nach oben.
UPDATE `categories`
   SET `sort_order` = 10
 WHERE `owner_user_id` = 6
   AND `parent_id` = 5
   AND `name` = 'Grund- und Aufbauwortschatz';

-- 3. Kontrolle: erwartet "Grund- und Aufbauwortschatz" als erste Zeile. Die uebrigen
--    Unterkategorien behalten die Reihenfolge ihrer id.
SELECT id, parent_id, name, sort_order
  FROM `categories`
 WHERE `parent_id` = 5
   AND `owner_user_id` = 6
 ORDER BY `sort_order` ASC, `id` ASC;
