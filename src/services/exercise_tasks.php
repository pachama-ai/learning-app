<?php

declare(strict_types=1);

/**
 * The kinds of task themselves: Grundrechnen, Algebra, Prozentrechnung.
 *
 * One function per kind of task. Each of them draws its numbers and computes its
 * answer in the same step, so the answer always belongs to the question that was
 * drawn with it. Nothing here is ever read from outside: the parameters come from
 * exercise_normalise_params(), which only lets through the keys and values that
 * exercise_catalog() allows.
 *
 * Whole numbers wherever an answer has to be exact. Where a result cannot be a
 * short number (a square root, a standard deviation), it is rounded and written
 * with the sign that says so - "≈ 5,83".
 *
 * Every function returns
 *
 *   ['question' => ['de' => …, 'en' => …], 'answer' => ['de' => …, 'en' => …]]
 *
 * The two languages are the same text for a task that is numbers and arithmetic
 * signs only, and two translations of the same sentence for the ones that carry
 * wording.
 */


/** The right-angled triangles whose sides are whole numbers, small to large. */
const EXERCISE_PYTHAGORAS_TRIPLES = [
    [3, 4, 5],
    [6, 8, 10],
    [5, 12, 13],
    [9, 12, 15],
    [8, 15, 17],
    [12, 16, 20],
    [7, 24, 25],
    [20, 21, 29],
];

/**
 * Multiplication: two whole numbers from the range.
 */
function exercise_task_times_table(array $params): array
{
    $first = exercise_draw((int) $params['min'], (int) $params['max']);
    $second = exercise_draw((int) $params['min'], (int) $params['max']);

    return [
        'question' => exercise_same($first . ' · ' . $second),
        'answer' => exercise_same((string) ($first * $second)),
    ];
}

/**
 * Division as the reverse of multiplication.
 *
 * The divisor and the answer are drawn and the number that is divided is their
 * product, so the division always comes out even. With `remainder` a rest is
 * added on purpose, and then the answer is written as a quotient with a rest.
 */
function exercise_task_division_inverse(array $params): array
{
    $low = max(2, (int) $params['min']);
    $high = max($low, (int) $params['max']);
    $divisor = exercise_draw($low, $high);
    $quotient = exercise_draw($low, $high);
    $dividend = $divisor * $quotient;

    if ($params['remainder'] === true) {
        $rest = exercise_draw(1, $divisor - 1);

        return [
            'question' => exercise_same(($dividend + $rest) . ' ÷ ' . $divisor),
            'answer' => exercise_sentence('exercise.task.remainder', [
                'quotient' => $quotient,
                'remainder' => $rest,
            ]),
        ];
    }

    if ($params['ask'] === 'divisor') {
        return [
            'question' => exercise_same($dividend . ' ÷ ? = ' . $quotient),
            'answer' => exercise_same((string) $divisor),
        ];
    }

    return [
        'question' => exercise_same($dividend . ' ÷ ' . $divisor),
        'answer' => exercise_same((string) $quotient),
    ];
}

/**
 * Two simple fractions, added, subtracted, multiplied or divided.
 *
 * The answer is always written as the smallest fraction with the same value. The
 * numerators stay below their denominator, so a fraction is really a fraction and
 * not a whole number in disguise. When subtracting, the larger fraction is put
 * first, so the answer is never negative.
 */
