<?php

declare(strict_types=1);

/**
 * Liest und prueft die Werte, die ein API-Endpunkt im Anfragekoerper bekommen hat.
 *
 * Jede Funktion hier beendet die Anfrage mit einem klaren JSON-Fehler, sobald ein
 * Wert nicht brauchbar ist. Dadurch kann ein Endpunkt seine Felder einfach der
 * Reihe nach lesen:
 *
 *     $body = read_json_object();
 *     $name = require_input_text($body, 'name', 100, 'invalid_name');
 *
 * Die Pruefung steht hier und nicht im Endpunkt, damit Anlegen und Aendern eines
 * Eintrags immer genau dieselben Regeln anwenden.
 *
 * Diese Datei prueft nur die Form der Eingabe. Ob ein Name schon vergeben ist oder
 * eine Kategorie wirklich existiert, entscheidet der Service, denn dafuer braucht
 * es die Datenbank.
 */

/*
 * Eine hochgeladene Zeichnung prueft der Sanitizer, der auch die Groessengrenze
 * kennt. Er wird hier eingebunden, weil die Groesse schon in dieser Datei geprueft
 * wird, bevor die Zeichnung weitergegeben wird.
 */
require_once __DIR__ . '/json_response.php';
require_once __DIR__ . '/svg_sanitizer.php';

/**
 * Liest den Anfragekoerper als JSON-Objekt.
 *
 * @param bool $allowEmptyBody Wenn true, ist eine Anfrage ohne Koerper kein
 *                             Fehler: sie hat dann einfach keine Felder. Ein
 *                             DELETE, das nur eine id braucht, nutzt das.
 * @return array<string, mixed>
 */
function read_json_object(bool $allowEmptyBody = false): array
{
    $raw = (string) file_get_contents('php://input');
    $payload = json_decode($raw, true);

    /* Eine Anfrage, die wirklich nichts geschickt hat, ist "keine Felder" und
       nicht kaputtes JSON. */
    if ($allowEmptyBody && trim($raw) === '') {
        return [];
    }

    // Kaputtes JSON, eine Zahl oder eine blanke Zeichenkette landen alle hier.
    // Ein leeres Objekt ist erlaubt: ein DELETE ohne Felder muss trotzdem etwas
    // schicken, und ob es mehr braucht, entscheidet der Endpunkt selbst.
    if (!is_array($payload)) {
        send_json_error('invalid_request_body', 'Send a JSON object in the request body.', 400);
    }

    return $payload;
}

/**
 * Weist Text zurueck, der nicht gespeichert werden kann: ungueltiges UTF-8,
 * Steuerzeichen, die das Layout zerstoeren wuerden, oder mehr Zeichen als die
 * Spalte fasst.
 */
function clean_input_text(string $value, int $maxLength, string $code): string
{
    if (!mb_check_encoding($value, 'UTF-8')) {
        send_json_error($code, 'The text must be valid UTF-8.', 400);
    }

    $value = trim($value);

    if ($value === '') {
        send_json_error($code, 'The text must not be empty.', 400);
    }

    // mb_strlen zaehlt Zeichen, ein Umlaut gilt also als ein Zeichen.
    if (mb_strlen($value) > $maxLength) {
        send_json_error($code, 'The text is longer than ' . $maxLength . ' characters.', 400);
    }

    // Tabulator und Zeilenumbruch sind erlaubt (eine Beschreibung darf ueber
    // mehrere Zeilen gehen); jedes andere Steuerzeichen wird abgelehnt.
    if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $value) === 1) {
        send_json_error($code, 'The text must not contain control characters.', 400);
    }

    return $value;
}

/**
 * Ein Feld, das da sein muss und nicht leer sein darf.
 *
 * @param array<string, mixed> $body
 */
function require_input_text(array $body, string $key, int $maxLength, string $code): string
{
    if (!array_key_exists($key, $body) || !is_string($body[$key])) {
        send_json_error($code, 'The field "' . $key . '" must be text.', 400);
    }

    return clean_input_text($body[$key], $maxLength, $code);
}

/**
 * Ein optionales Textfeld. Fehlend, null oder leer bedeuten alle "kein Wert".
 *
 * @param array<string, mixed> $body
 */
function optional_input_text(array $body, string $key, int $maxLength, string $code): ?string
{
    if (!array_key_exists($key, $body) || $body[$key] === null) {
        return null;
    }

    if (!is_string($body[$key])) {
        send_json_error($code, 'The field "' . $key . '" must be text.', 400);
    }

    if (trim($body[$key]) === '') {
        return null;
    }

    return clean_input_text($body[$key], $maxLength, $code);
}

/**
 * Eine optionale positive Ganzzahl, wie sie fuer ids gebraucht wird - etwa
 * parent_id oder category_id.
 *
 * @param array<string, mixed> $body
 */
