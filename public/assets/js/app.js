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
     * There is no placeholder drawing any more. A category without a drawing of
     * its own shows the first letter of the name it is displayed under - in the
     * tile and in the preview of the upload field alike (see fillIconCircle).
     */
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
    var openMenu = null;
    var feedbackTimer = null;

    /* Which entry a new one is created in, and what the empty state offers. */
    var editingParentId = null;
    var entryEmptyHandler = null;

    /*
     * True while the home page is in edit mode: every tile then carries a menu
     * in its corner. The mode is not stored anywhere - leaving the page or
     * opening a detail view simply ends it.
     */
    var editMode = false;

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

        /* The one dialog. Its fields are built while it opens. */
        dialog: document.getElementById('app-dialog'),
        dialogForm: document.getElementById('app-dialog-form'),
        dialogTitle: document.getElementById('app-dialog-title'),
        dialogMessage: document.getElementById('app-dialog-message'),
        dialogFields: document.getElementById('app-dialog-fields'),
        dialogError: document.getElementById('app-dialog-error'),
        dialogDanger: document.getElementById('app-dialog-danger'),
        dialogCancel: document.getElementById('app-dialog-cancel'),
        dialogSubmit: document.getElementById('app-dialog-submit'),

        /* The hint line that belongs to the edit mode. */
        editHint: document.getElementById('edit-hint'),
        tilesView: document.getElementById('view-home'),

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
     * Whether a typed name confirms a category.
     *
     * A category can carry three names - the neutral one plus the English and
     * the German wording - and the page shows the one that belongs to the
     * language that is switched on. The server accepts all three, so this does
     * the same: without it the button could say "the name matches" while the
     * server refused to delete.
     */
    function nameMatchesTarget(target, typed) {
        var wanted = String(typed === undefined || typed === null ? '' : typed).trim().toLowerCase();

        if (wanted === '') {
            return false;
        }

        return ['name', 'name_en', 'name_de'].some(function (key) {
            return typeof target[key] === 'string' && target[key].trim().toLowerCase() === wanted;
        });
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

        /*
         * null means "this category has no drawing of its own". The circle then
         * shows the first letter of the name instead of staying empty.
         */
        return {
            title: displayName(row),
            icon: typeof row.icon_url === 'string' && row.icon_url !== '' ? row.icon_url : null,
            iconScale: scale
        };
    }

    /*
     * There is deliberately no colour helper any more.
     *
     * The `color` column is still in the database, but nothing in this
     * application reads, writes or shows it: a category is neutral by design,
     * and the tiles of the light theme take their colour from their position in
     * the row (see the stylesheet). The dot, the row track and every marker
     * therefore stay neutral through --cat-default.
     */

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

        if (code === 'confirm_name_required') {
            return t('dialog.errorConfirmRequired');
        }

        if (code === 'category_delete_conflict') {
            return t('dialog.errorDeleteConflict');
        }

        if (code === 'category_delete_failed' || code === 'category_update_failed') {
            return t('dialog.errorDelete');
        }

        if (code === 'category_not_found') {
            return t('dialog.errorAlreadyGone');
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

    /*
     * The first character of a displayed name, for the circle of a category that
     * has no drawing of its own. It is upper case, so "mathematics" and
     * "Mathematics" both show an "M", and it follows the language switch because
     * the caller passes the name it already displays.
     */
    function initialLetter(name) {
        var text = String(name === undefined || name === null ? '' : name).trim();

        if (text === '') {
            return '?';
        }

        return text.charAt(0).toLocaleUpperCase();
    }

    /*
     * Fills the icon circle of a tile or of a preview: the drawing when there is
     * one, otherwise the first letter of the name. An empty circle is never
     * shown, because a circle without any content reads like a loading state.
     */
    function fillIconCircle(circle, meta) {
        circle.textContent = '';

        if (meta.icon === null) {
            var initial = document.createElement('span');
            initial.className = 'blob__initial';
            initial.textContent = initialLetter(meta.title);
            circle.appendChild(initial);
            return;
        }

        var icon = document.createElement('img');
        icon.className = 'blob__icon';
        icon.src = meta.icon;
        /* The whole tile is one link with an accessible name, so describing the
           drawing again would only repeat it. */
        icon.alt = '';
        /* No loading="lazy": the drawing comes from the database through
           api/category_icon.php, and a lazy image stayed empty in testing even
           while the tile was on screen. */
        icon.setAttribute('decoding', 'async');
        icon.style.setProperty('--icon-scale', String(meta.iconScale));
        circle.appendChild(icon);
    }

    function buildAreaTile(area, index) {
        var meta = categoryMeta(area);

        /*
         * One tile needs two elements, because the menu in the corner is a real
         * <button> and a button inside a link is neither valid markup nor
         * clickable in a dependable way. The slot carries the width of a tile and
         * the position that gives the tile its colour, the link inside it stays
         * exactly what it was.
         */
        var slot = document.createElement('div');
        slot.className = 'area-card-slot';
        slot.style.setProperty('--reveal-index', String(2 + index));

        var link = document.createElement('a');
        link.className = 'area-card';
        link.href = 'index.php?category=' + encodeURIComponent(area.id);
        link.setAttribute('data-area-id', String(area.id));
        link.setAttribute('aria-label', t('area.open', { name: meta.title }));

        /* Blob on the left, the menu on the right. */
        var head = document.createElement('span');
        head.className = 'area-card__head';

        var blob = document.createElement('span');
        blob.className = 'blob';
        fillIconCircle(blob, meta);

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

        slot.appendChild(link);

        /*
         * The menu of this tile. It is always in the markup and only becomes
         * visible while the edit mode is on (see the stylesheet), so switching
         * the mode never has to rebuild the row.
         */
        var menu = buildMenu([
            {
                label: t('action.edit'),
                run: function () {
                    openCategoryForm('edit', area, area.parent_id);
                }
            },
            {
                label: t('action.delete'),
                danger: true,
                run: function () {
                    openDeleteDialog('category', area);
                }
            }
        ], meta.title, 'tile-menu');

        menu.classList.add('tile-menu');
        slot.appendChild(menu);

        return slot;
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
    function buildMenu(actions, label, wrapperClass) {
        var wrap = document.createElement('span');
        wrap.className = wrapperClass ? wrapperClass : 'row__menu';

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
    function buildEntryRow(entry, index) {
        var item = document.createElement('li');
        item.className = 'row reveal';
        item.style.setProperty('--reveal-index', String(index));

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
                    openCategoryForm('edit', entry, entry.parent_id);
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
                    openCardForm(card, card.category_id);
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
            editMode = false;
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


            elements.detailActions.hidden = false;
            elements.detailStats.hidden = false;

            if (isSubcategory) {
                setStatLabel('cards.heading');
                animateCount(elements.detailCount, currentEntryCards.length);

                elements.entryList.textContent = '';

                if (currentEntryCards.length === 0) {
                    showEntryEmpty('cards.empty.title', 'cards.empty.hint', 'cards.addCard', function () {
                        openCardForm(null, current.id);
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
                    openCategoryForm('create', null, current.id);
                });
            } else {
                children.forEach(function (child, index) {
                    elements.entryList.appendChild(buildEntryRow(child, index));
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

        /*
         * The button switches the edit mode of the tile row, so it belongs to
         * the start page. On a detail view the entry is edited with the two
         * actions next to the heading instead, which is why the button is hidden
         * there.
         */
        elements.editButton.hidden = level !== 'home';
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
            openCategoryForm('create', null, null);
            return;
        }

        if (currentEntry.parent_id === null) {
            openCategoryForm('create', null, currentEntry.id);
            return;
        }

        openCardForm(null, currentEntry.id);
    }

    function openEditForCurrentEntry() {
        if (currentEntry === null) {
            return;
        }

        openCategoryForm('edit', currentEntry, currentEntry.parent_id);
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
       ONE dialog for every form and every confirmation
       ---------------------------------------------------------------------- */

    /*
     * Everything the open dialog needs to know about itself lives in these
     * variables. They are filled while it opens and read by the one submit
     * handler, so opening, validating, saving and closing happen in exactly one
     * place - whatever form is on screen.
     */
    var dialogKind = null;      // 'category' | 'card' | 'delete'
    var dialogEntry = null;     // the row that is being edited or deleted
    var dialogParentId = null;  // where a new entry belongs
    var dialogOpener = null;    // the element that opened it (the focus goes back)
    var dialogUsed = false;     // true as soon as a field was touched
    var dialogIcon = null;      // { svg, name, removed, storedUrl, preview }
    var dialogFields = {};      // name -> { control, error, wrap }

    /* A tiny element factory: shorter than createElement + className + text. */
    function el(tag, className, text) {
        var node = document.createElement(tag);

        if (className) {
            node.className = className;
        }

        if (typeof text === 'string') {
            node.textContent = text;
        }

        return node;
    }

    /* Opens the shared dialog and lets it animate in. */
    function openDialog() {
        if (typeof elements.dialog.showModal === 'function') {
            elements.dialog.showModal();
        } else {
            elements.dialog.setAttribute('open', '');
        }

        window.requestAnimationFrame(function () {
            elements.dialog.classList.add('is-open');
        });
    }

    /*
     * Closes the dialog and hands the focus back to whatever opened it.
     *
     * The wait is the closing animation (200ms, 0 with reduced motion); without
     * it the panel would disappear in one frame.
     */
    function closeDialog() {
        elements.dialog.classList.remove('is-open');

        window.setTimeout(function () {
            if (typeof elements.dialog.close === 'function' && elements.dialog.open) {
                elements.dialog.close();
            } else {
                elements.dialog.removeAttribute('open');
            }

            var target = dialogOpener;

            if (target === null || !document.contains(target) || typeof target.focus !== 'function') {
                target = elements.addButton;
            }

            if (target && typeof target.focus === 'function') {
                target.focus();
            }

            dialogKind = null;
            dialogEntry = null;
            dialogParentId = null;
            dialogIcon = null;
            dialogOpener = null;
            dialogUsed = false;
            dialogFields = {};
        }, prefersReducedMotion() ? 0 : 200);
    }

    function setDialogError(message) {
        elements.dialogError.textContent = message === null ? '' : message;
        elements.dialogError.hidden = message === null;
    }

    /*
     * A field level error. The message appears directly under the field that
     * caused it and the field itself is marked, so nobody has to guess which
     * input is meant.
     */
    function setFieldError(name, message) {
        var field = dialogFields[name];

        if (!field) {
            setDialogError(message);
            return;
        }

        field.error.textContent = message === null ? '' : message;
        field.error.hidden = message === null;
        field.wrap.classList.toggle('is-invalid', message !== null);
    }

    function clearFieldError(name) {
        setFieldError(name, null);

        if (!elements.dialogError.hidden) {
            setDialogError(null);
        }
    }

    function clearDialogErrors() {
        setDialogError(null);

        Object.keys(dialogFields).forEach(function (name) {
            setFieldError(name, null);
        });
    }

    /* Adds one labelled field to the open dialog and remembers it by name. */
    function addField(name, kind, options) {
        var settings = options || {};
        var control;
        var id = 'dialog-field-' + name;

        if (kind === 'textarea') {
            control = el('textarea', 'dialog__input dialog__input--area');
            control.rows = settings.rows || 2;
        } else if (kind === 'checkbox') {
            control = el('input', 'dialog__check');
            control.type = 'checkbox';
        } else {
            control = el('input', 'dialog__input');
            control.type = 'text';
        }

        control.id = id;
        control.name = name;

        if (settings.maxLength) {
            control.maxLength = settings.maxLength;
        }

        if (settings.placeholderKey) {
            control.setAttribute('placeholder', t(settings.placeholderKey));
            control.setAttribute('data-i18n-placeholder', settings.placeholderKey);
        }

        if (typeof settings.value === 'string') {
            control.value = settings.value;
        }

        if (settings.checked === true) {
            control.checked = true;
        }

        control.addEventListener('input', function () {
            dialogUsed = true;
            clearFieldError(name);

            if (typeof settings.onInput === 'function') {
                settings.onInput(control);
            }
        });

        control.addEventListener('change', function () {
            dialogUsed = true;
        });

        var wrap = el('div', 'dialog__field');
        var label = el('label', 'dialog__label', t(settings.labelKey));
        label.setAttribute('for', id);
        label.setAttribute('data-i18n', settings.labelKey);

        var error = el('p', 'dialog__field-error');
        error.hidden = true;
        error.setAttribute('role', 'alert');

        if (kind === 'checkbox') {
            /* The design puts the box before its label. */
            wrap.classList.add('dialog__field--check');
            wrap.appendChild(control);
            wrap.appendChild(label);
        } else {
            wrap.appendChild(label);
            wrap.appendChild(control);
        }

        /* The message of this field comes first: it belongs to the input right
           above it, and the quiet hint follows below. */
        wrap.appendChild(error);

        if (settings.hintKey) {
            var hint = el('p', 'dialog__hint', t(settings.hintKey));
            hint.setAttribute('data-i18n', settings.hintKey);
            wrap.appendChild(hint);
        }

        dialogFields[name] = { control: control, error: error, wrap: wrap };

        /* The translations are appended into their own group instead, so the
           fields really sit inside the collapsed part. */
        var container = settings.container || elements.dialogFields;

        container.appendChild(wrap);

        return control;
    }

    /* The collapsible "Translations (optional)" group. */
    function addTranslationGroup(entry) {
        var details = el('details', 'dialog__group');
        var summary = el('summary', 'dialog__summary', t('dialog.translations'));
        summary.setAttribute('data-i18n', 'dialog.translations');

        details.appendChild(summary);

        var hint = el('p', 'dialog__hint', t('dialog.translationsHint'));
        hint.setAttribute('data-i18n', 'dialog.translationsHint');
        details.appendChild(hint);

        var grid = el('div', 'dialog__grid');

        [
            { name: 'name_en', labelKey: 'dialog.category.nameEn', maxLength: config.limits.name },
            { name: 'name_de', labelKey: 'dialog.category.nameDe', maxLength: config.limits.name },
            { name: 'description_en', labelKey: 'dialog.category.descriptionEn', maxLength: config.limits.description, textarea: true },
            { name: 'description_de', labelKey: 'dialog.category.descriptionDe', maxLength: config.limits.description, textarea: true }
        ].forEach(function (def) {
            var control = addField(def.name, def.textarea ? 'textarea' : 'text', {
                labelKey: def.labelKey,
                maxLength: def.maxLength,
                value: entry === null || typeof entry[def.name] !== 'string' ? '' : entry[def.name],
                container: grid
            });

            /* The main description field and the description of the language
               that is switched on are the same column, so they stay in sync. */
            var mainName = def.name === 'description_' + locale ? 'description' : null;

            if (mainName !== null && dialogFields[mainName]) {
                var main = dialogFields[mainName].control;
                main.addEventListener('input', function () {
                    control.value = main.value;
                });
                control.addEventListener('input', function () {
                    main.value = control.value;
                });
            }
        });

        details.appendChild(grid);
        elements.dialogFields.appendChild(details);

        return details;
    }

    /* ----------------------------------------------------------------------
       The icon field: click, drag and drop, preview, remove
       ---------------------------------------------------------------------- */

    /*
     * Rewrites an SVG so that every drawing fills the same circle.
     *
     * The browser measures the real bounding box of the drawing (getBBox), and
     * the viewBox is replaced by a SQUARE around that box with a small margin.
     * Different viewBox sizes, drawing dimensions, aspect ratios and empty
     * whitespace around the drawing therefore end up looking identical inside
     * the circle - without a single number having to be typed in by hand.
     *
     * Nothing is inserted with innerHTML: the text is parsed by DOMParser, which
     * produces an inert document, and only attributes are changed.
     */
    function normaliseIconSvg(text) {
        return new Promise(function (resolve) {
            var parsed = new window.DOMParser().parseFromString(text, 'image/svg+xml');
            var root = parsed && parsed.documentElement;

            if (!root || root.nodeName.toLowerCase() !== 'svg' || parsed.querySelector('parsererror')) {
                resolve({ ok: false });
                return;
            }

            /* Belt and braces: the server sanitises again before it stores the
               drawing, but nothing risky is ever put into the page here either. */
            Array.prototype.forEach.call(parsed.querySelectorAll('script, foreignObject, iframe, image, use'), function (node) {
                node.remove();
            });

            Array.prototype.forEach.call(parsed.querySelectorAll('*'), function (node) {
                Array.prototype.slice.call(node.attributes).forEach(function (attribute) {
                    var name = attribute.name.toLowerCase();

                    if (name.indexOf('on') === 0 || name.indexOf('href') >= 0) {
                        node.removeAttribute(attribute.name);
                    }
                });
            });

            var viewBox = (root.getAttribute('viewBox') || '').trim().split(/[\s,]+/).map(Number);

            if (viewBox.length !== 4 || viewBox.some(function (value) { return !isFinite(value); })) {
                var width = parseFloat(root.getAttribute('width')) || 0;
                var height = parseFloat(root.getAttribute('height')) || 0;

                if (width <= 0 || height <= 0) {
                    /* Without a viewBox and without a size there is nothing to
                       measure, so the drawing is refused instead of being stored
                       in a shape nobody can predict. */
                    resolve({ ok: false });
                    return;
                }

                viewBox = [0, 0, width, height];
            }

            root.setAttribute('viewBox', viewBox.join(' '));
            root.removeAttribute('width');
            root.removeAttribute('height');

            /* Measured in a hidden box that is in the document, because getBBox
               only answers for a drawing that is really laid out. */
            var box = el('div', 'icon-measure');
            var clone = root.cloneNode(true);
            box.appendChild(clone);
            document.body.appendChild(box);

            var rectangle = null;

            try {
                rectangle = clone.getBBox();
            } catch (error) {
                rectangle = null;
            }

            box.remove();

            var useBox = viewBox;
            var padding = 0.04;

            if (rectangle && rectangle.width > 0 && rectangle.height > 0) {
                var size = Math.max(rectangle.width, rectangle.height) * (1 + padding * 2);
                useBox = [
                    rectangle.x + rectangle.width / 2 - size / 2,
                    rectangle.y + rectangle.height / 2 - size / 2,
                    size,
                    size
                ];
            } else {
                /* No usable measurement: at least make the box square, which is
                   what keeps the aspect ratio from being distorted. */
                var side = Math.max(viewBox[2], viewBox[3]);
                useBox = [
                    viewBox[0] + viewBox[2] / 2 - side / 2,
                    viewBox[1] + viewBox[3] / 2 - side / 2,
                    side,
                    side
                ];
            }

            var round = function (value) { return Math.round(value * 1000) / 1000; };

            root.setAttribute('viewBox', useBox.map(round).join(' '));

            var serialised = new window.XMLSerializer().serializeToString(root);

            resolve({ ok: true, svg: serialised });
        });
    }

    /* The preview inside the icon circle, exactly like a tile shows it. */
    function renderIconPreview() {
        var circle = dialogIcon.preview;
        circle.textContent = '';

        var source = null;

        if (!dialogIcon.removed) {
            if (dialogIcon.svg !== null) {
                source = 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(dialogIcon.svg);
            } else if (dialogIcon.storedUrl !== null) {
                source = dialogIcon.storedUrl;
            }
        }

        if (source === null) {
            var initial = el('span', 'blob__initial');
            initial.textContent = initialLetter(dialogFields.name ? dialogFields.name.control.value : '');
            circle.appendChild(initial);
            return;
        }

        var icon = el('img', 'blob__icon');
        icon.src = source;
        icon.alt = '';
        circle.appendChild(icon);
    }

    function buildIconField(entry) {
        var wrap = el('div', 'dialog__field');
        var label = el('label', 'dialog__label', t('dialog.category.iconLabel'));
        label.setAttribute('data-i18n', 'dialog.category.iconLabel');
        wrap.appendChild(label);

        var zone = el('div', 'upload');
        zone.tabIndex = 0;
        zone.setAttribute('role', 'button');

        var circle = el('span', 'blob');
        dialogIcon.preview = circle;

        var textBlock = el('span', 'upload__text');
        var title = el('span', 'upload__title', t('dialog.icon.uploadTitle'));
        title.setAttribute('data-i18n', 'dialog.icon.uploadTitle');
        var hint = el('span', 'upload__hint', t('dialog.icon.uploadHint'));
        hint.setAttribute('data-i18n', 'dialog.icon.uploadHint');
        textBlock.appendChild(title);
        textBlock.appendChild(hint);

        zone.appendChild(circle);
        zone.appendChild(textBlock);
        wrap.appendChild(zone);

        var actions = el('div', 'upload__actions');
        var removeButton = el('button', 'dialog__button--text', t('dialog.icon.remove'));
        removeButton.type = 'button';
        removeButton.setAttribute('data-i18n', 'dialog.icon.remove');
        removeButton.hidden = true;
        actions.appendChild(removeButton);
        wrap.appendChild(actions);

        var note = el('p', 'dialog__hint');
        note.hidden = true;
        wrap.appendChild(note);

        var error = el('p', 'dialog__field-error');
        error.hidden = true;
        error.setAttribute('role', 'alert');
        wrap.appendChild(error);

        var file = document.createElement('input');
        file.type = 'file';
        file.accept = 'image/svg+xml,.svg';
        file.className = 'upload__file';
        file.hidden = true;
        wrap.appendChild(file);

        function showNote(message) {
            note.textContent = message === null ? '' : message;
            note.hidden = message === null;
        }

        function showError(message) {
            error.textContent = message === null ? '' : message;
            error.hidden = message === null;
        }

        /* The hint says what happens next: while a file hovers over the area it
           invites a drop, at rest it explains the click. */
        function setHint(text) {
            hint.textContent = text;
        }

        function resetHint() {
            setHint(t('dialog.icon.uploadHint'));
        }

        function updateRemoveButton() {
            var hasSomething = (!dialogIcon.removed && dialogIcon.svg !== null)
                || (!dialogIcon.removed && dialogIcon.storedUrl !== null);

            removeButton.hidden = !hasSomething;
            removeButton.textContent = t('dialog.icon.remove');
        }

        function acceptFile(selected) {
            showError(null);

            if (!selected) {
                return;
            }

            if (!/\.svg$/i.test(selected.name) && selected.type !== 'image/svg+xml') {
                showError(t('dialog.icon.onlySvg'));
                return;
            }

            if (selected.size > config.limits.iconBytes) {
                showError(t('dialog.icon.tooLarge', { max: Math.round(config.limits.iconBytes / 1024) }));
                return;
            }

            var reader = new window.FileReader();

            reader.addEventListener('load', function () {
                normaliseIconSvg(String(reader.result)).then(function (result) {
                    if (!result.ok) {
                        showError(t('dialog.icon.notSvg'));
                        return;
                    }

                    dialogIcon.svg = result.svg;
                    dialogIcon.name = selected.name;
                    dialogIcon.removed = false;
                    dialogUsed = true;
                    renderIconPreview();
                    updateRemoveButton();
                    showNote(t('dialog.icon.normalised'));
                });
            });

            reader.addEventListener('error', function () {
                showError(t('dialog.icon.notSvg'));
            });

            reader.readAsText(selected);
        }

        zone.addEventListener('click', function () {
            file.click();
        });

        zone.addEventListener('keydown', function (event) {
            if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                file.click();
            }
        });

        file.addEventListener('change', function () {
            acceptFile(file.files && file.files.length > 0 ? file.files[0] : null);
            /* Lets the same file be chosen again after it was refused. */
            file.value = '';
        });

        ['dragenter', 'dragover'].forEach(function (type) {
            zone.addEventListener(type, function (event) {
                event.preventDefault();
                zone.classList.add('is-dragover');
                textBlock.querySelector('.upload__hint').textContent = t('dialog.icon.drop');
            });
        });

        ['dragleave', 'dragend'].forEach(function (type) {
            zone.addEventListener(type, function () {
                zone.classList.remove('is-dragover');
                resetHint();
            });
        });

        zone.addEventListener('drop', function (event) {
            event.preventDefault();
            zone.classList.remove('is-dragover');
            resetHint();
            acceptFile(event.dataTransfer && event.dataTransfer.files && event.dataTransfer.files.length > 0
                ? event.dataTransfer.files[0]
                : null);
        });

        removeButton.addEventListener('click', function () {
            dialogIcon.svg = null;
            dialogIcon.name = '';
            dialogIcon.removed = true;
            dialogUsed = true;
            showError(null);
            showNote(t('dialog.icon.fallbackHint'));
            renderIconPreview();
            updateRemoveButton();
        });

        wrap.iconNote = showNote;
        wrap.iconError = showError;

        renderIconPreview();
        updateRemoveButton();
        showNote(dialogIcon.storedUrl === null && dialogIcon.svg === null ? t('dialog.icon.fallbackHint') : null);

        elements.dialogFields.appendChild(wrap);

        return wrap;
    }

    /* ----------------------------------------------------------------------
       The three forms that use the shared dialog
       ---------------------------------------------------------------------- */

    /*
     * Which description column the single "Description" field writes to.
     *
     * The table has one description per language and no neutral one, so the
     * field in the main part of the form always writes to the description of the
     * language the interface is in. The same column is shown again, in sync,
     * inside the collapsed translations group.
     */
    function descriptionColumn() {
        return locale === 'de' ? 'description_de' : 'description_en';
    }

    /* mode "create"/"edit", entry the row, parentId where a new row belongs. */
    function openCategoryForm(mode, entry, parentId) {
        var isEdit = mode === 'edit' && entry !== null;
        var isSubcategory = isEdit ? entry.parent_id !== null : parentId !== null;
        var title;

        if (isEdit) {
            title = t(isSubcategory ? 'dialog.category.editSubcategory' : 'dialog.category.editArea');
        } else {
            title = t(isSubcategory ? 'dialog.category.createSubcategory' : 'dialog.category.createArea');
        }

        dialogKind = 'category';
        dialogEntry = isEdit ? entry : null;
        dialogParentId = isEdit ? entry.parent_id : parentId;
        dialogIcon = {
            svg: null,
            name: '',
            removed: false,
            storedUrl: isEdit && typeof entry.icon_url === 'string' ? entry.icon_url : null,
            preview: null
        };
        dialogUsed = false;
        dialogOpener = document.activeElement;
        dialogFields = {};

        elements.dialogTitle.textContent = title;
        elements.dialogMessage.hidden = true;
        elements.dialogFields.textContent = '';
        clearDialogErrors();
        elements.dialogDanger.textContent = t('action.delete');
        elements.dialogDanger.hidden = !isEdit;
        elements.dialogCancel.textContent = t('dialog.cancel');
        elements.dialogSubmit.textContent = t('dialog.save');
        elements.dialogSubmit.disabled = false;
        elements.dialogSubmit.dataset.busy = t('dialog.saving');

        var descriptionValue = isEdit && typeof entry[descriptionColumn()] === 'string'
            ? entry[descriptionColumn()]
            : '';

        addField('name', 'text', {
            labelKey: 'dialog.nameLabel',
            placeholderKey: 'dialog.namePlaceholder',
            maxLength: config.limits.name,
            value: isEdit ? entry.name : '',
            /* Without a drawing the circle shows the first letter of the name,
               so it has to follow what is being typed. */
            onInput: function () {
                renderIconPreview();
            }
        });

        var description = addField('description', 'textarea', {
            labelKey: 'dialog.descriptionLabel',
            placeholderKey: 'dialog.descriptionPlaceholder',
            hintKey: 'dialog.descriptionHint',
            maxLength: config.limits.description,
            rows: 3,
            value: descriptionValue,
            onInput: function (control) {
                renderIconPreview();
            }
        });

        addTranslationGroup(isEdit ? entry : null);
        buildIconField(isEdit ? entry : null);

        openDialog();

        /*
         * The first field takes the focus (see the spec of the dialog system):
         * the name, which is the one field nobody can skip.
         */
        dialogFields.name.control.focus();

        return description;
    }

    /* The icon payload of the open category form, or null when nothing changed. */
    function iconPayload() {
        if (dialogIcon.removed) {
            return null;
        }

        if (dialogIcon.svg !== null) {
            return dialogIcon.svg;
        }

        return undefined;
    }

    function validateCategoryForm() {
        var name = dialogFields.name.control.value.trim();
        var description = dialogFields.description.control.value.trim();
        var firstBad = null;

        if (name === '') {
            setFieldError('name', t('dialog.errorNameRequired'));
            firstBad = firstBad || dialogFields.name.control;
        } else if (name.length > config.limits.name) {
            setFieldError('name', t('dialog.errorNameTooLong', { max: config.limits.name }));
            firstBad = firstBad || dialogFields.name.control;
        }

        if (description.length > config.limits.description) {
            setFieldError('description', t('dialog.errorDescriptionTooLong', { max: config.limits.description }));
            firstBad = firstBad || dialogFields.description.control;
        }

        ['name_en', 'name_de', 'description_en', 'description_de'].forEach(function (field) {
            var limit = field.indexOf('description') === 0 ? config.limits.description : config.limits.name;
            var value = dialogFields[field].control.value.trim();

            if (value.length > limit) {
                setFieldError(field, t('dialog.errorDescriptionTooLong', { max: limit }));
                firstBad = firstBad || dialogFields[field].control;
            }
        });

        if (firstBad !== null) {
            firstBad.focus();
            return null;
        }

        var payload = {
            name: name,
            description_en: dialogFields.description_en.control.value.trim(),
            description_de: dialogFields.description_de.control.value.trim(),
            name_en: dialogFields.name_en.control.value.trim(),
            name_de: dialogFields.name_de.control.value.trim()
        };

        var icon = iconPayload();

        if (icon !== undefined) {
            payload.icon_svg = icon;
        }

        return payload;
    }

    function openCardForm(card, categoryId) {
        dialogKind = 'card';
        dialogEntry = card;
        dialogParentId = categoryId;
        dialogIcon = null;
        dialogUsed = false;
        dialogOpener = document.activeElement;
        dialogFields = {};

        elements.dialogTitle.textContent = t(card === null ? 'dialog.card.create' : 'dialog.card.edit');
        elements.dialogMessage.hidden = true;
        elements.dialogFields.textContent = '';
        clearDialogErrors();
        elements.dialogDanger.hidden = true;
        elements.dialogCancel.textContent = t('dialog.cancel');
        elements.dialogSubmit.textContent = t('dialog.save');
        elements.dialogSubmit.disabled = false;

        addField('front', 'textarea', {
            labelKey: 'dialog.card.frontLabel',
            placeholderKey: 'dialog.card.frontPlaceholder',
            maxLength: config.limits.cardText,
            rows: 2,
            value: card === null ? '' : card.front
        });

        addField('back', 'textarea', {
            labelKey: 'dialog.card.backLabel',
            placeholderKey: 'dialog.card.backPlaceholder',
            maxLength: config.limits.cardText,
            rows: 2,
            value: card === null ? '' : card.back
        });

        addField('is_bidirectional', 'checkbox', {
            labelKey: 'dialog.card.bidirectionalLabel',
            checked: card !== null && card.is_bidirectional === true
        });

        openDialog();
        dialogFields.front.control.focus();
    }

    function validateCardForm() {
        var front = dialogFields.front.control.value.trim();
        var back = dialogFields.back.control.value.trim();
        var firstBad = null;

        if (front === '') {
            setFieldError('front', t('dialog.errorFrontRequired'));
            firstBad = dialogFields.front.control;
        }

        if (back === '') {
            setFieldError('back', t('dialog.errorBackRequired'));
            firstBad = firstBad || dialogFields.back.control;
        }

        if (firstBad !== null) {
            firstBad.focus();
            return null;
        }

        return {
            front: front,
            back: back,
            is_bidirectional: dialogFields.is_bidirectional.control.checked
        };
    }

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
        dialogKind = 'delete';
        dialogEntry = { kind: kind, target: target };
        dialogParentId = null;
        dialogIcon = null;
        dialogUsed = false;
        dialogOpener = document.activeElement;
        dialogFields = {};

        elements.dialogFields.textContent = '';
        clearDialogErrors();
        elements.dialogDanger.hidden = true;
        elements.dialogCancel.textContent = t('dialog.cancel');
        elements.dialogSubmit.disabled = false;

        if (kind === 'category') {
            /*
             * Two sources, one shape.
             *
             * A category that was read on its own (api/categories.php?id=N)
             * carries a delete_preview with the whole subtree. A tile comes from
             * the list, which counts the direct subcategories and the cards of
             * the branch - enough to decide whether anything depends on it, and
             * the server counts again inside its own transaction before it
             * deletes anything.
             */
            var preview = target.delete_preview || {
                categories: typeof target.subcategory_count === 'number' ? target.subcategory_count : 0,
                cards: typeof target.card_count === 'number' ? target.card_count : 0
            };
            var parts = deletePreviewParts(preview);
            var dependent = preview.categories > 0 || preview.cards > 0;

            elements.dialogTitle.textContent = t('dialog.delete.title', { name: displayName(target) });
            elements.dialogMessage.textContent = parts === ''
                ? t('dialog.delete.nothingBelow')
                : t('dialog.delete.consequence', { parts: parts });
            elements.dialogMessage.hidden = false;
            elements.dialogSubmit.textContent = t('dialog.delete.submit');

            /*
             * Only a category that really has something below it asks for the
             * name to be typed again - an empty one is deleted with a single
             * click, because there is nothing to be careful about.
             */
            if (dependent) {
                var confirm = addField('confirm_name', 'text', {
                    labelKey: 'dialog.delete.confirmLabel',
                    maxLength: config.limits.name,
                    onInput: function (control) {
                        elements.dialogSubmit.disabled = !nameMatchesTarget(target, control.value);
                    }
                });

                elements.dialogSubmit.disabled = true;
                openDialog();
                confirm.focus();
                return;
            }

            openDialog();
            elements.dialogCancel.focus();
            return;
        }

        elements.dialogTitle.textContent = t('dialog.deleteCard.title');
        elements.dialogMessage.textContent = t('dialog.deleteCard.hint');
        elements.dialogMessage.hidden = false;
        elements.dialogSubmit.textContent = t('dialog.delete.submit');

        openDialog();
        elements.dialogCancel.focus();
    }

    /* "Delete" inside the edit form: close it, then ask for the name. */
    function askDeleteAfterEdit(entry) {
        closeDialog();

        window.setTimeout(function () {
            openDeleteDialog('category', entry);
        }, prefersReducedMotion() ? 0 : 220);
    }

    /* ----------------------------------------------------------------------
       Saving and deleting through the one submit handler
       ---------------------------------------------------------------------- */

    function setBusy(busy) {
        elements.dialogSubmit.disabled = busy;
        elements.dialogCancel.disabled = busy;
        elements.dialogSubmit.textContent = busy
            ? t('dialog.saving')
            : (elements.dialogSubmit.dataset.idleLabel || t('dialog.save'));
    }

    /*
     * The one place that talks to the API for a dialog.
     *
     * Some errors belong to a field, so the answer is mapped to the field that
     * caused it wherever that is possible; everything else lands in the error
     * line of the dialog. The button is disabled while the request runs, so a
     * double click cannot create the same row twice.
     */
    function fieldForErrorCode(code) {
        var map = {
            invalid_name: 'name',
            category_exists: 'name',
            invalid_name_en: 'name_en',
            invalid_name_de: 'name_de',
            invalid_description_en: 'description_en',
            invalid_description_de: 'description_de',
            invalid_icon: 'icon'
        };

        return map[code] || null;
    }

    function handleSubmitFailure(result) {
        /*
         * A request that never reached the server, or an answer nobody mapped,
         * still has to name the operation that failed.
         */
        if (dialogKind === 'delete' && (result.code === 'network_error' || result.code === 'request_failed')) {
            setDialogError(t('dialog.errorDelete'));
            return;
        }

        var field = fieldForErrorCode(result.code);

        if (field === null || field === 'icon') {
            if (field === 'icon' && dialogFields.icon) {
                setFieldError('icon', errorMessage(result.code));
                return;
            }

            setDialogError(errorMessage(result.code));
            return;
        }

        setFieldError(field, errorMessage(result.code));
        dialogFields[field].control.focus();
    }

    function submitDialog() {
        clearDialogErrors();

        var isDelete = dialogKind === 'delete';
        var isCard = dialogKind === 'card';
        var payload = null;
        var url = '';
        var method = 'POST';
        var successMessage = '';

        if (isDelete) {
            var target = dialogEntry.target;
            var isCategory = dialogEntry.kind === 'category';

            if (dialogFields.confirm_name) {
                var typed = dialogFields.confirm_name.control.value.trim();

                if (!nameMatchesTarget(target, typed)) {
                    setFieldError('confirm_name', t('dialog.errorConfirmName'));
                    dialogFields.confirm_name.control.focus();
                    return;
                }

                payload = { confirm_name: typed };
            } else {
                /*
                 * No body at all: the id in the URL already says what is meant,
                 * and the server only asks for a name when something depends on
                 * the category. Sending an empty body would make the request look
                 * like a confirmation with a missing field.
                 */
                payload = undefined;
            }

            url = (isCategory ? config.endpoints.category : config.endpoints.card)
                + '?id=' + encodeURIComponent(target.id);
            method = 'DELETE';
        } else if (isCard) {
            payload = validateCardForm();

            if (payload === null) {
                return;
            }

            var isCardEdit = dialogEntry !== null;

            if (!isCardEdit) {
                payload.category_id = dialogParentId;
            }

            url = isCardEdit
                ? config.endpoints.card + '?id=' + encodeURIComponent(dialogEntry.id)
                : config.endpoints.cards;
            method = isCardEdit ? 'PATCH' : 'POST';
        } else {
            payload = validateCategoryForm();

            if (payload === null) {
                return;
            }

            var isEdit = dialogEntry !== null;

            if (!isEdit) {
                payload.parent_id = dialogParentId;
            }

            url = isEdit
                ? config.endpoints.category + '?id=' + encodeURIComponent(dialogEntry.id)
                : config.endpoints.categories;
            method = isEdit ? 'PATCH' : 'POST';
        }

        var removedName = null;

        elements.dialogSubmit.dataset.idleLabel = elements.dialogSubmit.textContent;
        setBusy(true);

        apiRequest(url, method, payload).then(function (result) {
            setBusy(false);

            if (!result.ok) {
                /*
                 * A delete that answers 404 is not a failure of the request: the
                 * row really is gone (somebody else deleted it, or it was already
                 * removed). The list is reloaded so the page shows the truth
                 * instead of a tile that can never be deleted.
                 */
                if (isDelete && result.status === 404) {
                    closeDialog();
                    responseCache = {};
                    render();
                    showFeedback(t('dialog.errorAlreadyGone'));
                    return;
                }

                handleSubmitFailure(result);
                return;
            }

            /*
             * Everything the page shows comes from the API again, so a saved row
             * is really there and an edited one really shows its new text.
             */
            responseCache = {};

            if (isDelete) {
                var removedTarget = dialogEntry.target;
                var removedLabel = dialogEntry.kind === 'category'
                    ? displayName(removedTarget)
                    : removedTarget.front;
                var removedOpenEntry = dialogEntry.kind === 'category'
                    && currentEntry !== null
                    && currentEntry.id === removedTarget.id;

                closeDialog();
                showFeedback(t('feedback.deleted', { name: removedLabel }));

                if (removedOpenEntry) {
                    /* The page itself is gone, so the browser goes up one level. */
                    window.location.href = removedTarget.parent_id === null
                        ? 'index.php'
                        : 'index.php?category=' + encodeURIComponent(removedTarget.parent_id);
                    return;
                }

                render();
                return;
            }

            var createdName = dialogEntry === null && result.data && typeof result.data.name === 'string'
                ? (locale === 'de' && typeof result.data.name_de === 'string' && result.data.name_de !== ''
                    ? result.data.name_de
                    : result.data.name)
                : null;

            newAreaId = dialogEntry === null && !isCard && result.data && typeof result.data.id === 'number'
                ? result.data.id
                : null;

            closeDialog();

            if (createdName !== null && !isCard) {
                showFeedback(t('feedback.created', { name: createdName }));
            } else {
                showFeedback(t('feedback.updated', { name: isCard ? payload.front : displayName(dialogEntry) }));
            }

            render();
        });
    }

    /* ----------------------------------------------------------------------
       Edit mode on the home page
       ---------------------------------------------------------------------- */

    function setEditMode(next) {
        editMode = next;

        elements.tilesView.classList.toggle('is-editing', editMode);
        elements.editHint.hidden = !editMode;
        elements.editButton.setAttribute('aria-pressed', editMode ? 'true' : 'false');
        /*
         * The key travels with the button, so the language switch translates the
         * label that is really on screen - "Done" while the mode is on.
         */
        elements.editButton.setAttribute('data-i18n', editMode ? 'footer.done' : 'footer.edit');
        elements.editButton.textContent = t(editMode ? 'footer.done' : 'footer.edit');

        if (!editMode) {
            closeMenu();
        }
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

        /* Add follows the level that is open. */
        elements.addButton.addEventListener('click', openAddForCurrentEntry);

        /* The footer button on the right switches the edit mode of the tiles. */
        elements.editButton.setAttribute('aria-pressed', 'false');
        elements.editButton.addEventListener('click', function () {
            setEditMode(!editMode);
        });

        /* The detail view has its own two actions next to the heading. */
        elements.editEntry.addEventListener('click', openEditForCurrentEntry);
        elements.deleteEntry.addEventListener('click', function () {
            if (currentEntry !== null) {
                openDeleteDialog('category', currentEntry);
            }
        });

        elements.emptyAction.addEventListener('click', function () {
            openCategoryForm('create', null, null);
        });

        elements.entryEmptyAction.addEventListener('click', function () {
            if (entryEmptyHandler !== null) {
                entryEmptyHandler();
            }
        });

        /* One form, one submit handler, three ways out. */
        elements.dialogForm.addEventListener('submit', function (event) {
            event.preventDefault();
            submitDialog();
        });

        elements.dialogCancel.addEventListener('click', function () {
            closeDialog();
        });

        elements.dialogDanger.addEventListener('click', function () {
            if (dialogKind === 'category' && dialogEntry !== null) {
                askDeleteAfterEdit(dialogEntry);
            }
        });

        /*
         * A click that lands on the dialog element itself - not on the form
         * inside it - is a click on the backdrop. It closes the dialog, but only
         * while nothing has been typed: with input in the form the click does
         * nothing, so no work is ever lost by a stray click.
         */
        elements.dialog.addEventListener('click', function (event) {
            if (event.target !== elements.dialog || dialogUsed) {
                return;
            }

            closeDialog();
        });

        /*
         * Escape takes the same path as the cancel button, so the focus always
         * goes back to the element that opened the dialog.
         *
         * It is handled twice on purpose: "cancel" is the native event of a
         * modal dialog, and the key handler covers every situation in which that
         * event does not arrive (an embedded browser, a dialog that is not modal).
         */
        elements.dialog.addEventListener('cancel', function (event) {
            event.preventDefault();
            closeDialog();
        });

        elements.dialog.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                event.preventDefault();
                closeDialog();
            }
        });

        elements.dialog.addEventListener('close', function () {
            elements.dialog.classList.remove('is-open');
        });

        /* A click anywhere else, or Escape, closes an open menu. */
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
        /* No translation table is on the page before this line, so the label of
           the edit button is set here for the first time. */
        elements.editButton.textContent = t('footer.edit');

        applyLocale(locale, false);
    }

    init();
})();
