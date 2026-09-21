<?php

declare(strict_types=1);

/**
 * Who is learning right now.
 *
 * Progress belongs to exactly one user and is stored in user_card_progress with
 * that user's id. The id can only come from a real signed-in session:
 *
 *   - this project has NO login yet. Nothing anywhere calls session_start(), no
 *     file reads $_SESSION, and the users table is empty. That is reported as a
 *     blocker instead of being papered over,
 *   - there is therefore NO default user and NO "first user" fallback. Writing
 *     progress under a guessed id would silently mix up the progress of two
 *     people, which is the one thing this table must never do.
 *
 * As soon as a login exists it has to do exactly one thing: put the id of the
 * signed-in user into $_SESSION['user_id']. Everything else already works,
 * because this file is the only place that ever reads that value.
 *
 * Example of a future login:
 *
 *     session_start();
 *     $_SESSION['user_id'] = $authenticatedUserId;
 */

/** How long a session cookie may live before the browser drops it. */
const SESSION_COOKIE_LIFETIME = 60 * 60 * 24 * 30;

/**
 * Returns the id of the signed-in user, or null when nobody is signed in.
 *
 * The id is only accepted when the user really exists in the users table: a
 * session can outlive its row, and the foreign key on user_card_progress would
 * then reject every write with an unclear database error. Checking here turns
 * that into a plain "nobody is signed in".
 *
 * @return int|null null means "there is no user session"
 */
function current_user_id(PDO $pdo): ?int
{
    /* Remembered for the rest of the request: the answer cannot change while a
       single request is running. */
    static $userId = false;

    if ($userId !== false) {
        return $userId;
    }

    $userId = null;
    $candidate = session_user_id_from_php_session();

    if ($candidate !== null && session_user_exists($pdo, $candidate)) {
        $userId = $candidate;
    }

    return $userId;
}

/**
 * Reads $_SESSION['user_id'], starting a session when there is none.
 *
 * This only touches the PHP session; it writes nothing to the database and
 * invents nothing. An empty session - the normal case today - simply has no
 * user id in it and the answer is null.
 */
function session_user_id_from_php_session(): ?int
{
    if (session_status() === PHP_SESSION_NONE) {
        /*
         * The cookie is set here for the first time. httponly keeps it away from
         * JavaScript and samesite=Lax keeps it off cross-site requests; both are
         * the safe defaults for a session that will one day carry a login.
         */
        @ini_set('session.cookie_httponly', '1');
        @ini_set('session.cookie_samesite', 'Lax');
        @ini_set('session.use_strict_mode', '1');
        @session_set_cookie_params(['lifetime' => SESSION_COOKIE_LIFETIME]);

        /* A session that cannot be started (a broken session path, for example)
           must not turn into a fatal error: nobody is signed in then, and that
           is an answer this application can work with. */
        if (@session_start() === false) {
            return null;
        }
    }

    $value = $_SESSION['user_id'] ?? null;

    /* "7" and 7 are both accepted; anything else, including a missing key, means
       "no user". */
    if (is_string($value) && ctype_digit($value)) {
        $value = (int) $value;
    }

    if (!is_int($value) || $value < 1) {
        return null;
    }

    return $value;
}

/**
 * Reports whether the users table really holds this id.
 */
function session_user_exists(PDO $pdo, int $userId): bool
{
    $statement = $pdo->prepare('SELECT COUNT(*) FROM users WHERE id = :id');
    $statement->bindValue(':id', $userId, PDO::PARAM_INT);
    $statement->execute();

    return (int) $statement->fetchColumn() > 0;
}

/**
 * The error every write to progress answers with when nobody is signed in.
 *
 * It is a definite answer and not a silent success: the browser shows a clear
 * message instead of pretending that the rating was stored.
 */
function session_user_required_error(): array
{
    return [
        'code' => 'no_user_session',
        'message' => 'There is no signed-in user, so progress cannot be saved.',
        'status' => 403,
    ];
}
