<?php

declare(strict_types=1);

/**
 * Imports flashcard CSV files into one learning area.
 *
 * The format is fixed and carries everything the two tables need:
 *
 *     category,front,back,front_de,back_de,front_en,back_en,is_bidirectional
 *
 *   - `category` is the name of the subcategory a row belongs to. One file may
 *     hold several of them, several files may feed the same one.
 *   - `front`/`back` are the pair the card shows. The four language columns carry
 *     the same text per language, and `front_en`/`back_en` are the SWAPPED pair -
 *     the file is the truth and nothing is mirrored or guessed here.
 *   - `is_bidirectional` is 0 or 1.
 *
 * There is no per-file knowledge in this tool: the same command reads every file
 * of that format, whatever it is about. Which area the cards land in is said on
 * the command line (--area), not guessed from the file name.
 *
 * Writing happens in ONE transaction: either every row of every file is in the
 * database or none of it is. Without --execute the tool only reads and reports,
 * so the plan can be read against before anything is written.
 *
 * Usage:
 *
 *     php bin/import_cards_csv.php --owner=6 --area=English \
 *         --file=b1_vokabelliste.csv --file=b2_vokabelliste.csv --dry-run
 *
 * A learning area that does not exist yet is created with --create-area; its
 * drawing comes from --icon=<path to an svg>.
 */

$projectRoot = dirname(__DIR__);

require_once $projectRoot . '/src/config/database.php';

/** The eight columns this format has, in the order the plan prints them. */
const CARD_CSV_COLUMNS = ['category', 'front', 'back', 'front_de', 'back_de', 'front_en', 'back_en', 'is_bidirectional'];

/** How many example cards the plan shows per subcategory. */
const CARD_CSV_SAMPLES = 2;

/* --------------------------------------------------------------------------
   Arguments
   -------------------------------------------------------------------------- */

/**
 * Reads --owner, --area, --file, --create-area, --icon, --color, --expect,
 * --dry-run and --execute.
 *
 * @return array{owner: int, area: string, files: list<string>, createArea: bool,
 *     icon: string|null, color: string|null, expect: int|null, execute: bool}|null
 */
