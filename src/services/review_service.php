<?php

declare(strict_types=1);

require_once __DIR__ . '/study_session_service.php';
require_once __DIR__ . '/card_service.php';
require_once __DIR__ . '/category_service.php';

/**
 * Die Wiederholungslogik: die Kartenbox, die Abstände und der Stand einer Karte.
 *
 * Das ist die EINZIGE Stelle im Projekt, die Stand, Abstand und Fälligkeit entscheidet.
 * Der Browser rechnet davon nichts aus - er zeigt nur, was dieser Service zurückgegeben
 * hat, ein gespeicherter und ein angezeigter Wert können also nie auseinandergehen.
 *
 * ---------------------------------------------------------------------------
 * Die Spalten, die sie benutzt (die echte Struktur von user_card_progress,
 * unverändert)
 * ---------------------------------------------------------------------------
 *   user_id          der Besitzer, nie geraten
 *   card_id          die Karte, zu der der Fortschritt gehört
 *   state            0 = nie gelernt, 1 = am Lernen, 2 = gewusst
 *   due_at           wann die Karte wiederkommt
 *   last_reviewed_at wann sie zuletzt bewertet wurde
 *   repetitions      wie oft sie richtig beantwortet wurde
 *   lapses           wie oft sie mit "Nochmal" beantwortet wurde
 *   stability        wie lange die Erinnerung hält, in Tagen
 *   difficulty       1.0 (leicht für diese Person) .. 10.0 (schwer)
 *
 * ---------------------------------------------------------------------------
 * Die Rechnung (es gab keinen Planer im Projekt, das ist der neue)
 * ---------------------------------------------------------------------------
 * Es ist eine Kartenbox mit einer Gedächtnisstärke, im Geist von SM-2 und FSRS, und sie
 * benutzt nur die Spalten, die es schon gibt:
 *
 *   - stability ist der Abstand in Tagen. Eine Karte ist fällig, wenn due_at vorbei ist.
 *   - eine Bewertung ändert stability um einen Faktor, eine schon leicht zu merkende
 *     Karte wächst also schneller als eine frische.
 *   - "Nochmal" ist die einzige Bewertung, die einen Aussetzer zählt, stability stark
 *     schrumpfen lässt und die Karte nach wenigen Minuten zurückholt, in derselben
 *     Einheit.
 *   - difficulty bewegt sich langsam (0.2 je Bewertung) und verschiebt nur den
 *     Startpunkt einer Karte. Sie wird auf den Bereich oben begrenzt.
 *   - eine Karte ist "gewusst", sobald ihr neuer Abstand einen vollen Tag erreicht. Bis
 *     dahin bleibt sie im Lernzustand, und genau deshalb erscheint sie in dieser Einheit
 *     und bei "schwere Karten wiederholen" erneut.
 *
 * Die Zahlen unten sind die ganze Abstimmung der Box; es sind absichtlich wenige, damit
 * das Verhalten an einer Stelle gelesen und geändert werden kann.
 */

/** Die drei Zustände, die in die Spalte state passen. */
const REVIEW_STATE_NEW = 0;
const REVIEW_STATE_LEARNING = 1;
const REVIEW_STATE_KNOWN = 2;

/** Die vier Bewertungen, die eine Person geben kann, mit ihrem Schlüssel. */
const REVIEW_RATINGS = [
    1 => 'again',
    2 => 'hard',
    3 => 'good',
    4 => 'easy',
];

/** Wie stark die Erinnerung nach der ERSTEN Antwort jeder Art ist, in Tagen. */
const REVIEW_FIRST_STABILITY = [
    1 => 0.20,   // Nochmal: ein paar Minuten, die Karte kommt in dieser Einheit wieder
    2 => 0.80,   // Schwer:  mehr als ein halber Tag, noch am Lernen
    3 => 1.60,   // Gut:     eineinhalb Tage
    4 => 3.20,   // Leicht:  mehr als drei Tage
];

/** Um wie viel eine spätere Antwort die vorhandene Stabilität vervielfacht. */
const REVIEW_STABILITY_FACTOR = [
    1 => 0.20,   // Nochmal: das Meiste vergessen
    2 => 1.20,   // Schwer:  langsam wachsen
    3 => 2.20,   // Gut:     der normale Schritt
    4 => 3.00,   // Leicht:  der größte Schritt
];

/** Ein Abstand fällt nie unter diesen Wert, in Tagen. */
const REVIEW_MIN_STABILITY = 0.2;

/** Wie lange "Nochmal" wartet, bevor die Karte wiederkommt, in Minuten. */
const REVIEW_AGAIN_MINUTES = 10;

/** Wo difficulty startet, wie weit eine Bewertung sie bewegt und wo sie aufhört. */
const REVIEW_DIFFICULTY_START = 5.0;
const REVIEW_DIFFICULTY_STEP = [1 => 0.6, 2 => 0.2, 3 => 0.0, 4 => -0.3];
const REVIEW_DIFFICULTY_MIN = 1.0;
const REVIEW_DIFFICULTY_MAX = 10.0;

/** Eine Karte gilt als gewusst, sobald ihr Abstand einen ganzen Tag erreicht. */
const REVIEW_KNOWN_MIN_STABILITY = 1.0;

/* -------------------------------------------------------------------------
   Lesen
   ------------------------------------------------------------------------- */

