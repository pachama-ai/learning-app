START TRANSACTION;

-- Mathematik
UPDATE categories SET name_en = 'Multiplication tables and division' WHERE id = 79 AND owner_user_id = 6 AND name_de = 'Einmaleins und Division' AND (name_en IS NULL OR TRIM(name_en) = '');
UPDATE categories SET name_en = 'Fractions and decimals' WHERE id = 80 AND owner_user_id = 6 AND name_de = 'Brüche und Dezimalzahlen' AND (name_en IS NULL OR TRIM(name_en) = '');
UPDATE categories SET name_en = 'Negative numbers and rules for parentheses' WHERE id = 81 AND owner_user_id = 6 AND name_de = 'Negative Zahlen und Klammerregeln' AND (name_en IS NULL OR TRIM(name_en) = '');
UPDATE categories SET name_en = 'Powers and scientific notation' WHERE id = 82 AND owner_user_id = 6 AND name_de = 'Potenzen und wissenschaftliche Schreibweise' AND (name_en IS NULL OR TRIM(name_en) = '');
UPDATE categories SET name_en = 'Rearranging equations' WHERE id = 83 AND owner_user_id = 6 AND name_de = 'Gleichungen umstellen' AND (name_en IS NULL OR TRIM(name_en) = '');
UPDATE categories SET name_en = 'Ratios and the rule of three' WHERE id = 84 AND owner_user_id = 6 AND name_de = 'Verhältnisse und Dreisatz' AND (name_en IS NULL OR TRIM(name_en) = '');
UPDATE categories SET name_en = 'Percentages' WHERE id = 85 AND owner_user_id = 6 AND name_de = 'Prozentrechnung' AND (name_en IS NULL OR TRIM(name_en) = '');
UPDATE categories SET name_en = 'Units and conversions' WHERE id = 86 AND owner_user_id = 6 AND name_de = 'Einheiten und Umrechnungen' AND (name_en IS NULL OR TRIM(name_en) = '');
UPDATE categories SET name_en = 'Energy formulas' WHERE id = 87 AND owner_user_id = 6 AND name_de = 'Energieformeln' AND (name_en IS NULL OR TRIM(name_en) = '');
UPDATE categories SET name_en = 'Efficiency, full-load hours and capacity factor' WHERE id = 88 AND owner_user_id = 6 AND name_de = 'Wirkungsgrad, Volllaststunden und Kapazitätsfaktor' AND (name_en IS NULL OR TRIM(name_en) = '');
UPDATE categories SET name_en = 'Quarter-hour values and time intervals' WHERE id = 89 AND owner_user_id = 6 AND name_de = 'Viertelstundenwerte und Zeitintervalle' AND (name_en IS NULL OR TRIM(name_en) = '');
UPDATE categories SET name_en = 'Averages' WHERE id = 90 AND owner_user_id = 6 AND name_de = 'Mittelwerte' AND (name_en IS NULL OR TRIM(name_en) = '');
UPDATE categories SET name_en = 'Basic statistical concepts' WHERE id = 91 AND owner_user_id = 6 AND name_de = 'Statistik-Grundbegriffe' AND (name_en IS NULL OR TRIM(name_en) = '');
UPDATE categories SET name_en = 'Reading charts and tables' WHERE id = 92 AND owner_user_id = 6 AND name_de = 'Diagramme und Tabellen lesen' AND (name_en IS NULL OR TRIM(name_en) = '');
UPDATE categories SET name_en = 'Pythagorean theorem' WHERE id = 93 AND owner_user_id = 6 AND name_de = 'Satz des Pythagoras' AND (name_en IS NULL OR TRIM(name_en) = '');

