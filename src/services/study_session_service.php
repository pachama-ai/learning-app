<?php

declare(strict_types=1);

/**
 * Die Lernsitzung als Zeile: wann sie anfing, wann sie endete, wie viel passiert ist.
 *
 * Tabelle und Bedeutung jeder Spalte kommen aus database/schema.sql -
 * hier wird keine Spalte erfunden:
 *
 *   started_at     der Moment der ERSTEN Antwort einer Runde. Eine Runde, die
 *                  geöffnet und ohne Antwort geschlossen wird, hinterlässt keine Zeile.
 *   ended_at       wird beim Schließen der Lernansicht gesetzt, NULL solange sie läuft
 *   cards_studied  wie viele Antworten gegeben wurden (eine zweimal beantwortete Karte
 *                  zählt zweimal - das zeigt auch der Zähler in der Ansicht)
 *   cards_known    wie viele davon "Gut" oder "Einfach" waren
 *
 * Warum es diese Zeilen überhaupt braucht: die Serie auf einer Unterseite zählt die
 * Tage mit mindestens einer Sitzung (siehe dashboard_service.php). Ohne einen
 * Schreiber für diese Tabelle wäre die Zahl immer null.
 *
 * Zwei Regeln hält diese Datei ein:
 *
 *   - Die Uhr ist PHPs, nie NOW() oder CURDATE() der Datenbank. Die Serie vergleicht
 *     die Daten in PHPs Zeitzone, beide Seiten lesen also dieselbe Uhr und können sich
 *     nie darüber uneinig sein, zu welchem Tag eine Sitzung gehört.
 *   - Jede Anweisung ist vorbereitet und gebunden. Kein Wert aus einer Anfrage landet
 *     je im SQL-Text.
 */

/**
 * Die zwei Antworten, die "konnte ich" bedeuten: "Gut" und "Einfach".
 *
 * "Schwer" zählt absichtlich nicht - für das Intervall ist es ein Erfolg, aber nicht
 * die Antwort von jemandem, der die Karte wusste (siehe Migration).
 */
const STUDY_SESSION_KNOWN_RATINGS = [3, 4];

/** Das Zeitstempelformat der Spalten - dasselbe wie bei den Fortschrittszeilen. */
function study_session_timestamp(int $now): string
{
    return date('Y-m-d H:i:s', $now);
}

/**
 * Ob die Tabelle die Kategorie-Spalte schon hat.
 *
 * Die Spalte steht in database/schema.sql; sie muss wie jede andere Änderung
 * einmal von Hand ausgeführt werden. Bis dahin läuft die Anwendung genau wie
 * vorher: eine Runde wird ohne Ort geschrieben, und die Serie zählt die ganze Person
 * statt einer Unterkategorie.
 *
 * Die Frage wird einmal pro Anfrage gestellt und gemerkt, die Spaltenliste wird also
 * einmal gelesen und nicht bei jeder Antwort.
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
 * Schreibt eine Antwort in die laufende Sitzung und gibt deren id zurück.
 *
 * Der Browser schickt die id der Runde, in der er ist - oder NULL bei der ERSTEN
 * Antwort einer Runde. Das ist der Moment, in dem die Zeile entsteht; eine Runde ohne
 * eine einzige Antwort hinterlässt also nichts.
 *
 * Eine id, die nicht dieser Person gehört, zu einer schon geschlossenen Runde gehört
 * oder zu einer Runde in einer ANDEREN Unterkategorie, wird wie eine erste Antwort
 * behandelt: es beginnt eine neue Zeile. Eine verlorene oder veraltete id kann also nie
 * in eine fremde Runde, in eine alte oder an die falsche Stelle schreiben.
 *
 * @param int|null $categoryId die Unterkategorie, in der diese Antwort gegeben wurde
 * @return int die id der Runde, zu der diese Antwort gehört
 */
function study_session_record_rating(PDO $pdo, int $userId, ?int $categoryId, ?int $sessionId, int $rating, int $now): int
{
    $known = in_array($rating, STUDY_SESSION_KNOWN_RATINGS, true) ? 1 : 0;
    $withCategory = study_session_has_category($pdo);
    $open = $sessionId === null ? null : study_session_find_open($pdo, $userId, $sessionId, $withCategory ? $categoryId : null);

    if ($open === null) {
        /*
         * Zwei feste Varianten derselben Anweisung, ausgewählt nach der Struktur der
         * Tabelle - und nicht ein Text, in den ein Wert eingesetzt wird: was hier steht,
         * steht in dieser Datei und nirgends sonst.
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
 * Die id einer laufenden Sitzung dieser Person, oder null, wenn es keine gibt.
 *
 * "Laufend" heißt ended_at IS NULL. Eine Zeile mit Ende ist fertig und wird nie wieder
 * beschrieben.
 *
 * Mit einer Kategorie muss die Zeile zur selben Unterkategorie gehören. Eine Zeile von
 * vor der Migration trägt keine und bleibt für immer, was sie ist - ein Lerntag ohne
 * Ort -, statt die Antworten einer späteren Runde zu schlucken.
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
 * Schließt die Runde: ended_at wird einmal gesetzt und nie wieder.
 *
 * Eine Sitzung ohne id wurde nie gestartet (es wurde nichts beantwortet), es gibt also
 * nichts zu schließen und die Antwort ist false.
 *
 * Eine Runde, deren Tab einfach abgeschossen wurde, bleibt für immer offen. Das ist
 * harmlos: die Serie zählt nach started_at, und eine offene Zeile zählt wie jede
 * andere. Es wird nichts geraten und nichts hinter deinem Rücken repariert.
 *
 * @return bool ob wirklich eine laufende Zeile geschlossen wurde
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
 * Nimmt eine Antwort wieder aus der Runde heraus, damit die Zähler nach einem
 * Rückgängigmachen zu dem passen, was die Lernansicht zeigt.
 *
 * Beide Zahlen bleiben bei mindestens null: die Spalten sind unsigned, und ein Zähler,
 * der unter null rutschen würde, ist kein Grund, eine Anfrage scheitern zu lassen.
 *
 * @return bool ob eine Zeile geändert wurde
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
