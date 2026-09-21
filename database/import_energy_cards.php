<?php

declare(strict_types=1);

/**
 * One-off import of the energy flashcards.
 *
 *   php database/import_energy_cards.php --dry-run
 *   php database/import_energy_cards.php --execute --wipe-subcategories
 *
 * The file is database/import/energie_karten_import.csv (UTF-8, separated by
 * semicolons) with the header
 *
 *   parent_category;subcategory;front;back;is_bidirectional
 *
 * What this script does
 *
 *   STEP A  (only with --wipe-subcategories)
 *           Removes EVERY subcategory of the tree, whichever learning area it
 *           belongs to, and any level below that. The order is fixed by the
 *           foreign keys and is the same order the application uses:
 *             1. the learning progress of the cards in those categories
 *             2. the cards themselves
 *             3. the categories, deepest level first
 *           Learning areas, users and cards that hang directly on an area are
 *           NOT deleted. If such cards exist they are reported loudly, because
 *           they are the one case where the result should be looked at before
 *           anything is executed.
 *
 *   STEP B  Imports the file: one subcategory per different `subcategory` value
 *           (in the order of first appearance) under the learning area named in
 *           `parent_category`, then every card, in the order of the file.
 *
 * Safety
 *
 *   * Everything - the deletion AND the import - happens in ONE transaction.
 *     Any error rolls the whole thing back, so the database is either exactly
 *     as it was before or fully imported. Never half.
 *   * The file is validated completely before the first statement runs, and the
 *     counts of the file are compared with the numbers this import expects.
 *   * --execute refuses to run without --wipe-subcategories while a subcategory
 *     of the file already exists under the target area: that is the guard
 *     against importing the same file twice.
 *   * No area is created, renamed or deleted. Nothing about the table structure
 *     is touched, and no progress row is written.
 *
 * The script is a command line tool. It has no URL, it is not part of the web
 * root and it prints no credentials, no SQL and no file paths.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    echo "Not found.\n";
    exit(1);
}

$projectRoot = dirname(__DIR__);

require_once $projectRoot . '/src/config/database.php';
require_once $projectRoot . '/src/services/card_service.php';

/** The header of the file, in this exact order. */
const CSV_HEADER = ['parent_category', 'subcategory', 'front', 'back', 'is_bidirectional'];

/** The learning area the file names in parent_category. */
const EXPECTED_AREA = 'Energie';

/**
 * How many cards each subcategory must contain, and how many in total.
 *
 * This is the contract of this one-off import: it was counted in the file, and a
 * file that does not match it is not imported at all.
 */
const EXPECTED_CARDS_PER_SUBCATEGORY = [
    'Strom und Elektrotechnik' => 32,
    'Energieträger und Stromerzeugung' => 27,
    'Stromnetz und Übertragungsnetz' => 25,
    'Strommarkt und Marktkommunikation' => 34,
    'Systembetrieb, Regelenergie und Redispatch' => 25,
    'Energiegeschichte, Mobilität und Energiewende' => 18,
];

/** Longest accepted subcategory name (the column is varchar(100)). */
const MAX_SUBCATEGORY_LENGTH = 100;

exit(import_main($argv, $projectRoot));

/**
 * Runs the whole tool and returns the exit code.
 */