/**
 * Liefert die Fortschrittszeile einer Karte oder null, wenn dieses Konto sie nie
 * bewertet hat.
 *
 * @return array<string, mixed>|null
 */
function review_find_progress(PDO $pdo, int $userId, int $cardId): ?array
{
    $statement = $pdo->prepare(
        'SELECT card_id, state, due_at, last_reviewed_at, repetitions, lapses, stability, difficulty
           FROM user_card_progress
          WHERE user_id = :user_id AND card_id = :card_id'
    );
    $statement->bindValue(':user_id', $userId, PDO::PARAM_INT);
    $statement->bindValue(':card_id', $cardId, PDO::PARAM_INT);
    $statement->execute();

    $row = $statement->fetch();

    return $row === false ? null : $row;
}

/**
 * Der Stand einer Karte: "new", "unsure" oder "known".
 *
 *   new    - keine Fortschrittszeile oder eine, die den Zustand "nie gelernt" nie
 *            verlassen hat
 *   unsure - in der Lernphase, überfällig oder gerade jetzt fällig
 *            (ein unbekanntes Fälligkeitsdatum zählt als fällig: eine Karte ohne Datum
 *            käme sonst nie wieder)
 *   known  - erfolgreich wiederholt und noch nicht fällig
 *
 * @param array<string, mixed>|null $progress
 */
function review_status_of($progress, ?int $now = null): string
{
    $now = $now ?? time();

    if ($progress === null) {
        return 'new';
    }

    $state = (int) $progress['state'];

    if ($state === REVIEW_STATE_NEW) {
        return 'new';
    }

    if ($state === REVIEW_STATE_LEARNING) {
        return 'unsure';
    }

    $dueAt = review_due_timestamp($progress['due_at'] ?? null);

    if ($dueAt === null || $dueAt <= $now) {
        return 'unsure';
    }

    return 'known';
}

/**
 * Meldet, ob eine Karte jetzt fällig ist.
 *
 * @param array<string, mixed>|null $progress
 */
function review_is_due($progress, ?int $now = null): bool
{
    if ($progress === null) {
        return false;
    }

    if ((int) $progress['state'] === REVIEW_STATE_NEW) {
        return false;
    }

    $dueAt = review_due_timestamp($progress['due_at'] ?? null);

    return $dueAt === null || $dueAt <= ($now ?? time());
}

/**
 * Liefert die Karten einer Unterkategorie zusammen mit dem Fortschritt eines Kontos.
 *
 * Das ist das Lesen hinter der Kartenliste. Fortschritt und Karten werden in EINER
 * Abfrage verbunden: eine Liste, die für jede Karte einmal die Datenbank fragen würde,
 * bräuchte so viele Hin- und Rückwege, wie die Unterkategorie Karten hat.
 *
 * Ohne angemeldetes Konto gibt es keinen Fortschritt zu zeigen, und jede Karte ist
 * ehrlicherweise "neu" - genau das sagt die Datenbank auch, denn ohne Konto-Id kann es
 * für niemanden eine Fortschrittszeile geben.
 *
 * @return list<array<string, mixed>>
 */
function review_cards_with_progress(PDO $pdo, int $categoryId, ?int $userId, string $language = 'de'): array
{
    return review_cards_in_categories($pdo, [$categoryId], $userId, $language);
}

/**
 * Die Kategorie und alles, was direkt darunter liegt.
 *
 * Ein Lernbereich hält keine eigenen Karten: sie liegen in seinen Unterkategorien.
 * "Alles lernen" ist also eine Einheit über den Bereich und seine Unterkategorien, und
 * das ist dieselbe Menge, die der Kartenzähler des Bereichs schon immer gezeigt hat.
 *
 * @return list<int>
 */
function review_branch_category_ids(PDO $pdo, int $categoryId, int $ownerUserId): array
{
    $statement = $pdo->prepare(
        'SELECT id FROM categories WHERE parent_id = :parent_id AND owner_user_id = :owner_user_id ORDER BY id ASC'
    );
    $statement->bindValue(':parent_id', $categoryId, PDO::PARAM_INT);
    $statement->bindValue(':owner_user_id', $ownerUserId, PDO::PARAM_INT);
    $statement->execute();

    /*
     * Eine Kategorie von jemand anderem ist gar keine Einheit: die Antwort ist leer
     * statt einer Id, die dieses Konto nicht besitzt. Der Endpunkt prüft dasselbe mit
     * category_exists(), bevor er hier ankommt, das hier ist also die zweite Absicherung
     * und nicht die erste.
     */
    $owned = $pdo->prepare('SELECT COUNT(*) FROM categories WHERE id = :id AND owner_user_id = :owner_user_id');
    $owned->bindValue(':id', $categoryId, PDO::PARAM_INT);
    $owned->bindValue(':owner_user_id', $ownerUserId, PDO::PARAM_INT);
    $owned->execute();

    if ((int) $owned->fetchColumn() === 0) {
        return [];
    }

    $ids = [$categoryId];

    foreach ($statement->fetchAll() as $row) {
        $ids[] = (int) $row['id'];
    }

    return $ids;
}

