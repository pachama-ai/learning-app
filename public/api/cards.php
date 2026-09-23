<?php

declare(strict_types=1);

/**
 * GET  /api/cards.php?category_id=7 -> the flashcards of one category
 * POST /api/cards.php               -> creates a flashcard in that category
 *
 * Body of the POST:
 *   {
 *     "category_id": 7,
 *     "front": "What is 2 + 2?",
 *     "back": "4",
 *     "is_bidirectional": false
 *   }
 *
 * A card belongs to exactly one category through cards.category_id. Cards are
 * normally created inside a subcategory, which is the level this app offers for
 * them, but the endpoint accepts any category that exists.
 *
 * Studying and repeating cards (the spaced repetition) is NOT part of this
 * endpoint: it only stores and reads the card itself.
 */

require_once __DIR__ . '/../../src/config/database.php';
require_once __DIR__ . '/../../src/helpers/json_response.php';
require_once __DIR__ . '/../../src/helpers/request_input.php';
require_once __DIR__ . '/../../src/helpers/session_user.php';
require_once __DIR__ . '/../../src/services/category_service.php';
require_once __DIR__ . '/../../src/services/card_service.php';
require_once __DIR__ . '/../../src/services/review_service.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method !== 'GET' && $method !== 'POST') {
    send_json_error('method_not_allowed', 'Only GET and POST requests are allowed.', 405);
}

/* -------------------------------------------------------------------- POST */

if ($method === 'POST') {
    $body = read_json_object();

    $categoryId = optional_positive_id($body, 'category_id', 'invalid_category_id');

    if ($categoryId === null) {
        send_json_error('invalid_category_id', 'The field "category_id" must be a positive whole number.', 400);
    }

    $isBidirectional = optional_flag($body, 'is_bidirectional') ?? false;

    try {
        $pdo = create_database_connection();

        if (!category_exists($pdo, $categoryId)) {
            send_json_error('category_not_found', 'This category does not exist.', 404);
        }

        /*
         * The text of every language the table can hold. German lives in the two
         * original columns, English in the two the migration adds. A language the
         * table does not have is refused instead of being dropped without a word.
         */
        $columns = card_columns($pdo);
        $languages = card_content_languages($columns);
        $texts = card_texts_from_body($body, $columns);

        foreach (['front_en', 'back_en'] as $englishColumn) {
            if (array_key_exists($englishColumn, $body) && !in_array('en', $languages, true)) {
                send_json_error('card_language_unavailable', 'This table has no English columns yet.', 400);
            }
        }

        /*
         * The exercise is read before the text is judged, because it decides which
         * rule applies: a fixed card needs a question and an answer, an exercise
         * card only needs a title - its answer comes from the generator.
         */
        $exerciseRequest = card_exercise_from_request($body);

        if ($exerciseRequest['error'] === 'invalid_exercise_type') {
            send_json_error('invalid_exercise_type', 'This kind of task does not exist.', 400);
        }

        if ($exerciseRequest['error'] === 'invalid_exercise_range') {
            send_json_error('invalid_exercise_range', 'The number range does not fit this kind of task.', 400);
        }

        $exercise = $exerciseRequest['exercise'];

        if ($exercise !== null && !card_exercise_table_available($pdo)) {
            send_json_error('exercise_unavailable', 'This installation has no table for exercise cards yet.', 400);
        }

        $complete = false;

        foreach ($languages as $language) {
            $filled = $exercise === null
                ? card_language_is_complete($texts, $language, $columns)
                : card_language_has_question($texts, $language, $columns);

            if ($filled) {
                $complete = true;
            }
        }

        if (!$complete) {
            send_json_error(
                'invalid_card_text',
                $exercise === null
                    ? 'Fill in a question and an answer in at least one language.'
                    : 'Give the exercise a title in at least one language.',
                400
            );
        }

        /* The map region is optional and may be missing, null or empty. */
        $mapRegion = optional_input_text($body, 'map_region', CARD_MAP_REGION_MAX_LENGTH, 'invalid_map_region');

        if ($mapRegion !== null && !card_map_region_is_valid($mapRegion)) {
            send_json_error('invalid_map_region', 'The map region must look like "DE:Bayern", "EU:FR" or "WORLD:CN".', 400);
        }

        $card = create_card_translated($pdo, $categoryId, $texts, $columns, $isBidirectional, $mapRegion, $exercise);

        send_json_success($card, 201);
    } catch (Throwable $error) {
        error_log('Creating a card failed: ' . $error->getMessage());

        send_json_error('card_create_failed', 'The card could not be saved.', 500);
    }
}

/* --------------------------------------------------------------------- GET */

$categoryId = require_query_id('category_id', 'invalid_category_id');

/* The interface says which of its two languages it is showing. */
$language = optional_query_language();

try {
    $pdo = create_database_connection();

    if (!category_exists($pdo, $categoryId)) {
        send_json_error('category_not_found', 'This category does not exist.', 404);
    }

    /*
     * Every card carries the status it has for the signed-in user. Without a
     * signed-in user there is no progress to read and every card is "new",
     * which is the truth: without a user id no progress row can exist.
     */
    $userId = current_user_id($pdo);
    $cards = review_cards_with_progress($pdo, $categoryId, $userId, $language);

    // An empty list is a valid answer and lets the page show its empty state.
    send_json_success([
        'cards' => $cards,
        'summary' => review_summarise_cards($cards),
        'has_user' => $userId !== null,
        /* Which languages this table can hold: one, or two after the
           migration. The card dialog shows its language tabs only for two. */
        'content_languages' => card_content_languages(card_columns($pdo)),
        'language' => $language,
    ]);
} catch (Throwable $error) {
    error_log('Loading cards failed: ' . $error->getMessage());

    send_json_error('cards_unavailable', 'The cards could not be loaded.', 500);
}