function exercise_task_fraction(array $params): array
{
    $operation = (string) exercise_pick($params['operations']);
    $denominators = EXERCISE_FRACTION_DENOMINATORS;
    $firstDenominator = exercise_pick($denominators);

    if ($operation === 'add' || $operation === 'subtract') {
        /* Different denominators are what makes this a task worth practising. */
        $others = array_values(array_filter($denominators, static fn (int $value): bool => $value !== $firstDenominator));
        $secondDenominator = exercise_pick($others);
    } else {
        $secondDenominator = exercise_pick($denominators);
    }

    $firstTop = exercise_draw(
        max(1, (int) $params['min']),
        max(1, min((int) $params['max'], $firstDenominator - 1))
    );
    $secondTop = exercise_draw(
        max(1, (int) $params['min']),
        max(1, min((int) $params['max'], $secondDenominator - 1))
    );

    /* The larger fraction first when subtracting: a − b with a bigger than b. */
    if ($operation === 'subtract' && $firstTop * $secondDenominator < $secondTop * $firstDenominator) {
        [$firstTop, $firstDenominator, $secondTop, $secondDenominator] =
            [$secondTop, $secondDenominator, $firstTop, $firstDenominator];
    }

    $signs = ['add' => ' + ', 'subtract' => ' − ', 'multiply' => ' · ', 'divide' => ' : '];
    $sign = $signs[$operation] ?? ' + ';

    switch ($operation) {
        case 'multiply':
            $top = $firstTop * $secondTop;
            $bottom = $firstDenominator * $secondDenominator;
            break;

        case 'divide':
            /* Dividing by a fraction is multiplying by its reciprocal. */
            $top = $firstTop * $secondDenominator;
            $bottom = $firstDenominator * $secondTop;
            break;

        default:
            $common = exercise_least_common_multiple($firstDenominator, $secondDenominator);
            $leftTop = $firstTop * intdiv($common, $firstDenominator);
            $rightTop = $secondTop * intdiv($common, $secondDenominator);
            $top = $operation === 'add' ? $leftTop + $rightTop : $leftTop - $rightTop;
            $bottom = $common;
    }

    return [
        'question' => exercise_same(
            $firstTop . '/' . $firstDenominator . $sign . $secondTop . '/' . $secondDenominator
        ),
        'answer' => exercise_same(exercise_fraction_text($top, $bottom)),
    ];
}

/**
 * A short expression with brackets and at least one negative number.
 *
 * Four patterns, all of them written the way they are written at school. The
 * numbers are drawn from the range and the answer is worked out with whole
 * numbers, so it is always exact. In the first two patterns the bracket is forced
 * to be negative, so that a negative number really appears in the task.
 */
function exercise_task_negative_parens(array $params): array
{
    $pattern = (string) exercise_pick($params['patterns']);
    $first = exercise_draw((int) $params['min'], (int) $params['max']);
    $second = exercise_draw((int) $params['min'], (int) $params['max']);
    $third = exercise_draw((int) $params['min'], (int) $params['max']);

    if (($pattern === 'plus_minus' || $pattern === 'minus_minus') && $third <= $second) {
        [$second, $third] = [$third, $second];
    }

    switch ($pattern) {
        case 'minus_minus':
            return [
                'question' => exercise_same($first . ' − (' . $second . ' − ' . $third . ')'),
                'answer' => exercise_same((string) ($first - $second + $third)),
            ];

        case 'negative_product':
            return [
                'question' => exercise_same('−(' . $first . ' − ' . $second . ') · ' . $third),
                'answer' => exercise_same((string) (-($first - $second) * $third)),
            ];

        case 'negative_factor':
            return [
                'question' => exercise_same('(' . $first . ' + ' . $second . ') · (−' . $third . ')'),
                'answer' => exercise_same((string) (-($first + $second) * $third)),
            ];

        default:
            return [
                'question' => exercise_same($first . ' + (' . $second . ' − ' . $third . ')'),
                'answer' => exercise_same((string) ($first + $second - $third)),
            ];
    }
}

/**
 * Powers of ten and scientific notation.
 *
 *   power_of_ten     10³ = ?                     -> 1000
 *   to_scientific    write 4 500 000 in scientific notation -> 4,5 · 10⁶
 *   from_scientific  4,5 · 10⁶ = ?                -> 4 500 000
 *
 * The mantissa keeps one decimal place and the exponent is a whole number, so the
 * value is exact: it is built from whole tenths and a power of ten, never from a
 * rounded floating point number.
 */
