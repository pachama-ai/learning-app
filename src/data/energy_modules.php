<?php

declare(strict_types=1);

/**
 * Die Inhalte der Nachschlageseite "Energie verstehen".
 *
 * Diese Datei ist reine Beschreibung und hat keine Logik: sie gibt eine Liste von
 * Wissensmodulen zurück, aus der die Seite ihre Abschnitte baut. Ein neuer Begriff ist
 * damit eine neue Zeile hier - und keine Änderung an der Vorlage, am Blatt oder am
 * Skript.
 *
 * Aufbau eines Moduls
 *
 *   id        eindeutig und stabil. Sie steht in der Adresse (#ohms_law) und in `related`.
 *   group     einer der Themenbereiche aus energy_group_order()
 *   topic     die kurze Bezeichnung dieses Moduls im zweiten Auswahlfeld der Filterleiste
 *   title     die Überschrift
 *   terms     Suchbegriffe: deutsche und englische Wörter, auch Synonyme. Gesucht wird
 *             immer über BEIDE Sprachen, "voltage" trifft also auch in der deutschen Ansicht.
 *   symbols   Formelzeichen (U, R) - suchbar wie ein Wort
 *   units     Einheiten (V, Ω) - suchbar wie ein Wort
 *   summary   die kurze Erklärung, zwei bis vier Sätze
 *   detail    die Vertiefung, sichtbar erst beim Aufklappen. Eine Zeile je Absatz.
 *   formulas  die Formeln des Moduls, siehe unten
 *   visual    die Zeichnung des Moduls, siehe unten
 *   related   Ids verwandter Module
 *
 * Jeder sichtbare Text steht zweisprachig da: ['de' => '...', 'en' => '...']. Die Formel
 * selbst (latex) ist sprachunabhängig, weil Mathematik keine Sprache hat.
 *
 * Eine Formel
 *
 *   latex      der Satz für den Formelsatz (KaTeX)
 *   variables  Formelzeichen, Bedeutung und Einheit, Zeile für Zeile
 *   valid      unter welcher Voraussetzung die Formel gilt. Sie steht bei JEDER Formel:
 *              eine Formel ohne ihre Bedingung wird falsch verstanden.
 *   example    ein kurzes Zahlenbeispiel
 *
 * Eine Zeichnung
 *
 *   kind       welcher Baustein aus public/assets/js/energy_visuals.js sie malt
 *   labels     die Wörter, die IN der Zeichnung stehen. Sie gehören hierher und nicht in
 *              das Skript, sonst hätte ein neues Modul wieder Text im Code.
 *   a11y       was ein Vorleseprogramm statt der Zeichnung sagt
 *   controls   die Regler, mit Bereich, Schrittweite, Startwert, Einheit und Beschriftung
 *   fixed      Werte, die in dieser Zeichnung fest sind und keinen Regler haben
 *   caution    die Modellannahme unter der Zeichnung
 *   animated   true, wenn sich in der Zeichnung etwas bewegt. Nur dann zeigt die Seite
 *              Abspielen, Pause und Zurücksetzen dazu.
 *
 * Physikalische Sorgfalt
 *
 * Jede Zeichnung, die Elektronen zeigt, ist ein Modell und sagt das auch (caution). Die
 * Elektronen wandern absichtlich langsam: die Energie kommt am Ende eines Leiters fast
 * sofort an, weil sich das elektrische Feld ausbreitet - nicht, weil Elektronen schnell
 * wären. Die Zahlen in den Beispielen sind mit echten Werten gerechnet, nicht geraten.
 */

/**
 * Die Themenbereiche der Seite, in der Reihenfolge, in der die Filterleiste sie zeigt.
 *
 * Die Beschriftungen stehen in src/helpers/translations.php: 'energy.group.<id>'. Hier
 * steht nur die Reihenfolge - und energy_groups_of() lässt weg, wozu es noch kein Modul
 * gibt, damit kein Filter ins Leere führt.
 *
 * @return list<string>
 */
function energy_group_order(): array
{
    return ['basics', 'energy', 'magnetism', 'generation', 'transformer', 'grid', 'consumers'];
}

/**
 * Die Themen, zu denen es wirklich ein Modul gibt, in der Reihenfolge von oben.
 *
 * @param list<array<string, mixed>> $modules
 * @return list<string>
 */
function energy_groups_of(array $modules): array
{
    $present = [];

    foreach ($modules as $module) {
        $present[(string) $module['group']] = true;
    }

    $groups = [];

    foreach (energy_group_order() as $group) {
        if (isset($present[$group])) {
            $groups[] = $group;
        }
    }

    return $groups;
}

/**
 * Alle Wissensmodule der Nachschlageseite.
 *
 * @return list<array<string, mixed>>
 */
