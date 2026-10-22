<?php

declare(strict_types=1);

/**
 * Hängt Lernkarten aus einer CSV-Datei an eine BESTEHENDE Unterkategorie an.
 *
 *   php bin/import_cards_into_category.php --owner=6 --category-id=228 \
 *       --file=lernkarten_co2_energietraeger_de_en.csv --expect=3 --dry-run
 *
 *   php bin/import_cards_into_category.php --owner=6 --category-id=228 \
 *       --file=lernkarten_co2_energietraeger_de_en.csv --expect=3 --execute
 *
 * Das Format ist dasselbe wie bei bin/import_energy_cards.php:
 *
 *   subcategory;front_de;back_de;front_en;back_en;is_bidirectional
 *
 * Die Spalte `subcategory` wird nur gelesen, um sicherzugehen, dass die Datei wirklich zu
 * dieser Kategorie gehört: sie muss deren Namen (oder den englischen Namen) tragen. Sonst
 * bricht der Lauf ab, bevor etwas geschrieben wird - so kann eine Datei nicht in der
 * falschen Kategorie landen.
 *
 * Was dieses Werkzeug NICHT tut:
 *
 *   * Es legt keine Kategorie an, ändert keine und löscht keine.
 *   * Es ändert keine bestehende Karte und löscht keine.
 *   * Es schreibt keine Zeile in user_card_progress: neue Karten sind für jedes Konto neu.
 *   * Es rührt die Tabellenstruktur nicht an.
 *
 * Gegen Doppel: eine Zeile, deren deutsche Vorderseite (oder, wenn es keine gibt, deren
 * englische Vorderseite) in dieser Kategorie schon steht, wird übersprungen - egal ob sie
 * aus einer früheren Version derselben Datei stammt oder in der Anwendung von Hand
 * angelegt wurde. Dieselbe Datei zweimal zu importieren ist damit folgenlos.
 *
 * Geschrieben wird in EINER Transaktion: entweder sind alle neuen Karten da oder keine.
 * Ohne --execute wird nur gelesen und berichtet.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    echo "Not found.\n";
    exit(1);
}

$projectRoot = dirname(__DIR__);

require_once $projectRoot . '/src/config/database.php';

/** Die Kopfzeile, in genau dieser Reihenfolge. Weitere Spalten sind erlaubt. */
const CARD_IMPORT_HEADER = ['subcategory', 'front_de', 'back_de', 'front_en', 'back_en', 'is_bidirectional'];

/* -------------------------------------------------------------------------
   Kommandozeile
   ------------------------------------------------------------------------- */

/**
 * Liest die Optionen. null heißt: es fehlt etwas, der Aufrufer gibt die Hilfe aus.
 *
 * @param list<string> $argv
 * @return array{owner: int|null, category_id: int|null, file: string|null, expect: int|null, execute: bool}|null
 */
function card_import_arguments(array $argv): ?array
{
    $options = [
        'owner' => null,
        'category_id' => null,
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

        if (preg_match('/^--(owner|category-id|file|expect)=(.*)$/u', $argument, $match) === 1) {
            $value = trim($match[2]);

            if ($value === '') {
                echo 'The value of --' . $match[1] . " must not be empty.\n";

                return null;
            }

            if ($match[1] === 'owner' || $match[1] === 'category-id' || $match[1] === 'expect') {
                if (preg_match('/^\d+$/', $value) !== 1) {
                    echo 'The value of --' . $match[1] . " must be a whole number.\n";

                    return null;
                }

                $options[str_replace('-', '_', $match[1])] = (int) $value;
                continue;
            }

            $options['file'] = $value;
            continue;
        }

        echo 'Unknown option: ' . $argument . "\n";

        return null;
    }

    if ($options['owner'] === null || $options['category_id'] === null || $options['file'] === null) {
        echo "--owner, --category-id and --file are required.\n";

        return null;
    }

    return $options;
}

