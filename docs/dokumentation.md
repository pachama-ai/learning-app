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
- **Nachschlagen: „Energie verstehen".** Eine eigene Seite (`/energy.php`) mit
  Begriffen, Formeln und interaktiven Zeichnungen für Elektrotechnik und
  Energiewirtschaft. Sie berührt die Datenbank nicht und ändert keine Karte; sie ist
  dafür gedacht, in einem zweiten Fenster neben den Karten offen zu stehen.
- **Gemeinsam schreiben: „Story Together".** Eine zweite Seite (`/story.php`), auf der
  genau zwei Konten abwechselnd an einer Geschichte schreiben – ein Beitrag, danach 30
  Minuten Bearbeitungszeit, dann ist die andere Person an der Reihe.
  Über dem Editor steht eine freiwillige Schreibanregung (Grammar Challenge).
  Für alle anderen Konten gibt es die Seite nicht.

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
| `src/data/` | Die Inhalte, die keine Datenbank brauchen: die Wissensmodule der Nachschlageseite „Energie verstehen" und die Aufgaben der Grammar Challenge. Beschreibungen, keine Logik. |
| `database/` | `schema.sql` – der Aufbau der Datenbank. Dazu Skripte, die von Hand ausgeführt werden: `card_variants.sql` (die Tabelle der Satzbeispiele), `story_together.sql` (die beiden Tabellen der gemeinsamen Geschichte) und Änderungen an Inhalten, etwa die englischen Kategorienamen. |
| `bin/` | Drei Skripte fürs Terminal, die CSV-Dateien einlesen: `import_cards_csv.php` (Karten, wahlweise mit Satzbeispielen), `import_energy_cards.php` (Karten unter einen Lernbereich), `import_english_csv.php` (der English-Baum). |
| `docs/` | Diese Datei. |

Nur `public/` ist über den Webserver erreichbar. `src/` liegt daneben, damit
Zugangsdaten und Fachlogik nicht abrufbar sind. `public/index.php` ist nur die
Hülle – sie berührt die Datenbank nicht, alle Inhalte kommen per JavaScript aus den
Endpunkten.

## 5. Die Datenbank

Neun Tabellen. Der Aufbau steht vollständig in **`database/schema.sql`**.

| Tabelle | Zeilen | Inhalt |
| --- | ---: | --- |
| `users` | 2 | die Konten |
| `categories` | 92 | 8 Lernbereiche und 84 Unterkategorien, alle mit Besitzer |
| `cards` | 7777 | die Karten; 100 mit Landkarte, 284 nur auf Deutsch |
| `card_variants` | 1500 | die Satzbeispiele einer Karte, 0 bis n je Karte |
| `user_card_progress` | 259 | eine Zeile je Nutzer und Karte – der Lernstand |
| `study_sessions` | 46 | eine Zeile je abgeschlossener Lernrunde, Grundlage der Serie |
| `card_exercises` | 39 | die Übungsaufgabe einer Karte, höchstens eine pro Karte |
| `stories` | 1 | die gemeinsame Geschichte („Story Together"), genau eine Zeile |
| `story_contributions` | 4 | ein Beitrag je Zug und Geschichte, einer der beiden Konten |

(Stand: 16.10.2026. Gegenüber dem 11.10.2026 sind die Zahlen an drei Stellen
gewachsen – `user_card_progress` von 219 auf 259, `study_sessions` von 40 auf 46,
`story_contributions` von 1 auf 4. Geschrumpft ist eine Stelle: der alte doppelte
Lernbereich „Energy" (id 193) wurde mit seinen 15 Unterkategorien und 436 Karten
gelöscht. Gewachsen ist dafür eine andere: der neue Lernbereich **PRIO** entstand mit
achtzehn Unterkategorien und 281 Karten (siehe §7). Deshalb stehen in `categories`
92 statt 89 Zeilen – die 89 waren 8 Lernbereiche und 81 Unterkategorien, jetzt sind
es 8 und 84 – und in `cards` 7777 statt 7920. Am Abend kamen zwölf weitere Karten in
den Bereich `Energy` (siehe §7). Genaueres dazu in §7 und §10.)

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
- `cards.image_front` und `cards.image_back` tragen **nur den Dateinamen** eines
  Fotos, nicht den Pfad. Die Dateien liegen in `public/uploads/cards/`; der Name
  wird beim Hochladen zufällig erzeugt. Fehlen die beiden Spalten, arbeitet die
  Anwendung ohne Fotos weiter, und der Kartendialog meldet das beim Hochladen.
  Angelegt werden sie mit `database/card_images.sql` (von Hand auszuführen).
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
| 9.10. | Grund- und Aufbauwortschatz / Basic and Intermediate Vocabulary | 353 | neue Unterkategorie in `English` (Konto 6), beidseitig |
| 9.10. | Stromnetze / Power Grids | 130 | neue Unterkategorie in `English` (Konto 6), nur deutsche Richtung |
| 9.10. | Grund- und Aufbauwortschatz durch Wörterbuch-Karten ersetzt (statt „Was bedeutet …?“) | 353 ersetzt | `English` (Konto 6) |
| 11.10. | Energy basics & energy transition (Grundlagen und Energiewende) | 107 | `Energiegrundlagen & Energiewende` (Energie, id 3), einseitig |
| 11.10. | Ältere Energie-Listen erneut durchgesehen: nur die Karten, die wirklich fehlten | 69 | 26 in `Energiegrundlagen & Energiewende` und 43 in `Stromnetz & Systembetrieb` (Energie, id 3), einseitig |
| 16.10. | Acht Energie-Listen als neuer Lernbereich PRIO | 146 | neue Unterkategorien in `PRIO` (id 224, Konto 6), einseitig |
| 16.10. | Zweite PRIO-Runde: Stromnetz, Strommarkt, Strompreise, Netzentgelte, Felder & Blindleistung, Energiegesetze (vier Unterkategorien) | 117 | neun neue Unterkategorien in `PRIO`, einseitig |
| 16.10. | „Netzbetrieb & Systemstabilität" durch die aktualisierte Fassung ersetzt | 26 (22 ersetzt) | `PRIO`, Unterkategorie id 232 |
| 16.10. | Achte Datei der PRIO-Runde nachgezogen (lag zuerst nicht vor) | 14 | neue Unterkategorie `Energiedaten, Strommix & Energiewende` in `PRIO` |
| 16.10. | Einheiten, Energieformen und Atomaufbau aus einer neuen Liste | 12 | 8 in `Energiegrundlagen & Energiewende` und 4 in `Atome, Teilchen & Elektrizität` (Energy, id 3), einseitig |

**Der neue Lernbereich „PRIO" (16.10.2026).** Der Bereich entstand über die
Anwendung selbst (`POST /api/categories.php`, Name/name_de/name_en = `PRIO`), damit
die vorhandene Prüfung greift – eine zweite Anfrage mit demselben Namen antwortet
mit `category_exists` (409). Seine acht Unterkategorien und 146 Karten kamen danach
über `bin/import_energy_cards.php`, Datei für Datei, jede in ihrer eigenen
Transaktion. Die acht Dateien nannten in `parent_category` noch „Energie"; das
Werkzeug nimmt `--area-id` seit diesem Tag auch bei einer Datei MIT dieser Spalte an
und lässt die Id gewinnen. So landet derselbe Inhalt unter einer neuen Kategorie,
ohne die Dateien umzuschreiben – und ohne dass ein Lauf versehentlich den
bestehenden Bereich `Energy` anfasst. Der bestehende Bereich blieb unberührt:
847 Karten und 118 Fortschrittszeilen vorher wie nachher, in `PRIO` steht keine
einzige Fortschrittszeile (in PRIO wurde noch nicht gelernt).

Die englischen Namen der acht Unterkategorien stehen nicht in den Dateien: deren
Spalte `subcategory` ist deutsch, und der Importeur schreibt `name` = `name_de` =
diesen Wert und lässt `name_en` leer (dieselbe Regel wie bei jedem anderen Import,
siehe §5). Gesetzt wurden sie mit `database/prio_area_setup.sql` – einer Datei, die nur
das tut. Ein zweiter Lauf ist harmlos, weil Namen nur gesetzt werden, wo sie leer sind.

**Die zweite PRIO-Runde (16.10.2026, nachmittags).** Sechs neue Dateien kamen über
`bin/import_energy_cards.php --area-id=224` dazu: 117 Karten in neun Unterkategorien
(die Datei zu den Energiegesetzen enthält vier: Gesetze & Energiepolitik, EU &
International, Behörden & Institutionen, Energieunternehmen). Die englischen Namen
dieser neun stehen ebenfalls in `database/prio_area_setup.sql`.

Die aktualisierte Fassung von „Netzbetrieb & Systemstabilität" (26 statt 22 Karten,
vier neue Fragen zur Regelenergie) wurde **ersetzt, nicht ergänzt**. Dafür gibt es
schon `bin/import_cards_csv.php --replace`: geleert und neu gefüllt wird genau die
Unterkategorie, die die Datei nennt – die anderen sechzehn von PRIO blieben unberührt.
Der Lernfortschritt wandert dabei über die deutsche Vorderseite auf die neue Karte
(hier war keiner zu übertragen: PRIO hatte 0 Fortschrittszeilen). Die Datei dafür
liegt als Arbeitskopie in `database/import/prio_netzbetrieb_systemstabilitaet_de_en.csv`
– im Format dieses Werkzeugs (`category;front;back;front_de;…`), weil die
Originaldatei die Spalte `subcategory` trägt.

**Die achte Datei kam später.** `prio_energiedaten_strommix_energiewende_de_en.csv`
lag beim ersten Lauf noch nicht im Projekt (danach gesucht wurde im Projektstamm, in
`database/import`, im Benutzerverzeichnis und in der Git-Historie). Sie erschien um
15:04 und wurde noch am selben Tag nachgezogen: 14 Karten, Unterkategorie
„Energiedaten, Strommix & Energiewende" (id 242, englisch „Energy data, power mix &
energy transition"). PRIO steht damit bei achtzehn Unterkategorien und 281 Karten.

**Nachgeprüft am 16.10.2026.** Der Inhalt wurde Karte für Karte mit der jeweiligen
Datei verglichen – dieselben Texte in beiden Sprachen, dieselbe Reihenfolge, keine
fehlende, keine zusätzliche und keine doppelte Karte:

| Unterkategorie | Datei | Karten in Datei und Datenbank |
| --- | --- | ---: |
| Netzbetrieb & Systemstabilität | `energie_netzbetrieb_systemstabilitaet_de_en (1).csv` | 26 = 26 |
| Sektorkopplung & Wärmewende | `energie_sektorkopplung_waermewende_aktualisiert_de_en.csv` | 15 = 15 |
| Energiedaten, Strommix & Energiewende | `prio_energiedaten_strommix_energiewende_de_en.csv` | 14 = 14 |

Bei „Netzbetrieb & Systemstabilität" liegt die **26-Karten-Fassung in der Datei mit
`(1)` im Namen**; die gleichnamige Datei ohne `(1)` enthält noch die älteren 22 Karten.
Einen älteren Sektorkopplung-Stand mit 10 Karten gibt es im Projekt nicht mehr (auch
nicht in der Git-Historie), in der Datenbank steht ausschließlich die 15-Karten-Fassung.

**Die Reihenfolge der Startseite.** Sie steckt in `categories.sort_order`: kleinere
Zahl = weiter vorne, 1000 ist der Standardwert (dann entscheidet die id). Gelesen wird
sie in `category_order_sql()` und gilt für die Kacheln UND für die Leiste
„Themengebiete" links, weil beide dieselbe Liste benutzen. Alle Zahlen stehen an einer
Stelle, in `database/start_page_order.sql`:

| Platz | Bereich | `sort_order` |
| ---: | --- | ---: |
| 1 | `PRIO` | 10 |
| 2 | `English` | 20 |
| 3 | `Mathematics` | 30 |
| 4 | `Geography` | 40 |
| 5 | `Informatik` | 50 |
| 6 | `Energy` (der alte Bereich) | 2000 |

Die Zehnerabstände lassen Platz, um später etwas dazwischen zu schieben; die 2000 hält
`Energy` hinter jedem Bereich, der den Standardwert 1000 bekommt. Die Bereiche des
anderen Kontos (id 167 und 183) haben ihre eigene Startseite und bleiben unberührt.

**PRIO trägt dasselbe Symbol wie der Bereich `Energy`.** Kopiert wurde es über
`PATCH /api/category.php?id=224` mit dem gespeicherten `icon_svg` von id 3 – also
durch dieselbe Prüfung wie ein Symbol, das jemand selbst hochlädt. Beide Zeichnungen
sind zeichengleich (30693 Zeichen, `icon_scale` 1.00), und beide Bereiche liefern
`api/category_icon.php` mit demselben Inhalt aus. Ein Symbol ist nur für sein Konto
abrufbar: ohne angemeldete Sitzung antwortet der Endpunkt mit 404.

