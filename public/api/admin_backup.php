<?php

declare(strict_types=1);

/**
 * Eine Sicherung der Datenbank anlegen.
 *
 *   POST api/admin_backup.php
 *     { "csrf_token": "..." }
 *
 * Diese Datei antwortet nur einem angemeldeten Konto mit der Rolle "admin". Der
 * Knopf im Kontofenster ist nur die halbe Sache: er ist Bequemlichkeit und
 * verschwindet bei allen anderen Konten. Die Entscheidung faellt hier, mit der
 * Rolle aus der Tabelle users - nicht mit einer E-Mail-Adresse. Eine Adresse
 * kann sich aendern oder von jemand anderem registriert werden, die Rolle in
 * der Datenbank kann das nicht.
 *
 * Die Datei bleibt duenn, wie jeder Endpunkt hier: Anfrage lesen, Rolle pruefen,
 * den Service aufrufen und als JSON antworten. Kein Dateipfad, kein Passwort
 * und keine Verbindungszeichenfolge verlaesst diese Datei nach aussen.
 */

require_once __DIR__ . '/../../src/config/database.php';
require_once __DIR__ . '/../../src/helpers/json_response.php';
require_once __DIR__ . '/../../src/helpers/request_input.php';
require_once __DIR__ . '/../../src/services/user_service.php';
require_once __DIR__ . '/../../src/services/backup_service.php';

/*
 * Alles laeuft in diesem Schutz: ein unerwarteter Fehler soll eine lesbare
 * JSON-Antwort ergeben und nicht die allgemeine Fehlerseite des Servers. Der
 * Grund geht ins Protokoll.
 */
try {
    handle_admin_backup_request($_SERVER['REQUEST_METHOD'] ?? 'GET');
} catch (Throwable $error) {
    error_log('api/admin_backup.php: ' . $error->getMessage());

    send_json_error('server_error', 'This request could not be handled.', 500);
}

/** Der eine Einstiegspunkt dieses Endpunkts. */
function handle_admin_backup_request(string $method): void
{
    if ($method !== 'POST') {
        header('Allow: POST');
        send_json_error('method_not_allowed', 'Only POST is supported here.', 405);
    }

    $body = read_json_object();

    /* Wie bei jedem Schreiben: ohne den Token aus der Sitzung passiert nichts. */
    if (user_csrf_valid(optional_input_text($body, 'csrf_token', 128, 'invalid_token')) !== true) {
        send_json_error(
            'invalid_token',
            'This request carried no valid token. Reload the page and try again.',
            400
        );
    }

    $pdo = create_database_connection();
    $userId = current_user_id($pdo);

    if ($userId === null) {
        send_json_error('not_signed_in', 'This needs a signed-in account.', 401);
    }

    /*
     * Die eine Zeile, um die es hier geht. Sie steht vor jeder Arbeit: wer kein
     * Admin ist, laesst hier nichts anfassen, egal was der Browser geschickt
     * hat. Die Antwort ist 403 und nicht 404 - die Frage war berechtigt, die
     * Antwort ist "nein".
     */
    if (!user_is_admin($pdo, $userId)) {
        send_json_error('forbidden', 'This action needs an administrator account.', 403);
    }

    $result = create_database_backup();

    if ($result['ok'] !== true) {
        /* Der genaue Grund steht im Server-Protokoll (siehe backup_service.php);
           hier gibt es eine Meldung, die jeder lesen kann. */
        send_json_error('backup_failed', 'The backup could not be created.', 500);
    }

    send_json_success([
        'file' => $result['file'],
        'bytes' => $result['bytes'],
        'created_at' => $result['created_at'],
    ]);
}
