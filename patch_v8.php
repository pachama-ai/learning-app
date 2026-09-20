<?php

declare(strict_types=1);

/**
 * V8: taller tiles + the light-theme contrast values.
 *
 * Layout and colour only, and only in app.css: no markup, no JavaScript, no
 * backend. Every replacement asserts a single occurrence.
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
    /* ---- light: a deeper vertical gradient, pure white writing ------------- */
    [
        <<<'CSS'
    --bg: #606887;
    --bg-image: none;
    --text: #F6F5F2;
    --muted: #EDEFF6;
CSS,
        <<<'CSS'
    --bg: #525A79;
    --bg-image: linear-gradient(180deg, #5F6788, #525A79);
    --text: #FFFFFF;
    --muted: #F1F3FA;
CSS,
    ],

    /* ---- light: tiles you can actually feel --------------------------------- */
    [
        <<<'CSS'
    --tile-bg: rgba(255, 255, 255, 0.10);
    --tile-bg-hover: rgba(255, 255, 255, 0.16);
    --tile-ring: rgba(255, 255, 255, 0.22);
    --icon-circle: rgba(255, 255, 255, 0.16);
CSS,
        <<<'CSS'
    --tile-bg: rgba(255, 255, 255, 0.16);
    --tile-bg-hover: rgba(255, 255, 255, 0.22);
    --tile-ring: rgba(255, 255, 255, 0.30);
    --icon-circle: rgba(255, 255, 255, 0.22);
    /* The shadow that lifts a tile off the slate. */
    --tile-shadow: 0 10px 30px rgba(20, 25, 55, 0.18);
CSS,
    ],

    /* ---- dark: untouched, it only needs the neutral shadow value ------------ */
    [
        <<<'CSS'
    --icon-circle: rgba(255, 255, 255, 0.10);
    --surface: var(--tile-bg);
CSS,
        <<<'CSS'
    --icon-circle: rgba(255, 255, 255, 0.10);
    /* No drop shadow here: the dark tiles stay exactly as they were. */
    --tile-shadow: 0 0 0 rgba(0, 0, 0, 0);
    --surface: var(--tile-bg);
CSS,
    ],

    /* ---- light: stronger light pools at the edges --------------------------- */
    [
        <<<'CSS'
    --blob-a: rgba(58, 104, 128, 0.55);
    --blob-b: rgba(80, 140, 190, 0.60);
CSS,
        <<<'CSS'
    --blob-a: rgba(58, 104, 128, 0.66);
    --blob-b: rgba(80, 140, 190, 0.72);
CSS,
    ],

    /* ---- one spacing for header -> bar -> tiles ----------------------------- */
    [
        <<<'CSS'
.view--start {
    display: flex;
    flex-direction: column;
    flex: 1 1 auto;
}
CSS,
        <<<'CSS'
.view--start {
    display: flex;
    flex-direction: column;
    flex: 1 1 auto;
    /* The one spacing of this view: header -> distribution bar -> tiles. */
    gap: 64px;
}
CSS,
    ],
    [
        <<<'CSS'
    gap: clamp(24px, 4vw, 64px);
    padding-bottom: clamp(24px, 3vw, 40px);
}
CSS,
        <<<'CSS'
    gap: clamp(24px, 4vw, 64px);
    /* No bottom padding of its own: .view--start's gap is the spacing. */
    padding-bottom: 0;
}
CSS,
    ],
    [
        <<<'CSS'
.distribution-block {
    display: flex;
    flex-direction: column;
    gap: 0;
    padding-bottom: clamp(20px, 3vw, 36px);
}
CSS,
        <<<'CSS'
.distribution-block {
    display: flex;
    flex-direction: column;
    gap: 0;
    /* As above: the spacing comes from .view--start. */
    padding-bottom: 0;
}
CSS,
    ],

    /* ---- the tiles take the free height: 340px floor, 460px ceiling --------- */
    [
        <<<'CSS'
.area-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    column-gap: clamp(16px, 2.2vw, 28px);
    row-gap: clamp(16px, 2.2vw, 28px);
    /* Takes the free space on the page, but the tiles keep their own height
       instead of being stretched to the bottom. */
    flex: 1 1 auto;
    align-content: start;
}
CSS,
        <<<'CSS'
.area-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 20px;
    /*
     * The tiles take the free height of the page: never under 340px, never over
     * 460px. The grid box is what grows, the single row follows it, so a tile
     * can be neither taller than the ceiling nor shorter than the floor.
     */
    flex: 1 1 auto;
    align-content: stretch;
    grid-auto-rows: minmax(340px, 1fr);
    min-height: 340px;
    max-height: 460px;
}
CSS,
    ],
    [
        <<<'CSS'
    .area-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
CSS,
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
    ],

    /* ---- the tile: 28px of air and a real elevation ------------------------- */
    [
        <<<'CSS'
    display: flex;
    flex-direction: column;
    gap: 10px;
    padding: 18px 16px 16px;
    border: 0;
    border-radius: var(--radius-tile);
    background-color: var(--tile-bg);
    box-shadow: inset 0 0 0 1px var(--tile-ring);
CSS,
        <<<'CSS'
    display: flex;
    flex-direction: column;
    /*
     * Two zones. The index and the icon sit at the top, everything that carries
     * writing is pushed to the bottom by "margin-top: auto" on the name, and the
     * space in between is the air a taller tile gains.
     */
    gap: 10px;
    padding: 28px;
    border: 0;
    border-radius: var(--radius-tile);
    background-color: var(--tile-bg);
    box-shadow: inset 0 0 0 1px var(--tile-ring), var(--tile-shadow);
CSS,
    ],

    /* ---- top zone: a bigger icon circle and a bigger drawing ---------------- */
    [
        <<<'CSS'
    width: 64px;
    height: 64px;
    border-radius: 52% 48% 44% 56% / 48% 52% 48% 52%;
CSS,
        <<<'CSS'
    width: 88px;
    height: 88px;
    border-radius: 52% 48% 44% 56% / 48% 52% 48% 52%;
CSS,
    ],
    [
        <<<'CSS'
.blob__icon {
    width: 30px;
    height: 30px;
CSS,
        <<<'CSS'
.blob__icon {
    width: 40px;
    height: 40px;
CSS,
    ],

    /* ---- bottom zone starts at the name ------------------------------------ */
    [
        <<<'CSS'
.area-card__name {
    font-size: 1.3rem;
CSS,
        <<<'CSS'
.area-card__name {
    /* Everything from here down is the bottom zone; the free height collects
       above it, between the icon and the title. */
    margin-top: auto;
    font-size: 1.3rem;
CSS,
    ],
    [
        <<<'CSS'
.area-card__progress {
    height: 2px;
    margin-top: auto;
    background-color: var(--hairline);
}
CSS,
        <<<'CSS'
.area-card__progress {
    height: 2px;
    /* No "margin-top: auto" any more: the name owns the free space now, so the
       title, the description, the track and the data line stay one group. */
    background-color: var(--hairline);
}
CSS,
    ],
];

foreach ($pairs as $index => [$from, $to]) {
    $count = substr_count($css, $from);

    if ($count !== 1) {
        fwrite(STDERR, 'pair ' . $index . ' matched ' . $count . " times:\n" . substr($from, 0, 160) . "\n\n");
        exit(1);
    }

    $css = str_replace($from, $to, $css);
}

$out = $eol === "\n" ? $css : str_replace("\n", $eol, $css);

if (file_put_contents($path, $out) === false) {
    fwrite(STDERR, "cannot write css\n");
    exit(1);
}

echo 'app.css: ' . count($pairs) . " replacements ok\n";