/**
 * Die Karten einer oder mehrerer Kategorien, mit dem Fortschritt eines Kontos und dem
 * Text in der Sprache, die gezeigt werden soll.
 *
 * Eine Abfrage für die ganze Liste. Die Platzhalter entstehen aus der ANZAHL der Ids,
 * und jeder Wert wird trotzdem gebunden.
 *
 * @param list<int> $categoryIds
 * @return list<array<string, mixed>>
 */
function review_cards_in_categories(PDO $pdo, array $categoryIds, ?int $userId, string $language = 'de'): array
{
    $ids = array_values(array_unique(array_filter($categoryIds, static fn ($id) => (int) $id > 0)));

    if ($ids === []) {
        return [];
    }

    $columns = card_columns($pdo);

    /*
     * Jede Sprache, die die Tabelle hat, wird in derselben Abfrage gelesen. front und
     * back bleiben in der Liste: die ältere Form einer Kartenzeile liest sie.
     */
    $selected = ['k.id', 'k.category_id', 'k.is_bidirectional', 'k.front', 'k.back'];

    /* Nur wenn die Tabelle sie hat: eine Karte kann eine Kartenregion tragen. */
    if (card_column_available($columns, 'map_region')) {
        $selected[] = 'k.map_region';
    }

    /*
     * Die Aufgabe einer Karte, wenn es die Tabelle dafür gibt. Die zwei Werte werden hier
     * umbenannt, damit sie nicht für eine Spalte von `cards` gehalten werden - und
     * normalize_card_row() macht daraus die Aufgabe, die gezeigt wird.
     */
    if (card_exercise_table_available($pdo) && card_exercise_params_available($pdo)) {
        $selected[] = 'card_exercises.exercise_type AS exercise_type';
        $selected[] = 'card_exercises.exercise_params AS exercise_params';
    }

    foreach (card_language_columns($columns) as $pair) {
        foreach ($pair as $column) {
            $selected[] = 'k.' . $column;
        }
    }

    $selection = implode(', ', array_unique($selected));

    /*
     * Jeder Platzhalter bekommt seinen eigenen Namen. Die Konto-Id in der Verbindung oben
     * ist ein benannter Platzhalter, und eine Anweisung darf benannte und nummerierte
     * nicht mischen.
     */
    $placeholders = [];

    foreach ($ids as $index => $id) {
        $placeholders[] = ':card_category_' . $index;
    }

    /*
     * Die Verbindungsbedingung trägt die Konto-Id. Wenn niemand angemeldet ist, ist der
     * Wert NULL, und "p.user_id = NULL" ist nie wahr - die Verbindung holt also gar
     * keinen Fortschritt statt den von jemand anderem.
     */
    $statement = $pdo->prepare(
        'SELECT ' . $selection . ',
                p.state, p.due_at, p.last_reviewed_at, p.repetitions, p.lapses,
                p.stability, p.difficulty
           FROM cards AS k' . card_exercise_join($pdo, 'k') . '
           LEFT JOIN user_card_progress AS p
                  ON p.card_id = k.id AND p.user_id = :user_id
          WHERE k.category_id IN (' . implode(', ', $placeholders) . ')
            AND k.category_id IN (SELECT id FROM categories WHERE owner_user_id = :owner_user_id)
          ORDER BY k.id ASC'
    );

    if ($userId === null) {
        $statement->bindValue(':user_id', null, PDO::PARAM_NULL);
        /* Kein Konto, keine Karten: "owner_user_id = NULL" ist nie wahr, die Antwort
           bleibt also leer statt die Liste von jemand anderem zu zeigen. */
        $statement->bindValue(':owner_user_id', null, PDO::PARAM_NULL);
    } else {
        $statement->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $statement->bindValue(':owner_user_id', $userId, PDO::PARAM_INT);
    }

    foreach ($ids as $index => $id) {
        $statement->bindValue(':card_category_' . $index, $id, PDO::PARAM_INT);
    }

    $statement->execute();

    $entries = [];

    foreach ($statement->fetchAll() as $row) {
        $progress = $row['state'] === null ? null : $row;

        $card = array_merge(normalize_card_row($row), card_localized_text($row, $columns, $language));
        $card['progress'] = review_public_progress($progress);

        $entries[] = $card;
    }

    return $entries;
}

/**
 * Zählt eine Liste von Karten nach Stand, für den kleinen Balken über der Liste.
 *
 * @param list<array<string, mixed>> $cards das Ergebnis von review_cards_with_progress()
 * @return array{total: int, new: int, unsure: int, known: int, due: int}
 */
function review_summarise_cards(array $cards): array
{
    $summary = ['total' => count($cards), 'new' => 0, 'unsure' => 0, 'known' => 0, 'due' => 0];

    foreach ($cards as $card) {
        $status = (string) ($card['progress']['status'] ?? 'new');
        $summary[$status] = ($summary[$status] ?? 0) + 1;

        if (($card['progress']['is_due'] ?? false) === true) {
            $summary['due']++;
        }
    }

    return $summary;
}

/**
 * Wie lange jede der vier Antworten die Karte wegbleiben ließe, in Minuten.
 *
 * Die Knöpfe unter einer aufgedeckten Karte zeigen, was jede Antwort machen würde. Diese
 * Zahlen werden HIER ausgerechnet, von demselben Planer, der die Antwort anschließend
 * speichert, Vorschau und gespeicherter Wert können also nie auseinanderlaufen - der
 * Browser formatiert nur die Minuten, die er bekommt.
 *
 * @param array<string, mixed>|null $progress
 * @return array<string, int>
 */
