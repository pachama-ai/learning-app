<?php

declare(strict_types=1);

/**
 * Signing in.
 *
 * This is the only place where an account is created and where a session gets a
 * user id. That id is what src/helpers/session_user.php hands to everything
 * else, and it is the reason progress can be stored at all: without it, the app
 * still shows cards, it just cannot remember an answer.
 *
 * The decisions behind it
 *
 *   * The password is never stored and never written into a log - only the hash
 *     from password_hash() with PASSWORD_DEFAULT, checked with password_verify().
 *   * A failed sign-in and an unknown name answer with the SAME code, and a
 *     failed sign-in waits a moment before answering. Neither the words nor the
 *     time of the answer say which names exist.
 *   * The session id is renewed when somebody signs in, so an id that was known
 *     before the sign-in cannot be used afterwards.
 *   * Every request of this file carries the token from user_csrf_token(). It
 *     lives in the session, so another site cannot read it and cannot sign
 *     anybody in behind their back.
 *   * The table is only extended by the reviewable file
 *     database/add_user_auth.sql, which a person runs by hand. Until then
 *     user_sign_in_ready() is false and every attempt answers "not set up yet"
 *     instead of running into a database error.
 *
 * What this file never does: it writes no user_card_progress row, it changes no
 * table structure, and it deletes no account.
 */

require_once __DIR__ . '/../helpers/session_user.php';

/** The rules a name has to follow. The limit is the width of the column. */
const USER_NAME_MIN_LENGTH = 3;
const USER_NAME_MAX_LENGTH = 100;

/** The limit of the email column. */
const USER_EMAIL_MAX_LENGTH = 190;

/** The rules a password has to follow. */
const USER_PASSWORD_MIN_LENGTH = 8;
const USER_PASSWORD_MAX_LENGTH = 200;

/** The role every account created in the browser gets. */
const USER_DEFAULT_ROLE = 'learner';

/**
 * How long a failed sign-in waits before it answers, in microseconds.
 *
 * This is not a lock-out: it only keeps the answer from being a fast "this name
 * does not exist" and a slow "the password was wrong".
 */
const USER_FAILED_SIGN_IN_DELAY = 400000;

/**
 * The hash used when a name does not exist, so that password_verify() does the
 * same work in both cases. It is a hash of nothing anybody can type.
 */
const USER_DUMMY_HASH = '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG';

/* --------------------------------------------------------------------------
   What the users table can hold
   -------------------------------------------------------------------------- */

/**
 * The real columns of the users table, read from the metadata of a query that
 * returns no rows - the same trick card_columns() uses for the cards table.
 *
 * @return list<string>
 */
function user_columns(PDO $pdo): array
{
    static $columns = null;

    if ($columns === null) {
        $columns = [];
        $statement = $pdo->query('SELECT * FROM users LIMIT 0');

        for ($index = 0; $index < $statement->columnCount(); $index++) {
            $meta = $statement->getColumnMeta($index);

            if (is_array($meta) && isset($meta['name']) && is_string($meta['name'])) {
                $columns[] = $meta['name'];
            }
        }

        if ($columns === []) {
            foreach ($pdo->query('SHOW COLUMNS FROM users')->fetchAll() as $row) {
                $columns[] = (string) $row['Field'];
            }
        }
    }

    return $columns;
}

/**
 * @param list<string> $columns
 */
function user_column_available(array $columns, string $column): bool
{
    return in_array($column, $columns, true);
}

/**
 * Reports whether sign-in is possible: the password column is the one piece the
 * table cannot do without, and it arrives with database/add_user_auth.sql.
 */
function user_sign_in_ready(PDO $pdo): bool
{
    return user_column_available(user_columns($pdo), 'password_hash');
}

/* --------------------------------------------------------------------------
   The token that every sign-in request has to carry
   -------------------------------------------------------------------------- */

/**
 * The token of this session, made when it is asked for the first time.
 *
 * It travels to the browser with the page and back with every sign-in request.
 * Another site can send a request, but it cannot read this value, so it cannot
 * make anybody sign in (or sign out) without touching the form.
 */
