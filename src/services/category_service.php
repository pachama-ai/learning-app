<?php

declare(strict_types=1);

/**
 * Database queries for the `categories` table.
 *
 * Verified structure (checked with SHOW COLUMNS):
 *   id             int unsigned, NOT NULL, primary key, auto_increment
 *   parent_id      int unsigned, NULL, foreign key to categories.id
 *   name           varchar(100), NOT NULL
 *   color          varchar(7), NULL      -- still in the table, not read or written here
 *   icon_svg       text, NULL            -- the drawn icon, sanitised
 *   icon_scale     decimal(3,2), NOT NULL, default 1.00
 *   name_en        varchar(100), NULL
 *   name_de        varchar(100), NULL
 *
 * The two description columns are still in the table, but nothing in this
 * application reads or writes them any more: a category is shown by its name.
 * They were not dropped and their data was not touched.
 *
 * A NULL parent_id means "top-level learning area". Any other value points at
 * the parent category, which is how subcategories are stored.
 *
 * The optional columns are never referenced blindly: the table is inspected
 * once per request and the SELECT is built from the columns that really exist.
 * The page therefore keeps working on a database where the migration has not
 * been run yet, only without the icons and the translated names.
 *
 * Every function receives the PDO connection as an argument instead of opening
 * its own connection, so one request always uses exactly one connection.
 */

/** Longest accepted name, matching the varchar(100) column. */
const CATEGORY_MAX_NAME_LENGTH = 100;


/** How deep the tree may be walked while a category is deleted. */
const CATEGORY_MAX_DEPTH = 12;

/**
 * Names of the columns that really exist in the table.
 *
 * The answer is remembered for the rest of the request, so the extra SHOW
 * COLUMNS only runs once even when several queries are made.
 *
 * @return list<string>
 */
function category_columns(PDO $pdo): array
{
    static $columns = null;

    if ($columns === null) {
        $columns = [];

        foreach ($pdo->query('SHOW COLUMNS FROM categories')->fetchAll() as $column) {
            $columns[] = (string) $column['Field'];
        }
    }

    return $columns;
}

/**
 * Reports whether one optional column exists.
 *
 * @param list<string> $columns
 */
function category_column_available(array $columns, string $column): bool
{
    return in_array($column, $columns, true);
}

/**
 * Builds the shared SELECT for a category list.
 *
 * subcategory_count counts the direct children of the category.
 * own_card_count counts the cards that sit directly in this category.
 * card_count counts the cards of the category itself plus the cards of its
 * direct children, because a card belongs to exactly one category through
 * cards.category_id and the subcategories are the deeper level of the tree.
 *
 * The icon itself is never sent to the browser as part of a list: a row only
 * carries whether an icon exists and a short fingerprint of it. The icon is
 * fetched separately through api/category_icon.php, which keeps a 60 KB
 * drawing out of every list response.
 *
 * @param list<string> $columns
 */
function category_select_sql(array $columns, bool $withIconSvg = false): string
{
    /*
     * The drawing itself is only part of the answer for a SINGLE category: a
     * list must not carry one drawing of up to 60 KB per row. For a list the
     * browser asks api/category_icon.php instead.
     */
    $iconSvg = $withIconSvg && category_column_available($columns, 'icon_svg')
        ? "                c.icon_svg AS icon_svg,\n"
        : '';
    $scale = category_column_available($columns, 'icon_scale') ? 'c.icon_scale' : '1.00';
    $iconFingerprint = category_column_available($columns, 'icon_svg')
        ? "CASE WHEN c.icon_svg IS NULL OR c.icon_svg = '' THEN NULL ELSE MD5(c.icon_svg) END"
        : 'NULL';
    $nameEn = category_column_available($columns, 'name_en') ? 'c.name_en' : 'NULL';
    $nameDe = category_column_available($columns, 'name_de') ? 'c.name_de' : 'NULL';

    return 'SELECT
                c.id,
                c.parent_id,
                c.name,
                ' . $scale . ' AS icon_scale,
                ' . $iconFingerprint . ' AS icon_fingerprint,
                ' . $nameEn . ' AS name_en,
                ' . $nameDe . ' AS name_de,
                (SELECT COUNT(*)
                   FROM categories AS child
                  WHERE child.parent_id = c.id) AS subcategory_count,
                (SELECT COUNT(*)
                   FROM cards AS card
                  WHERE card.category_id = c.id) AS own_card_count,
                (SELECT COUNT(*)
                   FROM cards AS card
                  WHERE card.category_id = c.id
                     OR card.category_id IN (
                            SELECT grandchild.id
                              FROM categories AS grandchild
                             WHERE grandchild.parent_id = c.id
                        )) AS card_count
            FROM categories AS c';
}

