<?php

declare(strict_types=1);

/**
 * The tile row can be dragged with a mouse or a pen as well (touch and a
 * trackpad already pan it natively), and every programmatic jump is instant
 * instead of smooth, so dragging does not feel laggy.
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
    function scrollTilesToPointer(clientX) {
        var rect = elements.tilesTrack.getBoundingClientRect();
        var thumbWidth = elements.tilesThumb.getBoundingClientRect().width;
        var travel = Math.max(1, rect.width - thumbWidth);
        var offset = Math.min(Math.max(clientX - rect.left - thumbWidth / 2, 0), travel);
        var maximum = elements.grid.scrollWidth - elements.grid.clientWidth;

        elements.grid.scrollLeft = (offset / travel) * maximum;
    }
JS,
        <<<'JS'
    /*
     * Jumps to an offset at once. "scroll-behavior: smooth" is meant for the two
     * buttons; while a pointer drags, smooth scrolling would lag behind.
     */
    function setTilesScroll(left) {
        var previous = elements.grid.style.scrollBehavior;

        elements.grid.style.scrollBehavior = 'auto';
        elements.grid.scrollLeft = left;
        elements.grid.style.scrollBehavior = previous;
    }

    /* Where a click or a drag on the track lands, expressed as a scroll offset. */
    function scrollTilesToPointer(clientX) {
        var rect = elements.tilesTrack.getBoundingClientRect();
        var thumbWidth = elements.tilesThumb.getBoundingClientRect().width;
        var travel = Math.max(1, rect.width - thumbWidth);
        var offset = Math.min(Math.max(clientX - rect.left - thumbWidth / 2, 0), travel);
        var maximum = elements.grid.scrollWidth - elements.grid.clientWidth;

        setTilesScroll((offset / travel) * maximum);
    }
JS,
    ],
    [
        <<<'JS'
        elements.grid.addEventListener('pointerdown', function (event) {
            tilePointerStart = { x: event.clientX, y: event.clientY };
            tilePointerMoved = false;
        });

        elements.grid.addEventListener('pointermove', function (event) {
            if (tilePointerStart === null) {
                return;
            }

            if (Math.abs(event.clientX - tilePointerStart.x) > 6 || Math.abs(event.clientY - tilePointerStart.y) > 6) {
                tilePointerMoved = true;
            }
        });
JS,
        <<<'JS'
        elements.grid.addEventListener('pointerdown', function (event) {
            tilePointerStart = { x: event.clientX, y: event.clientY, scrollLeft: elements.grid.scrollLeft };
            tilePointerMoved = false;
        });

        elements.grid.addEventListener('pointermove', function (event) {
            if (tilePointerStart === null) {
                return;
            }

            var dx = event.clientX - tilePointerStart.x;

            if (Math.abs(dx) > 6 || Math.abs(event.clientY - tilePointerStart.y) > 6) {
                tilePointerMoved = true;
            }

            /*
             * A mouse or a pen drags the row, exactly like touch and a trackpad.
             * Touch itself is left to the browser: cancelling its default action
             * would switch the native panning off.
             */
            if (tilePointerMoved && event.pointerType === 'mouse') {
                event.preventDefault();
                setTilesScroll(tilePointerStart.scrollLeft - dx);
            }
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