function review_interval_previews($progress, ?int $now = null): array
{
    $now = $now ?? time();
    $previews = [];

    foreach (array_keys(REVIEW_RATINGS) as $rating) {
        $next = review_calculate($progress, $rating, $now);
        $previews[REVIEW_RATINGS[$rating]] = (int) round(($next['due_timestamp'] - $now) / 60);
    }

    return $previews;
}

/**
 * Macht aus dem gespeicherten Datum einen Zeitstempel oder null, wenn es keinen gibt.
 *
 * Die Spalte ist ein DATETIME in der Zeitzone der Datenbank; PHP schreibt und liest sie
 * über seine eigene Zeitzone, beide Seiten benutzen also dieselbe Uhr. MySQL wird nie
 * nach NOW() gefragt: gespeichert wird der Wert, den PHP ausgerechnet hat, damit eine
 * Bewertung und ihr gespeichertes Datum auf die Sekunde gleich bleiben.
 *
 * @param mixed $value
 */
function review_due_timestamp($value): ?int
{
    if (!is_string($value) || trim($value) === '') {
        return null;
    }

    $timestamp = strtotime($value);

    return $timestamp === false ? null : $timestamp;
}

/**
 * Macht aus einer Fortschrittszeile die Form, die die API dem Browser gibt.
 *
 * @param array<string, mixed>|null $progress
 * @return array<string, mixed>
 */
function review_public_progress($progress, ?int $now = null): array
{
    $now = $now ?? time();

    if ($progress === null) {
        return [
            'status' => 'new',
            'state' => REVIEW_STATE_NEW,
            'due_at' => null,
            'last_reviewed_at' => null,
            'repetitions' => 0,
            'lapses' => 0,
            'stability' => null,
            'difficulty' => null,
            'is_due' => false,
            'interval_days' => null,
        ];
    }

    return [
        'status' => review_status_of($progress, $now),
        'state' => (int) $progress['state'],
        'due_at' => $progress['due_at'] === null ? null : (string) $progress['due_at'],
        'last_reviewed_at' => $progress['last_reviewed_at'] === null ? null : (string) $progress['last_reviewed_at'],
        'repetitions' => (int) $progress['repetitions'],
        'lapses' => (int) $progress['lapses'],
        'stability' => $progress['stability'] === null ? null : (float) $progress['stability'],
        'difficulty' => $progress['difficulty'] === null ? null : (float) $progress['difficulty'],
        'is_due' => review_is_due($progress, $now),
        'interval_days' => $progress['stability'] === null ? null : round((float) $progress['stability'], 2),
    ];
}

/* -------------------------------------------------------------------------
   Rechnen
   ------------------------------------------------------------------------- */

/**
 * Rechnet den Fortschritt aus, zu dem eine Bewertung führt, ohne die Datenbank
 * anzufassen.
 *
 * Das ist die Kartenbox selbst. Sie ist eine reine Funktion aus der alten Zeile und der
 * Bewertung, und genau das macht sie leicht nachprüfbar: dieselbe Eingabe liefert immer
 * dieselbe Ausgabe, und innerhalb einer Bewertung wird keine Uhr zweimal gelesen.
 *
 * @param array<string, mixed>|null $progress die Zeile vor der Bewertung
 * @param int $rating 1 = nochmal, 2 = schwer, 3 = gut, 4 = leicht
 * @param int $now der Zeitpunkt der Bewertung, als Zeitstempel
 * @return array<string, mixed> die Werte zum Speichern
 */
function review_calculate($progress, int $rating, int $now): array
{
    $oldStability = $progress === null ? null : ($progress['stability'] === null ? null : (float) $progress['stability']);
    $oldDifficulty = $progress === null ? null : ($progress['difficulty'] === null ? null : (float) $progress['difficulty']);
    $repetitions = $progress === null ? 0 : (int) $progress['repetitions'];
    $lapses = $progress === null ? 0 : (int) $progress['lapses'];

    /* Eine Karte, die nie bewertet wurde, oder eine, die ihre Stabilität verloren hat,
       startet beim ersten Wert dieser Bewertung. */
    if ($oldStability === null || $oldStability <= 0) {
        $stability = REVIEW_FIRST_STABILITY[$rating];
    } else {
        $stability = $oldStability * REVIEW_STABILITY_FACTOR[$rating];
    }

    $stability = max(REVIEW_MIN_STABILITY, round($stability, 4));

    $difficulty = ($oldDifficulty ?? REVIEW_DIFFICULTY_START) + REVIEW_DIFFICULTY_STEP[$rating];
    $difficulty = min(REVIEW_DIFFICULTY_MAX, max(REVIEW_DIFFICULTY_MIN, round($difficulty, 3)));

    /* "Nochmal" ist die einzige Antwort, die kein Erfolg ist. */
    if ($rating === 1) {
        $lapses++;
    } else {
        $repetitions++;
    }

    /*
     * "Nochmal" kommt in derselben Einheit wieder, alles andere wartet seinen Abstand ab.
     * Beides wird in derselben due_at-Spalte gespeichert.
     */
    if ($rating === 1) {
        $dueAt = $now + (REVIEW_AGAIN_MINUTES * 60);
    } else {
        $dueAt = $now + (int) round($stability * 86400);
    }

    $state = ($rating !== 1 && $stability >= REVIEW_KNOWN_MIN_STABILITY)
        ? REVIEW_STATE_KNOWN
        : REVIEW_STATE_LEARNING;

    return [
        'state' => $state,
        'due_at' => date('Y-m-d H:i:s', $dueAt),
        'due_timestamp' => $dueAt,
        'last_reviewed_at' => date('Y-m-d H:i:s', $now),
        'repetitions' => $repetitions,
        'lapses' => $lapses,
        'stability' => $stability,
        'difficulty' => $difficulty,
        'interval_days' => round($stability, 2),
    ];
}

