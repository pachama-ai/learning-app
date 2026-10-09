-- ============================================================================
-- english_learning_subcategories_names_en.sql
--
-- Setzt die englischen Namen der beiden neuen Unterkategorien im englischen
-- Lernbereich von Konto 6 (selina.schneider). Die deutschen Namen sind beim
-- Import entstanden (bin/import_energy_cards.php schreibt name und name_de,
-- aber nicht name_en).
--
-- Angefasst werden genau zwei Zeilen, und nur wenn name_en noch leer ist:
--   Grund- und Aufbauwortschatz  ->  Basic and Intermediate Vocabulary
--   Stromnetze                   ->  Power Grids
--
-- Die Bedingung p.name / p.name_de / p.name_en stellt sicher, dass die
-- Kategorie direkt unter einem Lernbereich "English"/"Englisch" hängt und
-- nicht unter einer gleichnamigen Unterkategorie eines anderen Zweigs.
--
-- Ausfuehren von Hand:
--   mysql --default-character-set=utf8mb4 -u BENUTZER -p study < database/english_learning_subcategories_names_en.sql
-- ============================================================================

SET NAMES utf8mb4;

START TRANSACTION;

UPDATE categories c
  JOIN categories p ON p.id = c.parent_id
   SET c.name_en = 'Basic and Intermediate Vocabulary'
 WHERE c.owner_user_id = 6
   AND c.name = 'Grund- und Aufbauwortschatz'
   AND (c.name_en IS NULL OR TRIM(c.name_en) = '')
   AND (p.name = 'English' OR p.name_de = 'Englisch' OR p.name_en = 'English');

UPDATE categories c
  JOIN categories p ON p.id = c.parent_id
   SET c.name_en = 'Power Grids'
 WHERE c.owner_user_id = 6
   AND c.name = 'Stromnetze'
   AND (c.name_en IS NULL OR TRIM(c.name_en) = '')
   AND (p.name = 'English' OR p.name_de = 'Englisch' OR p.name_en = 'English');

-- Kontrolle: erwartet zwei Zeilen mit gefuelltem name_en.
SELECT c.id, c.parent_id, c.name, c.name_de, c.name_en
  FROM categories c
  JOIN categories p ON p.id = c.parent_id
 WHERE c.owner_user_id = 6
   AND c.name IN ('Grund- und Aufbauwortschatz', 'Stromnetze')
   AND p.name_de = 'Englisch'
 ORDER BY c.id;

COMMIT;
