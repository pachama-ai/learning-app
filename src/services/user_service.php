<?php

declare(strict_types=1);

/**
 * Das Anmelden.
 *
 * Das ist die einzige Stelle, an der ein Konto entsteht und an der eine Sitzung eine
 * Konto-Id bekommt. Diese Id ist es, die src/helpers/session_user.php an alles andere
 * weiterreicht, und sie ist der Grund, warum Fortschritt überhaupt gespeichert werden
 * kann: ohne sie zeigt die Anwendung weiter Karten, sie kann sich nur keine Antwort
 * merken.
 *
 * Die Entscheidungen dahinter
 *
 *   * Das Passwort wird nie gespeichert und nie in ein Protokoll geschrieben - nur der
 *     Hash aus password_hash() mit PASSWORD_DEFAULT, geprüft mit password_verify().
 *   * Eine gescheiterte Anmeldung und eine unbekannte Adresse antworten mit DEMSELBEN
 *     Code, und eine gescheiterte Anmeldung wartet einen Moment, bevor sie antwortet.
 *     Weder die Worte noch die Dauer der Antwort verraten, welche Adressen es gibt.
 *   * Die Sitzungs-Id wird erneuert, wenn sich jemand anmeldet, eine Id, die vor dem
 *     Anmelden bekannt war, kann danach also nicht mehr benutzt werden.
 *   * Jede Anfrage an diese Datei trägt den Token aus user_csrf_token(). Er liegt in der
 *     Sitzung, eine andere Seite kann ihn also nicht lesen und niemanden hinter dessen
 *     Rücken anmelden.
 *   * Die Tabelle wird nur durch die prüfbare Datei database/schema.sql erweitert,
 *     die eine Person von Hand laufen lässt. Bis dahin ist user_sign_in_ready() falsch
 *     und jeder Versuch antwortet mit "noch nicht eingerichtet" statt in einen
 *     Datenbankfehler zu laufen.
 *
 * Was diese Datei nie tut: sie schreibt keine Zeile in user_card_progress, sie ändert
 * keine Tabellenstruktur und sie löscht kein Konto.
 */

require_once __DIR__ . '/../helpers/session_user.php';

/** Die Regeln, denen ein Name folgen muss. Die Grenze ist die Breite der Spalte. */
const USER_NAME_MIN_LENGTH = 3;
const USER_NAME_MAX_LENGTH = 100;

/** Die Grenze der E-Mail-Spalte. */
const USER_EMAIL_MAX_LENGTH = 190;

/** Die Regeln, denen ein Passwort folgen muss. */
const USER_PASSWORD_MIN_LENGTH = 8;
const USER_PASSWORD_MAX_LENGTH = 200;

/** Die Rolle, die jedes im Browser angelegte Konto bekommt. */
const USER_DEFAULT_ROLE = 'learner';

/**
 * Die eine Rolle mit erweiterten Rechten.
 *
 * Sie wird NICHT ueber die E-Mail-Adresse entschieden: eine Adresse kann sich
 * aendern oder von jemand anderem registriert werden. Die Rolle steht in der
 * Spalte users.role, und sie ist die einzige Quelle fuer so eine Entscheidung.
 */
const USER_ADMIN_ROLE = 'admin';

/**
 * Wie lange eine gescheiterte Anmeldung wartet, bevor sie antwortet, in Mikrosekunden.
 *
 * Das ist keine Sperre: es verhindert nur, dass die Antwort ein schnelles "diese Adresse
 * gibt es nicht" und ein langsames "das Passwort war falsch" ist.
 */
const USER_FAILED_SIGN_IN_DELAY = 400000;

/**
 * Der Hash, der benutzt wird, wenn es eine Adresse nicht gibt, damit password_verify()
 * in beiden Fällen dieselbe Arbeit tut. Es ist der Hash von nichts, was jemand eintippen
 * kann.
 */
const USER_DUMMY_HASH = '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG';

/* --------------------------------------------------------------------------
   Was die Tabelle users halten kann
   -------------------------------------------------------------------------- */

/**
 * Die echten Spalten der Tabelle users, gelesen aus den Angaben zu einer Abfrage, die
 * keine Zeilen liefert - derselbe Kniff, den card_columns() für die Tabelle cards
 * benutzt.
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
 * Ob diese Spalte in der Tabelle users wirklich existiert.
 *
 * @param list<string> $columns
 */
