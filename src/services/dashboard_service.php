<?php

declare(strict_types=1);

/**
 * Die laufende Nummer der Startansicht: wie viele Tage jemand in Folge gelernt hat.
 *
 * Sie steht in einer eigenen Datei, weil sie die einzige Frage ist, die noch
 * `study_sessions` braucht: die Ansicht, die diese Tabelle früher las, gibt es nicht
 * mehr, und ihren Service damit auch nicht.
 *
 * Diese Datei liest nur. Sie schreibt nichts, kein Aufruf kann also eine Karte, eine
 * Kategorie oder eine Fortschrittszeile ändern, und die Datenbankstruktur wird hier
 * nirgends angefasst.
 */

require_once __DIR__ . '/study_session_service.php';

/**
 * So weit zurück wird die Serie höchstens gezählt.
 *
 * Das ist eine Obergrenze und keine Spielregel: sie sagt nur, dass die Schleife unten
 * nie endlos läuft, egal was in der Tabelle steht. In der Praxis erreicht sie niemand.
 */
const DASHBOARD_STREAK_MAX_DAYS = 400;

/**
 * Wie viele Tage in Folge diese Person gelernt hat, von heute rückwärts gezählt.
 *
 * Ein Tag zählt, wenn `study_sessions` mindestens eine Zeile für diese Person hat, deren
 * `started_at` auf diesen Tag fällt - der Moment, in dem die Sitzung wirklich begann,
 * siehe database/add_study_sessions.sql. Zwei Sitzungen am selben Tag sind trotzdem ein
 * Tag, deshalb fragt die Abfrage nach den verschiedenen Daten.
 *
 * Mit einer Kategorie geht es um EINE Unterkategorie: die Tage in Folge, an denen diese
 * Unterkategorie gelernt wurde - egal ob die Person dazwischen woanders gelernt hat. Vor
 * der Migration, die `category_id` bringt (database/add_session_category.sql), gibt es
 * keinen Ort in einer Zeile, und die Antwort ist die Zahl für die ganze Person; der
 * Aufrufer muss nicht wissen, welche der beiden er bekommt.
 *
 * Gezählt wird von HEUTE rückwärts und beim ersten Tag ohne Zeile gestoppt. Wer gestern
 * gelernt hat, heute aber nicht, hat also 0 Tage und nicht 1: nur eine Zeile für heute
 * macht aus einer Serie eine laufende. Das ist die ehrliche Antwort - "gestern" ist
 * keine Serie.
 *
 * `available` unterscheidet die zwei Nullen:
 *   - false -> es gibt für diese Person (oder diese Unterkategorie) überhaupt keine
 *     Sitzungen ("noch nichts gelernt" ist nicht dasselbe wie "0 Tage in Folge")
 *   - true  -> es gibt Sitzungen, und `days` ist die echte Zahl (auch 0 möglich)
 *
 * Das Lesen kann scheitern: die Tabelle kommt aus einer Migration, die von Hand
 * ausgeführt werden muss (database/add_study_sessions.sql), sie kann auf einem Rechner
 * also fehlen, wo dieser Schritt nicht gemacht wurde. Das ist kein Fehler, den die
 * Oberfläche melden muss - sie hat dann einfach nichts zu zeigen, was dieselbe Antwort
 * ist wie "noch keine Sitzungen".
 *
 * Zur Uhr: die Daten werden in PHPs Zeitzone verglichen, derselben Uhr, mit der PHP
 * `started_at` schreibt (siehe review_due_timestamp() eine Datei weiter). MySQL wird nie
 * nach CURDATE() gefragt, beide Seiten können sich also nicht widersprechen.
 *
 * @return array{available: bool, days: int}
 */
function dashboard_streak(PDO $pdo, int $userId, ?int $categoryId = null): array
{
    $byCategory = $categoryId !== null && study_session_has_category($pdo);

    try {
        /*
         * Eine Zeile pro Tag statt einer pro Sitzung: DISTINCT auf dem Datum lässt die
         * Datenbank gruppieren. Das LIMIT begrenzt, was PHP lesen muss - auch für
         * jemanden, der jahrelang jeden Tag gelernt hat.
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
         * Die Daten werden die SCHLÜSSEL dieses Arrays, der Durchlauf unten ist also ein
         * Nachschlagen und keine Suche in einer Liste - das hält es billig.
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
        /* Fehlende Tabelle, kein Leserecht, Server weg: die Oberfläche zeigt nichts
           statt eines Fehlers, genau wie bei einer leeren Tabelle. */
        error_log('Reading the study streak failed: ' . $error->getMessage());

        return ['available' => false, 'days' => 0];
    }
}

/**
 * Dieselbe Zahl für jede Unterkategorie auf einmal, für die erste Ansicht einer Seite.
 *
 * Die erste Ansicht wird allein aus api/bootstrap.php gezeichnet, die Antwort muss die
 * Serie der Kategorie also mitbringen, um die es auf der Seite geht - sonst hätte die
 * Kachel nichts zu zeigen, bis etwas geschrieben wird.
 *
 * @return array<string, array{available: bool, days: int}>|null
 *         ein Eintrag pro Unterkategorie, in der je gelernt wurde, oder null, wenn es die
 *         Kategorie-Spalte nicht gibt (dann gibt es nur die Zahl für die ganze Person,
 *         siehe dashboard_streak())
 */
function dashboard_streaks_by_category(PDO $pdo, int $userId): ?array
{
    if (!study_session_has_category($pdo)) {
        return null;
    }

    try {
        /*
         * Ein Lesevorgang für alle statt einer pro Unterkategorie. Die Datumsgrenze ist
         * dieselbe Obergrenze, die der Durchlauf unten einhält: ältere Tage könnten die
         * Antwort nie ändern, sie werden also gar nicht gelesen.
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
 * Zählt die Tage in Folge aus einer Menge gelernter Tage, beginnend bei heute.
 *
 * Die Menge hat die Daten als Schlüssel, jeder Schritt des Durchlaufs ist also ein
 * Nachschlagen. Die Schleife ist durch dieselbe Obergrenze begrenzt wie der Rest der
 * Datei, sie kann also nie endlos laufen, egal was in der Tabelle steht.
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
