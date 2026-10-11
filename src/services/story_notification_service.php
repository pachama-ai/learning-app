<?php

declare(strict_types=1);

/**
 * Die Benachrichtigung „du bist dran" für die gemeinsame Geschichte.
 *
 * Wann sie rausgeht
 * -----------------
 * Genau dann, wenn die Bearbeitungszeit des letzten Beitrags abgelaufen ist - also in dem
 * Moment, in dem der Zug wechselt. NICHT beim Veröffentlichen: zwei Stunden lang darf der
 * Verfasser seinen Text noch ändern, und eine Mail „jetzt bist du dran" wäre in dieser Zeit
 * schlicht falsch.
 *
 * Genau einmal
 * ------------
 * Der Merker steht in `story_contributions.turn_notified_at` (die Spalte kommt mit
 * database/story_turn.sql). Erst reservieren, dann verschicken, bei einem Fehler den Merker
 * wieder freigeben: so geht dieselbe Mail nicht zweimal raus, wenn zwei Läufe gleichzeitig
 * anfangen, und ein fehlgeschlagener Versand wird beim nächsten Lauf wiederholt. Lieber
 * eine Mail zu viel als eine, die nie kommt.
 *
 * Wer sie bekommt
 * ---------------
 * Die andere Person als der Verfasser des letzten Beitrags. Die Adresse kommt aus
 * `users.email` - die Liste der Schreiber (src/helpers/story_writers.php) führt nur Ids und
 * Rollen, und welche Adresse zu einem Konto gehört, steht in der Datenbank. Eine zweite
 * Liste im Quelltext wäre eine zweite Wahrheit, und die erste Adressänderung würde sie
 * falsch machen.
 *
 * Was diese Datei NICHT tut: sie ändert keinen Beitrag. Sie liest den letzten, schickt eine
 * Mail und setzt einen Zeitstempel.
 */

require_once __DIR__ . '/story_service.php';
require_once __DIR__ . '/../helpers/mailer.php';
/* Die Sätze der Mail kommen aus den Übersetzungen wie jeder andere sichtbare Text -
   deshalb lädt diese Datei sie, obwohl story_service.php sie nicht braucht. */
require_once __DIR__ . '/../helpers/translations.php';

/**
 * Die Sprache der Benachrichtigung.
 *
 * Die Sätze kommen aus src/helpers/translations.php wie jeder andere sichtbare Text, und
 * dort gibt es sie in beiden Sprachen. Welche für die Empfängerin gilt, weiß der Server
 * nicht: die Wahl liegt im localStorage des Browsers, und die Mail wird geschrieben, wenn
 * womöglich niemand einen Browser offen hat. Deshalb steht sie hier fest - Deutsch, weil die
 * beiden Schreiberinnen deutsch schreiben. Englisch wäre eine Zeile Änderung.
 */
const STORY_MAIL_LANGUAGE = 'de';

/**
 * Ist die Merkspalte schon da?
 *
 * Ohne diese Prüfung würde der ganze Lauf an einer fehlenden Spalte scheitern, und die
 * Meldung wäre ein Datenbankfehler statt eines Hinweises auf database/story_turn.sql.
 *
 * @param PDO $pdo
 * @return bool
 */
function story_notification_ready(PDO $pdo): bool
{
    $statement = $pdo->prepare(
        'SELECT COUNT(*)
           FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = :table
            AND COLUMN_NAME = :column'
    );

    $statement->bindValue(':table', 'story_contributions', PDO::PARAM_STR);
    $statement->bindValue(':column', 'turn_notified_at', PDO::PARAM_STR);
    $statement->execute();

    return (int) $statement->fetchColumn() > 0;
}

/**
 * Der letzte Beitrag der Geschichte, so wie er in der Datenbank steht.
 *
 * Sortiert wird nach Zeitpunkt und dann nach Id: der Tag ist kein Sortiermerkmal mehr, seit
 * der Zug nicht mehr am Kalendertag hängt.
 *
 * @param PDO $pdo
 * @return array<string, mixed>|null
 */
