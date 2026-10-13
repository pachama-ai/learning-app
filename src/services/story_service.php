<?php

declare(strict_types=1);

/**
 * Die gemeinsame Geschichte: lesen, prüfen, schreiben.
 *
 * Alle Regeln dieser Funktion stehen hier und nirgends sonst, und sie gelten
 * serverseitig. Der Browser darf Schaltflächen ausblenden - verlassen kann man sich
 * darauf nicht: eine POST-Anfrage kann jederzeit von Hand abgeschickt werden.
 *
 * Die Regeln
 * ----------
 *   1. Schreiben dürfen nur die beiden Konten aus src/helpers/story_writers.php.
 *   2. Nach dem Veröffentlichen bleiben STORY_EDIT_MINUTES Minuten Bearbeitungszeit.
 *      Solange darf nur der Verfasser selbst seinen Text noch ändern, und die andere
 *      Person wartet.
 *   3. Danach ist der Beitrag endgültig, und der Zug wechselt zur anderen Person. Es wird
 *      abwechselnd geschrieben; wer zuletzt geschrieben hat, ist als Nächster nicht mehr
 *      dran.
 *   4. Den ersten Beitrag darf schreiben, wer zuerst kommt - er legt damit fest, wer
 *      als Nächstes dran ist.
 *   5. Wer eine Zeit lang nicht schreibt, verliert seinen Platz nicht. Es gibt keine
 *      Pflicht, jeden Tag zu schreiben, und keinen Zug, der verfällt: er bleibt stehen,
 *      bis jemand schreibt.
 *   6. Nach Ablauf der Bearbeitungszeit ist ein Beitrag unveränderlich. Es gibt in dieser
 *      Datei keine Anweisung, die einen Beitrag löscht.
 *
 * Wie die Regeln halten
 * ---------------------
 * Die Anwendung prüft (Schritt 2 der Veröffentlichung), die Datenbank sichert ab:
 *
 *   * `SELECT ... FOR UPDATE` auf die Zeile der Geschichte lässt zwei gleichzeitige
 *     Anfragen nacheinander laufen statt nebeneinander. Die zweite sieht die erste und
 *     weiß damit, dass gerade eben geschrieben wurde.
 *   * Den eindeutigen Schlüssel über den Kalendertag gab es einmal; mit der
 *     Bearbeitungszeit passt er nicht mehr und ist entfernt (database/story_turn.sql).
 *     Die Sperre oben ist damit die einzige Absicherung - und sie trägt, weil jede
 *     Veröffentlichung und jede Änderung zuerst diese eine Zeile der Geschichte berührt.
 *
 * Die Zeiten
 * ----------
 * `created_at` liegt in UTC in der Datenbank (`UTC_TIMESTAMP()`), damit ein Umzug des
 * Servers nichts verschiebt. Die Bearbeitungszeit rechnet deshalb in Zeitpunkten: 
 * `created_at` plus 30 Minuten. Der Kalendertag eines Beitrags (`contribution_date`) ist
 * dagegen der Tag in Europe/Berlin - er entscheidet jetzt nur noch darüber, unter welchem
 * Datum ein Beitrag in der Anzeige steht. Nach außen gehen beide Angaben mit: der Tag als
 * 'YYYY-MM-DD' und der Zeitpunkt als ISO-Zeichenkette mit Versatz (für die Anzeige).
 */

require_once __DIR__ . '/../helpers/story_writers.php';
require_once __DIR__ . '/../data/grammar_tasks.php';

/** Es gibt genau eine Geschichte, und sie hat den festen Platz 1. */
const STORY_ID = 1;

/** Der Titel, mit dem die Geschichte beginnt. Änderbar ist er später über stories.title. */
const STORY_DEFAULT_TITLE = 'The Unexpected Journey';

/** Längster erlaubter Titel (die Spalte ist varchar(200)). */
const STORY_MAX_TITLE_LENGTH = 200;

/**
 * Längster erlaubter Beitrag in Zeichen.
 *
 * Es gibt KEINE Mindestlänge: drei Sätze sind so willkommen wie ein langer Abschnitt.
 * Die Grenze ist rein technisch - sie hält einen versehentlich eingefügten Riesentext
 * aus der Datenbank und aus der Anzeige.
 */
const STORY_MAX_CONTENT_LENGTH = 5000;

