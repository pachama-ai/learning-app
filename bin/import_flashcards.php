<?php

declare(strict_types=1);

/**
 * One-off importer for database/import/energy_flashcards_compact.csv
 *
 * Usage (from the project root):
 *
 *   php bin/import_flashcards.php --file=database/import/energy_flashcards_compact.csv --dry-run
 *   php bin/import_flashcards.php --file=database/import/energy_flashcards_compact.csv --execute
 *
 * The script lives in bin/ on purpose: it is a command line tool, it is NOT part
 * of the web root (Apache only serves public/), and it therefore has no public
 * URL. There is no upload endpoint for this import and there is no web form.
 *
 * --dry-run  validates everything and reports what would happen. It only reads.
 * --execute  writes the data, but only after the whole file passed validation.
 *            All writes happen inside ONE transaction: if a single row is
 *            invalid or the database refuses something, nothing is written at
 *            all and the database is exactly as it was before.
 *
 * What the import does (and what it deliberately does not do):
 *
 *   * It reads the German columns. The English columns of this file are empty
 *     on purpose, so `name_en` is left NULL - the application then falls back to
 *     `name`, which is exactly the "German text in English mode" that is wanted.
 *   * `is_two_sided` from the CSV is the `cards.is_bidirectional` column of the
 *     real table (the table has no column called `is_two_sided`).
 *   * `category_key`, `subcategory_key` and `sort_order` are not stored: the
 *     table has no columns for them and the structure must not be changed. The
 *     cards are inserted in CSV order, and the API lists cards by id, so the
 *     order of the file is the order in the application.
 *   * No category is renamed, deleted or overwritten. Nothing but INSERT is
 *     used. Categories are matched by name; only the missing ones are created.
 *   * An identical card in the same subcategory is skipped, so running the
 *     import twice inserts nothing the second time.
 */

/* --------------------------------------------------------------------------
   Setup
   -------------------------------------------------------------------------- */

/* The tool is only meant to be started from a shell. Over HTTP it answers like
   a missing page: it is not part of the application. */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    echo "Not found.\n";
    exit(1);
}

$projectRoot = dirname(__DIR__);

require_once $projectRoot . '/src/config/database.php';
require_once $projectRoot . '/src/services/category_service.php';
require_once $projectRoot . '/src/services/card_service.php';

/** The header of the file, in this exact order. */
const CSV_HEADER = [
    'category_key',
    'category_de',
    'category_en',
    'subcategory_key',
    'subcategory_de',
    'subcategory_en',
    'front',
    'back',
    'is_two_sided',
    'sort_order',
];

/** The one learning area this import is about (German name from the CSV). */
const EXPECTED_AREA_DE = 'Energie';

/**
 * The six subcategories that must exist under that area, in the requested order.
 *
 * The list is the contract of this one-off import: it keeps the structure at
 * exactly two levels and refuses a file that would add a different one.
 */
const EXPECTED_SUBCATEGORIES_DE = [
    'Strom und Elektrotechnik',
    'Energieträger und Stromerzeugung',
    'Stromnetz und Übertragungsnetz',
    'Strommarkt und Marktkommunikation',
    'Systembetrieb, Regelenergie und Redispatch',
    'Energiegeschichte, Mobilität und Energiewende',
];

/* Column numbers, so the code below never uses a bare number. */
const COLUMN_CATEGORY_DE = 1;
const COLUMN_SUBCATEGORY_DE = 4;
const COLUMN_FRONT = 6;
const COLUMN_BACK = 7;
const COLUMN_IS_TWO_SIDED = 8;

/**
 * Runs the whole tool and returns the exit code.
 */
