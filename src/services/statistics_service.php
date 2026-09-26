<?php

declare(strict_types=1);

require_once __DIR__ . '/category_service.php';
require_once __DIR__ . '/review_service.php';

/**
 * The numbers behind the statistics view.
 *
 * This file only reads. It writes nothing, so no call of it can change a card,
 * a category or a progress row, and the structure of the database is not touched
 * anywhere in here.
 *
 * Two scopes are counted, and both end up in the same shape:
 *   - a learning area (a category without a parent): every card of every
 *     subcategory of that area, added together
 *   - a subcategory: its own cards and - should it ever get subcategories - the
 *     cards below it
 *
 * "Known" is the state the review service writes, not a number of its own here:
 * see review_service.php - 0 = never learned, 1 = learning, 2 = known. The
 * constants are used instead of the digits, so a change there never has to be
 * copied into this file.
 *
 * A card WITHOUT a progress row has never been seen and counts as new. That is
 * exactly what the card list of a category says too, so the head of a card list
 * and this page never disagree.
 *
 * study_sessions exists but may still be empty. The seven day history is
 * therefore only filled when that table really has rows for this person;
 * otherwise the answer carries "no data" and the view says so instead of drawing
 * seven empty bars.
 */

/** How many days the little history covers. */
const STATISTICS_HISTORY_DAYS = 7;

/**
 * How far ahead the second due segment reaches. Everything after that counts as
 * "later". The value is a constant of this file, never something a request sent.
 */
const STATISTICS_SOON_DAYS = 3;

/**
 * Builds the placeholder list for a query over several categories.
 *
 * The ids come from the categories table, not from a request, and each one is
 * still bound as a parameter - so even a hand written id could not become part
 * of the SQL. The same helper is used everywhere a list of ids is needed.
 *
 * @param array<int, int|string> $ids
 * @return array{0: string, 1: array<string, int>}
 */
function statistics_id_placeholders(array $ids): array
{
    $placeholders = [];
    $bindings = [];

    foreach (array_values($ids) as $index => $id) {
        $name = ':id' . $index;
        $placeholders[] = $name;
        $bindings[$name] = (int) $id;
    }

    return [implode(', ', $placeholders), $bindings];
}

/**
 * Binds the ids of a placeholder list.
 *
 * @param array<string, int> $bindings
 */
function statistics_bind_ids(PDOStatement $statement, array $bindings): void
{
    foreach ($bindings as $name => $id) {
        $statement->bindValue($name, $id, PDO::PARAM_INT);
    }
}

/**
 * The whole category table as a tree, read once.
 *
 * A NULL parent_id becomes the key 0, which is never a real id - the same trick
 * category_service.php uses one directory above.
 *
 * @return array<int, array<int, int>>
 */
function statistics_category_tree(PDO $pdo): array
{
    $tree = [];

    foreach ($pdo->query('SELECT id, parent_id FROM categories')->fetchAll() as $row) {
        $parentKey = $row['parent_id'] === null ? 0 : (int) $row['parent_id'];
        $tree[$parentKey][] = (int) $row['id'];
    }

    return $tree;
}

/**
 * A category and everything below it, walked over a tree that is already read.
 *
 * @param array<int, array<int, int>> $tree
 * @return array<int, int>
 */
