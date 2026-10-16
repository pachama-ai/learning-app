-- ============================================================================
-- prio_area_setup.sql
--
-- Richtet den neuen Lernbereich "PRIO" von Konto 6 (selina.schneider) fertig ein.
-- Der Bereich selbst und seine acht Unterkategorien sind am 16.10.2026 entstanden:
-- der Bereich über POST /api/categories.php, die Unterkategorien und ihre 146 Karten
-- über bin/import_energy_cards.php.
--
-- Eine Sache steht hier: die englischen Namen der Unterkategorien von PRIO. Der
-- Importeur schreibt `name` und `name_de` aus der CSV, aber `name_en` bleibt leer
-- (er kennt nur die deutsche Spalte "subcategory"). Ohne diese Namen stünde im
-- englischen Modus der deutsche Text. Gesetzt werden sie in zwei Runden: die ersten
-- acht am Vormittag des 16.10.2026, die neun weiteren am Nachmittag desselben Tages.
--
-- Die Reihenfolge der Startseite stand einmal hier und steht jetzt in
-- database/start_page_order.sql - eine Datei, eine Sache. Dort stehen alle
-- Lernbereiche in der Reihenfolge, in der ihre Kacheln erscheinen sollen
-- (PRIO zuerst, der alte Bereich Energy zuletzt).
--
-- Wiederholbar: die Namen werden nur gesetzt, wo sie noch leer sind. Der Bereich
-- wird ueber seinen Namen gefunden, nicht ueber eine feste id - eine gleichnamige
-- Unterkategorie eines anderen Zweigs kann nicht getroffen werden, weil
-- parent_id IS NULL verlangt wird.
--
-- Ausfuehren von Hand:
--   mysql --default-character-set=utf8mb4 -u BENUTZER -p study < database/prio_area_setup.sql
-- ============================================================================

SET NAMES utf8mb4;

START TRANSACTION;

-- 1. Die englischen Namen der acht Unterkategorien von PRIO.
UPDATE categories c
  JOIN categories p ON p.id = c.parent_id
   SET c.name_en = 'Atoms, molecules & chemical basics'
 WHERE c.owner_user_id = 6
   AND c.name = 'Atome, Moleküle & chemische Grundlagen'
   AND (c.name_en IS NULL OR TRIM(c.name_en) = '')
   AND p.parent_id IS NULL
   AND (p.name = 'PRIO' OR p.name_de = 'PRIO' OR p.name_en = 'PRIO');

UPDATE categories c
  JOIN categories p ON p.id = c.parent_id
   SET c.name_en = 'Electricity, magnetism & induction'
 WHERE c.owner_user_id = 6
   AND c.name = 'Elektrizität, Magnetismus & Induktion'
   AND (c.name_en IS NULL OR TRIM(c.name_en) = '')
   AND p.parent_id IS NULL
   AND (p.name = 'PRIO' OR p.name_de = 'PRIO' OR p.name_en = 'PRIO');

UPDATE categories c
  JOIN categories p ON p.id = c.parent_id
   SET c.name_en = 'Power & energy'
 WHERE c.owner_user_id = 6
   AND c.name = 'Leistung & Energie'
   AND (c.name_en IS NULL OR TRIM(c.name_en) = '')
   AND p.parent_id IS NULL
   AND (p.name = 'PRIO' OR p.name_de = 'PRIO' OR p.name_en = 'PRIO');

UPDATE categories c
  JOIN categories p ON p.id = c.parent_id
   SET c.name_en = 'Electricity generation & energy sources'
 WHERE c.owner_user_id = 6
   AND c.name = 'Stromerzeugung & Energieträger'
   AND (c.name_en IS NULL OR TRIM(c.name_en) = '')
   AND p.parent_id IS NULL
   AND (p.name = 'PRIO' OR p.name_de = 'PRIO' OR p.name_en = 'PRIO');

UPDATE categories c
  JOIN categories p ON p.id = c.parent_id
   SET c.name_en = 'Climate, greenhouse effect & emissions'
 WHERE c.owner_user_id = 6
   AND c.name = 'Klima, Treibhauseffekt & Emissionen'
   AND (c.name_en IS NULL OR TRIM(c.name_en) = '')
   AND p.parent_id IS NULL
   AND (p.name = 'PRIO' OR p.name_de = 'PRIO' OR p.name_en = 'PRIO');

UPDATE categories c
  JOIN categories p ON p.id = c.parent_id
   SET c.name_en = 'Sector coupling & heat transition'
 WHERE c.owner_user_id = 6
   AND c.name = 'Sektorkopplung & Wärmewende'
   AND (c.name_en IS NULL OR TRIM(c.name_en) = '')
   AND p.parent_id IS NULL
   AND (p.name = 'PRIO' OR p.name_de = 'PRIO' OR p.name_en = 'PRIO');

UPDATE categories c
  JOIN categories p ON p.id = c.parent_id
   SET c.name_en = 'Storage & flexibility'
 WHERE c.owner_user_id = 6
   AND c.name = 'Speicher & Flexibilität'
   AND (c.name_en IS NULL OR TRIM(c.name_en) = '')
   AND p.parent_id IS NULL
   AND (p.name = 'PRIO' OR p.name_de = 'PRIO' OR p.name_en = 'PRIO');

UPDATE categories c
  JOIN categories p ON p.id = c.parent_id
   SET c.name_en = 'Grid operation & system stability'
 WHERE c.owner_user_id = 6
   AND c.name = 'Netzbetrieb & Systemstabilität'
   AND (c.name_en IS NULL OR TRIM(c.name_en) = '')
   AND p.parent_id IS NULL
   AND (p.name = 'PRIO' OR p.name_de = 'PRIO' OR p.name_en = 'PRIO');

