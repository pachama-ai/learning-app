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
(function () {
    'use strict';

    /* A thin stroke arrow, drawn inline so no extra icon file is needed.
       "currentColor" makes it follow the theme. */
    var ARROW_SVG = '<svg class="arrow" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" focusable="false" aria-hidden="true"><path d="M4 12h15M13 6l6 6-6 6"/></svg>';

    /*
     * Shown for a category that has no drawing of its own yet.
     *
     * A neutral ring, written inline as a data URI: nothing is loaded from a
     * static file, and it is deliberately not one of the subject drawings, so
     * an empty tile can never be mistaken for a real icon.
     */
    var FALLBACK_ICON = 'data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 24 24%22 fill=%22none%22 stroke=%22%23000%22 stroke-width=%221.2%22%3E%3Ccircle cx=%2212%22 cy=%2212%22 r=%227.5%22/%3E%3C/svg%3E';

    /*
     * There is deliberately NO table of categories in this file.
     *
     * An earlier version carried a list of the known learning areas with
     * their name, their icon file, their icon size, their colour and their
     * wording in both languages. Every one of those values is a column of
     * the `categories` table now and is read from the API:
     *
     *   the name of a category   -> name, name_en, name_de
     *   its drawing              -> icon_svg, served by api/category_icon.php
     *   the size of the drawing  -> icon_scale
     *   its colour               -> color
     *   how much is inside it    -> subcategory_count, card_count (SQL counts)
     *
     * Only two things are still written down here: the arrow shape and the
     * neutral placeholder above. Neither of them is application data.
     */

    var config = JSON.parse(document.getElementById('app-config').textContent);
    var translations = config.translations || {};
    var locale = config.defaultLocale;
    var theme = 'light';

    /* Answers are kept for this page load, so switching the language does not
       send the same request again. Emptied after a new area is created. */
    var responseCache = {};

    /* Set when a new area was just created, so its tile can be animated in. */
    var newAreaId = null;

    /* The tiles of the current start page, linked by position for the hover. */
    var linkedTiles = [];

    /*
     * What the detail view is showing right now. "currentEntry" is the category
     * the page belongs to, so the plus button, the edit button and the dialog
     * titles can all answer the question "what would this act on?".
     */
    var currentEntry = null;
    var currentEntryCards = [];
    var editingEntry = null;
    var editingCard = null;
    var pendingDelete = null;
    var stagedIconSvg = null;
    var stagedIconName = '';
    var iconRemoved = false;
    var openMenu = null;
    var feedbackTimer = null;

    /* Which entry a new one is created in, and what the empty state offers. */
    var editingParentId = null;
    var entryEmptyHandler = null;

    /*
     * pendingColor stays undefined until somebody touches the colour field.
     * Undefined means "leave the stored colour alone", which matters because the
     * API answers with a fallback colour that is NOT stored in the database.
     */
    var pendingColor;

    var elements = {
        page: document.querySelector('.page'),
        crumb: document.getElementById('crumb'),
        homeView: document.getElementById('view-home'),
        detailView: document.getElementById('view-detail'),
        homeHeading: document.getElementById('home-heading'),
        detailHeading: document.getElementById('detail-heading'),
        detailStats: document.getElementById('detail-stats'),
        detailCount: document.getElementById('detail-count'),
        detailDot: document.getElementById('detail-dot'),
        grid: document.getElementById('area-grid'),
        tiles: document.getElementById('tiles'),
        tilesNav: document.getElementById('tiles-nav'),
        tilesTrack: document.getElementById('tiles-nav-track'),
        tilesThumb: document.getElementById('tiles-nav-thumb'),
        /* The two arrows live in the footer; the wrapper around them is hidden
           on every view that has no tile row. */
        tilesButtons: document.getElementById('tiles-nav-buttons'),
        tilesPrev: document.getElementById('tiles-nav-prev'),
        tilesNext: document.getElementById('tiles-nav-next'),
        sidebarNav: document.getElementById('sidebar-nav'),
        loading: document.getElementById('loading-state'),
        error: document.getElementById('error-state'),
        empty: document.getElementById('empty-state'),
        emptyTitle: document.getElementById('empty-title'),
        emptyHint: document.getElementById('empty-hint'),
        addButton: document.getElementById('add-button'),
        editButton: document.getElementById('edit-button'),
        themeToggle: document.getElementById('theme-toggle'),
        langUnderline: document.getElementById('lang-underline'),
        localeButtons: document.querySelectorAll('[data-locale]'),
        emptyAction: document.getElementById('empty-action'),

        entryList: document.getElementById('entry-list'),
        entryEmpty: document.getElementById('entry-empty'),
        entryEmptyTitle: document.getElementById('entry-empty-title'),
        entryEmptyHint: document.getElementById('entry-empty-hint'),
        entryEmptyAction: document.getElementById('entry-empty-action'),
        areaCards: document.getElementById('area-cards'),
        areaCardList: document.getElementById('area-card-list'),

        detailActions: document.getElementById('detail-actions'),
        editEntry: document.getElementById('edit-entry'),
        deleteEntry: document.getElementById('delete-entry'),
        statLabel: document.getElementById('detail-stat-label'),

        categoryDialog: document.getElementById('category-dialog'),
        categoryForm: document.getElementById('category-form'),
        categoryTitle: document.getElementById('category-dialog-title'),
        categoryName: document.getElementById('category-name'),
        categoryNameEn: document.getElementById('category-name-en'),
        categoryNameDe: document.getElementById('category-name-de'),
        categoryDescriptionEn: document.getElementById('category-description-en'),
        categoryDescriptionDe: document.getElementById('category-description-de'),
        categoryColor: document.getElementById('category-color'),
        categoryColorClear: document.getElementById('category-color-clear'),
        categoryIcon: document.getElementById('category-icon'),
        categoryIconState: document.getElementById('category-icon-state'),
        categoryIconBadge: document.getElementById('category-icon-badge'),
        categoryIconRemove: document.getElementById('category-icon-remove'),
        categoryIconScale: document.getElementById('category-icon-scale'),
        categoryError: document.getElementById('category-error'),
        categoryCancel: document.getElementById('category-cancel'),
        categorySave: document.getElementById('category-save'),
        categoryDelete: document.getElementById('category-delete'),

        cardDialog: document.getElementById('card-dialog'),
        cardForm: document.getElementById('card-form'),
        cardTitle: document.getElementById('card-dialog-title'),
        cardFront: document.getElementById('card-front'),
        cardBack: document.getElementById('card-back'),
        cardBidirectional: document.getElementById('card-bidirectional'),
        cardError: document.getElementById('card-error'),
        cardCancel: document.getElementById('card-cancel'),
        cardSave: document.getElementById('card-save'),

        deleteDialog: document.getElementById('delete-dialog'),
        deleteForm: document.getElementById('delete-form'),
        deleteTitle: document.getElementById('delete-dialog-title'),
        deleteMessage: document.getElementById('delete-message'),
        deleteConfirmField: document.getElementById('delete-confirm-field'),
        deleteConfirm: document.getElementById('delete-confirm'),
        deleteError: document.getElementById('delete-error'),
        deleteCancel: document.getElementById('delete-cancel'),
        deleteSubmit: document.getElementById('delete-submit'),

        feedback: document.getElementById('feedback')
    };

    /* ----------------------------------------------------------------------
       Small helpers
       ---------------------------------------------------------------------- */

    function prefersReducedMotion() {
        return typeof window.matchMedia === 'function'
            && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    }

    function t(key, replacements) {
        var table = translations[locale] || {};
        var fallback = translations[config.defaultLocale] || {};
        var value = typeof table[key] === 'string' ? table[key] : fallback[key];

        if (typeof value !== 'string') {
            value = key;
        }

        if (replacements) {
            Object.keys(replacements).forEach(function (token) {
                value = value.split('{' + token + '}').join(replacements[token]);
            });
        }

        return value;
    }

    function readStorage(key) {
        try {
            return window.localStorage.getItem(key);
        } catch (error) {
            return null;
        }
    }

    function writeStorage(key, value) {
        try {
            window.localStorage.setItem(key, value);
        } catch (error) {
            /* The page still works, only the memory is missing. */
        }
    }

    /* Two digits with a leading zero: 8 becomes "08". */
    function pad2(value) {
        return value < 10 ? '0' + value : String(value);
    }

    /*
     * The wording of a category.
     *
     * The database decides: `categories.name_<language>` is used when it holds
     * something, and `categories.name` otherwise. Nothing in this file knows the
     * name of a category, so renaming a row in the database renames it on the
     * page - in the language that is switched on.
     */
    function displayName(row) {
        var translated = row['name_' + locale];

        if (typeof translated === 'string' && translated !== '') {
            return translated;
        }

        return row.name;
    }

    /*
     * Everything the page needs to draw one learning area.
     *
     * The drawing comes from the database through api/category_icon.php. Only a
     * category that has no drawing of its own falls back to the neutral
     * placeholder; no icon file is ever chosen by name.
     */
    function categoryMeta(row) {
        var scale = typeof row.icon_scale === 'number' ? row.icon_scale : 1;

        if (!(scale >= 0.2 && scale <= 3)) {
            scale = 1;
        }

        return {
            title: displayName(row),
            icon: row.icon_url ? row.icon_url : FALLBACK_ICON,
            iconScale: scale
        };
    }

    /*
     * The colour of one category, taken from `categories.color`.
     *
     * The stored value is written onto the element as --cat-base and the class
     * "has-cat" switches it on; the stylesheet turns that into --cat and derives
     * the dark variant from the very same value. A category without a colour
     * keeps the neutral --cat-default, so one unusable value can never break the
     * page.
     */
    function applyCategoryColor(element, row) {
        var color = typeof row.color === 'string' && /^#[0-9A-Fa-f]{6}$/.test(row.color)
            ? row.color
            : null;

        if (color === null) {
            element.classList.remove('has-cat');
            element.style.removeProperty('--cat-base');
            return;
        }

        element.classList.add('has-cat');
        element.style.setProperty('--cat-base', color);
    }


    /*
     * The neutral colour of the stylesheet (--cat-default). It is read from the
     * CSS instead of being written down in JavaScript as well, and it is only
     * the value the colour field shows while a category has no colour of its own.
     */
    function defaultCategoryColor() {
        var value = window.getComputedStyle(document.documentElement)
            .getPropertyValue('--cat-default')
            .trim();

        return /^#[0-9A-Fa-f]{6}$/.test(value) ? value : '#C3C8DB';
    }

    /*
     * "08 SUBCATEGORIES" / "01 SUBCATEGORY" - a padded counter, used by the rows
     * of the detail view and by the tooltip. The plural form follows the count.
     */
    function countedLabel(count, oneKey, otherKey) {
        return pad2(count) + ' ' + t(count === 1 ? oneKey : otherKey);
    }

    /*
     * The information line of a tile. Every number in it was counted by the
     * database:
     *
     *   8 subcategories            - what really sits inside
     *   8 subcategories \u00b7 24 cards - plus the cards, once there are any
     *   No subcategories yet       - the empty state, in the current language
     *
     * No pad2 here: this is prose and not a counter, and a zero is never shown as
     * a number.
     */
    function tileDataLine(area) {
        if (area.subcategory_count === 0) {
            return t('tile.subcategories.none');
        }

        var line = area.subcategory_count + ' ' + t(area.subcategory_count === 1 ? 'tile.subcategories.one' : 'tile.subcategories.other');

        if (area.card_count > 0) {
            line += ' \u00B7 ' + area.card_count + ' ' + t(area.card_count === 1 ? 'tile.cards.one' : 'tile.cards.other');
        }

        return line;
    }

    /* The tooltip is shorter: "MATHEMATICS · 24 CARDS". */
    function tileTooltip(area, meta) {
        return t('tile.tooltip', {
            name: meta.title,
            cards: countedLabel(area.card_count, 'tile.cards.one', 'tile.cards.other')
        });
    }

    /* ----------------------------------------------------------------------
       Translation
       ---------------------------------------------------------------------- */

    function translateStaticText() {
        Array.prototype.forEach.call(document.querySelectorAll('[data-i18n]'), function (node) {
            node.textContent = t(node.getAttribute('data-i18n'));
        });

        Array.prototype.forEach.call(document.querySelectorAll('[data-i18n-label]'), function (node) {
            node.setAttribute('aria-label', t(node.getAttribute('data-i18n-label')));
        });

        Array.prototype.forEach.call(document.querySelectorAll('[data-i18n-placeholder]'), function (node) {
            node.setAttribute('placeholder', t(node.getAttribute('data-i18n-placeholder')));
        });

        Array.prototype.forEach.call(document.querySelectorAll('[data-i18n-title]'), function (node) {
            node.setAttribute('title', t(node.getAttribute('data-i18n-title')));
        });

        /* The counter carries numbers, so it is rewritten rather than translated. */
        updateTileNavigation();
    }

    /* ----------------------------------------------------------------------
       Headings: one clipped mask per line, each line sliding up on its own
       ---------------------------------------------------------------------- */

    function setHeading(element, text) {
        var lines = String(text).split('\n');

        element.textContent = '';

        lines.forEach(function (line, index) {
            var mask = document.createElement('span');
            mask.className = 'heading__mask';

            var inner = document.createElement('span');
            inner.className = 'heading__line';
            inner.style.setProperty('--line-index', String(index));
            inner.textContent = line;

            mask.appendChild(inner);
            element.appendChild(mask);
        });
    }

    /* ----------------------------------------------------------------------
       Animations
       ---------------------------------------------------------------------- */

    function applyRevealOrder(nodes, startIndex) {
        Array.prototype.forEach.call(nodes, function (node, index) {
            node.style.setProperty('--reveal-index', String(startIndex + index));
        });
    }

    /*
     * Counts a number up from zero to the value the API really returned. The
     * detail view uses it for its subcategory count.
     */
    function animateCount(element, value) {
        var target = Number(value) || 0;

        if (prefersReducedMotion()) {
            element.textContent = String(target);
            return;
        }

        /* Hidden from assistive technology while it counts, so a screen reader
           announces the final value once instead of every intermediate step. */
        element.setAttribute('aria-hidden', 'true');

        var start = null;
        var duration = 900;

        function step(timestamp) {
            if (start === null) {
                start = timestamp;
            }

            var progress = Math.min((timestamp - start) / duration, 1);
            var eased = 1 - Math.pow(1 - progress, 3);

            element.textContent = String(Math.round(target * eased));

            if (progress < 1) {
                window.requestAnimationFrame(step);
                return;
            }

            element.textContent = String(target);
            element.removeAttribute('aria-hidden');
        }

        window.requestAnimationFrame(step);
    }

    /* Thin-line skeletons while the areas are loading. */
    function showSkeletons(container, count) {
        container.textContent = '';

        for (var index = 0; index < count; index++) {
            var skeleton = document.createElement('div');
            skeleton.className = 'skeleton';
            skeleton.setAttribute('aria-hidden', 'true');

            var blob = document.createElement('span');
            blob.className = 'skeleton__blob';

            var lineOne = document.createElement('span');
            lineOne.className = 'skeleton__line';

            var lineTwo = document.createElement('span');
            lineTwo.className = 'skeleton__line skeleton__line--short';

            skeleton.appendChild(blob);
            skeleton.appendChild(lineOne);
            skeleton.appendChild(lineTwo);
            container.appendChild(skeleton);
        }
    }

    /* ----------------------------------------------------------------------
       Linked hover between the tiles of the start page
       ---------------------------------------------------------------------- */

    function setLinked(index) {
        linkedTiles.forEach(function (tile, position) {
            tile.classList.toggle('is-linked', position === index);
        });

        elements.grid.classList.toggle('is-linking', index !== null);
    }

    function clearLinked() {
        setLinked(null);
    }

    function wireLinkedHover() {
        function wire(node, index) {
            if (node === null) {
                return;
            }

            node.addEventListener('mouseenter', function () {
                setLinked(index);
            });
            node.addEventListener('mouseleave', clearLinked);
            /* Keyboard focus behaves exactly like hovering. */
            node.addEventListener('focus', function () {
                setLinked(index);
            });
            node.addEventListener('blur', clearLinked);
        }

        linkedTiles.forEach(wire);
    }

    /* ----------------------------------------------------------------------
       API (the existing endpoints, called exactly as before)
       ---------------------------------------------------------------------- */

    function fetchJson(url) {
        return window.fetch(url, {
            headers: { Accept: 'application/json' }
        }).then(function (response) {
            return response.json()
                .catch(function () {
                    return null;
                })
                .then(function (payload) {
                    if (!response.ok || !payload || payload.success !== true) {
                        throw new Error('request to ' + url + ' failed with status ' + response.status);
                    }

                    return payload.data;
                });
        });
    }

    function fetchCategories(query) {
        if (responseCache[query]) {
            return Promise.resolve(responseCache[query]);
        }

        return fetchJson(config.endpoints.categories + query).then(function (data) {
            if (!Array.isArray(data)) {
                throw new Error('categories response is not a list');
            }

            responseCache[query] = data;
            return data;
        });
    }


    /*
     * One helper for every write. It always answers with an object, so a caller
     * never has to look at HTTP details:
     *   { ok: true,  data: ... }
     *   { ok: false, code: 'category_exists' }
     */
    function apiRequest(url, method, body) {
        var options = {
            method: method,
            headers: { Accept: 'application/json' }
        };

        if (body !== undefined) {
            options.headers['Content-Type'] = 'application/json';
            options.body = JSON.stringify(body);
        }

        return window.fetch(url, options).then(function (response) {
            return response.json()
                .catch(function () {
                    /* A body that is not JSON (for example an HTML error page)
                       must not stop the page with an exception. */
                    return null;
                })
                .then(function (payload) {
                    if (response.ok && payload && payload.success === true) {
                        return { ok: true, status: response.status, data: payload.data };
                    }

                    return {
                        ok: false,
                        status: response.status,
                        code: payload && payload.error ? String(payload.error.code) : 'request_failed'
                    };
                });
        }).catch(function () {
            /* The request never reached the server. */
            return { ok: false, status: 0, code: 'network_error' };
        });
    }

    /* Turns a code from the API into a sentence in the current language. */
    function errorMessage(code) {
        if (code === 'category_exists') {
            return t('dialog.errorDuplicate');
        }

        if (code === 'invalid_icon') {
            return t('dialog.errorIcon');
        }

        if (code === 'invalid_icon_scale') {
            return t('dialog.errorScale');
        }

        if (code === 'invalid_front') {
            return t('dialog.errorFront');
        }

        if (code === 'invalid_back') {
            return t('dialog.errorBack');
        }

        if (code === 'name_mismatch') {
            return t('dialog.errorConfirmName');
        }

        if (code === 'invalid_name' || code === 'invalid_request_body') {
            return t('dialog.errorName');
        }

        return t('dialog.errorServer');
    }

    /* Short message at the bottom of the page, for a moment. */
    function showFeedback(message) {
        elements.feedback.textContent = message;
        elements.feedback.hidden = false;

        window.clearTimeout(feedbackTimer);
        feedbackTimer = window.setTimeout(function () {
            elements.feedback.hidden = true;
        }, 3200);
    }

    /* ----------------------------------------------------------------------
       Theme and language
       ---------------------------------------------------------------------- */

    function updateThemeControl() {
        elements.themeToggle.setAttribute('aria-pressed', theme === 'dark' ? 'true' : 'false');
        elements.themeToggle.setAttribute(
            'aria-label',
            t(theme === 'dark' ? 'theme.switch.toLight' : 'theme.switch.toDark')
        );
    }

    /* The moon or sun turns 90 degrees on every switch. */
    function rotateThemeIcon() {
        elements.themeToggle.classList.remove('is-rotating');
        /* Reading a layout value restarts the animation on a repeated click. */
        void elements.themeToggle.offsetWidth;
        elements.themeToggle.classList.add('is-rotating');

        window.setTimeout(function () {
            elements.themeToggle.classList.remove('is-rotating');
        }, 500);
    }

    function applyTheme(nextTheme, persist) {
        var next = nextTheme === 'dark' ? 'dark' : 'light';
        var root = document.documentElement;

        function commit() {
            theme = next;
            root.setAttribute('data-theme', next);
            updateThemeControl();

            if (persist) {
                writeStorage(config.storageKeys.theme, theme);
            }
        }

        if (next === theme) {
            commit();
            rotateThemeIcon();
            return;
        }

        var canReveal = !prefersReducedMotion()
            && typeof document.startViewTransition === 'function';

        if (!canReveal) {
            commit();
            rotateThemeIcon();
            return;
        }

        /* The reveal starts at the theme button. */
        var rect = elements.themeToggle.getBoundingClientRect();
        root.style.setProperty('--reveal-x', (rect.left + rect.width / 2) + 'px');
        root.style.setProperty('--reveal-y', (rect.top + rect.height / 2) + 'px');
        root.classList.add('is-theme-switching');

        var transition = document.startViewTransition(function () {
            commit();
            rotateThemeIcon();
        });

        Promise.resolve(transition.finished).catch(function () {
            root.classList.remove('is-theme-switching');
        }).then(function () {
            root.classList.remove('is-theme-switching');
        });
    }

    function updateLanguageUnderline() {
        var active = null;

        Array.prototype.forEach.call(elements.localeButtons, function (button) {
            if (button.getAttribute('data-locale') === locale) {
                active = button;
            }
        });

        if (active === null) {
            return;
        }

        elements.langUnderline.style.width = active.offsetWidth + 'px';
        elements.langUnderline.style.transform = 'translateX(' + active.offsetLeft + 'px)';
    }

    function updateLanguageButtons() {
        Array.prototype.forEach.call(elements.localeButtons, function (button) {
            button.setAttribute('aria-pressed', button.getAttribute('data-locale') === locale ? 'true' : 'false');
        });

        updateLanguageUnderline();
    }

    function applyLocale(nextLocale, persist) {
        var next = typeof translations[nextLocale] === 'object' ? nextLocale : config.defaultLocale;

        if (persist) {
            writeStorage(config.storageKeys.language, next);
        }

        function swap() {
            locale = next;
            document.documentElement.setAttribute('lang', locale);
            translateStaticText();
            updateLanguageButtons();
            updateThemeControl();
            render();
        }

        if (locale === next || prefersReducedMotion()) {
            swap();
            return;
        }

        elements.page.classList.add('is-lang-switching');

        window.setTimeout(function () {
            swap();
            elements.page.classList.remove('is-lang-switching');
        }, 150);
    }

    /* ----------------------------------------------------------------------
       Building the start page
       ---------------------------------------------------------------------- */

    function buildAreaTile(area, index) {
        var meta = categoryMeta(area);

        var link = document.createElement('a');
        link.className = 'area-card reveal';
        link.href = 'index.php?category=' + encodeURIComponent(area.id);
        link.setAttribute('data-area-id', String(area.id));
        link.setAttribute('aria-label', t('area.open', { name: meta.title }));
        /*
         * One tile is one link. The colour the database holds for this category is
         * handed to the stylesheet; the tile itself stays neutral, and the colour
         * is used by the rows and by the statistic dot.
         */
        applyCategoryColor(link, area);
        link.style.setProperty('--reveal-index', String(2 + index));

        /* Blob on the left, [01] on the right. */
        var head = document.createElement('span');
        head.className = 'area-card__head';

        var blob = document.createElement('span');
        blob.className = 'blob';

        /*
         * The subject drawings are used unchanged. The alt text is empty on
         * purpose: the whole tile is one link with an accessible name, so
         * describing the drawing again would only repeat it.
         */
        var icon = document.createElement('img');
        icon.className = 'blob__icon';
        icon.src = meta.icon;
        icon.alt = '';
        /*
         * No loading="lazy" here. The drawing comes from the database through
         * api/category_icon.php, and a lazy image stayed empty in testing even
         * while the tile was on screen. The answer carries a fingerprint and is
         * cached for a week, so it is only fetched once anyway.
         */
        icon.setAttribute('decoding', 'async');
        icon.style.setProperty('--icon-scale', String(meta.iconScale));
        blob.appendChild(icon);

        head.appendChild(blob);

        var name = document.createElement('span');
        name.className = 'area-card__name';
        /* textContent, never innerHTML: the name comes from the database. */
        name.textContent = meta.title;

        var foot = document.createElement('span');
        foot.className = 'area-card__foot';

        var stat = document.createElement('span');
        stat.className = 'area-card__stat';
        stat.textContent = tileDataLine(area);

        var arrow = document.createElement('span');
        arrow.innerHTML = ARROW_SVG;

        foot.appendChild(stat);
        foot.appendChild(arrow);

        /*
         * The head keeps the icon at the top, the title and the information line
         * form one block at the bottom. The height between the two is the air a
         * taller tile gains.
         */
        var bottom = document.createElement('span');
        bottom.className = 'area-card__bottom';
        bottom.appendChild(name);
        bottom.appendChild(foot);

        var tooltip = document.createElement('span');
        tooltip.className = 'area-card__tooltip';
        tooltip.setAttribute('aria-hidden', 'true');
        tooltip.textContent = tileTooltip(area, meta);

        link.appendChild(head);
        link.appendChild(bottom);
        link.appendChild(tooltip);

        return link;
    }

    function hideStates() {
        elements.error.hidden = true;
        elements.empty.hidden = true;
        elements.loading.hidden = true;
    }

    function showError() {
        hideStates();
        elements.error.hidden = false;
        elements.grid.hidden = true;
        elements.entryList.hidden = true;
        elements.entryEmpty.hidden = true;
        elements.areaCards.hidden = true;
    }

    /* ----------------------------------------------------------------------
       Home view
       ---------------------------------------------------------------------- */

    function renderHome() {
        elements.homeView.hidden = false;
        elements.detailView.hidden = true;

        /* Nothing is open here, so the plus button adds a learning area. */
        currentEntry = null;
        currentEntryCards = [];

        setCrumb(null);
        setHeading(elements.homeHeading, t('home.heading'));
        document.title = t('app.title');

        hideStates();
        elements.grid.hidden = false;
        showSkeletons(elements.grid, 4);

        fetchCategories('').then(function (areas) {
            linkedTiles = [];
            elements.grid.textContent = '';

            var highlighted = null;

            areas.forEach(function (area, index) {
                var tile = buildAreaTile(area, index);
                elements.grid.appendChild(tile);
                linkedTiles.push(tile);

                if (newAreaId !== null && area.id === newAreaId) {
                    highlighted = tile;
                }
            });

            if (areas.length === 0) {
                elements.grid.hidden = true;
                elements.emptyTitle.textContent = t('home.empty.title');
                elements.emptyHint.textContent = t('home.empty.hint');
                elements.emptyAction.textContent = t('footer.addAria');
                elements.emptyAction.hidden = false;
                elements.empty.hidden = false;
            }

            newAreaId = null;

            if (highlighted !== null) {
                highlighted.classList.add('is-new', 'is-highlighted');

                /*
                 * Both classes are removed again afterwards. "item-in" ends with
                 * animation-fill-mode "both", which would keep opacity at 1 and
                 * silently stop the linked-hover dimming on this one tile.
                 */
                window.setTimeout(function () {
                    highlighted.classList.remove('is-highlighted');
                }, 1200);

                window.setTimeout(function () {
                    highlighted.classList.remove('is-new');
                }, 1400);
            }

            wireLinkedHover();
            updateFooterControls('home');
            updateTileNavigation();
        }).catch(handleLoadError);
    }

    /* ----------------------------------------------------------------------
       Detail view (unchanged behaviour)
       ---------------------------------------------------------------------- */

    function buildSidebarLink(area, index, isActive) {
        var link = document.createElement('a');
        link.className = 'sidebar__link';
        link.href = 'index.php?category=' + encodeURIComponent(area.id);
        /* Lets the row pick up its category colour where it is needed. */
        applyCategoryColor(link, area);

        if (isActive) {
            link.setAttribute('aria-current', 'page');
        }

        var number = document.createElement('span');
        number.textContent = '[' + pad2(index + 1) + ']';

        var name = document.createElement('span');
        name.textContent = displayName(area);

        link.appendChild(number);
        link.appendChild(name);

        return link;
    }

    /*
     * A small "..." button with a menu. It is the only place where an entry can
     * be changed or removed, and it really is a button, so it works with a
     * keyboard: Enter opens the menu, Escape closes it again.
     */
    function buildMenu(actions, label) {
        var wrap = document.createElement('span');
        wrap.className = 'row__menu';

        var button = document.createElement('button');
        button.type = 'button';
        button.className = 'menu-button';
        button.setAttribute('aria-haspopup', 'true');
        button.setAttribute('aria-expanded', 'false');
        button.setAttribute('aria-label', t('action.more', { name: label }));
        /* The three dots, written as one character so no icon file is needed. */
        button.textContent = '\u22EF';

        var menu = document.createElement('span');
        menu.className = 'menu';
        menu.setAttribute('role', 'menu');
        menu.hidden = true;

        actions.forEach(function (action) {
            var item = document.createElement('button');
            item.type = 'button';
            item.className = 'menu__item' + (action.danger ? ' menu__item--danger' : '');
            item.setAttribute('role', 'menuitem');
            item.textContent = action.label;
            item.addEventListener('click', function () {
                closeMenu();
                action.run();
            });
            menu.appendChild(item);
        });

        button.addEventListener('click', function (event) {
            /* Without this the document listener would close the menu again at
               once, because the click is still on its way up. */
            event.stopPropagation();
            toggleMenu(wrap, button, menu);
        });

        wrap.appendChild(button);
        wrap.appendChild(menu);

        return wrap;
    }

    function toggleMenu(wrap, button, menu) {
        if (openMenu !== null && openMenu.menu === menu) {
            closeMenu();
            return;
        }

        closeMenu();

        openMenu = { wrap: wrap, button: button, menu: menu };
        menu.hidden = false;
        button.setAttribute('aria-expanded', 'true');
    }

    function closeMenu() {
        if (openMenu === null) {
            return;
        }

        openMenu.menu.hidden = true;
        openMenu.button.setAttribute('aria-expanded', 'false');
        openMenu = null;
    }

    /*
     * One subcategory row: a link to its flashcards, the number of cards that
     * sit in it and a menu with "edit" and "delete".
     */
    function buildEntryRow(entry, index, colorContext) {
        var item = document.createElement('li');
        item.className = 'row reveal';
        item.style.setProperty('--reveal-index', String(index));
        /* The rows belong to the open area, so they share its colour. */
        applyCategoryColor(item, colorContext);

        var title = displayName(entry);

        var link = document.createElement('a');
        link.className = 'row__link';
        link.href = 'index.php?category=' + encodeURIComponent(entry.id);
        link.setAttribute('aria-label', t('cards.open', { name: title }));

        var number = document.createElement('span');
        number.className = 'row__index';
        number.textContent = '[' + pad2(index + 1) + ']';

        var name = document.createElement('span');
        name.className = 'row__name';
        name.textContent = title;

        /*
         * The real number of cards in this subcategory, counted by the API. It
         * stays "00" while there is nothing to count, because a row of numbers
         * reads better when it has the same width everywhere.
         */
        var count = document.createElement('span');
        count.className = 'row__count data-pill';
        count.textContent = countedLabel(entry.own_card_count, 'tile.cards.one', 'tile.cards.other');

        /*
         * Decorative empty track, exactly like the one on a tile: there is no
         * review history yet, so there is nothing honest to fill in.
         */
        var progress = document.createElement('span');
        progress.className = 'row__progress';
        progress.setAttribute('aria-hidden', 'true');

        var progressFill = document.createElement('span');
        progressFill.className = 'row__progress-fill';
        progress.appendChild(progressFill);

        var arrow = document.createElement('span');
        arrow.className = 'row__arrow';
        arrow.innerHTML = ARROW_SVG;

        link.appendChild(number);
        link.appendChild(name);
        link.appendChild(count);
        link.appendChild(progress);
        link.appendChild(arrow);

        item.appendChild(link);
        item.appendChild(buildMenu([
            {
                label: t('action.edit'),
                run: function () {
                    openCategoryDialog('edit', entry, entry.parent_id);
                }
            },
            {
                label: t('action.delete'),
                danger: true,
                run: function () {
                    openDeleteDialog('category', entry);
                }
            }
        ], title));

        return item;
    }

    /*
     * One flashcard row. A card has no page of its own, so there is no link
     * here: the row shows the front, the back and, when the card is meant to be
     * practised both ways, a badge.
     */
    function buildCardRow(card, index) {
        var item = document.createElement('li');
        item.className = 'row row--card reveal';
        item.style.setProperty('--reveal-index', String(index));

        var body = document.createElement('span');
        body.className = 'row__link row__link--static';

        var number = document.createElement('span');
        number.className = 'row__index';
        number.textContent = '[' + pad2(index + 1) + ']';

        var stack = document.createElement('span');
        stack.className = 'row__stack';

        var front = document.createElement('span');
        front.className = 'row__name';
        /* textContent, never innerHTML: both sides come from the database. */
        front.textContent = card.front;

        var back = document.createElement('span');
        back.className = 'row__back';
        back.textContent = card.back;

        stack.appendChild(front);
        stack.appendChild(back);

        var badge = document.createElement('span');
        badge.className = 'row__badge';
        badge.textContent = t('card.bidirectional');
        badge.hidden = card.is_bidirectional !== true;

        body.appendChild(number);
        body.appendChild(stack);
        body.appendChild(badge);

        item.appendChild(body);
        item.appendChild(buildMenu([
            {
                label: t('action.edit'),
                run: function () {
                    openCardDialog(card, card.category_id);
                }
            },
            {
                label: t('action.delete'),
                danger: true,
                run: function () {
                    openDeleteDialog('card', card);
                }
            }
        ], card.front));

        return item;
    }

    /*
     * The label above the number of the detail view. The key is kept on the
     * element as well, so a language switch translates it again by itself.
     */
    function setStatLabel(key) {
        elements.statLabel.textContent = t(key);
        elements.statLabel.setAttribute('data-i18n', key);
    }

    /*
     * The empty state of the detail view. Which button it offers depends on what
     * the page is about, so the action is handed in as a function.
     */
    function showEntryEmpty(titleKey, hintKey, actionKey, run) {
        elements.entryList.hidden = true;
        elements.entryEmptyTitle.textContent = t(titleKey);
        elements.entryEmptyHint.textContent = t(hintKey);
        elements.entryEmptyAction.textContent = t(actionKey);
        elements.entryEmptyAction.hidden = false;
        entryEmptyHandler = run;
        elements.entryEmpty.hidden = false;
    }

    /* Flashcards that sit directly in a learning area, not in a subcategory. */
    function renderAreaCards(cards) {
        elements.areaCardList.textContent = '';

        if (cards.length === 0) {
            elements.areaCards.hidden = true;
            return;
        }

        cards.forEach(function (card, index) {
            elements.areaCardList.appendChild(buildCardRow(card, index));
        });

        elements.areaCards.hidden = false;
    }

    /*
     * The detail view shows whatever is inside ONE category:
     *   - a learning area: its subcategories, and the flashcards that sit
     *     directly in the area when there are any
     *   - a subcategory: its flashcards
     *
     * Four requests are made at the same time: the area list (for the sidebar
     * and the footer counter), the category itself, its children and its cards.
     * The category is read with apiRequest so that "this does not exist"
     * (404) can be told apart from "the server is not answering".
     */
    function renderDetail(categoryId) {
        elements.homeView.hidden = true;
        elements.detailView.hidden = false;

        setCrumb(null);
        setHeading(elements.detailHeading, '');
        document.title = t('app.title');

        hideStates();
        elements.entryList.hidden = true;
        elements.entryEmpty.hidden = true;
        elements.areaCards.hidden = true;
        elements.detailActions.hidden = true;
        elements.detailStats.hidden = true;

        Promise.all([
            fetchCategories(''),
            apiRequest(config.endpoints.categories + '?id=' + encodeURIComponent(categoryId), 'GET'),
            fetchCategories('?parent_id=' + encodeURIComponent(categoryId)),
            apiRequest(config.endpoints.cards + '?category_id=' + encodeURIComponent(categoryId), 'GET')
        ]).then(function (results) {
            var allAreas = results[0];
            var single = results[1];
            var children = results[2];
            var cardsResult = results[3];

            elements.sidebarNav.textContent = '';
            allAreas.forEach(function (area, index) {
                elements.sidebarNav.appendChild(buildSidebarLink(area, index, area.id === categoryId));
            });

            if (!single.ok) {
                /* The id is in the URL but the category is gone. */
                setHeading(elements.detailHeading, t('detail.notFound.title'));
                elements.entryEmptyTitle.textContent = t('detail.notFound.title');
                elements.entryEmptyHint.textContent = t('detail.notFound.hint');
                elements.entryEmptyAction.hidden = true;
                elements.entryEmpty.hidden = false;
                return;
            }

            var current = single.data;
            var isSubcategory = current.parent_id !== null;
            var parent = null;

            if (isSubcategory) {
                allAreas.forEach(function (area) {
                    if (area.id === current.parent_id) {
                        parent = area;
                    }
                });
            }

            currentEntry = current;
            currentEntryCards = cardsResult.ok && Array.isArray(cardsResult.data) ? cardsResult.data : [];

            /*
             * The rows of a subcategory share the colour of their learning area,
             * so a whole branch reads as one category.
             */
            var colourSource = parent === null ? current : parent;
            var pageTitle = displayName(current);

            var crumbParts = [];

            if (isSubcategory && parent !== null) {
                crumbParts.push({
                    label: displayName(parent),
                    href: 'index.php?category=' + encodeURIComponent(parent.id)
                });
            }

            crumbParts.push({ label: pageTitle });
            setCrumb(crumbParts);
            updateFooterControls(isSubcategory ? 'card' : 'area');

            setHeading(elements.detailHeading, pageTitle);
            document.title = pageTitle + ' — ' + t('app.title');

            /* The dot beside the statistic carries the colour of the area. */
            applyCategoryColor(elements.detailDot, colourSource);

            elements.detailActions.hidden = false;
            elements.detailStats.hidden = false;

            if (isSubcategory) {
                setStatLabel('cards.heading');
                animateCount(elements.detailCount, currentEntryCards.length);

                elements.entryList.textContent = '';

                if (currentEntryCards.length === 0) {
                    showEntryEmpty('cards.empty.title', 'cards.empty.hint', 'cards.addCard', function () {
                        openCardDialog(null, current.id);
                    });
                } else {
                    currentEntryCards.forEach(function (card, index) {
                        elements.entryList.appendChild(buildCardRow(card, index));
                    });

                    elements.entryList.hidden = false;
                }

                /* The learning area section belongs to the level above. */
                elements.areaCards.hidden = true;
                return;
            }

            setStatLabel('detail.subareas');
            animateCount(elements.detailCount, children.length);

            elements.entryList.textContent = '';

            if (children.length === 0) {
                showEntryEmpty('detail.empty.title', 'detail.empty.hint', 'detail.addSubcategory', function () {
                    openCategoryDialog('create', null, current.id);
                });
            } else {
                children.forEach(function (child, index) {
                    elements.entryList.appendChild(buildEntryRow(child, index, colourSource));
                });

                elements.entryList.hidden = false;
            }

            renderAreaCards(currentEntryCards);
        }).catch(handleLoadError);
    }

    /*
     * The breadcrumb is a list of steps now: "START - Mathematics" on a learning
     * area, "START - Mathematics - Number systems" on a subcategory. A step with
     * an href is a link back, the last step is plain text.
     *
     * The home page passes null and shows no label at all: it is the top of the
     * tree, so there is nothing above it to link back to.
     */
    /*
     * The plus and the edit button mean different things on each level, so they
     * are labelled for what they will do in the view that is open.
     */
    function updateFooterControls(level) {
        var addKey = 'footer.addAria';

        if (level === 'area') {
            addKey = 'footer.addSubcategoryAria';
        } else if (level === 'card') {
            addKey = 'footer.addCardAria';
        }

        elements.addButton.setAttribute('aria-label', t(addKey));
        elements.addButton.setAttribute('data-i18n-label', addKey);

        /* Nothing is open on the home page, so there is nothing to edit. */
        elements.editButton.hidden = level === 'home';
        elements.editButton.setAttribute('aria-label', t('footer.editAria'));
        elements.editButton.setAttribute('data-i18n-label', 'footer.editAria');

        /*
         * The two arrows belong to the tile row, so only the start page shows
         * them. Whether they are greyed out as well is decided by
         * updateTileNavigation().
         */
        elements.tilesButtons.hidden = level !== 'home';
    }

    /* The plus button acts on whatever the detail view is showing. */
    function openAddForCurrentEntry() {
        if (currentEntry === null) {
            openCategoryDialog('create', null, null);
            return;
        }

        if (currentEntry.parent_id === null) {
            openCategoryDialog('create', null, currentEntry.id);
            return;
        }

        openCardDialog(null, currentEntry.id);
    }

    function openEditForCurrentEntry() {
        if (currentEntry === null) {
            return;
        }

        openCategoryDialog('edit', currentEntry, currentEntry.parent_id);
    }

    function setCrumb(parts) {
        elements.crumb.textContent = '';

        if (parts === null || parts.length === 0) {
            return;
        }

        var home = document.createElement('a');
        home.href = 'index.php';
        home.setAttribute('data-i18n', 'crumb.start');
        home.textContent = t('crumb.start');

        elements.crumb.appendChild(home);

        parts.forEach(function (part) {
            var separator = document.createElement('span');
            separator.setAttribute('aria-hidden', 'true');
            separator.textContent = ' — ';

            elements.crumb.appendChild(separator);

            if (part.href) {
                var link = document.createElement('a');
                link.href = part.href;
                link.textContent = part.label;
                elements.crumb.appendChild(link);
                return;
            }

            var current = document.createElement('span');
            current.textContent = part.label;
            elements.crumb.appendChild(current);
        });
    }

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
        var scrollable = maximum > 2;

        /*
         * While every tile fits there is nothing to scroll, and that is not a
         * reason to hide anything: the line stays where it is - it is the
         * hairline above the footer - but it is greyed out, the thumb covers the
         * whole track and the two arrows are disabled. See the stylesheet.
         */
        elements.tilesNav.classList.toggle('is-static', !scrollable);
        elements.tilesPrev.disabled = !scrollable || elements.grid.scrollLeft <= 1;
        elements.tilesNext.disabled = !scrollable || elements.grid.scrollLeft >= maximum - 1;

        var trackWidth = elements.tilesTrack.clientWidth;
        var ratio = elements.grid.scrollWidth > 0 ? elements.grid.clientWidth / elements.grid.scrollWidth : 1;
        /* While everything fits the thumb is exactly as wide as the track. */
        var thumbWidth = scrollable
            ? Math.max(28, Math.round(trackWidth * ratio))
            : trackWidth;

        elements.tilesThumb.style.width = thumbWidth + 'px';

        var travel = Math.max(0, trackWidth - thumbWidth);
        var progress = maximum > 0 ? elements.grid.scrollLeft / maximum : 0;

        elements.tilesThumb.style.transform = 'translateX(' + Math.round(progress * travel) + 'px)';
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
    function scrollTilesToPointer(clientX) {
        var rect = elements.tilesTrack.getBoundingClientRect();
        var thumbWidth = elements.tilesThumb.getBoundingClientRect().width;
        var travel = Math.max(1, rect.width - thumbWidth);
        var offset = Math.min(Math.max(clientX - rect.left - thumbWidth / 2, 0), travel);
        var maximum = elements.grid.scrollWidth - elements.grid.clientWidth;

        setTilesScroll((offset / travel) * maximum);
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
            /* The thumb grows to 3px while it is being dragged. */
            elements.tilesTrack.classList.add('is-dragging');
            beginTileDrag();
            scrollTilesToPointer(event.clientX);
        });

        elements.tilesTrack.addEventListener('pointerup', function (event) {
            if (!elements.tilesTrack.hasPointerCapture(event.pointerId)) {
                return;
            }

            elements.tilesTrack.releasePointerCapture(event.pointerId);
            elements.tilesTrack.classList.remove('is-dragging');
            endTileDrag();
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
            tilePointerStart = { x: event.clientX, y: event.clientY, scrollLeft: elements.grid.scrollLeft };
            tilePointerMoved = false;

            if (event.pointerType === 'mouse') {
                beginTileDrag();
            }
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

        window.addEventListener('pointerup', function () {
            if (tilePointerStart !== null && tilePointerMoved) {
                endTileDrag();
            }

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
        window.console.error(error);
        showError();
    }

    wireTileNavigation();

    function render() {
        clearLinked();

        if (config.categoryId === null) {
            renderHome();
            return;
        }

        renderDetail(config.categoryId);
    }

    /* ----------------------------------------------------------------------
       Dialogs
       ---------------------------------------------------------------------- */

    /* Opens a native dialog and lets it animate in. */
    function openDialog(dialog) {
        if (typeof dialog.showModal === 'function') {
            dialog.showModal();
        } else {
            dialog.setAttribute('open', '');
        }

        window.requestAnimationFrame(function () {
            dialog.classList.add('is-open');
        });
    }

    /* Closes a dialog, waits for the short exit, and hands the focus back. */
    function closeDialog(dialog, focusTarget) {
        dialog.classList.remove('is-open');

        window.setTimeout(function () {
            if (typeof dialog.close === 'function' && dialog.open) {
                dialog.close();
            } else {
                dialog.removeAttribute('open');
            }

            if (focusTarget && typeof focusTarget.focus === 'function') {
                focusTarget.focus();
            }
        }, prefersReducedMotion() ? 0 : 200);
    }

    /* The three ways out of a dialog: cancel, the backdrop and the form. */
    function wireDialog(dialog, cancelButton, form, submitHandler) {
        cancelButton.addEventListener('click', function () {
            closeDialog(dialog, elements.addButton);
        });

        form.addEventListener('submit', submitHandler);

        dialog.addEventListener('click', function (event) {
            /* A click that lands on the dialog itself - not on the form inside
               it - is a click on the backdrop. */
            if (event.target === dialog) {
                closeDialog(dialog, elements.addButton);
            }
        });

        dialog.addEventListener('close', function () {
            dialog.classList.remove('is-open');
        });
    }

    function setDialogError(element, message) {
        element.textContent = message === null ? '' : message;
        element.hidden = message === null;
    }

    function setButtonBusy(button, busy, idleKey, busyKey) {
        button.disabled = busy;
        button.textContent = t(busy ? busyKey : idleKey);
    }

    /* ----------------------------------------------------------------------
       The form for a learning area or a subcategory
       ---------------------------------------------------------------------- */

    function updateIconState() {
        var stored = !iconRemoved && editingEntry !== null && typeof editingEntry.icon_url === 'string' && editingEntry.icon_url !== '';
        var staged = !iconRemoved && stagedIconSvg !== null;

        elements.categoryIconState.hidden = !stored && !staged;

        if (staged) {
            elements.categoryIconBadge.textContent = stagedIconName;
            return;
        }

        elements.categoryIconBadge.textContent = t('action.iconStored');
    }

    /*
     * Opens the category form.
     *
     * mode     "create" or "edit"
     * entry    the category that is being edited (null while creating)
     * parentId the category a new entry belongs in (null -> a learning area)
     */
    function openCategoryDialog(mode, entry, parentId) {
        editingEntry = mode === 'edit' ? entry : null;
        editingParentId = parentId;
        stagedIconSvg = null;
        stagedIconName = '';
        iconRemoved = false;
        pendingColor = undefined;

        elements.categoryForm.reset();
        setDialogError(elements.categoryError, null);
        setButtonBusy(elements.categorySave, false, 'dialog.save', 'dialog.saving');
        elements.categoryIcon.value = '';
        elements.categoryColor.disabled = false;

        if (editingEntry !== null) {
            var isSubcategory = editingEntry.parent_id !== null;

            elements.categoryTitle.textContent = t(
                isSubcategory ? 'dialog.category.editSubcategory' : 'dialog.category.editArea'
            );
            elements.categoryName.value = editingEntry.name;
            elements.categoryNameEn.value = editingEntry.name_en || '';
            elements.categoryNameDe.value = editingEntry.name_de || '';
            elements.categoryDescriptionEn.value = editingEntry.description_en || '';
            elements.categoryDescriptionDe.value = editingEntry.description_de || '';
            elements.categoryColor.value = typeof editingEntry.color === 'string'
                && /^#[0-9A-Fa-f]{6}$/.test(editingEntry.color)
                ? editingEntry.color
                : defaultCategoryColor();
            elements.categoryIconScale.value = String(editingEntry.icon_scale);
            elements.categoryDelete.hidden = false;
        } else {
            elements.categoryTitle.textContent = t(
                parentId === null ? 'dialog.category.createArea' : 'dialog.category.createSubcategory'
            );
            elements.categoryNameEn.value = '';
            elements.categoryNameDe.value = '';
            elements.categoryDescriptionEn.value = '';
            elements.categoryDescriptionDe.value = '';
            /* The neutral colour of the stylesheet, not a colour written here. */
            elements.categoryColor.value = defaultCategoryColor();
            elements.categoryIconScale.value = '1';
            elements.categoryDelete.hidden = true;
        }

        updateIconState();
        openDialog(elements.categoryDialog);
        elements.categoryName.focus();
    }

    /* A colour is "empty" when the field is switched off; that means automatic. */
    function clearCategoryColor() {
        pendingColor = null;
        elements.categoryColor.disabled = true;
    }

    function removeIcon() {
        iconRemoved = true;
        stagedIconSvg = null;
        stagedIconName = '';
        elements.categoryIcon.value = '';
        updateIconState();
    }

    function readIconFile(file) {
        var reader = new window.FileReader();

        reader.addEventListener('load', function () {
            stagedIconSvg = String(reader.result);
            updateIconState();
        });

        reader.addEventListener('error', function () {
            stagedIconSvg = null;
            stagedIconName = '';
            setDialogError(elements.categoryError, t('dialog.errorIcon'));
            updateIconState();
        });

        reader.readAsText(file);
    }

    function handleIconChoice() {
        var files = elements.categoryIcon.files;
        var file = files && files.length > 0 ? files[0] : null;

        setDialogError(elements.categoryError, null);

        if (file === null) {
            return;
        }

        if (file.size > config.limits.iconBytes) {
            setDialogError(elements.categoryError, t('dialog.errorIcon'));
            elements.categoryIcon.value = '';
            return;
        }

        stagedIconName = file.name;
        iconRemoved = false;
        readIconFile(file);
    }

    function handleCategorySubmit(event) {
        event.preventDefault();
        setDialogError(elements.categoryError, null);

        var name = elements.categoryName.value.trim();

        if (name === '') {
            setDialogError(elements.categoryError, t('dialog.errorEmpty'));
            elements.categoryName.focus();
            return;
        }

        if (name.length > config.limits.name) {
            setDialogError(elements.categoryError, t('dialog.errorTooLong'));
            elements.categoryName.focus();
            return;
        }

        var scale = parseFloat(elements.categoryIconScale.value);
        var isEdit = editingEntry !== null;

        if (isNaN(scale) || scale < 0.2 || scale > 3) {
            setDialogError(elements.categoryError, t('dialog.errorScale'));
            elements.categoryIconScale.focus();
            return;
        }

        var payload = {
            name: name,
            name_en: elements.categoryNameEn.value.trim(),
            name_de: elements.categoryNameDe.value.trim(),
            description_en: elements.categoryDescriptionEn.value.trim(),
            description_de: elements.categoryDescriptionDe.value.trim(),
            icon_scale: scale
        };

        if (pendingColor !== undefined) {
            payload.color = pendingColor;
        }

        if (iconRemoved) {
            payload.icon_svg = null;
        } else if (stagedIconSvg !== null) {
            payload.icon_svg = stagedIconSvg;
        }

        if (!isEdit) {
            payload.parent_id = editingParentId;
        }

        var url = isEdit
            ? config.endpoints.category + '?id=' + encodeURIComponent(editingEntry.id)
            : config.endpoints.categories;

        setButtonBusy(elements.categorySave, true, 'dialog.save', 'dialog.saving');

        apiRequest(url, isEdit ? 'PATCH' : 'POST', payload).then(function (result) {
            setButtonBusy(elements.categorySave, false, 'dialog.save', 'dialog.saving');

            if (!result.ok) {
                setDialogError(elements.categoryError, errorMessage(result.code));
                return;
            }

            /* The lists are loaded again, so a new row is really there and an
               edited one shows its new text. */
            responseCache = {};
            newAreaId = !isEdit && result.data && typeof result.data.id === 'number' ? result.data.id : null;

            closeDialog(elements.categoryDialog, elements.addButton);
            showFeedback(t('feedback.saved'));
            render();
        });
    }

    /* ----------------------------------------------------------------------
       The form for one flashcard
       ---------------------------------------------------------------------- */

    function openCardDialog(card, categoryId) {
        editingCard = card;
        editingParentId = categoryId;

        elements.cardForm.reset();
        setDialogError(elements.cardError, null);
        setButtonBusy(elements.cardSave, false, 'dialog.save', 'dialog.saving');

        elements.cardTitle.textContent = t(card === null ? 'dialog.card.create' : 'dialog.card.edit');

        if (card !== null) {
            elements.cardFront.value = card.front;
            elements.cardBack.value = card.back;
            elements.cardBidirectional.checked = card.is_bidirectional === true;
        }

        openDialog(elements.cardDialog);
        elements.cardFront.focus();
    }

    function handleCardSubmit(event) {
        event.preventDefault();
        setDialogError(elements.cardError, null);

        var front = elements.cardFront.value.trim();
        var back = elements.cardBack.value.trim();
        var isEdit = editingCard !== null;

        if (front === '') {
            setDialogError(elements.cardError, t('dialog.errorFront'));
            elements.cardFront.focus();
            return;
        }

        if (back === '') {
            setDialogError(elements.cardError, t('dialog.errorBack'));
            elements.cardBack.focus();
            return;
        }

        if (front.length > config.limits.cardText || back.length > config.limits.cardText) {
            setDialogError(elements.cardError, t('dialog.errorFront'));
            return;
        }

        var payload = {
            front: front,
            back: back,
            is_bidirectional: elements.cardBidirectional.checked
        };

        var url = isEdit
            ? config.endpoints.card + '?id=' + encodeURIComponent(editingCard.id)
            : config.endpoints.cards;

        if (!isEdit) {
            payload.category_id = editingParentId;
        }

        setButtonBusy(elements.cardSave, true, 'dialog.save', 'dialog.saving');

        apiRequest(url, isEdit ? 'PATCH' : 'POST', payload).then(function (result) {
            setButtonBusy(elements.cardSave, false, 'dialog.save', 'dialog.saving');

            if (!result.ok) {
                setDialogError(elements.cardError, errorMessage(result.code));
                return;
            }

            responseCache = {};
            closeDialog(elements.cardDialog, elements.addButton);
            showFeedback(t('feedback.saved'));
            render();
        });
    }

    /* ----------------------------------------------------------------------
       Deleting an entry (and the confirmation that goes with it)
       ---------------------------------------------------------------------- */

    /* "2 subcategories, 1 flashcard" in the language that is switched on. */
    function deletePreviewParts(preview) {
        var parts = [];

        if (preview.categories > 0) {
            parts.push(t(
                preview.categories === 1 ? 'dialog.delete.subcategories.one' : 'dialog.delete.subcategories.other',
                { count: preview.categories }
            ));
        }

        if (preview.cards > 0) {
            parts.push(t(
                preview.cards === 1 ? 'dialog.delete.cards.one' : 'dialog.delete.cards.other',
                { count: preview.cards }
            ));
        }

        return parts.join(', ');
    }

    function openDeleteDialog(kind, target) {
        pendingDelete = { kind: kind, target: target };

        elements.deleteForm.reset();
        setDialogError(elements.deleteError, null);
        elements.deleteConfirm.value = '';
        elements.deleteConfirmField.hidden = kind !== 'category';

        if (kind === 'category') {
            var preview = target.delete_preview || { categories: 0, cards: 0 };
            var parts = deletePreviewParts(preview);

            elements.deleteTitle.textContent = t('dialog.delete.title', {
                name: displayName(target)
            });
            elements.deleteMessage.textContent = parts === ''
                ? t('dialog.delete.nothingBelow')
                : t('dialog.delete.consequence', { parts: parts });
        } else {
            elements.deleteTitle.textContent = t('dialog.deleteCard.title');
            elements.deleteMessage.textContent = t('dialog.deleteCard.hint');
        }

        openDialog(elements.deleteDialog);
        (kind === 'category' ? elements.deleteConfirm : elements.deleteSubmit).focus();
    }

    /* "Delete" inside the edit form: close the form, then ask for the name. */
    function askDeleteAfterEdit(entry) {
        closeDialog(elements.categoryDialog, elements.addButton);

        window.setTimeout(function () {
            openDeleteDialog('category', entry);
        }, prefersReducedMotion() ? 0 : 220);
    }

    function handleDeleteSubmit(event) {
        event.preventDefault();
        setDialogError(elements.deleteError, null);

        if (pendingDelete === null) {
            closeDialog(elements.deleteDialog, elements.addButton);
            return;
        }

        var isCategory = pendingDelete.kind === 'category';
        var target = pendingDelete.target;
        var body = {};

        if (isCategory) {
            body.confirm_name = elements.deleteConfirm.value.trim();

            if (body.confirm_name === '') {
                setDialogError(elements.deleteError, t('dialog.errorConfirmName'));
                elements.deleteConfirm.focus();
                return;
            }
        }

        var url = (isCategory ? config.endpoints.category : config.endpoints.card)
            + '?id=' + encodeURIComponent(target.id);

        setButtonBusy(elements.deleteSubmit, true, 'dialog.delete.submit', 'dialog.delete.deleting');

        apiRequest(url, 'DELETE', body).then(function (result) {
            setButtonBusy(elements.deleteSubmit, false, 'dialog.delete.submit', 'dialog.delete.deleting');

            if (!result.ok) {
                setDialogError(elements.deleteError, errorMessage(result.code));
                return;
            }

            var removedName = isCategory ? displayName(target) : target.front;
            var removedOpenEntry = isCategory && currentEntry !== null && currentEntry.id === target.id;

            responseCache = {};
            closeDialog(elements.deleteDialog, elements.addButton);
            showFeedback(t('feedback.deleted', { name: removedName }));

            if (removedOpenEntry) {
                /* The page itself is gone, so the browser goes up one level. */
                window.location.href = target.parent_id === null
                    ? 'index.php'
                    : 'index.php?category=' + encodeURIComponent(target.parent_id);
                return;
            }

            render();
        });
    }

    /* ----------------------------------------------------------------------
       Start
       ---------------------------------------------------------------------- */

    function wireEvents() {
        elements.themeToggle.addEventListener('click', function () {
            applyTheme(theme === 'dark' ? 'light' : 'dark', true);
        });

        Array.prototype.forEach.call(elements.localeButtons, function (button) {
            button.addEventListener('click', function () {
                applyLocale(button.getAttribute('data-locale'), true);
            });
        });

        /* Add and edit follow the level that is open. */
        elements.addButton.addEventListener('click', openAddForCurrentEntry);
        elements.editButton.addEventListener('click', openEditForCurrentEntry);
        elements.editEntry.addEventListener('click', openEditForCurrentEntry);
        elements.deleteEntry.addEventListener('click', function () {
            if (currentEntry !== null) {
                openDeleteDialog('category', currentEntry);
            }
        });

        elements.emptyAction.addEventListener('click', function () {
            openCategoryDialog('create', null, null);
        });

        elements.entryEmptyAction.addEventListener('click', function () {
            if (entryEmptyHandler !== null) {
                entryEmptyHandler();
            }
        });

        wireDialog(elements.categoryDialog, elements.categoryCancel, elements.categoryForm, handleCategorySubmit);
        wireDialog(elements.cardDialog, elements.cardCancel, elements.cardForm, handleCardSubmit);
        wireDialog(elements.deleteDialog, elements.deleteCancel, elements.deleteForm, handleDeleteSubmit);

        elements.categoryDelete.addEventListener('click', function () {
            if (editingEntry !== null) {
                askDeleteAfterEdit(editingEntry);
            }
        });

        elements.categoryColorClear.addEventListener('click', clearCategoryColor);

        elements.categoryColor.addEventListener('input', function () {
            pendingColor = elements.categoryColor.value;
            elements.categoryColor.disabled = false;
        });

        elements.categoryIcon.addEventListener('change', handleIconChoice);
        elements.categoryIconRemove.addEventListener('click', removeIcon);

        /* Every field clears the error message as soon as it is used again. */
        elements.categoryName.addEventListener('input', function () {
            setDialogError(elements.categoryError, null);
        });

        elements.cardFront.addEventListener('input', function () {
            setDialogError(elements.cardError, null);
        });

        elements.cardBack.addEventListener('input', function () {
            setDialogError(elements.cardError, null);
        });

        elements.deleteConfirm.addEventListener('input', function () {
            setDialogError(elements.deleteError, null);
        });

        /* A click anywhere else, or Escape, closes an open row menu. */
        document.addEventListener('click', closeMenu);

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                closeMenu();
            }
        });

        window.addEventListener('resize', updateLanguageUnderline);
    }

    function init() {
        /* The boot script in the head already applied the theme. */
        theme = document.documentElement.getAttribute('data-theme') === 'dark' ? 'dark' : 'light';

        var savedLocale = readStorage(config.storageKeys.language);

        if (typeof translations[savedLocale] === 'object') {
            locale = savedLocale;
        }

        /* Running order for the two static blocks above the grid. */
        applyRevealOrder(document.querySelectorAll('.view--start .reveal'), 0);

        wireEvents();
        applyLocale(locale, false);
    }

    init();
})();
