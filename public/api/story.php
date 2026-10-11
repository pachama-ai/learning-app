<?php

declare(strict_types=1);

/**
 * Die gemeinsame Geschichte lesen und fortschreiben.
 *
 *   GET  api/story.php
 *        antwortet mit der ganzen Geschichte, dem Stand der Reihe, den Zahlen, die die
 *        Seite braucht, und dem Token, das das nächste POST mitbringen muss.
 *
 *   POST api/story.php
 *        body: { "content": "...", "csrf_token": "..." }
 *        veröffentlicht einen Beitrag und antwortet mit demselben Aufbau wie GET.
 *
 *   POST api/story.php  (action = "edit")
 *        body: { "action": "edit", "contribution_id": 7, "content": "...",
 *                "csrf_token": "..." }
 *        ändert den eigenen, zuletzt veröffentlichten Beitrag. Antwort wie GET.
 *
 * Der Unterschied zwischen den beiden Aktionen steht nicht hier, sondern im Service:
 * story_publish() schreibt eine neue Zeile, story_edit() ändert eine vorhandene. Diese
 * Datei entscheidet nur, wer gerufen wird.
 *
 * Die Zugriffsprüfung steht ganz oben und gilt für BEIDE Methoden. Wer nicht zu den
 * beiden Schreibern gehört, bekommt eine 403 - und zwar bevor irgendetwas gelesen oder
 * geschrieben wird. Dass die Startseite die Kachel verbirgt und die Seite den Editor
 * ausblendet, ist Bequemlichkeit und kein Schutz: ein POST lässt sich jederzeit von Hand
 * abschicken.
 *
 * "Nicht angemeldet" und "nicht berechtigt" bekommen dieselbe Antwort. Wer nicht
 * mitschreibt, muss nicht erfahren, dass es diese Geschichte gibt.
 *
 * Diese Datei bleibt dünn, wie jeder Endpunkt hier: Anfrage lesen, das Offensichtliche
 * prüfen, den Service aufrufen, als JSON antworten. Jede SQL-Anweisung liegt im Service,
 * und jede dort ist vorbereitet.
 */

require_once __DIR__ . '/../../src/config/database.php';
require_once __DIR__ . '/../../src/helpers/json_response.php';
require_once __DIR__ . '/../../src/helpers/request_input.php';
require_once __DIR__ . '/../../src/helpers/session_user.php';
require_once __DIR__ . '/../../src/services/story_service.php';
require_once __DIR__ . '/../../src/services/user_service.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    handle_story_request($method);
} catch (Throwable $error) {
    error_log('api/story.php: ' . $error->getMessage());

    send_json_error('server_error', 'This request could not be handled.', 500);
}

/** Der eine Einstiegspunkt dieses Endpunkts. */
function handle_story_request(string $method): void
{
    $pdo = create_database_connection();
    $userId = current_user_id($pdo);

    if (story_writer_key($userId) === null) {
        send_json_error('story_forbidden', 'This story is not available for this account.', 403);
    }

    if ($method === 'GET') {
        send_json_success(story_response($pdo, $userId));
    }

    if ($method !== 'POST') {
        header('Allow: GET, POST');
        send_json_error('method_not_allowed', 'Only GET and POST are supported here.', 405);
    }

    $body = read_json_object();

    /* Zuerst der Token: ohne ihn läuft in dieser Datei nichts weiter. */
    if (user_csrf_valid(optional_input_text($body, 'csrf_token', 128, 'invalid_token')) !== true) {
        send_json_error(
            'invalid_token',
            'This request carried no valid token. Reload the page and try again.',
            400
        );
    }

    /*
     * Der Text wird hier nur grob begrenzt gelesen (das Doppelte der echten Grenze),
     * damit ein versehentlich eingefügter Riesentext nicht erst den Server belastet. Die
     * eigentliche Grenze prüft story_publish() - dort steht sie, und dort bekommt sie
     * auch ihren eigenen Fehlercode mit einem brauchbaren Satz.
     */
    $content = optional_input_text($body, 'content', STORY_MAX_CONTENT_LENGTH * 2, 'invalid_content') ?? '';

    /*
     * Zwei Aktionen teilen sich diesen Endpunkt, weil beide dasselbe antworten und
     * dieselbe Prüfung davor haben. Ohne Angabe wird veröffentlicht - eine ältere
     * Anfrage ohne "action" bleibt damit gültig.
     */
    $action = optional_input_text($body, 'action', 16, 'invalid_action') ?? 'publish';

    if ($action === 'edit') {
        $contributionId = optional_positive_id($body, 'contribution_id', 'invalid_contribution');

        if ($contributionId === null) {
            send_json_error('invalid_contribution', 'An edit needs the field "contribution_id".', 400);
        }

        $result = story_edit($pdo, (int) $userId, $contributionId, $content);
    } else {
        $result = story_publish($pdo, (int) $userId, $content);
    }

    if ($result['ok'] !== true) {
        story_fail($result['code']);
    }

    /* Geantwortet wird mit dem frischen Stand, nicht mit dem abgeschickten Text: die
       Seite baut sich daraus neu auf und braucht keinen zweiten Aufruf. */
    send_json_success(story_response($pdo, (int) $userId));
}

/** Alles, was die Seite braucht, samt dem Token für das nächste POST. */
function story_response(PDO $pdo, int $userId): array
{
    $response = story_overview($pdo, $userId);
    $response['csrf_token'] = user_csrf_token();

    return $response;
}

/**
 * Macht aus dem Code des Service die passende Antwort.
 *
 * Die Codes sind dieselben, die die Oberfläche schon in Sätze übersetzt; ein neuer Code
 * braucht also eine Zeile in den Übersetzungen und keine Änderung hier.
 */
function story_fail(?string $code): void
{
    if ($code === 'not_a_writer') {
        send_json_error('story_forbidden', 'This story is not available for this account.', 403);
    }

    if ($code === 'not_your_turn' || $code === 'edit_window_open') {
        /*
         * Kein Fehler im Programm, sondern ein Zusammenstoss in der Sache - und beide
         * Codes beschreiben denselben Zustand: `not_your_turn` kommt von der wartenden
         * Person, `edit_window_open` von der, die gerade selbst geschrieben hat und nur
         * noch ändern darf. Ein neuer Beitrag ist in beiden Fällen jetzt nicht dran, und
         * dafuer ist 409 die richtige Antwort.
         */
        send_json_error('not_your_turn', 'It is not this account\'s turn to write.', 409);
    }

    if ($code === 'empty_contribution') {
        send_json_error('empty_contribution', 'The contribution is empty.', 400);
    }

    if ($code === 'contribution_too_long') {
        send_json_error('contribution_too_long', 'The contribution is too long.', 400);
    }

    /* Der Beitrag ist nicht (mehr) änderbar: er gehört jemand anderem, es gibt ihn nicht,
       oder es ist schon weitergeschrieben worden. 409 heißt: der Zustand lässt es nicht
       zu - und wer nicht ändern darf, muss nicht erfahren, warum genau. */
    if ($code === 'not_editable') {
        send_json_error('not_editable', 'This contribution can no longer be changed.', 409);
    }

    send_json_error('story_not_ready', 'The contribution could not be saved.', 500);
}