/**
 * So lange darf ein veröffentlichter Beitrag noch geändert werden.
 *
 * Die Frist hängt am Zeitpunkt der VERÖFFENTLICHUNG (`created_at`) und nicht am letzten
 * Ändern: sonst könnte man sie durch Ändern beliebig verlängern, und die andere Person
 * würde nie an die Reihe kommen. Erst wenn die Frist abgelaufen ist, ist der Beitrag
 * endgültig und der Zug wechselt.
 *
 * An dieser einen Zahl hängt auch die Benachrichtigung: `story_notification_status()`
 * fragt `story_edit_window_open()`, und das rechnet mit dieser Frist. Der Cron
 * (bin/story_notify.php) läuft jede Minute, die Mail geht also kurz nach der halben
 * Stunde raus - nicht erst nach zwei Stunden.
 *
 * Bis zum 13.10.2026 waren es zwei Stunden.
 */
const STORY_EDIT_MINUTES = 30;

/**
 * Nach so vielen Kalendertagen wechselt die Grammar Challenge zur nächsten Zeitform.
 *
 * Die Aufgabe hängt damit allein am Datum: beide Nutzer sehen am selben Tag dieselbe,
 * ein Neuladen ändert nichts, und es muss nichts gespeichert werden (siehe
 * story_challenge_for).
 */
const STORY_CHALLENGE_DAYS = 2;

/** Die Zeitzone, in der "heute" gilt. */
const STORY_TIMEZONE = 'Europe/Berlin';

/**
 * Die Zeitzone als Objekt.
 *
 * @return DateTimeZone
 */
function story_zone(): DateTimeZone
{
    return new DateTimeZone(STORY_TIMEZONE);
}

/**
 * Der Zeitpunkt, der als "jetzt" gilt.
 *
 * Der Parameter ist nur für Tests da: damit lässt sich ein beliebiger Zeitpunkt einsetzen,
 * ohne an der Systemuhr zu drehen. Weil die Bearbeitungszeit Zeitpunkte vergleicht und
 * keine Kalendertage, reicht dieser eine Wert als Grundlage für alle Rechnungen - die
 * Zeitzone spielt dabei keine Rolle.
 *
 * @param DateTimeImmutable|null $now
 * @return DateTimeImmutable
 */
function story_now(?DateTimeImmutable $now = null): DateTimeImmutable
{
    return $now === null ? new DateTimeImmutable('now', story_zone()) : $now;
}

/**
 * Welcher Kalendertag in Europe/Berlin gerade ist.
 *
 * @param DateTimeImmutable|null $now nur für Tests
 * @return string 'YYYY-MM-DD'
 */
function story_today(?DateTimeImmutable $now = null): string
{
    return story_now($now)->setTimezone(story_zone())->format('Y-m-d');
}

/**
 * Alle Beiträge der Geschichte, in der Reihenfolge, in der sie gelesen werden.
 *
 * Sortiert wird nach Kalendertag und dann nach Id. Der zweite Teil ist kein Zierrat: seit
 * der Zug nach der Bearbeitungszeit wechselt, können zwei Beiträge am selben Tag entstehen,
 * und dann entscheidet die Id über die Reihenfolge - sie wächst mit dem Zeitpunkt, an dem
 * geschrieben wurde.
 *
 * @param PDO $pdo
 * @param int $storyId
 * @return list<array<string, mixed>>
 */
function story_contributions(PDO $pdo, int $storyId): array
{
    $statement = $pdo->prepare(
        'SELECT id, user_id, content, word_count, contribution_date, created_at
           FROM story_contributions
          WHERE story_id = :story_id
          ORDER BY contribution_date ASC, id ASC'
    );

    $statement->bindValue(':story_id', $storyId, PDO::PARAM_INT);
    $statement->execute();

    $contributions = [];

    foreach ($statement as $row) {
        $writer = story_writer_key((int) $row['user_id']);

        /*
         * Ein Beitrag eines Kontos, das nicht mehr in der Liste steht, wird NICHT
         * weggelassen: die Geschichte wäre dann lückenhaft. Er bekommt die Rolle null und
         * wird in der Anzeige als unbekannt geführt - der Fall kann nur entstehen, wenn
         * jemand die Liste ändert, und dann soll man es sehen.
         */
        $contributions[] = [
            'id' => (int) $row['id'],
            'writer' => $writer,
            'date' => (string) $row['contribution_date'],
            'at' => story_local_iso((string) $row['created_at']),
            'content' => (string) $row['content'],
            'words' => (int) $row['word_count'],
        ];
    }

    return $contributions;
}

