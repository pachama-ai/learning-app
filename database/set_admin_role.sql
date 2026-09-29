-- Setzt die Rolle "admin" fuer ein einzelnes Konto.
--
-- Warum die Rolle und nicht die E-Mail-Adresse: die Rolle steht in der Spalte
-- users.role und gehoert genau einem Konto. Eine E-Mail-Adresse kann sich
-- aendern oder von jemand anderem registriert werden - sie taugt deshalb nicht
-- als Rechtekriterium. Im Code wird immer user_is_admin() gefragt
-- (siehe src/services/user_service.php), niemals eine Adresse.
--
-- Dieses Skript aendert genau eine Zeile. Es legt keine Tabelle und keine
-- Spalte an und loescht nichts. Ausgefuehrt wird es von Hand, wie jedes Skript
-- in diesem Ordner.
--
-- Schritt 1 - nachsehen, welches Konto gemeint ist:
--
--   SELECT id, name, email, role FROM users;
--
-- Schritt 2 - die Rolle setzen (hier fuer id 6; die Id oben ablesen und
-- anpassen, falls sie bei dir anders ist):
--
UPDATE users SET role = 'admin' WHERE id = 6;

-- Schritt 3 - kontrollieren, dass genau die richtigen Konten Admin sind:
--
SELECT id, name, role FROM users WHERE role = 'admin';

-- Danach ist der Block "Administration" im Kontofenster dieses Kontos sichtbar,
-- und POST api/admin_backup.php antwortet nicht mehr mit 403.
--
-- Rueckgaengig machen: role wieder auf den Standardwert setzen.
--   UPDATE users SET role = 'learner' WHERE id = 6;
