<?php

declare(strict_types=1);

/**
 * POST   /api/card_image.php?card_id=4&side=front
 * DELETE /api/card_image.php?card_id=4&side=front
 *
 * POST nimmt ein Foto im Feld "image" (multipart/form-data) an und ersetzt das
 * bisherige Foto dieser Seite. DELETE nimmt es wieder weg.
 *
 * Die Antwort ist in beiden Fällen die aktualisierte Karte im selben Aufbau wie
 * api/card.php, damit der Browser nichts zusätzlich zusammenbauen muss.
 *
 * Die Datei landet in public/uploads/cards. Gespeichert wird nur ein zufälliger
 * Name; der Dateiname aus dem Browser wird nie übernommen.
 */

require_once __DIR__ . '/../../src/config/database.php';
require_once __DIR__ . '/../../src/helpers/json_response.php';
require_once __DIR__ . '/../../src/helpers/request_input.php';
require_once __DIR__ . '/../../src/helpers/session_user.php';
require_once __DIR__ . '/../../src/services/card_image_service.php';
require_once __DIR__ . '/../../src/services/user_service.php';

$method = $_SERVER['REQUEST_METHOD'] ?? '';

if ($method !== 'POST' && $method !== 'DELETE') {
    send_json_error('method_not_allowed', 'Only POST and DELETE requests are allowed.', 405);
}

$cardId = require_query_id('card_id', 'invalid_card_id');
$side = is_string($_GET['side'] ?? null) ? (string) $_GET['side'] : '';

if (card_image_column_for_side($side) === null) {
    send_json_error('invalid_image_side', 'The field "side" must be "front" or "back".', 400);
}

try {
    $pdo = create_database_connection();
    $userId = current_user_id($pdo);

    /* Ein Foto gehört zu einer Karte, und eine Karte gehört einem Konto. */
    if ($userId === null) {
        $required = session_user_required_error();

        send_json_error($required['code'], $required['message'], $required['status']);
    }

    /*
     * Der CSRF-Token dieser Sitzung. Beim Hochladen kommt er mit dem Formular,
     * beim Entfernen als eigener Kopf - ein Token in der Adresse landet sonst in
     * Protokollen und im Verlauf.
     */
    $csrfToken = $method === 'POST'
        ? (is_string($_POST['csrf_token'] ?? null) ? (string) $_POST['csrf_token'] : '')
        : (is_string($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null) ? (string) $_SERVER['HTTP_X_CSRF_TOKEN'] : '');

    if (user_csrf_valid($csrfToken) !== true) {
        send_json_error('invalid_token', 'The security token is missing or outdated.', 403);
    }

    if ($method === 'POST') {
        $file = $_FILES['image'] ?? [];
        $result = card_image_store($pdo, is_array($file) ? $file : [], $cardId, $side, $userId);
    } else {
        $result = card_image_remove($pdo, $cardId, $side, $userId);
    }

    if ($result['error'] !== null) {
        $errors = [
            'card_images_unavailable' => [
                'The card photo columns are missing. Run database/card_images.sql first.',
                400,
            ],
            'invalid_image_side' => ['The field "side" must be "front" or "back".', 400],
            'card_not_found' => ['This card does not exist.', 404],
            'image_missing' => ['No image was received.', 400],
            'image_empty' => ['The image is empty.', 400],
            'image_too_large' => ['The image is larger than 2 MB.', 400],
            'image_too_small' => ['The image is too small.', 400],
            'image_dimensions' => ['The image is larger than 4500 pixels on a side.', 400],
            'image_type' => ['Only JPG, PNG and WebP images are accepted.', 400],
            'image_invalid' => ['The file could not be read as an image.', 400],
            'image_failed' => ['The image could not be saved.', 500],
        ];

        $error = $errors[$result['error']] ?? ['The image could not be saved.', 500];

        send_json_error($result['error'], $error[0], $error[1]);
    }

    send_json_success($result['card']);
} catch (Throwable $error) {
    /* Einzelheiten nur ins Server-Protokoll, nie in die Antwort. */
    error_log('Saving a card image failed: ' . $error->getMessage());

    send_json_error('image_failed', 'The image could not be saved.', 500);
}
