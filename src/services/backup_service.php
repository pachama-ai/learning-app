<?php

declare(strict_types=1);

/**
 * Eine Sicherung der Datenbank anlegen.
 *
 * Der Ablauf in einem Satz: mysqldump schreibt die Datenbank in eine Datei mit
 * Zeitstempel im Namen, und zwar nach storage/backups im Projektstamm.
 *
 * Warum der Ordner so wichtig ist: ein Abzug enthaelt E-Mail-Adressen und
 * Passwort-Hashes. Er bleibt deshalb ausschliesslich hier liegen - ausserhalb von
 * public/ und ausserhalb von Git. Es gibt bewusst keinen Weg, auf dem ein Abzug
 * irgendwohin hochgeladen wird.
 *
 * Die Zugangsdaten kommen ausnahmslos aus der .env und niemals in den Quelltext.
 *
 * Warum das Passwort ueber MYSQL_PWD und nicht als Schalter: eine
 * Kommandozeile kann jeder lesen, der auf dem Server "ps" tippt. Als
 * Umgebungsvariable des Kindprozesses steht es nur in dessen eigener Umgebung.
 *
 * Diese Datei kennt keinen Nutzer und keine Rolle - WER das hier ausloesen
 * darf, entscheidet der Endpunkt (public/api/admin_backup.php).
 */

require_once __DIR__ . '/../helpers/env.php';

/** Der Ordner der Sicherungen, fest im Projektstamm neben public/. */
const BACKUP_FOLDER_NAME = 'storage/backups';

/** Der Anfang jedes Dateinamens. */
const BACKUP_FILE_PREFIX = 'learning_app_';

/** Wie lange mysqldump hoechstens brauchen darf, in Sekunden. */
const BACKUP_TIMEOUT_SECONDS = 120;

/* --------------------------------------------------------------------------
   Wo die Sicherungen liegen
   -------------------------------------------------------------------------- */

/**
 * Der Ordner, in dem die Sicherungen landen: storage/backups im Projektstamm.
 *
 * Der Ort ist absichtlich fest und nicht einstellbar. Ein Abzug enthaelt
 * E-Mail-Adressen und Passwort-Hashes, und ein falsch gesetzter Einstellungswert
 * koennte ihn nach public/ schreiben - dort holt ihn sich jeder Browser mit einer
 * geratenen Adresse ab. Ohne Einstellung gibt es diesen Fehler nicht.
 *
 * @return string Pfad ohne abschliessenden Schraegstrich
 */
function backup_directory(): string
{
    return dirname(__DIR__, 2) . '/' . BACKUP_FOLDER_NAME;
}

/**
 * Ob dieser Ordner wirklich ausserhalb des Web-Roots liegt.
 *
 * Die Pruefung ist eine zweite Sicherung: der Ordner ist zwar fest verdrahtet,
 * aber faellt er je in public/, holt sich jeder Browser den Abzug mit einer
 * geratenen Adresse ab. Im Zweifel lieber kein Backup als ein oeffentliches.
 *
 * @param string $directory Der zu pruefende Ordner; er darf noch fehlen.
 */
function backup_directory_is_safe(string $directory): bool
{
    $publicDirectory = realpath(dirname(__DIR__, 2) . '/public');

    /* Ohne public/ gibt es nichts, wovor man sich schuetzen muesste. */
    if ($publicDirectory === false) {
        return true;
    }

    $target = realpath($directory);

    /* Einen Ordner, den es noch nicht gibt, gibt es auch nicht zu pruefen -
       geprueft wird dann der naechste Ordner darueber, der wirklich existiert. */
    if ($target === false) {
        $target = backup_nearest_existing_path($directory);
    }

    if ($target === false) {
        return false;
    }

    return !($target === $publicDirectory
        || str_starts_with($target . '/', $publicDirectory . '/'));
}

/**
 * Der naechste Ordner ueber dem uebergebenen, den es wirklich gibt.
 *
 * @param string $path Ein Pfad, der selbst nicht existieren muss.
 *
 * @return string|false false, wenn auch keiner der Elternordner existiert
 */
function backup_nearest_existing_path(string $path)
{
    $candidate = $path;

    while ($candidate !== '' && $candidate !== '/' && $candidate !== '.') {
        if (is_dir($candidate)) {
            $resolved = realpath($candidate);

            return $resolved === false ? false : $resolved;
        }

        $parent = dirname($candidate);

        if ($parent === $candidate) {
            break;
        }

        $candidate = $parent;
    }

    return false;
}

/* --------------------------------------------------------------------------
   Der Abzug selbst
   -------------------------------------------------------------------------- */