function import_main(array $argv, string $projectRoot): int
{
    $options = import_read_arguments($argv, $projectRoot);

    if ($options === null) {
        return 1;
    }

    echo "Energy flashcards import\n";
    echo 'File : ' . basename($options['file']) . "\n";
    echo 'Mode : ' . ($options['execute']
        ? ($options['wipe'] ? 'EXECUTE with --wipe-subcategories' : 'EXECUTE')
        : 'DRY RUN (reads only)') . "\n\n";

    /* ---------------------------------------------------------------------
       1. read and validate the file (no database involved yet)
       --------------------------------------------------------------------- */

    $csv = import_read_csv($options['file']);

    if ($csv['fatal'] !== null) {
        echo 'FILE ERROR: ' . $csv['fatal'] . "\n";

        return 1;
    }

    $problems = $csv['errors'];

    /* The numbers this import expects. */
    foreach (import_count_problems($csv['per_subcategory']) as $problem) {
        $problems[] = ['line' => 0, 'message' => $problem];
    }

    if ($problems !== []) {
        echo "VALIDATION FAILED (" . count($problems) . ") - nothing was changed:\n";

        foreach ($problems as $problem) {
            echo '  ' . ($problem['line'] > 0 ? 'line ' . $problem['line'] . ': ' : 'file: ') . $problem['message'] . "\n";
        }

        return 1;
    }

    /* ---------------------------------------------------------------------
       2. look at the database
       --------------------------------------------------------------------- */

    try {
        $pdo = create_database_connection();
    } catch (Throwable $error) {
        /* The reason may name the host or the user; it stays in this shell. */
        echo "DATABASE ERROR: the connection could not be opened.\n";

        return 1;
    }

    try {
        $state = import_read_state($pdo);
    } catch (Throwable $error) {
        echo 'DATABASE ERROR while reading: ' . $error->getMessage() . "\n";

        return 1;
    }

    /* Exactly one area must match the name in the file. */
    $matches = $state['area_matches'];

    if (count($matches) !== 1) {
        echo "STOP: the learning area \"$options[area]\" from the file is "
            . (count($matches) === 0 ? 'not there' : 'ambiguous (' . count($matches) . ' matches)') . "\n";

        foreach ($matches as $match) {
            echo '  id ' . $match['id'] . ': name="' . $match['name'] . '" name_de='
                . var_export($match['name_de'], true) . ' name_en=' . var_export($match['name_en'], true) . "\n";
        }

        echo "Nothing was changed. Create the area (or make its names unique) and run this again.\n";

        return 1;
    }

    $area = $matches[0];

    /* ---------------------------------------------------------------------
       3. the plan
       --------------------------------------------------------------------- */

    $subcategories = [];
    foreach ($csv['order'] as $name) {
        $subcategories[$name] = $csv['per_subcategory'][$name];
    }

    import_print_step_a($state, $subcategories);
    import_print_step_b($area, $csv, $subcategories, $state);

    /* The guard against a second import. */
    $alreadyThere = array_values(array_intersect(array_keys($subcategories), $state['area_subcategory_names']));

    if (!$options['execute']) {
        echo "\nDRY RUN: nothing was written.\n";

        return 0;
    }

    if (!$options['wipe'] && $alreadyThere !== []) {
        echo "\nSTOP: these subcategories of the file are already under \"{$area['name']}\":\n";

        foreach ($alreadyThere as $name) {
            echo '  ' . $name . "\n";
        }

        echo "Nothing was changed. Run with --wipe-subcategories to replace them.\n";

        return 1;
    }

    /* ---------------------------------------------------------------------
       4. write, all of it or none of it
       --------------------------------------------------------------------- */

    try {
        $result = import_execute($pdo, $options['wipe'], $area, $csv);
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        echo "\nIMPORT FAILED - rolled back, the database is exactly as it was.\n";
        echo '  ' . $error->getMessage() . "\n";

        return 1;
    }

    echo "\nIMPORT FINISHED\n";
    echo '  subcategories deleted : ' . $result['deleted_categories'] . "\n";
    echo '  cards deleted         : ' . $result['deleted_cards'] . "\n";
    echo '  progress rows deleted : ' . $result['deleted_progress'] . "\n";
    echo '  subcategories created : ' . $result['created_categories'] . "\n";
    echo '  cards imported        : ' . $result['created_cards'] . "\n";

    /* The proof, read after the commit: the tree really looks like this now. */
    $check = $pdo->prepare(
        'SELECT (SELECT COUNT(*) FROM categories WHERE parent_id = :area_id) AS subcategories,
                (SELECT COUNT(*) FROM cards k JOIN categories c ON c.id = k.category_id WHERE c.parent_id = :parent_id) AS cards'
    );
    $check->bindValue(':area_id', (int) $area['id'], PDO::PARAM_INT);
    $check->bindValue(':parent_id', (int) $area['id'], PDO::PARAM_INT);
    $check->execute();
    $row = $check->fetch();

    echo '  now under "' . $area['name'] . '": ' . $row['subcategories'] . ' subcategories, ' . $row['cards'] . " cards\n";

    return 0;
}