/* -------------------------------------------------------------------------
   Schreiben
   ------------------------------------------------------------------------- */

/**
 * Speichert eine Bewertung und liefert zurück, was gespeichert wurde.
 *
 * Die Karte wird hier noch einmal geprüft - sie muss existieren und sie muss zu der
 * Kategorie gehören, die die Person vor sich hatte -, denn für beides ist der Browser
 * keine verlässliche Quelle. Alles passiert in einer Transaktion, eine Karte kann also
 * nie halb bewertet dastehen.
 *
 * @return array{ok: bool, code?: string, message?: string, data?: array<string, mixed>}
 */
function review_rate_card(PDO $pdo, int $userId, int $cardId, int $rating, int $categoryId, ?int $sessionId = null, ?int $now = null): array
{
    if (!isset(REVIEW_RATINGS[$rating])) {
        return ['ok' => false, 'code' => 'invalid_rating', 'message' => 'The rating must be 1, 2, 3 or 4.'];
    }

    /*
     * Der Besitzer ist Teil der Suche: find_card() antwortet nur mit einer Karte aus
     * einer Kategorie, die diese Person besitzt, eine fremde Karten-Id endet hier also
     * als "nicht gefunden".
     */
    $card = find_card($pdo, $cardId, $userId);

    if ($card === null) {
        return ['ok' => false, 'code' => 'card_not_found', 'message' => 'This flashcard does not exist.'];
    }

    if ((int) $card['category_id'] !== $categoryId) {
        return ['ok' => false, 'code' => 'card_not_in_category', 'message' => 'This flashcard does not belong to this subcategory.'];
    }

    $now = $now ?? time();
    $previous = review_find_progress($pdo, $userId, $cardId);
    $next = review_calculate($previous, $rating, $now);

    /*
     * Die Id des Lerndurchgangs, zu dem diese Antwort gehört. Sie wird in der
     * Transaktion darunter entschieden: der Browser schickt bei der ersten Antwort eines
     * Durchgangs null und bekommt die neue Id zurück, siehe study_session_service.php.
     */
    $runId = $sessionId;

    review_run_in_transaction($pdo, static function () use ($pdo, $userId, $cardId, $next, $rating, $categoryId, $now, $sessionId, &$runId): void {
        review_store_progress($pdo, $userId, $cardId, $next);

        /*
         * Die Durchgangszeile wird in DERSELBEN Transaktion geschrieben wie der
         * Fortschritt: entweder sind beide da oder keine. Eine Bewertung, die gespeichert,
         * aber nicht gezählt wurde - oder umgekehrt -, ließe sich hinterher nicht
         * erklären.
         */
        $runId = study_session_record_rating($pdo, $userId, $categoryId, $sessionId, $rating, $now);
    });

    return [
        'ok' => true,
        'data' => [
            'card_id' => $cardId,
            'rating' => $rating,
            'rating_name' => REVIEW_RATINGS[$rating],
            /*
             * Der Lerndurchgang, in dem diese Antwort gezählt wurde. Der Browser schickt
             * diese Id mit seiner nächsten Antwort, ein Durchgang bleibt also eine Zeile in
             * study_sessions (siehe study_session_service.php).
             */
            'session_id' => $runId,
            /* Wie die Karte jetzt aussieht. */
            'progress' => review_public_progress($next, $now),
            'interval_days' => $next['interval_days'],
            'due_at' => $next['due_at'],
            'status' => review_status_of($next, $now),
            /*
             * Wie sie vorher aussah, damit die letzte Bewertung zurückgenommen werden kann.
             * Der Browser trägt diese Werte nur mit sich herum; er rechnet nie damit.
             */
            'previous' => $previous === null ? null : review_public_progress($previous, $now),
            'previous_state' => $previous === null ? null : [
                'state' => (int) $previous['state'],
                'due_at' => $previous['due_at'],
                'last_reviewed_at' => $previous['last_reviewed_at'],
                'repetitions' => (int) $previous['repetitions'],
                'lapses' => (int) $previous['lapses'],
                'stability' => $previous['stability'],
                'difficulty' => $previous['difficulty'],
            ],
            /* Was gerade geschrieben wurde, damit ein Zurücknehmen beweisen kann, dass
               sich seither nichts geändert hat. */
            'stored' => [
                'state' => $next['state'],
                'due_at' => $next['due_at'],
                'last_reviewed_at' => $next['last_reviewed_at'],
                'repetitions' => $next['repetitions'],
                'lapses' => $next['lapses'],
                'stability' => $next['stability'],
                'difficulty' => $next['difficulty'],
            ],
            'had_progress_before' => $previous !== null,
        ],
    ];
}

