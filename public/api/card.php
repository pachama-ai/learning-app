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
require_once __DIR__ . '/../../src/helpers/session_user.php';
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
    $userId = current_user_id($pdo);

    /* A card belongs to a category, and a category belongs to an account.
       Changing or deleting one therefore needs that account. */
    if ($userId === null) {
        $required = session_user_required_error();

        send_json_error($required['code'], $required['message'], $required['status']);
    }

    $current = find_card($pdo, $cardId, $userId);

    if ($current === null) {
        send_json_error('card_not_found', 'This card does not exist.', 404);
    }

    if ($method === 'DELETE') {
        delete_card($pdo, $cardId, $userId);

        send_json_success(['deleted' => true, 'id' => $cardId]);
    }

    $columns = card_columns($pdo);
    $languages = card_content_languages($columns);
    $changes = [];

    /*
     * The text of every language the table holds. German lives in the two
     * original columns, English in the two that the migration adds. A language
     * that the table does not have is refused instead of being dropped without a
     * word.
     */
    foreach (card_language_columns($columns) as $pair) {
        foreach ($pair as $column) {
            if (!array_key_exists($column, $body)) {
                continue;
            }

            $changes[$column] = (string) optional_input_text($body, $column, CARD_MAX_TEXT_LENGTH, 'invalid_' . $column);
        }
    }

    foreach (['front_en', 'back_en'] as $englishColumn) {
        if (array_key_exists($englishColumn, $body) && !in_array('en', $languages, true)) {
            send_json_error('card_language_unavailable', 'This table has no English columns yet.', 400);
        }
    }

    foreach (['front', 'back'] as $germanColumn) {
        if (array_key_exists($germanColumn, $body) && !array_key_exists($germanColumn, $changes)) {
            $changes[$germanColumn] = (string) optional_input_text($body, $germanColumn, CARD_MAX_TEXT_LENGTH, 'invalid_' . $germanColumn);
        }
    }

    /*
     * The exercise comes first: it decides whether this card needs a question and
     * an answer or only a title. A body without "exercise_type" at all leaves the
     * exercise as it is; an empty or null value takes it away.
     */
    if (array_key_exists('exercise_type', $body)) {
        $exerciseRequest = card_exercise_from_request($body);

        if ($exerciseRequest['error'] === 'invalid_exercise_type') {
            send_json_error('invalid_exercise_type', 'This kind of task does not exist.', 400);
        }

        if ($exerciseRequest['error'] === 'invalid_exercise_params') {
            send_json_error('invalid_exercise_params', 'The numbers do not fit this kind of task.', 400);
        }

        if ($exerciseRequest['exercise'] !== null
            && (!card_exercise_table_available($pdo) || !card_exercise_params_available($pdo))) {
            send_json_error(
                'exercise_unavailable',
                'Exercise cards need the migration database/add_exercise_params.sql first.',
                400
            );
        }

        $changes['exercise'] = $exerciseRequest['exercise'];
    }

    /*
     * At least one language has to be complete afterwards. The card as it would
     * be is the change on top of what is stored now.
     */
    $languageColumns = [];

    foreach (card_language_columns($columns) as $pair) {
        foreach ($pair as $column) {
            $languageColumns[] = $column;
        }
    }

    /*
     * An exercise card keeps its exercise unless the change takes it away, so the
     * rule to apply is the one of the card as it will be.
     */
    $keepsExercise = array_key_exists('exercise', $changes)
        ? $changes['exercise'] !== null
        : ($current['exercise'] ?? null) !== null;

    if (array_intersect(array_keys($changes), $languageColumns) !== []) {
        $after = array_merge($current, $changes);
        $complete = false;

        foreach ($languages as $language) {
            $filled = $keepsExercise
                ? card_language_has_question($after, $language, $columns)
                : card_language_is_complete($after, $language, $columns);

            if ($filled) {
                $complete = true;
            }
        }

        if (!$complete) {
            send_json_error(
                'invalid_card_text',
                $keepsExercise
                    ? 'Give the exercise a title in at least one language.'
                    : 'Fill in a question and an answer in at least one language.',
                400
            );
        }
    }

    /*
     * The map region can be set, replaced or taken away again. An empty value is
     * "no map" and is stored as NULL; a value that does not match the pattern is
     * refused instead of being stored and ignored later.
     */
    if (array_key_exists('map_region', $body)) {
        $mapRegion = $body['map_region'] === null ? '' : trim((string) $body['map_region']);

        if ($mapRegion !== '' && !card_map_region_is_valid($mapRegion)) {
            send_json_error('invalid_map_region', 'The map region must look like "DE:Bayern", "EU:FR" or "WORLD:CN".', 400);
        }

        $changes['map_region'] = $mapRegion === '' ? null : $mapRegion;
    }

    if (array_key_exists('is_bidirectional', $body)) {
        $changes['is_bidirectional'] = optional_flag($body, 'is_bidirectional') ?? false;
    }

    if ($changes === []) {
        send_json_error('invalid_request_body', 'Send at least one field to change.', 400);
    }

    $updated = update_card($pdo, $cardId, $changes, $userId);

    send_json_success($updated);
} catch (Throwable $error) {
    error_log('Changing a card failed: ' . $error->getMessage());

    send_json_error('card_update_failed', 'The card could not be saved.', 500);
}
