<?php

declare(strict_types=1);

/**
 * The kinds of task themselves: Energie-Kennzahlen, Einheiten, Statistik.
 *
 * One function per kind of task, built the same way as in exercise_tasks.php:
 * numbers are drawn and the answer is computed in the same step, every answer is
 * either exact or clearly marked as rounded, and the parameters can only contain
 * what exercise_catalog() allows.
 */

/**
 * A value converted from one unit into another of the same family.
 *
 * The families are fixed lists in exercise_unit_families(), and every step inside
 * a family is a power of ten. The value is therefore chosen as a multiple of the
 * step, which makes the answer a whole number in every direction - no task ever
 * ends in 0,0000000001 kW.
 *
 * About cubic metres: the family holds m³ and the litre, which is its exact
 * thousandth. A conversion between m³ and Nm³ is not in the list on purpose - it
 * depends on pressure and temperature, so it would need a basis that this
 * application does not have, and inventing one would make the task wrong.
 */
function exercise_task_unit_conversion(array $params): array
{
    $families = exercise_unit_families();
    $familyKey = (string) exercise_pick($params['families']);
    $units = $families[$familyKey] ?? $families['v'];
    $symbols = array_keys($units);

    $from = (string) exercise_pick($symbols);
    $others = array_values(array_filter($symbols, static fn (string $symbol): bool => $symbol !== $from));
    $to = (string) exercise_pick($others);

    $fromFactor = (int) $units[$from];
    $toFactor = (int) $units[$to];
    $low = max(1, (int) $params['min']);
    $high = max($low, (int) $params['max']);

    if ($fromFactor > $toFactor) {
        /* Into a smaller unit: multiplying always gives a whole number. */
        $value = exercise_draw_multiple($low, $high, intdiv($fromFactor, $toFactor));
        $result = $value * intdiv($fromFactor, $toFactor);
    } else {
        /* Into a bigger unit: the value has to be a multiple of the step. */
        $ratio = intdiv($toFactor, $fromFactor);
        $value = exercise_draw_multiple($low, $high, $ratio);
        $result = intdiv($value, $ratio);
    }

    return [
        'question' => exercise_same(exercise_decimal((float) $value, 0, 'de') . ' ' . $from . ' = ? ' . $to),
        'answer' => exercise_same(exercise_decimal((float) $result, 0, 'de') . ' ' . $to),
    ];
}

/**
 * One of the two energy formulas with one letter missing.
 *
 *   P = U · I    voltage and current are given, the power is asked for
 *   E = P · t    power and time are given, the energy is asked for
 *
 * All three values are whole numbers of their unit, so the answer is exact. The
 * letter that is missing is drawn, and the numbers are the sizes they have in
 * reality: volts in the hundreds, amperes in single digits, hours within a day.
 */
function exercise_task_energy_formula(array $params): array
{
    $high = max(1, (int) $params['max']);

    if (exercise_pick($params['formulas']) === 'power') {
        $voltage = exercise_draw(12, max(12, min($high, 400)));
        $current = exercise_draw(1, 16);
        $power = $voltage * $current;

        switch (exercise_pick(['P', 'U', 'I'])) {
            case 'U':
                return [
                    'question' => exercise_same('P = U · I   P = ' . $power . ' W,   I = ' . $current . ' A   →   U = ?'),
                    'answer' => exercise_same($voltage . ' V'),
                ];

            case 'I':
                return [
                    'question' => exercise_same('P = U · I   P = ' . $power . ' W,   U = ' . $voltage . ' V   →   I = ?'),
                    'answer' => exercise_same($current . ' A'),
                ];

            default:
                return [
                    'question' => exercise_same('P = U · I   U = ' . $voltage . ' V,   I = ' . $current . ' A   →   P = ?'),
                    'answer' => exercise_same($power . ' W'),
                ];
        }
    }

    $power = exercise_draw(1, max(1, min($high, 500)));
    $hours = exercise_draw(1, 24);
    $energy = $power * $hours;

    switch (exercise_pick(['E', 'P', 't'])) {
        case 'P':
            return [
                'question' => exercise_same('E = P · t   E = ' . $energy . ' kWh,   t = ' . $hours . ' h   →   P = ?'),
                'answer' => exercise_same($power . ' kW'),
            ];

        case 't':
            return [
                'question' => exercise_same('E = P · t   E = ' . $energy . ' kWh,   P = ' . $power . ' kW   →   t = ?'),
                'answer' => exercise_same($hours . ' h'),
            ];

        default:
            return [
                'question' => exercise_same('E = P · t   P = ' . $power . ' kW,   t = ' . $hours . ' h   →   E = ?'),
                'answer' => exercise_same($energy . ' kWh'),
            ];
    }
}

