<?php

declare(strict_types=1);

/**
 * Generated exercises: which kinds of task this application can build, what each
 * of them may be given, and how a task is put together.
 *
 * A fixed card shows text somebody wrote. A generated card shows a task that is
 * built at the moment the card is displayed, with numbers that are drawn again on
 * every display. The database stores only WHICH kind of task a card is and the
 * numbers it may use (the table `card_exercises`: `exercise_type` and
 * `exercise_params` as JSON). Everything else happens here and in
 * exercise_tasks.php.
 *
 * There is no formula anywhere: not in the database, not in a request, not in
 * this file as text that is worked out later. The keys in `exercise_params` are
 * looked up in exercise_catalog() below; a key that is not in that list is
 * refused, and a value outside its limits is pulled into them. Every kind of task
 * is one function in exercise_tasks.php that draws its numbers and computes its
 * answer in the same step. A new kind of task therefore only comes into being by
 * writing new code - never by data.
 *
 * The catalogue is also the single source of truth for the interface:
 * public/index.php hands it to the browser as config.exerciseTypes, so the card
 * dialog offers exactly the kinds of task that exist here, with exactly the
 * fields each of them needs.
 */

require_once __DIR__ . '/exercise_tasks.php';
require_once __DIR__ . '/exercise_tasks_energy.php';
require_once __DIR__ . '/../helpers/translations.php';

/** The denominators a fraction task may use. */
const EXERCISE_FRACTION_DENOMINATORS = [2, 3, 4, 5, 6, 8, 10, 12];

/** The percentages a percentage task may use: familiar ones only. */
const EXERCISE_PERCENTAGES = [5, 10, 15, 20, 25, 40, 50, 60, 75];

/** How many decimals a rounded answer gets unless its kind says otherwise. */
const EXERCISE_DECIMALS = 2;

/**
 * Every kind of task, with everything it may be given.
 *
 *   label   translation key of the name of the kind of task
 *   hint    translation key of the sentence that explains it in the dialog
 *   params  what a card of this kind may be told, as a small schema that the
 *           server and the card dialog both read:
 *
 *             kind     'int'    a whole number between lowest and highest
 *                      'select' exactly one of options
 *                      'multi'  any number of options
 *                      'flag'   yes or no
 *             default  what a new card gets
 *             lowest   the limits of an 'int'
 *             highest
 *             options  the allowed values of 'select' and 'multi'
 *
 * A parameter is shown under exercise.param.<name> and an option under
 * exercise.option.<value>, so no label has to be repeated per kind of task.
 *
 * @return array<string, array<string, mixed>>
 */