-- Die zweite Runde (16.10.2026, nachmittags): neun Unterkategorien aus den Dateien
-- prio_stromnetz_netzinfrastruktur, prio_strommarkt_energiehandel,
-- prio_strompreise_tarife, prio_netzentgelte_regulierung,
-- prio_felder_induktion_blindleistung und
-- prio_energiegesetze_eu_institutionen_unternehmen.
UPDATE categories c
  JOIN categories p ON p.id = c.parent_id
   SET c.name_en = 'Power grid & grid infrastructure'
 WHERE c.owner_user_id = 6
   AND c.name = 'Stromnetz & Netzinfrastruktur'
   AND (c.name_en IS NULL OR TRIM(c.name_en) = '')
   AND p.parent_id IS NULL
   AND (p.name = 'PRIO' OR p.name_de = 'PRIO' OR p.name_en = 'PRIO');

UPDATE categories c
  JOIN categories p ON p.id = c.parent_id
   SET c.name_en = 'Electricity market & energy trading'
 WHERE c.owner_user_id = 6
   AND c.name = 'Strommarkt & Energiehandel'
   AND (c.name_en IS NULL OR TRIM(c.name_en) = '')
   AND p.parent_id IS NULL
   AND (p.name = 'PRIO' OR p.name_de = 'PRIO' OR p.name_en = 'PRIO');

UPDATE categories c
  JOIN categories p ON p.id = c.parent_id
   SET c.name_en = 'Electricity prices & tariffs'
 WHERE c.owner_user_id = 6
   AND c.name = 'Strompreise & Tarife'
   AND (c.name_en IS NULL OR TRIM(c.name_en) = '')
   AND p.parent_id IS NULL
   AND (p.name = 'PRIO' OR p.name_de = 'PRIO' OR p.name_en = 'PRIO');

UPDATE categories c
  JOIN categories p ON p.id = c.parent_id
   SET c.name_en = 'Grid fees & regulation'
 WHERE c.owner_user_id = 6
   AND c.name = 'Netzentgelte & Regulierung'
   AND (c.name_en IS NULL OR TRIM(c.name_en) = '')
   AND p.parent_id IS NULL
   AND (p.name = 'PRIO' OR p.name_de = 'PRIO' OR p.name_en = 'PRIO');

UPDATE categories c
  JOIN categories p ON p.id = c.parent_id
   SET c.name_en = 'Fields, induction & reactive power'
 WHERE c.owner_user_id = 6
   AND c.name = 'Felder, Induktion & Blindleistung'
   AND (c.name_en IS NULL OR TRIM(c.name_en) = '')
   AND p.parent_id IS NULL
   AND (p.name = 'PRIO' OR p.name_de = 'PRIO' OR p.name_en = 'PRIO');

UPDATE categories c
  JOIN categories p ON p.id = c.parent_id
   SET c.name_en = 'Laws & energy policy'
 WHERE c.owner_user_id = 6
   AND c.name = 'Gesetze & Energiepolitik'
   AND (c.name_en IS NULL OR TRIM(c.name_en) = '')
   AND p.parent_id IS NULL
   AND (p.name = 'PRIO' OR p.name_de = 'PRIO' OR p.name_en = 'PRIO');

UPDATE categories c
  JOIN categories p ON p.id = c.parent_id
   SET c.name_en = 'EU & international'
 WHERE c.owner_user_id = 6
   AND c.name = 'EU & International'
   AND (c.name_en IS NULL OR TRIM(c.name_en) = '')
   AND p.parent_id IS NULL
   AND (p.name = 'PRIO' OR p.name_de = 'PRIO' OR p.name_en = 'PRIO');

UPDATE categories c
  JOIN categories p ON p.id = c.parent_id
   SET c.name_en = 'Authorities & institutions'
 WHERE c.owner_user_id = 6
   AND c.name = 'Behörden & Institutionen'
   AND (c.name_en IS NULL OR TRIM(c.name_en) = '')
   AND p.parent_id IS NULL
   AND (p.name = 'PRIO' OR p.name_de = 'PRIO' OR p.name_en = 'PRIO');

UPDATE categories c
  JOIN categories p ON p.id = c.parent_id
   SET c.name_en = 'Energy companies'
 WHERE c.owner_user_id = 6
   AND c.name = 'Energieunternehmen'
   AND (c.name_en IS NULL OR TRIM(c.name_en) = '')
   AND p.parent_id IS NULL
   AND (p.name = 'PRIO' OR p.name_de = 'PRIO' OR p.name_en = 'PRIO');

-- Die achte Datei der zweiten Runde (prio_energiedaten_strommix_energiewende) lag
-- beim ersten Lauf noch nicht im Projekt und kam am 16.10.2026 nachmittags dazu.
UPDATE categories c
  JOIN categories p ON p.id = c.parent_id
   SET c.name_en = 'Energy data, power mix & energy transition'
 WHERE c.owner_user_id = 6
   AND c.name = 'Energiedaten, Strommix & Energiewende'
   AND (c.name_en IS NULL OR TRIM(c.name_en) = '')
   AND p.parent_id IS NULL
   AND (p.name = 'PRIO' OR p.name_de = 'PRIO' OR p.name_en = 'PRIO');

-- Kontrolle: achtzehn Zeilen mit gefuelltem name_en.
SELECT c.id AS unterkategorie_id, c.name, c.name_de, c.name_en,
       (SELECT COUNT(*) FROM cards k WHERE k.category_id = c.id) AS karten
  FROM categories c
  JOIN categories p ON p.id = c.parent_id
 WHERE c.owner_user_id = 6
   AND p.parent_id IS NULL
   AND p.name = 'PRIO'
 ORDER BY c.id;

COMMIT;
