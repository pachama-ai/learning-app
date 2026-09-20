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
   file (max 300 KB) -> **Save**.
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
6. Try to upload a file that is not an SVG, or one above 300 KB: it is refused
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
