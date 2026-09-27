<?php

declare(strict_types=1);

/**
 * GET /api/bootstrap.php -> alles, was die Oberfläche für ihren ersten Aufbau braucht
 *
 * Eine Antwort statt einer Anfrage pro Ansicht: die Lernbereiche, ihre Unterkategorien
 * und die Karten jeder Unterkategorie, jede Karte mit dem Stand, den sie für das
 * angemeldete Konto hat. Der Browser behält diese Antwort im Speicher und baut die
 * übrigen Ansichten daraus auf, das Durchwandern der Anwendung kostet also keine
 * weitere Anfrage.
 *
 * Was absichtlich NICHT in dieser Antwort steckt:
 *
 *   - die erzeugte Aufgabe einer Übungskarte. Ihre Zahlen werden gezogen, während
 *     die Karte gelesen wird; sie mitzuschicken würde immer dieselben Zahlen zeigen.
 *     Die Antwort nennt nur die Art der Aufgabe und ihre Zahlen, und der Browser holt
 *     sich eine frische Aufgabe, wenn er so eine Karte wirklich anzeigt
 *     (api/exercise_preview.php).
 *   - die Zeichnungen der Kategorien und die Kartenbilder. Die haben ihren eigenen
 *     Abruf und ihren eigenen Speicher im Browser und kommen aus
 *     api/category_icon.php und den Dateien in public/assets/maps.
 *
 * Die Antwort liest nur: sie beginnt keine Transaktion, ändert nichts und legt keine
 * Fortschrittszeile an. Ohne angemeldetes Konto ist jede Karte "neu", und das stimmt
 * auch so - ohne Konto-Id kann es keinen Fortschritt geben.
 *
 * Die Antwort ist absichtlich ein flaches Objekt:
 *   {
 *     "areas":       [ ... dieselbe Form wie GET /api/categories.php ... ],
 *     "children":    { "2": [ ... Unterkategorien von 2 ... ], ... },
 *     "cards":       { "85": [ ... Karten von 85, jede mit "progress" ... ], ... },
 *     "summaries":   { "85": { "total": 15, "due": 15, ... }, ... },
 *     "has_user":    true|false,
 *     "content_languages": ["de"],
 *     "language":    "de"
 *   }
 */

require_once __DIR__ . '/../../src/config/database.php';
require_once __DIR__ . '/../../src/helpers/json_response.php';
require_once __DIR__ . '/../../src/helpers/request_input.php';
require_once __DIR__ . '/../../src/helpers/session_user.php';
require_once __DIR__ . '/../../src/services/category_service.php';
require_once __DIR__ . '/../../src/services/card_service.php';
require_once __DIR__ . '/../../src/services/dashboard_service.php';
require_once __DIR__ . '/../../src/services/review_service.php';

/*
 * Das ist die größte einzelne Antwort der Anwendung (rund 750 KB JSON), und JSON aus
 * Kartentexten lässt sich sehr gut zippen. Jeder Browser bittet um gzip, der erste
 * Aufbau überträgt also nur einen Bruchteil davon. Die Einstellung muss stehen, bevor
 * etwas geschrieben wird, deshalb steht sie hier und nicht weiter unten.
 */
if (!headers_sent() && extension_loaded('zlib')) {
    @ini_set('zlib.output_compression', '1');
    @ini_set('zlib.output_compression_level', '6');
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method !== 'GET') {
    send_json_error('method_not_allowed', 'Only GET requests are allowed.', 405);
}

/* Die Oberfläche sagt, welche ihrer beiden Sprachen sie gerade zeigt. */
$language = optional_query_language();

try {
    $pdo = create_database_connection();
    $userId = current_user_id($pdo);
    $columns = card_columns($pdo);

    /*
     * Kategorien gehören einem Konto, ein abgemeldeter Besucher hat hier also nichts
     * zu bekommen. Die Antwort behält trotzdem ihre genaue Form und ist nur leer -
     * daraus liest die Startseite ihren Begrüßungszustand.
     */
    if ($userId === null) {
        send_json_success([
            'areas' => [],
            'children' => [],
            'cards' => [],
            'summaries' => [],
            'has_user' => false,
            'content_languages' => card_content_languages($columns),
            'language' => $language,
        ]);
    }

    $areas = find_main_categories($pdo, $userId);

    /*
     * Die Unterkategorien jedes Bereichs und - falls es je eine dritte Ebene gibt -
     * die Unterkategorien einer Unterkategorie. Gefragt wird nur für Kategorien, die
     * wirklich welche haben, ein zweistufiger Baum kostet also eine Abfrage pro
     * Bereich und keine mehr.
     */
    $children = [];
    $level = array_map(static fn (array $area): int => (int) $area['id'], $areas);

    while ($level !== []) {
        $withChildren = category_ids_with_children($pdo, $level, $userId);
        $next = [];

        foreach ($withChildren as $parentId) {
            $list = find_subcategories($pdo, $parentId, $userId);
            $children[(string) $parentId] = $list;

            foreach ($list as $child) {
                $next[] = (int) $child['id'];
            }
        }

        $level = $next;
    }

    /* Alle Karten aller Kategorien in einem Lesevorgang, nach Kategorie gruppiert. */
    $all = review_cards_all_categories($pdo, $userId, $language);

    /*
     * Die Aufgabe einer Übungskarte bleibt weg: sie wird gezeichnet, während die Karte
     * gezeigt wird; eine mitgeschickte Aufgabe würde Zahlen festhalten, die sich
     * ändern müssen.
     */
    foreach ($all['cards'] as $categoryId => $list) {
        foreach ($list as $index => $card) {
            if (isset($card['exercise']['task'])) {
                unset($all['cards'][$categoryId][$index]['exercise']['task']);
            }
        }
    }

    send_json_success([
        'areas' => $areas,
        'children' => $children,
        'cards' => $all['cards'],
        'summaries' => $all['summaries'],
        /*
         * Wie viele Tage in Folge diese Person gelernt hat (siehe
         * src/services/dashboard_service.php).
         *
         * Sie reist mit dem Aufbau mit, weil die erste Ansicht einer Seite allein aus
         * dieser Antwort entsteht - ohne sie hätte die Kachel "Serie" nichts zu zeigen,
         * bis ein Schreibvorgang den Browser die Karten neu holen ließe.
         */
        'streak' => $userId === null ? ['available' => false, 'days' => 0] : dashboard_streak($pdo, $userId),
        /*
         * Dieselbe Zahl je Unterkategorie, damit die Kachel der zuerst geöffneten
         * Seite IHRE Tage zeigen kann und nicht die der ganzen Person. Null, solange
         * es die Kategorie-Spalte noch nicht gibt (siehe
         * src/services/dashboard_service.php).
         */
        'streaks' => $userId === null ? null : dashboard_streaks_by_category($pdo, $userId),
        'has_user' => $userId !== null,
        'content_languages' => card_content_languages($columns),
        'language' => $language,
    ]);
} catch (Throwable $error) {
    /* Der Grund gehört ins Server-Protokoll, der Browser bekommt einen allgemeinen Satz. */
    error_log('Building the bootstrap failed: ' . $error->getMessage());

    send_json_error('bootstrap_unavailable', 'The application could not be loaded.', 500);
}