-- Geografie
UPDATE categories SET name_en = 'Germany: federal states and regions' WHERE id = 70 AND owner_user_id = 6 AND name_de = 'Deutschland: Bundesländer und Räume' AND (name_en IS NULL OR TRIM(name_en) = '');
UPDATE categories SET name_en = 'Germany: landscapes, rivers and coasts' WHERE id = 71 AND owner_user_id = 6 AND name_de = 'Deutschland: Landschaften, Flüsse und Küsten' AND (name_en IS NULL OR TRIM(name_en) = '');
UPDATE categories SET name_en = 'Germany: energy geography and infrastructure' WHERE id = 72 AND owner_user_id = 6 AND name_de = 'Deutschland: Energiegeografie und Infrastruktur' AND (name_en IS NULL OR TRIM(name_en) = '');
UPDATE categories SET name_en = 'Europe: countries and energy geography' WHERE id = 73 AND owner_user_id = 6 AND name_de = 'Europa: Länder und Energiegeografie' AND (name_en IS NULL OR TRIM(name_en) = '');
UPDATE categories SET name_en = 'Europe: landscapes, seas and rivers' WHERE id = 74 AND owner_user_id = 6 AND name_de = 'Europa: Landschaften, Meere und Flüsse' AND (name_en IS NULL OR TRIM(name_en) = '');
UPDATE categories SET name_en = 'World: orientation and map knowledge' WHERE id = 75 AND owner_user_id = 6 AND name_de = 'Welt: Orientierung und Kartenwissen' AND (name_en IS NULL OR TRIM(name_en) = '');
UPDATE categories SET name_en = 'World: countries and energy geography' WHERE id = 76 AND owner_user_id = 6 AND name_de = 'Welt: Länder und Energiegeografie' AND (name_en IS NULL OR TRIM(name_en) = '');
UPDATE categories SET name_en = 'Europe electricity mix 2025' WHERE id = 77 AND owner_user_id = 6 AND name_de = 'Europa: Strommix 2025' AND (name_en IS NULL OR TRIM(name_en) = '');
UPDATE categories SET name_en = 'Germany electricity mix 2025' WHERE id = 78 AND owner_user_id = 6 AND name_de = 'Deutschland: Strommix 2025' AND (name_en IS NULL OR TRIM(name_en) = '');

-- Archiv-Energie (Hauptkategorie ID 193)
UPDATE categories SET name_en = 'Electricity and energy fundamentals' WHERE id = 200 AND owner_user_id = 6 AND parent_id = 193 AND name_de = 'Strom und Energie Grundlagen' AND (name_en IS NULL OR TRIM(name_en) = '');
UPDATE categories SET name_en = 'Power grid fundamentals' WHERE id = 201 AND owner_user_id = 6 AND parent_id = 193 AND name_de = 'Stromnetz Grundlagen' AND (name_en IS NULL OR TRIM(name_en) = '');
UPDATE categories SET name_en = 'Renewable electricity generation' WHERE id = 202 AND owner_user_id = 6 AND parent_id = 193 AND name_de = 'Erneuerbare Stromerzeugung' AND (name_en IS NULL OR TRIM(name_en) = '');
UPDATE categories SET name_en = 'Fossil fuels' WHERE id = 203 AND owner_user_id = 6 AND parent_id = 193 AND name_de = 'Fossile Energieträger' AND (name_en IS NULL OR TRIM(name_en) = '');
UPDATE categories SET name_en = 'Battery storage and lithium-ion technology' WHERE id = 204 AND owner_user_id = 6 AND parent_id = 193 AND name_de = 'Batteriespeicher und Lithium-Ionen-Technik' AND (name_en IS NULL OR TRIM(name_en) = '');
UPDATE categories SET name_en = 'Flexibility and energy systems' WHERE id = 205 AND owner_user_id = 6 AND parent_id = 193 AND name_de = 'Flexibilität und Energiesystem' AND (name_en IS NULL OR TRIM(name_en) = '');
UPDATE categories SET name_en = 'Electricity data 2025: Germany and Europe' WHERE id = 206 AND owner_user_id = 6 AND parent_id = 193 AND name_de = 'Stromdaten 2025: Deutschland und Europa' AND (name_en IS NULL OR TRIM(name_en) = '');

SELECT c.id, parent.name_de AS parent_de, c.name_de, c.name_en
  FROM categories c
  LEFT JOIN categories parent ON parent.id = c.parent_id
 WHERE c.id IN (70,71,72,73,74,75,76,77,78,79,80,81,82,83,84,85,86,87,88,89,90,91,92,93,200,201,202,203,204,205,206)
   AND c.owner_user_id = 6
 ORDER BY c.parent_id, c.id;

COMMIT;
