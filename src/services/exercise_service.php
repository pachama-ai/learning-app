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
 * Baut eine Aufgabe dieser Art aus diesen Zahlen.
 *
 * Die Antwort gehört zu den Zahlen, die in genau diesem Aufruf gezogen wurden: Frage
 * und Antwort entstehen zusammen, sie gehören also immer zueinander, während ein späterer
 * Aufruf neue Zahlen zieht.
 *
 * Beide Sprachen reisen mit. Aufgaben aus Zahlen und Rechenzeichen lesen sich in beiden
 * Sprachen gleich; die mit einem Satz entstehen aus Übersetzungsschlüsseln, es
 * unterscheidet sich also nur der Wortlaut.
 *
 * Liefert null, wenn die Aufgabenart nicht gebaut werden kann.
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
   Zahlen ziehen und aufschreiben
   ------------------------------------------------------------------------- */

/** Eine ganze Zahl von low bis high, beide mitgezählt. */
function exercise_draw(int $low, int $high): int
{
    if ($high <= $low) {
        return $low;
    }

    return random_int($low, $high);
}

/**
 * Ein Eintrag aus einer festen Liste, nach Zufall gezogen.
 *
 * @param list<mixed> $values
 * @return mixed
 */
function exercise_pick(array $values)
{
    return $values[random_int(0, count($values) - 1)];
}

/**
 * Schreibt eine Zahl in den beiden Sprachen dieser Oberfläche.
 *
 * Der einzige Unterschied ist das Komma: Deutsch schreibt 1,5 und Englisch 1.5.
 * Tausender werden in beiden durch ein Leerzeichen getrennt, weil das lesbar ist und in
 * beiden dasselbe bedeutet.
 */
function exercise_number(float $value, int $decimals = 0): array
{
    return [
        'de' => exercise_decimal($value, $decimals, 'de'),
        'en' => exercise_decimal($value, $decimals, 'en'),
    ];
}

/** Eine Zahl in einer Sprache, ohne überflüssige Nullen am Ende. */
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
 * Eine Zahl, die gerundet werden musste, mit dem Zeichen, das das sagt.
 *
 * "≈ 5,83" statt "5,83": wer die Karte liest, soll sehen, dass der genaue Wert keine
 * kurze Zahl ist. Das Zeichen ist in beiden Sprachen dasselbe.
 */
function exercise_rounded(float $value, int $decimals = EXERCISE_DECIMALS): array
{
    return [
        'de' => '≈ ' . exercise_decimal($value, $decimals, 'de'),
        'en' => '≈ ' . exercise_decimal($value, $decimals, 'en'),
    ];
}

/**
 * Die zwei Texte von etwas, das sich auf Deutsch und auf Englisch gleich liest.
 *
 * @return array{de: string, en: string}
 */
function exercise_same(string $text): array
{
    return ['de' => $text, 'en' => $text];
}

/**
 * Die zwei Texte eines Satzes, der übersetzt werden muss.
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

/** Eine ganze Zahl mit hochgestellter Potenz: 10³, 10⁶. */
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
 * Ein Bruch, so klein geschrieben, wie er geht: 6/8 wird 3/4, 8/8 wird 1.
 *
 * Die Rechnung dahinter ist genau; nur das Aussehen wird hier entschieden. Ein Minuszeichen
 * steht vor dem Bruch, so wie er von Hand aufgeschrieben wird.
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

/** Die größte Zahl, die beide Zahlen ohne Rest teilt (Euklid). */
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

/** Die kleinste Zahl, die beide Zahlen ohne Rest teilen. */
function exercise_least_common_multiple(int $first, int $second): int
{
    if ($first === 0 || $second === 0) {
        return 0;
    }

    return abs(intdiv($first * $second, exercise_greatest_common_divisor($first, $second)));
}

/**
 * Eine Zahl zwischen low und high, die ein Vielfaches von step ist.
 *
 * Benutzt, wo eine Aufgabe genau aufgehen muss: eine Grundzahl, die ein Prozentsatz
 * glatt teilt, eine Liste, deren Mittelwert eine ganze Zahl ist, eine Reihe, deren Summe
 * ein Vielfaches von vier ist. Liegt kein Vielfaches im Bereich, wird das nächste darüber
 * genommen, damit ein enger Bereich trotzdem eine Aufgabe zeigt statt keiner.
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
 * Eine Liste ganzer Zahlen, eine nach der anderen, von low bis high.
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
 * Eine Liste von Zahlen, so aufgeschrieben, wie sie auf einer Karte steht: "3, 7, 11".
 *
 * @param list<int> $values
 */