/**
 * Führt ein Stück Arbeit in einer Transaktion aus.
 *
 * Ist schon eine Transaktion offen - weil der Aufrufer eine begonnen hat oder weil ein
 * größerer Vorgang diesen Service gerufen hat -, tritt die Arbeit ihr bei, statt eine
 * zweite zu öffnen. MySQL kennt keine verschachtelten Transaktionen, ein zweites
 * beginTransaction() würde also scheitern; das Beitreten hält den Service aus einem
 * größeren Vorgang heraus benutzbar und garantiert trotzdem, dass keine halb
 * geschriebene Bewertung entstehen kann.
 *
 * @param callable(): mixed $work
 * @return mixed was $work zurückgegeben hat
 */
function review_run_in_transaction(PDO $pdo, callable $work)
{
    $ownsTransaction = !$pdo->inTransaction();

    if ($ownsTransaction) {
        $pdo->beginTransaction();
    }

    try {
        $result = $work();
    } catch (Throwable $error) {
        if ($ownsTransaction) {
            $pdo->rollBack();
        }

        throw $error;
    }

    if ($ownsTransaction) {
        $pdo->commit();
    }

    return $result;
}

/**
 * Schreibt eine Fortschrittszeile. Den Primärschlüssel (user_id, card_id) gibt es
 * schon, eine vorhandene Zeile wird also an Ort und Stelle geändert - keine zweite
 * Zeile, keine Änderung an der Struktur.
 *
 * @param array<string, mixed> $values
 */
function review_store_progress(PDO $pdo, int $userId, int $cardId, array $values): void
{
    $statement = $pdo->prepare(
        'INSERT INTO user_card_progress
             (user_id, card_id, state, due_at, last_reviewed_at, repetitions, lapses, stability, difficulty)
         VALUES
             (:user_id, :card_id, :state, :due_at, :last_reviewed_at, :repetitions, :lapses, :stability, :difficulty)
         ON DUPLICATE KEY UPDATE
             state = VALUES(state),
             due_at = VALUES(due_at),
             last_reviewed_at = VALUES(last_reviewed_at),
             repetitions = VALUES(repetitions),
             lapses = VALUES(lapses),
             stability = VALUES(stability),
             difficulty = VALUES(difficulty)'
    );

    $statement->bindValue(':user_id', $userId, PDO::PARAM_INT);
    $statement->bindValue(':card_id', $cardId, PDO::PARAM_INT);
    $statement->bindValue(':state', (int) $values['state'], PDO::PARAM_INT);
    $statement->bindValue(':due_at', (string) $values['due_at'], PDO::PARAM_STR);
    $statement->bindValue(':last_reviewed_at', (string) $values['last_reviewed_at'], PDO::PARAM_STR);
    $statement->bindValue(':repetitions', (int) $values['repetitions'], PDO::PARAM_INT);
    $statement->bindValue(':lapses', (int) $values['lapses'], PDO::PARAM_INT);
    $statement->bindValue(':stability', (float) $values['stability']);
    $statement->bindValue(':difficulty', (float) $values['difficulty']);
    $statement->execute();
}

/**
 * Nimmt die letzte Bewertung zurück.
 *
 * Erlaubt ist das nur, solange sich seither nichts geändert hat: der Aufrufer muss die
 * Werte mitschicken, die gerade geschrieben wurden, die Zeile wird erneut gelesen und
 * damit verglichen. Hat jemand in der Zwischenzeit dieselbe Karte in einem anderen
 * Reiter bewertet, wird das Zurücknehmen abgelehnt, statt diese neuere Antwort
 * wegzuwerfen.
 *
 * Eine Karte, die vor der Bewertung keinen Fortschritt hatte, bekommt ihre Zeile wieder
 * entfernt; diese Zeile hat die Bewertung, die gerade zurückgenommen wird, vor einem
 * Augenblick angelegt, und ihr Entfernen ist der einzige Weg zurück zu "nie gelernt".
 *
 * @param array<string, mixed>|null $stored was die Bewertung geschrieben hat
 * @param array<string, mixed>|null $previous was vor der Bewertung da war
 * @return array{ok: bool, code?: string, message?: string, data?: array<string, mixed>}
 */
