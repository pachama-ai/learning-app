<?php
/*
 * Temporary patch script (deleted right after it runs).
 *
 * The [01] marker in the top-right of a tile sits on the strongest colour wash,
 * where the plain --muted only reaches 4.41:1. Mixing 12% of the near-black
 * text colour into it clears 4.5:1 while staying visually muted. The plain
 * declaration stays first as the fallback for browsers without color-mix().
 */
declare(strict_types=1);

$path = __DIR__ . '/public/assets/css/app.css';
$css = file_get_contents($path);
$eol = strpos($css, "\r\n") !== false ? "\r\n" : "\n";

$old = implode($eol, [
    '.area-card__index {',
    '    flex: 0 0 auto;',
    '    font-family: var(--font-mono);',
    '    font-size: 11px;',
    '    letter-spacing: 0.04em;',
    '    color: var(--muted);',
]);
$new = implode($eol, [
    '.area-card__index {',
    '    flex: 0 0 auto;',
    '    font-family: var(--font-mono);',
    '    font-size: 11px;',
    '    letter-spacing: 0.04em;',
    '    color: var(--muted);',
    '    color: color-mix(in srgb, var(--muted) 88%, var(--text));',
]);

$count = substr_count($css, $old);
echo "matches={$count}\n";
if ($count !== 1) {
    fwrite(STDERR, "Expected exactly one match - aborting without writing.\n");
    exit(1);
}

file_put_contents($path, str_replace($old, $new, $css));

$check = file_get_contents($path);
echo 'mix count: ' . substr_count($check, 'color-mix(in srgb, var(--muted) 88%, var(--text))') . " (expect 2)\n";
echo 'CRLF preserved: ' . (substr_count($check, "\r\n") > 1000 ? 'yes' : 'no') . "\n";
echo 'bytes: ' . strlen($check) . "\n";
