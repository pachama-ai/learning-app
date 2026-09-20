<?php

declare(strict_types=1);

/**
 * Helpers for sending consistent JSON responses from API endpoints.
 *
 * Every endpoint uses the same envelope, so the frontend always knows where to
 * look for the payload and where to look for an error:
 *   success: {"success": true,  "data": ...}
 *   failure: {"success": false, "error": {"code": "...", "message": "..."}}
 */

/**
 * Sends a JSON response with the given HTTP status code and ends the request.
 *
 * Ends the request on purpose: an API endpoint must never print anything after
 * its JSON body, because that would make the response invalid JSON.
 *
 * @param array<string, mixed> $payload
 */
function send_json(int $statusCode, array $payload): void
{
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE);

    // json_encode fails on invalid UTF-8. Send a safe error body instead of an
    // empty response, so the client never has to parse a broken payload.
    if ($json === false) {
        $statusCode = 500;
        $json = json_encode([
            'success' => false,
            'error' => [
                'code' => 'json_encoding_failed',
                'message' => 'The response could not be encoded.',
            ],
        ]);
    }

    // headers_sent() guards against a "headers already sent" warning if some
    // file accidentally output something before this function was called.
    if (!headers_sent()) {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
    }

    echo $json;
    exit;
}

/**
 * Sends a successful response.
 *
 * @param mixed $data Payload the client should receive.
 */
function send_json_success($data = null, int $statusCode = 200): void
{
    send_json($statusCode, [
        'success' => true,
        'data' => $data,
    ]);
}

/**
 * Sends an error response.
 *
 * Only use $message for text that is safe to show to the user. Never pass
 * exception messages, SQL, credentials or file paths into it.
 */
function send_json_error(string $code, string $message, int $statusCode = 400): void
{
    send_json($statusCode, [
        'success' => false,
        'error' => [
            'code' => $code,
            'message' => $message,
        ],
    ]);
}
