# Offene Punkte

Was hier steht, ist geprüft — jede Zeile wurde am angegebenen Tag im Code
nachgesehen, nicht geschätzt. Wo eine Zahl fehlt, steht, warum sie fehlt.

Stand: 2026-09-28

---

## 1. Import: zwölf Fehlercodes ohne Satz

`public/assets/js/app.js` baut die Meldung aus dem Fehlercode:

```js
var key = 'import.error.' + String(code || '');
```

In `src/helpers/translations.php` gibt es 13 Schlüssel `import.error.*`. Der
Import-Endpunkt und die Zeilenprüfung können aber mehr Codes senden, und für
diese zwölf fehlt ein Schlüssel:

`invalid_category_id`, `method_not_allowed`, `control_characters`, `encoding`,
`exercise_no_title`, `exercise_unavailable`, `fields_count`, `flag_value`,
`language_half`, `language_unavailable`, `no_language`, `too_long`

`importMessage()` fängt das ab (`sentence === key ? errorMessage(code) : sentence`),
der Nutzer sieht also *eine* Meldung — nur nicht den genauen Satz. Für
`exercise_unavailable` wäre das schade, denn dieser Satz erklärt, dass eine
Migration fehlt.

**Status: in Arbeit.** Die fehlenden Schlüssel werden ergänzt.

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
