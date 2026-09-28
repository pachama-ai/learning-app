<?php

declare(strict_types=1);

/**
 * Die Aufgabenarten selbst: Grundrechnen, Algebra, Prozentrechnung.
 *
 * Eine Funktion je Aufgabenart. Jede zieht ihre Zahlen und rechnet ihre Antwort im
 * selben Schritt aus, die Antwort gehört also immer zu der Frage, die mit ihr gezogen
 * wurde. Von außen wird hier nichts gelesen: die Parameter kommen aus
 * exercise_normalise_params(), und das lässt nur die Schlüssel und Werte durch, die
 * exercise_catalog() erlaubt.
 *
 * Ganze Zahlen, wo eine Antwort genau sein muss. Wo ein Ergebnis keine kurze Zahl sein
 * kann (eine Wurzel, eine Standardabweichung), wird gerundet und mit dem Zeichen
 * geschrieben, das das sagt - "≈ 5,83".
 *
 * Jede Funktion liefert
 *
 *   ['question' => ['de' => …, 'en' => …], 'answer' => ['de' => …, 'en' => …]]
 *
 * Bei einer Aufgabe aus Zahlen und Rechenzeichen ist der Text in beiden Sprachen
 * derselbe, bei den Aufgaben mit Wortlaut sind es zwei Übersetzungen desselben Satzes.
 *
 * ===========================================================================
 * Wie eine Aufgabe zu ihrer Funktion kommt - diese Namen bitte nicht ändern
 * ===========================================================================
 *
 * Der Name einer Funktion in dieser Datei ist kein Name, sondern ein Schlüssel. In
 * src/services/exercise_service.php steht
 *
 *     $function = 'exercise_task_' . $type;
 *
 * und $type ist der Schlüssel der Aufgabenart, genau so, wie er im Katalog steht und
 * wie er in der Datenbankspalte exercise_params.type gespeichert ist. Aus "times_table"
 * wird also exercise_task_times_table, aus "standard_deviation"
 * exercise_task_standard_deviation. exercise_task_builders() setzt den Namen genauso
 * zusammen und fragt mit function_exists(), ob es diese Funktion gibt; benutzbar sind
 * nur die Arten, die diese Frage bestehen. Aufgerufen wird die Funktion danach über
 * die Variable - ($builders[$type])(...) - und nicht über ihren Namen.
 *
 * Darum ist ein Umbenennen gefährlich: der Name steht nirgendwo im Code, sondern wird
 * immer nur zusammengesetzt. Wird exercise_task_times_table zum Beispiel in
 * exercise_task_multiplication umbenannt, findet PHP die alte Funktion nicht mehr, die
 * Art fällt aus der Liste, exercise_build_task() liefert null - und die Karte wird
 * stillschweigend als feste Karte gezeigt. Kein Fehler, kein Eintrag im Protokoll,
 * nichts, was auffällt. Die Zeilen in der Datenbank zeigen weiter auf "times_table",
 * denn auch dort steht nur der Schlüssel. Ein Umbenennen wäre also keine
 * Umbenennung, sondern ein Abschalten der Aufgabenart - und deshalb bleiben die Namen,
 * wie sie sind.
 */


/** Die rechtwinkligen Dreiecke mit ganzzahligen Seiten, klein nach groß. */
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
 * Malnehmen: zwei ganze Zahlen aus dem Bereich.
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
 * Teilen als Umkehrung des Malnehmens.
 *
 * Teiler und Ergebnis werden gezogen, und die Zahl, die geteilt wird, ist ihr Produkt;
 * die Division geht also immer glatt auf. Mit `remainder` wird absichtlich ein Rest
 * dazugegeben, und dann steht die Antwort als Ergebnis mit Rest.
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
 * Zwei einfache Brüche, addiert, abgezogen, malgenommen oder geteilt.
 *
 * Die Antwort steht immer als kleinstmöglicher Bruch mit demselben Wert. Die Zähler
 * bleiben unter ihrem Nenner, ein Bruch ist also wirklich ein Bruch und keine ganze
 * Zahl im Gewand. Beim Abziehen steht der größere Bruch vorn, die Antwort ist also nie
 * negativ.
 */