/**
 * Einen in UTC gespeicherten Zeitpunkt als Ortszeit in Europe/Berlin.
 *
 * Das Ergebnis ist eine ISO-Zeichenkette mit Versatz ('2026-10-11T13:05:00+02:00').
 * JavaScript liest sie mit new Date(...) und formatiert sie in der Sprache der Seite -
 * die Anzeige hängt also nicht am Server.
 *
 * @param string $utc 'YYYY-MM-DD HH:MM:SS'
 * @return string
 */
function story_local_iso(string $utc): string
{
    $moment = story_utc_to_local($utc);

    return $moment === null ? '' : $moment->format('c');
}

/**
 * Einen in UTC gespeicherten Zeitpunkt als Ortszeit in Europe/Berlin.
 *
 * @param string $utc 'YYYY-MM-DD HH:MM:SS'
 * @return DateTimeImmutable|null null, wenn der Wert nicht lesbar ist
 */
function story_utc_to_local(string $utc): ?DateTimeImmutable
{
    $moment = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $utc, new DateTimeZone('UTC'));

    if ($moment === false) {
        /* Sollte nicht vorkommen; ein unlesbarer Wert darf die Seite nicht zerlegen. */
        return null;
    }

    return $moment->setTimezone(story_zone());
}

/**
 * Der Berliner Kalendertag eines in UTC gespeicherten Zeitpunkts.
 *
 * @param string $utc 'YYYY-MM-DD HH:MM:SS'
 * @return string|null 'YYYY-MM-DD' oder null, wenn der Wert nicht lesbar ist
 */
function story_local_day(string $utc): ?string
{
    $moment = story_utc_to_local($utc);

    return $moment === null ? null : $moment->format('Y-m-d');
}

/**
 * Wie viele Kalendertage zwischen zwei Tagen liegen.
 *
 * @param string $from 'YYYY-MM-DD'
 * @param string $to 'YYYY-MM-DD'
 * @return int nie kleiner als null
 */
function story_day_distance(string $from, string $to): int
{
    $start = new DateTimeImmutable($from . ' 00:00:00', new DateTimeZone('UTC'));
    $end = new DateTimeImmutable($to . ' 00:00:00', new DateTimeZone('UTC'));
    $days = (int) $start->diff($end)->days;

    /* Liegt der Start in der Zukunft (etwa nach einem Tippfehler im Datum), bleibt es bei
       der ersten Aufgabe statt bei einer negativen Stelle in der Liste. */
    return $to < $from ? 0 : $days;
}

/**
 * Bis wann ein Beitrag noch geändert werden darf.
 *
 * Gerechnet wird ab dem Zeitpunkt der Veröffentlichung. Der steht als 'at' im Beitrag
 * (ISO-Zeichenkette mit Versatz, siehe story_local_iso), und weil ein Zeitpunkt samt
 * Versatz eindeutig ist, braucht es dafür keine zweite Angabe.
 *
 * Ist der Wert unlesbar, gibt es keine Frist - und keine Frist heißt hier: nicht mehr
 * änderbar. Ein zu strenger Fehler ist harmlos, ein zu nachsichtiger nicht.
 *
 * @param array<string, mixed> $contribution
 * @return DateTimeImmutable|null
 */
function story_edit_deadline(array $contribution): ?DateTimeImmutable
{
    $at = (string) ($contribution['at'] ?? '');

    if ($at === '') {
        return null;
    }

    try {
        $published = new DateTimeImmutable($at);
    } catch (Exception $error) {
        return null;
    }

    return $published->add(new DateInterval('PT' . STORY_EDIT_MINUTES . 'M'));
}

/**
 * Läuft die Bearbeitungszeit dieses Beitrags noch?
 *
 * @param array<string, mixed> $contribution
 * @param DateTimeImmutable|null $now nur für Tests
 * @return bool
 */
function story_edit_window_open(array $contribution, ?DateTimeImmutable $now = null): bool
{
    $deadline = story_edit_deadline($contribution);

    if ($deadline === null) {
        return false;
    }

    return story_now($now) < $deadline;
}

/**
 * Die eine Geschichte, angelegt beim ersten Aufruf.
 *
 * `INSERT ... ON DUPLICATE KEY UPDATE id = id` ist der Grund, warum das auch bei zwei
 * gleichzeitigen Anfragen genau eine Zeile ergibt: die zweite Anweisung tut dann nichts.
 *
 * @param PDO $pdo
 * @return array<string, mixed>
 */
