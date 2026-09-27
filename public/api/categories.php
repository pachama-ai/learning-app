<?php

declare(strict_types=1);

/**
 * GET  /api/categories.php              -> die Lernbereiche
 * GET  /api/categories.php?parent_id=2  -> die Unterkategorien von Kategorie 2
 * GET  /api/categories.php?id=2         -> eine Kategorie samt Löschvorschau
 * POST /api/categories.php              -> legt einen Lernbereich oder eine Unterkategorie an
 *
 * Inhalt des POST (alle Felder außer "name" sind freiwillig):
 *   {
 *     "parent_id": 2,                  // weggelassen oder null -> ein Lernbereich
 *     "name": "Geschichte",
 *     "name_en": "History",            // Wortlaut auf Englisch
 *     "name_de": "Geschichte",         // Wortlaut auf Deutsch
 *     "icon_svg": "<svg ...>",
 *     "icon_scale": 1.15
 *   }
 *
 * Jeder Wert wird geprüft, bevor er die Datenbank erreicht, und das SVG wird
 * entschärft (siehe src/helpers/svg_sanitizer.php). In der Antwort steht nie ein
 * Datenbankfehler, kein SQL und keine Zugangsdaten.
 */

require_once __DIR__ . '/../../src/config/database.php';
require_once __DIR__ . '/../../src/helpers/json_response.php';
require_once __DIR__ . '/../../src/helpers/request_input.php';
require_once __DIR__ . '/../../src/helpers/svg_sanitizer.php';
require_once __DIR__ . '/../../src/services/category_service.php';
require_once __DIR__ . '/../../src/helpers/session_user.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method !== 'GET' && $method !== 'POST') {
    send_json_error('method_not_allowed', 'Only GET and POST requests are allowed.', 405);
}

/* -------------------------------------------------------------------------
   POST: einen Lernbereich anlegen (ohne parent_id) oder eine Unterkategorie
   ------------------------------------------------------------------------- */

if ($method === 'POST') {
    $body = read_json_object();

    $parentId = optional_positive_id($body, 'parent_id', 'invalid_parent_id');
    $name = require_input_text($body, 'name', CATEGORY_MAX_NAME_LENGTH, 'invalid_name');
    $nameEn = optional_input_text($body, 'name_en', CATEGORY_MAX_NAME_LENGTH, 'invalid_name_en');
    $nameDe = optional_input_text($body, 'name_de', CATEGORY_MAX_NAME_LENGTH, 'invalid_name_de');
    /* Die Beschreibungsspalten dieser Tabelle werden nicht mehr gelesen und nicht
       mehr geschrieben, eine Anfrage, die sie trotzdem mitschickt, ändert also
       einfach nichts. */
    $iconSvg = optional_svg_icon($body, 'icon_svg', 'invalid_icon');
    $iconScale = optional_icon_scale($body, 'icon_scale', 'invalid_icon_scale');

    try {
        $pdo = create_database_connection();
        $userId = current_user_id($pdo);

        /* Eine Kategorie gehört einem Konto, zum Anlegen braucht es also eines. */
        if ($userId === null) {
            $required = session_user_required_error();

            send_json_error($required['code'], $required['message'], $required['status']);
        }

        // Eine Unterkategorie braucht einen Elternteil, den es wirklich gibt -
        // und der zu diesem Konto gehört.
        if ($parentId !== null && !category_exists($pdo, $parentId, $userId)) {
            send_json_error('parent_not_found', 'The parent category does not exist.', 404);
        }

        // Auf der Spalte name liegt kein eindeutiger Index und die Struktur darf
        // nicht geändert werden, die Doppelprüfung passiert also hier. Zwei
        // Kategorien mit demselben Namen dürfen an verschiedenen Stellen stehen,
        // aber nicht unter demselben Elternteil.
        if (category_sibling_name_exists($pdo, $name, $parentId, $userId)) {
            send_json_error('category_exists', 'A category with this name already exists here.', 409);
        }

        $fields = ['parent_id' => $parentId, 'name' => $name];

        /*
         * Der Maßstab des Symbols ist automatisch: eine Zeichnung wird vor dem
         * Speichern normalisiert, sie füllt ihren Kreis also immer bei Maßstab 1.
         * Geschrieben wird der Wert nur, wenn wirklich ein Symbol Teil dieser
         * Anfrage ist; ein ausdrücklich mitgeschickter Wert aus einer älteren
         * Fassung der Oberfläche wird weiterhin angenommen.
         */
        foreach ([
            'name_en' => $nameEn,
            'name_de' => $nameDe,
            'icon_svg' => $iconSvg,
            'icon_scale' => $iconScale,
        ] as $column => $value) {
            if ($value !== null) {
                $fields[$column] = $value;
            }
        }

        if (isset($fields['icon_svg']) && !array_key_exists('icon_scale', $fields)) {
            $fields['icon_scale'] = 1.0;
        }

        $created = create_category($pdo, $fields, $userId);

        send_json_success($created, 201);
    } catch (Throwable $error) {
        // Einzelheiten gehören nur ins Server-Protokoll, der Browser bekommt
        // einen allgemeinen Satz.
        error_log('Creating a category failed: ' . $error->getMessage());

        send_json_error('category_create_failed', 'The category could not be saved.', 500);
    }
}

