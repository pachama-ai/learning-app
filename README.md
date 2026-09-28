# Lernanwendung

Eine Web-Anwendung zum Lernen mit Karteikarten. Alle Nutzer teilen sich **einen**
Kartenbestand, jeder hat seinen **eigenen** Lernfortschritt pro Karte. Der Stoff
liegt in Lernbereichen („Energie") und darunter in Unterkategorien („Einheiten").

**Technik:** PHP 8.5 mit PDO, MySQL 8.4, HTML, CSS und reines JavaScript. Kein
Framework, kein Node.js, kein Bundler, kein Bauschritt – die Dateien werden genau
so ausgeliefert, wie sie im Editor stehen.

## Überblick

- Karten anlegen und ändern, auf Deutsch und/oder Englisch, wahlweise in beide
  Richtungen abfragbar
- Karten als CSV importieren (bis 1000 Zeilen und 1 MB, mit Vorschau vor dem
  Schreiben)
- Lernmodus mit Bewertung („Nochmal", „Schwer", „Gut", „Leicht"); daraus ergibt
  sich, wann die Karte wieder dran ist
- Lernstand pro Konto: neu, unsicher, gewusst, fällig, Serie an Tagen
- Suchen und nach Status filtern
- Hell und dunkel, Deutsch und Englisch – beides bleibt im Browser gespeichert
- Landkarten und erzeugte Übungsaufgaben als Sonderformen einer Karte

## Starten

```bash
cp .env.example .env       # dann die sechs Werte eintragen
./start-dev.sh             # http://127.0.0.1:8081/
```

Voraussetzung sind PHP 8.5 und ein MySQL-Server. Die Datenbank anlegen, dann
`database/schema.sql` darauf ausführen (in phpMyAdmin: Datenbank wählen, Reiter
„SQL", Inhalt einfügen, Go):

```sql
CREATE DATABASE IF NOT EXISTS `learning_app`
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

In `.env` stehen `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS` und
`DB_CHARSET`. `DB_PORT` und `DB_CHARSET` haben einen Standardwert (`3306`,
`utf8mb4`) und dürfen fehlen. Die Datei liegt im Projektstamm, also außerhalb des
Web-Roots, und ist in `.gitignore` eingetragen – im Git liegt nur `.env.example`
**ohne** echte Werte.

Kurz prüfen, ob es läuft:

```bash
curl -s http://127.0.0.1:8081/api/health.php
# {"success":true,"data":{"database":"learning_app","message":"Database connection works"}}
```

Das Startskript benutzt den PHP-eigenen Server mit **vier Workern**. Eine Seite lädt
mehrere Dinge gleichzeitig (Lernbereiche, Kategorie, Unterkategorien, Karten); mit
einem einzigen Worker würden diese Anfragen hintereinander abgearbeitet. Beenden mit
`Strg+C`. Anderer Port: `PORT=8082 ./start-dev.sh`. Wer lieber Apache nimmt:
`./start-apache.sh` (Port 8082, `public/` als DocumentRoot).

Die Inhalte (Kategorien und Karten) sind **nicht** im Schema enthalten. Sie kommen
aus einem Dump oder über den Import – siehe `docs/dokumentation.md`.

## Aufbau

| Pfad | Inhalt |
| --- | --- |
| `public/` | das einzige web-erreichbare Verzeichnis; `index.php` ist nur die HTML-Hülle |
| `public/api/` | ein JSON-Endpunkt pro Datei: prüfen → Service → JSON |
| `src/config/` | die Datenbankverbindung (die Werte kommen aus `.env`) |
| `src/helpers/` | kleine, zustandslose Funktionen |
| `src/services/` | Fachlogik und **alle** SQL-Abfragen |
| `bin/` | drei Skripte fürs Terminal, die die CSV-Dateien einmal eingelesen haben (die Dateien sind inzwischen gelöscht, ihr Inhalt steht in der Datenbank) |
| `database/` | `schema.sql` – der Aufbau der Datenbank |
| `docs/` | `dokumentation.md` – Aufbau, Datenbank, Lernlogik, offene Punkte |

## Regeln in drei Zeilen

- Nur `public/` ist web-erreichbar. Zugangsdaten, SQL und Fachlogik liegen außerhalb.
- Jede Datenbankabfrage steht in `src/services/` und ist ein Prepared Statement mit
  gebundenen Parametern – niemals zusammengebauter SQL-Text.
- Sichtbare Texte laufen immer über `src/helpers/translations.php`, und jede
  API-Antwort benutzt den Umschlag `{"success":true,"data":…}` beziehungsweise
  `{"success":false,"error":{…}}`.

`database/schema.sql` legt nur Tabellen an (`IF NOT EXISTS`, kein `DROP`, kein
`ALTER`). Sie und die Daten werden **nur nach Absprache** geändert; die Anwendung
selbst ändert das Schema nie.

## Dokumentation

- **`docs/dokumentation.md`** – Aufbau, Datenbank und Lernlogik, Start im Detail,
  Eigenheiten und offene Punkte, Sicherungskopien
- **`.github/copilot-instructions.md`** – die verbindlichen Regeln für Änderungen
  am Projekt

Ältere Fassungen der Dokumentation bleiben in der Git-Historie erhalten
(`git log --oneline -- docs/`).
