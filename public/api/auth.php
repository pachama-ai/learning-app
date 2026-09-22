<?php

declare(strict_types=1);

/**
 * Signing in, out and up.
 *
 *   GET  api/auth.php
 *        answers who is signed in, whether signing in is set up at all, and the
 *        token the next POST has to carry.
 *
 *   POST api/auth.php
 *        body: { "action": "sign_in" | "register" | "sign_out",
 *                "identifier": "Anna Beispiel" or "anna@example.com",
 *                "password": "...",
 *                "csrf_token": "..." }
 *
 * This file stays thin, like every endpoint here: read the request, check what
 * is obvious, call src/services/user_service.php, answer as JSON. Every SQL
 * statement lives in the service, and every statement there is prepared.
 *
 * The answers carry the same codes the dialog already turns into sentences.
 * A wrong password and a name that does not exist get the SAME code, so the
 * endpoint cannot be used to find out which names exist.
 */

require_once __DIR__ . '/../../src/config/database.php';
require_once __DIR__ . '/../../src/helpers/json_response.php';
require_once __DIR__ . '/../../src/helpers/request_input.php';
require_once __DIR__ . '/../../src/services/user_service.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

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

/* The token first: without it nothing else in this file runs. */
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
    /* "Not set up yet" is not the visitor's mistake, and it says so: the
       migration in database/add_user_auth.sql has to be run first. */
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
