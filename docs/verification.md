# Prüfanleitung

Ein Durchgang von Anfang bis Ende, mit dem sich die ganze Anwendung prüfen lässt:
die Datenbankverbindung, die API, die Seite im Browser, die Anmeldung und der
Lernmodus.

Alles hier ist **lesend**, außer den Stellen, die ausdrücklich als „verändert
Daten“ markiert sind. Diese Werte (Ids, Anzahlen) sind der Stand vom 27.09.2026.

---

## 0. Anwendung starten

```bash
cd /home/user/projects/learning-app
./start-dev.sh
```

Dann <http://127.0.0.1:8081/> öffnen. Anderer Port:

```bash
PORT=8082 ./start-dev.sh
```

Das Skript startet `php -S` mit **vier Workern** (`PHP_CLI_SERVER_WORKERS=4`). Eine
Seite fragt mehrere Dinge gleichzeitig ab; mit einem einzigen Worker werden diese
Anfragen hintereinander beantwortet und die Seite wartet auf ihre Summe.

Ohne Skript, mit einem Worker:

```bash
php -S 127.0.0.1:8081 -t public
```

> Der Apache-Dienst, der auf diesem Rechner außerdem läuft, liefert
> `/var/www/html` aus und hat mit diesem Projekt nichts zu tun.

---

## A. Die API antwortet

```bash
curl -s http://127.0.0.1:8081/api/health.php
```

Erwartet (geprüft am 27.09.2026):

```json
{"success":true,"data":{"database":"learning_app","message":"Database connection works"}}
```

Das beweist die PDO-Verbindung **und** dass die richtige Datenbank gewählt wurde.

```bash
curl -s "http://127.0.0.1:8081/api/bootstrap.php?language=de"
```

Erwartet: ein Bündel mit `areas`, `children`, `cards`, `summaries`, `streak`,
`streaks`, `has_user`, `content_languages`, `language`. Gemessen mit einem
angemeldeten Konto: 5 Bereiche, Karten und Zusammenfassungen für 52 Kategorien,
`"has_user":true`, `"content_languages":["de","en"]`.

> Ohne Anmeldung kommen `areas`, `children`, `cards` und `summaries` leer zurück
> und `has_user` ist `false` – das ist die ehrliche Antwort, wenn niemand
> angemeldet ist.

```bash
curl -s "http://127.0.0.1:8081/api/cards.php?category_id=101&language=de"
```

Erwartet: die Karten der Unterkategorie 101 mit ihrem Fortschritt, dazu

```json
"summary":{"total":18,"new":12,"unsure":0,"known":6,"due":0}
```

(auch das ist ein gemessener Wert vom 27.09.2026 und hängt davon ab, was zuletzt
gelernt wurde).

```bash
curl -s "http://127.0.0.1:8081/api/review.php?category_id=101"
```

Erwartet: die Warteschlange einer Lernrunde. Gemessen: 12 Einträge,
`"counts":{"due":0,"new":12,"unsure":0,"known":6,"cards":18}`, der erste Eintrag

```json
{"card_id":1180,"direction":"forward","status":"new","is_due":false,
 "preview_minutes":{"again":10,"hard":1152,"good":2304,"easy":4608}}
```

Die vier Minuten gehören zu den vier Bewertungen (Nochmal/Schwer/Gut/Einfach). Sie
kommen vom selben Rechner, der die Bewertung später speichert.

```bash
curl -s "http://127.0.0.1:8081/api/categories.php"
curl -s "http://127.0.0.1:8081/api/categories.php?parent_id=3"
curl -s "http://127.0.0.1:8081/api/categories.php?id=3"
```

Erwartet: 5 Lernbereiche; die 13 Unterkategorien von Energie; beim Einzelaufruf
zusätzlich `icon_svg` und `delete_preview`. In **Listen** kommt `icon_svg` nie vor,
nur `icon_url`.

```bash
curl -s -o /dev/null -w "%{http_code} %{content_type}\n" \
     "http://127.0.0.1:8081/api/category_icon.php?id=3"
```

Erwartet: `200 image/svg+xml; charset=utf-8` (geprüft). Mit demselben `ETag` im
Kopf `If-None-Match` antwortet der Endpunkt `304`.

### Fehlerfälle

```bash
curl -s -w "\n%{http_code}\n" "http://127.0.0.1:8081/api/categories.php?id=abc"
curl -s -w "\n%{http_code}\n" "http://127.0.0.1:8081/api/categories.php?id=999999"
curl -s -w "\n%{http_code}\n" -X PUT "http://127.0.0.1:8081/api/cards.php?category_id=2"
```

