<?php

declare(strict_types=1);

/**
 * POST /api/exercise_preview.php
 *
 * Baut EIN Beispiel einer erzeugten Aufgabe, aus der Art der Aufgabe und den
 * Zahlen, die der Kartendialog gerade hält - damit das Formular zeigen kann, was
 * es gleich speichert.
 *
 * Warum der Browser das Beispiel nicht selbst würfelt: die Zahlen werden an
 * genau einer Stelle gezogen, in src/services/exercise_service.php
 * (exercise_build_task()). Ein zweiter Erzeuger im Browser wäre eine zweite
 * Wahrheit, die man im Gleichschritt halten müsste, und das Beispiel könnte dann
 * von der Aufgabe abweichen, die die Karte wirklich zeigt. Dieser Endpunkt
 * benutzt dieselbe Funktion wie das Lesen der Karten, deshalb können das Beispiel
 * im Dialog, die Aufgabe in der Kartenliste und die Aufgabe in der Lernansicht
 * nur aus derselben Quelle kommen.
 *
 * Was er NICHT tut
 *   * er schreibt nichts: kein INSERT, kein UPDATE, kein DELETE, keine
 *     temporäre Tabelle
 *   * er berührt überhaupt keine Tabelle - für eine Aufgabe wird die Datenbank
 *     nicht gebraucht, deshalb öffnet dieser Endpunkt nicht einmal eine
 *     Verbindung
 *   * er merkt sich nichts über die Anfrage: die Zahlen werden gebaut und
 *     vergessen
 *
 * Dass er ohne Anmeldung erreichbar ist, ist aus demselben Grund Absicht und
 * harmlos: er braucht keinen Datenbankzugriff und gibt nichts zurück, was nicht
 * ohnehin auf dem Bildschirm dessen steht, der die Karte bearbeitet.
 */

require_once __DIR__ . '/../../src/helpers/json_response.php';
require_once __DIR__ . '/../../src/helpers/request_input.php';
require_once __DIR__ . '/../../src/services/exercise_service.php';

// Dieser Endpunkt beantwortet nur POST-Anfragen. send_json_error() beendet die
// Anfrage, deshalb wird der Code darunter bei einer falschen Methode gar nicht
// erreicht.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    send_json_error('method_not_allowed', 'Only POST requests are allowed.', 405);
}

try {
    $body = read_json_object();

    /*
     * Die Listenform: die Kartenliste braucht einen frischen Satz Zahlen pro
     * Übungskarte, die sie gleich zeigt. Eine Anfrage statt einer pro Karte,
     * gebaut von derselben Funktion - so kann eine Liste nie eine Aufgabe zeigen,
     * die die Karte selbst nicht bauen würde. Eine Karte, deren Aufgabenart sich
     * nicht bauen lässt, antwortet mit null, statt die ganze Anfrage scheitern zu
     * lassen.
     */
    $items = $body['items'] ?? null;

    if (is_array($items) && array_is_list($items)) {
        $tasks = [];

        foreach (array_slice($items, 0, 50) as $item) {
            $one = is_array($item) ? $item : [];
            $oneType = isset($one['exercise_type']) && is_string($one['exercise_type']) ? trim($one['exercise_type']) : '';
            $oneParams = isset($one['exercise_params']) && is_array($one['exercise_params']) && !array_is_list($one['exercise_params'])
                ? $one['exercise_params']
                : [];

            if ($oneType === '' || !exercise_type_is_known($oneType)) {
                $tasks[] = null;

                continue;
            }

            $tasks[] = exercise_build_task($oneType, exercise_normalise_params($oneType, $oneParams));
        }

        send_json_success(['tasks' => $tasks]);
    }

    $type = $body['exercise_type'] ?? null;
    if (!is_string($type) || trim($type) === '') {
        send_json_error('invalid_exercise_type', 'The field "exercise_type" must name a kind of task.', 400);
    }

    $type = trim($type);

    if (!exercise_type_is_known($type)) {
        send_json_error('invalid_exercise_type', 'This kind of task does not exist.', 400);
    }

    /*
     * Die Zahlen werden hier nicht abgelehnt, sondern in ihre Grenzen gezogen:
     * während jemand einen Bereich tippt, soll das Beispiel folgen statt sich zu
     * beschweren. Ein fehlender oder unbrauchbarer Wert fällt auf den Standardwert
     * seines Feldes zurück - dieselbe Regel gilt beim Lesen einer gespeicherten
     * Karte.
     */
    $raw = $body['exercise_params'] ?? [];

    if (!is_array($raw) || array_is_list($raw)) {
        $raw = [];
    }

    $params = exercise_normalise_params($type, $raw);
    $task = exercise_build_task($type, $params);

    if ($task === null) {
        send_json_error('exercise_unavailable', 'This kind of task cannot be built.', 400);
    }

    send_json_success([
        'type' => $type,
        'label' => exercise_type_label($type),
        'params' => $params,
        'task' => $task,
    ]);
} catch (Throwable $error) {
    // Der echte Grund geht nur ins Server-Protokoll; er könnte eine Datei oder
    // eine Codezeile nennen, was draußen niemand braucht.
    error_log('Exercise preview failed: ' . $error->getMessage());

    send_json_error('exercise_preview_failed', 'The example could not be built.', 500);
}