function exercise_catalog(): array
{
    $numbers = static function (int $min, int $max, int $lowest, int $highest): array {
        return [
            'min' => ['kind' => 'int', 'default' => $min, 'lowest' => $lowest, 'highest' => $highest],
            'max' => ['kind' => 'int', 'default' => $max, 'lowest' => $lowest, 'highest' => $highest],
        ];
    };

    $choice = static function (array $options, $default, string $kind = 'select'): array {
        return ['kind' => $kind, 'default' => $default, 'options' => $options];
    };

    return [
        'times_table' => [
            'label' => 'exercise.type.times_table',
            'hint' => 'exercise.hint.times_table',
            'params' => $numbers(2, 20, 1, 1000),
        ],
        'division_inverse' => [
            'label' => 'exercise.type.division_inverse',
            'hint' => 'exercise.hint.division_inverse',
            'params' => $numbers(2, 20, 2, 1000) + [
                'ask' => $choice(['result', 'divisor'], 'result'),
                'remainder' => ['kind' => 'flag', 'default' => false],
            ],
        ],
        'fraction' => [
            'label' => 'exercise.type.fraction',
            'hint' => 'exercise.hint.fraction',
            'params' => $numbers(1, 9, 1, 99) + [
                'operations' => $choice(['add', 'subtract', 'multiply', 'divide'], 'add', 'multi'),
            ],
        ],
        'negative_parens' => [
            'label' => 'exercise.type.negative_parens',
            'hint' => 'exercise.hint.negative_parens',
            'params' => $numbers(1, 12, 1, 50) + [
                'patterns' => $choice(
                    ['plus_minus', 'minus_minus', 'negative_product', 'negative_factor'],
                    'plus_minus',
                    'multi'
                ),
            ],
        ],
        'powers_scientific' => [
            'label' => 'exercise.type.powers_scientific',
            'hint' => 'exercise.hint.powers_scientific',
            'params' => $numbers(1, 9, 1, 9) + [
                'variants' => $choice(['power_of_ten', 'to_scientific', 'from_scientific'], 'power_of_ten', 'multi'),
            ],
        ],
        'linear_equation' => [
            'label' => 'exercise.type.linear_equation',
            'hint' => 'exercise.hint.linear_equation',
            'params' => $numbers(1, 12, 1, 100) + [
                'variants' => $choice(['equation', 'formula'], 'equation', 'multi'),
            ],
        ],
        'pythagoras' => [
            'label' => 'exercise.type.pythagoras',
            'hint' => 'exercise.hint.pythagoras',
            'params' => $numbers(1, 20, 1, 200) + [
                'variants' => $choice(['hypotenuse', 'leg'], 'hypotenuse', 'multi'),
            ],
        ],
        'percent' => [
            'label' => 'exercise.type.percent',
            'hint' => 'exercise.hint.percent',
            'params' => $numbers(10, 1000, 1, 100000) + [
                'ask' => $choice(['value', 'rate', 'base'], 'value'),
            ],
        ],
        'percent_energy' => [
            'label' => 'exercise.type.percent_energy',
            'hint' => 'exercise.hint.percent_energy',
            'params' => [
                'variants' => $choice(
                    ['mix', 'pv_ratio', 'self_consumption', 'storage_level', 'grid_losses', 'price_change'],
                    'mix',
                    'multi'
                ),
            ],
        ],
        'rule_of_three' => [
            'label' => 'exercise.type.rule_of_three',
            'hint' => 'exercise.hint.rule_of_three',
            'params' => $numbers(1, 20, 1, 500) + [
                'variants' => $choice(['quantity_cost', 'quantity_power'], 'quantity_cost', 'multi'),
            ],
        ],
        'unit_conversion' => [
            'label' => 'exercise.type.unit_conversion',
            'hint' => 'exercise.hint.unit_conversion',
            'params' => $numbers(1, 1000, 1, 100000) + [
                'families' => $choice(['v', 'w', 'wh', 'volume'], 'v', 'multi'),
            ],
        ],
        'energy_formula' => [
            'label' => 'exercise.type.energy_formula',
            'hint' => 'exercise.hint.energy_formula',
            'params' => $numbers(1, 1000, 1, 100000) + [
                'formulas' => $choice(['power', 'energy'], 'power', 'multi'),
            ],
        ],
        'efficiency' => [
            'label' => 'exercise.type.efficiency',
            'hint' => 'exercise.hint.efficiency',
            'params' => $numbers(100, 5000, 1, 100000) + [
                'ask' => $choice(['efficiency', 'useful', 'input'], 'efficiency'),
            ],
        ],
        'utilisation' => [
            'label' => 'exercise.type.utilisation',
            'hint' => 'exercise.hint.utilisation',
            'params' => $numbers(100, 5000, 1, 1000000) + [
                'ask' => $choice(['utilisation', 'actual', 'maximum'], 'utilisation'),
            ],
        ],
        'full_load_hours' => [
            'label' => 'exercise.type.full_load_hours',
            'hint' => 'exercise.hint.full_load_hours',
            'params' => $numbers(1, 100, 1, 2000) + [
                'ask' => $choice(['hours', 'capacity_factor'], 'hours'),
            ],
        ],
        'quarter_hours' => [
            'label' => 'exercise.type.quarter_hours',
            'hint' => 'exercise.hint.quarter_hours',
            'params' => $numbers(4, 60, 1, 10000) + [
                'count' => ['kind' => 'int', 'default' => 4, 'lowest' => 4, 'highest' => 8],
                'ask' => $choice(['energy', 'average'], 'energy'),
            ],
        ],
        'statistics_spread' => [
            'label' => 'exercise.type.statistics_spread',
            'hint' => 'exercise.hint.statistics_spread',
            'params' => $numbers(1, 50, 1, 10000) + [
                'count' => ['kind' => 'int', 'default' => 7, 'lowest' => 5, 'highest' => 9],
                'ask' => $choice(['median', 'minimum', 'maximum', 'range'], 'median'),
            ],
        ],
        'mean_value' => [
            'label' => 'exercise.type.mean_value',
            'hint' => 'exercise.hint.mean_value',
            'params' => $numbers(1, 50, 1, 10000) + [
                'count' => ['kind' => 'int', 'default' => 4, 'lowest' => 3, 'highest' => 5],
            ],
        ],
        'standard_deviation' => [
            'label' => 'exercise.type.standard_deviation',
            'hint' => 'exercise.hint.standard_deviation',
            'params' => $numbers(1, 20, 1, 1000) + [
                'count' => ['kind' => 'int', 'default' => 6, 'lowest' => 5, 'highest' => 8],
            ],
        ],
        'data_table' => [
            'label' => 'exercise.type.data_table',
            'hint' => 'exercise.hint.data_table',
            'params' => $numbers(5, 50, 1, 10000) + [
                'count' => ['kind' => 'int', 'default' => 5, 'lowest' => 4, 'highest' => 6],
                'ask' => $choice(['largest', 'difference', 'share'], 'largest'),
            ],
        ],
    ];
}

