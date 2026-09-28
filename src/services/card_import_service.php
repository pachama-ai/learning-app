<?php

declare(strict_types=1);

/**
 * Lernkarten aus einer CSV-Datei in EINE Unterkategorie importieren.
 *
 * Die Datei ist eine mit Semikolon getrennte CSV in UTF-8. Die erste Zeile ist die
 * Kopfzeile und benennt die Spalten; die Reihenfolge ist egal und die Namen achten nicht
 * auf Groß- und Kleinschreibung. Die vorgesehene Kopfzeile - die, die der Dialog zeigt
 * und die Beispieldatei benutzt - ist
 *
 *     front_de;back_de;front_en;back_en;is_bidirectional
 *
 * Deutsch und Englisch sind die beiden Sprachen, die eine Karte tragen kann (siehe
 * card_language_columns() in card_service.php). Eine Zeile muss mindestens EINE
 * vollständige Sprache haben: eine Vorderseite UND eine Rückseite. Die zweite Sprache
 * darf ganz fehlen, aber nicht halb gefüllt sein.
 *
 * Was diese Datei NICHT tut
 *
 *   * sie ändert nie die Tabellenstruktur,
 *   * sie schreibt nie eine Zeile in user_card_progress (importierte Karten sind neue
 *     Karten),
 *   * sie löscht nie etwas,
 *   * sie schreibt nie in eine andere Kategorie als die übergebene.
 *
 * Warum die Zeilen hier geprüft werden und nicht mit clean_input_text()
 *
 * Die Helfer in request_input.php melden das ERSTE Problem, indem sie die Anfrage
 * beenden, und das ist für ein Formular mit einer Karte richtig. Eine Datei hat viele
 * Zeilen, und wer sie hochlädt, möchte alle Probleme auf einmal sehen - deshalb gelten
 * dieselben Regeln (gültiges UTF-8, höchstens 2000 Zeichen, keine Steuerzeichen) hier
 * Zeile für Zeile, und die Probleme werden gesammelt statt geworfen.
 */

require_once __DIR__ . '/card_service.php';
require_once __DIR__ . '/exercise_service.php';
require_once __DIR__ . '/../helpers/request_input.php';

/**
 * Die Kopfzeile, wie dieser Import sie dokumentiert und wie die Beispieldatei sie benutzt.
 *
 * @var list<string>
 */
const CARD_IMPORT_HEADER = ['front_de', 'back_de', 'front_en', 'back_en', 'is_bidirectional'];

/**
 * Spalten, die in der Kopfzeile stehen dürfen, ohne nötig zu sein.
 *
 * "exercise" benennt eine erzeugte Aufgabe statt einer festen Karte: die Aufgabenart
 * und, nach einem Doppelpunkt, die Zahlen, die sie benutzen darf. Die Schreibweise liest
 * exercise_parse_cell() in src/services/exercise_service.php, dieselbe Funktion, die auch
 * der Import auf der Kommandozeile benutzt. Eine Datei ohne diese Spalte arbeitet
 * unverändert weiter.
 *
 * @var list<string>
 */
const CARD_IMPORT_OPTIONAL = ['exercise'];

/** Die Datei darf nicht größer sein als das. Muss zum Wert in index.php passen. */
const CARD_IMPORT_MAX_BYTES = 1048576;

/** Die Datei darf nicht mehr Datenzeilen haben als das. Muss zu index.php passen. */
const CARD_IMPORT_MAX_ROWS = 1000;

/** Das Trennzeichen der Datei. Eine CSV, die es benutzt, wird von fgetcsv() gelesen. */
const CARD_IMPORT_SEPARATOR = ';';

/**
 * Jeder Name, den eine Spalte der Datei haben darf, klein geschrieben.
 *
 * Die vorgesehenen Namen sind die, die die Beispieldatei benutzt; die anderen werden
 * angenommen, damit eine von Hand geschriebene - oder aus einem anderen Werkzeug
 * ausgeführte - Datei nicht erst umbenannt werden muss.
 *
 * @var array<string, list<string>>
 */
const CARD_IMPORT_ALIASES = [
    'front_de' => ['front_de', 'front', 'vorderseite_de', 'vorderseite'],
    'back_de' => ['back_de', 'back', 'rueckseite_de', 'rückseite_de', 'rueckseite', 'rückseite'],
    'front_en' => ['front_en', 'vorderseite_en'],
    'back_en' => ['back_en', 'rueckseite_en', 'rückseite_en'],
    'is_bidirectional' => ['is_bidirectional', 'auch_umgekehrt', 'umgekehrt', 'bidirectional'],
    'exercise' => ['exercise', 'aufgabe', 'uebung', 'übung'],
];

/** Werte, die in der freiwilligen 0/1-Spalte "ja" und "nein" heißen. */
const CARD_IMPORT_TRUE_VALUES = ['1', 'true', 'yes', 'ja', 'j', 'x', 'wahr'];
const CARD_IMPORT_FALSE_VALUES = ['0', 'false', 'no', 'nein', 'n', ''];