function user_csrf_token(): string
{
    /* Starting the session is safe here: session_user.php starts it with the
       httpOnly, SameSite=Lax cookie it was written for anyway. */
    session_user_id_from_php_session();

    if (!isset($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function user_csrf_valid(?string $token): bool
{
    if (!is_string($token) || $token === '') {
        return false;
    }

    /* The session has to be open before it can be read. A request that only
       checks the token has no other reason to start one, and without this line
       the comparison would always run against an empty session. */
    session_user_id_from_php_session();

    $expected = $_SESSION['csrf_token'] ?? null;

    return is_string($expected) && $expected !== '' && hash_equals($expected, $token);
}

/* --------------------------------------------------------------------------
   Checking what was typed
   -------------------------------------------------------------------------- */

/** An identifier with an "@" is an e-mail address, everything else is a name. */
function user_identifier_is_email(string $identifier): bool
{
    return strpos($identifier, '@') !== false;
}

/**
 * The first problem of an identifier, or null when it is usable.
 *
 * The codes are the ones the browser turns into a sentence; the server is the
 * only authority, the checks in the browser are only there for quick feedback.
 */
function user_identifier_problem(string $identifier): ?string
{
    if (trim($identifier) === '') {
        return 'identifier_required';
    }

    if (user_identifier_is_email($identifier)) {
        if (mb_strlen($identifier) > USER_EMAIL_MAX_LENGTH) {
            return 'email_too_long';
        }

        return filter_var($identifier, FILTER_VALIDATE_EMAIL) === false ? 'invalid_email' : null;
    }

    $length = mb_strlen($identifier);

    if ($length < USER_NAME_MIN_LENGTH) {
        return 'name_too_short';
    }

    return $length > USER_NAME_MAX_LENGTH ? 'name_too_long' : null;
}

function user_password_problem(string $password): ?string
{
    if ($password === '') {
        return 'password_required';
    }

    if (mb_strlen($password) < USER_PASSWORD_MIN_LENGTH) {
        return 'password_too_short';
    }

    return mb_strlen($password) > USER_PASSWORD_MAX_LENGTH ? 'password_too_long' : null;
}

/* --------------------------------------------------------------------------
   Finding, creating and checking an account
   -------------------------------------------------------------------------- */

/**
 * The row of an identifier, or null.
 *
 * A name is looked up in the name column, an address in the email column - and
 * the address is only asked for when the table really has that column.
 */
function user_find(PDO $pdo, string $identifier): ?array
{
    /* SELECT *: the table may or may not have the columns of the migration yet,
       and every one of them is wanted here. */
    $sql = user_column_available(user_columns($pdo), 'email')
        ? 'SELECT * FROM users WHERE name = :identifier OR (email IS NOT NULL AND email = :identifier) LIMIT 1'
        : 'SELECT * FROM users WHERE name = :identifier LIMIT 1';

    $statement = $pdo->prepare($sql);
    $statement->bindValue(':identifier', $identifier, PDO::PARAM_STR);
    $statement->execute();
    $row = $statement->fetch(PDO::FETCH_ASSOC);

    return $row === false ? null : $row;
}

/**
 * Creates an account.
 *
 * @return array{ok: bool, error: ?string, user: ?array{id: int, name: string}}
 */
function user_register(PDO $pdo, string $identifier, string $password): array
{
    $identifier = trim($identifier);
    $problem = user_identifier_problem($identifier) ?? user_password_problem($password);

    if ($problem !== null) {
        return ['ok' => false, 'error' => $problem, 'user' => null];
    }

    if (!user_sign_in_ready($pdo)) {
        return ['ok' => false, 'error' => 'sign_in_not_ready', 'user' => null];
    }

    $hasEmail = user_column_available(user_columns($pdo), 'email');
    $email = null;
    $name = $identifier;

    if (user_identifier_is_email($identifier)) {
        if (!$hasEmail) {
            return ['ok' => false, 'error' => 'sign_in_not_ready', 'user' => null];
        }

        $email = $identifier;
        $at = (int) mb_strpos($identifier, '@');
        $local = trim(mb_substr($identifier, 0, $at));

        /* The readable name of an account made with an address: the part in front
           of the "@". It is what the header shows and what the person types when
           they sign in with a name instead. An address like "@example.com" has no
           usable part in front, so the whole address becomes the name. */
        $name = $local === '' ? $identifier : $local;
        $name = mb_substr($name, 0, USER_NAME_MAX_LENGTH);
    }

    if (user_find($pdo, $identifier) !== null) {
        return [
            'ok' => false,
            'error' => $email === null ? 'name_exists' : 'email_exists',
            'user' => null
        ];
    }

    /* The same name may not exist twice either: the name is the other way in. */
    if ($email !== null && user_find($pdo, $name) !== null) {
        return ['ok' => false, 'error' => 'name_exists', 'user' => null];
    }

    $columns = ['name', 'role', 'password_hash'];
    $placeholders = [':name', ':role', ':password_hash'];
    $values = [
        ':name' => $name,
        ':role' => USER_DEFAULT_ROLE,
        ':password_hash' => password_hash($password, PASSWORD_DEFAULT)
    ];

    if ($hasEmail) {
        $columns[] = 'email';
        $placeholders[] = ':email';
        $values[':email'] = $email;
    }

    $statement = $pdo->prepare(
        'INSERT INTO users (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $placeholders) . ')'
    );

    try {
        $statement->execute($values);
    } catch (PDOException $error) {
        /* Two people choosing the same name at the same second: the unique key
           answers, and that is a sentence the form already has. */
        if ((string) $error->getCode() === '23000') {
            return ['ok' => false, 'error' => 'name_exists', 'user' => null];
        }

        throw $error;
    }

    return ['ok' => true, 'error' => null, 'user' => ['id' => (int) $pdo->lastInsertId(), 'name' => $name]];
}

/**
 * Checks an identifier and a password against the table.
 *
 * @return array{ok: bool, error: ?string, user: ?array{id: int, name: string}}
 */
function user_sign_in(PDO $pdo, string $identifier, string $password): array
{
    $identifier = trim($identifier);

    if ($identifier === '' || $password === '') {
        return ['ok' => false, 'error' => 'credentials', 'user' => null];
    }

    if (!user_sign_in_ready($pdo)) {
        return ['ok' => false, 'error' => 'sign_in_not_ready', 'user' => null];
    }

    $row = user_find($pdo, $identifier);
    $hash = $row === null ? USER_DUMMY_HASH : (string) ($row['password_hash'] ?? '');

    if ($hash === '') {
        $hash = USER_DUMMY_HASH;
    }

    /* password_verify() runs in both cases, so the time the answer takes says
       nothing about whether the name exists. */
    if (password_verify($password, $hash) !== true || $row === null) {
        usleep(USER_FAILED_SIGN_IN_DELAY);

        return ['ok' => false, 'error' => 'credentials', 'user' => null];
    }

    return ['ok' => true, 'error' => null, 'user' => ['id' => (int) $row['id'], 'name' => (string) $row['name']]];
}

/* --------------------------------------------------------------------------
   The session
   -------------------------------------------------------------------------- */

/**
 * Remembers who is signed in.
 *
 * This is the one place that writes $_SESSION['user_id']; session_user.php is
 * the one place that reads it. Nothing else has to know about the session.
 *
 * @param array{id: int, name: string} $user
 */
function user_sign_in_session(array $user): void
{
    session_user_id_from_php_session();

    /* New session id, old one thrown away (session fixation). */
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $user['id'];
}

/** Forgets the signed-in user and ends the session. */
function user_sign_out_session(): void
{
    session_user_id_from_php_session();
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => $params['path'],
            'domain' => $params['domain'],
            'secure' => (bool) $params['secure'],
            'httponly' => (bool) $params['httponly'],
            'samesite' => $params['samesite'] ?? 'Lax'
        ]);
    }

    session_destroy();
}

