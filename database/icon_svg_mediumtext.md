# `categories.icon_svg` is `MEDIUMTEXT` now

**Status: already done by hand in phpMyAdmin (2026-09-21).**

This file is **documentation only**. It is not a script to run, it is not part of
any import, and the application never executes it. It exists so that the reason
for the column type can be found later.

## Why the type had to change

A drawing may be uploaded with up to **350 KB** (358,400 bytes). The old type
`TEXT` holds at most 65,535 **bytes**, so a large drawing could not be stored in
it. `MEDIUMTEXT` holds up to 16,777,215 bytes, which is far more than the upload
limit needs.

## What the change is (for reference - it has already been executed)

```sql
ALTER TABLE `categories`
    MODIFY COLUMN `icon_svg` MEDIUMTEXT NULL;
```

Nothing else was touched: no other column, no index, no constraint, and **no row
was changed**. The existing drawings kept their exact content.

## What it means for the application

* A drawing is stored as it was uploaded. It is no longer rounded or shortened to
  fit into 65 KB - the coordinates stay exactly as drawn (see
  `src/helpers/svg_sanitizer.php`).
* The upload limit is enforced in one place on the server
  (`SVG_MAX_UPLOAD_BYTES`) and mirrored in the form (`iconBytes` in
  `public/index.php`). Both are 350 KB.
* A drawing is **never** part of a JSON answer - neither in the category list nor
  for a single category. A row carries only the address of its drawing
  (`icon_url`), which contains a short fingerprint (the first 8 characters of
  `MD5(icon_svg)`). The browser fetches the drawing from
  `public/api/category_icon.php` and keeps it; a replaced drawing gets a new
  address, so an old one can never be shown again.
* Existing drawings were not re-written, re-imported or re-sanitised.

## How to check the type later

```sql
SHOW COLUMNS FROM `categories` LIKE 'icon_svg';
```

Expected: `mediumtext`, `NULL` allowed, default `NULL`.
