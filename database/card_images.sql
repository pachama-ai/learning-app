-- ============================================================================
-- card_images.sql - je ein Foto für Vorder- und Rückseite einer Lernkarte
--
-- Die Spalten tragen nur den Dateinamen, nicht den ganzen Pfad:
--
--     image_front = "4f1c9a...e2.jpg"
--
-- Die Datei liegt in public/uploads/cards. Der Name wird beim Hochladen zufällig
-- erzeugt, damit zwei Karten nie dieselbe Datei überschreiben und ein geratener
-- Name nichts von anderen Karten zeigt. NULL heißt "kein Foto".
--
-- WIE AUSFÜHREN
--
-- In phpMyAdmin die Datenbank wählen, Reiter "SQL", den Inhalt dieser Datei
-- einfügen und ausführen. Die Anwendung läuft auch ohne diese Spalten weiter;
-- nur das Foto-Feld im Kartendialog meldet dann, dass die Erweiterung fehlt.
--
-- RÜCKGÄNGIG
--
--     ALTER TABLE `cards` DROP COLUMN `image_front`, DROP COLUMN `image_back`;
--
-- Die Bilddateien selbst bleiben dabei liegen und müssen von Hand aus
-- public/uploads/cards gelöscht werden.
-- ============================================================================

ALTER TABLE `cards`
    ADD COLUMN IF NOT EXISTS `image_front` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL
        COMMENT 'Dateiname in public/uploads/cards; NULL = kein Foto'
        AFTER `map_region`;

ALTER TABLE `cards`
    ADD COLUMN IF NOT EXISTS `image_back` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL
        COMMENT 'Dateiname in public/uploads/cards; NULL = kein Foto'
        AFTER `image_front`;
