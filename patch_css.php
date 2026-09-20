<?php

declare(strict_types=1);

/**
 * One-off patch for public/assets/css/app.css (CRLF file).
 *
 * Anchors the organic shapes to the real content column instead of the maximum
 * width, so they still show a sliver in the page gutter on narrower windows.
 * The 48px clearance is larger than the 40px drift, so a shape can never reach
 * a piece of text.
 *
 * Order matters: the comment quotes the old expression, so it is rewritten
 * before the four rules are.
 */

$path = __DIR__ . '/public/assets/css/app.css';
$css = file_get_contents($path);

if ($css === false) {
    fwrite(STDERR, "could not read css\n");
    exit(1);
}

$eol = strpos($css, "\r\n") !== false ? "\r\n" : "\n";
$oldAnchor = 'calc(50% + var(--max-width) / 2 + 80px)';

$pairs = [
    // A. Give the fixed layer the exact width of .page.
    [
        implode($eol, [
            '.bg-shapes {',
            '    position: fixed;',
            '    inset: 0;',
            '    z-index: -2;',
            '    overflow: clip;',
            '    pointer-events: none;',
            '}',
        ]) . $eol,
        implode($eol, [
            '.bg-shapes {',
            '    position: fixed;',
            '    inset: 0;',
            '    z-index: -2;',
            '    overflow: clip;',
            '    pointer-events: none;',
            '    /*',
            '     * Exactly the width of .page. The shapes are anchored to this, so they',
            '     * always sit just outside the reading column and keep showing a sliver in',
            '     * whatever gutter the window has.',
            '     */',
            '    --column-width: min(var(--max-width), 100% - 2 * var(--page-margin));',
            '}',
        ]) . $eol,
    ],
    // B. Rewrite the explanation while it still quotes the old expression.
    [
        implode($eol, [
            ' * The shapes are anchored to the CONTENT COLUMN, not to the viewport.',
            ' *',
            ' * "' . $oldAnchor . '" is the column edge plus 80px of',
            ' * clearance, and because the real column is never wider than --max-width that',
            ' * expression always lands outside it, at every window width. Nothing that',
            ' * carries text therefore ever sits on a shape, so the shapes cannot reduce',
            ' * text contrast, and the middle of the page stays clear. The extra 80px',
            ' * covers the 40px of drift below.',
        ]) . $eol,
        implode($eol, [
            ' * The shapes are anchored to the CONTENT COLUMN, not to the viewport.',
            ' *',
            ' * "--column-width" is exactly the width of .page, so "half of it plus 48px"',
            ' * is the column edge plus 48px of clearance - at every window width, wide or',
            ' * narrow. That is more than the 40px of drift below, so a shape can never',
            ' * reach anything that carries text: the shapes cannot reduce text contrast,',
            ' * and the middle of the page stays clear.',
        ]) . $eol,
    ],
    // C. Now the four rules themselves.
    [
        $oldAnchor,
        'calc(50% + var(--column-width) / 2 + 48px)',
    ],
];

foreach ($pairs as $index => [$from, $to]) {
    $count = substr_count($css, $from);

    if ($count < 1) {
        fwrite(STDERR, 'pair ' . $index . ' matched 0 times, aborting' . PHP_EOL);
        exit(1);
    }

    $css = str_replace($from, $to, $css);
    echo 'pair ' . $index . ': ' . $count . ' replaced' . PHP_EOL;
}

if (file_put_contents($path, $css) === false) {
    fwrite(STDERR, "could not write css\n");
    exit(1);
}

$check = file_get_contents($path);
echo '--column-width uses : ' . substr_count($check, 'var(--column-width)') . PHP_EOL;
echo 'old anchor left     : ' . substr_count($check, $oldAnchor) . PHP_EOL;
