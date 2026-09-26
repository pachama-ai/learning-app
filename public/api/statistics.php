<?php

declare(strict_types=1);

/**
 * GET /api/statistics.php?id=85
 *
 * The numbers of one category for the person who is signed in: how many cards it
 * holds, how many of them are known, when the rest is due and what this person
 * studied on the last days.
 *
 * The id alone decides the scope, and the server decides what that means: a
 * category without a parent is a learning area and is counted with all of its
 * subcategories, every other category is counted as itself - see
 * src/services/statistics_service.php. The browser never decides that, so a hand
 * written request cannot give the same id a different meaning.
 *
 * Without a signed in person there is nothing to count, because progress belongs
 * to a user. That is an error and not a page full of zeros.
 *
 * This endpoint reads. It has no POST part, because a statistic is not something
 * that is written.
 */

require_once __DIR__ . '/../../src/config/database.php';
require_once __DIR__ . '/../../src/helpers/json_response.php';
require_once __DIR__ . '/../../src/helpers/session_user.php';
require_once __DIR__ . '/../../src/services/category_service.php';
require_once __DIR__ . '/../../src/services/statistics_service.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    send_json_error('method_not_allowed', 'Only GET requests are allowed.', 405);
}

$rawId = $_GET['id'] ?? null;

if (!is_string($rawId) || !ctype_digit($rawId) || (int) $rawId < 1) {
    send_json_error('invalid_id', 'The field "id" must be a positive whole number.', 400);
}

$categoryId = (int) $rawId;
$userId = session_user_id_from_php_session();

if ($userId === null) {
    send_json_error('not_signed_in', 'Sign in to see your progress.', 401);
}

try {
    $pdo = create_database_connection();

    if (!category_exists($pdo, $categoryId)) {
        send_json_error('category_not_found', 'This category does not exist.', 404);
    }

    $statistics = statistics_for_category($pdo, $categoryId, $userId);

    if ($statistics === null) {
        send_json_error('category_not_found', 'This category does not exist.', 404);
    }

    send_json_success($statistics);
} catch (Throwable $error) {
    /* The reason stays in the server log: an answer must never carry SQL,
       credentials or a file path. */
    error_log('statistics failed: ' . $error->getMessage());

    send_json_error('server_error', 'These numbers could not be read right now.', 500);
}
