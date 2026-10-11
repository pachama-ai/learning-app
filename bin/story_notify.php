<?php

declare(strict_types=1);

/**
 * Die Benachrichtigung „du bist dran" verschicken.
 *
 *   php bin/story_notify.php            (dasselbe wie --dry-run)
 *   php bin/story_notify.php --dry-run  zeigt nur, was ansteht: nichts wird geschickt
 *   php bin/story_notify.php --execute  verschickt sie und setzt den Merker
 *
 * Gedacht für den Cron, jede Minute:
 *
 *   * * * * * cd /pfad/zum/projekt && /usr/bin/php bin/story_notify.php --execute >/dev/null 2>&1
 *
 * Warum jede Minute, obwohl nur alle paar Tage etwas ansteht: der Zug wechselt zu einem
 * beliebigen Zeitpunkt (zwei Stunden nach dem letzten Beitrag), und ein Cron, der nur
 * stündlich läuft, würde die Mail bis zu einer Stunde später schicken.
 *
 * Was passiert
 * ------------
 * Es wird genau EINE Sache geprüft: hat der letzte Beitrag seine Bearbeitungszeit hinter
 * sich, und wurde für ihn schon benachrichtigt? Wenn ja, geht eine Mail an die andere
 * Person, und der Merker wird gesetzt (siehe src/services/story_notification_service.php).
 * Beiträge werden nicht geändert und nicht gelöscht.
 *
 * Ausgänge: 0 = alles in Ordnung (auch wenn nichts zu tun war), 1 = es stimmt etwas nicht
 * (Versand fehlgeschlagen, Merkspalte fehlt, Datenbank nicht erreichbar). Cron schickt bei
 * einem Ausgang ungleich 0 eine Mail an den Benutzer - und genau dann soll es das auch.
 */

if (PHP_SAPI !== 'cli') {
    /* Kein Aufruf über den Webserver: dieses Skript gehört nicht ins Netz. */
    exit(1);
}

require_once __DIR__ . '/../src/config/database.php';
require_once __DIR__ . '/../src/services/story_notification_service.php';

$execute = in_array('--execute', $argv, true);

if (in_array('--help', $argv, true)) {
    echo "Benutzung: php bin/story_notify.php [--dry-run|--execute]\n";

    exit(0);
}

echo "Story Together - Benachrichtigung \"du bist dran\"\n";
echo 'Zeit (UTC): ' . gmdate('Y-m-d H:i:s') . "\n\n";

try {
    $pdo = create_database_connection();
} catch (Throwable $error) {
    fwrite(STDERR, "Die Datenbank ist nicht erreichbar.\n");

    exit(1);
}

if (story_notification_ready($pdo) !== true) {
    fwrite(
        STDERR,
        "Die Spalte turn_notified_at fehlt in story_contributions.\n"
        . "Bitte zuerst database/story_turn.sql in phpMyAdmin ausführen.\n"
    );

    exit(1);
}

$status = story_notification_status($pdo);

if ($status['due'] !== true) {
    echo "Nichts zu tun.\n";
    echo 'Grund: ' . story_notification_reason_text((string) $status['reason']) . "\n";

    exit(0);
}

echo 'Letzter Beitrag: id ' . (string) $status['contribution_id']
    . ', geschrieben von ' . (string) $status['writer']
    . ', am ' . (string) $status['day'] . "\n";
echo 'Benachrichtigung an: ' . (string) $status['email']
    . ' (' . (string) $status['recipient'] . ")\n";

if ($execute !== true) {
    echo "\nTROCKENLAUF: Es wurde nichts verschickt und nichts vermerkt.\n";

    exit(0);
}

$result = story_notify_due($pdo);

if ($result['sent'] === true) {
    echo "\nVerschickt. Der Merker steht auf diesem Beitrag.\n";

    exit(0);
}

fwrite(STDERR, "\nDie Mail konnte nicht verschickt werden.\n");
fwrite(STDERR, 'Grund: ' . story_notification_error_text((string) $result['error']) . "\n");

exit(1);

/**
 * Den Code des Service in einen Satz fürs Terminal bringen.
 *
 * Diese Sätze sieht nur, wer das Skript von Hand oder im Cron aufruft - nicht der Nutzer im
 * Browser. Sie stehen deshalb hier und nicht in den Übersetzungen.
 *
 * @param string $reason
 * @return string
 */
function story_notification_reason_text(string $reason): string
{
    if ($reason === 'no_contribution') {
        return 'Es gibt noch keinen Beitrag.';
    }

    if ($reason === 'window_open') {
        return 'Die Bearbeitungszeit des letzten Beitrags läuft noch.';
    }

    if ($reason === 'already_notified') {
        return 'Für diesen Beitrag wurde schon benachrichtigt.';
    }

    if ($reason === 'no_email') {
        return 'Das Konto, das dran wäre, hat keine E-Mail-Adresse in der Tabelle users.';
    }

    if ($reason === 'unknown_writer') {
        return 'Der letzte Beitrag gehört zu keinem Konto aus src/helpers/story_writers.php.';
    }

    if ($reason === 'no_recipient') {
        return 'Es gibt keine zweite Person in src/helpers/story_writers.php.';
    }

    return $reason;
}

/**
 * Dasselbe für einen Fehler des Versands.
 *
 * @param string $error
 * @return string
 */
function story_notification_error_text(string $error): string
{
    if ($error === 'not_configured') {
        return 'Der Versand ist in .env nicht eingerichtet (MAIL_HOST, MAIL_USER, MAIL_PASS).';
    }

    if ($error === 'connect_failed') {
        return 'Die Verbindung zum Mailserver kam nicht zustande '
            . '(MAIL_HOST, MAIL_PORT und MAIL_SECURE in .env prüfen).';
    }

    if ($error === 'invalid_recipient') {
        return 'Die Empfängeradresse ist keine gültige Adresse.';
    }

    if ($error === 'send_failed') {
        return 'Der Mailserver hat die Nachricht abgelehnt. Einzelheiten stehen im Fehlerprotokoll.';
    }

    return $error === '' ? 'unbekannt' : $error;
}
