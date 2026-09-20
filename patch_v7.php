<?php

declare(strict_types=1);

/**
 * Colour-and-surface patch for the new design language.
 *
 * Three targets, all edited through assertions: a replacement only happens when
 * its source text occurs EXACTLY once, so a mismatch stops the run instead of
 * half-applying it.
 *
 *   1. public/assets/css/app.css   (LF)    tokens, tiles, pools, grain, accent
 *   2. public/index.php            (CRLF)  markup: pool layer + grain layer
 *   3. src/helpers/translations.php (CRLF) the shortened tile data label
 *
 * No layout, no spacing, no radii, no durations, no keyframes are changed.
 */

$root = __DIR__;

/**
 * Replaces $from with $to in $file, asserting a single occurrence.
 *
 * @param array<int, array{0: string, 1: string}> $pairs
 */
function patch(string $file, array $pairs, ?string $forceEol = null): void
{
    $raw = file_get_contents($file);

    if ($raw === false) {
        fwrite(STDERR, 'cannot read ' . $file . PHP_EOL);
        exit(1);
    }

    $eol = str_contains($raw, "\r\n") ? "\r\n" : "\n";
    $css = str_replace("\r\n", "\n", $raw);

    foreach ($pairs as $index => [$from, $to]) {
        $count = substr_count($css, $from);

        if ($count !== 1) {
            fwrite(STDERR, basename($file) . ' pair ' . $index . ' matched ' . $count . " times:\n"
                . substr($from, 0, 200) . "\n\n");
            exit(1);
        }

        $css = str_replace($from, $to, $css);
    }

    $out = $forceEol === "\n" ? $css : str_replace("\n", $eol, $css);

    if (file_put_contents($file, $out) === false) {
        fwrite(STDERR, 'cannot write ' . $file . PHP_EOL);
        exit(1);
    }

    echo basename($file) . ': ' . count($pairs) . " replacements ok\n";
}

/* ==========================================================================
   1. app.css
   ========================================================================== */

/* Dark theme tokens first: the light block also contains "#606887" afterwards,
   so the dark lines have to be consumed before that value exists twice. */
$darkTokensOld = <<<'CSS'
    /* A slate blue, deliberately not almost black. */
    --bg: #606887;
    --text: #F4F5F9;
    /* Bright on purpose: the small monospace labels must stay legible here. */
    --muted: #E9EBF4;
    --line: rgba(244, 245, 249, 0.28);
    --line-strong: rgba(244, 245, 249, 0.35);
    /*
     * A dark wash, not a light one: light text on a lightened slate only
     * reached 4.06:1. Darkening the chip keeps it a subtle step away from
     * the background and lifts the text to about 6.6:1.
     */
    --surface: rgba(20, 22, 34, 0.22);
    --plus-bg: #F4F5F9;
    --plus-fg: #3E4460;

    --cat-mathematics: #A5AECB;
    --cat-energy: #C2B3A3;
    --cat-geography: #A6B7AE;
    --cat-english: #B8AAC4;

    /* Lighter companions for the reserves, so they read on the slate. */
    --cat-reserve-blue: #ADB6D1;
    --cat-reserve-rose: #C0AEB6;
    --cat-reserve-butter: #BFBAA2;

    --shape-1: #7A7F9F;
    --shape-2: #8288AA;
    --shape-3: #565D7E;
    --shape-4: #6F7596;
CSS;

