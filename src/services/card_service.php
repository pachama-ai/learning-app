<?php

declare(strict_types=1);

/**
 * Die Datenbankabfragen zur Tabelle `cards` (den Lernkarten).
 *
 * Geprüfte Struktur (mit SHOW COLUMNS nachgesehen):
 *   id               int unsigned, NOT NULL, Primärschlüssel, auto_increment
 *   category_id      int unsigned, NOT NULL, Fremdschlüssel auf categories.id
 *   front            text, NOT NULL
 *   back             text, NOT NULL
 *   is_bidirectional tinyint(1), NOT NULL, Vorgabe 0
 *
 * Eine Spalte mit Zeitstempel gibt es nicht, die Reihenfolge der Liste ist also die
 * Reihenfolge der Ids: die zuerst angelegte Karte steht vorn.
 *
 * Der Fremdschlüssel fk_cards_category ist ON DELETE RESTRICT, die Datenbank weigert
 * sich also selbst, eine Kategorie zu löschen, in der noch Karten liegen. Deshalb
 * löscht das Löschen einer Kategorie zuerst ihre Karten (siehe category_service.php).
 *
 * user_card_progress zeigt mit ON DELETE CASCADE auf eine Karte, der Lernfortschritt
 * einer Karte verschwindet also zusammen mit der Karte. Diese Regel gehört zur
 * bestehenden Struktur und wurde nicht geändert.
 *
 * Eine Karte kann auch eine Übungskarte sein: statt einer Frage und einer Antwort, die
 * jemand geschrieben hat, zeigt sie eine Aufgabe, die beim Anzeigen der Karte gebaut
 * wird, mit Zahlen, die jedes Mal neu gezogen werden. Das gehört zur Tabelle
 * `card_exercises`, die unten gelesen und geschrieben wird.
 */

require_once __DIR__ . '/exercise_service.php';
require_once __DIR__ . '/category_service.php';
require_once __DIR__ . '/../helpers/request_input.php';
require_once __DIR__ . '/../helpers/session_variant.php';

/** Längster Text, der für die Vorderseite oder die Rückseite einer Karte angenommen wird. */
const CARD_MAX_TEXT_LENGTH = 2000;

/**
 * Macht aus einer Datenbankzeile die Form, die die API verspricht.
 *
 * @param array<string, mixed> $row
 * @return array{id: int, category_id: int, front: string, back: string, is_bidirectional: bool}
 */
function normalize_card_row(array $row): array
{
    return [
        'id' => (int) $row['id'],
        'category_id' => (int) $row['category_id'],
        'front' => (string) $row['front'],
        'back' => (string) $row['back'],
        // JSON hat echte Wahrheitswerte, das tinyint wird hier also zu true oder false.
        'is_bidirectional' => (int) $row['is_bidirectional'] === 1,
        /*
         * Die Kartenregion ist entweder ein Schlüssel wie "DE:Bayern" oder gar nichts -
         * eine leere Spalte und ein Wert, der nicht zum Muster passt, heißen beide
         * "keine Karte". Der Wert wird nirgends als Markup gedeutet; er dient nur dazu,
         * ein Element in einer festen Kartendatei zu suchen.
         */
        'map_region' => isset($row['map_region']) && card_map_region_is_valid((string) $row['map_region'])
            ? (string) $row['map_region']
            : null,
        /*
         * Eine Aufgabe oder gar nichts. Die Aufgabe selbst wird nirgends gespeichert:
         * sie entsteht hier aus der Art der Aufgabe und dem Bereich, ihre Zahlen sind
         * also bei jedem Lesen neu. Eine Zeile, deren exercise_type keine der Arten ist,
         * die diese Anwendung kennt (eine ältere Zeile oder eine von Hand geänderte),
         * ist eine Karte ohne Aufgabe und wird einfach als feste Karte gezeigt.
         */
        'exercise' => card_exercise_from_row($row),
    ];
}

/**
 * Liefert eine Karte dieses Kontos oder null, wenn es sie dort nicht gibt.
 *
 * `cards` hat absichtlich keine eigene Besitzerspalte: eine Karte liegt immer in genau
 * einer Kategorie, der Besitzer der Kategorie ist also schon der Besitzer der Karte.
 * Eine Wahrheit statt zweier, die auseinanderlaufen können.
 *
 * @return array{id: int, category_id: int, front: string, back: string, is_bidirectional: bool}|null
 */
function find_card(PDO $pdo, int $cardId, int $ownerUserId): ?array
{
    $statement = $pdo->prepare(
        'SELECT ' . implode(', ', card_read_columns($pdo)) . '
           FROM cards' . card_exercise_join($pdo) . '
          WHERE id = :id
            AND category_id IN (SELECT id FROM categories WHERE owner_user_id = :owner_user_id)'
    );
    $statement->bindValue(':id', $cardId, PDO::PARAM_INT);
    $statement->bindValue(':owner_user_id', $ownerUserId, PDO::PARAM_INT);
    $statement->execute();

    $row = $statement->fetch();

    return $row === false ? null : normalize_card_row($row);
}

/**
 * Ändert die angegebenen Felder einer Karte.
 *
 * Angenommen werden nur Schlüssel, die es wirklich als Spalte gibt; ein Wert aus dem
 * Anfrage-Inhalt kann also nie Teil des SQL-Textes werden. Eine leere Liste von
 * Änderungen liefert die Karte einfach unverändert zurück.
 *
 * @param array<string, mixed> $changes Werte, nach Spaltenname abgelegt. Der Schlüssel
 *        "exercise" ist die eine Ausnahme: er ist keine Spalte dieser Tabelle,
 *        sondern die Aufgabe, die zu der Karte gehört.
 * @return array{id: int, category_id: int, front: string, back: string, is_bidirectional: bool}|null
 */
