<?php

declare(strict_types=1);

/**
 * The learning session as a row: when it began, when it ended, how much happened.
 *
 * The table and the meaning of every column come from
 * database/add_study_sessions.sql - nothing here invents a column:
 *
 *   started_at     the moment of the FIRST answer of a run. A run that is opened
 *                  and closed again without answering anything leaves no row.
 *   ended_at       set when the learning view is closed, NULL while the run is on
 *   cards_studied  how many answers were given (a card answered twice counts
 *                  twice, which is what the counter in the view shows)
 *   cards_known    how many of them were "Good" or "Easy"
 *
 * Why these rows are needed at all: the number of days in a row on a subcategory
 * page counts the days that hold at least one session (see dashboard_service.php).
 * Without a writer for this table that number can only ever be zero.
 *
 * Two rules this file keeps:
 *
 *   - The clock is PHP's, never NOW() or CURDATE() of the database. The streak
 *     compares the dates in PHP's time zone, so both sides read the same clock and
 *     can never disagree about which day a session belongs to.
 *   - Every statement is prepared and bound. No value of a request is ever put
 *     into SQL text.
 */

/**
 * The two answers that mean "I knew it": "Good" and "Easy".
 *
 * "Hard" deliberately does not count - it is a success for the interval, but not
 * the answer of somebody who knew the card (see the migration).
 */
const STUDY_SESSION_KNOWN_RATINGS = [3, 4];

/** The timestamp format of the columns - the same one the progress rows use. */
function study_session_timestamp(int $now): string
{
    return date('Y-m-d H:i:s', $now);
}

/**
 * Whether the table has the category column yet.
 *
 * The column comes from database/add_session_category.sql, a structure change that
 * has to be run by hand like every other one. Until then the application works
 * exactly as before: a run is written without a place, and the number of days in a
 * row counts the whole person instead of one subcategory.
 *
 * The question is asked once per request and remembered, so the column list is
 * read once and not on every single answer.
 */
function study_session_has_category(PDO $pdo): bool
{
    static $hasColumn = null;

    if ($hasColumn === null) {
        $statement = $pdo->query("SHOW COLUMNS FROM study_sessions LIKE 'category_id'");
        $hasColumn = $statement !== false && $statement->fetch() !== false;
    }

    return $hasColumn;
}

/**
 * Writes one answer into the running session and answers with its id.
 *
 * The browser sends the id of the run it is in, or NULL with the FIRST answer of
 * a run - that is the moment the row is created, so a run without a single answer
 * leaves nothing behind.
 *
 * An id that does not belong to this person, one of a run that was already closed
 * or one of a run in ANOTHER subcategory is treated like a first answer: a new row
 * starts. So a lost or stale id can never write into somebody else's run, into an
 * old one, or into the wrong place.
 *
 * @param int|null $categoryId the subcategory this answer was given in
 * @return int the id of the run this answer belongs to
 */
