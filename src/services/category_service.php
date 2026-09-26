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
 *   icon_svg       mediumtext, NULL       -- the drawn icon, sanitised
 *                                           (never part of an answer: the row
 *                                            carries only the address)
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
        $columns = category_columns_from_metadata($pdo);

        /*
         * A driver that cannot report the metadata of a result would hand
         * back an empty list, and an empty list would silently drop the
         * optional columns from every query. SHOW COLUMNS is the fallback
         * for exactly that case; on a normal MySQL driver it never runs.
         */
        if ($columns === []) {
            foreach ($pdo->query('SHOW COLUMNS FROM categories')->fetchAll() as $column) {
                $columns[] = (string) $column['Field'];
            }
        }
    }

    return $columns;
}

/**
 * Reads the column names of the categories table from the metadata of a
 * query that returns no rows.
 *
 * The names of the columns are already part of the answer metadata, so the
 * table does not have to be described twice. "LIMIT 0" transfers no rows at
 * all. Measured on this machine: about 1.2 ms here against about 3.5 ms for
 * SHOW COLUMNS, on every single API request.
 *
 * @return list<string>
 */
function category_columns_from_metadata(PDO $pdo): array
{
    $statement = $pdo->query('SELECT * FROM categories LIMIT 0');
    $columns = [];

    for ($index = 0; $index < $statement->columnCount(); $index++) {
        $meta = $statement->getColumnMeta($index);

        if (is_array($meta) && isset($meta['name']) && is_string($meta['name'])) {
            $columns[] = $meta['name'];
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
 * It is written as two counted reads and not as one read with an OR, because
 * the OR stops MySQL from using the index on cards.category_id: it walks the
 * whole index once per row instead. Both spellings count the same cards -
 * every card has exactly one category_id, so nothing can be counted twice.
 *
 * The icon itself is never sent to the browser as part of a list: a row only
 * carries whether an icon exists and a short fingerprint of it. The icon is
 * fetched separately through api/category_icon.php, which keeps a 60 KB
 * drawing out of every list response.
 *
 * @param list<string> $columns
 */
function category_select_sql(array $columns): string
{
    /*
     * The drawing is NEVER part of an answer - not in a list and not for a single
     * category. A drawing may be 350 KB, and a page that asks for a category
     * would carry it in every answer.
     *
     * What a row carries instead is the address of the drawing (icon_url below),
     * with a short fingerprint of its content in it. The browser fetches the
     * drawing once from api/category_icon.php and keeps it, and a replaced icon
     * gets a new address, so nothing stale is ever shown.
     */
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
                (
                    (SELECT COUNT(*)
                       FROM cards AS own_card
                      WHERE own_card.category_id = c.id)
                    +
                    (SELECT COUNT(*)
                       FROM cards AS child_card
                       JOIN categories AS direct_child
                         ON direct_child.id = child_card.category_id
                      WHERE direct_child.parent_id = c.id)
                ) AS card_count
            FROM categories AS c';
}

/**
 * Returns all top-level categories (the learning areas) of ONE account.
 *
 * Every read in this file takes the owner as a required argument. There is no
 * default and no "no filter" mode on purpose: a forgotten argument has to fail
 * loudly instead of quietly handing somebody else's categories to a request.
 */
function find_main_categories(PDO $pdo, int $ownerUserId): array
{
    $statement = $pdo->prepare(
        category_select_sql(category_columns($pdo)) . '
         WHERE c.parent_id IS NULL
           AND c.owner_user_id = :owner_user_id
         ORDER BY c.id ASC'
    );
    $statement->bindValue(':owner_user_id', $ownerUserId, PDO::PARAM_INT);
    $statement->execute();

    return normalize_category_rows($statement->fetchAll());
}

/**
 * Returns the subcategories of one category, ordered by id.
 */
function find_subcategories(PDO $pdo, int $parentId, int $ownerUserId): array
{
    // The value is bound as an integer. It reaches the database separately from
    // the SQL text, so it can never be read as part of the query.
    $statement = $pdo->prepare(
        category_select_sql(category_columns($pdo)) . '
         WHERE c.parent_id = :parent_id
           AND c.owner_user_id = :owner_user_id
         ORDER BY c.id ASC'
    );
    $statement->bindValue(':parent_id', $parentId, PDO::PARAM_INT);
    $statement->bindValue(':owner_user_id', $ownerUserId, PDO::PARAM_INT);
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
function find_category(PDO $pdo, int $categoryId, int $ownerUserId, bool $withDeletePreview = false): ?array
{
    $statement = $pdo->prepare(
        category_select_sql(category_columns($pdo)) . '
         WHERE c.id = :id
           AND c.owner_user_id = :owner_user_id'
    );
    $statement->bindValue(':id', $categoryId, PDO::PARAM_INT);
    $statement->bindValue(':owner_user_id', $ownerUserId, PDO::PARAM_INT);
    $statement->execute();

    $row = $statement->fetch();

    if ($row === false) {
        return null;
    }

    $category = normalize_category_row($row);

    if (!$withDeletePreview) {
        return $category;
    }

    $stats = category_subtree_stats($pdo, $categoryId, $ownerUserId);

    // "categories" counts the subcategories below this one; the category itself
    // is not part of the preview.
    $category['delete_preview'] = [
        'categories' => max(0, $stats['categories'] - 1),
        'cards' => $stats['cards'],
    ];

    return $category;
}

/**
 * Reports whether this account owns a category with this id.
 *
 * This is the gate in front of everything that hangs off a category: the cards
 * and the review queue ask here first. Without the owner in the condition a
 * second account could reach somebody else's cards just by guessing a category
 * id.
 */
function category_exists(PDO $pdo, int $categoryId, int $ownerUserId): bool
{
    $statement = $pdo->prepare(
        'SELECT COUNT(*) FROM categories WHERE id = :id AND owner_user_id = :owner_user_id'
    );
    $statement->bindValue(':id', $categoryId, PDO::PARAM_INT);
    $statement->bindValue(':owner_user_id', $ownerUserId, PDO::PARAM_INT);
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
function category_sibling_name_exists(PDO $pdo, string $name, ?int $parentId, int $ownerUserId, ?int $exceptId = null): bool
{
    /* Two accounts may both own a "Mathematics" area, so the owner belongs in
       this condition as much as the name does. */
    $sql = 'SELECT COUNT(*)
              FROM categories
             WHERE owner_user_id = :owner_user_id
               AND name = :name
               AND ' . ($parentId === null ? 'parent_id IS NULL' : 'parent_id = :parent_id');

    if ($exceptId !== null) {
        $sql .= ' AND id <> :except_id';
    }

    $statement = $pdo->prepare($sql);
    $statement->bindValue(':owner_user_id', $ownerUserId, PDO::PARAM_INT);
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
function create_category(PDO $pdo, array $fields, int $ownerUserId): array
{
    $available = category_columns($pdo);
    $columns = ['parent_id', 'name', 'owner_user_id'];
    $values = [':parent_id', ':name', ':owner_user_id'];
    $bindings = [
        ':parent_id' => [$fields['parent_id'] ?? null, PDO::PARAM_INT],
        ':name' => [(string) $fields['name'], PDO::PARAM_STR],
        ':owner_user_id' => [$ownerUserId, PDO::PARAM_INT],
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

    $created = find_category($pdo, (int) $pdo->lastInsertId(), $ownerUserId);

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
function update_category(PDO $pdo, int $categoryId, array $changes, int $ownerUserId): ?array
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
        return find_category($pdo, $categoryId, $ownerUserId);
    }

    $statement = $pdo->prepare(
        'UPDATE categories SET ' . implode(', ', $assignments) . '
          WHERE id = :id AND owner_user_id = :owner_user_id'
    );
    $statement->bindValue(':id', $categoryId, PDO::PARAM_INT);
    $statement->bindValue(':owner_user_id', $ownerUserId, PDO::PARAM_INT);

    foreach ($bindings as $placeholder => [$value, $type]) {
        if ($value === null) {
            $statement->bindValue($placeholder, null, PDO::PARAM_NULL);
            continue;
        }

        $statement->bindValue($placeholder, $value, $type);
    }

    $statement->execute();

    return find_category($pdo, $categoryId, $ownerUserId);
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
function category_subtree_ids(PDO $pdo, int $categoryId, int $ownerUserId): array
{
    $children = [];
    $owned = [];

    /* Only this account's rows are read, so a foreign id can never pull another
       account's subtree into a delete or a count. */
    $statement = $pdo->prepare('SELECT id, parent_id FROM categories WHERE owner_user_id = :owner_user_id');
    $statement->bindValue(':owner_user_id', $ownerUserId, PDO::PARAM_INT);
    $statement->execute();

    foreach ($statement->fetchAll() as $row) {
        $owned[(int) $row['id']] = true;

        // A NULL parent_id becomes the key 0, which is never a real id.
        $parentKey = $row['parent_id'] === null ? 0 : (int) $row['parent_id'];
        $children[$parentKey][] = (int) $row['id'];
    }

    /*
     * A category of somebody else is not a subtree with one member - it is no
     * subtree at all. Returning [] keeps the answer honest instead of echoing an
     * id that this account does not own, and delete_category_tree() then finds
     * nothing to delete and refuses with its own check.
     */
    if (!isset($owned[$categoryId])) {
        return [];
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
function category_subtree_stats(PDO $pdo, int $categoryId, int $ownerUserId): array
{
    $ids = category_subtree_ids($pdo, $categoryId, $ownerUserId);

    /* A foreign category is no subtree at all, and "IN ()" is not valid SQL -
       so the empty answer is written out here instead of being built. */
    if ($ids === []) {
        return ['categories' => 0, 'cards' => 0];
    }
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
function category_delete_dependents(PDO $pdo, int $categoryId, int $ownerUserId): array
{
    $stats = category_subtree_stats($pdo, $categoryId, $ownerUserId);

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
function delete_category_tree(PDO $pdo, int $categoryId, int $ownerUserId): array
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
        $ids = category_subtree_ids($pdo, $categoryId, $ownerUserId);

        // 1. the learning progress of every card in this subtree
        $deletedProgress = delete_progress_of_categories($pdo, $ids, $ownerUserId);

        // 2. the cards themselves
        $deletedCards = delete_cards_of_categories($pdo, $ids, $ownerUserId);

        /*
         * 3. and 4. the categories. category_subtree_ids() returns a parent
         * before its children, so the reversed list deletes the deepest level
         * first. A category can therefore never be removed while something still
         * points at it.
         */
        $statement = $pdo->prepare('DELETE FROM categories WHERE id = :id AND owner_user_id = :owner_user_id');
        $deletedCategories = 0;
        $deletedSelected = 0;
        $statement->bindValue(':owner_user_id', $ownerUserId, PDO::PARAM_INT);

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

        $check = $pdo->prepare('SELECT COUNT(*) FROM categories WHERE id = :id AND owner_user_id = :owner_user_id');
        $check->bindValue(':id', $categoryId, PDO::PARAM_INT);
        $check->bindValue(':owner_user_id', $ownerUserId, PDO::PARAM_INT);
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

/**
 * Which of these categories have subcategories of their own?
 *
 * Used by api/bootstrap.php: the answer names the parents that need a query, so
 * a tree that is only two levels deep does not cost one query per category.
 *
 * @param list<int> $ids
 * @return list<int> the ids that really have children, ordered like the input
 */
function category_ids_with_children(PDO $pdo, array $ids, int $ownerUserId): array
{
    $wanted = array_values(array_unique(array_filter($ids, static fn ($id) => (int) $id > 0)));

    if ($wanted === []) {
        return [];
    }

    /* Every placeholder gets its own name: a statement may not mix named and
       positional placeholders, and the rest of this file uses named ones. */
    $placeholders = [];

    foreach ($wanted as $index => $id) {
        $placeholders[] = ':parent_' . $index;
    }

    $statement = $pdo->prepare(
        'SELECT DISTINCT parent_id FROM categories
          WHERE parent_id IN (' . implode(', ', $placeholders) . ')
            AND owner_user_id = :owner_user_id
          ORDER BY parent_id ASC'
    );

    $statement->bindValue(':owner_user_id', $ownerUserId, PDO::PARAM_INT);

    foreach ($wanted as $index => $id) {
        $statement->bindValue(':parent_' . $index, (int) $id, PDO::PARAM_INT);
    }

    $statement->execute();

    return array_map(static fn ($value): int => (int) $value, $statement->fetchAll(PDO::FETCH_COLUMN));
}
