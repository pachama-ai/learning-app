<?php

declare(strict_types=1);

/**
 * Die Lerneinheit einer Unterkategorie.
 *
 * GET  /api/review.php?category_id=50            -> die Schlange der Einheit
 * GET  /api/review.php?category_id=50&mode=difficult
 *                                                -> nur die Karten, die mit
 *                                                   "Nochmal" oder "Schwer"
 *                                                   beantwortet wurden
 * POST /api/review.php   {"action":"rate", ...}  -> eine Bewertung speichern
 * POST /api/review.php   {"action":"undo", ...}  -> die letzte Bewertung zurücknehmen
 * POST /api/review.php   {"action":"session_end"} -> den Lerndurchgang schließen
 *
 * Eine Bewertung schreibt auch den Lerndurchgang mit, in dem sie passiert ist
 * (study_sessions, siehe src/services/study_session_service.php). Die erste Antwort
 * eines Durchgangs legt diese Zeile an, und die Antwort des Servers trägt ihre Id; der
 * Browser schickt die Id bei jeder weiteren Antwort zurück, und "session_end" schließt
 * den Durchgang, wenn die Lernansicht verlassen wird - egal ob die Schlange leer
 * wurde oder die Person früher aufgehört hat.
 *
 * Die Schlange entsteht hier und nicht im Browser: welche Karte zuerst kommt und
 * welchen Stand sie hat, entscheidet der Server. Der Browser darf sie zeigen und
 * sonst nichts.
 *
 * Jeder Schreibvorgang braucht ein angemeldetes Konto. Ohne eines gibt es keine Id,
 * unter der der Fortschritt liegen könnte, und die Antwort sagt genau das, statt eine
 * zu erfinden.
 */

require_once __DIR__ . '/../../src/config/database.php';
require_once __DIR__ . '/../../src/helpers/json_response.php';
require_once __DIR__ . '/../../src/helpers/request_input.php';
require_once __DIR__ . '/../../src/helpers/session_user.php';
require_once __DIR__ . '/../../src/services/card_service.php';
require_once __DIR__ . '/../../src/services/category_service.php';
require_once __DIR__ . '/../../src/services/review_service.php';
require_once __DIR__ . '/../../src/services/study_session_service.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method !== 'GET' && $method !== 'POST') {
    send_json_error('method_not_allowed', 'Only GET and POST requests are allowed.', 405);
}

/** Die Modusnamen, die dieser Endpunkt annimmt. */
const REVIEW_MODES = ['all', 'difficult'];

/* -------------------------------------------------------------------------
   POST: eine Bewertung oder ihr Zurücknehmen
   ------------------------------------------------------------------------- */

