-- ==========================================================================
-- Eine zweite Art von Karte: die Übung, deren Zahlen jedes Mal neu gezogen werden
-- ==========================================================================
--
-- ERST DURCHLESEN, DANN VON HAND AUSFÜHREN (phpMyAdmin oder der mysql-Client).
-- Nichts in der Anwendung führt diese Datei aus, und Copilot führt eine
-- strukturelle Änderung nie von selbst aus.
--
-- Auf der Kommandozeile:
--   mysql -u <Benutzer> -p learning_app < database/add_card_exercises.sql
--
-- Warum eine eigene Tabelle
--   Eine feste Karte zeigt den Text, den jemand in `cards.front` und
--   `cards.back` geschrieben hat. Eine Übungskarte zeigt eine Aufgabe, die
--   entsteht, wenn die Karte angezeigt wird, mit Zahlen, die bei jedem Anzeigen
--   neu gezogen werden. Die Übung braucht also drei Dinge, die eine feste Karte
--   nicht hat: welche Art von Aufgabe es ist, und die kleinste und die größte
--   Zahl, die darin vorkommen darf.
--
--   Diese drei Werte sind alles, was gespeichert wird. Die Aufgabe selbst -
--   "34 + 58", die Antwort "92", das Ganze - entsteht durch den Code in
--   `src/services/exercise_service.php`, und nur für die Aufgabenarten, die in
--   dieser Datei stehen. Hier hält nichts eine Formel, und es wird nie eine
--   Formel aus der Datenbank geholt und zur Laufzeit ausgerechnet. Eine neue
--   Aufgabenart entsteht deshalb nur durch neues PHP.
--
-- Was sie tut
--   Legt genau eine neue Tabelle an, `card_exercises`:
--
--     card_id        die Karte, zu der diese Übung gehört. Sie ist gleichzeitig
--                    der Primärschlüssel, eine Karte trägt also höchstens eine
--                    Übung, und das Löschen der Karte löscht ihre Übung mit.
--     exercise_type  der Schlüssel einer der Aufgabenarten, die der Service
--                    kennt, zum Beispiel 'add' oder 'multiply'. Der Code weist
--                    jeden Schlüssel ab, den er nicht kennt; ein falscher Wert
--                    in dieser Spalte kann also nichts anrichten - er lässt die
--                    Karte nur wieder als feste Karte erscheinen.
--     range_min/_max die Zahlen, aus denen die Aufgabe gebaut werden darf. Bei
--                    'add' sind das die beiden Summanden, bei 'multiply' die
--                    beiden Faktoren. Wie genau sie benutzt werden, steht bei
--                    jeder Aufgabenart im Service.
--
-- Was sie NICHT tut
--   * sie fasst `cards` nicht an: keine neue Spalte, keine geänderte Spalte,
--     keine entfernte Spalte. Jede vorhandene Karte bleibt eine feste Karte,
--     und die Anwendung arbeitet genau wie vorher, solange diese Tabelle noch
--     leer ist
--   * kein DROP, kein RENAME, kein TRUNCATE, kein DELETE, kein UPDATE
--     vorhandener Werte
--   * keine andere Tabelle wird angefasst: `users`, `categories`, `cards` und
--     `user_card_progress` bleiben genau, wie sie sind
--
-- Sicherheit
--   Die Anweisung trägt "IF NOT EXISTS", ein zweiter Lauf ändert beim zweiten
--   Mal also nichts. Die Tabelle ist beim Anlegen leer, es gibt also keine
--   Daten, die verloren gehen könnten.
--
-- Zurücknehmen (nur falls du den alten Zustand je zurückhaben willst)
--   DROP TABLE `card_exercises`;
--
--   Das ist für die Karten selbst harmlos: `cards` wurde nie geändert, jede
--   Übungskarte zeigt also einfach wieder ihren Vorder- und Rückseitentext.
-- ==========================================================================

CREATE TABLE IF NOT EXISTS `card_exercises` (
    `card_id` INT UNSIGNED NOT NULL
        COMMENT 'The card this exercise belongs to; also the primary key, so a card carries at most one exercise',
    `exercise_type` VARCHAR(32) NOT NULL
        COMMENT 'Key of one of the kinds of task defined in src/services/exercise_service.php; unknown keys fall back to a fixed card',
    `range_min` INT NOT NULL DEFAULT 1
        COMMENT 'Lowest number the task may be built from',
    `range_max` INT NOT NULL DEFAULT 20
        COMMENT 'Highest number the task may be built from',
    PRIMARY KEY (`card_id`),
    CONSTRAINT `fk_exercises_card`
        FOREIGN KEY (`card_id`) REFERENCES `cards` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
