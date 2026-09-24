<?php

declare(strict_types=1);

/**
 * GET /api/category_icon.php?id=2
 *
 * Serves the drawing that is stored in categories.icon_svg.
 *
 * Why this endpoint exists: the drawing lives in the database and may be up to
 * 350 KB, so no answer of this application ever carries it. A row only says
 * whether an icon exists and gives a short fingerprint; the <img> tag asks for
 * the drawing here, and the browser keeps it.
 *
 * The address carries that fingerprint (v=...), so it changes as soon as the
 * drawing changes: the answer can be cached for as long as a browser likes
 * without ever showing an old icon. On top of that the answer carries an ETag
 * and answers a conditional request with 304, so a browser that has the drawing
 * does not receive it a second time at all.
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

/*
 * The drawing is SVG, and SVG is text: it travels gzipped. Apache does that by
 * itself (see deploy/apache/), the development server of PHP does not - so the
 * two behave the same. Measured on a 110 KB drawing: 28 KB with gzip.
 */
if (!ini_get('zlib.output_compression') && !headers_sent()) {
    ini_set('zlib.output_compression', '6');
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method !== 'GET' && $method !== 'HEAD') {
    send_json_error('method_not_allowed', 'Only GET requests are allowed.', 405);
}

$categoryId = require_query_id('id');

try {
    $pdo = create_database_connection();

    $statement = $pdo->prepare('SELECT icon_svg, MD5(icon_svg) AS fingerprint FROM categories WHERE id = :id');
    $statement->bindValue(':id', $categoryId, PDO::PARAM_INT);
    $statement->execute();

    $row = $statement->fetch();

    $svg = is_array($row) ? $row['icon_svg'] : null;

    if (!is_string($svg) || trim($svg) === '') {
        // Covers both "no such category" and "category without an icon".
        send_json_error('icon_not_found', 'This category has no stored icon.', 404);
    }

    /*
     * The fingerprint is the MD5 of the drawing - the same value the address
     * carries. A browser that already has this drawing is therefore answered
     * with "nothing changed" instead of 350 KB once more.
     */
    $etag = '"' . substr(is_array($row) ? (string) $row['fingerprint'] : '', 0, 8) . '"';
    $ifNoneMatch = $_SERVER['HTTP_IF_NONE_MATCH'] ?? '';

    if (!headers_sent()) {
        http_response_code(200);
        header('Content-Type: image/svg+xml; charset=utf-8');
        // Nothing may be loaded from anywhere while this file is shown.
        header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; sandbox");
        header('X-Content-Type-Options: nosniff');
        header('ETag: ' . $etag);
        /*
         * The address carries the fingerprint and therefore never changes while
         * the drawing stays the same: a new icon is a new address. That is what
         * makes "immutable" honest here.
         */
        header('Cache-Control: public, max-age=604800, immutable');
    }

    if (is_string($ifNoneMatch) && $ifNoneMatch !== '' && strpos($ifNoneMatch, $etag) !== false) {
        /* The browser already has this drawing. */
        http_response_code(304);
        exit;
    }

    echo $svg;
} catch (Throwable $error) {
    // The real reason goes to the server log only.
    error_log('Serving a category icon failed: ' . $error->getMessage());

    send_json_error('icon_unavailable', 'The icon could not be loaded.', 500);
}
