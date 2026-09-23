<?php

declare(strict_types=1);

/**
 * Command line import of flashcards from a CSV file.
 *
 *   php bin/import_energy_cards.php --file=<path> --dry-run
 *   php bin/import_energy_cards.php --file=<path> \
 *        --execute --wipe-subcategories --expect=209
 *
 * The file has to be named with --file=: there is no default file any more,
 * because the CSV files that once lived in database/import/ are gone (their
 * content is in the database).
 *
 * The file is UTF-8 and separated by semicolons. Two headers are accepted, and two
 * optional columns may follow the required ones:
 *
 *   ...;is_bidirectional[;map_region][;exercise]
 *
 * The --exercise column names a generated task instead of a fixed card:
 *
 *   <kind of task>                          the defaults of that kind
 *   <kind of task>:<name>=<value>,<name>=<value>
 *
 * Examples:
 *
 *   times_table:min=2,max=20
 *   percent:min=10,max=1000,ask=rate
 *   percent_energy:variants=mix|storage_level
 *   division_inverse:min=2,max=20,remainder=yes
 *
 * A parameter that allows several options at once is written with a pipe, a
 * yes/no parameter takes yes or no. A card with an exercise needs a title, not an
 * answer: the answer is built when the card is shown. An empty cell means a fixed
 * card, so every file written so far keeps working unchanged. Which kinds of task
 * exist and which numbers each of them takes is written down in exactly one place:
 * exercise_catalog() in src/services/exercise_service.php.
 *
 *   parent_category;subcategory;front_de;back_de;front_en;back_en;is_bidirectional
 *   parent_category;subcategory;front_de;back_de;front_en;back_en;is_bidirectional;map_region
 *
 * A row must carry at least one complete language (front side and back side);
 * the second language may be missing, but not half filled. map_region is either
 * empty or "AREA:REGION" with AREA one of DE, EU, WORLD - see the pattern below.
 *
 * The learning area named in the file has to exist already: this tool never
 * creates one. It creates the subcategories of the file below that area.
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
 *   --allow-existing-subcategories
 *           A subcategory of the file that already exists under the target area is
 *           REUSED: the cards go into it and no second subcategory with the same
 *           name appears. Without this flag such a file stops with a message
 *           instead. Even with the flag a run refuses when a card of the file
 *           already sits in that subcategory, so a real duplicate cannot slip
 *           through.
 *
 * Safety
 *
 *   * Everything - the deletion AND the import - happens in ONE transaction.
 *     Any error rolls the whole thing back, so the database is either exactly
 *     as it was before or fully imported. Never half.
 *   * The file is validated completely before the first statement runs, and the
 *     counts of the file are compared with the numbers this import expects.
 *   * --execute refuses to run while a subcategory of the file already exists under
 *     the target area - unless --wipe-subcategories replaces them or
 *     --allow-existing-subcategories reuses them. The name of a subcategory is the
 *     guard against importing the same file twice; with the reuse flag that guard
 *     moves to the cards, which are compared by their front side.
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

/** The header without a map, in this exact order. */
const CSV_HEADER = ['parent_category', 'subcategory', 'front_de', 'back_de', 'front_en', 'back_en', 'is_bidirectional'];

/** The header of a file that also carries a map region. */
const CSV_HEADER_WITH_REGION = ['parent_category', 'subcategory', 'front_de', 'back_de', 'front_en', 'back_en', 'is_bidirectional', 'map_region'];

/**
 * The header of a file whose last column names a generated exercise.
 *
 * The column is optional and may also follow the map_region column. An empty
 * cell means a fixed card, which is what every file written so far says, so an
 * older file keeps working unchanged.
 */
const CSV_HEADER_WITH_EXERCISE = ['parent_category', 'subcategory', 'front_de', 'back_de', 'front_en', 'back_en', 'is_bidirectional', 'exercise'];

/** The header of a file with both optional columns, in this order. */
const CSV_HEADER_WITH_REGION_AND_EXERCISE = ['parent_category', 'subcategory', 'front_de', 'back_de', 'front_en', 'back_en', 'is_bidirectional', 'map_region', 'exercise'];

