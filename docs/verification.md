# Verification procedure

One pass, start to finish, that checks the whole application: the database
connection, the API, the fact that every piece of application data really comes
from the database, and the start page.

Everything here is read-only except steps **D5** and **D6**, which create one
entry and delete it again.

---

## 0. Start the application

```bash
cd /home/user/projects/learning-app
php -S 127.0.0.1:8081 -t public
```

Then open <http://127.0.0.1:8081/>.

> The Apache server that also runs in this distro serves `/var/www/html` and has
> nothing to do with this project. Use the command above.

---

## A. The API answers

```bash
curl -s http://127.0.0.1:8081/api/health.php
```
Expected: `"database":"learning_app"` and `"message":"Database connection works"`.
This proves the PDO connection **and** that the right database is selected.

```bash
curl -s http://127.0.0.1:8081/api/stats.php
```
Expected: `learning_areas: 6`, `subcategories: 8`, `total_cards: null`
(`null` because the `cards` table is empty - a value that cannot be known is
never invented).

```bash
curl -s "http://127.0.0.1:8081/api/categories.php"
```
Expected: six entries (ids 2, 3, 4, 5, 15, 16). Every entry carries
`id`, `parent_id`, `name`, `color`, `icon_url`, `icon_scale`, `name_en`,
`name_de`, `description_en`, `description_de`, `subcategory_count`,
`own_card_count`, `card_count`.
The four learning areas have a colour and an `icon_url`; `h` and `f` have
`"color": null` and `"icon_url": null`.

```bash
curl -s "http://127.0.0.1:8081/api/categories.php?id=2"
```
Expected: the same fields **plus** `icon_svg` (about 12,900 characters of stored
SVG) and `delete_preview` (`{"categories":8,"cards":0}`).

```bash
curl -s "http://127.0.0.1:8081/api/categories.php?parent_id=2"
```
Expected: the eight subcategories of Mathematics, each with `parent_id: 2`.

```bash
curl -s -o /dev/null -w "%{http_code} %{content_type}\n" "http://127.0.0.1:8081/api/category_icon.php?id=2"
```
Expected: `200 image/svg+xml; charset=utf-8`, and the body is the drawing from
`categories.icon_svg`.

### Errors answer as JSON and leak nothing

```bash
curl -s -w "\n%{http_code}\n" "http://127.0.0.1:8081/api/categories.php?id=abc"
curl -s -w "\n%{http_code}\n" "http://127.0.0.1:8081/api/categories.php?id=999999"
curl -s -w "\n%{http_code}\n" -X PUT "http://127.0.0.1:8081/api/cards.php?category_id=2"
```
Expected: `400 invalid_id`, `404 category_not_found`, `405 method_not_allowed`.
None of the three answers may contain SQL, a file path, a class name, a stack
trace or credentials.

---

## B. The database itself

In phpMyAdmin (database `learning_app`, tab **SQL**):

```sql
DESCRIBE categories;
DESCRIBE cards;
DESCRIBE user_card_progress;

SELECT id, parent_id, name, name_en, name_de, color,
       icon_scale, CHAR_LENGTH(COALESCE(icon_svg,'')) AS icon_bytes
  FROM categories ORDER BY parent_id IS NOT NULL, id;

SELECT COUNT(*) FROM cards;
```

Expected:
* `categories` has the migration columns `color`, `icon_svg`, `icon_scale`,
  `name_en`, `name_de`, `description_en`, `description_de`
* ids 2, 3, 4, 5 have a colour, an `icon_bytes` value above 10,000 and an
  `icon_scale` (1.00 / 1.24 / 1.15 / 1.00); ids 15 and 16 (`h`, `f`) have neither
* the eight subcategories (ids 6-13) have `parent_id = 2` and no colour of their
  own - they are shown in the colour of their learning area
* `SELECT COUNT(*) FROM cards` is 0

There is **no migration to run**: the columns already exist, nothing was added
or dropped in this pass.

---

## C. Nothing is hard-coded any more

