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
 *     "icon_svg": "<svg ...>",        // null removes the icon
 *     "icon_scale": 1.15              // null resets it to 1.00
 *   }
 *
 * DELETE body (optional):
 *   {"confirm_name": "History"}
 *
 * Deleting removes the category, every subcategory below it and every card in
 * that subtree, because the foreign keys of this database are ON DELETE
 * RESTRICT and would otherwise refuse the delete. Everything happens in one
 * transaction, so a half deleted tree can never be left behind.
 *
 * A delete request needs NO body. The name only has to be repeated when
 * something really depends on the category - subcategories or cards - and the
 * server decides that from the data, not the browser:
 *
 *   - an empty category is deleted with the id alone
 *   - a category with subcategories or cards needs "confirm_name"
 *
 * Any of the three names of the category (the neutral one, the English and the
 * German wording) confirms it, compared without upper and lower case.
 *
 * The answer is
 *   {"success": true, "data": {"deleted_category_id": 7, ...}}
 * and the endpoint only answers that after the row is really gone: the service
 * checks its own delete count and looks the id up once more before it commits.
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

/*
 * The body is optional here. A DELETE that needs nothing but an id sends none at
 * all, and a PATCH sends the fields it wants to change.
 */
$body = read_json_object(true);

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
        /*
         * How much depends on this category decides whether the request has to
         * repeat the name. That is counted HERE, from the database - a hand
         * written request cannot skip the confirmation by leaving the field out,
         * and the browser cannot demand one where none is needed.
         */
        $dependents = category_delete_dependents($pdo, $categoryId);

        if ($dependents['descendants'] > 0 || $dependents['cards'] > 0) {
            $confirmName = optional_confirm_name($body);

            if ($confirmName === null) {
                send_json_error(
                    'confirm_name_required',
                    'This category has subcategories or cards. Repeat its name to confirm.',
                    400
                );
            }

            if (!category_name_matches($current, $confirmName)) {
                send_json_error(
                    'name_mismatch',
                    'The name does not match this category.',
                    400
                );
            }
        }

        try {
            $deleted = delete_category_tree($pdo, $categoryId);
        } catch (PDOException $error) {
            /* A row that still points at this category: a conflict in the data,
               not a broken server. */
            if ((string) $error->getCode() === '23000') {
                send_json_error(
                    'category_delete_conflict',
                    'Other data still refers to this category.',
                    409
                );
            }

            throw $error;
        } catch (RuntimeException $error) {
            /* The service refused to commit because the row was still there. */
            error_log('Deleting category ' . $categoryId . ' was refused: ' . $error->getMessage());

            send_json_error('category_delete_failed', 'The category could not be deleted.', 500);
        }

        send_json_success([
            'deleted_category_id' => $categoryId,
            'deleted_categories' => $deleted['categories'],
            'deleted_cards' => $deleted['cards'],
            'deleted_progress' => $deleted['progress'],
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

    /*
     * A normalised drawing fills its circle at scale 1, so the value is set
     * automatically whenever an icon is part of this request. The column is
     * still written - it is simply never filled in by a person any more.
     */
    if (array_key_exists('icon_svg', $changes) && $changes['icon_svg'] !== null
        && !array_key_exists('icon_scale', $changes)) {
        $changes['icon_scale'] = 1.0;
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

    if ($method === 'DELETE') {
        send_json_error('category_delete_failed', 'The category could not be deleted.', 500);
    }

    send_json_error('category_update_failed', 'The category could not be saved.', 500);
}
