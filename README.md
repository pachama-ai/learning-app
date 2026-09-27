# Lernkartei (Learning App)

Eine Web-Anwendung zum Lernen mit Karteikarten. Alle Nutzer teilen sich **einen**
Kartenbestand; jeder Nutzer hat seinen **eigenen** Lernfortschritt pro Karte. Der
Inhalt ist in Lernbereiche, Unterkategorien und Karten gegliedert.

**Technik:** PHP 8.5 mit PDO, MySQL/MariaDB, HTML, CSS und reines JavaScript.
Kein Framework, kein Node.js, kein Bundler, kein Bauschritt – die Dateien werden
genau so ausgeliefert, wie sie im Editor stehen.

Diese Datei ist absichtlich kurz. Die vollständige technische Erklärung steht in
**[`docs/technik.md`](docs/technik.md)**.

## Starten

```bash
cd /home/user/projects/learning-app
./start-dev.sh            # http://127.0.0.1:8081/
PORT=8082 ./start-dev.sh  # anderer Port, falls 8081 belegt ist
```

Das Skript startet den PHP-eigenen Entwicklungsserver mit **vier Workern**. Eine
Seite lädt mehrere Dinge gleichzeitig (Lernbereiche, Kategorie, Unterkategorien,
Karten); mit einem einzigen Worker würden diese Anfragen hintereinander
abgearbeitet und die Seite wartet auf ihre Summe. Beenden mit `Strg+C`.

Zwei Alternativen:

```bash
php -S 127.0.0.1:8081 -t public   # derselbe Server, ein Worker
./start-apache.sh                 # Apache auf http://127.0.0.1:8082/
```

Kurz prüfen, ob alles läuft:

```bash
curl -s http://127.0.0.1:8081/api/health.php
```

Erwartet: `{"success":true,"data":{"database":"learning_app", ...}}`.

In Produktion läuft die Anwendung unter Apache mit `public/` als DocumentRoot;
die Datenbank heißt `learning_app`.

## Konfiguration (.env)

Alle Zugangsdaten stehen in der Datei **`.env`** im Projektstamm — neben `public/`,
also außerhalb des Web-Roots. Angelegt wird sie aus der Vorlage:

```bash
cp .env.example .env
```

Dann die sechs Werte eintragen: `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`,
`DB_PASS`, `DB_CHARSET`. `DB_CHARSET` und `DB_PORT` haben einen Standardwert
(`utf8mb4`, `3306`) und dürfen fehlen.

- `.env` ist in `.gitignore` eingetragen und gehört **nicht** ins Git. Im Git liegt
  nur `.env.example` **ohne** echte Werte.
- Gelesen wird die Datei von `src/helpers/env.php` (`env()` und `env_required()`),
  benutzt von `src/config/database.php`. Beide liegen unter `src/`, also außerhalb
  des Web-Roots.
- Fehlt ein Pflichtwert, wird die Verbindung abgelehnt: im Fehlerprotokoll steht
  eine klare Meldung, der Browser bekommt nur „The database connection could not be
  established.“ — niemals einen Wert, einen Pfad oder einen Stacktrace.
- Nichts anderes im Projekt enthält Zugangsdaten. Wenn du irgendwo einen zweiten
  Satz Werte siehst, ist das ein Fehler.

- PDO läuft mit `ERRMODE_EXCEPTION`, `DEFAULT_FETCH_MODE = FETCH_ASSOC` und
  `EMULATE_PREPARES = false`. Der letzte Punkt bedeutet: die Datenbank bereitet
  die Anweisung selbst vor, deshalb braucht **jeder Wert seinen eigenen
  Platzhalter**.

## Aufbau (kurz)

| Pfad | Inhalt |
| --- | --- |
| `public/` | das einzige web-erreichbare Verzeichnis (Apache-DocumentRoot) |
| `public/index.php` | die HTML-Hülle; berührt die Datenbank nicht |
| `public/api/` | ein JSON-Endpunkt pro Datei: prüfen → Service → JSON |
| `public/assets/` | CSS, JavaScript, Schriften, Icons, Landkarten, Beispiel-CSV |
| `src/config/` | Datenbankverbindung und Zugangsdaten |
| `src/helpers/` | kleine, zustandslose Funktionen |
| `src/services/` | Fachlogik und **alle** SQL-Abfragen |
| `bin/` | drei CSV-Importe für die Kommandozeile (per URL nicht erreichbar) |
| `database/` | SQL-Dateien für phpMyAdmin und die CSV-Quelldateien |
| `docs/` | technische Erklärung, Prüfanleitung, Migrationen, Umgebung |

