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

        /* At least one language has to be complete: a question AND an answer. */
        $complete = false;

        foreach ($languages as $language) {
            if (card_language_is_complete($texts, $language, $columns)) {
                $complete = true;
            }
        }

        if (!$complete) {
            send_json_error('invalid_card_text', 'Fill in a question and an answer in at least one language.', 400);
        }

        $card = create_card_translated($pdo, $categoryId, $texts, $columns, $isBidirectional);

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
