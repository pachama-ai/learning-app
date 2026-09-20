<?php

declare(strict_types=1);

/**
 * GET  /api/categories.php              -> the learning areas
 * GET  /api/categories.php?parent_id=2  -> the subcategories of category 2
 * GET  /api/categories.php?id=2         -> one category, plus a delete preview
 * POST /api/categories.php              -> creates a learning area or subcategory
 *
 * Body of the POST (all fields except "name" are optional):
 *   {
 *     "parent_id": 2,                  // omitted or null -> a learning area
 *     "name": "History",
 *     "name_en": "History",            // wording shown in English
 *     "name_de": "Geschichte",         // wording shown in German
 *     "description_en": "...",
 *     "description_de": "...",
 *     "icon_svg": "<svg ...>",
 *     "icon_scale": 1.15
 *   }
 *
 * Every value is validated before it reaches the database, and the SVG is
 * sanitised (see src/helpers/svg_sanitizer.php). The answer never contains a
 * database error, SQL or credentials.
 */

require_once __DIR__ . '/../../src/config/database.php';
require_once __DIR__ . '/../../src/helpers/json_response.php';
require_once __DIR__ . '/../../src/helpers/request_input.php';
require_once __DIR__ . '/../../src/helpers/svg_sanitizer.php';
require_once __DIR__ . '/../../src/services/category_service.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method !== 'GET' && $method !== 'POST') {
    send_json_error('method_not_allowed', 'Only GET and POST requests are allowed.', 405);
}

/* -------------------------------------------------------------------------
   POST: create a learning area (no parent_id) or a subcategory (parent_id)
   ------------------------------------------------------------------------- */

if ($method === 'POST') {
    $body = read_json_object();

    $parentId = optional_positive_id($body, 'parent_id', 'invalid_parent_id');
    $name = require_input_text($body, 'name', CATEGORY_MAX_NAME_LENGTH, 'invalid_name');
    $nameEn = optional_input_text($body, 'name_en', CATEGORY_MAX_NAME_LENGTH, 'invalid_name_en');
    $nameDe = optional_input_text($body, 'name_de', CATEGORY_MAX_NAME_LENGTH, 'invalid_name_de');
    $descriptionEn = optional_input_text($body, 'description_en', CATEGORY_MAX_DESCRIPTION_LENGTH, 'invalid_description_en');
    $descriptionDe = optional_input_text($body, 'description_de', CATEGORY_MAX_DESCRIPTION_LENGTH, 'invalid_description_de');
    $iconSvg = optional_svg_icon($body, 'icon_svg', 'invalid_icon');
    $iconScale = optional_icon_scale($body, 'icon_scale', 'invalid_icon_scale');

    try {
        $pdo = create_database_connection();

        // A subcategory needs a parent that really exists.
        if ($parentId !== null && !category_exists($pdo, $parentId)) {
            send_json_error('parent_not_found', 'The parent category does not exist.', 404);
        }

        // There is no unique index on the name column and the structure must not
        // be changed, so the duplicate check happens here. Two categories with
        // the same name may exist in different places, but not under one parent.
        if (category_sibling_name_exists($pdo, $name, $parentId)) {
            send_json_error('category_exists', 'A category with this name already exists here.', 409);
        }

        $fields = ['parent_id' => $parentId, 'name' => $name];

        /*
         * The icon scale is automatic: a drawing is normalised before it is
         * stored, so it always fills its circle at scale 1. The value is only
         * written when an icon really is part of this request, and an explicit
         * value from an older client is still accepted.
         */
        foreach ([
            'name_en' => $nameEn,
            'name_de' => $nameDe,
            'description_en' => $descriptionEn,
            'description_de' => $descriptionDe,
            'icon_svg' => $iconSvg,
            'icon_scale' => $iconScale,
        ] as $column => $value) {
            if ($value !== null) {
                $fields[$column] = $value;
            }
        }

        if (isset($fields['icon_svg']) && !array_key_exists('icon_scale', $fields)) {
            $fields['icon_scale'] = 1.0;
        }

        $created = create_category($pdo, $fields);

        send_json_success($created, 201);
    } catch (Throwable $error) {
        // Details go to the server log only; the browser gets a generic message.
        error_log('Creating a category failed: ' . $error->getMessage());

        send_json_error('category_create_failed', 'The category could not be saved.', 500);
    }
}

/* -------------------------------------------------------------------------
   GET: list the learning areas, list the subcategories or read one category
   ------------------------------------------------------------------------- */

$categoryId = null;
$rawId = $_GET['id'] ?? null;

if ($rawId !== null && $rawId !== '') {
    // is_string() also rejects array input such as ?id[]=1. Without it an array
    // would reach ctype_digit() and then the database layer.
    if (!is_string($rawId) || !ctype_digit($rawId) || (int) $rawId < 1) {
        send_json_error('invalid_id', 'The id parameter must be a positive integer.', 400);
    }

    $categoryId = (int) $rawId;
}

$parentId = null;
$rawParentId = $_GET['parent_id'] ?? null;

// An empty parameter means "no filter" and returns the main categories, which
// keeps ?parent_id= harmless in a hand-typed URL.
if ($rawParentId !== null && $rawParentId !== '') {
    if (!is_string($rawParentId) || !ctype_digit($rawParentId) || (int) $rawParentId < 1) {
        send_json_error(
            'invalid_parent_id',
            'The parent_id parameter must be a positive integer.',
            400
        );
    }

    $parentId = (int) $rawParentId;
}

try {
    $pdo = create_database_connection();

    if ($categoryId !== null) {
        $category = find_category($pdo, $categoryId, true);

        if ($category === null) {
            send_json_error('category_not_found', 'This category does not exist.', 404);
        }

        send_json_success($category);
    }

    $categories = $parentId === null
        ? find_main_categories($pdo)
        : find_subcategories($pdo, $parentId);

    // An empty result is a valid answer and is returned as an empty array, so
    // the frontend can show its empty state instead of treating it as an error.
    send_json_success($categories);
} catch (Throwable $error) {
    // The real reason goes to the server log only. It can contain the password,
    // the connection string or SQL, so the browser only gets a generic message.
    error_log('Loading categories failed: ' . $error->getMessage());

    send_json_error(
        'categories_unavailable',
        'The categories could not be loaded.',
        500
    );
}
