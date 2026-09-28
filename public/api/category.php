<?php

declare(strict_types=1);

/**
 * PATCH  /api/category.php?id=7  -> ändert die Felder, die mitgeschickt werden
 * DELETE /api/category.php?id=7  -> entfernt die Kategorie und alles darin
 *                                    ({"confirm": true}, wenn etwas darin liegt)
 *
 * Inhalt des PATCH (nur die Felder, die sich ändern sollen):
 *   {
 *     "name": "Geschichte",           // ebenso: name_en, name_de
 *     "icon_svg": "<svg ...>",        // null entfernt das Symbol
 *     "icon_scale": 1.15              // null setzt es auf 1.00 zurück
 *   }
 *
 * Inhalt des DELETE (freiwillig):
 *   {"confirm": true}
 *
 * Löschen entfernt die Kategorie, jede Unterkategorie darunter und jede Karte in
 * diesem Teilbaum, weil die Fremdschlüssel dieser Datenbank ON DELETE RESTRICT sind
 * und das Löschen sonst verweigern würden. Alles passiert in einer Transaktion, ein
 * halb gelöschter Baum kann also nie zurückbleiben.
 *
 * Eine Löschanfrage braucht KEINEN Inhalt. Nachgefragt wird nur, wenn wirklich etwas
 * an der Kategorie hängt - Unterkategorien oder Karten - und das entscheidet der
 * Server aus den Daten, nicht der Browser:
 *
 *   - eine leere Kategorie wird allein mit der Id gelöscht
 *   - eine Kategorie mit Unterkategorien oder Karten braucht "confirm": true
 *
 * Eingetippt werden muss nichts: der Browser zeigt die Zahlen im gemeinsamen Dialog
 * und schickt die Zustimmung, sobald die Person zugestimmt hat. Eine nicht bestätigte
 * Anfrage bekommt den Code "confirm_required" und ändert nichts.
 *
 * Die Antwort ist
 *   {"success": true, "data": {"deleted_category_id": 7, ...}}
 * und der Endpunkt antwortet damit erst, wenn die Zeile wirklich weg ist: der Service
 * prüft seine eigene Löschzahl und sieht die Id noch einmal nach, bevor er bestätigt.
 */

require_once __DIR__ . '/../../src/config/database.php';
require_once __DIR__ . '/../../src/helpers/json_response.php';
require_once __DIR__ . '/../../src/helpers/request_input.php';
require_once __DIR__ . '/../../src/helpers/session_user.php';
require_once __DIR__ . '/../../src/helpers/svg_sanitizer.php';
require_once __DIR__ . '/../../src/services/category_service.php';

$method = $_SERVER['REQUEST_METHOD'] ?? '';

if ($method !== 'PATCH' && $method !== 'DELETE') {
    send_json_error('method_not_allowed', 'Only PATCH and DELETE requests are allowed.', 405);
}

$categoryId = require_query_id('id');

/*
 * Der Inhalt ist hier freiwillig. Ein DELETE, das nichts als eine Id braucht, schickt
 * gar keinen mit, und ein PATCH schickt die Felder, die es ändern möchte.
 */
$body = read_json_object(true);