function study_session_record_rating(PDO $pdo, int $userId, ?int $categoryId, ?int $sessionId, int $rating, int $now): int
{
    $known = in_array($rating, STUDY_SESSION_KNOWN_RATINGS, true) ? 1 : 0;
    $withCategory = study_session_has_category($pdo);
    $open = $sessionId === null ? null : study_session_find_open($pdo, $userId, $sessionId, $withCategory ? $categoryId : null);

    if ($open === null) {
        /*
         * Two fixed variants of the same statement, chosen by the structure of the
         * table - and not one text that a value is put into: what stands here is
         * written in this file and nowhere else.
         */
        $statement = $pdo->prepare(
            $withCategory
                ? 'INSERT INTO study_sessions (user_id, category_id, started_at, ended_at, cards_studied, cards_known)
                   VALUES (:user_id, :category_id, :started_at, NULL, 1, :cards_known)'
                : 'INSERT INTO study_sessions (user_id, started_at, ended_at, cards_studied, cards_known)
                   VALUES (:user_id, :started_at, NULL, 1, :cards_known)'
        );
        $statement->bindValue(':user_id', $userId, PDO::PARAM_INT);

        if ($withCategory) {
            $statement->bindValue(':category_id', $categoryId, $categoryId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        }

        $statement->bindValue(':started_at', study_session_timestamp($now));
        $statement->bindValue(':cards_known', $known, PDO::PARAM_INT);
        $statement->execute();

        return (int) $pdo->lastInsertId();
    }

    $statement = $pdo->prepare(
        'UPDATE study_sessions
            SET cards_studied = cards_studied + 1,
                cards_known = cards_known + :cards_known
          WHERE id = :id AND user_id = :user_id'
    );
    $statement->bindValue(':cards_known', $known, PDO::PARAM_INT);
    $statement->bindValue(':id', $open, PDO::PARAM_INT);
    $statement->bindValue(':user_id', $userId, PDO::PARAM_INT);
    $statement->execute();

    return $open;
}

/**
 * The id of a running session of this person, or null when there is none.
 *
 * "Running" means ended_at IS NULL. A row with an end is finished and is never
 * written into again.
 *
 * With a category the row has to belong to the same subcategory. A row from
 * before the migration carries none, and it stays for ever what it is - a day of
 * learning without a place - instead of swallowing the answers of a later run.
 */
function study_session_find_open(PDO $pdo, int $userId, int $sessionId, ?int $categoryId = null): ?int
{
    $sql = 'SELECT id
              FROM study_sessions
             WHERE id = :id AND user_id = :user_id AND ended_at IS NULL';

    if ($categoryId !== null) {
        $sql .= ' AND category_id = :category_id';
    }

    $statement = $pdo->prepare($sql);
    $statement->bindValue(':id', $sessionId, PDO::PARAM_INT);
    $statement->bindValue(':user_id', $userId, PDO::PARAM_INT);

    if ($categoryId !== null) {
        $statement->bindValue(':category_id', $categoryId, PDO::PARAM_INT);
    }

    $statement->execute();

    $row = $statement->fetch();

    return $row === false ? null : (int) $row['id'];
}

/**
 * Closes the run: ended_at is set once and never again.
 *
 * A session without an id was never started (nothing was answered), so there is
 * nothing to close and the answer is false.
 *
 * A run whose tab was simply killed stays open for ever. That is harmless: the
 * streak counts by started_at, and an open row counts like any other. Nothing is
 * guessed and nothing is repaired behind your back.
 *
 * @return bool whether a running row was really closed
 */
function study_session_close(PDO $pdo, int $userId, ?int $sessionId, int $now): bool
{
    if ($sessionId === null) {
        return false;
    }

    $statement = $pdo->prepare(
        'UPDATE study_sessions
            SET ended_at = :ended_at
          WHERE id = :id AND user_id = :user_id AND ended_at IS NULL'
    );
    $statement->bindValue(':ended_at', study_session_timestamp($now));
    $statement->bindValue(':id', $sessionId, PDO::PARAM_INT);
    $statement->bindValue(':user_id', $userId, PDO::PARAM_INT);
    $statement->execute();

    return $statement->rowCount() === 1;
}

/**
 * Takes one answer back out of the run, so the counters keep matching what the
 * learning view shows after an undo.
 *
 * Both numbers are floored at zero: the columns are unsigned, and a counter that
 * would go below zero is not a reason to fail a request.
 *
 * @return bool whether a row was changed
 */
function study_session_take_back_rating(PDO $pdo, int $userId, ?int $sessionId, int $rating): bool
{
    if ($sessionId === null) {
        return false;
    }

    $known = in_array($rating, STUDY_SESSION_KNOWN_RATINGS, true) ? 1 : 0;

    $statement = $pdo->prepare(
        'UPDATE study_sessions
            SET cards_studied = GREATEST(cards_studied - 1, 0),
                cards_known = GREATEST(cards_known - :cards_known, 0)
          WHERE id = :id AND user_id = :user_id'
    );
    $statement->bindValue(':cards_known', $known, PDO::PARAM_INT);
    $statement->bindValue(':id', $sessionId, PDO::PARAM_INT);
    $statement->bindValue(':user_id', $userId, PDO::PARAM_INT);
    $statement->execute();

    return $statement->rowCount() === 1;
}