/**
 * Returns all top-level categories (the learning areas).
 */
function find_main_categories(PDO $pdo): array
{
    $statement = $pdo->prepare(
        category_select_sql(category_columns($pdo)) . '
         WHERE c.parent_id IS NULL
         ORDER BY c.id ASC'
    );
    $statement->execute();

    return normalize_category_rows($statement->fetchAll());
}

/**
 * Returns the subcategories of one category, ordered by id.
 */
function find_subcategories(PDO $pdo, int $parentId): array
{
    // The value is bound as an integer. It reaches the database separately from
    // the SQL text, so it can never be read as part of the query.
    $statement = $pdo->prepare(
        category_select_sql(category_columns($pdo)) . '
         WHERE c.parent_id = :parent_id
         ORDER BY c.id ASC'
    );
    $statement->bindValue(':parent_id', $parentId, PDO::PARAM_INT);
    $statement->execute();

    return normalize_category_rows($statement->fetchAll());
}

/**
 * Returns one category, or null when it does not exist.
 *
 * With $withDeletePreview the subtree is counted as well, so the delete dialog
 * can say exactly how much would disappear. That count is a preview only: the
 * delete endpoint counts again inside its own transaction.
 *
 * @return array<string, mixed>|null
 */
function find_category(PDO $pdo, int $categoryId, bool $withDeletePreview = false): ?array
{
    $statement = $pdo->prepare(
        category_select_sql(category_columns($pdo), true) . '
         WHERE c.id = :id'
    );
    $statement->bindValue(':id', $categoryId, PDO::PARAM_INT);
    $statement->execute();

    $row = $statement->fetch();

    if ($row === false) {
        return null;
    }

    $category = normalize_category_row($row);

    if (!$withDeletePreview) {
        return $category;
    }

    $stats = category_subtree_stats($pdo, $categoryId);

    // "categories" counts the subcategories below this one; the category itself
    // is not part of the preview.
    $category['delete_preview'] = [
        'categories' => max(0, $stats['categories'] - 1),
        'cards' => $stats['cards'],
    ];

    return $category;
}

/**
 * Reports whether a category with this id exists.
 */
function category_exists(PDO $pdo, int $categoryId): bool
{
    $statement = $pdo->prepare('SELECT COUNT(*) FROM categories WHERE id = :id');
    $statement->bindValue(':id', $categoryId, PDO::PARAM_INT);
    $statement->execute();

    return (int) $statement->fetchColumn() > 0;
}

/**
 * Reports whether a category with this name already exists among its siblings.
 *
 * The name column uses the utf8mb4_unicode_ci collation, which is
 * case-insensitive, so "History" and "history" are treated as the same name.
 *
 * @param int|null $parentId The parent the new category would be created in.
 * @param int|null $exceptId A category that is allowed to keep the name (the
 *                           one that is currently being edited).
 */
function category_sibling_name_exists(PDO $pdo, string $name, ?int $parentId, ?int $exceptId = null): bool
{
    $sql = 'SELECT COUNT(*)
              FROM categories
             WHERE name = :name
               AND ' . ($parentId === null ? 'parent_id IS NULL' : 'parent_id = :parent_id');

    if ($exceptId !== null) {
        $sql .= ' AND id <> :except_id';
    }

    $statement = $pdo->prepare($sql);
    $statement->bindValue(':name', $name, PDO::PARAM_STR);

    if ($parentId !== null) {
        $statement->bindValue(':parent_id', $parentId, PDO::PARAM_INT);
    }

    if ($exceptId !== null) {
        $statement->bindValue(':except_id', $exceptId, PDO::PARAM_INT);
    }

    $statement->execute();

    return (int) $statement->fetchColumn() > 0;
}

/**
 * Reports whether a top-level category with this name already exists.
 *
 * Kept because api/add_category.php uses it; the newer endpoint uses
 * category_sibling_name_exists(), which can also look at subcategories.
 */
function category_name_exists(PDO $pdo, string $name): bool
{
    return category_sibling_name_exists($pdo, $name, null);
}