/**
 * The keys of every kind of task, in the order of the catalogue.
 *
 * @return list<string>
 */
function exercise_type_keys(): array
{
    return array_keys(exercise_catalog());
}

/** Whether this key is one of the kinds of task that live here. */
function exercise_type_is_known(string $type): bool
{
    return array_key_exists($type, exercise_catalog());
}

/** The translation key that names this kind of task, or null. */
function exercise_type_label(string $type): ?string
{
    $entry = exercise_catalog()[$type] ?? null;

    return $entry === null ? null : (string) $entry['label'];
}

/** The translation key that explains this kind of task, or null. */
function exercise_type_hint(string $type): ?string
{
    $entry = exercise_catalog()[$type] ?? null;

    return $entry === null ? null : (string) $entry['hint'];
}

/**
 * The parameters a card of this kind starts with in the dialog.
 *
 * @return array<string, mixed>
 */
function exercise_type_default_params(string $type): array
{
    $entry = exercise_catalog()[$type] ?? null;

    if ($entry === null) {
        return [];
    }

    $params = [];

    foreach ($entry['params'] as $name => $schema) {
        $params[$name] = $schema['default'];
    }

    return $params;
}

/**
 * The schema of one parameter, or null.
 *
 * @return array<string, mixed>|null
 */
function exercise_param_schema(string $type, string $name): ?array
{
    return exercise_catalog()[$type]['params'][$name] ?? null;
}

/**
 * Brings a stored or sent set of parameters into the shape this kind of task
 * expects: only known keys, every value inside its limits, every missing value
 * filled with its default.
 *
 * This is what makes a card that was edited by hand, or a kind of task whose
 * schema gained a parameter later, still show a sensible task instead of none. It
 * is also what stops anything unknown from travelling further: a key that is not
 * in the schema is dropped, not stored and not looked up anywhere.
 *
 * @param array<string, mixed> $params
 * @return array<string, mixed>
 */