/**
 * The efficiency: how much of what went in came out useful.
 *
 * A power plant keeps 35 to 45 % of its fuel, a device 80 to 95 %. One of those
 * two is drawn, and then one of the three values is asked for. The energy that
 * goes in is chosen as a multiple of the step that makes the useful part whole,
 * so both answers are exact.
 */
function exercise_task_efficiency(array $params): array
{
    $rate = random_int(0, 1) === 0
        ? exercise_pick([35, 38, 40, 42, 45])
        : exercise_pick([80, 85, 88, 90, 92, 95]);

    $step = intdiv(100, exercise_greatest_common_divisor($rate, 100));
    $input = exercise_draw_multiple(
        max(100, (int) $params['min']),
        max(200, (int) $params['max']),
        $step
    );
    $useful = intdiv($input * $rate, 100);

    switch ($params['ask']) {
        case 'useful':
            return [
                'question' => exercise_sentence('exercise.task.efficiency.useful', [
                    'input' => $input,
                    'rate' => $rate,
                ]),
                'answer' => exercise_same($useful . ' kWh'),
            ];

        case 'input':
            return [
                'question' => exercise_sentence('exercise.task.efficiency.input', [
                    'useful' => $useful,
                    'rate' => $rate,
                ]),
                'answer' => exercise_same($input . ' kWh'),
            ];

        default:
            return [
                'question' => exercise_sentence('exercise.task.efficiency.value', [
                    'input' => $input,
                    'useful' => $useful,
                ]),
                'answer' => exercise_same($rate . ' %'),
            ];
    }
}

/**
 * The utilisation: how much of what a plant could produce it really produced.
 *
 * Both the maximum and the percentage are drawn, the actual production is
 * computed from them - so whichever of the three is asked for, the answer is a
 * whole number.
 */
function exercise_task_utilisation(array $params): array
{
    $rate = exercise_pick([8, 12, 15, 20, 25, 30, 40, 50, 60, 75, 80, 90]);
    $step = intdiv(100, exercise_greatest_common_divisor($rate, 100));
    $maximum = exercise_draw_multiple(
        max(10, (int) $params['min']),
        max(20, (int) $params['max']),
        $step
    );
    $actual = intdiv($maximum * $rate, 100);

    switch ($params['ask']) {
        case 'actual':
            return [
                'question' => exercise_sentence('exercise.task.utilisation.actual', [
                    'maximum' => $maximum,
                    'rate' => $rate,
                ]),
                'answer' => exercise_same($actual . ' MW'),
            ];

        case 'maximum':
            return [
                'question' => exercise_sentence('exercise.task.utilisation.maximum', [
                    'actual' => $actual,
                    'rate' => $rate,
                ]),
                'answer' => exercise_same($maximum . ' MW'),
            ];

        default:
            return [
                'question' => exercise_sentence('exercise.task.utilisation.value', [
                    'actual' => $actual,
                    'maximum' => $maximum,
                ]),
                'answer' => exercise_same($rate . ' %'),
            ];
    }
}

/**
 * Full load hours and the capacity factor.
 *
 * The annual yield divided by the rated power gives the full load hours: a plant
 * of 5 MW that produced 8 000 MWh ran, as it were, 1 600 hours at full power. The
 * ratio of that to the 8 760 hours of a year is the capacity factor, which is a
 * percentage and usually a small one - that is why it is shown with one decimal
 * and the ≈ sign.
 */
function exercise_task_full_load_hours(array $params): array
{
    $power = exercise_draw(1, max(1, min(500, (int) $params['max'])));
    $hours = exercise_draw(500, max(600, min(5000, (int) $params['max'] * 50)));
    $energy = $power * $hours;

    if ($params['ask'] === 'capacity_factor') {
        $factor = $hours / 8760 * 100;

        return [
            'question' => exercise_sentence('exercise.task.full_load_hours.factor', [
                'energy' => $energy,
                'power' => $power,
            ]),
            'answer' => exercise_rounded($factor, 1),
        ];
    }

    return [
        'question' => exercise_sentence('exercise.task.full_load_hours.hours', [
            'energy' => $energy,
            'power' => $power,
        ]),
        'answer' => exercise_same($hours . ' h'),
    ];
}