function exercise_task_powers_scientific(array $params): array
{
    $variant = (string) exercise_pick($params['variants']);
    $exponent = exercise_draw((int) $params['min'], (int) $params['max']);
    $exponent = max(1, min(9, $exponent));

    if ($variant === 'power_of_ten') {
        $value = 10 ** $exponent;

        return [
            'question' => exercise_same('10' . exercise_superscript($exponent)),
            'answer' => exercise_same(exercise_decimal((float) $value, 0, 'de')),
        ];
    }

    /* A mantissa from 1,1 to 9,9 as whole tenths: exact, never 4,5000001. */
    $tenths = exercise_draw(11, 99);

    if ($tenths % 10 === 0) {
        $tenths++;
    }

    $value = ($tenths / 10) * (10 ** $exponent);
    $mantissa = exercise_number($tenths / 10, 1);
    $plain = exercise_number($value, 0);

    /*
     * This is one of the few tasks where the number itself is written differently
     * in the two languages: 1,4 · 10³ has a comma in German and a full stop in
     * English. Both the question and the answer carry that number, so both are
     * built per language.
     */
    $scientific = [
        'de' => $mantissa['de'] . ' · 10' . exercise_superscript($exponent),
        'en' => $mantissa['en'] . ' · 10' . exercise_superscript($exponent),
    ];

    if ($variant === 'to_scientific') {
        return [
            'question' => [
                'de' => t_fill('de', 'exercise.task.to_scientific', ['number' => $plain['de']]),
                'en' => t_fill('en', 'exercise.task.to_scientific', ['number' => $plain['en']]),
            ],
            'answer' => $scientific,
        ];
    }

    return [
        'question' => $scientific,
        'answer' => $plain,
    ];
}

/**
 * A linear equation, or one of the known formulas solved for one of its letters.
 *
 *   equation  a · x + b = c  ->  x
 *   formula   E = P · t with two of the three given  ->  the third one
 *
 * The equation is built from the answer, never the other way round: the answer is
 * a whole number and the right side is computed from it, so it always fits
 * exactly. In the formulas the numbers are the usual ones (230 V, whole amperes,
 * whole hours), which keeps the answers exact as well.
 */
function exercise_task_linear_equation(array $params): array
{
    $variant = (string) exercise_pick($params['variants']);

    if ($variant === 'formula') {
        return exercise_formula_task($params);
    }

    $coefficient = exercise_draw((int) $params['min'], (int) $params['max']);
    $answer = exercise_draw((int) $params['min'], (int) $params['max']);
    $addend = exercise_draw((int) $params['min'], (int) $params['max']);

    /* Half of the tasks add the addend, half of them take it away. */
    if (random_int(0, 1) === 1) {
        $right = $coefficient * $answer + $addend;

        return [
            'question' => exercise_same($coefficient . ' · x + ' . $addend . ' = ' . $right),
            'answer' => exercise_same('x = ' . $answer),
        ];
    }

    $addend = min($addend, $coefficient * $answer);
    $right = $coefficient * $answer - $addend;

    return [
        'question' => exercise_same($coefficient . ' · x − ' . $addend . ' = ' . $right),
        'answer' => exercise_same('x = ' . $answer),
    ];
}

/**
 * One of the three formulas E = P · t and P = U · I, with one letter missing.
 *
 * Which letter is asked for is drawn, and the other two are given. All three
 * values are whole numbers of their unit, so the answer is exact.
 */
function exercise_formula_task(array $params): array
{
    $low = (int) $params['min'];
    $high = (int) $params['max'];

    if (random_int(0, 1) === 0) {
        /* P = U · I */
        $voltage = exercise_draw(max(1, min($low, 400)), max(1, min($high, 400)));
        $current = exercise_draw(1, max(1, min(20, $high)));
        $power = $voltage * $current;
        $unknown = exercise_pick(['P', 'U', 'I']);

        $parts = [
            'P' => $power . ' W',
            'U' => $voltage . ' V',
            'I' => $current . ' A',
        ];

        return [
            'question' => exercise_same('P = U · I   ' . $parts['U'] . ',   ' . $parts['I'] . '   →   P = ?'),
            'answer' => exercise_same($parts['P']),
        ];
    }

    /* E = P · t */
    $power = exercise_draw(1, max(1, min(100, $high)));
    $hours = exercise_draw(1, 24);
    $energy = $power * $hours;

    return [
        'question' => exercise_same('E = P · t   ' . $power . ' kW,   ' . $hours . ' h   →   E = ?'),
        'answer' => exercise_same($energy . ' kWh'),
    ];
}