function review_undo_rating(PDO $pdo, int $userId, int $cardId, $stored, $previous, int $categoryId, ?int $sessionId = null, int $rating = 0): array
{
    /* Dieselbe Besitzerprüfung wie bei der Bewertung selbst, siehe review_rate_card(). */
    $card = find_card($pdo, $cardId, $userId);

    if ($card === null || (int) $card['category_id'] !== $categoryId) {
        return ['ok' => false, 'code' => 'card_not_found', 'message' => 'This flashcard does not exist.'];
    }

    $current = review_find_progress($pdo, $userId, $cardId);

    if ($current === null) {
        return ['ok' => false, 'code' => 'undo_conflict', 'message' => 'This rating can no longer be taken back.'];
    }

    if (!is_array($stored) || !review_row_matches($current, $stored)) {
        return ['ok' => false, 'code' => 'undo_conflict', 'message' => 'This rating can no longer be taken back.'];
    }

    review_run_in_transaction($pdo, static function () use ($pdo, $userId, $cardId, $previous, $sessionId, $rating): void {
        /*
         * Die zurückgenommene Antwort verlässt auch den Durchgang, damit dessen Zähler
         * weiter zu dem passen, was die Lernansicht zeigt. Das passiert zuerst, weil der
         * Zweig darunter vorzeitig zurückkehren kann.
         */
        study_session_take_back_rating($pdo, $userId, $sessionId, $rating);

        if ($previous === null) {
            $statement = $pdo->prepare('DELETE FROM user_card_progress WHERE user_id = :user_id AND card_id = :card_id');
            $statement->bindValue(':user_id', $userId, PDO::PARAM_INT);
            $statement->bindValue(':card_id', $cardId, PDO::PARAM_INT);
            $statement->execute();

            return;
        }

        review_store_progress($pdo, $userId, $cardId, [
            'state' => (int) $previous['state'],
            'due_at' => (string) $previous['due_at'],
            'last_reviewed_at' => $previous['last_reviewed_at'],
            'repetitions' => (int) $previous['repetitions'],
            'lapses' => (int) $previous['lapses'],
            'stability' => (float) $previous['stability'],
            'difficulty' => (float) $previous['difficulty'],
        ]);
    });

    $restored = review_find_progress($pdo, $userId, $cardId);

    return [
        'ok' => true,
        'data' => [
            'card_id' => $cardId,
            'progress' => review_public_progress($restored),
        ],
    ];
}

/**
 * Vergleicht eine gespeicherte Zeile mit den Werten, die ein Zurücknehmen erwartet.
 *
 * @param array<string, mixed> $row
 * @param array<string, mixed> $expected
 */
function review_row_matches(array $row, array $expected): bool
{
    foreach (['state', 'due_at', 'last_reviewed_at', 'repetitions', 'lapses'] as $key) {
        if (!array_key_exists($key, $expected)) {
            return false;
        }

        $left = $row[$key] === null ? null : (string) $row[$key];
        $right = $expected[$key] === null ? null : (string) $expected[$key];

        /* MySQL behält die Sekunde in einem DATETIME, "2026-09-21 15:04:05" kommt also
           genauso zurück, wie es geschrieben wurde, und lässt sich als Text vergleichen.
           Ein Wert, der als Zahl ankommt, wird genauso gerundet. */
        if ($key === 'repetitions' || $key === 'lapses') {
            if ((int) $left !== (int) $right) {
                return false;
            }

            continue;
        }

        if ($left !== $right) {
            return false;
        }
    }

    return true;
}

/* -------------------------------------------------------------------------
   Die Lerneinheit
   ------------------------------------------------------------------------- */

/**
 * Baut die Schlange einer Lerneinheit für eine Unterkategorie.
 *
 * Die Reihenfolge ist die, die die Anwendung verspricht:
 *   1. fällige oder überfällige Karten,
 *   2. dann Karten, die nie gelernt wurden,
 *   3. und erst wenn diese beiden leer sind, die Karten, die noch nicht fällig sind.
 *
 * Eine bidirektionale Karte erscheint pro Einheit genau einmal. Ihre Richtung wird beim
 * Aufbau der Schlange zufällig gezogen und nicht gespeichert; ein neuer Sitzungsstart
 * kann daher die andere Richtung wählen. Die Karten-Zählung bleibt davon unberührt.
 *
 * @param list<array<string, mixed>> $cards die Karten der Unterkategorie, wie die API sie auflistet
 * @param array<string, string> $statuses Karten-Id => "new" | "unsure" | "known"
 * @param array<int, bool> $isDue Karten-Id => ist die Karte gerade fällig
 * @param string $mode "all" oder "difficult"
 * @return array{queue: list<array<string, mixed>>, counts: array<string, int>}
 */
function review_build_queue(array $cards, array $statuses, array $isDue, string $mode = 'all'): array
{
    $due = [];
    $fresh = [];
    $later = [];
    $counts = ['due' => 0, 'new' => 0, 'unsure' => 0, 'known' => 0, 'cards' => 0];

    foreach ($cards as $card) {
        $cardId = (int) $card['id'];
        $status = $statuses[$cardId] ?? 'new';

        $counts['cards']++;

        if ($status === 'new') {
            $counts['new']++;
        } elseif ($status === 'unsure') {
            $counts['unsure']++;
        } else {
            $counts['known']++;
        }

        if (isset($isDue[$cardId]) && $isDue[$cardId]) {
            $counts['due']++;
        }

        /*
         * "Difficult" ist eine zweite Einheit mit den Karten, die mit "Nochmal" oder
         * "Schwer" beantwortet wurden: das sind genau die Karten im Lernzustand.
         */
        if ($mode === 'difficult' && $status !== 'unsure') {
            continue;
        }

        $entries = review_directions_of($card, $status, $isDue[$cardId] ?? false);

        if (($card['is_bidirectional'] ?? false) === true && count($entries) > 1) {
            $entries = [$entries[random_int(0, count($entries) - 1)]];
        }

        foreach ($entries as $entry) {
            if ($mode === 'difficult') {
                $due[] = $entry;
                continue;
            }

            if ($status === 'new') {
                $fresh[] = $entry;
            } elseif (($isDue[$cardId] ?? false)) {
                $due[] = $entry;
            } else {
                $later[] = $entry;
            }
        }
    }

    $queue = array_merge($due, $fresh);

    /* Die noch nicht fälligen Karten kommen zuletzt, und nur wenn sonst nichts zu tun
       ist: eine Einheit sollte nicht enden, bevor sie angefangen hat. */
    if ($queue === []) {
        $queue = $later;
    }

    return ['queue' => $queue, 'counts' => $counts];
}