function update_card(PDO $pdo, int $cardId, array $changes, int $ownerUserId): ?array
{
    $available = card_columns($pdo);
    $pairs = card_language_columns($available);
    $columns = ['is_bidirectional' => PDO::PARAM_INT];

    if (card_column_available($available, 'map_region')) {
        $columns['map_region'] = PDO::PARAM_STR;
    }

    foreach ($pairs as $pair) {
        foreach ($pair as $column) {
            $columns[$column] = PDO::PARAM_STR;
        }
    }

    $assignments = [];
    $values = [];

    foreach ($columns as $column => $type) {
        if (!array_key_exists($column, $changes)) {
            continue;
        }

        $assignments[] = $column . ' = :' . $column;
        $values[$column] = [$changes[$column], $type];
    }

    /*
     * Eine Änderung der deutschen Seite wird auf front und back gespiegelt: die beiden
     * sind NOT NULL und ältere Leser benutzen sie noch, sie einfach liegen zu lassen
     * würde die beiden Fassungen des deutschen Textes auseinanderlaufen lassen.
     */
    foreach (['front' => 0, 'back' => 1] as $column => $index) {
        $germanColumn = $pairs['de'][$index] ?? null;

        if ($germanColumn === null || $column === $germanColumn || !card_column_available($available, $column)) {
            continue;
        }

        if (array_key_exists($germanColumn, $changes)) {
            $assignments[] = $column . ' = :' . $column;
            $values[$column] = [(string) $changes[$germanColumn], PDO::PARAM_STR];
        }
    }

    /*
     * Eine Aufgabe ist keine Spalte dieser Tabelle, sie taucht in der Zuweisungsliste
     * also nie auf. Eine Karte, bei der sich nur die Aufgabe ändert, darf hier nicht
     * übersprungen werden.
     */
    $exerciseChange = array_key_exists('exercise', $changes);

    if ($assignments === [] && !$exerciseChange) {
        return find_card($pdo, $cardId, $ownerUserId);
    }


    /*
     * Die Spalten und die Aufgabe werden zusammen geschrieben oder gar nicht: eine
     * halb gelungene Änderung hinterließe eine Karte, die weder das Alte noch das Neue
     * zeigt. Eine Transaktion, die der Aufrufer schon begonnen hat, bleibt in Ruhe.
     */
    $ownsTransaction = !$pdo->inTransaction();

    if ($ownsTransaction) {
        $pdo->beginTransaction();
    }

    try {
        if ($assignments !== []) {
            $statement = $pdo->prepare(
                'UPDATE cards SET ' . implode(', ', $assignments) . '
                  WHERE id = :id
                    AND category_id IN (SELECT id FROM categories WHERE owner_user_id = :owner_user_id)'
            );
            $statement->bindValue(':id', $cardId, PDO::PARAM_INT);
            $statement->bindValue(':owner_user_id', $ownerUserId, PDO::PARAM_INT);

            foreach ($values as $column => [$value, $type]) {
                if ($column === 'is_bidirectional') {
                    $statement->bindValue(':' . $column, $value ? 1 : 0, $type);
                    continue;
                }

                /* Eine leere map_region heißt "keine Karte" und muss NULL sein, nicht "". */
                if ($column === 'map_region') {
                    if ($value === null || $value === '' || !card_map_region_is_valid((string) $value)) {
                        $statement->bindValue(':' . $column, null, PDO::PARAM_NULL);
                    } else {
                        $statement->bindValue(':' . $column, (string) $value, PDO::PARAM_STR);
                    }

                    continue;
                }

                $statement->bindValue(':' . $column, (string) $value, $type);
            }

            $statement->execute();
        }

        if ($exerciseChange) {
            save_card_exercise($pdo, $cardId, $changes['exercise']);
        }

        $updated = find_card($pdo, $cardId, $ownerUserId);
    } catch (Throwable $error) {
        if ($ownsTransaction) {
            $pdo->rollBack();
        }

        throw $error;
    }

    if ($ownsTransaction) {
        $pdo->commit();
    }

    return $updated;
}


/**
 * Löscht eine Karte. Liefert false, wenn es nichts zu löschen gab.
 *
 * Die Fortschrittszeilen dieser Karte entfernt die Datenbank selbst
 * (fk_progress_card ist ON DELETE CASCADE).
 */
function delete_card(PDO $pdo, int $cardId, int $ownerUserId): bool
{
    $statement = $pdo->prepare(
        'DELETE FROM cards
          WHERE id = :id
            AND category_id IN (SELECT id FROM categories WHERE owner_user_id = :owner_user_id)'
    );
    $statement->bindValue(':id', $cardId, PDO::PARAM_INT);
    $statement->bindValue(':owner_user_id', $ownerUserId, PDO::PARAM_INT);
    $statement->execute();

    return $statement->rowCount() > 0;
}

/**
 * Löscht den Lernfortschritt aller Karten der angegebenen Kategorien.
 *
 * Das ist der erste Schritt beim Löschen einer Kategorie. Der Fremdschlüssel auf
 * user_card_progress.card_id ist ON DELETE CASCADE und würde diese Zeilen von selbst
 * entfernen, die Reihenfolge wird aber ausgeschrieben und ausdrücklich ausgeführt:
 * wer eine Kategorie löscht, soll die ganze Reihenfolge an einer Stelle lesen können,
 * und eine Datenbank ohne diese Kaskade verhält sich genauso.
 *
 * Läuft in der Transaktion des Aufrufers. Angefasst werden nur die Fortschrittszeilen
 * der Karten in den angegebenen Kategorien - nie der Fortschritt einer anderen Karte
 * und nie ein Konto.
 *
 * @param list<int> $categoryIds
 * @return int Wie viele Fortschrittszeilen entfernt wurden.
 */
