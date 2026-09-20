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
 */

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
    ];
}

/**
 * @param list<array<string, mixed>> $rows
 * @return list<array{id: int, category_id: int, front: string, back: string, is_bidirectional: bool}>
 */
function normalize_card_rows(array $rows): array
{
    $cards = [];

    foreach ($rows as $row) {
        $cards[] = normalize_card_row($row);
    }

    return $cards;
}

/**
 * Returns the cards of one category, oldest first.
 *
 * @return list<array{id: int, category_id: int, front: string, back: string, is_bidirectional: bool}>
 */
function find_cards(PDO $pdo, int $categoryId): array
{
    // The value travels separately from the SQL text, so it can never be read
    // as part of the query.
    $statement = $pdo->prepare(
        'SELECT id, category_id, front, back, is_bidirectional
           FROM cards
          WHERE category_id = :category_id
          ORDER BY id ASC'
    );
    $statement->bindValue(':category_id', $categoryId, PDO::PARAM_INT);
    $statement->execute();

    return normalize_card_rows($statement->fetchAll());
}

/**
 * Returns one card, or null when it does not exist.
 *
 * @return array{id: int, category_id: int, front: string, back: string, is_bidirectional: bool}|null
 */
function find_card(PDO $pdo, int $cardId): ?array
{
    $statement = $pdo->prepare(
        'SELECT id, category_id, front, back, is_bidirectional
           FROM cards
          WHERE id = :id'
    );
    $statement->bindValue(':id', $cardId, PDO::PARAM_INT);
    $statement->execute();

    $row = $statement->fetch();

    return $row === false ? null : normalize_card_row($row);
}

/**
 * Inserts a card and returns it in the same shape the read functions use.
 *
 * @return array{id: int, category_id: int, front: string, back: string, is_bidirectional: bool}
 */
function create_card(PDO $pdo, int $categoryId, string $front, string $back, bool $isBidirectional): array
{
    $statement = $pdo->prepare(
        'INSERT INTO cards (category_id, front, back, is_bidirectional)
         VALUES (:category_id, :front, :back, :is_bidirectional)'
    );
    $statement->bindValue(':category_id', $categoryId, PDO::PARAM_INT);
    $statement->bindValue(':front', $front, PDO::PARAM_STR);
    $statement->bindValue(':back', $back, PDO::PARAM_STR);
    // The column is a tinyint, so the boolean is written as 1 or 0.
    $statement->bindValue(':is_bidirectional', $isBidirectional ? 1 : 0, PDO::PARAM_INT);
    $statement->execute();

    return [
        'id' => (int) $pdo->lastInsertId(),
        'category_id' => $categoryId,
        'front' => $front,
        'back' => $back,
        'is_bidirectional' => $isBidirectional,
    ];
}

/**
 * Updates the given fields of one card.
 *
 * Only the keys that really exist as columns are accepted, so a value from the
 * request body can never become part of the SQL text. An empty list of changes
 * simply returns the card unchanged.
 *
 * @param array<string, mixed> $changes Values keyed by column name.
 * @return array{id: int, category_id: int, front: string, back: string, is_bidirectional: bool}|null
 */
function update_card(PDO $pdo, int $cardId, array $changes): ?array
{
    $columns = [
        'front' => PDO::PARAM_STR,
        'back' => PDO::PARAM_STR,
        'is_bidirectional' => PDO::PARAM_INT,
    ];

    $assignments = [];
    $values = [];

    foreach ($columns as $column => $type) {
        if (!array_key_exists($column, $changes)) {
            continue;
        }

        $assignments[] = $column . ' = :' . $column;
        $values[$column] = [$changes[$column], $type];
    }

    if ($assignments === []) {
        return find_card($pdo, $cardId);
    }

    $statement = $pdo->prepare(
        'UPDATE cards SET ' . implode(', ', $assignments) . ' WHERE id = :id'
    );
    $statement->bindValue(':id', $cardId, PDO::PARAM_INT);

    foreach ($values as $column => [$value, $type]) {
        if ($column === 'is_bidirectional') {
            $statement->bindValue(':' . $column, $value ? 1 : 0, $type);
            continue;
        }

        $statement->bindValue(':' . $column, (string) $value, $type);
    }

    $statement->execute();

    return find_card($pdo, $cardId);
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
 * How many cards sit directly in this category (not counting its children).
 */
function card_count_for_category(PDO $pdo, int $categoryId): int
{
    $statement = $pdo->prepare('SELECT COUNT(*) FROM cards WHERE category_id = :category_id');
    $statement->bindValue(':category_id', $categoryId, PDO::PARAM_INT);
    $statement->execute();

    return (int) $statement->fetchColumn();
}

/**
 * Deletes every card of the given categories and reports how many were removed.
 *
 * Used while a whole category is deleted: the cards have to go first, because
 * fk_cards_category is ON DELETE RESTRICT and would otherwise stop the delete.
 *
 * @param list<int> $categoryIds
 */
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
