<?php

declare(strict_types=1);

/**
 * Bausteine fuer einheitliche JSON-Antworten der API-Endpunkte.
 *
 * Jeder Endpunkt benutzt denselben Umschlag, damit das Frontend immer weiss, wo
 * die Nutzdaten stehen und wo ein Fehler:
 *   Erfolg: {"success": true,  "data": ...}
 *   Fehler: {"success": false, "error": {"code": "...", "message": "..."}}
 */

/**
 * Schickt eine JSON-Antwort mit dem uebergebenen HTTP-Code und beendet die
 * Anfrage.
 *
 * Das Beenden ist Absicht: nach dem JSON darf nichts mehr ausgegeben werden,
 * sonst ist die Antwort kein gueltiges JSON mehr.
 *
 * @param array<string, mixed> $payload
 */
function send_json(int $statusCode, array $payload): void
{
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE);

    // json_encode scheitert bei ungueltigem UTF-8. Statt einer leeren Antwort
    // geht dann ein sicherer Fehlerkoerper hinaus, damit der Client nie ein
    // kaputtes JSON lesen muss.
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

    // headers_sent() faengt die Warnung "headers already sent" ab, falls eine
    // Datei vor diesem Aufruf versehentlich etwas ausgegeben hat.
    if (!headers_sent()) {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
    }

    echo $json;
    exit;
}

/**
 * Schickt eine Erfolgsantwort.
 *
 * @param mixed $data Nutzdaten, die der Client bekommen soll.
 */
function send_json_success($data = null, int $statusCode = 200): void
{
    send_json($statusCode, [
        'success' => true,
        'data' => $data,
    ]);
}

/**
 * Schickt eine Fehlerantwort.
 *
 * $message darf nur Text enthalten, der dem Nutzer gezeigt werden darf. Keine
 * Ausnahmetexte, kein SQL, keine Zugangsdaten und keine Dateipfade hineingeben.
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