/** Longest accepted subcategory name (the column is varchar(100)). */
const MAX_SUBCATEGORY_LENGTH = 100;

/** Longest accepted card text, the same limit the API enforces. */
const MAX_CARD_TEXT_LENGTH = 2000;

/** Longest accepted map_region value (the column is varchar(40)). */
const MAX_REGION_LENGTH = 40;

/**
 * What a map_region value may look like: the area is one of three names, the
 * region is a plain identifier (letters, digits, underscore, hyphen).
 *
 * Baden__x26__Württemberg is the id of that state in germany.svg, which is why
 * letters with umlauts are allowed here as well.
 */
const REGION_PATTERN = '/^(DE|EU|WORLD):[A-Za-z0-9_äöüÄÖÜß-]{1,32}$/u';

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

    echo "Flashcard import\n";
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

    /* The number the operator says the file has. */
    foreach (import_expectation_problems($csv, $options['expect']) as $problem) {
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
        $state = import_read_state($pdo, $csv['areas']);
    } catch (Throwable $error) {
        echo 'DATABASE ERROR while reading: ' . $error->getMessage() . "\n";

        return 1;
    }

    /* Exactly one area must match the name in the file. */
    $matches = $state['area_matches'];
    $wantedArea = $csv['areas'] === [] ? '(none)' : $csv['areas'][0];

    if (count($matches) !== 1) {
        echo "STOP: the learning area \"$wantedArea\" from the file is "
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

    import_print_step_a($state, $subcategories, $area);
    import_print_step_b($area, $csv, $subcategories, $state);

    /* The guard against a second import. */
    $alreadyThere = array_values(array_intersect(array_keys($subcategories), $state['area_subcategory_names']));

    if (!$options['execute']) {
        echo "\nDRY RUN: nothing was written.\n";

        return 0;
    }

    if (!$options['wipe'] && $alreadyThere !== [] && !$options['reuse']) {
        echo "\nSTOP: these subcategories of the file are already under \"{$area['name']}\":\n";

        foreach ($alreadyThere as $name) {
            echo '  ' . $name . "\n";
        }

        echo "Nothing was changed. Run with --wipe-subcategories to replace them,\n"
            . "or with --allow-existing-subcategories to put the new cards into them.\n";

        return 1;
    }

    /*
     * With the reuse flag the guard moves from the name to the cards: a front side
     * that is already stored in one of these subcategories would become a
     * duplicate, so the run stops and names it.
     */
    if ($options['reuse'] && $alreadyThere !== []) {
        $duplicates = import_front_collisions($pdo, $area, $alreadyThere, $csv);

        if ($duplicates !== []) {
            echo "\nSTOP: these cards of the file are already stored in that subcategory:\n";

            foreach (array_slice($duplicates, 0, 10) as $line) {
                echo '  ' . $line . "\n";
            }

            if (count($duplicates) > 10) {
                echo '  ... and ' . (count($duplicates) - 10) . " more\n";
            }

            echo "Nothing was changed.\n";

            return 1;
        }
    }

    /* ---------------------------------------------------------------------
       4. write, all of it or none of it
       --------------------------------------------------------------------- */

    try {
        $result = import_execute($pdo, $options['wipe'], $options['reuse'], $area, $csv);
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
    echo '  subcategories reused  : ' . $result['reused_categories'] . "\n";
    echo '  cards imported        : ' . $result['created_cards'] . "\n";

    /* The proof, read after the commit: the tree really looks like this now. */
    $check = $pdo->prepare(
        'SELECT (SELECT COUNT(*) FROM categories WHERE parent_id = :area_id) AS subcategories,
                (SELECT COUNT(*) FROM cards k JOIN categories c ON c.id = k.category_id WHERE c.parent_id = :parent_id) AS cards,
                (SELECT COUNT(*) FROM cards k JOIN categories c ON c.id = k.category_id
                  WHERE c.parent_id = :region_id AND k.map_region IS NOT NULL AND k.map_region <> \'\') AS with_region'
    );
    $check->bindValue(':area_id', (int) $area['id'], PDO::PARAM_INT);
    $check->bindValue(':parent_id', (int) $area['id'], PDO::PARAM_INT);
    $check->bindValue(':region_id', (int) $area['id'], PDO::PARAM_INT);
    $check->execute();
    $row = $check->fetch();

    echo '  now under "' . $area['name'] . '": ' . $row['subcategories'] . ' subcategories, ' . $row['cards'] . ' cards, '
        . $row['with_region'] . " with a map region\n";

    return 0;
}

/* --------------------------------------------------------------------------
   Arguments
   -------------------------------------------------------------------------- */

/**
 * Reads --file, --dry-run, --execute, --wipe-subcategories,
 * --allow-existing-subcategories and --expect.
 *
 * Without a mode the tool only reads.
 *
 * @return array{file: string, execute: bool, wipe: bool, reuse: bool, expect: int|null}|null
 */
function import_read_arguments(array $argv, string $projectRoot): ?array
{
    /* No default file: the CSV files that once lived in database/import/ are
       deleted, because their content is in the database. A run without
       --file= stops with a clear message instead of pointing at a file that
       does not exist any more. */
    $file = null;
    $execute = false;
    $wipe = false;
    $reuse = false;
    $expect = null;
    $modeGiven = false;

    foreach (array_slice($argv, 1) as $argument) {
        if (strpos($argument, '--expect=') === 0) {
            $value = substr($argument, 9);

            if (ctype_digit($value) && (int) $value > 0) {
                $expect = (int) $value;
                continue;
            }

            echo "The value of --expect must be a positive whole number.\n";

            return null;
        }
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

        if ($argument === '--allow-existing-subcategories') {
            $reuse = true;
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

    if ($file === null) {
        echo "No CSV file given. Pass --file=<path>; there is no default file any more.\n\n";

        import_print_usage();

        return null;
    }

    $real = realpath($file);

    if ($real === false || !is_file($real)) {
        echo 'File not found: ' . basename($file) . "\n";

        return null;
    }

    return ['file' => $real, 'execute' => $execute, 'wipe' => $wipe, 'reuse' => $reuse, 'expect' => $expect];
}

function import_print_usage(): void
{
    echo "Usage:\n";
    echo "  php bin/import_energy_cards.php --file=<path> --dry-run\n";
    echo "  php bin/import_energy_cards.php --file=<path> --execute --expect=209 --wipe-subcategories\n";
    echo "\n";
    echo "  --file=...              the CSV file, required (there is no default file)\n";
    echo "  --dry-run               read and report, write nothing (default)\n";
    echo "  --execute               really import, all of it or none of it\n";
    echo "  --wipe-subcategories    delete the subcategories of the area in the file first\n";
    echo "  --allow-existing-subcategories\n";
    echo "                          put the cards into a subcategory that is already there\n";
    echo "  --expect=N              refuse to run when the file does not have N cards\n";
}

/* --------------------------------------------------------------------------
   Reading and validating the file
   -------------------------------------------------------------------------- */

/**
 * Reads the file and validates every row.
 *
 * @return array{
 *     rows: list<array{line: int, subcategory: string, front_de: string, back_de: string,
 *         front_en: string, back_en: string, is_bidirectional: int, map_region: string}>,
 *     errors: list<array{line: int, message: string}>,
 *     per_subcategory: array<string, int>,
 *     order: list<string>,
 *     areas: list<string>,
 *     with_region: int,
 *     fatal: string|null
 * }
 */
function import_read_csv(string $path): array
{
    $empty = ['rows' => [], 'errors' => [], 'per_subcategory' => [], 'order' => [], 'areas' => [], 'with_region' => 0, 'fatal' => null];

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

    /*
     * Four shapes are accepted: with and without the map region column, and with
     * and without the exercise column. Both optional columns stand at the end.
     */
    $hasRegion = in_array('map_region', $header, true);
    $hasExercise = in_array('exercise', $header, true);

    if ($hasRegion && $hasExercise) {
        $expected = CSV_HEADER_WITH_REGION_AND_EXERCISE;
    } elseif ($hasRegion) {
        $expected = CSV_HEADER_WITH_REGION;
    } elseif ($hasExercise) {
        $expected = CSV_HEADER_WITH_EXERCISE;
    } else {
        $expected = CSV_HEADER;
    }

    if ($header !== $expected) {
        fclose($handle);

        return [
            'rows' => [],
            'errors' => [],
            'per_subcategory' => [],
            'order' => [],
            'areas' => [],
            'with_region' => 0,
            'fatal' => 'the header does not match. Expected: ' . implode(';', CSV_HEADER)
                . ' (map_region and exercise are allowed at the end, in this order) - found: '
                . implode(';', $header),
        ];
    }

    $rows = [];
    $errors = [];
    $perSubcategory = [];
    $order = [];
    $areas = [];
    $withRegion = 0;
    $seenFronts = [];
    $lineNumber = 1;

    while (($cells = fgetcsv($handle, 0, ';')) !== false) {
        $lineNumber++;

        if ($cells === [null] || import_row_is_empty($cells)) {
            /* A blank line is not a data row. */
            continue;
        }

        if (count($cells) !== count($expected)) {
            $errors[] = ['line' => $lineNumber, 'message' => 'expected ' . count($expected) . ' fields, found ' . count($cells)];
            continue;
        }

        $parent = trim((string) $cells[0]);
        $subcategory = (string) $cells[1];
        $frontDe = (string) $cells[2];
        $backDe = (string) $cells[3];
        $frontEn = (string) $cells[4];
        $backEn = (string) $cells[5];
        $flag = trim((string) $cells[6]);
        $region = $hasRegion ? trim((string) $cells[7]) : '';

        /* The exercise column, wherever it sits. */
        $exerciseCell = '';

        if ($hasExercise) {
            $exerciseCell = trim((string) $cells[count($expected) - 1]);
        }

        $parsedExercise = import_parse_exercise($exerciseCell);
        $exercise = $parsedExercise['exercise'];

        if ($parsedExercise['error'] !== null) {
            $errors[] = ['line' => $lineNumber, 'message' => $parsedExercise['error']];
        }

        if ($parent === '') {
            $errors[] = ['line' => $lineNumber, 'message' => 'parent_category is empty'];
        } elseif (!isset($areas[mb_strtolower($parent)])) {
            $areas[mb_strtolower($parent)] = $parent;
        }

        if (trim($subcategory) === '') {
            $errors[] = ['line' => $lineNumber, 'message' => 'subcategory is empty'];
        } elseif (mb_strlen(trim($subcategory)) > MAX_SUBCATEGORY_LENGTH) {
            $errors[] = ['line' => $lineNumber, 'message' => 'subcategory is longer than ' . MAX_SUBCATEGORY_LENGTH . ' characters'];
        }

        /*
         * An exercise card carries a title instead of an answer: its answer is built
         * when the card is shown. The same rule the application uses, so a file and
         * the card dialog ask for the same thing.
         */
        if ($exerciseCell !== '') {
            if (trim($frontDe) === '' && trim($frontEn) === '') {
                $errors[] = ['line' => $lineNumber, 'message' => 'an exercise card needs a title in at least one language (front_de or front_en)'];
            }
        } else {
            /* At least one language has to be complete, and no language may be half. */
            $germanComplete = trim($frontDe) !== '' && trim($backDe) !== '';
            $englishComplete = trim($frontEn) !== '' && trim($backEn) !== '';

            foreach (['de' => [$frontDe, $backDe], 'en' => [$frontEn, $backEn]] as $language => [$front, $back]) {
                $used = trim($front) !== '' || trim($back) !== '';

                if ($used && (trim($front) === '' || trim($back) === '')) {
                    $errors[] = ['line' => $lineNumber, 'message' => 'the ' . $language . ' side is only half filled'];
                }
            }

            if (!$germanComplete && !$englishComplete) {
                $errors[] = ['line' => $lineNumber, 'message' => 'neither language is complete (front and back)'];
            }
        }

        foreach (['front_de' => $frontDe, 'back_de' => $backDe, 'front_en' => $frontEn, 'back_en' => $backEn] as $column => $value) {
            if (mb_strlen($value) > MAX_CARD_TEXT_LENGTH) {
                $errors[] = ['line' => $lineNumber, 'message' => $column . ' is longer than ' . MAX_CARD_TEXT_LENGTH . ' characters'];
            }
        }

        if ($flag !== '0' && $flag !== '1') {
            $errors[] = ['line' => $lineNumber, 'message' => 'is_bidirectional must be 0 or 1, found "' . $flag . '"'];
        }

        if ($region !== '' && !import_region_is_valid($region)) {
            $errors[] = ['line' => $lineNumber, 'message' => 'map_region must look like "DE:Bayern", "EU:FR" or "WORLD:CN" - found "' . $region . '"'];
        }

        /* The same question in the same subcategory twice is not a duplicate card
           to skip here - in this one-off import it means the file is not the one
           this import expects, so it is reported. */
        $key = trim($subcategory) . "\n" . mb_strtolower(trim($frontDe === '' ? $frontEn : $frontDe));

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

        if ($region !== '') {
            $withRegion++;
        }

        /* The text is stored exactly as the file has it: no shortening, no
           reformatting, no HTML encoding - only the checks above look at a
           trimmed copy. */
        $rows[] = [
            'line' => $lineNumber,
            'subcategory' => $name,
            'front_de' => $frontDe,
            'back_de' => $backDe,
            'front_en' => $frontEn,
            'back_en' => $backEn,
            'is_bidirectional' => $flag === '1' ? 1 : 0,
            'map_region' => $region,
            'exercise' => $exercise,
        ];
    }

    fclose($handle);

    return [
        'rows' => $rows,
        'errors' => $errors,
        'per_subcategory' => $perSubcategory,
        'order' => $order,
        'areas' => array_values($areas),
        'with_region' => $withRegion,
        'fatal' => null,
    ];
}


/**
 * Reads one exercise cell of the file.
 *
 *   "<type>"                                  the defaults of that kind of task
 *   "<type>:<name>=<value>,<name>=<value>"    with numbers of its own
 *
 * A parameter that allows several options at once is written with a pipe, for
 * example variants=mix|storage_level, and a yes/no parameter takes yes or no.
 * Everything is checked with the same rules the card dialog is checked with, so a
 * file can never store numbers that no task can be built from.
 *
 * @return array{exercise: array{type: string, params: array<string, mixed>}|null, error: string|null}
 */
function import_parse_exercise(string $cell): array
{
    $cell = trim($cell);

    /* An empty cell means a fixed card. */
    if ($cell === '') {
        return ['exercise' => null, 'error' => null];
    }

    $parts = explode(':', $cell, 2);
    $type = trim($parts[0]);
    $written = isset($parts[1]) ? trim($parts[1]) : '';

    if (!exercise_type_is_known($type)) {
        return ['exercise' => null, 'error' => 'unknown kind of task "' . $type . '" in the exercise column'];
    }

    if ($written === '') {
        return [
            'exercise' => ['type' => $type, 'params' => exercise_type_default_params($type)],
            'error' => null,
        ];
    }

    $catalogue = exercise_catalog()[$type]['params'];
    $params = [];

    foreach (explode(',', $written) as $pair) {
        $pair = trim($pair);

        if ($pair === '') {
            continue;
        }

        $halves = explode('=', $pair, 2);

        if (count($halves) !== 2) {
            return ['exercise' => null, 'error' => 'the exercise column expects name=value, found "' . $pair . '"'];
        }

        $name = trim($halves[0]);
        $value = trim($halves[1]);
        $schema = $catalogue[$name] ?? null;

        if ($schema === null) {
            return ['exercise' => null, 'error' => '"' . $name . '" is not a parameter of ' . $type];
        }

        if ($schema['kind'] === 'int') {
            if (!ctype_digit($value)) {
                return ['exercise' => null, 'error' => '"' . $name . '" must be a whole number, found "' . $value . '"'];
            }

            $params[$name] = (int) $value;

            continue;
        }

        if ($schema['kind'] === 'select') {
            $params[$name] = $value;

            continue;
        }

        if ($schema['kind'] === 'multi') {
            $params[$name] = array_values(array_filter(
                array_map('trim', explode('|', $value)),
                static fn (string $one): bool => $one !== ''
            ));

            continue;
        }

        /* The remaining kind is a yes/no parameter. */
        $lower = mb_strtolower($value);

        if (!in_array($lower, ['yes', 'no', 'ja', 'nein', '1', '0', 'true', 'false'], true)) {
            return ['exercise' => null, 'error' => '"' . $name . '" must be yes or no, found "' . $value . '"'];
        }

        $params[$name] = in_array($lower, ['yes', 'ja', '1', 'true'], true);
    }

    /*
     * A number outside its limits is pulled into them, the same way a stored card
     * is treated when it is read: the file is written by hand, so it may be a
     * little off without failing the whole import.
     */
    $params = exercise_normalise_params($type, $params);

    if (!exercise_params_are_valid($type, $params)) {
        return ['exercise' => null, 'error' => 'the numbers of "' . $type . '" do not fit this kind of task'];
    }

    return ['exercise' => ['type' => $type, 'params' => $params], 'error' => null];
}
/**
 * The same pattern the application uses: the area is one of three names and the
 * region is a plain identifier. Anything else never reaches the database.
 */
function import_region_is_valid(string $region): bool
{
    return mb_strlen($region) <= MAX_REGION_LENGTH && preg_match(REGION_PATTERN, $region) === 1;
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
 * Compares the number of cards in the file with the number the operator wrote on
 * the command line.
 *
 * @param array<string, mixed> $csv
 * @return list<string>
 */
function import_expectation_problems(array $csv, ?int $expected): array
{
    $problems = [];
    $total = count($csv['rows']);

    if ($expected === null) {
        return $problems;
    }

    if ($total !== $expected) {
        $problems[] = 'the file has ' . $total . ' cards, --expect says ' . $expected;
    }

    if (count($csv['areas']) !== 1) {
        $problems[] = 'the file names ' . count($csv['areas']) . ' learning areas, this import needs exactly one';
    }

    return $problems;
}

/* --------------------------------------------------------------------------
   Looking at the database
   -------------------------------------------------------------------------- */

/**
 * Reads the areas and everything the report needs.
 *
 * @param list<string> $wantedAreas The names the file uses in parent_category.
 * @return array<string, mixed>
 */
function import_read_state(PDO $pdo, array $wantedAreas): array
{
    $areas = $pdo->query('SELECT id, name, name_en, name_de FROM categories WHERE parent_id IS NULL ORDER BY id')
        ->fetchAll(PDO::FETCH_ASSOC);

    /* The area the file names: name, name_de or name_en, without case. */
    $wanted = array_map(static fn ($name): string => mb_strtolower(trim((string) $name)), $wantedAreas);
    $matches = [];

    foreach ($areas as $area) {
        foreach (['name', 'name_de', 'name_en'] as $column) {
            if (!isset($area[$column]) || !is_string($area[$column])) {
                continue;
            }

            if (in_array(mb_strtolower(trim($area[$column])), $wanted, true)) {
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

    /*
     * Cards and progress rows per learning area. The wipe only touches the area
     * the file names, so the report has to be able to name its numbers alone.
     */
    $cardsPerArea = [];
    $progressPerArea = [];

    $perArea = $pdo->query(
        'SELECT p.id AS area_id,
                COUNT(DISTINCT k.id) AS cards,
                COUNT(DISTINCT pr.card_id) AS progress
           FROM categories p
           LEFT JOIN categories c ON c.parent_id = p.id
           LEFT JOIN cards k ON k.category_id = c.id
           LEFT JOIN user_card_progress pr ON pr.card_id = k.id
          WHERE p.parent_id IS NULL
          GROUP BY p.id'
    )->fetchAll(PDO::FETCH_ASSOC);

    foreach ($perArea as $row) {
        $cardsPerArea[(int) $row['area_id']] = (int) $row['cards'];
        $progressPerArea[(int) $row['area_id']] = (int) $row['progress'];
    }

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
        'cards_per_area' => $cardsPerArea,
        'progress_per_area' => $progressPerArea,
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
function import_print_step_a(array $state, array $subcategories, array $area): void
{
    echo "STEP A - delete the subcategories of the target area\n";
    echo '  only below "' . $area['name'] . '" (id ' . $area['id'] . ') - every other area keeps its subcategories' . "\n";

    $areaId = (int) $area['id'];
    $names = [];

    foreach ($state['subcategories'] as $subcategory) {
        if ((int) $subcategory['area_id'] === $areaId) {
            $names[] = $subcategory['name'];
        }
    }

    if ($names === []) {
        echo "  there is no subcategory below this area at the moment\n";
    } else {
        echo '  ' . count($names) . " subcategories: " . implode(', ', $names) . "\n";
    }

    echo '  cards to delete          : ' . ($state['cards_per_area'][$areaId] ?? 0) . "\n";
    echo '  progress rows to delete  : ' . ($state['progress_per_area'][$areaId] ?? 0) . "\n";
    echo '  subcategories to delete  : ' . count($names) . "\n";

    $otherAreas = $state['subcategory_count'] - count($names);

    if ($otherAreas > 0) {
        echo '  left untouched           : ' . $otherAreas . " subcategories in the other areas\n";
    }

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

    echo "\n  validation: header ok, " . count($csv['rows']) . " rows, at least one complete language per row,\n";
    echo "              is_bidirectional only 0 or 1, no duplicate front per subcategory,\n";
    echo '              map_region empty or ' . REGION_PATTERN . "\n";
    echo '  cards           : ' . count($csv['rows']) . "\n";
    echo '  with map region : ' . $csv['with_region'] . "\n";
    echo '  without region  : ' . (count($csv['rows']) - $csv['with_region']) . "\n";
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
/**
 * The cards of the file whose front side is already stored in one of the named
 * subcategories below the area.
 *
 * The comparison is the one the import dialog of the application uses: the front
 * side, trimmed, in lower case, with runs of whitespace collapsed.
 *
 * @param array<string, mixed> $area  the learning area, with its id
 * @param list<string>         $names the subcategories of the file that exist
 * @param array<string, mixed> $csv
 * @return list<string> one line per card, ready to be printed
 */
function import_front_collisions(PDO $pdo, array $area, array $names, array $csv): array
{
    $key = static function (string $value): string {
        $flat = preg_replace('/\s+/u', ' ', trim($value));

        return mb_strtolower($flat === null ? '' : $flat, 'UTF-8');
    };

    $statement = $pdo->prepare(
        'SELECT k.front_de
           FROM cards k
           JOIN categories c ON c.id = k.category_id
          WHERE c.parent_id = :parent_id AND c.name = :name'
    );

    $stored = [];

    foreach ($names as $name) {
        $statement->bindValue(':parent_id', (int) $area['id'], PDO::PARAM_INT);
        $statement->bindValue(':name', $name, PDO::PARAM_STR);
        $statement->execute();

        foreach ($statement as $row) {
            $front = $key((string) $row['front_de']);

            if ($front !== '') {
                $stored[$front] = true;
            }
        }
    }

    $collisions = [];

    foreach ($csv['rows'] as $row) {
        if (in_array($row['subcategory'], $names, true) === false) {
            continue;
        }

        $front = $key((string) $row['front_de']);

        if ($front !== '' && isset($stored[$front])) {
            $collisions[] = $row['subcategory'] . ' | ' . mb_substr((string) $row['front_de'], 0, 60);
        }
    }

    return $collisions;
}

function import_execute(PDO $pdo, bool $wipe, bool $reuse, array $area, array $csv): array
{
    $pdo->beginTransaction();

    $deleted = ['categories' => 0, 'cards' => 0, 'progress' => 0];

    /* ---- STEP A ---------------------------------------------------------- */

    if ($wipe) {
        $deleted = import_delete_subcategories($pdo, (int) $area['id']);

        /* The proof: nothing is left below THIS area. */
        $left = (int) $pdo->query('SELECT COUNT(*) FROM categories WHERE parent_id = ' . (int) $area['id'])->fetchColumn();

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

    /*
     * With --allow-existing-subcategories a subcategory that is already there is
     * reused: its id is taken over and nothing is inserted. Without the flag the
     * caller has made sure that no name of the file exists yet.
     */
    $reusedCategories = 0;
    $reusable = [];

    if ($reuse) {
        $existing = $pdo->prepare('SELECT id, name FROM categories WHERE parent_id = :parent_id');
        $existing->bindValue(':parent_id', (int) $area['id'], PDO::PARAM_INT);
        $existing->execute();

        foreach ($existing as $row) {
            $reusable[(string) $row['name']] = (int) $row['id'];
        }
    }

    foreach ($csv['order'] as $name) {
        if ($reuse && isset($reusable[$name])) {
            $idOf[$name] = $reusable[$name];
            $reusedCategories++;
            continue;
        }

        $insertCategory->bindValue(':parent_id', (int) $area['id'], PDO::PARAM_INT);
        $insertCategory->bindValue(':name', $name, PDO::PARAM_STR);
        $insertCategory->bindValue(':name_de', $name, PDO::PARAM_STR);
        $insertCategory->execute();

        $idOf[$name] = (int) $pdo->lastInsertId();
        $createdCategories++;
    }

    /*
     * The cards go in the order of the file. There is no sort column: the list is
     * ordered by id, so the ids ascending are the order of the file.
     *
     * The two old columns front and back are NOT NULL and still read by older
     * code, so they carry the German text as well - the same rule the application
     * uses when a card is saved in the dialog.
     */
    $insertCard = $pdo->prepare(
        'INSERT INTO cards
            (category_id, front, back, front_de, back_de, front_en, back_en, map_region, is_bidirectional)
         VALUES
            (:category_id, :front, :back, :front_de, :back_de, :front_en, :back_en, :map_region, :is_bidirectional)'
    );

    $createdExercises = 0;

    foreach ($csv['rows'] as $row) {
        $insertCard->bindValue(':category_id', $idOf[$row['subcategory']], PDO::PARAM_INT);
        $insertCard->bindValue(':front', $row['front_de'], PDO::PARAM_STR);
        $insertCard->bindValue(':back', $row['back_de'], PDO::PARAM_STR);
        $insertCard->bindValue(':front_de', $row['front_de'], PDO::PARAM_STR);
        $insertCard->bindValue(':back_de', $row['back_de'], PDO::PARAM_STR);
        $insertCard->bindValue(':front_en', $row['front_en'], PDO::PARAM_STR);
        $insertCard->bindValue(':back_en', $row['back_en'], PDO::PARAM_STR);
        $insertCard->bindValue(':map_region', $row['map_region'] === '' ? null : $row['map_region'], $row['map_region'] === '' ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $insertCard->bindValue(':is_bidirectional', $row['is_bidirectional'], PDO::PARAM_INT);
        $insertCard->execute();

        /*
         * An exercise card gets its row in card_exercises right away, in the same
         * transaction: the numbers of the task belong to the card. save_card_exercise()
         * is the same function the card endpoints use, and it refuses to write when
         * the migration for the column is missing.
         */
        if ($row['exercise'] !== null) {
            save_card_exercise($pdo, (int) $pdo->lastInsertId(), $row['exercise']);
            $createdExercises++;
        }

        $createdCards++;
    }

    if ($createdExercises > 0) {
        echo 'exercise cards: ' . $createdExercises . "\n";
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
        'reused_categories' => $reusedCategories,
        'created_cards' => $createdCards,
    ];
}

/**
 * Deletes every subcategory BELOW one learning area.
 *
 * Only the area that the file names is emptied: importing one area can never
 * take the subcategories of another one with it.
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
function import_delete_subcategories(PDO $pdo, int $areaId): array
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

    /* Only the descendants of this area. */
    foreach (array_keys($parentOf) as $id) {
        $cursor = $id;
        $belongs = false;
        $guard = 0;

        while ($guard < 20) {
            $guard++;

            if ($parentOf[$cursor] === $areaId) {
                $belongs = true;
                break;
            }

            if (!isset($parentOf[$parentOf[$cursor]])) {
                break;
            }

            $cursor = $parentOf[$cursor];
        }

        if (!$belongs) {
            unset($parentOf[$id]);
        }
    }

    if ($parentOf === []) {
        return ['categories' => 0, 'cards' => 0, 'progress' => 0];
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
