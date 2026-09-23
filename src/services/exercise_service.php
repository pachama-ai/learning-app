<?php

declare(strict_types=1);

/**
 * Exercise cards: the kinds of task this application can build, and the code that
 * builds one.
 *
 * A fixed card shows text that somebody wrote into `cards.front` and
 * `cards.back`. An exercise card shows a task that is put together at the moment
 * the card is displayed, with numbers that are drawn again on every display.
 *
 * The database only says WHICH kind of task a card is and between which numbers
 * that task lives (the table `card_exercises`: `exercise_type`, `range_min`,
 * `range_max`). The task itself - "34 + 58", the answer "92", all of it - is
 * built here, and only here.
 *
 * There is no formula anywhere: not in the database, not in a request, not in
 * this file as text that is worked out later. Every kind of task is one branch of
 * the switch below that draws its numbers and computes its answer in the same
 * step, with plain PHP arithmetic on whole numbers. Nothing is ever "evaluated",
 * and an unknown key in the database is not an error: the card simply falls back
 * to being shown as a fixed card. A new kind of task therefore only comes into
 * being by writing new code here.
 *
 * All numbers are whole numbers, also for the fractions and the decimals: a
 * decimal is carried as tenths, a fraction as numerator and denominator. That
 * keeps every answer exact (no floating point rounding) and makes it easy to
 * check.
 */

/*
 * The sizes that are drawn from a fixed list rather than from the range of the
 * card, because only familiar ones make sense: a percentage of 37 would be
 * arithmetic, not mental arithmetic.
 */
const EXERCISE_PERCENTAGES = [5, 10, 20, 25, 50, 75];

/** The denominators a fraction task may use. */
const EXERCISE_FRACTION_DENOMINATORS = [2, 3, 4, 5, 6, 8, 10, 12];

/** How many decimal places a decimal task uses (tenths, so one). */
const EXERCISE_DECIMAL_PLACES = 1;

/**
 * Every kind of task this application knows, in the order the dialog lists them.
 *
 *   label        translation key of the name of this kind, NOT a sentence: like
 *                every other text of this interface it is looked up per language
 *   hint         translation key of the one line that explains, in the dialog,
 *                where the numbers of this kind of task come from
 *   lowest       smallest number the range of a card may start at
 *   highest      largest number the range of a card may end at
 *   default_min  what the dialog fills in for a new card
 *   default_max
 *
 * The limits keep a card from being given numbers that produce unreadable tasks
 * (a square of 10000 or a sum of two million numbers).
 *
 * @return array<string, array<string, int|string>>
 */
function exercise_catalog(): array
{
    return [
        'add' => [
            'label' => 'exercise.type.add',
            'hint' => 'exercise.hint.add',
            'lowest' => 1,
            'highest' => 10000,
            'default_min' => 1,
            'default_max' => 100,
        ],
        'subtract' => [
            'label' => 'exercise.type.subtract',
            'hint' => 'exercise.hint.subtract',
            'lowest' => 1,
            'highest' => 10000,
            'default_min' => 1,
            'default_max' => 100,
        ],
        'multiply' => [
            'label' => 'exercise.type.multiply',
            'hint' => 'exercise.hint.multiply',
            'lowest' => 1,
            'highest' => 1000,
            'default_min' => 1,
            'default_max' => 10,
        ],
        'divide' => [
            'label' => 'exercise.type.divide',
            'hint' => 'exercise.hint.divide',
            'lowest' => 1,
            'highest' => 1000,
            'default_min' => 1,
            'default_max' => 10,
        ],
        'missing_addend' => [
            'label' => 'exercise.type.missing_addend',
            'hint' => 'exercise.hint.missing_addend',
            'lowest' => 1,
            'highest' => 10000,
            'default_min' => 1,
            'default_max' => 100,
        ],
        'percent_of' => [
            'label' => 'exercise.type.percent_of',
            'hint' => 'exercise.hint.percent_of',
            'lowest' => 1,
            'highest' => 100000,
            'default_min' => 10,
            'default_max' => 1000,
        ],
        'square' => [
            'label' => 'exercise.type.square',
            'hint' => 'exercise.hint.square',
            'lowest' => 1,
            'highest' => 1000,
            'default_min' => 1,
            'default_max' => 20,
        ],
        'fraction' => [
            'label' => 'exercise.type.fraction',
            'hint' => 'exercise.hint.fraction',
            'lowest' => 1,
            'highest' => 99,
            'default_min' => 1,
            'default_max' => 9,
        ],
        'decimal' => [
            'label' => 'exercise.type.decimal',
            'hint' => 'exercise.hint.decimal',
            'lowest' => 1,
            'highest' => 10000,
            'default_min' => 1,
            'default_max' => 20,
        ],
    ];
}

