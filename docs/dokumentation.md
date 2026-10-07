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
- **Satzbeispiele als Varianten.** Eine Karte kann mehrere Formulierungen derselben
  Regel tragen (Grammatik: fünf Beispielsätze). Beim Lernen wird zufällig eine
  gezeigt – möglichst nicht zweimal hintereinander dieselbe. Der Lernstand bleibt an
  der Karte, nicht am Satz.
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
| `database/` | `schema.sql` – der Aufbau der Datenbank. Dazu Skripte, die von Hand ausgeführt werden: `card_variants.sql` (die Tabelle der Satzbeispiele) und Änderungen an Inhalten, etwa die englischen Kategorienamen. |
| `bin/` | Drei Skripte fürs Terminal, die CSV-Dateien einlesen: `import_cards_csv.php` (Karten, wahlweise mit Satzbeispielen), `import_energy_cards.php` (Karten unter einen Lernbereich), `import_english_csv.php` (der English-Baum). |
| `docs/` | Diese Datei. |

Nur `public/` ist über den Webserver erreichbar. `src/` liegt daneben, damit
Zugangsdaten und Fachlogik nicht abrufbar sind. `public/index.php` ist nur die
Hülle – sie berührt die Datenbank nicht, alle Inhalte kommen per JavaScript aus den
Endpunkten.

## 5. Die Datenbank

Sieben Tabellen. Der Aufbau steht vollständig in **`database/schema.sql`**.

| Tabelle | Zeilen | Inhalt |
| --- | ---: | --- |
| `users` | 2 | die Konten |
| `categories` | 88 | 8 Lernbereiche und 80 Unterkategorien, alle mit Besitzer |
| `cards` | 7859 | die Karten; 100 mit Landkarte, 284 nur auf Deutsch |
| `card_variants` | 1500 | die Satzbeispiele einer Karte, 0 bis n je Karte |
| `user_card_progress` | 115 | eine Zeile je Nutzer und Karte – der Lernstand |
| `study_sessions` | 21 | eine Zeile je abgeschlossener Lernrunde, Grundlage der Serie |
| `card_exercises` | 39 | die Übungsaufgabe einer Karte, höchstens eine pro Karte |

(Zahlen vom 7.10.2026.)

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
- `card_variants.card_id` ist `ON DELETE CASCADE`: wird eine Karte gelöscht (etwa
  beim Ersetzen einer Liste), verschwinden ihre Satzbeispiele mit.
