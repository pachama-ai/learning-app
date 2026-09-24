<?php

declare(strict_types=1);

/**
 * The repetition logic: the card box, the intervals and the status of a card.
 *
 * It is the ONLY place in the project that decides state, interval and due date.
 * The browser never calculates any of it - it only shows what this service
 * returned, so a stored value and a shown value can never disagree.
 *
 * ---------------------------------------------------------------------------
 * The columns it uses (the real schema of user_card_progress, unchanged)
 * ---------------------------------------------------------------------------
 *   user_id          the owner, never guessed
 *   card_id          the card the progress belongs to
 *   state            0 = never learned, 1 = learning, 2 = known
 *   due_at           when the card comes back
 *   last_reviewed_at when it was rated the last time
 *   repetitions      how often it was answered correctly
 *   lapses           how often it was answered with "Again"
 *   stability        how long the memory lasts, in days
 *   difficulty       1.0 (easy for this person) .. 10.0 (hard)
 *
 * ---------------------------------------------------------------------------
 * The calculation (there was no scheduler in the project, this is the new one)
 * ---------------------------------------------------------------------------
 * It is a card box with a memory strength, in the spirit of SM-2 and FSRS, and
 * it only uses the columns that already exist:
 *
 *   - stability is the interval in days. A card is due when due_at has passed.
 *   - a rating changes stability by a factor, so a card that is already easy to
 *     remember grows faster than a fresh one.
 *   - "Again" is the only rating that counts a lapse, shrinks stability hard and
 *     brings the card back after a few minutes, inside the same session.
 *   - difficulty moves slowly (0.2 per rating) and only shifts the starting
 *     point of a card. It is clamped to the range above.
 *   - a card is "known" once its new interval reaches a full day. Until then it
 *     stays in the learning state, which is what makes it appear again in this
 *     session and in "repeat the difficult cards".
 *
 * The numbers below are the whole tuning of the box; they are deliberately few,
 * so the behaviour can be read and changed in one place.
 */

/** The three states that fit into the state column. */
const REVIEW_STATE_NEW = 0;
const REVIEW_STATE_LEARNING = 1;
const REVIEW_STATE_KNOWN = 2;

/** The four ratings a person can give, with the key that is printed on it. */
const REVIEW_RATINGS = [
    1 => 'again',
    2 => 'hard',
    3 => 'good',
    4 => 'easy',
];

/** How strong the memory is, in days, after the FIRST answer of each kind. */
const REVIEW_FIRST_STABILITY = [
    1 => 0.20,   // Again: a few minutes, the card comes back in this session
    2 => 0.80,   // Hard:  more than half a day, still learning
    3 => 1.60,   // Good:  a day and a half
    4 => 3.20,   // Easy:  more than three days
];

/** How much a later answer multiplies the existing stability. */
const REVIEW_STABILITY_FACTOR = [
    1 => 0.20,   // Again: forget most of it
    2 => 1.20,   // Hard:  grow slowly
    3 => 2.20,   // Good:  the normal step
    4 => 3.00,   // Easy:  the biggest step
];

/** Never let an interval fall below this, in days. */
const REVIEW_MIN_STABILITY = 0.2;

/** How long "Again" waits before the card comes back, in minutes. */
const REVIEW_AGAIN_MINUTES = 10;

/** Where difficulty starts, how far a rating moves it, and where it stops. */
const REVIEW_DIFFICULTY_START = 5.0;
const REVIEW_DIFFICULTY_STEP = [1 => 0.6, 2 => 0.2, 3 => 0.0, 4 => -0.3];
const REVIEW_DIFFICULTY_MIN = 1.0;
const REVIEW_DIFFICULTY_MAX = 10.0;

/** A card counts as known once its interval reaches a whole day. */
const REVIEW_KNOWN_MIN_STABILITY = 1.0;

/* -------------------------------------------------------------------------
   Reading
   ------------------------------------------------------------------------- */

