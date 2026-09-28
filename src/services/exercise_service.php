<?php

declare(strict_types=1);

/**
 * Erzeugte Aufgaben: welche Aufgabenarten diese Anwendung bauen kann, was jede von
 * ihnen bekommen darf und wie eine Aufgabe zusammengesetzt wird.
 *
 * Eine feste Karte zeigt Text, den jemand geschrieben hat. Eine erzeugte Karte zeigt
 * eine Aufgabe, die in dem Moment gebaut wird, in dem die Karte angezeigt wird, mit
 * Zahlen, die bei jedem Anzeigen neu gezogen werden. Die Datenbank speichert nur,
 * WELCHE Art von Aufgabe eine Karte ist, und die Zahlen, die sie benutzen darf (die
 * Tabelle `card_exercises`: `exercise_type` und `exercise_params` als JSON). Alles
 * andere passiert hier und in exercise_tasks.php.
 *
 * Eine Formel steht nirgends: nicht in der Datenbank, nicht in einer Anfrage, nicht in
 * dieser Datei als Text, der später ausgerechnet wird. Die Schlüssel in
 * `exercise_params` werden unten in exercise_catalog() nachgesehen; ein Schlüssel, der
 * nicht in dieser Liste steht, wird abgelehnt, und ein Wert außerhalb seiner Grenzen
 * wird in sie hineingezogen. Jede Aufgabenart ist eine Funktion in exercise_tasks.php,
 * die ihre Zahlen zieht und ihre Antwort im selben Schritt ausrechnet. Eine neue
 * Aufgabenart entsteht deshalb nur durch neuen Code - nie durch Daten.
 *
 * Der Katalog ist außerdem die einzige Quelle für die Oberfläche: public/index.php
 * reicht ihn dem Browser als config.exerciseTypes weiter, der Kartendialog bietet also
 * genau die Aufgabenarten an, die es hier gibt, mit genau den Feldern, die jede von
 * ihnen braucht.
 */

require_once __DIR__ . '/exercise_tasks.php';
require_once __DIR__ . '/exercise_tasks_energy.php';
require_once __DIR__ . '/../helpers/translations.php';

/** Die Nenner, die eine Bruch-Aufgabe benutzen darf. */
const EXERCISE_FRACTION_DENOMINATORS = [2, 3, 4, 5, 6, 8, 10, 12];

/** Die Prozentsätze, die eine Prozent-Aufgabe benutzen darf: nur geläufige. */
const EXERCISE_PERCENTAGES = [5, 10, 15, 20, 25, 40, 50, 60, 75];

/** Wie viele Nachkommastellen eine gerundete Antwort bekommt, wenn ihre Art nichts anderes sagt. */
const EXERCISE_DECIMALS = 2;