/* -------------------------------------------------------------------------
   GET: die Lernbereiche auflisten, die Unterkategorien auflisten oder eine
   einzelne Kategorie lesen
   ------------------------------------------------------------------------- */

$categoryId = null;
$rawId = $_GET['id'] ?? null;

if ($rawId !== null && $rawId !== '') {
    // is_string() weist auch Array-Eingaben wie ?id[]=1 ab. Ohne die Prüfung
    // erreichte ein Array ctype_digit() und danach die Datenbankschicht.
    if (!is_string($rawId) || !ctype_digit($rawId) || (int) $rawId < 1) {
        send_json_error('invalid_id', 'The id parameter must be a positive integer.', 400);
    }

    $categoryId = (int) $rawId;
}

$parentId = null;
$rawParentId = $_GET['parent_id'] ?? null;

// Ein leerer Parameter heißt "kein Filter" und liefert die Hauptkategorien;
// damit bleibt ?parent_id= in einer von Hand getippten Adresse harmlos.
if ($rawParentId !== null && $rawParentId !== '') {
    if (!is_string($rawParentId) || !ctype_digit($rawParentId) || (int) $rawParentId < 1) {
        send_json_error(
            'invalid_parent_id',
            'The parent_id parameter must be a positive integer.',
            400
        );
    }

    $parentId = (int) $rawParentId;
}

try {
    $pdo = create_database_connection();
    $userId = current_user_id($pdo);

    /*
     * Eine Kategorie gehört einem Konto, ein abgemeldeter Besucher hat also nichts
     * aufzulisten und nichts zu lesen. Die Liste antwortet leer mit 200, damit die
     * Startseite ihren Begrüßungszustand zeigen kann statt eines Fehlers; eine
     * einzelne Id antwortet mit 404, denn "nicht deine" und "gibt es nicht" müssen
     * gleich aussehen.
     */
    if ($userId === null) {
        if ($categoryId !== null) {
            send_json_error('category_not_found', 'This category does not exist.', 404);
        }

        send_json_success([]);
    }

    if ($categoryId !== null) {
        $category = find_category($pdo, $categoryId, $userId, true);

        if ($category === null) {
            send_json_error('category_not_found', 'This category does not exist.', 404);
        }

        send_json_success($category);
    }

    $categories = $parentId === null
        ? find_main_categories($pdo, $userId)
        : find_subcategories($pdo, $parentId, $userId);

    // Ein leeres Ergebnis ist eine gültige Antwort und kommt als leeres Array
    // zurück, damit die Oberfläche ihren leeren Zustand zeigen kann und das nicht
    // für einen Fehler hält.
    send_json_success($categories);
} catch (Throwable $error) {
    // Der echte Grund gehört nur ins Server-Protokoll. Darin können das Passwort,
    // die Verbindungszeichenfolge oder SQL stehen, der Browser bekommt deshalb
    // nur einen allgemeinen Satz.
    error_log('Loading categories failed: ' . $error->getMessage());

    send_json_error(
        'categories_unavailable',
        'The categories could not be loaded.',
        500
    );
}