/* --------------------------------------------------------------------------
   Arguments
   -------------------------------------------------------------------------- */

/**
 * Reads --file, --dry-run, --execute and --wipe-subcategories.
 *
 * Without a mode the tool only reads.
 *
 * @return array{file: string, execute: bool, wipe: bool, area: string}|null
 */
function import_read_arguments(array $argv, string $projectRoot): ?array
{
    $file = $projectRoot . '/database/import/energie_karten_import.csv';
    $execute = false;
    $wipe = false;
    $modeGiven = false;

    foreach (array_slice($argv, 1) as $argument) {
        if (strpos($argument, '--file=') === 0) {
            $candidate = substr($argument, 7);
            $file = preg_match('/^([a-zA-Z]:|[\/\\\\])/', $candidate) === 1
                ? $candidate
                : $projectRoot . '/' . $candidate;
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

        if ($argument === '--wipe-subcategories') {
            $wipe = true;
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

    $real = realpath($file);

    if ($real === false || !is_file($real)) {
        echo 'File not found: ' . basename($file) . "\n";

        return null;
    }

    return ['file' => $real, 'execute' => $execute, 'wipe' => $wipe, 'area' => EXPECTED_AREA];
}

function import_print_usage(): void
{
    echo "Usage:\n";
    echo "  php database/import_energy_cards.php --dry-run\n";
    echo "  php database/import_energy_cards.php --execute --wipe-subcategories\n";
}

/* --------------------------------------------------------------------------
   Reading and validating the file
   -------------------------------------------------------------------------- */

/**
 * Reads the file and validates every row.
 *
 * @return array{
 *     rows: list<array{line: int, subcategory: string, front: string, back: string, is_bidirectional: int}>,
 *     errors: list<array{line: int, message: string}>,
 *     per_subcategory: array<string, int>,
 *     order: list<string>,
 *     fatal: string|null
 * }
 */
function import_read_csv(string $path): array
{
    $empty = ['rows' => [], 'errors' => [], 'per_subcategory' => [], 'order' => [], 'fatal' => null];

    $handle = fopen($path, 'rb');

    if ($handle === false) {
        return ['rows' => [], 'errors' => [], 'per_subcategory' => [], 'order' => [], 'fatal' => 'the file could not be opened'];
    }

    /* A byte order mark in front of the first header name would make the header
       comparison fail. The three bytes are read and dropped before fgetcsv()
       sees them. */
    if (fread($handle, 3) !== "\xEF\xBB\xBF") {
        rewind($handle);
    }

    $header = fgetcsv($handle, 0, ';');

    if ($header === false || $header === [null]) {
        fclose($handle);

        return ['rows' => [], 'errors' => [], 'per_subcategory' => [], 'order' => [], 'fatal' => 'the file is empty'];
    }

    /* Defensive: a BOM inside the first cell is removed as well. */
    if (strpos((string) $header[0], "\xEF\xBB\xBF") === 0) {
        $header[0] = substr((string) $header[0], 3);
    }

    $header = array_map(static fn ($name): string => trim((string) $name), $header);

    if ($header !== CSV_HEADER) {
        fclose($handle);

        return [
            'rows' => [],
            'errors' => [],
            'per_subcategory' => [],
            'order' => [],
            'fatal' => 'the header does not match. Expected: ' . implode(';', CSV_HEADER)
                . ' - found: ' . implode(';', $header),
        ];
    }

    $rows = [];
    $errors = [];
    $perSubcategory = [];
    $order = [];
    $seenFronts = [];
    $lineNumber = 1;

    while (($cells = fgetcsv($handle, 0, ';')) !== false) {
        $lineNumber++;

        if ($cells === [null] || import_row_is_empty($cells)) {
            /* A blank line is not a data row. */
            continue;
        }

        if (count($cells) !== 5) {
            $errors[] = ['line' => $lineNumber, 'message' => 'expected 5 fields, found ' . count($cells)];
            continue;
        }

        $parent = trim((string) $cells[0]);
        $subcategory = (string) $cells[1];
        $front = (string) $cells[2];
        $back = (string) $cells[3];
        $flag = trim((string) $cells[4]);

        if ($parent === '') {
            $errors[] = ['line' => $lineNumber, 'message' => 'parent_category is empty'];
        } elseif (mb_strtolower($parent) !== mb_strtolower(EXPECTED_AREA)) {
            $errors[] = ['line' => $lineNumber, 'message' => 'parent_category is "' . $parent . '", this import is about "' . EXPECTED_AREA . '"'];
        }

        if (trim($subcategory) === '') {
            $errors[] = ['line' => $lineNumber, 'message' => 'subcategory is empty'];
        } elseif (mb_strlen(trim($subcategory)) > MAX_SUBCATEGORY_LENGTH) {
            $errors[] = ['line' => $lineNumber, 'message' => 'subcategory is longer than ' . MAX_SUBCATEGORY_LENGTH . ' characters'];
        }

        if (trim($front) === '') {
            $errors[] = ['line' => $lineNumber, 'message' => 'front is empty'];
        }

        if (trim($back) === '') {
            $errors[] = ['line' => $lineNumber, 'message' => 'back is empty'];
        }

        if ($flag !== '0' && $flag !== '1') {
            $errors[] = ['line' => $lineNumber, 'message' => 'is_bidirectional must be 0 or 1, found "' . $flag . '"'];
        }

        /* The same question in the same subcategory twice is not a duplicate card
           to skip here - in this one-off import it means the file is not the one
           this import expects, so it is reported. */
        $key = trim($subcategory) . "\n" . trim($front);

        if (isset($seenFronts[$key])) {
            $errors[] = ['line' => $lineNumber, 'message' => 'the same front already appears in "' . trim($subcategory) . '" (line ' . $seenFronts[$key] . ')'];
        } else {
            $seenFronts[$key] = $lineNumber;
        }

        $name = trim($subcategory);

        if (!isset($perSubcategory[$name])) {
            $perSubcategory[$name] = 0;
            $order[] = $name;
        }

        $perSubcategory[$name]++;

        /* The text is stored exactly as the file has it: no shortening, no
           reformatting, no HTML encoding - only the checks above look at a
           trimmed copy. */
        $rows[] = [
            'line' => $lineNumber,
            'subcategory' => $name,
            'front' => $front,
            'back' => $back,
            'is_bidirectional' => $flag === '1' ? 1 : 0,
        ];
    }

    fclose($handle);

    return [
        'rows' => $rows,
        'errors' => $errors,
        'per_subcategory' => $perSubcategory,
        'order' => $order,
        'fatal' => null,
    ];
}

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
 * Compares the counts of the file with the numbers this import expects.
 *
 * @param array<string, int> $perSubcategory
 * @return list<string>
 */
function import_count_problems(array $perSubcategory): array
{
    $problems = [];
    $total = array_sum($perSubcategory);
    $expectedTotal = array_sum(EXPECTED_CARDS_PER_SUBCATEGORY);

    if ($total !== $expectedTotal) {
        $problems[] = 'the file has ' . $total . ' cards, expected ' . $expectedTotal;
    }

    foreach (EXPECTED_CARDS_PER_SUBCATEGORY as $name => $count) {
        $found = $perSubcategory[$name] ?? 0;

        if ($found !== $count) {
            $problems[] = '"' . $name . '" has ' . $found . ' cards, expected ' . $count;
        }
    }

    foreach (array_keys($perSubcategory) as $name) {
        if (!array_key_exists($name, EXPECTED_CARDS_PER_SUBCATEGORY)) {
            $problems[] = 'the file contains the subcategory "' . $name . '", which this import does not expect';
        }
    }

    return $problems;
}

/* --------------------------------------------------------------------------
   Looking at the database
   -------------------------------------------------------------------------- */

/**
 * Everything the report and the plan need, read in one go.
 *
 * @return array<string, mixed>
 */
function import_read_state(PDO $pdo): array
{
    $areas = $pdo->query('SELECT id, name, name_en, name_de FROM categories WHERE parent_id IS NULL ORDER BY id')
        ->fetchAll(PDO::FETCH_ASSOC);

    /* The area the file names: name, name_de or name_en, without case. */
    $wanted = mb_strtolower(EXPECTED_AREA);
    $matches = [];

    foreach ($areas as $area) {
        foreach (['name', 'name_de', 'name_en'] as $column) {
            if (isset($area[$column]) && is_string($area[$column]) && mb_strtolower(trim($area[$column])) === $wanted) {
                $matches[] = $area;
                break;
            }
        }
    }

    /* Every subcategory of the tree, with its area and its depth. */
    $subcategories = $pdo->query(
        'SELECT c.id, c.parent_id, c.name, p.id AS area_id, p.name AS area_name
           FROM categories c
           JOIN categories p ON p.id = c.parent_id
          ORDER BY c.id'
    )->fetchAll(PDO::FETCH_ASSOC);

    $cardsInSubcategories = (int) $pdo->query(
        'SELECT COUNT(*) FROM cards k JOIN categories c ON c.id = k.category_id WHERE c.parent_id IS NOT NULL'
    )->fetchColumn();

    $progressInSubcategories = (int) $pdo->query(
        'SELECT COUNT(*) FROM user_card_progress pr
           JOIN cards k ON k.id = pr.card_id
           JOIN categories c ON c.id = k.category_id
          WHERE c.parent_id IS NOT NULL'
    )->fetchColumn();

    /* Cards that hang directly on an area: they are kept and only reported. */
    $cardsOnAreas = $pdo->query(
        'SELECT k.id, k.category_id, c.name
           FROM cards k
           JOIN categories c ON c.id = k.category_id
          WHERE c.parent_id IS NULL
          ORDER BY k.id'
    )->fetchAll(PDO::FETCH_ASSOC);

    /* How deep the tree is: needed for the deletion order. */
    $parentsOf = [];
    foreach ($pdo->query('SELECT id, parent_id FROM categories WHERE parent_id IS NOT NULL')->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $parentsOf[(int) $row['id']] = (int) $row['parent_id'];
    }

    $deepest = 0;
    foreach ($subcategories as $subcategory) {
        $depth = 1;
        $cursor = (int) $subcategory['parent_id'];

        while (isset($parentsOf[$cursor]) && $depth < 20) {
            $depth++;
            $cursor = $parentsOf[$cursor];
        }

        $deepest = max($deepest, $depth);
    }

    $areaId = count($matches) === 1 ? (int) $matches[0]['id'] : 0;

    $areaSubcategoryNames = [];

    if ($areaId > 0) {
        $statement = $pdo->prepare('SELECT name FROM categories WHERE parent_id = :id');
        $statement->bindValue(':id', $areaId, PDO::PARAM_INT);
        $statement->execute();
        $areaSubcategoryNames = array_map(static fn ($row): string => (string) $row['name'], $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    return [
        'areas' => $areas,
        'area_matches' => $matches,
        'subcategories' => $subcategories,
        'subcategory_count' => count($subcategories),
        'deepest_level' => $deepest,
        'cards_in_subcategories' => $cardsInSubcategories,
        'progress_in_subcategories' => $progressInSubcategories,
        'cards_on_areas' => $cardsOnAreas,
        'area_subcategory_names' => $areaSubcategoryNames,
    ];
}

/* --------------------------------------------------------------------------
   The report
   -------------------------------------------------------------------------- */

/**
 * STEP A: what would be deleted.
 *
 * @param array<string, mixed> $state
 * @param array<string, int> $subcategories of the file
 */
function import_print_step_a(array $state, array $subcategories): void
{
    echo "STEP A - delete every subcategory\n";

    if ($state['subcategory_count'] === 0) {
        echo "  there is no subcategory in the tree at the moment\n";
    }

    /* Grouped by area, in the order of the areas. */
    $perArea = [];

    foreach ($state['subcategories'] as $subcategory) {
        $perArea[$subcategory['area_name']][] = $subcategory['name'];
    }

    foreach ($perArea as $areaName => $names) {
        echo '  ' . str_pad($areaName, 20) . count($names) . ' subcategories: ' . implode(', ', $names) . "\n";
    }

    echo '  deepest level in the tree: ' . $state['deepest_level'] . "\n";
    echo '  cards to delete          : ' . $state['cards_in_subcategories'] . "\n";
    echo '  progress rows to delete  : ' . $state['progress_in_subcategories'] . "\n";
    echo '  subcategories to delete  : ' . $state['subcategory_count'] . "\n";

    if ($state['cards_on_areas'] !== []) {
        echo "\n  WARNING: " . count($state['cards_on_areas']) . " card(s) hang directly on a learning area.\n";
        echo "  They are NOT deleted and NOT part of this import - please look at them first:\n";

        foreach ($state['cards_on_areas'] as $card) {
            echo '    card ' . $card['id'] . ' on "' . $card['name'] . "\"\n";
        }
    }

    echo "\n";
}

/**
 * STEP B: what would be imported.
 *
 * @param array<string, mixed> $area
 * @param array<string, mixed> $csv
 * @param array<string, int> $subcategories of the file
 * @param array<string, mixed> $state
 */
function import_print_step_b(array $area, array $csv, array $subcategories, array $state): void
{
    echo "STEP B - import the file\n";
    echo '  target area : "' . $area['name'] . '" (id ' . $area['id'] . ', found through the name in the file)' . "\n";
    echo '  cards in file: ' . count($csv['rows']) . "\n\n";

    echo "  subcategories, in the order of the file:\n";

    foreach ($subcategories as $name => $count) {
        $exists = in_array($name, $state['area_subcategory_names'], true) ? '  (already there)' : '';
        printf("    %-46s %3d cards%s\n", $name, $count, $exists);
    }

    echo "\n  validation: header ok, " . count($csv['rows']) . " rows with 5 fields, no empty values,\n";
    echo "              is_bidirectional only 0 or 1, no duplicate front per subcategory,\n";
    echo "              counts match the expected numbers (" . array_sum($subcategories) . " cards, " . count($subcategories) . " subcategories)\n";
}

/* --------------------------------------------------------------------------
   Writing
   -------------------------------------------------------------------------- */

/**
 * Deletes every subcategory and imports the file, inside ONE transaction.
 *
 * The caller rolls back if anything here throws, so a failure leaves the
 * database exactly as it was - including the deletion.
 *
 * @param array<string, mixed> $area
 * @param array<string, mixed> $csv
 * @return array<string, int>
 */
function import_execute(PDO $pdo, bool $wipe, array $area, array $csv): array
{
    $pdo->beginTransaction();

    $deleted = ['categories' => 0, 'cards' => 0, 'progress' => 0];

    /* ---- STEP A ---------------------------------------------------------- */

    if ($wipe) {
        $deleted = import_delete_subcategories($pdo);

        /* The proof: nothing below an area is left. */
        $left = (int) $pdo->query('SELECT COUNT(*) FROM categories WHERE parent_id IS NOT NULL')->fetchColumn();

        if ($left !== 0) {
            throw new RuntimeException('the deletion left ' . $left . ' subcategories behind');
        }
    }

    /* ---- STEP B ---------------------------------------------------------- */

    $areasStillThere = (int) $pdo->query('SELECT COUNT(*) FROM categories WHERE parent_id IS NULL')->fetchColumn();

    if ($areasStillThere === 0) {
        throw new RuntimeException('the learning areas disappeared');
    }

    $createdCategories = 0;
    $createdCards = 0;
    $idOf = [];

    /*
     * The columns are written out one by one, exactly as this import asks for:
     * the German name carries the text, the English name stays empty, and the
     * drawing columns stay NULL. The application's create_category() is not used
     * here because it does not write name_de.
     */
    $insertCategory = $pdo->prepare(
        'INSERT INTO categories
            (parent_id, name, name_en, name_de, color, icon_svg, icon_scale, description_en, description_de)
         VALUES
            (:parent_id, :name, NULL, :name_de, NULL, NULL, 1.00, NULL, NULL)'
    );

    foreach ($csv['order'] as $name) {
        $insertCategory->bindValue(':parent_id', (int) $area['id'], PDO::PARAM_INT);
        $insertCategory->bindValue(':name', $name, PDO::PARAM_STR);
        $insertCategory->bindValue(':name_de', $name, PDO::PARAM_STR);
        $insertCategory->execute();

        $idOf[$name] = (int) $pdo->lastInsertId();
        $createdCategories++;
    }

    /* The cards go in the order of the file. There is no sort column: the list is
       ordered by id, so the ids ascending are the order of the file. */
    foreach ($csv['rows'] as $row) {
        create_card(
            $pdo,
            $idOf[$row['subcategory']],
            $row['front'],
            $row['back'],
            $row['is_bidirectional'] === 1
        );

        $createdCards++;
    }

    /* Every subcategory must have its cards, and the total must be right. */
    if ($createdCards !== count($csv['rows'])) {
        throw new RuntimeException('not every card was written (' . $createdCards . ' of ' . count($csv['rows']) . ')');
    }

    $pdo->commit();

    return [
        'deleted_categories' => $deleted['categories'],
        'deleted_cards' => $deleted['cards'],
        'deleted_progress' => $deleted['progress'],
        'created_categories' => $createdCategories,
        'created_cards' => $createdCards,
    ];
}

/**
 * Deletes every subcategory of the tree.
 *
 * The order is the one the foreign keys require and the one the application
 * uses when it deletes a category:
 *   1. the learning progress of the affected cards
 *   2. the cards themselves
 *   3. the categories, deepest level first
 *
 * The helpers for 1. and 2. are the ones the application already uses, so there
 * is only one place that knows how progress and cards disappear.
 *
 * @return array{categories: int, cards: int, progress: int}
 */
function import_delete_subcategories(PDO $pdo): array
{
    $rows = $pdo->query('SELECT id, parent_id FROM categories WHERE parent_id IS NOT NULL')->fetchAll(PDO::FETCH_ASSOC);

    if ($rows === []) {
        return ['categories' => 0, 'cards' => 0, 'progress' => 0];
    }

    $parentOf = [];
    $depthOf = [];

    foreach ($rows as $row) {
        $id = (int) $row['id'];
        $parentOf[$id] = (int) $row['parent_id'];
    }

    foreach (array_keys($parentOf) as $id) {
        $depth = 1;
        $cursor = $parentOf[$id];

        while (isset($parentOf[$cursor]) && $depth < 20) {
            $depth++;
            $cursor = $parentOf[$cursor];
        }

        $depthOf[$id] = $depth;
    }

    /* Deepest first: a category cannot go before its children. */
    arsort($depthOf, SORT_NUMERIC);
    $ids = array_map('intval', array_keys($depthOf));

    /* 1. and 2.: the application's own helpers. */
    $progress = delete_progress_of_categories($pdo, $ids);
    $cards = delete_cards_of_categories($pdo, $ids);

    /* 3. the categories themselves. */
    $statement = $pdo->prepare('DELETE FROM categories WHERE id = :id');
    $deleted = 0;

    foreach ($ids as $id) {
        $statement->bindValue(':id', $id, PDO::PARAM_INT);
        $statement->execute();
        $deleted += $statement->rowCount();
    }

    return ['categories' => $deleted, 'cards' => $cards, 'progress' => $progress];
}
