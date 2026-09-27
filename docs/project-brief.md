# Projekt-Überblick — Lernkartei

Diese Datei ist der Einstieg für alle, die neu am Projekt arbeiten (auch für
KI-Assistenten). Sie sagt, was das Projekt ist, welche Regeln gelten, was gerade
funktioniert und was noch offen ist.

**Die technische Erklärung steht in [`docs/technik.md`](technik.md)** — Aufbau,
Ablauf einer Anfrage, Datenbank, Lernlogik, Frontend und die Startanleitung.

Die frühere, inzwischen überholte Fassung dieser Datei (Stand 21.09.2026) liegt
als `docs/archiv/project-brief-2026-09-21.md` daneben. Sie wurde nicht gelöscht,
weil dort noch Messwerte und Hintergründe stehen (z. B. die Performance-Messungen).

---

## 1. Was die Anwendung ist

Eine Web-Anwendung zum Lernen mit Karteikarten. Alle Nutzer teilen sich **einen**
Kartenbestand, jeder Nutzer hat seinen **eigenen** Lernfortschritt pro Karte.
Die Inhalte stecken in drei Ebenen: Lernbereich → Unterkategorie → Karte.

Vier Ansichten teilen sich eine Seite: Startseite, Bereichsseite,
Unterkategorie-Seite mit Kartenliste, und die Lernansicht als Vollbild.

## 2. Regeln, die nicht gebrochen werden dürfen

Diese Regeln stehen ausführlich in `.github/copilot-instructions.md` und sind für
jede Änderung verbindlich.

**Datenbank**

* Das Schema wird **nur nach vorheriger Absprache** geändert. Kein `ALTER`, `DROP`,
  `TRUNCATE`, `RENAME`, keine neue oder umbenannte Tabelle, Spalte, Index oder
  Bedingung ohne ausdrückliche Zustimmung.
* Vorhandene Daten werden **nicht gelöscht** und nicht überschrieben, außer der
  Nutzer hat genau diese Operation verlangt.
* Keine erfundenen Beispieldaten, keine Seed-Daten.
* Nie einen Spaltennamen raten: erst `DESCRIBE <tabelle>` beziehungsweise
  `SHOW CREATE TABLE <tabelle>`, dann den echten Namen benutzen.
* Struktur-Änderungen werden als lesbare SQL-Datei in `database/` vorgeschlagen und
  von Hand in phpMyAdmin ausgeführt – nie automatisch.

**Code**

* PHP mit PDO und **nur** Prepared Statements, auch für Ids. Niemals SQL aus
  Eingaben zusammensetzen.
* Kein Frontend-Framework, kein jQuery, kein Node.js, kein Bundler. Reines
  JavaScript.
* Alle Datenzugriffe laufen über PHP. Der Browser spricht nie mit MySQL und sieht
  keine Zugangsdaten, Verbindungszeichenfolgen oder Dateipfade.
* Jede HTML-Ausgabe wird escaped (`htmlspecialchars` mit `ENT_QUOTES`, UTF-8).
* Jede API-Antwort benutzt den Umschlag `{"success":true,"data":…}` oder
  `{"success":false,"error":{"code":…,"message":…}}`, mit `Content-Type:
  application/json` und `JSON_UNESCAPED_UNICODE`.
* Fehler verraten dem Client nie SQL, Zugangsdaten oder Stacktraces; Einzelheiten
  gehen ins Fehlerprotokoll.
* Sichtbare Texte laufen immer über `src/helpers/translations.php` (Deutsch und
  Englisch). Nie eine deutsche oder englische Zeichenkette fest in eine Vorlage
  oder in JavaScript schreiben.
* Für Symbole die SVGs aus der Anwendung benutzen – keine Emojis, kein Icon-Font.

**Arbeitsweise**

* Ein Thema pro Schritt. Vor einer Änderung an mehreren Dateien sagen, welche
  Dateien sich ändern und warum.
* Keine Datei still überschreiben.
* Code verständlich für Einsteiger halten: klare Namen, kurze Funktionen,
  Kommentare dort, wo eine Entscheidung nicht offensichtlich ist.
* Nach jeder Änderung eine Handprüfung angeben: was anklicken, was eingeben, was
  erwarten – inklusive der Fehlerfälle.
* Die Lernlogik (Spaced Repetition) nicht anfassen, solange nicht klar ist, ob
  Kartenanlage und Wiederholung vollständig funktionieren.

## 3. Was aktuell funktioniert (Stand 27.09.2026)

* **Anmeldung und Konto:** Registrieren, Anmelden, Abmelden, Kontolöschung mit
  Passwort und CSRF-Token (`api/auth.php`, `api/account.php`,
  `src/services/user_service.php`). Es gibt einen echten Nutzer in `users`.
