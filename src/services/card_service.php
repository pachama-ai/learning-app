<?php

declare(strict_types=1);

/**
 * Database queries for the `cards` table (the flashcards).
 *
 * Verified structure (checked with SHOW COLUMNS):
 *   id               int unsigned, NOT NULL, primary key, auto_increment
 *   category_id      int unsigned, NOT NULL, foreign key to categories.id
 *   front            text, NOT NULL
 *   back             text, NOT NULL
 *   is_bidirectional tinyint(1), NOT NULL, default 0
 *
 * There is no timestamp column, so the order of the list is the order of the
 * ids: the card that was added first is shown first.
 *
 * The foreign key fk_cards_category is ON DELETE RESTRICT, which means the
 * database itself refuses to delete a category that still holds cards. That is
 * why deleting a category deletes its cards first (see category_service.php).
 *
 * user_card_progress references a card with ON DELETE CASCADE, so the learning
 * progress of a card disappears together with the card. That rule is part of
 * the existing structure and was not changed.
 *
 * A card may also be an exercise card: instead of a question and an answer that
 * somebody wrote, it shows a task that is built when the card is displayed, with
 * numbers that are drawn again every time. That belongs to the table
 * `card_exercises`, which is read and written below.
 */

require_once __DIR__ . '/exercise_service.php';

/** Longest text accepted for the front or the back of a card. */
const CARD_MAX_TEXT_LENGTH = 2000;

/**
 * Converts a database row into the shape the API promises.
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
        // JSON has real booleans, so the tinyint becomes true or false here.
        'is_bidirectional' => (int) $row['is_bidirectional'] === 1,
        /*
         * The map region is either a key like "DE:Bayern" or nothing at all - an
         * empty column and a value that does not pass the pattern both mean "no
         * map". The value is never interpreted as markup anywhere; it is only
         * used to look up one element in a static map file.
         */
        'map_region' => isset($row['map_region']) && card_map_region_is_valid((string) $row['map_region'])
            ? (string) $row['map_region']
            : null,
        /*
         * An exercise, or nothing at all. The task itself is stored nowhere: it is
         * built here from the kind of task and the range, so its numbers are new
         * on every read. A row whose exercise_type is not one of the kinds of task
         * this application knows (an older row, or one edited by hand) is a card
         * without an exercise and is simply shown as a fixed card.
         */
        'exercise' => card_exercise_from_row($row),
    ];
}

/**
 * @param list<array<string, mixed>> $rows
 * @return list<array{id: int, category_id: int, front: string, back: string, is_bidirectional: bool}>
 */

/**
 * Returns one card, or null when it does not exist.
 *
 * @return array{id: int, category_id: int, front: string, back: string, is_bidirectional: bool}|null
 */
function find_card(PDO $pdo, int $cardId): ?array
{
    $statement = $pdo->prepare(
        'SELECT ' . implode(', ', card_read_columns($pdo)) . '
           FROM cards' . card_exercise_join($pdo) . '
          WHERE id = :id'
    );
    $statement->bindValue(':id', $cardId, PDO::PARAM_INT);
    $statement->execute();

    $row = $statement->fetch();

    return $row === false ? null : normalize_card_row($row);
}

/**
 * Updates the given fields of one card.
 *
 * Only the keys that really exist as columns are accepted, so a value from the
 * request body can never become part of the SQL text. An empty list of changes
 * simply returns the card unchanged.
 *
 * @param array<string, mixed> $changes Values keyed by column name. The key
 *        "exercise" is the one exception: it is not a column of this table but
 *        the exercise that belongs to the card.
 * @return array{id: int, category_id: int, front: string, back: string, is_bidirectional: bool}|null
 */
