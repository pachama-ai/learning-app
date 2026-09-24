<?php

declare(strict_types=1);

/**
 * GET /api/bootstrap.php -> everything the interface needs for its first view
 *
 * One answer instead of one request per view: the learning areas, their
 * subcategories and the cards of every subcategory, each card with the status it
 * has for the signed-in user. The browser keeps this answer in memory and renders
 * the other views out of it, so walking through the app costs no further request.
 *
 * What is NOT part of this answer, on purpose:
 *
 *   - the generated task of an exercise card. Its numbers are drawn when the card
 *     is read, so caching them would show the same numbers again and again. The
 *     answer carries the kind of task and its numbers, and the browser asks for a
 *     fresh task when it really displays such a card (api/exercise_preview.php).
 *   - the drawings of the categories and the maps. They have their own fetch and
 *     their own cache in the browser and are served by api/category_icon.php and
 *     the files in public/assets/maps.
 *
 * The answer is read-only: it starts no transaction, changes nothing and creates
 * no progress row. Without a signed-in user every card is "new", which is the
 * truth - without a user id there can be no progress.
 *
 * The response is intentionally one flat object:
 *   {
 *     "areas":       [ ... the same shape as GET /api/categories.php ... ],
 *     "children":    { "2": [ ... subcategories of 2 ... ], ... },
 *     "cards":       { "85": [ ... cards of 85, each with "progress" ... ], ... },
 *     "summaries":   { "85": { "total": 15, "due": 15, ... }, ... },
 *     "has_user":    true|false,
 *     "content_languages": ["de"],
 *     "language":    "de"
 *   }
 */

require_once __DIR__ . '/../../src/config/database.php';
require_once __DIR__ . '/../../src/helpers/json_response.php';
require_once __DIR__ . '/../../src/helpers/request_input.php';
require_once __DIR__ . '/../../src/helpers/session_user.php';
require_once __DIR__ . '/../../src/services/category_service.php';
require_once __DIR__ . '/../../src/services/card_service.php';
require_once __DIR__ . '/../../src/services/review_service.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method !== 'GET') {
    send_json_error('method_not_allowed', 'Only GET requests are allowed.', 405);
}

/* The interface says which of its two languages it is showing. */
$language = optional_query_language();

try {
    $pdo = create_database_connection();
    $userId = current_user_id($pdo);
    $columns = card_columns($pdo);

    $areas = find_main_categories($pdo);

    /*
     * The subcategories of every area, and - should there ever be a third level -
     * the subcategories of a subcategory as well. The walk only asks for the
     * children of categories that really have some, so a two-level tree costs one
     * query per area and nothing more.
     */
    $children = [];
    $level = array_map(static fn (array $area): int => (int) $area['id'], $areas);

    while ($level !== []) {
        $withChildren = category_ids_with_children($pdo, $level);
        $next = [];

        foreach ($withChildren as $parentId) {
            $list = find_subcategories($pdo, $parentId);
            $children[(string) $parentId] = $list;

            foreach ($list as $child) {
                $next[] = (int) $child['id'];
            }
        }

        $level = $next;
    }

    /* Every card of every category in one read, grouped by category. */
    $all = review_cards_all_categories($pdo, $userId, $language);

    /*
     * The task of an exercise card is left out: it is drawn when the card is
     * displayed, so a cached task would freeze numbers that must change.
     */
    foreach ($all['cards'] as $categoryId => $list) {
        foreach ($list as $index => $card) {
            if (isset($card['exercise']['task'])) {
                unset($all['cards'][$categoryId][$index]['exercise']['task']);
            }
        }
    }

    send_json_success([
        'areas' => $areas,
        'children' => $children,
        'cards' => $all['cards'],
        'summaries' => $all['summaries'],
        'has_user' => $userId !== null,
        'content_languages' => card_content_languages($columns),
        'language' => $language,
    ]);
} catch (Throwable $error) {
    /* The reason goes to the server log; the browser gets a generic message. */
    error_log('Building the bootstrap failed: ' . $error->getMessage());

    send_json_error('bootstrap_unavailable', 'The application could not be loaded.', 500);
}
