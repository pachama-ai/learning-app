<?php

declare(strict_types=1);

/**
 * The running number the start view shows: how many days in a row somebody
 * studied.
 *
 * It lives in a file of its own because it is the only question left that needs
 * `study_sessions`: the view that used to read that table is gone, and with it
 * its service.
 *
 * This file only reads. It writes nothing, so no call of it can change a card, a
 * category or a progress row, and the database structure is not touched anywhere
 * in here.
 */

require_once __DIR__ . '/study_session_service.php';

/**
 * How far back the streak is counted, at most.
 *
 * This is a limit and not a rule of the game: it only says that the loop below
 * may never run forever, whatever the table holds. Nobody reaches it in practice.
 */
const DASHBOARD_STREAK_MAX_DAYS = 400;

/**
 * How many days in a row this person studied, counting back from today.
 *
 * A day counts when `study_sessions` holds at least one row for this person whose
 * `started_at` falls on that day - the moment the session really began, see
 * database/add_study_sessions.sql. Two sessions on the same day are still one
 * day, which is why the query asks for the distinct dates.
 *
 * With a category the question is about ONE subcategory: the days in a row that
 * this subcategory was studied, whether or not the person studied somewhere else
 * in between. Before the migration that adds `category_id`
 * (database/add_session_category.sql) there is no place in a row, and the answer
 * is the number for the whole person - the caller does not have to know which of
 * the two it gets.
 *
 * The streak counts back from TODAY and stops at the first day without a row. So
 * somebody who studied yesterday but not today has 0 days, not 1: only a row for
 * today makes a streak a running one. That is the honest answer - "yesterday" is
 * not a streak.
 *
 * `available` tells the two zeroes apart:
 *   - false -> there are no sessions for this person (or this subcategory) at all
 *     ("nothing learned yet" is not the same as "0 days in a row")
 *   - true  -> there are sessions, and `days` is the real count (possibly 0)
 *
 * The read may fail: the table comes from a migration that has to be run by hand
 * (database/add_study_sessions.sql), so it can be missing on a machine where that
 * step was not done. That is not an error the interface has to report - it simply
 * has nothing to show, which is the same answer as "no sessions yet".
 *
 * Note on the clock: the dates are compared in PHP's time zone, the same clock
 * PHP uses when it writes `started_at` (see review_due_timestamp() one file over).
 * MySQL is never asked for CURDATE(), so both sides can never disagree.
 *
 * @return array{available: bool, days: int}
 */
function dashboard_streak(PDO $pdo, int $userId, ?int $categoryId = null): array
{
    $byCategory = $categoryId !== null && study_session_has_category($pdo);

    try {
        /*
         * One row per day instead of one row per session: DISTINCT on the date
         * lets the database do the grouping. The LIMIT bounds what PHP has to
         * read even for somebody who studied every single day for years.
         */
        $sql = 'SELECT DISTINCT DATE(started_at) AS studied_on
                  FROM study_sessions
                 WHERE user_id = :user_id'
            . ($byCategory ? ' AND category_id = :category_id' : '')
            . ' ORDER BY studied_on DESC LIMIT ' . DASHBOARD_STREAK_MAX_DAYS;

        $statement = $pdo->prepare($sql);
        $statement->bindValue(':user_id', $userId, PDO::PARAM_INT);

        if ($byCategory) {
            $statement->bindValue(':category_id', $categoryId, PDO::PARAM_INT);
        }

        $statement->execute();

        /*
         * The dates become the KEYS of this array, so the walk below is a lookup
         * and not a search through a list - that is what keeps it cheap.
         */
        $studiedDays = [];

        foreach ($statement->fetchAll() as $row) {
            $studiedDays[(string) $row['studied_on']] = true;
        }

        if ($studiedDays === []) {
            return ['available' => false, 'days' => 0];
        }

        return ['available' => true, 'days' => dashboard_streak_days($studiedDays)];
    } catch (Throwable $error) {
        /* Missing table, no right to read it, server gone: the interface shows
           nothing instead of an error, exactly like an empty table. */
        error_log('Reading the study streak failed: ' . $error->getMessage());

        return ['available' => false, 'days' => 0];
    }
}

/**
 * The same number for every subcategory at once, for the first view of a page.
 *
 * The first view is drawn out of api/bootstrap.php alone, so the answer has to
 * carry the streak of the category the page is about - otherwise the tile has
 * nothing to show until something is written.
 *
 * @return array<string, array{available: bool, days: int}>|null
 *         one entry per subcategory that was ever studied, or null when the
 *         category column does not exist (then there is only the number for the
 *         whole person, see dashboard_streak())
 */
function dashboard_streaks_by_category(PDO $pdo, int $userId): ?array
{
    if (!study_session_has_category($pdo)) {
        return null;
    }

    try {
        /*
         * One read for all of them instead of one read per subcategory. The date
         * bound is the same limit the walk below keeps: older days could never
         * change the answer, so they are not read at all.
         */
        $since = (new DateTimeImmutable('today'))
            ->modify('-' . DASHBOARD_STREAK_MAX_DAYS . ' days')
            ->format('Y-m-d H:i:s');

        $statement = $pdo->prepare(
            'SELECT category_id, DATE(started_at) AS studied_on
               FROM study_sessions
              WHERE user_id = :user_id AND category_id IS NOT NULL AND started_at >= :since
              GROUP BY category_id, studied_on'
        );
        $statement->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $statement->bindValue(':since', $since);
        $statement->execute();

        $daysPerCategory = [];

        foreach ($statement->fetchAll() as $row) {
            $daysPerCategory[(string) (int) $row['category_id']][(string) $row['studied_on']] = true;
        }

        $streaks = [];

        foreach ($daysPerCategory as $categoryId => $studiedDays) {
            $streaks[$categoryId] = [
                'available' => true,
                'days' => dashboard_streak_days($studiedDays),
            ];
        }

        return $streaks;
    } catch (Throwable $error) {
        error_log('Reading the study streaks per category failed: ' . $error->getMessage());

        return null;
    }
}

/**
 * Counts the days in a row from a set of studied days, starting at today.
 *
 * The set has the dates as its keys, so every step of the walk is a lookup. The
 * loop is bounded by the same limit the rest of this file uses, so it can never
 * run forever, whatever the table holds.
 *
 * @param array<string, true> $studiedDays
 */
function dashboard_streak_days(array $studiedDays): int
{
    $days = 0;
    $today = new DateTimeImmutable('today');

    for ($back = 0; $back < DASHBOARD_STREAK_MAX_DAYS; $back++) {
        $day = $today->modify('-' . $back . ' days')->format('Y-m-d');

        if (!isset($studiedDays[$day])) {
            break;
        }

        $days++;
    }

    return $days;
}
