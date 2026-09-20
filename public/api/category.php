<?php

declare(strict_types=1);

/**
 * PATCH  /api/category.php?id=7  -> changes the fields that are sent
 * DELETE /api/category.php?id=7  -> removes the category and everything in it
 *
 * PATCH body (only the fields that should change):
 *   {
 *     "name": "History",              // also: name_en, name_de
 *     "description_en": "...",        // also: description_de
 *     "color": "#8E9AB0",             // null removes the colour
 *     "icon_svg": "<svg ...>",        // null removes the icon
 *     "icon_scale": 1.15              // null resets it to 1.00
 *   }
 *
 * DELETE body:
 *   {"confirm_name": "History"}
 *
 * Deleting removes the category, every subcategory below it and every card in
 * that subtree, because the foreign keys of this database are ON DELETE
 * RESTRICT and would otherwise refuse the delete. Everything happens in one
 * transaction, so a half deleted tree can never be left behind.
 *
 * Because that is destructive, the request has to repeat the name of the
 * category. The comparison ignores upper and lower case and spaces around it.
 */

require_once __DIR__ . '/../../src/config/database.php';
require_once __DIR__ . '/../../src/helpers/json_response.php';
require_once __DIR__ . '/../../src/helpers/request_input.php';
require_once __DIR__ . '/../../src/helpers/svg_sanitizer.php';
require_once __DIR__ . '/../../src/services/category_service.php';

$method = $_SERVER['REQUEST_METHOD'] ?? '';

if ($method !== 'PATCH' && $method !== 'DELETE') {
    send_json_error('method_not_allowed', 'Only PATCH and DELETE requests are allowed.', 405);
}

$categoryId = require_query_id('id');
$body = read_json_object();

try {
    $pdo = create_database_connection();

    $current = find_category($pdo, $categoryId, false);

    if ($current === null) {
        send_json_error('category_not_found', 'This category does not exist.', 404);
    }

    /* ---------------------------------------------------------------------
       DELETE: the name has to be repeated, then the whole subtree disappears
       --------------------------------------------------------------------- */

    if ($method === 'DELETE') {
        $confirmName = require_confirm_name($body);

        if (mb_strtolower($confirmName) !== mb_strtolower((string) $current['name'])) {
            send_json_error(
                'name_mismatch',
                'The name does not match this category.',
                400
            );
        }

        $deleted = delete_category_tree($pdo, $categoryId);

        send_json_success([
            'deleted_categories' => $deleted['categories'],
            'deleted_cards' => $deleted['cards'],
        ]);
    }

    /* ---------------------------------------------------------------------
       PATCH: change only the fields that were really sent
       --------------------------------------------------------------------- */

    $changes = [];

    if (array_key_exists('name', $body)) {
        $changes['name'] = require_input_text($body, 'name', CATEGORY_MAX_NAME_LENGTH, 'invalid_name');
    }

    foreach ([
        'name_en' => CATEGORY_MAX_NAME_LENGTH,
        'name_de' => CATEGORY_MAX_NAME_LENGTH,
        'description_en' => CATEGORY_MAX_DESCRIPTION_LENGTH,
        'description_de' => CATEGORY_MAX_DESCRIPTION_LENGTH,
    ] as $field => $maxLength) {
        if (array_key_exists($field, $body)) {
            // An empty value clears the field.
            $changes[$field] = optional_input_text($body, $field, $maxLength, 'invalid_' . $field);
        }
    }

    if (array_key_exists('color', $body)) {
        $changes['color'] = optional_hex_color($body, 'color', 'invalid_color');
    }

    if (array_key_exists('icon_scale', $body)) {
        $scale = optional_icon_scale($body, 'icon_scale', 'invalid_icon_scale');
        $changes['icon_scale'] = $scale ?? 1.0;
    }

    if (array_key_exists('icon_svg', $body)) {
        // null or an empty string removes the icon and the category falls back
        // to the illustration that ships with the app.
        $changes['icon_svg'] = $body['icon_svg'] === null || $body['icon_svg'] === ''
            ? null
            : optional_svg_icon($body, 'icon_svg', 'invalid_icon');
    }

    if ($changes === []) {
        send_json_error('invalid_request_body', 'Send at least one field to change.', 400);
    }

    // A renamed category must not collide with a sibling.
    if (array_key_exists('name', $changes) && $changes['name'] !== $current['name']) {
        if (category_sibling_name_exists($pdo, $changes['name'], $current['parent_id'], $categoryId)) {
            send_json_error('category_exists', 'A category with this name already exists here.', 409);
        }
    }

    $updated = update_category($pdo, $categoryId, $changes);

    send_json_success($updated);
} catch (Throwable $error) {
    // Details go to the server log only; the browser gets a generic message.
    error_log('Changing a category failed: ' . $error->getMessage());

    send_json_error('category_update_failed', 'The category could not be saved.', 500);
}
