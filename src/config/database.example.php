<?php

/**
 * EXAMPLE database configuration - safe to commit.
 *
 * This file contains no real credentials. It exists so that a new developer
 * can see which values are required and in which format.
 *
 * To set up a working environment:
 *   1. Copy this file to database.local.php
 *   2. Enter the real password in the copy
 *
 * database.local.php is listed in .gitignore and must never be committed.
 */

return [
    // Host of the database server. "localhost" means the same machine as PHP.
    'host' => 'localhost',

    // Default MySQL/MariaDB port.
    'port' => 3306,

    // Name of the existing database (created manually in phpMyAdmin).
    'database' => 'learning_app',

    // Existing database user.
    'username' => 'learning_admin',

    // Never put a real password in this example file.
    'password' => 'YOUR_PASSWORD_HERE',

    // Character set for the connection. utf8mb4 stores every character,
    // including emoji and all German umlauts.
    'charset' => 'utf8mb4',
];