function optional_positive_id(array $body, string $key, string $code): ?int
{
    if (!array_key_exists($key, $body) || $body[$key] === null || $body[$key] === '') {
        return null;
    }

    $value = $body[$key];

    // "7" und 7 werden beide angenommen; eine Kommazahl oder ein Wahrheitswert
    // nicht.
    if (is_string($value) && ctype_digit($value)) {
        $value = (int) $value;
    }

    if (!is_int($value) || $value < 1) {
        send_json_error($code, 'The field "' . $key . '" must be a positive whole number.', 400);
    }

    return $value;
}

/**
 * Ein optionaler Faktor fuer die Groesse einer Symbolzeichnung. Der Bereich
 * verhindert, dass ein Wert das Symbol unsichtbar oder um ein Vielfaches zu gross
 * macht.
 *
 * @param array<string, mixed> $body
 */
function optional_icon_scale(array $body, string $key, string $code): ?float
{
    if (!array_key_exists($key, $body) || $body[$key] === null || $body[$key] === '') {
        return null;
    }

    $value = $body[$key];

    if (!is_int($value) && !is_float($value) && !(is_string($value) && is_numeric($value))) {
        send_json_error($code, 'The icon size must be a number.', 400);
    }

    $scale = round((float) $value, 2);

    if ($scale < 0.2 || $scale > 3.0) {
        send_json_error($code, 'The icon size must be between 0.2 and 3.', 400);
    }

    return $scale;
}

/**
 * Ein optionaler Ja/Nein-Wert.
 *
 * @param array<string, mixed> $body
 */
function optional_flag(array $body, string $key): ?bool
{
    if (!array_key_exists($key, $body) || $body[$key] === null) {
        return null;
    }

    $value = $body[$key];

    if (is_bool($value)) {
        return $value;
    }

    if ($value === 1 || $value === 0) {
        return $value === 1;
    }

    return (bool) filter_var($value, FILTER_VALIDATE_BOOLEAN);
}

/**
 * Eine optionale SVG-Zeichnung. Die Datei wird geprueft, bevor sie gespeichert
 * wird; eine Datei, die sich nicht sicher machen laesst, wird abgelehnt statt
 * gespeichert.
 *
 * @param array<string, mixed> $body
 */
function optional_svg_icon(array $body, string $key, string $code): ?string
{
    if (!array_key_exists($key, $body) || $body[$key] === null || $body[$key] === '') {
        return null;
    }

    if (!is_string($body[$key])) {
        send_json_error($code, 'The icon must be sent as text.', 400);
    }

    /*
     * Zu gross ist eine eigene Antwort. "Das ist keine brauchbare SVG-Datei"
     * waere zwar richtig, aber nutzlos: die Person hat eine Datei, die einfach
     * groesser ist als erlaubt, und genau das sagt die Meldung.
     */
    if (strlen($body[$key]) > SVG_MAX_UPLOAD_BYTES) {
        send_json_error(
            'icon_too_large',
            'The icon is larger than ' . (int) (SVG_MAX_UPLOAD_BYTES / 1024) . ' KB.',
            400
        );
    }

    $svg = svg_sanitize($body[$key]);

    if ($svg === null) {
        send_json_error($code, 'The icon is not a usable SVG file.', 400);
    }

    return $svg;
}


/**
 * Eine Pflicht-id, die in der Adresse steht, etwa ?id=7.
 *
 * Nur Ziffern werden angenommen; ein Array (?id[]=1) oder anderer Text wird
 * abgelehnt, bevor er die Datenbankschicht erreichen kann.
 */
function require_query_id(string $key, string $code = 'invalid_id'): int
{
    $raw = $_GET[$key] ?? null;

    if (!is_string($raw) || !ctype_digit($raw) || (int) $raw < 1) {
        send_json_error($code, 'The ' . $key . ' parameter must be a positive whole number.', 400);
    }

    return (int) $raw;
}

/**
 * Die Sprache, in der der Kartentext gezeigt werden soll.
 *
 * Die Oberflaeche wechselt ohne Neuladen zwischen Deutsch und Englisch und sagt
 * dem Server deshalb, welche der beiden sie gerade zeigt. Ein fehlender oder
 * unbekannter Wert faellt auf Deutsch zurueck, statt ein Fehler zu sein: die Liste
 * muss trotzdem laden.
 */
function optional_query_language(string $key = 'language', string $fallback = 'de'): string
{
    $raw = $_GET[$key] ?? null;

    if (!is_string($raw) || trim($raw) === '') {
        return $fallback;
    }

    $value = strtolower(substr(trim($raw), 0, 2));

    if (!in_array($value, SUPPORTED_CONTENT_LANGUAGES, true)) {
        return $fallback;
    }

    return $value;
}

/** Die Sprachen, in denen Kartentext liegen darf. */
const SUPPORTED_CONTENT_LANGUAGES = ['de', 'en'];