* **Der ganze Lesepfad:** Startseite, Bereichsseite, Unterkategorie-Seite, Symbole,
  Landkarten.
* **Kartenarbeit:** Kartenliste mit Status (neu/unsicher/gewusst), Verteilungsbalken,
  Zählungen, Suche ab 15 Karten, Dialog mit Live-Vorschau, Karte anlegen, ändern,
  löschen.
* **Lernansicht:** Umdrehen per Klick/Taste, vier Bewertungen per Knopf und Taste
  1–4, Intervall-Vorschau unter jedem Knopf, Wischen, Zurücknehmen der letzten
  Antwort, Zusammenfassung am Ende.
* **Lernlogik:** Zustand, Intervall und Fälligkeit werden ausschließlich in
  `src/services/review_service.php` berechnet (siehe `docs/technik.md`, Abschnitt 7).
* **Lern-Sitzungen und Serie:** `study_sessions` wird beim Bewerten geschrieben und
  beim Beenden geschlossen; die Tages-Serie wird gesamt und je Unterkategorie
  angezeigt.
* **Übungsaufgaben:** 20 Aufgabentypen mit Parameter-Formular und Beispiel vom
  Server (`api/exercise_preview.php`).
* **CSV-Import:** im Browser mit Prüfvorschau (`api/import_cards.php`) und drei
  Kommandozeilen-Importe in `bin/`.
* **Hell und Dunkel, Deutsch und Englisch**, beides im Browser gespeichert und beim
  Laden vor dem ersten Bild angewendet.

## 4. Was noch offen ist

Die ausführliche Liste steht in `docs/technik.md`, Abschnitt 10. Die wichtigsten
Punkte:

1. **Externe Schriften:** `app.css` lädt „Inter“ und „Source Serif 4“ von Google
   Fonts. Das widerspricht dem Kommentar direkt darüber und dem Grundsatz, ohne
   fremde Dienste auszukommen.
2. **Tote Stellen im Stylesheet:** Regeln für `.grain`, `.hairline`, `.data-pill`,
   `.row__arrow`, `.row__progress`, `.row__count`, `.row__play`,
   `.card-tools__label` sowie für `.is-linked`/`.is-linking`.
3. **Ungenutzte Übersetzungsschlüssel:** etwa 58 Schlüssel werden nirgends benutzt.
   Verdacht auf einen echten Fehler: die fünf Schlüssel `import.fatal.*` werden
   gesucht, aber unter `import.error.*` abgefragt – der Nutzer sieht deshalb die
   allgemeine Meldung statt des genauen Satzes.
4. **Spalten ohne Leser:** `categories.color`, `categories.description_en`/`_de`,
   `users.role`, `card_exercises.range_min`/`range_max` (nur melden, nichts ändern).
5. **Aufräumen im Code:** unbenutzte CSS-Variablen (`--cat-default`,
   `--accent-soft`, `--warn-soft`, `--background`, `--surface-soft-strong`),
   unbenutzte Datei `public/assets/icons/informatik.svg`, tote Konstante
   `SVG_BLOCKED_ELEMENTS`, eine unbenutzte Variable in `app.js`
   (`editingParentId`), das nie ausgewertete Attribut `data-i18n-empty`.

## 5. Arbeitsweise in dieser Umgebung

Das Projekt liegt in WSL2/Ubuntu; VS Code öffnet es meist über
`\\wsl.localhost\Ubuntu\…`. Daraus ergeben sich ein paar Fallen, die schon
aufgetreten sind:

* Ein Schreibvorgang auf eine Datei auf diesem Pfad kann **still nichts tun**
  (Erfolg gemeldet, Datei unverändert). Nach jeder Änderung auf der Platte
  nachsehen: `grep` im Terminal oder ein kleines PHP-Skript.
* Editoren schreiben manchmal **CRLF**. Ein Shell-Skript muss LF haben, und ein
  mehrzeiliger Suchtext mit `\r\n` findet eine LF-Datei nie.
* Das Terminal (`sed`, `grep`, `php -l`) ist die verlässliche Quelle; ein Editor
  oder eine Suchfunktion kann veraltete Inhalte zeigen.
* Vor dem Prüfen nach einer Änderung: `php -l` für jede geänderte PHP-Datei und
  `node --check` für JavaScript, danach die Seite im Browser.
* Die Meldung **„WSL: Disconnected — Reload Window“** kommt vom Standby-Timer von
  Windows, nicht vom Projekt. Ursache, Behebung und Diagnose stehen in
  `docs/development-environment.md`.

**Git.** Jeder Commit erklärt das *Warum*, nicht nur das *Was*. Branch `main`,
Remote `origin` (`github.com:pachama-ai/learning-app.git`).
