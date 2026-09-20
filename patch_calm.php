<?php

declare(strict_types=1);

/**
 * Calm the start page down. Three things go, the structure stays:
 *
 *   1. the three statistic pills beside the heading
 *   2. the hand-drawn underline under the H1
 *   3. the "LERNKARTEI" label in the header
 *
 * Files: public/index.php (markup), public/assets/js/app.js (it built the pills,
 * the underline and the label), public/assets/css/app.css (the rules that only
 * those three used). No backend file is touched.
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
                . substr($from, 0, 180) . "\n\n");
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

/* ==========================================================================
   1. index.php
   ========================================================================== */

patch($root . '/public/index.php', [
    /* The header label of the home page. */
    [
        <<<'HTML'
                <?php if ($requestedCategoryId === null): ?>
                    <!-- Home page: the brand as the page label. -->
                    <p class="crumb" id="crumb">
                        <span class="crumb__text" data-i18n="app.brand"><?= $text('app.brand') ?></span>
                    </p>
                <?php else: ?>
                    <!--
                        Category page: app.js fills this with
                        "START - <area>", which is navigation.
                    -->
                    <p class="crumb" id="crumb"></p>
                <?php endif; ?>
HTML,
        <<<'HTML'
                <!--
                    Starts empty on both views, and an empty ".crumb" takes no
                    space (see the stylesheet). The home page deliberately shows
                    no label here; on a category page app.js fills this with
                    "START - <area>", which is navigation.
                -->
                <p class="crumb" id="crumb"></p>
HTML,
    ],

    /* The three statistic pills beside the heading. */
    [
        <<<'HTML'
                <!--
                    Two columns. align-items:end in the stylesheet lines the
                    bottom of the statistics up with the last heading line.
                -->
                <header class="start-header reveal">
                    <div class="start-header__intro">
                        <h1 class="heading" id="home-heading"><?= $text('home.heading') ?></h1>
                    </div>

                    <dl class="stats stats--start" id="start-stats">
                        <div class="stats__item stat-pill stats__item--blue">
                            <dt class="stats__label">
                                <span class="stats__dot" aria-hidden="true"></span>
                                <span data-i18n="stats.learningAreas"><?= $text('stats.learningAreas') ?></span>
                            </dt>
                            <dd class="stats__value" id="stat-areas" aria-live="polite">0</dd>
                        </div>
                        <div class="stats__item stat-pill stats__item--mint">
                            <dt class="stats__label">
                                <span class="stats__dot" aria-hidden="true"></span>
                                <span data-i18n="stats.subcategories"><?= $text('stats.subcategories') ?></span>
                            </dt>
                            <dd class="stats__value" id="stat-subcategories" aria-live="polite">0</dd>
                        </div>
                        <div class="stats__item stat-pill stats__item--peach">
                            <dt class="stats__label">
                                <span class="stats__dot" aria-hidden="true"></span>
                                <span data-i18n="stats.totalCards"><?= $text('stats.totalCards') ?></span>
                            </dt>
                            <dd class="stats__value" id="stat-cards" aria-live="polite">0</dd>
                        </div>
                    </dl>
                </header>
HTML,
        <<<'HTML'
                <!--
                    The heading stands alone. The counts that used to sit beside
                    it are still in the footer counter, so nothing is lost.
                -->
                <header class="start-header reveal">
                    <div class="start-header__intro">
                        <h1 class="heading" id="home-heading"><?= $text('home.heading') ?></h1>
                    </div>
                </header>
HTML,
    ],
]);

/* ==========================================================================
   2. app.js
   ========================================================================== */