**Einheiten, Energieformen und Atomaufbau (16.10.2026).** Eine neue Liste
(`energie_grundlagen_einheiten_energieformen_atome_de_en (1).csv`, 15 Zeilen) kam in
einem anderen Spaltenformat: `lesson;subcategory;question_en;answer_en;question_de;
answer_de`. Ihre Spalte `lesson` nennt für alle Zeilen „Energy basics & energy
transition" – den englischen Namen der bestehenden Unterkategorie `Energiegrundlagen &
Energiewende` (id 213). Dorthin gingen acht Karten (Einheiten, Leistung, Energieformen);
die vier Karten zu Atomaufbau und Elektrostatik kamen in die passendere bestehende
Unterkategorie `Atome, Teilchen & Elektrizität` (id 219). Es wurde also **keine** neue
Unterkategorie angelegt (weiterhin 92).

**Drei Karten waren schon da und blieben draußen:** „Was ist chemische Energie?" gibt es
wortgleich (id 18027), „Was ist kinetische Energie?" und „Was ist thermische Energie?"
gibt es mit ausführlicherem Wortlaut („… (Bewegungsenergie)?" id 18025,
„… (Wärmeenergie)?" id 18029) – dieselbe Frage, deshalb keine zweite Karte. Zwölf Karten
wurden importiert (ids 18396–18407), alle einseitig und mit beiden Sprachen. Die
umgewandelte Arbeitskopie liegt in
`database/import/grundlagen_einheiten_formen_atome_umgewandelt.csv`.

Die Quell-CSVs liegen noch im Projektstamm (frühere Importe haben ihre Dateien
danach gelöscht, siehe oben) – einundzwanzig an der Zahl. Ihr Inhalt
steht vollständig in der Datenbank; ein zweiter Import derselben Datei würde von den
Unterkategorien abgewiesen, die es unter PRIO schon gibt.
Nicht alles ist zweisprachig: 11 der 89 Kategorien haben kein `name_en`, und 284
Karten haben keinen englischen Text. Die App fällt dann auf den deutschen Text
zurück (`NULL` heißt „nimm `name`") und markiert solche Karten mit „nur Deutsch".
Das ist so gewollt, es fehlt einfach noch die Übersetzung.

## 8. „Energie verstehen" – das Nachschlagewerk

**Stillgelegt am 11.10.2026.** Die Seite antwortet mit `404 Not Found`; ihr Code steht
unverändert in `public/energy.php`, wird aber nicht mehr erreicht (am Anfang der Datei
steht ein Block, der sie abschaltet und nennt, was zu ihr gehört – vier Zeilen löschen
und sie ist wieder da). Damit ist auch `public/assets/vendor/katex/` (608 KB) gerade
unbenutzt: nur diese Seite hat den Formelsatz geladen. In `src/helpers/translations.php`
stehen weiterhin ihre Oberflächentexte.

Eine zweite Seite neben der Lernkartei: `public/energy.php`, erreichbar unter
`/energy.php`. Sie schlägt Begriffe, Formeln und Zusammenhänge nach, während man
lernt. Sie berührt die Datenbank **nicht**, ändert keine Karte und kennt keinen
Lernstand – das Einzige, was sie vom Server holt, ist der Anmeldezustand für die
Kopfzeile.

**Sie war mit der Seite verlinkt, aber von ihr aus nicht mehr zu erreichen.** Seit die
gemeinsame Leiste aus der Kopfzeile entfernt wurde (siehe §9), gab es keinen Verweis
von der Lernkartei hierher – die Adresse musste man kennen. Wer sie wieder haben will,
entfernt den Block am Anfang der Datei.

| Datei | Wofür |
| --- | --- |
| `public/energy.php` | die Hülle der Seite; sie reicht die Inhalte als JSON in den Browser |
| `src/data/energy_modules.php` | die Wissensmodule: Überschrift, Erklärung, Vertiefung, Formeln, Zeichnung – je Sprache |
| `src/helpers/site_header.php` | die Kopfzeile, die alle drei Seiten tragen (Konto, Erscheinungsbild, Sprache) |
| `src/helpers/page_head.php` | die Zeilen des `<head>`, die beide Seiten gleich brauchen |
| `public/assets/css/energy.css` | das Aussehen dieser Seite; Farben und Schriften kommen aus `app.css` |
| `public/assets/js/energy.js` | die zwei Filter, Aufklappen, Sprache und Erscheinungsbild |
| `public/assets/js/energy_visuals.js` | die Zeichnungen, eine Funktion je Baustein |
| `public/assets/vendor/katex/` | der Formelsatz (KaTeX 0.16.11, MIT-Lizenz, liegt im Projekt) |

**Inhalte sind Daten, kein Code.** Ein neuer Begriff ist ein neuer Eintrag in
`energy_modules.php`: `group` ist der Themenbereich, `topic` die kurze Bezeichnung im
zweiten Auswahlfeld. Auch die Wörter, die **in** einer Zeichnung stehen, gehören
dorthin (Feld `labels`) – deshalb steht in `energy.js` und `energy_visuals.js` kein
einziger sichtbarer Satz. Die Oberflächentexte (Filter, Knöpfe) stehen wie überall in
`src/helpers/translations.php`.

**Die Filterleiste.** Zwei Auswahlfelder, die voneinander abhängen: links der
Themenbereich, rechts das konkrete Thema daraus. Es gibt keinen Freitext mehr. Beide
Listen entstehen aus den Daten – ein Themenbereich ohne Module wird gar nicht
angeboten, und ein neues Modul erscheint von allein. Ist „Alle Themen" gewählt, steht
im zweiten Feld jedes Modul, nach Bereichen gruppiert (`optgroup`). Dazu „Nur Formeln"
und „Zurücksetzen". Die Wahl überlebt ein Neuladen (`sessionStorage`), aber keinen
neuen Reiter; ein Sprachwechsel lässt sie stehen, weil das Skript die Listen neu baut
und die Werte aus seinem eigenen Zustand wieder setzt.

**Zu jedem Modul eine Adresse.** Die Id des Moduls steht in der Adresse
(`energy.php#ohms_law`), ein Verweis auf ein verwandtes Modul springt dorthin. Ist
das Ziel durch einen Filter ausgeblendet, wird der Filter aufgehoben – sonst führte
der Verweis auf eine leere Stelle. Damit kann später auch eine Karteikarte auf ein
Modul zeigen.

**Zeichnungen.** Jede ist ein SVG mit `viewBox` und ohne feste Breite. Sie entsteht
erst, wenn ihr Modul in Sicht kommt (`IntersectionObserver`), und wird angehalten,
wenn es wieder verschwindet oder der Reiter in den Hintergrund geht. Für alle
Zeichnungen zusammen läuft **ein** Taktgeber (`requestAnimationFrame`). Bei
`prefers-reduced-motion` läuft von selbst keine; sie zeigt ein Standbild und wartet
auf einen Klick auf „Abspielen".

**Formeln.** Der Formelsatz wird **nur auf dieser Seite** geladen und liegt im
Projekt – kein CDN, wie überall hier. Das ist die eine schwere Datei: 268 KB roh,
73 KB gepackt; die Schriften holt der Browser nur, wenn er sie braucht. Die Formel
selbst (`latex`) ist sprachunabhängig und wird einmal gesetzt; beim Sprachwechsel
wechseln nur die Wörter ringsum. Unter jeder Formel stehen die Formelzeichen mit
Bedeutung und Einheit, die Bedingung, unter der sie gilt, und ein Zahlenbeispiel.

**Modell statt Wirklichkeit.** Jede Zeichnung, die Elektronen zeigt, ist ein Modell
und sagt das unter sich. Die Punkte wandern absichtlich langsam: die Energie kommt am
Ende eines Leiters fast sofort an, weil sich das elektrische Feld ausbreitet – nicht,
weil Elektronen schnell wären. Die Zahlen dahinter (Elementarladung, Elektronen je
Sekunde, Wanderungsgeschwindigkeit, Energie) sind mit echten Werten gerechnet.

**Was es gibt und was noch fehlt.** Vollständig ist bisher der Themenbereich
**Elektrische Grundlagen** mit zehn Modulen: Ladung und Elektronen, Elektrisches Feld,
Spannung und Spannungsquelle, Stromstärke, Widerstand, Ohmsches Gesetz, Leistung,
Energie, Gleichstrom und Wechselstrom, Leistung-Energie-Zeit. Die übrigen sechs
Bereiche (Energieformen und Energieumwandlung, Magnetismus und Induktion, Generatoren
und Stromerzeugung, Transformatoren und Stromübertragung, Stromnetz und Netzbetrieb,
Energiespeicher und Verbraucher) sind angelegt, haben aber noch keine Module – sie
tauchen deshalb in der Filterleiste noch nicht auf.

Geplant ist als Nächstes die Reihe „Energieformen und Energieumwandlung" mit einem
interaktiven Energieflussdiagramm, danach Magnetismus und Induktion (Magnet durch die
Spule mit Spannungskurve) und die Generator-Simulation (drehendes Feld, Flussänderung,
Drehzahl, offener und geschlossener Stromkreis).

## 9. „Story Together" – die gemeinsame Geschichte

Eine dritte Seite: `public/story.php`, erreichbar unter `/story.php`. Genau **zwei
Konten** schreiben dort abwechselnd an einer Geschichte. Nach jedem Beitrag hat der
Verfasser **30 Minuten** Zeit, ihn noch zu ändern; danach ist er endgültig und der Zug
wechselt zur anderen Person.