function update_card(PDO $pdo, int $cardId, array $changes): ?array
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
     * A change to the German side is mirrored into front and back: those two are
     * NOT NULL and older readers still use them, so leaving them behind would make
     * the two versions of the German text drift apart.
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
     * An exercise is not a column of this table, so it never appears in the
     * assignment list. A card whose exercise is the only thing that changes must
     * not be skipped here.
     */
    $exerciseChange = array_key_exists('exercise', $changes);

    if ($assignments === [] && !$exerciseChange) {
        return find_card($pdo, $cardId);
    }


    /*
     * The columns and the exercise are written together or not at all: a change
     * that half succeeded would leave a card that shows neither the old nor the
     * new. A transaction the caller has already started is left alone.
     */
    $ownsTransaction = !$pdo->inTransaction();

    if ($ownsTransaction) {
        $pdo->beginTransaction();
    }

    try {
        if ($assignments !== []) {
            $statement = $pdo->prepare(
                'UPDATE cards SET ' . implode(', ', $assignments) . ' WHERE id = :id'
            );
            $statement->bindValue(':id', $cardId, PDO::PARAM_INT);

            foreach ($values as $column => [$value, $type]) {
                if ($column === 'is_bidirectional') {
                    $statement->bindValue(':' . $column, $value ? 1 : 0, $type);
                    continue;
                }

                /* An empty map_region means "no map" and has to be NULL, not "". */
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

        $updated = find_card($pdo, $cardId);
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
 * Deletes one card. Returns false when there was nothing to delete.
 *
 * The learning progress rows of this card are removed by the database itself
 * (fk_progress_card is ON DELETE CASCADE).
 */
function delete_card(PDO $pdo, int $cardId): bool
{
    $statement = $pdo->prepare('DELETE FROM cards WHERE id = :id');
    $statement->bindValue(':id', $cardId, PDO::PARAM_INT);
    $statement->execute();

    return $statement->rowCount() > 0;
}

/**
 * Deletes the learning progress of every card of the given categories.
 *
 * This is the first step of deleting a category. The foreign key on
 * user_card_progress.card_id is ON DELETE CASCADE and would remove these rows by
 * itself, but the order is written out and executed explicitly: whoever deletes
 * a category should be able to read the whole order in one place, and a database
 * without that cascade behaves the same way.
 *
 * Runs inside the transaction of the caller. Only the progress rows of the cards
 * in the given categories are touched - never the progress of another card and
 * never a user.
 *
 * @param list<int> $categoryIds
 * @return int How many progress rows were removed.
 */
function delete_progress_of_categories(PDO $pdo, array $categoryIds): int
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

    /* The subquery names the cards of the subtree, so only their progress rows
       are part of this statement. */
    $statement = $pdo->prepare(
        'DELETE FROM user_card_progress'
        . ' WHERE card_id IN (SELECT id FROM cards WHERE category_id IN (' . implode(', ', $placeholders) . '))'
    );

    foreach ($ids as $placeholder => $id) {
        $statement->bindValue($placeholder, $id, PDO::PARAM_INT);
    }

    $statement->execute();

    return $statement->rowCount();
}

/**
 * Deletes every card of the given categories and reports how many were removed.
 *
 * The second step of deleting a category: the cards have to go before the
 * categories, because fk_cards_category is ON DELETE RESTRICT.
 *
 * @param list<int> $categoryIds
 */

/* -------------------------------------------------------------------------
   The exercise of a card
   ------------------------------------------------------------------------- */

/*
 * A card may carry an exercise instead of a question and an answer that somebody
 * wrote. Which kind of task it is and between which numbers it lives sit in the
 * table `card_exercises`: one row per card at most, because card_id is the
 * primary key of that table.
 *
 * The table is optional in this sense: an installation that has not run
 * database/add_card_exercises.sql yet works exactly as before. That is why it is
 * looked for once per request, and why the read queries only join it when it is
 * really there.
 */

/**
 * Whether the table `card_exercises` exists in this database.
 *
 * Asked once per request and then remembered, like the optional columns of
 * `cards` and `categories`.
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
 * Whether the column that holds the numbers of a task exists.
 *
 * The numbers came later than the table: an installation that has run
 * database/add_card_exercises.sql but not database/add_exercise_params.sql can
 * still read its cards (a card then shows its task with the default numbers), but
 * it cannot store an exercise. Asked once per request, like the table itself.
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
 * The join that brings the exercise of a card into a query.
 *
 * An empty string when the table is not there, so one query text works with and
 * without the migration. The name of the card table is checked against the two
 * spellings this application uses, so nothing that comes from a request can ever
 * reach the query text.
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
 * The exercise of one card row, or null when the card is a fixed card.
 *
 * @param array<string, mixed> $row
 * @return array<string, mixed>|null
 */
function card_exercise_from_row(array $row): ?array
{
    $type = isset($row['exercise_type']) ? (string) $row['exercise_type'] : '';

    /* An unknown key is not an error: the card is shown as a fixed card. */
    if ($type === '' || !exercise_type_is_known($type)) {
        return null;
    }

    /*
     * The numbers in use, not the raw column: a value that was edited by hand or
     * that is older than the limits of its kind of task is pulled into them, and
     * the interface shows what the task really works with.
     */
    $params = exercise_normalise_params($type, card_exercise_params_from_row($row));

    return [
        'type' => $type,
        'label' => exercise_type_label($type),
        'params' => $params,
        /* Built here and now, so the numbers are new on every read. */
        'task' => exercise_build_task($type, $params),
    ];
}

/**
 * The numbers of an exercise row, as they were stored.
 *
 * Anything that is not a JSON object is treated as "nothing stored": the defaults
 * of that kind of task then apply, which is what a row from before the migration
 * looks like.
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
 * Writes, replaces or removes the exercise of one card.
 *
 * An exercise is not a column of `cards`, so it needs its own statement. Deleting
 * and inserting instead of an "insert or update" is one statement more, but it
 * always ends with exactly one row and cannot leave half of an old exercise
 * behind - and it means the same thing in every database.
 *
 * A kind of task this application does not know is never stored, whatever the
 * caller sends: the key in that column can only ever be one of the keys in
 * exercise_service.php.
 *
 * Runs inside the transaction of the caller.
 *
 * @param array<string, mixed>|null $exercise null means "no exercise"
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
 * Whether one language of a card carries a question.
 *
 * A fixed card needs both sides. An exercise card does not: its answer comes from
 * the generator, so only the question side has to be filled - and that is where
 * the title of the exercise stands.
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
 * Reads the exercise out of a request body and checks it.
 *
 * Three cases, and they are told apart on purpose:
 *
 *   - the body says nothing about an exercise  -> null, no error
 *   - the body says "no exercise" (an empty or null exercise_type) -> null
 *   - the body names a kind of task -> that exercise, after checking its range
 *
 * The answer is a pair of "what" and "what went wrong", so the endpoint can
 * answer with the right error code: a kind of task this application does not know
 * gives "invalid_exercise_type", a range that does not fit it gives
 * "invalid_exercise_range".
 *
 * Nothing that arrives here is ever worked out as a formula: the type only has to
 * be one of the keys in exercise_service.php, and the numbers only have to be
 * whole numbers inside the limits of that kind of task.
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

    /* An empty type is how the dialog says "this is not an exercise card". */
    if ($type === '') {
        return ['exercise' => null, 'error' => null];
    }

    if (!exercise_type_is_known($type)) {
        return ['exercise' => null, 'error' => 'invalid_exercise_type'];
    }

    /*
     * The numbers of the task. Saying nothing about them is allowed: the defaults
     * of that kind of task then apply. Saying something that is not an object of
     * known names and allowed values is not.
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
   The two languages of a card
   ------------------------------------------------------------------------- */

/*
 * A card carries its German text in the two original columns (`front`, `back`)
 * and, once the migration in database/add_card_english_columns.sql has been run,
 * its English text in `front_en` and `back_en`.
 *
 * Which of those columns really exist is asked once per request and then
 * remembered - exactly like the optional columns of `categories`. Everything
 * below works with one language as well as with two, so the application is
 * correct before and after the migration.
 */

/**
 * What a map_region value may look like.
 *
 * AREA is one of three names and decides the file (DE -> germany.svg, EU ->
 * europe.svg, WORLD -> world.svg). REGION is the id of one element in that file:
 * a German state name, an ISO country code, or - for Baden-Württemberg - the id
 * that file uses for it. Anything else never reaches the database, and a value
 * that is already stored but does not match is simply ignored when a card is
 * shown. Nowhere is the value interpreted as markup: it is only used to look up
 * one element in a static application asset.
 */
const CARD_MAP_REGION_PATTERN = '/^(DE|EU|WORLD):[A-Za-z0-9_äöüÄÖÜß-]{1,32}$/u';

/** The longest map_region value (the column is varchar(40)). */
const CARD_MAP_REGION_MAX_LENGTH = 40;

function card_map_region_is_valid(string $value): bool
{
    return $value !== ''
        && mb_strlen($value) <= CARD_MAP_REGION_MAX_LENGTH
        && preg_match(CARD_MAP_REGION_PATTERN, $value) === 1;
}

/** The column pairs per language. German uses the two original columns. */
function card_language_columns(array $columns = []): array
{
    $known = [
        'de' => ['front_de', 'back_de'],
        'en' => ['front_en', 'back_en'],
    ];

    /* Without a column list the app asks for every language it knows. */
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
     * A table from before the language columns: the German text sits in front
     * and back, under the names this app has always used.
     */
    if (!isset($pairs['de'])) {
        $pairs['de'] = ['front', 'back'];
    }

    return $pairs;
}

/**
 * The names of the columns that really exist in the `cards` table.
 *
 * Read from the metadata of a query that returns no rows: the names are part of
 * the answer, so the table does not have to be described twice.
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
 * @param list<string> $columns
 */
function card_column_available(array $columns, string $column): bool
{
    return in_array($column, $columns, true);
}

/**
 * The languages a card can have in this table: "de" always, "en" once the
 * migration has been run.
 *
 * @param list<string> $columns
 * @return list<string>
 */
function card_content_languages(array $columns): array
{
    return array_keys(card_language_columns($columns));
}

/**
 * Every column a card read needs: the fixed ones plus the question and the answer
 * of every language the table has. front and back stay in the list because the
 * older shape of a card row still uses them.
 *
 * @return list<string>
 */
function card_read_columns(PDO $pdo): array
{
    $columns = ['id', 'category_id', 'is_bidirectional', 'front', 'back'];

    /* The map region only travels along when the table really has the column. */
    if (card_column_available(card_columns($pdo), 'map_region')) {
        $columns[] = 'map_region';
    }

    /*
     * The exercise of a card sits in a table of its own, so its three values only
     * travel along when that table exists. They are renamed here: in a joined
     * query the plain names could be read twice.
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
 * Reports whether one language of a card is complete (question AND answer).
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
 * The text of one card in the language that should be shown, with the marker that
 * tells the browser when the other language had to be used.
 *
 * A card may be German only, English only or both. The rule is simple and the
 * same everywhere:
 *
 *   1. the language that was asked for, when it is complete,
 *   2. otherwise the other one, when THAT one is complete - marked as the
 *      language it really is,
 *   3. otherwise whatever text there is, in the language that was asked for.
 *
 * @param array<string, mixed> $row the row, with the language columns if they exist
 * @param list<string> $columns
 * @return array<string, mixed>
 */
function card_localized_text(array $row, array $columns, string $language): array
{
    $pairs = card_language_columns($columns);
    $languages = array_keys($pairs);

    /*
     * One flat table "column => text". Completeness is asked per language, and
     * that question is about the real columns, not about the words "front" and
     * "back".
     */
    $texts = [];

    foreach ($pairs as $pair) {
        foreach ($pair as $column) {
            $texts[$column] = (string) ($row[$column] ?? '');
        }
    }

    /*
     * The language that was asked for, when this table has it at all. A table
     * with one language can never answer a request for the other one, and saying
     * so is what makes the interface show "German only".
     */
    /*
     * An exercise card stores no answer: its answer is built at the moment the
     * card is displayed. "Complete" therefore means "carries a title" for that
     * kind of card, and a card whose answer column is empty must not be marked as
     * being in the wrong language - nothing about it is missing.
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

        /* The text that is shown is not in the language that was asked for. */
        $missing = true;
    }

    $result = [
        'front' => $texts[$pairs[$shown][0]] ?? '',
        'back' => $texts[$pairs[$shown][1]] ?? '',
        'language' => $shown,
        'missing_language' => $missing,
    ];

    /* Both languages travel with the card, so the dialog can edit both sides
       without asking again. */
    foreach ($languages as $code) {
        $result['front_' . $code] = $texts[$pairs[$code][0]] ?? '';
        $result['back_' . $code] = $texts[$pairs[$code][1]] ?? '';
    }

    return $result;
}

/**
 * Reads the text fields of a card from a request body, in every language the
 * table supports.
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
     * The dialog sends "front" and "back" as well when a card has one language:
     * they are the German text under its original name. A table without the
     * front_de/back_de columns keeps the German text there alone.
     */
    foreach (['front', 'back'] as $column) {
        if (array_key_exists($column, $body) && trim((string) ($texts[$column] ?? '')) === '') {
            $texts[$column] = (string) optional_input_text($body, $column, CARD_MAX_TEXT_LENGTH, 'invalid_' . $column);
        }
    }

    return $texts;
}

/**
 * Inserts a card with the text of every language the table supports.
 *
 * @param array<string, string> $texts keyed by column name
 * @param list<string> $columns
 * @return array<string, mixed>
 */
function create_card_translated(
    PDO $pdo,
    int $categoryId,
    array $texts,
    array $columns,
    bool $isBidirectional,
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
     * front and back are NOT NULL and older readers still use them, so the German
     * text is written there as well whenever they are not the German columns
     * themselves.
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
     * The card and its exercise are written together or not at all. A transaction
     * that the caller has already started is not touched: the import writes many
     * cards inside one.
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

        $created = find_card($pdo, $cardId);

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
function delete_cards_of_categories(PDO $pdo, array $categoryIds): int
{
    if ($categoryIds === []) {
        return 0;
    }

    // The ids come from the database (they were read, not typed by a person),
    // and each one is cast to an integer, so nothing but numbers reaches the
    // placeholder list.
    $placeholders = [];
    $ids = [];

    foreach (array_values($categoryIds) as $index => $categoryId) {
        $placeholders[] = ':id' . $index;
        $ids[':id' . $index] = (int) $categoryId;
    }

    $statement = $pdo->prepare(
        'DELETE FROM cards WHERE category_id IN (' . implode(', ', $placeholders) . ')'
    );

    foreach ($ids as $placeholder => $id) {
        $statement->bindValue($placeholder, $id, PDO::PARAM_INT);
    }

    $statement->execute();

    return $statement->rowCount();
}