function story_load_or_create(PDO $pdo): array
{
    $select = $pdo->prepare('SELECT id, title, created_at, updated_at FROM stories WHERE id = :id');
    $select->bindValue(':id', STORY_ID, PDO::PARAM_INT);
    $select->execute();

    $story = $select->fetch();

    if ($story !== false) {
        return $story;
    }

    $insert = $pdo->prepare(
        'INSERT INTO stories (id, title, created_at, updated_at)
         VALUES (:id, :title, UTC_TIMESTAMP(), UTC_TIMESTAMP())
         ON DUPLICATE KEY UPDATE id = id'
    );

    $insert->bindValue(':id', STORY_ID, PDO::PARAM_INT);
    $insert->bindValue(':title', STORY_DEFAULT_TITLE, PDO::PARAM_STR);
    $insert->execute();

    $select->execute();

    return $select->fetch();
}

/**
 * Wer wann schreiben darf.
 *
 * DER ZUG HÄNGT AM LETZTEN BEITRAG
 * --------------------------------
 * Nach dem Veröffentlichen bleiben STORY_EDIT_MINUTES Minuten Bearbeitungszeit. Solange
 * liegt der Zug beim Verfasser: er darf seinen Text noch ändern, und die andere Person
 * wartet. Ist die Zeit um, ist der Beitrag endgültig und der Zug geht zur anderen Person.
 *
 *     letzter Beitrag + 30 Minuten   -> noch der Verfasser (er darf nur noch ändern)
 *     danach                         -> die andere Person ist an der Reihe
 *
 * Gerechnet wird in Zeitpunkten, nicht in Kalendertagen: wer um 9 Uhr schreibt, gibt um
 * 9:30 Uhr ab; wer um 23:50 Uhr schreibt, gibt um 0:20 Uhr nachts ab. Ein Kalendertag
 * hätte für den einen nichts und für den anderen alles bedeutet.
 *
 * Wer nicht schreibt, verliert seinen Zug nicht: der Zug bleibt stehen, bis jemand
 * schreibt. Dieselbe Person kann deshalb mehrere Beiträge hintereinander schreiben, wenn
 * die andere pausiert - die Geschichte geht weiter, und der Zug bleibt gerecht.
 *
 * Solange noch kein Beitrag da ist, ist `turn_writer` null: dann darf schreiben, wer
 * zuerst kommt, und der erste Beitrag legt damit den Erst-Autor fest.
 *
 * Diese Funktion rechnet und schreibt nicht: sie bekommt die Beiträge und den Zeitpunkt und
 * sagt, was daraus folgt. Dadurch lässt sie sich mit jedem beliebigen Zeitpunkt aufrufen -
 * der Ablauf der Bearbeitungszeit ist damit prüfbar, ohne 30 Minuten zu warten.
 *
 * @param list<array<string, mixed>> $contributions
 * @param DateTimeImmutable|null $now nur für Tests
 * @param string|null $writerKey die Rolle, die gerade fragt
 * @return array<string, mixed>
 */
function story_state(array $contributions, ?DateTimeImmutable $now, ?string $writerKey): array
{
    $last = $contributions === [] ? null : $contributions[count($contributions) - 1];
    $windowOpen = $last !== null && story_edit_window_open($last, $now);
    $turn = null;

    if ($last !== null) {
        $turn = $windowOpen
            ? (string) $last['writer']
            : story_other_writer((string) $last['writer']);
    }

    $reason = null;

    if ($writerKey === null) {
        $reason = 'not_a_writer';
    } elseif ($last !== null && $turn !== $writerKey) {
        /* Zwei Fälle, dieselbe Antwort: entweder läuft die Bearbeitungszeit der anderen
           Person noch, oder sie ist gerade abgelaufen - in beiden Fällen wartet man. */
        $reason = 'not_your_turn';
    } elseif ($windowOpen) {
        /* Man hat gerade selbst geschrieben. Ein NEUER Beitrag ist damit nicht möglich;
           ändern lässt sich der eigene aber noch (story_editable_id). */
        $reason = 'edit_window_open';
    }

    $deadline = $last === null ? null : story_edit_deadline($last);

    return [
        'has_contributions' => $last !== null,
        'last_date' => $last === null ? null : (string) $last['date'],
        'last_writer' => $last === null ? null : $last['writer'],
        /* Wer JETZT schreiben darf - derselbe Wert, den auch die Kachel der Startseite
           zeigt. Während der Bearbeitungszeit ist das der Verfasser: schreiben darf in
           dieser Zeit niemand, aber er ist der Einzige, der hier noch etwas zu tun hat. */
        'turn_writer' => $turn,
        'edit_window_open' => $windowOpen,
        /* Bis wann geändert werden darf. Für beide derselbe Wert, damit auch die wartende
           Person sieht, wann es weitergeht. */
        'edit_deadline' => $windowOpen && $deadline !== null ? $deadline->format('c') : null,
        'can_write' => $reason === null,
        'blocked_reason' => $reason,
    ];
}