/**
 * A row of quarter-hour values: the energy they add up to, or their average.
 *
 * A quarter of an hour is a quarter of a kWh per kW, so the energy is the sum of
 * the values divided by four, and the power values are chosen as multiples of four
 * to keep that whole. The average is built by drawing the values around a chosen
 * mean, so it is a whole number as well.
 *
 * The first answer is in kWh, the second one in kW - which is why the kind of task
 * carries which of the two is asked for.
 */
function exercise_task_quarter_hours(array $params): array
{
    $count = max(4, min(8, (int) $params['count']));
    $low = max(1, (int) $params['min']);
    $high = max($low + 4, (int) $params['max']);

    if ($params['ask'] === 'average') {
        $values = [];
        $mean = null;

        for ($attempt = 0; $attempt < 40; $attempt++) {
            $values = [];

            for ($index = 0; $index < $count - 1; $index++) {
                $values[] = exercise_draw($low, $high);
            }

            $wanted = exercise_draw($low, $high);
            $last = $count * $wanted - array_sum($values);

            if ($last >= $low && $last <= $high) {
                $values[] = $last;
                $mean = $wanted;
                break;
            }
        }

        if ($mean === null) {
            /* No whole mean found in this narrow range: take the real one. */
            $values = exercise_draw_series($count, $low, $high);
            $mean = array_sum($values) / $count;

            return [
                'question' => exercise_sentence('exercise.task.quarter_hours.average', [
                    'values' => exercise_series_text($values),
                ]),
                'answer' => exercise_rounded((float) $mean, EXERCISE_DECIMALS),
            ];
        }

        return [
            'question' => exercise_sentence('exercise.task.quarter_hours.average', [
                'values' => exercise_series_text($values),
            ]),
            'answer' => exercise_same(exercise_decimal((float) $mean, 0, 'de') . ' kW'),
        ];
    }

    $values = [];

    for ($index = 0; $index < $count; $index++) {
        $values[] = exercise_draw_multiple($low, $high, 4);
    }

    $energy = array_sum($values) / 4;

    return [
        'question' => exercise_sentence('exercise.task.quarter_hours.energy', [
            'values' => exercise_series_text($values),
        ]),
        'answer' => exercise_same(exercise_decimal($energy, 0, 'de') . ' kWh'),
    ];
}

/**
 * One of four figures about a short list of numbers: median, smallest, largest or
 * the span between the two.
 *
 * The list is drawn and shown as it was drawn - sorting it is part of the task for
 * whoever reads it. The median is the middle value of the sorted list (the list
 * always has an odd number of values, so there is exactly one middle).
 */
function exercise_task_statistics_spread(array $params): array
{
    $count = max(5, min(9, (int) $params['count']));
    $values = exercise_draw_series($count, (int) $params['min'], (int) $params['max']);
    $sorted = $values;
    sort($sorted);

    $smallest = $sorted[0];
    $largest = $sorted[$count - 1];
    $figures = [
        'median' => $sorted[intdiv($count, 2)],
        'minimum' => $smallest,
        'maximum' => $largest,
        'range' => $largest - $smallest,
    ];

    $ask = (string) $params['ask'];

    return [
        'question' => exercise_sentence('exercise.task.statistics_spread.' . $ask, [
            'values' => exercise_series_text($values),
        ]),
        'answer' => exercise_same((string) ($figures[$ask] ?? $figures['median'])),
    ];
}

/**
 * The mean of three to five numbers.
 *
 * The list is built around a whole mean: the last value is computed from the
 * others, so the answer is a whole number. If the range is too narrow for that,
 * the true mean is used and rounded with the ≈ sign.
 */
function exercise_task_mean_value(array $params): array
{
    $count = max(3, min(5, (int) $params['count']));
    $low = max(1, (int) $params['min']);
    $high = max($low, (int) $params['max']);

    for ($attempt = 0; $attempt < 40; $attempt++) {
        $values = [];

        for ($index = 0; $index < $count - 1; $index++) {
            $values[] = exercise_draw($low, $high);
        }

        $wanted = exercise_draw($low, $high);
        $last = $count * $wanted - array_sum($values);

        if ($last >= $low && $last <= $high) {
            $values[] = $last;

            return [
                'question' => exercise_sentence('exercise.task.mean_value', [
                    'values' => exercise_series_text($values),
                ]),
                'answer' => exercise_same((string) $wanted),
            ];
        }
    }

    $values = exercise_draw_series($count, $low, $high);

    return [
        'question' => exercise_sentence('exercise.task.mean_value', [
            'values' => exercise_series_text($values),
        ]),
        'answer' => exercise_rounded(array_sum($values) / $count, EXERCISE_DECIMALS),
    ];
}

