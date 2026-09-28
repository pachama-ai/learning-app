<?php

declare(strict_types=1);

/**
 * GET  /api/cards.php?category_id=7 -> die Lernkarten einer Kategorie
 * POST /api/cards.php               -> legt eine Lernkarte in dieser Kategorie an
 *
 * Inhalt des POST:
 *   {
 *     "category_id": 7,
 *     "front": "Was ist 2 + 2?",
 *     "back": "4",
 *     "is_bidirectional": false
 *   }
 *
 * Eine Karte gehört über cards.category_id zu genau einer Kategorie. Angeboten
 * werden Karten normalerweise in einer Unterkategorie, das ist die Ebene, die diese
 * Anwendung dafür vorsieht; der Endpunkt nimmt aber jede Kategorie an, die es gibt.
 *
 * Lernen und Wiederholen (die Abstände) gehören NICHT zu diesem Endpunkt: er legt die
 * Karte nur an und liest sie wieder.
 */

require_once __DIR__ . '/../../src/config/database.php';
require_once __DIR__ . '/../../src/helpers/json_response.php';
require_once __DIR__ . '/../../src/helpers/request_input.php';
require_once __DIR__ . '/../../src/helpers/session_user.php';
require_once __DIR__ . '/../../src/services/category_service.php';
require_once __DIR__ . '/../../src/services/card_service.php';
require_once __DIR__ . '/../../src/services/dashboard_service.php';
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
        $userId = current_user_id($pdo);

        /* Eine Karte gehört zu einer Kategorie, und eine Kategorie gehört einem Konto. */
        if ($userId === null) {
            $required = session_user_required_error();

            send_json_error($required['code'], $required['message'], $required['status']);
        }

        if (!category_exists($pdo, $categoryId, $userId)) {
            send_json_error('category_not_found', 'This category does not exist.', 404);
        }

        /*
         * Der Text jeder Sprache, die die Tabelle haben kann. Deutsch steht in den
         * beiden ursprünglichen Spalten, Englisch in den beiden, die die Migration
         * hinzufügt. Eine Sprache, die die Tabelle nicht hat, wird abgelehnt statt
         * wortlos fallengelassen.
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
         * Die Aufgabe wird gelesen, bevor der Text beurteilt wird, denn sie entscheidet,
         * welche Regel gilt: eine feste Karte braucht Frage und Antwort, eine
         * Übungskarte nur eine Überschrift - ihre Antwort kommt aus dem Erzeuger.
         */
        $exerciseRequest = card_exercise_from_request($body);

        if ($exerciseRequest['error'] === 'invalid_exercise_type') {
            send_json_error('invalid_exercise_type', 'This kind of task does not exist.', 400);
        }

        if ($exerciseRequest['error'] === 'invalid_exercise_params') {
            send_json_error('invalid_exercise_params', 'The numbers do not fit this kind of task.', 400);
        }

        $exercise = $exerciseRequest['exercise'];

        if ($exercise !== null && (!card_exercise_table_available($pdo) || !card_exercise_params_available($pdo))) {
            send_json_error(
                'exercise_unavailable',
                'Exercise cards need the migration database/add_exercise_params.sql first.',
                400
            );
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

        /* Die Kartenregion ist freiwillig und darf fehlen, null oder leer sein. */
        $mapRegion = optional_input_text($body, 'map_region', CARD_MAP_REGION_MAX_LENGTH, 'invalid_map_region');

        if ($mapRegion !== null && !card_map_region_is_valid($mapRegion)) {
            send_json_error('invalid_map_region', 'The map region must look like "DE:Bayern", "EU:FR" or "WORLD:CN".', 400);
        }

        $card = create_card_translated($pdo, $categoryId, $texts, $columns, $isBidirectional, $userId, $mapRegion, $exercise);

        send_json_success($card, 201);
    } catch (Throwable $error) {
        error_log('Creating a card failed: ' . $error->getMessage());

        send_json_error('card_create_failed', 'The card could not be saved.', 500);
    }
}

/* --------------------------------------------------------------------- GET */

$categoryId = require_query_id('category_id', 'invalid_category_id');

/* Die Oberfläche sagt, welche ihrer beiden Sprachen sie gerade zeigt. */
$language = optional_query_language();

try {
    $pdo = create_database_connection();
    $userId = current_user_id($pdo);

    /*
     * Eine Kategorie gehört einem Konto. Ohne eines gibt es keine Kategorie zu lesen
     * und keinen Fortschritt zu zeigen, geantwortet wird deshalb mit der leeren Liste
     * und 200 - dieselbe Form, die die Oberfläche erwartet (so mit der
     * Konten-Arbeit entschieden).
     */
    if ($userId === null) {
        send_json_success([
            'cards' => [],
            'summary' => review_summarise_cards([]),
            /* Niemand ist angemeldet, es gibt also keine Serie zu zeigen - der
               Schlüssel steht aber da, damit der Browser nie raten muss, ob er
               vergessen wurde. */
            'streak' => ['available' => false, 'days' => 0],
            'has_user' => false,
            'content_languages' => card_content_languages(card_columns($pdo)),
            'language' => $language,
        ]);
    }

    if (!category_exists($pdo, $categoryId, $userId)) {
        send_json_error('category_not_found', 'This category does not exist.', 404);
    }

    /*
     * Jede Karte trägt den Stand, den sie für das angemeldete Konto hat.
     */
    $cards = review_cards_with_progress($pdo, $categoryId, $userId, $language);

    // Eine leere Liste ist eine gültige Antwort und lässt die Seite ihren leeren
    // Zustand zeigen.
    send_json_success([
        'cards' => $cards,
        'summary' => review_summarise_cards($cards),
        /* Wie viele Tage in Folge diese Person in DIESER Unterkategorie gelernt hat -
           siehe src/services/dashboard_service.php. */
        'streak' => dashboard_streak($pdo, $userId, $categoryId),
        'has_user' => $userId !== null,
        /* Welche Sprachen diese Tabelle haben kann: eine, oder zwei nach der
           Migration. Der Kartendialog zeigt seine Sprachreiter nur bei zwei. */
        'content_languages' => card_content_languages(card_columns($pdo)),
        'language' => $language,
    ]);
} catch (Throwable $error) {
    error_log('Loading cards failed: ' . $error->getMessage());

    send_json_error('cards_unavailable', 'The cards could not be loaded.', 500);
}