/* --------------------------------------------------------------------------
   What the header shows
   -------------------------------------------------------------------------- */

/**
 * The little that the browser may know about a signed-in user.
 *
 * @return array{id: int, name: string, initials: string}|null
 */
function user_public_data(PDO $pdo, int $userId): ?array
{
    $statement = $pdo->prepare('SELECT id, name FROM users WHERE id = :id');
    $statement->bindValue(':id', $userId, PDO::PARAM_INT);
    $statement->execute();
    $row = $statement->fetch(PDO::FETCH_ASSOC);

    if ($row === false) {
        return null;
    }

    $name = (string) $row['name'];

    return ['id' => (int) $row['id'], 'name' => $name, 'initials' => user_initials($name)];
}

/**
 * One or two letters for the small circle in the header: the first letter of the
 * first two words, so "Anna Beispiel" becomes "AB" and "Anna" stays "A".
 */
function user_initials(string $name): string
{
    $parts = preg_split('/[\s._-]+/u', trim($name));

    if (!is_array($parts)) {
        $parts = [$name];
    }

    $initials = '';

    foreach ($parts as $part) {
        if ($part === '' || $part === null) {
            continue;
        }

        $initials .= mb_strtoupper(mb_substr((string) $part, 0, 1));

        if (mb_strlen($initials) === 2) {
            break;
        }
    }

    return $initials === '' ? '?' : $initials;
}
