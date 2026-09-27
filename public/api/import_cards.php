<?php

declare(strict_types=1);

/**
 * POST /api/import_cards.php -> prüft eine CSV-Datei oder legt ihre Karten an
 *
 * Die Anfrage ist multipart/form-data und trägt
 *
 *   file         die CSV-Datei (nötig, .csv, höchstens 1 MB)
 *   category_id  die Unterkategorie, zu der die Karten gehören (nötig)
 *   mode         "preview" (Vorgabe) oder "import"
 *
 * Der Vorschaumodus ändert nichts: er liest die Datei, prüft jede Zeile und antwortet
 * mit der Auswertung, der Fehlerliste und den ersten Zeilen - genau das zeigt der
 * Dialog, bevor etwas gespeichert wird.
 *
 * Die Kopfzeile darf neben den fünf nötigen Spalten eine weitere, freiwillige tragen:
 * "exercise" benennt eine erzeugte Aufgabe statt einer festen Karte, zum Beispiel
 * "times_table:min=2,max=20". Geprüft wird sie mit exercise_parse_cell() aus
 * src/services/exercise_service.php, derselben Funktion, die auch der Import auf der
 * Kommandozeile und der Kartendialog benutzen. Eine Datei ohne diese Spalte verhält
 * sich genau wie vorher.
 *
 * Der Importmodus wiederholt die ganze Prüfung auf dem Server (die Vorschau im Browser
 * ist nur Bequemlichkeit) und schreibt dann alle Zeilen in EINER Transaktion. Eine
 * Datei mit einer einzigen schlechten Zeile wird komplett abgelehnt, einen halben
 * Import gibt es also nicht.
 *
 * Der Endpunkt schreibt nie in eine andere Kategorie als die mitgeschickte, rührt die
 * Tabellenstruktur nicht an und legt keine Fortschrittszeile an.
 */

require_once __DIR__ . '/../../src/config/database.php';
require_once __DIR__ . '/../../src/helpers/json_response.php';
require_once __DIR__ . '/../../src/helpers/request_input.php';
require_once __DIR__ . '/../../src/helpers/session_user.php';
require_once __DIR__ . '/../../src/services/category_service.php';
require_once __DIR__ . '/../../src/services/card_service.php';
require_once __DIR__ . '/../../src/services/card_import_service.php';

/** Wie viele Zeilen die Vorschauliste zeigt. Der Dialog sagt "und N weitere". */
const CARD_IMPORT_PREVIEW_ROWS = 10;

$method = $_SERVER['REQUEST_METHOD'] ?? '';

if ($method !== 'POST') {
    send_json_error('method_not_allowed', 'Only POST requests are allowed.', 405);
}

$mode = ($_POST['mode'] ?? 'preview') === 'import' ? 'import' : 'preview';

/* ------------------------------------------------------------------ upload */

/*
 * Eine Anfrage, die größer ist als post_max_size, kommt mit leerem $_POST und $_FILES
 * an. Die Längenangabe im Kopf ist dann das Einzige, was "keine Datei" und "Datei viel
 * zu groß" noch unterscheidet, deshalb wird sie zuerst geprüft.
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

/* Nur .csv wird angenommen, und nur eine Datei, die wirklich per POST kam. */
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
    $userId = current_user_id($pdo);

    /* Importierte Karten landen in einer Kategorie eines Kontos, dafür braucht es eines. */
    if ($userId === null) {
        $required = session_user_required_error();

        send_json_error($required['code'], $required['message'], $required['status']);
    }

    if (!category_exists($pdo, $categoryId, $userId)) {
        send_json_error('category_not_found', 'This category does not exist.', 404);
    }

    $read = card_import_read_file($temporaryPath);
    $tableColumns = card_columns($pdo);
    $fatal = $read['fatal'];

    if ($fatal === null) {
        /*
         * Ob diese Installation überhaupt Aufgaben speichern kann - eine Datenbank, in
         * der database/add_exercise_params.sql nie gelaufen ist, kann trotzdem feste
         * Karten importieren.
         */
        $exercisesPossible = card_exercise_table_available($pdo) && card_exercise_params_available($pdo);
        $checked = card_import_validate($read, card_import_existing_fronts($pdo, $categoryId, $userId), $tableColumns, $exercisesPossible);
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

    $imported = card_import_insert($pdo, $categoryId, $checked['cards'], $userId);

    send_json_success([
        'imported' => $imported,
        'duplicates' => $checked['duplicates'],
        'row_count' => $read['data_rows'],
    ], 201);
} catch (Throwable $error) {
    /* Der Grund landet im Protokoll, die Antwort bleibt allgemein. */
    error_log('Importing cards failed: ' . $error->getMessage());

    send_json_error('import_failed', 'The cards could not be imported. Nothing was saved.', 500);
}