/**
 * Jede Aufgabenart, mit allem, was sie bekommen darf.
 *
 *   label   Übersetzungsschlüssel des Namens der Aufgabenart
 *   hint    Übersetzungsschlüssel des Satzes, der sie im Dialog erklärt
 *   params  was einer Karte dieser Art gesagt werden darf, als kleines Schema, das der
 *           Server und der Kartendialog beide lesen:
 *
 *             kind     'int'    eine ganze Zahl zwischen lowest und highest
 *                      'select' genau eine von options
 *                      'multi'  beliebig viele von options
 *                      'flag'   ja oder nein
 *             default  was eine neue Karte bekommt
 *             lowest   die Grenzen eines 'int'
 *             highest
 *             options  die erlaubten Werte von 'select' und 'multi'
 *
 * Ein Parameter wird unter exercise.param.<name> gezeigt und eine Auswahl unter
 * exercise.option.<value>, es muss also keine Beschriftung je Aufgabenart wiederholt
 * werden.
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

    /*
     * Ein Parameter der Art "multi" darf mehrere seiner Auswahlen auf einmal tragen, sein
     * Wert ist also immer eine LISTE - auch die Vorgabe. Mit einer bloßen Zeichenkette
     * dort lehnte die Prüfung in exercise_params_are_valid() jede Karte ab, die die
     * Vorgabe benutzen wollte, und eine neue Übungskarte dieser Arten ließ sich gar nicht
     * speichern. (Beim Anlegen von Übungskarten gefunden, nicht im Code.)
     */
    $choice = static function (array $options, $default, string $kind = 'select'): array {
        return [
            'kind' => $kind,
            'default' => $kind === 'multi' && !is_array($default) ? [$default] : $default,
            'options' => $options,
        ];
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
 * Die Schlüssel jeder Aufgabenart, in der Reihenfolge des Katalogs.
 *
 * @return list<string>
 */
function exercise_type_keys(): array
{
    return array_keys(exercise_catalog());
}

/** Ob dieser Schlüssel eine der Aufgabenarten ist, die es hier gibt. */
function exercise_type_is_known(string $type): bool
{
    return array_key_exists($type, exercise_catalog());
}

/** Der Übersetzungsschlüssel, der diese Aufgabenart benennt, oder null. */
function exercise_type_label(string $type): ?string
{
    $entry = exercise_catalog()[$type] ?? null;

    return $entry === null ? null : (string) $entry['label'];
}

/** Der Übersetzungsschlüssel, der diese Aufgabenart erklärt, oder null. */

/**
 * Die Parameter, mit denen eine Karte dieser Art im Dialog startet.
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
 * Das Schema eines Parameters oder null.
 *
 * @return array<string, mixed>|null
 */

/**
 * Bringt einen gespeicherten oder geschickten Satz von Parametern in die Form, die
 * diese Aufgabenart erwartet: nur bekannte Schlüssel, jeder Wert innerhalb seiner
 * Grenzen, jeder fehlende Wert mit seiner Vorgabe gefüllt.
 *
 * Das ist der Grund, warum eine von Hand geänderte Karte oder eine Aufgabenart, deren
 * Schema später einen Parameter dazubekommen hat, trotzdem eine sinnvolle Aufgabe zeigt
 * statt gar keine. Und es ist der Grund, warum nichts Unbekanntes weiterreist: ein
 * Schlüssel, der nicht im Schema steht, wird fallengelassen, nicht gespeichert und
 * nirgends nachgesehen.
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
                /* Eine Liste, die nichts erlaubt, könnte keine Aufgabe bauen:
                   zurück zur Vorgabe. */
                $clean[$name] = $kept === [] ? $schema['options'] : $kept;
                break;

            case 'flag':
                $clean[$name] = is_bool($value) ? $value : (bool) $schema['default'];
                break;
        }
    }

    /*
     * Ein Bereich, der rückwärts läuft, kann keine Aufgabe bauen: jede Ziehung gäbe für
     * immer dieselbe Zahl zurück. Zahlen, die von irgendwoher kommen - ein älteres
     * Formular, eine von Hand geschriebene Anfrage, eine in phpMyAdmin geänderte Karte -,
     * werden hier richtig herum gedreht, eine Karte kann also nie auf einer Zahl
     * festhängen.
     */
    if (isset($clean['min'], $clean['max']) && (int) $clean['min'] > (int) $clean['max']) {
        $swap = $clean['min'];
        $clean['min'] = $clean['max'];
        $clean['max'] = $swap;
    }

    return $clean;
}

/**
 * Ob ein Satz von Parametern aus einer Anfrage so gespeichert werden darf, wie er ist.
 *
 * Das strenge Gegenstück zu exercise_normalise_params(): ein Name, den niemand kennt,
 * ein Wert außerhalb seiner Grenzen oder eine leere Liste werden hier abgelehnt,
 * gespeichert wird also immer genau das Gemeinte - und nie stillschweigend erst beim
 * Anzeigen der Karte zurechtgerückt.
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
 * Die Grenzen der Zahlenfelder einer Aufgabenart, für die Meldung, die der Dialog zeigt,
 * wenn jemand etwas außerhalb davon eintippt.
 *
 * @return array{lowest: int, highest: int}
 */

/* -------------------------------------------------------------------------
   Eine Aufgabe bauen
   ------------------------------------------------------------------------- */