patch($root . '/public/assets/js/app.js', [
    /* The file header. */
    [
        <<<'JS'
/*
 * Lernkartei - learning area browser
 *
 * The start page asks two existing endpoints for real data and renders it:
 *   api/categories.php -> the learning areas with colour and counts
 *   api/stats.php      -> the three numbers shown beside the heading
 *
 * Nothing is invented here. When the API reports a value as null the page shows
 * a dash instead of a number, and no optimistic placeholder is displayed.
 */
JS,
        <<<'JS'
/*
 * Lernkartei - learning area browser
 *
 * The start page asks one existing endpoint for real data and renders it:
 *   api/categories.php -> the learning areas with colour and counts
 *
 * Nothing is invented here: every number on the page is a value the API really
 * returned. api/stats.php still exists and still answers - the start page simply
 * does not display those figures any more.
 */
JS,
    ],

    /* The element handles of the removed pills. */
    [
        <<<'JS'
        detailHeading: document.getElementById('detail-heading'),
        statAreas: document.getElementById('stat-areas'),
        statSubcategories: document.getElementById('stat-subcategories'),
        statCards: document.getElementById('stat-cards'),
        distribution: document.getElementById('distribution'),
JS,
        <<<'JS'
        detailHeading: document.getElementById('detail-heading'),
        distribution: document.getElementById('distribution'),
JS,
    ],

    /* The cache of the statistics answer. */
    [
        <<<'JS'
    var responseCache = {};
    var statsCache = null;
JS,
        <<<'JS'
    var responseCache = {};
JS,
    ],

    /* The hand-drawn underline under the H1. */
    [
        <<<'JS'
    /*
     * Draws a hand-drawn looking underline under the last word of the last
     * heading line - the word the heading is really about ("area" in English,
     * "Themengebiet" in German). One inline SVG path, animated with the
     * stroke-dashoffset trick. The path length is measured here so the line can
     * never be shown half drawn, and the whole thing is decoration only.
     */
    function addHeadingAccent(element) {
        var masks = element.querySelectorAll('.heading__mask');
        var lastMask = masks.length > 0 ? masks[masks.length - 1] : null;
        var line = lastMask === null ? null : lastMask.querySelector('.heading__line');
        var match;
        var word;
        var rest;
        var wrapper;
        var svg;
        var path;
        var length;

        if (line === null) {
            return;
        }

        match = /(\S+)\s*$/.exec(line.textContent);

        if (match === null) {
            return;
        }

        word = match[1];
        rest = line.textContent.slice(0, line.textContent.length - word.length);
        line.textContent = '';

        if (rest !== '') {
            line.appendChild(document.createTextNode(rest));
        }

        wrapper = document.createElement('span');
        wrapper.className = 'heading__accent-word';
        wrapper.appendChild(document.createTextNode(word));

        svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
        svg.setAttribute('class', 'heading__accent');
        svg.setAttribute('viewBox', '0 0 200 12');
        /* "none" lets the line follow the width of the word it sits under. */
        svg.setAttribute('preserveAspectRatio', 'none');
        svg.setAttribute('aria-hidden', 'true');
        svg.setAttribute('focusable', 'false');

        path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
        /* Slightly uneven, so it reads as drawn by hand rather than ruled. */
        path.setAttribute('d', 'M2 8.5C32 3.5 62 3 92 5.5c30 2.5 60 3.5 106-1.5');

        length = typeof path.getTotalLength === 'function' ? path.getTotalLength() : 240;
        path.style.strokeDasharray = String(length);
        path.style.strokeDashoffset = String(length);

        svg.appendChild(path);
        wrapper.appendChild(svg);
        line.appendChild(wrapper);
    }

    /* ----------------------------------------------------------------------
JS,
        <<<'JS'
    /* ----------------------------------------------------------------------
JS,
    ],

    /* The dash helper of the pills. */
    [
        <<<'JS'
    /*
     * Counts a number up from zero to the value the API really returned.
     * A null value is never animated, it is shown as a dash straight away.
     */
    function setStatistic(element, value) {
        if (value === null || value === undefined) {
            element.textContent = t('state.unavailable');
            return false;
        }

        animateCount(element, value);
        return true;
    }

    function animateCount(element, value) {
JS,
        <<<'JS'
    /*
     * Counts a number up from zero to the value the API really returned. The
     * detail view uses it for its subcategory count.
     */
    function animateCount(element, value) {
JS,
    ],

    /* The statistics request. */
    [
        <<<'JS'
    function fetchStats() {
        if (statsCache !== null) {
            return Promise.resolve(statsCache);
        }

        return fetchJson(config.endpoints.stats).then(function (data) {
            statsCache = data;
            return data;
        });
    }

JS,
        '',
    ],
    [
        <<<'JS'
                responseCache = {};
                statsCache = null;
                newAreaId = result.data ? result.data.id : null;
JS,
        <<<'JS'
                responseCache = {};
                newAreaId = result.data ? result.data.id : null;
JS,
    ],

    /* Building the home view. */
    [
        <<<'JS'
        setCrumb(null);
        setHeading(elements.homeHeading, t('home.heading'));
        addHeadingAccent(elements.homeHeading);
        document.title = t('app.title');
JS,
        <<<'JS'
        setCrumb(null);
        setHeading(elements.homeHeading, t('home.heading'));
        document.title = t('app.title');
JS,
    ],
    [
        <<<'JS'
        /* The statistics come from their own endpoint. If that one fails the
           page still works: the counts that can be derived from the category
           list are used and the rest stay unknown. */
        Promise.all([
            fetchCategories(''),
            fetchStats().catch(function () {
                return null;
            })
        ]).then(function (results) {
            var areas = results[0];
            var stats = results[1];

            linkedTiles = [];
JS,
        <<<'JS'
        fetchCategories('').then(function (areas) {
            linkedTiles = [];
JS,
    ],
    [
        <<<'JS'
            buildDistribution(areas);
            wireLinkedHover();
            updateFooterCount(areas.length);
            renderStartStats(stats, areas);
        }).catch(handleLoadError);
JS,
        <<<'JS'
            buildDistribution(areas);
            wireLinkedHover();
            updateFooterCount(areas.length);
        }).catch(handleLoadError);
JS,
    ],
    [
        <<<'JS'
    /*
     * Writes the three numbers the start page shows. A null value becomes a dash,
     * and a missing or failed statistics response falls back to what the category
     * list already proves: the number of areas and the number of their
     * subcategories. The API still returns learned_percent; the start page simply
     * does not display it any more.
     */
    function renderStartStats(stats, areas) {
        var learningAreas = areas.length;
        var subcategories = areas.reduce(function (sum, area) {
            return sum + area.subcategory_count;
        }, 0);
        var totalCards = null;

        if (stats !== null && stats !== undefined) {
            learningAreas = stats.learning_areas;
            subcategories = stats.subcategories;
            totalCards = stats.total_cards;
        }

        setStatistic(elements.statAreas, learningAreas);
        setStatistic(elements.statSubcategories, subcategories);
        setStatistic(elements.statCards, totalCards);
    }

    /* ----------------------------------------------------------------------
JS,
        <<<'JS'
    /* ----------------------------------------------------------------------
JS,
    ],

    /* The header label. */
    [
        <<<'JS'
        /*
         * The home page is labelled with the brand: it is the top of the tree,
         * so there is nothing above it to link back to. A category page gets
         * "START - <area>", because that breadcrumb really does navigate.
         */
        if (title === null) {
            var brand = document.createElement('span');
            brand.className = 'crumb__text';
            brand.setAttribute('data-i18n', 'app.brand');
            brand.textContent = t('app.brand');
            elements.crumb.appendChild(brand);
            return;
        }
JS,
        <<<'JS'
        /*
         * The home page shows no label at all: it is the top of the tree, so
         * there is nothing above it to link back to. A category page gets
         * "START - <area>", because that breadcrumb really does navigate.
         */
        if (title === null) {
            return;
        }
JS,
    ],
]);

/* ==========================================================================
   3. app.css
   ========================================================================== */

patch($root . '/public/assets/css/app.css', [
    /* The underline, its animation and its small-screen switch-off. */
    [
        <<<'CSS'
/*
 * Hand-drawn looking underline under one word of the heading. The path is an
 * inline SVG inside .heading__accent-word, and it is drawn with the usual
 * stroke-dashoffset trick. The animation delay puts it after the heading line
 * animation has finished.
 */
.heading__accent-word {
    position: relative;
    display: inline-block;
}

.heading__accent {
    position: absolute;
    left: 0;
    right: 0;
    /* Sits in the space below the baseline, inside the mask padding. */
    bottom: -0.16em;
    width: 100%;
    height: 0.12em;
    overflow: visible;
    color: var(--text);
    pointer-events: none;
}

/*
 * The path is drawn with the usual stroke-dashoffset trick. The dash values here
 * are only a fallback: app.js measures the real path length and sets both
 * values inline, so the line is never partly visible before it is drawn.
 */
.heading__accent path {
    fill: none;
    stroke: currentColor;
    stroke-width: 3;
    stroke-linecap: round;
    stroke-dasharray: 200;
    stroke-dashoffset: 200;
    animation: accent-draw 700ms var(--ease) 820ms forwards;
}

@keyframes accent-draw {
    to {
        stroke-dashoffset: 0;
    }
}

/*
 * Below 640px the heading wraps unpredictably, so the underline is dropped
 * rather than risk sitting under the wrong word or outside its line box.
 */
@media (max-width: 639px) {
    .heading__accent {
        display: none;
    }
}

CSS,
        '',
    ],
    [
        <<<'CSS'
    /* The underline appears complete instead of being drawn stroke by stroke. */
    .heading__accent path {
        animation: none !important;
        stroke-dashoffset: 0 !important;
    }

CSS,
        '',
    ],

    /* The two rules that only the removed pills used. */
    [
        <<<'CSS'
/* The three start-page values sit in one row beside the heading. */
.stats--start {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    margin-top: 0;
}

CSS,
        '',
    ],
    [
        <<<'CSS'
.stats--start .stats__value {
    font-size: clamp(1rem, 1.4vw, 1.2rem);
}

CSS,
        '',
    ],
    [
        <<<'CSS'
    /* The header stacks, so the statistics sit under the heading. */
    .start-header {
        grid-template-columns: minmax(0, 1fr);
    }

    .stats--start {
        margin-top: 4px;
    }
}
CSS,
        '}',
    ],
    [
        <<<'CSS'
    .stats--start {
        gap: 8px;
    }

    /* The card count stays, the decorative track is dropped: the row would
CSS,
        <<<'CSS'
    /* The card count stays, the decorative track is dropped: the row would
CSS,
    ],

    /* The start header has one child again, so it needs one column. */
    [
        <<<'CSS'
.start-header {
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto;
    align-items: end;
CSS,
        <<<'CSS'
.start-header {
    display: grid;
    /* One column: the heading is the only thing left in here. */
    grid-template-columns: minmax(0, 1fr);
    align-items: end;
CSS,
    ],
]);

echo "all targets patched\n";
