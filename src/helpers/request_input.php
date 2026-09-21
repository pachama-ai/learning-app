<?php

declare(strict_types=1);

/**
 * Reads and validates the values an API endpoint received in its request body.
 *
 * Every function here ends the request with a clear JSON error as soon as a
 * value is not usable, so an endpoint can read its fields in a straight line:
 *
 *     $body = read_json_object();
 *     $name = require_input_text($body, 'name', 100, 'invalid_name');
 *
 * The validation lives here instead of in the endpoint so that creating and
 * updating a record always apply exactly the same rules.
 *
 * This file only checks the shape of the input. Whether a name is already taken
 * or whether a category really exists is decided in the service, because that
 * needs the database.
 */

/**
 * Reads the request body as a JSON object.
 *
 * @return array<string, mixed>
 */
/**
 * @param bool $allowEmptyBody When true, a request without a body is not an
 *                             error: it simply has no fields. A DELETE that
 *                             needs nothing but an id uses this.
 * @return array<string, mixed>
 */
function read_json_object(bool $allowEmptyBody = false): array
{
    $raw = (string) file_get_contents('php://input');
    $payload = json_decode($raw, true);

    /* A request that really sent nothing is "no fields", not bad JSON. */
    if ($allowEmptyBody && trim($raw) === '') {
        return [];
    }

    // Broken JSON, a number or a bare string all end up here. An empty object
    // is allowed: a DELETE that carries no field still has to send something,
    // and the endpoint decides separately whether it needs more than that.
    if (!is_array($payload)) {
        send_json_error('invalid_request_body', 'Send a JSON object in the request body.', 400);
    }

    return $payload;
}

/**
 * Rejects text that cannot be stored: invalid UTF-8, control characters that
 * would break the layout, or more characters than the column holds.
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

    // mb_strlen counts characters, so an umlaut counts as one.
    if (mb_strlen($value) > $maxLength) {
        send_json_error($code, 'The text is longer than ' . $maxLength . ' characters.', 400);
    }

    // Tab and newline are allowed (a description may span lines); every other
    // control character is refused.
    if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $value) === 1) {
        send_json_error($code, 'The text must not contain control characters.', 400);
    }

    return $value;
}

/**
 * A field that must be there and must not be empty.
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
 * An optional text field. Missing, null or empty all mean "no value".
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
 * An optional positive integer, used for ids such as parent_id or category_id.
 *
 * @param array<string, mixed> $body
 */
function optional_positive_id(array $body, string $key, string $code): ?int
{
    if (!array_key_exists($key, $body) || $body[$key] === null || $body[$key] === '') {
        return null;
    }

    $value = $body[$key];

    // "7" and 7 are both accepted; a float or a boolean is not.
    if (is_string($value) && ctype_digit($value)) {
        $value = (int) $value;
    }

    if (!is_int($value) || $value < 1) {
        send_json_error($code, 'The field "' . $key . '" must be a positive whole number.', 400);
    }

    return $value;
}

/**
 * An optional factor for the size of an icon drawing. The range keeps a value
 * from making an icon invisible or many times too large.
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
 * An optional yes/no value.
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
 * An optional SVG icon. The file is sanitised before it is stored, and a file
 * that cannot be made safe is refused instead of being stored.
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

    $svg = svg_sanitize($body[$key]);

    if ($svg === null) {
        send_json_error($code, 'The icon is not a usable SVG file.', 400);
    }

    return $svg;
}

/**
 * The name a person typed into the delete dialog.
 *
 * Deleting a category removes everything below it, so the endpoint asks for the
 * name again. The comparison ignores upper and lower case and surrounding
 * spaces, but nothing else.
 *
 * @param array<string, mixed> $body
 */
function require_confirm_name(array $body): string
{
    if (!array_key_exists('confirm_name', $body) || !is_string($body['confirm_name'])) {
        send_json_error('invalid_request_body', 'Send a JSON object with a "confirm_name" field.', 400);
    }

    return trim($body['confirm_name']);
}

/**
 * The optional confirmation name of a delete request.
 *
 * A delete that really needs a confirmation sends the name; one that does not
 * (an empty category, or a client that never had the field) simply sends
 * nothing, and null comes back instead of an error.
 *
 * @param array<string, mixed> $body
 */
function optional_confirm_name(array $body): ?string
{
    if (!array_key_exists('confirm_name', $body) || !is_string($body['confirm_name'])) {
        return null;
    }

    $value = trim($body['confirm_name']);

    return $value === '' ? null : $value;
}

/**
 * A required id that arrives in the query string, such as ?id=7.
 *
 * Only digits are accepted, so an array (?id[]=1) or any other text is refused
 * before it can reach the database layer.
 */
function require_query_id(string $key, string $code = 'invalid_id'): int
{
    $raw = $_GET[$key] ?? null;

    if (!is_string($raw) || !ctype_digit($raw) || (int) $raw < 1) {
        send_json_error($code, 'The ' . $key . ' parameter must be a positive whole number.', 400);
    }

    return (int) $raw;
}
