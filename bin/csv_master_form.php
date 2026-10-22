<?php

declare(strict_types=1);

/**
 * Wandelt CSV-Dateien von der Kopfzeile MIT `parent_category` in die Master-Form um.
 *
 *   php bin/csv_master_form.php --into=database/import datei1.csv datei2.csv ...
 *
 * Warum es dieses Werkzeug gibt
 *
 * `bin/import_energy_cards.php` liest zwei Kopfzeilen: eine MIT `parent_category`, deren
 * Zielbereich über den Namen in der Datei gefunden wird, und die Master-Form OHNE diese
 * Spalte, deren Ziel über `--area-id` angegeben wird. Für eine Datei MIT `parent_category`
 * lässt der Importer `--area-id` ausdrücklich nicht zu.
 *
 * Das ist genau dann ein Problem, wenn zwei Lernbereiche denselben Namen tragen: im
 * Projekt gibt es „Energy" zweimal (id 3 und id 193, in `name`, `name_de` und `name_en`
 * gleich). Der Importer bricht dann mit „the learning area ... is ambiguous" ab, und
 * `bin/import_cards_csv.php --area=<name>` hätte dasselbe Problem.
 *
 * Dieses Werkzeug nimmt die Spalte `parent_category` heraus und schreibt die Karten
 * unverändert in einen Arbeitsordner. Die so entstandene Datei importiert man mit
 * `--area-id=<id>` — dann ist der Zielbereich eindeutig, und an den Inhalten ist nichts
 * geändert.
 *
 * Was es NICHT tut: es schreibt nur Dateien. Keine Datenbank, kein Import, kein Löschen.
 * Eine Datei, die die Master-Form schon hat, wird unverändert in den Zielordner kopiert.
 *
 * @return void
 */

const CSV_TRENNER = ';';
const CSV_ANFUEHRUNG = '"';

/**
 * Liest die Kommandozeile: einen Zielordner und mindestens eine Datei.
 *
 * @param list<string> $argumente die Argumente ohne den Skriptnamen
 * @return array{into: string|null, files: list<string>}
 */
function csv_read_arguments(array $argumente): array
{
    $into = null;
    $files = [];

    foreach ($argumente as $argument) {
        if (strpos($argument, '--into=') === 0) {
            $into = substr($argument, 7);
            continue;
        }

        if ($argument === '--help' || $argument === '-h') {
            csv_print_usage();
            exit(0);
        }

        if (strpos($argument, '--') === 0) {
            fwrite(STDERR, 'Unknown option: ' . $argument . "\n\n");
            csv_print_usage();
            exit(1);
        }

        $files[] = $argument;
    }

    return ['into' => $into, 'files' => $files];
}

/**
 * Schreibt die Hilfe auf die Standardausgabe.
 *
 * @return void
 */
function csv_print_usage(): void
{
    echo "Usage:\n";
    echo "  php bin/csv_master_form.php --into=<folder> <file> [<file> ...]\n\n";
    echo "  --into=<folder>   where the converted files are written (is created)\n";
    echo "  <file>            one or more CSV files with the header that has parent_category\n\n";
    echo "The output keeps the file name. Sheets that already use the master form are copied.\n";
}

/**
 * Schreibt eine Zeile im CSV-Format dieser Anwendung.
 *
 * Angeführt wird nur, was muss (Trennzeichen, Anführungszeichen oder Zeilenumbruch im
 * Feld); ein Anführungszeichen im Text wird verdoppelt. Das entspricht dem, was die
 * Quelldateien tun, und hält Formeln und Sonderzeichen unangetastet.
 *
 * @param resource $handle die offene Zieldatei
 * @param list<string> $felder die Felder der Zeile
 * @return void
 */