Erwartet: `400 invalid_id`, `404 category_not_found`, `405 method_not_allowed` –
geprüft. Keine dieser Antworten darf SQL, einen Dateipfad, einen Klassennamen,
einen Stacktrace oder Zugangsdaten enthalten. Die erste Antwort sieht so aus:

```json
{"success":false,"error":{"code":"invalid_id","message":"The id parameter must be a positive integer."}}
```

---

## B. Die Datenbank selbst

In phpMyAdmin (Datenbank `learning_app`, Reiter **SQL**):

```sql
SHOW TABLES;
DESCRIBE categories;
DESCRIBE cards;
DESCRIBE user_card_progress;
SELECT COUNT(*) FROM categories;
SELECT COUNT(*) FROM cards;
SELECT COUNT(*) FROM users;
```

Erwartet (27.09.2026):

| Prüfung | Erwartung |
| --- | --- |
| Tabellen | `card_exercises`, `cards`, `categories`, `study_sessions`, `user_card_progress`, `users` |
| `categories` | 57 Zeilen, 5 ohne `parent_id` (Lernbereiche), 52 mit |
| `cards` | 3688 Zeilen, jede mit `front_de`/`back_de`/`front_en`/`back_en` |
| `card_exercises` | 39 Zeilen |
| `user_card_progress` | 29 Zeilen |
| `study_sessions` | 2 Zeilen |
| `users` | 1 Zeile |
| `categories.color` | 5 Bereiche haben eine Farbe – sie wird vom Code aber **nicht mehr gelesen** |
| `categories.icon_svg` | nur die 5 Bereiche haben eines |

Es gibt **keine Migration auszuführen**: alle Spalten existieren.

---

## C. Nichts ist fest verdrahtet

```bash
cd /home/user/projects/learning-app

# keine Bereichs- oder Unterkategorienamen im Code
grep -rn "area.mathematics\|area.energy\|area.geography\|area.english" src public || echo "sauber"

# kein Symboldateiname einer Kategorie im Frontend
grep -rn "math_icon\|energy_icon\|geography_icon\|language_icon" public/index.php public/assets/js/app.js || echo "sauber"

# keine Farbtabelle nach Kategorienamen im Stylesheet
grep -rn "cat-mathematics\|cat-energy\|cat-geography\|cat-english" public/assets/css/app.css || echo "sauber"

# der Browser speichert nur Einstellungen, keine Inhalte
grep -n "localStorage\|sessionStorage" public/assets/js/app.js
```

Erwartet: die ersten drei Zeilen melden `sauber`. Die letzte zeigt nur die kleinen
Lesen/Schreiben-Helfer und die drei Schlüssel `lernkartei.theme`,
`lernkartei.language` (localStorage) und `lernkartei.note` (sessionStorage).

---

## D. Die Seite im Browser

<http://127.0.0.1:8081/> öffnen und der Reihe nach durchgehen. Jede Zeile ist eine
Aussage über die Daten, nicht über das Aussehen.

| # | Prüfung | Erwartung |
| --- | --- | --- |
| 1 | Startseite | fünf Kacheln (die Lernbereiche), in der Reihenfolge ihrer Id |
| 2 | Browser-Konsole | keine Fehlermeldung, insbesondere kein 404 |
| 3 | Info-Zeile auf der Kachel | „16 Unterkategorien · 225 Karten“ bei Mathematik; die Zahlen kommen aus SQL |
| 4 | Symbole | jede Bereichskachel zeigt ihre Zeichnung aus `categories.icon_svg` |
| 5 | Sprache umschalten (DE/EN) | alle Beschriftungen, Namen und Statuswörter wechseln; die Wahl überlebt ein Neuladen |
| 6 | Thema umschalten (Mond/Sonne) | hell ↔ dunkel wechselt vollständig; die Wahl überlebt ein Neuladen; beim Laden blitzt kein falsches Thema auf |
| 7 | Bereich öffnen (`index.php?category=3`) | Kopfzone mit Symbol und Namen, links die Sidebar mit allen Bereichen, darunter die 13 Unterkategorien als Zeilen |
| 8 | Zeile einer Unterkategorie | ein Knopf „Lernen“ mit der Anzahl fälliger Karten als Zähler (bei 0 ist er ein stiller Umriss) |
| 9 | Unterkategorie öffnen (`index.php?category=101`) | drei Kennzahl-Kacheln (fällig, sitzt schon, in Folge gelernt), darunter die Kartenliste mit 18 Zeilen |
| 10 | Statuswort je Karte | „Neu“, „Unsicher“ oder „Gewusst“ – als Punkt **und** als Wort, nie nur als Farbe |
| 11 | Suche | erscheint ab 15 Karten; sie filtert sofort in der Liste, ohne Anfrage an den Server |
| 12 | Dialog „+ Karte“ | Pflichtfelder, Sprach-Reiter Deutsch/Englisch, optionale Region, „Speichern & nächste Karte“ |
| 13 | Adresse direkt aufrufen (F5, Lesezeichen) | jede Ansicht lädt richtig, auch ohne Klick von der Startseite |
| 14 | Fußzeile | zwei Pfeile links (auf Seiten ohne Kachelreihe ausgeblendet) und der runde Plus-Knopf in der Mitte |

