<?php

declare(strict_types=1);

/**
 * Second colour-only patch: the two contrast fixes that the measurements
 * showed are needed. Same rules as before - colour values only.
 */

$path = __DIR__ . '/public/assets/css/app.css';
$raw = file_get_contents($path);

if ($raw === false) {
    fwrite(STDERR, "could not read css\n");
    exit(1);
}

$css = str_replace("\r\n", "\n", $raw);

$pairs = [
    // Light: the tile description measured 3.90-4.12:1 in --muted, below AA.
    // --text gives roughly 10:1 on every tinted tile.
    [
        "    min-height: calc(2 * 1.5em);\n    color: var(--muted);\n    font-size: 0.9rem;",
        "    min-height: calc(2 * 1.5em);\n"
        . "    /*\n"
        . "     * --text, not --muted: on the tinted tiles --muted only reaches about\n"
        . "     * 3.9:1, while --text reaches about 10:1.\n"
        . "     */\n"
        . "    color: var(--text);\n"
        . "    font-size: 0.9rem;",
    ],
    // Same reason for the small "[01]" marker on the tile. The anchor includes
    // the selector, because the detail rows share the same three lines.
    [
        ".area-card__index {\n    flex: 0 0 auto;\n    font-family: var(--font-mono);\n"
        . "    font-size: 11px;\n    letter-spacing: 0.04em;\n    color: var(--muted);\n"
        . "    font-variant-numeric: tabular-nums;\n}",
        ".area-card__index {\n    flex: 0 0 auto;\n    font-family: var(--font-mono);\n"
        . "    font-size: 11px;\n    letter-spacing: 0.04em;\n"
        . "    /* Same reason as the description: AA on the tinted tile. */\n"
        . "    color: var(--text);\n"
        . "    font-variant-numeric: tabular-nums;\n}",
    ],
    // Dark: --muted measured 4.16:1 on the #606887 backdrop, below AA.
    // #E9EBF4 measures 4.61:1 there and stays clearly softer than --text.
    ['    --muted: #DDE0EB;', '    --muted: #E9EBF4;'],
];

foreach ($pairs as $index => [$from, $to]) {
    $count = substr_count($css, $from);

    if ($count !== 1) {
        fwrite(STDERR, 'pair ' . $index . ' matched ' . $count . " times, aborting\n");
        exit(1);
    }

    $css = str_replace($from, $to, $css);
}

if (file_put_contents($path, $css) === false) {
    fwrite(STDERR, "could not write css\n");
    exit(1);
}

echo 'applied ' . count($pairs) . " contrast fixes\n";
echo 'muted on tiles (light) : ' . substr_count($css, "    color: var(--muted);\n    font-size: 0.9rem;") . " (must be 0)\n";
echo 'dark muted #DDE0EB      : ' . substr_count($css, '#DDE0EB') . " (must be 0)\n";
echo 'dark muted #E9EBF4      : ' . substr_count($css, '#E9EBF4') . " (must be 1)\n";