function csv_write_row($handle, array $felder): void
{
    $teile = array_map(static function (string $feld): string {
        if (strpbrk($feld, CSV_TRENNER . CSV_ANFUEHRUNG . "\n\r") === false) {
            return $feld;
        }

        return CSV_ANFUEHRUNG . str_replace(CSV_ANFUEHRUNG, CSV_ANFUEHRUNG . CSV_ANFUEHRUNG, $feld) . CSV_ANFUEHRUNG;
    }, $felder);

    fwrite($handle, implode(CSV_TRENNER, $teile) . "\n");
}

/**
 * Schreibt eine Zieldatei: ohne `parent_category`, sonst Feld für Feld gleich.
 *
 * @param string $quelle der Pfad der Quelldatei
 * @param string $ziel der Pfad der Zieldatei
 * @return array{cards: int, dropped: bool, columns: list<string>} Karten, ob die Spalte
 *         entfernt wurde, und die Spalten der Zieldatei
 */
function csv_convert(string $quelle, string $ziel): array
{
    $lesen = fopen($quelle, 'rb');

    if ($lesen === false) {
        fwrite(STDERR, 'Cannot read: ' . $quelle . "\n");
        exit(1);
    }

    $schreiben = fopen($ziel, 'wb');

    if ($schreiben === false) {
        fwrite(STDERR, 'Cannot write: ' . $ziel . "\n");
        exit(1);
    }

    $kopf = fgetcsv($lesen, 0, CSV_TRENNER, CSV_ANFUEHRUNG, '');
    $index = is_array($kopf) ? array_search('parent_category', $kopf, true) : false;

    if ($index === false) {
        /* Schon die Master-Form: die Zeilen gehen unverändert hinüber. */
        csv_write_row($schreiben, array_map('strval', is_array($kopf) ? $kopf : []));

        $cards = 0;
        while (($zeile = fgetcsv($lesen, 0, CSV_TRENNER, CSV_ANFUEHRUNG, '')) !== false) {
            if ($zeile === [null]) {
                continue;
            }

            csv_write_row($schreiben, array_map('strval', $zeile));
            $cards++;
        }

        fclose($lesen);
        fclose($schreiben);

        return ['cards' => $cards, 'dropped' => false, 'columns' => is_array($kopf) ? $kopf : []];
    }

    $neuerKopf = array_values(array_diff($kopf, [$kopf[$index]]));
    csv_write_row($schreiben, $neuerKopf);

    $cards = 0;
    while (($zeile = fgetcsv($lesen, 0, CSV_TRENNER, CSV_ANFUEHRUNG, '')) !== false) {
        if ($zeile === [null]) {
            continue;
        }

        /* Dieselbe Spalte in jeder Zeile weglassen, die Reihenfolge bleibt also gleich. */
        array_splice($zeile, $index, 1);

        csv_write_row($schreiben, array_map('strval', $zeile));
        $cards++;
    }

    fclose($lesen);
    fclose($schreiben);

    return ['cards' => $cards, 'dropped' => true, 'columns' => $neuerKopf];
}

$eingabe = csv_read_arguments(array_slice($argv, 1));

if ($eingabe['into'] === null || $eingabe['files'] === []) {
    csv_print_usage();
    exit(1);
}

if (is_dir($eingabe['into']) === false && mkdir($eingabe['into'], 0775, true) === false) {
    fwrite(STDERR, 'Cannot create folder: ' . $eingabe['into'] . "\n");
    exit(1);
}

$gesamt = 0;

foreach ($eingabe['files'] as $datei) {
    $ziel = rtrim($eingabe['into'], '/') . '/' . basename($datei);
    $ergebnis = csv_convert($datei, $ziel);
    $gesamt += $ergebnis['cards'];

    printf(
        "%-58s %3d cards  %s\n",
        basename($datei),
        $ergebnis['cards'],
        $ergebnis['dropped'] ? 'parent_category removed' : 'already master form (copied)'
    );
}

echo "\nFiles written to " . rtrim($eingabe['into'], '/') . " (" . $gesamt . " cards together).\n";
echo "Import them with --area-id=<id>, the learning area is then unambiguous.\n";