function energy_modules(): array
{
    return [
        /* ------------------------------------------------------------------
           Grundlagen: Ladung
           ------------------------------------------------------------------ */
        [
            'id' => 'charge',
            'group' => 'basics',
            'topic' => [
                'de' => 'Ladung und Elektronen',
                'en' => 'Charge and electrons',
            ],
            'title' => [
                'de' => 'Elektrische Ladung und Elektronen',
                'en' => 'Electric charge and electrons',
            ],
            'terms' => [
                'de' => ['Ladung', 'Elektron', 'Elementarladung', 'Coulomb', 'Atom', 'Reibungselektrizität'],
                'en' => ['charge', 'electron', 'elementary charge', 'coulomb', 'atom'],
            ],
            'symbols' => ['Q', 'e', 'n'],
            'units' => ['C', 'As'],
            'summary' => [
                'de' => 'Jeder Stoff besteht aus Atomen. Im Kern sitzen positiv geladene Protonen, '
                    . 'in der Hülle die negativ geladenen Elektronen. Die Ladung eines Elektrons ist '
                    . 'immer genau gleich groß und lässt sich nicht teilen.',
                'en' => 'Every material is made of atoms. The nucleus carries positive protons, the '
                    . 'shell carries negative electrons. The charge of one electron is always exactly '
                    . 'the same size and cannot be split.',
            ],
            'detail' => [
                'de' => [
                    'Ladung gibt es nur in ganzen Vielfachen der Elementarladung e = 1,602 · 10⁻¹⁹ C. '
                        . 'Ein halbes Elektron gibt es nicht, und deshalb ist jede gemessene Ladung ein '
                        . 'ganzzahliges Vielfaches dieser winzigen Zahl.',
                    'Gleichnamige Ladungen stoßen sich ab, ungleichnamige ziehen sich an. Diese Kraft ist '
                        . 'die Ursache von Spannung, Strom und Feld - sie ist der Anfang der ganzen Kette.',
                    'In einem Metall gehören die äußeren Elektronen keinem einzelnen Atom mehr. Sie sind '
                        . 'frei beweglich und füllen den Leiter wie ein Gas. Genau deshalb leitet Metall '
                        . 'und Kunststoff nicht.',
                    'Reibt man zwei Stoffe aneinander, wandern einige dieser Elektronen von einem zum '
                        . 'anderen. Beide werden geladen: der eine positiv, weil ihm Elektronen fehlen, '
                        . 'der andere negativ, weil er welche dazubekommen hat.',
                    'Ein Körper wird positiv geladen, indem man ihm Elektronen wegnimmt - nicht, indem man '
                        . 'positive Ladung hinzufügt. Positive Ladung entsteht in der Praxis fast immer '
                        . 'durch ein fehlendes Elektron.',
                ],
                'en' => [
                    'Charge exists only in whole multiples of the elementary charge e = 1.602 × 10⁻¹⁹ C. '
                        . 'There is no half electron, so every measured charge is a whole-number multiple '
                        . 'of this tiny value.',
                    'Like charges repel, unlike charges attract. This force is the cause of voltage, '
                        . 'current and field - it is where the whole chain starts.',
                    'In a metal the outer electrons no longer belong to a single atom. They are free to '
                        . 'move and fill the conductor like a gas. That is exactly why metal conducts and '
                        . 'plastic does not.',
                    'Rub two materials together and some of these electrons move from one to the other. '
                        . 'Both become charged: one positive because it is missing electrons, the other '
                        . 'negative because it gained some.',
                    'A body becomes positively charged by taking electrons away from it - not by adding '
                        . 'positive charge. In practice, positive charge almost always means a missing '
                        . 'electron.',
                ],
            ],
            'formulas' => [
                [
                    'latex' => 'Q = n \cdot e',
                    'variables' => [
                        ['symbol' => 'Q', 'unit' => 'C', 'meaning' => ['de' => 'Ladung', 'en' => 'charge']],
                        ['symbol' => 'n', 'unit' => '1', 'meaning' => ['de' => 'Anzahl der Elektronen', 'en' => 'number of electrons']],
                        ['symbol' => 'e', 'unit' => 'C', 'meaning' => ['de' => 'Elementarladung, 1,602 · 10⁻¹⁹ C', 'en' => 'elementary charge, 1.602 × 10⁻¹⁹ C']],
                    ],
                    'valid' => [
                        'de' => 'wenn man die Ladung in Vielfachen der Elementarladung zählt, also bei '
                            . 'Elektronen, Protonen und Ionen.',
                        'en' => 'when charge is counted in multiples of the elementary charge, that is for '
                            . 'electrons, protons and ions.',
                    ],
                    'example' => [
                        'de' => 'Fünf überschüssige Elektronen tragen Q = 5 · 1,602 · 10⁻¹⁹ C = '
                            . '8,01 · 10⁻¹⁹ C. Zum Vergleich: Ein Blitz transportiert rund 5 C, '
                            . 'das sind etwa 3 · 10¹⁹ Elektronen.',
                        'en' => 'Five extra electrons carry Q = 5 × 1.602 × 10⁻¹⁹ C = 8.01 × 10⁻¹⁹ C. '
                            . 'For comparison: a lightning strike moves about 5 C, which is roughly '
                            . '3 × 10¹⁹ electrons.',
                    ],
                ],
            ],
            'visual' => [
                'kind' => 'charge_model',
                'labels' => [
                    'body' => ['de' => 'Metallkörper', 'en' => 'metal body'],
                    'electrons' => ['de' => 'Elektronen', 'en' => 'electrons'],
                    'charge' => ['de' => 'Ladung', 'en' => 'charge'],
                    'neutral' => ['de' => 'neutral', 'en' => 'neutral'],
                    'positive' => ['de' => 'positiv geladen - es fehlen Elektronen', 'en' => 'positively charged - electrons are missing'],
                    'negative' => ['de' => 'negativ geladen - Elektronen sind dazugekommen', 'en' => 'negatively charged - electrons have been added'],
                ],
                'a11y' => [
                    'de' => 'Ein Metallkörper mit Elektronen. Ein Regler verschiebt die Anzahl der '
                        . 'Elektronen gegenüber dem neutralen Zustand und zeigt die daraus folgende Ladung.',
                    'en' => 'A metal body with electrons. A slider shifts the number of electrons '
                        . 'relative to the neutral state and shows the resulting charge.',
                ],
                'controls' => [
                    [
                        'id' => 'excess',
                        'min' => -4,
                        'max' => 4,
                        'step' => 1,
                        'value' => 0,
                        'unit' => 'e',
                        'label' => ['de' => 'Elektronen gegenüber neutral', 'en' => 'electrons compared with neutral'],
                    ],
                ],
                'caution' => [
                    'de' => 'Ein Elektron ist hier ein Kreis. In Wirklichkeit hat es keine '
                        . 'anschauliche Größe, und die Abstände im Bild sind millionenfach zu groß.',
                    'en' => 'An electron is drawn as a circle here. In reality it has no size you '
                        . 'could picture, and the distances in the picture are a million times too large.',
                ],
            ],
            'related' => ['voltage', 'current'],
        ],

        /* ------------------------------------------------------------------
           Grundlagen: Elektrisches Feld
           ------------------------------------------------------------------ */
        [
            'id' => 'electric_field',
            'group' => 'basics',
            'topic' => [
                'de' => 'Elektrisches Feld',
                'en' => 'Electric field',
            ],
            'title' => [
                'de' => 'Elektrisches Feld',
                'en' => 'Electric field',
            ],
            'terms' => [
                'de' => ['Feld', 'Feldlinie', 'Feldstärke', 'Ladung', 'anziehen', 'abstoßen', 'Plattenkondensator'],
                'en' => ['field', 'field line', 'field strength', 'attract', 'repel', 'plate capacitor'],
            ],
            'symbols' => ['E', 'Q', 'U', 'd'],
            'units' => ['V/m', 'N/C'],
            'summary' => [
                'de' => 'Um jede Ladung herum gibt es ein elektrisches Feld. Es beschreibt, welche '
                    . 'Kraft auf eine zweite Ladung wirken würde - auch dann, wenn gerade keine da '
                    . 'ist. Die Feldlinien zeigen die Richtung: sie laufen von der positiven zur '
                    . 'negativen Ladung.',
                'en' => 'Every charge has an electric field around it. It describes what force a second '
                    . 'charge would feel - even when there is none. The field lines show the direction: '
                    . 'they run from the positive to the negative charge.',
            ],
            'detail' => [
                'de' => [
                    'Ein Feld ist keine Materie, sondern eine Beschreibung: für jeden Punkt im Raum '
                        . 'steht fest, wie stark die Kraft dort wäre und in welche Richtung sie zeigt.',
                    'Ungleichnamige Ladungen ziehen sich an, gleichnamige stoßen sich ab. Genau das '
                        . 'zeichnen die Feldlinien: sie beginnen bei der positiven Ladung und enden bei '
                        . 'der negativen.',
                    'Wo die Linien dicht beieinander liegen, ist das Feld stark; wo sie auseinanderlaufen, '
                        . 'ist es schwach. Die Dichte der Linien ist also kein Zufall, sondern die '
                        . 'Aussage des Bildes.',
                    'Zwischen zwei geladenen Metallplatten sind die Linien parallel und gleichmäßig '
                        . 'verteilt - dort ist das Feld überall gleich stark. Man nennt das einen '
                        . 'Plattenkondensator, und nur dort gilt E = U / d so einfach.',
                    'Das elektrische Feld und das Magnetfeld sind verwandt, aber nicht dasselbe: Feldlinien '
                        . 'des Magnetfelds laufen immer im Kreis und enden nirgends.',
                ],
                'en' => [
                    'A field is not matter, it is a description: for every point in space it says how '
                        . 'strong the force would be there and which way it points.',
                    'Unlike charges attract, like charges repel. That is exactly what the field lines '
                        . 'draw: they start at the positive charge and end at the negative one.',
                    'Where the lines are close together the field is strong; where they spread apart it '
                        . 'is weak. So the density of the lines is not decoration, it is the message.',
                    'Between two charged metal plates the lines are parallel and evenly spaced - the '
                        . 'field is the same everywhere there. That is a plate capacitor, and only there '
                        . 'does E = U / d hold that simply.',
                    'The electric field and the magnetic field are related but not the same: magnetic '
                        . 'field lines always run in closed loops and never end.',
                ],
            ],
            'formulas' => [
                [
                    'latex' => 'E = \\frac{U}{d}',
                    'variables' => [
                        ['symbol' => 'E', 'unit' => 'V/m', 'meaning' => ['de' => 'Feldstärke', 'en' => 'field strength']],
                        ['symbol' => 'U', 'unit' => 'V', 'meaning' => ['de' => 'Spannung zwischen den Platten', 'en' => 'voltage between the plates']],
                        ['symbol' => 'd', 'unit' => 'm', 'meaning' => ['de' => 'Abstand der Platten', 'en' => 'distance between the plates']],
                    ],
                    'valid' => [
                        'de' => 'zwischen zwei parallelen Platten, also dort, wo das Feld überall gleich '
                            . 'stark ist. Bei einer einzelnen Ladung nimmt es mit dem Abstand ab.',
                        'en' => 'between two parallel plates, that is where the field is the same '
                            . 'everywhere. Around a single charge it falls off with distance.',
                    ],
                    'example' => [
                        'de' => '230 V an zwei Platten mit 1 cm Abstand ergeben E = 230 V / 0,01 m = '
                            . '23 000 V/m.',
                        'en' => '230 V across two plates 1 cm apart give E = 230 V / 0.01 m = 23 000 V/m.',
                    ],
                ],
            ],
            'visual' => [
                'kind' => 'electric_field_lines',
                'labels' => [
                    'positive' => ['de' => 'positive Ladung', 'en' => 'positive charge'],
                    'negative' => ['de' => 'negative Ladung', 'en' => 'negative charge'],
                    'fieldLine' => ['de' => 'Feldlinie', 'en' => 'field line'],
                    'neutral' => ['de' => 'hier hebt sich das Feld auf', 'en' => 'the field cancels out here'],
                ],
                'a11y' => [
                    'de' => 'Zwei Ladungen mit ihren Feldlinien. Ein Schalter wählt gleichnamige oder '
                        . 'ungleichnamige Ladungen, ein Regler ihre Stärke.',
                    'en' => 'Two charges with their field lines. A switch selects like or unlike charges, '
                        . 'a slider sets their strength.',
                ],
                'controls' => [
                    [
                        'id' => 'strength',
                        'min' => 0.5,
                        'max' => 3,
                        'step' => 0.5,
                        'value' => 1.5,
                        'unit' => 'e',
                        'label' => ['de' => 'Ladung', 'en' => 'charge'],
                    ],
                    [
                        'id' => 'pair',
                        'kind' => 'select',
                        'value' => 'unlike',
                        'label' => ['de' => 'Ladungspaar', 'en' => 'pair of charges'],
                        'options' => [
                            ['value' => 'unlike', 'label' => ['de' => 'ungleichnamig', 'en' => 'unlike']],
                            ['value' => 'like', 'label' => ['de' => 'gleichnamig', 'en' => 'like']],
                        ],
                    ],
                ],
                'caution' => [
                    'de' => 'Die Linien sind ein Modell: sie zeigen die Richtung der Kraft, sind aber '
                        . 'nicht selbst etwas, das man messen könnte. Gezeichnet sind wenige Linien - '
                        . 'in Wirklichkeit ist das Feld zwischen ihnen überall vorhanden.',
                    'en' => 'The lines are a model: they show the direction of the force, but they are '
                        . 'not something you could measure. Only a few are drawn - in reality the field '
                        . 'exists everywhere between them.',
                ],
            ],
            'related' => ['charge', 'voltage', 'magnetic_field'],
        ],

        /* ------------------------------------------------------------------
           Grundlagen: Spannung
           ------------------------------------------------------------------ */
        [
            'id' => 'voltage',
            'group' => 'basics',
            'topic' => [
                'de' => 'Spannung und Spannungsquelle',
                'en' => 'Voltage and voltage source',
            ],
            'title' => [
                'de' => 'Spannung und Spannungsquelle',
                'en' => 'Voltage and voltage source',
            ],
            'terms' => [
                'de' => ['Spannung', 'Spannungsquelle', 'Potentialdifferenz', 'Volt', 'Batterie', 'Potential'],
                'en' => ['voltage', 'potential difference', 'volt', 'battery', 'potential'],
            ],
            'symbols' => ['U'],
            'units' => ['V'],
            'summary' => [
                'de' => 'Spannung ist die Energie, die eine Ladung auf ihrem Weg durch die Quelle '
                    . 'bekommt - gerechnet pro Ladung. Sie ist kein Vorrat, sondern immer ein '
                    . 'Unterschied zwischen zwei Punkten. Eine Spannungsquelle hält diesen Unterschied '
                    . 'aufrecht, indem sie in ihrem Inneren ständig Ladung trennt.',
                'en' => 'Voltage is the energy a charge receives on its way through the source - counted '
                    . 'per unit of charge. It is not a stock of anything, it is always a difference '
                    . 'between two points. A voltage source keeps this difference up by separating '
                    . 'charge inside itself again and again.',
            ],
            'detail' => [
                'de' => [
                    'Ein einzelner Punkt hat keine Spannung. Erst der Vergleich zweier Punkte ergibt eine '
                        . 'Spannung - so wie ein einzelner Höhenwert keine Höhendifferenz ist.',
                    'Ohne geschlossenen Stromkreis und ohne Verbraucher bricht die Spannung der Quelle '
                        . 'nicht zusammen: sie liegt an. Erst wenn ein Verbraucher da ist, fließt auch Strom.',
                    'Eine Batterie liefert Gleichspannung, ihr Pluspol bleibt Pluspol. Aus der Steckdose '
                        . 'kommt Wechselspannung: die Polarität wechselt 50-mal in der Sekunde.',
                    'Die Spannung ist kein Maß für die Energie, die insgesamt umgesetzt wird - dafür '
                        . 'braucht man zusätzlich die geflossene Ladung.',
                ],
                'en' => [
                    'A single point has no voltage. Only the comparison of two points gives a voltage - '
                        . 'just as a single height value is not a height difference.',
                    'Without a closed circuit and without a load the source keeps its voltage: it is '
                        . 'simply there. Current only flows once a load is connected.',
                    'A battery delivers direct voltage, its positive pole stays positive. The socket '
                        . 'delivers alternating voltage: the polarity changes 50 times per second.',
                    'Voltage is not a measure of how much energy is used in total - for that you also '
                        . 'need the charge that flowed.',
                ],
            ],
            'formulas' => [
                [
                    'latex' => 'U = \frac{W}{Q}',
                    'variables' => [
                        ['symbol' => 'U', 'unit' => 'V', 'meaning' => ['de' => 'Spannung', 'en' => 'voltage']],
                        ['symbol' => 'W', 'unit' => 'J', 'meaning' => ['de' => 'umgesetzte Energie', 'en' => 'energy converted']],
                        ['symbol' => 'Q', 'unit' => 'C', 'meaning' => ['de' => 'geflossene Ladung', 'en' => 'charge that flowed']],
                    ],
                    'valid' => [
                        'de' => 'für die Definition der Spannung: sie beschreibt, wie viel Energie auf '
                            . 'jede Ladung entfällt.',
                        'en' => 'as the definition of voltage: it describes how much energy falls on each '
                            . 'unit of charge.',
                    ],
                    'example' => [
                        'de' => 'Wenn 2 C Ladung durch eine Quelle mit 12 V laufen, bekommen sie zusammen '
                            . 'W = U · Q = 12 V · 2 C = 24 J Energie.',
                        'en' => 'If 2 C of charge pass through a 12 V source, together they receive '
                            . 'W = U × Q = 12 V × 2 C = 24 J of energy.',
                    ],
                ],
            ],
            'visual' => [
                'kind' => 'voltage_potential',
                'labels' => [
                    'high' => ['de' => 'Pluspol', 'en' => 'positive terminal'],
                    'low' => ['de' => 'Minuspol', 'en' => 'negative terminal'],
                    'potential' => ['de' => 'Energie pro Ladung', 'en' => 'energy per charge'],
                    'difference' => ['de' => 'Spannung = Unterschied', 'en' => 'voltage = difference'],
                    'none' => ['de' => 'Beide Punkte sind gleich - also keine Spannung.', 'en' => 'Both points are equal - so there is no voltage.'],
                ],
                'a11y' => [
                    'de' => 'Zwei unterschiedlich hohe Balken stehen für zwei Punkte im Stromkreis. Die '
                        . 'Höhe ihres Unterschieds ist die Spannung.',
                    'en' => 'Two bars of different height stand for two points in the circuit. The size '
                        . 'of their difference is the voltage.',
                ],
                'controls' => [
                    [
                        'id' => 'voltage',
                        'min' => 0,
                        'max' => 24,
                        'step' => 0.5,
                        'value' => 6,
                        'unit' => 'V',
                        'label' => ['de' => 'Spannung U', 'en' => 'voltage U'],
                    ],
                ],
                'caution' => [
                    'de' => 'Die Balkenhöhe steht für die Energie pro Ladung an diesem Punkt, '
                        . 'nicht für eine Menge an Ladung.',
                    'en' => 'The height of a bar stands for the energy per charge at that point, '
                        . 'not for an amount of charge.',
                ],
            ],
            'related' => ['charge', 'current', 'ohms_law'],
        ],

        /* ------------------------------------------------------------------
           Grundlagen: Stromstärke
           ------------------------------------------------------------------ */
        [
            'id' => 'current',
            'group' => 'basics',
            'topic' => [
                'de' => 'Stromstärke',
                'en' => 'Current',
            ],
            'title' => [
                'de' => 'Stromstärke',
                'en' => 'Electric current',
            ],
            'terms' => [
                'de' => ['Stromstärke', 'Strom', 'Ampere', 'Elektronenstrom', 'Ladung pro Sekunde', 'Stromfluss'],
                'en' => ['current', 'ampere', 'flow of charge', 'electron flow'],
            ],
            'symbols' => ['I', 'Q', 't'],
            'units' => ['A', 'C', 's'],
            'summary' => [
                'de' => 'Die Stromstärke sagt, wie viel Ladung in einer Sekunde durch einen '
                    . 'Leiterquerschnitt geht. Ein Ampere bedeutet ein Coulomb pro Sekunde. Der Strom '
                    . 'beginnt praktisch sofort, sobald der Kreis geschlossen ist - nicht weil die '
                    . 'Elektronen schnell wären, sondern weil sich das elektrische Feld sehr schnell '
                    . 'im Leiter ausbreitet.',
                'en' => 'Current tells how much charge passes a cross-section of a conductor in one '
                    . 'second. One ampere means one coulomb per second. Current starts almost at once '
                    . 'when the circuit is closed - not because the electrons are fast, but because the '
                    . 'electric field spreads very quickly through the conductor.',
            ],
            'detail' => [
                'de' => [
                    'Die Elektronen selbst kommen nur langsam voran: in einem Kupferdraht mit 1 mm² '
                        . 'Querschnitt wandert ein einzelnes Elektron bei 1 A etwa 0,07 mm in der Sekunde. '
                        . 'Für einen Meter bräuchte es also rund vier Stunden.',
                    'Trotzdem ist das Licht sofort an. Der Grund: Das elektrische Feld durchläuft den '
                        . 'Leiter fast mit Lichtgeschwindigkeit und schiebt dabei alle Elektronen '
                        . 'gleichzeitig ein Stück weiter. Am anderen Ende kommt deshalb in Bruchteilen '
                        . 'einer Sekunde Ladung an.',
                    'Damit wandert die Energie nicht mit den Elektronen von der Quelle zum Verbraucher. '
                        . 'Sie steckt im Feld und wird am Verbraucher umgesetzt - die Elektronen sind nur '
                        . 'das Transportmittel für die Ladung.',
                    'Bei 1 A fließen in jeder Sekunde rund 6,2 · 10¹⁸ Elektronen durch den Querschnitt. '
                        . 'Diese Zahl kann niemand zählen, und deshalb rechnet man mit der Ladung in '
                        . 'Coulomb und nicht mit einzelnen Elektronen.',
                    'In einem Metall bewegen sich die Elektronen von Minus nach Plus. Die technische '
                        . 'Stromrichtung zeigt dagegen von Plus nach Minus - eine Festlegung aus einer '
                        . 'Zeit, in der man die Elektronen noch nicht kannte. Sie ist bis heute gültig.',
                ],
                'en' => [
                    'The electrons themselves move slowly: in a copper wire of 1 mm² at 1 A, a single '
                        . 'electron drifts about 0.07 mm per second. It would need roughly four hours '
                        . 'for one metre.',
                    'The lamp still lights up at once. The reason: the electric field runs through the '
                        . 'conductor at nearly the speed of light and pushes all electrons forward a '
                        . 'little at the same time. Charge therefore arrives at the other end within a '
                        . 'fraction of a second.',
                    'So the energy does not travel from source to load with the electrons. It sits in '
                        . 'the field and is converted at the load - the electrons are only the means of '
                        . 'transport for the charge.',
                    'At 1 A, roughly 6.2 × 10¹⁸ electrons pass the cross-section every second. Nobody '
                        . 'can count that number, which is why we calculate with charge in coulombs and '
                        . 'not with single electrons.',
                    'In a metal the electrons move from minus to plus. The technical direction of '
                        . 'current is drawn from plus to minus - a convention from a time when the '
                        . 'electron was not yet known. It is still valid today.',
                ],
            ],
            'formulas' => [
                [
                    'latex' => 'I = \frac{Q}{t}',
                    'variables' => [
                        ['symbol' => 'I', 'unit' => 'A', 'meaning' => ['de' => 'Stromstärke', 'en' => 'current']],
                        ['symbol' => 'Q', 'unit' => 'C', 'meaning' => ['de' => 'geflossene Ladung', 'en' => 'charge that flowed']],
                        ['symbol' => 't', 'unit' => 's', 'meaning' => ['de' => 'dafür benötigte Zeit', 'en' => 'time it took']],
                    ],
                    'valid' => [
                        'de' => 'so wie sie dasteht für einen gleichbleibenden Strom. Bei Wechselstrom '
                            . 'oder beim Einschalten beschreibt sie den Mittelwert bzw. den Momentanwert.',
                        'en' => 'as written for a steady current. For alternating current or at switch-on '
                            . 'it describes the average or the instantaneous value.',
                    ],
                    'example' => [
                        'de' => 'Fließen in 4 s genau 4 C durch den Leiter, ist I = 4 C / 4 s = 1 A.',
                        'en' => 'If exactly 4 C pass the conductor in 4 s, then I = 4 C / 4 s = 1 A.',
                    ],
                ],
            ],
            'visual' => [
                'kind' => 'current_drift',
                'labels' => [
                    'wire' => ['de' => 'Leiter', 'en' => 'conductor'],
                    'section' => ['de' => 'Querschnitt', 'en' => 'cross-section'],
                    'electrons' => ['de' => 'Elektronen', 'en' => 'electrons'],
                    'perSecond' => ['de' => 'Ladung pro Sekunde', 'en' => 'charge per second'],
                    'counted' => ['de' => 'durch den Querschnitt gezählt', 'en' => 'counted through the cross-section'],
                    'note' => ['de' => 'Das Feld ist fast sofort da, die Elektronen nicht.', 'en' => 'The field arrives almost at once, the electrons do not.'],
                ],
                'a11y' => [
                    'de' => 'Ein waagerechter Leiter. Punkte stehen für Elektronen und wandern langsam '
                        . 'nach rechts. Ein Zähler zeigt, wie viel Ladung in einer Sekunde den '
                        . 'markierten Querschnitt passiert.',
                    'en' => 'A horizontal conductor. Dots stand for electrons and drift slowly to the '
                        . 'right. A counter shows how much charge passes the marked cross-section in '
                        . 'one second.',
                ],
                'controls' => [
                    [
                        'id' => 'current',
                        'min' => 0.2,
                        'max' => 5,
                        'step' => 0.1,
                        'value' => 1,
                        'unit' => 'A',
                        'label' => ['de' => 'Stromstärke I', 'en' => 'current I'],
                    ],
                ],
                'animated' => true,
                'caution' => [
                    'de' => 'Die Punkte wandern absichtlich langsam, damit sichtbar wird, wie '
                        . 'träge die Elektronen selbst sind. Was fast sofort am Ende des Leiters '
                        . 'ankommt, ist das elektrische Feld und mit ihm die Energie.',
                    'en' => 'The dots move slowly on purpose, to show how sluggish the electrons '
                        . 'themselves are. What arrives at the end of the conductor almost at once is '
                        . 'the electric field, and with it the energy.',
                ],
            ],
            'related' => ['charge', 'voltage', 'resistance'],
        ],

        /* ------------------------------------------------------------------
           Grundlagen: Widerstand
           ------------------------------------------------------------------ */
        [
            'id' => 'resistance',
            'group' => 'basics',
            'topic' => [
                'de' => 'Widerstand',
                'en' => 'Resistance',
            ],
            'title' => [
                'de' => 'Elektrischer Widerstand',
                'en' => 'Electrical resistance',
            ],
            'terms' => [
                'de' => ['Widerstand', 'Ohm', 'Leitwert', 'Leiter', 'spezifischer Widerstand', 'Wärme'],
                'en' => ['resistance', 'ohm', 'conductance', 'resistivity', 'conductor'],
            ],
            'symbols' => ['R', 'G', 'ρ'],
            'units' => ['Ω', 'S', 'mm²'],
            'summary' => [
                'de' => 'Der Widerstand sagt, wie viel Spannung nötig ist, damit ein bestimmter Strom '
                    . 'fließt. Ein Ohm bedeutet: ein Volt treibt ein Ampere. Er hängt vom Material, von '
                    . 'der Länge und vom Querschnitt des Leiters ab - ein längerer Draht hat mehr '
                    . 'Widerstand, ein dickerer weniger.',
                'en' => 'Resistance tells how much voltage is needed for a certain current to flow. One '
                    . 'ohm means: one volt drives one ampere. It depends on the material, the length and '
                    . 'the cross-section - a longer wire has more resistance, a thicker one less.',
            ],
            'detail' => [
                'de' => [
                    'Im Widerstand wird elektrische Energie in Wärme umgewandelt. Das ist bei einem '
                        . 'Heizdraht gewollt und bei einer Leitung unerwünscht.',
                    'Der Widerstand ist eine Eigenschaft des Bauteils, nicht des Stroms. Er bleibt '
                        . 'gleich, egal wie viel Strom gerade fließt - solange sich die Temperatur nicht '
                        . 'ändert.',
                    'Deshalb wird eine Glühlampe im Betrieb heiß und ihr Widerstand dabei größer. Ihr '
                        . 'Strom steigt dann nicht mehr proportional zur Spannung, und das einfache '
                        . 'Ohmsche Gesetz gilt nicht mehr genau.',
                    'Der Kehrwert des Widerstands heißt Leitwert G = 1/R und wird in Siemens (S) '
                        . 'angegeben. Ein kleiner Widerstand bedeutet einen großen Leitwert.',
                ],
                'en' => [
                    'Inside a resistor electrical energy is converted into heat. With a heating element '
                        . 'that is intended; in a supply line it is a loss.',
                    'Resistance belongs to the component, not to the current. It stays the same no '
                        . 'matter how much current flows - as long as the temperature does not change.',
                    'That is why a filament lamp gets hot and its resistance grows. Its current then no '
                        . 'longer rises in proportion to the voltage, and the plain Ohm\u2019s law no '
                        . 'longer holds exactly.',
                    'The reciprocal of resistance is conductance G = 1/R, measured in siemens (S). A '
                        . 'small resistance means a large conductance.',
                ],
            ],
            'formulas' => [
                [
                    'latex' => 'R = \frac{U}{I}',
                    'variables' => [
                        ['symbol' => 'R', 'unit' => 'Ω', 'meaning' => ['de' => 'Widerstand', 'en' => 'resistance']],
                        ['symbol' => 'U', 'unit' => 'V', 'meaning' => ['de' => 'Spannung am Widerstand', 'en' => 'voltage across the resistor']],
                        ['symbol' => 'I', 'unit' => 'A', 'meaning' => ['de' => 'Strom durch den Widerstand', 'en' => 'current through the resistor']],
                    ],
                    'valid' => [
                        'de' => 'für jeden Verbraucher, auch wenn er sich erwärmt. Nur die Aussage '
                            . '"doppelte Spannung, doppelter Strom" gilt dann nicht mehr.',
                        'en' => 'for any load, even one that heats up. Only the statement "double the '
                            . 'voltage, double the current" stops being true.',
                    ],
                    'example' => [
                        'de' => 'Liegen 6 V an und es fließen 0,5 A, ist R = 6 V / 0,5 A = 12 Ω.',
                        'en' => 'With 6 V applied and 0.5 A flowing, R = 6 V / 0.5 A = 12 Ω.',
                    ],
                ],
                [
                    'latex' => 'R = \rho \cdot \frac{l}{A}',
                    'variables' => [
                        ['symbol' => 'R', 'unit' => 'Ω', 'meaning' => ['de' => 'Widerstand des Leiters', 'en' => 'resistance of the conductor']],
                        ['symbol' => 'ρ', 'unit' => 'Ω·mm²/m', 'meaning' => ['de' => 'spezifischer Widerstand des Materials', 'en' => 'resistivity of the material']],
                        ['symbol' => 'l', 'unit' => 'm', 'meaning' => ['de' => 'Länge', 'en' => 'length']],
                        ['symbol' => 'A', 'unit' => 'mm²', 'meaning' => ['de' => 'Querschnittsfläche', 'en' => 'cross-sectional area']],
                    ],
                    'valid' => [
                        'de' => 'für einen Leiter mit gleichbleibendem Querschnitt und gleichmäßiger '
                            . 'Temperatur. Bei Wechselstrom kommt der Skineffekt dazu.',
                        'en' => 'for a conductor of constant cross-section and even temperature. With '
                            . 'alternating current the skin effect adds to this.',
                    ],
                    'example' => [
                        'de' => 'Kupfer hat ρ = 0,0178 Ω·mm²/m. Ein Kabel mit 1,5 mm² und 20 m Länge hat '
                            . 'damit R = 0,0178 · 20 / 1,5 = 0,24 Ω - und zwar in jeder Ader, also '
                            . 'zweimal im Stromkreis (Hin- und Rückleiter).',
                        'en' => 'Copper has ρ = 0.0178 Ω·mm²/m. A cable of 1.5 mm² and 20 m length '
                            . 'therefore has R = 0.0178 × 20 / 1.5 = 0.24 Ω - in each conductor, so '
                            . 'twice in the circuit (out and back).',
                    ],
                ],
            ],
            'visual' => [
                'kind' => 'resistance_flow',
                'labels' => [
                    'source' => ['de' => 'Quelle', 'en' => 'source'],
                    'resistor' => ['de' => 'Widerstand', 'en' => 'resistor'],
                    'electrons' => ['de' => 'Elektronen', 'en' => 'electrons'],
                    'current' => ['de' => 'Stromstärke', 'en' => 'current'],
                    'fixedVoltage' => ['de' => 'Spannung fest', 'en' => 'voltage fixed'],
                ],
                'a11y' => [
                    'de' => 'Ein einfacher Stromkreis mit einer Quelle und einem Widerstand. Ein Regler '
                        . 'verändert den Widerstand bei gleichbleibender Spannung; die Punkte im Leiter '
                        . 'wandern langsamer, wenn der Widerstand steigt.',
                    'en' => 'A simple circuit with a source and a resistor. A slider changes the '
                        . 'resistance while the voltage stays the same; the dots in the wire drift more '
                        . 'slowly as the resistance rises.',
                ],
                'controls' => [
                    [
                        'id' => 'resistance',
                        'min' => 1,
                        'max' => 20,
                        'step' => 0.5,
                        'value' => 5,
                        'unit' => 'Ω',
                        'label' => ['de' => 'Widerstand R', 'en' => 'resistance R'],
                    ],
                ],
                'fixed' => ['voltage' => 6],
                'animated' => true,
                'caution' => [
                    'de' => 'Die Wanderung der Punkte steht für die Stromstärke, nicht für die '
                        . 'Geschwindigkeit der Energie. Der Widerstand ist hier als Bauteil gezeichnet.',
                    'en' => 'The drift of the dots stands for the current, not for the speed of '
                        . 'the energy. The resistor is drawn as a component here.',
                ],
            ],
            'related' => ['current', 'ohms_law', 'power'],
        ],

        /* ------------------------------------------------------------------
           Grundlagen: Ohmsches Gesetz
           ------------------------------------------------------------------ */
        [
            'id' => 'ohms_law',
            'group' => 'basics',
            'topic' => [
                'de' => 'Ohmsches Gesetz',
                'en' => 'Ohm\u2019s law',
            ],
            'title' => [
                'de' => 'Ohmsches Gesetz',
                'en' => 'Ohm\u2019s law',
            ],
            'terms' => [
                'de' => ['Ohmsches Gesetz', 'Zusammenhang', 'Kennlinie', 'proportional', 'U I R'],
                'en' => ['ohm\u2019s law', 'characteristic curve', 'proportional'],
            ],
            'symbols' => ['U', 'I', 'R'],
            'units' => ['V', 'A', 'Ω'],
            'summary' => [
                'de' => 'Das Ohmsche Gesetz verbindet die drei Grundgrößen: U = R · I. Bei einem '
                    . 'ohmschen Widerstand wächst der Strom proportional zur Spannung: doppelte '
                    . 'Spannung, doppelter Strom. Im Bild ist das eine Gerade durch den Ursprung, '
                    . 'deren Steilheit vom Widerstand abhängt.',
                'en' => 'Ohm\u2019s law ties the three basic quantities together: U = R × I. In an ohmic '
                    . 'resistor the current grows in proportion to the voltage: double the voltage, '
                    . 'double the current. In a graph that is a straight line through the origin whose '
                    . 'slope depends on the resistance.',
            ],
            'detail' => [
                'de' => [
                    'Die Formel lässt sich nach jeder der drei Größen umstellen. Merken muss man sich '
                        . 'also nur eine - und die Regel, dass bei doppelter Spannung der doppelte Strom '
                        . 'fließt, wenn der Widerstand bleibt.',
                    'Das Gesetz gilt für Bauteile, deren Widerstand sich nicht ändert: Metallschicht- '
                        . 'und Drahtwiderstände zum Beispiel. Man nennt sie ohmsche Widerstände.',
                    'Es gilt nicht für Dioden, Transistoren oder Glühlampen. Dort ist der Widerstand '
                        . 'selbst von Spannung oder Temperatur abhängig; die Kennlinie ist dann keine '
                        . 'Gerade.',
                    'Zwei Widerstände hintereinander (in Reihe) addieren sich: R = R₁ + R₂. Beide '
                        . 'werden vom selben Strom durchflossen. Parallel liegt an beiden dieselbe '
                        . 'Spannung, und es gilt 1/R = 1/R₁ + 1/R₂.',
                ],
                'en' => [
                    'The formula can be rearranged for each of the three quantities. So there is only '
                        . 'one to remember - plus the rule that double the voltage means double the '
                        . 'current when the resistance stays the same.',
                    'The law holds for components whose resistance does not change: metal film and wire '
                        . 'resistors, for example. They are called ohmic resistors.',
                    'It does not hold for diodes, transistors or filament lamps. There the resistance '
                        . 'itself depends on voltage or temperature, and the curve is no straight line.',
                    'Two resistors one after the other (in series) add up: R = R₁ + R₂. The same '
                        . 'current flows through both. In parallel both carry the same voltage, and '
                        . '1/R = 1/R₁ + 1/R₂.',
                ],
            ],
            'formulas' => [
                [
                    'latex' => 'U = R \cdot I',
                    'variables' => [
                        ['symbol' => 'U', 'unit' => 'V', 'meaning' => ['de' => 'Spannung', 'en' => 'voltage']],
                        ['symbol' => 'R', 'unit' => 'Ω', 'meaning' => ['de' => 'Widerstand', 'en' => 'resistance']],
                        ['symbol' => 'I', 'unit' => 'A', 'meaning' => ['de' => 'Stromstärke', 'en' => 'current']],
                    ],
                    'valid' => [
                        'de' => 'für ohmsche Widerstände bei gleichbleibender Temperatur.',
                        'en' => 'for ohmic resistors at constant temperature.',
                    ],
                    'example' => [
                        'de' => '12 Ω und 0,5 A ergeben U = 12 Ω · 0,5 A = 6 V.',
                        'en' => '12 Ω and 0.5 A give U = 12 Ω × 0.5 A = 6 V.',
                    ],
                ],
                [
                    'latex' => 'I = \frac{U}{R}',
                    'variables' => [
                        ['symbol' => 'I', 'unit' => 'A', 'meaning' => ['de' => 'Stromstärke', 'en' => 'current']],
                        ['symbol' => 'U', 'unit' => 'V', 'meaning' => ['de' => 'Spannung', 'en' => 'voltage']],
                        ['symbol' => 'R', 'unit' => 'Ω', 'meaning' => ['de' => 'Widerstand', 'en' => 'resistance']],
                    ],
                    'valid' => ['de' => 'dieselbe Bedingung, nur nach dem Strom umgestellt.', 'en' => 'the same condition, rearranged for the current.'],
                    'example' => [
                        'de' => '6 V an 12 Ω ergeben I = 6 V / 12 Ω = 0,5 A.',
                        'en' => '6 V across 12 Ω give I = 6 V / 12 Ω = 0.5 A.',
                    ],
                ],
            ],
            'visual' => [
                'kind' => 'ohms_characteristic',
                'labels' => [
                    'voltageAxis' => ['de' => 'Spannung U in V', 'en' => 'voltage U in V'],
                    'currentAxis' => ['de' => 'Strom I in A', 'en' => 'current I in A'],
                    'workingPoint' => ['de' => 'Arbeitspunkt', 'en' => 'working point'],
                ],
                'a11y' => [
                    'de' => 'Ein Schaubild mit der Spannung auf der waagerechten und dem Strom auf der '
                        . 'senkrechten Achse. Die Gerade durch den Ursprung zeigt den Zusammenhang; der '
                        . 'markierte Punkt ist der eingestellte Arbeitspunkt.',
                    'en' => 'A chart with voltage on the horizontal and current on the vertical axis. The '
                        . 'straight line through the origin shows the relationship; the marked point is '
                        . 'the working point that is set.',
                ],
                'controls' => [
                    [
                        'id' => 'voltage',
                        'min' => 0,
                        'max' => 24,
                        'step' => 0.5,
                        'value' => 6,
                        'unit' => 'V',
                        'label' => ['de' => 'Spannung U', 'en' => 'voltage U'],
                    ],
                    [
                        'id' => 'resistance',
                        'min' => 2,
                        'max' => 60,
                        'step' => 1,
                        'value' => 12,
                        'unit' => 'Ω',
                        'label' => ['de' => 'Widerstand R', 'en' => 'resistance R'],
                    ],
                ],
                'caution' => [
                    'de' => 'Die Gerade gilt nur für einen ohmschen Widerstand. Bei einer Glühlampe '
                        . 'wird sie zur Kurve, weil der Widerstand mit der Temperatur steigt.',
                    'en' => 'The straight line only holds for an ohmic resistor. For a filament lamp it '
                        . 'bends into a curve, because resistance rises with temperature.',
                ],
            ],
            'related' => ['voltage', 'resistance', 'power'],
        ],

        /* ------------------------------------------------------------------
           Grundlagen: Leistung
           ------------------------------------------------------------------ */
        [
            'id' => 'power',
            'group' => 'basics',
            'topic' => [
                'de' => 'Leistung',
                'en' => 'Power',
            ],
            'title' => [
                'de' => 'Elektrische Leistung',
                'en' => 'Electrical power',
            ],
            'terms' => [
                'de' => ['Leistung', 'Watt', 'Leistungsaufnahme', 'Nennleistung', 'Kilowatt'],
                'en' => ['power', 'watt', 'rating', 'kilowatt'],
            ],
            'symbols' => ['P', 'U', 'I', 'R'],
            'units' => ['W', 'kW'],
            'summary' => [
                'de' => 'Die Leistung sagt, wie schnell elektrische Energie umgesetzt wird: '
                    . 'P = U · I. Ein Watt bedeutet ein Joule pro Sekunde. Sie steht auf jedem Gerät '
                    . 'als Nennleistung - und sie bestimmt, wie viel Strom bei 230 V fließt.',
                'en' => 'Power says how fast electrical energy is converted: P = U × I. One watt means '
                    . 'one joule per second. It is printed on every appliance as its rating - and it '
                    . 'determines how much current flows at 230 V.',
            ],
            'detail' => [
                'de' => [
                    'Die Leistung gilt für einen Augenblick und nicht für eine Dauer. Erst die Zeit '
                        . 'macht daraus eine Energiemenge - siehe "Zusammenhang zwischen Leistung, '
                        . 'Energie und Zeit".',
                    'Aus dem Ohmschen Gesetz folgen zwei weitere Formen: P = U² / R und P = I² · R. '
                        . 'Bei einer Leitung ist die zweite wichtig, denn die Verluste wachsen mit dem '
                        . 'QUADRAT des Stroms.',
                    'Zwei Größenordnungen zum Vergleich: Eine LED-Lampe nimmt 10 W auf, ein Wasserkocher '
                        . '2000 W - zweihundertmal so viel. Bei 230 V sind das 0,04 A gegen 8,7 A.',
                    'Bei 230 V und 16 A, einer üblichen Absicherung, sind es 3680 W. Mehr geht aus einer '
                        . 'Steckdose nicht, deshalb löst die Sicherung aus, wenn mehrere starke Geräte '
                        . 'gleichzeitig laufen.',
                ],
                'en' => [
                    'Power applies to an instant, not to a duration. Only time turns it into an amount '
                        . 'of energy - see "Power, energy and time".',
                    'From Ohm\u2019s law two more forms follow: P = U² / R and P = I² × R. In a line the '
                        . 'second one matters, because the losses grow with the SQUARE of the current.',
                    'Two orders of magnitude for comparison: an LED lamp draws 10 W, a kettle 2000 W - '
                        . 'two hundred times as much. At 230 V that is 0.04 A against 8.7 A.',
                    'At 230 V and 16 A, a common fuse rating, that is 3680 W. More cannot be drawn from '
                        . 'one socket, which is why the fuse trips when several powerful appliances run '
                        . 'at once.',
                ],
            ],
            'formulas' => [
                [
                    'latex' => 'P = U \cdot I',
                    'variables' => [
                        ['symbol' => 'P', 'unit' => 'W', 'meaning' => ['de' => 'Leistung', 'en' => 'power']],
                        ['symbol' => 'U', 'unit' => 'V', 'meaning' => ['de' => 'Spannung', 'en' => 'voltage']],
                        ['symbol' => 'I', 'unit' => 'A', 'meaning' => ['de' => 'Stromstärke', 'en' => 'current']],
                    ],
                    'valid' => [
                        'de' => 'für Gleichstrom und, mit den Effektivwerten, für Wechselstrom. Bei '
                            . 'Wechselstrom mit Phasenverschiebung kommt der Leistungsfaktor cos φ dazu.',
                        'en' => 'for direct current and, using effective values, for alternating current. '
                            . 'With a phase shift in alternating current the power factor cos φ comes in.',
                    ],
                    'example' => [
                        'de' => '230 V und 8,7 A ergeben P = 230 V · 8,7 A = 2001 W - das ist ein '
                            . 'Wasserkocher.',
                        'en' => '230 V and 8.7 A give P = 230 V × 8.7 A = 2001 W - that is a kettle.',
                    ],
                ],
                [
                    'latex' => 'P = I^2 \cdot R',
                    'variables' => [
                        ['symbol' => 'P', 'unit' => 'W', 'meaning' => ['de' => 'Leistung', 'en' => 'power']],
                        ['symbol' => 'I', 'unit' => 'A', 'meaning' => ['de' => 'Stromstärke', 'en' => 'current']],
                        ['symbol' => 'R', 'unit' => 'Ω', 'meaning' => ['de' => 'Widerstand', 'en' => 'resistance']],
                    ],
                    'valid' => [
                        'de' => 'an jedem Widerstand, durch den der Strom I fließt. Sie ist die Form für '
                            . 'Leitungsverluste: dort kennt man den Strom und nicht die Spannung.',
                        'en' => 'at any resistor carrying the current I. It is the form for line losses: '
                            . 'there you know the current, not the voltage.',
                    ],
                    'example' => [
                        'de' => 'Ein Kabel mit 0,24 Ω, durch das 10 A fließen, setzt '
                            . 'P = (10 A)² · 0,24 Ω = 24 W in Wärme um.',
                        'en' => 'A cable of 0.24 Ω carrying 10 A turns P = (10 A)² × 0.24 Ω = 24 W into heat.',
                    ],
                ],
            ],
            'visual' => [
                'kind' => 'power_bars',
                'labels' => [
                    'power' => ['de' => 'Leistung', 'en' => 'power'],
                    'current' => ['de' => 'Strom bei 230 V', 'en' => 'current at 230 V'],
                    'setting' => ['de' => 'deine Einstellung', 'en' => 'your setting'],
                    'scale' => ['de' => 'Leistung in Watt', 'en' => 'power in watts'],
                ],
                /* Die Vergleichsgrößen des Bildes: Beschriftung und Leistung. */
                'references' => [
                    ['label' => ['de' => 'LED-Lampe', 'en' => 'LED lamp'], 'power' => 10],
                    ['label' => ['de' => 'Laptop', 'en' => 'laptop'], 'power' => 65],
                    ['label' => ['de' => 'Wasserkocher', 'en' => 'kettle'], 'power' => 2000],
                ],
                'a11y' => [
                    'de' => 'Ein Balkenbild: zwei Regler stellen Spannung und Strom ein, daraus folgt '
                        . 'die Leistung. Sie steht als Balken neben drei bekannten Geräten.',
                    'en' => 'A bar chart: two sliders set voltage and current, which gives the power. It '
                        . 'is shown as a bar next to three known appliances.',
                ],
                'controls' => [
                    [
                        'id' => 'voltage',
                        'min' => 100,
                        'max' => 240,
                        'step' => 10,
                        'value' => 230,
                        'unit' => 'V',
                        'label' => ['de' => 'Spannung U', 'en' => 'voltage U'],
                    ],
                    [
                        'id' => 'current',
                        'min' => 0,
                        'max' => 16,
                        'step' => 0.1,
                        'value' => 2,
                        'unit' => 'A',
                        'label' => ['de' => 'Stromstärke I', 'en' => 'current I'],
                    ],
                ],
                'fixed' => ['maxPower' => 2500],
                'caution' => [
                    'de' => 'Die drei Geräte sind Beispiele mit runden Werten. Die Balkenlänge steht '
                        . 'für die Leistung, nicht für den Verbrauch - der hängt zusätzlich an der Zeit.',
                    'en' => 'The three appliances are examples with round numbers. The bar length stands '
                        . 'for power, not for consumption - that also depends on time.',
                ],
            ],
            'related' => ['energy', 'ohms_law', 'power_energy_time'],
        ],

        /* ------------------------------------------------------------------
           Grundlagen: Elektrische Energie
           ------------------------------------------------------------------ */
        [
            'id' => 'energy',
            'group' => 'basics',
            'topic' => [
                'de' => 'Energie',
                'en' => 'Energy',
            ],
            'title' => [
                'de' => 'Elektrische Energie',
                'en' => 'Electrical energy',
            ],
            'terms' => [
                'de' => ['Energie', 'Kilowattstunde', 'Wattstunde', 'Stromverbrauch', 'Stromrechnung', 'Arbeit'],
                'en' => ['energy', 'kilowatt hour', 'watt hour', 'consumption', 'electricity bill'],
            ],
            'symbols' => ['E', 'P', 't'],
            'units' => ['J', 'Wh', 'kWh'],
            'summary' => [
                'de' => 'Energie ist Leistung mal Zeit: E = P · t. Sie ist das, was man bezahlt, und '
                    . 'die Einheit auf der Stromrechnung ist die Kilowattstunde. Ein Gerät mit 1000 W, '
                    . 'das eine Stunde läuft, verbraucht 1 kWh.',
                'en' => 'Energy is power times time: E = P × t. It is what you pay for, and the unit on '
                    . 'the bill is the kilowatt hour. An appliance of 1000 W running for one hour uses '
                    . '1 kWh.',
            ],
            'detail' => [
                'de' => [
                    'Die Leistung sagt, wie schnell Energie umgesetzt wird. Erst die Zeit macht daraus '
                        . 'eine Menge - und nur die Menge steht auf der Rechnung.',
                    'Im Bild ist die Energie die Fläche unter der Leistungskurve: Leistung nach oben, '
                        . 'Zeit nach rechts, und die Fläche dazwischen ist die Energie. Bleibt die '
                        . 'Leistung gleich, ist diese Fläche ein Rechteck.',
                    '1 kWh sind 3,6 Millionen Joule. Ein Haushalt verbraucht im Jahr einige tausend '
                        . 'Kilowattstunden - ein einzelnes Gerät mit 10 W läuft dafür monatelang.',
                    'Ändert sich die Leistung während der Zeit, ist die Fläche kein Rechteck mehr. Dann '
                        . 'rechnet man die Abschnitte einzeln und zählt sie zusammen.',
                ],
                'en' => [
                    'Power says how fast energy is converted. Only time turns it into an amount - and '
                        . 'only the amount appears on the bill.',
                    'In the picture the energy is the area under the power curve: power upwards, time to '
                        . 'the right, and the area in between is the energy. If the power stays the same, '
                        . 'that area is a rectangle.',
                    '1 kWh is 3.6 million joules. A household uses a few thousand kilowatt hours per '
                        . 'year - a single 10 W appliance runs for months on one of them.',
                    'If the power changes during the time, the area is no longer a rectangle. Then you '
                        . 'calculate each section and add them up.',
                ],
            ],
            'formulas' => [
                [
                    'latex' => 'E = P \cdot t',
                    'variables' => [
                        ['symbol' => 'E', 'unit' => 'J bzw. kWh', 'meaning' => ['de' => 'Energie', 'en' => 'energy']],
                        ['symbol' => 'P', 'unit' => 'W', 'meaning' => ['de' => 'Leistung', 'en' => 'power']],
                        ['symbol' => 't', 'unit' => 's bzw. h', 'meaning' => ['de' => 'Zeit', 'en' => 'time']],
                    ],
                    'valid' => [
                        'de' => 'solange die Leistung während dieser Zeit gleich bleibt. Sonst muss man '
                            . 'abschnittsweise rechnen oder die Kurve auswerten.',
                        'en' => 'as long as the power stays the same during that time. Otherwise you '
                            . 'calculate section by section or evaluate the curve.',
                    ],
                    'example' => [
                        'de' => 'Eine LED mit 10 W, die 5 Stunden brennt, verbraucht E = 10 W · 5 h = '
                            . '50 Wh = 0,05 kWh. Bei 30 Cent je kWh sind das 1,5 Cent.',
                        'en' => 'A 10 W LED burning for 5 hours uses E = 10 W × 5 h = 50 Wh = 0.05 kWh. '
                            . 'At 30 cents per kWh that is 1.5 cents.',
                    ],
                ],
            ],
            'visual' => [
                'kind' => 'power_time_area',
                'labels' => [
                    'power' => ['de' => 'Leistung P in W', 'en' => 'power P in W'],
                    'time' => ['de' => 'Zeit t in h', 'en' => 'time t in h'],
                    'energy' => ['de' => 'Energie', 'en' => 'energy'],
                    'area' => ['de' => 'Fläche = Energie', 'en' => 'area = energy'],
                    'cost' => ['de' => 'Kosten', 'en' => 'cost'],
                    'price' => ['de' => 'Preis', 'en' => 'price'],
                ],
                'a11y' => [
                    'de' => 'Ein Schaubild mit der Zeit waagerecht und der Leistung senkrecht. Das '
                        . 'Rechteck unter der Leistungslinie ist die Energie.',
                    'en' => 'A chart with time horizontal and power vertical. The rectangle under the '
                        . 'power line is the energy.',
                ],
                'controls' => [
                    [
                        'id' => 'power',
                        'min' => 10,
                        'max' => 3000,
                        'step' => 10,
                        'value' => 1000,
                        'unit' => 'W',
                        'label' => ['de' => 'Leistung P', 'en' => 'power P'],
                    ],
                    [
                        'id' => 'time',
                        'min' => 0.5,
                        'max' => 10,
                        'step' => 0.5,
                        'value' => 1,
                        'unit' => 'h',
                        'label' => ['de' => 'Zeit t', 'en' => 'time t'],
                    ],
                ],
                'fixed' => ['pricePerKwh' => 0.3, 'currency' => '€'],
                'caution' => [
                    'de' => 'Der Preis ist ein Beispielwert von 30 Cent je Kilowattstunde. Er hängt am '
                        . 'Tarif und ändert sich mit der Zeit.',
                    'en' => 'The price is an example value of 30 cents per kilowatt hour. It depends on '
                        . 'your tariff and changes over time.',
                ],
            ],
            'related' => ['power', 'power_energy_time', 'current'],
        ],

        /* ------------------------------------------------------------------
           Grundlagen: Gleichstrom und Wechselstrom
           ------------------------------------------------------------------ */
        [
            'id' => 'dc_ac',
            'group' => 'basics',
            'topic' => [
                'de' => 'Gleichstrom und Wechselstrom',
                'en' => 'Direct and alternating current',
            ],
            'title' => [
                'de' => 'Gleichstrom und Wechselstrom',
                'en' => 'Direct and alternating current',
            ],
            'terms' => [
                'de' => ['Gleichstrom', 'Wechselstrom', 'Wechselspannung', 'Frequenz', 'Hertz',
                    'Periodendauer', 'Netzspannung', 'Effektivwert', 'Scheitelwert', 'Sinus'],
                'en' => ['direct current', 'alternating current', 'frequency', 'hertz', 'period',
                    'mains voltage', 'effective value', 'peak value', 'sine'],
            ],
            'symbols' => ['I', 'U', 'f', 'T'],
            'units' => ['A', 'V', 'Hz', 'ms'],
            'summary' => [
                'de' => 'Bei Gleichstrom bleiben Richtung und Stärke gleich - eine Batterie liefert das. '
                    . 'Bei Wechselstrom ändern sie sich fortlaufend: in Europa wechselt die Richtung '
                    . '50-mal in der Sekunde, und die Spannung folgt einer Sinuskurve. Wechselstrom '
                    . 'lässt sich mit Transformatoren umsetzen, Gleichstrom nicht.',
                'en' => 'With direct current the direction and size stay the same - that is what a '
                    . 'battery delivers. With alternating current both keep changing: in Europe the '
                    . 'direction changes 50 times per second and the voltage follows a sine curve. '
                    . 'Alternating current can be transformed, direct current cannot.',
            ],
            'detail' => [
                'de' => [
                    'Eine Periode dauert T, die Frequenz ist f = 1 / T. Bei 50 Hz dauert eine Periode '
                        . '20 ms.',
                    'Die 230 V aus der Steckdose sind der Effektivwert. Der Scheitelwert ist √2-mal so '
                        . 'groß, also rund 325 V - so hoch ist die Spannung wirklich in der Spitze.',
                    'Der Effektivwert ist der Wert einer Gleichspannung, die an einem Widerstand '
                        . 'dieselbe Leistung erzeugen würde. Deshalb rechnet man damit genauso wie mit '
                        . 'Gleichstrom.',
                    'Gleichstrom braucht man für Elektronik, Batterien und Gleichstromleitungen, '
                        . 'Wechselstrom für alles, was über Transformatoren läuft. Drehstrom ist '
                        . 'Wechselstrom mit drei um 120° versetzten Phasen.',
                ],
                'en' => [
                    'One period lasts T, the frequency is f = 1 / T. At 50 Hz a period lasts 20 ms.',
                    'The 230 V from the socket is the effective value. The peak value is √2 times as '
                        . 'large, about 325 V - that is how high the voltage really gets at the peak.',
                    'The effective value is the direct voltage that would produce the same power in a '
                        . 'resistor. That is why you calculate with it exactly as with direct current.',
                    'Direct current is needed for electronics, batteries and DC lines; alternating '
                        . 'current for everything that runs through transformers. Three-phase current is '
                        . 'alternating current with three phases offset by 120°.',
                ],
            ],
            'formulas' => [
                [
                    'latex' => 'f = \frac{1}{T}',
                    'variables' => [
                        ['symbol' => 'f', 'unit' => 'Hz', 'meaning' => ['de' => 'Frequenz', 'en' => 'frequency']],
                        ['symbol' => 'T', 'unit' => 's', 'meaning' => ['de' => 'Periodendauer', 'en' => 'period']],
                    ],
                    'valid' => [
                        'de' => 'für jede regelmäßige Wiederholung, auch über die Netzspannung hinaus.',
                        'en' => 'for any regular repetition, not only for mains voltage.',
                    ],
                    'example' => [
                        'de' => 'Bei 50 Hz ist T = 1 / 50 Hz = 0,02 s = 20 ms.',
                        'en' => 'At 50 Hz, T = 1 / 50 Hz = 0.02 s = 20 ms.',
                    ],
                ],
                [
                    'latex' => 'U_{\mathrm{eff}} = \frac{\hat{U}}{\sqrt{2}}',
                    'variables' => [
                        ['symbol' => 'U_eff', 'unit' => 'V', 'meaning' => ['de' => 'Effektivwert', 'en' => 'effective value']],
                        ['symbol' => 'û', 'unit' => 'V', 'meaning' => ['de' => 'Scheitelwert', 'en' => 'peak value']],
                    ],
                    'valid' => [
                        'de' => 'für eine reine Sinusform. Bei anderen Kurvenformen ist der Faktor ein '
                            . 'anderer.',
                        'en' => 'for a pure sine. For other waveforms the factor is different.',
                    ],
                    'example' => [
                        'de' => 'Ein Scheitelwert von 325 V ergibt U_eff = 325 V / 1,414 = 230 V.',
                        'en' => 'A peak value of 325 V gives U_eff = 325 V / 1.414 = 230 V.',
                    ],
                ],
            ],
            'visual' => [
                'kind' => 'dc_ac_wave',
                'labels' => [
                    'dc' => ['de' => 'Gleichstrom', 'en' => 'direct current'],
                    'ac' => ['de' => 'Wechselstrom', 'en' => 'alternating current'],
                    'effective' => ['de' => 'Effektivwert', 'en' => 'effective value'],
                    'peak' => ['de' => 'Scheitelwert', 'en' => 'peak value'],
                    'period' => ['de' => 'eine Periode', 'en' => 'one period'],
                    'voltageAxis' => ['de' => 'Spannung U in V', 'en' => 'voltage U in V'],
                    'timeAxis' => ['de' => 'Zeit t in ms', 'en' => 'time t in ms'],
                ],
                'a11y' => [
                    'de' => 'Ein Schaubild der Spannung über der Zeit. Ein Schalter wechselt zwischen '
                        . 'einer waagerechten Geraden für Gleichstrom und einer Sinuskurve für '
                        . 'Wechselstrom.',
                    'en' => 'A chart of voltage over time. A switch toggles between a flat line for '
                        . 'direct current and a sine curve for alternating current.',
                ],
                'controls' => [
                    [
                        'id' => 'kind',
                        'kind' => 'select',
                        'value' => 'ac',
                        'label' => ['de' => 'Stromart', 'en' => 'kind of current'],
                        'options' => [
                            ['value' => 'dc', 'label' => ['de' => 'Gleichstrom', 'en' => 'direct current']],
                            ['value' => 'ac', 'label' => ['de' => 'Wechselstrom', 'en' => 'alternating current']],
                        ],
                    ],
                    [
                        'id' => 'amplitude',
                        'min' => 0,
                        'max' => 400,
                        'step' => 5,
                        'value' => 325,
                        'unit' => 'V',
                        'label' => ['de' => 'Scheitelwert', 'en' => 'peak value'],
                    ],
                    [
                        'id' => 'frequency',
                        'min' => 10,
                        'max' => 100,
                        'step' => 1,
                        'value' => 50,
                        'unit' => 'Hz',
                        'label' => ['de' => 'Frequenz', 'en' => 'frequency'],
                    ],
                ],
                'caution' => [
                    'de' => 'Gezeichnet ist eine reine Sinusform. In Wirklichkeit ist die Netzspannung '
                        . 'nicht ganz sauber, und viele Geräte erzeugen andere Kurvenformen.',
                    'en' => 'A pure sine is drawn here. In reality the mains voltage is not perfectly '
                        . 'clean, and many devices produce other waveforms.',
                ],
            ],
            'related' => ['voltage', 'current', 'grid_frequency'],
        ],

        /* ------------------------------------------------------------------
           Grundlagen: Leistung, Energie und Zeit
           ------------------------------------------------------------------ */
        [
            'id' => 'power_energy_time',
            'group' => 'basics',
            'topic' => [
                'de' => 'Leistung, Energie und Zeit',
                'en' => 'Power, energy and time',
            ],
            'title' => [
                'de' => 'Zusammenhang zwischen Leistung, Energie und Zeit',
                'en' => 'The link between power, energy and time',
            ],
            'terms' => [
                'de' => ['Zusammenhang', 'Leistung', 'Energie', 'Zeit', 'vergleichen', 'Umstellen', 'Verbrauch'],
                'en' => ['relationship', 'power', 'energy', 'time', 'compare', 'rearrange', 'consumption'],
            ],
            'symbols' => ['P', 'E', 't'],
            'units' => ['W', 'J', 'h', 'kWh'],
            'summary' => [
                'de' => 'Die drei Größen hängen über E = P · t zusammen. Bei derselben Energiemenge '
                    . 'gilt: viel Leistung heißt wenig Zeit. Ein Wasserkocher mit 2000 W erledigt '
                    . 'dieselbe Energie in einem Zwanzigstel der Zeit einer 100-W-Heizung.',
                'en' => 'The three quantities are linked by E = P × t. For the same amount of energy, '
                    . 'high power means little time. A 2000 W kettle delivers the same energy in a '
                    . 'twentieth of the time a 100 W heater needs.',
            ],
            'detail' => [
                'de' => [
                    'Aus E = P · t bekommt man durch Umstellen P = E / t und t = E / P. Zwei der drei '
                        . 'Größen reichen also, um die dritte zu berechnen.',
                    'Dieselbe Energie kann schnell und stark oder langsam und schwach abgegeben werden. '
                        . 'Bezahlt wird die Energie, nicht die Leistung.',
                    'Die Leistung bestimmt aber, wie dick ein Kabel und wie groß eine Sicherung sein '
                        . 'muss: 2000 W an 230 V sind 8,7 A, 100 W nur 0,43 A.',
                    'Ein Beispiel: 1 kWh lässt sich in einer Stunde mit 1000 W verbrauchen oder in zehn '
                        . 'Stunden mit 100 W. Der Zähler zeigt am Ende dasselbe.',
                ],
                'en' => [
                    'Rearranging E = P × t gives P = E / t and t = E / P. So two of the three quantities '
                        . 'are enough to work out the third.',
                    'The same energy can be delivered fast and strongly or slowly and weakly. You pay '
                        . 'for the energy, not for the power.',
                    'Power does decide how thick a cable and how large a fuse must be: 2000 W at 230 V '
                        . 'is 8.7 A, 100 W only 0.43 A.',
                    'One example: 1 kWh can be used in one hour at 1000 W or in ten hours at 100 W. The '
                        . 'meter shows the same at the end.',
                ],
            ],
            'formulas' => [
                [
                    'latex' => 'P = \frac{E}{t}',
                    'variables' => [
                        ['symbol' => 'P', 'unit' => 'W', 'meaning' => ['de' => 'Leistung', 'en' => 'power']],
                        ['symbol' => 'E', 'unit' => 'J bzw. kWh', 'meaning' => ['de' => 'Energie', 'en' => 'energy']],
                        ['symbol' => 't', 'unit' => 's bzw. h', 'meaning' => ['de' => 'Zeit', 'en' => 'time']],
                    ],
                    'valid' => [
                        'de' => 'dieselbe Formel wie E = P · t, nur nach der Leistung umgestellt. Sie gilt '
                            . 'für eine gleichbleibende Leistung.',
                        'en' => 'the same formula as E = P × t, rearranged for power. It holds for a '
                            . 'constant power.',
                    ],
                    'example' => [
                        'de' => 'Wer 2 kWh in 4 Stunden verbraucht, hatte im Mittel '
                            . 'P = 2 kWh / 4 h = 0,5 kW = 500 W an Leistung.',
                        'en' => 'Using 2 kWh in 4 hours means an average power of '
                            . 'P = 2 kWh / 4 h = 0.5 kW = 500 W.',
                    ],
                ],
            ],
            'visual' => [
                'kind' => 'power_time_compare',
                'labels' => [
                    'deviceA' => ['de' => 'Gerät A', 'en' => 'appliance A'],
                    'deviceB' => ['de' => 'Gerät B', 'en' => 'appliance B'],
                    'sameEnergy' => ['de' => 'beide Rechtecke sind gleich groß', 'en' => 'both rectangles are the same size'],
                    'power' => ['de' => 'Leistung P in W', 'en' => 'power P in W'],
                    'time' => ['de' => 'Zeit t in h', 'en' => 'time t in h'],
                    'energy' => ['de' => 'Energie', 'en' => 'energy'],
                ],
                'a11y' => [
                    'de' => 'Zwei Regler stellen Leistung und Zeit für Gerät A ein, ein dritter die '
                        . 'Leistung von Gerät B. Die Zeit für Gerät B wird so berechnet, dass beide '
                        . 'Rechtecke dieselbe Fläche haben.',
                    'en' => 'Two sliders set power and time for appliance A, a third sets the power of '
                        . 'appliance B. The time for B is calculated so that both rectangles have the '
                        . 'same area.',
                ],
                'controls' => [
                    [
                        'id' => 'powerA',
                        'min' => 100,
                        'max' => 3000,
                        'step' => 50,
                        'value' => 1000,
                        'unit' => 'W',
                        'label' => ['de' => 'Leistung A', 'en' => 'power A'],
                    ],
                    [
                        'id' => 'timeA',
                        'min' => 0.5,
                        'max' => 6,
                        'step' => 0.5,
                        'value' => 2,
                        'unit' => 'h',
                        'label' => ['de' => 'Zeit A', 'en' => 'time A'],
                    ],
                    [
                        'id' => 'powerB',
                        'min' => 250,
                        'max' => 3000,
                        'step' => 50,
                        'value' => 500,
                        'unit' => 'W',
                        'label' => ['de' => 'Leistung B', 'en' => 'power B'],
                    ],
                ],
                'caution' => [
                    'de' => 'Beide Geräte setzen dieselbe Energie um. Das Bild zeigt nur, wie '
                        . 'unterschiedlich schnell das geschieht - und dass die Fläche dabei gleich '
                        . 'bleibt.',
                    'en' => 'Both appliances convert the same energy. The picture only shows how '
                        . 'differently fast that happens - and that the area stays the same.',
                ],
            ],
            'related' => ['power', 'energy', 'efficiency'],
        ],
    ];
}
