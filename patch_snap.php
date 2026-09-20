<?php

declare(strict_types=1);

/**
 * While a pointer drags the row, the mandatory snapping has to be off: every
 * intermediate scroll offset would otherwise be snapped, so the row would jump
 * in whole tiles instead of following the pointer. On release the snapping comes
 * back and the row lands on the nearest whole tile.
 */

$file = __DIR__ . '/public/assets/js/app.js';
$raw = file_get_contents($file);

if ($raw === false) {
    fwrite(STDERR, "cannot read app.js\n");
    exit(1);
}

$eol = str_contains($raw, "\r\n") ? "\r\n" : "\n";
$js = str_replace("\r\n", "\n", $raw);

$pairs = [
    [
        <<<'JS'
    /* Where a click or a drag on the track lands, expressed as a scroll offset. */
JS,
        <<<'JS'
    /*
     * Snapping is switched off for the duration of a drag, so the row follows the
     * pointer instead of jumping from tile to tile; on release it comes back and
     * the row lands on the nearest whole tile, which is what "tile by tile" means.
     */
    function beginTileDrag() {
        elements.grid.style.scrollSnapType = 'none';
    }

    function endTileDrag() {
        var step = tileStep();

        elements.grid.style.scrollSnapType = '';

        if (step > 0) {
            setTilesScroll(Math.round(elements.grid.scrollLeft / step) * step);
        }
    }

    /* Where a click or a drag on the track lands, expressed as a scroll offset. */
JS,
    ],
    [
        <<<'JS'
        elements.tilesTrack.addEventListener('pointerdown', function (event) {
            event.preventDefault();
            elements.tilesTrack.setPointerCapture(event.pointerId);
            scrollTilesToPointer(event.clientX);
        });
JS,
        <<<'JS'
        elements.tilesTrack.addEventListener('pointerdown', function (event) {
            event.preventDefault();
            elements.tilesTrack.setPointerCapture(event.pointerId);
            beginTileDrag();
            scrollTilesToPointer(event.clientX);
        });

        elements.tilesTrack.addEventListener('pointerup', function (event) {
            if (!elements.tilesTrack.hasPointerCapture(event.pointerId)) {
                return;
            }

            elements.tilesTrack.releasePointerCapture(event.pointerId);
            endTileDrag();
        });
JS,
    ],
    [
        <<<'JS'
        elements.grid.addEventListener('pointerdown', function (event) {
            tilePointerStart = { x: event.clientX, y: event.clientY, scrollLeft: elements.grid.scrollLeft };
            tilePointerMoved = false;
        });
JS,
        <<<'JS'
        elements.grid.addEventListener('pointerdown', function (event) {
            tilePointerStart = { x: event.clientX, y: event.clientY, scrollLeft: elements.grid.scrollLeft };
            tilePointerMoved = false;

            if (event.pointerType === 'mouse') {
                beginTileDrag();
            }
        });
JS,
    ],
    [
        <<<'JS'
        window.addEventListener('pointerup', function () {
            tilePointerStart = null;
        });
JS,
        <<<'JS'
        window.addEventListener('pointerup', function () {
            if (tilePointerStart !== null && tilePointerMoved) {
                endTileDrag();
            }

            tilePointerStart = null;
        });
JS,
    ],
];

foreach ($pairs as $index => [$from, $to]) {
    $count = substr_count($js, $from);

    if ($count !== 1) {
        fwrite(STDERR, 'pair ' . $index . ' matched ' . $count . " times\n");
        exit(1);
    }

    $js = str_replace($from, $to, $js);
}

file_put_contents($file, $eol === "\n" ? $js : str_replace("\n", $eol, $js));

echo "app.js: " . count($pairs) . " replacements ok\n";