/**
 * The standard deviation of five to eight numbers.
 *
 * The usual formula, with the arithmetic mean as the point of reference: the mean
 * of the squared distances, and the square root of that. It is the deviation of
 * the whole list (divided by how many values there are), which is the one taught
 * with this formula. The answer is rounded to two decimals and written with the ≈
 * sign, unless it happens to be a whole number.
 */
function exercise_task_standard_deviation(array $params): array
{
    $count = max(5, min(8, (int) $params['count']));
    $values = exercise_draw_series($count, (int) $params['min'], (int) $params['max']);
    $mean = array_sum($values) / $count;
    $squares = 0.0;

    foreach ($values as $value) {
        $squares += ($value - $mean) ** 2;
    }

    $deviation = sqrt($squares / $count);
    $rounded = round($deviation, EXERCISE_DECIMALS);

    return [
        'question' => exercise_sentence('exercise.task.standard_deviation', [
            'values' => exercise_series_text($values),
            'decimals' => EXERCISE_DECIMALS,
        ]),
        'answer' => abs($deviation - round($deviation)) < 0.0000001
            ? exercise_same(exercise_decimal($deviation, 0, 'de'))
            : exercise_rounded($deviation, EXERCISE_DECIMALS),
    ];
}

/**
 * A small table of values, and one question about it.
 *
 * Four to six values with letters as their names, drawn from the range. Asked for
 * is the largest value, the difference between two of them, or the share one of
 * them has of the total. For the share the total is a multiple of the step that
 * makes the percentage whole, so that answer is exact as well.
 */
function exercise_task_data_table(array $params): array
{
    $count = max(4, min(6, (int) $params['count']));
    $low = max(1, (int) $params['min']);
    $high = max($low, (int) $params['max']);
    $names = [];
    $values = [];

    for ($index = 0; $index < $count; $index++) {
        $names[] = chr(65 + $index);
        $values[] = exercise_draw($low, $high);
    }

    $rows = [];

    foreach ($names as $index => $name) {
        $rows[] = $name . ': ' . $values[$index];
    }

    $table = implode(',  ', $rows);

    if ($params['ask'] === 'difference') {
        $first = exercise_draw(0, $count - 2);
        $second = exercise_draw($first + 1, $count - 1);

        return [
            'question' => exercise_sentence('exercise.task.data_table.difference', [
                'table' => $table,
                'first' => $names[$first],
                'second' => $names[$second],
            ]),
            'answer' => exercise_same((string) abs($values[$first] - $values[$second])),
        ];
    }

    if ($params['ask'] === 'share') {
        /*
         * One of the values is made a whole percentage of the whole table on
         * purpose, so the answer is exact. The value is drawn first and the total
         * follows from it; the others then have to fit between the smallest and
         * the largest value, which is what the attempt is for. When the range is
         * too narrow for that, the question about the largest value is asked
         * instead - a task is always shown.
         */
        for ($attempt = 0; $attempt < 30; $attempt++) {
            $rate = exercise_pick([5, 10, 20, 25, 50]);
            $chosen = exercise_draw($low, $high);
            $total = intdiv($chosen * 100, $rate / 1);
            $rest = $total - $chosen;
            $others = [];

            for ($index = 1; $index < $count; $index++) {
                $others[] = intdiv($rest, $count - 1);
            }

            $others[$count - 2] += $rest - array_sum($others);
            $usable = true;

            foreach ($others as $single) {
                if ($single < $low || $single > $high) {
                    $usable = false;
                }
            }

            if (!$usable) {
                continue;
            }

            $values = array_merge([$chosen], $others);
            $rows = [];

            foreach ($names as $index => $name) {
                $rows[] = $name . ': ' . $values[$index];
            }

            return [
                'question' => exercise_sentence('exercise.task.data_table.share', [
                    'table' => implode(',  ', $rows),
                    'name' => $names[0],
                ]),
                'answer' => exercise_same($rate . ' %'),
            ];
        }
    }

    $largest = max($values);
    $position = array_search($largest, $values, true);

    return [
        'question' => exercise_sentence('exercise.task.data_table.largest', ['table' => $table]),
        'answer' => exercise_same($names[$position] . ' = ' . $largest),
    ];
}