try {
    $pdo = create_database_connection();
    $userId = current_user_id($pdo);

    /* Eine Kategorie gehört einem Konto: Umbenennen und Löschen brauchen es. */
    if ($userId === null) {
        $required = session_user_required_error();

        send_json_error($required['code'], $required['message'], $required['status']);
    }

    $current = find_category($pdo, $categoryId, $userId);

    if ($current === null) {
        send_json_error('category_not_found', 'This category does not exist.', 404);
    }

    /* ---------------------------------------------------------------------
       DELETE: eine Bestätigung, dann verschwindet der ganze Teilbaum
       --------------------------------------------------------------------- */

    if ($method === 'DELETE') {
        /*
         * Wie viel an dieser Kategorie hängt, entscheidet, ob die Anfrage bestätigt
         * werden muss. Gezählt wird HIER, aus der Datenbank - eine von Hand gebaute
         * Anfrage kann die Bestätigung nicht überspringen, indem sie die Zustimmung
         * weglässt, und der Browser kann keine verlangen, wo keine nötig ist.
         *
         * Auf "confirm_required" antwortet der Browser, indem er die Kategorie noch
         * einmal liest (api/categories.php?id=N) und mit diesen Zahlen nachfragt.
         */
        $dependents = category_delete_dependents($pdo, $categoryId, $userId);

        if ($dependents['descendants'] > 0 || $dependents['cards'] > 0) {
            if (optional_flag($body, 'confirm') !== true) {
                send_json_error(
                    'confirm_required',
                    'This category has subcategories or cards. Send "confirm": true to delete it.',
                    400
                );
            }
        }

        try {
            $deleted = delete_category_tree($pdo, $categoryId, $userId);
        } catch (PDOException $error) {
            /* Eine Zeile, die noch auf diese Kategorie zeigt: ein Widerspruch in den
               Daten, kein kaputter Server. */
            if ((string) $error->getCode() === '23000') {
                send_json_error(
                    'category_delete_conflict',
                    'Other data still refers to this category.',
                    409
                );
            }

            throw $error;
        } catch (RuntimeException $error) {
            /* Der Service hat die Bestätigung verweigert, weil die Zeile noch da war. */
            error_log('Deleting category ' . $categoryId . ' was refused: ' . $error->getMessage());

            send_json_error('category_delete_failed', 'The category could not be deleted.', 500);
        }

        send_json_success([
            'deleted_category_id' => $categoryId,
            'deleted_categories' => $deleted['categories'],
            'deleted_cards' => $deleted['cards'],
            'deleted_progress' => $deleted['progress'],
        ]);
    }

    /* ---------------------------------------------------------------------
       PATCH: nur die Felder ändern, die wirklich geschickt wurden
       --------------------------------------------------------------------- */

    $changes = [];

    if (array_key_exists('name', $body)) {
        $changes['name'] = require_input_text($body, 'name', CATEGORY_MAX_NAME_LENGTH, 'invalid_name');
    }

    /*
     * Hier werden nur die Namen angenommen. Die Beschreibungsspalten bleiben in Ruhe:
     * sie gehören nicht mehr zur Anwendung, und eine Anfrage, die sie trotzdem
     * mitschickt, darf kein Fehler sein (sie ändert einfach nichts).
     */
    foreach ([
        'name_en' => CATEGORY_MAX_NAME_LENGTH,
        'name_de' => CATEGORY_MAX_NAME_LENGTH,
    ] as $field => $maxLength) {
        if (array_key_exists($field, $body)) {
            // Ein leerer Wert leert das Feld.
            $changes[$field] = optional_input_text($body, $field, $maxLength, 'invalid_' . $field);
        }
    }


    if (array_key_exists('icon_scale', $body)) {
        $scale = optional_icon_scale($body, 'icon_scale', 'invalid_icon_scale');
        $changes['icon_scale'] = $scale ?? 1.0;
    }

    if (array_key_exists('icon_svg', $body)) {
        // null oder eine leere Zeichenkette entfernt das Symbol, und die Kategorie
        // fällt auf die Zeichnung zurück, die mit der Anwendung kommt.
        $changes['icon_svg'] = $body['icon_svg'] === null || $body['icon_svg'] === ''
            ? null
            : optional_svg_icon($body, 'icon_svg', 'invalid_icon');
    }

    /*
     * Eine normalisierte Zeichnung füllt ihren Kreis bei Maßstab 1, der Wert wird also
     * automatisch gesetzt, sobald ein Symbol Teil dieser Anfrage ist. Die Spalte wird
     * weiterhin geschrieben - sie wird nur von keiner Person mehr ausgefüllt.
     */
    if (array_key_exists('icon_svg', $changes) && $changes['icon_svg'] !== null
        && !array_key_exists('icon_scale', $changes)) {
        $changes['icon_scale'] = 1.0;
    }

    if ($changes === []) {
        send_json_error('invalid_request_body', 'Send at least one field to change.', 400);
    }

    // Eine umbenannte Kategorie darf nicht mit einer Schwesterkategorie zusammenstoßen.
    if (array_key_exists('name', $changes) && $changes['name'] !== $current['name']) {
        if (category_sibling_name_exists($pdo, $changes['name'], $current['parent_id'], $userId, $categoryId)) {
            send_json_error('category_exists', 'A category with this name already exists here.', 409);
        }
    }

    $updated = update_category($pdo, $categoryId, $changes, $userId);

    send_json_success($updated);
} catch (Throwable $error) {
    // Einzelheiten gehören nur ins Server-Protokoll, der Browser bekommt einen
    // allgemeinen Satz.
    error_log('Changing a category failed: ' . $error->getMessage());

    if ($method === 'DELETE') {
        send_json_error('category_delete_failed', 'The category could not be deleted.', 500);
    }

    send_json_error('category_update_failed', 'The category could not be saved.', 500);
}
