-- ==========================================================================
-- Lerneinheiten: wie viel heute gelernt wurde, und in welchem Durchgang
-- ==========================================================================
--
-- ERST DURCHLESEN, DANN VON HAND AUSFÜHREN (phpMyAdmin oder der mysql-Client).
-- Nichts in der Anwendung führt diese Datei aus, und Copilot führt eine
-- strukturelle Änderung nie von selbst aus.
--
-- Auf der Kommandozeile:
--   mysql -u <Benutzer> -p learning_app < database/add_study_sessions.sql
--
-- Warum eine eigene Tabelle
--   `user_card_progress` beantwortet die Frage "wo steht diese Karte für diese
--   Person" - eine Zeile je Karte. Die Frage "wie viel habe ich heute geschafft"
--   kann sie nicht beantworten, denn eine fünfmal bewertete Karte sieht genauso
--   aus wie eine einmal bewertete, und der Tag, an dem eine Karte zuerst gelernt
--   wurde, wird bei jeder späteren Bewertung überschrieben.
--
--   Eine Einheit ist deshalb etwas Eigenes: eine Zeile je Durchgang der
--   Lernansicht. Sie wird neben dem Fortschritt geschrieben, nie statt seiner,
--   das Wiederholen selbst bleibt also genau, wie es ist.
--
-- Die Spalten
--
--   id             Die eigene Nummer der Einheit.
--   user_id        Wessen Einheit es ist. Das Löschen eines Kontos nimmt seine
--                  Einheiten mit (ON DELETE CASCADE), dieselbe Regel, der die
--                  Fortschrittszeilen schon folgen.
--   started_at     Wann diese Einheit wirklich anfing: mit der ERSTEN Bewertung,
--                  nicht mit dem Öffnen der Lernansicht. Ein Durchgang, der
--                  geöffnet und ohne Bewertung wieder geschlossen wird, hinterlässt
--                  also keine Zeile, und genau darum geht es bei dieser Regel.
--   ended_at       Wann die Lernansicht geschlossen wurde - egal ob die Schlange
--                  leer wurde oder die Person früher aufgehört hat. NULL, solange
--                  die Einheit noch läuft.
--
--                  Eine ehrliche Anmerkung: wird ein Browser-Reiter einfach
--                  abgeschossen, kommt nie ein "Schließen" an und die Zeile bleibt
--                  mit ended_at = NULL offen. Das ist harmlos, denn die Statistik
--                  zählt die Einheiten über started_at und cards_studied zusammen -
--                  eine offene Zeile zählt wie jede andere. Es wird nie etwas
--                  geraten oder hinter deinem Rücken repariert.
--   cards_studied  Wie viele Bewertungen in dieser Einheit passiert sind. Jede
--                  zählt, eine Karte zweimal zu bewerten (nach "Nochmal") ist also
--                  zwei. Das ist es, was der Zähler in der Lernansicht zeigt.
--   cards_known    Wie viele davon mit "Gut" oder "Leicht" bewertet wurden - die
--                  beiden Antworten der bestehenden Skala (Nochmal, Schwer, Gut,
--                  Leicht), die "konnte ich" bedeuten. "Schwer" zählt absichtlich
--                  nicht: das ist ein Erfolg für den Abstand, aber kein "konnte
--                  ich".
--
-- Was sie tut
--   Legt genau eine neue Tabelle an, mit einem Index für die Tagesfrage.
--
-- Was sie NICHT tut
--   * keine bestehende Tabelle wird angefasst: `users`, `categories`, `cards`,
--     `user_card_progress` und `card_exercises` behalten ihre Spalten genau so,
--     wie sie sind
--   * kein DROP, kein RENAME, kein TRUNCATE, kein DELETE, kein UPDATE
--     vorhandener Werte
--   * keine Gamification: keine Serien, keine Punkte, keine Abzeichen, keine
--     Ziele - dafür gibt es keine Spalte, und keine ist geplant
--
-- Sicherheit
--   "IF NOT EXISTS" macht einen zweiten Lauf wirkungslos. Die Tabelle ist beim
--   Anlegen leer, es kann also in keiner Richtung etwas verloren gehen.
--
-- Zurücknehmen (nur falls du den alten Zustand je zurückhaben willst)
--   DROP TABLE `study_sessions`;
--
--   Der Lernfortschritt selbst liegt in `user_card_progress` und wird davon nicht
--   angefasst, vom Wiederholen ginge also nichts verloren.
-- ==========================================================================

CREATE TABLE IF NOT EXISTS `study_sessions` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT
        COMMENT 'The session itself; one row per run of the learning view',
    `user_id` INT UNSIGNED NOT NULL
        COMMENT 'Whose session this is; deleted with the account',
    `started_at` DATETIME NOT NULL
        COMMENT 'When the first rating of this session happened, not when the view was opened',
    `ended_at` DATETIME NULL
        COMMENT 'When the learning view was closed; NULL while the session runs or when the tab was killed',
    `cards_studied` INT UNSIGNED NOT NULL DEFAULT 0
        COMMENT 'How many ratings happened in this session; every rating counts',
    `cards_known` INT UNSIGNED NOT NULL DEFAULT 0
        COMMENT 'How many of them were rated Good or Easy',
    PRIMARY KEY (`id`),
    KEY `idx_study_sessions_user_started` (`user_id`, `started_at`),
    CONSTRAINT `fk_study_sessions_user`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
