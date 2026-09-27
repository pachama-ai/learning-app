<?php

declare(strict_types=1);

/**
 * Die Aufgabensorten selbst: Energie-Kennzahlen, Einheiten, Statistik.
 *
 * Eine Funktion je Aufgabensorte, genauso gebaut wie in exercise_tasks.php:
 * Zahlen werden gezogen und die Lösung im selben Schritt berechnet, jede Lösung
 * ist entweder exakt oder klar als gerundet gekennzeichnet, und die Parameter
 * können nur enthalten, was exercise_catalog() erlaubt.
 */

/**
 * Ein Wert, von einer Einheit in eine andere derselben Familie umgerechnet.
 *
 * Die Familien stehen als feste Listen in exercise_unit_families(), und jeder
 * Schritt innerhalb einer Familie ist eine Zehnerpotenz. Der Wert wird deshalb
 * als Vielfaches des Schritts gewählt, damit die Lösung in jede Richtung eine
 * ganze Zahl bleibt - keine Aufgabe endet bei 0,0000000001 kW.
 *
 * Zu den Kubikmetern: die Familie enthält m³ und den Liter, der genau ein
 * Tausendstel davon ist. Eine Umrechnung zwischen m³ und Nm³ fehlt absichtlich -
 * sie hängt von Druck und Temperatur ab, bräuchte also eine Bezugsgröße, die
 * diese Anwendung nicht hat, und eine erfundene würde die Aufgabe falsch machen.
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
        /* In eine kleinere Einheit: Multiplizieren ergibt immer eine ganze Zahl. */
        $value = exercise_draw_multiple($low, $high, intdiv($fromFactor, $toFactor));
        $result = $value * intdiv($fromFactor, $toFactor);
    } else {
        /* In eine größere Einheit: der Wert muss ein Vielfaches des Schritts sein. */
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
 * Eine der zwei Energieformeln mit einem fehlenden Buchstaben.
 *
 *   P = U · I    Spannung und Strom sind gegeben, die Leistung ist gesucht
 *   E = P · t    Leistung und Zeit sind gegeben, die Energie ist gesucht
 *
 * Alle drei Werte sind ganze Zahlen ihrer Einheit, die Lösung ist also exakt. Der
 * fehlende Buchstabe wird gezogen, und die Zahlen haben die Größen, die sie in
 * Wirklichkeit haben: Volt im Hunderterbereich, Ampere einstellig, Stunden
 * innerhalb eines Tages.
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
 * Der Wirkungsgrad: wie viel von dem, was hineinging, nutzbar herauskam.
 *
 * Ein Kraftwerk behält 35 bis 45 % seines Brennstoffs, ein Gerät 80 bis 95 %. Eine
 * der beiden Zahlen wird gezogen, danach ist einer der drei Werte gesucht. Die
 * hineingehende Energie wird als Vielfaches des Schritts gewählt, der den Nutzen
 * ganzzahlig macht - beide Lösungen sind also exakt.
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
 * Die Auslastung: wie viel von dem, was eine Anlage leisten könnte, sie wirklich
 * geleistet hat.
 *
 * Maximum und Prozentwert werden beide gezogen, die tatsächliche Erzeugung daraus
 * berechnet - egal welcher der drei Werte gesucht ist, die Lösung ist eine ganze
 * Zahl.
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
 * Volllaststunden und der Kapazitätsfaktor.
 *
 * Der Jahresertrag geteilt durch die Nennleistung ergibt die Volllaststunden: eine
 * Anlage mit 5 MW, die 8 000 MWh erzeugt hat, lief sozusagen 1 600 Stunden unter
 * Volllast. Das Verhältnis zu den 8 760 Stunden eines Jahres ist der
 * Kapazitätsfaktor, ein Prozentwert und meist ein kleiner - deshalb steht er mit
 * einer Nachkommastelle und dem ≈-Zeichen.
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
 * Eine Reihe Viertelstundenwerte: die Energie, die sie zusammen ergeben, oder ihr
 * Mittelwert.
 *
 * Eine Viertelstunde ist ein Viertel kWh je kW, die Energie ist also die Summe der
 * Werte geteilt durch vier - die Leistungswerte werden deshalb als Vielfache von
 * vier gezogen, damit das ganzzahlig bleibt. Der Mittelwert entsteht, indem die
 * Werte um ein gewähltes Mittel herum gezogen werden, ist also ebenfalls eine
 * ganze Zahl.
 *
 * Die erste Lösung ist in kWh, die zweite in kW - deshalb trägt die Aufgabensorte
 * mit, welche der beiden gefragt ist.
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
            /* In dem engen Bereich kein ganzzahliges Mittel gefunden: dann eben das echte. */
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
 * Eine von vier Kennzahlen zu einer kurzen Zahlenliste: Median, kleinster Wert,
 * größter Wert oder der Abstand zwischen beiden.
 *
 * Die Liste wird gezogen und so gezeigt, wie sie gezogen wurde - sie zu sortieren
 * gehört für den Leser zur Aufgabe. Der Median ist der mittlere Wert der
 * sortierten Liste (die Liste hat immer eine ungerade Anzahl, es gibt also genau
 * eine Mitte).
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
 * Der Mittelwert aus drei bis fünf Zahlen.
 *
 * Die Liste wird um ein ganzzahliges Mittel herum gebaut: der letzte Wert wird aus
 * den anderen berechnet, die Lösung ist also eine ganze Zahl. Ist der Bereich
 * dafür zu eng, wird der echte Mittelwert genommen und mit dem ≈-Zeichen gerundet.
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
 * Die Standardabweichung aus fünf bis acht Zahlen.
 *
 * Die übliche Formel, mit dem arithmetischen Mittel als Bezugspunkt: das Mittel
 * der quadrierten Abstände und daraus die Wurzel. Es ist die Abweichung der ganzen
 * Liste (geteilt durch die Anzahl der Werte), also die, die mit dieser Formel
 * gelehrt wird. Die Lösung wird auf zwei Nachkommastellen gerundet und mit dem
 * ≈-Zeichen geschrieben, außer sie ist zufällig ganzzahlig.
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
 * Eine kleine Wertetabelle und eine Frage dazu.
 *
 * Vier bis sechs Werte, benannt mit Buchstaben, aus dem Bereich gezogen. Gefragt
 * ist der größte Wert, der Unterschied zwischen zwei von ihnen oder der Anteil,
 * den einer am Ganzen hat. Für den Anteil ist die Summe ein Vielfaches des
 * Schritts, der den Prozentwert ganzzahlig macht - auch diese Lösung ist also
 * exakt.
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
         * Einer der Werte wird absichtlich zu einem glatten Prozentanteil am Ganzen
         * gemacht, damit die Lösung exakt ist. Der Wert wird zuerst gezogen, die
         * Summe folgt daraus; die anderen müssen dann zwischen kleinstem und größtem
         * Wert passen, und dafür ist der Versuch da. Ist der Bereich dafür zu eng,
         * wird stattdessen nach dem größten Wert gefragt - eine Aufgabe wird immer
         * gezeigt.
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