/**
 * Inserts a new top-level learning area from nothing but a name.
 *
 * Kept because api/add_category.php uses it - that older endpoint only knows a
 * name. Everything newer goes through create_category(), which also takes a
 * colour, an icon and the two translations.
 *
 * @return array<string, mixed>
 */
function create_main_category(PDO $pdo, string $name): array
{
    return create_category($pdo, ['parent_id' => null, 'name' => $name]);
}

/**
 * Inserts a new learning area or subcategory and returns it in the same shape
 * the read functions return, so the browser can use it without a second request.
 *
 * The values come from $fields, where only the keys listed below are used. Every
 * value is bound separately, so nothing from the request can become part of the
 * SQL text. Missing keys simply stay at their column default.
 *
 * @param array<string, mixed> $fields
 * @return array<string, mixed>
 */
function create_category(PDO $pdo, array $fields): array
{
    $available = category_columns($pdo);
    $columns = ['parent_id', 'name'];
    $values = [':parent_id', ':name'];
    $bindings = [
        ':parent_id' => [$fields['parent_id'] ?? null, PDO::PARAM_INT],
        ':name' => [(string) $fields['name'], PDO::PARAM_STR],
    ];

    $optional = [
        'name_en' => PDO::PARAM_STR,
        'name_de' => PDO::PARAM_STR,
        'icon_svg' => PDO::PARAM_STR,
        'icon_scale' => PDO::PARAM_STR,
    ];

    foreach ($optional as $column => $type) {
        if (!array_key_exists($column, $fields) || !category_column_available($available, $column)) {
            continue;
        }

        $columns[] = $column;
        $values[] = ':' . $column;
        $bindings[':' . $column] = [$fields[$column], $type];
    }

    $statement = $pdo->prepare(
        'INSERT INTO categories (' . implode(', ', $columns) . ')
         VALUES (' . implode(', ', $values) . ')'
    );

    foreach ($bindings as $placeholder => [$value, $type]) {
        if ($value === null) {
            // A real NULL, which is what makes a top-level category top-level
            // and what leaves an optional field empty.
            $statement->bindValue($placeholder, null, PDO::PARAM_NULL);
            continue;
        }

        $statement->bindValue($placeholder, $value, $type);
    }

    $statement->execute();

    $created = find_category($pdo, (int) $pdo->lastInsertId());

    return $created ?? [];
}

/**
 * Updates the given fields of one category.
 *
 * Keys that are not real columns are ignored, so a request body can never add
 * anything to the SQL text. A value of null clears an optional field.
 *
 * @param array<string, mixed> $changes
 * @return array<string, mixed>|null
 */
function update_category(PDO $pdo, int $categoryId, array $changes): ?array
{
    $allowed = [
        'name' => PDO::PARAM_STR,
        'name_en' => PDO::PARAM_STR,
        'name_de' => PDO::PARAM_STR,
        'icon_svg' => PDO::PARAM_STR,
        'icon_scale' => PDO::PARAM_STR,
    ];

    $available = category_columns($pdo);
    $assignments = [];
    $bindings = [];

    foreach ($allowed as $column => $type) {
        if (!array_key_exists($column, $changes) || !category_column_available($available, $column)) {
            continue;
        }

        $assignments[] = $column . ' = :' . $column;
        $bindings[':' . $column] = [$changes[$column], $type];
    }

    if ($assignments === []) {
        return find_category($pdo, $categoryId);
    }

    $statement = $pdo->prepare(
        'UPDATE categories SET ' . implode(', ', $assignments) . ' WHERE id = :id'
    );
    $statement->bindValue(':id', $categoryId, PDO::PARAM_INT);

    foreach ($bindings as $placeholder => [$value, $type]) {
        if ($value === null) {
            $statement->bindValue($placeholder, null, PDO::PARAM_NULL);
            continue;
        }

        $statement->bindValue($placeholder, $value, $type);
    }

    $statement->execute();

    return find_category($pdo, $categoryId);
}

/**
 * Collects the id of a category and of every category below it.
 *
 * The whole (small) table is read once and walked in PHP. That keeps the query
 * simple, avoids a recursive SQL statement and cannot be tricked into walking
 * forever: after CATEGORY_MAX_DEPTH levels the loop stops.
 *
 * @return list<int> The category itself first, then its children level by level.
 */