/**
 * Die Aufgabenarten, die wirklich einen Erzeuger haben.
 *
 * Ein Schlüssel, der im Katalog steht, aber keinen Erzeuger hat, ist nicht benutzbar:
 * exercise_build_task() liefert dafür null, und die Karte wird als feste Karte gezeigt.
 * Das ist das Sicherheitsnetz für eine halb geschriebene Aufgabenart.
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

/* -------------------------------------------------------------------------
   Reading a cell of an imported file
   ------------------------------------------------------------------------- */

/**
 * Reads one exercise cell, the way an imported file writes it:
 *
 *   "<kind of task>"                          the defaults of that kind of task
 *   "<kind of task>:<name>=<value>,<name>=<value>"   with numbers of its own
 *
 * A parameter that allows several options at once is written with a pipe
 * (variants=mix|storage_level), a yes/no parameter takes yes or no. Everything is
 * checked with the same rules the card dialog is checked with, so a file can
 * never store numbers that no task can be built from.
 *
 * The syntax is here and nowhere else: the command line importer, the import in
 * the browser and the card dialog all end up in this function, so the same cell
 * means the same thing everywhere.
 *
 * "code" tells the caller WHICH mistake it was, so each interface can say it in
 * its own language; "error" is the English sentence the command line prints.
 *
 * @return array{exercise: array{type: string, params: array<string, mixed>}|null, error: string|null, code: string|null}
 */
function exercise_parse_cell(string $cell): array
{
    $cell = trim($cell);

    /* An empty cell means a fixed card. */
    if ($cell === '') {
        return ['exercise' => null, 'error' => null, 'code' => null];
    }

    $parts = explode(':', $cell, 2);
    $type = trim($parts[0]);
    $written = isset($parts[1]) ? trim($parts[1]) : '';

    if (!exercise_type_is_known($type)) {
        return [
            'exercise' => null,
            'error' => 'unknown kind of task "' . $type . '"',
            'code' => 'unknown_type',
        ];
    }

    if ($written === '') {
        return [
            'exercise' => ['type' => $type, 'params' => exercise_type_default_params($type)],
            'error' => null,
            'code' => null,
        ];
    }

    $catalogue = exercise_catalog()[$type]['params'];
    $params = [];

    foreach (explode(',', $written) as $pair) {
        $pair = trim($pair);

        if ($pair === '') {
            continue;
        }

        $halves = explode('=', $pair, 2);

        if (count($halves) !== 2) {
            return ['exercise' => null, 'error' => 'expected name=value, found "' . $pair . '"', 'code' => 'format'];
        }

        $name = trim($halves[0]);
        $value = trim($halves[1]);
        $schema = $catalogue[$name] ?? null;

        if ($schema === null) {
            return ['exercise' => null, 'error' => '"' . $name . '" is not a parameter of ' . $type, 'code' => 'unknown_param'];
        }

        if ($schema['kind'] === 'int') {
            if (!ctype_digit($value)) {
                return ['exercise' => null, 'error' => '"' . $name . '" must be a whole number, found "' . $value . '"', 'code' => 'number'];
            }

            $params[$name] = (int) $value;

            continue;
        }

        if ($schema['kind'] === 'select') {
            $params[$name] = $value;

            continue;
        }

        if ($schema['kind'] === 'multi') {
            $params[$name] = array_values(array_filter(
                array_map('trim', explode('|', $value)),
                static fn (string $one): bool => $one !== ''
            ));

            continue;
        }

        /* The remaining kind is a yes/no parameter. */
        $lower = mb_strtolower($value);

        if (!in_array($lower, ['yes', 'no', 'ja', 'nein', '1', '0', 'true', 'false'], true)) {
            return ['exercise' => null, 'error' => '"' . $name . '" must be yes or no, found "' . $value . '"', 'code' => 'number'];
        }

        $params[$name] = in_array($lower, ['yes', 'ja', '1', 'true'], true);
    }

    /*
     * A number outside its limits is pulled into them, the same way a stored card
     * is treated when it is read: a file is written by hand, so it may be a little
     * off without failing the whole import.
     */
    $params = exercise_normalise_params($type, $params);

    if (!exercise_params_are_valid($type, $params)) {
        return ['exercise' => null, 'error' => 'the numbers do not fit "' . $type . '"', 'code' => 'numbers'];
    }

    return ['exercise' => ['type' => $type, 'params' => $params], 'error' => null, 'code' => null];
}
