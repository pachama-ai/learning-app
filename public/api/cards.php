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
require_once __DIR__ . '/../../src/services/category_service.php';
require_once __DIR__ . '/../../src/services/card_service.php';

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

    $front = require_input_text($body, 'front', CARD_MAX_TEXT_LENGTH, 'invalid_front');
    $back = require_input_text($body, 'back', CARD_MAX_TEXT_LENGTH, 'invalid_back');
    $isBidirectional = optional_flag($body, 'is_bidirectional') ?? false;

    try {
        $pdo = create_database_connection();

        if (!category_exists($pdo, $categoryId)) {
            send_json_error('category_not_found', 'This category does not exist.', 404);
        }

        $card = create_card($pdo, $categoryId, $front, $back, $isBidirectional);

        send_json_success($card, 201);
    } catch (Throwable $error) {
        error_log('Creating a card failed: ' . $error->getMessage());

        send_json_error('card_create_failed', 'The card could not be saved.', 500);
    }
}

/* --------------------------------------------------------------------- GET */

$categoryId = require_query_id('category_id', 'invalid_category_id');

try {
    $pdo = create_database_connection();

    if (!category_exists($pdo, $categoryId)) {
        send_json_error('category_not_found', 'This category does not exist.', 404);
    }

    // An empty list is a valid answer and lets the page show its empty state.
    send_json_success(find_cards($pdo, $categoryId));
} catch (Throwable $error) {
    error_log('Loading cards failed: ' . $error->getMessage());

    send_json_error('cards_unavailable', 'The cards could not be loaded.', 500);
}