function card_import_print_usage(): void
{
    echo "Usage:\n";
    echo "  php bin/import_cards_into_category.php --owner=<id> --category-id=<id> \\\n";
    echo "      --file=<path> [--expect=<count>] [--dry-run|--execute]\n";
    echo "\n";
    echo "  --owner        id of the account that owns the category\n";
    echo "  --category-id  id of the EXISTING subcategory the cards belong to\n";
    echo "  --file         CSV: subcategory;front_de;back_de;front_en;back_en;is_bidirectional\n";
    echo "  --expect       optional expected number of NEW cards\n";
    echo "  --dry-run      read and report only (default)\n";
    echo "  --execute      write the new cards in one transaction\n";
}

/* -------------------------------------------------------------------------
   Datei lesen
   ------------------------------------------------------------------------- */

/**
 * Eine Textzelle ohne Aussenluft.
 */
function card_import_clean(string $value): string
{
    return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
}

/**
 * Der Schlüssel, mit dem eine Karte als "schon da" gilt.
 *
 * Kleinschreibung und zusammengefasste Leerzeichen: dieselbe Frage mit zwei Leerzeichen
 * ist dieselbe Frage. Umlaute und Akzente bleiben, wie sie sind - dafür müsste man
 * Sprachen kennen, und das tut dieses Werkzeug nicht.
 */
function card_import_key(string $value): string
{
    return mb_strtolower(card_import_clean($value), 'UTF-8');
}

/**
 * Liest die Datei und prüft jede Zeile, BEVOR die Datenbank angefasst wird.
 *
 * @return array{rows: list<array{front_de: string, back_de: string, front_en: string, back_en: string, bidirectional: bool}>, errors: list<string>, subcategories: list<string>}|null
 */
function card_import_read_csv(string $path): ?array
{
    if (!is_file($path)) {
        echo 'File not found: ' . $path . "\n";

        return null;
    }

    $handle = fopen($path, 'rb');

    if ($handle === false) {
        echo 'The file could not be opened: ' . $path . "\n";

        return null;
    }

    $header = fgetcsv($handle, 0, ';', '"', '');

    if ($header === false) {
        fclose($handle);
        echo "The file is empty.\n";

        return null;
    }

    $header = array_map(static fn ($cell): string => trim((string) $cell), $header);
    $expected = CARD_IMPORT_HEADER;

    if (array_slice($header, 0, count($expected)) !== $expected) {
        fclose($handle);
        echo "Unexpected header.\n";
        echo "  expected: " . implode(';', $expected) . "\n";
        echo "  found:    " . implode(';', $header) . "\n";

        return null;
    }

    $index = array_flip($header);
    $rows = [];
    $errors = [];
    $subcategories = [];
    $line = 1;

    while (($cells = fgetcsv($handle, 0, ';', '"', '')) !== false) {
        $line++;

        /* Eine leere Zeile am Ende ist keine Karte. */
        if ($cells === [null] || (count($cells) === 1 && trim((string) $cells[0]) === '')) {
            continue;
        }

        $cell = static function (string $name) use ($cells, $index): string {
            $position = $index[$name] ?? null;

            return $position === null ? '' : (string) ($cells[$position] ?? '');
        };

        $subcategory = card_import_clean($cell('subcategory'));
        $frontDe = trim($cell('front_de'));
        $backDe = trim($cell('back_de'));
        $frontEn = trim($cell('front_en'));
        $backEn = trim($cell('back_en'));
        $bidirectional = card_import_clean($cell('is_bidirectional'));

        if ($subcategory === '') {
            $errors[] = 'line ' . $line . ': the subcategory is empty';
        } else {
            $subcategories[$subcategory] = true;
        }

        /* Eine halbe Sprache ist ein Fehler, keine Lücke: sie würde als Karte ohne
           Rückseite in der Anwendung stehen. */
        if (($frontDe === '') !== ($backDe === '')) {
            $errors[] = 'line ' . $line . ': German is only half filled in';
        }

        if (($frontEn === '') !== ($backEn === '')) {
            $errors[] = 'line ' . $line . ': English is only half filled in';
        }

        if ($frontDe === '' && $frontEn === '') {
            $errors[] = 'line ' . $line . ': no complete language';
        }

        if (!in_array($bidirectional, ['', '0', '1'], true)) {
            $errors[] = 'line ' . $line . ': is_bidirectional must be 0 or 1';
        }

        $rows[] = [
            'front_de' => $frontDe,
            'back_de' => $backDe,
            'front_en' => $frontEn,
            'back_en' => $backEn,
            'bidirectional' => $bidirectional === '1',
        ];
    }

    fclose($handle);

    return [
        'rows' => $rows,
        'errors' => $errors,
        'subcategories' => array_keys($subcategories),
    ];
}