function user_column_available(array $columns, string $column): bool
{
    return in_array($column, $columns, true);
}

/**
 * Meldet, ob Anmelden möglich ist: die Passwortspalte ist das eine Stück, auf das die
 * Tabelle nicht verzichten kann, und sie kommt mit database/schema.sql.
 */
function user_sign_in_ready(PDO $pdo): bool
{
    return user_column_available(user_columns($pdo), 'password_hash');
}

/* --------------------------------------------------------------------------
   Der Token, den jede Anmelde-Anfrage tragen muss
   -------------------------------------------------------------------------- */

/**
 * Der Token dieser Sitzung, angelegt, wenn er zum ersten Mal gefragt wird.
 *
 * Er reist mit der Seite zum Browser und mit jeder Anmelde-Anfrage zurück. Eine andere
 * Seite kann eine Anfrage schicken, aber sie kann diesen Wert nicht lesen, sie kann also
 * niemanden anmelden (oder abmelden), ohne das Formular anzufassen.
 */
function user_csrf_token(): string
{
    /* Die Sitzung zu starten ist hier ungefährlich: session_user.php startet sie
       ohnehin mit dem httpOnly- und SameSite=Lax-Cookie, für das sie geschrieben
       wurde. */
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

    /* Die Sitzung muss offen sein, bevor man in ihr lesen kann. Eine Anfrage, die nur
       den Token prüft, hat keinen anderen Grund, eine zu starten, und ohne diese Zeile
       liefe der Vergleich immer gegen eine leere Sitzung. */
    session_user_id_from_php_session();

    $expected = $_SESSION['csrf_token'] ?? null;

    return is_string($expected) && $expected !== '' && hash_equals($expected, $token);
}

/* --------------------------------------------------------------------------
   Prüfen, was eingetippt wurde
   -------------------------------------------------------------------------- */

/** Eine Kennung mit einem "@" ist eine E-Mail-Adresse, alles andere ein Name. */
function user_identifier_is_email(string $identifier): bool
{
    return strpos($identifier, '@') !== false;
}

/**
 * Das erste Problem einer Kennung oder null, wenn sie brauchbar ist.
 *
 * Die Codes sind die, die der Browser in einen Satz verwandelt; maßgeblich ist allein der
 * Server, die Prüfungen im Browser sind nur für die schnelle Rückmeldung da.
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
   Ein Konto finden, anlegen und prüfen
   -------------------------------------------------------------------------- */

/**
 * Die Zeile zu einer Kennung oder null.
 *
 * Ein Name wird in der Namensspalte gesucht, eine Adresse in der E-Mail-Spalte - und
 * nach der Adresse wird nur gefragt, wenn die Tabelle diese Spalte wirklich hat.
 */
function user_find(PDO $pdo, string $identifier): ?array
{
    /*
     * SELECT *: die Tabelle hat die Spalten der Migration vielleicht schon oder noch
     * nicht, und gebraucht werden sie hier alle.
     *
     * Der Wert wird absichtlich zweimal gebunden - einmal für den Namen, einmal für die
     * Adresse. PDO mit abgeschalteten emulierten Vorbereitungen (siehe die Verbindung in
     * src/config/database.php) lehnt einen benannten Platzhalter ab, der zweimal in einer
     * Anweisung steht, beide bekommen also ihren eigenen Namen.
     */
    if (user_column_available(user_columns($pdo), 'email')) {
        $statement = $pdo->prepare(
            'SELECT * FROM users WHERE name = :name OR (email IS NOT NULL AND email = :email) LIMIT 1'
        );
        $statement->bindValue(':name', $identifier, PDO::PARAM_STR);
        $statement->bindValue(':email', $identifier, PDO::PARAM_STR);
    } else {
        $statement = $pdo->prepare('SELECT * FROM users WHERE name = :name LIMIT 1');
        $statement->bindValue(':name', $identifier, PDO::PARAM_STR);
    }

    $statement->execute();
    $row = $statement->fetch(PDO::FETCH_ASSOC);

    return $row === false ? null : $row;
}