function exercise_normalise_params(string $type, array $params): array
{
    $entry = exercise_catalog()[$type] ?? null;

    if ($entry === null) {
        return [];
    }

    $clean = [];

    foreach ($entry['params'] as $name => $schema) {
        $value = $params[$name] ?? $schema['default'];

        switch ($schema['kind']) {
            case 'int':
                $number = is_numeric($value) ? (int) $value : (int) $schema['default'];
                $clean[$name] = max((int) $schema['lowest'], min($number, (int) $schema['highest']));
                break;

            case 'select':
                $clean[$name] = in_array($value, $schema['options'], true) ? $value : $schema['default'];
                break;

            case 'multi':
                $kept = is_array($value) ? array_values(array_intersect($schema['options'], $value)) : [];
                /* A list that allows nothing could not build a task: fall back. */
                $clean[$name] = $kept === [] ? $schema['options'] : $kept;
                break;

            case 'flag':
                $clean[$name] = is_bool($value) ? $value : (bool) $schema['default'];
                break;
        }
    }

    /*
     * A range that runs backwards cannot build a task: every draw would return
     * the same number for ever. Numbers that arrived from anywhere - an older
     * form, a request written by hand, a card edited in phpMyAdmin - are put the
     * right way round here, so a card can never end up stuck on one number.
     */
    if (isset($clean['min'], $clean['max']) && (int) $clean['min'] > (int) $clean['max']) {
        $swap = $clean['min'];
        $clean['min'] = $clean['max'];
        $clean['max'] = $swap;
    }

    return $clean;
}

/**
 * Whether a set of parameters from a request may be stored as it is.
 *
 * The strict counterpart of exercise_normalise_params(): a name nobody knows, a
 * value outside its limits or an empty list is refused here, so what is stored is
 * always exactly what was meant - and never quietly corrected only when the card
 * is shown.
 *
 * @param array<string, mixed> $params
 */
function exercise_params_are_valid(string $type, array $params): bool
{
    $entry = exercise_catalog()[$type] ?? null;

    if ($entry === null) {
        return false;
    }

    foreach ($params as $name => $value) {
        $schema = $entry['params'][$name] ?? null;

        if ($schema === null) {
            return false;
        }

        switch ($schema['kind']) {
            case 'int':
                if (!is_int($value) || $value < (int) $schema['lowest'] || $value > (int) $schema['highest']) {
                    return false;
                }

                break;

            case 'select':
                if (!is_string($value) || !in_array($value, $schema['options'], true)) {
                    return false;
                }

                break;

            case 'multi':
                if (!is_array($value) || $value === []) {
                    return false;
                }

                foreach ($value as $single) {
                    if (!is_string($single) || !in_array($single, $schema['options'], true)) {
                        return false;
                    }
                }

                break;

            case 'flag':
                if (!is_bool($value)) {
                    return false;
                }

                break;
        }
    }

    return true;
}

/**
 * The limits of the number fields of one kind of task, for the message the dialog
 * shows when somebody types something outside them.
 *
 * @return array{lowest: int, highest: int}
 */
function exercise_number_limits(string $type): array
{
    $entry = exercise_catalog()[$type] ?? null;

    if ($entry === null) {
        return ['lowest' => 1, 'highest' => 1];
    }

    $lowest = 1;
    $highest = 1;

    foreach ($entry['params'] as $schema) {
        if ($schema['kind'] !== 'int') {
            continue;
        }

        $lowest = min($lowest, (int) $schema['lowest']);
        $highest = max($highest, (int) $schema['highest']);
    }

    return ['lowest' => $lowest, 'highest' => $highest];
}

/* -------------------------------------------------------------------------
   Building one task
   ------------------------------------------------------------------------- */

/**
 * The kinds of task that really have a builder.
 *
 * A key that is in the catalogue but has no builder is not usable:
 * exercise_build_task() returns null for it and the card is shown as a fixed
 * card, which is the safety net for a half-written kind of task.
 *
 * @return array<string, string>
 */
