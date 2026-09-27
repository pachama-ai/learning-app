<?php

declare(strict_types=1);

/**
 * Anmelden, abmelden, Konto anlegen.
 *
 *   GET  api/auth.php
 *        antwortet, wer angemeldet ist, ob die Anmeldung überhaupt eingerichtet
 *        ist, und mit dem Token, den das nächste POST mitbringen muss.
 *
 *   POST api/auth.php
 *        body: { "action": "sign_in" | "register" | "sign_out",
 *                "identifier": "anna@example.com" (nur die E-Mail-Adresse),
 *                "password": "...",
 *                "csrf_token": "..." }
 *
 * Diese Datei bleibt dünn, wie jeder Endpunkt hier: Anfrage lesen, das
 * Offensichtliche prüfen, src/services/user_service.php aufrufen, als JSON
 * antworten. Jede SQL-Anweisung liegt im Service, und jede dort ist vorbereitet.
 *
 * Die Antworten tragen dieselben Fehlercodes, die der Dialog schon in Sätze
 * übersetzt. Ein falsches Passwort und eine unbekannte Adresse bekommen denselben
 * Code, damit man mit diesem Endpunkt nicht herausfinden kann, welche Konten es
 * gibt.
 */

require_once __DIR__ . '/../../src/config/database.php';
require_once __DIR__ . '/../../src/helpers/json_response.php';
require_once __DIR__ . '/../../src/helpers/request_input.php';
require_once __DIR__ . '/../../src/services/user_service.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

/*
 * Alles darunter läuft in diesem Schutz. Ohne ihn würde ein unerwarteter
 * Datenbankfehler die Anfrage als HTML-Seite beenden, und der Browser zeigte
 * seinen allgemeinen Satz statt etwas, das das Problem benennt. Die Einzelheiten
 * gehen ins Server-Protokoll und nie in die Antwort.
 */
try {
    handle_auth_request($method);
} catch (Throwable $error) {
    error_log('api/auth.php: ' . $error->getMessage());

    send_json_error('server_error', 'This request could not be handled.', 500);
}

/** Der eine Einstiegspunkt dieses Endpunkts. */
function handle_auth_request(string $method): void
{
    if ($method === 'GET') {
        $pdo = create_database_connection();
        $userId = current_user_id($pdo);

        send_json_success([
            'user' => $userId === null ? null : user_public_data($pdo, $userId),
            'ready' => user_sign_in_ready($pdo),
            'csrf_token' => user_csrf_token()
        ]);
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

    $action = optional_input_text($body, 'action', 20, 'invalid_action') ?? '';

    if ($action === 'sign_out') {
        user_sign_out_session();

        send_json_success(['user' => null]);
    }

    if ($action !== 'sign_in' && $action !== 'register') {
        send_json_error('invalid_action', 'This action is not known here.', 400);
    }

    $identifier = optional_input_text($body, 'identifier', USER_EMAIL_MAX_LENGTH, 'invalid_identifier') ?? '';
    $password = optional_input_text($body, 'password', USER_PASSWORD_MAX_LENGTH, 'invalid_password') ?? '';

    $pdo = create_database_connection();
    $result = $action === 'register'
        ? user_register($pdo, $identifier, $password)
        : user_sign_in($pdo, $identifier, $password);

    if ($result['ok'] !== true) {
        /* "Noch nicht eingerichtet" ist nicht der Fehler des Besuchers, und das
           sagt die Antwort auch: zuerst muss die Migration in
           database/add_user_auth.sql von Hand ausgeführt werden. */
        if ($result['error'] === 'sign_in_not_ready') {
            send_json_error(
                'sign_in_not_ready',
                'Signing in is not set up yet. The file database/add_user_auth.sql has to be run first.',
                503
            );
        }

        if ($result['error'] === 'credentials') {
            send_json_error('credentials', 'The name and the password do not match.', 401);
        }

        send_json_error((string) $result['error'], 'This sign-in was not accepted.', 400);
    }

    user_sign_in_session($result['user']);

    send_json_success(['user' => user_public_data($pdo, (int) $result['user']['id'])]);
}