/**
 * Sucht ein Konto über seine E-Mail-Adresse.
 *
 * Seit die Anmeldung nur noch über die Adresse geht, steht diese Suche getrennt von
 * user_find(): dort wird weiter nach Name ODER Adresse gesucht, weil das Anlegen prüft,
 * ob ein Name schon vergeben ist. Beim Anmelden wäre genau das der zweite Weg ins Konto,
 * den es nicht mehr geben soll.
 *
 * Getrimmt wird hier, und Groß- oder Kleinschreibung spielt keine Rolle: die Spalte steht
 * in utf8mb4_unicode_ci, der Vergleich ignoriert sie also schon.
 *
 * @param PDO $pdo die Datenbankverbindung
 * @param string $email die eingetippte Adresse
 * @return array<string, mixed>|null die Zeile des Kontos oder null
 */
function user_find_by_email(PDO $pdo, string $email): ?array
{
    /* Ohne die Spalte gibt es keine Adresse zu suchen - das kann nur passieren,
       solange die Migration database/schema.sql noch nicht lief. */
    if (!user_column_available(user_columns($pdo), 'email')) {
        return null;
    }

    $statement = $pdo->prepare('SELECT * FROM users WHERE email IS NOT NULL AND email = :email LIMIT 1');
    $statement->bindValue(':email', trim($email), PDO::PARAM_STR);
    $statement->execute();
    $row = $statement->fetch(PDO::FETCH_ASSOC);

    return $row === false ? null : $row;
}

/**
 * Legt ein Konto an.
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

    /* Ohne Adresse gäbe es kein zweites Mal hinein: die Anmeldung kennt nur noch die
       E-Mail. Sie muss also schon beim Anlegen da sein - sonst wäre das neue Konto
       sofort ausgesperrt. */
    if (!user_identifier_is_email($identifier)) {
        return ['ok' => false, 'error' => 'invalid_email', 'user' => null];
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

        /* Der lesbare Name eines Kontos, das mit einer Adresse angelegt wurde: der Teil
           vor dem "@". Er ist es, den die Kopfzeile zeigt und den die Person eintippt,
           wenn sie sich stattdessen mit einem Namen anmeldet. Eine Adresse wie
           "@example.com" hat davor nichts Brauchbares, dort wird die ganze Adresse zum
           Namen. */
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

    /* Auch denselben Namen darf es nicht zweimal geben: der Name ist der andere Weg
       hinein. */
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
        /* Zwei Personen, die in derselben Sekunde denselben Namen wählen: der eindeutige
           Schlüssel antwortet, und das ist ein Satz, den das Formular schon hat. */
        if ((string) $error->getCode() === '23000') {
            return ['ok' => false, 'error' => 'name_exists', 'user' => null];
        }

        throw $error;
    }

    return ['ok' => true, 'error' => null, 'user' => ['id' => (int) $pdo->lastInsertId(), 'name' => $name]];
}

/**
 * Prüft eine Kennung und ein Passwort gegen die Tabelle.
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

    $row = user_find_by_email($pdo, $identifier);
    $hash = $row === null ? USER_DUMMY_HASH : (string) ($row['password_hash'] ?? '');

    if ($hash === '') {
        $hash = USER_DUMMY_HASH;
    }

    /* password_verify() läuft in beiden Fällen, die Dauer der Antwort verrät also
       nichts darüber, ob es die Adresse gibt. */
    if (password_verify($password, $hash) !== true || $row === null) {
        usleep(USER_FAILED_SIGN_IN_DELAY);

        return ['ok' => false, 'error' => 'credentials', 'user' => null];
    }

    return ['ok' => true, 'error' => null, 'user' => ['id' => (int) $row['id'], 'name' => (string) $row['name']]];
}

/**
 * Prüft das Passwort des Kontos selbst.
 *
 * Das ist das Einzige, was zwischen einem offenen Laptop und dem Löschen eines ganzen
 * Lernfortschritts steht, deshalb wird das Konto nach seinem Passwort gefragt und nicht
 * nach seinem Namen: ein Name steht auf dem Bildschirm, ein Passwort nicht.
 *
 * Es benutzt password_verify() gegen den gespeicherten Hash, genau wie die Anmeldung, und
 * dieselbe kurze Pause bei einem falschen Passwort - ein gescheiterter Versuch kostet so
 * viel Zeit wie ein erfolgreicher, niemand erfährt also etwas daraus.
 *
 * Jeder Fall von "es gibt nichts zu vergleichen" antwortet mit false: kein solches Konto,
 * ein leeres Passwort, eine Tabelle ohne die Spalte (siehe database/schema.sql).
 */
