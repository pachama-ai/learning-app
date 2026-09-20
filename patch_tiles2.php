<?php

declare(strict_types=1);

/**
 * Rest of the tile/scroller patch: app.css (corrected anchor), app.js and the
 * three translation labels. index.php was already patched.
 */

$root = __DIR__;

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

    $out = $eol === "\n" ? $css : str_replace("\n", $eol, $css);

    if (file_put_contents($file, $out) === false) {
        fwrite(STDERR, 'cannot write ' . $file . PHP_EOL);
        exit(1);
    }

    echo basename($file) . ': ' . count($pairs) . " replacements ok\n";
}

patch($root . '/public/assets/css/app.css', [
    [
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

/*
 * More than four learning areas would wrap into a cramped second row, so the
 * grid turns into a horizontal scroll strip with snap points instead. The two
 * classes beat the column rules inside the media queries further down.
 */
.area-grid.is-scrollable {
    display: flex;
    gap: clamp(16px, 2.2vw, 28px);
    overflow-x: auto;
    overscroll-behavior-x: contain;
    scroll-snap-type: x mandatory;
    padding-bottom: 8px;
}

.area-grid.is-scrollable .area-card {
    flex: 0 0 300px;
    scroll-snap-align: start;
}
CSS,
        <<<'CSS'
/*
 * The tile row. The wrapper takes the free height of the page (never under 340px,
 * never over 460px) and stacks the scroller above the navigation.
 */
.tiles {
    display: flex;
    flex-direction: column;
    gap: 14px;
    flex: 1 1 auto;
    min-height: 340px;
    max-height: 460px;

    /*
     * How many whole tiles one view shows. The width of a tile is derived from
     * this and from the width of the column - never a fixed pixel value, so a
     * tile is always complete and never half cut off.
     */
    --tiles-per-view: 4;
    --tile-gap: 16px;
}

/*
 * A horizontal scroller with its native scrollbar hidden, because the custom
 * navigation below carries the position. Nothing fades, nothing is cut off and
 * there is no negative margin: the small padding is only there so the ring and
 * the drop shadow of a tile are never clipped by the scrollport.
 */
.area-grid {
    display: flex;
    align-items: stretch;
    gap: var(--tile-gap);
    flex: 1 1 auto;
    min-height: 0;
    padding: 6px 4px 20px;
    overflow-x: auto;
    overflow-y: hidden;
    overscroll-behavior-x: contain;
    scroll-snap-type: x mandatory;
    scroll-behavior: smooth;
    scroll-padding-inline: 4px;
    scrollbar-width: none;
    -ms-overflow-style: none;
}

.area-grid::-webkit-scrollbar {
    display: none;
}

.area-card {
    /* One view shows --tiles-per-view whole tiles, gaps included. */
    flex: 0 0 calc((100% - (var(--tiles-per-view) - 1) * var(--tile-gap)) / var(--tiles-per-view));
    scroll-snap-align: start;
}
CSS,
    ],
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
    border-radius: 52% 48% 44% 56% / 48% 52% 48% 52%;
CSS,
        <<<'CSS'
    width: 72px;
    height: 72px;
    border-radius: 52% 48% 44% 56% / 48% 52% 48% 52%;
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
/* Hover and keyboard focus get the same treatment, including the 4px lift. */
.area-card:hover,
.area-card:focus-visible {
CSS,
        <<<'CSS'
/*
 * The custom navigation of the tile row: counter, track, thumb and two round
 * buttons. It uses the existing tokens only, so it follows both themes.
 */
.tiles-nav {
    display: flex;
    align-items: center;
    gap: 14px;
    flex: 0 0 auto;
}

.tiles-nav[hidden] {
    display: none;
}

.tiles-nav__counter {
    font-family: var(--font-mono);
    font-size: 11px;
    letter-spacing: 0.08em;
    font-variant-numeric: tabular-nums;
    color: var(--muted);
    white-space: nowrap;
}

.tiles-nav__track {
    position: relative;
    flex: 1 1 auto;
    height: 2px;
    border-radius: 999px;
    background-color: var(--line);
    cursor: pointer;
    touch-action: none;
}

.tiles-nav__thumb {
    position: absolute;
    top: 0;
    left: 0;
    width: 25%;
    height: 100%;
    border-radius: 999px;
    background-color: var(--text);
    cursor: grab;
}

.tiles-nav__thumb:active {
    cursor: grabbing;
}

.tiles-nav__button {
    display: grid;
    place-items: center;
    flex: 0 0 auto;
    width: 36px;
    height: 36px;
    padding: 0;
    border: 1px solid var(--line-strong);
    border-radius: 50%;
    background: transparent;
    color: var(--text);
    cursor: pointer;
    transition: background-color 200ms var(--ease), opacity 200ms var(--ease);
}

.tiles-nav__button:hover:not([disabled]) {
    background-color: var(--surface);
}

.tiles-nav__button[disabled] {
    opacity: .35;
    cursor: default;
}

/* Hover and keyboard focus get the same treatment, including the 4px lift. */
.area-card:hover,
.area-card:focus-visible {
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
/*
 * The tiles per view. The width of a tile follows from this number and the width
 * of the column, so it is never a guess.
 */
@media (max-width: 1199px) {
    .tiles {
        --tiles-per-view: 3;
    }
}

@media (max-width: 860px) {
    .tiles {
        --tiles-per-view: 2;
    }
}

@media (max-width: 639px) {
    .tiles {
        --tiles-per-view: 1;
    }

    .heading {
        max-width: none;
    }
CSS,
    ],
    [
        <<<'CSS'
@media (max-width: 1023px) {
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
}

CSS,
        '',
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

        /* Click and drag on the track, and drag on the thumb itself. */
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