function import_main(array $argv, string $projectRoot): int
{
    $options = import_read_arguments($argv, $projectRoot);

    if ($options === null) {
        return 1;
    }

    $file = $options['file'];
    $execute = $options['execute'];

    echo "Import file : " . import_relative_path($file, $projectRoot) . "\n";
    echo "Mode        : " . ($execute ? "EXECUTE (writes to the database)" : "DRY RUN (reads only)") . "\n\n";

    /* ---- 1. read and validate the file (no database involved yet) -------- */

    $csv = import_read_csv($file);

    if ($csv['fatal'] !== null) {
        echo 'FILE ERROR: ' . $csv['fatal'] . "\n";

        return 1;
    }

    $rows = $csv['rows'];
    $errors = $csv['errors'];

    if ($errors !== []) {
        echo "VALIDATION ERRORS (" . count($errors) . "):\n";

        foreach ($errors as $error) {
            echo '  line ' . $error['line'] . ': ' . $error['message'] . "\n";
        }

        echo "\nNothing was written. The file must be corrected first.\n";

        return 1;
    }

    /* ---- 2. look at the database and work out a plan --------------------- */

    try {
        $pdo = create_database_connection();
    } catch (Throwable $error) {
        /* The reason may name the host or the user, so it stays in this shell
           and is not printed as part of a page. */
        echo "DATABASE ERROR: the connection could not be opened.\n";
        echo '  ' . $error->getMessage() . "\n";

        return 1;
    }

    try {
        $plan = import_plan($pdo, $rows);
    } catch (Throwable $error) {
        echo 'DATABASE ERROR while reading: ' . $error->getMessage() . "\n";

        return 1;
    }

    /* ---- 3. report -------------------------------------------------------- */

    import_print_summary($csv, $plan, $execute);

    if (!$execute) {
        echo "\nDRY RUN: nothing was written to the database.\n";

        return 0;
    }

    /* ---- 4. write, all of it or none of it ------------------------------- */

    try {
        $written = import_execute($pdo, $plan);
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        echo "\nIMPORT FAILED - the database was rolled back and is unchanged.\n";
        echo '  ' . $error->getMessage() . "\n";

        return 1;
    }

    echo "\nImport finished.\n";
    echo '  categories created   : ' . $written['categories_created'] . "\n";
    echo '  subcategories created: ' . $written['subcategories_created'] . "\n";
    echo '  cards inserted       : ' . $written['cards_inserted'] . "\n";
    echo '  duplicates skipped   : ' . $written['duplicates_skipped'] . "\n";

    return 0;
}

/* --------------------------------------------------------------------------
   Arguments
   -------------------------------------------------------------------------- */

/**
 * Reads --file, --dry-run and --execute.
 *
 * Without a file name the default file of this import is used. Without a mode
 * the tool stays on the safe side and only reads.
 *
 * @return array{file: string, execute: bool}|null
 */
function import_read_arguments(array $argv, string $projectRoot): ?array
{
    $file = 'database/import/energy_flashcards_compact.csv';
    $execute = false;
    $modeGiven = false;

    foreach (array_slice($argv, 1) as $argument) {
        if (strpos($argument, '--file=') === 0) {
            $file = substr($argument, 7);
            continue;
        }

        if ($argument === '--dry-run') {
            if ($modeGiven && $execute) {
                echo "Choose either --dry-run or --execute, not both.\n";

                return null;
            }

            $execute = false;
            $modeGiven = true;
            continue;
        }

        if ($argument === '--execute') {
            if ($modeGiven && !$execute) {
                echo "Choose either --dry-run or --execute, not both.\n";

                return null;
            }

            $execute = true;
            $modeGiven = true;
            continue;
        }

        if ($argument === '--help' || $argument === '-h') {
            import_print_usage();

            return null;
        }

        echo 'Unknown argument: ' . $argument . "\n\n";
        import_print_usage();

        return null;
    }

    if ($file === '') {
        echo "The --file= path is empty.\n";

        return null;
    }

    /* A relative path is read from the project root, so the tool can be started
       from anywhere. */
    if (preg_match('/^([a-zA-Z]:|[\/\\\\])/', $file) !== 1) {
        $file = $projectRoot . '/' . $file;
    }

    $real = realpath($file);

    if ($real === false || !is_file($real)) {
        echo 'File not found: ' . $file . "\n";

        return null;
    }

    return ['file' => $real, 'execute' => $execute];
}

function import_print_usage(): void
{
    echo "Usage:\n";
    echo "  php bin/import_flashcards.php --file=database/import/energy_flashcards_compact.csv --dry-run\n";
    echo "  php bin/import_flashcards.php --file=database/import/energy_flashcards_compact.csv --execute\n";
}

/** A short path for the output, without the project root. */
function import_relative_path(string $path, string $projectRoot): string
{
    return strpos($path, $projectRoot . '/') === 0
        ? substr($path, strlen($projectRoot) + 1)
        : $path;
}

