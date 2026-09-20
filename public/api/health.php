<?php

declare(strict_types=1);

/**
 * GET /api/health.php
 *
 * Checks that the API can reach the learning_app database.
 *
 * This endpoint is read-only: it changes no data, creates no tables and
 * returns no credentials.
 */

require_once __DIR__ . '/../../src/config/database.php';
require_once __DIR__ . '/../../src/helpers/json_response.php';

// This endpoint only answers GET requests. send_json_error() ends the request,
// so the code below is not reached when the method is wrong.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    send_json_error('method_not_allowed', 'Only GET requests are allowed.', 405);
}

try {
    $pdo = create_database_connection();

    // "SELECT DATABASE()" is a fixed, read-only statement with no user input,
    // so a prepared statement with bound parameters is not needed here. It
    // returns the name of the database that is really selected, which proves
    // that both the connection and the database selection work.
    $databaseName = $pdo->query('SELECT DATABASE()')->fetchColumn();

    send_json_success([
        'database' => is_string($databaseName) ? $databaseName : null,
        'message' => 'Database connection works',
    ]);
} catch (Throwable $error) {
    // The real reason is written to the server log only. It could contain the
    // password, the connection string or SQL details, so it must never be sent
    // to the browser.
    error_log('Health check failed: ' . $error->getMessage());

    send_json_error(
        'database_connection_failed',
        'The database connection could not be established.',
        500
    );
}
