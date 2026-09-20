<?php

declare(strict_types=1);

/**
 * GET /api/category_icon.php?id=2
 *
 * Serves the drawing that is stored in categories.icon_svg.
 *
 * Why this endpoint exists: the icon lives in the database, and a category list
 * must not carry a 60 KB drawing per row. A list therefore only says whether an
 * icon exists and gives a short fingerprint; the <img> tag then asks for the
 * drawing here, and the browser caches it.
 *
 * The answer is always image/svg+xml, never HTML, and it is served with a
 * Content-Security-Policy that allows nothing at all. Combined with the fact
 * that the file is only ever drawn inside an <img> tag - where a browser does
 * not run scripts - the stored SVG cannot execute anything. The SVG is also
 * sanitised before it is stored (src/helpers/svg_sanitizer.php).
 *
 * 404 means "this category has no icon of its own"; the page then falls back to
 * the illustration that ships with the app. No file path, no database error and
 * no other detail is ever sent to the browser.
 */

require_once __DIR__ . '/../../src/config/database.php';
require_once __DIR__ . '/../../src/helpers/json_response.php';
require_once __DIR__ . '/../../src/helpers/request_input.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method !== 'GET' && $method !== 'HEAD') {
    send_json_error('method_not_allowed', 'Only GET requests are allowed.', 405);
}

$categoryId = require_query_id('id');

try {
    $pdo = create_database_connection();

    $statement = $pdo->prepare('SELECT icon_svg FROM categories WHERE id = :id');
    $statement->bindValue(':id', $categoryId, PDO::PARAM_INT);
    $statement->execute();

    $svg = $statement->fetchColumn();

    if (!is_string($svg) || trim($svg) === '') {
        // Covers both "no such category" and "category without an icon".
        send_json_error('icon_not_found', 'This category has no stored icon.', 404);
    }

    if (!headers_sent()) {
        http_response_code(200);
        header('Content-Type: image/svg+xml; charset=utf-8');
        // Nothing may be loaded from anywhere while this file is shown.
        header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; sandbox");
        header('X-Content-Type-Options: nosniff');
        /* The address carries a fingerprint of the drawing (see the API), so a
           new icon is asked for as soon as the old one is replaced. */
        header('Cache-Control: public, max-age=604800');
    }

    echo $svg;
} catch (Throwable $error) {
    // The real reason goes to the server log only.
    error_log('Serving a category icon failed: ' . $error->getMessage());

    send_json_error('icon_unavailable', 'The icon could not be loaded.', 500);
}
