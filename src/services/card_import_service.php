<?php

declare(strict_types=1);

/**
 * Importing flashcards from a CSV file into ONE subcategory.
 *
 * The file is a semicolon separated CSV in UTF-8. The first line is the header
 * and names the columns; the order does not matter and the names are not case
 * sensitive. The canonical header - the one shown in the dialog and written in
 * the sample file - is
 *
 *     front_de;back_de;front_en;back_en;is_bidirectional
 *
 * German and English are the two languages a card can carry (see
 * card_language_columns() in card_service.php). A row must have at least ONE
 * complete language: a front side AND a back side. The second language may be
 * missing completely, but not half filled.
 *
 * What this file does NOT do
 *
 *   * it never changes the table structure,
 *   * it never writes a user_card_progress row (imported cards are new cards),
 *   * it never deletes anything,
 *   * it never writes into another category than the one that was passed in.
 *
 * Why the rows are checked here and not with clean_input_text()
 *
 * The helpers in request_input.php report the FIRST problem by ending the
 * request, which is right for a form with one card. A file has many rows, and
 * the person who uploads it wants to see every problem at once - so the same
 * rules (valid UTF-8, at most 2000 characters, no control characters) are applied
 * here row by row, and the problems are collected instead of thrown.
 */

require_once __DIR__ . '/card_service.php';
require_once __DIR__ . '/../helpers/request_input.php';

/**
 * The header as this import documents it and as the sample file uses it.
 *
 * @var list<string>
 */
const CARD_IMPORT_HEADER = ['front_de', 'back_de', 'front_en', 'back_en', 'is_bidirectional'];

/** The file must not be bigger than this. Must match the value in index.php. */
const CARD_IMPORT_MAX_BYTES = 1048576;

/** The file must not have more data rows than this. Must match index.php. */
const CARD_IMPORT_MAX_ROWS = 1000;

/** The separator of the file. A CSV that uses it is read by fgetcsv(). */
const CARD_IMPORT_SEPARATOR = ';';

/**
 * Every name a column of the file may have, lower case.
 *
 * The canonical names are the ones the sample file uses; the others are accepted
 * so a file written by hand - or exported from another tool - does not have to be
 * renamed first.
 *
 * @var array<string, list<string>>
 */
const CARD_IMPORT_ALIASES = [
    'front_de' => ['front_de', 'front', 'vorderseite_de', 'vorderseite'],
    'back_de' => ['back_de', 'back', 'rueckseite_de', 'rückseite_de', 'rueckseite', 'rückseite'],
    'front_en' => ['front_en', 'vorderseite_en'],
    'back_en' => ['back_en', 'rueckseite_en', 'rückseite_en'],
    'is_bidirectional' => ['is_bidirectional', 'auch_umgekehrt', 'umgekehrt', 'bidirectional'],
];

/** Values that mean "yes" and "no" in the optional 0/1 column. */
const CARD_IMPORT_TRUE_VALUES = ['1', 'true', 'yes', 'ja', 'j', 'x', 'wahr'];
const CARD_IMPORT_FALSE_VALUES = ['0', 'false', 'no', 'nein', 'n', ''];

/**
 * Reads the file and returns the header and the raw rows.
 *
 * Nothing is validated against the database here - this function only turns the
 * bytes into rows, so preview and import look at exactly the same data.
 *
 * @return array{
 *     columns: list<string>,
 *     rows: list<array{line: int, values: array<string, string>, fields: int}>,
 *     data_rows: int,
 *     fatal: array{code: string, params: array<string, string|int>}|null
 * }
 */