function exercise_task_builders(): array
{
    $builders = [];

    foreach (exercise_type_keys() as $type) {
        $function = 'exercise_task_' . $type;

        if (function_exists($function)) {
            $builders[$type] = $function;
        }
    }

    return $builders;
}

/**
 * Builds one task of this kind from these numbers.
 *
 * The answer belongs to the numbers that were drawn in this very call: question
 * and answer are made together, so they always belong to each other, while a
 * later call draws new numbers.
 *
 * Both languages travel along. Tasks that are numbers and arithmetic signs only
 * read the same in both languages; the ones that carry a sentence are built from
 * translation keys, so only the wording differs.
 *
 * Returns null when the kind of task cannot be built.
 *
 * @param array<string, mixed> $params
 * @return array{type: string, label: string, question: array<string, string>, answer: array<string, string>}|null
 */
function exercise_build_task(string $type, array $params): ?array
{
    $label = exercise_type_label($type);
    $builders = exercise_task_builders();

    if ($label === null || !isset($builders[$type])) {
        return null;
    }

    $task = ($builders[$type])(exercise_normalise_params($type, $params));

    if ($task === null) {
        return null;
    }

    return [
        'type' => $type,
        'label' => $label,
        'question' => $task['question'],
        'answer' => $task['answer'],
    ];
}

/* -------------------------------------------------------------------------
   Drawing numbers and writing them down
   ------------------------------------------------------------------------- */

/** A whole number from low to high, both included. */
function exercise_draw(int $low, int $high): int
{
    if ($high <= $low) {
        return $low;
    }

    return random_int($low, $high);
}

/**
 * One entry of a fixed list, drawn by chance.
 *
 * @param list<mixed> $values
 * @return mixed
 */
function exercise_pick(array $values)
{
    return $values[random_int(0, count($values) - 1)];
}

/**
 * Writes a number in the two languages of this interface.
 *
 * The only difference between them is the decimal separator: German writes 1,5
 * and English 1.5. Thousands are separated by a space in both, because that is
 * readable and means the same in either of them.
 */
function exercise_number(float $value, int $decimals = 0): array
{
    return [
        'de' => exercise_decimal($value, $decimals, 'de'),
        'en' => exercise_decimal($value, $decimals, 'en'),
    ];
}

/** A number in one language, without useless trailing zeros. */
function exercise_decimal(float $value, int $decimals, string $language): string
{
    $separator = $language === 'en' ? '.' : ',';
    $text = number_format($value, $decimals, $separator, ' ');

    if ($decimals > 0 && str_contains($text, $separator)) {
        $text = rtrim(rtrim($text, '0'), $separator);
    }

    return $text;
}

/**
 * A number that had to be rounded, with the sign that says so.
 *
 * "≈ 5,83" instead of "5,83": whoever reads the card should see that the exact
 * value is not a short one. The sign is the same in both languages.
 */
function exercise_rounded(float $value, int $decimals = EXERCISE_DECIMALS): array
{
    return [
        'de' => '≈ ' . exercise_decimal($value, $decimals, 'de'),
        'en' => '≈ ' . exercise_decimal($value, $decimals, 'en'),
    ];
}

/**
 * The two texts of something that reads the same in German and in English.
 *
 * @return array{de: string, en: string}
 */
function exercise_same(string $text): array
{
    return ['de' => $text, 'en' => $text];
}

/**
 * The two texts of a sentence that has to be translated.
 *
 * @param array<string, string|int|float> $params
 * @return array{de: string, en: string}
 */
function exercise_sentence(string $key, array $params = []): array
{
    return [
        'de' => t_fill('de', $key, $params),
        'en' => t_fill('en', $key, $params),
    ];
}

/** A whole number written with a superscript exponent: 10³, 10⁶. */
function exercise_superscript(int $exponent): string
{
    $digits = ['⁰', '¹', '²', '³', '⁴', '⁵', '⁶', '⁷', '⁸', '⁹'];
    $text = '';

    foreach (str_split((string) abs($exponent)) as $digit) {
        $text .= $digits[(int) $digit];
    }

    return $exponent < 0 ? '⁻' . $text : $text;
}