/**
 * Wie viele Wörter ein Text hat.
 *
 * Gezählt werden die Stücke zwischen den Leerzeichen, die mindestens einen Buchstaben
 * oder eine Ziffer enthalten. Ein Gedankenstrich allein oder eine Reihe von Punkten ist
 * also kein Wort. Genau dieselbe Regel steht in public/assets/js/story.js - die Anzeige
 * beim Tippen und die gespeicherte Zahl müssen übereinstimmen.
 *
 * @param string $text
 * @return int
 */
function story_count_words(string $text): int
{
    $tokens = preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY);

    if ($tokens === false) {
        return 0;
    }

    $count = 0;

    foreach ($tokens as $token) {
        if (preg_match('/[\p{L}\p{N}]/u', $token) === 1) {
            $count++;
        }
    }

    return $count;
}

/**
 * Einen eingetippten Beitrag in die Form bringen, in der er gespeichert wird.
 *
 * Absätze und Zeilenumbrüche bleiben erhalten - sie gehören zum Text und werden später
 * genau so wieder angezeigt. Aufgeräumt wird nur, was offensichtlich nicht gemeint ist:
 * Zeilenenden des Betriebssystems, mehr als eine Leerzeile zwischen zwei Absätzen, und
 * Leerraum am Anfang und am Ende.
 *
 * @param string $content
 * @return string
 */
function story_normalise_content(string $content): string
{
    $text = str_replace(["\r\n", "\r"], "\n", $content);
    $text = preg_replace("/\n{3,}/u", "\n\n", $text);

    return trim((string) $text);
}

/**
 * Einen Beitrag veröffentlichen.
 *
 * Der ganze Vorgang läuft in einer Transaktion: die Zeile der Geschichte wird gesperrt,
 * die Beiträge werden INNERHALB der Sperre gelesen, geprüft und geschrieben. Entweder
 * ist der Beitrag danach vollständig da oder gar nicht.
 *
 * @param PDO $pdo
 * @param int $userId die Id aus der Sitzung
 * @param string $content der eingetippte Text
 * @param DateTimeImmutable|null $now nur für Tests
 * @return array{ok: bool, code: string|null, contribution: array<string, mixed>|null}
 */
