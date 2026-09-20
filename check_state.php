<?php

declare(strict_types=1);

/**
 * Read-only status check: which columns does `categories` have right now, and
 * how many rows are in each table? Confirms that the migration file has NOT been
 * executed by anybody.
 */

require_once __DIR__ . '/src/config/database.php';

$pdo = create_database_connection();

echo "categories columns: ";

$columns = [];

foreach ($pdo->query('DESCRIBE `categories`') as $row) {
    $columns[] = $row['Field'];
}

echo implode(', ', $columns) . "\n";

foreach (['users', 'categories', 'cards', 'user_card_progress'] as $table) {
    echo str_pad($table, 22) . $pdo->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn() . " rows\n";
}