function user_password_matches(PDO $pdo, int $userId, string $password): bool
{
    if ($password === '' || !user_sign_in_ready($pdo)) {
        return false;
    }

    $statement = $pdo->prepare('SELECT password_hash FROM users WHERE id = :id');
    $statement->bindValue(':id', $userId, PDO::PARAM_INT);
    $statement->execute();
    $hash = (string) ($statement->fetchColumn() ?: '');

    if ($hash === '' || password_verify($password, $hash) !== true) {
        usleep(USER_FAILED_SIGN_IN_DELAY);

        return false;
    }

    return true;
}

/* --------------------------------------------------------------------------
   Die Sitzung
   -------------------------------------------------------------------------- */

/**
 * Merkt sich, wer angemeldet ist.
 *
 * Das ist die eine Stelle, die $_SESSION['user_id'] schreibt; session_user.php ist
 * die eine Stelle, die sie liest. Sonst muss nichts von der Sitzung wissen.
 *
 * @param array{id: int, name: string} $user
 */
function user_sign_in_session(array $user): void
{
    session_user_id_from_php_session();

    /* Neue Sitzungs-Id, die alte wird weggeworfen (gegen Sitzungsübernahme). */
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $user['id'];
}

/** Vergisst die angemeldete Person und beendet die Sitzung. */
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
   Was die Kopfzeile zeigt
   -------------------------------------------------------------------------- */

/**
 * Das Wenige, was der Browser über eine angemeldete Person wissen darf.
 *
 * @return array{id: int, name: string, initials: string, role?: string}|null
 */
function user_public_data(PDO $pdo, int $userId): ?array
{
    /*
     * SELECT *: die Tabelle kann freiwillige Spalten tragen (eine Adresse, das Datum der
     * Anlage). Jede davon wird unten erst gelesen, nachdem gefragt wurde, ob es sie gibt,
     * derselbe Code dient also einer Tabelle mit und ohne sie.
     */
    $statement = $pdo->prepare('SELECT * FROM users WHERE id = :id');
    $statement->bindValue(':id', $userId, PDO::PARAM_INT);
    $statement->execute();
    $row = $statement->fetch(PDO::FETCH_ASSOC);

    if ($row === false) {
        return null;
    }

    $name = (string) $row['name'];

    $data = ['id' => (int) $row['id'], 'name' => $name, 'initials' => user_initials($name)];

    /*
     * Die zwei leisen Zeilen des Kontomenüs: die Adresse und das Datum der Anlage. Sie
     * werden mit derselben Prüfung gelesen, die jede andere freiwillige Spalte benutzt,
     * eine fehlende Spalte heißt also eine Zeile weniger in der Liste statt eines Fehlers.
     * Keiner der beiden Werte ist für die angemeldete Person ein Geheimnis - es ist ihr
     * eigenes Konto, und nichts davon wird je jemand anderem gezeigt.
     */
    $columns = user_columns($pdo);

    if (user_column_available($columns, 'email') && ($row['email'] ?? null) !== null
        && (string) $row['email'] !== '') {
        $data['email'] = (string) $row['email'];
    }

    if (user_column_available($columns, 'created_at') && ($row['created_at'] ?? null) !== null
        && (string) $row['created_at'] !== '') {
        $data['created_at'] = (string) $row['created_at'];
    }

    /*
     * Die Rolle. Sie entscheidet, ob das Kontofenster den Admin-Bereich zeigt.
     * Das ist Bequemlichkeit und kein Schutz: wer das Markup von Hand aendert,
     * sieht den Knopf, aber api/admin_backup.php prueft die Rolle noch einmal
     * fuer sich, bevor es etwas tut.
     */
    if (user_column_available($columns, 'role') && ($row['role'] ?? null) !== null
        && (string) $row['role'] !== '') {
        $data['role'] = (string) $row['role'];
    }

    return $data;
}

/**
 * Die Rolle eines Kontos, genau so, wie sie in der Tabelle steht.
 *
 * Eine Tabelle ohne die Spalte "role" antwortet mit null. null heisst
 * ausdruecklich "keine Rolle" und ist damit kein Admin.
 *
 * @return string|null null bedeutet "es ist keine Rolle hinterlegt"
 */