```bash
cd /home/user/projects/learning-app

# no category or subcategory names in the code
grep -rn "area.mathematics\|area.energy\|area.geography\|area.english\|subject.numberSystems" src public || echo "clean"

# no icon file name of a category in the frontend
grep -rn "math_icon\|energy_icon\|geography_icon\|language_icon" public/index.php public/assets/js/app.js || echo "clean"

# no colour table by category name in the stylesheet
grep -rn "cat-mathematics\|cat-energy\|cat-geography\|cat-english" public/assets/css/app.css || echo "clean"

# no inventing of colours on the server
grep -rn "CATEGORY_FALLBACK\|fallback_category_color" src || echo "clean"

# the browser stores preferences only, never content
grep -n "localStorage" public/assets/js/app.js
```
Expected: the first four lines print `clean`; the last one shows only the two
lines of the small `readStorage` / `writeStorage` helper, which is used with
`lernkartei.theme` and `lernkartei.language`.

---

## D. The page in the browser

Open <http://127.0.0.1:8081/> and walk through this list. Every point is a
statement about the database, not about the markup.

| # | Check | Expected |
| --- | --- | --- |
| 1 | Start page, learning areas | Six tiles, in the order of `categories.id` |
| 2 | DevTools console | No error, and in particular no 404 |
| 3 | Tile information line | `8 subcategories` for Mathematics, `No subcategories yet` for the others - counted by SQL |
| 4 | Numbers on a tile | No `[01]` badge any more |
| 5 | Scroll counter | No `01-04 / 06` above the line |
| 6 | Footer | No `6 LEARNING AREAS`, no line of its own: the scroll line above it is the hairline |
| 7 | Footer left | Two round arrow buttons; the left one is greyed out at the start |
| 8 | Footer middle / right | The plus button in the middle, `EDIT` on the right (start page: the exit is hidden, nothing is open) |
| 9 | Scroll line | Full content width, 1px, exactly 24px below the tiles; the thumb is 2px and as wide as the visible share (4 of 6 tiles ≈ 667px at 1440×900) |
| 10 | Hover / drag the thumb | It grows to 3px; a click on the track jumps; dragging moves the row tile by tile |
| 11 | H1 | "Choose a / learning area" (EN) and "Wähle ein / Themengebiet" (DE), two lines, light, tight |
| 12 | H1 font | DevTools: `font-family: "Inter Tight", ...`, weight 300 |
| 13 | Descenders | The `g` of "learning area" and of "Themengebiet" is complete - the clipped mask cuts nothing |
| 14 | Window sizes | 1920×1080, 1440×900 and 1024×768: no page scrollbar (`document.documentElement.scrollHeight === innerHeight`) |
| 15 | Empty state | Temporarily set one tile row to 8 per view (`--tiles-per-view: 8` plus `white-space: normal` on `.area-card__stat` in the DevTools): the line stays visible, is greyed to 30%, the thumb covers the whole track and both arrows are disabled |
| 16 | Language switch | `DE` / `EN` changes every label, the tile names and the empty state; the choice survives a reload |
| 17 | Theme switch | Moon/sun changes light and dark; the scroll line and the thumb follow |
| 18 | Category colour | Click a learning area: the dot beside the statistic has the colour stored in `categories.color`; a colour change in the database (see D5) is visible after a reload |
| 19 | Icons | Every tile shows the drawing from `categories.icon_svg`; `h` and `f` show the neutral ring |
| 20 | Favicon | The browser tab shows `assets/icons/browser_icon.svg` |

### D5. Change a name and a colour in the database and watch the page follow

In phpMyAdmin:

```sql
UPDATE categories SET name_de = 'Mathematik (Test)' WHERE id = 2;
UPDATE categories SET color = '#C9A96B'          WHERE id = 2;
SELECT name_de, color FROM categories WHERE id = 2;
```

Reload the start page: the first tile is now called "Mathematik (Test)" in
German and carries the new colour in the dot of its detail page. Nothing in the
code was touched to make that happen.

