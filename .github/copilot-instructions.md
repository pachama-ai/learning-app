# Copilot Instructions — Learning App

These instructions apply to **all** future Copilot work in this repository.
Follow them strictly. If a request conflicts with any rule below, ask the user
before proceeding instead of guessing.

---

## 1. Project Overview

**Learning App** is a flashcard web application.

- Users share the **same** pool of cards.
- Every user has their **own separate progress** for every card.
- Categories can be nested into subcategories using `parent_id`.
- Cards belong to categories through `category_id`.
- The interface supports **light and dark mode** and **German and English** text.

Stack: PHP + Apache + MySQL/MariaDB + HTML, CSS and vanilla JavaScript.
There is no build step, no package manager and no frontend framework.

---

## 2. Database Rules (highest priority)

- Database name: **`learning_app`**
- Existing tables: **`users`**, **`categories`**, **`cards`**, **`user_card_progress`**
- The database was created manually in phpMyAdmin and is the source of truth.

Hard rules:

- **Never change the database structure without asking first.** No `ALTER`, `DROP`,
  `TRUNCATE` or `RENAME`; no added, removed or renamed tables, columns, indexes or
  constraints — unless the user explicitly approved that exact change.
- **Never delete existing data.** No `DELETE` or data-overwriting `UPDATE` unless the
  user explicitly asked for that exact operation.
- **Never create fake, sample, demo or seed data** unless explicitly requested.
- **Never assume column names or types.** Before writing a query against a table you
  have not verified in this session, inspect the real schema with
  `DESCRIBE <table>` / `SHOW CREATE TABLE <table>` and use the actual column names.
- If a column or table you need does not exist, **report it and ask** — do not add it.
- Schema changes are proposed as a reviewable SQL file in `database/` for the user to run
  manually. Never execute schema changes automatically.

---

## 3. Technology Rules

### Backend

- Use **PHP** for all server-side logic.
- Use **PDO** for the database connection. Configure it with:
  - `PDO::ATTR_ERRMODE` = `PDO::ERRMODE_EXCEPTION`
  - `PDO::ATTR_DEFAULT_FETCH_MODE` = `PDO::FETCH_ASSOC`
  - `PDO::ATTR_EMULATE_PREPARES` = `false`
- Use **prepared statements with bound parameters for all user input**, without
  exception. Never build SQL by concatenating or interpolating user-supplied values.
- Never place database credentials in anything that is reachable from the browser.
  Credentials live in `src/config/` only, which is outside the web root.

### Frontend

- Use **HTML, CSS and vanilla JavaScript** only.
- **Do not use React, Vue, Angular, jQuery, Node.js, bundlers or any other frontend
  framework.**
- Use **JSON** for all communication between JavaScript and PHP.
- The browser must **never** connect directly to MySQL. All data access goes through PHP.
- Never expose database credentials, connection strings or internal file paths in
  frontend JavaScript.
- No build tooling: files are served directly by Apache as written.

### Naming

- Use **English names** for all files, folders, tables, columns, functions, classes and
  variables.
- Only the text the user sees gets translated into German or English.

---

## 4. Architecture and Folder Rules

| Path | Rule |
| --- | --- |
| `public/` | The **only** web-accessible directory (Apache DocumentRoot). No secrets here. |
| `public/index.php` | Entry point / front controller. |
| `public/api/` | One file per JSON endpoint. Kept thin: validate → call service → return JSON. |
| `public/assets/css/` | Stylesheets. |
| `public/assets/js/` | Client-side JavaScript. |
| `public/assets/icons/` | SVG icons used by the interface. |
| `src/config/` | Credentials and settings. Must never be publicly reachable. |
| `src/helpers/` | Small, reusable, stateless functions. |
| `src/services/` | Business logic and **all** PDO queries. |
| `database/` | SQL scripts for the user to run manually. |

- Keep SQL and business logic out of `public/`. Endpoints in `public/api/` delegate to
  services in `src/services/`.
- Reuse existing helpers and services instead of duplicating logic.

---

## 5. Application Requirements

- Users share one card pool; progress is per user and stored in `user_card_progress`.
- Categories nest through `parent_id` (a `NULL` `parent_id` means a top-level category).
- Cards link to a category through `category_id`.
- New cards are **normally created inside subcategories**, not in top-level categories.
- Support **light mode and dark mode**.
- Support **German and English** interface text.
- Persist the selected **language and theme in the browser** (`localStorage`), and apply
  them on load before first paint so the wrong theme never flashes.
- All interface text goes through the translation lookup. Never hard-code a German or an
  English string directly in a template or in JavaScript.
- Use the **SVG icons stored in `public/assets/icons`** — not emoji, not an icon font.
- Keep the design **visually similar to the provided reference screenshots**: same layout,
  spacing and visual style. If a screenshot for a screen is missing, ask for it rather
  than inventing a design.
- Build **reusable PHP functions and reusable JavaScript functions**.
- **Validate all form input** server-side, and mirror the checks client-side for quick
  feedback. Treat the server-side check as the only authoritative one.
- API endpoints return **clear, consistent JSON**:
  - success: `{"success": true, "data": ...}`
  - failure: `{"success": false, "error": {"code": "...", "message": "..."}}`
- Use correct HTTP status codes (`200`, `201`, `400`, `404`, `405`, `500`).
- API responses set `Content-Type: application/json` and use `JSON_UNESCAPED_UNICODE`.
- Errors must **never** expose passwords, credentials, connection strings, raw SQL or
  stack traces. Log details server-side; return a generic message to the client.
- Escape all HTML output (`htmlspecialchars` with `ENT_QUOTES` and `UTF-8`) to prevent XSS.

---

## 6. Development Rules

- Work on **one feature at a time**. Do not mix unrelated changes into one step.
- **Before changing several files, explain which files will change and why**, then wait
  for confirmation.
- **Do not silently overwrite or delete files.** Show the intended change first and ask
  before replacing existing content.
- Keep the code **understandable for a beginner**: clear names, short functions, and
  comments where a decision is not obvious. No clever tricks, no premature abstraction.
- **Explain important code decisions** — for example why certain PDO options are set, why
  a validation lives where it does, or why a query is shaped that way.
- **After every feature, provide a manual test procedure**: exact steps, what to click,
  what to enter, and the expected result for each step, including the failure cases.
- **Do not implement the spaced-repetition algorithm** until basic card creation and
  card review both work end to end.

---

## 7. Workflow Checklist

**Before coding**

1. Restate the feature in one sentence.
2. List the files that will be created or modified.
3. Confirm the relevant database columns from the real schema.
4. Ask before anything that touches the schema or existing data.

**After coding**

1. Summarise what changed and why.
2. Provide the manual test procedure.
3. State anything that is still unverified, plus any follow-up the user must do manually.

---

## 8. Copilot Must Never

- Change the database structure without approval.
- Delete existing data.
- Create fake or sample data unasked.
- Use a frontend framework, Node.js or a bundler.
- Connect to MySQL from JavaScript or expose credentials to the browser.
- Concatenate user input into SQL.
- Overwrite or delete a file without showing the change first.
- Implement spaced repetition before card creation and review are working.
