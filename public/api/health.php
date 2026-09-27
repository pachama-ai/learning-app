<?php

declare(strict_types=1);

/**
 * GET /api/health.php
 *
 * Prüft, ob die API die Datenbank learning_app erreichen kann.
 *
 * Der Endpunkt liest nur: er ändert keine Daten, legt keine Tabellen an und
 * gibt keine Zugangsdaten heraus.
 */

require_once __DIR__ . '/../../src/config/database.php';
require_once __DIR__ . '/../../src/helpers/json_response.php';

// Dieser Endpunkt beantwortet nur GET-Anfragen. send_json_error() beendet die
// Anfrage, deshalb wird der Code darunter bei einer falschen Methode gar nicht
// erreicht.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    send_json_error('method_not_allowed', 'Only GET requests are allowed.', 405);
}

try {
    $pdo = create_database_connection();

    // "SELECT DATABASE()" ist eine feste Anweisung ohne Eingabe von außen,
    // deshalb braucht sie hier kein Prepared Statement. Sie liefert den Namen
    // der Datenbank, die wirklich ausgewählt ist - damit ist bewiesen, dass
    // sowohl die Verbindung als auch die Auswahl der Datenbank funktioniert.
    $databaseName = $pdo->query('SELECT DATABASE()')->fetchColumn();

    send_json_success([
        'database' => is_string($databaseName) ? $databaseName : null,
        'message' => 'Database connection works',
    ]);
} catch (Throwable $error) {
    // Der echte Grund landet nur im Server-Protokoll. Er könnte das Passwort,
    // die Verbindungszeichenfolge oder SQL-Einzelheiten enthalten und darf
    // deshalb niemals an den Browser gehen.
    error_log('Health check failed: ' . $error->getMessage());

    send_json_error(
        'database_connection_failed',
        'The database connection could not be established.',
        500
    );
}