function delete_progress_of_categories(PDO $pdo, array $categoryIds, int $ownerUserId): int
{
    if ($categoryIds === []) {
        return 0;
    }

    $placeholders = [];
    $ids = [];

    foreach (array_values($categoryIds) as $index => $categoryId) {
        $placeholders[] = ':id' . $index;
        $ids[':id' . $index] = (int) $categoryId;
    }

    /*
     * Die Unterabfrage benennt die Karten des Teilbaums, nur deren Fortschrittszeilen
     * sind also Teil dieser Anweisung.
     *
     * Sie verbindet außerdem die Kategorie jeder Karte, obwohl die Ids schon aus einem
     * Teilbaum kommen, der für dieses Konto gelesen wurde. Diese zweite Absicherung ist
     * Absicht: ein Löschen ist der eine Vorgang, den eine spätere Prüfung nicht mehr
     * rückgängig machen kann, es verlässt sich also nicht darauf, dass sein Aufrufer
     * richtig gefiltert hat.
     */
    $statement = $pdo->prepare(
        'DELETE FROM user_card_progress'
        . ' WHERE card_id IN ('
        . '   SELECT k.id'
        . '     FROM cards AS k'
        . '     JOIN categories AS c ON c.id = k.category_id'
        . '    WHERE k.category_id IN (' . implode(', ', $placeholders) . ')'
        . '      AND c.owner_user_id = :owner_user_id'
        . ' )'
    );

    $statement->bindValue(':owner_user_id', $ownerUserId, PDO::PARAM_INT);

    foreach ($ids as $placeholder => $id) {
        $statement->bindValue($placeholder, $id, PDO::PARAM_INT);
    }

    $statement->execute();

    return $statement->rowCount();
}

/* -------------------------------------------------------------------------
   Die Aufgabe einer Karte
   ------------------------------------------------------------------------- */

/*
 * Eine Karte kann eine Aufgabe tragen statt einer Frage und einer Antwort, die jemand
 * geschrieben hat. Welche Art von Aufgabe es ist und zwischen welchen Zahlen sie lebt,
 * steht in der Tabelle `card_exercises`: höchstens eine Zeile je Karte, weil card_id
 * der Primärschlüssel dieser Tabelle ist.
 *
 * Die Tabelle ist in dem Sinn freiwillig: eine Installation, in der sie fehlt
 * (database/schema.sql muss einmal ausgeführt werden), arbeitet genau wie vorher.
 * Deshalb wird einmal je Anfrage nachgesehen, und deshalb verbinden die Leseabfragen
 * sie nur, wenn es sie wirklich gibt.
 */

/**
 * Ob die Tabelle `card_exercises` in dieser Datenbank existiert.
 *
 * Einmal je Anfrage gefragt und dann gemerkt, wie die freiwilligen Spalten von `cards`
 * und `categories`.
 */
function card_exercise_table_available(PDO $pdo): bool
{
    static $available = null;

    if ($available === null) {
        $statement = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table'
        );
        $statement->bindValue(':table', 'card_exercises', PDO::PARAM_STR);
        $statement->execute();

        $available = (int) $statement->fetchColumn() > 0;
    }

    return $available;
}

/**
 * Ob die Spalte existiert, die die Zahlen einer Aufgabe hält.
 *
 * Die Zahlen kamen später als die Tabelle: eine Installation, in der die Tabelle
 * `card_exercises` die Spalte `exercise_params` noch nicht hat (also vor
 * database/schema.sql eingerichtet wurde), kann ihre Karten weiterhin lesen (eine
 * Karte zeigt ihre Aufgabe dann mit den Vorgabezahlen), sie kann eine Aufgabe
 * aber nicht speichern. Einmal je Anfrage gefragt, wie die Tabelle selbst.
 */
function card_exercise_params_available(PDO $pdo): bool
{
    static $available = null;

    if ($available === null) {
        $statement = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND COLUMN_NAME = :column'
        );
        $statement->bindValue(':table', 'card_exercises', PDO::PARAM_STR);
        $statement->bindValue(':column', 'exercise_params', PDO::PARAM_STR);
        $statement->execute();

        $available = (int) $statement->fetchColumn() > 0;
    }

    return $available;
}

/**
 * Die Verbindung (JOIN), die die Aufgabe einer Karte in eine Abfrage holt.
 *
 * Eine leere Zeichenkette, wenn es die Tabelle nicht gibt, ein Abfragetext arbeitet also
 * mit und ohne die Migration. Der Name der Kartentabelle wird gegen die zwei
 * Schreibweisen geprüft, die diese Anwendung benutzt, nichts aus einer Anfrage kann also
 * je in den Abfragetext gelangen.
 */
function card_exercise_join(PDO $pdo, string $cardTable = 'cards'): string
{
    if (!card_exercise_table_available($pdo)) {
        return '';
    }

    $alias = in_array($cardTable, ['cards', 'k'], true) ? $cardTable : 'cards';

    return ' LEFT JOIN card_exercises ON card_exercises.card_id = ' . $alias . '.id';
}

/**
 * Die Aufgabe einer Kartenzeile oder null, wenn die Karte eine feste Karte ist.
 *
 * @param array<string, mixed> $row
 * @return array<string, mixed>|null
 */
function card_exercise_from_row(array $row): ?array
{
    $type = isset($row['exercise_type']) ? (string) $row['exercise_type'] : '';

    /* Ein unbekannter Schlüssel ist kein Fehler: die Karte wird als feste Karte gezeigt. */
    if ($type === '' || !exercise_type_is_known($type)) {
        return null;
    }

    /*
     * Die Zahlen, die wirklich gelten, nicht die rohe Spalte: ein von Hand geänderter
     * Wert oder einer, der älter ist als die Grenzen seiner Aufgabenart, wird in diese
     * hineingezogen, und die Oberfläche zeigt, womit die Aufgabe wirklich arbeitet.
     */
    $params = exercise_normalise_params($type, card_exercise_params_from_row($row));

    return [
        'type' => $type,
        'label' => exercise_type_label($type),
        'params' => $params,
        /* Hier und jetzt gebaut, die Zahlen sind bei jedem Lesen also neu. */
        'task' => exercise_build_task($type, $params),
    ];
}

