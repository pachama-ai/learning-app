<?php

declare(strict_types=1);

/**
 * PATCH  /api/card.php?id=4 -> changes the fields that are sent
 * DELETE /api/card.php?id=4 -> removes one flashcard
 *
 * PATCH body (only the fields that should change):
 *   {"front": "...", "back": "...", "is_bidirectional": true}
 *
 * A single card is not a tree, so deleting it needs no name confirmation: the
 * dialog asks once and then calls this endpoint. The learning progress of the
 * card is removed by the database itself (fk_progress_card is ON DELETE
 * CASCADE); that behaviour is part of the existing structure and was not
 * changed.
 */

require_once __DIR__ . '/../../src/config/database.php';
require_once __DIR__ . '/../../src/helpers/json_response.php';
require_once __DIR__ . '/../../src/helpers/request_input.php';
require_once __DIR__ . '/../../src/services/card_service.php';

$method = $_SERVER['REQUEST_METHOD'] ?? '';

if ($method !== 'PATCH' && $method !== 'DELETE') {
    send_json_error('method_not_allowed', 'Only PATCH and DELETE requests are allowed.', 405);
}

$cardId = require_query_id('id');
/*
 * A DELETE needs no field at all, so an empty request is fine there; a
 * PATCH without a body has nothing to change and is refused.
 */
$body = read_json_object($method === 'DELETE');

try {
    $pdo = create_database_connection();

    $current = find_card($pdo, $cardId);

    if ($current === null) {
        send_json_error('card_not_found', 'This card does not exist.', 404);
    }

    if ($method === 'DELETE') {
        delete_card($pdo, $cardId);

        send_json_success(['deleted' => true, 'id' => $cardId]);
    }

    $changes = [];

    if (array_key_exists('front', $body)) {
        $changes['front'] = require_input_text($body, 'front', CARD_MAX_TEXT_LENGTH, 'invalid_front');
    }

    if (array_key_exists('back', $body)) {
        $changes['back'] = require_input_text($body, 'back', CARD_MAX_TEXT_LENGTH, 'invalid_back');
    }

    if (array_key_exists('is_bidirectional', $body)) {
        $changes['is_bidirectional'] = optional_flag($body, 'is_bidirectional') ?? false;
    }

    if ($changes === []) {
        send_json_error('invalid_request_body', 'Send at least one field to change.', 400);
    }

    $updated = update_card($pdo, $cardId, $changes);

    send_json_success($updated);
} catch (Throwable $error) {
    error_log('Changing a card failed: ' . $error->getMessage());

    send_json_error('card_update_failed', 'The card could not be saved.', 500);
}