function exercise_series_text(array $values): string
{
    return implode(', ', $values);
}

/* -------------------------------------------------------------------------
   Die festen Listen der Einheiten
   ------------------------------------------------------------------------- */

/**
 * Die Einheiten, die eine Umrechnungsaufgabe benutzen darf, nach dem gruppiert, was
 * sie messen.
 *
 * Jeder Eintrag ist eine Liste von der kleinsten Einheit aufwärts, zusammen mit der
 * Angabe, wie viele der kleinsten Einheit eine davon ist. Der Faktor zwischen zwei
 * Einheiten einer Familie ist der Quotient dieser Zahlen, und der ist immer eine
 * Zehnerpotenz - deshalb geht jede Umrechnung in diesen Familien genau auf.
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
        /* Wattstunden: wovon ein Zähler zählt und eine Rechnung geschrieben wird. */
        'wh' => ['Wh' => 1, 'kWh' => 1000, 'MWh' => 1000000, 'GWh' => 1000000000],
        /* Volumen. Der Liter ist genau ein Tausendstel eines Kubikmeters. */
        'volume' => ['l' => 1, 'm³' => 1000],
    ];
}

/* -------------------------------------------------------------------------
   Eine Zelle aus einer importierten Datei lesen
   ------------------------------------------------------------------------- */

/**
 * Liest eine Übungszelle, so wie eine importierte Datei sie schreibt:
 *
 *   "<Aufgabenart>"                          die Vorgaben dieser Aufgabenart
 *   "<Aufgabenart>:<name>=<wert>,<name>=<wert>"   mit eigenen Zahlen
 *
 * Ein Parameter, der mehrere Auswahlen auf einmal erlaubt, wird mit einem senkrechten
 * Strich geschrieben (variants=mix|storage_level), ein Ja/Nein-Parameter nimmt yes oder
 * no. Geprüft wird alles mit denselben Regeln, mit denen der Kartendialog geprüft wird,
 * eine Datei kann also nie Zahlen speichern, aus denen keine Aufgabe zu bauen ist.
 *
 * Die Schreibweise steht hier und nirgends sonst: der Import auf der Kommandozeile, der
 * Import im Browser und der Kartendialog landen alle in dieser Funktion, dieselbe Zelle
 * bedeutet also überall dasselbe.
 *
 * "code" sagt dem Aufrufer, WELCHER Fehler es war, damit jede Oberfläche ihn in ihrer
 * eigenen Sprache sagen kann; "error" ist der englische Satz, den die Kommandozeile
 * ausgibt.
 *
 * @return array{exercise: array{type: string, params: array<string, mixed>}|null, error: string|null, code: string|null}
 */
function exercise_parse_cell(string $cell): array
{
    $cell = trim($cell);

    /* Eine leere Zelle heißt eine feste Karte. */
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

        /* Die übrig bleibende Art ist ein Ja/Nein-Parameter. */
        $lower = mb_strtolower($value);

        if (!in_array($lower, ['yes', 'no', 'ja', 'nein', '1', '0', 'true', 'false'], true)) {
            return ['exercise' => null, 'error' => '"' . $name . '" must be yes or no, found "' . $value . '"', 'code' => 'number'];
        }

        $params[$name] = in_array($lower, ['yes', 'ja', '1', 'true'], true);
    }

    /*
     * Eine Zahl außerhalb ihrer Grenzen wird in sie hineingezogen, genauso wie eine
     * gespeicherte Karte beim Lesen behandelt wird: eine Datei wird von Hand geschrieben,
     * sie darf also ruhig etwas danebenliegen, ohne den ganzen Import scheitern zu lassen.
     */
    $params = exercise_normalise_params($type, $params);

    if (!exercise_params_are_valid($type, $params)) {
        return ['exercise' => null, 'error' => 'the numbers do not fit "' . $type . '"', 'code' => 'numbers'];
    }

    return ['exercise' => ['type' => $type, 'params' => $params], 'error' => null, 'code' => null];
}