Undo:

```sql
UPDATE categories SET name_de = 'Mathematik' WHERE id = 2;
UPDATE categories SET color = '#C3C8DB'      WHERE id = 2;
```

### D6. Create, edit and delete through the interface

1. Footer **plus** on the start page -> `Add learning area`. Fill in a name, an
   English and a German name, one description, pick a colour and choose an SVG
   file (max 350 KB) -> **Save**.
   The tile appears with the uploaded drawing. **Reload the page**: it is still
   there (it was written to the database, not into the page).
2. Open it, footer **plus** -> `Add subcategory`, save. Reload: it stays.
3. Open the subcategory, footer **plus** -> `Add flashcard`, save. Reload: it
   stays, front and back come from the `cards` table, the subcategory row shows
   `01 CARD`.
4. Open the subcategory again and press `Delete`: the dialog asks once and
   deletes the card. Reload: the row is gone.
5. Open the learning area and press `Delete`: the dialog names the number of
   subcategories and cards that disappear **and** demands the exact name. Type a
   wrong name -> `The name does not match this entry.`, nothing is deleted. Type
   the right name -> the whole subtree is gone. Reload: still gone.
6. Try to upload a file that is not an SVG, or one above 350 KB: it is refused
   with a message and nothing is written.

---

## E. Data flow, end to end

```
Browser  --fetch-->  public/api/*.php  --PDO-->  MySQL/MariaDB  --JSON-->  Browser
```

* The browser calls nothing but `api/*.php`. There is no SQL and no database
  driver in JavaScript.
* Every statement in `src/services/` uses a prepared statement with bound
  parameters; never a value inside the SQL text.
* Every write answers with the row as it was really stored, and the frontend
  reloads the list from the API afterwards (`responseCache = {}` + `render()`).
* `user_card_progress` and the spaced-repetition columns were not touched.

---

## F. Deleting without typing a name (2026-09-21)

The rule changed in this pass: nothing has to be typed any more, and a deletion
that has nothing below it can even be taken back. Section D6 step 5 describes the
old behaviour - this is the current one.

### F1. An empty entry disappears at once, with a way back

1. Start page, three dots on the corner of a tile of an **empty** learning area
   (one that says "No subcategories yet") -> **Delete**.
2. Expected: no question appears. The tile disappears immediately and a short
   message appears at the bottom: *"<name> was deleted."* with the button
   **Undo**. Nothing has been sent to the server yet.
3. Press **Undo** within six seconds: the tile is back and the message says
   *"Kept as it was."* Nothing was ever deleted.
4. Repeat and simply wait: after about six seconds the message goes away and the
   list is reloaded. The tile stays gone, and a reload confirms it.

### F2. Leaving the page finishes the deletion

1. Delete an empty entry and **immediately** click another tile (or close the
   tab).
2. Expected: the deletion still happens - the browser sends the waiting request
   with `keepalive` while the page goes away. There is no undo in this case.

### F3. An entry with content asks once

1. Three dots on the Mathematics tile -> **Delete**.
2. Expected: the shared dialog opens with the name in the title and one sentence
   that names the numbers, for example
   *"This also deletes 8 subcategories - permanently."*
   There is **no input field** and no checkbox; the focus starts on **Cancel**.
3. **Cancel** (or Escape, or a click next to the panel): nothing is deleted.
4. Open it again and press the red **Delete**: the whole subtree disappears in
   one transaction and the message *"<name> was deleted."* appears.
5. The same dialog appears for a subcategory that still holds flashcards.

### F4. Where else the delete is offered

* Every row of a list (subcategory or flashcard) has its own three dots menu
  with **Edit** and **Delete**.
* The head of a detail view has the same menu next to the name.
* Deleting the entry whose own page is open deletes at once and then goes one
  level up.

### F5. The contract behind it

```bash
curl -s -X DELETE "http://127.0.0.1:8081/api/category.php?id=2"
```
Expected: HTTP 400 with `"code":"confirm_required"` - nothing is deleted.