/**
 * Die Zahlen einer Aufgaben-Zeile, so wie sie gespeichert wurden.
 *
 * Alles, was kein JSON-Objekt ist, gilt als "nichts gespeichert": dann greifen die
 * Vorgaben dieser Aufgabenart, und genau so sieht eine Zeile von vor der Migration aus.
 *
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function card_exercise_params_from_row(array $row): array
{
    $raw = $row['exercise_params'] ?? null;

    if (!is_string($raw) || $raw === '') {
        return [];
    }

    $decoded = json_decode($raw, true);

    return is_array($decoded) ? $decoded : [];
}

/**
 * Schreibt, ersetzt oder entfernt die Aufgabe einer Karte.
 *
 * Eine Aufgabe ist keine Spalte von `cards`, sie braucht also ihre eigene Anweisung.
 * Löschen und Einfügen statt eines "einfügen oder ändern" ist eine Anweisung mehr, endet
 * aber immer bei genau einer Zeile und kann keine halbe alte Aufgabe zurücklassen -
 * und es bedeutet in jeder Datenbank dasselbe.
 *
 * Eine Aufgabenart, die diese Anwendung nicht kennt, wird nie gespeichert, egal was der
 * Aufrufer schickt: der Schlüssel in dieser Spalte kann immer nur einer der Schlüssel
 * aus exercise_service.php sein.
 *
 * Läuft in der Transaktion des Aufrufers.
 *
 * @param array<string, mixed>|null $exercise null heißt "keine Aufgabe"
 */
function save_card_exercise(PDO $pdo, int $cardId, ?array $exercise): void
{
    if (!card_exercise_table_available($pdo)) {
        return;
    }

    $statement = $pdo->prepare('DELETE FROM card_exercises WHERE card_id = :card_id');
    $statement->bindValue(':card_id', $cardId, PDO::PARAM_INT);
    $statement->execute();

    if ($exercise === null) {
        return;
    }

    $type = (string) ($exercise['type'] ?? '');

    if (!exercise_type_is_known($type)) {
        return;
    }

    if (!card_exercise_params_available($pdo)) {
        throw new RuntimeException('The column card_exercises.exercise_params does not exist yet.');
    }

    $params = exercise_normalise_params(
        $type,
        is_array($exercise['params'] ?? null) ? $exercise['params'] : []
    );

    $statement = $pdo->prepare(
        'INSERT INTO card_exercises (card_id, exercise_type, exercise_params)
         VALUES (:card_id, :exercise_type, :exercise_params)'
    );
    $statement->bindValue(':card_id', $cardId, PDO::PARAM_INT);
    $statement->bindValue(':exercise_type', $type, PDO::PARAM_STR);
    $statement->bindValue(':exercise_params', json_encode($params, JSON_UNESCAPED_UNICODE), PDO::PARAM_STR);
    $statement->execute();
}

/**
 * Ob eine Sprache einer Karte eine Frage trägt.
 *
 * Eine feste Karte braucht beide Seiten. Eine Übungskarte nicht: ihre Antwort kommt aus
 * dem Erzeuger, es muss also nur die Frageseite gefüllt sein - und dort steht die
 * Überschrift der Aufgabe.
 *
 * @param array<string, mixed> $texts
 * @param list<string> $columns
 */
function card_language_has_question(array $texts, string $language, array $columns = []): bool
{
    $pair = card_language_columns($columns)[$language] ?? null;

    if ($pair === null) {
        return false;
    }

    return trim((string) ($texts[$pair[0]] ?? '')) !== '';
}

/**
 * Liest die Aufgabe aus einem Anfrage-Inhalt und prüft sie.
 *
 * Drei Fälle, und die werden absichtlich auseinandergehalten:
 *
 *   - der Inhalt sagt nichts über eine Aufgabe -> null, kein Fehler
 *   - der Inhalt sagt "keine Aufgabe" (exercise_type leer oder null) -> null
 *   - der Inhalt nennt eine Aufgabenart -> diese Aufgabe, nach Prüfung ihres Bereichs
 *
 * Die Antwort ist ein Paar aus "was" und "was schiefging", damit der Endpunkt mit dem
 * richtigen Fehlercode antworten kann: eine Aufgabenart, die diese Anwendung nicht
 * kennt, ergibt "invalid_exercise_type"; ein Bereich, der nicht dazu passt, ergibt
 * "invalid_exercise_params".
 *
 * Nichts, was hier ankommt, wird je als Formel ausgerechnet: die Art muss nur einer
 * der Schlüssel aus exercise_service.php sein, und die Zahlen müssen ganze Zahlen
 * innerhalb der Grenzen dieser Aufgabenart sein.
 *
 * @param array<string, mixed> $body
 * @return array{exercise: array<string, int|string>|null, error: string|null}
 */
function card_exercise_from_request(array $body): array
{
    if (!array_key_exists('exercise_type', $body)) {
        return ['exercise' => null, 'error' => null];
    }

    $type = $body['exercise_type'] === null ? '' : trim((string) $body['exercise_type']);

    /* Eine leere Art ist die Art, wie der Dialog "das ist keine Übungskarte" sagt. */
    if ($type === '') {
        return ['exercise' => null, 'error' => null];
    }

    if (!exercise_type_is_known($type)) {
        return ['exercise' => null, 'error' => 'invalid_exercise_type'];
    }

    /*
     * Die Zahlen der Aufgabe. Nichts über sie zu sagen ist erlaubt: dann greifen die
     * Vorgaben dieser Aufgabenart. Etwas zu sagen, das kein Objekt aus bekannten Namen
     * und erlaubten Werten ist, ist es nicht.
     */
    $params = $body['exercise_params'] ?? null;

    if ($params === null) {
        $params = exercise_type_default_params($type);
    }

    if (!is_array($params) || array_is_list($params) || !exercise_params_are_valid($type, $params)) {
        return ['exercise' => null, 'error' => 'invalid_exercise_params'];
    }

    return [
        'exercise' => ['type' => $type, 'params' => exercise_normalise_params($type, $params)],
        'error' => null,
    ];
}

