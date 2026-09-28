-- ============================================================================
-- schema.sql - der vollstaendige Aufbau der Datenbank `learning_app`
--
-- Diese Datei beschreibt, wie die Datenbank aussieht: alle Tabellen, Spalten,
-- Typen, Vorgabewerte, Indizes und Fremdschluessel. Sie ist aus dem
-- TATSAECHLICHEN Stand der laufenden Datenbank ausgelesen (mit SHOW CREATE
-- TABLE), nicht aus den frueheren Einzelmigrationen zusammengesetzt. Das ist
-- wichtig, weil es Spalten gibt, die damals von Hand in phpMyAdmin angelegt
-- wurden und in keiner Migrationsdatei stehen - zum Beispiel die vier
-- Sprachspalten und map_region in `cards` und die Zeichnungsspalte
-- categories.icon_svg, die MEDIUMTEXT ist und nicht TEXT. Eine Datei, die nur
-- die alten Migrationen zusammenfasst, waere beim Neuaufbau kaputt.
--
-- Stand: 28. September 2026.
--
-- NUR DIE STRUKTUR, KEINE INHALTE
--
-- Diese Datei legt leere Tabellen an. Die Kategorien und die Karten kommen
-- getrennt dazu: ueber einen Datenbank-Dump aus phpMyAdmin oder ueber einen
-- Import. Inhalt und Struktur sind absichtlich getrennt - ein Dump enthaelt
-- beides, und diese Datei bleibt so lesbar.
--
-- WOFUER SIE GEDACHT IST
--
-- Um eine leere Datenbank aufzubauen:
--
--     mysql -u BENUTZER -p learning_app < database/schema.sql
--
-- In phpMyAdmin: die Datenbank waehlen, Reiter "SQL", den Inhalt dieser Datei
-- einfuegen und ausfuehren.
--
-- Die Datenbank selbst muss es schon geben. Anlegen laesst sie sich mit
--
--     CREATE DATABASE IF NOT EXISTS `learning_app`
--         CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
--
-- (auskommentiert, weil das eigene Rechte braucht und hier nicht still
-- scheitern soll).
--
-- NICHTS WIRD ZERSTOERT
--
-- Jede Anweisung traegt "IF NOT EXISTS": gibt es eine Tabelle schon, bleibt sie
-- samt ihren Daten unangetastet. Es gibt kein DROP, kein TRUNCATE und kein
-- ALTER. Die Datei kann also auch auf einer gefuellten Datenbank laufen, ohne
-- etwas zu veraendern.
--
-- Die Reihenfolge ist Absicht: eine Tabelle steht erst dann, wenn die Tabellen
-- da sind, auf die ihre Fremdschluessel zeigen.
--
-- DIE FRUEHEREN EINZELDATEIEN
--
-- Bis zum 28.09.2026 lag hier eine Datei je Schritt (initial_categories.sql,
-- add_category_content.sql, add_user_auth.sql, add_category_owner.sql,
-- assign_category_owner.sql, add_study_sessions.sql, add_card_exercises.sql,
-- add_exercise_params.sql, add_session_category.sql). Sie sind durch diese eine
-- Datei ersetzt worden. Was damals in welchem Schritt passiert ist, steht
-- weiterhin in der Git-Historie.
-- ============================================================================

-- Neu angelegte Tabellen benutzen diesen Zeichensatz. Er ist derselbe, den die
-- Datenbank selbst benutzt (utf8mb4_unicode_ci), Umlaute und Saetze wie "€"
-- passen also hinein.
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;


-- ============================================================================
-- 1. users - die Konten
--
-- Ohne Konto laesst sich die App ansehen, aber nicht lernen: der Fortschritt
-- haengt an einer Zeile hier. name ist der Anmeldename, email und
-- password_hash kommen aus der Anmeldung. role steht derzeit in jeder Zeile auf
-- "user"; die Spalte wird von der Anwendung noch nicht ausgewertet.
--
-- Steht vor categories, weil categories.owner_user_id hierher zeigt.
-- ============================================================================

CREATE TABLE IF NOT EXISTS `users` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(190) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `password_hash` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `role` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_users_name` (`name`),
  UNIQUE KEY `uniq_users_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================================