```bash
curl -s -X DELETE "http://127.0.0.1:8081/api/category.php?id=999999"
```
Expected: HTTP 404 with `"code":"category_not_found"`.

```bash
curl -s -X DELETE "http://127.0.0.1:8081/api/category.php?id=2" -H 'Content-Type: application/json' -d '{"confirm":true}'
```
Expected: the subtree really disappears (`deleted_categories`, `deleted_cards`,
`deleted_progress`). **Only run this on a throwaway entry.**

---

## G. The detail view (2026-09-21)

1. Open a learning area. Expected: a head zone in the colour of that area (light
   theme), the same circle a tile shows, the description in the current
   language, one line of figures ("8 subcategories · 0 cards"), one three-dot
   menu next to the name and, at the end of the list, a plain
   **+ Add subcategory** row.
2. Open a subcategory that has no cards. Expected: the same head with the
   colour and the drawing of the *parent* area, one line "0 cards", and an empty
   state with a sentence and one button instead of a dashed box.
3. Switch the language: the heading, the description and the figures follow; the
   sidebar loses its counters and shows a coloured dot per area.
4. Switch the theme: the head zone is neutral in the dark theme, the light tint
   comes back in the light theme.
5. There is no edit mode any more: the menu in the corner of a tile is quiet
   until the tile is hovered, focused or a menu is open (on a touch device it is
   always visible).

---

## H. Drawings (icons) - 350 KB, and never inside an answer (2026-09-21)

### H1. The limit

1. Open the menu of a learning area -> **Edit**.
2. Expected: under the symbol row the hint *"Optional. One .svg file, at most 350 KB."*
   (German: *"Optional. Eine .svg-Datei, höchstens 350 KB."*).
3. Choose an SVG file of about 349 KB. Expected: the file name appears, the circle
   shows the drawing, no error.
4. Choose a file above 350 KB. Expected: *"The file is larger than 350 KB."* and
   nothing is staged (`input.icon-row__file` is not part of the form value).
5. Press Escape - the form was only a test, nothing is saved by it.

### H2. The same limit on the server

```bash
curl -s -X PATCH "http://127.0.0.1:8081/api/category.php?id=<id>" \
     -H 'Content-Type: application/json' \
     -d '{"icon_svg":"<a drawing of 358401 bytes>"}'
```
Expected: HTTP 400 with `"code":"icon_too_large"` and the message
`The icon is larger than 350 KB.` - and the row keeps its old drawing.

### H3. A drawing is never part of an answer

```bash
curl -s "http://127.0.0.1:8081/api/categories.php" | wc -c
curl -s "http://127.0.0.1:8081/api/categories.php?id=2" | wc -c
```
Expected: the list is well under 2 KB and the single category under 500 bytes -
neither contains `<svg`. Both carry `icon_url` only, which is the address of the
drawing plus a fingerprint of its content.

### H4. The drawing endpoint caches and revalidates

```bash
curl -sI "http://127.0.0.1:8081/api/category_icon.php?id=2"
```
Expected: `Content-Type: image/svg+xml`, `ETag: "<fingerprint>"`,
`Cache-Control: public, max-age=604800, immutable`.

```bash
curl -s -o /dev/null -w '%{http_code}' -H 'If-None-Match: "<the same ETag>"' \
     "http://127.0.0.1:8081/api/category_icon.php?id=2"
```
Expected: `304` - the browser keeps the drawing it already has.

The address carries `v=<first 8 characters of MD5(icon_svg)>`, so replacing a
drawing produces a new address and an old drawing can never be shown again.

### H5. Where the drawing appears

Check each of them in the light **and** the dark theme:

* the tiles on the start page (four drawings, all loaded)
* the head card of a detail view
* the preview circle inside the edit dialog
* a category without a drawing shows the first letter of its name instead

The colour comes from `--icon-filter` alone (`brightness(0) invert(0.17)` in the
light theme, `brightness(0) invert(1)` in the dark one), so nothing about the
drawings changed with the larger limit.