/**
 * Liest die Datei und liefert die Kopfzeile und die rohen Zeilen.
 *
 * Hier wird nichts gegen die Datenbank geprüft - diese Funktion macht nur aus den Bytes
 * Zeilen, Vorschau und Import sehen also genau dieselben Daten an.
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
     * Eine Bytereihenfolge-Markierung vor dem ersten Spaltennamen würde den Namen
     * unkenntlich machen, die drei Bytes werden also gelesen und verworfen, bevor
     * fgetcsv() sie zu sehen bekommt - derselbe Kniff, den der Import auf der
     * Kommandozeile benutzt.
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

    /* Die Kopfzeile wird getrimmt; der Text einer Karte nie. */
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
            /* Dieselbe Spalte zweimal: die erste gewinnt, die Datei wird abgelehnt. */
            $unknown[] = $label;

            continue;
        }

        $map[$column] = $index;
        $columns[] = $column;
    }

    if ($unknown !== []) {
        fclose($handle);

        /* Als Liste von Namen behalten, damit die Meldung nennen kann, was unbekannt war. */
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
            /* Eine leere Zeile trägt nichts, sie ist also keine Zeile. */
            continue;
        }

        if (count($rows) >= CARD_IMPORT_MAX_ROWS) {
            fclose($handle);

            $empty['fatal'] = ['code' => 'too_many_rows', 'params' => ['rows' => CARD_IMPORT_MAX_ROWS]];

            return $empty;
        }

        $values = [];

        /* Zuerst die nötigen Spalten, dann die freiwilligen: eine Datei ohne die
           Aufgaben-Spalte füllt diesen Eintrag einfach mit leerem Text. */
        foreach (array_merge(CARD_IMPORT_HEADER, CARD_IMPORT_OPTIONAL) as $column) {
            $index = $map[$column] ?? null;
            /* Ein abschließender Wagenrücklauf einer CRLF-Datei gehört zum
               Zeilenende und nicht zum Text. */
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
 * Die vorgesehene Spalte, die ein Kopfzeilenname meint, oder null, wenn nichts passt.
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
 * Welche der vier nötigen Textspalten fehlen.
 *
 * Eine Datei darf eine ganze Sprache weglassen, aber nicht von jeder Sprache die Hälfte:
 * mindestens ein Paar (Vorder- und Rückseite derselben Sprache) muss es als Spalten
 * geben.
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

    /* Nichts Brauchbares gefunden: die vier Spalten nennen, die dieser Import braucht. */
    return ['front_de', 'back_de', 'front_en', 'back_en'];
}

/**
 * Wahr, wenn jede Zelle der Zeile leer ist.
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
 * Merkt eine Datei als unlesbar vor und behält die Antwort in derselben Form.
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
 * Prüft jede Zeile und baut die Karten, die importiert würden.
 *
 * @param array<string, mixed> $read Das Ergebnis von card_import_read_file().
 * @param list<string>         $existingFronts Die normalisierten Vorderseiten, die es in dieser Unterkategorie schon gibt.
 * @param list<string>         $tableColumns Die Spalten, die die Tabelle cards wirklich hat.
 * @return array{
 *     cards: list<array<string, mixed>>,
 *     errors: list<array{line: int, code: string, params: array<string, string|int>}>,
 *     preview: list<array<string, mixed>>,
 *     importable: int,
 *     duplicates: int,
 *     invalid: int
 * }
 */
function card_import_validate(
    array $read,
    array $existingFronts,
    array $tableColumns,
    bool $exerciseAvailable = true
): array
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
        $problem = card_import_row_problem($row, $read['columns'], $pairs, $exerciseAvailable);

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
function card_import_row_problem(array $row, array $columns, array $pairs, bool $exerciseAvailable = true): ?array
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

    /*
     * The optional exercise column. Its syntax is read by the very function the
     * command line importer and the card dialog use, so all three understand the
     * same thing - and a row with an unusable cell is refused here instead of
     * quietly becoming a fixed card.
     */
    $exerciseCell = trim((string) ($row['values']['exercise'] ?? ''));
    $parsedExercise = exercise_parse_cell($exerciseCell);

    if ($exerciseCell !== '' && !$exerciseAvailable) {
        return ['code' => 'exercise_unavailable', 'params' => []];
    }

    if ($parsedExercise['error'] !== null) {
        return [
            'code' => $parsedExercise['code'] === 'unknown_type'
                ? 'exercise_unknown_type'
                : 'exercise_invalid',
            'params' => ['value' => $exerciseCell]
        ];
    }

    /*
     * An exercise card carries a title instead of an answer: the answer is built
     * when the task is shown. One title in one language is enough - the same rule
     * the card dialog and the card endpoint follow.
     */
    if ($exerciseCell !== '') {
        $germanTitle = trim((string) $row['values']['front_de']);
        $englishTitle = trim((string) $row['values']['front_en']);

        if ($germanTitle === '' && $englishTitle === '') {
            return ['code' => 'exercise_no_title', 'params' => []];
        }

        return null;
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
        /* null means: a fixed card, exactly as before this column existed. */
        'exercise' => exercise_parse_cell(trim((string) ($values['exercise'] ?? '')))['exercise'],
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
        /* What stands in the exercise column, so the preview can show it. */
        'exercise' => trim((string) ($values['exercise'] ?? '')),
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
function card_import_existing_fronts(PDO $pdo, int $categoryId, int $ownerUserId): array
{
    $columns = card_columns($pdo);
    $pairs = card_language_columns($columns);
    $statement = $pdo->prepare(
        'SELECT front, back, front_de, back_de, front_en, back_en
           FROM cards
          WHERE category_id = :category_id
            AND category_id IN (SELECT id FROM categories WHERE owner_user_id = :owner_user_id)'
    );

    $statement->bindValue(':category_id', $categoryId, PDO::PARAM_INT);
    $statement->bindValue(':owner_user_id', $ownerUserId, PDO::PARAM_INT);
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
function card_import_insert(PDO $pdo, int $categoryId, array $cards, int $ownerUserId): int
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

            /*
             * The exercise travels the same way a card written in the dialog does:
             * one transaction, and the numbers in the same shape the dialog sends.
             */
            create_card_translated(
                $pdo,
                $categoryId,
                $texts,
                $columns,
                $card['is_bidirectional'] === true,
                $ownerUserId,
                null,
                $card['exercise'] ?? null
            );
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
