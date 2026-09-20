<?php

declare(strict_types=1);

/**
 * Database queries for the numbers shown on the start page.
 *
 * Every value here is counted from real rows. Nothing is estimated, sampled or
 * invented, and when a value cannot be known it is reported as null so the
 * frontend can show a dash instead of a made-up number.
 */

/**
 * Returns the four start-page statistics.
 *
 * learning_areas - how many top-level categories exist
 * subcategories  - how many categories have a parent
 * total_cards    - how many cards exist, or null when there are none
 * learned_percent- always null for now
 *
 * Why learned_percent is null: "learned" is not defined anywhere in this
 * application yet, and there is no logged-in user, so there is no reliable
 * current-user context to calculate it from. An average over all users would be
 * meaningless here, and a fixed user id would be wrong. The frontend therefore
 * shows a dash, exactly as it does for an empty cards table.
 *
 * @return array{learning_areas: int, subcategories: int, total_cards: int|null, learned_percent: null}
 */
function get_overview_stats(PDO $pdo): array
{
    $statement = $pdo->prepare(
        'SELECT
            (SELECT COUNT(*) FROM categories WHERE parent_id IS NULL) AS learning_areas,
            (SELECT COUNT(*) FROM categories WHERE parent_id IS NOT NULL) AS subcategories,
            (SELECT COUNT(*) FROM cards) AS total_cards'
    );
    $statement->execute();

    $row = $statement->fetch();

    $totalCards = (int) $row['total_cards'];

    return [
        'learning_areas' => (int) $row['learning_areas'],
        'subcategories' => (int) $row['subcategories'],
        // An empty cards table means "unknown", not "zero cards learned from",
        // so it is reported as null.
        'total_cards' => $totalCards === 0 ? null : $totalCards,
        'learned_percent' => null,
    ];
}
