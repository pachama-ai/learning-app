<?php

declare(strict_types=1);

/**
 * PATCH  /api/card.php?id=4 -> ändert die Felder, die mitgeschickt werden
 * DELETE /api/card.php?id=4 -> entfernt eine Lernkarte
 *
 * Inhalt des PATCH (nur die Felder, die sich ändern sollen):
 *   {"front": "...", "back": "...", "is_bidirectional": true}
 *
 * Eine einzelne Karte ist kein Baum, beim Löschen braucht es deshalb keine
 * Bestätigung des Namens: der Dialog fragt einmal und ruft dann diesen Endpunkt.
 * Der Lernfortschritt der Karte wird von der Datenbank selbst mitgelöscht
 * (fk_progress_card ist ON DELETE CASCADE); das gehört zur bestehenden Struktur und
 * wurde nicht geändert.
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
 * Ein DELETE braucht kein Feld, dort ist eine leere Anfrage in Ordnung; ein PATCH
 * ohne Inhalt hat nichts zu ändern und wird abgelehnt.
 */
$body = read_json_object($method === 'DELETE');

try {
    $pdo = create_database_connection();
    $userId = current_user_id($pdo);

    /* Eine Karte gehört zu einer Kategorie, und eine Kategorie gehört einem Konto.
       Ändern oder löschen braucht deshalb dieses Konto. */
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
     * Der Text jeder Sprache, die die Tabelle hat. Deutsch steht in den beiden
     * ursprünglichen Spalten, Englisch in den beiden, die die Migration hinzufügt.
     * Eine Sprache, die die Tabelle nicht hat, wird abgelehnt statt wortlos
     * fallengelassen.
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
     * Die Aufgabe kommt zuerst: sie entscheidet, ob diese Karte eine Frage und eine
     * Antwort braucht oder nur eine Überschrift. Ein Inhalt ganz ohne
     * "exercise_type" lässt die Aufgabe, wie sie ist; ein leerer oder null-Wert
     * nimmt sie weg.
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
                'Exercise cards need the table from database/schema.sql first.',
                400
            );
        }

        $changes['exercise'] = $exerciseRequest['exercise'];
    }

    /*
     * Am Ende muss mindestens eine Sprache vollständig sein. Die Karte, wie sie
     * wäre, ist die Änderung oben auf dem, was jetzt gespeichert ist.
     */
    $languageColumns = [];

    foreach (card_language_columns($columns) as $pair) {
        foreach ($pair as $column) {
            $languageColumns[] = $column;
        }
    }

    /*
     * Eine Übungskarte behält ihre Aufgabe, solange die Änderung sie nicht wegnimmt;
     * geprüft wird also nach der Regel für die Karte, wie sie danach aussieht.
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
     * Die Kartenregion kann gesetzt, ersetzt oder wieder weggenommen werden. Ein
     * leerer Wert heißt "keine Karte"und wird als NULL gespeichert; ein Wert, der
     * nicht zum Muster passt, wird abgelehnt statt gespeichert und später ignoriert.
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