/**
 * Die eine oder die zwei Runden, die eine Karte in einer Einheit hat.
 *
 * @param array<string, mixed> $card
 * @return list<array<string, mixed>>
 */
function review_directions_of(array $card, string $status, bool $isDue): array
{
    $forward = [
        'card_id' => (int) $card['id'],
        'direction' => 'forward',
        'front' => (string) $card['front'],
        'back' => (string) $card['back'],
        'status' => $status,
        'is_due' => $isDue,
        'is_bidirectional' => (bool) $card['is_bidirectional'],
        /* Nur ein Wert, der zum Muster passt, geht zum Browser, die Einheit bekommt also
           nie etwas, dem sie misstrauen müsste. */
        'map_region' => isset($card['map_region']) && card_map_region_is_valid((string) $card['map_region'])
            ? (string) $card['map_region']
            : null,
        /*
         * Die Aufgabe der Karte, wenn sie eine ist. Ihre Aufgabe ist keine Spalte: sie
         * wird bei jedem Lesen der Karte neu ausgewürfelt, jede Einheit bekommt also neue
         * Zahlen. Ohne das bekäme die Einheit nur die Überschrift und eine leere Antwort,
         * und die Lernkarte würde genau das zeigen.
         */
        'exercise' => $card['exercise'] ?? null,
    ];

    if (($card['is_bidirectional'] ?? false) !== true) {
        return [$forward];
    }

    $reverse = $forward;
    $reverse['direction'] = 'reverse';
    $reverse['front'] = (string) $card['back'];
    $reverse['back'] = (string) $card['front'];

    /*
     * Andersherum wird nach der Antwort gefragt und die Aufgabe gezeigt: Frage und Antwort
     * tauschen die Plätze, alles andere bleibt, wie es ist.
     */
    if (is_array($reverse['exercise']) && isset($reverse['exercise']['task'])) {
        $task = $reverse['exercise']['task'];
        $reverse['exercise']['task'] = [
            'type' => $task['type'] ?? $reverse['exercise']['type'] ?? '',
            'question' => $task['answer'] ?? null,
            'answer' => $task['question'] ?? null,
        ];
    }

    return [$forward, $reverse];
}

/**
 * Jede Karte jeder Kategorie in EINEM Lesevorgang, nach Kategorie gruppiert.
 *
 * Das braucht api/bootstrap.php: der Browser lädt es einmal und baut die anderen
 * Ansichten daraus auf. Eine Abfrage statt einer pro Unterkategorie, denn eine Liste
 * von Unterkategorien würde sonst vierzig Hin- und Rückwege kosten.
 *
 * Die Form einer einzelnen Karte ist genau die Form, die api/cards.php zurückgibt, der
 * Browser muss also nicht zwei davon kennen.
 *
 * @return array{cards: array<int, list<array<string, mixed>>>, summaries: array<int, array<string, mixed>>}
 */
function review_cards_all_categories(PDO $pdo, ?int $userId, string $language = 'de'): array
{
    $columns = card_columns($pdo);

    $selected = ['k.id', 'k.category_id', 'k.is_bidirectional', 'k.front', 'k.back'];

    if (card_column_available($columns, 'map_region')) {
        $selected[] = 'k.map_region';
    }

    if (card_exercise_table_available($pdo) && card_exercise_params_available($pdo)) {
        $selected[] = 'card_exercises.exercise_type AS exercise_type';
        $selected[] = 'card_exercises.exercise_params AS exercise_params';
    }

    foreach (card_language_columns($columns) as $pair) {
        foreach ($pair as $column) {
            $selected[] = 'k.' . $column;
        }
    }

    $statement = $pdo->prepare(
        'SELECT ' . implode(', ', array_unique($selected)) . ',
                p.state, p.due_at, p.last_reviewed_at, p.repetitions, p.lapses,
                p.stability, p.difficulty
           FROM cards AS k' . card_exercise_join($pdo, 'k') . '
           LEFT JOIN user_card_progress AS p
                  ON p.card_id = k.id AND p.user_id = :user_id
          WHERE k.category_id IN (SELECT id FROM categories WHERE owner_user_id = :owner_user_id)
          ORDER BY k.category_id ASC, k.id ASC'
    );

    if ($userId === null) {
        $statement->bindValue(':user_id', null, PDO::PARAM_NULL);
        /* Abgemeldet heißt leere Antwort und nicht jede Karte der Datenbank. */
        $statement->bindValue(':owner_user_id', null, PDO::PARAM_NULL);
    } else {
        $statement->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $statement->bindValue(':owner_user_id', $userId, PDO::PARAM_INT);
    }

    $statement->execute();

    $cards = [];

    foreach ($statement->fetchAll() as $row) {
        $progress = $row['state'] === null ? null : $row;

        $card = array_merge(normalize_card_row($row), card_localized_text($row, $columns, $language));
        $card['progress'] = review_public_progress($progress);

        $cards[(int) $row['category_id']][] = $card;
    }

    $summaries = [];

    foreach ($cards as $categoryId => $list) {
        $summaries[$categoryId] = review_summarise_cards($list);
    }

    return ['cards' => $cards, 'summaries' => $summaries];
}
