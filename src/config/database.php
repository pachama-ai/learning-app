<?php

declare(strict_types=1);

/**
 * Baut die PDO-Verbindung zur Datenbank learning_app.
 *
 * Die Zugangsdaten stehen NICHT in dieser Datei und auch nicht in einer Datei in
 * diesem Ordner, sondern in der Datei .env im Projektstamm. Gelesen werden sie
 * ueber src/helpers/env.php. Damit gibt es genau eine Stelle mit Werten, sie
 * liegt ausserhalb des Web-Roots, und sie ist in .gitignore eingetragen.
 *
 * Welche Werte gebraucht werden, steht in .env.example im Projektstamm.
 */

require_once __DIR__ . '/../helpers/env.php';

/**
 * Baut eine PDO-Verbindung zu der Datenbank, die in .env beschrieben ist.
 *
 * @throws RuntimeException wenn eine Pflichtvariable fehlt (siehe env_required()).
 * @throws PDOException     wenn der Datenbankserver die Verbindung ablehnt.
 */
function create_database_connection(): PDO
{
    $host = env_required('DB_HOST');
    $port = (int) env('DB_PORT', '3306');
    $database = env_required('DB_NAME');
    $user = env_required('DB_USER');
    $password = env_required('DB_PASS');
    $charset = env('DB_CHARSET', 'utf8mb4');

    // Der DSN enthaelt nur unkritische Angaben (Host, Port, Datenbankname).
    // Benutzer und Passwort werden PDO einzeln uebergeben und landen deshalb in
    // keiner Zeichenkette, die versehentlich in einem Protokoll auftauchen
    // koennte.
    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        $host,
        $port,
        $database,
        (string) $charset
    );

    // Warum diese Optionen:
    // - ERRMODE_EXCEPTION: eine fehlschlagende Abfrage wirft, statt still
    //   "false" zu liefern. Ein Fehler kann damit nicht uebersehen werden.
    // - DEFAULT_FETCH_MODE = FETCH_ASSOC: Zeilen kommen als assoziative Arrays
    //   mit den echten Spaltennamen der Datenbank zurueck.
    // - EMULATE_PREPARES = false: MySQL bereitet die Anweisung selbst vor. Die
    //   Platzhalter bleiben vom SQL-Text getrennt - das macht Prepared
    //   Statements zu einem echten Schutz vor SQL-Injection.
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];

    return new PDO($dsn, $user, $password, $options);
}
