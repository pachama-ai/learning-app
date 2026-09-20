<?php

declare(strict_types=1);

/**
 * Read-only check: how many top-level categories exist right now?
 * Uses the app's own connection helper so no credentials are printed.
 */

require_once __DIR__ . '/src/config/database.php';

$pdo = create_database_connection();

$rows = $pdo->query(
    'SELECT id, parent_id, name FROM categories ORDER BY parent_id IS NOT NULL, id'
)->fetchAll();

echo count($rows) . " rows\n";

foreach ($rows as $row) {
    echo str_pad((string) $row['id'], 4)
        . str_pad($row['parent_id'] === null ? 'top' : 'sub', 5)
        . $row['name'] . "\n";
}