function card_import_read_file(string $path): array
{
    $empty = ['columns' => [], 'rows' => [], 'data_rows' => 0, 'fatal' => null];
    $handle = fopen($path, 'rb');

    if ($handle === false) {
        return card_import_fatal($empty, 'read_failed');
    }

    /*
     * A byte order mark in front of the first header name would make the name
     * unrecognisable, so the three bytes are read and dropped before fgetcsv()
     * sees them - the same trick the command line importer uses.
     */
    if (fread($handle, 3) !== "\xEF\xBB\xBF") {
        rewind($handle);
    }

    $labels = fgetcsv($handle, 0, CARD_IMPORT_SEPARATOR);

    if ($labels === false || $labels === [null] || card_import_row_is_empty($labels)) {
        fclose($handle);

        return card_import_fatal($empty, 'empty_file');
    }

    if (isset($labels[0]) && strpos((string) $labels[0], "\xEF\xBB\xBF") === 0) {
        $labels[0] = substr((string) $labels[0], 3);
    }

    /* The header is trimmed; the text of a card never is. */
    $labels = array_map(static fn ($label): string => trim((string) $label), $labels);
    $map = [];
    $columns = [];
    $unknown = [];

    foreach ($labels as $index => $label) {
        $column = card_import_column_for($label);

        if ($column === null) {
            if ($label !== '') {
                $unknown[] = $label;
            }

            continue;
        }

        if (isset($map[$column])) {
            /* The same column twice: the first one wins, the file is refused. */
            $unknown[] = $label;

            continue;
        }

        $map[$column] = $index;
        $columns[] = $column;
    }

    if ($unknown !== []) {
        fclose($handle);

        /* Kept as a list of names, so the message can name what was not known. */
        $empty['fatal'] = ['code' => 'header_unknown', 'params' => ['columns' => implode(', ', $unknown)]];

        return $empty;
    }

    $missing = card_import_missing_columns($columns);

    if ($missing !== []) {
        fclose($handle);

        $empty['fatal'] = ['code' => 'header_missing', 'params' => ['columns' => implode(', ', $missing)]];

        return $empty;
    }

    $rows = [];
    $lineNumber = 1;

    while (($cells = fgetcsv($handle, 0, CARD_IMPORT_SEPARATOR)) !== false) {
        $lineNumber++;

        if ($cells === [null] || card_import_row_is_empty($cells)) {
            /* A blank line carries nothing, so it is not a row. */
            continue;
        }

        if (count($rows) >= CARD_IMPORT_MAX_ROWS) {
            fclose($handle);

            $empty['fatal'] = ['code' => 'too_many_rows', 'params' => ['rows' => CARD_IMPORT_MAX_ROWS]];

            return $empty;
        }

        $values = [];

        foreach (CARD_IMPORT_HEADER as $column) {
            $index = $map[$column] ?? null;
            /* A trailing carriage return of a CRLF file belongs to the line
               ending, not to the text. */
            $values[$column] = $index === null || !isset($cells[$index])
                ? ''
                : rtrim((string) $cells[$index], "\r");
        }

        $rows[] = ['line' => $lineNumber, 'values' => $values, 'fields' => count($cells)];
    }

    fclose($handle);

    return ['columns' => $columns, 'rows' => $rows, 'data_rows' => count($rows), 'fatal' => null];
}

/**
 * The canonical column a header label means, or null when nothing matches.
 */
function card_import_column_for(string $label): ?string
{
    $needle = mb_strtolower(trim($label));

    foreach (CARD_IMPORT_ALIASES as $column => $names) {
        if (in_array($needle, $names, true)) {
            return $column;
        }
    }

    return null;
}

/**
 * Which of the four required text columns are missing.
 *
 * A file may leave out a whole language, but it cannot leave out half of every
 * language: at least one pair (front and back of the same language) has to exist
 * as columns.
 *
 * @param list<string> $columns
 * @return list<string>
 */
function card_import_missing_columns(array $columns): array
{
    $germanComplete = in_array('front_de', $columns, true) && in_array('back_de', $columns, true);
    $englishComplete = in_array('front_en', $columns, true) && in_array('back_en', $columns, true);

    if ($germanComplete || $englishComplete) {
        return [];
    }

    /* Nothing usable was found: name the four columns this import needs. */
    return ['front_de', 'back_de', 'front_en', 'back_en'];
}

/**
 * True when every cell of the line is empty.
 *
 * @param list<string|null> $cells
 */
function card_import_row_is_empty(array $cells): bool
{
    foreach ($cells as $cell) {
        if (trim((string) $cell) !== '') {
            return false;
        }
    }

    return true;
}

/**
 * Marks a file as unreadable and keeps the answer in the same shape.
 *
 * @param array<string, mixed> $result
 * @return array<string, mixed>
 */
function card_import_fatal(array $result, string $code, array $params = []): array
{
    $result['fatal'] = ['code' => $code, 'params' => $params];

    return $result;
}

/**
 * Checks every row and builds the cards that would be imported.
 *
 * @param array<string, mixed> $read The result of card_import_read_file().
 * @param list<string>         $existingFronts The normalised fronts that already exist in this subcategory.
 * @param list<string>         $tableColumns The columns the cards table really has.
 * @return array{
 *     cards: list<array<string, mixed>>,
 *     errors: list<array{line: int, code: string, params: array<string, string|int>}>,
 *     preview: list<array<string, mixed>>,
 *     importable: int,
 *     duplicates: int,
 *     invalid: int
 * }
 */
