<?php

declare(strict_types=1);

/**
 * Hängt ein zweispaltiges Wörterbuch an eine vorhandene Vokabel-Unterkategorie an.
 *
 * Das Format der Datei ist bewusst schlicht:
 *
 *     Englisch;Deutsch
 *     bag;Tasche f; Tüte f; Sack m
 *     to bake;backen; (Ziegel) brennen
 *
 * Anders als die beiden großen Importeure legt dieses Werkzeug KEINE Kategorie an und
 * leert auch keine. Es liest die vorhandenen Karten der genannten Unterkategorie und
 * schreibt nur die Wörter, die dort noch nicht stehen. Damit bleiben die Karten-Ids
 * und mit ihnen der Lernfortschritt unangetastet.
 *
 * Doppelte Einträge entstehen auf zwei Wegen, und beide werden hier abgefangen:
 *
 *   - derselbe englische Ausdruck steht zweimal in der Datei. Die deutschen
 *     Bedeutungen werden dann zu EINER Karte zusammengelegt.
 *   - der Ausdruck steht schon in der Datenbank. Dann wird die vorhandene Karte
 *     behalten; sie kann bereits Lernfortschritt tragen, und ein zweites Mal
 *     angelegt wäre sie nur eine Dublette.
 *
 * Verglichen wird die englische Vorderseite ohne Rücksicht auf Groß- und
 * Kleinschreibung sowie ohne doppelte Leerzeichen. Das ist derselbe Schlüssel wie in
 * import_progress_key() und reicht für Wörterbücher, die denselben Ausdruck mal mit
 * und mal ohne Satzzeichen am Rand führen.
 *
 * Aufruf (erst Trockenlauf, dann schreiben):
 *
 *     php bin/import_dictionary_cards.php --owner=6 --area=English \
 *         --category="Grund- und Aufbauwortschatz" \
 *         --file=woerterbuch_englisch_deutsch.csv --expect=3780 --dry-run
 *
 *     php bin/import_dictionary_cards.php --owner=6 --area=English \
 *         --category="Grund- und Aufbauwortschatz" \
 *         --file=woerterbuch_englisch_deutsch.csv --expect=3780 --execute
 *
 * Ohne --execute passiert nichts: der Lauf liest, zählt und zeigt, was er tun würde.
 * Geschrieben wird in EINER Transaktion. --expect prüft vorher die Zahl der neuen
 * Karten; passt sie nicht, bricht der Lauf ab, bevor die erste Zeile geschrieben ist.
 */

$projectRoot = dirname(__DIR__);

require_once $projectRoot . '/src/config/database.php';

/*
 * Nur ein Kommandozeilen-Werkzeug: keine URL, nicht im Web-Verzeichnis, keine
 * Zugangsdaten im Browser. Die Sperre verhindert, dass ein falsch eingerichteter
 * Server die Datei wie eine Seite ausführt.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    echo "Not found.\n";
    exit(1);
}

/** Mögliche Spaltennamen der englischen Seite, alle in Kleinschreibung. */
const DICTIONARY_ENGLISH_COLUMNS = ['englisch', 'english', 'front', 'word', 'vokabel'];

/** Mögliche Spaltennamen der deutschen Seite, alle in Kleinschreibung. */
const DICTIONARY_GERMAN_COLUMNS = ['deutsch', 'german', 'back', 'translation', 'übersetzung'];

/* -------------------------------------------------------------------------
   Argumente
   ------------------------------------------------------------------------- */

/**
 * Liest die Angaben der Kommandozeile.
 *
 * @param list<string> $argv
 * @return array{owner: int|null, area: string|null, category: string|null,
 *     file: string|null, expect: int|null, execute: bool}|null
 */