/* --------------------------------------------------------------------------
   Reading and validating the CSV
   -------------------------------------------------------------------------- */

/**
 * Reads the file and validates every row.
 *
 * Nothing here touches the database: a file that is not usable is refused
 * before the first query is sent.
 *
 * @return array{
 *     rows: list<array{line: int, area: string, subcategory: string, front: string, back: string, is_two_sided: bool}>,
 *     errors: list<array{line: int, message: string}>,
 *     empty_lines: int,
 *     header: list<string>,
 *     fatal: string|null
 * }
 */
function import_read_csv(string $path): array
{
    $empty = ['rows' => [], 'errors' => [], 'empty_lines' => 0, 'header' => [], 'fatal' => null];

    $handle = fopen($path, 'rb');

    if ($handle === false) {
        return ['rows' => [], 'errors' => [], 'empty_lines' => 0, 'header' => [], 'fatal' => 'the file could not be opened'];
    }

    /* A UTF-8 BOM in front of the first header name would make the header
       comparison fail. The three bytes are read and dropped here, before
       fgetcsv() sees them. */
    $bom = fread($handle, 3);

    if ($bom !== "\xEF\xBB\xBF") {
        rewind($handle);
    }

    $header = fgetcsv($handle, 0, ';');

    if ($header === false || $header === [null]) {
        fclose($handle);

        return ['rows' => [], 'errors' => [], 'empty_lines' => 0, 'header' => [], 'fatal' => 'the file is empty'];
    }

    /* Defensive: if a BOM appears inside the first cell (some tools write it
       that way), it is removed as well. */
    $header[0] = import_strip_bom((string) $header[0]);

    $header = array_map(static fn ($name): string => trim((string) $name), $header);

    if ($header !== CSV_HEADER) {
        fclose($handle);

        return [
            'rows' => [],
            'errors' => [],
            'empty_lines' => 0,
            'header' => $header,
            'fatal' => 'the header does not match. Expected: ' . implode(';', CSV_HEADER)
                . ' - found: ' . implode(';', $header),
        ];
    }

    $rows = [];
    $errors = [];
    $emptyLines = 0;
    $lineNumber = 1;
    $seen = [];

    while (($cells = fgetcsv($handle, 0, ';')) !== false) {
        $lineNumber++;

        /* fgetcsv() reports a blank line as a single null cell. */
        if ($cells === [null]) {
            $emptyLines++;
            continue;
        }

        if (import_row_is_empty($cells)) {
            $emptyLines++;
            continue;
        }

        $result = import_validate_row($cells, $lineNumber);

        if ($result['error'] !== null) {
            $errors[] = ['line' => $lineNumber, 'message' => $result['error']];
            continue;
        }

        $row = $result['row'];

        /* Two identical cards in the same subcategory inside the file: the
           second one is a duplicate and is reported as such. */
        $key = $row['subcategory'] . "\n" . $row['front'] . "\n" . $row['back'];

        if (isset($seen[$key])) {
            $row['duplicate_in_file'] = true;
        } else {
            $seen[$key] = true;
            $row['duplicate_in_file'] = false;
        }

        $rows[] = $row;
    }

    fclose($handle);

    return [
        'rows' => $rows,
        'errors' => $errors,
        'empty_lines' => $emptyLines,
        'header' => $header,
        'fatal' => null,
    ];
}

function import_strip_bom(string $value): string
{
    return strpos($value, "\xEF\xBB\xBF") === 0 ? substr($value, 3) : $value;
}

/** True when every cell of the row is empty. */
function import_row_is_empty(array $cells): bool
{
    foreach ($cells as $cell) {
        if (trim((string) $cell) !== '') {
            return false;
        }
    }

    return true;
}

/**
 * Validates one row of the file.
 *
 * @return array{row: array<string, mixed>|null, error: string|null}
 */
