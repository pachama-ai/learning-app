<?php

declare(strict_types=1);

/**
 * GET /api/stats.php
 *
 * Returns the four numbers of the start page:
 *   learning_areas, subcategories, total_cards, learned_percent
 *
 * This endpoint is read-only. Every value is counted from real rows; a value
 * that cannot be known is null, never a placeholder number.
 */

require_once __DIR__ . '/../../src/config/database.php';
require_once __DIR__ . '/../../src/helpers/json_response.php';
require_once __DIR__ . '/../../src/services/stats_service.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    send_json_error('method_not_allowed', 'Only GET requests are allowed.', 405);
}

try {
    $pdo = create_database_connection();

    send_json_success(get_overview_stats($pdo));
} catch (Throwable $error) {
    // Details go to the server log only; the browser gets a generic message.
    error_log('Loading the statistics failed: ' . $error->getMessage());

    send_json_error(
        'stats_unavailable',
        'The statistics could not be loaded.',
        500
    );
}
