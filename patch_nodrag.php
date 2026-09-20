<?php

declare(strict_types=1);

/**
 * A tile is a link, and Chrome starts a native link drag when one is pulled with
 * the mouse. That cancels the pointer events the row needs to pan, so the native
 * drag is switched off: the tile can then be dragged like any other surface,
 * while a plain click still opens it.
 */

$file = __DIR__ . '/public/assets/css/app.css';
$raw = file_get_contents($file);

if ($raw === false) {
    fwrite(STDERR, "cannot read css\n");
    exit(1);
}

$from = <<<'CSS'
.area-card {
    /* One view shows --tiles-per-view whole tiles, gaps included. */
    flex: 0 0 calc((100% - (var(--tiles-per-view) - 1) * var(--tile-gap)) / var(--tiles-per-view));
    scroll-snap-align: start;
}
CSS;

$to = <<<'CSS'
.area-card {
    /* One view shows --tiles-per-view whole tiles, gaps included. */
    flex: 0 0 calc((100% - (var(--tiles-per-view) - 1) * var(--tile-gap)) / var(--tiles-per-view));
    scroll-snap-align: start;
    /*
     * "user-drag: none" is what lets the row be dragged with a mouse: without it
     * Chrome starts its own link drag and stops sending the pointer events the
     * row would pan with. A plain click is unaffected.
     */
    -webkit-user-drag: none;
}
CSS;

if (substr_count($raw, $from) !== 1) {
    fwrite(STDERR, "anchor matched " . substr_count($raw, $from) . " times\n");
    exit(1);
}

file_put_contents($file, str_replace($from, $to, $raw));

echo "app.css: user-drag switched off\n";