/**
 * The keys of every kind of task, in the order of the catalog.
 *
 * @return list<string>
 */
function exercise_type_keys(): array
{
    return array_keys(exercise_catalog());
}

/**
 * Whether this key is one of the kinds of task that are written down here.
 */
function exercise_type_is_known(string $type): bool
{
    return array_key_exists($type, exercise_catalog());
}

/**
 * The translation key that names this kind of task, or null when the key is not
 * one of ours.
 */
function exercise_type_label(string $type): ?string
{
    return exercise_catalog()[$type]['label'] ?? null;
}

/**
 * The number range the dialog suggests for this kind of task.
 *
 * @return array{min: int, max: int}
 */
function exercise_type_default_range(string $type): array
{
    $entry = exercise_catalog()[$type] ?? null;

    if ($entry === null) {
        return ['min' => 1, 'max' => 10];
    }

    return ['min' => (int) $entry['default_min'], 'max' => (int) $entry['default_max']];
}

/**
 * Whether a range a person typed is one this kind of task can work with.
 *
 * The limits are the ones in the catalog: a range that lies outside them is
 * refused when a card is saved, instead of being stored and quietly corrected on
 * every display.
 */
function exercise_range_is_valid(string $type, int $rangeMin, int $rangeMax): bool
{
    $entry = exercise_catalog()[$type] ?? null;

    if ($entry === null) {
        return false;
    }

    return $rangeMin >= (int) $entry['lowest']
        && $rangeMax <= (int) $entry['highest']
        && $rangeMin <= $rangeMax;
}

/**
 * Brings a stored range into the limits of its kind of task.
 *
 * Stored values are trusted to have been checked when they were saved, but a row
 * can be older than the limits or be edited by hand. Drawing numbers is never
 * refused because of that: the range is quietly pulled into the allowed limits so
 * the card still shows a task.
 *
 * @return array{0: int, 1: int}
 */
function exercise_normalise_range(string $type, int $rangeMin, int $rangeMax): array
{
    $entry = exercise_catalog()[$type] ?? null;

    if ($entry === null) {
        return [1, 10];
    }

    $lowest = (int) $entry['lowest'];
    $highest = (int) $entry['highest'];
    $low = max($lowest, min($rangeMin, $highest));
    $high = max($low, min($rangeMax, $highest));

    return [$low, $high];
}

/**
 * Builds one task of this kind, with everything the interface needs to show it.
 *
 * The answer belongs to the numbers that were drawn in this very call: question
 * and answer are made together, so they always belong to each other, while a
 * later call draws new numbers.
 *
 * Both languages travel along, because one of the nine kinds of task is written
 * differently in each of them (a decimal has a comma in German and a full stop in
 * English). For the other eight the two are the same string - they are only
 * numbers and signs, which need no translation.
 *
 * Returns null when the key is not one of ours. The caller then shows the card as
 * a fixed card.
 *
 * @return array{type: string, label: string, question: array<string, string>, answer: array<string, string>}|null
 */
