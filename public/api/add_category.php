<?php

declare(strict_types=1);

/**
 * POST /api/add_category.php
 *
 * Body (JSON): {"name": "History"}
 *
 * Creates one new top-level learning area (parent_id = NULL) and answers with
 * HTTP 201 and the created row.
 *
 * Only the `name` column is written. The table structure is not touched and no
 * existing row is changed or removed.
 */

require_once __DIR__ . '/../../src/config/database.php';
require_once __DIR__ . '/../../src/helpers/json_response.php';
require_once __DIR__ . '/../../src/services/category_service.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    send_json_error('method_not_allowed', 'Only POST requests are allowed.', 405);
}

// The body is JSON, not a form post, so it is read from php://input.
$rawBody = file_get_contents('php://input');
$payload = json_decode((string) $rawBody, true);

if (!is_array($payload) || !array_key_exists('name', $payload)) {
    send_json_error('invalid_request_body', 'Send a JSON object with a "name" field.', 400);
}

$name = $payload['name'];

if (!is_string($name)) {
    send_json_error('invalid_name', 'The name must be text.', 400);
}

// Reject bytes that are not valid UTF-8 before anything else: such a string
// could not be stored or encoded as JSON reliably.
if (!mb_check_encoding($name, 'UTF-8')) {
    send_json_error('invalid_name', 'The name must be valid UTF-8 text.', 400);
}

// Surrounding whitespace is removed first, so " B" and "B " are the same name.
$name = trim($name);

if ($name === '') {
    send_json_error('invalid_name', 'The name must not be empty.', 400);
}

// The column is varchar(100). mb_strlen counts characters, not bytes, so an
// umlaut counts as one character instead of two.
if (mb_strlen($name) > 100) {
    send_json_error('invalid_name', 'The name must not be longer than 100 characters.', 400);
}

// Control characters (newlines, tabs, and so on) would break the layout.
if (preg_match('/[\x00-\x1F\x7F]/u', $name) === 1) {
    send_json_error('invalid_name', 'The name must not contain control characters.', 400);
}

try {
    $pdo = create_database_connection();

    // There is no unique index on the name column and the structure must not be
    // changed, so the duplicate check happens here. It also keeps the list free
    // of two entries that read exactly the same.
    if (category_name_exists($pdo, $name)) {
        send_json_error('category_exists', 'A learning area with this name already exists.', 409);
    }

    $category = create_main_category($pdo, $name);

    send_json_success($category, 201);
} catch (Throwable $error) {
    // Details go to the server log only; the browser gets a generic message.
    error_log('Creating a category failed: ' . $error->getMessage());

    send_json_error(
        'category_create_failed',
        'The learning area could not be saved.',
        500
    );
}
