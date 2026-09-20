<?php

declare(strict_types=1);

/**
 * V8 follow-up: the 460px ceiling belongs to the single-row desktop layout only,
 * and the comments should carry the numbers that were actually measured.
 */

$path = __DIR__ . '/public/assets/css/app.css';
$raw = file_get_contents($path);

if ($raw === false) {
    fwrite(STDERR, "cannot read css\n");
    exit(1);
}

$eol = str_contains($raw, "\r\n") ? "\r\n" : "\n";
$css = str_replace("\r\n", "\n", $raw);

$pairs = [
    /* Ceiling off as soon as there is more than one row. */
    [
        <<<'CSS'
    /*
     * Two columns mean two rows. The rows size to their content between the same
     * floor and ceiling, and the grid is no longer capped, so nothing is squashed.
     */
    .area-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
        grid-auto-rows: minmax(340px, 460px);
        max-height: none;
    }
CSS,
        <<<'CSS'
    /*
     * Two columns mean more than one row. Here the rows keep the 340px floor and
     * grow only with their content - the 460px ceiling belongs to the single-row
     * desktop layout, otherwise a phone would get four 460px slabs to scroll
     * through.
     */
    .area-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
        grid-auto-rows: minmax(340px, auto);
        max-height: none;
    }
CSS,
    ],

    /* Document the contrast trade-off where the value lives. */
    [
        <<<'CSS'
    /*
     * The three white washes a tile is made of: the surface, its hairline ring
     * and the circle behind the subject drawing. The strength is the only thing
     * that differs between the themes - a tile is NEVER tinted with a category
     * colour.
     */
CSS,
        <<<'CSS'
    /*
     * The three white washes a tile is made of: the surface, its hairline ring
     * and the circle behind the subject drawing. The strength is the only thing
     * that differs between the themes - a tile is NEVER tinted with a category
     * colour.
     *
     * NOTE: because the wash is LIGHTER than the slate behind it, white writing
     * on a tile measures about 3.9:1, while the same white is 5.6:1 on the page
     * background itself. Only a weaker wash (or a dark one instead of a white
     * one) would lift the tile to 4.5:1 - the two move in opposite directions.
     */
CSS,
    ],
    [
        <<<'CSS'
    /*
     * --text, not --muted: the tile is LIGHTER than the page background, so this
     * is the strongest colour available. --text reaches about 4.1:1 here and
     * 5.0:1 on the background itself; --muted would only reach about 3.9:1.
     */
CSS,
        <<<'CSS'
    /*
     * --text, not --muted: the tile is lighter than the page background, so the
     * text colour is the strongest option there is. Measured: #FFFFFF reaches
     * 5.6:1 on the background and 3.9:1 on the tile, --muted would be 3.6:1 on
     * the tile. See the note on --tile-bg above.
     */
CSS,
    ],
];

foreach ($pairs as $index => [$from, $to]) {
    $count = substr_count($css, $from);

    if ($count !== 1) {
        fwrite(STDERR, 'pair ' . $index . ' matched ' . $count . " times\n");
        exit(1);
    }

    $css = str_replace($from, $to, $css);
}

$out = $eol === "\n" ? $css : str_replace("\n", $eol, $css);

if (file_put_contents($path, $out) === false) {
    fwrite(STDERR, "cannot write css\n");
    exit(1);
}

echo 'app.css: ' . count($pairs) . " follow-up replacements ok\n";