function import_validate_row(array $cells, int $line): array
{
    if (count($cells) !== count(CSV_HEADER)) {
        return [
            'row' => null,
            'error' => 'expected ' . count(CSV_HEADER) . ' columns, found ' . count($cells),
        ];
    }

    $area = import_clean_text($cells[COLUMN_CATEGORY_DE], CATEGORY_MAX_NAME_LENGTH);

    if ($area['value'] === null) {
        return ['row' => null, 'error' => 'category_de: ' . $area['error']];
    }

    if ($area['value'] !== EXPECTED_AREA_DE) {
        return [
            'row' => null,
            'error' => 'category_de is "' . $area['value'] . '", this import is only about "' . EXPECTED_AREA_DE . '"',
        ];
    }

    $subcategory = import_clean_text($cells[COLUMN_SUBCATEGORY_DE], CATEGORY_MAX_NAME_LENGTH);

    if ($subcategory['value'] === null) {
        return ['row' => null, 'error' => 'subcategory_de: ' . $subcategory['error']];
    }

    if (!in_array($subcategory['value'], EXPECTED_SUBCATEGORIES_DE, true)) {
        return [
            'row' => null,
            'error' => 'subcategory_de "' . $subcategory['value'] . '" is not one of the six requested subcategories'
                . ' (a third level is not part of this import)',
        ];
    }

    $front = import_clean_text($cells[COLUMN_FRONT], CARD_MAX_TEXT_LENGTH);

    if ($front['value'] === null) {
        return ['row' => null, 'error' => 'front: ' . $front['error']];
    }

    $back = import_clean_text($cells[COLUMN_BACK], CARD_MAX_TEXT_LENGTH);

    if ($back['value'] === null) {
        return ['row' => null, 'error' => 'back: ' . $back['error']];
    }

    $twoSided = trim((string) $cells[COLUMN_IS_TWO_SIDED]);

    if ($twoSided !== '0' && $twoSided !== '1') {
        return [
            'row' => null,
            'error' => 'is_two_sided must be 0 or 1, found "' . $twoSided . '"',
        ];
    }

    return [
        'row' => [
            'line' => $line,
            'area' => $area['value'],
            'subcategory' => $subcategory['value'],
            'front' => $front['value'],
            'back' => $back['value'],
            /* The column of the table is called is_bidirectional. */
            'is_two_sided' => $twoSided === '1',
        ],
        'error' => null,
    ];
}

/**
 * Cleans and checks one text cell, using the same rules the API uses for a card.
 *
 * The API helpers answer with a JSON error and stop the request, which is the
 * right thing for an endpoint and the wrong thing for a report that has to name
 * every bad line, so the same rules are applied here and returned instead.
 *
 * @return array{value: string|null, error: string|null}
 */
function import_clean_text($value, int $maxLength): array
{
    $text = trim((string) $value);

    if ($text === '') {
        return ['value' => null, 'error' => 'must not be empty'];
    }

    if (!mb_check_encoding($text, 'UTF-8')) {
        return ['value' => null, 'error' => 'must be valid UTF-8'];
    }

    if (mb_strlen($text) > $maxLength) {
        return ['value' => null, 'error' => 'longer than ' . $maxLength . ' characters'];
    }

    /* Tab and newline are allowed; every other control character is refused. */
    if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $text) === 1) {
        return ['value' => null, 'error' => 'must not contain control characters'];
    }

    return ['value' => $text, 'error' => null];
}

/* --------------------------------------------------------------------------
   Looking at the database and planning the import
   -------------------------------------------------------------------------- */

/**
 * Works out what would happen, without writing anything.
 *
 * @param list<array<string, mixed>> $rows
 * @return array<string, mixed>
 */