function dictionary_arguments(array $argv): ?array
{
    $options = [
        'owner' => null,
        'area' => null,
        'category' => null,
        'file' => null,
        'expect' => null,
        'execute' => false,
    ];

    foreach (array_slice($argv, 1) as $argument) {
        if ($argument === '--execute') {
            $options['execute'] = true;
            continue;
        }

        if ($argument === '--dry-run') {
            $options['execute'] = false;
            continue;
        }

        if (preg_match('/^--(owner|area|category|file|expect)=(.*)$/u', $argument, $match) === 1) {
            $value = $match[2];

            if ($value === '') {
                echo 'The value of --' . $match[1] . " must not be empty.\n";

                return null;
            }

            if ($match[1] === 'owner' || $match[1] === 'expect') {
                if (!ctype_digit($value)) {
                    echo 'The value of --' . $match[1] . " must be a whole number.\n";

                    return null;
                }

                $options[$match[1]] = (int) $value;
                continue;
            }

            $options[$match[1]] = $value;
            continue;
        }

        echo "Unknown argument: " . $argument . "\n";
        dictionary_print_usage();

        return null;
    }

    if ($options['owner'] === null || $options['area'] === null
        || $options['category'] === null || $options['file'] === null) {
        echo "--owner, --area, --category and --file are required.\n";
        dictionary_print_usage();

        return null;
    }

    return $options;
}

/** Zeigt die erlaubten Angaben. */
function dictionary_print_usage(): void
{
    echo "Usage:\n";
    echo "  php bin/import_dictionary_cards.php --owner=<id> --area=<name> \\\n";
    echo "      --category=<name> --file=<path> [--expect=<count>] [--dry-run|--execute]\n";
    echo "\n";
    echo "  --owner      id of the account that owns the category\n";
    echo "  --area       name of the learning area, e.g. English\n";
    echo "  --category   name of the existing subcategory to append to\n";
    echo "  --file       CSV with two columns (Englisch;Deutsch or English;Deutsch)\n";
    echo "  --expect     optional expected number of NEW cards\n";
    echo "  --dry-run    read and report only (default)\n";
    echo "  --execute    write the new cards in one transaction\n";
}

/* -------------------------------------------------------------------------
   Die Datei lesen
   ------------------------------------------------------------------------- */

/**
 * Liest einen CSV-Datensatz.
 *
 * fgetcsv() statt Aufteilen nach Zeilen: eine Zelle in Anführungszeichen darf ein
 * Semikolon enthalten (die deutschen Bedeutungen tun das dauernd) und sogar einen
 * Zeilenumbruch. Das Trennzeichen kommt von außen, weil die Dateien es verschieden
 * führen.
 *
 * @param resource $handle
 * @return list<string>|false
 */
function dictionary_read_record($handle, string $delimiter): array|false
{
    $cells = fgetcsv($handle, 0, $delimiter, '"', '');

    if ($cells === false) {
        return false;
    }

    return array_map(static fn ($cell): string => (string) $cell, $cells);
}

/**
 * Das Trennzeichen der Datei, gelesen aus ihrer ersten Zeile.
 *
 * Ein Semikolon in der Kopfzeile kann nur das Trennzeichen sein; die beiden
 * Spaltennamen tragen keines. Alles andere ist Komma.
 */
function dictionary_delimiter(string $firstLine): string
{
    return strpos($firstLine, ';') !== false ? ';' : ',';
}

/**
 * Räumt einen Zellwert auf, ohne seinen Inhalt zu verändern.
 *
 * Geschützte Leerzeichen kommen aus Tabellenkalkulationen und sehen im Browser wie
 * normale aus; mehrere Leerzeichen hintereinander entstehen beim Kopieren. Beides
 * wird vereinheitlicht, damit derselbe Ausdruck nicht zweimal verschieden aussieht.
 */
function dictionary_clean(string $value): string
{
    $value = str_replace("\u{00A0}", ' ', $value);
    $cleaned = preg_replace('/\s+/u', ' ', $value);

    return trim($cleaned ?? $value);
}

