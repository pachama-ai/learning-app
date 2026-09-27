<?php

declare(strict_types=1);

/**
 * GET /api/category_icon.php?id=2
 *
 * Liefert die Zeichnung, die in categories.icon_svg gespeichert ist.
 *
 * Warum es diesen Endpunkt gibt: die Zeichnung liegt in der Datenbank und kann
 * bis zu 350 KB groß sein, deshalb trägt sie keine Antwort dieser Anwendung mit
 * sich. Eine Zeile sagt nur, ob es ein Symbol gibt, und nennt einen kurzen
 * Fingerabdruck; das <img>-Tag fordert die Zeichnung hier an, und der Browser
 * behält sie.
 *
 * In der Adresse steht dieser Fingerabdruck (v=...), sie ändert sich also, sobald
 * sich die Zeichnung ändert: die Antwort darf so lange zwischengespeichert
 * werden, wie ein Browser möchte, ohne je ein altes Symbol zu zeigen. Zusätzlich
 * trägt die Antwort ein ETag und beantwortet eine bedingte Anfrage mit 304, ein
 * Browser mit der Zeichnung bekommt sie also gar nicht erneut.
 *
 * Die Antwort ist immer image/svg+xml, nie HTML, und sie geht mit einer
 * Content-Security-Policy hinaus, die nichts erlaubt. Zusammen damit, dass die
 * Datei nur je in einem <img>-Tag gezeichnet wird - wo ein Browser keine Skripte
 * ausführt - kann das gespeicherte SVG nichts ausführen. Das SVG wird außerdem
 * vor dem Speichern entschärft (src/helpers/svg_sanitizer.php).
 *
 * 404 heißt "diese Kategorie hat kein eigenes Symbol"; die Seite fällt dann auf
 * die Zeichnung zurück, die mit der Anwendung kommt. Kein Dateipfad, kein
 * Datenbankfehler und keine andere Einzelheit geht je an den Browser.
 */

require_once __DIR__ . '/../../src/config/database.php';
require_once __DIR__ . '/../../src/helpers/json_response.php';
require_once __DIR__ . '/../../src/helpers/request_input.php';
require_once __DIR__ . '/../../src/helpers/session_user.php';

/*
 * Die Zeichnung ist SVG, und SVG ist Text: sie reist gezippt. Apache macht das
 * von selbst (siehe deploy/apache/), der Entwicklungsserver von PHP nicht - damit
 * sich beide gleich verhalten. Auf einer Zeichnung mit 110 KB gemessen: 28 KB mit
 * gzip.
 */
if (!ini_get('zlib.output_compression') && !headers_sent()) {
    ini_set('zlib.output_compression', '6');
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method !== 'GET' && $method !== 'HEAD') {
    send_json_error('method_not_allowed', 'Only GET requests are allowed.', 405);
}

$categoryId = require_query_id('id');

try {
    $pdo = create_database_connection();

    /*
     * Die Zeichnung gehört einer Kategorie, und eine Kategorie gehört einem Konto:
     * die Zeichnung des einen Kontos wird keinem anderen ausgeliefert. Eine
     * abgemeldete Anfrage bekommt dasselbe 404 wie eine falsche Id - die Adresse
     * darf nicht verraten, dass es eine Zeichnung gibt, die der Besucher nicht
     * sehen darf.
     */
    $userId = current_user_id($pdo);

    $statement = $pdo->prepare(
        'SELECT icon_svg, MD5(icon_svg) AS fingerprint
           FROM categories
          WHERE id = :id AND owner_user_id = :owner_user_id'
    );
    $statement->bindValue(':id', $categoryId, PDO::PARAM_INT);
    $statement->bindValue(':owner_user_id', $userId, $userId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
    $statement->execute();

    $row = $statement->fetch();

    $svg = is_array($row) ? $row['icon_svg'] : null;

    if (!is_string($svg) || trim($svg) === '') {
        // Deckt "keine solche Kategorie", "nicht die Kategorie dieses Kontos"
        // und "Kategorie ohne Symbol" ab - für den Besucher sind alle drei
        // dasselbe.
        send_json_error('icon_not_found', 'This category has no stored icon.', 404);
    }

    /*
     * Der Fingerabdruck ist die MD5-Summe der Zeichnung - derselbe Wert, den die
     * Adresse trägt. Ein Browser, der diese Zeichnung schon hat, bekommt deshalb
     * "nichts geändert" statt noch einmal 350 KB.
     */
    $etag = '"' . substr(is_array($row) ? (string) $row['fingerprint'] : '', 0, 8) . '"';
    $ifNoneMatch = $_SERVER['HTTP_IF_NONE_MATCH'] ?? '';

    if (!headers_sent()) {
        http_response_code(200);
        header('Content-Type: image/svg+xml; charset=utf-8');
        // Solange diese Datei gezeigt wird, darf von nirgendwo etwas geladen werden.
        header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; sandbox");
        header('X-Content-Type-Options: nosniff');
        header('ETag: ' . $etag);
        /*
         * Die Adresse trägt den Fingerabdruck und ändert sich deshalb nie, solange
         * die Zeichnung gleich bleibt: ein neues Symbol ist eine neue Adresse. Das
         * macht "immutable" hier ehrlich.
         */
        header('Cache-Control: private, max-age=604800, immutable');
    }

    if (is_string($ifNoneMatch) && $ifNoneMatch !== '' && strpos($ifNoneMatch, $etag) !== false) {
        /* Der Browser hat diese Zeichnung schon. */
        http_response_code(304);
        exit;
    }

    echo $svg;
} catch (Throwable $error) {
    // Der echte Grund landet nur im Server-Protokoll.
    error_log('Serving a category icon failed: ' . $error->getMessage());

    send_json_error('icon_unavailable', 'The icon could not be loaded.', 500);
}
