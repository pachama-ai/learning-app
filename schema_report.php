<?php

declare(strict_types=1);

/**
 * Read-only schema report. Uses the app's own connection helper, so no
 * credentials are ever printed.
 */

require_once __DIR__ . '/src/config/database.php';

$pdo = create_database_connection();

foreach (['users', 'categories', 'cards', 'user_card_progress'] as $table) {
    echo "==================== " . $table . " ====================\n";

    foreach ($pdo->query('DESCRIBE `' . $table . '`') as $row) {
        printf(
            "%-22s %-28s %-6s %-8s %-10s %s\n",
            $row['Field'],
            $row['Type'],
            $row['Null'],
            $row['Key'],
            $row['Default'] === null ? 'NULL' : (string) $row['Default'],
            $row['Extra']
        );
    }

    $create = $pdo->query('SHOW CREATE TABLE `' . $table . '`')->fetch();
    echo "--- create ---\n" . $create['Create Table'] . "\n\n";
}

foreach (['categories', 'cards', 'user_card_progress', 'users'] as $table) {
    $count = $pdo->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn();
    echo str_pad($table, 22) . ' rows: ' . $count . "\n";
}
