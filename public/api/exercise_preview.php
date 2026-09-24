<?php

declare(strict_types=1);

/**
 * POST /api/exercise_preview.php
 *
 * Builds ONE example of a generated task, from the kind of task and the numbers
 * the card dialog is holding right now, so the form can show what it is about to
 * save.
 *
 * Why the browser does not roll the example itself: the numbers are drawn in
 * exactly one place, src/services/exercise_service.php (exercise_build_task()).
 * A second generator in the browser would be a second truth to keep in step, and
 * the example could then differ from the task the card really shows. This
 * endpoint uses the same function the card reads use, so the example in the
 * dialog, the task in the card list and the task in the learning view can only
 * ever come from the same place.
 *
 * What it does NOT do
 *   * it writes nothing: no INSERT, no UPDATE, no DELETE, no temporary table
 *   * it touches no table at all - the database is not needed for a task, so this
 *     endpoint does not even open a connection
 *   * it stores nothing about the request: the numbers are built and forgotten
 *
 * Being reachable without being signed in is deliberate and harmless for the same
 * reason: it needs no database access and returns nothing that is not already on
 * the screen of whoever is editing the card.
 */

require_once __DIR__ . '/../../src/helpers/json_response.php';
require_once __DIR__ . '/../../src/helpers/request_input.php';
require_once __DIR__ . '/../../src/services/exercise_service.php';

// This endpoint only answers POST requests. send_json_error() ends the request,
// so the code below is not reached when the method is wrong.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    send_json_error('method_not_allowed', 'Only POST requests are allowed.', 405);
}

try {
    $body = read_json_object();

    /*
     * Batch form: the card list needs one fresh set of numbers per exercise card
     * it is about to show. One request instead of one per card, built by the very
     * same function - so a list can never show a task the card itself would not
     * build. A card whose kind of task cannot be built answers with null instead
     * of failing the whole request.
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
     * The numbers are not refused here, they are pulled into their limits: while
     * somebody is typing a range, the example should follow instead of
     * complaining. A missing or unusable value falls back to the default of its
     * field, which is the same rule a stored card follows when it is read.
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
    // The real reason goes to the server log only; it could name a file or a
    // line of code, which nobody outside needs.
    error_log('Exercise preview failed: ' . $error->getMessage());

    send_json_error('exercise_preview_failed', 'The example could not be built.', 500);
}
