<?php

declare(strict_types=1);

/**
 * The learning session of one subcategory.
 *
 * GET  /api/review.php?category_id=50            -> the queue of the session
 * GET  /api/review.php?category_id=50&mode=difficult
 *                                                -> only the cards that were
 *                                                   answered "Again" or "Hard"
 * POST /api/review.php   {"action":"rate", ...}  -> store one rating
 * POST /api/review.php   {"action":"undo", ...}  -> take the last rating back
 *
 * The queue is built here and not in the browser: which card comes first, and
 * what its status is, are decisions of the server. The browser may show them and
 * nothing else.
 *
 * Every write needs a signed-in user. Without one there is no id to store the
 * progress under, and the answer says exactly that instead of inventing one.
 */

require_once __DIR__ . '/../../src/config/database.php';
require_once __DIR__ . '/../../src/helpers/json_response.php';
require_once __DIR__ . '/../../src/helpers/request_input.php';
require_once __DIR__ . '/../../src/helpers/session_user.php';
require_once __DIR__ . '/../../src/services/card_service.php';
require_once __DIR__ . '/../../src/services/category_service.php';
require_once __DIR__ . '/../../src/services/review_service.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method !== 'GET' && $method !== 'POST') {
    send_json_error('method_not_allowed', 'Only GET and POST requests are allowed.', 405);
}

/** The mode names this endpoint accepts. */
const REVIEW_MODES = ['all', 'difficult'];

/* -------------------------------------------------------------------------
   POST: one rating, or taking one back
   ------------------------------------------------------------------------- */

if ($method === 'POST') {
    $body = read_json_object();
    $action = isset($body['action']) && is_string($body['action']) ? $body['action'] : 'rate';

    if ($action !== 'rate' && $action !== 'undo') {
        send_json_error('invalid_action', 'The action must be "rate" or "undo".', 400);
    }

    $categoryId = optional_positive_id($body, 'category_id', 'invalid_category_id');
    $cardId = optional_positive_id($body, 'card_id', 'invalid_card_id');

    if ($categoryId === null) {
        send_json_error('invalid_category_id', 'The field "category_id" must be a positive whole number.', 400);
    }

    if ($cardId === null) {
        send_json_error('invalid_card_id', 'The field "card_id" must be a positive whole number.', 400);
    }

    $rating = 0;

    if ($action === 'rate') {
        $rawRating = $body['rating'] ?? null;

        if (!is_int($rawRating) && !(is_string($rawRating) && ctype_digit($rawRating))) {
            send_json_error('invalid_rating', 'The rating must be 1, 2, 3 or 4.', 400);
        }

        $rating = (int) $rawRating;
    }

    try {
        $pdo = create_database_connection();

        /* Nobody signed in: the progress cannot be stored, and saying so is the
           only honest answer. No default user is used. */
        $userId = current_user_id($pdo);

        if ($userId === null) {
            $required = session_user_required_error();

            send_json_error($required['code'], $required['message'], $required['status']);
        }

        if (!category_exists($pdo, $categoryId)) {
            send_json_error('category_not_found', 'This category does not exist.', 404);
        }

        if ($action === 'undo') {
            $stored = isset($body['stored']) && is_array($body['stored']) ? $body['stored'] : null;
            $previous = isset($body['previous']) && is_array($body['previous']) ? $body['previous'] : null;

            $result = review_undo_rating($pdo, $userId, $cardId, $stored, $previous, $categoryId);

            if (!$result['ok']) {
                send_json_error($result['code'], $result['message'], 409);
            }

            send_json_success($result['data']);
        }

        $result = review_rate_card($pdo, $userId, $cardId, $rating, $categoryId);

        if (!$result['ok']) {
            $status = $result['code'] === 'card_not_found' ? 404 : 400;

            send_json_error($result['code'], $result['message'], $status);
        }

        send_json_success($result['data']);
    } catch (Throwable $error) {
        /* The reason stays in the server log. It can hold SQL and connection
           details, which the browser must never see. */
        error_log('Rating a card failed: ' . $error->getMessage());

        send_json_error('review_failed', 'This answer could not be saved.', 500);
    }
}

/* -------------------------------------------------------------------------
   GET: the queue of the session
   ------------------------------------------------------------------------- */

$categoryId = require_query_id('category_id', 'invalid_category_id');
$mode = 'all';
$rawMode = $_GET['mode'] ?? null;

if (is_string($rawMode) && $rawMode !== '') {
    if (!in_array($rawMode, REVIEW_MODES, true)) {
        send_json_error('invalid_mode', 'The mode must be "all" or "difficult".', 400);
    }

    $mode = $rawMode;
}

try {
    $pdo = create_database_connection();

    if (!category_exists($pdo, $categoryId)) {
        send_json_error('category_not_found', 'This category does not exist.', 404);
    }

    $userId = current_user_id($pdo);

    /*
     * The session belongs to the entry that was opened. A subcategory brings its
     * own cards; a learning area brings the cards of its subcategories as well,
     * which is what makes "Study all" possible without touching the repetition
     * logic - the queue and the scheduler see exactly the same cards as before.
     */
    $branchIds = review_branch_category_ids($pdo, $categoryId);
    $cards = review_cards_in_categories($pdo, $branchIds, $userId, optional_query_language());
    $summary = review_summarise_cards($cards);

    $cardsForQueue = [];
    $statuses = [];
    $isDue = [];

    foreach ($cards as $card) {
        $cardId = (int) $card['id'];
        $statuses[$cardId] = (string) $card['progress']['status'];
        $isDue[$cardId] = (bool) $card['progress']['is_due'];
        $cardsForQueue[] = $card;
    }

    $built = review_build_queue($cardsForQueue, $statuses, $isDue, $mode);

    /*
     * Every entry of the queue carries what each of the four answers would do to
     * its card. The numbers come from the scheduler in the service, so the button
     * under the card promises exactly what the rating will then store.
     */
    $progressByCard = [];

    foreach ($cards as $card) {
        $progressByCard[(int) $card['id']] = $card['progress'];
    }

    $queue = [];

    foreach ($built['queue'] as $entry) {
        $entry['preview_minutes'] = review_interval_previews($progressByCard[(int) $entry['card_id']] ?? null);
        $queue[] = $entry;
    }

    send_json_success([
        'category_id' => $categoryId,
        'mode' => $mode,
        /* false tells the browser that an answer cannot be stored yet and why. */
        'has_user' => $userId !== null,
        'summary' => $summary,
        'counts' => $built['counts'],
        'queue' => $queue,
        'content_languages' => card_content_languages(card_columns($pdo)),
    ]);
} catch (Throwable $error) {
    error_log('Loading a learning session failed: ' . $error->getMessage());

    send_json_error('review_unavailable', 'The learning session could not be loaded.', 500);
}