/* -------------------------------------------------------------------------
   Die zwei Sprachen einer Karte
   ------------------------------------------------------------------------- */

/*
 * Eine Karte trägt ihren deutschen Text in den beiden ursprünglichen Spalten (`front`,
 * `back`) und ihren englischen Text in `front_en` und `back_en`, sobald es diese Spalten
 * gibt.
 *
 * Zu den englischen Spalten und zu `map_region` gibt es bewusst keine SQL-Datei in
 * database/: sie wurden von Hand in phpMyAdmin angelegt, mit demselben Handgriff wie die
 * drei ältesten Tabellen. In docs/dokumentation.md steht das als eigener Eintrag, damit die
 * Schemahistorie trotzdem vollständig ist.
 *
 * Welche dieser Spalten es wirklich gibt, wird einmal je Anfrage gefragt und dann
 * gemerkt - genau wie die freiwilligen Spalten von `categories`. Alles hier unten
 * arbeitet mit einer Sprache genauso wie mit zweien, die Anwendung ist also mit und ohne
 * diese Spalten richtig.
 */

/**
 * Wie ein map_region-Wert aussehen darf.
 *
 * AREA ist einer von drei Namen und entscheidet die Datei (DE -> germany.svg, EU ->
 * europe.svg, WORLD -> world.svg). REGION ist die Id eines Elements in dieser Datei:
 * ein Name eines deutschen Bundeslandes, ein ISO-Ländercode oder - bei
 * Baden-Württemberg - die Id, die diese Datei dafür benutzt. Alles andere erreicht die
 * Datenbank nie, und ein bereits gespeicherter Wert, der nicht passt, wird beim Anzeigen
 * einer Karte einfach übergangen. Nirgends wird der Wert als Markup gedeutet: er dient
 * nur dazu, ein Element in einer festen Datei der Anwendung zu suchen.
 */
const CARD_MAP_REGION_PATTERN = '/^(DE|EU|WORLD):[A-Za-z0-9_äöüÄÖÜß-]{1,32}$/u';

/** Der längste map_region-Wert (die Spalte ist varchar(40)). */
const CARD_MAP_REGION_MAX_LENGTH = 40;

function card_map_region_is_valid(string $value): bool
{
    return $value !== ''
        && mb_strlen($value) <= CARD_MAP_REGION_MAX_LENGTH
        && preg_match(CARD_MAP_REGION_PATTERN, $value) === 1;
}

/**
 * Die Spaltenpaare je Sprache. Deutsch liegt in den beiden urspruenglichen
 * Spalten, Englisch in den beiden, die spaeter dazugekommen sind.
 *
 * @return array<string, list<string>>
 */
function card_language_columns(array $columns = []): array
{
    $known = [
        'de' => ['front_de', 'back_de'],
        'en' => ['front_en', 'back_en'],
    ];

    /* Ohne Spaltenliste fragt die Anwendung jede Sprache ab, die sie kennt. */
    if ($columns === []) {
        return $known;
    }

    $pairs = [];

    foreach ($known as $language => $pair) {
        if (card_column_available($columns, $pair[0]) && card_column_available($columns, $pair[1])) {
            $pairs[$language] = $pair;
        }
    }

    /*
     * Eine Tabelle von vor den Sprachspalten: der deutsche Text steht in front und back,
     * unter den Namen, die diese Anwendung schon immer benutzt hat.
     */
    if (!isset($pairs['de'])) {
        $pairs['de'] = ['front', 'back'];
    }

    return $pairs;
}

/**
 * Die Namen der Spalten, die es in der Tabelle `cards` wirklich gibt.
 *
 * Gelesen aus den Angaben zu einer Abfrage, die keine Zeilen liefert: die Namen sind
 * Teil der Antwort, die Tabelle muss also nicht zweimal beschrieben werden.
 *
 * @return list<string>
 */
function card_columns(PDO $pdo): array
{
    static $columns = null;

    if ($columns === null) {
        $columns = [];
        $statement = $pdo->query('SELECT * FROM cards LIMIT 0');

        for ($index = 0; $index < $statement->columnCount(); $index++) {
            $meta = $statement->getColumnMeta($index);

            if (is_array($meta) && isset($meta['name']) && is_string($meta['name'])) {
                $columns[] = $meta['name'];
            }
        }

        if ($columns === []) {
            foreach ($pdo->query('SHOW COLUMNS FROM cards')->fetchAll() as $row) {
                $columns[] = (string) $row['Field'];
            }
        }
    }

    return $columns;
}

/**
 * Ob diese Spalte in der Tabelle cards wirklich existiert.
 *
 * @param list<string> $columns
 */
function card_column_available(array $columns, string $column): bool
{
    return in_array($column, $columns, true);
}

/**
 * Die Sprachen, die eine Karte in dieser Tabelle haben kann: "de" immer, "en" sobald die
 * Migration gelaufen ist.
 *
 * @param list<string> $columns
 * @return list<string>
 */
function card_content_languages(array $columns): array
{
    return array_keys(card_language_columns($columns));
}

/**
 * Jede Spalte, die ein Kartenlesen braucht: die festen plus Frage und Antwort jeder
 * Sprache, die die Tabelle hat. front und back bleiben in der Liste, weil die ältere
 * Form einer Kartenzeile sie noch benutzt.
 *
 * @return list<string>
 */