/**
 * Returns the progress row of one card, or null when this user never rated it.
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
 * The status of one card: "new", "unsure" or "known".
 *
 *   new    - no progress row, or one that never left the state "never learned"
 *   unsure - in the learning phase, or overdue, or due right now
 *            (an unknown due date counts as due: a card without a date would
 *            otherwise never come back)
 *   known  - repeated successfully and not due yet
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
 * Reports whether a card is due now.
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
 * Returns the cards of one subcategory together with the progress of one user.
 *
 * This is the read behind the card list. Progress and cards are joined in ONE
 * query: a list that asked the database once per card would need as many round
 * trips as the subcategory has cards.
 *
 * Without a signed-in user there is no progress to show, and every card is
 * honestly "new" - that is exactly what the database says, because without a
 * user id there cannot be a progress row for anybody.
 *
 * @return list<array<string, mixed>>
 */
function review_cards_with_progress(PDO $pdo, int $categoryId, ?int $userId, string $language = 'de'): array
{
    return review_cards_in_categories($pdo, [$categoryId], $userId, $language);
}

/**
 * The category and everything directly below it.
 *
 * A learning area holds no cards of its own: they sit in its subcategories. So
 * "Study all" is one session over the area and its subcategories, and that is
 * the same set the card counter of the area always showed.
 *
 * @return list<int>
 */
function review_branch_category_ids(PDO $pdo, int $categoryId): array
{
    $statement = $pdo->prepare('SELECT id FROM categories WHERE parent_id = :parent_id ORDER BY id ASC');
    $statement->bindValue(':parent_id', $categoryId, PDO::PARAM_INT);
    $statement->execute();

    $ids = [$categoryId];

    foreach ($statement->fetchAll() as $row) {
        $ids[] = (int) $row['id'];
    }

    return $ids;
}

