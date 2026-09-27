<?php

declare(strict_types=1);

/**
 * Liest die Datei .env im Projektstamm und stellt ihre Werte bereit.
 *
 * Warum es diese Datei gibt: Zugangsdaten (Datenbank, spaeter vielleicht
 * API-Schluessel) gehoeren nicht in den Quelltext und nicht ins Git. Sie stehen
 * in einer einzigen Datei .env, die nur auf diesem Rechner liegt. Diese Datei
 * ist die einzige Stelle, die sie einliest.
 *
 * Regeln der Datei .env:
 *   - eine Zeile je Wert, Form: NAME=Wert
 *   - Leerzeilen und Zeilen, die mit # beginnen, werden uebersprungen
 *   - Werte duerfen in einfachen oder doppelten Anfuehrungszeichen stehen; die
 *     Anfuehrungszeichen gehoeren dann nicht zum Wert
 *   - eine bereits gesetzte Umgebungsvariable wird NICHT ueberschrieben (so
 *     gewinnt zum Beispiel eine Variable, die der Server selbst mitgibt)
 *
 * Diese Datei gibt niemals einen Wert aus. Fehlt etwas, landet eine klare
 * Meldung im Fehlerprotokoll - der Wert selbst und der Dateiinhalt nie in der
 * Antwort an den Browser.
 */

/** Der Name der Datei im Projektstamm. */
const ENV_FILE_NAME = '.env';

/**
 * Der Pfad zur Datei .env. Sie liegt im Projektstamm, also zwei Ebenen ueber
 * diesem Ordner (src/helpers/ -> src/ -> Projektstamm).
 */
function env_file_path(): string
{
    return dirname(__DIR__, 2) . '/' . ENV_FILE_NAME;
}

/**
 * Liest die Datei .env einmal pro Anfrage und legt die Werte als
 * Umgebungsvariablen ab.
 *
 * @param string|null $path Nur fuer Tests: ein anderer Pfad zur Datei.
 */
function env_load(?string $path = null): void
{
    /* Nur einmal lesen, egal wie oft env() aufgerufen wird. */
    static $alreadyLoaded = false;

    if ($alreadyLoaded) {
        return;
    }

    $alreadyLoaded = true;
    $filePath = $path ?? env_file_path();

    if (!is_file($filePath) || !is_readable($filePath)) {
        /* Kein Abbruch: vielleicht kommen die Werte aus der Umgebung des
           Servers. Wer sie braucht, meldet sich mit env_required(). */
        error_log('env: Die Datei ' . ENV_FILE_NAME . ' fehlt oder ist nicht lesbar: ' . $filePath);

        return;
    }

    $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    if ($lines === false) {
        error_log('env: Die Datei ' . ENV_FILE_NAME . ' konnte nicht gelesen werden.');

        return;
    }

    foreach ($lines as $line) {
        $line = trim($line);

        /* Kommentar oder leer: nichts zu tun. */
        if ($line === '' || $line[0] === '#') {
            continue;
        }

        $separator = strpos($line, '=');

        if ($separator === false) {
            continue;
        }

        $name = trim(substr($line, 0, $separator));
        $value = trim(substr($line, $separator + 1));

        if ($name === '') {
            continue;
        }

        /* Werte in Anfuehrungszeichen: die Zeichen gehoeren nicht zum Wert. */
        $length = strlen($value);

        if ($length >= 2) {
            $first = $value[0];
            $last = $value[$length - 1];

            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                $value = substr($value, 1, -1);
            }
        }

        /* Eine schon gesetzte Variable bleibt, wie sie ist. */
        if (getenv($name) !== false) {
            continue;
        }

        putenv($name . '=' . $value);
        $_ENV[$name] = $value;
    }
}

/**
 * Ein Wert aus der Umgebung, oder der Standardwert.
 *
 * Ein leerer Wert zaehlt wie "nicht gesetzt": fuer eine Zugangsdatei ist ein
 * leerer Platzhalter genauso unbrauchbar wie ein fehlender.
 *
 * @param string      $name     Name der Variablen, z. B. "DB_HOST".
 * @param string|null $standard Wert, der gilt, wenn nichts gesetzt ist.
 */
function env(string $name, ?string $standard = null): ?string
{
    env_load();

    $value = getenv($name);

    if ($value === false || trim($value) === '') {
        return $standard;
    }

    return $value;
}

/**
 * Ein Wert, der unbedingt da sein muss.
 *
 * Fehlt er, wird eine klare Meldung ins Fehlerprotokoll geschrieben und eine
 * allgemeine Ausnahme geworfen. Die Meldung nennt nur den Namen der Variablen -
 * niemals einen Wert, niemals den Inhalt der Datei. Die Endpunkte fangen die
 * Ausnahme ab und antworten mit einem allgemeinen Fehler (500).
 *
 * @throws RuntimeException wenn die Variable fehlt.
 */
function env_required(string $name): string
{
    $value = env($name);

    if ($value === null) {
        error_log(
            'env: Die Pflichtvariable ' . $name . ' fehlt. Bitte ' . ENV_FILE_NAME
            . ' im Projektstamm anlegen (Vorlage: .env.example).'
        );

        throw new RuntimeException('Die Konfiguration ist unvollstaendig. Einzelheiten stehen im Fehlerprotokoll.');
    }

    return $value;
}