/**
 * A fraction written as small as it can be: 6/8 becomes 3/4, 8/8 becomes 1.
 *
 * The arithmetic behind it is exact; only the look is decided here. A minus sign
 * is put in front of the fraction, the way it is written down by hand.
 */
function exercise_fraction_text(int $numerator, int $denominator): string
{
    if ($denominator === 0) {
        return (string) $numerator;
    }

    if ($numerator === 0) {
        return '0';
    }

    $sign = '';

    if ($numerator < 0) {
        $sign = '−';
        $numerator = -$numerator;
    }

    if ($denominator < 0) {
        $sign = $sign === '−' ? '' : '−';
        $denominator = -$denominator;
    }

    $divisor = exercise_greatest_common_divisor($numerator, $denominator);
    $top = intdiv($numerator, $divisor);
    $bottom = intdiv($denominator, $divisor);

    if ($bottom === 1) {
        return $sign . $top;
    }

    return $sign . $top . '/' . $bottom;
}

/** The largest number that divides both numbers without a remainder (Euclid). */
function exercise_greatest_common_divisor(int $first, int $second): int
{
    $first = abs($first);
    $second = abs($second);

    while ($second !== 0) {
        $rest = $first % $second;
        $first = $second;
        $second = $rest;
    }

    return max(1, $first);
}

/** The smallest number that both numbers divide without a remainder. */
function exercise_least_common_multiple(int $first, int $second): int
{
    if ($first === 0 || $second === 0) {
        return 0;
    }

    return abs(intdiv($first * $second, exercise_greatest_common_divisor($first, $second)));
}

/**
 * A number between low and high that is a multiple of step.
 *
 * Used where a task has to come out exactly: a base a percentage divides evenly,
 * a list whose mean is a whole number, a series whose sum is a multiple of four.
 * The next multiple above the range is used when the range holds none, so that a
 * narrow range still shows a task instead of none.
 */
function exercise_draw_multiple(int $low, int $high, int $step): int
{
    $step = max(1, abs($step));
    $first = (int) (ceil($low / $step) * $step);
    $last = (int) (floor($high / $step) * $step);

    if ($first > $last) {
        return $first;
    }

    return $first + $step * random_int(0, intdiv($last - $first, $step));
}

/**
 * A list of whole numbers, one after the other, from low to high.
 *
 * @return list<int>
 */
function exercise_draw_series(int $count, int $low, int $high): array
{
    $values = [];

    for ($index = 0; $index < $count; $index++) {
        $values[] = exercise_draw($low, $high);
    }

    return $values;
}

/**
 * A list of numbers written down the way it is shown on a card: "3, 7, 11".
 *
 * @param list<int> $values
 */
function exercise_series_text(array $values): string
{
    return implode(', ', $values);
}

/* -------------------------------------------------------------------------
   The fixed lists of units
   ------------------------------------------------------------------------- */

/**
 * The units a conversion task may use, grouped by what they measure.
 *
 * Each entry is a list from the smallest unit upwards, together with how many of
 * the smallest unit one of them is. The factor between two units of a family is
 * the quotient of those numbers, which is always a power of ten - that is why
 * every conversion in these families comes out exactly.
 *
 * @return array<string, array<string, int>>
 */
function exercise_unit_families(): array
{
    return [
        /* Volt. */
        'v' => ['V' => 1, 'kV' => 1000],
        /* Watt. */
        'w' => ['W' => 1, 'kW' => 1000, 'MW' => 1000000],
        /* Wattstunden: what a counter counts and a bill is written for. */
        'wh' => ['Wh' => 1, 'kWh' => 1000, 'MWh' => 1000000, 'GWh' => 1000000000],
        /* Volume. The litre is the exact thousandth of a cubic metre. */
        'volume' => ['l' => 1, 'm³' => 1000],
    ];
}
