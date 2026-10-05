<?php

declare(strict_types=1);

/**
 * Importiert Lernkarten-CSV-Dateien in einen Lernbereich.
 *
 * Das Format ist fest und trägt alles, was die beiden Tabellen brauchen:
 *
 *     category,front,back,front_de,back_de,front_en,back_en,is_bidirectional
 *
 *   - `category` ist der Name der Unterkategorie, zu der eine Zeile gehört. Eine Datei
 *     darf mehrere davon enthalten, mehrere Dateien dürfen dieselbe füllen.
 *   - `front`/`back` sind das Paar, das die Karte zeigt. Die vier Sprachspalten tragen
 *     denselben Text je Sprache, und `front_en`/`back_en` sind das VERTAUSCHTE Paar -
 *     die Datei ist die Wahrheit, hier wird nichts gespiegelt und nichts geraten.
 *   - `is_bidirectional` ist 0 oder 1.
 *
 * In diesem Werkzeug steckt kein Wissen über einzelne Dateien: dasselbe Kommando liest
 * jede Datei dieses Formats, worum auch immer sie geht. In welchen Bereich die Karten
 * landen, steht auf der Kommandozeile (--area) und wird nicht aus dem Dateinamen
 * geraten.
 *
 * Geschrieben wird in EINER Transaktion: entweder ist jede Zeile jeder Datei in der
 * Datenbank oder keine. Ohne --execute liest und berichtet das Werkzeug nur, der Plan
 * lässt sich also gegenlesen, bevor etwas geschrieben wird.
 *
 * Aufruf:
 *
 *     php bin/import_cards_csv.php --owner=6 --area=English \
 *         --file=b1_vokabelliste.csv --file=b2_vokabelliste.csv --dry-run
 *
 *     php bin/import_cards_csv.php --owner=6 --area=English \
 *         --file=b2_vokabelliste_mit_beispielsaetzen.csv --expect=500 --replace --execute
 *
 * Ein Lernbereich, den es noch nicht gibt, wird mit --create-area angelegt; seine
 * Zeichnung kommt aus --icon=<Pfad zu einer svg>.
 *
 * Zwei Trennzeichen: die Kopfzeile einer Datei entscheidet, ob sie mit Semikolon oder mit
 * Komma getrennt ist. Die Dateien aus der Tabellenkalkulation kommen mit Semikolon, die
 * älteren mit Komma - umgeschrieben werden muss deshalb keine.
 *
 * Eine Unterkategorie, die es unter diesem Bereich schon gibt, ist ein Hindernis: sie
 * würde sonst ein zweites Mal angelegt, und dieselben Karten stünden doppelt da. Mit
 * --replace wird stattdessen ihre vorhandene Zeile geleert und neu gefüllt.
 *
 * Der Lernfortschritt geht dabei nicht verloren: er wird vor dem Löschen gelesen und auf
 * die neue Karte mit derselben deutschen Vorderseite wieder eingesetzt - dieselbe Karte,
 * neuer Wortlaut. Passt eine Vorderseite nicht eindeutig, wird sie nicht übertragen, und
 * der Lauf sagt am Ende, welche Zeile das betrifft.
 *
 * Mit --only-german werden die beiden englischen Spalten NICHT geschrieben: die Karten
 * sind dann reine deutsche Karten, und front_en/back_en bleiben NULL. Das ist für Dateien
 * gedacht, deren "englische" Spalten noch einmal den deutschen Text tragen - ohne diese
 * Option sähen solche Karten in der Oberfläche wie englische Karten aus, während sie
 * Deutsch zeigen, und das ist schlimmer als eine leere Spalte.
 *
 * EINE DATEI MIT VARIANTEN
 *
 * Trägt eine Datei die drei Spalten source_card_number, variant und variant_key
 * (CARD_CSV_VARIANT_COLUMNS), dann ist jede Zeile NICHT eine Karte, sondern eine
 * Satzvariant derselben Karte. Alle Zeilen mit derselben source_card_number werden zu
 * einer Karte zusammengefasst; die Varianten landen in `card_variants`, und der
 * Lernfortschritt hängt weiter an der Karte. Die Karte selbst trägt den Text ihrer ersten
 * Variante - sie ist damit auch dann eine gültige Karte, wenn die Varianten niemand liest.
 *
 * In einer solchen Datei entscheidet --category=<name> über die Unterkategorie. Die Spalte
 * category der Datei wird dort nicht gelesen: eine Datei darf mehrere Regelgruppen
 * enthalten, und die Karten sollen trotzdem zusammen in einer Kategorie liegen.
 */

$projectRoot = dirname(__DIR__);

require_once $projectRoot . '/src/config/database.php';

/*
 * Nur ein Kommandozeilen-Werkzeug: es hat keine Adresse, liegt nicht im Web-Verzeichnis
 * und gibt keine Zugangsdaten, kein SQL und keine Dateipfade aus. Die Sperre steht hier,
 * damit ein falsch eingerichteter Server es nicht wie eine Seite ausführen kann.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    echo "Not found.\n";
    exit(1);
}

/** Die acht Spalten, die dieses Format hat, in der Reihenfolge, in der der Plan sie ausgibt. */
const CARD_CSV_COLUMNS = ['category', 'front', 'back', 'front_de', 'back_de', 'front_en', 'back_en', 'is_bidirectional'];

/**
 * Die drei Spalten, die eine Datei mit Varianten zusätzlich trägt.
 *
 * Fehlen sie, ist jede Zeile eine eigene Karte wie bisher. Stehen sie da, gehören alle
 * Zeilen mit derselben source_card_number zu EINER Karte: sie sind ihre Varianten.
 */
