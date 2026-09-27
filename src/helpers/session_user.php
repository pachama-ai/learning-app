<?php

declare(strict_types=1);

/**
 * Wer gerade lernt.
 *
 * Der Fortschritt gehoert genau einem Nutzer und steht in user_card_progress
 * unter dessen id. Die id kann nur aus einer echten angemeldeten Sitzung kommen:
 *
 *   - die Anmeldung steckt in public/api/auth.php und src/services/user_service.php.
 *     Nach erfolgreicher Pruefung ruft der Service user_sign_in_session() und
 *     legt die id des Kontos in $_SESSION['user_id'] ab,
 *   - es gibt weiterhin KEINEN Standardnutzer und keinen Rueckfall auf "den
 *     ersten Nutzer". Fortschritt unter einer geratenen id wuerde den Fortschritt
 *     zweier Personen still vermischen - das Einzige, was diese Tabelle nie tun
 *     darf,
 *   - ohne Anmeldung ist die Antwort null, und jedes Schreiben von Fortschritt
 *     antwortet mit 403 no_user_session. Das ist eine klare Antwort und kein
 *     stiller Erfolg.
 *
 * Diese Datei ist die einzige Stelle, die $_SESSION['user_id'] LIEST. Geschrieben
 * wird der Wert in src/services/user_service.php.
 */

/** Wie lange ein Sitzungs-Cookie leben darf, bevor der Browser ihn wegwirft. */
const SESSION_COOKIE_LIFETIME = 60 * 60 * 24 * 30;

/**
 * Liefert die id des angemeldeten Nutzers oder null, wenn niemand angemeldet ist.
 *
 * Die id gilt nur, wenn der Nutzer wirklich in der Tabelle users steht: eine
 * Sitzung kann ihre Zeile ueberleben, und der Fremdschluessel auf
 * user_card_progress wuerde dann jedes Schreiben mit einem unklaren
 * Datenbankfehler ablehnen. Die Pruefung hier macht daraus ein schlichtes "es ist
 * niemand angemeldet".
 *
 * @return int|null null bedeutet "es gibt keine Nutzersitzung"
 */
function current_user_id(PDO $pdo): ?int
{
    /* Fuer den Rest der Anfrage gemerkt: die Antwort kann sich waehrend einer
       laufenden Anfrage nicht aendern. */
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
 * Liest $_SESSION['user_id'] und startet dabei eine Sitzung, wenn es keine gibt.
 *
 * Das beruehrt nur die PHP-Sitzung; die Datenbank bleibt unangetastet und es wird
 * nichts erfunden. Eine Sitzung ohne Nutzer-id ist der Normalfall, solange
 * niemand angemeldet ist - die Antwort ist dann schlicht null.
 */
function session_user_id_from_php_session(): ?int
{
    if (session_status() === PHP_SESSION_NONE) {
        /*
         * Hier wird das Cookie zum ersten Mal gesetzt. httponly haelt es von
         * JavaScript fern, samesite=Lax von fremden Seiten; beides sind die
         * sicheren Voreinstellungen fuer eine Sitzung, die einmal eine Anmeldung
         * tragen wird.
         */
        @ini_set('session.cookie_httponly', '1');
        @ini_set('session.cookie_samesite', 'Lax');
        @ini_set('session.use_strict_mode', '1');
        @session_set_cookie_params(['lifetime' => SESSION_COOKIE_LIFETIME]);

        /* Eine Sitzung, die sich nicht starten laesst (etwa ein kaputter
           Sitzungspfad), darf kein fataler Fehler werden: dann ist eben niemand
           angemeldet, und damit kann die Anwendung umgehen. */
        if (@session_start() === false) {
            return null;
        }
    }

    $value = $_SESSION['user_id'] ?? null;

    /* "7" und 7 werden beide angenommen; alles andere - auch ein fehlender
       Schluessel - bedeutet "kein Nutzer". */
    if (is_string($value) && ctype_digit($value)) {
        $value = (int) $value;
    }

    if (!is_int($value) || $value < 1) {
        return null;
    }

    return $value;
}

/**
 * Meldet, ob die Tabelle users diese id wirklich enthaelt.
 */
function session_user_exists(PDO $pdo, int $userId): bool
{
    $statement = $pdo->prepare('SELECT COUNT(*) FROM users WHERE id = :id');
    $statement->bindValue(':id', $userId, PDO::PARAM_INT);
    $statement->execute();

    return (int) $statement->fetchColumn() > 0;
}

/**
 * Der Fehler, den jedes Schreiben von Fortschritt ohne Anmeldung liefert.
 *
 * Eine klare Antwort statt eines stillen Erfolgs: der Browser zeigt eine
 * deutliche Meldung, statt so zu tun, als waere die Bewertung gespeichert.
 */
function session_user_required_error(): array
{
    return [
        'code' => 'no_user_session',
        'message' => 'There is no signed-in user, so progress cannot be saved.',
        'status' => 403,
    ];
}