function statistics_subtree_ids(array $tree, int $rootId): array
{
    $ids = [$rootId];
    $frontier = [$rootId];
    $depth = 0;

    while ($frontier !== [] && $depth < CATEGORY_MAX_DEPTH) {
        $next = [];

        foreach ($frontier as $id) {
            foreach ($tree[$id] ?? [] as $childId) {
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
 * How many cards the scope holds and how much of them this person has worked on.
 *
 * The percentage is measured against the cards that HAVE a progress row, not
 * against all cards: a card nobody has opened yet says nothing about how well
 * the material is known. With no progress row at all the answer is null, and the
 * view shows a dash - never a made up zero.
 *
 * @param array<int, int> $ids
 * @return array{total: int, with_progress: int, fresh: int, learning: int, known: int, known_percent: float|null}
 */
function statistics_card_counts(PDO $pdo, array $ids, int $userId): array
{
    [$placeholders, $bindings] = statistics_id_placeholders($ids);

    $totalStatement = $pdo->prepare('SELECT COUNT(*) FROM cards WHERE category_id IN (' . $placeholders . ')');
    statistics_bind_ids($totalStatement, $bindings);
    $totalStatement->execute();
    $total = (int) $totalStatement->fetchColumn();

    $stateStatement = $pdo->prepare(
        'SELECT p.state, COUNT(*) AS amount
           FROM user_card_progress p
           JOIN cards k ON k.id = p.card_id
          WHERE p.user_id = :user_id AND k.category_id IN (' . $placeholders . ')
          GROUP BY p.state'
    );
    statistics_bind_ids($stateStatement, $bindings);
    $stateStatement->bindValue(':user_id', $userId, PDO::PARAM_INT);
    $stateStatement->execute();

    $learning = 0;
    $known = 0;
    $withProgress = 0;

    foreach ($stateStatement->fetchAll() as $row) {
        $amount = (int) $row['amount'];
        $withProgress += $amount;

        if ((int) $row['state'] === REVIEW_STATE_KNOWN) {
            $known += $amount;
        } elseif ((int) $row['state'] === REVIEW_STATE_LEARNING) {
            $learning += $amount;
        }
    }

    return [
        'total' => $total,
        'with_progress' => $withProgress,
        'fresh' => max(0, $total - $withProgress),
        'learning' => $learning,
        'known' => $known,
        'known_percent' => $withProgress > 0 ? round(($known / $withProgress) * 100, 1) : null,
    ];
}

/**
 * The four due segments of the scope.
 *
 * "Soon" ends after STATISTICS_SOON_DAYS days; everything with a later date is
 * "later". A row without a due date is a card that was answered and is not
 * scheduled any more - it is counted on its own instead of being squeezed into
 * one of the other three.
 *
 * @param array<int, int> $ids
 * @return array{now: int, soon: int, later: int, none: int}
 */
function statistics_due_buckets(PDO $pdo, array $ids, int $userId): array
{
    [$placeholders, $bindings] = statistics_id_placeholders($ids);
    $soonDays = STATISTICS_SOON_DAYS;

    $statement = $pdo->prepare(
        'SELECT
            SUM(CASE WHEN p.due_at IS NULL THEN 1 ELSE 0 END) AS none,
            SUM(CASE WHEN p.due_at IS NOT NULL AND p.due_at <= NOW() THEN 1 ELSE 0 END) AS now_due,
            SUM(CASE WHEN p.due_at > NOW() AND p.due_at <= DATE_ADD(NOW(), INTERVAL ' . $soonDays . ' DAY)
                     THEN 1 ELSE 0 END) AS soon,
            SUM(CASE WHEN p.due_at > DATE_ADD(NOW(), INTERVAL ' . $soonDays . ' DAY)
                     THEN 1 ELSE 0 END) AS later
           FROM user_card_progress p
           JOIN cards k ON k.id = p.card_id
          WHERE p.user_id = :user_id AND k.category_id IN (' . $placeholders . ')'
    );

    statistics_bind_ids($statement, $bindings);
    $statement->bindValue(':user_id', $userId, PDO::PARAM_INT);
    $statement->execute();

    $row = $statement->fetch();

    if ($row === false) {
        return ['now' => 0, 'soon' => 0, 'later' => 0, 'none' => 0];
    }

    return [
        'now' => (int) ($row['now_due'] ?? 0),
        'soon' => (int) ($row['soon'] ?? 0),
        'later' => (int) ($row['later'] ?? 0),
        'none' => (int) ($row['none'] ?? 0),
    ];
}

/**
 * How many cards this person studied on each of the last days.
 *
 * study_sessions is read inside a try: the table is part of the project and is
 * there, but a statistics page must never turn into an error page because one
 * table is missing or empty. "available" is false whenever there is nothing to
 * show, and the view then writes one quiet line instead of an empty chart.
 *
 * @return array{available: bool, days: array<int, array{day: string, cards: int}>}
 */
function statistics_history(PDO $pdo, int $userId): array
{
    $empty = ['available' => false, 'days' => []];

    try {
        $countStatement = $pdo->prepare('SELECT COUNT(*) FROM study_sessions WHERE user_id = :user_id');
        $countStatement->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $countStatement->execute();

        if ((int) $countStatement->fetchColumn() === 0) {
            return $empty;
        }

        $dayStatement = $pdo->prepare(
            'SELECT DATE(ended_at) AS day, SUM(cards_studied) AS amount
               FROM study_sessions
              WHERE user_id = :user_id
                AND ended_at IS NOT NULL
                AND ended_at >= DATE_SUB(CURDATE(), INTERVAL ' . (STATISTICS_HISTORY_DAYS - 1) . ' DAY)
              GROUP BY DATE(ended_at)'
        );
        $dayStatement->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $dayStatement->execute();
    } catch (Throwable $error) {
        /* No table, no rows, no permission - for this page all three mean the
           same thing: there is nothing to show yet. */
        return $empty;
    }

    $studied = [];

    foreach ($dayStatement->fetchAll() as $row) {
        $studied[(string) $row['day']] = (int) $row['amount'];
    }

    /* The list always has every day of the window, so the chart has seven bars
       and no gaps - a missing day is a day without cards, not a missing column. */
    $days = [];
    $today = new DateTimeImmutable('today');

    for ($back = STATISTICS_HISTORY_DAYS - 1; $back >= 0; $back--) {
        $date = $today->modify('-' . $back . ' days');
        $key = $date->format('Y-m-d');
        $days[] = ['day' => $key, 'cards' => $studied[$key] ?? 0];
    }

    return ['available' => true, 'days' => $days];
}

/**
 * One row per direct subcategory of an area: name, card count and how much of it
 * is known.
 *
 * Every row counts its whole subtree, so the rows add up to the numbers in the
 * head of the area. A card in a sub-subcategory belongs to the row of the
 * subcategory it hangs under, not to a row of its own.
 *
 * @return array<int, array<string, mixed>>
 */
function statistics_children(PDO $pdo, array $tree, int $areaId, int $userId): array
{
    $childIds = $tree[$areaId] ?? [];

    if ($childIds === []) {
        return [];
    }

    $rows = [];
    $names = [];

    [$placeholders, $bindings] = statistics_id_placeholders($childIds);

    $nameStatement = $pdo->prepare(
        'SELECT id, name, name_en, name_de FROM categories WHERE id IN (' . $placeholders . ')'
    );
    statistics_bind_ids($nameStatement, $bindings);
    $nameStatement->execute();

    foreach ($nameStatement->fetchAll() as $row) {
        $names[(int) $row['id']] = $row;
    }

    foreach ($childIds as $childId) {
        $ids = statistics_subtree_ids($tree, $childId);

        if ($ids === []) {
            continue;
        }

        $counts = statistics_card_counts($pdo, $ids, $userId);
        $name = $names[$childId] ?? null;

        $rows[] = [
            'id' => $childId,
            'name' => $name === null ? '' : (string) $name['name'],
            'name_en' => $name === null ? null : $name['name_en'],
            'name_de' => $name === null ? null : $name['name_de'],
            'cards' => $counts['total'],
            'known' => $counts['known'],
            'known_percent' => $counts['known_percent'],
        ];
    }

    return $rows;
}

/**
 * Everything the statistics view shows about one category.
 *
 * The scope is decided here, on the server: a category without a parent is a
 * learning area and is counted with all of its subcategories, every other
 * category is counted as itself. The browser never decides that, so a hand
 * written request cannot ask for a different meaning of the same id.
 *
 * @return array<string, mixed>|null null when the category does not exist
 */
function statistics_for_category(PDO $pdo, int $categoryId, int $userId): ?array
{
    $category = find_category($pdo, $categoryId);

    if ($category === null) {
        return null;
    }

    $isArea = $category['parent_id'] === null;
    $ids = category_subtree_ids($pdo, $categoryId);

    return [
        'scope' => [
            'id' => (int) $category['id'],
            'kind' => $isArea ? 'area' : 'category',
            'parent_id' => $category['parent_id'] === null ? null : (int) $category['parent_id'],
            'name' => (string) $category['name'],
            'name_en' => $category['name_en'] ?? null,
            'name_de' => $category['name_de'] ?? null,
        ],
        'cards' => statistics_card_counts($pdo, $ids, $userId),
        'due' => statistics_due_buckets($pdo, $ids, $userId),
        /* The two blocks that need study_sessions. Strang D writes that table;
           until it has rows, both say "nothing yet" and the view stays honest. */
        'today' => statistics_today($pdo, $userId),
        'history' => statistics_history($pdo, $userId),
        'children' => $isArea ? statistics_children($pdo, statistics_category_tree($pdo), $categoryId, $userId) : [],
    ];
}

/**
 * How many cards were studied today.
 *
 * Same rule as the history: without a row in study_sessions the answer is "not
 * available" - the view then writes that instead of a zero that would look like
 * a fact. As soon as Strang D writes rows, this block fills itself.
 *
 * @return array{available: bool, cards: int|null}
 */
function statistics_today(PDO $pdo, int $userId): array
{
    $nothing = ['available' => false, 'cards' => null];

    try {
        $countStatement = $pdo->prepare('SELECT COUNT(*) FROM study_sessions WHERE user_id = :user_id');
        $countStatement->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $countStatement->execute();

        if ((int) $countStatement->fetchColumn() === 0) {
            return $nothing;
        }

        $statement = $pdo->prepare(
            'SELECT SUM(cards_studied) AS amount
               FROM study_sessions
              WHERE user_id = :user_id
                AND ended_at IS NOT NULL
                AND ended_at >= CURDATE()'
        );
        $statement->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $statement->execute();

        $row = $statement->fetch();

        return [
            'available' => true,
            'cards' => $row === false || $row['amount'] === null ? 0 : (int) $row['amount'],
        ];
    } catch (Throwable $error) {
        /* No table, no rows, no permission - for this page all three mean the
           same thing: there is nothing to show yet. */
        return $nothing;
    }
}
