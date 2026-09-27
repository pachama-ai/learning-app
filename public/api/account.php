<?php

declare(strict_types=1);

/**
 * Etwas am Konto selbst ändern.
 *
 *   POST api/account.php
 *     { "action": "delete", "password": "...", "csrf_token": "..." }
 *         löscht das Konto samt Lernfortschritt und Lern-Sitzungen
 *
 * Die Aktion braucht ein angemeldetes Konto, den Token aus api/auth.php und das
 * Passwort dieses Kontos. Deshalb kann sie weder von einer anderen Seite
 * ausgelöst werden noch von jemandem, der nur an einem offenen Bildschirm
 * vorbeigeht.
 *
 * Diese Datei bleibt dünn, wie jeder Endpunkt hier: Anfrage lesen, das
 * Offensichtliche prüfen, src/services/user_service.php aufrufen und als JSON
 * antworten. Jede SQL-Anweisung liegt im Service, jede ist vorbereitet, und hier
 * wird nie ein Passwort, eine Verbindungszeichenfolge oder ein Dateipfad
 * genannt.
 */

require_once __DIR__ . '/../../src/config/database.php';
require_once __DIR__ . '/../../src/helpers/json_response.php';
require_once __DIR__ . '/../../src/helpers/request_input.php';
require_once __DIR__ . '/../../src/services/user_service.php';

/*
 * Alles läuft in diesem Schutz: ohne ihn würde ein unerwarteter Datenbankfehler
 * die Anfrage als HTML-Seite beenden, und der Browser zeigte seinen allgemeinen
 * Satz statt einer Antwort, die er lesen kann. Der Grund geht ins
 * Server-Protokoll.
 */
try {
    handle_account_request($_SERVER['REQUEST_METHOD'] ?? 'GET');
} catch (Throwable $error) {
    error_log('api/account.php: ' . $error->getMessage());

    send_json_error('server_error', 'This request could not be handled.', 500);
}

/** Der eine Einstiegspunkt dieses Endpunkts. */
function handle_account_request(string $method): void
{
    if ($method !== 'POST') {
        header('Allow: POST');
        send_json_error('method_not_allowed', 'Only POST is supported here.', 405);
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

    $pdo = create_database_connection();
    $userId = current_user_id($pdo);

    if ($userId === null) {
        send_json_error('not_signed_in', 'This needs a signed-in account.', 401);
    }

    $action = optional_input_text($body, 'action', 20, 'invalid_action') ?? '';

    if ($action === 'delete') {
        $password = optional_input_text($body, 'password', 200, 'invalid_password') ?? '';

        if ($password === '') {
            send_json_error('password_required', 'This request carried no password.', 400);
        }

        if (user_public_data($pdo, $userId) === null) {
            send_json_error('not_signed_in', 'This needs a signed-in account.', 401);
        }

        /*
         * Die Entscheidung fällt HIER und nicht im Browser: eine von Hand
         * geschriebene Anfrage kann das Passwort nicht überspringen, indem sie das
         * Feld weglässt, und nichts, was nur in der Seite versteckt ist, kann ein
         * Konto löschen.
         *
         * Warum das Passwort und nicht ein eingetippter Name (wie es eine frühere
         * Fassung verlangte): der Name der Person steht offen in der Kopfzeile, er
         * beweist also gar nichts, wenn jemand vor dem offenen Bildschirm sitzt.
         * Das Passwort beweist etwas.
         */
        if (user_password_matches($pdo, $userId, $password) !== true) {
            send_json_error('wrong_password', 'This password does not belong to this account.', 400);
        }

        if (delete_user_account($pdo, $userId) !== true) {
            send_json_error('account_not_found', 'This account does not exist (any more).', 404);
        }

        /* Die Sitzung wird zuletzt beendet: nachdem die Zeile weg ist, gibt es
           nichts mehr, womit man angemeldet sein könnte. */
        user_sign_out_session();

        send_json_success(['deleted' => true]);
    }

    send_json_error('invalid_action', 'This action is not known here.', 400);
}