const CARD_CSV_VARIANT_COLUMNS = ['source_card_number', 'variant', 'variant_key'];

/** Wie viele Beispielkarten der Plan je Unterkategorie zeigt. */
const CARD_CSV_SAMPLES = 2;

/* --------------------------------------------------------------------------
   Argumente
   -------------------------------------------------------------------------- */

/**
 * Liest --owner, --area, --category, --file, --create-area, --icon, --expect, --replace,
 * --dry-run und --execute.
 *
 * @return array{owner: int, area: string, category: string|null, files: list<string>,
 *     createArea: bool, icon: string|null, expect: int|null, execute: bool,
 *     onlyGerman: bool, replace: bool}|null
 */
function import_arguments(array $argv, string $projectRoot): ?array
{
    $owner = null;
    $area = null;
    $category = null;
    $files = [];
    $createArea = false;
    $icon = null;
    $expect = null;
    $execute = false;
    $onlyGerman = false;
    $replace = false;
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

        if (strpos($argument, '--category=') === 0) {
            $category = trim(substr($argument, 11));

            if ($category === '') {
                echo "The value of --category must be a name.\n";

                return null;
            }

            continue;
        }

        if (strpos($argument, '--file=') === 0) {
            /* Ein Pfad mit Laufwerksbuchstaben oder führendem Schrägstrich wird genommen,
               wie er ist; alles andere wird vom Projektordner aus gelesen. */
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

        if ($argument === '--only-german') {
            $onlyGerman = true;
            continue;
        }

        if ($argument === '--replace') {
            $replace = true;
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
        'category' => $category,
        'files' => $files,
        'createArea' => $createArea,
        'icon' => $icon,
        'expect' => $expect,
        'execute' => $execute,
        'onlyGerman' => $onlyGerman,
        'replace' => $replace,
    ];
}

/** Liest ein "--name=<positive ganze Zahl>"-Argument, wenn es gerade das vorliegende ist. */
function import_int_option(string $argument, string $prefix, ?int &$target): bool
{
    if (strpos($argument, $prefix) !== 0) {
        return false;
    }

    $value = substr($argument, strlen($prefix));

    if (!ctype_digit($value) || (int) $value < 1) {
        echo 'The value of ' . $prefix . ' must be a positive whole number.' . "\n";
        $target = null;

        /* Der Aufrufer hält bei einem null an, ein schlechter Wert wird also nicht
           stillschweigend übergangen. */
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
    echo "  php bin/import_cards_csv.php --owner=<id> --area=<name> --file=<path> --replace --execute\n";
    echo "  php bin/import_cards_csv.php --owner=<id> --area=<name> --category=<name> --file=<path> --dry-run\n";
    echo "\n";
    echo "  --owner=<id>       the user the new subcategories belong to, required\n";
    echo "  --area=<name>      the learning area the cards go into, required\n";
    echo "  --category=<name>  the one subcategory a file with variants goes into\n";
    echo "  --create-area      create that area when it does not exist yet\n";
    echo "  --icon=<path>      the drawing of a new area (svg file)\n";
    echo "  --file=<path>      one CSV file, may be given several times\n";
    echo "  --only-german      write only the German columns; front_en/back_en stay empty\n";
    echo "  --replace          clear a subcategory that already exists, then fill it again\n";
    echo "  --dry-run          read and report, write nothing (default)\n";
    echo "  --execute          really import, all of it or none of it\n";
    echo "\n";
    echo "The format of every file is: " . implode(',', CARD_CSV_COLUMNS) . "\n";
    echo "A file with variants carries " . implode(',', CARD_CSV_VARIANT_COLUMNS)
        . " as well; then --category=<name> says where its cards go.\n";
}

/* --------------------------------------------------------------------------
   Die Dateien lesen
   -------------------------------------------------------------------------- */

/** Nimmt eine Bytereihenfolge-Markierung von der ersten Zelle einer Kopfzeile ab. */
function import_strip_bom(string $value): string
{
    return strpos($value, "\xEF\xBB\xBF") === 0 ? substr($value, 3) : $value;
}

/**
 * Liest einen Datensatz einer CSV-Datei.
 *
 * Benutzt wird fgetcsv() und kein Aufteilen nach Zeilen: eine Zelle in Anführungszeichen
 * darf einen Zeilenumbruch enthalten, und zeitformen.csv tut das. Die Datei von Hand
 * aufzuteilen würde so eine Zeile in zwei reißen und die zweite Hälfte zu einer eigenen
 * Zeile machen - genau das ist beim ersten Lauf dieses Werkzeugs passiert.
 *
 * Das Trennzeichen kommt von außen, weil es je Datei verschieden ist.
 *
 * @param resource $handle
 */
function import_read_record($handle, string $delimiter): array|false
{
    $cells = fgetcsv($handle, 0, $delimiter, '"', '');

    return $cells === false ? false : array_map(static fn ($cell): string => (string) $cell, $cells);
}

/**
 * Das Trennzeichen einer Datei, gelesen aus ihrer ersten Zeile.
 *
 * Ein Semikolon in der Kopfzeile kann nur das Trennzeichen sein: die acht Spaltennamen
 * von CARD_CSV_COLUMNS tragen keines. Alles andere ist Komma, wie es die älteren Dateien
 * haben.
 */
function import_delimiter(string $firstLine): string
{
    return strpos($firstLine, ';') !== false ? ';' : ',';
}

/**
 * Der Schlüssel, über den eine Karte beim Ersetzen wiedererkannt wird.
 *
 * Es ist die deutsche Vorderseite: dieselbe Karte kann neu formuliert sein, das Wort, nach
 * dem gefragt wird, bleibt aber dasselbe. Gross- und Kleinschreibung und Leerzeichen am
 * Rand sollen dabei nicht zählen - ein neuer Satz am englischen Wort ändert die Vorderseite
 * nicht.
 */
function import_progress_key(string $front): string
{
    return mb_strtolower(trim($front));
}

/**
 * Ob die Tabelle card_variants schon da ist.
 *
 * Gefragt wird über SHOW TABLES und nicht über einen Versuch: ein Schreibversuch auf eine
 * fehlende Tabelle wäre ein Fehler mitten in der Transaktion, und der Plan soll ihn vorher
 * nennen können.
 */
function import_variant_table_exists(PDO $pdo): bool
{
    $statement = $pdo->query("SHOW TABLES LIKE 'card_variants'");

    return $statement !== false && $statement->fetchColumn() !== false;
}

/**
 * Liest eine Datei und prüft jede Zeile.
 *
 * variantMode sagt, ob jede Zeile eine eigene Karte ist oder ob die Zeilen mit derselben
 * source_card_number die Varianten einer Karte sind.
 *
 * @return array{name: string, rows: list<array<string, string>>, problems: list<string>,
 *     fatal: string|null, variantMode: bool}
 */
function import_read_csv(string $path): array
{
    $result = ['name' => basename($path), 'rows' => [], 'problems' => [], 'fatal' => null, 'variantMode' => false];

    $handle = @fopen($path, 'rb');

    if ($handle === false) {
        $result['fatal'] = 'the file cannot be read';

        return $result;
    }

    $delimiter = import_delimiter((string) fgets($handle));
    rewind($handle);

    $header = import_read_record($handle, $delimiter);

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

    /*
     * Trägt die Datei die drei Spalten einer Variantendatei, ist jede Zeile eine Variante
     * und nicht eine Karte. Entweder alle drei oder keine: eine halbe Variantendatei wäre
     * nicht zu deuten, denn ohne die Nummer wüsste niemand, was zu welcher Karte gehört.
     */
    $variantColumns = [];

    foreach (CARD_CSV_VARIANT_COLUMNS as $column) {
        if (isset($indexOf[$column])) {
            $variantColumns[] = $column;
        }
    }

    if ($variantColumns !== [] && count($variantColumns) !== count(CARD_CSV_VARIANT_COLUMNS)) {
        fclose($handle);
        $result['fatal'] = 'the file carries ' . implode(', ', $variantColumns)
            . ', but a file with variants needs all of ' . implode(', ', CARD_CSV_VARIANT_COLUMNS);

        return $result;
    }

    $result['variantMode'] = $variantColumns !== [];

    $record = 0;

    while (($cells = import_read_record($handle, $delimiter)) !== false) {
        $record++;

        /*
         * Die Zahl, die eine Person in ihrer Tabellenkalkulation sieht: die Kopfzeile ist
         * Zeile 1, der erste Datensatz ist also Zeile 2. Gezählt werden DATENSÄTZE und
         * nicht Zeilen der Datei, was dasselbe ist, solange keine Zelle einen Umbruch
         * enthält - und die Tabellenkalkulation zählt auch Datensätze.
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

        if ($result['variantMode']) {
            foreach (CARD_CSV_VARIANT_COLUMNS as $column) {
                $row[$column] = trim((string) ($cells[$indexOf[$column]] ?? ''));
            }
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

        if ($result['variantMode']) {
            /* Ohne Kartennummer und Nummer wüsste niemand, wohin die Zeile gehört, und
               eine leere Kennung würde zwei Zeilen unbemerkt zu einer machen. */
            if (!ctype_digit($row['source_card_number']) || (int) $row['source_card_number'] < 1) {
                $result['problems'][] = 'row ' . $number . ': source_card_number is "' . $row['source_card_number']
                    . '", expected a positive whole number';
                continue;
            }

            if (!ctype_digit($row['variant']) || (int) $row['variant'] < 1) {
                $result['problems'][] = 'row ' . $number . ': variant is "' . $row['variant']
                    . '", expected a positive whole number';
                continue;
            }

            if ($row['variant_key'] === '') {
                $result['problems'][] = 'row ' . $number . ': the variant_key is empty';
                continue;
            }
        }

        $result['rows'][] = $row;
    }

    fclose($handle);

    return $result;
}

/**
 * Gruppiert die Zeilen aller Dateien nach der Unterkategorie, die sie nennen, und sucht
 * zwei Zeilen, die dieselbe Karte würden.
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

    /* Zwei Zeilen mit derselben Vorderseite in einer Unterkategorie wären zwei Karten,
       die niemand auseinanderhalten kann, sie brechen den Lauf also ab, statt zweimal
       geschrieben zu werden. */
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

/**
 * Fasst die Zeilen einer Variantendatei zu Karten zusammen.
 *
 * Alle Zeilen mit derselben source_card_number gehören zu EINER Karte. Die Karte bekommt
 * den Text ihrer ersten Variante - damit steht sie auch dann für sich, wenn niemand die
 * Varianten liest -, und ihre Varianten reisen als Liste mit, damit der Plan und das
 * Schreiben sie sehen.
 *
 * Gemeldet wird, was sonst zwei gleiche Zeilen gäbe: ein variant_key, der zweimal
 * vorkommt, eine Variantennummer, die innerhalb einer Karte zweimal vorkommt, und eine
 * Nummerierung, die nicht bei 1 anfängt oder eine Lücke hat.
 *
 * @param list<array{name: string, rows: list<array<string, string>>, problems: list<string>, fatal: string|null}> $files
 * @return array{groups: array<string, array{rows: list<array<string, mixed>>, files: list<string>}>, problems: list<string>}
 */
function import_group_variant_rows(array $files, string $categoryName): array
{
    $byNumber = [];
    $problems = [];
    $fileNames = [];
    $keyLines = [];

    foreach ($files as $file) {
        foreach ($file['rows'] as $row) {
            if (!in_array($file['name'], $fileNames, true)) {
                $fileNames[] = $file['name'];
            }

            if (isset($keyLines[$row['variant_key']])) {
                $problems[] = 'row ' . $row['line'] . ': the variant_key "' . $row['variant_key']
                    . '" is already used in row ' . $keyLines[$row['variant_key']];
                continue;
            }

            $keyLines[$row['variant_key']] = $row['line'];
            $byNumber[(int) $row['source_card_number']][] = $row;
        }
    }

    ksort($byNumber);

    $cards = [];

    foreach ($byNumber as $number => $rows) {
        $byVariant = [];

        foreach ($rows as $row) {
            $variant = (int) $row['variant'];

            if (isset($byVariant[$variant])) {
                $problems[] = 'source_card_number ' . $number . ': the variant ' . $variant . ' comes twice (rows '
                    . $byVariant[$variant]['line'] . ' and ' . $row['line'] . ')';
                continue;
            }

            $byVariant[$variant] = $row;
        }

        ksort($byVariant);

        if (array_keys($byVariant) !== range(1, count($byVariant))) {
            $problems[] = 'source_card_number ' . $number . ': the variants are ' . implode(',', array_keys($byVariant))
                . ', expected 1 up to n without a gap';
            continue;
        }

        $first = $byVariant[1];
        $variants = [];

        foreach ($byVariant as $row) {
            $variants[] = [
                'variant_number' => (int) $row['variant'],
                'variant_key' => $row['variant_key'],
                'front_de' => $row['front_de'],
                'back_de' => $row['back_de'],
                'front_en' => $row['front_en'],
                'back_en' => $row['back_en'],
            ];
        }

        $cards[] = [
            'line' => $first['line'],
            'category' => $categoryName,
            'front' => $first['front_de'],
            'back' => $first['back_de'],
            'front_de' => $first['front_de'],
            'back_de' => $first['back_de'],
            'front_en' => $first['front_en'],
            'back_en' => $first['back_en'],
            'is_bidirectional' => $first['is_bidirectional'],
            'variants' => $variants,
        ];
    }

    return [
        'groups' => [$categoryName => ['rows' => $cards, 'files' => $fileNames]],
        'problems' => $problems,
    ];
}

/* --------------------------------------------------------------------------
   Der Plan
   -------------------------------------------------------------------------- */

/** Eine Zeile, die sagt, wohin die Karten dieser Datei gehen würden. */
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

/**
 * Wie eine geplante Unterkategorie im Plan dasteht.
 *
 * $existingCards ist null, wenn sie noch nicht da ist; steht dort eine Zahl, würde sie mit
 * --replace geleert. $existingProgress sagt, wie vielen ihrer Karten dabei der Fortschritt
 * mitwandert - die Zeilen hängen an den Karten und gehen mit.
 */
function import_group_state(?int $existingCards, ?int $existingProgress): string
{
    if ($existingCards === null) {
        return 'new';
    }

    return 'EXISTING - ' . $existingCards . ' cards would be cleared, ' . $existingProgress
        . ' of them with progress (carried over, a blocker without --replace)';
}

/**
 * Gibt eine geplante Unterkategorie aus: die Zahlen und ein paar Beispielzeilen.
 *
 * Mit $onlyGerman werden die englischen Spalten als "-" gezeigt, weil sie auch nicht
 * geschrieben werden - der Plan zeigt, was die Datenbank bekommt.
 */
function import_print_group(string $name, array $group, ?int $existingCards, ?int $existingProgress, bool $onlyGerman = false): void
{
    $both = 0;

    foreach ($group['rows'] as $row) {
        if ($row['is_bidirectional'] === '1') {
            $both++;
        }
    }

    $state = import_group_state($existingCards, $existingProgress);

    printf(
        "  %-42s %5d cards  both directions: %-5d one direction: %-5d  %s\n",
        $name,
        count($group['rows']),
        $both,
        count($group['rows']) - $both,
        $state
    );

    foreach (array_slice($group['rows'], 0, CARD_CSV_SAMPLES) as $row) {
        printf(
            "      front: %-28s back: %-28s de: %s | %s   en: %s | %s\n",
            mb_substr($row['front'], 0, 28),
            mb_substr($row['back'], 0, 28),
            mb_substr($row['front_de'], 0, 18),
            mb_substr($row['back_de'], 0, 18),
            $onlyGerman ? '-' : mb_substr($row['front_en'], 0, 18),
            $onlyGerman ? '-' : mb_substr($row['back_en'], 0, 18)
        );
    }

    if (count($group['rows']) > CARD_CSV_SAMPLES) {
        echo '      ... and ' . (count($group['rows']) - CARD_CSV_SAMPLES) . " more\n";
    }
}

/**
 * Gibt eine geplante Unterkategorie einer Variantendatei aus.
 *
 * Gezeigt wird die erste Variante von zwei Karten - die Sätze, um die es geht. Die Regel
 * dahinter steht in keiner Spalte der Datei, sie lässt sich hier also nicht zeigen.
 */
function import_print_variant_group(string $name, array $group, ?int $existingCards, ?int $existingProgress): void
{
    $variants = 0;
    $perCard = [];

    foreach ($group['rows'] as $row) {
        $count = count($row['variants'] ?? []);
        $variants += $count;
        $perCard[$count] = ($perCard[$count] ?? 0) + 1;
    }

    printf(
        "  %-42s %5d cards  %5d variants  %s\n",
        $name,
        count($group['rows']),
        $variants,
        import_group_state($existingCards, $existingProgress)
    );

    foreach (array_slice($group['rows'], 0, CARD_CSV_SAMPLES) as $row) {
        $first = $row['variants'][0] ?? null;

        printf(
            "      %-10s %s\n",
            $first === null ? '-' : $first['variant_key'],
            mb_substr($row['front'], 0, 58)
        );
    }

    if (count($perCard) > 1) {
        /* Nicht jede Karte hat gleich viele Varianten. Das ist erlaubt, soll aber auffallen. */
        $parts = [];

        foreach ($perCard as $count => $cards) {
            $parts[] = $cards . ' cards with ' . $count;
        }

        echo '      variants per card: ' . implode(', ', $parts) . "\n";
    }

    if (count($group['rows']) > CARD_CSV_SAMPLES) {
        echo '      ... and ' . (count($group['rows']) - CARD_CSV_SAMPLES) . " more\n";
    }
}

/* --------------------------------------------------------------------------
   Schreiben
   -------------------------------------------------------------------------- */

/**
 * Schreibt jede Unterkategorie und jede Karte des Plans.
 *
 * Die Transaktion hat der Aufrufer geöffnet. Alles, was schiefgehen kann, wirft, der
 * Aufrufer kann den ganzen Lauf also zurückrollen und die Datenbank bleibt, wie sie war.
 *
 * Steht ein Name in $existingIds, wird diese Zeile mit $replace geleert und wieder
 * gefüllt, statt eine zweite Unterkategorie desselben Namens anzulegen. Ohne $replace
 * wäre das ein Fehler, der Aufrufer hat ihn aber schon im Plan gemeldet.
 *
 * Der Fortschritt der alten Karten überlebt das Ersetzen: er wird vor dem Löschen gelesen
 * und auf die neue Karte mit derselben deutschen Vorderseite wieder eingesetzt.
 *
 * Trägt eine Zeile den Schlüssel "variants", werden diese Varianten gleich nach ihrer Karte
 * geschrieben - in derselben Transaktion, also entweder alle oder keine.
 *
 * @param array<string, int> $existingIds Unterkategorie-Name -> Id, unter diesem Bereich
 * @return array{categories: int, cards: int, cleared: int, clearedCards: int,
 *     carried: int, notCarried: list<string>, variants: int}
 */
function import_write(PDO $pdo, array $groups, int $areaId, int $ownerUserId, bool $onlyGerman = false, array $existingIds = [], bool $replace = false): array
{
    $insertCategory = $pdo->prepare(
        'INSERT INTO categories (parent_id, name, name_en, name_de, owner_user_id)
         VALUES (:parent_id, :name, :name_en, :name_de, :owner_user_id)'
    );

    /* Die Karten einer Unterkategorie, die ersetzt wird. Ihre Fortschrittszeilen hängen an
       ihnen (ON DELETE CASCADE) und verschwinden also mit - deshalb werden sie vorher
       gelesen. */
    $clearCards = $pdo->prepare('DELETE FROM cards WHERE category_id = :category_id');

    /* Vor dem Löschen gezählt, damit der Lauf berichten kann, was er weggenommen hat. */
    $countCards = $pdo->prepare('SELECT COUNT(*) FROM cards WHERE category_id = :category_id');

    /* Die alten Karten mit ihrer deutschen Vorderseite: über sie wird der Fortschritt
       nachher wiedererkannt. */
    $readOldCards = $pdo->prepare('SELECT id, front FROM cards WHERE category_id = :category_id ORDER BY id');

    $readOldProgress = $pdo->prepare(
        'SELECT p.user_id, p.card_id, p.state, p.due_at, p.last_reviewed_at,
                p.repetitions, p.lapses, p.stability, p.difficulty
           FROM user_card_progress p JOIN cards k ON k.id = p.card_id
          WHERE k.category_id = :category_id'
    );

    /* Und hier wieder eingesetzt, auf der neuen Karte: dieselben Werte, neue Karten-Id. */
    $insertProgress = $pdo->prepare(
        'INSERT INTO user_card_progress
            (user_id, card_id, state, due_at, last_reviewed_at, repetitions, lapses, stability, difficulty)
         VALUES
            (:user_id, :card_id, :state, :due_at, :last_reviewed_at, :repetitions, :lapses, :stability, :difficulty)'
    );

    /*
     * Die Varianten. Die Anweisung wird nur vorbereitet, wenn wirklich welche zu schreiben
     * sind: prepare() schickt den Satz zum Server, und fehlt die Tabelle, wäre schon das
     * ein Fehler - eine Datei ohne Varianten braucht sie aber gar nicht.
     */
    $insertVariant = null;

    foreach ($groups as $group) {
        foreach ($group['rows'] as $row) {
            if (($row['variants'] ?? []) !== []) {
                $insertVariant = $pdo->prepare(
                    'INSERT INTO card_variants (card_id, variant_number, variant_key, front_de, back_de, front_en, back_en)
                     VALUES (:card_id, :variant_number, :variant_key, :front_de, :back_de, :front_en, :back_en)'
                );
                break 2;
            }
        }
    }

    $variantsWritten = 0;

    /*
     * Die acht Spalten der Datei, eine nach der anderen geschrieben. front/back sind das
     * Paar, das die Karte zeigt; die Sprachspalten tragen denselben Text je Sprache, und
     * front_en/back_en sind das vertauschte Paar, genau wie die Datei es hat. map_region
     * behält seine Vorgabe: dieses Format sagt nichts über eine Karte.
     */
    $insertCard = $pdo->prepare(
        'INSERT INTO cards
            (category_id, front, back, front_de, back_de, front_en, back_en, is_bidirectional)
         VALUES
            (:category_id, :front, :back, :front_de, :back_de, :front_en, :back_en, :is_bidirectional)'
    );

    $categories = 0;
    $cleared = 0;
    $clearedCards = 0;
    $cards = 0;
    $carried = 0;
    $notCarried = [];

    foreach ($groups as $name => $group) {
        $existingId = $existingIds[$name] ?? null;

        if ($existingId !== null && !$replace) {
            throw new RuntimeException('"' . $name . '" already exists and --replace was not given');
        }

        /* Was beim Ersetzen mitwandert: je Fortschrittszeile die alte Vorderseite und die
           Werte, die dazu gehören. Für eine neue Unterkategorie bleibt beides leer. */
        $savedProgress = [];
        $oldFrontCounts = [];
        $newFrontCounts = [];
        $newIdsByFront = [];

        if ($existingId !== null) {
            $countCards->bindValue(':category_id', $existingId, PDO::PARAM_INT);
            $countCards->execute();
            $clearedCards += (int) $countCards->fetchColumn();

            /*
             * Erst lesen, dann löschen: die Fortschrittszeilen hängen an den Karten und
             * wären nach dem Löschen nicht mehr zu finden.
             */
            $readOldCards->bindValue(':category_id', $existingId, PDO::PARAM_INT);
            $readOldCards->execute();
            $oldCards = $readOldCards->fetchAll();

            $frontOfCard = [];

            foreach ($oldCards as $oldCard) {
                $frontOfCard[(int) $oldCard['id']] = (string) $oldCard['front'];
                $key = import_progress_key((string) $oldCard['front']);
                $oldFrontCounts[$key] = ($oldFrontCounts[$key] ?? 0) + 1;
            }

            $readOldProgress->bindValue(':category_id', $existingId, PDO::PARAM_INT);
            $readOldProgress->execute();

            foreach ($readOldProgress->fetchAll() as $row) {
                $savedProgress[] = ['front' => $frontOfCard[(int) $row['card_id']] ?? '', 'row' => $row];
            }

            $clearCards->bindValue(':category_id', $existingId, PDO::PARAM_INT);
            $clearCards->execute();

            $categoryId = $existingId;
            $cleared++;
        } else {
            /* name_de und name_en tragen denselben Namen: die Unterkategorie heißt in beiden
               Sprachen gleich, und das tun die anderen Importe auch. */
            $insertCategory->bindValue(':parent_id', $areaId, PDO::PARAM_INT);
            $insertCategory->bindValue(':name', $name, PDO::PARAM_STR);
            $insertCategory->bindValue(':name_en', $name, PDO::PARAM_STR);
            $insertCategory->bindValue(':name_de', $name, PDO::PARAM_STR);
            $insertCategory->bindValue(':owner_user_id', $ownerUserId, PDO::PARAM_INT);
            $insertCategory->execute();

            $categoryId = (int) $pdo->lastInsertId();
            $categories++;
        }

        foreach ($group['rows'] as $row) {
            $key = import_progress_key($row['front']);
            $newFrontCounts[$key] = ($newFrontCounts[$key] ?? 0) + 1;
            $insertCard->bindValue(':category_id', $categoryId, PDO::PARAM_INT);
            $insertCard->bindValue(':front', $row['front'], PDO::PARAM_STR);
            $insertCard->bindValue(':back', $row['back'], PDO::PARAM_STR);
            $insertCard->bindValue(':front_de', $row['front_de'], PDO::PARAM_STR);
            $insertCard->bindValue(':back_de', $row['back_de'], PDO::PARAM_STR);
            /* Mit --only-german bleiben die englischen Spalten leer: die Karte ist
               dann eine reine deutsche Karte, statt eine zu sein, die englisch
               heißt und deutsch aussieht. */
            $insertCard->bindValue(':front_en', $onlyGerman ? null : $row['front_en'], $onlyGerman ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $insertCard->bindValue(':back_en', $onlyGerman ? null : $row['back_en'], $onlyGerman ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $insertCard->bindValue(':is_bidirectional', (int) $row['is_bidirectional'], PDO::PARAM_INT);
            $insertCard->execute();

            $newCardId = (int) $pdo->lastInsertId();
            $newIdsByFront[$key] = $newCardId;
            $cards++;

            foreach ($row['variants'] ?? [] as $variant) {
                $insertVariant->bindValue(':card_id', $newCardId, PDO::PARAM_INT);
                $insertVariant->bindValue(':variant_number', $variant['variant_number'], PDO::PARAM_INT);
                $insertVariant->bindValue(':variant_key', $variant['variant_key'], PDO::PARAM_STR);
                $insertVariant->bindValue(':front_de', $variant['front_de'], PDO::PARAM_STR);
                $insertVariant->bindValue(':back_de', $variant['back_de'], PDO::PARAM_STR);
                /* Wie bei der Karte: mit --only-german bleiben die englischen Spalten leer. */
                $insertVariant->bindValue(':front_en', $onlyGerman ? null : $variant['front_en'], $onlyGerman ? PDO::PARAM_NULL : PDO::PARAM_STR);
                $insertVariant->bindValue(':back_en', $onlyGerman ? null : $variant['back_en'], $onlyGerman ? PDO::PARAM_NULL : PDO::PARAM_STR);
                $insertVariant->execute();

                $variantsWritten++;
            }
        }

        /*
         * Der Fortschritt auf die neuen Karten. Erkannt wird die Karte an ihrer deutschen
         * Vorderseite. Kommt sie mehrfach vor (zwei Karten mit demselben Wort und
         * verschiedener Übersetzung), wird lieber nichts übertragen als das Falsche - und
         * der Lauf sagt, welche Zeile das betrifft.
         */
        foreach ($savedProgress as $saved) {
            $key = import_progress_key($saved['front']);
            $row = $saved['row'];

            if (($oldFrontCounts[$key] ?? 0) !== 1 || ($newFrontCounts[$key] ?? 0) !== 1) {
                $notCarried[] = $name . ': "' . $saved['front'] . '" kommt '
                    . (($newFrontCounts[$key] ?? 0) === 0 ? 'in der neuen Liste nicht vor' : 'mehrfach vor');
                continue;
            }

            $insertProgress->bindValue(':user_id', (int) $row['user_id'], PDO::PARAM_INT);
            $insertProgress->bindValue(':card_id', $newIdsByFront[$key], PDO::PARAM_INT);
            $insertProgress->bindValue(':state', (int) $row['state'], PDO::PARAM_INT);
            $insertProgress->bindValue(':due_at', $row['due_at'], $row['due_at'] === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $insertProgress->bindValue(':last_reviewed_at', $row['last_reviewed_at'], $row['last_reviewed_at'] === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $insertProgress->bindValue(':repetitions', (int) $row['repetitions'], PDO::PARAM_INT);
            $insertProgress->bindValue(':lapses', (int) $row['lapses'], PDO::PARAM_INT);
            $insertProgress->bindValue(':stability', $row['stability'], $row['stability'] === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $insertProgress->bindValue(':difficulty', $row['difficulty'], $row['difficulty'] === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $insertProgress->execute();

            $carried++;
        }
    }

    return [
        'categories' => $categories,
        'cards' => $cards,
        'cleared' => $cleared,
        'clearedCards' => $clearedCards,
        'carried' => $carried,
        'notCarried' => $notCarried,
        'variants' => $variantsWritten,
    ];
}

/* --------------------------------------------------------------------------
   Hauptlauf
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
    echo 'Files : ' . count($options['files']) . "\n";
    echo 'Sprache: ' . ($options['onlyGerman'] ? "nur Deutsch (front_en/back_en bleiben leer)" : 'alle Spalten aus der Datei') . "\n\n";

    try {
        $pdo = create_database_connection();
    } catch (Throwable $error) {
        echo "DATABASE ERROR: the connection could not be opened.\n";

        return 1;
    }

    /* ---- das Konto ---- */

    $ownerStatement = $pdo->prepare('SELECT id, name FROM users WHERE id = :id');
    $ownerStatement->bindValue(':id', $options['owner'], PDO::PARAM_INT);
    $ownerStatement->execute();
    $ownerRow = $ownerStatement->fetch();

    if ($ownerRow === false) {
        echo 'STOP: there is no user with id ' . $options['owner'] . ".\n";

        return 1;
    }

    echo 'User  : ' . $ownerRow['name'] . "\n\n";

    /* ---- der Lernbereich ---- */

    /*
     * Der Bereich wird über name, name_de und name_en gesucht, und nur unter den
     * Bereichen dieses Kontos: zwei Konten dürfen jeder einen Bereich desselben Namens
     * haben, und die Karten müssen im richtigen landen.
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

    /* ---- die Dateien ---- */

    $files = [];

    foreach ($options['files'] as $path) {
        $files[] = import_read_csv($path);
    }

    /*
     * Eine Variantendatei und eine gewöhnliche Datei im selben Lauf wären zwei Regeln in
     * einem Befehl: die eine füllt eine genannte Unterkategorie, die andere bringt ihre
     * Namen selbst mit. Das wird abgelehnt, statt es zu erraten.
     */
    $variantFiles = 0;

    foreach ($files as $file) {
        if ($file['variantMode']) {
            $variantFiles++;
        }
    }

    $variantMode = $variantFiles > 0;

    echo "\nKind  : " . ($variantMode
        ? 'cards with variants (every source_card_number is one card)'
        : 'one card per row') . "\n";

    $grouping = $variantMode
        ? import_group_variant_rows($files, (string) $options['category'])
        : import_group_rows($files);
    $groups = $grouping['groups'];

    echo "\n" . str_repeat('-', 78) . "\n";
    echo "THE PLAN\n";
    echo str_repeat('-', 78) . "\n";

    foreach ($files as $file) {
        import_print_file($file);
    }

    /*
     * Welche der geplanten Unterkategorien hängen schon unter diesem Bereich? Ihre Id
     * wird gebraucht: mit --replace wird genau diese Zeile geleert und wieder gefüllt.
     */
    $existingIds = [];
    $existingCards = [];
    $existingProgress = [];
    $blockers = $grouping['problems'];

    if ($area !== null && $groups !== []) {
        $check = $pdo->prepare('SELECT id, name FROM categories WHERE parent_id = :parent_id AND owner_user_id = :owner_user_id');
        $check->bindValue(':parent_id', (int) $area['id'], PDO::PARAM_INT);
        $check->bindValue(':owner_user_id', $options['owner'], PDO::PARAM_INT);
        $check->execute();

        foreach ($check as $row) {
            $existingIds[(string) $row['name']] = (int) $row['id'];
        }

        /*
         * Was ein Ersetzen kostet, steht vorher im Plan: wie viele Karten gehen, und wie
         * viele davon schon einen Fortschritt tragen. Diese Zeilen hängen an den Karten,
         * sie verschwinden also mit ihnen - das soll niemand erst hinterher merken.
         */
        foreach (array_intersect_key($existingIds, $groups) as $name => $categoryId) {
            /*
             * Beide Zahlen als eigene Unterabfrage in einer Abfrage ohne FROM. Ein
             * COUNT(*) mit einer korrelierten Unterabfrage daneben wäre falsch: MySQL
             * nimmt dann für die zweite Spalte die Werte EINER beliebigen Zeile, und der
             * Plan würde 38 Karten mit Fortschritt als "1" melden.
             */
            $costStatement = $pdo->prepare(
                'SELECT (SELECT COUNT(*) FROM cards k WHERE k.category_id = :cards_id) AS cards,
                        (SELECT COUNT(*) FROM user_card_progress p
                           JOIN cards k ON k.id = p.card_id
                          WHERE k.category_id = :progress_id) AS progress'
            );
            $costStatement->bindValue(':cards_id', $categoryId, PDO::PARAM_INT);
            $costStatement->bindValue(':progress_id', $categoryId, PDO::PARAM_INT);
            $costStatement->execute();
            $cost = $costStatement->fetch();

            $existingCards[$name] = (int) $cost['cards'];
            $existingProgress[$name] = (int) $cost['progress'];

            if (!$options['replace']) {
                $blockers[] = 'the subcategory "' . $name . '" already exists (' . $existingCards[$name]
                    . ' cards); use --replace to clear and refill it';
            }
        }
    }

    echo "SUBCATEGORIES\n";

    foreach ($groups as $name => $group) {
        if ($variantMode) {
            import_print_variant_group($name, $group, $existingCards[$name] ?? null, $existingProgress[$name] ?? null);
            continue;
        }

        import_print_group($name, $group, $existingCards[$name] ?? null, $existingProgress[$name] ?? null, $options['onlyGerman']);
    }

    if ($variantMode) {
        echo "\nNOTE: the category column of the file is not read here; --category decides.\n";
    }

    $cards = 0;
    $variants = 0;

    foreach ($groups as $group) {
        $cards += count($group['rows']);

        foreach ($group['rows'] as $row) {
            $variants += count($row['variants'] ?? []);
        }
    }

    echo "\nTOTAL\n";
    echo '  subcategories to create : ' . (count($groups) - count(array_intersect_key($existingIds, $groups))) . "\n";
    echo '  subcategories to refill : ' . count(array_intersect_key($existingIds, $groups)) . "\n";
    echo '  cards to create         : ' . $cards . "\n";

    if ($variantMode) {
        echo '  variants to create      : ' . $variants . "\n";
    }

    foreach ($files as $file) {
        if ($file['fatal'] !== null) {
            $blockers[] = $file['name'] . ': ' . $file['fatal'];
        }
    }

    if ($options['expect'] !== null && $cards !== $options['expect']) {
        $blockers[] = 'the files hold ' . $cards . ' cards'
            . ($variantMode ? ' (from ' . $variants . ' variant rows)' : '')
            . ', but --expect says ' . $options['expect'];
    }

    if ($variantMode && $variantFiles !== count($files)) {
        $blockers[] = 'some files carry variants and some do not; one run is one kind of file';
    }

    if ($variantMode && $options['category'] === null) {
        $blockers[] = 'a file with variants needs --category=<name>: the one subcategory its cards go into';
    }

    if (!$variantMode && $options['category'] !== null) {
        $blockers[] = '--category is only read for a file with variants; an ordinary file names its subcategories itself';
    }

    if ($variantMode && !import_variant_table_exists($pdo)) {
        $blockers[] = 'the table card_variants does not exist; run database/card_variants.sql first';
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

    /* ---- schreiben, alles oder nichts ---- */

    $pdo->beginTransaction();

    try {
        if ($area === null) {
            $icon = $options['icon'] === null ? null : (string) file_get_contents($options['icon']);

            $createArea = $pdo->prepare(
                'INSERT INTO categories (parent_id, name, name_en, name_de, owner_user_id, icon_svg, icon_scale)
                 VALUES (NULL, :name, :name_en, :name_de, :owner_user_id, :icon_svg, 1.00)'
            );
            $createArea->bindValue(':name', $options['area'], PDO::PARAM_STR);
            $createArea->bindValue(':name_en', $options['area'], PDO::PARAM_STR);
            $createArea->bindValue(':name_de', $options['area'], PDO::PARAM_STR);
            $createArea->bindValue(':owner_user_id', $options['owner'], PDO::PARAM_INT);
            $createArea->bindValue(':icon_svg', $icon, $icon === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $createArea->execute();

            $areaId = (int) $pdo->lastInsertId();
        } else {
            $areaId = (int) $area['id'];
        }

        $written = import_write($pdo, $groups, $areaId, $options['owner'], $options['onlyGerman'], $existingIds, $options['replace']);

        if ($written['cards'] !== $cards) {
            throw new RuntimeException('not every card was written (' . $written['cards'] . ' of ' . $cards . ')');
        }

        if ($written['variants'] !== $variants) {
            throw new RuntimeException('not every variant was written (' . $written['variants'] . ' of ' . $variants . ')');
        }

        /* Der Beweis, gelesen vor dem Festschreiben: die Zeilen sind wirklich da. */
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
    echo '  subcategories refilled  : ' . $written['cleared'] . "\n";
    echo '  cards removed           : ' . $written['clearedCards'] . "\n";
    echo '  cards created           : ' . $written['cards'] . "\n";

    if ($variantMode) {
        echo '  variants created        : ' . $written['variants'] . "\n";
    }

    echo '  progress carried over   : ' . $written['carried'] . "\n";
    echo '  cards in the area now   : ' . $inArea . "\n";

    if ($written['notCarried'] !== []) {
        echo "\nProgress that could not be carried over (" . count($written['notCarried']) . "):\n";

        foreach (array_slice($written['notCarried'], 0, 10) as $note) {
            echo '  - ' . $note . "\n";
        }

        if (count($written['notCarried']) > 10) {
            echo '  ... and ' . (count($written['notCarried']) - 10) . " more\n";
        }
    }

    return 0;
}

exit(import_main($argv, $projectRoot));