function import_arguments(array $argv, string $projectRoot): ?array
{
    $owner = null;
    $area = null;
    $files = [];
    $createArea = false;
    $icon = null;
    $color = null;
    $expect = null;
    $execute = false;
    $modeGiven = false;

    foreach (array_slice($argv, 1) as $argument) {
        if (import_int_option($argument, '--owner=', $owner)) {
            continue;
        }

        if (import_int_option($argument, '--expect=', $expect)) {
            continue;
        }

        if (strpos($argument, '--area=') === 0) {
            $area = trim(substr($argument, 7));

            if ($area === '') {
                echo "The value of --area must be a name.\n";

                return null;
            }

            continue;
        }

        if (strpos($argument, '--file=') === 0) {
            /* A path with a drive letter or a leading slash is taken as it is;
               everything else is read from the project folder. */
            $candidate = substr($argument, 7);
            $files[] = preg_match('/^([a-zA-Z]:|[\/\\\\])/', $candidate) === 1
                ? $candidate
                : $projectRoot . '/' . $candidate;
            continue;
        }

        if (strpos($argument, '--icon=') === 0) {
            $candidate = substr($argument, 7);
            $icon = preg_match('/^([a-zA-Z]:|[\/\\\\])/', $candidate) === 1
                ? $candidate
                : $projectRoot . '/' . $candidate;
            continue;
        }

        if (strpos($argument, '--color=') === 0) {
            $value = substr($argument, 8);

            if (preg_match('/^#[0-9A-Fa-f]{6}$/', $value) !== 1) {
                echo "The value of --color must look like #C3C8DB.\n";

                return null;
            }

            $color = $value;
            continue;
        }

        if ($argument === '--create-area') {
            $createArea = true;
            continue;
        }

        if ($argument === '--dry-run' || $argument === '--execute') {
            if ($modeGiven && ($argument === '--execute') !== $execute) {
                echo "Choose either --dry-run or --execute, not both.\n";

                return null;
            }

            $execute = $argument === '--execute';
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

    if ($owner === null || $area === null || $files === []) {
        echo "An owner, an area and at least one --file are required.\n\n";
        import_print_usage();

        return null;
    }

    if ($icon !== null && !is_file($icon)) {
        echo 'The icon file was not found: ' . basename($icon) . "\n";

        return null;
    }

    foreach ($files as $file) {
        if (!is_file($file)) {
            echo 'File not found: ' . basename($file) . "\n";

            return null;
        }
    }

    return [
        'owner' => $owner,
        'area' => $area,
        'files' => $files,
        'createArea' => $createArea,
        'icon' => $icon,
        'color' => $color,
        'expect' => $expect,
        'execute' => $execute,
    ];
}

/** Reads one "--name=<positive whole number>" argument, if it is the one at hand. */
function import_int_option(string $argument, string $prefix, ?int &$target): bool
{
    if (strpos($argument, $prefix) !== 0) {
        return false;
    }

    $value = substr($argument, strlen($prefix));

    if (!ctype_digit($value) || (int) $value < 1) {
        echo 'The value of ' . $prefix . ' must be a positive whole number.' . "\n";
        $target = null;

        /* The caller stops on a null, so a bad value is not silently ignored. */
        return true;
    }

    $target = (int) $value;

    return true;
}

function import_print_usage(): void
{
    echo "Usage:\n";
    echo "  php bin/import_cards_csv.php --owner=<id> --area=<name> --file=<path> [--file=<path> ...] --dry-run\n";
    echo "  php bin/import_cards_csv.php --owner=<id> --area=<name> [--create-area --icon=<svg>] --file=<path> --execute\n";
    echo "\n";
    echo "  --owner=<id>       the user the new subcategories belong to, required\n";
    echo "  --area=<name>      the learning area the cards go into, required\n";
    echo "  --create-area      create that area when it does not exist yet\n";
    echo "  --icon=<path>      the drawing of a new area (svg file)\n";
    echo "  --color=<#RRGGBB>  the stored colour of a new area\n";
    echo "  --file=<path>      one CSV file, may be given several times\n";
    echo "  --dry-run          read and report, write nothing (default)\n";
    echo "  --execute          really import, all of it or none of it\n";
    echo "\n";
    echo "The format of every file is: " . implode(',', CARD_CSV_COLUMNS) . "\n";
}

/* --------------------------------------------------------------------------
   Reading the files
   -------------------------------------------------------------------------- */

/** Takes a byte order mark off the first cell of a header. */
function import_strip_bom(string $value): string
{
    return strpos($value, "\xEF\xBB\xBF") === 0 ? substr($value, 3) : $value;
}

/**
 * Reads one record of a CSV file.
 *
 * fgetcsv() is used and not a split by line: a quoted cell may contain a line
 * break, and zeitformen.csv does. Splitting the file by hand would tear such a
 * row into two and turn the second half into a row of its own - which is exactly
 * what happened the first time this tool ran.
 */
function import_read_record($handle): array|false
{
    $cells = fgetcsv($handle, 0, ',', '"', '');

    return $cells === false ? false : array_map(static fn ($cell): string => (string) $cell, $cells);
}

/**
 * Reads one file and validates every row.
 *
 * @return array{name: string, rows: list<array<string, string>>, problems: list<string>, fatal: string|null}
 */
function import_read_csv(string $path): array
{
    $result = ['name' => basename($path), 'rows' => [], 'problems' => [], 'fatal' => null];

    $handle = @fopen($path, 'rb');

    if ($handle === false) {
        $result['fatal'] = 'the file cannot be read';

        return $result;
    }

    $header = import_read_record($handle);

    if ($header === false) {
        fclose($handle);
        $result['fatal'] = 'the file is empty';

        return $result;
    }

    $header[0] = import_strip_bom($header[0]);
    $indexOf = [];

    foreach ($header as $index => $name) {
        $indexOf[strtolower(trim($name))] = $index;
    }

    $missing = [];

    foreach (CARD_CSV_COLUMNS as $column) {
        if (!isset($indexOf[$column])) {
            $missing[] = $column;
        }
    }

    if ($missing !== []) {
        fclose($handle);
        $result['fatal'] = 'the column(s) ' . implode(', ', $missing) . ' are missing (found: '
            . implode(', ', array_map('trim', $header)) . ')';

        return $result;
    }

    $record = 0;

    while (($cells = import_read_record($handle)) !== false) {
        $record++;

        /*
         * The number a person sees in their spreadsheet: the header is row 1, so
         * the first record is row 2. It counts RECORDS and not lines of the
         * file, which is the same thing as long as no cell contains a break -
         * and the spreadsheet counts records as well.
         */
        $number = $record + 1;

        if (count($cells) === 1 && trim($cells[0]) === '') {
            continue;
        }

        $row = ['line' => (string) $number];

        foreach (CARD_CSV_COLUMNS as $column) {
            $row[$column] = trim((string) ($cells[$indexOf[$column]] ?? ''));
        }

        if ($row['is_bidirectional'] === '') {
            $row['is_bidirectional'] = '0';
        }

        if ($row['category'] === '') {
            $result['problems'][] = 'row ' . $number . ': the category is empty';
            continue;
        }

        if (trim($row['front']) === '' && trim($row['back']) === '') {
            $result['problems'][] = 'row ' . $number . ': front and back are both empty';
            continue;
        }

        if ($row['is_bidirectional'] !== '0' && $row['is_bidirectional'] !== '1') {
            $result['problems'][] = 'row ' . $number . ': is_bidirectional is "' . $row['is_bidirectional'] . '", expected 0 or 1';
            continue;
        }

        $result['rows'][] = $row;
    }

    fclose($handle);

    return $result;
}

/**
 * Groups the rows of all files by the subcategory they name, and looks for two
 * rows that would become the same card.
 *
 * @param list<array{name: string, rows: list<array<string, string>>, problems: list<string>, fatal: string|null}> $files
 * @return array{groups: array<string, array{rows: list<array<string, string>>, files: list<string>}>, problems: list<string>}
 */
function import_group_rows(array $files): array
{
    $groups = [];
    $problems = [];

    foreach ($files as $file) {
        foreach ($file['rows'] as $row) {
            $name = $row['category'];

            if (!isset($groups[$name])) {
                $groups[$name] = ['rows' => [], 'files' => []];
            }

            $groups[$name]['rows'][] = $row;

            if (!in_array($file['name'], $groups[$name]['files'], true)) {
                $groups[$name]['files'][] = $file['name'];
            }
        }
    }

    /* Two rows with the same front in one subcategory would be two cards nobody
       can tell apart, so they stop the run instead of being written twice. */
    foreach ($groups as $name => $group) {
        $seen = [];

        foreach ($group['rows'] as $row) {
            $key = mb_strtolower(trim($row['front']) . "\x00" . trim($row['back']));

            if (isset($seen[$key])) {
                $problems[] = $name . ': row ' . $row['line'] . ' repeats the card from row ' . $seen[$key];
                continue;
            }

            $seen[$key] = $row['line'];
        }
    }

    return ['groups' => $groups, 'problems' => $problems];
}

/* --------------------------------------------------------------------------
   The plan
   -------------------------------------------------------------------------- */

/** One line that says where the cards of this file would go. */
function import_print_file(array $file): void
{
    echo 'FILE  ' . $file['name'] . "\n";

    if ($file['fatal'] !== null) {
        echo '  STOP: ' . $file['fatal'] . "\n\n";

        return;
    }

    echo '  ' . count($file['rows']) . " rows\n";

    foreach ($file['problems'] as $problem) {
        echo '  skip  ' . $problem . "\n";
    }

    echo "\n";
}

function import_print_group(string $name, array $group, bool $exists): void
{
    $both = 0;

    foreach ($group['rows'] as $row) {
        if ($row['is_bidirectional'] === '1') {
            $both++;
        }
    }

    printf(
        "  %-42s %5d cards  both directions: %-5d one direction: %-5d  %s\n",
        $name,
        count($group['rows']),
        $both,
        count($group['rows']) - $both,
        $exists ? 'ALREADY THERE - a blocker' : 'new'
    );

    foreach (array_slice($group['rows'], 0, CARD_CSV_SAMPLES) as $row) {
        printf(
            "      front: %-28s back: %-28s de: %s | %s   en: %s | %s\n",
            mb_substr($row['front'], 0, 28),
            mb_substr($row['back'], 0, 28),
            mb_substr($row['front_de'], 0, 18),
            mb_substr($row['back_de'], 0, 18),
            mb_substr($row['front_en'], 0, 18),
            mb_substr($row['back_en'], 0, 18)
        );
    }

    if (count($group['rows']) > CARD_CSV_SAMPLES) {
        echo '      ... and ' . (count($group['rows']) - CARD_CSV_SAMPLES) . " more\n";
    }
}

/* --------------------------------------------------------------------------
   Writing
   -------------------------------------------------------------------------- */

/**
 * Writes every subcategory and every card of the plan.
 *
 * The caller has opened the transaction. Everything that can go wrong throws, so
 * the caller can roll the whole run back and the database stays as it was.
 *
 * @return array{categories: int, cards: int}
 */
function import_write(PDO $pdo, array $groups, int $areaId, int $ownerUserId): array
{
    $insertCategory = $pdo->prepare(
        'INSERT INTO categories (parent_id, name, name_en, name_de, owner_user_id)
         VALUES (:parent_id, :name, :name_en, :name_de, :owner_user_id)'
    );

    /*
     * The eight columns of the file, written one by one. front/back are the pair
     * the card shows; the language columns carry the same text per language, and
     * front_en/back_en are the swapped pair exactly as the file has them.
     * map_region keeps its default: this format says nothing about a map.
     */
    $insertCard = $pdo->prepare(
        'INSERT INTO cards
            (category_id, front, back, front_de, back_de, front_en, back_en, is_bidirectional)
         VALUES
            (:category_id, :front, :back, :front_de, :back_de, :front_en, :back_en, :is_bidirectional)'
    );

    $categories = 0;
    $cards = 0;

    foreach ($groups as $name => $group) {
        /* name_de and name_en carry the same name: the subcategory is called the
           same in both languages, which is what the other imports do as well. */
        $insertCategory->bindValue(':parent_id', $areaId, PDO::PARAM_INT);
        $insertCategory->bindValue(':name', $name, PDO::PARAM_STR);
        $insertCategory->bindValue(':name_en', $name, PDO::PARAM_STR);
        $insertCategory->bindValue(':name_de', $name, PDO::PARAM_STR);
        $insertCategory->bindValue(':owner_user_id', $ownerUserId, PDO::PARAM_INT);
        $insertCategory->execute();

        $categoryId = (int) $pdo->lastInsertId();
        $categories++;

        foreach ($group['rows'] as $row) {
            $insertCard->bindValue(':category_id', $categoryId, PDO::PARAM_INT);
            $insertCard->bindValue(':front', $row['front'], PDO::PARAM_STR);
            $insertCard->bindValue(':back', $row['back'], PDO::PARAM_STR);
            $insertCard->bindValue(':front_de', $row['front_de'], PDO::PARAM_STR);
            $insertCard->bindValue(':back_de', $row['back_de'], PDO::PARAM_STR);
            $insertCard->bindValue(':front_en', $row['front_en'], PDO::PARAM_STR);
            $insertCard->bindValue(':back_en', $row['back_en'], PDO::PARAM_STR);
            $insertCard->bindValue(':is_bidirectional', (int) $row['is_bidirectional'], PDO::PARAM_INT);
            $insertCard->execute();

            $cards++;
        }
    }

    return ['categories' => $categories, 'cards' => $cards];
}

/* --------------------------------------------------------------------------
   Main
   -------------------------------------------------------------------------- */

function import_main(array $argv, string $projectRoot): int
{
    $options = import_arguments($argv, $projectRoot);

    if ($options === null) {
        return 1;
    }

    echo "Flashcard import (one format, one area)\n";
    echo 'Mode  : ' . ($options['execute'] ? 'EXECUTE (writes to the database)' : 'DRY RUN (reads only)') . "\n";
    echo 'Owner : id ' . $options['owner'] . "\n";
    echo 'Area  : ' . $options['area'] . "\n";
    echo 'Files : ' . count($options['files']) . "\n\n";

    try {
        $pdo = create_database_connection();
    } catch (Throwable $error) {
        echo "DATABASE ERROR: the connection could not be opened.\n";

        return 1;
    }

    /* ---- the owner ---- */

    $ownerStatement = $pdo->prepare('SELECT id, name FROM users WHERE id = :id');
    $ownerStatement->bindValue(':id', $options['owner'], PDO::PARAM_INT);
    $ownerStatement->execute();
    $ownerRow = $ownerStatement->fetch();

    if ($ownerRow === false) {
        echo 'STOP: there is no user with id ' . $options['owner'] . ".\n";

        return 1;
    }

    echo 'User  : ' . $ownerRow['name'] . "\n\n";

    /* ---- the area ---- */

    /*
     * The area is looked for by name, name_de and name_en, and only among the
     * areas of this owner: two accounts may each have an area of the same name,
     * and the cards must land in the right one.
     */
    $areaStatement = $pdo->prepare(
        'SELECT id, name FROM categories
          WHERE parent_id IS NULL
            AND owner_user_id = :owner_user_id
            AND (name = :name OR name_en = :name_en OR name_de = :name_de)
          ORDER BY id'
    );
    $areaStatement->bindValue(':owner_user_id', $options['owner'], PDO::PARAM_INT);
    $areaStatement->bindValue(':name', $options['area'], PDO::PARAM_STR);
    $areaStatement->bindValue(':name_en', $options['area'], PDO::PARAM_STR);
    $areaStatement->bindValue(':name_de', $options['area'], PDO::PARAM_STR);
    $areaStatement->execute();
    $areaRows = $areaStatement->fetchAll();

    if (count($areaRows) > 1) {
        echo 'STOP: "' . $options['area'] . "\" matches more than one area of this user:\n";

        foreach ($areaRows as $row) {
            echo '  id ' . $row['id'] . ': ' . $row['name'] . "\n";
        }

        return 1;
    }

    $area = $areaRows === [] ? null : $areaRows[0];

    if ($area === null && !$options['createArea']) {
        echo 'STOP: this user has no area called "' . $options['area'] . "\".\n";
        echo "      Create it with --create-area (and --icon=<svg> for its drawing).\n";

        return 1;
    }

    if ($area === null) {
        echo 'AREA  : "' . $options['area'] . '" is created' . "\n";
    } else {
        echo 'AREA  : id ' . $area['id'] . ' (' . $area['name'] . ")\n";
    }

    /* ---- the files ---- */

    $files = [];

    foreach ($options['files'] as $path) {
        $files[] = import_read_csv($path);
    }

    $grouping = import_group_rows($files);
    $groups = $grouping['groups'];

    echo "\n" . str_repeat('-', 78) . "\n";
    echo "THE PLAN\n";
    echo str_repeat('-', 78) . "\n";

    foreach ($files as $file) {
        import_print_file($file);
    }

    /* Which of the planned subcategories are already under this area? */
    $existing = [];
    $blockers = $grouping['problems'];

    if ($area !== null && $groups !== []) {
        $check = $pdo->prepare('SELECT name FROM categories WHERE parent_id = :parent_id AND owner_user_id = :owner_user_id');
        $check->bindValue(':parent_id', (int) $area['id'], PDO::PARAM_INT);
        $check->bindValue(':owner_user_id', $options['owner'], PDO::PARAM_INT);
        $check->execute();

        foreach ($check as $row) {
            $existing[(string) $row['name']] = true;
        }
    }

    echo "SUBCATEGORIES\n";

    foreach ($groups as $name => $group) {
        import_print_group($name, $group, isset($existing[$name]));
    }

    $cards = 0;

    foreach ($groups as $group) {
        $cards += count($group['rows']);
    }

    echo "\nTOTAL\n";
    echo '  subcategories to create : ' . count($groups) . "\n";
    echo '  cards to create         : ' . $cards . "\n";

    foreach ($files as $file) {
        if ($file['fatal'] !== null) {
            $blockers[] = $file['name'] . ': ' . $file['fatal'];
        }
    }

    if ($options['expect'] !== null && $cards !== $options['expect']) {
        $blockers[] = 'the files hold ' . $cards . ' cards, but --expect says ' . $options['expect'];
    }

    if ($blockers !== []) {
        echo "\nSTOP: nothing can be written yet:\n";

        foreach ($blockers as $blocker) {
            echo '  - ' . $blocker . "\n";
        }

        return 1;
    }

    if (!$options['execute']) {
        echo "\nDRY RUN: nothing was written. Add --execute to write this plan.\n";

        return 0;
    }

    /* ---- writing, all of it or none of it ---- */

    $pdo->beginTransaction();

    try {
        if ($area === null) {
            $icon = $options['icon'] === null ? null : (string) file_get_contents($options['icon']);

            $createArea = $pdo->prepare(
                'INSERT INTO categories (parent_id, name, name_en, name_de, owner_user_id, color, icon_svg, icon_scale)
                 VALUES (NULL, :name, :name_en, :name_de, :owner_user_id, :color, :icon_svg, 1.00)'
            );
            $createArea->bindValue(':name', $options['area'], PDO::PARAM_STR);
            $createArea->bindValue(':name_en', $options['area'], PDO::PARAM_STR);
            $createArea->bindValue(':name_de', $options['area'], PDO::PARAM_STR);
            $createArea->bindValue(':owner_user_id', $options['owner'], PDO::PARAM_INT);
            $createArea->bindValue(':color', $options['color'], $options['color'] === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $createArea->bindValue(':icon_svg', $icon, $icon === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $createArea->execute();

            $areaId = (int) $pdo->lastInsertId();
        } else {
            $areaId = (int) $area['id'];
        }

        $written = import_write($pdo, $groups, $areaId, $options['owner']);

        if ($written['cards'] !== $cards) {
            throw new RuntimeException('not every card was written (' . $written['cards'] . ' of ' . $cards . ')');
        }

        /* The proof, read before the commit: the rows are really there. */
        $countStatement = $pdo->prepare(
            'SELECT COUNT(*) FROM cards k JOIN categories c ON c.id = k.category_id
              WHERE c.parent_id = :parent_id AND c.owner_user_id = :owner_user_id'
        );
        $countStatement->bindValue(':parent_id', $areaId, PDO::PARAM_INT);
        $countStatement->bindValue(':owner_user_id', $options['owner'], PDO::PARAM_INT);
        $countStatement->execute();

        $inArea = (int) $countStatement->fetchColumn();

        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        echo "\nIMPORT FAILED - rolled back, the database is exactly as it was.\n";
        echo '  ' . $error->getMessage() . "\n";

        return 1;
    }

    echo "\nIMPORT FINISHED\n";
    echo '  area created            : ' . ($area === null ? 'yes' : 'no, it was there') . "\n";
    echo '  subcategories created   : ' . $written['categories'] . "\n";
    echo '  cards created           : ' . $written['cards'] . "\n";
    echo '  cards in the area now   : ' . $inArea . "\n";

    return 0;
}

exit(import_main($argv, $projectRoot));