function exercise_task_fraction(array $params): array
{
    $operation = (string) exercise_pick($params['operations']);
    $denominators = EXERCISE_FRACTION_DENOMINATORS;
    $firstDenominator = exercise_pick($denominators);

    if ($operation === 'add' || $operation === 'subtract') {
        /* Verschiedene Nenner sind das, was diese Aufgabe übenswert macht. */
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

    /* Beim Abziehen der größere Bruch zuerst: a − b mit a größer als b. */
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
            /* Durch einen Bruch teilen heißt, mit seinem Kehrwert malnehmen. */
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
 * Ein kurzer Ausdruck mit Klammern und mindestens einer negativen Zahl.
 *
 * Vier Muster, alle so geschrieben, wie sie in der Schule geschrieben werden. Die
 * Zahlen werden aus dem Bereich gezogen und die Antwort wird mit ganzen Zahlen
 * ausgerechnet, sie ist also immer genau. In den ersten beiden Mustern wird die Klammer
 * absichtlich negativ gemacht, damit in der Aufgabe auch wirklich eine negative Zahl
 * vorkommt.
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
 * Zehnerpotenzen und die wissenschaftliche Schreibweise.
 *
 *   power_of_ten      10³ = ?                     -> 1000
 *   to_scientific     4 500 000 wissenschaftlich  -> 4,5 · 10⁶
 *   from_scientific   4,5 · 10⁶ = ?                -> 4 500 000
 *
 * Die Mantisse behält eine Nachkommastelle und der Exponent ist eine ganze Zahl, der
 * Wert ist also genau: er entsteht aus ganzen Zehnteln und einer Zehnerpotenz, nie aus
 * einer gerundeten Kommazahl.
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

    /* Eine Mantisse von 1,1 bis 9,9 als ganze Zehntel: genau, nie 4,5000001. */
    $tenths = exercise_draw(11, 99);

    if ($tenths % 10 === 0) {
        $tenths++;
    }

    $value = ($tenths / 10) * (10 ** $exponent);
    $mantissa = exercise_number($tenths / 10, 1);
    $plain = exercise_number($value, 0);

    /*
     * Das ist eine der wenigen Aufgaben, in denen die Zahl selbst in den beiden
     * Sprachen anders geschrieben wird: 1,4 · 10³ hat im Deutschen ein Komma und im
     * Englischen einen Punkt. Frage und Antwort tragen diese Zahl beide, deshalb
     * entstehen beide je Sprache.
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
 * Eine lineare Gleichung oder eine der bekannten Formeln, nach einem Buchstaben
 * umgestellt.
 *
 *   equation  a · x + b = c  ->  x
 *   formula   E = P · t mit zwei der drei Werten  ->  der dritte
 *
 * Die Gleichung wird aus der Antwort gebaut, nie andersherum: die Antwort ist eine
 * ganze Zahl und die rechte Seite wird daraus gerechnet, sie passt also immer genau.
 * In den Formeln stehen die üblichen Zahlen (230 V, ganze Ampere, ganze Stunden), damit
 * auch dort die Antworten genau bleiben.
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

    /* Die Hälfte der Aufgaben addiert den Summanden, die andere Hälfte zieht ihn ab. */
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
 * Eine der drei Formeln E = P · t und P = U · I, mit einem fehlenden Buchstaben.
 *
 * Welcher Buchstabe gefragt ist, wird gezogen, die beiden anderen sind gegeben. Alle
 * drei Werte sind ganze Zahlen ihrer Einheit, die Antwort ist also genau.
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
 * Der Satz des Pythagoras: zwei Seiten eines rechtwinkligen Dreiecks sind gegeben,
 * die dritte ist gefragt.
 *
 * Meistens nimmt die Aufgabe ein Dreieck mit ganzzahligen Seiten (3-4-5, 6-8-10,
 * 5-12-13 …), die Antwort ist dann genau. Sonst werden die Seiten aus dem Bereich
 * gezogen und die Antwort ist eine Wurzel, die gerundet wird - geschrieben mit dem
 * Zeichen ≈, damit sie niemand für einen genauen Wert hält.
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
         * Die Hypotenuse und eine Kathete sind bekannt, die andere Kathete ist gefragt.
         * Passt kein ganzzahliges Dreieck in den Bereich, werden beide gegebenen Seiten
         * frei gezogen - die Hypotenuse als längere -, damit es wirklich die Seiten
         * desselben Dreiecks sind und nur die Antwort gerundet ist.
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
 * Prozentrechnung: zwei der drei Werte sind gegeben, der dritte ist gefragt.
 *
 * Die Grundzahl ist immer ein Vielfaches des Schrittes, bei dem der Prozentsatz ganz
 * aufgeht, keine Aufgabe endet also in einem Komma, das dort nicht hingehört.
 *
 *   value   150 · 20 % = ?          -> 30
 *   rate    wie viel Prozent sind 30 von 150?  -> 20 %
 *   base    20 % von ? sind 30      -> 150
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
        /* Die Grundzahl ist ein Vielfaches des Schrittes, sie teilt sich also ohne Rest. */
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
 * Prozentrechnung im Energie-Zusammenhang.
 *
 * Eine von sechs Situationen wird gezogen und danach die Zahlen dafür. Jede Situation
 * hat ihre eigenen plausiblen Größen: ein Anteil am Erzeugungsmix in GWh, eine
 * Photovoltaikanlage gegen die Leistung, die sie liefern könnte, der Eigenverbrauch in
 * kWh, der Ladezustand einer Batterie in MWh, Verluste im Netz oder die Änderung eines
 * Preises. Der Anteil ist immer ein ganzer Prozentsatz der Gesamtmenge, die Antwort ist
 * also genau.
 *
 * Frage und Antwort tragen die Einheit der Situation, der Satz und die Zahl gehören also
 * immer zusammen.
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
            /* Der Erzeugungsmix: eine Quelle gegen alles Erzeugte. */
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
 * Der Dreisatz: eine Menge und ihre Kosten oder eine Zahl von Geräten und ihr
 * Verbrauch, umgerechnet auf eine andere Menge.
 *
 * Der Preis eines Stücks (oder eines Geräts) wird zuerst gezogen und die Aufgabe daraus
 * gebaut, die Antwort ist also eine ganze Zahl und keine gerundete.
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