function import_plan(PDO $pdo, array $rows): array
{
    $areaRow = import_find_category($pdo, null, [EXPECTED_AREA_DE]);
    $areaId = $areaRow === null ? null : (int) $areaRow['id'];

    $subcategories = [];

    foreach (EXPECTED_SUBCATEGORIES_DE as $name) {
        $existing = $areaId === null ? null : import_find_category($pdo, $areaId, [$name]);

        $subcategories[$name] = [
            'id' => $existing === null ? null : (int) $existing['id'],
            'action' => $existing === null ? 'create' : 'reuse',
            'matched_name' => $existing === null ? null : import_which_name($existing, $name),
        ];
    }

    /* Cards: which ones are already there (in the subcategory, same front and
       back) and which ones would be inserted. */
    $existingCards = [];

    foreach ($subcategories as $name => $subcategory) {
        if ($subcategory['id'] === null) {
            continue;
        }

        foreach (find_cards($pdo, $subcategory['id']) as $card) {
            $existingCards[$name . "\n" . $card['front'] . "\n" . $card['back']] = true;
        }
    }

    $perSubcategory = [];
    $cardsToInsert = [];
    $duplicatesSkipped = 0;

    foreach ($rows as $row) {
        $name = $row['subcategory'];
        $key = $name . "\n" . $row['front'] . "\n" . $row['back'];

        if ($row['duplicate_in_file'] || isset($existingCards[$key])) {
            $duplicatesSkipped++;
            $perSubcategory[$name]['duplicates'] = ($perSubcategory[$name]['duplicates'] ?? 0) + 1;
            continue;
        }

        /* Also counts a card that appears twice in this very file. */
        $existingCards[$key] = true;
        $cardsToInsert[] = $row;
        $perSubcategory[$name]['to_insert'] = ($perSubcategory[$name]['to_insert'] ?? 0) + 1;
    }

    return [
        'area' => [
            'id' => $areaId,
            'action' => $areaRow === null ? 'create' : 'reuse',
            'stored_name' => $areaRow === null ? null : (string) $areaRow['name'],
            'matched_name' => $areaRow === null ? null : import_which_name($areaRow, EXPECTED_AREA_DE),
        ],
        'subcategories' => $subcategories,
        'per_subcategory' => $perSubcategory,
        'cards_to_insert' => $cardsToInsert,
        'duplicates_skipped' => $duplicatesSkipped,
    ];
}

/**
 * Finds a category by its parent and one of its names.
 *
 * A category can carry three names: the neutral one plus the English and the
 * German wording. The file names the German one, and the existing data may hold
 * the English wording in `name` (that is how this database was filled), so all
 * three columns are compared - without upper and lower case, and without
 * surrounding spaces. Nothing is renamed: the row is simply reused.
 *
 * find_main_categories() / find_subcategories() are used, so the very same
 * queries that the API uses are the ones that decide.
 *
 * @param list<string> $names
 * @return array<string, mixed>|null
 */
function import_find_category(PDO $pdo, ?int $parentId, array $names): ?array
{
    $candidates = $parentId === null
        ? find_main_categories($pdo)
        : find_subcategories($pdo, $parentId);

    $wanted = [];

    foreach ($names as $name) {
        $wanted[] = mb_strtolower(trim($name));
    }

    foreach ($candidates as $candidate) {
        foreach (['name', 'name_en', 'name_de'] as $column) {
            if (!isset($candidate[$column]) || !is_string($candidate[$column])) {
                continue;
            }

            if (in_array(mb_strtolower(trim($candidate[$column])), $wanted, true)) {
                return $candidate;
            }
        }
    }

    return null;
}

/** Which of the three name columns matched - used for a transparent report. */
function import_which_name(array $category, string $wanted): string
{
    $needle = mb_strtolower(trim($wanted));

    foreach (['name' => 'name', 'name_en' => 'name_en', 'name_de' => 'name_de'] as $column => $label) {
        if (isset($category[$column]) && is_string($category[$column])
            && mb_strtolower(trim($category[$column])) === $needle) {
            return $label;
        }
    }

    return 'name';
}

/* --------------------------------------------------------------------------
   The report
   -------------------------------------------------------------------------- */

/**
 * Prints what was read and what would happen.
 *
 * @param array<string, mixed> $csv
 * @param array<string, mixed> $plan
 */