-- 2. categories - die Lernbereiche und ihre Unterkategorien
--
-- Ein Lernbereich ist eine Zeile mit parent_id NULL, eine Unterkategorie zeigt
-- mit parent_id auf ihren Bereich. Der Baum ist damit beliebig tief, die App
-- benutzt aber zwei Ebenen: Bereich -> Unterkategorie.
--
-- owner_user_id sagt, wem die Kategorie gehoert. Zwei Konten haben also ihre
-- eigenen Bereiche, auch wenn sie denselben Karteninhalt lernen koennen.
--
-- Die Spalten color, icon_svg, icon_scale und die vier Namens- und
-- Beschreibungsspalten machen die Darstellung aus: icon_svg traegt eine
-- Zeichnung (bis 350 KB, deshalb MEDIUMTEXT und nicht TEXT), name_en/name_de
-- die Uebersetzung; steht dort nichts, zeigt die App name.
-- ============================================================================

CREATE TABLE IF NOT EXISTS `categories` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `parent_id` int unsigned DEFAULT NULL,
  `owner_user_id` int unsigned DEFAULT NULL COMMENT 'The account this category belongs to; NULL only until every row has been assigned',
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `color` varchar(7) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Hex like #8E9AB0; NULL means the built-in palette is used',
  `icon_svg` mediumtext COLLATE utf8mb4_unicode_ci,
  `icon_scale` decimal(3,2) NOT NULL DEFAULT '1.00' COMMENT 'Size correction of the drawing, 1.00 = exactly as drawn',
  `name_en` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'English display name; NULL means name is shown',
  `name_de` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'German display name; NULL means name is shown',
  `description_en` text COLLATE utf8mb4_unicode_ci COMMENT 'English description; NULL means no description is shown',
  `description_de` text COLLATE utf8mb4_unicode_ci COMMENT 'German description; NULL means no description is shown',
  PRIMARY KEY (`id`),
  KEY `parent_id` (`parent_id`),
  KEY `idx_categories_owner` (`owner_user_id`),
  CONSTRAINT `fk_categories_owner` FOREIGN KEY (`owner_user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_categories_parent` FOREIGN KEY (`parent_id`) REFERENCES `categories` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================================
-- 3. cards - die Lernkarten
--
-- Eine Karte gehoert ueber category_id zu genau einer Kategorie. Eine eigene
-- Besitzerspalte gibt es nicht: wem die Kategorie gehoert, dem gehoert die
-- Karte. Alle Konten teilen sich also denselben Kartenbestand, und nur der
-- Lernstand ist persoenlich.
--
-- front und back sind die alten Spalten und tragen weiterhin den deutschen
-- Text; front_de/back_de und front_en/back_en sind dieselben Texte in der
-- jeweiligen Sprache. Sie stehen alle nebeneinander, weil die App eine Sprache
-- waehlt und die alten zwei Spalten der Vollstaendigkeit wegen mitgeschrieben
-- werden.
--
-- map_region ist entweder NULL oder ein Schluessel wie "DE:Bayern" aus den
-- Kartendateien in public/assets/maps. is_bidirectional sagt, ob die Karte auch
-- andersherum gefragt werden soll.
-- ============================================================================

CREATE TABLE IF NOT EXISTS `cards` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `category_id` int unsigned NOT NULL,
  `front` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `back` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `front_de` text COLLATE utf8mb4_unicode_ci,
  `back_de` text COLLATE utf8mb4_unicode_ci,
  `front_en` text COLLATE utf8mb4_unicode_ci,
  `back_en` text COLLATE utf8mb4_unicode_ci,
  `map_region` varchar(40) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_bidirectional` tinyint(1) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `category_id` (`category_id`),
  CONSTRAINT `fk_cards_category` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================================
-- 4. user_card_progress - der Lernstand, je Konto und Karte
--
-- Das ist die Verbindung zwischen einem Konto und einer Karte: fuer jede Karte,
-- die jemand lernt, steht hier eine Zeile. Der Schluessel besteht deshalb aus
-- beiden Spalten zusammen - dieselbe Karte kann also bei vielen Konten liegen,
-- jedes aber nur einmal.
--
-- state ist 0 fuer "noch nie gelernt" und sonst der Zustand aus dem
-- Wiederholungsplaner; due_at sagt, wann die Karte wieder dran ist.
-- repetitions und lapses zaehlen Erfolge und Fehlgriffe, stability und
-- difficulty sind die Werte, mit denen der Planer rechnet. Karten ohne
-- Zeile gelten als neu.
-- ============================================================================

CREATE TABLE IF NOT EXISTS `user_card_progress` (
  `user_id` int unsigned NOT NULL,
  `card_id` int unsigned NOT NULL,
  `state` tinyint unsigned NOT NULL DEFAULT '0',
  `due_at` datetime DEFAULT NULL,
  `last_reviewed_at` datetime DEFAULT NULL,
  `repetitions` int unsigned NOT NULL DEFAULT '0',
  `lapses` int unsigned NOT NULL DEFAULT '0',
  `stability` decimal(10,4) DEFAULT NULL,
  `difficulty` decimal(6,3) DEFAULT NULL,
  PRIMARY KEY (`user_id`,`card_id`),
  KEY `fk_progress_card` (`card_id`),
  CONSTRAINT `fk_progress_card` FOREIGN KEY (`card_id`) REFERENCES `cards` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_progress_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================================
-- 5. study_sessions - eine Lerneinheit
--
-- Eine Zeile je Durchlauf der Lernansicht: wann die erste Antwort kam, wann die
-- Ansicht geschlossen wurde und wie viele Karten dabei bewertet wurden. Daraus
-- rechnet die App die Reihe ("so viele Tage hintereinander gelernt").
--
-- category_id ist freiwillig und auf ON DELETE SET NULL gesetzt: wird die
-- Unterkategorie geloescht, bleibt der Durchlauf stehen, verliert aber seinen
-- Bezug. Die Reihe bleibt so erhalten.
-- ============================================================================

CREATE TABLE IF NOT EXISTS `study_sessions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT COMMENT 'The session itself; one row per run of the learning view',
  `user_id` int unsigned NOT NULL COMMENT 'Whose session this is; deleted with the account',
  `category_id` int unsigned DEFAULT NULL,
  `started_at` datetime NOT NULL COMMENT 'When the first rating of this session happened, not when the view was opened',
  `ended_at` datetime DEFAULT NULL COMMENT 'When the learning view was closed; NULL while the session runs or when the tab was killed',
  `cards_studied` int unsigned NOT NULL DEFAULT '0' COMMENT 'How many ratings happened in this session; every rating counts',
  `cards_known` int unsigned NOT NULL DEFAULT '0' COMMENT 'How many of them were rated Good or Easy',
  PRIMARY KEY (`id`),
  KEY `idx_study_sessions_user_started` (`user_id`,`started_at`),
  KEY `idx_study_sessions_category` (`category_id`),
  CONSTRAINT `fk_study_sessions_category` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_study_sessions_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================================
-- 6. card_exercises - eine erzeugte Aufgabe zu einer Karte
--
-- Manche Karten sind keine feste Frage, sondern eine Aufgabe, deren Zahlen bei
-- jedem Anzeigen neu gewuerfelt werden (zum Beispiel das kleine Einmaleins).
-- card_id ist hier der Schluessel, eine Karte hat also hoechstens eine Aufgabe.
--
-- exercise_type benennt die Art der Aufgabe (die moeglichen Werte stehen in
-- src/services/exercise_service.php). exercise_params traegt die Zahlen, aus
-- denen die Aufgabe gebaut wird, als JSON. range_min und range_max sind die
-- aelteren Grenzen derselben Zahlen und bleiben der Vollstaendigkeit wegen
-- stehen.
--
-- Steht am Ende, weil der Fremdschluessel auf cards zeigt.
-- ============================================================================

CREATE TABLE IF NOT EXISTS `card_exercises` (
  `card_id` int unsigned NOT NULL COMMENT 'The card this exercise belongs to; also the primary key, so a card carries at most one exercise',
  `exercise_type` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Key of one of the kinds of task defined in src/services/exercise_service.php; unknown keys fall back to a fixed card',
  `exercise_params` json DEFAULT NULL COMMENT 'The numbers this task is built from, as JSON; which keys are allowed is written down in exercise_catalog()',
  `range_min` int NOT NULL DEFAULT '1' COMMENT 'Lowest number the task may be built from',
  `range_max` int NOT NULL DEFAULT '20' COMMENT 'Highest number the task may be built from',
  PRIMARY KEY (`card_id`),
  CONSTRAINT `fk_exercises_card` FOREIGN KEY (`card_id`) REFERENCES `cards` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================================
-- Damit ist die Struktur vollstaendig. Was jetzt noch fehlt, sind die Inhalte:
-- die Lernbereiche, ihre Unterkategorien und die Karten. Sie kommen ueber einen
-- Dump oder einen Import - siehe docs/dokumentation.md.
-- ============================================================================