function story_notification_last_row(PDO $pdo): ?array
{
    $statement = $pdo->prepare(
        'SELECT id, user_id, created_at, turn_notified_at
           FROM story_contributions
          WHERE story_id = :story_id
          ORDER BY created_at DESC, id DESC
          LIMIT 1'
    );

    $statement->bindValue(':story_id', STORY_ID, PDO::PARAM_INT);
    $statement->execute();

    $row = $statement->fetch();

    return $row === false ? null : $row;
}

/**
 * Die E-Mail-Adresse eines Schreibers.
 *
 * @param PDO $pdo
 * @param string $writerKey 'selina' oder 'xenia'
 * @return string|null null, wenn das Konto keine Adresse hat
 */
function story_notification_email(PDO $pdo, string $writerKey): ?string
{
    /* Umgekehrt suchen: die Liste geht von der Id zur Rolle, hier wird die Id zu einer Rolle
       gesucht. Genau deshalb gibt es sie nur einmal. */
    $userId = array_search($writerKey, STORY_WRITERS, true);

    if ($userId === false) {
        return null;
    }

    $statement = $pdo->prepare('SELECT email FROM users WHERE id = :id LIMIT 1');
    $statement->bindValue(':id', (int) $userId, PDO::PARAM_INT);
    $statement->execute();

    $email = $statement->fetchColumn();

    if (!is_string($email)) {
        return null;
    }

    $email = trim($email);

    return $email === '' ? null : $email;
}

/**
 * Steht eine Benachrichtigung an - und wenn nicht, warum nicht?
 *
 * `due` heißt: der letzte Beitrag ist älter als die Bearbeitungszeit, und für ihn wurde noch
 * niemand benachrichtigt. Alles andere ist eine Auskunft für den Aufrufer (das
 * Kommandozeilen-Werkzeug zeigt sie an) und kein Fehler.
 *
 * @param PDO $pdo
 * @param DateTimeImmutable|null $now nur für Tests
 * @return array<string, mixed>
 */
function story_notification_status(PDO $pdo, ?DateTimeImmutable $now = null): array
{
    if (story_notification_ready($pdo) !== true) {
        return ['due' => false, 'reason' => 'column_missing', 'recipient' => null, 'writer' => null,
            'contribution_id' => null, 'day' => null, 'email' => null];
    }

    $last = story_notification_last_row($pdo);

    if ($last === null) {
        return ['due' => false, 'reason' => 'no_contribution', 'recipient' => null, 'writer' => null,
            'contribution_id' => null, 'day' => null, 'email' => null];
    }

    $contributionId = (int) $last['id'];
    $writerKey = story_writer_key((int) $last['user_id']);
    $day = story_local_day((string) $last['created_at']);
    $base = ['recipient' => null, 'writer' => $writerKey, 'contribution_id' => $contributionId,
        'day' => $day, 'email' => null];

    if ($writerKey === null) {
        /* Ein Beitrag eines Kontos, das nicht mehr in der Liste steht: dann weiß niemand,
           wer als Nächstes dran ist, und es wird niemand benachrichtigt. */
        return ['due' => false, 'reason' => 'unknown_writer'] + $base;
    }

    if ($last['turn_notified_at'] !== null) {
        return ['due' => false, 'reason' => 'already_notified'] + $base;
    }

    /* Dieselbe Frage wie überall: läuft die Bearbeitungszeit noch? Sie läuft, solange der
       Beitrag jünger als STORY_EDIT_HOURS ist - dann ist niemand dran. */
    $contribution = ['at' => story_local_iso((string) $last['created_at'])];

    if (story_edit_window_open($contribution, $now) === true) {
        return ['due' => false, 'reason' => 'window_open'] + $base;
    }

    $recipient = story_other_writer($writerKey);

    if ($recipient === null) {
        return ['due' => false, 'reason' => 'no_recipient'] + $base;
    }

    $email = story_notification_email($pdo, $recipient);

    return [
        'due' => $email !== null,
        'reason' => $email === null ? 'no_email' : 'due',
        'recipient' => $recipient,
        'writer' => $writerKey,
        'contribution_id' => $contributionId,
        'day' => $day,
        'email' => $email,
    ];
}

