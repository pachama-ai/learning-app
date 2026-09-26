<?php

declare(strict_types=1);

/**
 * Changing something about the account itself.
 *
 *   POST api/account.php
 *     { "action": "delete", "password": "...", "csrf_token": "..." }
 *         deletes the account together with its learning progress and its learning
 *         sessions
 *
 * The action needs a signed-in account, the token that api/auth.php hands out and
 * the password of that account, so it cannot be triggered from another site and
 * not by somebody who only walks past an open page.
 *
 * This file stays thin, like every endpoint here: read the request, check what is
 * obvious, call src/services/user_service.php and answer as JSON. Every SQL
 * statement lives in the service, every one of them is prepared, and nothing here
 * ever names a password, a connection string or a file path.
 */

require_once __DIR__ . '/../../src/config/database.php';
require_once __DIR__ . '/../../src/helpers/json_response.php';
require_once __DIR__ . '/../../src/helpers/request_input.php';
require_once __DIR__ . '/../../src/services/user_service.php';

/*
 * Everything runs inside this guard: without it an unexpected database error would
 * end the request as an HTML page and the browser would show its generic sentence
 * instead of an answer it can read. The reason goes to the server log.
 */
try {
    handle_account_request($_SERVER['REQUEST_METHOD'] ?? 'GET');
} catch (Throwable $error) {
    error_log('api/account.php: ' . $error->getMessage());

    send_json_error('server_error', 'This request could not be handled.', 500);
}

/** The one entry point of this endpoint. */
function handle_account_request(string $method): void
{
    if ($method !== 'POST') {
        header('Allow: POST');
        send_json_error('method_not_allowed', 'Only POST is supported here.', 405);
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
         * The decision is made HERE and not in the browser: a hand written request
         * cannot skip the password by leaving the field out, and nothing that is
         * only hidden in the page can delete an account.
         *
         * Why the password and not a typed name (as an earlier version asked): the
         * name of the person stands openly in the header, so it is no proof at all
         * for somebody who is sitting in front of the open page. The password is.
         */
        if (user_password_matches($pdo, $userId, $password) !== true) {
            send_json_error('wrong_password', 'This password does not belong to this account.', 400);
        }

        if (delete_user_account($pdo, $userId) !== true) {
            send_json_error('account_not_found', 'This account does not exist (any more).', 404);
        }

        /* The session is ended last: after the row is gone there is nothing left
           to be signed in to. */
        user_sign_out_session();

        send_json_success(['deleted' => true]);
    }

    send_json_error('invalid_action', 'This action is not known here.', 400);
}
