<?php

declare(strict_types=1);

/**
 * Applies the remaining tile/scroller edits.
 *
 * The one big CSS block is swapped through files (an exact dump of the old text
 * and the new text), so the old CSS never has to be reproduced inside this
 * script. Everything else are small, anchored replacements.
 */

$root = __DIR__;

/** Swaps one exact block, given as files, in an LF file. */
function swapFile(string $target, string $oldFile, string $newFile): void
{
    $content = file_get_contents($target);
    $old = (string) file_get_contents($oldFile);
    $new = $newFile === '-' ? '' : (string) file_get_contents($newFile);

    $count = substr_count($content, $old);

    if ($count !== 1) {
        fwrite(STDERR, basename($target) . ' swap matched ' . $count . " times\n");
        exit(1);
    }

    file_put_contents($target, str_replace($old, $new, $content));
    echo basename($target) . ": block swapped\n";
}

/**
 * @param array<int, array{0: string, 1: string}> $pairs
 */
function patch(string $file, array $pairs): void
{
    $raw = file_get_contents($file);

    if ($raw === false) {
        fwrite(STDERR, 'cannot read ' . $file . PHP_EOL);
        exit(1);
    }

    $eol = str_contains($raw, "\r\n") ? "\r\n" : "\n";
    $content = str_replace("\r\n", "\n", $raw);

    foreach ($pairs as $index => [$from, $to]) {
        $count = substr_count($content, $from);

        if ($count !== 1) {
            fwrite(STDERR, basename($file) . ' pair ' . $index . ' matched ' . $count . " times:\n"
                . substr($from, 0, 160) . "\n\n");
            exit(1);
        }

        $content = str_replace($from, $to, $content);
    }

    file_put_contents($file, $eol === "\n" ? $content : str_replace("\n", $eol, $content));
    echo basename($file) . ': ' . count($pairs) . " replacements ok\n";
}

/* --- the two big CSS blocks, through files --------------------------------- */

swapFile($root . '/public/assets/css/app.css', '/tmp/old_grid.txt', $root . '/new_grid.css');
swapFile($root . '/public/assets/css/app.css', '/tmp/old_1023.txt', '-');

/* --- the small CSS edits --------------------------------------------------- */