function category_subtree_ids(PDO $pdo, int $categoryId): array
{
    $children = [];

    foreach ($pdo->query('SELECT id, parent_id FROM categories')->fetchAll() as $row) {
        // A NULL parent_id becomes the key 0, which is never a real id.
        $parentKey = $row['parent_id'] === null ? 0 : (int) $row['parent_id'];
        $children[$parentKey][] = (int) $row['id'];
    }

    $ids = [$categoryId];
    $frontier = [$categoryId];
    $depth = 0;

    while ($frontier !== [] && $depth < CATEGORY_MAX_DEPTH) {
        $next = [];

        foreach ($frontier as $id) {
            foreach ($children[$id] ?? [] as $childId) {
                $ids[] = $childId;
                $next[] = $childId;
            }
        }

        $frontier = $next;
        $depth++;
    }

    return $ids;
}

/**
 * Counts a category, everything below it and all cards in that subtree.
 *
 * @return array{categories: int, cards: int}
 */
function category_subtree_stats(PDO $pdo, int $categoryId): array
{
    $ids = category_subtree_ids($pdo, $categoryId);
    $placeholders = [];
    $bindings = [];

    foreach (array_values($ids) as $index => $id) {
        $placeholders[] = ':id' . $index;
        $bindings[':id' . $index] = $id;
    }

    $statement = $pdo->prepare('SELECT COUNT(*) FROM cards WHERE category_id IN (' . implode(', ', $placeholders) . ')');

    foreach ($bindings as $placeholder => $id) {
        $statement->bindValue($placeholder, $id, PDO::PARAM_INT);
    }

    $statement->execute();

    return [
        'categories' => count($ids),
        'cards' => (int) $statement->fetchColumn(),
    ];
}

/**
 * Reports how much depends on a category: its descendants and their cards.
 *
 * The delete endpoint uses this to decide whether a request has to be confirmed
 * once more. The decision is made on the server, never in the browser, so a hand
 * written request cannot skip the confirmation by leaving the field out.
 *
 * @return array{categories: int, cards: int, descendants: int}
 */
function category_delete_dependents(PDO $pdo, int $categoryId): array
{
    $stats = category_subtree_stats($pdo, $categoryId);

    return [
        /* Everything including the category itself. */
        'categories' => $stats['categories'],
        /* The categories below it, which is what a person sees as "depends on it". */
        'descendants' => max(0, $stats['categories'] - 1),
        'cards' => $stats['cards'],
    ];
}

/**
 * Deletes a category, every subcategory below it and every card in that subtree.
 *
 * The order is fixed by the foreign keys and is written out on purpose:
 *   1. the learning progress of the affected cards
 *   2. the cards, because fk_cards_category is ON DELETE RESTRICT
 *   3. the subcategories from the deepest level upwards, because
 *      fk_categories_parent is ON DELETE RESTRICT as well
 *   4. finally the selected category itself
 *
 * Everything happens in ONE transaction. Either the whole subtree disappears or
 * nothing does - a half deleted tree is never left behind.
 *
 * The selected category is checked twice before the transaction is allowed to
 * commit: its own DELETE has to affect exactly one row, and a SELECT has to find
 * nothing afterwards. A caller can therefore never be told "deleted" while the
 * row is still there.
 *
 * @return array{categories: int, cards: int, progress: int} What was really deleted.
 * @throws RuntimeException when the selected category is still there afterwards.
 */
