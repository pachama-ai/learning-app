<?php

declare(strict_types=1);

/**
 * One-line correction: the third pool was anchored to the right edge of the
 * VIEWPORT, so at 1440px and below it reached into the content column and sat
 * behind tile text. It is anchored to the content column now, like the other
 * two, which keeps it in the right gutter at every width.
 */

$path = __DIR__ . '/public/assets/css/app.css';
$raw = file_get_contents($path);

if ($raw === false) {
    fwrite(STDERR, "cannot read css\n");
    exit(1);
}

$from = "    top: calc(66% - 13vw);\n    right: -13vw;\n";
$to = "    top: calc(66% - 13vw);\n    left: calc(50% + var(--column-width) / 2 + 64px);\n";
$count = substr_count($raw, $from);

if ($count !== 1) {
    fwrite(STDERR, 'matched ' . $count . " times, aborting\n");
    exit(1);
}

if (file_put_contents($path, str_replace($from, $to, $raw)) === false) {
    fwrite(STDERR, "cannot write css\n");
    exit(1);
}

echo "blob C anchored to the content column\n";
