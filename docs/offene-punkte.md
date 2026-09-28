# Offene Punkte

Was hier steht, ist geprüft — jede Zeile wurde am angegebenen Tag im Code
nachgesehen, nicht geschätzt. Wo eine Zahl fehlt, steht, warum sie fehlt.

Stand: 2026-09-28

---

## 1. Import: Fehlercodes ohne Satz — erledigt

`public/assets/js/app.js` baut die Meldung aus dem Fehlercode, und zwar auf zwei
Wegen:

```js
t('import.row.' + entry.code)     // ein Fehler in einer Zeile der Datei
'import.error.' + code            // ein Fehler der Datei oder der Anfrage
```

Für die Zeilenfehler gibt es zwölf Schlüssel `import.row.*`, und alle zwölf sind
da (`fields_count`, `encoding`, `too_long`, `control_characters`, `flag_value`,
`language_half`, `language_unavailable`, `no_language`, `exercise_unknown_type`,
`exercise_invalid`, `exercise_no_title`, `exercise_unavailable`).

Für die Anfrage- und Dateifehler fehlten dagegen zwei Sätze:

* `invalid_category_id` — eine Anfrage ohne brauchbare Unterkategorie
* `method_not_allowed` — eine Anfrage, die nicht POST ist

Beide sind am 2026-09-28 auf Deutsch und Englisch ergänzt worden. Eine Prüfung,
die für jeden möglichen Code beide Sprachen auflöst, zählt **25 Codes und 0
fehlende Sätze**. Getestet wurde mit kaputten CSV-Dateien; die Codes
`header_unknown`, `header_missing`, `too_many_rows`, `invalid_category_id` und
`method_not_allowed` kommen aus der Datei bzw. der Anfrage, die übrigen aus einer
Zeile.

**Eine Anmerkung, damit der Fehler nicht zurückkommt:** Hier stand zuerst
„zwölf fehlende Codes". Das war falsch, weil die Zeilenfehler mit den
Anfragefehlern verglichen wurden. Wer hier etwas ändert, muss auf beide Präfixe
schauen.

---

## 2. Spalten, die niemand liest

Nur melden, nichts ändern: die Spalten gehören zur bestehenden Struktur und
werden nicht ohne Rückfrage angefasst.

| Spalte | Befund |
| --- | --- |
| `categories.color` | wird nirgends gelesen. Der Code sagt es selbst: `src/services/category_service.php` (zweimal) und `public/assets/js/app.js` |
| `categories.description_en` | kein Treffer in `src/`, `public/` oder `bin/` |
| `categories.description_de` | kein Treffer in `src/`, `public/` oder `bin/` |
| `users.role` | wird beim Anlegen geschrieben (`user_service.php`), aber nirgends gelesen |
| `card_exercises.range_min` | kein Treffer in `src/`, `public/` oder `bin/` |
| `card_exercises.range_max` | kein Treffer in `src/`, `public/` oder `bin/` |

---

## 3. Tote Stellen im Stylesheet

| Stelle | Befund |
| --- | --- |
| `.grain` | 6 Regeln in `public/assets/css/app.css`, 0 Verwendungen in `app.js` oder `index.php` |
| `.is-linked` / `.is-linking` | je 1 Verwendung in `app.js`, aber **keine** CSS-Regel mehr — andersherum tot als erwartet |

---

## 4. Unbenutzte Übersetzungsschlüssel — Zahl noch nicht belegt

Eine wörtliche Suche im ganzen Projekt findet **93** Schlüssel, die nirgends
wörtlich vorkommen. Diese Zahl ist aber **keine** Zahl unbenutzter Schlüssel:
Die Anwendung setzt viele Schlüssel erst zur Laufzeit zusammen, zum Beispiel

```js
t('cards.status.' + name + 'Hint')     // app.js
'exercise.param.' + name               // app.js
'exercise.option.' + option            // app.js
```

Diese vier Bauweisen erklären den größten Teil der 93 (`cards.status.*` mit 7,
`exercise.param.*` und `exercise.option.*` mit über 50 Schlüsseln). Eine
wörtliche Suche ist hier deshalb **kein** gültiges Verfahren, und eine
belastbare Liste gibt es noch nicht. Wer sie will, muss die dynamisch gebauten
Schlüssel mitdenken.

---

## 5. Was früher hier stand und inzwischen erledigt ist

Diese Punkte waren einmal offen und sind es nicht mehr. Sie stehen hier, damit
niemand sie erneut sucht:

* **Externe Schriften.** `app.css` lädt keine Schriften mehr von Google. `Inter`
  und `Source Serif 4` liegen als Datei in `public/assets/fonts/`; im Kommentar
  über den `@font-face`-Regeln steht, dass keine Anfrage mehr an einen fremden
  Dienst geht. `Inter Tight` bleibt als Rückfall im Stapel.
* **Tote Regeln.** `.data-pill`, `.row__arrow`, `.row__progress`, `.row__count`,
  `.row__play` und `.card-tools__label` haben in `app.css` **0** Treffer, die
  Regeln sind also entfernt.
* **Unbenutzte CSS-Variablen.** `--cat-default`, `--accent-soft`, `--warn-soft`,
  `--background` und `--surface-soft-strong`: je 0 Definitionen und 0
  Verwendungen.
* **`public/assets/icons/informatik.svg`** ist weg; im Ordner liegt nur noch
  `browser_icon.svg`.
* **`SVG_BLOCKED_ELEMENTS`** kommt in `src/` nicht mehr vor.
* **`editingParentId`** kommt in `app.js` nicht mehr vor.
* **`data-i18n-empty`** kommt weder in `index.php` noch in `app.js` vor.
* Der frühere Verweis auf `docs/technik.md` ist entfernt — diese Datei gibt es
  nicht mehr.