## Daten (Stand 27.09.2026)

| Tabelle | Zeilen | Inhalt |
| --- | ---: | --- |
| `categories` | 57 | 5 Lernbereiche und 52 Unterkategorien; die Symbole der Bereiche stehen in `icon_svg` in der Datenbank |
| `cards` | 3688 | die Karten, jede auf Deutsch **und** Englisch; 100 davon mit `map_region` (`DE:Bayern`, `EU:FR`, `WORLD:CN`) |
| `users` | 1 | die Konten; Anmelden und Registrieren laufen über `api/auth.php` |
| `user_card_progress` | 29 | ein Datensatz pro Nutzer und Karte |
| `card_exercises` | 39 | die Übungsaufgabe einer Karte, höchstens eine pro Karte |
| `study_sessions` | 2 | die Lern-Sitzungen, Grundlage der Tages-Serie |

`front`/`back` sind die alten NOT-NULL-Spalten und tragen weiterhin die deutschen
Texte; die Sprachspalten `front_de`/`back_de`/`front_en`/`back_en` sind das, was
die Oberfläche benutzt.

Die drei Landkarten-Dateien (`public/assets/maps/*.svg`), das Favicon und die
Schrift werden vom Browser zur Laufzeit geladen und bleiben deshalb Dateien. Alle
drei Landkarten sind aktiv: eine gespeicherte Region wird in der Liste, in der
Dialogvorschau und in der Lernansicht gezeigt.

## Sicherungskopie der Datenbank

Vor dem Aufräumen am 22.09.2026 wurde ein vollständiger Dump (Struktur **und**
Daten) geschrieben nach

```text
/home/user/backups/learning_app_vollstaendig_20260922_162310.sql     (375 185 Bytes)
```

Er liegt bewusst **außerhalb** dieses Repositories und ist nicht eingecheckt. Aus
Windows ist er erreichbar als
`\\wsl.localhost\Ubuntu\home\user\backups\learning_app_vollstaendig_20260922_162310.sql`,
sodass er sich woanders ablegen oder in phpMyAdmin importieren lässt, falls einmal
ein älterer Stand gebraucht wird. Im selben Ordner liegen weitere Sicherungen
(`learning_app_vor_*.sql`), die vor einzelnen Umbauten angelegt wurden.

## Regeln in drei Zeilen

- Nur `public/` ist web-erreichbar. Zugangsdaten, SQL und Fachlogik liegen außerhalb.
- Jede Datenbankabfrage steht in `src/services/` und ist ein Prepared Statement mit
  gebundenen Parametern – niemals zusammengebauter SQL-Text.
- Sichtbare Texte laufen immer über `src/helpers/translations.php` (Deutsch und
  Englisch), und jede API-Antwort benutzt den Umschlag
  `{"success":true,"data":…}` beziehungsweise `{"success":false,"error":{…}}`.

Das Datenbankschema und vorhandene Daten werden **nur nach Absprache** geändert.
Die Dateien in `database/*.sql` sind Vorschläge, die man von Hand in phpMyAdmin
ausführt; die Anwendung selbst ändert das Schema nie.

## Dokumentation

| Datei | Inhalt |
| --- | --- |
| `docs/technik.md` | alles Technische in einem Dokument: Aufbau, Ablauf einer Anfrage, WSL, Ordner, Dateien, Datenbank samt Migrationen, Lernlogik, Frontend, Startanleitung, Handprüfung, offene Fragen |
| `.github/copilot-instructions.md` | die verbindlichen Regeln für Änderungen am Projekt |

Es gibt bewusst nur diese zwei Dokumente. Ältere Fassungen bleiben in der
**Git-Historie** erhalten (`git log --oneline -- docs/`), auch wenn eine Datei
später entfernt wurde.

Ältere Fassungen dieser Dateien und der Dokumentation bleiben in der
**Git-Historie** erhalten (`git log --oneline -- docs/`), auch wenn eine Datei
später ersetzt oder entfernt wurde.
