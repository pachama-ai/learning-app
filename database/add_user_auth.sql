-- ---------------------------------------------------------------------------
-- Anmelden: die Spalten, die die Tabelle users dafür braucht
--
-- ERST DURCHLESEN, DANN VON HAND in phpMyAdmin AUSFÜHREN. Nichts in der
-- Anwendung führt diese Datei aus, und nichts ändert die Struktur von selbst.
--
-- Warum diese drei Spalten:
--
--   password_hash  Das Passwort wird nie gespeichert, nur der Hash, den
--                  password_hash() mit PASSWORD_DEFAULT erzeugt (bcrypt, 255
--                  Zeichen sind also reichlich). NULL heißt: diese Zeile kann
--                  sich nicht anmelden - nützlich für ein Konto, das auf einem
--                  anderen Weg entsteht.
--   email          Freiwillig. Sie lässt jemanden mit einer Adresse statt mit
--                  einem Namen anmelden. Ein Konto ohne sie behält einfach NULL.
--   created_at     Wann das Konto angelegt wurde. Nur zur Information.
--
-- Warum die beiden eindeutigen Schlüssel:
--   Mit dem Namen und der Adresse meldet man sich AN. Zwei Zeilen, die sich
--   einen davon teilen, machten die Anmeldung mehrdeutig, die Datenbank weigert
--   sich also. Mehrere NULL-Werte in email sind erlaubt (MySQL und MariaDB
--   lassen das in einem eindeutigen Index zu), und das braucht ein Konto ohne
--   Adresse auch.
--
-- Was diese Datei NICHT tut: sie löscht keine Zeile, benennt keine Spalte um,
-- wirft keinen Index weg und fasst keine andere Tabelle an. Die Tabelle users
-- ist im Moment leer (0 Zeilen), vorhandene Daten können also in keiner
-- Richtung betroffen sein.
-- ---------------------------------------------------------------------------

ALTER TABLE `users`
    ADD COLUMN `email` VARCHAR(190) NULL AFTER `name`,
    ADD COLUMN `password_hash` VARCHAR(255) NULL AFTER `email`,
    ADD COLUMN `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER `password_hash`,
    ADD UNIQUE KEY `uniq_users_name` (`name`),
    ADD UNIQUE KEY `uniq_users_email` (`email`);

-- Zurücknehmen, falls das je gewünscht ist:
--
-- ALTER TABLE `users`
--     DROP INDEX `uniq_users_email`,
--     DROP INDEX `uniq_users_name`,
--     DROP COLUMN `created_at`,
--     DROP COLUMN `password_hash`,
--     DROP COLUMN `email`;