function card_import_validate(array $read, array $existingFronts, array $tableColumns): array
{
    $pairs = card_language_columns($tableColumns);
    $cards = [];
    $errors = [];
    $preview = [];
    $seen = [];
    $duplicates = 0;

    foreach ($read['rows'] as $row) {
        $values = $row['values'];
        $line = $row['line'];
        $problem = card_import_row_problem($row, $read['columns'], $pairs);

        if ($problem !== null) {
            $errors[] = ['line' => $line, 'code' => $problem['code'], 'params' => $problem['params']];
            $preview[] = card_import_preview_row($line, $values, false, 'invalid');

            continue;
        }

        $front = card_import_row_front($values);
        $key = card_import_front_key($front);
        $isDuplicate = $key !== '' && (isset($existingFronts[$key]) || isset($seen[$key]));

        if ($isDuplicate) {
            $duplicates++;
            $preview[] = card_import_preview_row($line, $values, false, 'duplicate');

            continue;
        }

        $seen[$key] = true;
        $cards[] = card_import_card_from_row($values);
        $preview[] = card_import_preview_row($line, $values, true, 'ok');
    }

    return [
        'cards' => $cards,
        'errors' => $errors,
        'preview' => $preview,
        'importable' => count($cards),
        'duplicates' => $duplicates,
        'invalid' => count($errors),
    ];
}

/**
 * The ONE reason a row cannot be imported, or null when it is fine.
 *
 * Only the first problem is reported per row: the file is either correct or it
 * is corrected and uploaded again, and a list of five reasons for the same line
 * would not help anybody.
 *
 * @param array{line: int, values: array<string, string>, fields: int} $row
 * @param list<string> $columns The columns the file has.
 * @param array<string, list<string>> $pairs The language columns of the table.
 * @return array{code: string, params: array<string, string|int>}|null
 */
function card_import_row_problem(array $row, array $columns, array $pairs): ?array
{
    if ($row['fields'] !== count($columns)) {
        return ['code' => 'fields_count', 'params' => ['found' => $row['fields'], 'expected' => count($columns)]];
    }

    foreach ($row['values'] as $value) {
        if (!mb_check_encoding($value, 'UTF-8')) {
            return ['code' => 'encoding', 'params' => []];
        }

        if (mb_strlen($value) > CARD_MAX_TEXT_LENGTH) {
            return ['code' => 'too_long', 'params' => ['max' => CARD_MAX_TEXT_LENGTH]];
        }

        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $value) === 1) {
            return ['code' => 'control_characters', 'params' => []];
        }
    }

    $flag = trim((string) $row['values']['is_bidirectional']);

    if (!in_array(mb_strtolower($flag), CARD_IMPORT_TRUE_VALUES, true)
        && !in_array(mb_strtolower($flag), CARD_IMPORT_FALSE_VALUES, true)) {
        return ['code' => 'flag_value', 'params' => ['value' => $flag]];
    }

    /* Does the table have the columns this row needs? */
    foreach (['de', 'en'] as $language) {
        if (!isset($pairs[$language]) && card_import_language_used($row['values'], $language)) {
            return ['code' => 'language_unavailable', 'params' => ['language' => $language]];
        }
    }

    $germanComplete = card_import_language_complete($row['values'], 'de');
    $englishComplete = card_import_language_complete($row['values'], 'en');

    foreach (['de', 'en'] as $language) {
        if (card_import_language_used($row['values'], $language) && !card_import_language_complete($row['values'], $language)) {
            return ['code' => 'language_half', 'params' => ['language' => $language]];
        }
    }

    if (!$germanComplete && !$englishComplete) {
        return ['code' => 'no_language', 'params' => []];
    }

    return null;
}

/**
 * True when at least one of the two sides of a language carries text.
 *
 * @param array<string, string> $values
 */
function card_import_language_used(array $values, string $language): bool
{
    return trim($values['front_' . $language] ?? '') !== ''
        || trim($values['back_' . $language] ?? '') !== '';
}

/**
 * True when both sides of a language carry text.
 *
 * @param array<string, string> $values
 */