**Wer mitschreiben darf**, steht an genau einer Stelle: `STORY_WRITERS` in
`src/helpers/story_writers.php` (derzeit die Ids 6 und 8, mit den Rollen `selina` und
`xenia`). Drei Dinge lesen diese Liste – die Startseite (Kachel ja/nein), die Seite
(darf diese Sitzung überhaupt hier sein?) und der Endpunkt (darf dieser Beitrag
gespeichert werden?). Zwei Listen wären zwei Wahrheiten, und die zweite wäre irgendwann
falsch. Die Namen im Text („Xenia") stehen nicht hier, sondern in
`src/helpers/translations.php`; die Liste führt nur Rollen.

**Die Regeln.** Alle stehen in `src/services/story_service.php` und gelten serverseitig;
der Browser blendet nur aus, er entscheidet nichts.

1. Schreiben dürfen nur die beiden Konten.
2. Ein Beitrag je Zug. Nach dem Veröffentlichen bleiben **`STORY_EDIT_MINUTES` (30)
   Minuten Bearbeitungszeit**: solange darf nur der Verfasser selbst noch ändern, die
   andere Person wartet.
3. **Danach wechselt der Zug** zur anderen Person – gerechnet wird ab dem Zeitpunkt der
   Veröffentlichung, nicht mit dem Kalendertag. Wer um 23:50 Uhr schreibt, gibt um 0:20
   Uhr nachts ab.
4. Den ersten Beitrag darf schreiben, wer zuerst kommt – er ist damit der Erst-Autor, und
   die andere Person ist danach an der Reihe.
5. Wer nicht schreibt, **verliert seinen Zug nicht**: er bleibt stehen, bis jemand
   schreibt. Schreibt dieselbe Person deshalb mehrere Beiträge hintereinander, ist das in
   Ordnung – eine Prüfung „nicht zweimal derselbe Autor in Folge" gibt es nicht.
6. Geändert werden darf nur der eigene, **zuletzt veröffentlichte** Beitrag, und nur
   innerhalb der 30 Minuten. Danach ist er endgültig. Gelöscht wird nie.

**Wer dran ist, rechnet `story_state()` aus dem letzten Beitrag:**

```
letzter Beitrag + 30 Minuten   -> noch der Verfasser (er darf nur noch ändern)
danach                         -> die andere Person ist an der Reihe
```

In diesen 30 Minuten steht also niemandem das Schreiben zu: der Editor der wartenden
Person ist gesperrt, und der Verfasser sieht an seiner Stelle den Satz „Du kannst deinen
Beitrag noch bis … ändern" (Übersetzungsschlüssel `story.turn.editWindow`) – ohne ihn wäre
nicht zu sehen, warum der eigene Editor noch zu ist. Die **wartende** Person bekommt ihren
eigenen Satz („Noch einen Moment – ab 14:16 Uhr ist Xenia dran.", `story.turn.waiting`):
sie ist eben noch nicht dran, sondern gleich dran, und ohne diesen Unterschied würde sie
lostippen, während die andere noch am Text ist. Erst nach Ablauf steht dort „Xenia ist an
der Reihe, die Geschichte fortzusetzen." (`story.turn.other`, dieselbe Zeile wie auf der
Kachel der Startseite). Die Frist hängt am Zeitpunkt der
**Veröffentlichung** (`created_at`) und nicht am letzten Ändern: sonst könnte man sie durch
Ändern beliebig strecken, und die andere Person käme nie an die Reihe.

Der Wert steht als `turn_writer` im Stand – während der Frist der Verfasser, danach die
andere Person; `turn.writer` im Überblick ist `turn_writer` plus den Erst-Autor für den
Fall, dass noch niemand geschrieben hat. Rechnen statt speichern heißt: dieselbe Antwort
für beide Nutzer, ohne einen Zustand, der falsch werden kann.

**Warum die Regeln halten.** `SELECT ... FOR UPDATE` auf der Zeile der Geschichte lässt
zwei gleichzeitige Anfragen nacheinander laufen statt nebeneinander; die zweite sieht die
erste und weiss damit, dass gerade eben geschrieben wurde. Den eindeutigen Schlüssel
`uniq_story_contributions_day` auf `(story_id, contribution_date)` gab es einmal – mit der
Bearbeitungszeit passte er nicht mehr (er hätte der anderen Person denselben Tag
verboten) und ist in `database/story_turn.sql` wieder entfernt worden. Die Sperre auf der
Geschichte ist damit die einzige Absicherung, und sie trägt: jede Veröffentlichung und
jede Änderung berührt zuerst diese eine Zeile.

**Woher die Id kommt.** Ausschließlich aus der Sitzung
(`session_user_id_from_php_session()`), nie aus der Anfrage. Ein mitgeschicktes
`user_id`, `story_id` oder `contribution_date` im Rumpf wird nicht gelesen; der Tag
entsteht aus der Uhr des Servers. Jede schreibende Anfrage braucht zusätzlich den
CSRF-Token der Sitzung – ohne ihn oder mit einem fremden antwortet der Endpunkt mit
`400 invalid_token`.

**Die Zeiten.** `created_at` liegt in **UTC** in der Datenbank (`UTC_TIMESTAMP()`), damit
ein Umzug des Servers nichts verschiebt – verglichen wird in Zeitpunkten, deshalb spielt
die Zeitzone bei der Bearbeitungszeit keine Rolle. Der **Kalendertag** eines Beitrags
(`contribution_date`) ist dagegen der Tag in **Europe/Berlin**; er entscheidet jetzt nur
noch darüber, unter welchem Datum ein Beitrag in der Anzeige steht. Nach außen gehen beide
Angaben mit: der Tag als `YYYY-MM-DD`, der Zeitpunkt als ISO-Zeichenkette mit Versatz für
die Anzeige.

**Die Reihenfolge der Seite** steht in `public/story.php` und ist Absicht: erst der Kopf,
dann die **Zahlen**, dann die **Schreibaufgabe**, dann die **Geschichte**, ganz unten der
**Editor**. Man liest also von oben nach unten und schreibt direkt unter dem Gelesenen
weiter; die Zahlen oben sagen, was einen erwartet, ohne dass man dafür scrollen muss.

**Die Seite scrollt normal, der Lesebereich nur, wenn er lang wird.** Eine eigene
Fensterhöhe für die Seite gab es hier einmal (`.page--story` mit `100dvh` und
`overflow: hidden`), damit die Seitenleiste beim Scrollen nicht aus dem Bild wandert. Der
Preis war zu hoch: `overflow: hidden` schnitt die Themenliste an der Unterkante ab, sobald
sie länger war als das Fenster, und der Lesebereich bekam durch sein eigenes `max-height`
schon bei einem einzigen Beitrag einen Balken. Jetzt ist die Leiste eine ganz normale
Spalte – sie kann nicht abgeschnitten werden, weil man zu ihr scrollen kann – und die
Seite scrollt wie jede andere. `min-height: 100dvh` bleibt, damit eine kurze Seite das
Fenster trotzdem füllt. Ein doppelter Scroll entsteht dabei nicht: der Lesebereich nimmt
den Scroll erst, wenn sein Inhalt höher wäre als das Fenster (siehe unten), sonst wächst
er mit dem Text.

**Was die Seite zeigt.** Der Kopf mit Zeichnung, Label und Titel. Darunter die Zahlen als
**eine Zeile** – kein eigener Abschnitt, keine Überschrift, rund 19 Pixel hoch: erst die
Kennzahlen im Fließtext, mit Punkt getrennt („408 Wörter · 1 Beitrag · 1 Tag"), dann ein
**schmaler Balken** (140 × 6 Pixel, runde Enden) mit den zwei Anteilen (Salbei = Xenia,
Terrakotta = Selina) und dahinter die Legende („● Xenia 0 % · ● Selina 100 %"), am Ende
der Zeile leise „seit 11.10.2026". Der Balken selbst ist für Vorleseprogramme
ausgeblendet – die Legende daneben sagt dasselbe in Worten.

Die Tage zählen die **verschiedenen Kalendertage mit einem Beitrag**, nicht die Beiträge:
zwei Beiträge an einem Tag sind eine Zeile in dieser Zahl. Eine **Zeitleiste mit Achse und
Punkten** stand hier einmal; sie ist weg, seit die Zahlen nur noch eine Zeile sind – die
Angabe „seit …" ist die einzige Datumsangabe geblieben.

Darunter die Beiträge in Leserichtung: eine kleine Zeile mit Autor und Datum (ein runder
Punkt in der Autorenfarbe davor), darunter der Text in der Serifenschrift mit einem
schmalen farbigen Strich links. **Kein Kasten um einen Beitrag** – der neueste ist nur
durch das Wort „Neuester" in seiner Zeile hervorgehoben, und der Text wird nie eingerückt
wie in einem Formular. Der erste Absatz der Geschichte beginnt mit einer **Initiale** in
der Farbe seines Autors.

Die Seite hat **einen** Abstandsrhythmus, und er steckt in drei Variablen: `--story-gap`
(56 bis 64 Pixel) trennt die Abschnitte (Kopf, Zahlen, Aufgabe, Geschichte, Editor),
`--story-rule-gap` ist die Hälfte davon und liegt ober- **und** unterhalb der einen
Trennlinie vor dem Editor – so sitzt die Linie mittig zwischen zwei Abschnitten statt an
einem zu kleben –, und `--story-inner` (20 bis 24 Pixel) regelt, was innerhalb eines
Abschnitts zusammengehört (Überschrift, Knöpfe, Zahlen). Die Zahlen, die Aufgabelleiste,
die Geschichte und der Editor sind **gleich breit** (`--story-column`, 44rem, also rund
700 Pixel – die Breite, bei der ein längerer englischer Text angenehm zu lesen ist) und
liegen auf derselben Flucht wie der Kopf darüber, der die volle Spalte einnimmt.

**Der Lesebereich scrollt nur, wenn er lang wird.** `.story-scroll` hat **keine feste
Höhe**, sondern `max-height: 80dvh` und ein eigenes `overflow-y`: bei einem oder zwei
Beiträgen gibt es deshalb gar keinen Balken, und die Seite wächst mit dem Text wie eine
Artikelseite. Erst wenn die Geschichte höher würde als das Fenster, bleibt der Bereich
stehen und scrollt in sich selbst – der Kopf und der Editor bleiben dabei im Bild. Beim
Öffnen steht der Bereich bei seinem **neuesten Beitrag** (geschrieben wird ja auch von
unten), und `overscroll-behavior: contain` sorgt dafür, dass am Ende des Textes Schluss ist
statt die Seite weiterzurollen. Eine Vorschau mit „Weiterlesen" gab es hier einmal; sie ist
weg, seit der Bereich selbst scrollt.

Sein **Balken** ist absichtlich leiser als die Balken aus `app.css`: schmal (10 Pixel, mit
unsichtbarem Rand und `background-clip: content-box` wirkt der Daumen noch dünner), ohne
Kasten und in der gedämpften Textfarbe (`--story-scrollbar`, aus `--text` gemischt – damit
stimmt er hell und dunkel, ohne eine zweite Farbe). Für Firefox stehen `scrollbar-width:
thin` und `scrollbar-color` daneben; Chrome, Edge und Safari nehmen die
`::-webkit-scrollbar`-Regeln.

Der **Fade** an den Rändern ist eine Maske und kein Farbverlauf: eine Maske kennt keine
Hintergrundfarbe und funktioniert deshalb hell und dunkel gleich. Er erscheint nur dort,
wo wirklich noch Text ist (`is-fade-top`, `is-fade-bottom` – die beiden Zustandsklassen
setzt `updateScrollFade()` bei jedem Scrollen und bei jeder Größenänderung), und
`mask-origin`/`mask-clip: content-box` hält ihn aus dem Balken heraus, der sonst halb
durchsichtig wäre. `tabindex="0"` macht den Bereich mit der Tastatur scrollbar.

**Der Editor hat zwei Zustände.** Im Ruhezustand ist er keine Kiste: **kein Rahmen**, nur
die warme Fläche (`--panel`) mit dem gedämpften Hinweis als Platzhalter – als hätte jemand
eine leere Seite aufgelegt. Erst wer hineinschreibt (`is-active`, gesetzt von
`updateEditorState()` bei Fokus, Eingabe und im Änderungsmodus), bekommt Wortzähler und
Knöpfe; im Fokus erscheint ein **sehr zarter, dünner** Rand in der Autorenfarbe (1 Pixel mit
55 Prozent Deckkraft, dazu ein Hauch außen herum) – ein Hinweis, kein Rahmen. Geschrieben
wird in derselben Serifenschrift wie die Geschichte, in mindestens 240 Pixel Höhe (höchstens
320) und mit 20 bis 24 Pixel Innenabstand. Der Veröffentlichen-Knopf ist flach und in der
Autorenfarbe gefüllt, „Abbrechen" dagegen ein unterstrichener Textlink. `app.css` färbt den
Fokusrahmen jedes Textfelds grün – deshalb steht im Selektor eine zweite Klasse mit, sonst
gewönne das Grün.

**Wer nicht dran ist**, sieht den Editor sichtbar, aber **ausgegraut**: reduzierte
Deckkraft, kein Textcursor, und `disabled` im Feld, damit es keinen Fokus bekommt. Nichts
davon schützt etwas – es ist die Anzeige des Zustands, den `story_state()` gemeldet hat und
den `story_publish()` serverseitig durchsetzt. Im Änderungsmodus hebt sich die Sperre auf,
weil der eigene Beitrag auch dann geändert werden darf.

Die Statuszeile unter dem Editor („Du bist an der Reihe", „Xenia ist an der Reihe, die
Geschichte fortzusetzen") ist klein, in der Sans-Schrift und gedämpft: sie ist Auskunft,
keine Überschrift. Ist heute schon geschrieben, steht dort **nichts** – der Beitrag von
heute steht direkt darüber, und ein Satz darüber wäre nur Wiederholung.

Die Zahlen sind kleiner als der Text der Geschichte und stehen in **einer** Zeile: die drei
Kennzahlen im Fließtext, dahinter der schmale Balken mit der Legende und am Ende „seit …".
Auf einem schmalen Fenster bricht die Zeile um; der Hinweis „seit …" rutscht dann in die
nächste Zeile und steht links.

| Datei | Wofür |
| --- | --- |
| `public/story.php` | die Hülle der Seite; ohne passende Sitzung nur der Hinweis „Nicht verfügbar" |
| `src/helpers/story_writers.php` | die Liste der beiden Konten – die einzige Wahrheit darüber |
| `src/services/story_service.php` | die Regeln, die Abfragen und die Zeiten |
| `src/data/grammar_tasks.php` | die Aufgaben der Grammar Challenge – Inhalte, keine Logik |
| `public/api/story.php` | `GET` lesen, `POST` veröffentlichen oder ändern; beides prüft die Sitzung |
| `public/assets/css/story.css` | das Aussehen dieser Seite; Farben und Schriften kommen aus `app.css` |
| `public/assets/js/story.js` | Anzeige, Editor, Ändern, Sprach- und Erscheinungswechsel |
| `database/story_together.sql` | legt die beiden Tabellen an (von Hand auszuführen) |

**Der Weg dorthin.** Es gibt **keine Leiste in der Kopfzeile** mehr (die Zeile
„Flashcards · Understanding energy · Story Together" ist entfernt). Die Geschichte wird
vielmehr überall wie ein Lernbereich erreicht:

* **Startseite:** die Kachel „Story Together" als erste in der Reihe – aber nur, wenn die
  Sitzung eine der beiden ist. Das entscheidet `public/index.php` beim Ausliefern und
  schreibt es als `story.available` in die Konfiguration; `app.js` zeichnet die Kachel
  dann nur. Dass die Startseite dafür überhaupt eine Sitzung liest, ist gewollt – es ist
  die einzige Stelle, an der `public/index.php` die Sitzung anfasst, die Datenbank bleibt
  unberührt.

  Weil sich die Sitzung auch **ohne Seitenwechsel** ändern kann (Anmelden, Abmelden,
  Konto gelöscht – alles läuft im Dialog und die Seite wird bewusst nicht neu geladen),
  sagt `api/bootstrap.php` dieselbe Auskunft noch einmal mit (`story`). `loadBootstrap()`
  schreibt sie nach `config.story`, und die Ansichten warten auf diese Antwort, bevor sie
  zeichnen: die Kachel erscheint und verschwindet also mit dem An- oder Abmelden und nicht
  erst nach Strg+R. Zur selben Sitzung gehört auch die Zeile auf der Kachel; sie wird beim
  Sitzungswechsel vergessen (`forgetStoryTurn()`) und neu erfragt, sonst stünde dort noch
  „Du bist dran" von der Person davor.
* **Seitenleiste:** `buildStorySidebarLink()` in `app.js` hängt einen Eintrag ganz oben
  in „Themengebiete", mit denselben Klassen wie ein Lernbereich. Er belegt den ersten
  Platz der Farbreihe, die Lernbereiche rücken deshalb um eine Stelle nach hinten –
  dadurch tragen sie in der Leiste dieselben Farben wie ihre Kacheln (Mathematik blau,
  Energie rosé, Geografie gelb) und die Geschichte das Salbeigrün. Der Eintrag ist
  dieselbe Bequemlichkeit wie die Kachel: Seite und Endpunkt prüfen unabhängig davon.
* **`story.php` selbst** hat dieselbe Seitenleiste (`renderSidebar()` in `story.js`),
  damit man von der Geschichte aus direkt in einen Lernbereich springen kann – und
  dieselbe Hülle wie eine Unterkategorieseite: Brotkrumen „START — STORY TOGETHER",
  darunter der farbige Kopf mit rundem Symbolkreis und großem Titel. Die Bereiche
  kommen wie dort aus `api/categories.php` (rund 1,3 kB), nicht aus `api/bootstrap.php`
  (rund 3,6 MB, das wäre für eine Leiste die zehnfache Arbeit). Ein Zähler
  (`sidebarRun`) sorgt dafür, dass ein Sprachwechsel die Liste nicht doppelt füllt,
  falls die erste Antwort noch unterwegs ist.

Der Kopf der Seite ist derselbe Baustein wie bei einer Unterkategorie (`detail__head`,
`view--detail`, `sidebar`): beide zeigen auf denselben Bildschirmbreiten dieselbe
Aufteilung, dieselben Abstände und dieselbe Anmutung. Die Geschichte selbst steht in
einer Spalte von rund 44rem, die mit dem Kopf bündig beginnt und ihn nach unten mit etwa
`clamp(44px, 6vw, 64px)` Abstand fortsetzt. Über jedem Beitrag steht nichts weiter als
sein Datum; der Autor steckt in der Farbe des senkrechten Strichs neben dem Text
(Salbeigrün für Xenia, Terrakotta für Selina).

Die Kachel ist keine Prüfung. Wer sie nicht sieht, kommt trotzdem nicht weiter –
`story.php` zeigt ihm nur „Nicht verfügbar", und `api/story.php` antwortet mit 403.

**Die Kachel hat die Farbe ihres Platzes.** Sie gehört zu keinem Lernbereich und bekommt
trotzdem keine Sonderbehandlung: die Palette hängt in `app.css` an der Position in der
Reihe (`nth-child(8n+1)` und so fort), und als erste Kachel ist das der erste Ton – das
gedeckte Salbeigrün. Fläche, Symbolkreis, Pfeil und das Verhalten beim Überfahren sind
damit dieselben wie bei Mathematik, Energie und Geografie. Eine eigene Farbregel gab es
hier einmal; sie war nach dem Umbau des Blattes wirkungslos und ist entfernt.

**Die Zeile auf der Kachel ist der Stand**, keine Beschreibung: „Du bist dran" bzw.
„Xenia ist dran" (`story.tile.turn.mine` / `story.tile.turn.other`). Er kommt nicht aus
`index.php`, sondern aus demselben Endpunkt wie die Seite selbst
(`api/story.php`, Feld `turn.writer`) – die Zugregel steht so an genau einer Stelle.
Beide Nutzer sehen **denselben Namen**, denn er hängt am letzten Beitrag und nicht daran,
wer gerade die Seite offen hat: während der Bearbeitungszeit ist es der Verfasser, danach
die andere Person. Den Fall einer noch leeren Geschichte beantwortet `story_turn_writer()`
im Service: dann ist der Erst-Autor dran (der erste Eintrag in
`src/helpers/story_writers.php`). Bis die Antwort da ist, bleibt
die Zeile leer – ein kurzer leerer Platz ist besser als ein Satz, der nicht mehr stimmt.

**Was bewusst fehlt.** Kein Löschen und kein Zurücknehmen eines Beitrags; kein Titel
ändern. Geändert werden kann nur der eigene, zuletzt veröffentlichte Beitrag, und nur
innerhalb der 30 Minuten – danach ist er endgültig.

**Gespeichert wird ohne Zwischenschritt.** Ein Klick auf „Beitrag veröffentlichen" oder
„Änderung speichern" schickt den Text direkt an die API. Den Vorschau-Dialog („Ein letzter
Blick") gab es einmal; er war eine zweite Seite vor der eigentlichen Handlung und ist
entfernt. Auch `Strg`/`Cmd`+`Enter` speichert direkt. Serverseitig bleibt alles, wie es war:
Zug, Bearbeitungszeit, Länge, CSRF-Token und die Erlaubnis, genau diesen Beitrag zu
ändern, prüft `api/story.php` in jedem Fall.

### Einen Beitrag nachträglich ändern

Der eigene, **zuletzt veröffentlichte** Beitrag lässt sich ändern, solange die 30
Minuten Bearbeitungszeit laufen. Danach ist er gesperrt: die andere Person ist an der
Reihe, und ein Eingriff würde das, was danach kommt, auf eine Aussage beziehen, die es
nicht mehr gibt.

Geprüft wird serverseitig in `story_edit()` – dieselbe Funktion, die auch die Anzeige
fragt (`editable_contribution_id` im Überblick): nur der eigene Beitrag, nur der letzte,
und nur mit gültigem CSRF-Token. Die Antwort ist in allen drei Fällen dieselbe
(`409 not_editable`); wer nicht ändern darf, muss nicht erfahren, warum genau.

Geändert wird **nur der Text**: der Kalendertag (`contribution_date`) bleibt, wie er war.
Ändern ist also kein neuer Beitrag, zählt nicht als zweiter Beitrag des Tages und ändert
die Reihenfolge nicht – und es **verlängert die Bearbeitungszeit nicht**: die Frist hängt am
Zeitpunkt der Veröffentlichung (`created_at`), sonst könnte man sie durch Ändern beliebig
strecken. Nachgezogen werden nur `word_count` und `stories.updated_at`. Im
Browser läuft es über denselben Editor wie ein neuer Beitrag: der Knopf „Bearbeiten" steht
in der Zeile des Beitrags, Titel und Knöpfe wechseln mit dem Modus, und „Abbrechen" stellt
den Editor wieder auf einen neuen Beitrag um.

Zwei Dinge tut der Änderungsmodus zusätzlich. Erstens **verschwindet der Beitrag für die
Dauer des Änderns aus der Geschichte** (`.entry.is-editing`, `display: none`): derselbe Text
zweimal auf einer Seite – oben mit Initiale, unten im Feld – wäre verwirrend. `renderWrite()`
zeigt deshalb immer erst alle Beiträge und versteckt dann den einen; so bleibt nichts hängen,
wenn abgebrochen oder die Sprache gewechselt wird (dann entstehen die Beiträge neu).
Zweitens ist das Feld mit **dem vollständigen Text** gefüllt, der in der Antwort steht –
also dem, was in der Datenbank liegt. Das passiert im Skript über `value`, und ein `value`
ist reiner Text: er wird nie als HTML gelesen, deshalb braucht es hier kein
`htmlspecialchars` und es kann auch nichts eingeschleust werden, was wie Markup aussieht.

### Die Benachrichtigung „du bist dran"

30 Minuten nach dem Veröffentlichen wechselt der Zug – und die Person, die jetzt dran ist,
erfährt es per E-Mail. Wann sie rausgeht, ist damit keine eigene Entscheidung: sie geht
genau dann raus, wenn die Bearbeitungszeit abgelaufen ist. Eine Mail direkt nach dem
Schreiben wäre falsch, denn 30 Minuten lang darf der Text noch geändert werden. Der Cron
läuft jede Minute, die Mail ist also spätestens eine Minute nach Ablauf da.

**Wer sie bekommt**, entscheidet der letzte Beitrag: die andere Person als die Verfasserin.
Die Adresse kommt aus `users.email` und nicht aus einer zweiten Liste im Quelltext –
`src/helpers/story_writers.php` führt nur Ids und Rollen. Fehlt die Adresse, wird nichts
verschickt.

**Genau einmal.** Der Merker steht in `story_contributions.turn_notified_at`. Die Reihenfolge
in `story_notify_due()` ist Absicht: erst **reservieren** (`UPDATE ... WHERE turn_notified_at
IS NULL`), dann verschicken, bei einem Fehler den Merker wieder freigeben. So kann dieselbe
Mail nicht doppelt rausgehen, und ein misslungener Versand wird beim nächsten Lauf
wiederholt.

**Verschickt wird über SMTP**, nicht mit PHPs `mail()`: ein Programm wie `sendmail` gibt es
auf diesem Server nicht. Der Versand steht in `src/helpers/mailer.php` und spricht selbst mit
dem Postfach (EHLO, STARTTLS, AUTH LOGIN, DATA) – ohne fremde Bibliothek. Die Zugangsdaten
stehen ausschließlich in `.env` (`MAIL_HOST`, `MAIL_PORT`, `MAIL_SECURE`, `MAIL_USER`,
`MAIL_PASS`, `MAIL_FROM`, `MAIL_FROM_NAME`, `APP_BASE_URL`); die Vorlage steht in
`.env.example`. Die Sätze der Mail kommen aus `src/helpers/translations.php`
(`story.mail.subject`, `story.mail.body`) und sind reiner Text – kein HTML, damit nichts in
der Mail stehen kann, was wie Markup aussieht. Gefüllt werden zwei Platzhalter: `{name}`
(Empfängerin) und `{url}` (`APP_BASE_URL` + `/story.php`). Der Text ist seit dem 13.10.2026
kurz: Anrede, der eine Satz („Die Geschichte wartet darauf, von dir weitergeschrieben zu
werden.") und der Link. Wer geschrieben hat und wie lange man noch ändern darf, stand
einmal darin – beides braucht die Mail nicht, und der Name der Verfasserin steht ohnehin
in der Geschichte. Die Platzhalter `{author}` und `{title}` werden deshalb nicht mehr
gefüllt; der `JOIN` auf `stories.title` in `story_notification_last_row()` läuft weiter und
kostet nichts.

**Warum die Mail auf Deutsch geht, obwohl die Oberfläche zweisprachig ist.** Beide Fassungen
liegen in den Übersetzungen bereit – welche gilt, entscheidet `STORY_MAIL_LANGUAGE`. Der
Server kann die Sprachwahl der Empfängerin nicht kennen: sie steht im `localStorage` des
Browsers, und für ein Konto wird sie nirgends gespeichert (eine Spalte dafür gibt es in
`users` nicht, und ohne sie weiß auch der Cron nichts davon). Also Deutsch als Vorgabe; die
englische Fassung wird mit einer Zeile in `story_notification_service.php` aktiv.

**Ausgelöst wird das per Cron**, jede Minute:

```
* * * * * cd /pfad/zum/projekt && /usr/bin/php bin/story_notify.php --execute >/dev/null 2>&1
```

`bin/story_notify.php` ist ein Kommandozeilen-Werkzeug wie die Importe: `--dry-run` zeigt
nur, was ansteht, `--execute` verschickt und setzt den Merker. Es gibt 0 zurück, wenn alles
in Ordnung ist (auch wenn nichts zu tun war), und 1 bei einem echten Problem. Der
Cron-Aufruf oben wirft die Ausgabe weg und `MAILTO=""` unterdrückt die Cron-eigenen Mails
(es gibt ja keinen lokalen Mailserver); wer wissen will, was los ist, ruft das Werkzeug von
Hand mit `--dry-run` auf. Läuft der Cron nicht, kommt keine Mail; die Seite selbst
funktioniert weiter. Der Merker braucht die Spalte `turn_notified_at`, die mit
`database/story_turn.sql` dazukommt; fehlt sie, sagt das Werkzeug das und tut nichts.

### Die Grammar Challenge

Über dem Editor steht eine **schmale Leiste**: links das Markenzeichen und die Beschriftung
(„Schreibaufgabe für heute"), rechts die Zeitform der nächsten Tage und dahinter das Niveau
(„B1"). Sie steht **immer** da – auch dem, der heute nicht mehr schreiben darf. Sie ist eine
Anregung und keine Bedingung; nach dem Veröffentlichen verschwindet der Editor, die Leiste
bleibt.

**Die Aufgabe ist freiwillig.** Es wird nicht geprüft, ob eine Anweisung eingehalten
wurde: keine Bewertung, keine Farbanalyse, keine Korrektur. Der Beitrag darf ohne sie
veröffentlicht werden. Das ist Absicht und nicht eine noch fehlende Funktion.

**Der Aufgabenpool** steht in `src/data/grammar_tasks.php` – als Liste und nicht in der
Datenbank, weil eine Aufgabe Programm-Inhalt ist und kein Nutzerdatum. Die Datenbank
trägt, was die beiden schreiben; was die Anwendung anbietet, steht im Projekt wie die
Wissensmodule der Nachschlageseite. Eine neue Aufgabe ist damit eine neue Zeile in dieser
Datei, und `sort_order` legt ihren Platz in der Reihe von einfach nach komplex fest
(Present Simple bis Passive Voice).

**Welche Zeitform dran ist**, entscheidet `story_challenge_for()` in
`src/services/story_service.php` **allein aus dem Datum**:

```
Stelle = (Tage seit dem Start) / 2   (ganzzahlig geteilt)   →   Rest der Liste
```

Gezählt wird ab dem Tag, an dem die Geschichte angelegt wurde (`stories.created_at`, nach
Berliner Zeit umgerechnet); die Liste kommt aus dem `sort_order` der Aufgaben. Alle
`STORY_CHALLENGE_DAYS` (zwei) Kalendertage geht es also eine Zeitform weiter, nach der
letzten beginnt die Reihe von vorn – Present Simple, Past Simple, Past Continuous, …,
Passive Voice und dann wieder von vorn.

Damit ist die Aufgabe für beide Nutzer am selben Tag dieselbe, sie überlebt jedes
Neuladen, und es braucht **keine gespeicherte Zuweisung**: ein Zustand, den man nicht
speichern muss, ist ein Zustand, der nicht falsch werden kann. Die Leiste reist in
derselben Antwort mit wie die Geschichte (`challenge` in `api/story.php`), es gibt also
keinen zweiten Aufruf und keinen zweiten Endpunkt.

**Gezeigt wird nur die Zeitform.** Die Leiste über dem Editor ist eine Zeile: links das
Markenzeichen und die Beschriftung, rechts die Zeitform und dahinter das Niveau („B1").
Anweisung, Beispielsatz und Eselsbrücke stehen in der Datei, aber **nicht** auf der Seite –
sie wären ein zweiter Mittelpunkt neben der Geschichte, und wer schreibt, weiß durch die
Zeitform, worauf er achten soll. Die Angabe steht in `src/data/grammar_tasks.php`, damit die
Aufgaben an einer Stelle liegen; gezeigt wird von dort nur `topic` und `level`.

Die Leiste klappt **nicht** auf und hat keinen Zustand: kein `<details>`, kein
`localStorage`, kein zweiter Klick. Die Zeitform und das Niveau sind Fachbegriffe und heißen
in beiden Sprachen gleich; nur die Beschriftung links wechselt mit der Sprache.

**Die Zeichnung** ist `public/assets/icons/story_icon.svg` – dieselbe Datei, die auch auf
der Kachel der Startseite steht. Sie wird wie jedes andere Symbol der Anwendung über
`--icon-filter` eingefärbt und passt sich damit hell und dunkel an.

## 10. Eigenheiten und offene Punkte

Nichts davon ist kaputt, und nichts davon wird ohne Rückfrage geändert. Es steht
hier, damit sich niemand wundert.

**Eine Akzentfarbe für beide Themen**

Bis zum 12.10.2026 war `--accent` im hellen Thema das gedeckte Grün `#2F6B4F`, im dunklen
Thema ein helles Cyan (`#8FD3E6`). An der Akzentfarbe hängt fast jedes Bedienelement -
jeder Primärknopf, jeder Fokusring, jeder Unterstrich, jeder Fortschrittsbalken - und weil
die Flächen der App im dunklen Thema unverändert blieben, war die halbe Anwendung dort
blau. Jetzt ist `--accent` in beiden Themen dieselbe Farbfamilie (`#2F6B4F` / `#8FC7A4`),
das Cyan gibt es nirgends mehr. Mitgeändert wurden nur die zwei Stellen, die ihren Wert
fest eintrugen: `--map-active` und `--map-active-line` (die hervorgehobene Fläche in einer
Landkarte). Die Hintergründe des dunklen Themas (`--bg`, `--panel`, `--tile-bg`) sind
absichtlich unverändert geblieben: sie sind blaugrau und tragen den Charakter dieses
Themas. Ob sie wärmer werden sollen, ist eine eigene Frage.

**Warum der Primärknopf im hellen Thema schwarz ist**

`[data-theme="light"] .dialog__button--primary` ist `var(--text)`, also fast schwarz, und
das ist Absicht: **Grün bedeutet in dieser Anwendung „hier stehen Karten an"**
(`.row__learn.has-due`, `.learn-button`). Ein grüner „Speichern"-Knopf in einem Dialog, in
dem es um Karten geht, würde dieselbe Farbe für zwei Bedeutungen benutzen. Im dunklen Thema
trägt der Knopf die Akzentfarbe, weil `--text` dort hell ist. Wer die Knöpfe in beiden
Themen grün will, ändert eine Regel - aber dann kollidiert das Grün mit dem Zeichen für
fällige Karten.

**Fotos an einer Lernkarte**

Seit dem 17.10.2026 kann jede Seite einer Karte ein Foto tragen. Hochgeladen wird
im Kartenformular; die Datei geht erst mit dem Speichern an den Server, weil eine
neue Karte vorher noch keine Id hat. Der Server liest jedes Bild selbst, dreht es
nach den EXIF-Daten, verkleinert die längste Seite auf 1600 Pixel und schreibt es
neu – dadurch verschwinden EXIF-Daten, und ein umbenanntes Skript kann nicht als
Bild durchgehen. Angenommen werden JPG, PNG und WebP bis 2 MB; SVG ist absichtlich
nicht dabei, weil es Skript enthalten kann. Gespeichert wird nur ein zufälliger
Dateiname mit fester Endung. Hochladen und Entfernen verlangen den CSRF-Token der
Sitzung: beim Upload reist er im Formular mit, beim Entfernen in einem eigenen
Kopf.

Auf der Lernkarte steht das Foto unter dem Text der jeweiligen Seite. Bei einer
Karte, die in beide Richtungen geübt wird, tauschen die Fotos beim Umdrehen mit:
Die Frage ist dann die alte Rückseite, also gehört deren Foto nach vorn.

**Zwei Bäume namens „Energy" – einer davon ist inzwischen weg**

Nutzer 6 hatte zwei Lernbereiche „Energy": den aktiven (id 3) und eine ältere Kopie
(id 193, 15 Unterkategorien, 436 Karten). Der Selektor
`.area-card[data-area-id="193"]` in `app.css` graute die Kachel der Kopie aus
(`opacity: 0.38`, `filter: grayscale(1)`).

**Die Kopie ist am 16.10.2026 gelöscht worden.** Sie trug keinen einzigen Fortschritt
(`user_card_progress`: 0 Zeilen für ihren ganzen Teilbaum), und weil daneben ein neuer
Bereich entsteht, sollte der alte nicht bleiben. Die Antwort des Servers nennt die
Zahlen: `deleted_categories` 16 (die Kopie selbst und ihre 15 Unterkategorien),
`deleted_cards` 436, `deleted_progress` 0 – alles in einer Transaktion, ein halb
gelöschter Baum kann also nicht zurückbleiben. Der aktive Bereich blieb unberührt:
7 Unterkategorien, 847 Karten, 118 Fortschrittszeilen (nachgezählt). Vor dem Löschen
liegt eine vollständige Sicherung in `storage/backups/`
(`study_20261016_141459_vor_loeschen_energie_193.sql`). Der Ausgrauungs-Selektor nennt
`[data-area-id="193"]` noch, obwohl es die Kachel nicht mehr gibt; er schadet nicht,
und die zweite Zeile (`[data-area-id="3"]`) gilt weiter.

**Ein Löschknopf, der nichts tat (gefunden und behoben am 16.10.2026)**

Eine ganze Kategorie ließ sich nicht löschen: der Dialog fragte, der rote Knopf wurde
gedrückt – und danach stellte sich dieselbe Frage noch einmal, gelöscht wurde nichts.
Die Ursache lag allein in `public/assets/js/app.js`. Der Server verlangt bei einer
Kategorie mit Unterkategorien oder Karten ein `{"confirm": true}` und antwortet ohne
Zustimmung mit dem Code `confirm_required`; die Seite schickte diese Zustimmung nie mit.
Ein früherer Umbau hatte das Löschen in die Kette `requestDelete` → `confirmDelete` →
`queueDelete` → `sendDelete` zerlegt und dabei die Zeile verloren, die die Zustimmung
baute. Sie stand danach als **toter Code** unter einem vorzeitigen `return` – also
genau an der Stelle, die beim Nachlesen den Fehler verdeckt. Jetzt geben alle drei
Aufrufe von `sendDelete` die Zustimmung mit (sie ist ja schon gegeben: der rote Knopf
ist die Antwort), auch der Weg, der beim Verlassen der Seite mit `keepalive`
abgeschickt wird. Der tote Code ist entfernt. Nachgeprüft mit einem echten Aufruf:
ohne Zustimmung `HTTP 400 confirm_required`, mit Zustimmung `HTTP 200` und den Zahlen
oben.

**Seit dem 16.10.2026 steht die Kachel von id 3 blass und ganz hinten** (derselbe
Selektor um `[data-area-id="3"]` erweitert, und `sort_order` 2000 in der Datenbank).
Gelernt wurde bis dahin in id 3 bzw. in seiner Unterkategorie id 213 „Energiegrundlagen
& Energiewende" (gemessen am 16.10.: 118 Fortschrittszeilen, 449 Wiederholungen, letzte
Einheit 15.10.2026 14:50 — die einzige Unterkategorie unter „Energy" mit Fortschritt).
Weil daneben ein neuer Bereich entstanden ist, soll der alte nicht mehr wie der Hauptweg
aussehen. **Gelöscht ist nichts:** die Kachel bleibt anklickbar und lernbar, und der
Fortschritt hängt an den Karten, nicht an der Kategorie — er wandert also nicht
automatisch in eine neue Kategorie mit neuen Karten. Wer beides zurücknehmen will,
entfernt in `app.css` die zweite Zeile des Selektors und setzt in
`database/start_page_order.sql` alle sechs Zahlen wieder auf 1000.

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

- In `src/helpers/translations.php` standen drei englische Texte mit einer
  Escape-Folge (`\u2019`, `\u00b7`). PHP wertet sie in einfachen Anführungszeichen
  nicht aus – auf dem Bildschirm stand deshalb „It\u2019s“. Jetzt stehen dort die
  echten Zeichen, und ein Suchlauf über alle Texte prüft, dass keine solche Folge
  mehr übrig ist.
- Externe Schriften: `Inter` und `Source Serif 4` liegen als Datei in
  `public/assets/fonts/`, es geht keine Anfrage mehr an einen fremden Dienst.
- Tote CSS-Regeln (`.data-pill`, `.row__arrow`, `.row__progress`, `.row__count`,
  `.row__play`, `.card-tools__label`) und fünf unbenutzte CSS-Variablen sind raus.
- `public/assets/icons/informatik.svg` ist weg, es liegt nur noch
  `browser_icon.svg` dort.

## 11. Sicherungskopien

Die Kartenfotos in `public/uploads/cards/` sind **nicht** Teil eines
`mysqldump`-Abzugs: In der Datenbank steht nur der Dateiname. Wer eine Sicherung
zurückholt, muss den Ordner deshalb zusätzlich kopieren, sonst verweisen die
Spalten `image_front` und `image_back` auf fehlende Dateien.

Die Dumps liegen **außerhalb** des Repos unter `/home/user/backups/` und sind
nicht eingecheckt. Die älteste und kleinste ist ein vollständiger Dump vom
22.09.2026 (`learning_app_vollstaendig_20260922_162310.sql`, 375 185 Bytes); daneben
liegen fünf Sicherungen `learning_app_vor_*.sql`, die vor einzelnen Umbauten
angelegt wurden, und ein Ordner `arbeitsstand_20260926`.

Für einen Neuaufbau: erst `database/schema.sql`, dann einen Dump einspielen. Der
Dump vom 22.09. ist **älter** als einige Schemaänderungen – die Struktur kommt
deshalb aus `schema.sql` und nicht aus dem Dump.

## 12. Wenn VS Code aussteigt (WSL)

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

## 13. Die Ansicht auf dem Handy

Bis 480 beziehungsweise 639 Pixel Breite sieht die Anwendung anders aus – nicht, weil es
ein zweites Blatt gäbe, sondern weil `app.css` und `story.css` dort umschalten. **Am
Rechner ändert sich nichts:** dieselben Kacheln im Karussell, dieselbe offene Themenliste,
dieselben Bearbeiten-Knöpfe, dieselbe Kennzeile in der Geschichte.

**Die Startseite scrollt wieder.** Bis zum 12.10.2026 hatten die Kacheln hier eine feste
Höhe (`clamp(260px, 34vh, 340px)`), und die Reihe darin schnitt alles darunter ab
(`overflow-y: hidden`). Auf einem 375 Pixel breiten Fenster war die Seite damit genau so
hoch wie das Fenster selbst: `window.scrollTo` tat nichts, und fünf von sieben
Themengebieten waren unerreichbar. Jetzt gibt `.tiles` dort seine Höhe ab (`height: auto`),
die Kacheln stehen als Liste untereinander, und die Seite wächst und scrollt wie jede
andere.

**Eine Kachel ist eine Zeile.** 72 Pixel hoch: Symbol links (44 statt 72 Pixel), Name und
eine Zeile Zahlen rechts. Das Menü an der Kachel (`…`) erscheint dort nicht – auf dem Handy
wird gelesen und gelernt; die Kachel selbst bleibt antippbar und führt weiter zum Lernen.
Der Seitenrand ist 16 statt 24 Pixel.

Das `…`-Menü hängt an `.area-card-slot` und **nicht** an der Kachel: es ist ein eigener
Knopf neben dem Verweis, weil ein Knopf in einem Verweis kein gültiges Markup wäre (siehe
`buildAreaTile` in `app.js`). Ausblenden lässt es sich deshalb nur mit
`.area-card-slot .tile-menu`. Der frühere Selektor `.area-card .tile-menu` traf nichts, und
weil ein grober Zeiger (Finger) das Menü ohnehin dauerhaft einblendet, standen die drei
Punkte auf dem Handy auf jeder Kachel (korrigiert am 15.10.2026).

**Das Zeichen über der Begrüßung ist das Browser-Symbol.** Die Karte „Deine Karten warten
auf dich" trägt das Favicon der Seite (`assets/icons/browser_icon.svg` — dieselbe Datei, die
im `<head>` als `rel="icon"` steht): **44 Pixel** am Rechner, **40** auf dem Handy,
zentriert, 18 Pixel über der Überschrift. Davor stand dort ein gezeichneter Stapel aus zwei
Karten — am Rechner und auf dem Handy zwei verschiedene Zeichen; jetzt ist es überall
dasselbe. Die Datei steht in `public/index.php`, ihre Größe in `app.css`
(`.notice__icon`), und das Bild ist für Vorlesehilfen versteckt (leeres `alt`, es sagt nur,
was die Überschrift darunter sagt).

**Die Kacheln sitzen enger unter der Überschrift.** `.tiles` hatte `margin-top: auto` und
schob damit den **ganzen freien Platz** der Ansicht zwischen Überschrift und Kacheln: in
einem 900 Pixel hohen Fenster gemessen 211 Pixel zwischen Kopf und Kachelband — der Titel
hing oben allein, die Kacheln klebten unten. Jetzt sammelt `.tiles-nav` den Platz ein
(`margin-top: auto`): die Kacheln folgen der Überschrift im Abstand der Ansicht (gemessen
**12 statt 211 Pixel**), und die Leiste bleibt trotzdem die feine Linie über der Fußzeile
(36 Pixel darüber, unverändert). Am Handy dasselbe Bild; dort ist die Leiste ausgeblendet,
der freie Platz rutscht also ans Ende der Ansicht. Die Abstände *innerhalb* des
Kachelbandes (`.area-grid`, `--tile-gap`) und nach oben zur Kopfzeile sind nicht angefasst.

**Die Kacheln füllen den Platz bis zur Fußleiste.** Der Leerraum lag damit zwischen Kacheln
und Leiste statt zwischen Titel und Kacheln - kleiner war er aber nicht, und er wuchs mit dem
Fenster: bei 1280×900 gemessen **124 Pixel** zwischen Kachel-Unterkante und Trennlinie, bei
1280×1400 **545**. Die Ursache stand in `.tiles`: `flex: 0 0 auto` mit fester Höhe nahm seinen
Anteil und ließ den Rest liegen. Ab **901 Pixel** wächst die Reihe jetzt in diesen Platz
hinein (`flex: 1 0 auto`), die Kacheln werden höher und gewinnen die Luft in der Mitte, weil
die Kachel ihren Inhalt mit `justify-content: space-between` verteilt (Zeichnung oben, Name
und Zahl unten). Gemessen bei 1280×900: Kachel 276 → **356 Pixel** hoch, Abstand zur Linie
124 → **44**, Luft in der Kachel 102 → **182**; bei 800 Pixel Fensterhöhe 69 → 44, bei
1024×900 163 → 44. Die **Höchstgrenze von 440 Pixeln** verhindert schmale Türme auf hohen
Fenstern: bei 1100 und 1400 Pixel Fensterhöhe stoppt die Reihe dort, der Rest bleibt als
ruhiger Abstand über der Leiste (169 beziehungsweise 445 Pixel). Die Fußzeile bleibt dabei am
unteren Rand verankert - der `+`-Knopf sitzt in jeder geprüften Größe 51 Pixel über dem Rand,
wie auf der Detailseite. Der Abstand Überschrift → Kacheln bleibt bei 24 Pixeln, und bei 620
Pixel Fensterhöhe ändert sich gar nichts (dort war schon kein freier Platz).

Unter 901 Pixeln gilt das absichtlich nicht: dort steht die Reihe als zweispaltiges Raster
(bis 900 Pixel) oder als Liste (bis 480 Pixel), und in beiden Fällen bestimmt der Inhalt die
Höhe der einzelnen Kachel. Eine gedehnte Reihe ließe dort unter der letzten Kachel nur leeren
Raum stehen - bei 768×900 nachgemessen 132 Pixel, deshalb steht die Grenze im Stylesheet und
nicht am Wunsch "möglichst viel Platz".

**Im dunklen Thema wird das Symbol umgedreht.** Die Datei ist einfarbig grau (`#333333` und
`#8c8c8c`), und ausgerechnet der dunkle Ton verschwindet auf der dunklen Fläche: übrig
blieb ein halbes Zeichen (im Browser gesehen, bevor es die Regel gab).
`[data-theme="dark"] .notice__icon { filter: invert(1) }` dreht beide Grautöne um —
die Form bleibt, der Abstand zwischen den beiden Tönen auch (0,31 → 0,69).

**Die Themenliste ist ein Klappmenü.** `sidebar__fold` ist ein `details`-Element, das der
Browser von sich aus auf- und zuklappen kann – dafür braucht es kein Skript. Am Rechner
steht es offen und ist nicht anklickbar (`pointer-events: none`), das Zeichen rechts ist
dort ausgeblendet; bis 639 Pixel klappt `initSidebarFold()` in `app.js` (und
`foldSidebar()` in `story.js`) es beim Laden zu. Vorher war die Liste hier eine waagerecht
scrollbare Leiste, in der der letzte Eintrag abgeschnitten aussah.

**Der offene Menüeintrag ist eine Tönung, kein Balken.** Vorher lagen dort drei Dinge
übereinander: die graue Fläche `--surface-soft`, ein 2 Pixel breiter Streifen links
(`box-shadow: inset 2px 0 0 …`) und der farbige Punkt. Jetzt ist die Fläche eine Tönung von
5 % (hell) beziehungsweise 7 % (dunkel) der Textfarbe, der Streifen ist weg, und der Punkt
davor sagt, welcher Eintrag offen ist (`opacity: .55` in Ruhe, `1` beim offenen Eintrag).
Die Zeilen sind 44 Pixel hoch und haben 4 Pixel Abstand - Punkte und Namen stehen damit in
einem gleichmäßigen Rhythmus.

**Der Pfeil am Klappmenü ist als Schalter zu erkennen.** Auf dem Handy sitzt das Zeichen in
einem weichen Kreis (26 Pixel, `--tile-bg`, ohne Rahmen) und dreht sich beim Aufklappen.
Vorher war es ein nackter Pfeil, der aussehen konnte wie ein zufälliges Zeichen neben der
Beschriftung.

**Der Kopf einer Ansicht ist kompakter.** `.detail__head` hat 14/18 statt 20/24 Pixel
Innenabstand und eine Zeichnung von 44 statt 54 Pixeln.

**Der Titel der Startseite hat mehr Luft unter der Kopfzeile** (`#view-home > .start-header`,
`margin-top: clamp(16px, 3vh, 32px)`). Der Abstand kam bis dahin allein aus dem Abstand der
Seite (36 Pixel bei 900 Pixel Fensterhöhe); weil die 60 Pixel große Überschrift aber
`line-height: 1` hat, trug sie kaum Fleisch über den Buchstaben, und gemessen blieben nur
**29 Pixel** zwischen der Unterkante der Kopfzeile und der Oberkante von „Wähle ein". Jetzt
sind es **56**. Der Zusatz sitzt am Titelkopf und nicht am Abstand der Seite, weil sonst die
Fußzeile und die Detailansicht denselben Zuschlag bekommen hätten; die Kopfzeile bleibt, wo
sie ist, denn sie ist Bedienung. Der freie Platz darunter ist unberührt: zwischen Titel und
Kacheln bleiben es in jeder geprüften Fenstergröße **24 Pixel**, weil die Blätterleiste ihn
mit `margin-top: auto` weiterhin an sich zieht. Geprüft in hell und dunkel bei 1280×900
(29 → 56), 1280×620 (18 → 36), 768×800 (28 → 52) und 375×667 (24 → 44); kein waagerechter
Überlauf. Nur ein sehr flaches Fenster (1280×620) scrollt dadurch etwas weiter, dort scrollt
die Seite aber ohnehin schon.

**Die Überblickszeile unter dem Kopf ist eine Zeile, kein Kastenviertel.** Sie zeigt die
Zahlen **einer** Unterkategorie: „15 Karten · 0 gewusst · 0 unsicher" — 11 Pixel, `--muted`,
die Ziffern in `--text`, mit feinen Punkten dazwischen. Vorher standen dort vier große
Kacheln (fällig, sitzt schon, unsicher, in Folge gelernt) und, nach einem Zwischenschritt,
gar nichts: erst zählte die Zeile die Karten des ganzen Themengebiets, also eines Zweigs, den
die offene Seite nicht zeigt. Jetzt füllen zwei kleine Funktionen sie - `renderCardFigures`
für die erste Zahl (nur dort sind Filter und Suche bekannt, deshalb steht dort bei einer
gefilterten Liste „12 von 40 Karten") und `renderCardSummary` für die beiden anderen. Auf der
Ebene eines Lernbereichs bleibt sie versteckt. Der Zähl-Anlauf auf der ersten Zahl
(`animateCount`) ist geblieben.

**Die vier Kacheln sind aus** (`#detail-dashboard { display: none }`). Sie waren
Lernstatistik und beherrschten hier den Kopf; die Zahlen stehen jetzt in der Zeile darüber.
Markup und Skript bleiben stehen - eine Zeile entfernen holt sie zurück, und keine Zahl geht
verloren. Drei Funktionen sind dabei ganz verschwunden, weil sie zu dieser Zeile gehörten und
sonst niemand sie rief: `renderFigures`, `renderDeckFigures` und `overallProgressForArea`
(samt ihren Übersetzungsschlüsseln `detail.cardsThisDeck.*`, `detail.cardsWholeDeck.*` und
`dash.overallKnown`).

**Die Zahl an den Lernen-Knöpfen bleibt** (`.row__learn-badge`, 26 Pixel): sie sagt, wie
viele Karten dieser Zeile gerade fällig sind - gefüllt in der Akzentfarbe, wenn es welche
gibt, sonst ein leiser Umriss mit der Null.

**Die Kartenliste ist ein Stapel, keine Tabelle.** Jede Karte liegt in einer eigenen Fläche:
1 Pixel `--line`, 12 Pixel Rundung, `--surface` als Grund, 13/15 Pixel Innenabstand und
8 Pixel Abstand zur nächsten Karte (`gap` am `.rows`-Stapel). Vorher war jede Karte eine
Zeile zwischen zwei gepunkteten Linien. Die gepunktete Linie über der Liste und der
Einrück-Effekt beim Zeigen sind weg: die Zeile bleibt stehen, nur Rahmen und Fläche werden
eine Spur deutlicher. Alles davon hängt an `.rows:has(.row--card)`, damit die Liste der
Unterkategorien (`.row--category`) ihre Tabelle behält.

**In der Zeile gilt eine klare Reihenfolge.** Die Frage steht als Titel (15 Pixel, 600,
`--text`), die Antwort darunter in 13 Pixeln und `--muted` und wird mit `…` abgeschnitten,
wenn sie zu lang ist. Das `…`-Menü saß vorher **links** (`order: -1`) in einer eigenen
Spalte: der Fragetext begann erst danach und rückte je nach Zeile unterschiedlich weit ein.
Jetzt ist es das letzte Element der Zeile (gemessen 16 Pixel vom rechten Rand) und im
Ruhezustand auf 40 % Deckkraft gedämpft - beim Zeigen und im Tastaturfokus wird es voll
sichtbar. Der Status ist kleiner geworden (10 Pixel Schrift, 6 Pixel Punkt) und hat seine
reservierte Breite von 84 Pixeln verloren, die jede Zeile auseinanderzog.

**Auf dem Handy bekommt der Text die ganze Breite.** Merker und Status stehen rechts und
nahmen ihren Platz unabhängig von der Fensterbreite: bei 375 Pixeln blieben Frage und Antwort
91 Pixel, eine lange Antwort lief in fünf Zeilen. Jetzt rutscht die Spalte unter den Text
(`flex-wrap` am `.row__link`), und die Antwort wird nach zwei Zeilen abgeschnitten
(`-webkit-line-clamp: 2`).

**Die Werkzeugleiste trägt dieselbe Rundung wie die Karten.** Die Filter-Chips waren Pillen
(999px), was neben Flächen mit 12 Pixeln Rundung wie ein anderes Bauteil aussah; sie sind
jetzt 13 Pixel groß und 5/12 Pixel gepolstert, die Leiste hat 16 Pixel Abstand nach oben, und
zwischen Leiste und Liste liegen 24 Pixel. Der ganze senkrechte Rhythmus dieser Seite ist
damit 12 (Kopf → Zeile), 14 (Zeile → Aktionen), 16 (Aktionen → Werkzeugleiste), 24
(Werkzeugleiste → Liste) - jede Stufe größer als die vorige.

**Am Rechner gibt es hier keinen Lernen-Knopf.** Die Seite ist die Verwaltung (anlegen,
importieren, öffnen); gelernt wird im Lernmodus, und dorthin führt der Lernen-Knopf in der
Zeile des Lernbereichs darüber. Bis 639 Pixel bleibt er stehen - dort sind die
Verwaltungsknöpfe aus, und eine Seite ganz ohne Handlung wäre eine Sackgasse.

**Auf einer Deck-Ansicht wird nur gelernt.** Bis 480 Pixel ausgeblendet: `#detail-actions`
(Karte anlegen, Importieren, Vokabeln bearbeiten), das Menü `#detail-menu`, die Menüs an
den Karten (`.row__menu`) und die Kacheln der Übersicht (`#detail-dashboard`). Das
`!important` ist Absicht: `app.js` blendet einzelne Blöcke selbst ein und aus, und diese
Regel muss stärker sein als ein spät gesetztes `hidden`. Lernen, Durchsehen und Suchen
bleiben.

**Die Kopfzeile ist kürzer, aber überall gleich.** Bis 639 Pixel entfällt der Pfad
(`START — STORY TOGETHER`, `.crumb { display: none }`) — auf einem schmalen Fenster ist er
die halbe Kopfzeile für nichts. Links steht nur das **Konto** (Name und „Abmelden"), eine
Spur kleiner als am Rechner; rechts bleiben Sonne/Mond und `EN | DE`, an derselben Stelle
wie auf der Startseite — die Kopfzeile kommt aus `src/helpers/site_header.php` und wird
von allen Seiten benutzt, es gibt also keine zweite Fassung, die auseinanderlaufen
könnte. Beide Seiten stehen auf derselben Höhe (gemessen: Mitte 37 zu 37).

**Rechts oben ist es eine Gruppe, kein Nebeneinander.** Sonne/Mond, ein feiner Strich
(`.site-header__divider`, 1 × 16 Pixel) und `EN | DE` stehen mit 8 Pixeln Abstand
beieinander - dieselbe Zahl auf allen Seiten und in beiden Größen. Vorher lagen zwischen
dem Schalter und der Sprache 20 Pixel: das sah aus wie zwei Bedienelemente, die zufällig
nebeneinander stehen. Der **Themenwechsler trägt keinen Kreis mehr**: mit 32 Pixeln
Durchmesser und Rahmen war die Sonne das größte Element der ganzen Kopfzeile, obwohl sie
nur umstellt, wie die Seite aussieht. Jetzt ist es ein Zeichen von 15 Pixeln (auf dem Handy
12) in derselben gedämpften Farbe wie das inaktive Sprachkürzel; den Kreis gibt es nur
beim Zeigen und im Tastaturfokus (`.theme-toggle:hover`). Der Sprachumschalter ist eine
Textzeile von 24 Pixeln Höhe (`min-height`), die Kürzel stehen 6 Pixel auseinander.

**Der Unterstrich hängt an der Textmitte, nicht am Kastenboden.**
`.lang-switch__underline` sitzt auf `bottom: calc(50% - 10px)` und nicht auf `bottom: 0`:
gemessen liegt er damit 2,5 Pixel unter den Buchstaben - und zwar auch dann, wenn die
Kästchen auf einem Fingergerät 44 Pixel hoch werden (`@media (hover: none), (pointer:
coarse)`). Mit `bottom: 0` wären es dort 17 Pixel gewesen und der Strich hätte wie eine
eigene Linie gewirkt.

**Auf einer Themengebiet-Seite wird auf dem Handy nur gelernt.** Bis 639 Pixel
ausgeblendet: `#detail-actions` (Unterkategorie anlegen, Auswählen), das Menü der Seite
(`#detail-menu`), das Menü an jeder Zeile (`.row__menu`), das Auswahlkästchen
(`.row__check`) und die Kacheln der Übersicht (`#detail-dashboard`). Die Liste bleibt
stehen, und **ihre Namen führen nicht mehr auf die Unterseite**: `.row:has(.row__learn)
.row__link { pointer-events: none }`. Der Zusatz `:has(.row__learn)` ist wichtig, weil
`.row__link` auch die Zeile einer **Karte** in einer Unterkategorie trägt — und die soll
man weiterhin ansehen können. Nur die Zeilen **mit** Lernen-Knopf sind Unterkategorien;
bei ihnen wirkt der Anker nicht mehr, der Lernen-Knopf daneben aber schon (gemessen:
Kartenzeile `auto`, Unterkategorie `none`, Knopf `auto`). Das `!important` bei den
ausgeblendeten Blöcken ist Absicht: `render()` in `app.js` blendet einzelne Blöcke selbst
ein und aus, und diese Regeln müssen stärker sein als ein spät gesetztes `hidden`.

**Die Schreibaufgabe bleibt eine Zeile.** Bis 640 Pixel darf die Leiste nicht mehr
umbrechen (`flex-wrap: nowrap`): die Beschriftung links schrumpft und bekommt ein
Auslassungszeichen, wenn kein Platz ist, während die Zeitform rechts nie umbricht
(`white-space: nowrap`) und ihren Platz behält. Schrift und Abstände sind eine Spur
kleiner. Gemessen bei 375 Pixel: Leiste 37 Pixel hoch, „Schreibaufgabe für heute" und
„Present Simple" auf derselben Höhe.

**Überschriften bleiben einzeilig.** Bis 639 Pixel sind sie kleiner — 26 Pixel statt 32
bis 40 auf der Startseite, 24 in einer Detailansicht — und die beiden Zeilen aus
`home.heading` laufen nebeneinander statt untereinander (`.heading__mask` und
`.heading__line` werden `inline`, `max-width: 18ch` fällt weg: das wären bei 26 Pixel nur
rund 230 Pixel und damit wieder zwei Zeilen). Das **trennende Leerzeichen** setzt
`setHeading()` in `app.js` zwischen die beiden Elemente: am Rechner sieht man es nicht,
im Text der Überschrift heißt sie damit „Wähle ein Themengebiet\" und nicht „Wähle
einThemengebiet\" — und auf dem Handy ist es genau der Abstand zwischen den Wörtern.
Gemessen bei 375 Pixel: Überschrift 335 × 31 Pixel, also eine Zeile. Ein
`white-space: nowrap` gibt es bewusst nicht — bei einem sehr langen Namen ist ein Umbruch
immer noch besser als ein Überlauf.

**Der Dialog „Wie viele neue Karten?"** ist zentriert und passt auf jeden Bildschirm:
`.count-dialog` hat `max-height: calc(100svh - 32px - var(--safe-bottom))` und
`overflow: hidden`, das Panel darunter sein eigenes `max-height` mit eigenem Scroll —
vorher stand der Dialog über den
Rand hinaus, und weil der Browser ihn mittig setzt, war er oben **und** unten
abgeschnitten. Der Innenabstand steckt in den Kindern (Titel, Schnellwahl, Aktionen),
**nicht** im Panel: ein Padding am Panel würde sich zu ihren Abständen addieren. Zwei
Balken nebeneinander gibt es nicht: am Fenster ist der Überlauf verborgen, gescrollt wird
nur im Panel.

**Bis 639 Pixel kommt er als Blatt von unten** (16.10.2026). Davor stand hier ein
zentrierter Dialog, und der war auf dem iPhone zweimal hintereinander unten
abgeschnitten: zuerst überließ er das Zentrieren dem Browser (`inset: 0` mit
`margin: auto`, das rechnet mit der Layout-Fläche statt mit dem Sichtbaren), dann über
`top: 50dvh` und `translate: 0 -50%`, schließlich über `margin: auto` in einem Rahmen aus
`svh`/`lvh`. Alles drei saß im mobilen Fenster am Rechner sauber und auf dem Gerät nicht.

Jetzt ist der Aufbau ein anderer statt nur eine andere Rechnung: das Fenster hängt am
unteren Rand und kann deshalb nicht mehr „zu tief in die Mitte rutschen“, egal wie die
Browser-Leiste steht. Es bleiben drei Werte, die man am Gerät nachprüfen kann:

* `position: fixed; bottom: 0; left: 0; right: 0; width: 100%; margin: 0` — das Blatt
  klebt unten und läuft über die volle Breite. `margin: 0` ist dabei die halbe Miete: der
  Browser setzt für einen Dialog `margin: auto` und zentriert ihn damit.
* `max-height: 85svh` — `svh` ist das Fenster im kleinsten Zustand (mit sichtbarer
  Leiste). Wird der Inhalt länger, scrollt er innen; die Knopfzeile ist
  `position: sticky; bottom: 0` und bleibt dabei stehen.
* `padding-bottom: max(16px, var(--bottom-safe))` — der Streifen des Home-Indikators UND
  der der Browser-Leiste gehören zum Blatt, bleiben aber leer (siehe unten:
  `--browser-bottom`).

**Der entscheidende Rest war die Browser-Leiste selbst** (16.10.2026). Auf dem iPhone
sitzt ein `position: fixed`-Element mit `bottom: 0` nicht am sichtbaren Rand, sondern am
unteren Rand der Layout-Fläche — um die Höhe der Leiste zu tief. `env(safe-area-inset-bottom)`
(= 34 px) ist der Home-Indikator, und der liegt hinter der Leiste; die 34 px reichten
also nicht, die Knopfzeile saß weiter unter der Leiste.

Keine CSS-Einheit kennt diese Leiste (`dvh`, `svh` und `lvh` haben es auf dem Gerät nicht
getroffen), also wird sie **gemessen**: `watchBrowserBottom` in `app.js` hängt eine
unsichtbare Hilfe mit `top: 0; bottom: 0` ins Dokument — die hat genau die Höhe der
Layout-Fläche, also das, worauf sich `bottom: 0` bezieht — und vergleicht sie mit dem, was
`visualViewport` als sichtbar meldet. Die Differenz steht als `--browser-bottom` in `:root`
und wird bei `resize`/`scroll`/`orientationchange` neu gesetzt. `--bottom-safe` ist das
Größere von `--safe-bottom` und `--browser-bottom` (die beiden Streifen liegen übereinander,
sie addieren sich also nicht) und wird an allen unteren Rändern benutzt: dem Blatt, dem
`padding-block-end` der Seite (Fußzeile mit Plus-Knopf), der Lernansicht, `.learn__ask`,
`.feedback` und `.learn__notice`. Ohne `visualViewport` bleibt der Wert 0, dann gilt das
Verhalten von vorher — am Rechner ändert sich nichts.

Dazu die Form: nur oben abgerundet (`border-radius: 20px 20px 0 0`), Schatten nach oben
statt nach unten, und beim Öffnen fährt das Blatt mit `transform: translateY(100%)` nach
`translateY(0)` hoch (200 ms, dieselbe Zeit, nach der `app.js` beim Schließen wirklich
schließt). Das Überlagerte bleibt das `::backdrop` (halbtransparent mit Weichzeichner;
Escape, das X, „Abbrechen“ und ein Tippen aufs Überlagerte schließen — letzteres im
Zähl-Dialog über den neuen Klick-Hörer in `wireNewCardsDialog`).

Gemessen im mobilen Fenster (390 × 844, Chromium): Blatt 557 … 844, also volle Breite und
bündig mit dem unteren Rand, Höhe 287, `max-height` 717 (= 85 %), Ecken `20px 20px 0 0`,
Knöpfe 756 … 804. Mit nachgestelltem Home-Indikator (34 px): `padding-bottom` 34 px,
Knöpfe enden 58 px über dem Rand. Mit nachgestellter Browser-Leiste (50 px) und Indikator:
`padding-bottom` bleibt 50 px (nichts addiert sich), Knöpfe 722 … 770, also 24 px Luft bis
zur Leiste; das `padding-block-end` der Seite geht auf 50 px, `.feedback` auf 78 px. Mit
sehr langem Inhalt: Höhe exakt auf 85 % gedeckelt, das Panel scrollt, die Knopfzeile
bleibt stehen. Ab 640 Pixel ist nichts angefasst — dort zentriert der Browser weiterhin
(gemessen bei 1280 × 900: Dialog 358 … 543, Mitte 450 = Fenstermitte, `transform: none`,
Rundung 16 Pixel).

**Dasselbe Blatt gilt für alle drei Dialoge** (16.10.2026): `.dialog` (Anmelden,
Kartendialog), `.account-dialog` (Konto) und `.count-dialog` (Anzahl neuer Karten). Alle
drei setzten sich auf die Voreinstellung des Browsers und hatten damit dieselbe Schwäche —
`.dialog` hatte `inset: 0; margin: auto` selbst im 639-Pixel-Block stehen, die beiden
anderen verließen sich ganz auf den Browser. Gemessen bei 390 × 844: Zähl-Dialog
557 … 844 (Höhe 287), Konto-Fenster 556 … 844 (288), Anmeldeformular 475 … 844 (369) —
alle drei bündig unten und über die volle Breite. Ab 640 Pixel ist nichts angefasst.

**Eine Einschränkung, die noch auf dem Gerät zu prüfen ist:** Das Anmeldeformular ist
ein Eingabe-Dialog. Öffnet sich dort die Tastatur, kann sie ein unten verankertes Blatt
verdecken. Am Rechner lässt sich das nicht nachstellen. Wenn es auf dem iPhone stört,
gehört dieser eine Dialog nach oben statt nach unten — `.dialog` ist dafür die einzige
Stelle, die geändert werden müsste.

**`viewport-fit=cover` und die Ränder des iPhones** (16.10.2026). Das Ansichtsfenster in
`src/helpers/page_head.php` trägt jetzt `viewport-fit=cover` — ohne diese Angabe wären
alle `env(safe-area-inset-*)`-Werte 0 und jede Regel dagegen wirkungslos. Die Folge: die
Seite läuft bis in die Ecken, und was am Rand steht, muss selbst Abstand halten. Am Ende
von `app.css` stehen dafür einmal `--safe-top/right/bottom/left` (überall sonst nur noch
benutzt) und die betroffenen Stellen:

* `.page` — `padding-block-start/end` auf `max(<bisheriger Wert>, var(--safe-*))`. Das
  erwischt die Kopfzeile (Nutzer, Abmelden, Sonne/Mond, EN/DE) und die Fußzeile (Pfeile,
  Plus-Knopf) auf allen drei Seiten, weil alle dieselbe `.page` benutzen. Seitlich kommt
  `padding-inline: var(--safe-left) var(--safe-right)` dazu; das wirkt nur im Querformat,
  im Hochformat sind beide Werte 0.
* `.learn` — die Lernansicht füllt den Bildschirm (`inset: 0`): der Schließen-Knopf soll
  nicht unter der Uhr sitzen, die vier Bewertungen nicht hinter dem Home-Indikator. Dazu
  rechnet die Karte bis 640 Pixel mit `58svh` statt `58vh` — `vh` zählt die von der
  Browser-Leiste verdeckten Streifen mit, die Karte wäre sonst um deren Höhe zu groß und
  drückte die Bewertungen wieder nach unten; `svh` ist die kleinste Höhe, die das Fenster
  annehmen kann.
* `.learn__ask`, `.feedback` und `.learn__notice` sind eigene `position: fixed`-Ebenen und
  hängen deshalb nicht an `.learn`; sie bekommen die Ränder selbst.
* Gemessen mit simulierten Rändern (die DevTools-Mobilansicht liefert `env()` = 0, deshalb
  wurden die vier Werte direkt gesetzt): `.page` oben 24 → 47, unten 32 → 34, seitlich
  0 → 44/44; die Kopfzeile beginnt bei 47 Pixel; `.learn` oben 47, unten 34; die vier
  Bewertungen enden 32 Pixel über dem Home-Indikator; `.feedback` unten 62 statt 28,
  `.learn__notice` 60 statt 26. Ohne Ränder (Rechner) sind alle Werte unverändert — dort
  ändert `cover` nichts.

Fläche, Rundung (16 Pixel) und Schatten teilt der Dialog mit dem Kontodialog — diese Regeln
stehen weiter oben gemeinsam für beide. Was ihn unterscheidet, steht in einem eigenen
Abschnitt **am Ende** von `app.css`, und das ist Absicht: dort gewinnen die Regeln gegen
die älteren `.count-dialog`-Regeln weiter oben, die der Überschrift zum Beispiel noch 24
Pixel geben. Im Einzelnen:

* **Die Vorschläge (0 / 5 / 10) sind die Bedienung — als Text, nicht als Pillen**
  (16.10.2026). Sie sind 46 Pixel hoch (der Daumen trifft auch die blanke Zahl), 16 Pixel
  groß und alle gleich breit (`flex: 1 1 0`) statt je nach Textlänge unterschiedlich.
  `NEW_CARDS_CHOICES` in `app.js` hält genau diese drei Zahlen; vorher waren es 5, 10, 20
  und „Alle“ (den Übersetzungsschlüssel `learn.newCardsAll` gibt es deshalb nicht mehr).
  **Die Null heißt „heute nichts Neues“:** die Einheit wiederholt dann nur, was ohnehin
  fällig ist. Ist nichts fällig, läuft sie gar nicht erst los, sondern sagt „hier gibt es
  noch nichts zu lernen“ (`beginLearnSession` prüft die leere Schlange schon lange).
  **Mehr als es gibt, lässt sich nicht wählen:** die Aufgabenliste bringt gar nicht mehr
  neue Karten mit, `limitNewCards` schneidet also von selbst ab — die Vorschläge werden
  deshalb nicht mehr nach der verfügbaren Zahl gefiltert. Der vorausgewählte Wert ist die
  größte angebotene Zahl, die es wirklich gibt (bei 15 neuen Karten also 10, bei 3 die 0).
  Bis zum 16.10.2026 trug jeder Vorschlag einen Rahmen und die gewählte Zahl zusätzlich
  einen Fokusring.
  **Geprüft** mit abgefangener Aufgabenliste (Kategorie 2, Fenster 390 × 844): bei 12 neuen
  Karten stehen genau `0 / 5 / 10` da und die 10 ist vorausgewählt; bei 3 neuen Karten die
  0; ein Tipp auf „0“ verschiebt die Markierung; „Starten“ mit „0“ und ohne fällige Karte
  meldet „hier gibt es noch nichts zu lernen“; mit einer fälligen Karte startet die Einheit
  mit genau dieser einen (`1 of 1`).
* **Die Markierung steckt im Text** (16.10.2026). Die gewählte Zahl ist in der
  Akzentfarbe und **unterstrichen** (2 Pixel, 6 Pixel Abstand zur Ziffer, beide Werte im
  Text); die anderen bleiben in der gedämpften Textfarbe ohne Rahmen. Beim Zeigen wird
  die blanke Zahl zur Textfarbe, die gewählte eine Spur kräftiger (`--count-hover`).
  Der Grund für den Umbau: Safari zeichnet einen `outline` **nicht entlang der Rundung**,
  sondern als Rechteck — über den runden Ecken der gewählten Pille lag ein gerader Strich,
  und sie sah abgeschnitten aus. Ein Strich im Text kann dagegen nicht beschnitten werden,
  und Text hat auch keine Kante, die clippen könnte.
* **Der Tastaturfokus ist ein dünner, gepunkteter Strich** (1,5 Pixel, in der Textfarbe,
  ebenfalls im Text, kein `outline` und kein Schatten). Er steht im Stylesheet NACH der
  Wahl, damit auch an der gewählten Zahl zu sehen ist, wo der Fokus gerade steht: die
  gewählte Zahl behält dabei die Akzentfarbe und bekommt nur den gepunkteten Strich.
  Gemessen (390 × 844): alle drei Zahlen auf 680 … 726, also gleiche Höhe und Grundlinie;
  ohne Fokus keine Rahmen, kein `border-radius`, kein `box-shadow`; im hellen Thema
  `--muted` für die stillen und `#2F6B4F` für die gewählte, im dunklen `#8FC7A4` auf der
  dunklen Fläche.
* **Es gibt kein Zahlenfeld mehr.** Bis zum 12.10.2026 stand darunter noch eine Zeile mit
einem schmalen Feld für eine eigene Zahl. Die vier Vorschläge decken das ab, was man
wirklich braucht, und ein zweites Bedienelement daneben ließ sie wie eine Abkürzung für
dasselbe aussehen - zwei Wege für eine Frage. Mit dem Markup sind auch die Regeln
verschwunden (`__field`, `__label`, `__input`) und die Übersetzungsschlüssel
`learn.newCardsField` und `learn.newCardsError`. In `app.js` hält `newCardsChoice` die
gewählte Zahl, es gibt also nichts mehr zu prüfen und keine Fehlerzeile. Der Fokus geht
beim Öffnen auf die gewählte Zahl; **Enter an der Reihe bestätigt die Wahl** (der Zuhörer
sitzt an `.count-dialog__quick`, damit Enter auf „Abbrechen" weiterhin abbricht).
* **„Starten" ist der einzige Knopf mit Fläche**, „Abbrechen" ist ein Textlink, der nur die
Farbe wechselt; beide sind 48 Pixel hoch (am Rechner wie auf dem Handy, damit auch der
Textlink mit dem Daumen zu treffen ist). Beide tragen die Akzentfarbe der App
(`--accent`): im hellen Thema `#2F6B4F`, im dunklen `#8FC7A4`. Der Kontrast der Schrift
darauf ist 6,3 : 1 im hellen und 8,2 : 1 im dunklen Thema. Ein eigener Ton fürs Zeigen
(`--count-hover`) wird eine Spur dunkler beziehungsweise heller.
* **Die Überschrift ist 20 Pixel** statt 24. Der Selektor heißt `.count-dialog
  .count-dialog__title`, weil der gemeinsame Dialogstil weiter oben allen Dialogen 24 Pixel
  gibt.

Gemessen bei 375 × 667 Pixel: 343 × 247 Pixel groß, 16 Pixel Rand links und rechts, 210
Pixel Abstand oben und unten, nichts abgeschnitten. Bei 375 × 340 Pixel rutscht der Inhalt
in den Scroll des Panels, Titel und Aktionszeile bleiben dabei stehen.

**Alle Dialoge sind auf dem Handy ein Blatt von unten.** Am Rechner stehen sie mittig
(dort zentriert sie der Browser; gemessen bei 375 × 667 stand der Anmeldedialog 343 × 370
Pixel groß mit 16 Pixeln Seitenrand und 148 Pixeln Abstand nach oben und unten). Bis 639
Pixel kamen `.dialog` und das Löschen schon immer von unten herein — volle Breite, nur
oben abgerundet —, nur die Anzahl neuer Karten stand daneben mittig. Seit 16.10.2026 gilt
für alle drei dasselbe: `bottom: 0`, volle Breite, `border-radius: 20px 20px 0 0`,
`max-height: 85svh`, `padding-bottom: max(16px, var(--bottom-safe))` und die Einfahrt über
`transform: translateY(100%)`. Die Begründung steht weiter oben: ein unten verankertes
Blatt kann nicht abgeschnitten werden, ein zentrierter Dialog auf dem iPhone schon.

**Der Fokus in den Feldern ist die Akzentfarbe, nicht Cyan.** In beiden Themen trägt
`input:focus` den Rand und den Ring aus `--accent`; die Regel
`.count-dialog__input[type="number"]` gibt es nicht mehr, weil es das Feld nicht mehr gibt.

**Die Lernansicht** hat auf schmalen Fenstern ein ruhiges 2×2-Raster für die vier
Bewertungen (`grid-template-columns: repeat(2, minmax(0, 1fr))`, 12 Pixel Abstand, 58 Pixel
hohe Felder mit 16 Pixel Radius) und eine Karte mit gleichmäßigem Innenabstand
(24/22/30 Pixel, Frage zentriert). Am Rechner bleibt es bei vier Feldern in einer Reihe.

**Die Kennzeile der Geschichte wird kürzer.** Bis 640 Pixel bleiben „30 Wörter · 1 Beitrag"
und der Balken; die Zahl der Tage, die Legende mit den Prozenten und „seit …" fallen weg –
vier Angaben brachen sonst in drei Zeilen um. Dafür bekommen die Figuren im Skript eine
eigene Klasse (`story-line__figure--days`), damit das Blatt sie einzeln ansprechen kann.

`uptime -s`, `free -h` und `df -h` zeigen, ob stattdessen die WSL-Distro neu
gestartet ist oder der Speicher voll war – beides war hier nie der Fall.

**Kein Fehler, nur Rauschen** (erscheint bei jedem Start, nicht verfolgen):
`PendingMigrationError: navigator is now a global in nodejs`,
`Error: EEXIST ... vscode.lock`, `Refused to load resource ... seti.woff`.