/**
 * The Pythagorean theorem: two sides of a right-angled triangle are given, the
 * third one is asked for.
 *
 * With probability most of the tasks use a triangle whose sides are whole
 * numbers (3-4-5, 6-8-10, 5-12-13 …), so the answer is exact. Otherwise the sides
 * are drawn from the range and the answer is a square root that is rounded - and
 * written with the ≈ sign, so nobody takes it for an exact value.
 */
function exercise_task_pythagoras(array $params): array
{
    $variant = (string) exercise_pick($params['variants']);
    $low = max(1, (int) $params['min']);
    $high = max($low, (int) $params['max']);

    $usable = array_values(array_filter(
        EXERCISE_PYTHAGORAS_TRIPLES,
        static fn (array $triple): bool => $triple[0] >= $low && $triple[1] <= $high
    ));

    $wantsWholeSides = random_int(0, 9) < 7 && $usable !== [];

    if ($wantsWholeSides) {
        $triple = exercise_pick($usable);
        $legFirst = $triple[0];
        $legSecond = $triple[1];
        $hypotenuse = $triple[2];
    } else {
        $legFirst = exercise_draw($low, $high);
        $legSecond = exercise_draw($low, $high);
        $hypotenuse = (int) round(sqrt($legFirst ** 2 + $legSecond ** 2));
    }

    if ($variant === 'leg') {
        /*
         * The hypotenuse and one leg are known, the other leg is asked for. When
         * no whole-number triangle fits the range, both given sides are drawn
         * freely - the hypotenuse the longer one - so that the two really are the
         * sides of the same triangle and only the answer is rounded.
         */
        if ($wantsWholeSides) {
            return [
                'question' => exercise_same(
                    'c = ' . $hypotenuse . ' cm,   a = ' . $legFirst . ' cm   →   b = ?'
                ),
                'answer' => exercise_same($legSecond . ' cm'),
            ];
        }

        $hypotenuse = exercise_draw($low + 1, $high + 1);
        $known = exercise_draw($low, $hypotenuse - 1);

        return [
            'question' => exercise_same(
                'c = ' . $hypotenuse . ' cm,   a = ' . $known . ' cm   →   b = ?'
            ),
            'answer' => exercise_rounded(sqrt($hypotenuse ** 2 - $known ** 2)),
        ];
    }

    return [
        'question' => exercise_same(
            'a = ' . $legFirst . ' cm,   b = ' . $legSecond . ' cm   →   c = ?'
        ),
        'answer' => $wantsWholeSides
            ? exercise_same($hypotenuse . ' cm')
            : exercise_rounded(sqrt($legFirst ** 2 + $legSecond ** 2)),
    ];
}

/**
 * Percentages: two of the three values are given, the third one is asked for.
 *
 * The base is always a multiple of the step that makes the percentage come out
 * whole, so no task ever ends in a decimal fraction that should not be there.
 *
 *   value   150 · 20 % = ?          -> 30
 *   rate    how much percent are 30 of 150?  -> 20 %
 *   base    20 % of ? are 30        -> 150
 */
function exercise_task_percent(array $params): array
{
    $rate = exercise_pick(EXERCISE_PERCENTAGES);
    $step = intdiv(100, exercise_greatest_common_divisor($rate, 100));
    $base = exercise_draw_multiple((int) $params['min'], (int) $params['max'], $step);
    $value = intdiv($base * $rate, 100);
    $ask = (string) $params['ask'];

    if ($ask === 'rate') {
        return [
            'question' => exercise_sentence('exercise.task.percent_rate', ['part' => $value, 'total' => $base]),
            'answer' => exercise_same($rate . ' %'),
        ];
    }

    if ($ask === 'base') {
        /* The base is a multiple of the step, so it divides without a rest. */
        $known = intdiv($base * $rate, 100);

        return [
            'question' => exercise_sentence('exercise.task.percent_base', ['rate' => $rate, 'part' => $known]),
            'answer' => exercise_same((string) $base),
        ];
    }

    return [
        'question' => exercise_same($base . ' · ' . $rate . ' % = ?'),
        'answer' => exercise_same((string) $value),
    ];
}