function card_import_language_complete(array $values, string $language): bool
{
    return trim($values['front_' . $language] ?? '') !== ''
        && trim($values['back_' . $language] ?? '') !== '';
}

/**
 * The front side a duplicate is looked up by: German when it is there, English
 * otherwise. Nothing else on a card says "this is the same question".
 *
 * @param array<string, string> $values
 */
function card_import_row_front(array $values): string
{
    if (trim($values['front_de']) !== '') {
        return $values['front_de'];
    }

    return $values['front_en'];
}

/**
 * The comparison key of a front side: trimmed and case insensitive, so "Was ist
 * Strom?" and "was ist strom?" count as the same question.
 */
function card_import_front_key(string $front): string
{
    return mb_strtolower(trim($front));
}

/**
 * Turns one row into the values the import writes. The keys are the names the
 * file uses; card_import_insert() maps them onto the real columns of the table.
 *
 * @param array<string, string> $values
 * @return array<string, mixed>
 */
function card_import_card_from_row(array $values): array
{
    $flag = mb_strtolower(trim($values['is_bidirectional']));

    return [
        'front_de' => $values['front_de'],
        'back_de' => $values['back_de'],
        'front_en' => $values['front_en'],
        'back_en' => $values['back_en'],
        'is_bidirectional' => in_array($flag, CARD_IMPORT_TRUE_VALUES, true),
    ];
}

/**
 * One row of the preview table.
 *
 * @param array<string, string> $values
 * @return array<string, mixed>
 */
function card_import_preview_row(int $line, array $values, bool $imports, string $state): array
{
    return [
        'line' => $line,
        'front_de' => $values['front_de'],
        'back_de' => $values['back_de'],
        'front_en' => $values['front_en'],
        'back_en' => $values['back_en'],
        'is_bidirectional' => in_array(mb_strtolower(trim($values['is_bidirectional'])), CARD_IMPORT_TRUE_VALUES, true),
        'state' => $state,
        'imports' => $imports,
    ];
}

/**
 * The front sides that already exist in one subcategory, as comparison keys.
 *
 * Both languages are collected: a German card and an English card with the same
 * question would be the same card for the person who reads them.
 *
 * @return array<string, true>
 */
function card_import_existing_fronts(PDO $pdo, int $categoryId): array
{
    $columns = card_columns($pdo);
    $pairs = card_language_columns($columns);
    $statement = $pdo->prepare(
        'SELECT front, back, front_de, back_de, front_en, back_en
           FROM cards
          WHERE category_id = :category_id'
    );

    $statement->bindValue(':category_id', $categoryId, PDO::PARAM_INT);
    $statement->execute();

    $existing = [];

    foreach ($statement->fetchAll() as $row) {
        foreach ($pairs as $pair) {
            /* Only the front side counts: the back side of another card is not
               the same question. */
            $value = trim((string) ($row[$pair[0]] ?? ''));

            if ($value !== '') {
                $existing[card_import_front_key($value)] = true;
            }
        }
    }

    return $existing;
}

/**
 * Writes the cards into one subcategory, all or nothing.
 *
 * Every card goes through create_card_translated(), the same function the card
 * dialog uses, so an imported card is stored exactly like a typed one - including
 * the German text in front/back for the columns that are NOT NULL. No
 * user_card_progress row is written: an imported card counts as new.
 *
 * @param list<array<string, mixed>> $cards
 * @return int How many cards were written.
 */
function card_import_insert(PDO $pdo, int $categoryId, array $cards): int
{
    $columns = card_columns($pdo);
    $pairs = card_language_columns($columns);
    $written = 0;

    $pdo->beginTransaction();

    try {
        foreach ($cards as $card) {
            /*
             * create_card_translated() writes by column name, so the names of the
             * file are translated into the names the table really has. On a table
             * from before the language columns that is the same German text under
             * the names front and back.
             */
            $texts = [];

            foreach (['de', 'en'] as $language) {
                if (!isset($pairs[$language])) {
                    continue;
                }

                $texts[$pairs[$language][0]] = (string) $card['front_' . $language];
                $texts[$pairs[$language][1]] = (string) $card['back_' . $language];
            }

            create_card_translated($pdo, $categoryId, $texts, $columns, $card['is_bidirectional'] === true);
            $written++;
        }

        $pdo->commit();
    } catch (Throwable $error) {
        /*
         * One row that cannot be stored means no row is stored: there is no such
         * thing as half an import.
         */
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $error;
    }

    return $written;
}
