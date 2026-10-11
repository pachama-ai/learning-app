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
  genau zwei Konten abwechselnd an einer Geschichte schreiben – ein Beitrag, danach zwei
  Stunden Bearbeitungszeit, dann ist die andere Person an der Reihe.
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
| `categories` | 89 | 8 Lernbereiche und 81 Unterkategorien, alle mit Besitzer |
| `cards` | 7920 | die Karten; 100 mit Landkarte, 284 nur auf Deutsch |
| `card_variants` | 1500 | die Satzbeispiele einer Karte, 0 bis n je Karte |
| `user_card_progress` | 219 | eine Zeile je Nutzer und Karte – der Lernstand |
| `study_sessions` | 40 | eine Zeile je abgeschlossener Lernrunde, Grundlage der Serie |
| `card_exercises` | 39 | die Übungsaufgabe einer Karte, höchstens eine pro Karte |
| `stories` | 1 | die gemeinsame Geschichte („Story Together"), genau eine Zeile |
| `story_contributions` | 1 | ein Beitrag je Zug und Geschichte, einer der beiden Konten |

(Stand: 11.10.2026, nach dem Import der 107 Energiekarten in
„Energiegrundlagen & Energiewende" und der 69 Karten, die aus älteren Energie-Listen
noch gefehlt haben. Die beiden letzten Tabellen entstanden am 11.10.2026
mit `database/story_together.sql`; am 11.10.2026 steht dort der erste echte Beitrag.)

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
| 9.10. | Grund- und Aufbauwortschatz / Basic and Intermediate Vocabulary | 353 | neue Unterkategorie in `English` (Konto 6), beidseitig |
| 9.10. | Stromnetze / Power Grids | 130 | neue Unterkategorie in `English` (Konto 6), nur deutsche Richtung |
| 9.10. | Grund- und Aufbauwortschatz durch Wörterbuch-Karten ersetzt (statt „Was bedeutet …?“) | 353 ersetzt | `English` (Konto 6) |
| 11.10. | Energy basics & energy transition (Grundlagen und Energiewende) | 107 | `Energiegrundlagen & Energiewende` (Energie, id 3), einseitig |
| 11.10. | Ältere Energie-Listen erneut durchgesehen: nur die Karten, die wirklich fehlten | 69 | 26 in `Energiegrundlagen & Energiewende` und 43 in `Stromnetz & Systembetrieb` (Energie, id 3), einseitig |
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
Verfasser **zwei Stunden** Zeit, ihn noch zu ändern; danach ist er endgültig und der Zug
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
2. Ein Beitrag je Zug. Nach dem Veröffentlichen bleiben **`STORY_EDIT_HOURS` (zwei)
   Stunden Bearbeitungszeit**: solange darf nur der Verfasser selbst noch ändern, die
   andere Person wartet.
3. **Danach wechselt der Zug** zur anderen Person – gerechnet wird ab dem Zeitpunkt der
   Veröffentlichung, nicht mit dem Kalendertag. Wer um 23 Uhr schreibt, gibt um 1 Uhr
   nachts ab.
4. Den ersten Beitrag darf schreiben, wer zuerst kommt – er ist damit der Erst-Autor, und
   die andere Person ist danach an der Reihe.
5. Wer nicht schreibt, **verliert seinen Zug nicht**: er bleibt stehen, bis jemand
   schreibt. Schreibt dieselbe Person deshalb mehrere Beiträge hintereinander, ist das in
   Ordnung – eine Prüfung „nicht zweimal derselbe Autor in Folge" gibt es nicht.
6. Geändert werden darf nur der eigene, **zuletzt veröffentlichte** Beitrag, und nur
   innerhalb der zwei Stunden. Danach ist er endgültig. Gelöscht wird nie.

**Wer dran ist, rechnet `story_state()` aus dem letzten Beitrag:**

```
letzter Beitrag + 2 Stunden   -> noch der Verfasser (er darf nur noch ändern)
danach                        -> die andere Person ist an der Reihe
```

In den zwei Stunden steht also niemandem das Schreiben zu: der Editor der wartenden
Person ist gesperrt, und der Verfasser sieht an seiner Stelle den Satz „Du kannst deinen
Beitrag noch bis … ändern" (Übersetzungsschlüssel `story.turn.editWindow`) – ohne ihn wäre
nicht zu sehen, warum der eigene Editor noch zu ist. Die Frist hängt am Zeitpunkt der
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

**Diese Seite steht still, ihre Inhaltsspalte scrollt.** Auf allen anderen Seiten scrollt
die Seite als Ganzes – dort wandert die Seitenleiste beim Scrollen mit und verschwindet
oben aus dem Bild (nachgemessen: auf `index.php?category=2` steht sie nach dem Scrollen
bei `top: -704px`). Hier standen vier Blöcke übereinander, die Seite wurde damit länger als
jedes Fenster, und auf einem flachen Fenster brach die Leiste an der Unterkante ab. Deshalb
bekommt das Gerüst über `.page--story` die Höhe des Fensters (`100dvh`), und nur `.detail`
scrollt: die Leiste ist ihre **Schwester im Raster** und liegt außerhalb dieses
Scrollbereichs, sie kann also gar nicht mitwandern – links bleibt sie stehen, auf schmalen
Fenstern bleibt die Reihe darüber stehen. Der innere Scroll der Geschichte
(`.story-scroll`) bleibt davon unberührt; die beiden Scrollbereiche wissen nichts
voneinander.

Damit die Spalte überhaupt scrollen kann, braucht die Kette vom Fensterrand bis zu ihr
`min-height: 0` – ein Flex-Kind darf sonst nicht kleiner werden als sein Inhalt, und dann
gäbe es keine Kante, an der gescrollt wird. Der Preis: das Scrollband sitzt am rechten Rand
der Inhaltsspalte statt am Fensterrand.

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

**Die Geschichte scrollt in sich selbst.** Der Lesebereich hat eine feste Höhe
(`clamp(320px, 56vh, 640px)`) und ein eigenes `overflow-y`. Damit wird die Seite nicht
endlos lang: oben steht immer der ganze Text, darunter immer der Editor, egal wie viel
schon geschrieben wurde. Beim Öffnen steht der Bereich bei seinem **neuesten Beitrag** –
geschrieben wird ja auch von unten. Eine Vorschau mit „Weiterlesen" gab es hier einmal;
sie ist weg, seit der Bereich selbst scrollt.

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
innerhalb der zwei Stunden – danach ist er endgültig.

**Gespeichert wird ohne Zwischenschritt.** Ein Klick auf „Beitrag veröffentlichen" oder
„Änderung speichern" schickt den Text direkt an die API. Den Vorschau-Dialog („Ein letzter
Blick") gab es einmal; er war eine zweite Seite vor der eigentlichen Handlung und ist
entfernt. Auch `Strg`/`Cmd`+`Enter` speichert direkt. Serverseitig bleibt alles, wie es war:
Zug, Bearbeitungszeit, Länge, CSRF-Token und die Erlaubnis, genau diesen Beitrag zu
ändern, prüft `api/story.php` in jedem Fall.

### Einen Beitrag nachträglich ändern

Der eigene, **zuletzt veröffentlichte** Beitrag lässt sich ändern, solange die zwei
Stunden Bearbeitungszeit laufen. Danach ist er gesperrt: die andere Person ist an der
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

Zwei Stunden nach dem Veröffentlichen wechselt der Zug – und die Person, die jetzt dran ist,
erfährt es per E-Mail. Wann sie rausgeht, ist damit keine eigene Entscheidung: sie geht
genau dann raus, wenn die Bearbeitungszeit abgelaufen ist. Eine Mail direkt nach dem
Schreiben wäre falsch, denn zwei Stunden lang darf der Text noch geändert werden.

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
(`story.mail.subject`, `story.mail.body`); verschickt wird die deutsche Fassung, weil die
beiden Schreiberinnen deutsch schreiben (`STORY_MAIL_LANGUAGE`).

**Ausgelöst wird das per Cron**, jede Minute:

```
* * * * * cd /pfad/zum/projekt && /usr/bin/php bin/story_notify.php --execute >/dev/null 2>&1
```

`bin/story_notify.php` ist ein Kommandozeilen-Werkzeug wie die Importe: `--dry-run` zeigt
nur, was ansteht, `--execute` verschickt und setzt den Merker. Es gibt 0 zurück, wenn alles
in Ordnung ist (auch wenn nichts zu tun war), und 1 bei einem echten Problem – dann meldet
sich Cron von selbst. Läuft der Cron nicht, kommt keine Mail; die Seite selbst funktioniert
weiter. Der Merker braucht die Spalte `turn_notified_at`, die mit
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

`uptime -s`, `free -h` und `df -h` zeigen, ob stattdessen die WSL-Distro neu
gestartet ist oder der Speicher voll war – beides war hier nie der Fall.

**Kein Fehler, nur Rauschen** (erscheint bei jedem Start, nicht verfolgen):
`PendingMigrationError: navigator is now a global in nodejs`,
`Error: EEXIST ... vscode.lock`, `Refused to load resource ... seti.woff`.