/**
 * Percentages in an energy context.
 *
 * One of six situations is drawn, and then the numbers for it. Each situation has
 * its own plausible sizes: a share of the generation mix in GWh, a photovoltaic
 * plant against the power it could deliver, self-consumption in kWh, the state of
 * charge of a battery in MWh, losses in the grid, or the change of a price. The
 * share is always a whole percentage of the total, so the answer is exact.
 *
 * The question and the answer carry the unit of the situation, so the sentence and
 * the number always belong together.
 */
function exercise_task_percent_energy(array $params): array
{
    $variant = (string) exercise_pick($params['variants']);
    $rate = exercise_pick(EXERCISE_PERCENTAGES);
    $step = intdiv(100, exercise_greatest_common_divisor($rate, 100));

    switch ($variant) {
        case 'pv_ratio':
            $total = exercise_draw_multiple(20, 500, $step);
            $part = intdiv($total * $rate, 100);

            return [
                'question' => exercise_sentence('exercise.task.percent_energy.pv_ratio', [
                    'part' => $part,
                    'total' => $total,
                ]),
                'answer' => exercise_same($rate . ' %'),
            ];

        case 'self_consumption':
            $total = exercise_draw_multiple(200, 12000, $step);
            $part = intdiv($total * $rate, 100);

            return [
                'question' => exercise_sentence('exercise.task.percent_energy.self_consumption', [
                    'part' => $part,
                    'total' => $total,
                ]),
                'answer' => exercise_same($rate . ' %'),
            ];

        case 'storage_level':
            $total = exercise_draw_multiple(10, 500, $step);
            $part = intdiv($total * $rate, 100);

            return [
                'question' => exercise_sentence('exercise.task.percent_energy.storage_level', [
                    'part' => $part,
                    'total' => $total,
                ]),
                'answer' => exercise_same($rate . ' %'),
            ];

        case 'grid_losses':
            $total = exercise_draw_multiple(100, 8000, $step);
            $part = intdiv($total * $rate, 100);

            return [
                'question' => exercise_sentence('exercise.task.percent_energy.grid_losses', [
                    'part' => $part,
                    'total' => $total,
                ]),
                'answer' => exercise_same($rate . ' %'),
            ];

        case 'price_change':
            $before = exercise_draw_multiple(10, 60, $step);
            $after = intdiv($before * (100 + $rate), 100);

            return [
                'question' => exercise_sentence('exercise.task.percent_energy.price_change', [
                    'before' => $before,
                    'after' => $after,
                ]),
                'answer' => exercise_same($rate . ' %'),
            ];

        default:
            /* The generation mix: one source against everything produced. */
            $total = exercise_draw_multiple(100, 900, $step);
            $part = intdiv($total * $rate, 100);

            return [
                'question' => exercise_sentence('exercise.task.percent_energy.mix', [
                    'part' => $part,
                    'total' => $total,
                ]),
                'answer' => exercise_same($rate . ' %'),
            ];
    }
}

/**
 * The rule of three: a quantity and a cost, or a number of devices and their
 * consumption, scaled to another quantity.
 *
 * The price of one piece (or of one device) is drawn first and the task is built
 * from it, so the answer is a whole number and not a rounded one.
 */
function exercise_task_rule_of_three(array $params): array
{
    $variant = (string) exercise_pick($params['variants']);
    $low = max(1, (int) $params['min']);
    $high = max($low, (int) $params['max']);

    if ($variant === 'quantity_power') {
        $each = exercise_draw(100, 4000);
        $first = exercise_draw($low, $high);
        $second = exercise_draw($low, $high);

        return [
            'question' => exercise_sentence('exercise.task.rule_quantity_power', [
                'first' => $first,
                'second' => $second,
                'total' => $first * $each,
            ]),
            'answer' => exercise_same(($second * $each) . ' kWh'),
        ];
    }

    $each = exercise_draw(1, 25);
    $first = exercise_draw($low, $high);
    $second = exercise_draw($low, $high);

    return [
        'question' => exercise_sentence('exercise.task.rule_quantity_cost', [
            'first' => $first,
            'second' => $second,
            'total' => $first * $each,
        ]),
        'answer' => exercise_same(($second * $each) . ' €'),
    ];
}