/**
 * Der Schlüssel, unter dem zwei Einträge als derselbe Ausdruck gelten.
 *
 * Groß- und Kleinschreibung, Leerzeichen am Rand und verschiedene Apostrophzeichen
 * sollen nicht zählen. Der Schlüssel wird nur zum Vergleichen benutzt; gespeichert
 * wird immer der Text aus der Datei.
 */
function dictionary_key(string $value): string
{
    $key = dictionary_clean($value);
    $key = str_replace(['’', '‘', '`'], "'", $key);

    return mb_strtolower($key, 'UTF-8');
}

/**
 * Legt zwei deutsche Bedeutungen zu einer zusammen.
 *
 * Die Wörterbücher trennen mehrere Bedeutungen mit einem Semikolon. Steht derselbe
 * englische Ausdruck zweimal in der Datei, bleiben so alle Bedeutungen erhalten,
 * ohne dass eine davon doppelt erscheint.
 */
function dictionary_merge_german(string $first, string $second): string
{
    $parts = [];
    $seen = [];

    foreach (preg_split('/\s*;\s*/u', $first . '; ' . $second) ?: [] as $part) {
        $part = trim($part);
        $key = mb_strtolower($part, 'UTF-8');

        if ($part === '' || isset($seen[$key])) {
            continue;
        }

        $seen[$key] = true;
        $parts[] = $part;
    }

    return implode('; ', $parts);
}

/**
 * Liest das Wörterbuch und führt doppelte englische Ausdrücke zusammen.
 *
 * @return array{rows: int, entries: list<array{english: string, german: string}>,
 *     duplicates: int, problems: list<string>, fatal: string|null}
 */
function dictionary_read_csv(string $path): array
{
    $result = ['rows' => 0, 'entries' => [], 'duplicates' => 0, 'problems' => [], 'fatal' => null];

    $handle = @fopen($path, 'rb');

    if ($handle === false) {
        $result['fatal'] = 'the file cannot be read';

        return $result;
    }

    $delimiter = dictionary_delimiter((string) fgets($handle));
    rewind($handle);

    $header = dictionary_read_record($handle, $delimiter);

    if ($header === false) {
        fclose($handle);
        $result['fatal'] = 'the file is empty';

        return $result;
    }

    $header[0] = strpos($header[0], "\xEF\xBB\xBF") === 0 ? substr($header[0], 3) : $header[0];

    $indexOf = [];

    foreach ($header as $index => $name) {
        $indexOf[mb_strtolower(trim($name), 'UTF-8')] = $index;
    }

    $englishIndex = null;
    $germanIndex = null;

    foreach (DICTIONARY_ENGLISH_COLUMNS as $column) {
        if (isset($indexOf[$column])) {
            $englishIndex = $indexOf[$column];
            break;
        }
    }

    foreach (DICTIONARY_GERMAN_COLUMNS as $column) {
        if (isset($indexOf[$column])) {
            $germanIndex = $indexOf[$column];
            break;
        }
    }

    if ($englishIndex === null || $germanIndex === null) {
        fclose($handle);
        $result['fatal'] = 'the file needs an English and a German column (found: '
            . implode(', ', array_map('trim', $header)) . ')';

        return $result;
    }

    $entries = [];
    $line = 1;

    while (($row = dictionary_read_record($handle, $delimiter)) !== false) {
        $line++;

        /* Eine leere Zeile am Dateiende ist keine Vokabel. fgetcsv() liefert dafür
           je nach Fassung [] oder [null]; nach dem Umwandeln in Strings bleibt
           eine leere Zelle übrig. */
        if ($row === [] || implode('', $row) === '') {
            continue;
        }

        $result['rows']++;

        $english = dictionary_clean($row[$englishIndex] ?? '');
        $german = dictionary_clean($row[$germanIndex] ?? '');

        if ($english === '' || $german === '') {
            $result['problems'][] = 'line ' . $line . ': one of the two sides is empty';

            continue;
        }

        $key = dictionary_key($english);

        if (isset($entries[$key])) {
            $entries[$key]['german'] = dictionary_merge_german($entries[$key]['german'], $german);
            $result['duplicates']++;
            continue;
        }

        $entries[$key] = ['english' => $english, 'german' => $german];
    }

    fclose($handle);

    $result['entries'] = array_values($entries);

    return $result;
}

