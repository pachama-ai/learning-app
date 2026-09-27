# Die Lernkartei — kurz erklärt

Diese Datei ist für Leser gedacht, die das Projekt zum ersten Mal sehen: für
Kommilitonen, für Betreuer, für jeden, der wissen will, was hier eigentlich
entstanden ist. Sie erklärt in einfachen Sätzen, was die Anwendung macht und wie
sie grob aufgebaut ist.

Technische Einzelheiten stehen absichtlich nicht hier. Wer den Code selbst
ansehen will, findet in `README.md` die Startanleitung und in den Kommentaren im
Code die Begründungen.

---

## 1. Was ist das?

Die Lernkartei ist eine Web-Anwendung zum Lernen mit Karteikarten. Man legt eine
Karte mit einer Vorderseite und einer Rückseite an und wiederholt sie so lange,
bis man sie sicher weiß. Alle Nutzer teilen sich denselben Kartenbestand, aber
jeder hat seinen eigenen Fortschritt: Was für mich „gewusst" ist, kann für dich
noch „unsicher" sein.

## 2. Was kann man damit machen?

- **Themengebiete anlegen.** Der Stoff liegt in Bereichen (zum Beispiel
  „Energie") und darunter in Unterkategorien (zum Beispiel „Einheiten"). Eine
  Kategorie kann eine Unterkategorie haben, aber keine dritte Ebene.
- **Karten anlegen und ändern.** Vorderseite und Rückseite, wahlweise in Deutsch
  und Englisch. Eine Karte kann außerdem eine Landkarte zeigen, auf der eine
  Region hervorgehoben ist.
- **Karten lernen.** Die App zeigt die Frage, man dreht die Karte um und bewertet
  die eigene Antwort mit „Nochmal", „Schwer", „Gut" oder „Einfach". Aus dieser
  Bewertung ergibt sich, wann die Karte das nächste Mal dran ist.
- **Fortschritt ansehen.** Auf jeder Seite steht, wie viele Karten neu, unsicher
  oder gewusst sind, wie viele gerade fällig wären und wie viele Tage in Folge
  schon gelernt wurde.
- **Karten importieren.** Über einen Dialog lassen sich CSV-Dateien einlesen.
  Vor dem Schreiben zeigt die App, welche Zeilen in Ordnung sind und welche
  nicht.
- **Hell und dunkel.** Die Oberfläche gibt es in einem hellen und einem dunklen
  Thema; die Wahl bleibt im Browser gespeichert.
- **Deutsch und Englisch.** Alle Beschriftungen gibt es in beiden Sprachen, die
  Wahl bleibt ebenfalls im Browser gespeichert.

## 3. Womit ist es gebaut?

Gebaut ist die Anwendung mit **PHP** auf der Serverseite und einer
**MySQL-/MariaDB-Datenbank** für die Inhalte. Auf der Client-Seite stehen nur
**HTML, CSS und einfaches JavaScript** — kein React, kein Vue, kein Bundler, kein
npm. Es gibt deshalb auch keinen Bauschritt: Die Dateien werden so ausgeliefert,
wie sie im Editor stehen. Der Browser redet nie direkt mit der Datenbank, sondern
fragt immer einen kleinen JSON-Endpunkt in PHP, der die Datenbankabfrage
übernimmt. Alle sichtbaren Texte stehen in einer Übersetzungsdatei und nicht
verstreut im Code.

## 4. Wie ist das Projekt aufgebaut?

| Ordner | Wofür |
| --- | --- |
| `public/` | Das Einzige, was der Webserver ausliefert: die HTML-Hülle, die JSON-Endpunkte und die Bilder, Schriften und Landkarten. |
| `src/` | Der Code, der nichts mit dem Browser zu tun hat: die Datenbankverbindung, kleine Helfer und die Fachlogik samt aller Datenbankabfragen. |
| `database/` | SQL-Dateien, die man von Hand in phpMyAdmin ausführt, wenn sich die Tabellenstruktur ändern soll — plus die CSV-Dateien, mit denen die Karten einmal eingelesen wurden. |
| `bin/` | Drei Skripte fürs Terminal, die CSV-Dateien in die Datenbank schreiben. |
| `docs/` | Diese Erklärung. |

## 5. Wie entscheidet die App, welche Karte fällig ist?

Jede Karte hat für jeden Nutzer einen kleinen Zustand. Eine neue Karte gilt als
**neu**. Nach der ersten Bewertung ist sie **unsicher** und bekommt einen Termin,
an dem sie wieder dran ist. Wird sie mehrfach gut beantwortet, dehnt sich dieser
Termin immer weiter aus, und die Karte gilt als **gewusst**. Wer mit „Nochmal"
antwortet, landet wieder in der Lernphase: Die Karte kommt zehn Minuten später
erneut.

Beim Lernen zeigt die App zuerst die Karten, die fällig sind, danach die noch nie
gelernten. Nur wenn beides leer ist, kommen Karten an die Reihe, die eigentlich
noch nicht fällig wären — damit eine Lernrunde nicht leer bleibt. Gerechnet wird
das auf dem Server, der Browser zeigt nur an, was zurückkommt.

## 6. Wie starte ich es?

Die Startanleitung mit den Befehlen für den Webserver und der Prüfung der
Datenbankverbindung steht in **`README.md`**. Dort findet sich auch die Liste der
Beispieldateien, die sich importieren lassen.

## 7. Woher kommen die Lerninhalte?

Zwei Quellen. Erstens die Karten, die man in der Anwendung selbst anlegt.
Zweitens die Listen, die als CSV-Datei daneben liegen: Vokabellisten nach
Niveau (B1 bis C2), englische Redewendungen, unregelmäßige Verben, Zeiten und ein
Fachvokabular zum Thema Energie. Diese Dateien liegen unter
`database/import/` und wurden einmal mit den Skripten aus `bin/` in die Datenbank
geschrieben; ab da sind sie ganz normale Karten und lassen sich wie jede andere
bearbeiten.

---

Ausführlichere technische Details standen früher in docs/technik.md, das Dokument
ist inzwischen in diese kurze Fassung eingeflossen und über die Git-Historie
weiter einsehbar.