function card_read_columns(PDO $pdo): array
{
    $columns = ['id', 'category_id', 'is_bidirectional', 'front', 'back'];

    /* Die Kartenregion reist nur mit, wenn die Tabelle die Spalte wirklich hat. */
    if (card_column_available(card_columns($pdo), 'map_region')) {
        $columns[] = 'map_region';
    }

    /*
     * Die Aufgabe einer Karte liegt in einer eigenen Tabelle, ihre zwei Werte reisen also
     * nur mit, wenn es diese Tabelle gibt. Sie werden hier umbenannt: in einer
     * verbundenen Abfrage könnten die schlichten Namen zweimal gelesen werden.
     */
    if (card_exercise_table_available($pdo) && card_exercise_params_available($pdo)) {
        $columns[] = 'card_exercises.exercise_type AS exercise_type';
        $columns[] = 'card_exercises.exercise_params AS exercise_params';
    }

    foreach (card_language_columns(card_columns($pdo)) as $pair) {
        foreach ($pair as $column) {
            $columns[] = $column;
        }
    }

    return array_values(array_unique($columns));
}

/**
 * Ob eine Sprache einer Karte vollständig ist (Frage UND Antwort).
 *
 * @param array<string, mixed> $texts
 */
function card_language_is_complete(array $texts, string $language, array $columns = []): bool
{
    $pair = card_language_columns($columns)[$language] ?? null;

    if ($pair === null) {
        return false;
    }

    return trim((string) ($texts[$pair[0]] ?? '')) !== ''
        && trim((string) ($texts[$pair[1]] ?? '')) !== '';
}

/**
 * Der Text einer Karte in der Sprache, die gezeigt werden soll, mit dem Merker, der dem
 * Browser sagt, wann auf die andere Sprache ausgewichen werden musste.
 *
 * Eine Karte kann nur deutsch, nur englisch oder beides sein. Die Regel ist einfach und
 * überall dieselbe:
 *
 *   1. die gefragte Sprache, wenn sie vollständig ist,
 *   2. sonst die andere, wenn DIE vollständig ist - gekennzeichnet als die Sprache,
 *      die sie wirklich ist,
 *   3. sonst der Text, den es gibt, in der gefragten Sprache.
 *
 * @param array<string, mixed> $row die Zeile, mit den Sprachspalten, wenn es sie gibt
 * @param list<string> $columns
 * @return array<string, mixed>
 */
function card_localized_text(array $row, array $columns, string $language): array
{
    $pairs = card_language_columns($columns);
    $languages = array_keys($pairs);

    /*
     * Eine flache Tabelle "Spalte => Text". Die Vollständigkeit wird je Sprache gefragt,
     * und diese Frage geht über die echten Spalten und nicht über die Wörter "front"
     * und "back".
     */
    $texts = [];

    foreach ($pairs as $pair) {
        foreach ($pair as $column) {
            $texts[$column] = (string) ($row[$column] ?? '');
        }
    }

    /*
     * Die gefragte Sprache, wenn diese Tabelle sie überhaupt hat. Eine Tabelle mit einer
     * Sprache kann nie eine Anfrage nach der anderen beantworten, und genau das zu sagen
     * bringt die Oberfläche dazu, "nur Deutsch" anzuzeigen.
     */
    /*
     * Eine Übungskarte speichert keine Antwort: ihre Antwort entsteht in dem Moment, in
     * dem die Karte gezeigt wird. "Vollständig" heißt bei dieser Art Karte also "trägt
     * eine Überschrift", und eine Karte mit leerer Antwortspalte darf nicht als
     * "falsche Sprache" gekennzeichnet werden - an ihr fehlt nichts.
     */
    $isExercise = trim((string) ($row['exercise_type'] ?? '')) !== '';
    $filled = static function (string $code) use ($texts, $columns, $isExercise): bool {
        return $isExercise
            ? card_language_has_question($texts, $code, $columns)
            : card_language_is_complete($texts, $code, $columns);
    };

    $asked = in_array($language, $languages, true) ? $language : null;
    $shown = $asked ?? $languages[0];
    $missing = $asked === null;

    if (!$filled($shown)) {
        $other = null;

        foreach ($languages as $code) {
            if ($code !== $shown && $filled($code)) {
                $other = $code;
                break;
            }
        }

        if ($other !== null) {
            $shown = $other;
        }

        /* Der gezeigte Text ist nicht in der Sprache, die gefragt war. */
        $missing = true;
    }

    $result = [
        'front' => $texts[$pairs[$shown][0]] ?? '',
        'back' => $texts[$pairs[$shown][1]] ?? '',
        'language' => $shown,
        'missing_language' => $missing,
    ];

    /* Beide Sprachen reisen mit der Karte mit, damit der Dialog beide Seiten
       bearbeiten kann, ohne erneut zu fragen. */
    foreach ($languages as $code) {
        $result['front_' . $code] = $texts[$pairs[$code][0]] ?? '';
        $result['back_' . $code] = $texts[$pairs[$code][1]] ?? '';
    }

    return $result;
}

/**
 * Liest die Textfelder einer Karte aus einem Anfrage-Inhalt, in jeder Sprache, die die
 * Tabelle unterstützt.
 *
 * @param array<string, mixed> $body
 * @param list<string> $columns
 * @return array<string, string>
 */
function card_texts_from_body(array $body, array $columns): array
{
    $texts = [];

    foreach (card_language_columns($columns) as $pair) {
        foreach ($pair as $column) {
            if (array_key_exists($column, $body)) {
                $texts[$column] = (string) optional_input_text($body, $column, CARD_MAX_TEXT_LENGTH, 'invalid_' . $column);
            }
        }
    }

    /*
     * Der Dialog schickt "front" und "back" auch dann mit, wenn eine Karte nur eine
     * Sprache hat: sie sind der deutsche Text unter seinem ursprünglichen Namen. Eine
     * Tabelle ohne die Spalten front_de/back_de behält den deutschen Text allein dort.
     */
    foreach (['front', 'back'] as $column) {
        if (array_key_exists($column, $body) && trim((string) ($texts[$column] ?? '')) === '') {
            $texts[$column] = (string) optional_input_text($body, $column, CARD_MAX_TEXT_LENGTH, 'invalid_' . $column);
        }
    }

    return $texts;
}

