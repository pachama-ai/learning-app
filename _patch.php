<?php
/*
 * Temporary patch script. app.css uses CRLF line endings, which the editor
 * tooling fails to persist, so the two small fixes below are applied here and
 * this file is deleted again immediately afterwards.
 */
declare(strict_types=1);

$path = __DIR__ . '/public/assets/css/app.css';
$css = file_get_contents($path);
$eol = strpos($css, "\r\n") !== false ? "\r\n" : "\n";

/*
 * 1. The linter asks for the standard "line-clamp" next to the -webkit- one.
 *    The -webkit- property is what Chromium/Safari need; the standard property
 *    is added for browsers that have moved on. Declaring both is safe.
 */
$old1 = implode($eol, [
    '    display: -webkit-box;',
    '    -webkit-line-clamp: 2;',
    '    -webkit-box-orient: vertical;',
]);
$new1 = implode($eol, [
    '    display: -webkit-box;',
    '    -webkit-line-clamp: 2;',
    '    line-clamp: 2;',
    '    -webkit-box-orient: vertical;',
]);

/*
 * 2. Contrast fix. The description sits on a colour-washed tile, and the wash
 *    darkens the surface behind it, so the plain --muted only reaches 4.41:1
 *    there. Mixing 12% of the near-black text colour into it clears 4.5:1.
 *    The plain declaration stays first as the fallback for browsers that do
 *    not understand color-mix().
 */
$old2 = implode($eol, [
    '    min-height: calc(2 * 1.5em);',
    '    color: var(--muted);',
]);
$new2 = implode($eol, [
    '    min-height: calc(2 * 1.5em);',
    '    color: var(--muted);',
    '    color: color-mix(in srgb, var(--muted) 88%, var(--text));',
]);

$count1 = substr_count($css, $old1);
$count2 = substr_count($css, $old2);
echo "match1={$count1} match2={$count2}\n";

if ($count1 !== 1 || $count2 !== 1) {
    fwrite(STDERR, "Expected exactly one match each - aborting without writing.\n");
    exit(1);
}

$css = str_replace($old1, $new1, $css);
$css = str_replace($old2, $new2, $css);
file_put_contents($path, $css);

// Verify on disk, not in memory.
$check = file_get_contents($path);
echo 'line-clamp standard: ' . (substr_count($check, 'line-clamp: 2;') === 1 ? 'OK' : 'FAIL') . "\n";
echo 'contrast mix: ' . (substr_count($check, 'color-mix(in srgb, var(--muted) 88%, var(--text))') === 1 ? 'OK' : 'FAIL') . "\n";
echo 'still CRLF: ' . (substr_count($check, "\r\n") > 1000 ? 'OK' : 'FAIL') . "\n";
echo 'bytes: ' . strlen($check) . "\n";
