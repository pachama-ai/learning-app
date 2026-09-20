<?php

declare(strict_types=1);

/**
 * Creates the PDO connection to the learning_app database.
 *
 * This file contains no credentials itself. It only reads them from
 * database.local.php, which is ignored by Git and is not web-accessible.
 */

/**
 * Builds a PDO connection to the database described in database.local.php.
 *
 * @throws RuntimeException when the configuration file is missing or incomplete.
 * @throws PDOException     when the database server refuses the connection.
 */
function create_database_connection(): PDO
{
    $configFilePath = __DIR__ . '/database.local.php';

    if (!is_file($configFilePath)) {
        throw new RuntimeException('Database configuration file is missing.');
    }

    /** @var array<string, mixed> $config */
    $config = require $configFilePath;

    if (!is_array($config)) {
        throw new RuntimeException('The database configuration file must return an array.');
    }

    // Fail with a clear message if a value is missing, instead of letting PDO
    // build a broken connection string that is hard to debug.
    $requiredKeys = ['host', 'port', 'database', 'username', 'password', 'charset'];

    foreach ($requiredKeys as $key) {
        if (!array_key_exists($key, $config)) {
            throw new RuntimeException('Missing database configuration value: ' . $key);
        }
    }

    // The DSN holds only non-secret connection details. The username and the
    // password are passed to PDO separately, so they never end up in a string
    // that could be logged by accident.
    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        (string) $config['host'],
        (int) $config['port'],
        (string) $config['database'],
        (string) $config['charset']
    );

    // Why each option is set:
    // - ERRMODE_EXCEPTION: a failing query throws instead of returning false,
    //   so an error can never be ignored silently.
    // - DEFAULT_FETCH_MODE = FETCH_ASSOC: rows come back as associative arrays
    //   that use the real column names from the database.
    // - EMULATE_PREPARES = false: MySQL prepares the statement on the server.
    //   The placeholders stay separate from the SQL text, which is what makes
    //   prepared statements a real protection against SQL injection.
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];

    return new PDO(
        $dsn,
        (string) $config['username'],
        (string) $config['password'],
        $options
    );
}