/**
 * The cards of one or more categories, with the progress of one user and the
 * text in the language that should be shown.
 *
 * One query for the whole list. The placeholders are built from the COUNT of
 * the ids and every value is still bound.
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
     * Every language the table has is read in the same query. front and back stay
     * in the list: the older shape of a card row reads them.
     */
    $selected = ['k.id', 'k.category_id', 'k.is_bidirectional', 'k.front', 'k.back'];

    /* Only when the table has it: a card may carry a map region. */
    if (card_column_available($columns, 'map_region')) {
        $selected[] = 'k.map_region';
    }

    /*
     * The exercise of a card, when the table for it exists. The three values are
     * renamed here, so they cannot be mistaken for a column of `cards` - and
     * normalize_card_row() turns them into the task that is shown.
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
     * Every placeholder gets its own name. The user id in the join above is a
     * named placeholder, and a statement may not mix named and positional ones.
     */
    $placeholders = [];

    foreach ($ids as $index => $id) {
        $placeholders[] = ':card_category_' . $index;
    }

    /*
     * The join condition carries the user id. When there is nobody signed in the
     * value is NULL, and "p.user_id = NULL" is never true - so the join brings
     * back no progress at all instead of the progress of somebody else.
     */
    $statement = $pdo->prepare(
        'SELECT ' . $selection . ',
                p.state, p.due_at, p.last_reviewed_at, p.repetitions, p.lapses,
                p.stability, p.difficulty
           FROM cards AS k' . card_exercise_join($pdo, 'k') . '
           LEFT JOIN user_card_progress AS p
                  ON p.card_id = k.id AND p.user_id = :user_id
          WHERE k.category_id IN (' . implode(', ', $placeholders) . ')
          ORDER BY k.id ASC'
    );

    if ($userId === null) {
        $statement->bindValue(':user_id', null, PDO::PARAM_NULL);
    } else {
        $statement->bindValue(':user_id', $userId, PDO::PARAM_INT);
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
 * Counts a list of cards by status, for the small bar above the list.
 *
 * @param list<array<string, mixed>> $cards the result of review_cards_with_progress()
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
 * How long each of the four answers would keep the card away, in minutes.
 *
 * The buttons under a flipped card show what each answer would do. Those numbers
 * are calculated HERE, by the same scheduler that will store the answer, so the
 * preview and the stored value can never drift apart - the browser only formats
 * the minutes it is given.
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
 * Turns the stored date into a timestamp, or null when there is none.
 *
 * The column is a DATETIME in the database's own time zone; PHP writes and reads
 * it through its own time zone, so both sides use the same clock. MySQL is never
 * asked for NOW(): the value that is stored is the one PHP computed, which keeps
 * a rating and its stored date identical to the second.
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
 * Converts a progress row into the shape the API hands to the browser.
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
   Calculating
   ------------------------------------------------------------------------- */

/**
 * Calculates the progress a rating leads to, without touching the database.
 *
 * This is the card box itself. It is a pure function of the old row and the
 * rating, which is what makes it easy to check: the same input always gives the
 * same output, and no clock is read twice inside one rating.
 *
 * @param array<string, mixed>|null $progress the row before the rating
 * @param int $rating 1 = again, 2 = hard, 3 = good, 4 = easy
 * @param int $now the moment of the rating, as a timestamp
 * @return array<string, mixed> the values to store
 */
function review_calculate($progress, int $rating, int $now): array
{
    $oldStability = $progress === null ? null : ($progress['stability'] === null ? null : (float) $progress['stability']);
    $oldDifficulty = $progress === null ? null : ($progress['difficulty'] === null ? null : (float) $progress['difficulty']);
    $repetitions = $progress === null ? 0 : (int) $progress['repetitions'];
    $lapses = $progress === null ? 0 : (int) $progress['lapses'];

    /* A card that was never rated, or one that lost its stability, starts from
       the first value of this rating. */
    if ($oldStability === null || $oldStability <= 0) {
        $stability = REVIEW_FIRST_STABILITY[$rating];
    } else {
        $stability = $oldStability * REVIEW_STABILITY_FACTOR[$rating];
    }

    $stability = max(REVIEW_MIN_STABILITY, round($stability, 4));

    $difficulty = ($oldDifficulty ?? REVIEW_DIFFICULTY_START) + REVIEW_DIFFICULTY_STEP[$rating];
    $difficulty = min(REVIEW_DIFFICULTY_MAX, max(REVIEW_DIFFICULTY_MIN, round($difficulty, 3)));

    /* "Again" is the only answer that is not a success. */
    if ($rating === 1) {
        $lapses++;
    } else {
        $repetitions++;
    }

    /*
     * "Again" comes back inside the same session, everything else waits for its
     * interval. Both are stored in the same due_at column.
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
   Writing
   ------------------------------------------------------------------------- */

/**
 * Stores one rating and returns what was stored.
 *
 * The card is checked again here - it must exist and it must belong to the
 * category the person was looking at - because the browser is not a trustworthy
 * source for either. Everything happens in one transaction, so a card can never
 * end up half rated.
 *
 * @return array{ok: bool, code?: string, message?: string, data?: array<string, mixed>}
 */
function review_rate_card(PDO $pdo, int $userId, int $cardId, int $rating, int $categoryId, ?int $now = null): array
{
    if (!isset(REVIEW_RATINGS[$rating])) {
        return ['ok' => false, 'code' => 'invalid_rating', 'message' => 'The rating must be 1, 2, 3 or 4.'];
    }

    $card = find_card($pdo, $cardId);

    if ($card === null) {
        return ['ok' => false, 'code' => 'card_not_found', 'message' => 'This flashcard does not exist.'];
    }

    if ((int) $card['category_id'] !== $categoryId) {
        return ['ok' => false, 'code' => 'card_not_in_category', 'message' => 'This flashcard does not belong to this subcategory.'];
    }

    $now = $now ?? time();
    $previous = review_find_progress($pdo, $userId, $cardId);
    $next = review_calculate($previous, $rating, $now);

    review_run_in_transaction($pdo, static function () use ($pdo, $userId, $cardId, $next): void {
        review_store_progress($pdo, $userId, $cardId, $next);
    });

    return [
        'ok' => true,
        'data' => [
            'card_id' => $cardId,
            'rating' => $rating,
            'rating_name' => REVIEW_RATINGS[$rating],
            /* What the card looks like now. */
            'progress' => review_public_progress($next, $now),
            'interval_days' => $next['interval_days'],
            'due_at' => $next['due_at'],
            'status' => review_status_of($next, $now),
            /*
             * What it looked like before, so the last rating can be taken back.
             * The browser only carries these values around; it never calculates
             * with them.
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
            /* What was just written, so an undo can prove nothing changed since. */
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
 * Runs a piece of work in a transaction.
 *
 * When a transaction is already open - because the caller started one, or
 * because a bigger operation called this service - the work joins it instead of
 * opening a second one. MySQL has no nested transactions, so calling
 * beginTransaction() twice would fail; joining keeps the service usable from a
 * larger piece of work and still guarantees that a half written rating cannot
 * happen.
 *
 * @param callable(): mixed $work
 * @return mixed whatever $work returned
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
 * Writes one row of progress. The primary key (user_id, card_id) already exists,
 * so an existing row is updated in place - no second row, no schema change.
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
 * Takes the last rating back.
 *
 * It is only allowed while nothing has changed since: the caller has to send the
 * values that were just written, the row is read again and compared with them.
 * If somebody rated the same card in another tab in the meantime, the undo is
 * refused instead of throwing that newer answer away.
 *
 * A card that had no progress before the rating gets its row removed again; that
 * row was created by the rating that is being taken back, a moment ago, and
 * removing it is the only way back to "never learned".
 *
 * @param array<string, mixed>|null $stored what the rating wrote
 * @param array<string, mixed>|null $previous what was there before the rating
 * @return array{ok: bool, code?: string, message?: string, data?: array<string, mixed>}
 */
function review_undo_rating(PDO $pdo, int $userId, int $cardId, $stored, $previous, int $categoryId): array
{
    $card = find_card($pdo, $cardId);

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

    review_run_in_transaction($pdo, static function () use ($pdo, $userId, $cardId, $previous): void {
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
 * Compares a stored row with the values an undo expects to find.
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

        /* MySQL keeps the second in a DATETIME, so "2026-09-21 15:04:05" comes
           back exactly as it was written and can be compared as text. A value
           that arrives as a number is rounded the same way. */
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
   The learning session
   ------------------------------------------------------------------------- */

/**
 * Builds the queue of one learning session for a subcategory.
 *
 * The order is the one the application promises:
 *   1. cards that are due or overdue,
 *   2. then cards that were never learned,
 *   3. and only when those two are empty, the cards that are not due yet.
 *
 * A card that is meant to be practised both ways appears twice - once as
 * front -> back and once as back -> front. The direction is a property of the
 * session, not of the card: nothing is added to the database for it, and the
 * progress of both turns belongs to the same card id.
 *
 * @param list<array<string, mixed>> $cards the cards of the subcategory, as the API lists them
 * @param array<string, string> $statuses card id => "new" | "unsure" | "known"
 * @param array<int, bool> $isDue card id => is the card due right now
 * @param string $mode "all" or "difficult"
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
         * "Difficult" is a second session with the cards that were answered
         * "Again" or "Hard": those are exactly the cards in the learning state.
         */
        if ($mode === 'difficult' && $status !== 'unsure') {
            continue;
        }

        $entries = review_directions_of($card, $status, $isDue[$cardId] ?? false);

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

    /* The cards that are not due yet come last, and only when there is nothing
       else to do: a session should not be over before it started. */
    if ($queue === []) {
        $queue = $later;
    }

    return ['queue' => $queue, 'counts' => $counts];
}

/**
 * The one or two turns a card has in a session.
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
        /* Only a value that passes the pattern goes to the browser, so the
           session never receives anything it would have to distrust. */
        'map_region' => isset($card['map_region']) && card_map_region_is_valid((string) $card['map_region'])
            ? (string) $card['map_region']
            : null,
        /*
         * The exercise of the card, when it is one. Its task is not a column: it is
         * rolled again every time the card is read, so every session gets new
         * numbers. Without this the session would only receive the title and an
         * empty answer, and the learn card would show exactly that.
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
     * The other way round asks for the answer and shows the task: question and
     * answer change places, everything else stays as it is.
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
 * Every card of every category in ONE read, grouped by category.
 *
 * This is what api/bootstrap.php needs: the browser loads it once and renders the
 * other views out of it. One query instead of one per subcategory, because a
 * subcategory list would otherwise cost forty round trips.
 *
 * The shape of a single card is exactly the shape api/cards.php returns, so the
 * browser does not have to know two of them.
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
          ORDER BY k.category_id ASC, k.id ASC'
    );

    if ($userId === null) {
        $statement->bindValue(':user_id', null, PDO::PARAM_NULL);
    } else {
        $statement->bindValue(':user_id', $userId, PDO::PARAM_INT);
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