/**
 * Legt eine Karte an, mit dem Text jeder Sprache, die die Tabelle unterstützt.
 *
 * Der Aufrufer muss dafür sorgen, dass $categoryId zu $ownerUserId gehört - beide
 * Aufrufer tun das mit category_exists(), bevor sie hier ankommen. Der Besitzer wird
 * für das Zurücklesen benutzt, diese Funktion kann also nie die Karte von jemand
 * anderem zurückgeben, selbst wenn diese Prüfung einmal vergessen würde.
 *
 * @param array<string, string> $texts nach Spaltenname abgelegt
 * @param list<string> $columns
 * @return array<string, mixed>
 */
function create_card_translated(
    PDO $pdo,
    int $categoryId,
    array $texts,
    array $columns,
    bool $isBidirectional,
    int $ownerUserId,
    ?string $mapRegion = null,
    ?array $exercise = null
): array {
    $pairs = card_language_columns($columns);
    $names = ['category_id', 'is_bidirectional'];
    $values = [':category_id', ':is_bidirectional'];

    $hasMap = card_column_available($columns, 'map_region');

    if ($hasMap) {
        $names[] = 'map_region';
        $values[] = ':map_region';
    }

    foreach ($pairs as $pair) {
        foreach ($pair as $column) {
            $names[] = $column;
            $values[] = ':' . $column;
        }
    }

    /*
     * front und back sind NOT NULL und ältere Leser benutzen sie noch, der deutsche Text
     * wird also auch dort hineingeschrieben, wo sie nicht selbst die deutschen Spalten
     * sind.
     */
    $legacy = [];

    foreach (['front' => 0, 'back' => 1] as $column => $index) {
        $germanColumn = $pairs['de'][$index] ?? null;

        if ($germanColumn === null || $column === $germanColumn || !card_column_available($columns, $column)) {
            continue;
        }

        $legacy[$column] = (string) ($texts[$germanColumn] ?? '');
        $names[] = $column;
        $values[] = ':' . $column;
    }

    /*
     * Die Karte und ihre Aufgabe werden zusammen geschrieben oder gar nicht. Eine
     * Transaktion, die der Aufrufer schon begonnen hat, wird nicht angefasst: der Import
     * schreibt viele Karten in einer.
     */
    $ownsTransaction = !$pdo->inTransaction();

    if ($ownsTransaction) {
        $pdo->beginTransaction();
    }

    $statement = $pdo->prepare(
        'INSERT INTO cards (' . implode(', ', $names) . ')
         VALUES (' . implode(', ', $values) . ')'
    );

    $statement->bindValue(':category_id', $categoryId, PDO::PARAM_INT);
    $statement->bindValue(':is_bidirectional', $isBidirectional ? 1 : 0, PDO::PARAM_INT);

    if ($hasMap) {
        if ($mapRegion === null || !card_map_region_is_valid($mapRegion)) {
            $statement->bindValue(':map_region', null, PDO::PARAM_NULL);
        } else {
            $statement->bindValue(':map_region', $mapRegion, PDO::PARAM_STR);
        }
    }

    foreach ($pairs as $pair) {
        foreach ($pair as $column) {
            $statement->bindValue(':' . $column, (string) ($texts[$column] ?? ''), PDO::PARAM_STR);
        }
    }

    foreach ($legacy as $column => $text) {
        $statement->bindValue(':' . $column, $text, PDO::PARAM_STR);
    }

    try {
        $statement->execute();

        $cardId = (int) $pdo->lastInsertId();

        save_card_exercise($pdo, $cardId, $exercise);

        $created = find_card($pdo, $cardId, $ownerUserId);

        if ($created === null) {
            throw new RuntimeException('The card was inserted but cannot be read back.');
        }
    } catch (Throwable $error) {
        if ($ownsTransaction) {
            $pdo->rollBack();
        }

        throw $error;
    }

    if ($ownsTransaction) {
        $pdo->commit();
    }

    return $created;
}
function delete_cards_of_categories(PDO $pdo, array $categoryIds, int $ownerUserId): int
{
    if ($categoryIds === []) {
        return 0;
    }

    // Die Ids kommen aus der Datenbank (sie wurden gelesen, nicht von einer Person
    // eingetippt), und jede wird in eine ganze Zahl umgewandelt, in die Platzhalterliste
    // gelangt also nichts als Zahlen.
    $placeholders = [];
    $ids = [];

    foreach (array_values($categoryIds) as $index => $categoryId) {
        $placeholders[] = ':id' . $index;
        $ids[':id' . $index] = (int) $categoryId;
    }

    /* Die zweite Hälfte der Bedingung ist die Absicherung, die in
       delete_progress_of_categories() beschrieben ist: diese Ids, und sie müssen zu
       diesem Konto gehören. */
    $statement = $pdo->prepare(
        'DELETE FROM cards'
        . ' WHERE category_id IN (' . implode(', ', $placeholders) . ')'
        . '   AND category_id IN (SELECT id FROM categories WHERE owner_user_id = :owner_user_id)'
    );

    $statement->bindValue(':owner_user_id', $ownerUserId, PDO::PARAM_INT);

    foreach ($ids as $placeholder => $id) {
        $statement->bindValue($placeholder, $id, PDO::PARAM_INT);
    }

    $statement->execute();

    return $statement->rowCount();
}

/* -------------------------------------------------------------------------
   Die Varianten einer Karte
   ------------------------------------------------------------------------- */

