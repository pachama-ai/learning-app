<?php

declare(strict_types=1);

/**
 * Colour-only patch for public/assets/css/app.css.
 *
 * Every replacement below changes a colour value (or removes a now-unused
 * colour token). No layout, size, opacity, effect or animation value is
 * touched. Assertions guard every step so a mismatch stops the run instead of
 * writing a half-applied file.
 */

$path = __DIR__ . '/public/assets/css/app.css';
$raw = file_get_contents($path);

if ($raw === false) {
    fwrite(STDERR, "could not read css\n");
    exit(1);
}

// Mixed CRLF/LF has been accumulating; normalise to LF so the file is coherent.
$css = str_replace("\r\n", "\n", $raw);

$pairs = [
    // ---- 1. Base colours, light ----
    ['    --text: #18181F;', '    --text: #1C1D26;'],
    ['    --muted: #5E5D6B;', '    --muted: #5C5F70;'],
    ['    --line: rgba(24, 24, 31, 0.14);', '    --line: rgba(28, 29, 38, 0.16);'],
    ['    --line-strong: rgba(24, 24, 31, 0.25);', '    --line-strong: rgba(28, 29, 38, 0.25);'],
    ['    --plus-bg: #18181F;', '    --plus-bg: #1C1D26;'],
    ['    --plus-fg: #F6F4FA;', '    --plus-fg: #F4F5F9;'],
    ["    --accent-ink: #0F4A43;\n", ''],

    // ---- 2. Category colours, light (desaturated) ----
    ['    --cat-mathematics: #6FA2F0;', '    --cat-mathematics: #6F7A9B;'],
    ['    --cat-energy: #F2A98A;', '    --cat-energy: #9A8B7C;'],
    ['    --cat-geography: #8EE3C8;', '    --cat-geography: #7F9088;'],
    ['    --cat-english: #B99FD3;', '    --cat-english: #8D819B;'],

    // ---- 3. Reserve colours, same treatment (they are never reached by the
    //         current data, but they must not reintroduce peach/mint/lilac) ----
    ['    --cat-reserve-blue: #CDDCF7;', '    --cat-reserve-blue: #7A85A4;'],
    ['    --cat-reserve-rose: #F4B6C6;', '    --cat-reserve-rose: #95818B;'],
    ['    --cat-reserve-butter: #EFD98B;', '    --cat-reserve-butter: #948F76;'],

    // ---- 4. Base colours, dark ----
    ['    --text: #F6F4FA;', '    --text: #F4F5F9;'],
    ['    --muted: #E6E8F1;', '    --muted: #DDE0EB;'],
    ['    --line: rgba(246, 244, 250, 0.28);', '    --line: rgba(244, 245, 249, 0.28);'],
    ['    --line-strong: rgba(246, 244, 250, 0.35);', '    --line-strong: rgba(244, 245, 249, 0.35);'],
    ['    --plus-bg: #F6F4FA;', '    --plus-bg: #F4F5F9;'],
    ['    --plus-fg: #2B2F45;', '    --plus-fg: #3E4460;'],
    ["    --accent-ink: #F6F4FA;\n", ''],

    // ---- 5. Category colours, dark ----
    ['    --cat-mathematics: #9DC0F8;', '    --cat-mathematics: #A5AECB;'],
    ['    --cat-energy: #F7C3AA;', '    --cat-energy: #C2B3A3;'],
    ['    --cat-geography: #A9EED9;', '    --cat-geography: #A6B7AE;'],
    ['    --cat-english: #D0BCE8;', '    --cat-english: #B8AAC4;'],
    [
        "    --cat-english: #B8AAC4;\n\n    --backdrop: rgba(20, 22, 34, 0.45);",
        "    --cat-english: #B8AAC4;\n\n"
        . "    /* Lighter companions for the reserves, so they read on the slate. */\n"
        . "    --cat-reserve-blue: #ADB6D1;\n"
        . "    --cat-reserve-rose: #C0AEB6;\n"
        . "    --cat-reserve-butter: #BFBAA2;\n\n"
        . "    --backdrop: rgba(20, 22, 34, 0.45);",
    ],

    // ---- 6. Background shape tones (one base tone per shape, per theme) ----
    [
        "    --cat-reserve-butter: #948F76;\n",
        "    --cat-reserve-butter: #948F76;\n\n"
        . "    /*\n"
        . "     * The four background shapes. Position, size, blur, opacity and drift\n"
        . "     * are unchanged; only these base tones are new, so the shapes read as one\n"
        . "     * blue-grey family instead of peach, mint, blue and beige.\n"
        . "     */\n"
        . "    --shape-1: #D9D6E3;\n"
        . "    --shape-2: #C9CEE3;\n"
        . "    --shape-3: #CBD0E0;\n"
        . "    --shape-4: #DDD9DF;\n",
    ],
    [
        "    --cat-reserve-butter: #BFBAA2;\n",
        "    --cat-reserve-butter: #BFBAA2;\n\n"
        . "    --shape-1: #7A7F9F;\n"
        . "    --shape-2: #8288AA;\n"
        . "    --shape-3: #565D7E;\n"
        . "    --shape-4: #6F7596;\n",
    ],
    [
        '    background-image: linear-gradient(140deg, var(--cat-energy), var(--cat-reserve-rose));',
        "    background-color: var(--shape-1);\n"
        . "    background-image: linear-gradient(140deg, var(--shape-1), color-mix(in srgb, var(--shape-1) 88%, #FFFFFF));",
    ],
    [
        '    background-image: linear-gradient(140deg, var(--cat-mathematics), var(--cat-english));',
        "    background-color: var(--shape-2);\n"
        . "    background-image: linear-gradient(140deg, var(--shape-2), color-mix(in srgb, var(--shape-2) 88%, #FFFFFF));",
    ],
    [
        '    background-image: linear-gradient(140deg, var(--cat-geography), var(--cat-reserve-blue));',
        "    background-color: var(--shape-3);\n"
        . "    background-image: linear-gradient(140deg, var(--shape-3), color-mix(in srgb, var(--shape-3) 88%, #FFFFFF));",
    ],
    [
        '    background-image: linear-gradient(140deg, var(--cat-reserve-butter), var(--cat-energy));',
        "    background-color: var(--shape-4);\n"
        . "    background-image: linear-gradient(140deg, var(--shape-4), color-mix(in srgb, var(--shape-4) 88%, #FFFFFF));",
    ],

    // ---- 7. Small colour details ----
    // H1 underline: text instead of green.
    ["    color: var(--accent-ink);\n    pointer-events: none;", "    color: var(--text);\n    pointer-events: none;"],
    // Pills: border text at 30%, writing in text.
    [
        "    border: 1px solid var(--accent-ink);\n    background: transparent;\n    border-radius: 999px;",
        "    border: 1px solid var(--line);\n"
        . "    border: 1px solid color-mix(in srgb, var(--text) 30%, transparent);\n"
        . "    color: var(--text);\n"
        . "    background: transparent;\n"
        . "    border-radius: 999px;",
    ],
    // Pill label, so a pill is written entirely in the text colour.
    [
        ".stats__label {\n    display: flex;\n    align-items: center;\n    gap: 6px;\n"
        . "    font-family: var(--font-mono);\n    font-size: 11px;\n"
        . "    letter-spacing: 0.08em;\n    text-transform: uppercase;\n    color: var(--muted);\n}",
        ".stats__label {\n    display: flex;\n    align-items: center;\n    gap: 6px;\n"
        . "    font-family: var(--font-mono);\n    font-size: 11px;\n"
        . "    letter-spacing: 0.08em;\n    text-transform: uppercase;\n    color: var(--text);\n}",
    ],
];

foreach ($pairs as $index => [$from, $to]) {
    $count = substr_count($css, $from);

    if ($count !== 1) {
        fwrite(STDERR, 'pair ' . $index . ' matched ' . $count . " times, aborting: " . substr($from, 0, 60) . PHP_EOL);
        exit(1);
    }

    $css = str_replace($from, $to, $css);
}

if (file_put_contents($path, $css) === false) {
    fwrite(STDERR, "could not write css\n");
    exit(1);
}

echo 'all ' . count($pairs) . " replacements applied\n";

// Leftovers that must be gone.
$check = file_get_contents($path);
$gone = [
    'accent-ink' => 'accent-ink',
    '#0F4A43' => '#0F4A43',
    '#6FA2F0' => '#6FA2F0',
    '#F2A98A' => '#F2A98A',
    '#8EE3C8' => '#8EE3C8',
    '#B99FD3' => '#B99FD3',
    '#18181F' => '#18181F',
    '#5E5D6B' => '#5E5D6B',
    '#E6E8F1' => '#E6E8F1',
    'cat colours inside gradients' => 'deg, var(--cat-',
];

foreach ($gone as $label => $needle) {
    echo str_pad($label, 30) . ': ' . substr_count($check, $needle) . PHP_EOL;
}