function story_publish(PDO $pdo, int $userId, string $content, ?DateTimeImmutable $now = null): array
{
    $writerKey = story_writer_key($userId);

    if ($writerKey === null) {
        return ['ok' => false, 'code' => 'not_a_writer', 'contribution' => null];
    }

    $text = story_normalise_content($content);

    if ($text === '') {
        return ['ok' => false, 'code' => 'empty_contribution', 'contribution' => null];
    }

    if (mb_strlen($text, 'UTF-8') > STORY_MAX_CONTENT_LENGTH) {
        return ['ok' => false, 'code' => 'contribution_too_long', 'contribution' => null];
    }

    $today = story_today($now);
    $story = story_load_or_create($pdo);
    $storyId = (int) $story['id'];
    $newId = 0;

    $pdo->beginTransaction();

    try {
        /* Die Sperre: zwei gleichzeitige Anfragen laufen nacheinander, nicht
           nebeneinander. Ohne sie könnten beide "du bist dran" sehen. */
        $lock = $pdo->prepare('SELECT id FROM stories WHERE id = :id FOR UPDATE');
        $lock->bindValue(':id', $storyId, PDO::PARAM_INT);
        $lock->execute();

        $contributions = story_contributions($pdo, $storyId);
        $state = story_state($contributions, $now, $writerKey);

        if ($state['can_write'] !== true) {
            $pdo->rollBack();

            return ['ok' => false, 'code' => (string) $state['blocked_reason'], 'contribution' => null];
        }

        $words = story_count_words($text);

        $insert = $pdo->prepare(
            'INSERT INTO story_contributions
                (story_id, user_id, content, word_count, contribution_date, created_at)
             VALUES
                (:story_id, :user_id, :content, :word_count, :contribution_date, UTC_TIMESTAMP())'
        );

        $insert->bindValue(':story_id', $storyId, PDO::PARAM_INT);
        $insert->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $insert->bindValue(':content', $text, PDO::PARAM_STR);
        $insert->bindValue(':word_count', $words, PDO::PARAM_INT);
        $insert->bindValue(':contribution_date', $today, PDO::PARAM_STR);
        $insert->execute();

        /*
         * Die neue Id sofort abholen, nicht am Ende: lastInsertId() gilt für die zuletzt
         * ausgeführte Anweisung, und das ist gleich die Aktualisierung der Geschichte -
         * danach ist der Wert 0.
         */
        $newId = (int) $pdo->lastInsertId();

        $update = $pdo->prepare('UPDATE stories SET updated_at = UTC_TIMESTAMP() WHERE id = :id');
        $update->bindValue(':id', $storyId, PDO::PARAM_INT);
        $update->execute();

        $pdo->commit();
    } catch (PDOException $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        /*
         * 23000 ist die Verletzung einer Bedingung der Datenbank. Hier kann es nur der
         * eindeutige Schlüssel über den Kalendertag sein - den gibt es nur noch, solange
         * database/story_turn.sql nicht ausgeführt wurde. Danach greift die Sperre oben,
         * und dieser Zweig wird nie wieder erreicht: der zweite Beitrag desselben Tages
         * scheitert dann schon an der Prüfung darüber.
         */
        if ($error->getCode() === '23000') {
            return ['ok' => false, 'code' => 'not_your_turn', 'contribution' => null];
        }

        throw $error;
    }

    /*
     * Der Zeitpunkt für die Anzeige: derselbe, der als created_at in der Datenbank steht.
     * Dort liegt die Zeit in UTC, hier wird sie nach Europe/Berlin gedreht. Wird beim Test
     * ein eigenes "jetzt" übergeben, bleibt die Angabe damit stimmig zum Tag darüber.
     */
    $moment = story_now($now);
    $at = story_local_iso($moment->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'));

    return [
        'ok' => true,
        'code' => null,
        'contribution' => [
            'id' => $newId,
            'writer' => $writerKey,
            'date' => $today,
            'at' => $at,
            'content' => $text,
            'words' => $words,
        ],
    ];
}

/**
 * Die Schreibaufgabe ("Grammar Challenge").
 *
 * Sie steht ÜBER dem Editor und ist immer zu sehen - auch dem, der heute nicht mehr
 * schreiben darf. Sie ist eine Anregung, keine Bedingung: ob sich jemand daran hält,
 * prüft niemand und soll niemand prüfen.
 *
 * WELCHE AUFGABE DRAN IST
 * -----------------------
 * Die Zeitform wechselt alle STORY_CHALLENGE_DAYS Kalendertage zur nächsten; nach der
 * letzten beginnt die Reihe von vorn. Gezählt wird ab dem Tag, an dem die Geschichte
 * angelegt wurde (stories.created_at, in Berliner Zeit).
 *
 *     Stelle = (Tage seit dem Start) / 2, ganzzahlig geteilt, dann der Rest der Liste
 *
 * Damit hängt die Aufgabe an nichts als dem Datum: dieselbe Antwort für beide Nutzer,
 * stabil über jedes Neuladen, und nach der letzten Zeitform geht es wieder von vorn. Eine
 * gespeicherte Zuweisung bräuchte es dafür nicht - und ein Zustand, den man nicht
 * speichern muss, ist ein Zustand, der nicht falsch werden kann.
 *
 * @param string $storyCreatedAt der Zeitpunkt aus stories.created_at (UTC)
 * @param string $today 'YYYY-MM-DD' in Europe/Berlin
 * @return array<string, mixed>|null null nur, wenn es keine Aufgaben gibt
 */
function story_challenge_for(string $storyCreatedAt, string $today): ?array
{
    $tasks = grammar_tasks();

    if ($tasks === []) {
        return null;
    }

    /* Die Reihenfolge kommt aus sort_order und nicht aus der Reihenfolge in der Datei:
       eine umsortierte Zeile soll die Reihe nicht durcheinanderbringen. */
    usort($tasks, static fn (array $left, array $right): int => $left['sort_order'] <=> $right['sort_order']);

    $start = story_local_day($storyCreatedAt) ?? $today;
    $passed = intdiv(story_day_distance($start, $today), STORY_CHALLENGE_DAYS);
    $task = $tasks[$passed % count($tasks)];

    return [
        'id' => (string) $task['id'],
        'topic' => (string) $task['topic'],
        'instruction_en' => (string) $task['instruction_en'],
        'example_en' => (string) $task['example_en'],
        'explanation_de' => (string) $task['explanation_de'],
        'level' => (string) $task['level'],
        'sort_order' => (int) $task['sort_order'],
    ];
}

/**
 * Wer heute schreiben darf - als Rolle, für die Anzeige.
 *
 * Diese Frage beantwortet story_state() schon fast: `turn_writer` ist die Person, die der
 * letzte Beitrag an die Reihe stellt - während der Bearbeitungszeit der Verfasser, danach
 * die andere Person. Nur solange noch kein Beitrag da ist, lässt es die Antwort offen
 * (dann darf schreiben, wer zuerst kommt) - für eine Anzeige braucht es aber
 * einen Namen. Deshalb fängt in diesem einen Fall der Erst-Autor an: der erste Eintrag in
 * src/helpers/story_writers.php.
 *
 * Wichtig: Das ist NUR eine Anzeige-Regel. Wer wirklich schreiben darf, entscheidet
 * weiterhin story_state() - und darin bleibt `turn_writer` bei einer leeren Geschichte
 * absichtlich null, weil dort beide anfangen dürfen.
 *
 * @param array<string, mixed> $state der Stand aus story_state()
 * @return string|null 'selina', 'xenia' oder null, wenn es keine Schreiber gibt
 */
function story_turn_writer(array $state): ?string
{
    if ($state['turn_writer'] !== null) {
        return (string) $state['turn_writer'];
    }

    $keys = array_values(STORY_WRITERS);

    return $keys === [] ? null : (string) $keys[0];
}

/**
 * Der Beitrag, den diese Person noch ändern darf - oder null.
 *
 * Geändert werden darf der EIGENE, ZULETZT veröffentlichte Beitrag, und nur innerhalb der
 * Bearbeitungszeit. Danach ist er endgültig - auch für den, der ihn geschrieben hat: die
 * andere Person ist dann an der Reihe, und ein nachträglicher Eingriff würde das, was
 * danach kommt, auf eine Aussage beziehen, die es nicht mehr gibt.
 *
 * Diese Prüfung gilt für die Anzeige (welcher Beitrag bekommt einen Bearbeiten-Knopf?)
 * UND für das Speichern - story_edit() fragt dieselbe Funktion. Zwei Regeln wären zwei
 * Wahrheiten.
 *
 * @param list<array<string, mixed>> $contributions
 * @param string|null $writerKey die Rolle, die gerade fragt
 * @param DateTimeImmutable|null $now nur für Tests
 * @return int|null die Id des Beitrags
 */
function story_editable_id(array $contributions, ?string $writerKey, ?DateTimeImmutable $now = null): ?int
{
    if ($writerKey === null || $contributions === []) {
        return null;
    }

    $last = $contributions[count($contributions) - 1];

    if ($last['writer'] !== $writerKey || story_edit_window_open($last, $now) !== true) {
        return null;
    }

    return (int) $last['id'];
}

/**
 * Einen veröffentlichten Beitrag nachträglich ändern.
 *
 * Geändert wird nur der TEXT. Der Kalendertag (contribution_date) bleibt, wie er war:
 * Bearbeiten ist kein neuer Beitrag, zählt also nicht als zweiter Beitrag des Tages und
 * ändert die Reihenfolge nicht. Aus demselben Grund wird auch stories.updated_at nur
 * nachgezogen, nicht die Zahl der Beiträge.
 *
 * Die Bearbeitungszeit verlängert sich dadurch NICHT: sie hängt am Zeitpunkt der
 * Veröffentlichung (siehe story_edit_deadline), sonst könnte man sie beliebig strecken.
 *
 * Die Sperre (SELECT ... FOR UPDATE) ist dieselbe wie beim Veröffentlichen: zwei
 * gleichzeitige Anfragen laufen nacheinander, und die zweite sieht, was die erste getan
 * hat. Ohne sie könnten zwei Browserfenster denselben Text überschreiben, ohne es zu
 * merken.
 *
 * @param PDO $pdo
 * @param int $userId die Id aus der Sitzung
 * @param int $contributionId der Beitrag, der geändert werden soll
 * @param string $content der neue Text
 * @param DateTimeImmutable|null $now nur für Tests
 * @return array{ok: bool, code: string|null, contribution: array<string, mixed>|null}
 */
function story_edit(PDO $pdo, int $userId, int $contributionId, string $content, ?DateTimeImmutable $now = null): array
{
    $writerKey = story_writer_key($userId);

    if ($writerKey === null) {
        return ['ok' => false, 'code' => 'not_a_writer', 'contribution' => null];
    }

    $text = story_normalise_content($content);

    if ($text === '') {
        return ['ok' => false, 'code' => 'empty_contribution', 'contribution' => null];
    }

    if (mb_strlen($text, 'UTF-8') > STORY_MAX_CONTENT_LENGTH) {
        return ['ok' => false, 'code' => 'contribution_too_long', 'contribution' => null];
    }

    $storyId = STORY_ID;

    $pdo->beginTransaction();

    try {
        $lock = $pdo->prepare('SELECT id FROM stories WHERE id = :id FOR UPDATE');
        $lock->bindValue(':id', $storyId, PDO::PARAM_INT);
        $lock->execute();

        $contributions = story_contributions($pdo, $storyId);
        $editableId = story_editable_id($contributions, $writerKey, $now);

        if ($editableId === null || $editableId !== $contributionId) {
            $pdo->rollBack();

            /* Drei Fälle, absichtlich mit einem Code: der Beitrag gehört jemand anderem,
               er ist nicht der letzte, oder es gibt ihn nicht. Wer nicht ändern darf,
               muss nicht erfahren, warum genau - und die Oberfläche kann nichts tun,
               was sie nicht schon tut. */
            return ['ok' => false, 'code' => 'not_editable', 'contribution' => null];
        }

        $words = story_count_words($text);

        $update = $pdo->prepare(
            'UPDATE story_contributions
                SET content = :content,
                    word_count = :word_count
              WHERE id = :id
                AND story_id = :story_id'
        );

        $update->bindValue(':content', $text, PDO::PARAM_STR);
        $update->bindValue(':word_count', $words, PDO::PARAM_INT);
        $update->bindValue(':id', $contributionId, PDO::PARAM_INT);
        $update->bindValue(':story_id', $storyId, PDO::PARAM_INT);
        $update->execute();

        $touch = $pdo->prepare('UPDATE stories SET updated_at = UTC_TIMESTAMP() WHERE id = :id');
        $touch->bindValue(':id', $storyId, PDO::PARAM_INT);
        $touch->execute();

        $pdo->commit();
    } catch (PDOException $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $error;
    }

    $edited = null;

    foreach (story_contributions($pdo, $storyId) as $contribution) {
        if ($contribution['id'] === $contributionId) {
            $edited = $contribution;
        }
    }

    return ['ok' => true, 'code' => null, 'contribution' => $edited];
}

/**
 * Alles, was die Seite braucht - in einer Antwort.
 *
 * @param PDO $pdo
 * @param int|null $userId die Id aus der Sitzung
 * @param DateTimeImmutable|null $now nur für Tests
 * @return array<string, mixed>
 */
function story_overview(PDO $pdo, ?int $userId, ?DateTimeImmutable $now = null): array
{
    $writerKey = story_writer_key($userId);
    $story = story_load_or_create($pdo);
    $contributions = story_contributions($pdo, (int) $story['id']);
    $today = story_today($now);
    $state = story_state($contributions, $now, $writerKey);

    return [
        'story' => [
            'id' => (int) $story['id'],
            'title' => (string) $story['title'],
            'created_at' => story_local_iso((string) $story['created_at']),
            'updated_at' => story_local_iso((string) $story['updated_at']),
        ],
        'contributions' => $contributions,
        'state' => $state,
        /* Wer als Nächstes schreibt - dieselbe Antwort, die die Seite unten in einem Satz
           ausspricht, und die, die die Kachel der Startseite zeigt. */
        'turn' => ['writer' => story_turn_writer($state)],
        /* Die Aufgabe kommt mit derselben Antwort mit: die Seite braucht dafür keine
           zweite Anfrage, und sie gilt für beide Nutzer am selben Tag gleich. */
        'challenge' => story_challenge_for((string) $story['created_at'], $today),
        /* Der Beitrag, den dieser Nutzer noch ändern darf - oder null. Die Oberfläche
           baut daraus den Bearbeiten-Knopf; ob es wirklich geht, entscheidet beim
           Speichern story_edit() noch einmal. */
        'editable_contribution_id' => story_editable_id($contributions, $writerKey, $now),
        'viewer' => [
            'key' => $writerKey,
            'is_writer' => $writerKey !== null,
        ],
        'today' => $today,
        'limits' => [
            'maxContentLength' => STORY_MAX_CONTENT_LENGTH,
            'maxTitleLength' => STORY_MAX_TITLE_LENGTH,
        ],
    ];
}