/* -------------------------------------------------------------------------
   Kategorie und vorhandene Karten
   ------------------------------------------------------------------------- */

/**
 * Sucht den Lernbereich über einen der drei Namen.
 *
 * @return array{id: int, name: string}|null
 */
function dictionary_find_area(PDO $pdo, int $ownerId, string $area): ?array
{
    /*
     * Jeder Platzhalter bekommt einen eigenen Namen: mit emulierten Prepared
     * Statements wäre ein dreimal benanntes :area erlaubt, ohne sie nicht.
     */
    $statement = $pdo->prepare(
        'SELECT id, name
           FROM categories
          WHERE owner_user_id = :owner_user_id
            AND parent_id IS NULL
            AND (name = :area_name OR name_de = :area_de OR name_en = :area_en)'
    );
    $statement->bindValue(':owner_user_id', $ownerId, PDO::PARAM_INT);
    $statement->bindValue(':area_name', $area);
    $statement->bindValue(':area_de', $area);
    $statement->bindValue(':area_en', $area);
    $statement->execute();

    $rows = $statement->fetchAll();

    if (count($rows) !== 1) {
        return null;
    }

    return ['id' => (int) $rows[0]['id'], 'name' => (string) $rows[0]['name']];
}

/**
 * Sucht die vorhandene Unterkategorie unterhalb des Lernbereichs.
 *
 * @return array{id: int, name: string}|null
 */
function dictionary_find_category(PDO $pdo, int $ownerId, int $areaId, string $category): ?array
{
    $statement = $pdo->prepare(
        'SELECT id, name
           FROM categories
          WHERE owner_user_id = :owner_user_id
            AND parent_id = :area_id
            AND (name = :category_name OR name_de = :category_de OR name_en = :category_en)'
    );
    $statement->bindValue(':owner_user_id', $ownerId, PDO::PARAM_INT);
    $statement->bindValue(':area_id', $areaId, PDO::PARAM_INT);
    $statement->bindValue(':category_name', $category);
    $statement->bindValue(':category_de', $category);
    $statement->bindValue(':category_en', $category);
    $statement->execute();

    $rows = $statement->fetchAll();

    if (count($rows) !== 1) {
        return null;
    }

    return ['id' => (int) $rows[0]['id'], 'name' => (string) $rows[0]['name']];
}

/**
 * Die englischen Vorderseiten, die in der Unterkategorie schon stehen.
 *
 * @return array<string, string> Schlüssel => vorhandener Text
 */
function dictionary_existing_keys(PDO $pdo, int $categoryId): array
{
    $statement = $pdo->prepare('SELECT front FROM cards WHERE category_id = :category_id');
    $statement->bindValue(':category_id', $categoryId, PDO::PARAM_INT);
    $statement->execute();

    $keys = [];

    foreach ($statement->fetchAll() as $row) {
        $front = (string) $row['front'];
        $keys[dictionary_key($front)] = $front;
    }

    return $keys;
}

/**
 * Schreibt die fehlenden Karten in einer Transaktion.
 *
 * front und back bleiben die alten Spalten; die vier Sprachspalten spiegeln dasselbe
 * Paar, weil eine Vokabelkarte in beiden Sprachen dieselbe Karte mit getauschten
 * Rollen ist. is_bidirectional = 1 heißt: sie wird auch andersherum gefragt.
 *
 * @param list<array{english: string, german: string}> $cards
 */