if ($method === 'POST') {
    $body = read_json_object();
    $action = isset($body['action']) && is_string($body['action']) ? $body['action'] : 'rate';

    if ($action !== 'rate' && $action !== 'undo' && $action !== 'session_end') {
        send_json_error('invalid_action', 'The action must be "rate", "undo" or "session_end".', 400);
    }

    $categoryId = optional_positive_id($body, 'category_id', 'invalid_category_id');
    $cardId = optional_positive_id($body, 'card_id', 'invalid_card_id');

    /* Das Schließen des Durchgangs braucht weder Karte noch Kategorie, die beiden
       Prüfungen gelten also nur für eine Bewertung und für ein Zurücknehmen. */
    if ($action !== 'session_end') {
        if ($categoryId === null) {
            send_json_error('invalid_category_id', 'The field "category_id" must be a positive whole number.', 400);
        }

        if ($cardId === null) {
            send_json_error('invalid_card_id', 'The field "card_id" must be a positive whole number.', 400);
        }
    }

    $rating = 0;

    if ($action === 'rate') {
        $rawRating = $body['rating'] ?? null;

        if (!is_int($rawRating) && !(is_string($rawRating) && ctype_digit($rawRating))) {
            send_json_error('invalid_rating', 'The rating must be 1, 2, 3 or 4.', 400);
        }

        $rating = (int) $rawRating;
    }

    /*
     * Eine zurückgenommene Antwort trägt die Antwort mit, die sie zurücknimmt, damit
     * die Zähler des Durchgangs ihr folgen können. Fehlt sie, bleiben die Zähler, wie
     * sie sind.
     */
    if ($action === 'undo') {
        $rawUndoRating = $body['rating'] ?? null;

        if (is_int($rawUndoRating) || (is_string($rawUndoRating) && ctype_digit($rawUndoRating))) {
            $rating = (int) $rawUndoRating;
        }
    }

    /*
     * Der Lerndurchgang, zu dem dieser Aufruf gehört. Der Browser schickt bei der
     * ersten Antwort eines Durchgangs null und bekommt die neue Id zurück
     * (study_session_service.php).
     */
    $sessionId = optional_positive_id($body, 'session_id', 'invalid_session_id');

    try {
        $pdo = create_database_connection();

        /* Niemand angemeldet: der Fortschritt kann nicht gespeichert werden, und das
           zu sagen ist die einzige ehrliche Antwort. Ein Standardkonto gibt es nicht. */
        $userId = current_user_id($pdo);

        if ($userId === null) {
            $required = session_user_required_error();

            send_json_error($required['code'], $required['message'], $required['status']);
        }

        /*
         * Der Durchgang ist zu Ende. Das steht vor der Kategorie-Prüfung, weil das
         * Schließen weder eine Kategorie noch eine Karte braucht - und
         * send_json_success die Anfrage genau hier beendet.
         */
        if ($action === 'session_end') {
            send_json_success([
                'session_id' => $sessionId,
                'closed' => study_session_close($pdo, $userId, $sessionId, time()),
            ]);
        }

        if (!category_exists($pdo, $categoryId, $userId)) {
            send_json_error('category_not_found', 'This category does not exist.', 404);
        }

        if ($action === 'undo') {
            $stored = isset($body['stored']) && is_array($body['stored']) ? $body['stored'] : null;
            $previous = isset($body['previous']) && is_array($body['previous']) ? $body['previous'] : null;

            $result = review_undo_rating($pdo, $userId, $cardId, $stored, $previous, $categoryId, $sessionId, $rating);

            if (!$result['ok']) {
                send_json_error($result['code'], $result['message'], 409);
            }

            send_json_success($result['data']);
        }

        $result = review_rate_card($pdo, $userId, $cardId, $rating, $categoryId, $sessionId);

        if (!$result['ok']) {
            $status = $result['code'] === 'card_not_found' ? 404 : 400;

            send_json_error($result['code'], $result['message'], $status);
        }

        send_json_success($result['data']);
    } catch (Throwable $error) {
        /* Der Grund bleibt im Server-Protokoll. Darin können SQL und Verbindungsdaten
           stehen, die der Browser nie sehen darf. */
        error_log('Rating a card failed: ' . $error->getMessage());

        send_json_error('review_failed', 'This answer could not be saved.', 500);
    }
}

/* -------------------------------------------------------------------------
   GET: die Schlange der Einheit
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
    $userId = current_user_id($pdo);

    /*
     * Eine Kategorie gehört einem Konto. Ohne eines gibt es keine Schlange zu bauen:
     * die Antwort ist die leere Einheit mit 200, damit die Oberfläche ihren eigenen
     * Zustand "nichts zu lernen" zeigen kann statt eines Fehlers (so mit der
     * Konten-Arbeit entschieden).
     */
    if ($userId === null) {
        send_json_success([
            'category_id' => $categoryId,
            'mode' => $mode,
            'has_user' => false,
            'summary' => review_summarise_cards([]),
            'counts' => ['due' => 0, 'new' => 0, 'unsure' => 0, 'known' => 0, 'cards' => 0],
            'queue' => [],
            'content_languages' => card_content_languages(card_columns($pdo)),
        ]);
    }

    if (!category_exists($pdo, $categoryId, $userId)) {
        send_json_error('category_not_found', 'This category does not exist.', 404);
    }

    /*
     * Die Einheit gehört zu dem Eintrag, der geöffnet wurde. Eine Unterkategorie
     * bringt ihre eigenen Karten mit; ein Lernbereich bringt auch die Karten seiner
     * Unterkategorien mit, und genau das macht "Alles lernen" möglich, ohne die
     * Wiederholungslogik anzufassen - Schlange und Planer sehen genau dieselben Karten
     * wie vorher.
     */
    $branchIds = review_branch_category_ids($pdo, $categoryId, $userId);
    $randomizeNewCategoryIds = review_randomized_new_category_ids($pdo, $branchIds, $userId);
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

    $built = review_build_queue($cardsForQueue, $statuses, $isDue, $mode, $randomizeNewCategoryIds);

    /*
     * Jeder Eintrag der Schlange trägt mit, was jede der vier Antworten mit seiner
     * Karte machen würde. Die Zahlen kommen vom Planer im Service, der Knopf unter der
     * Karte verspricht also genau das, was die Bewertung anschließend speichert.
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
        /* false sagt dem Browser, dass eine Antwort noch nicht gespeichert werden
           kann - und warum. */
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
