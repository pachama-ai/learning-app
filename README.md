# Learning App (Lernkartei)

A flashcard web application. All users share **one** pool of cards; every user has
their **own** progress per card.

**Stack:** PHP 8.5 with PDO, MySQL/MariaDB, HTML, CSS and vanilla JavaScript.
No framework, no Node.js, no bundler, no build step: the files are served exactly
as they are.

## Start it

```bash
./start-dev.sh            # http://127.0.0.1:8081/
PORT=8082 ./start-dev.sh  # another port, in case 8081 is taken
```

The script starts PHP's built-in server with **four workers**. A category page
asks for four things at once (the area list, the category, its subcategories and
its cards); with a single worker those four requests would be answered one after
the other and the page would wait for their sum.

In production the host is Apache with `public/` as the DocumentRoot, and a
MySQL/MariaDB database named `learning_app` is required.

## Configuration

`src/config/database.local.php` holds the credentials (host, port, database,
username, password, charset). It is **not** in git and it is not web-accessible:
copy `src/config/database.example.php` and fill it in. Everything under `src/`
sits outside the web root on purpose.

- PDO runs with `ERRMODE_EXCEPTION`, `DEFAULT_FETCH_MODE = FETCH_ASSOC` and
  `EMULATE_PREPARES = false`. The last one means the server prepares the
  statement, so **each value needs its own placeholder**.

## Layout

```text
public/       the only web-accessible directory (Apache DocumentRoot)
  index.php     front controller: prints the HTML shell and the translations
  api/          one file per JSON endpoint, thin: validate -> service -> JSON
  assets/       css, js, fonts, icons, maps, samples
src/          application code, not reachable from the browser
  config/       connection settings and credentials
  helpers/      small, stateless functions
  services/     business logic and ALL PDO queries
database/     SQL the user runs by hand + the command line importer
docs/         project-brief.md, verification.md, migrations.md
```

## Data

- `categories` holds 4 learning areas (Mathematics, Energy, Geography, English),
  17 subcategories and, for the four areas, a drawing in `icon_svg`.
  **The drawings exist only in the database**: no file in this repository contains
  them. The safety copy below is the other place they survive.
- `cards` holds 265 cards, every one of them in German **and** English, plus an
  optional `map_region` (`DE:Bayern`, `EU:FR`, `WORLD:CN`) for a card image.
- `users` holds the accounts (sign in and register through `api/auth.php`);
  `user_card_progress` holds one row per user and card.

`front`/`back` are the old NOT NULL columns and still carry the German text; the
language columns `front_de`/`back_de`/`front_en`/`back_en` are what the interface
uses.

The three map files (`public/assets/maps/*.svg`), the favicon and the font are
loaded by the browser at run time and therefore stay files. The Europe map is
switched off **in the display only**: the file and every stored `EU:` region are
untouched, so it can be switched on again at any time.

## Safety copy of the database

Before the cleanup of 2026-09-22 a complete dump (structure **and** data) was
written to

```text
/home/user/backups/learning_app_vollstaendig_20260922_162310.sql     (375 185 bytes)
```

It sits deliberately **outside** this repository and is not committed. From
Windows it is reachable as
`\\wsl.localhost\Ubuntu\home\user\backups\learning_app_vollstaendig_20260922_162310.sql`,
so it can be saved elsewhere or imported in phpMyAdmin if an older state is ever
needed.

## Conventions

- English names for files, folders, tables, columns, functions and variables.
  Only the text the user sees is translated (`src/helpers/translations.php`,
  German and English).
- Prepared statements with bound parameters for every user input. Never build SQL
  by concatenating.
- Every answer uses the JSON envelope `{"success":true,"data":...}` or
  `{"success":false,"error":{"code":...,"message":...}}`.
- The files in `database/*.sql` are migrations **for the user to run by hand** in
  phpMyAdmin; the application never changes the schema itself. What each one did
  is listed in `docs/migrations.md`.

## Documentation

- `docs/project-brief.md` — the whole project: database, endpoints, services,
  frontend, learning logic, conventions and the current state.
- `docs/verification.md` — the manual verification run, start to finish.
- `docs/migrations.md` — every migration file and what it changed.
