<?php

declare(strict_types=1);

/**
 * POST /api/import_cards.php -> checks a CSV file, or imports its cards
 *
 * The request is multipart/form-data and carries
 *
 *   file         the CSV file (required, .csv, at most 1 MB)
 *   category_id  the subcategory the cards belong to (required)
 *   mode         "preview" (default) or "import"
 *
 * Preview mode changes nothing: it reads the file, checks every row and answers
 * with the summary, the error list and the first rows - that is what the dialog
 * shows before anything is stored.
 *
 * The header may carry one more, optional column besides the five required ones:
 * "exercise" names a generated task instead of a fixed card, for example
 * "times_table:min=2,max=20". It is checked with exercise_parse_cell() from
 * src/services/exercise_service.php, the same function the command line importer
 * and the card dialog use. A file without that column behaves exactly as before.
 *
 * Import mode repeats the whole check on the server (the preview in the browser
 * is only comfort) and then writes all rows in ONE transaction. A file with a
 * single bad row is refused completely, so there is no such thing as half an
 * import.
 *
 * The endpoint never writes into another category than the one that was sent,
 * never touches the table structure and never creates a progress row.
 */

require_once __DIR__ . '/../../src/config/database.php';
require_once __DIR__ . '/../../src/helpers/json_response.php';
require_once __DIR__ . '/../../src/helpers/request_input.php';
require_once __DIR__ . '/../../src/services/category_service.php';
require_once __DIR__ . '/../../src/services/card_service.php';
require_once __DIR__ . '/../../src/services/card_import_service.php';

/** How many rows the preview table shows. The dialog says "and N more". */
const CARD_IMPORT_PREVIEW_ROWS = 10;

$method = $_SERVER['REQUEST_METHOD'] ?? '';

if ($method !== 'POST') {
    send_json_error('method_not_allowed', 'Only POST requests are allowed.', 405);
}

$mode = ($_POST['mode'] ?? 'preview') === 'import' ? 'import' : 'preview';

/* ------------------------------------------------------------------ upload */

/*
 * A request that is bigger than post_max_size arrives with empty $_POST and
 * $_FILES. The length header is the only thing left to tell "no file" and "file
 * far too big" apart, so it is checked first.
 */
$contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
$file = $_FILES['file'] ?? null;

if (!is_array($file)) {
    if ($contentLength > CARD_IMPORT_MAX_BYTES) {
        send_json_error('file_too_large', 'The file is larger than the limit of 1 MB.', 400);
    }

    send_json_error('file_missing', 'No file was received.', 400);
}

$uploadError = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);

if ($uploadError === UPLOAD_ERR_INI_SIZE || $uploadError === UPLOAD_ERR_FORM_SIZE) {
    send_json_error('file_too_large', 'The file is larger than the limit of 1 MB.', 400);
}

if ($uploadError !== UPLOAD_ERR_OK) {
    send_json_error('file_missing', 'The file could not be received.', 400);
}

$size = (int) ($file['size'] ?? 0);

if ($size <= 0) {
    send_json_error('empty_file', 'The file is empty.', 400);
}

if ($size > CARD_IMPORT_MAX_BYTES) {
    send_json_error('file_too_large', 'The file is larger than the limit of 1 MB.', 400);
}

$name = basename((string) ($file['name'] ?? ''));
$extension = mb_strtolower((string) pathinfo($name, PATHINFO_EXTENSION));

/* Only .csv is accepted, and only a file that really came through a POST. */
if ($extension !== 'csv') {
    send_json_error('file_type', 'Only .csv files are accepted.', 400);
}

$temporaryPath = (string) ($file['tmp_name'] ?? '');

if ($temporaryPath === '' || !is_uploaded_file($temporaryPath)) {
    send_json_error('file_missing', 'The file could not be received.', 400);
}

/* ------------------------------------------------------------- category id */

$categoryId = ctype_digit((string) ($_POST['category_id'] ?? '')) ? (int) $_POST['category_id'] : 0;

if ($categoryId <= 0) {
    send_json_error('invalid_category_id', 'The field "category_id" must be a positive whole number.', 400);
}

try {
    $pdo = create_database_connection();

    if (!category_exists($pdo, $categoryId)) {
        send_json_error('category_not_found', 'This category does not exist.', 404);
    }

    $read = card_import_read_file($temporaryPath);
    $tableColumns = card_columns($pdo);
    $fatal = $read['fatal'];

    if ($fatal === null) {
        /*
         * Whether this installation can store exercises at all - a database that
         * never ran database/add_exercise_params.sql can still import fixed cards.
         */
        $exercisesPossible = card_exercise_table_available($pdo) && card_exercise_params_available($pdo);
        $checked = card_import_validate($read, card_import_existing_fronts($pdo, $categoryId), $tableColumns, $exercisesPossible);
        $preview = array_slice($checked['preview'], 0, CARD_IMPORT_PREVIEW_ROWS);
    } else {
        $checked = ['cards' => [], 'errors' => [], 'preview' => [], 'importable' => 0, 'duplicates' => 0, 'invalid' => 0];
        $preview = [];
    }

    if ($mode === 'preview') {
        send_json_success([
            'file' => $name,
            'columns' => $read['columns'],
            'expected_columns' => CARD_IMPORT_HEADER,
            'optional_columns' => CARD_IMPORT_OPTIONAL,
            'row_count' => $read['data_rows'],
            'importable' => $checked['importable'],
            'duplicates' => $checked['duplicates'],
            'invalid' => $checked['invalid'],
            'errors' => $checked['errors'],
            'preview' => $preview,
            'hidden' => max(0, count($checked['preview']) - count($preview)),
            'fatal' => $fatal,
            'limits' => ['rows' => CARD_IMPORT_MAX_ROWS, 'bytes' => CARD_IMPORT_MAX_BYTES, 'text' => CARD_MAX_TEXT_LENGTH],
        ]);
    }

    /* ------------------------------------------------------------- import */

    if ($fatal !== null) {
        send_json_error('invalid_file', 'This file cannot be imported.', 400);
    }

    if ($checked['invalid'] > 0) {
        send_json_error('invalid_rows', 'Some rows contain errors, so nothing was imported.', 400);
    }

    if ($checked['importable'] === 0) {
        send_json_error('nothing_to_import', 'Every row is already in this subcategory.', 400);
    }

    $imported = card_import_insert($pdo, $categoryId, $checked['cards']);

    send_json_success([
        'imported' => $imported,
        'duplicates' => $checked['duplicates'],
        'row_count' => $read['data_rows'],
    ], 201);
} catch (Throwable $error) {
    /* The reason is written into the log; the answer stays generic. */
    error_log('Importing cards failed: ' . $error->getMessage());

    send_json_error('import_failed', 'The cards could not be imported. Nothing was saved.', 500);
}