### Anmelden

1. Kopfzeile → **Anmelden** → mit Name oder E-Mail und Passwort anmelden (oder ein
   Konto anlegen: „Noch kein Konto?“).
2. Erwartet: die Kopfzeile zeigt den Namen und „Abmelden“, die Kennzahlen füllen
   sich, die Statuswörter der Kartenliste werden bunt (bekannt = grün, unsicher =
   warm).
3. **Ohne** Anmeldung: alles Lesen funktioniert, jede Bewertung antwortet aber mit
   `403 no_user_session`. Das ist beabsichtigt – es gibt keinen Ersatz-Nutzer.

### Lernrunde

1. In Unterkategorie 101 auf **Lernen** klicken. Erwartet: die Lernansicht
   öffnet sich, oben der Fortschritt, die Karte mit der Frage.
2. `Leertaste` oder Klick dreht die Karte um; darunter stehen vier Knöpfe
   (Nochmal/Schwer/Gut/Einfach) und unter jedem die Vorschau in Minuten.
3. Taste `1`–`4` antwortet. Erwartet: die Antwort wird **sofort** gespeichert
   (die Kacheln ändern sich nach dem Beenden entsprechend), die nächste Karte kommt.
4. `Pfeil links` nimmt die letzte Antwort zurück.
5. `Escape` fragt nach, wenn schon etwas gespeichert wurde, und beendet sonst direkt.
6. Am Ende erscheint die Zusammenfassung; danach steht in `study_sessions` eine
   Zeile mit `ended_at`.

**Verändert Daten:** Schritte 3–6 schreiben Fortschritt und eine Sitzung. Das ist
gewollt und nicht rückgängig zu machen; für einen reinen Lesetest die Runde nicht
beenden.

### CSV-Import

1. In einer Unterkategorie auf **Import** klicken.
2. Die Beispiel-CSV („Beispieldatei“) herunterladen und im Dialog auswählen.
3. Erwartet: eine Vorschau mit den erkannten Spalten, der Zeilenzahl und markierten
   Problemen – es wird dabei **nichts** geschrieben.
4. Erst der Knopf zum Importieren schreibt in einer Transaktion.

**Verändert Daten.**

---

## E. Datenfluss von Ende zu Ende

```
Browser  --fetch-->  public/api/*.php  --PDO-->  MySQL/MariaDB  --JSON-->  Browser
```

* Der Browser ruft nur `api/*.php` auf. In JavaScript gibt es kein SQL und keinen
  Datenbanktreiber.
* Jede Anweisung in `src/services/` ist ein Prepared Statement mit gebundenen
  Parametern; kein Wert steht im SQL-Text.
* Jede schreibende Antwort enthält die Zeile so, wie sie wirklich gespeichert
  wurde. Danach lädt der Browser die Liste neu.
* Die einzige Ausnahme von „SQL nur in `src/services/`“ ist
  `public/api/category_icon.php`: es liest sein SVG selbst (ebenfalls als Prepared
  Statement), weil es die Datei direkt ausliefern muss statt JSON.

---

## F. Frühere Prüfungen (nicht mehr gültig)

Die vorherige Fassung dieser Anleitung (Stand 21.09.2026) liegt als
`docs/archiv/verification-2026-09-21.md` daneben. Sie beschreibt einen Stand mit
sechs Lernbereichen (`h` und `f`), einer leeren Kartentabelle und ohne Anmeldung.
Diese Erwartungen stimmen nicht mehr; die Datei wird nur noch als Zeitdokument
aufbewahrt.
