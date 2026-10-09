<?php

declare(strict_types=1);

/**
 * POST /api/categories_delete.php -> mehrere Unterkategorien auf einmal löschen
 *
 * Inhalt:
 *   {
 *     "parent_id": 5,          // der Lernbereich, dessen Übersicht offen ist
 *     "ids": [111, 112, 113]   // die angekreuzten Unterkategorien
 *   }
 *
 * Gelöscht werden dürfen nur DIREKTE Unterkategorien dieses Bereichs. Der Bereich muss
 * dem angemeldeten Konto gehören, und jede Id muss eines seiner Kinder sein; alles andere
 * weist der Service ab, bevor etwas geschrieben wird. Der Lernbereich selbst ist über
 * diesen Endpunkt nicht löschbar.
 *
 * Das ist der EINE Weg, eine Unterkategorie zu löschen. api/category.php verweigert es
 * für eine Kategorie mit Elternteil, damit die alte direkte Ausführung von der
 * Detailseite nicht mehr greift.
 *
 * Alles passiert in EINER Transaktion: entweder verschwinden alle angekreuzten
 * Teilbäume oder keiner. Karten, Lernfortschritte und Kartenvarianten (über den
 * ON-DELETE-CASCADE der Karten) gehen dabei mit.
 *
 * Die Antwort ist
 *   {"success": true, "data": {"deleted_categories": 3, "deleted_cards": 1200, "deleted_progress": 41}}
 */

require_once __DIR__ . '/../../src/config/database.php';
require_once __DIR__ . '/../../src/helpers/json_response.php';
require_once __DIR__ . '/../../src/helpers/request_input.php';
require_once __DIR__ . '/../../src/helpers/session_user.php';
require_once __DIR__ . '/../../src/services/category_service.php';

/** So viele Ids nimmt eine Anfrage höchstens an. Kein Bereich hat je mehr Kinder. */
const SUBCATEGORY_DELETE_MAX = 200;

$method = $_SERVER['REQUEST_METHOD'] ?? '';

if ($method !== 'POST') {
    send_json_error('method_not_allowed', 'Only POST requests are allowed.', 405);
}

$body = read_json_object();

/* Diese Funktion beendet die Anfrage selbst, wenn der Wert fehlt oder keine ganze Zahl ist. */
$parentId = optional_positive_id($body, 'parent_id', 'invalid_parent_id');

if ($parentId === null) {
    send_json_error('invalid_parent_id', 'The field "parent_id" must be a positive whole number.', 400);
}

$rawIds = $body['ids'] ?? null;

if (!is_array($rawIds) || !array_is_list($rawIds) || $rawIds === []) {
    send_json_error('invalid_ids', 'The field "ids" must be a non-empty list of ids.', 400);
}

if (count($rawIds) > SUBCATEGORY_DELETE_MAX) {
    send_json_error('invalid_ids', 'Too many ids in one request.', 400);
}

/* Jede Id wird geprüft und dabei entschärft: doppelte Ids zählen nur einmal, damit
   dieselbe Unterkategorie nicht zweimal in derselben Transaktion gelöscht wird. */
$ids = [];

foreach ($rawIds as $value) {
    if (is_string($value) && ctype_digit($value)) {
        $value = (int) $value;
    }

    if (!is_int($value) || $value < 1) {
        send_json_error('invalid_ids', 'Every id in "ids" must be a positive whole number.', 400);
    }

    $ids[$value] = $value;
}

try {
    $pdo = create_database_connection();
    $userId = current_user_id($pdo);

    if ($userId === null) {
        $required = session_user_required_error();

        send_json_error($required['code'], $required['message'], $required['status']);
    }

    $result = delete_subcategories($pdo, $parentId, array_values($ids), $userId);

    if ($result['ok'] !== true) {
        $messages = [
            'parent_not_found' => 'This learning area does not exist.',
            'invalid_selection' => 'Every id must be a direct subcategory of that learning area.',
            'nothing_to_delete' => 'No subcategory was selected.',
        ];

        send_json_error(
            (string) $result['code'],
            $messages[$result['code']] ?? 'The selection could not be deleted.',
            $result['code'] === 'parent_not_found' ? 404 : 400
        );
    }

    $data = $result['data'] ?? ['categories' => 0, 'cards' => 0, 'progress' => 0];

    send_json_success([
        'deleted_categories' => (int) $data['categories'],
        'deleted_cards' => (int) $data['cards'],
        'deleted_progress' => (int) $data['progress'],
    ]);
} catch (PDOException $error) {
    /* Eine Zeile, die noch auf eine gelöschte Kategorie zeigt: ein Widerspruch in den
       Daten, kein kaputter Server. */
    if ((string) $error->getCode() === '23000') {
        send_json_error('category_delete_conflict', 'Other data still refers to these subcategories.', 409);
    }

    error_log('Deleting subcategories failed: ' . $error->getMessage());

    send_json_error('category_delete_failed', 'The subcategories could not be deleted.', 500);
} catch (Throwable $error) {
    error_log('Deleting subcategories failed: ' . $error->getMessage());

    send_json_error('category_delete_failed', 'The subcategories could not be deleted.', 500);
}
