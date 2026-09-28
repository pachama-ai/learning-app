-- ==========================================================================
-- Die Kategorie, zu der ein Lerndurchgang gehört
-- ==========================================================================
--
-- ERST DURCHLESEN, DANN VON HAND AUSFÜHREN (phpMyAdmin oder der mysql-Client).
-- Nichts in der Anwendung führt diese Datei aus, und Copilot führt eine
-- strukturelle Änderung nie von selbst aus.
--
-- Auf der Kommandozeile:
--   mysql -u <Benutzer> -p learning_app < database/add_session_category.sql
--
-- Warum
--   Die Frage "wie viele Tage in Folge habe ich DIESE Unterkategorie gelernt?"
--   lässt sich heute nicht beantworten. study_sessions kennt die Person und den
--   Tag, aber nicht, wo der Durchgang stattgefunden hat - die Kachel auf einer
--   Unterkategorieseite zählt also jeden Tag, an dem diese Person irgendwo
--   gelernt hat, und zeigt auf jeder Seite dieselbe Zahl.
--
--   Eine Spalte, und sie darf absichtlich NULL sein: ein Durchgang, der vor
--   dieser Änderung geschrieben wurde, hat keine Kategorie, und das bleibt als
--   NULL sichtbar, statt geraten zu werden.
--
-- Was sie tut
--   * fügt study_sessions.category_id hinzu (int unsigned, NULL, hinter user_id)
--   * fügt den Index hinzu, den die Frage je Kategorie braucht
--   * fügt einen Fremdschlüssel auf categories (id) hinzu
--       ON DELETE SET NULL: das Löschen einer Kategorie behält die Lerngeschichte
--       und wirft nur die Verknüpfung weg. CASCADE würde Lerntage stillschweigend
--       löschen, und RESTRICT würde verhindern, dass man je eine Kategorie löscht,
--       in der gelernt wurde - beides ist diese Geschichte nicht wert.
--
-- Was sie NICHT tut
--   * keine Spalte wird entfernt oder umbenannt, kein Typ geändert
--   * keine Zeile wird gelöscht, kein Wert überschrieben
--   * keine andere Tabelle wird angefasst: users, categories, cards,
--     user_card_progress und card_exercises behalten ihre Spalten genau, wie sie
--     sind
--
-- Nach dieser Datei arbeitet die Anwendung weiter unverändert: sie schreibt und
-- liest die neue Spalte erst, wenn ihr Code das tut. Bis dahin bleibt die Serie
-- die Zahl für die ganze Person.
--
-- --------------------------------------------------------------------------
-- Freiwillig, und nur wenn du einverstanden bist - das sind DATEN, keine Struktur
-- --------------------------------------------------------------------------
-- Den einen Durchgang, den es heute gibt, hat der Test vom 2026-09-27 in
-- "Übungsaufgaben" (Kategorie 101) erzeugt. Er hat keine Kategorie, zählt also für
-- keine Unterkategorie. Soll er dort zählen, führe zusätzlich diese eine Zeile aus:
--
--   UPDATE study_sessions SET category_id = 101 WHERE category_id IS NULL AND id = 7;
--
-- Sie ändert genau diese eine Zeile und nichts sonst. Lässt du sie weg, bleibt der
-- Durchgang einfach, was er ist: ein Tag Lernen ohne Ort.
-- ==========================================================================

ALTER TABLE study_sessions
    ADD COLUMN category_id INT UNSIGNED NULL AFTER user_id;

ALTER TABLE study_sessions
    ADD INDEX idx_study_sessions_category (category_id);

ALTER TABLE study_sessions
    ADD CONSTRAINT fk_study_sessions_category
        FOREIGN KEY (category_id) REFERENCES categories (id)
        ON DELETE SET NULL;