$darkTokensNew = <<<'CSS'
    /*
     * The same slate family as the light theme, clearly darker, and as a vertical
     * gradient. --bg stays the plain darker end because the tooltip and the
     * dialog panel need an opaque colour; --bg-image is what paints.
     */
    --bg: #20243A;
    --bg-image: linear-gradient(180deg, #282D44, #20243A);
    --text: #F1F2F8;
    --muted: #BCC1D6;
    --line: rgba(241, 242, 248, 0.20);
    --line-strong: rgba(241, 242, 248, 0.32);

    /* The same three white washes, quieter than in the light theme. */
    --tile-bg: rgba(255, 255, 255, 0.06);
    --tile-bg-hover: rgba(255, 255, 255, 0.10);
    --tile-ring: rgba(255, 255, 255, 0.14);
    --icon-circle: rgba(255, 255, 255, 0.10);
    --surface: var(--tile-bg);

    --accent: #8FD3E6;
    --accent-ink: #1A2238;
    --plus-bg: var(--accent);
    --plus-fg: var(--accent-ink);

    --cat-mathematics: #A9AFC6;
    --cat-energy: #B5ADA5;
    --cat-geography: #A5B0AB;
    --cat-english: #B0A8B6;

    /* Near-neutral companions for the reserves, so they read on the dark sky. */
    --cat-reserve-blue: #ADB3C8;
    --cat-reserve-rose: #B8B0AC;
    --cat-reserve-butter: #B6B3A6;

    /* The three light pools, a little stronger than in the light theme. */
    --blob-a: rgba(58, 110, 140, 0.60);
    --blob-b: rgba(80, 140, 200, 0.45);
    --blob-c: rgba(120, 135, 200, 0.28);
CSS;

$lightTokensOld = <<<'CSS'
    /* ---- The colour system of this design ---- */
    --bg: #ECEAF0;
    --text: #1C1D26;
    --muted: #5C5F70;
    --line: rgba(28, 29, 38, 0.16);
    --line-strong: rgba(28, 29, 38, 0.25);
    --surface: rgba(255, 255, 255, 0.55);
    --plus-bg: #1C1D26;
    --plus-fg: #F4F5F9;

    /* ---- Category colours ---- */
    --cat-mathematics: #6F7A9B;
    --cat-energy: #9A8B7C;
    --cat-geography: #7F9088;
    --cat-english: #8D819B;

    /* Reserve colours for categories that are not one of the four above. */
    --cat-reserve-blue: #7A85A4;
    --cat-reserve-rose: #95818B;
    --cat-reserve-butter: #948F76;

    /*
     * The four background shapes. Position, size, blur, opacity and drift
     * are unchanged; only these base tones are new, so the shapes read as one
     * blue-grey family instead of peach, mint, blue and beige.
     */
    --shape-1: #D9D6E3;
    --shape-2: #C9CEE3;
    --shape-3: #CBD0E0;
    --shape-4: #DDD9DF;
CSS;

$lightTokensNew = <<<'CSS'
    /* ---- The colour system of this design ---- */
    /*
     * The mid-tone slate. The light theme is this one flat colour and it is the
     * default of the design; the dark theme paints a vertical gradient over the
     * same family.
     */
    --bg: #606887;
    --bg-image: none;
    --text: #F6F5F2;
    --muted: #EDEFF6;
    --line: rgba(246, 245, 242, 0.28);
    --line-strong: rgba(246, 245, 242, 0.40);

    /*
     * The three white washes a tile is made of: the surface, its hairline ring
     * and the circle behind the subject drawing. The strength is the only thing
     * that differs between the themes - a tile is NEVER tinted with a category
     * colour.
     */
    --tile-bg: rgba(255, 255, 255, 0.10);
    --tile-bg-hover: rgba(255, 255, 255, 0.16);
    --tile-ring: rgba(255, 255, 255, 0.22);
    --icon-circle: rgba(255, 255, 255, 0.16);
    /* Older name for the tile wash, kept so the rest of the sheet stays readable. */
    --surface: var(--tile-bg);

    /*
     * Aqua. It is used by exactly three things: the plus button, the underline
     * of the selected language and the focus ring.
     */
    --accent: #A8DDEB;
    --accent-ink: #1F2A44;
    --plus-bg: var(--accent);
    --plus-fg: var(--accent-ink);

    /*
     * ---- Category colours, deliberately near neutral ----
     * They carry the segmented bar and the dots inside the pills, nothing else.
     */
    --cat-mathematics: #C3C8DB;
    --cat-energy: #CFC8C0;
    --cat-geography: #C0CAC5;
    --cat-english: #CBC3D0;

    /* Near-neutral companions for categories beyond the four above. */
    --cat-reserve-blue: #C4CADA;
    --cat-reserve-rose: #CFC6C6;
    --cat-reserve-butter: #CBC7BB;

    /*
     * The three light pools: one wide, soft radial gradient each, blurred so
     * they have no visible edge at all. Only their drift is animated.
     */
    --blob-a: rgba(58, 104, 128, 0.55);
    --blob-b: rgba(80, 140, 190, 0.60);
    --blob-c: rgba(150, 200, 220, 0.16);
CSS;

patch($root . '/public/assets/css/app.css', [
    /* --- tokens --- */
    [$darkTokensOld, $darkTokensNew],
    [$lightTokensOld, $lightTokensNew],

    /* --- the light theme's scrim and icon filter --- */
    [
        <<<'CSS'
    --backdrop: rgba(24, 24, 31, 0.32);

    --icon-filter: none;
CSS,
        <<<'CSS'
    --backdrop: rgba(20, 23, 36, 0.50);

    /*
     * The subject drawings are <img> files with baked-in colours, so they cannot
     * use currentColor. Pushing them to white is the closest equivalent of
     * "icons in --text" - and they need it in BOTH themes now, because the light
     * theme is the mid-tone slate. The inline moon, sun, plus and arrows are NOT
     * filtered: they already use currentColor.
     */
    --icon-filter: brightness(0) invert(1);
CSS,
    ],

    /* --- the dark theme's scrim and icon filter --- */
    [
        <<<'CSS'
    --backdrop: rgba(20, 22, 34, 0.45);

    /*
     * The subject icons are black line art with baked-in colours, so dark mode
     * inverts them instead of editing the SVG files. The inline moon and sun of
     * the theme control are NOT filtered - they already use currentColor.
     */
    --icon-filter: invert(1) brightness(1.05);
CSS,
        <<<'CSS'
    --backdrop: rgba(12, 14, 24, 0.55);

    /* The subject icons are pushed to white as well; see :root. */
    --icon-filter: brightness(0) invert(1);
CSS,
    ],

    /* --- the root element paints the colour and, in dark mode, the gradient --- */
    [
        <<<'CSS'
 * layers below (background shapes and film grain) use a negative z-index, and
 * those paint above the canvas background but below the in-flow content - which
 * only holds while <body> paints no background of its own.
 */
html {
    background-color: var(--bg);
    transition: background-color 300ms var(--ease);
}
CSS,
        <<<'CSS'
 * layers below (the light pools and the film grain) use a negative z-index, and
 * those paint above the canvas background but below the in-flow content - which
 * only holds while <body> paints no background of its own.
 *
 * The gradient of the dark theme lives here too, on the root element, so the
 * pools still paint on top of it. The light theme has no gradient at all.
 */
html {
    background-color: var(--bg);
    background-image: var(--bg-image, none);
    transition: background-color 300ms var(--ease);
}
CSS,
    ],

    /* --- grain: its own layer, above everything, lighter in dark mode --- */
    [
        <<<'CSS'
/*
 * Static film grain over the background and over the translucent category
 * surfaces, and below the content.
 *
 * It is one fixed, repeating SVG noise tile: no animation and no full-window
 * filter, so text stays sharp and nothing can begin to scroll. "overlay" lets
 * the grain follow whatever it sits on instead of washing the colours out.
 * z-index -1 puts it above the canvas background and above .bg-shapes (-2), and
 * still below every piece of real content.
 */
body::before {
    content: "";
    position: fixed;
    inset: 0;
    z-index: -1;
    pointer-events: none;
    background-image: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='220' height='220'><filter id='noise'><feTurbulence type='fractalNoise' baseFrequency='.85' numOctaves='3' stitchTiles='stitch'/><feColorMatrix type='saturate' values='0'/></filter><rect width='100%' height='100%' filter='url(%23noise)'/></svg>");
    background-size: 220px 220px;
    mix-blend-mode: overlay;
    opacity: 0.22;
}

[data-theme="dark"] body::before {
    opacity: 0.28;
}
CSS,
        <<<'CSS'
/*
 * Film grain. One fixed, repeating SVG noise tile - no animation and no
 * full-window filter - so text stays sharp and nothing can start to scroll.
 *
 * It is its own element in the markup, sits in a fixed layer with z-index 9999
 * and therefore covers everything, and it can never be clicked or scrolled
 * because it takes no pointer events.
 */
.grain {
    position: fixed;
    inset: 0;
    z-index: 9999;
    pointer-events: none;
    background-image: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='220' height='220'><filter id='noise'><feTurbulence type='fractalNoise' baseFrequency='.85' numOctaves='3' stitchTiles='stitch'/><feColorMatrix type='saturate' values='0'/></filter><rect width='100%' height='100%' filter='url(%23noise)'/></svg>");
    background-size: 220px 220px;
    opacity: 0.20;
}

/* The same texture, inverted: light grains instead of dark ones. */
[data-theme="dark"] .grain {
    opacity: 0.14;
    filter: invert(1);
}
CSS,
    ],

    /* --- focus ring in the accent --- */
    [
        <<<'CSS'
/* One visible focus ring for everything interactive, including keyboard use. */
:focus-visible {
    outline: 2px solid var(--text);
CSS,
        <<<'CSS'
/* One visible focus ring for everything interactive, including keyboard use. */
:focus-visible {
    outline: 2px solid var(--accent);
CSS,
    ],

    /* --- the layer of light pools replaces the layer of organic shapes --- */
    [
        <<<'CSS'
/*
 * Full-viewport layer of large organic shapes. "position: fixed" with "inset: 0"
 * makes the layer exactly as large as the viewport, so a shape can bleed past the
 * content column and past the window edges and is never cut off at a content
 * boundary. "overflow: clip" confines them to the layer, which is also what stops
 * them from creating horizontal scrolling.
 *
 * z-index -2 puts the layer above the canvas background (painted by <html>) and
 * below the film grain (-1), the translucent category surfaces and the content.
 * The shapes sit at the window edges so the reading column keeps a clear
 * background and text contrast is never reduced.
 */
.bg-shapes {
    position: fixed;
    inset: 0;
    z-index: -2;
    overflow: clip;
    pointer-events: none;
}

.background-shape {
    position: absolute;
    display: block;
    width: 46vw;
    aspect-ratio: 1;
    opacity: .55;
    /* A soft organic silhouette; there is no shape morphing here. */
    border-radius: 62% 38% 46% 54% / 54% 46% 58% 42%;
    /* The glossy highlight: one thin white line along the inner edge. */
    box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.4);
    /*
     * Only "transform" is animated. Animating a size or an offset instead would
     * make the layer wider than the viewport and produce a horizontal
     * scrollbar.
     */
    animation: background-drift 40s ease-in-out infinite alternate;
}

/*
 * The shapes are anchored to the CONTENT COLUMN, not to the viewport.
 *
 * "calc(50% + var(--max-width) / 2 + 60px)" is the column edge plus 60px of
 * clearance - and because the real column is never wider than --max-width, that
 * expression always lands outside it, at every window width. Nothing that
 * carries text therefore ever sits on top of a shape, so the shapes cannot
 * reduce text contrast, and the middle of the page stays clear. The extra 60px
 * covers the 40px drift below.
 */

/* Upper left, bleeding past the top and left edges. Peach to rose. */
.background-shape--1 {
    top: -22vw;
    right: calc(50% + var(--max-width) / 2 + 60px);
    background-color: var(--shape-1);
    background-image: linear-gradient(140deg, var(--shape-1), color-mix(in srgb, var(--shape-1) 88%, #FFFFFF));
}

/* Right side, level with the category tiles. Blue to lavender. */
.background-shape--2 {
    top: 10vh;
    left: calc(50% + var(--max-width) / 2 + 60px);
    background-color: var(--shape-2);
    background-image: linear-gradient(140deg, var(--shape-2), color-mix(in srgb, var(--shape-2) 88%, #FFFFFF));
    animation-duration: 46s;
    animation-delay: -8s;
}

/* Lower left. Mint to pale blue. */
.background-shape--3 {
    bottom: -30vw;
    right: calc(50% + var(--max-width) / 2 + 60px);
    background-color: var(--shape-3);
    background-image: linear-gradient(140deg, var(--shape-3), color-mix(in srgb, var(--shape-3) 88%, #FFFFFF));
    animation-duration: 52s;
    animation-delay: -18s;
}

/* One quiet extra shape, low and to the right. Butter to peach. */
.background-shape--4 {
    bottom: -24vw;
    left: calc(50% + var(--max-width) / 2 + 60px);
    width: 34vw;
    background-color: var(--shape-4);
    background-image: linear-gradient(140deg, var(--shape-4), color-mix(in srgb, var(--shape-4) 88%, #FFFFFF));
    opacity: .4;
    animation-duration: 58s;
    animation-delay: -26s;
}

[data-theme="dark"] .background-shape {
    opacity: .35;
}

/* Keeps the fourth shape the quietest one in both themes. */
[data-theme="dark"] .background-shape--4 {
    opacity: .26;
}
CSS,
        <<<'CSS'
/*
 * Full-viewport layer of three light pools. "position: fixed" with "inset: 0"
 * makes the layer exactly as large as the viewport, so a pool can bleed past the
 * content column and past the window edges and is never cut off at a content
 * boundary. "overflow: clip" confines the pools to the layer, which is also what
 * stops them from creating horizontal scrolling.
 *
 * z-index -1 puts the layer above the canvas background - the flat slate of the
 * light theme and the gradient of the dark theme, both painted by <html> - and
 * below the grain (9999), the tiles and the content.
 *
 * The pools sit at the window edges so the reading column keeps a clear
 * background and no text ever ends up on top of one.
 */
.bg-layer {
    position: fixed;
    inset: 0;
    z-index: -1;
    overflow: clip;
    pointer-events: none;
    /* The real content column, so a pool can always be kept outside it. */
    --column-width: min(var(--max-width), 100% - 2 * var(--page-margin));
}

/*
 * A pool is nothing but a wide radial gradient, blurred until it has no edge.
 * Only "transform" is animated: animating a size or an offset instead would make
 * the layer wider than the viewport and produce a horizontal scrollbar.
 */
.bg-blob {
    position: absolute;
    display: block;
    border-radius: 50%;
    background: radial-gradient(closest-side, var(--blob-core), transparent 100%);
    filter: blur(40px);
    will-change: transform;
    animation: background-drift 46s ease-in-out infinite alternate;
}

/*
 * The pools are anchored to the CONTENT COLUMN, not to the viewport.
 *
 * "calc(50% + var(--column-width) / 2 + 64px)" is the column edge plus 64px of
 * clearance, and because the real column is never wider than the expression
 * assumes, it always lands outside the column, at every window width. The 64px
 * also covers the 40px of drift below, so no pool can ever drift onto text.
 */

/* A: large, upper left, mostly outside the window. */
.bg-blob--a {
    top: calc(4% - 18vw);
    right: calc(50% + var(--column-width) / 2 + 64px);
    width: 36vw;
    aspect-ratio: 1;
    --blob-core: var(--blob-a);
}

/* B: medium, lower left, its centre half under the bottom edge. */
.bg-blob--b {
    top: calc(100% - 16vw);
    right: calc(50% + var(--column-width) / 2 + 64px);
    width: 32vw;
    aspect-ratio: 1;
    --blob-core: var(--blob-b);
    animation-duration: 54s;
    animation-delay: -12s;
}

/* C: small and quiet, lower right, its centre on the right edge. */
.bg-blob--c {
    top: calc(66% - 13vw);
    right: -13vw;
    width: 26vw;
    aspect-ratio: 1;
    --blob-core: var(--blob-c);
    animation-duration: 62s;
    animation-delay: -22s;
}
CSS,
    ],

    /* --- tiles: a white wash instead of a category tint --- */
    [
        <<<'CSS'
/*
 * A category tile: a translucent wash of its own category colour with a thin
 * inner ring of the same colour, so the surface is coloured but never a solid
 * block. The whole tile is one link, so it holds no nested controls.
 *
 * The colour arrives through --cat (see the data-category rules at the top of
 * the sheet). The first declaration of each pair is the plain fallback for a
 * browser without color-mix(); the second one is the real value.
 */
.area-card {
    position: relative;
    display: flex;
    flex-direction: column;
    gap: 10px;
    padding: 18px 16px 16px;
    border: 0;
    border-radius: var(--radius-tile);
    background-color: rgba(255, 255, 255, 0.3);
    background-color: color-mix(in srgb, var(--cat, var(--cat-mathematics)) 28%, transparent);
    box-shadow: inset 0 0 0 1px rgba(24, 24, 31, 0.12);
    box-shadow: inset 0 0 0 1px color-mix(in srgb, var(--cat, var(--cat-mathematics)) 35%, transparent);
    color: inherit;
    text-decoration: none;
    transition: background-color 200ms var(--ease), transform 250ms var(--ease);
}

/*
 * In dark mode the wash is mixed against a dark base instead of "transparent".
 * Mixing a light pastel with transparency gives a surface LIGHTER than the slate
 * background, and the near-white text on it only reached 3.6:1. Against the dark
 * base the same category hue reads at about 10:1 and the tile still looks tinted.
 */
[data-theme="dark"] .area-card {
    background-color: rgba(20, 22, 34, 0.6);
    background-color: color-mix(in srgb, var(--cat, var(--cat-mathematics)) 28%, rgba(20, 22, 34, 0.6));
    box-shadow: inset 0 0 0 1px rgba(246, 244, 250, 0.18);
    box-shadow: inset 0 0 0 1px color-mix(in srgb, var(--cat, var(--cat-mathematics)) 45%, rgba(246, 244, 250, 0.1));
}

/* Hover and keyboard focus get the same treatment, including the 4px lift. */
.area-card:hover,
.area-card:focus-visible {
    transform: translateY(-4px);
    background-color: color-mix(in srgb, var(--cat, var(--cat-mathematics)) 42%, transparent);
}

[data-theme="dark"] .area-card:hover,
[data-theme="dark"] .area-card:focus-visible {
    background-color: color-mix(in srgb, var(--cat, var(--cat-mathematics)) 40%, rgba(20, 22, 34, 0.6));
}
CSS,
        <<<'CSS'
/*
 * A category tile: one translucent white wash with a thin white ring, so the
 * surface reads as a surface and never as a solid block. The whole tile is one
 * link, so it holds no nested controls.
 *
 * The category colour is deliberately NOT used here - it carries the segmented
 * bar and the dots in the pills instead. Both themes take the same two tokens;
 * only the strength of the wash differs.
 */
.area-card {
    position: relative;
    display: flex;
    flex-direction: column;
    gap: 10px;
    padding: 18px 16px 16px;
    border: 0;
    border-radius: var(--radius-tile);
    background-color: var(--tile-bg);
    box-shadow: inset 0 0 0 1px var(--tile-ring);
    color: inherit;
    text-decoration: none;
    transition: background-color 200ms var(--ease), transform 250ms var(--ease);
}

/* Hover and keyboard focus get the same treatment, including the 4px lift. */
.area-card:hover,
.area-card:focus-visible {
    transform: translateY(-4px);
    background-color: var(--tile-bg-hover);
}
CSS,
    ],

    /* --- the icon circle: a white wash, no category colour --- */
    [
        <<<'CSS'
/*
 * The one organic shape in the design, and the one detail of each icon that
 * carries the category colour. It sits behind the drawing, so the icon itself is
 * never distorted.
 */
.blob {
    display: grid;
    place-items: center;
    width: 64px;
    height: 64px;
    border-radius: 52% 48% 44% 56% / 48% 52% 48% 52%;
    background-color: rgba(255, 255, 255, 0.5);
    background-color: color-mix(in srgb, var(--cat, var(--cat-mathematics)) 55%, transparent);
    transition: border-radius 500ms var(--ease), background-color 500ms var(--ease);
}

/* The subject icons are inverted to near-white in dark mode, so the blob they
   sit on has to stay dark for them to read. Same hue, dark base. */
[data-theme="dark"] .blob {
    background-color: color-mix(in srgb, var(--cat, var(--cat-mathematics)) 32%, rgba(20, 22, 34, 0.6));
}
CSS,
        <<<'CSS'
/*
 * The one organic shape in the design: the circle behind the subject drawing. It
 * is a white wash too, a little stronger than the tile itself, and it sits
 * behind the drawing so the icon is never distorted.
 */
.blob {
    display: grid;
    place-items: center;
    width: 64px;
    height: 64px;
    border-radius: 52% 48% 44% 56% / 48% 52% 48% 52%;
    background-color: var(--icon-circle);
    transition: border-radius 500ms var(--ease), background-color 500ms var(--ease);
}
CSS,
    ],

    /* --- the (invisible) progress fill no longer uses a category colour --- */
    [
        <<<'CSS'
.area-card__progress-fill {
    display: block;
    height: 100%;
    width: 0;
    background-color: var(--cat, var(--cat-mathematics));
    transform-origin: left;
}
CSS,
        <<<'CSS'
.area-card__progress-fill {
    display: block;
    height: 100%;
    width: 0;
    /* --text, not a category colour: the fill is empty, and the categories are
       reserved for the bar and the pill dots. */
    background-color: var(--text);
    transform-origin: left;
}
CSS,
    ],

    /* --- tile index and description: the new reason for --text --- */
    [
        <<<'CSS'
    letter-spacing: 0.04em;
    /* Same reason as the description: AA on the tinted tile. */
    color: var(--text);
CSS,
        <<<'CSS'
    letter-spacing: 0.04em;
    /* Same reason as the description: the strongest colour on a white-washed tile. */
    color: var(--text);
CSS,
    ],
    [
        <<<'CSS'
    min-height: calc(2 * 1.5em);
    /*
     * --text, not --muted: on the tinted tiles --muted only reaches about
     * 3.9:1, while --text reaches about 10:1.
     */
    color: var(--text);
CSS,
        <<<'CSS'
    min-height: calc(2 * 1.5em);
    /*
     * --text, not --muted: the tile is LIGHTER than the page background, so this
     * is the strongest colour available. --text reaches about 4.1:1 here and
     * 5.0:1 on the background itself; --muted would only reach about 3.9:1.
     */
    color: var(--text);
CSS,
    ],

    /* --- the tile data pill never wraps again --- */
    [
        <<<'CSS'
.area-card__stat {
    font-family: var(--font-mono);
    font-size: 11px;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    color: var(--text);
    font-variant-numeric: tabular-nums;
    padding: 3px 10px;
}
CSS,
        <<<'CSS'
.area-card__stat {
    font-family: var(--font-mono);
    /*
     * One line, always. "nowrap" is the important part; the type is slightly
     * smaller than the other pills and follows the tile width, so the data line
     * fits even in the narrowest four-column layout and in German.
     */
    white-space: nowrap;
    font-size: clamp(9px, 0.8vw, 10.5px);
    letter-spacing: 0.06em;
    text-transform: uppercase;
    color: var(--text);
    font-variant-numeric: tabular-nums;
    padding: 3px 8px;
}
CSS,
    ],

    /* --- the highlight after adding an item stays visible --- */
    [
        <<<'CSS'
.area-card.is-highlighted {
    background-color: var(--active-surface);
    transition: background-color 400ms var(--ease);
}
CSS,
        <<<'CSS'
.area-card.is-highlighted {
    /* The stronger of the two tile washes, because the base one is the same as
       the resting tile and would not be visible. */
    background-color: var(--tile-bg-hover);
    transition: background-color 400ms var(--ease);
}
CSS,
    ],

    /* --- the language underline and the plus button take the accent --- */
    [
        <<<'CSS'
.lang-switch__underline {
    position: absolute;
    bottom: 0;
    left: 0;
    width: 0;
    height: 1px;
    background-color: var(--text);
CSS,
        <<<'CSS'
.lang-switch__underline {
    position: absolute;
    bottom: 0;
    left: 0;
    width: 0;
    height: 1px;
    background-color: var(--accent);
CSS,
    ],
    [
        <<<'CSS'
/*
 * The circular plus button. It is the one solid filled control, which is what
 * makes it the obvious action on the page. Its colours come from the plus
 * tokens, so each theme gets its own pair.
 */
.plus-button {
    display: grid;
    place-items: center;
    justify-self: center;
    width: 44px;
    height: 44px;
    padding: 0;
    border: 1px solid transparent;
    border-radius: 50%;
    background-color: var(--plus-bg);
    color: var(--plus-fg);
    cursor: pointer;
    transition: opacity 250ms var(--ease);
}
CSS,
        <<<'CSS'
/*
 * The circular plus button. It is the one solid filled control, which is what
 * makes it the obvious action on the page. It is also the only place that uses
 * the aqua accent as a surface, and the faint glow around it comes from the same
 * colour.
 */
.plus-button {
    display: grid;
    place-items: center;
    justify-self: center;
    width: 44px;
    height: 44px;
    padding: 0;
    border: 1px solid transparent;
    border-radius: 50%;
    background-color: var(--plus-bg);
    color: var(--plus-fg);
    box-shadow: 0 0 22px rgba(168, 221, 235, 0.35);
    cursor: pointer;
    transition: opacity 250ms var(--ease);
}

[data-theme="dark"] .plus-button {
    box-shadow: 0 0 22px rgba(143, 211, 230, 0.30);
}
CSS,
    ],

    /* --- reduced motion: the pools stop moving --- */
    [
        <<<'CSS'
    /* The background shapes keep their colour but stop moving. */
    .background-shape {
        animation: none !important;
    }
CSS,
        <<<'CSS'
    /* The light pools keep their colour but stop moving. */
    .bg-blob {
        animation: none !important;
    }
CSS,
    ],
]);

/* ==========================================================================
   2. index.php - markup only
   ========================================================================== */

patch($root . '/public/index.php', [
    [
        <<<'HTML'
    <!--
        One full-viewport layer of large organic shapes. It deliberately sits
        OUTSIDE .page: the layer is fixed to the viewport, so a shape can run
        past the content column and past the window edges without leaving a hard
        vertical edge where that column ends. Decorative only - nothing here is
        clickable and nothing is announced by a screen reader. The colours come
        from the category tokens in app.css.
    -->
    <div class="bg-shapes" aria-hidden="true">
        <span class="background-shape background-shape--1"></span>
        <span class="background-shape background-shape--2"></span>
        <span class="background-shape background-shape--3"></span>
        <span class="background-shape background-shape--4"></span>
    </div>
HTML,
        <<<'HTML'
    <!--
        Three soft light pools, one wide blurred radial gradient each. They have
        no visible edge: nothing here is a shape with an outline and nothing has
        a hard border.

        The layer deliberately sits OUTSIDE .page and is fixed to the viewport, so
        a pool can run past the content column and past the window edges without
        leaving a hard vertical edge where the column ends. It keeps z-index -1,
        which is above the page colour and below every piece of real content, so
        no pool is ever behind a tile, a heading or any other text.

        Decorative only - nothing here is clickable or announced.
    -->
    <div class="bg-layer" aria-hidden="true">
        <span class="bg-blob bg-blob--a"></span>
        <span class="bg-blob bg-blob--b"></span>
        <span class="bg-blob bg-blob--c"></span>
    </div>
HTML,
    ],
    [
        <<<'HTML'
    <script src="assets/js/app.js"></script>
HTML,
        <<<'HTML'
    <!--
        Film grain: one fixed layer above everything (z-index 9999) that takes no
        pointer events, so the noise sits over the whole page - colour, tiles and
        text alike - and cannot be clicked, hovered or scrolled.
    -->
    <div class="grain" aria-hidden="true"></div>

    <script src="assets/js/app.js"></script>
HTML,
    ],
]);

/* ==========================================================================
   3. translations.php - the tile data label has to fit on one line
   ========================================================================== */

patch($root . '/src/helpers/translations.php', [
    [
        <<<'PHP'
            'tile.subcategories.one' => 'SUBCATEGORY',
            'tile.subcategories.other' => 'SUBCATEGORIES',
PHP,
        <<<'PHP'
            'tile.subcategories.one' => 'SUBCAT',
            'tile.subcategories.other' => 'SUBCATS',
PHP,
    ],
    [
        <<<'PHP'
            'tile.subcategories.one' => 'UNTERKATEGORIE',
            'tile.subcategories.other' => 'UNTERKATEGORIEN',
PHP,
        <<<'PHP'
            'tile.subcategories.one' => 'UNTERKAT.',
            'tile.subcategories.other' => 'UNTERKAT.',
PHP,
    ],
]);

echo "all targets patched\n";
