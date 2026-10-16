-- ============================================================================
-- start_page_order.sql
--
-- Die Reihenfolge der Lernbereiche auf der Startseite (Konto 6, selina.schneider).
--
-- Grundlage: categories.sort_order. Kleinere Zahl = weiter vorne; 1000 ist der
-- Standardwert, und bei gleicher Zahl entscheidet die id. Gelesen wird das in
-- src/services/category_service.php (category_order_sql) und gilt fuer die Kacheln
-- UND fuer die Leiste "Themengebiete" links, weil beide dieselbe Liste benutzen.
--
-- Stand 16.10.2026:
--
--     10  PRIO          der neue Bereich, ganz vorne
--     20  English       direkt daneben
--     30  Mathematics
--     40  Geography
--     50  Informatik
--   2000  Energy        der alte Bereich, ganz hinten
--
-- Die Abstaende von 10 lassen Platz, um spaeter etwas dazwischen zu schieben. Die
-- Bereiche des anderen Kontos (id 167 und 183, Nutzer 8) bleiben unberuehrt: jede
-- Person hat ihre eigene Startseite.
--
-- Wiederholbar: jeder Lauf schreibt dieselben Zahlen. Rueckgaengig machen heisst,
-- alle sechs Zahlen auf 1000 zu setzen - dann gilt wieder die Reihenfolge nach id
-- (2, 3, 4, 5, 119, 224).
--
-- Ausfuehren von Hand:
--   mysql --default-character-set=utf8mb4 -u BENUTZER -p study < database/start_page_order.sql
-- ============================================================================

SET NAMES utf8mb4;

START TRANSACTION;

-- Angesteuert wird ueber die id: ein Name kann sich aendern, eine id nicht, und
-- die Bedingung "parent_id IS NULL" stellt sicher, dass nur Lernbereiche getroffen
-- werden und keine gleichnamige Unterkategorie.
UPDATE categories SET sort_order = 10   WHERE owner_user_id = 6 AND parent_id IS NULL AND id = 224; -- PRIO
UPDATE categories SET sort_order = 20   WHERE owner_user_id = 6 AND parent_id IS NULL AND id = 5;   -- English
UPDATE categories SET sort_order = 30   WHERE owner_user_id = 6 AND parent_id IS NULL AND id = 2;   -- Mathematics
UPDATE categories SET sort_order = 40   WHERE owner_user_id = 6 AND parent_id IS NULL AND id = 4;   -- Geography
UPDATE categories SET sort_order = 50   WHERE owner_user_id = 6 AND parent_id IS NULL AND id = 119; -- Informatik
UPDATE categories SET sort_order = 2000 WHERE owner_user_id = 6 AND parent_id IS NULL AND id = 3;   -- Energy (der alte Bereich)

-- Kontrolle: die Startseite in Anzeigereihenfolge - genau so erscheinen die Kacheln.
SELECT c.id, c.name, c.name_de, c.sort_order
  FROM categories c
 WHERE c.owner_user_id = 6
   AND c.parent_id IS NULL
 ORDER BY c.sort_order ASC, c.id ASC;

COMMIT;
