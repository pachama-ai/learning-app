<?php

declare(strict_types=1);

/**
 * Third colour-only patch.
 *
 * In dark mode --surface was a translucent white wash, so anything written in
 * --text on top of it (the active sidebar entry, the hover state of the theme
 * toggle and of the primary dialog button, the highlight after adding an item)
 * only reached 4.06:1. A translucent dark wash instead of a light one lifts
 * that to about 6.6:1 and keeps the chip a subtle tonal step away from the
 * background. Geometry is untouched: it is still the same 10% - only the
 * colour it mixes with changed.
 */

$path = __DIR__ . '/public/assets/css/app.css';
$raw = file_get_contents($path);

if ($raw === false) {
    fwrite(STDERR, "could not read css\n");
    exit(1);
}

$css = str_replace("\r\n", "\n", $raw);

$from = '    --surface: rgba(255, 255, 255, 0.10);';
$to = "    /*\n"
    . "     * A dark wash, not a light one: light text on a lightened slate only\n"
    . "     * reached 4.06:1. Darkening the chip keeps it a subtle step away from\n"
    . "     * the background and lifts the text to about 6.6:1.\n"
    . "     */\n"
    . '    --surface: rgba(20, 22, 34, 0.22);';

if (substr_count($css, $from) !== 1) {
    fwrite(STDERR, "surface anchor matched " . substr_count($css, $from) . " times, aborting\n");
    exit(1);
}

$css = str_replace($from, $to, $css);

if (file_put_contents($path, $css) === false) {
    fwrite(STDERR, "could not write css\n");
    exit(1);
}

echo "surface updated\n";
echo 'leftover light wash: ' . substr_count($css, 'rgba(255, 255, 255, 0.10)') . " (must be 0)\n";
