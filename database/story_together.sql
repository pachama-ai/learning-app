-- Story Together: die beiden Tabellen der gemeinsamen Geschichte.
--
-- Dieses Skript legt AUSSCHLIESSLICH zwei neue Tabellen an. Es ändert keine bestehende
-- Tabelle, fügt keine Spalte hinzu und löscht nichts: kein ALTER, kein DROP, kein
-- DELETE. Bestehende Karten, Kategorien und Fortschritte bleiben unberührt.
--
-- Ausführen von Hand (phpMyAdmin: SQL-Reiter der Datenbank `study`, oder):
--     set -a; . ./.env; set +a
--     MYSQL_PWD="$DB_PASS" mysql -h "$DB_HOST" -u "$DB_USER" "$DB_NAME" < database/story_together.sql
--
-- Zweimal ausführen ist unschädlich: beide Tabellen entstehen mit IF NOT EXISTS, und
-- das Skript fügt KEINE Zeilen ein. Die eine Geschichte und ihre Beiträge legt die
-- Anwendung selbst an, wenn sie gebraucht werden.
--
-- Warum zwei Tabellen und nicht eine
-- ----------------------------------
-- `stories` trägt den Titel und die beiden Zeitstempel. `story_contributions` trägt
-- jeden Beitrag genau einmal, mit seinem Autor und seinem Kalendertag. Getrennt ist das,
-- weil der Titel sich einmal ändern darf, ein veröffentlichter Beitrag aber nicht: es
-- gibt keine Anweisung in der Anwendung, die einen Beitrag ändert.
--
-- Der eindeutige Schlüssel `uniq_story_contributions_day`
-- -------------------------------------------------------
-- Er ist die eigentliche Absicherung der Regel "höchstens ein Beitrag je Kalendertag".
-- Zwei gleichzeitige Anfragen können beide die Prüfung in der Anwendung überstehen -
-- die zweite scheitert dann hier, und zwar in der Datenbank und nicht im Kopf. Die
-- Transaktion in story_service.php ist die zweite Sicherung (SELECT ... FOR UPDATE).
--
-- NACHTRAG (11.10.2026): Diese Tagesregel gilt nicht mehr. Der Zug wechselt jetzt zwei
-- Stunden nach dem Veröffentlichen, und die andere Person schreibt damit unter Umständen
-- noch am selben Tag. `database/story_turn.sql` entfernt den Schlüssel deshalb wieder -
-- und fügt die Spalte `turn_notified_at` für die Benachrichtigung hinzu. Dieses Skript
-- hier bleibt so stehen, wie es gelaufen ist.
--
-- Die Zeiten
-- ----------
-- `created_at` und `updated_at` schreibt die Anwendung als UTC (UTC_TIMESTAMP()),
-- damit ein Serverumzug oder eine andere Zeitzone die gespeicherten Zeiten nicht
-- verschiebt. `contribution_date` ist dagegen der KALENDERTAG in Europe/Berlin - das
-- ist die Zeitzone, in der die beiden schreiben, und sie entscheidet über "heute".

CREATE TABLE IF NOT EXISTS `stories` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(200) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `story_contributions` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `story_id` int(10) unsigned NOT NULL,
  `user_id` int(10) unsigned NOT NULL,
  `content` text NOT NULL,
  `word_count` int(10) unsigned NOT NULL DEFAULT 0,
  `contribution_date` date NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_story_contributions_day` (`story_id`, `contribution_date`),
  KEY `idx_story_contributions_order` (`story_id`, `contribution_date`, `id`),
  KEY `idx_story_contributions_user` (`user_id`),
  CONSTRAINT `fk_story_contributions_story` FOREIGN KEY (`story_id`) REFERENCES `stories` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_story_contributions_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Kontrolle: beide Tabellen müssen danach da sein, und die Zahl der Beiträge muss 0
-- sein, solange noch niemand geschrieben hat.
SELECT TABLE_NAME, TABLE_ROWS
  FROM information_schema.TABLES
 WHERE TABLE_SCHEMA = DATABASE()
   AND TABLE_NAME IN ('stories', 'story_contributions');

SELECT COUNT(*) AS beitraege FROM `story_contributions`;