function exercise_build_task(string $type, int $rangeMin, int $rangeMax): ?array
{
    $label = exercise_type_label($type);

    if ($label === null) {
        return null;
    }

    [$low, $high] = exercise_normalise_range($type, $rangeMin, $rangeMax);

    $question = null;
    $answer = null;

    switch ($type) {
        case 'add':
            $first = exercise_draw($low, $high);
            $second = exercise_draw($low, $high);
            $question = exercise_same_in_both_languages($first . ' + ' . $second);
            $answer = exercise_same_in_both_languages((string) ($first + $second));
            break;

        case 'subtract':
            /* The larger number comes first, so the answer is never negative. */
            $first = exercise_draw($low, $high);
            $second = exercise_draw($low, $high);

            if ($second > $first) {
                [$first, $second] = [$second, $first];
            }

            $question = exercise_same_in_both_languages($first . ' − ' . $second);
            $answer = exercise_same_in_both_languages((string) ($first - $second));
            break;

        case 'multiply':
            $first = exercise_draw($low, $high);
            $second = exercise_draw($low, $high);
            $question = exercise_same_in_both_languages($first . ' · ' . $second);
            $answer = exercise_same_in_both_languages((string) ($first * $second));
            break;

        case 'divide':
            /*
             * The divisor and the answer are drawn and the number that is divided
             * is their product. So the division always comes out even, which is
             * the point of this kind of task.
             */
            $divisor = exercise_draw($low, $high);
            $result = exercise_draw($low, $high);
            $question = exercise_same_in_both_languages(($divisor * $result) . ' ÷ ' . $divisor);
            $answer = exercise_same_in_both_languages((string) $result);
            break;

        case 'missing_addend':
            /*
             * "34 + ? = 92": the number that is asked for is the second addend.
             * The sum is shown, the addend is the answer.
             */
            $first = exercise_draw($low, $high);
            $second = exercise_draw($low, $high);
            $question = exercise_same_in_both_languages($first . ' + ? = ' . ($first + $second));
            $answer = exercise_same_in_both_languages((string) $second);
            break;

        case 'percent_of':
            $percent = exercise_pick(EXERCISE_PERCENTAGES);
            $base = exercise_percent_base($low, $high, $percent);
            $question = exercise_same_in_both_languages($base . ' × ' . $percent . ' %');
            /* Whole by construction: the base is a multiple of 100/gcd(percent,100). */
            $answer = exercise_same_in_both_languages((string) intdiv($base * $percent, 100));
            break;

        case 'square':
            $number = exercise_draw($low, $high);
            $question = exercise_same_in_both_languages($number . '²');
            $answer = exercise_same_in_both_languages((string) ($number * $number));
            break;

        case 'fraction':
            /*
             * Same denominator, so the task stays mental arithmetic. The
             * numerators come from the range of the card, but never reach the
             * denominator: otherwise the fraction would be a whole number in
             * disguise. The answer is always written as the smallest fraction
             * that has the same value.
             */
            $denominator = exercise_pick(EXERCISE_FRACTION_DENOMINATORS);
            $numeratorLow = max(1, min($low, $denominator - 1));
            $numeratorHigh = max($numeratorLow, min($high, $denominator - 1));

            $first = exercise_draw($numeratorLow, $numeratorHigh);
            $second = exercise_draw($numeratorLow, $numeratorHigh);

            if (random_int(0, 1) === 1) {
                $question = exercise_same_in_both_languages(
                    $first . '/' . $denominator . ' + ' . $second . '/' . $denominator
                );
                $top = $first + $second;
            } else {
                if ($second > $first) {
                    [$first, $second] = [$second, $first];
                }

                $question = exercise_same_in_both_languages(
                    $first . '/' . $denominator . ' − ' . $second . '/' . $denominator
                );
                $top = $first - $second;
            }

            $answer = exercise_same_in_both_languages(exercise_fraction_text($top, $denominator));
            break;

        case 'decimal':
            /*
             * A decimal is carried as tenths, so nothing is ever rounded. Whole
             * part from the range, one decimal place from 1 to 9 (so a task never
             * shows a useless ",0").
             */
            $first = exercise_draw_decimal($low, $high);
            $second = exercise_draw_decimal($low, $high);

            if (random_int(0, 1) === 1) {
                $sum = $first + $second;
                $sign = ' + ';
            } else {
                if ($second > $first) {
                    [$first, $second] = [$second, $first];
                }

                $sum = $first - $second;
                $sign = ' − ';
            }

            /* The only kind of task that is written differently per language. */
            $question = [
                'de' => exercise_tenths_text($first, 'de') . $sign . exercise_tenths_text($second, 'de'),
                'en' => exercise_tenths_text($first, 'en') . $sign . exercise_tenths_text($second, 'en'),
            ];
            $answer = [
                'de' => exercise_tenths_text($sum, 'de'),
                'en' => exercise_tenths_text($sum, 'en'),
            ];
            break;
    }

    if ($question === null || $answer === null) {
        return null;
    }

    return [
        'type' => $type,
        'label' => $label,
        'question' => $question,
        'answer' => $answer,
    ];
}