function user_role(PDO $pdo, int $userId): ?string
{
    if (!user_column_available(user_columns($pdo), 'role')) {
        return null;
    }

    $statement = $pdo->prepare('SELECT role FROM users WHERE id = :id');
    $statement->bindValue(':id', $userId, PDO::PARAM_INT);
    $statement->execute();
    $role = $statement->fetchColumn();

    if ($role === false || $role === null || (string) $role === '') {
        return null;
    }

    return (string) $role;
}

/**
 * Ob dieses Konto ein Admin ist.
 *
 * Der Vergleich ist streng: "Admin" mit grossem A ist ein anderer Wert als
 * "admin" und oeffnet hier nichts. Genau so ist es gemeint - nur der Wert, den
 * diese Anwendung selbst setzt, gibt die erweiterten Rechte.
 */
function user_is_admin(PDO $pdo, int $userId): bool
{
    return user_role($pdo, $userId) === USER_ADMIN_ROLE;
}

/**
 * Zwei Buchstaben für den kleinen Kreis in der Kopfzeile: der erste Buchstabe der ersten
 * beiden Wörter, "Anna Beispiel" wird also "AB" und "selina.schneider" wird "SS".
 *
 * Ein Name aus einem einzigen Wort gäbe nur einen Buchstaben, und ein Buchstabe in einem
 * Kreis sagt niemandem etwas - also folgt der zweite Buchstabe desselben Wortes, und
 * "Selina" wird zu "SE".
 */
function user_initials(string $name): string
{
    $parts = preg_split('/[\s._-]+/u', trim($name));

    if (!is_array($parts)) {
        $parts = [$name];
    }

    $parts = array_values(array_filter($parts, static function ($part) {
        return $part !== null && $part !== '';
    }));

    $initials = '';

    foreach ($parts as $part) {
        $initials .= mb_strtoupper(mb_substr((string) $part, 0, 1));

        if (mb_strlen($initials) === 2) {
            break;
        }
    }

    /* Ein Wort, bisher ein Buchstabe: den zweiten davon auch noch nehmen. */
    if (mb_strlen($initials) === 1 && count($parts) === 1) {
        $word = (string) $parts[0];

        if (mb_strlen($word) > 1) {
            $initials = mb_strtoupper(mb_substr($word, 0, 2));
        }
    }

    return $initials === '' ? '?' : $initials;
}


/* ---------------------------------------------------------------------------
   Das Ende eines Kontos
   --------------------------------------------------------------------------- */

/**
 * Löscht ein Konto und alles, was dazugehört, in einer Transaktion.
 *
 * Was mitgeht: der Lernfortschritt (user_card_progress) und die Lerneinheiten
 * (study_sessions). Beide hängen mit ON DELETE CASCADE an der Kontozeile, und beide
 * werden hier trotzdem AUSDRÜCKLICH ausgeschrieben - wer diese Funktion liest, sieht also,
 * was verschwindet, statt eine Regel der Struktur nachschlagen zu müssen.
 *
 * Was bleibt: Karten, Kategorien, card_exercises. Sie gehören niemandem, ein gehendes
 * Konto nimmt den anderen also nie Lernmaterial weg.
 *
 * @return bool false, wenn es kein solches Konto (mehr) gibt.
 */
function delete_user_account(PDO $pdo, int $userId): bool
{
    $exists = $pdo->prepare('SELECT COUNT(*) FROM users WHERE id = :id');
    $exists->bindValue(':id', $userId, PDO::PARAM_INT);
    $exists->execute();

    if ((int) $exists->fetchColumn() === 0) {
        return false;
    }

    $pdo->beginTransaction();

    try {
        $progress = $pdo->prepare('DELETE FROM user_card_progress WHERE user_id = :user_id');
        $progress->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $progress->execute();

        $sessions = $pdo->prepare('DELETE FROM study_sessions WHERE user_id = :user_id');
        $sessions->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $sessions->execute();

        $account = $pdo->prepare('DELETE FROM users WHERE id = :id');
        $account->bindValue(':id', $userId, PDO::PARAM_INT);
        $account->execute();

        $pdo->commit();
    } catch (Throwable $error) {
        /* Eine halbe Löschung ist schlimmer als keine: alles geht zurück. */
        $pdo->rollBack();

        throw $error;
    }

    return true;
}
