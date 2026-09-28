# Lernkartei – Dokumentation

Hier steht das Genauere zur Anwendung: Aufbau, Datenbank, Lernlogik, Start und die
Ecken, die man kennen sollte. Die `README.md` ist der kurze Einstieg, diese Datei
geht ins Detail.

---

## 1. Was ist das?

Eine Web-Anwendung zum Lernen mit Karteikarten. Man legt eine Karte mit Vorder- und
Rückseite an und wiederholt sie so lange, bis sie sitzt.

Alle Nutzer teilen sich denselben Kartenbestand. Den Lernfortschritt hat jeder für
sich: Was für mich „gewusst" ist, kann für dich noch „unsicher" sein. Deshalb steht
der Inhalt in `cards` und der Fortschritt in `user_card_progress` – zwei Dinge,
zwei Tabellen.

## 2. Funktionen

- **Lernbereiche und Unterkategorien anlegen.** Die Oberfläche kennt zwei Ebenen
  („Energie" → „Einheiten"). Die Datenbank könnte tiefer, die Anwendung nutzt das
  nicht.
- **Karten anlegen und ändern.** Deutsch und/oder Englisch, wahlweise in beide
  Richtungen abfragbar. Eine Karte kann eine Landkarte zeigen, auf der eine Region
  hervorgehoben ist.
- **Karten importieren.** Über einen Dialog (CSV bis 1000 Zeilen und 1 MB). Vor dem
  Schreiben zeigt die App, welche Zeilen in Ordnung sind und welche nicht.
- **Lernen mit Bewertung.** Frage, Umdrehen, dann „Nochmal", „Schwer", „Gut" oder
  „Leicht". Daraus ergibt sich, wann die Karte wieder dran ist.
- **Lernstand ansehen.** Neu, unsicher, gewusst, fällig und die Serie an Tagen.
- **Suchen und filtern.** In der Kartenliste nach Text und nach Status.
- **Hell und dunkel, Deutsch und Englisch.** Beides bleibt im Browser gespeichert.
- **Übungsaufgaben als Sonderfall.** Eine Karte kann statt einer festen Frage eine
  erzeugte Aufgabe sein (z. B. Einmaleins) – die Zahlen sind bei jeder Anzeige neu.

## 3. Technik

| | |
| --- | --- |
| Server | PHP 8.5 mit PDO |
| Datenbank | MySQL 8.4 (`learning_app`, utf8mb4 / utf8mb4_unicode_ci) |
| Browser | HTML, CSS und reines JavaScript |
| Sonst | kein Framework, kein Node.js, kein Bundler, kein Bauschritt |

Die Dateien werden genau so ausgeliefert, wie sie im Editor stehen. Der Browser
redet nie direkt mit der Datenbank, sondern fragt einen JSON-Endpunkt in PHP. Jede
Abfrage steht in `src/services/` und ist ein Prepared Statement mit gebundenen
Parametern. Alle sichtbaren Texte stehen in `src/helpers/translations.php`.

**Zu den PDO-Optionen:** `ERRMODE_EXCEPTION`, `DEFAULT_FETCH_MODE = FETCH_ASSOC`
und `EMULATE_PREPARES = false`. Der letzte Punkt ist der wichtigste: die Datenbank
bereitet die Anweisung selbst vor. Deshalb braucht **jeder Wert seinen eigenen
Platzhalter** – derselbe Name darf in einer Anweisung nicht zweimal vorkommen,
sonst gibt es einen Fehler.

## 4. Aufbau

| Ordner | Wofür |
| --- | --- |
| `public/` | Das Einzige, was der Webserver ausliefert: die HTML-Hülle, die JSON-Endpunkte und die Bilder, Schriften und Landkarten. |
| `public/api/` | Ein Endpunkt pro Datei: prüfen → Service aufrufen → JSON zurück. |
| `src/config/` | Die Datenbankverbindung. Zugangsdaten stehen in `.env`, nicht hier. |
| `src/helpers/` | Kleine, zustandslose Funktionen (Umgebungsvariablen, Antworten, Übersetzungen, Sitzung). |
| `src/services/` | Die Fachlogik und **alle** SQL-Abfragen. |
| `database/` | `schema.sql` und `import/` mit den CSV-Quelldateien. |
| `bin/` | Drei Skripte fürs Terminal, die die CSV-Dateien einmal eingelesen haben. |
| `docs/` | Diese Datei. |