function delete_category_tree(PDO $pdo, int $categoryId): array
{
    // The cards (and their progress) are deleted first and that is done by the
    // card service, so it is loaded here instead of relying on the caller.
    require_once __DIR__ . '/card_service.php';

    /*
     * Normally this function owns the transaction. When it is called from a
     * transaction that somebody else started - a test, or a future caller that
     * deletes several subtrees in one go - that outer transaction is used as it
     * is, so the decision about committing stays with the caller.
     */
    $ownsTransaction = !$pdo->inTransaction();

    if ($ownsTransaction) {
        $pdo->beginTransaction();
    }

    try {
        $ids = category_subtree_ids($pdo, $categoryId);

        // 1. the learning progress of every card in this subtree
        $deletedProgress = delete_progress_of_categories($pdo, $ids);

        // 2. the cards themselves
        $deletedCards = delete_cards_of_categories($pdo, $ids);

        /*
         * 3. and 4. the categories. category_subtree_ids() returns a parent
         * before its children, so the reversed list deletes the deepest level
         * first. A category can therefore never be removed while something still
         * points at it.
         */
        $statement = $pdo->prepare('DELETE FROM categories WHERE id = :id');
        $deletedCategories = 0;
        $deletedSelected = 0;

        foreach (array_reverse($ids) as $id) {
            $statement->bindValue(':id', $id, PDO::PARAM_INT);
            $statement->execute();

            $affected = $statement->rowCount();
            $deletedCategories += $affected;

            if ($id === $categoryId) {
                $deletedSelected = $affected;
            }
        }

        /*
         * The proof. "rowCount() === 1" says the row was there and is gone; the
         * SELECT says the same thing from the other side. If either of them
         * disagrees, the whole transaction is rolled back and the caller gets an
         * error instead of a success message about a row that still exists.
         */
        if ($deletedSelected !== 1) {
            throw new RuntimeException('The selected category was not deleted.');
        }

        $check = $pdo->prepare('SELECT COUNT(*) FROM categories WHERE id = :id');
        $check->bindValue(':id', $categoryId, PDO::PARAM_INT);
        $check->execute();

        if ((int) $check->fetchColumn() !== 0) {
            throw new RuntimeException('The selected category still exists.');
        }

        if ($ownsTransaction) {
            $pdo->commit();
        }

        return [
            'categories' => $deletedCategories,
            'cards' => $deletedCards,
            'progress' => $deletedProgress,
        ];
    } catch (Throwable $error) {
        // Rolling back puts the database exactly where it was before the
        // attempt, including the cards that were already deleted.
        if ($ownsTransaction) {
            $pdo->rollBack();
        }

        throw $error;
    }
}

/**
 * Converts a list of database rows into the shape the API promises.
 *
 * @param list<array<string, mixed>> $rows
 * @return list<array<string, mixed>>
 */
function normalize_category_rows(array $rows): array
{
    $categories = [];

    foreach (array_values($rows) as $row) {
        $categories[] = normalize_category_row($row);
    }

    return $categories;
}

/**
 * Converts one database row into the shape the API promises.
 *
 * The casts matter for the JSON output: the browser receives "id": 3 as a
 * number instead of "3" as a string, so it can compare ids without converting.
 *
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function normalize_category_row(array $row): array
{
    $id = (int) $row['id'];

    /*
     * The `color` column is still in the table but nothing in this
     * application reads, writes or displays it any more, so it is not part
     * of the answer. A category is neutral by design.
     */
    $fingerprint = $row['icon_fingerprint'] ?? null;
    $hasIcon = is_string($fingerprint) && $fingerprint !== '';

    $scale = $row['icon_scale'] ?? null;

    if (!is_numeric($scale) || (float) $scale < 0.2 || (float) $scale > 3.0) {
        $scale = 1.0;
    }

    $category = [
        'id' => $id,
        'parent_id' => $row['parent_id'] === null ? null : (int) $row['parent_id'],
        'name' => (string) $row['name'],
        /*
         * The address of the stored icon. It carries a short fingerprint of the
         * drawing, so a browser that cached the old icon asks for the new one
         * as soon as the icon is edited. null means "this category has no icon
         * of its own"; the browser then falls back to its own illustration.
         */
        'icon_url' => $hasIcon
            ? 'api/category_icon.php?id=' . $id . '&v=' . substr((string) $fingerprint, 0, 8)
            : null,
        'icon_scale' => round((float) $scale, 2),
        'name_en' => normalize_optional_text($row['name_en'] ?? null, CATEGORY_MAX_NAME_LENGTH),
        'name_de' => normalize_optional_text($row['name_de'] ?? null, CATEGORY_MAX_NAME_LENGTH),
        'subcategory_count' => (int) ($row['subcategory_count'] ?? 0),
        'own_card_count' => (int) ($row['own_card_count'] ?? 0),
        'card_count' => (int) ($row['card_count'] ?? 0),
    ];

    /*
     * The stored drawing itself. Only a single category carries it (see
     * category_select_sql), and a category without a drawing answers with null,
     * never with a file name.
     */
    if (array_key_exists('icon_svg', $row)) {
        $iconSvg = $row['icon_svg'];

        $category['icon_svg'] = is_string($iconSvg) && trim($iconSvg) !== ''
            ? $iconSvg
            : null;
    }

    return $category;
}

/**
 * Trims an optional text value and turns an empty one into null, so the browser
 * always receives either a real string or null.
 *
 * @param mixed $value
 */
function normalize_optional_text($value, int $maxLength): ?string
{
    if (!is_string($value)) {
        return null;
    }

    $value = trim($value);

    if ($value === '' || mb_strlen($value) > $maxLength) {
        return null;
    }

    return $value;
}