/* -------------------------------------------------------------------------
   Datenbank
   ------------------------------------------------------------------------- */

/**
 * Die Kategorie dieses Kontos, oder null.
 *
 * @return array{id: int, name: string, name_en: string|null}|null
 */
function card_import_find_category(PDO $pdo, int $categoryId, int $ownerId): ?array
{
    $statement = $pdo->prepare(
        'SELECT id, name, name_en
           FROM categories
          WHERE id = :id AND owner_user_id = :owner_user_id'
    );
    $statement->bindValue(':id', $categoryId, PDO::PARAM_INT);
    $statement->bindValue(':owner_user_id', $ownerId, PDO::PARAM_INT);
    $statement->execute();
    $row = $statement->fetch();

    if ($row === false) {
        return null;
    }

    return [
        'id' => (int) $row['id'],
        'name' => (string) $row['name'],
        'name_en' => $row['name_en'] === null ? null : (string) $row['name_en'],
    ];
}

/**
 * Die Vorderseiten, die in dieser Kategorie schon stehen - als Schlüssel.
 *
 * Gelesen werden alle drei möglichen Vorderseiten: eine von Hand angelegte Karte kann
 * nur die englische tragen, und die soll genauso als Doppel erkannt werden.
 *
 * @return array<string, true>
 */
function card_import_existing_keys(PDO $pdo, int $categoryId): array
{
    $statement = $pdo->prepare(
        'SELECT front, front_de, front_en FROM cards WHERE category_id = :category_id'
    );
    $statement->bindValue(':category_id', $categoryId, PDO::PARAM_INT);
    $statement->execute();

    $keys = [];

    foreach ($statement->fetchAll() as $row) {
        foreach (['front', 'front_de', 'front_en'] as $column) {
            $value = trim((string) ($row[$column] ?? ''));

            if ($value !== '') {
                $keys[card_import_key($value)] = true;
            }
        }
    }

    return $keys;
}

/**
 * Schreibt die neuen Karten in einer Transaktion.
 *
 * front und back bleiben die alten Spalten und tragen die deutsche Seite - genau so, wie
 * die Karten dieser Kategorien schon gespeichert sind. Gibt es keine deutsche Seite,
 * tragen sie die englische: eine Karte braucht eine Vorderseite.
 *
 * @param list<array{front_de: string, back_de: string, front_en: string, back_en: string, bidirectional: bool}> $cards
 * @return int die Zahl der geschriebenen Zeilen
 */
function card_import_write(PDO $pdo, int $categoryId, array $cards): int
{
    $insert = $pdo->prepare(
        'INSERT INTO cards
             (category_id, front, back, front_de, back_de, front_en, back_en, is_bidirectional)
         VALUES
             (:category_id, :front, :back, :front_de, :back_de, :front_en, :back_en, :is_bidirectional)'
    );

    $written = 0;

    $pdo->beginTransaction();

    try {
        foreach ($cards as $card) {
            $front = $card['front_de'] !== '' ? $card['front_de'] : $card['front_en'];
            $back = $card['back_de'] !== '' ? $card['back_de'] : $card['back_en'];

            $insert->execute([
                ':category_id' => $categoryId,
                ':front' => $front,
                ':back' => $back,
                ':front_de' => $card['front_de'] === '' ? null : $card['front_de'],
                ':back_de' => $card['back_de'] === '' ? null : $card['back_de'],
                ':front_en' => $card['front_en'] === '' ? null : $card['front_en'],
                ':back_en' => $card['back_en'] === '' ? null : $card['back_en'],
                ':is_bidirectional' => $card['bidirectional'] ? 1 : 0,
            ]);

            $written++;
        }

        $pdo->commit();
    } catch (Throwable $error) {
        $pdo->rollBack();

        throw $error;
    }

    return $written;
}