Nur `public/` ist über den Webserver erreichbar. `src/` liegt daneben, damit
Zugangsdaten und Fachlogik nicht abrufbar sind. `public/index.php` ist nur die
Hülle – sie berührt die Datenbank nicht, alle Inhalte kommen per JavaScript aus den
Endpunkten.

## 5. Die Datenbank

Sechs Tabellen. Der Aufbau steht vollständig in **`database/schema.sql`**.

| Tabelle | Zeilen | Inhalt |
| --- | ---: | --- |
| `users` | 1 | die Konten |
| `categories` | 61 | 5 Lernbereiche und 56 Unterkategorien, alle mit Besitzer |
| `cards` | 3772 | die Karten; 100 mit Landkarte, 84 nur auf Deutsch |
| `user_card_progress` | 55 | eine Zeile je Nutzer und Karte – der Lernstand |
| `study_sessions` | 7 | eine Zeile je abgeschlossener Lernrunde, Grundlage der Serie |
| `card_exercises` | 39 | die Übungsaufgabe einer Karte, höchstens eine pro Karte |

(Zahlen vom 28.09.2026.)

Ein paar Eigenheiten, die man wissen sollte:

- `categories.parent_id` macht den Baum: `NULL` ist ein Lernbereich, sonst zeigt
  die Spalte auf den Bereich.
- `cards` hat **keine** Besitzerspalte. Wem die Kategorie gehört, dem gehört die
  Karte.
- `front` und `back` sind die alten NOT-NULL-Spalten und tragen weiterhin den
  deutschen Text. Die Oberfläche benutzt die Sprachspalten
  `front_de`/`back_de`/`front_en`/`back_en`.
- `categories.icon_svg` ist `MEDIUMTEXT` und nicht `TEXT` – eine hochgeladene
  Zeichnung darf bis 350 KB groß sein, in `TEXT` passten nur 64 KB.
- `user_card_progress` hat `ON DELETE CASCADE` auf beiden Fremdschlüsseln. Wird ein
  Konto oder eine Karte gelöscht, geht der Lernstand mit – das ist gewollt.
- `study_sessions.category_id` ist `ON DELETE SET NULL`: wird die Unterkategorie
  gelöscht, bleibt die Runde für die Serie erhalten.

### Wie die App entscheidet, was fällig ist

Das rechnet allein der Server (`src/services/review_service.php`), der Browser
zeigt nur an, was zurückkommt. Es gibt genau drei Zustände:

- **neu** – keine Fortschrittszeile oder eine, die nie gelernt wurde
- **unsicher** – in der Lernphase **oder** gerade fällig
- **gewusst** – gelernt und noch nicht fällig

Dazu kommt das Fälligkeitsdatum. Wichtig: „fällig" ist **keine** vierte Stufe,
sondern eine Teilmenge von „unsicher" – eine fällige Karte ist nie „gewusst".

Gelernt wird zuerst, was fällig ist, danach, was noch nie dran war. Ist beides
leer, kommen Karten an die Reihe, die eigentlich noch nicht fällig wären, damit
eine Runde nicht leer bleibt.

Der Abstand wächst mit den Bewertungen: Jede Bewertung verändert `stability` um
einen Faktor, und `difficulty` verschiebt diesen Faktor langsam nach oben oder
unten. „Nochmal" ist die einzige Bewertung, die einen Aussetzer zählt. Die
Startwerte und Grenzen stehen als Konstanten oben in `review_service.php`.

## 6. Starten

```bash
cp .env.example .env      # dann die Werte eintragen
./start-dev.sh            # http://127.0.0.1:8081/
```

In `.env` stehen sechs Werte: `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`,
`DB_PASS`, `DB_CHARSET`. `DB_PORT` und `DB_CHARSET` haben einen Standardwert
(`3306`, `utf8mb4`) und dürfen fehlen. Die Datei liegt im Projektstamm, also neben
`public/` und damit außerhalb des Web-Roots. Im Git liegt nur `.env.example`,
nie echte Werte.