/**
 * Die Benachrichtigung als Betreff und Text.
 *
 * Die Sätze stehen in src/helpers/translations.php; hier werden nur die Platzhalter gefüllt:
 * Name der Empfängerin, Name der Verfasserin, der Tag des Beitrags und der Link zur Seite.
 *
 * @param array<string, mixed> $status der Stand aus story_notification_status()
 * @return array{subject: string, body: string}
 */
function story_notification_message(array $status): array
{
    $language = STORY_MAIL_LANGUAGE;
    $recipient = (string) $status['recipient'];

    $url = '/story.php';
    $base = mail_settings()['base_url'];

    if ($base !== '') {
        $url = $base . $url;
    }

    $day = (string) $status['day'];

    /* Ein Datum ist kein Satz, aber seine Schreibweise hängt an der Sprache: im Deutschen
       Tag.Monat.Jahr, im Englischen die Reihenfolge Jahr-Monat-Tag. */
    $formattedDay = $day === ''
        ? ''
        : (new DateTimeImmutable($day))->format($language === 'de' ? 'd.m.Y' : 'Y-m-d');

    return [
        'subject' => t($language, 'story.mail.subject'),
        'body' => t_fill($language, 'story.mail.body', [
            'name' => t($language, 'story.author.' . $recipient),
            'author' => t($language, 'story.author.' . (string) $status['writer']),
            'day' => $formattedDay,
            'url' => $url,
        ]),
    ];
}

/**
 * Die fällige Benachrichtigung verschicken - und den Merker setzen.
 *
 * Reihenfolge mit Absicht: erst reservieren, dann verschicken, bei einem Fehler wieder
 * freigeben. Zwei gleichzeitige Läufe können so nicht dieselbe Mail doppelt schicken, und
 * ein misslungener Versand wird beim nächsten Lauf wiederholt.
 *
 * @param PDO $pdo
 * @param DateTimeImmutable|null $now nur für Tests
 * @return array<string, mixed> der Stand, ergänzt um 'sent' und 'error'
 */
function story_notify_due(PDO $pdo, ?DateTimeImmutable $now = null): array
{
    $status = story_notification_status($pdo, $now);

    if ($status['due'] !== true) {
        return $status + ['sent' => false, 'error' => null];
    }

    if (mail_is_configured() !== true) {
        /* Nichts ist eingerichtet: dann wird auch nichts vermerkt. Sobald die Werte in .env
           stehen, holt der nächste Lauf die Benachrichtigung nach. */
        return $status + ['sent' => false, 'error' => 'not_configured'];
    }

    $reserve = $pdo->prepare(
        'UPDATE story_contributions
            SET turn_notified_at = UTC_TIMESTAMP()
          WHERE id = :id
            AND turn_notified_at IS NULL'
    );

    $reserve->bindValue(':id', (int) $status['contribution_id'], PDO::PARAM_INT);
    $reserve->execute();

    if ($reserve->rowCount() !== 1) {
        /* Jemand war schneller - dann war die Mail schon unterwegs. */
        return ['due' => true, 'reason' => 'already_notified', 'recipient' => $status['recipient'],
            'writer' => $status['writer'], 'contribution_id' => $status['contribution_id'],
            'day' => $status['day'], 'email' => $status['email'],
            'sent' => false, 'error' => null];
    }

    $message = story_notification_message($status);
    $result = mail_send((string) $status['email'], $message['subject'], $message['body']);

    if ($result['ok'] !== true) {
        $release = $pdo->prepare(
            'UPDATE story_contributions SET turn_notified_at = NULL WHERE id = :id'
        );

        $release->bindValue(':id', (int) $status['contribution_id'], PDO::PARAM_INT);
        $release->execute();

        return $status + ['sent' => false, 'error' => $result['error']];
    }

    return $status + ['sent' => true, 'error' => null];
}