function import_print_summary(array $csv, array $plan, bool $execute): void
{
    $rows = $csv['rows'];
    $created = 0;
    $reused = 0;

    foreach ($plan['subcategories'] as $subcategory) {
        if ($subcategory['action'] === 'create') {
            $created++;
        } else {
            $reused++;
        }
    }

    echo "SUMMARY\n";
    printf("  rows read                 : %d\n", count($rows));
    printf("  empty lines skipped       : %d\n", $csv['empty_lines']);
    printf("  invalid rows              : %d\n", count($csv['errors']));
    printf("  areas created             : %d\n", $plan['area']['action'] === 'create' ? 1 : 0);
    printf("  areas reused              : %d\n", $plan['area']['action'] === 'reuse' ? 1 : 0);
    printf("  subcategories created     : %d\n", $created);
    printf("  subcategories reused      : %d\n", $reused);
    printf("  cards inserted            : %d\n", count($plan['cards_to_insert']));
    printf("  duplicate cards skipped   : %d\n", $plan['duplicates_skipped']);

    $area = $plan['area'];

    if ($area['action'] === 'reuse') {
        echo "\nThe area is already there and is reused:\n";
        echo '  "' . EXPECTED_AREA_DE . '" matches the stored name "' . $area['stored_name']
            . '" through the column ' . $area['matched_name'] . ' (id ' . $area['id'] . ")\n";
        echo "  Nothing about that row is changed - no renaming, no overwriting.\n";
    } else {
        echo "\nThe area does not exist yet and will be created:\n";
        echo '  name = "' . EXPECTED_AREA_DE . "\" (parent_id NULL, so it is a top-level area)\n";
    }

    echo "\nSubcategories (German name, because the file has no English text):\n";

    foreach (EXPECTED_SUBCATEGORIES_DE as $name) {
        $subcategory = $plan['subcategories'][$name];
        $counters = $plan['per_subcategory'][$name] ?? [];

        printf(
            "  %-46s %-8s %s\n",
            $name,
            $subcategory['action'] === 'create' ? 'create' : 'reuse',
            ($subcategory['action'] === 'create' ? '' : '(id ' . $subcategory['id'] . ') ')
                . 'cards: ' . ($counters['to_insert'] ?? 0) . ' new, ' . ($counters['duplicates'] ?? 0) . ' already there'
        );
    }
}

/* --------------------------------------------------------------------------
   Writing
   -------------------------------------------------------------------------- */

/**
 * Writes the plan in ONE transaction.
 *
 * Everything that could still go wrong is inside the try block: if any single
 * statement fails, the caller rolls the whole transaction back and the database
 * stays exactly as it was.
 *
 * @param list<array<string, mixed>> $rows
 * @return array{categories_created: int, subcategories_created: int, cards_inserted: int, duplicates_skipped: int}
 */
function import_execute(PDO $pdo, array $plan): array
{
    $pdo->beginTransaction();

    $categoriesCreated = 0;
    $subcategoriesCreated = 0;
    $cardsInserted = 0;

    /* 1. the learning area. */
    $areaId = $plan['area']['id'];

    if ($areaId === null) {
        $created = create_category($pdo, ['parent_id' => null, 'name' => EXPECTED_AREA_DE]);
        $areaId = (int) $created['id'];
        $categoriesCreated++;
    }

    /* 2. the six subcategories, in the requested order and one level deep. */
    $subcategoryIds = [];

    foreach (EXPECTED_SUBCATEGORIES_DE as $name) {
        $subcategory = $plan['subcategories'][$name];
        $id = $subcategory['id'];

        if ($id === null) {
            $created = create_category($pdo, ['parent_id' => $areaId, 'name' => $name]);
            $id = (int) $created['id'];
            $subcategoriesCreated++;
        }

        $subcategoryIds[$name] = $id;
    }

    /* 3. the cards, in the order of the file. */
    foreach ($plan['cards_to_insert'] as $row) {
        create_card(
            $pdo,
            $subcategoryIds[$row['subcategory']],
            $row['front'],
            $row['back'],
            $row['is_two_sided']
        );

        $cardsInserted++;
    }

    /* 4. The proof that the structure is what it should be: the area is
       top-level, and it has exactly the six subcategories.
       If either test fails, the transaction is rolled back. */
    $checkArea = $pdo->prepare('SELECT parent_id FROM categories WHERE id = :id');
    $checkArea->bindValue(':id', $areaId, PDO::PARAM_INT);
    $checkArea->execute();
    $parentId = $checkArea->fetchColumn();

    if ($parentId !== null && $parentId !== false) {
        throw new RuntimeException('the imported area is not a top-level category');
    }

    if (count($subcategoryIds) !== count(EXPECTED_SUBCATEGORIES_DE)) {
        throw new RuntimeException('not every requested subcategory was created or found');
    }

    $pdo->commit();

    return [
        'categories_created' => $categoriesCreated,
        'subcategories_created' => $subcategoriesCreated,
        'cards_inserted' => $cardsInserted,
        'duplicates_skipped' => $plan['duplicates_skipped'],
    ];
}

exit(import_main($argv, $projectRoot));