function dictionary_write(PDO $pdo, int $categoryId, array $cards): int
{
    $insert = $pdo->prepare(
        'INSERT INTO cards
             (category_id, front, back, front_de, back_de, front_en, back_en, is_bidirectional)
         VALUES
             (:category_id, :front, :back, :front_de, :back_de, :front_en, :back_en, 1)'
    );

    $pdo->beginTransaction();

    try {
        foreach ($cards as $card) {
            $insert->execute([
                ':category_id' => $categoryId,
                ':front' => $card['english'],
                ':back' => $card['german'],
                ':front_de' => $card['english'],
                ':back_de' => $card['german'],
                ':front_en' => $card['english'],
                ':back_en' => $card['german'],
            ]);
        }

        $pdo->commit();
    } catch (Throwable $error) {
        $pdo->rollBack();
        throw $error;
    }

    return count($cards);
}

/* -------------------------------------------------------------------------
   Ablauf
   ------------------------------------------------------------------------- */

/**
 * Führt den Lauf aus.
 *
 * @param list<string> $argv
 */
function dictionary_main(array $argv, string $projectRoot): int
{
    $options = dictionary_arguments($argv);

    if ($options === null) {
        return 2;
    }

    $path = preg_match('/^([a-zA-Z]:|[\/\\\\])/', $options['file']) === 1
        ? $options['file']
        : $projectRoot . '/' . $options['file'];

    $csv = dictionary_read_csv($path);

    if ($csv['fatal'] !== null) {
        echo 'File: ' . $path . "\n";
        echo 'Error: ' . $csv['fatal'] . "\n";

        return 1;
    }

    $pdo = create_database_connection();
    $area = dictionary_find_area($pdo, $options['owner'], $options['area']);

    if ($area === null) {
        echo 'The area "' . $options['area'] . '" was not found exactly once for owner '
            . $options['owner'] . ".\n";

        return 1;
    }

    $category = dictionary_find_category($pdo, $options['owner'], $area['id'], $options['category']);

    if ($category === null) {
        echo 'The subcategory "' . $options['category'] . '" was not found exactly once under "'
            . $area['name'] . "\".\n";

        return 1;
    }

    $existing = dictionary_existing_keys($pdo, $category['id']);
    $newCards = [];
    $alreadyPresent = [];

    foreach ($csv['entries'] as $entry) {
        $key = dictionary_key($entry['english']);

        if (isset($existing[$key])) {
            $alreadyPresent[] = $entry;
            continue;
        }

        $newCards[] = $entry;
    }

    echo 'File          : ' . basename($path) . "\n";
    echo 'Area          : ' . $area['name'] . ' (id ' . $area['id'] . ")\n";
    echo 'Subcategory   : ' . $category['name'] . ' (id ' . $category['id'] . ")\n";
    echo 'Rows read     : ' . $csv['rows'] . "\n";
    echo 'Unique English: ' . count($csv['entries']) . "\n";
    echo 'Merged dupes  : ' . $csv['duplicates'] . "\n";
    echo 'Already there : ' . count($alreadyPresent) . "\n";
    echo 'New cards     : ' . count($newCards) . "\n";

    if ($csv['problems'] !== []) {
        echo 'Skipped rows  : ' . count($csv['problems']) . "\n";

        foreach (array_slice($csv['problems'], 0, 10) as $problem) {
            echo '  - ' . $problem . "\n";
        }
    }

    if ($newCards !== []) {
        echo "Examples of new cards:\n";

        foreach (array_slice($newCards, 0, 5) as $card) {
            echo '  ' . $card['english'] . ' => ' . $card['german'] . "\n";
        }
    }

    if ($options['expect'] !== null && count($newCards) !== $options['expect']) {
        echo 'Expected ' . $options['expect'] . ' new cards, found ' . count($newCards)
            . ". Nothing was written.\n";

        return 1;
    }

    if (!$options['execute']) {
        echo "Dry run: nothing written. Add --execute to write these cards.\n";

        return 0;
    }

    try {
        $written = dictionary_write($pdo, $category['id'], $newCards);
    } catch (Throwable $error) {
        echo 'Writing failed, transaction rolled back: ' . $error->getMessage() . "\n";

        return 1;
    }

    echo 'Written       : ' . $written . " cards\n";

    return 0;
}

exit(dictionary_main($argv, $projectRoot));
