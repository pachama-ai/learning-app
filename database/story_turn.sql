-- Story Together: der Zug wechselt nach der Bearbeitungszeit, nicht mit dem Kalendertag.
--
-- Warum dieses Skript
-- -------------------
-- Bisher galt "höchstens ein Beitrag je Kalendertag", und die Datenbank hat diese Regel
-- selbst abgesichert: der eindeutige Schlüssel `uniq_story_contributions_day` auf
-- (story_id, contribution_date) ließ einen zweiten Beitrag desselben Tages gar nicht erst
-- zu.
--
-- Jetzt gilt stattdessen die Bearbeitungszeit: nach dem Veröffentlichen bleiben zwei
-- Stunden zum Ändern, danach ist der Beitrag endgültig und der Zug wechselt zur anderen
-- Person. Die andere Person schreibt dann unter Umständen noch am SELBEN Tag - und genau
-- das verbietet der alte Schlüssel. Ohne diese Änderung käme sie nicht an die Reihe.
--
-- Was dieses Skript tut - und was nicht
-- -------------------------------------
--   1. Es entfernt den eindeutigen Schlüssel über den Kalendertag. Der Tag selbst bleibt
--      in der Spalte `contribution_date` gespeichert; er ist jetzt nur noch eine Angabe
--      für die Anzeige ("Beitrag vom 11. Oktober").
--   2. Es fügt die Spalte `turn_notified_at` hinzu: der Zeitpunkt, an dem für diesen
--      Beitrag die Benachrichtigung "du bist dran" verschickt wurde. NULL heißt "noch
--      nicht verschickt". Ohne diesen Merker ginge dieselbe Mail bei jedem Cron-Lauf
--      erneut raus.
--
-- Es löscht KEINE Zeile, es ändert KEINEN Beitragstext und es legt keine Zeile an:
-- kein DELETE, kein UPDATE auf bestehenden Inhalt. Die vorhandenen Beiträge bleiben, wie
-- sie sind, und `turn_notified_at` steht bei ihnen auf NULL.
--
-- Ausführen von Hand (phpMyAdmin: SQL-Reiter der Datenbank `study`, oder):
--     set -a; . ./.env; set +a
--     MYSQL_PWD="$DB_PASS" mysql -h "$DB_HOST" -u "$DB_USER" "$DB_NAME" < database/story_turn.sql
--
-- Nur EINMAL ausführen. Beim zweiten Mal meldet die Datenbank, dass es den Schlüssel bzw.
-- die Spalte nicht mehr gibt; passiert ist dann nichts weiter.
--
-- Vorher eine Sicherung anlegen: das Skript ändert zwar keine Zeilen, aber eine Sicherung
-- kostet nichts (siehe bin/ und public/api/admin_backup.php).

-- 1. Der Tagesbezug fällt.
--    `idx_story_contributions_order` bleibt: er sortiert die Beiträge und hat mit dieser
--    Regel nichts zu tun.
ALTER TABLE `story_contributions`
  DROP INDEX `uniq_story_contributions_day`;

-- 2. Der Merker für die Benachrichtigung.
ALTER TABLE `story_contributions`
  ADD COLUMN `turn_notified_at` datetime DEFAULT NULL AFTER `created_at`;

-- Kontrolle: der eindeutige Schlüssel muss weg sein (NON_UNIQUE = 1 bei allen übrigen),
-- und die neue Spalte muss da sein.
SELECT INDEX_NAME, NON_UNIQUE, COLUMN_NAME, SEQ_IN_INDEX
  FROM information_schema.STATISTICS
 WHERE TABLE_SCHEMA = DATABASE()
   AND TABLE_NAME = 'story_contributions'
 ORDER BY INDEX_NAME, SEQ_IN_INDEX;

SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE()
   AND TABLE_NAME = 'story_contributions'
 ORDER BY ORDINAL_POSITION;