patch($root . '/public/assets/css/app.css', [
    [
        <<<'CSS'
    gap: 10px;
    padding: 28px;
    border: 0;
CSS,
        <<<'CSS'
    gap: 10px;
    padding: 24px;
    border: 0;
CSS,
    ],
    [
        <<<'CSS'
    width: 88px;
    height: 88px;
CSS,
        <<<'CSS'
    width: 72px;
    height: 72px;
CSS,
    ],
    [
        <<<'CSS'
.blob__icon {
    width: 40px;
    height: 40px;
CSS,
        <<<'CSS'
.blob__icon {
    /* 34px keeps the same drawing-to-circle ratio the 40px icon had in an 88px
       circle, so the smaller circle does not look crowded. */
    width: 34px;
    height: 34px;
CSS,
    ],
    [
        <<<'CSS'
@media (max-width: 639px) {
    .area-grid {
        grid-template-columns: minmax(0, 1fr);
    }

    .heading {
        max-width: none;
    }
CSS,
        <<<'CSS'
@media (max-width: 639px) {
    .heading {
        max-width: none;
    }
CSS,
    ],
    [
        <<<'CSS'
    /* The light pools keep their colour but stop moving. */
    .bg-blob {
        animation: none !important;
    }
CSS,
        <<<'CSS'
    /* The light pools keep their colour but stop moving. */
    .bg-blob {
        animation: none !important;
    }

    /* The tile row jumps instead of travelling. */
    .area-grid {
        scroll-behavior: auto;
    }
CSS,
    ],
]);

/* --- app.js ---------------------------------------------------------------- */

patch($root . '/public/assets/js/app.js', [
    [
        <<<'JS'
        grid: document.getElementById('area-grid'),
JS,
        <<<'JS'
        grid: document.getElementById('area-grid'),
        tiles: document.getElementById('tiles'),
        tilesNav: document.getElementById('tiles-nav'),
        tilesCounter: document.getElementById('tiles-nav-counter'),
        tilesTrack: document.getElementById('tiles-nav-track'),
        tilesThumb: document.getElementById('tiles-nav-thumb'),
        tilesPrev: document.getElementById('tiles-nav-prev'),
        tilesNext: document.getElementById('tiles-nav-next'),
JS,
    ],
    [
        <<<'JS'
            /*
             * Five or more areas would wrap into a cramped second row, so the
             * grid becomes a horizontal, snapping strip instead. Four areas or
             * fewer stay a plain grid and never scroll sideways.
             */
            elements.grid.classList.toggle('is-scrollable', areas.length > 4);

            buildDistribution(areas);
            wireLinkedHover();
            updateFooterCount(areas.length);
JS,
        <<<'JS'
            buildDistribution(areas);
            wireLinkedHover();
            updateFooterCount(areas.length);
            updateTileNavigation();
JS,
    ],
    [
        <<<'JS'
    function handleLoadError(error) {
JS,
        <<<'JS'
    /* ----------------------------------------------------------------------
       The horizontal tile row
       ---------------------------------------------------------------------- */

    /*
     * The row shows whole tiles only - how many per view is decided by the
     * stylesheet (4 on a desktop, 3 on a laptop, 2 on a tablet, 1 on a phone),
     * and the width of a tile follows from that and the width of the column.
     *
     * This module keeps the counter, the track, the thumb and the two buttons in
     * step with the real scroll position, and hides the whole navigation while
     * every tile fits without scrolling.
     */
    var tileObserver = null;
    var tilePointerStart = null;
    var tilePointerMoved = false;

    function tilesPerView() {
        if (elements.tiles === null) {
            return 1;
        }

        var value = parseInt(window.getComputedStyle(elements.tiles).getPropertyValue('--tiles-per-view'), 10);

        return isNaN(value) || value < 1 ? 1 : value;
    }

    /* One tile plus one gap: the distance the row moves per tile. */
    function tileStep() {
        if (elements.grid === null) {
            return 0;
        }

        var tile = elements.grid.querySelector('.area-card');

        if (tile === null) {
            return 0;
        }

        var styles = window.getComputedStyle(elements.grid);
        var gap = parseFloat(styles.columnGap);

        if (isNaN(gap)) {
            gap = parseFloat(styles.gap);
        }

        return tile.getBoundingClientRect().width + (isNaN(gap) ? 0 : gap);
    }

    function updateTileNavigation() {
        if (elements.grid === null || elements.tilesNav === null) {
            return;
        }

        var maximum = elements.grid.scrollWidth - elements.grid.clientWidth;

        /* While everything fits there is nothing to navigate. */
        elements.tilesNav.hidden = maximum <= 2;

        if (elements.tilesNav.hidden) {
            return;
        }

        var tiles = elements.grid.querySelectorAll('.area-card');
        var total = tiles.length;
        var step = tileStep();
        var perView = tilesPerView();
        var index = step > 0 ? Math.round(elements.grid.scrollLeft / step) : 0;

        index = Math.max(0, Math.min(index, Math.max(0, total - 1)));

        elements.tilesCounter.textContent = pad2(index + 1) + '\u2013' + pad2(Math.min(total, index + perView)) + ' / ' + pad2(total);

        var trackWidth = elements.tilesTrack.clientWidth;
        var ratio = elements.grid.scrollWidth > 0 ? elements.grid.clientWidth / elements.grid.scrollWidth : 1;
        var thumbWidth = Math.max(28, Math.round(trackWidth * ratio));

        elements.tilesThumb.style.width = thumbWidth + 'px';

        var travel = Math.max(0, trackWidth - thumbWidth);
        var progress = maximum > 0 ? elements.grid.scrollLeft / maximum : 0;

        elements.tilesThumb.style.transform = 'translateX(' + Math.round(progress * travel) + 'px)';

        elements.tilesPrev.disabled = elements.grid.scrollLeft <= 1;
        elements.tilesNext.disabled = elements.grid.scrollLeft >= maximum - 1;
    }

    function scrollTilesBy(direction) {
        var step = tileStep();

        if (step === 0) {
            return;
        }

        elements.grid.scrollBy({
            left: direction * step * tilesPerView(),
            behavior: prefersReducedMotion() ? 'auto' : 'smooth'
        });
    }

    /* Where a click or a drag on the track lands, expressed as a scroll offset. */
    function scrollTilesToPointer(clientX) {
        var rect = elements.tilesTrack.getBoundingClientRect();
        var thumbWidth = elements.tilesThumb.getBoundingClientRect().width;
        var travel = Math.max(1, rect.width - thumbWidth);
        var offset = Math.min(Math.max(clientX - rect.left - thumbWidth / 2, 0), travel);
        var maximum = elements.grid.scrollWidth - elements.grid.clientWidth;

        elements.grid.scrollLeft = (offset / travel) * maximum;
    }

    function wireTileNavigation() {
        if (elements.grid === null || elements.tilesNav === null) {
            return;
        }

        elements.grid.addEventListener('scroll', updateTileNavigation, { passive: true });

        elements.tilesPrev.addEventListener('click', function () {
            scrollTilesBy(-1);
        });

        elements.tilesNext.addEventListener('click', function () {
            scrollTilesBy(1);
        });

        /* Click and drag on the track, and dragging the thumb itself. */
        elements.tilesTrack.addEventListener('pointerdown', function (event) {
            event.preventDefault();
            elements.tilesTrack.setPointerCapture(event.pointerId);
            scrollTilesToPointer(event.clientX);
        });

        elements.tilesTrack.addEventListener('pointermove', function (event) {
            if (!elements.tilesTrack.hasPointerCapture(event.pointerId)) {
                return;
            }

            scrollTilesToPointer(event.clientX);
        });

        /*
         * Dragging a tile must not open it. A pointer that travelled more than a
         * few pixels counts as a drag, and the click that follows is swallowed.
         */
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

        window.addEventListener('pointerup', function () {
            tilePointerStart = null;
        });

        elements.grid.addEventListener('click', function (event) {
            if (!tilePointerMoved) {
                return;
            }

            tilePointerMoved = false;
            event.preventDefault();
            event.stopPropagation();
        }, true);

        /*
         * The width of the column changes with the window and with the scrollbar,
         * so the thumb is recalculated whenever the row is resized.
         */
        if (typeof window.ResizeObserver === 'function') {
            tileObserver = new window.ResizeObserver(updateTileNavigation);
            tileObserver.observe(elements.grid);
            tileObserver.observe(elements.tiles);
        }

        window.addEventListener('resize', updateTileNavigation);
    }

    function handleLoadError(error) {
JS,
    ],
    [
        <<<'JS'
        Array.prototype.forEach.call(document.querySelectorAll('[data-i18n-title]'), function (node) {
            node.setAttribute('title', t(node.getAttribute('data-i18n-title')));
        });
    }
JS,
        <<<'JS'
        Array.prototype.forEach.call(document.querySelectorAll('[data-i18n-title]'), function (node) {
            node.setAttribute('title', t(node.getAttribute('data-i18n-title')));
        });

        /* The counter carries numbers, so it is rewritten rather than translated. */
        updateTileNavigation();
    }
JS,
    ],
    [
        <<<'JS'
    function render() {
JS,
        <<<'JS'
    wireTileNavigation();

    function render() {
JS,
    ],
]);

/* --- the three accessible labels ------------------------------------------- */

patch($root . '/src/helpers/translations.php', [
    [
        <<<'PHP'
            'footer.addAria' => 'Add learning area',
PHP,
        <<<'PHP'
            'scroller.previous' => 'Previous categories',
            'scroller.next' => 'Next categories',
            'scroller.position' => 'Category scroll position',
            'footer.addAria' => 'Add learning area',
PHP,
    ],
    [
        <<<'PHP'
            'footer.addAria' => 'Themengebiet hinzufügen',
PHP,
        <<<'PHP'
            'scroller.previous' => 'Vorherige Gebiete',
            'scroller.next' => 'Nächste Gebiete',
            'scroller.position' => 'Scrollposition der Gebiete',
            'footer.addAria' => 'Themengebiet hinzufügen',
PHP,
    ],
]);

echo "all targets patched\n";