/**
 * A whole number from low to high, both included.
 *
 * random_int() is the one random source in PHP that is meant to be used for
 * anything that has to be unpredictable. For a task the numbers do not have to be
 * unguessable, but using the same function everywhere keeps the code simple.
 */
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
 * @param list<int> $values
 */
function exercise_pick(array $values): int
{
    return $values[random_int(0, count($values) - 1)];
}

/**
 * The two texts of a task that reads the same in German and in English.
 *
 * Eight of the nine kinds of task consist of numbers and arithmetic signs only,
 * so there is nothing to translate. Sending the same text twice keeps the shape
 * of the answer the same for every kind of task, which is easier for whoever
 * reads the reply.
 *
 * @return array{de: string, en: string}
 */
function exercise_same_in_both_languages(string $text): array
{
    return ['de' => $text, 'en' => $text];
}

/**
 * A base number that this percentage can be taken from without a remainder.
 *
 * "20 % von 150" is 30, but "20 % von 151" is 30.2 - not a task for a card. The
 * base therefore has to be a multiple of 100/gcd(percent, 100): for 20 that is
 * every fifth number, for 25 every fourth, for 50 every second. The base is drawn
 * from the multiples inside the range of the card. When the range is so narrow
 * that it holds none, the next multiple above it is used - a task is then shown
 * with a base just outside the range instead of no task at all.
 */
function exercise_percent_base(int $low, int $high, int $percent): int
{
    $step = intdiv(100, exercise_greatest_common_divisor($percent, 100));
    $first = intdiv($low + $step - 1, $step) * $step;
    $last = intdiv($high, $step) * $step;

    if ($first > $last) {
        return $first;
    }

    return $first + $step * random_int(0, intdiv($last - $first, $step));
}

/**
 * A decimal from the range, carried as tenths.
 *
 * The whole part lies in low..high and gets one decimal place, so a task never
 * shows "7,0" but always something like "7,4".
 */
function exercise_draw_decimal(int $low, int $high): int
{
    $whole = exercise_draw($low, $high);
    $tenths = random_int(1, 9);

    return $whole * 10 + $tenths;
}

/**
 * Writes tenths as a decimal number in one language.
 *
 * German puts a comma between the whole part and the decimals, English a full
 * stop. A number whose decimals are zero is written without them: "2" instead of
 * "2,0".
 */
function exercise_tenths_text(int $tenths, string $language): string
{
    $sign = $tenths < 0 ? '−' : '';
    $value = abs($tenths);
    $whole = intdiv($value, 10);
    $rest = $value % 10;
    $separator = $language === 'en' ? '.' : ',';

    if ($rest === 0) {
        return $sign . $whole;
    }

    return $sign . $whole . $separator . $rest;
}

/**
 * Writes a fraction as small as it can be: 6/8 becomes 3/4, 8/8 becomes 1.
 */
function exercise_fraction_text(int $numerator, int $denominator): string
{
    if ($numerator === 0) {
        return '0';
    }

    $divisor = exercise_greatest_common_divisor($numerator, $denominator);
    $top = intdiv($numerator, $divisor);
    $bottom = intdiv($denominator, $divisor);

    if ($bottom === 1) {
        return (string) $top;
    }

    return $top . '/' . $bottom;
}

/**
 * The largest number that divides both numbers without a remainder (Euclid).
 *
 * Only whole numbers and only a loop that always gets smaller: nothing here can
 * run long, and there is no division by zero because the loop stops when the
 * second number is 0.
 */
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