/*
 * Manche Karten zeigen dieselbe Regel als einen von mehreren Beispielsätzen (Grammatik).
 * Die Sätze stehen in `card_variants`, null bis n Zeilen je Karte; eine Karte ohne solche
 * Zeilen ist eine gewöhnliche Karte und wird genau wie vorher angezeigt.
 *
 * Der Lernfortschritt hängt weiterhin an der Karte (`user_card_progress.card_id`): gezeigt
 * wird ein Satz, gelernt wird die Karte.
 *
 * Die Tabelle ist freiwillig, wie `card_exercises`: eine Installation ohne sie arbeitet
 * genau wie vorher, es wird einmal je Anfrage nachgesehen.
 */

/** Die Spalten einer Variante. card_localized_text() braucht genau diese vier. */
const CARD_VARIANT_COLUMNS = ['front_de', 'back_de', 'front_en', 'back_en'];

/**
 * Ob die Tabelle `card_variants` in dieser Datenbank existiert.
 *
 * Einmal je Anfrage gefragt und dann gemerkt, wie bei `card_exercises`.
 */
function card_variant_table_available(PDO $pdo): bool
{
    static $available = null;

    if ($available === null) {
        $statement = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table'
        );
        $statement->bindValue(':table', 'card_variants', PDO::PARAM_STR);
        $statement->execute();

        $available = (int) $statement->fetchColumn() > 0;
    }

    return $available;
}

/**
 * Die Varianten dieser Karten, nach Karten-Id gruppiert.
 *
 * EINE Abfrage für alle Karten und nicht eine je Karte: die Schlange einer Einheit kann
 * hunderte Karten enthalten. Gelesen wird nur für die Karten, um die es gerade geht - die
 * Tabelle wächst mit jeder Variante, die je importiert wurde, und die wird hier nicht
 * mitgeschleppt.
 *
 * @param list<int> $cardIds
 * @return array<int, list<array<string, mixed>>> Karten-Id -> Varianten in Nummernfolge
 */
function card_variants_of_cards(PDO $pdo, array $cardIds): array
{
    $ids = array_values(array_unique(array_filter($cardIds, static fn ($id) => (int) $id > 0)));

    if ($ids === [] || !card_variant_table_available($pdo)) {
        return [];
    }

    /* Jeder Platzhalter bekommt seinen eigenen Namen: eine Anweisung darf denselben Namen
       nicht zweimal tragen. */
    $placeholders = [];

    foreach ($ids as $index => $id) {
        $placeholders[] = ':variant_card_' . $index;
    }

    $statement = $pdo->prepare(
        'SELECT id, card_id, variant_number, variant_key, front_de, back_de, front_en, back_en
           FROM card_variants
          WHERE card_id IN (' . implode(', ', $placeholders) . ')
          ORDER BY card_id ASC, variant_number ASC'
    );

    foreach ($ids as $index => $id) {
        $statement->bindValue(':variant_card_' . $index, $id, PDO::PARAM_INT);
    }

    $statement->execute();

    $variants = [];

    foreach ($statement->fetchAll() as $row) {
        $variants[(int) $row['card_id']][] = $row;
    }

    return $variants;
}

/**
 * Wählt eine Variante aus und merkt sie sich.
 *
 * Zufällig, aber nicht die, die zuletzt für diese Karte gezeigt wurde - sonst stünde
 * zweimal hintereinander derselbe Satz da. Gemerkt wird in der Sitzung
 * (src/helpers/session_variant.php), nicht im Fortschritt.
 *
 * @param list<array<string, mixed>> $variants
 * @return array<string, mixed>|null null, wenn die Liste leer ist
 */
function card_pick_variant(array $variants, int $cardId): ?array
{
    if ($variants === []) {
        return null;
    }

    $lastId = session_variant_last($cardId);
    $candidates = [];

    foreach ($variants as $variant) {
        if ((int) $variant['id'] !== $lastId) {
            $candidates[] = $variant;
        }
    }

    /* Hat die Karte nur eine Variante, war sie die letzte und es bleibt nichts übrig. Dann
       wird sie gezeigt, statt gar nichts zu zeigen. */
    if ($candidates === []) {
        $candidates = $variants;
    }

    /* shuffle() wie in review_build_queue(): dieselbe Art zu wählen, an beiden Stellen. */
    shuffle($candidates);
    $picked = $candidates[0];

    session_variant_remember($cardId, (int) $picked['id']);

    return $picked;
}

/**
 * Ersetzt den Text der Karten durch den einer ihrer Varianten.
 *
 * Karten ohne Varianten bleiben, wie sie sind: das Variantensystem kommt nur dazu. Welche
 * Sprache der Variante gezeigt wird, entscheidet dieselbe Regel wie bei einer Karte
 * (card_localized_text) - es gibt also keine zweite Sprachverwaltung.
 *
 * @param list<array<string, mixed>> $cards Karten, wie normalize_card_row() sie liefert
 * @return list<array<string, mixed>>
 */
function card_apply_variants(PDO $pdo, array $cards, string $language): array
{
    if ($cards === [] || !card_variant_table_available($pdo)) {
        return $cards;
    }

    $variants = card_variants_of_cards($pdo, array_map(static fn (array $card): int => (int) $card['id'], $cards));

    foreach ($cards as $index => $card) {
        $list = $variants[(int) $card['id']] ?? [];
        $picked = card_pick_variant($list, (int) $card['id']);

        if ($picked === null) {
            continue;
        }

        $localized = card_localized_text($picked, CARD_VARIANT_COLUMNS, $language);

        $cards[$index]['front'] = $localized['front'];
        $cards[$index]['back'] = $localized['back'];
        $cards[$index]['language'] = $localized['language'];
        $cards[$index]['missing_language'] = $localized['missing_language'];
        /*
         * Die Kennung der gezeigten Variante reist mit. Die Oberfläche muss sie nicht
         * zeigen; sie macht "Variante 2 von 5" möglich, ohne dass das Lernen davon abhängt.
         */
        $cards[$index]['variant'] = [
            'id' => (int) $picked['id'],
            'number' => (int) $picked['variant_number'],
            'count' => count($list),
            'key' => (string) $picked['variant_key'],
        ];
    }

    return $cards;
}