Voraussetzungen: PHP 8.5 und ein MySQL-Server. Datenbank und Tabellen:

```sql
CREATE DATABASE IF NOT EXISTS `learning_app`
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

Dann `database/schema.sql` darauf ausführen – in phpMyAdmin: Datenbank wählen,
Reiter „SQL", Inhalt einfügen, Go. Danach sind die sechs Tabellen da; Inhalte
kommen aus einem Dump oder über den Import.

Kurz prüfen, ob es läuft:

```bash
curl -s http://127.0.0.1:8081/api/health.php
# {"success":true,"data":{"database":"learning_app","message":"Database connection works"}}
```

Ein anderer Port geht mit `PORT=8082 ./start-dev.sh`. Statt des PHP-Servers kann
man auch Apache nehmen: `./start-apache.sh` (Port 8082, `public/` als
DocumentRoot).

## 7. Woher die Inhalte kommen

Zwei Quellen: Karten, die man in der Anwendung anlegt, und die CSV-Dateien in
`database/import/`. Dort liegen **23** Dateien, und sie haben zwei Formen:

- **Das Importformat** – Kopfzeile
  `category,front,back,front_de,back_de,front_en,back_en,is_bidirectional`. Das ist
  das Format, das `bin/import_cards_csv.php` liest.
- **Die bereinigten Quelllisten** – Kopfzeile `English;Deutsch;Wortart;Level`. Sie
  haben zwei Spalten mehr und sind das, woraus die Importdateien gebaut wurden.

Für acht Themen gibt es beide Formen, eine Datei und ihre `_bereinigt`-Fassung sind
also **nicht** zwei Versionen desselben Inhalts, sondern zwei Schritte eines Weges:

| Bereinigte Liste | Daraus gebaute Importdatei |
| --- | --- |
| `b1_vokabelliste_500_bereinigt.csv` | `b1_vokabelliste.csv` |
| `b2_vokabelliste_500_bereinigt.csv` | `b2_vokabelliste.csv` |
| `c1_vokabelliste_500_bereinigt.csv` | `c1_vokabelliste.csv` |
| `c2_vokabelliste_500_bereinigt.csv` | `c2_vokabelliste.csv` |
| `englische_redewendungen_200_bereinigt.csv` | `redewendungen.csv` |
| `unregelmaessige_verben_gesamt_bereinigt.csv` | `unregelmaessige_verben.csv` |
| `tennet_energie_fachvokabular_mit_kategorien_bereinigt.csv` | `energie_fachvokabular.csv` |
| `englische_zeiten_uebersicht_bereinigt.csv` | `zeitformen.csv` |

Die übrigen sieben stehen für sich und sind schon im Importformat:
`betriebssysteme.csv`, `datenbank_konzepte.csv`, `excel_funktionen.csv`,
`git_github.csv`, `hardware_netzwerke.csv`, `linux_wsl.csv`, `sql_grundlagen.csv`.

Die Skripte in `bin/` haben diese Dateien einmal in die Datenbank geschrieben.
Danach sind es ganz normale Karten und lassen sich wie jede andere bearbeiten.

## 8. Eigenheiten und offene Punkte

Nichts davon ist kaputt, und nichts davon wird ohne Rückfrage geändert. Es steht
hier, damit sich niemand wundert.

**Spalten, die niemand liest**

| Spalte | Befund |
| --- | --- |
| `categories.color` | wird nirgends gelesen (die Palette kommt aus dem Stylesheet) |
| `categories.description_en` / `_de` | kein Treffer im Code |
| `users.role` | wird beim Anlegen geschrieben, aber nirgends gelesen |
| `card_exercises.range_min` / `range_max` | kein Treffer im Code; die Zahlen stehen heute in `exercise_params` |

**Tote Stellen im Stylesheet**

- `.grain`: 6 Regeln in `app.css`, aber keine Verwendung.
- `.is-linked` / `.is-linking`: werden in `app.js` gesetzt, haben aber keine
  CSS-Regel – also andersherum tot als man denkt.

**Unbenutzte Übersetzungsschlüssel**

Eine wörtliche Suche findet 93 Schlüssel, die nirgends im Code stehen. Das ist
**keine** Zahl unbenutzter Schlüssel: Viele werden erst zur Laufzeit
zusammengesetzt (`'cards.status.' + name + 'Hint'`, `'exercise.param.' + name`,
`'exercise.option.' + option`). Eine belastbare Liste gibt es noch nicht; wer sie
will, muss diese vier Bauweisen mitdenken.

**Kleinigkeit aus der Browser-Konsole**

Beim Umschalten von hell auf dunkel kann `Transition was aborted because of
invalid state` im Protokoll stehen. Das kommt aus dem Aufdeck-Effekt des
Themes (`startViewTransition`) und wird im Code schon abgefangen – die Anzeige
ist in Ordnung, es ist nur eine protokollierte Absage.

**Was früher offen war und erledigt ist**

- Externe Schriften: `Inter` und `Source Serif 4` liegen als Datei in
  `public/assets/fonts/`, es geht keine Anfrage mehr an einen fremden Dienst.
- Tote CSS-Regeln (`.data-pill`, `.row__arrow`, `.row__progress`, `.row__count`,
  `.row__play`, `.card-tools__label`) und fünf unbenutzte CSS-Variablen sind raus.
- `public/assets/icons/informatik.svg` ist weg, es liegt nur noch
  `browser_icon.svg` dort.

## 9. Sicherungskopien

Die Dumps liegen **außerhalb** des Repos unter `/home/user/backups/` und sind
nicht eingecheckt. Die älteste und kleinste ist ein vollständiger Dump vom
22.09.2026 (`learning_app_vollstaendig_20260922_162310.sql`, 375 185 Bytes); daneben
liegen fünf Sicherungen `learning_app_vor_*.sql`, die vor einzelnen Umbauten
angelegt wurden, und ein Ordner `arbeitsstand_20260926`.

Für einen Neuaufbau: erst `database/schema.sql`, dann einen Dump einspielen. Der
Dump vom 22.09. ist **älter** als einige Schemaänderungen – die Struktur kommt
deshalb aus `schema.sql` und nicht aus dem Dump.

## 10. Wenn VS Code aussteigt (WSL)

Das gehört nicht zur Anwendung, sondern zum Rechner. Notiert, weil es lange gesucht
wurde.

**Symptom:** Mitten in der Arbeit steht „WSL: Disconnected – Reload Window".
Neu laden hilft, aber es kommt wieder. Die Anwendung selbst läuft weiter.

**Ursache:** Der Bereitschafts-Timer von Windows. Geht Windows in den Standby, wird
die WSL-VM eingefroren. VS Code merkt das erst, wenn der Socket in einen Timeout
läuft – und fragt dann nach einem Neuladen.

**Behoben mit:**

```bash
powercfg.exe /change standby-timeout-ac 0
powercfg.exe /change standby-timeout-dc 0
powercfg.exe /change hibernate-timeout-ac 0
powercfg.exe /q SCHEME_CURRENT SUB_SLEEP    # zur Kontrolle: STANDBYIDLE = 0
```

Zwei Hinweise dazu: Die Werte hängen am **aktiven Energieplan** – wechselt man den
Plan, sind die alten Timer zurück. Und ein **manueller** Standby trennt die
Verbindung weiterhin, das ist normal.

**Wenn es wieder auftritt**, von beiden Seiten schauen:

```bash
# Hat der Rechner wirklich geschlafen?
powershell.exe -NoProfile -Command "Get-WinEvent -FilterHashtable @{LogName='System'; ProviderName='Microsoft-Windows-Power-Troubleshooter'} -MaxEvents 10 | Select-Object TimeCreated,Id | Format-List"

# Was hat die Gegenstelle gesehen?
grep -E "socket timeout event|is unresponsive|reconnect" ~/.vscode-server/data/logs/<zeitstempel>/window1/renderer.log
```

`uptime -s`, `free -h` und `df -h` zeigen, ob stattdessen die WSL-Distro neu
gestartet ist oder der Speicher voll war – beides war hier nie der Fall.

**Kein Fehler, nur Rauschen** (erscheint bei jedem Start, nicht verfolgen):
`PendingMigrationError: navigator is now a global in nodejs`,
`Error: EEXIST ... vscode.lock`, `Refused to load resource ... seti.woff`.