/**
 * Wie viele Karten diese Kategorie gerade enthält.
 */
function card_import_count(PDO $pdo, int $categoryId): int
{
    $statement = $pdo->prepare('SELECT COUNT(*) FROM cards WHERE category_id = :category_id');
    $statement->bindValue(':category_id', $categoryId, PDO::PARAM_INT);
    $statement->execute();

    return (int) $statement->fetchColumn();
}

/* -------------------------------------------------------------------------
   Ablauf
   ------------------------------------------------------------------------- */

/**
 * @param list<string> $argv
 */
function card_import_main(array $argv): int
{
    $options = card_import_arguments($argv);

    if ($options === null) {
        card_import_print_usage();

        return 1;
    }

    $csv = card_import_read_csv((string) $options['file']);

    if ($csv === null) {
        return 1;
    }

    if ($csv['errors'] !== []) {
        echo "The file has errors, nothing was written:\n";

        foreach (array_slice($csv['errors'], 0, 20) as $error) {
            echo '  ' . $error . "\n";
        }

        return 1;
    }

    echo 'File:      ' . $options['file'] . "\n";
    echo 'Rows:      ' . count($csv['rows']) . "\n";

    try {
        $pdo = create_database_connection();
        $category = card_import_find_category($pdo, (int) $options['category_id'], (int) $options['owner']);

        if ($category === null) {
            echo 'No category ' . $options['category_id'] . " for this account.\n";

            return 1;
        }

        echo 'Category:  ' . $category['id'] . ' "' . $category['name'] . "\"\n";

        /*
         * Die Datei nennt ihre Unterkategorie. Passt sie nicht zu dieser Kategorie, ist
         * das kein Grund zum Raten - dann gehört die Datei woandershin.
         */
        $names = array_filter([card_import_key($category['name']), $category['name_en'] === null ? '' : card_import_key($category['name_en'])]);

        foreach ($csv['subcategories'] as $subcategory) {
            if (!in_array(card_import_key($subcategory), $names, true)) {
                echo 'The file names the subcategory "' . $subcategory . '" but category '
                    . $category['id'] . ' is called "' . $category['name'] . "\".\n";
                echo "Nothing was written.\n";

                return 1;
            }
        }

        $existing = card_import_existing_keys($pdo, $category['id']);
        $new = [];
        $skipped = [];

        foreach ($csv['rows'] as $row) {
            $frontDe = $row['front_de'] !== '' ? $row['front_de'] : $row['front_en'];
            $key = card_import_key($frontDe);

            if (isset($existing[$key])) {
                $skipped[] = $frontDe;
                continue;
            }

            /* Auch innerhalb der Datei kann dieselbe Frage zweimal stehen. */
            $existing[$key] = true;
            $new[] = $row;
        }

        echo 'New cards: ' . count($new) . ' (already there: ' . count($skipped) . ")\n";

        if ($skipped !== []) {
            foreach (array_slice($skipped, 0, 5) as $front) {
                echo '  skipped: ' . mb_substr($front, 0, 70, 'UTF-8') . "\n";
            }
        }

        if ($options['expect'] !== null && $options['expect'] !== count($new)) {
            echo 'Expected ' . $options['expect'] . ' new cards, found ' . count($new) . ".\n";
            echo "Nothing was written.\n";

            return 1;
        }

        if (!$options['execute']) {
            echo "Dry run: nothing was written. Add --execute to write these cards.\n";

            return 0;
        }

        $before = card_import_count($pdo, $category['id']);
        $written = card_import_write($pdo, $category['id'], $new);
        $after = card_import_count($pdo, $category['id']);

        echo 'Written:   ' . $written . "\n";
        echo 'In category: ' . $before . ' -> ' . $after . " cards\n";

        return 0;
    } catch (Throwable $error) {
        /* Auf der Konsole darf der Grund stehen, aber ohne Zugangsdaten und ohne SQL. */
        echo 'Import failed: ' . $error->getMessage() . "\n";
        echo "Nothing was written.\n";

        return 1;
    }
}

exit(card_import_main($argv));