/**
 * Legt eine Sicherung der Datenbank an und meldet, was dabei herausgekommen ist.
 *
 * @return array{ok: bool, error: ?string, file?: string, bytes?: int, created_at?: string}
 *         Bei ok=false nennt "error" den Grund; der Endpunkt gibt diesen Grund
 *         nicht nach draussen, er gehoert nur ins Protokoll.
 */
function create_database_backup(): array
{
    $directory = backup_directory();

    if (!backup_directory_is_safe($directory)) {
        error_log('backup: Der Zielordner liegt im Web-Root und wird abgelehnt.');

        return ['ok' => false, 'error' => 'unsafe_directory'];
    }

    if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
        error_log('backup: Der Zielordner konnte nicht angelegt werden.');

        return ['ok' => false, 'error' => 'directory_failed'];
    }

    $fileName = BACKUP_FILE_PREFIX . date('Ymd_His') . '.sql';
    $path = $directory . '/' . $fileName;

    $exitCode = backup_run_mysqldump($path);

    if ($exitCode !== 0) {
        @unlink($path);

        return ['ok' => false, 'error' => 'dump_failed'];
    }

    /* Eine leere Datei ist kein Backup, sondern ein Fehler, der wie Erfolg
       aussieht - deshalb ausdruecklich pruefen. */
    if (!is_file($path) || (int) filesize($path) === 0) {
        @unlink($path);

        return ['ok' => false, 'error' => 'empty_dump'];
    }

    /* Der Abzug enthaelt personenbezogene Daten: nur der Eigentuemer liest ihn. */
    @chmod($path, 0600);

    return [
        'ok' => true,
        'error' => null,
        'file' => $fileName,
        'bytes' => (int) filesize($path),
        'created_at' => date('c'),
    ];
}

/**
 * Ruft mysqldump auf und schreibt den Abzug nach $path.
 *
 * Alle Werte kommen aus der .env. mysqldump bekommt sie als Liste von
 * Argumenten, nicht als eine Zeile fuer die Shell - so kann kein Wert aus der
 * .env jemals als Befehl verstanden werden.
 *
 * @param string $path Zielpfad; mysqldump schreibt die Datei selbst.
 *
 * @return int Der Exit-Code von mysqldump
 */
function backup_run_mysqldump(string $path): int
{
    $host = env_required('DB_HOST');
    $port = (string) (int) env('DB_PORT', '3306');
    $database = env_required('DB_NAME');
    $user = env_required('DB_USER');
    $password = env_required('DB_PASS');

    $arguments = [
        'mysqldump',
        '--host=' . $host,
        '--port=' . $port,
        '--user=' . $user,
        /* Ein einheitlicher Stand, ohne die Tabellen fuer andere zu sperren. */
        '--single-transaction',
        '--skip-lock-tables',
        /* Ohne --no-tablespaces verlangt mysqldump das PROCESS-Recht, das ein
           normaler Datenbankbenutzer nicht hat. */
        '--no-tablespaces',
        '--default-character-set=utf8mb4',
        '--result-file=' . $path,
        $database,
    ];

    $descriptors = [
        0 => ['file', '/dev/null', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    /*
     * Die Umgebung des Kindprozesses: nur PATH (damit mysqldump gefunden wird),
     * HOME und das Passwort. Ein geerbtes Protokoll wie MYSQL_PS1 braucht hier
     * niemand, und alles, was nicht mitgegeben wird, kann auch nicht auslaufen.
     */
    $environment = [
        'PATH' => (string) (getenv('PATH') ?: '/usr/bin:/bin'),
        'HOME' => (string) (getenv('HOME') ?: '/tmp'),
        'MYSQL_PWD' => $password,
    ];

    $process = @proc_open($arguments, $descriptors, $pipes, null, $environment);

    if (!is_resource($process)) {
        error_log('backup: mysqldump liess sich nicht starten.');

        return -1;
    }

    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $errors = '';
    $startedAt = time();
    $exitCode = -1;
    $timedOut = false;

    while (true) {
        $status = proc_get_status($process);

        $errors .= (string) stream_get_contents($pipes[2]);

        if ($status['running'] !== true) {
            $exitCode = (int) $status['exitcode'];

            break;
        }

        if (time() - $startedAt > BACKUP_TIMEOUT_SECONDS) {
            $timedOut = true;
            @proc_terminate($process, 9);

            break;
        }

        usleep(50000);
    }

    fclose($pipes[1]);
    fclose($pipes[2]);
    @proc_close($process);

    /* Der Grund gehoert ins Server-Protokoll und nirgendwo sonst hin: mysqldump
       nennt darin gern Host, Benutzer und Datenbank. */
    if ($errors !== '') {
        error_log('backup: mysqldump meldete: ' . trim($errors));
    }

    if ($timedOut) {
        error_log('backup: mysqldump hat zu lange gebraucht und wurde beendet.');

        return -1;
    }

    return $exitCode;
}