- In `card_variants` ist `(card_id, variant_number)` eindeutig und **nicht**
  `variant_key`: derselbe Schlüssel („G001-V1") kommt bei jedem Konto wieder vor, das
  eine eigene Kopie derselben Liste hat.
- Der Lernfortschritt hängt **nicht** an einer Variante. Es gibt in
  `user_card_progress` bewusst keine Spalte dafür: gelernt wird die Karte, nicht der
  Satz. Gezeigt wird ein Beispielsatz, gezählt wird die Karte.

Bis zum 28.09.2026 lag neben `schema.sql` eine SQL-Datei je Schritt: Kategorien
anlegen, Inhalts- und Sprachspalten, Konten, Besitzer, Lernsitzungen, Aufgaben,
Aufgabenparameter und die Kategorie einer Sitzung. Diese Einzeldateien sind durch
`schema.sql` ersetzt worden; ihr genauer Wortlaut steht weiterhin in der
Git-Historie (zuletzt vollständig im Commit `1145ffc`). Eine dieser Dateien war
außerdem keine Struktur-, sondern eine Datenänderung: allen vorhandenen
Kategorien wurde nachträglich ein Besitzer zugewiesen.

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

Zwei Quellen: Karten, die man in der Anwendung anlegt, und die Listen, die einmal
als CSV-Datei daneben lagen. Diese **Quelldateien sind am 28.09.2026 gelöscht
worden** – sie waren nur die Rohfassung, ihr Inhalt steht in der Datenbank. Dort
sind es ganz normale Karten, die sich wie jede andere bearbeiten lassen.

Damit die Löschung keine Lücke ist, wurde nachgezählt: die 500 Vokabeln je Niveau
(B1 bis C2), 400 Energie-Fachbegriffe, 200 Redewendungen, 162 unregelmäßige Verben
und 54 Zeitformen füllten damals genau die acht Unterkategorien des Bereichs
„English" (2816 Karten), und die sieben Informatik-Listen bildeten genau die sieben
Unterkategorien von „Informatik" (172 Karten).

Wie die Dateien hießen, steht hier, weil es zeigt, woher die Themen kommen. Acht
davon gab es in zwei Formen: als bereinigte Liste (`English;Deutsch;Wortart;Level`)
und als daraus gebaute Importdatei (`category,front,back,front_de,back_de,
front_en,back_en,is_bidirectional`). Eine Datei und ihre `_bereinigt`-Fassung waren
also nicht zwei Versionen desselben Inhalts, sondern zwei Schritte eines Weges:
`b1_vokabelliste_500_bereinigt.csv` → `b1_vokabelliste.csv` (ebenso B2, C1, C2),
`englische_redewendungen_200_bereinigt.csv` → `redewendungen.csv`,
`unregelmaessige_verben_gesamt_bereinigt.csv` → `unregelmaessige_verben.csv`,
`tennet_energie_fachvokabular_mit_kategorien_bereinigt.csv` →
`energie_fachvokabular.csv` und `englische_zeiten_uebersicht_bereinigt.csv` →
`zeitformen.csv`.

Dazu kamen sieben Dateien, die schon im Importformat waren: Betriebssysteme, SQL,
Excel, Datenbanken, Git, Linux und Hardware/Netzwerke.

Gelesen hat sie damals `bin/`. Später sind diese Werkzeuge wieder in Gebrauch
gekommen – siehe unten. Neue Karten kommen außerdem über den Import-Dialog in der
Anwendung; der nimmt jede CSV mit der Kopfzeile
`front_de;back_de;front_en;back_en;is_bidirectional`. Eine Beispieldatei dafür
liegt weiterhin unter `public/assets/samples/`.

### Was ab dem 3.10.2026 dazukam

Für diese Importe lagen die CSVs wieder im Projektstamm; gelesen hat sie
`bin/import_cards_csv.php` (Karten, wahlweise mit Satzbeispielen) oder
`bin/import_energy_cards.php` (Karten unter einen Lernbereich). Nach dem Import sind
sie – wie die alten – wieder **gelöscht** worden (Stand 7.10.2026): ihr Inhalt steht in
der Datenbank. Die Dateien zu B1–C2 und zur Grammatik liegen zusätzlich in der
Git-Historie; die Datei mit den Karten „Atome, Teilchen & Elektrizität" wurde vor dem
Löschen nie committet und steht nur noch in der Datenbank. Wer eine Liste erneut
einlesen will, legt die Datei einfach wieder daneben; beide Werkzeuge nehmen jeden
Pfad.

| Datum | Was | Karten | Wohin |
| --- | --- | ---: | --- |
| 3.10. | Energie-Ergänzungen: Elektrik, Transformatoren | 8 | `Energiegrundlagen & Energiewende` |
| 4.10. | B1–C2 mit Beispielsätzen (ersetzt die alten Listen, beide Konten) | 4000 | `English` |
| 5.10. | Grammatik mit je fünf Satzvarianten (beide Konten) | 300 | `English Grammar` (neu) |
| 5.10. | Elektrotechnik-Grundlagen: Blindleistung, Skin-Effekt, Transformator, Konverter, Generator | 5 | `Energiegrundlagen & Energiewende` |
| 5.10. | Antwort zur Leistungselektronik umgeschrieben | 1 geändert | `Energiegrundlagen & Energiewende` |
| 6.10. | Atome, Teilchen & Elektrizität | 39 | neue Unterkategorie in `Energy` |

Nicht alles ist zweisprachig: 9 der 88 Kategorien haben kein `name_en`, und 284
Karten haben keinen englischen Text. Die App fällt dann auf den deutschen Text
zurück (`NULL` heißt „nimm `name`") und markiert solche Karten mit „nur Deutsch".
Das ist so gewollt, es fehlt einfach noch die Übersetzung.

## 8. Eigenheiten und offene Punkte

Nichts davon ist kaputt, und nichts davon wird ohne Rückfrage geändert. Es steht
hier, damit sich niemand wundert.

**Zwei Bäume namens „Energy"**

Nutzer 6 hat zwei Lernbereiche „Energy": den aktiven (id 3, fünf Unterkategorien) und
eine ältere Kopie (id 193, 15 Unterkategorien, 436 Karten). Die Kachel der Kopie ist
über `.area-card[data-area-id="193"]` in `app.css` ausgegraut (`opacity: 0.38`,
`filter: grayscale(1)`). Die Kopie wurde bewusst behalten und nicht gelöscht – in ihr
wird nicht gelernt.

**Irreführende Ausgabe im Energie-Import**

`bin/import_energy_cards.php` zeigt in seinem Plan immer einen Block „STEP A – delete
the subcategories of the target area" samt der Zahl der Karten, die dabei verschwänden.
Gelöscht wird aber ausschließlich mit `--wipe-subcategories`: die einzigen
`DELETE`-Anweisungen des Skripts stehen hinter `if ($wipe)`. Ohne den Schalter ist der
Block eine Auskunft und keine Ankündigung – der Lauf selbst meldet dann
„cards deleted: 0". Wer den Text schärfen will, findet ihn in `import_print_step_a()`.

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
