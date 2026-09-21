/*
 * Lernkartei - learning area browser
 *
 * The start page asks one existing endpoint for real data and renders it:
 *   api/categories.php -> the learning areas with colour and counts
 *
 * Nothing is invented here: every number on the page is a value the API really
 * returned. The start page asks for exactly what it shows and nothing else; the
 * old statistics endpoint that no page called any more is gone.
 */
(function () {
    'use strict';

    /* A thin stroke arrow, drawn inline so no extra icon file is needed.
       "currentColor" makes it follow the theme. */
    /*
     * The play triangle of the two "Study" entries. It is drawn inline with
     * currentColor, so it follows the theme without a second icon file.
     */
    var PLAY_SVG = '<svg viewBox="0 0 24 24" width="16" height="16" fill="currentColor" focusable="false" aria-hidden="true"><path d="M8 5.2v13.6L18.4 12 8 5.2z"/></svg>';

    /* The arrow that points into the drop zone of the import dialog. Like every
       other icon of this file it is written with innerHTML, because the string is
       a constant of this script - a name or a text from the database never is. */
    var UPLOAD_SVG = '<svg viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round" focusable="false" aria-hidden="true"><path d="M12 16V4"/><path d="M7.5 8.5 12 4l4.5 4.5"/><path d="M4.5 15.5v2A2.5 2.5 0 0 0 7 20h10a2.5 2.5 0 0 0 2.5-2.5v-2"/></svg>';

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

    /*
     * What the card list of the open subcategory knows: the cards themselves, the
     * counts the API made and the text in the search field. The list is filtered
     * in the browser and never reloaded for a search - the filter only looks at
     * what is already there.
     */
    var openCards = [];
    var openCardSummary = null;
    var cardSearchQuery = '';
    var cardSearchMin = 15;

    /* Remembers a "save and next" so the dialog can open again afterwards. */
    var cardSaveAndNext = false;
    var openMenu = null;
    var feedbackTimer = null;

    /*
     * How long a deletion can still be taken back.
     *
     * Nothing is sent to the server during this window, so "Undo" really does
     * undo: the row never left the database.
     */
    var UNDO_WINDOW_MS = 6500;

    /*
     * The deletion that is waiting right now, or null:
     *   { kind, target, url, timer }
     * Only one can be waiting at a time - a second one finishes the first.
     */
    var pendingDelete = null;

    /*
     * The open import dialog: the file that was chosen and everything the server
     * answered about it. It is null while no import dialog is open.
     */
    var importState = null;
    var importPanel = null;

    /* Which entry a new one is created in, and what the empty state offers. */
    var editingParentId = null;
    var entryEmptyHandler = null;


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
        themeToggle: document.getElementById('theme-toggle'),
        langUnderline: document.getElementById('lang-underline'),
        localeButtons: document.querySelectorAll('[data-locale]'),
        emptyAction: document.getElementById('empty-action'),

        entryList: document.getElementById('entry-list'),
        entryEmpty: document.getElementById('entry-empty'),
        entryEmptyBlob: document.getElementById('entry-empty-blob'),
        entryEmptyTitle: document.getElementById('entry-empty-title'),
        entryEmptyHint: document.getElementById('entry-empty-hint'),
        entryEmptyAction: document.getElementById('entry-empty-action'),
        areaCards: document.getElementById('area-cards'),
        areaCardList: document.getElementById('area-card-list'),

        detailActions: document.getElementById('detail-actions'),
        detailBlob: document.getElementById('detail-blob'),
        detailStats: document.getElementById('detail-stats'),
        detailFigureCount: document.getElementById('detail-figure-count'),
        detailFigureCards: document.getElementById('detail-figure-cards'),
        detailCardCount: document.getElementById('detail-card-count'),
        detailCardLabel: document.getElementById('detail-card-label'),
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

        /* The short message, its text and the button that can belong to it. */
        feedback: document.getElementById('feedback'),
        feedbackText: document.getElementById('feedback-text'),
        feedbackAction: document.getElementById('feedback-action'),

        /* The header of a card list, above the rows of a subcategory. */
        cardTools: document.getElementById('card-tools'),
        cardToolsCount: document.getElementById('card-tools-count'),
        cardToolsDue: document.getElementById('card-tools-due'),
        cardToolsBar: document.getElementById('card-tools-bar'),
        cardToolsLegend: document.getElementById('card-tools-legend'),
        cardToolsPartNew: document.getElementById('card-tools-part-new'),
        cardToolsPartUnsure: document.getElementById('card-tools-part-unsure'),
        cardToolsPartKnown: document.getElementById('card-tools-part-known'),
        cardSearchWrap: document.getElementById('card-tools-search'),
        cardSearch: document.getElementById('card-search'),
        cardSearchEmpty: document.getElementById('card-search-empty'),
        learnButton: document.getElementById('learn-button'),
        importButton: document.getElementById('import-button'),
        learnLabel: document.getElementById('learn-button-label'),
        addEntryButton: document.getElementById('add-entry-button'),

        /* The study session. */
        learn: document.getElementById('learn'),
        learnStage: document.getElementById('learn-stage'),
        learnCard: document.getElementById('learn-card'),
        learnCounter: document.getElementById('learn-counter'),
        learnProgress: document.getElementById('learn-progress'),
        learnProgressFill: document.getElementById('learn-progress-fill'),
        learnSideLabel: document.getElementById('learn-side-label'),
        learnFrontText: document.getElementById('learn-front-text'),
        learnBackText: document.getElementById('learn-back-text'),
        learnHint: document.getElementById('learn-hint'),
        learnRating: document.getElementById('learn-rating'),
        learnButtons: document.getElementById('learn-buttons'),
        learnSummary: document.getElementById('learn-summary'),
        learnSummaryNumber: document.getElementById('learn-summary-number'),
        learnSummaryBars: document.getElementById('learn-summary-bars'),
        learnSummaryLeft: document.getElementById('learn-summary-left'),
        learnRepeat: document.getElementById('learn-repeat'),
        learnFinish: document.getElementById('learn-finish'),
        learnAsk: document.getElementById('learn-ask'),
        learnAskText: document.getElementById('learn-ask-text'),
        learnAskCancel: document.getElementById('learn-ask-cancel'),
        learnAskConfirm: document.getElementById('learn-ask-confirm'),
        learnClose: document.getElementById('learn-close'),
        learnNotice: document.getElementById('learn-notice'),
        /* The two faces of the study card can carry a map. */
        learnMapFront: document.getElementById('learn-map-front'),
        learnMapBack: document.getElementById('learn-map-back'),

        /* The second button of the card dialog. */
        dialogSaveNext: document.getElementById('app-dialog-save-next')
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

    /*
     * A hovered tile used to dim every other tile of the row. That read as
     * "the others are not available", although a click simply opens them, so
     * the dimming is gone: each tile keeps its full strength, and the one under
     * the pointer lifts itself and gets the shadow instead (see the
     * stylesheet).
     *
     * clearLinked() stays: the render path calls it.
     */
    function clearLinked() {
        linkedTiles.forEach(function (tile) {
            tile.classList.remove('is-linked');
        });

        elements.grid.classList.remove('is-linking');
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

        /* The server refuses a drawing above the limit with its own code, and the
           sentence is the one the form already shows for a local file. */
        if (code === 'icon_too_large') {
            return t('dialog.icon.tooLarge', { max: Math.round(config.limits.iconBytes / 1024) });
        }

        if (code === 'invalid_icon_scale') {
            return t('dialog.errorScale');
        }

        if (code === 'invalid_front') {
            return t('dialog.errorFront');
        }

        if (code === 'invalid_map_region') {
            return t('dialog.errorMapRegion');
        }

        if (code === 'invalid_back') {
            return t('dialog.errorBack');
        }

        if (code === 'confirm_required') {
            return t('dialog.errorDelete');
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

        /*
         * The study session needs a signed-in user before it can store anything.
         * The sentence says that plainly instead of pretending the answer was
         * saved.
         */
        if (code === 'no_user_session') {
            return t('cards.noUser');
        }

        if (code === 'review_failed') {
            return t('learn.savedFailed');
        }

        if (code === 'undo_conflict') {
            return t('learn.undoFailed');
        }

        return t('dialog.errorServer');
    }

    /* Takes the message away, together with any button that belonged to it. */
    function hideFeedback() {
        window.clearTimeout(feedbackTimer);
        feedbackTimer = null;
        elements.feedbackAction.hidden = true;
        elements.feedbackAction.onclick = null;
        elements.feedback.hidden = true;
    }

    /*
     * Short message at the bottom of the page, for a moment.
     *
     * With an action the message becomes an offer: it stays a little longer and
     * the button next to it can still take the last step back. The button is
     * removed with the message, so a stale button can never be clicked - and it
     * is one-shot, because a second click after an undo would undo what was
     * already kept.
     *
     * options: { actionLabel, onAction, duration }
     */
    function showFeedback(message, options) {
        var settings = options || {};

        hideFeedback();
        elements.feedbackText.textContent = message;

        if (typeof settings.actionLabel === 'string' && typeof settings.onAction === 'function') {
            elements.feedbackAction.textContent = settings.actionLabel;
            elements.feedbackAction.hidden = false;
            elements.feedbackAction.onclick = function (event) {
                event.preventDefault();
                var run = settings.onAction;
                hideFeedback();
                run();
            };
        }

        elements.feedback.hidden = false;

        feedbackTimer = window.setTimeout(function () {
            hideFeedback();
        }, typeof settings.duration === 'number' ? settings.duration : 3200);
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
     *
     * Without a name there is no letter and no placeholder character: an empty
     * circle is honest, a question mark looks like an error.
     */
    function initialLetter(name) {
        var text = String(name === undefined || name === null ? '' : name).trim();

        return text === '' ? '' : text.charAt(0).toLocaleUpperCase();
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
                    requestDelete('category', area, slot);
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

            updateFooterControls('home');
            updateTileNavigation();
        }).catch(handleLoadError);
    }

    /* ----------------------------------------------------------------------
       Detail view (unchanged behaviour)
       ---------------------------------------------------------------------- */

    /*
     * One learning area in the sidebar.
     *
     * No counter in front of the name any more: the dot carries the colour of
     * that area instead, which is the same colour its tile has on the start
     * page. The order still comes from the list itself, so nothing shifts.
     */
    function buildSidebarLink(area, index, isActive) {
        var link = document.createElement('a');
        link.className = 'sidebar__link';
        link.href = 'index.php?category=' + encodeURIComponent(area.id);
        link.style.setProperty('--link-color', 'var(--palette-' + ((index % 8) + 1) + ')');

        if (isActive) {
            link.setAttribute('aria-current', 'page');
        }

        var name = document.createElement('span');
        name.textContent = displayName(area);

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

        /*
         * The direct way into the session of THIS subcategory: one click from
         * the list, no detour over the page. It sits next to the arrow and next
         * to the menu, and because it is a button and not part of the link, it
         * never opens the page instead.
         */
        var play = document.createElement('button');
        play.type = 'button';
        play.className = 'row__play';
        play.setAttribute('aria-label', t('cards.learnThis', { name: title }));
        play.setAttribute('title', t('cards.learnThis', { name: title }));
        play.innerHTML = PLAY_SVG;
        play.addEventListener('click', function () {
            startLearning('all', entry.id, title);
        });

        item.appendChild(play);

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
                    requestDelete('category', entry, item);
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

        var meta = cardStatusMeta(card);

        /*
         * The row itself does nothing. The three dots menu on the right is the
         * ONE way into the card form, on every level (tile, row, head): a second
         * way to the same form is only a way to open it by accident.
         */
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

        /* A card with a map region shows the map above its text: the question
           stays the first thing that is read. */
        var map = el('span', 'card-map card-map--row');
        map.hidden = true;
        stack.appendChild(map);
        showMap(map, card.map_region, 'card-map card-map--row');

        stack.appendChild(front);
        stack.appendChild(back);

        var badge = document.createElement('span');
        badge.className = 'row__badge';
        badge.textContent = t('cards.bidirectionalShort');
        badge.hidden = card.is_bidirectional !== true;

        /*
         * A card that only exists in one language says so, instead of looking
         * empty in the other one. The marker only appears once the table really
         * has a second language.
         */
        var languageBadge = document.createElement('span');
        languageBadge.className = 'row__badge row__badge--language';
        languageBadge.textContent = t(card.language === 'en' ? 'cards.onlyEnglish' : 'cards.onlyGerman');
        languageBadge.hidden = card.missing_language !== true;

        /*
         * The status: a dot and the word for it, always both. The colour alone
         * would say nothing to a person who cannot tell the three colours apart,
         * and the title carries the longer sentence.
         */
        var status = document.createElement('span');
        status.className = 'row__status ' + meta.className;
        status.setAttribute('title', meta.hint);

        var dot = document.createElement('span');
        dot.className = 'row__status-dot';
        dot.setAttribute('aria-hidden', 'true');

        var statusText = document.createElement('span');
        statusText.className = 'row__status-text';
        statusText.textContent = meta.label;

        status.appendChild(dot);
        status.appendChild(statusText);

        body.appendChild(number);
        body.appendChild(stack);
        body.appendChild(badge);
        body.appendChild(languageBadge);
        body.appendChild(status);

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
                    requestDelete('card', card, item);
                }
            }
        ], card.front));

        return item;
    }

    /*
     * The last row of a list is the way in: "Add subcategory" or "Add
     * flashcard". It is a real button with the height of a row, so the list ends
     * with the next step instead of with a dead end.
     */
    function buildAddRow(labelKey, handler) {
        var item = document.createElement('li');
        item.className = 'row-add';

        var button = document.createElement('button');
        button.type = 'button';
        button.className = 'add-row';
        /* The stored labels already start with "+"; the sign is drawn here so
           the two parts can be spaced by the stylesheet. */
        button.textContent = '+ ' + t(labelKey).replace(/^\+\s*/, '');
        button.addEventListener('click', handler);

        item.appendChild(button);

        return item;
    }

    /*
     * The figures of the open entry.
     *
     * A figure only appears when there is something to count ("1 subcategory",
     * "8 subcategories", "32 flashcards"), and when there is nothing at all the
     * whole line stays away: a row of zeroes tells nobody anything.
     *
     * The noun follows the number, which is why the word is set here and not in
     * the template.
     */
    function renderFigures(count, oneKey, otherKey, cardCount) {
        var showMain = count > 0;
        var showCards = typeof cardCount === 'number' && cardCount > 0;

        elements.detailStats.hidden = !showMain && !showCards;
        elements.detailFigureCount.hidden = !showMain;
        elements.detailFigureCards.hidden = !showCards;

        if (showMain) {
            animateCount(elements.detailCount, count);
            elements.statLabel.textContent = t(count === 1 ? oneKey : otherKey);
        }

        if (showCards) {
            elements.detailCardCount.textContent = String(cardCount);
            elements.detailCardLabel.textContent = t(cardCount === 1 ? 'tile.cards.one' : 'tile.cards.other');
        }
    }

    /*
     * The empty state of the detail view.
     *
     * The circle carries the drawing of the area this page belongs to - or the
     * first letter of its name when it has no drawing - so the empty page still
     * belongs to that area. One sentence and one button follow; which button it
     * is depends on the page, so the action is handed in as a function.
     */
    function showEntryEmpty(titleKey, meta, actionKey, run) {
        elements.entryList.hidden = true;
        elements.entryEmptyBlob.hidden = false;
        fillIconCircle(elements.entryEmptyBlob, meta);
        elements.entryEmptyTitle.textContent = t(titleKey);
        /* One sentence: the second line belongs to the "not found" notice. */
        elements.entryEmptyHint.textContent = '';
        elements.entryEmptyHint.hidden = true;
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
            apiRequest(config.endpoints.cards + '?category_id=' + encodeURIComponent(categoryId)
                + '&language=' + encodeURIComponent(locale), 'GET')
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
                /* The id is in the URL but the category is gone. This notice
                   keeps its second line: it explains what happened. There is no
                   area to draw, so the circle stays away. */
                setHeading(elements.detailHeading, t('detail.notFound.title'));
                elements.entryEmptyBlob.hidden = true;
                elements.entryEmptyBlob.textContent = '';
                elements.entryEmptyTitle.textContent = t('detail.notFound.title');
                elements.entryEmptyHint.textContent = t('detail.notFound.hint');
                elements.entryEmptyHint.hidden = false;
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
            /*
             * The card list arrives as cards plus the counts the API made in the
             * same query. A card of an older answer without that envelope would
             * still be an array, so both shapes are understood.
             */
            var cardsPayload = cardsResult.ok && cardsResult.data !== null && typeof cardsResult.data === 'object'
                ? cardsResult.data
                : null;

            if (cardsPayload !== null && Array.isArray(cardsPayload.cards)) {
                currentEntryCards = cardsPayload.cards;
                openCardSummary = typeof cardsPayload.summary === 'object' ? cardsPayload.summary : null;

                /*
                 * Which languages a card can have is decided by the table, not
                 * by this script: the API reports it, and the card dialog shows
                 * the language tabs only when there really are two.
                 */
                if (Array.isArray(cardsPayload.content_languages) && cardsPayload.content_languages.length > 0) {
                    cardContentLanguages = cardsPayload.content_languages;
                }
            } else {
                currentEntryCards = Array.isArray(cardsResult.data) ? cardsResult.data : [];
                openCardSummary = null;
            }

            var pageTitle = displayName(current);

            /*
             * The head zone wears the colour of the learning area this page
             * belongs to. A learning area is its own area; a subcategory takes
             * the colour of its parent, so both levels of one branch look alike.
             * The value is the same palette token the tile uses, chosen by the
             * position of the area in the list.
             */
            var areaRow = isSubcategory && parent !== null ? parent : current;
            var areaIndex = 0;

            allAreas.forEach(function (area, index) {
                if (area.id === areaRow.id) {
                    areaIndex = index;
                }
            });

            elements.detailView.style.setProperty('--detail-palette', 'var(--palette-' + ((areaIndex % 8) + 1) + ')');
            fillIconCircle(elements.detailBlob, categoryMeta(areaRow));

            /*
             * One menu instead of two labelled buttons: the same control that
             * every row and every tile carries, with the same two entries.
             */
            elements.detailActions.textContent = '';
            elements.detailActions.appendChild(buildMenu([
                {
                    label: t('action.edit'),
                    run: openEditForCurrentEntry
                },
                {
                    label: t('action.delete'),
                    danger: true,
                    run: function () {
                        requestDelete('category', currentEntry, null);
                    }
                }
            ], pageTitle, 'detail__menu'));

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

            if (isSubcategory) {
                renderFigures(currentEntryCards.length, 'tile.cards.one', 'tile.cards.other', undefined);
                renderCardTools(openCardSummary);
                showHeadActions('card', current.id, currentEntryCards.length, currentEntryCards.length > 0);

                if (currentEntryCards.length === 0) {
                    /* Nothing to learn, nothing to search: the header stays away
                       and the page offers the one useful step. */
                    elements.entryList.textContent = '';
                    openCards = [];

                    showEntryEmpty('cards.empty.title', categoryMeta(areaRow), 'cards.addFirst', function () {
                        openCardForm(null, current.id);
                    });
                } else {
                    openCards = currentEntryCards;
                    cardSearchQuery = '';
                    elements.cardSearch.value = '';
                    renderCardList(current.id);
                }

                /* The learning area section belongs to the level above. */
                elements.areaCards.hidden = true;
                return;
            }

            /* A learning area has no card list of its own, so its header goes. */
            renderCardTools(null);
            openCards = [];

            /* Everything below this area can be studied in one session, so the
               button is there as soon as the branch holds a single card. */
            showHeadActions(
                'area',
                current.id,
                typeof current.card_count === 'number' ? current.card_count : 0,
                children.length > 0
            );

            renderFigures(children.length, 'tile.subcategories.one', 'tile.subcategories.other', currentEntryCards.length);

            elements.entryList.textContent = '';

            if (children.length === 0) {
                showEntryEmpty('detail.empty.title', categoryMeta(areaRow), 'detail.addSubcategory', function () {
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
         * The two arrows belong to the tile row, so only the start page shows
         * them. Whether they are greyed out as well is decided by
         * updateTileNavigation().
         */
        elements.tilesButtons.hidden = level !== 'home';

        /*
         * The round plus button is the start page's own action and stays there:
         * on a detail page the way to add the next entry sits in the head, where
         * it is visible without scrolling to the end of the list.
         */
        elements.addButton.hidden = level !== 'home';
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

    /*
     * Collects the work of one frame into one call.
     *
     * A scroll, a resize and a change of the row width can each fire several
     * times within the same frame, and every call reads the layout and writes
     * two styles. Waiting for the next animation frame keeps the same result -
     * the line is updated before that frame is painted - and runs the work
     * once instead of once per event.
     */
    var tileNavigationFrame = null;

    function scheduleTileNavigation() {
        if (typeof window.requestAnimationFrame !== 'function') {
            updateTileNavigation();
            return;
        }

        if (tileNavigationFrame !== null) {
            return;
        }

        tileNavigationFrame = window.requestAnimationFrame(function () {
            tileNavigationFrame = null;
            updateTileNavigation();
        });
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

        /*
         * The width only changes when the row is resized, the offset on every
         * scroll. Writing a value that is already there would still invalidate
         * the style of the element, so both are only written when they differ.
         */
        var widthValue = thumbWidth + 'px';

        if (elements.tilesThumb.style.width !== widthValue) {
            elements.tilesThumb.style.width = widthValue;
        }

        var travel = Math.max(0, trackWidth - thumbWidth);
        var progress = maximum > 0 ? elements.grid.scrollLeft / maximum : 0;
        var offsetValue = 'translateX(' + Math.round(progress * travel) + 'px)';

        if (elements.tilesThumb.style.transform !== offsetValue) {
            elements.tilesThumb.style.transform = offsetValue;
        }
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

        elements.grid.addEventListener('scroll', scheduleTileNavigation, { passive: true });

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
            tileObserver = new window.ResizeObserver(scheduleTileNavigation);
            tileObserver.observe(elements.grid);
            tileObserver.observe(elements.tiles);
        }

        window.addEventListener('resize', scheduleTileNavigation);
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

    /*
     * Lets a textarea grow with its content. The height is set from the scroll
     * height after every change, so nothing is ever cut off and no scrollbar
     * appears inside the field.
     */
    function growTextarea(control) {
        if (!control || control.offsetParent === null) {
            return;
        }

        control.style.height = 'auto';
        control.style.height = (control.scrollHeight + 2) + 'px';
    }

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
        elements.dialogSubmit.classList.remove('dialog__button--danger-pill');

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
            importState = null;
            importPanel = null;
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
            /* No native drag handle: the field grows with what is written, so
               the whole text is always visible without scrolling inside a box. */
            control.addEventListener('input', function () {
                growTextarea(control);
            });
            window.requestAnimationFrame(function () {
                growTextarea(control);
            });
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

        /*
         * One name per language, nothing else. The description columns are still
         * in the table, but no part of the application reads or writes them any
         * more (see the note in category_service.php).
         */
        [
            { name: 'name_en', labelKey: 'dialog.category.nameEn' },
            { name: 'name_de', labelKey: 'dialog.category.nameDe' }
        ].forEach(function (def) {
            addField(def.name, 'text', {
                labelKey: def.labelKey,
                maxLength: config.limits.name,
                value: entry === null || typeof entry[def.name] !== 'string' ? '' : entry[def.name],
                container: grid
            });
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
            var letter = initialLetter(dialogFields.name ? dialogFields.name.control.value : '');

            if (letter !== '') {
                var initial = el('span', 'blob__initial');
                initial.textContent = letter;
                circle.appendChild(initial);
            }

            return;
        }

        var icon = el('img', 'blob__icon');
        icon.src = source;
        icon.alt = '';
        circle.appendChild(icon);
    }

    /*
     * The symbol row.
     *
     * One line: the very circle a tile uses, the name of the chosen file and the
     * actions that belong to it. No dashed frame, no second explanation - the
     * circle already shows what the tile will show, and the preview follows the
     * name while it is typed.
     */
    function buildIconField() {
        var wrap = el('div', 'dialog__field');
        var label = el('span', 'dialog__label', t('dialog.category.iconLabel'));
        label.setAttribute('data-i18n', 'dialog.category.iconLabel');
        wrap.appendChild(label);

        var row = el('div', 'icon-row');

        var circle = el('span', 'blob icon-row__circle');
        dialogIcon.preview = circle;

        var text = el('span', 'icon-row__text');
        var action = el('button', 'icon-row__action', t('dialog.icon.choose'));
        action.type = 'button';
        action.setAttribute('data-i18n', 'dialog.icon.choose');

        var fileName = el('span', 'icon-row__name');
        fileName.hidden = true;

        text.appendChild(action);
        text.appendChild(fileName);

        var remove = el('button', 'icon-row__remove', t('dialog.icon.remove'));
        remove.type = 'button';
        remove.setAttribute('data-i18n', 'dialog.icon.remove');
        remove.hidden = true;

        row.appendChild(circle);
        row.appendChild(text);
        row.appendChild(remove);
        wrap.appendChild(row);

        /*
         * What is allowed, in one line under the row: which file types and how
         * large a file may be. The text carries the language key, so the switch
         * translates it like every other static text.
         */
        var hint = el('p', 'dialog__hint', t('dialog.category.iconHint'));
        hint.setAttribute('data-i18n', 'dialog.category.iconHint');
        wrap.appendChild(hint);

        var error = el('p', 'dialog__field-error');
        error.hidden = true;
        error.setAttribute('role', 'alert');
        wrap.appendChild(error);

        var file = document.createElement('input');
        file.type = 'file';
        file.accept = 'image/svg+xml,.svg';
        file.className = 'icon-row__file';
        file.hidden = true;
        wrap.appendChild(file);

        function showError(message) {
            error.textContent = message === null ? '' : message;
            error.hidden = message === null;
        }

        function updateRow() {
            var staged = !dialogIcon.removed && dialogIcon.svg !== null;
            var stored = !dialogIcon.removed && dialogIcon.storedUrl !== null;

            remove.hidden = !staged && !stored;
            action.textContent = t(staged || stored ? 'dialog.icon.replace' : 'dialog.icon.choose');
            action.setAttribute('data-i18n', staged || stored ? 'dialog.icon.replace' : 'dialog.icon.choose');

            fileName.textContent = staged && dialogIcon.name !== '' ? dialogIcon.name : '';
            fileName.hidden = fileName.textContent === '';

            renderIconPreview();
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
                    updateRow();
                });
            });

            reader.addEventListener('error', function () {
                showError(t('dialog.icon.notSvg'));
            });

            reader.readAsText(selected);
        }

        /* The whole row is the target: clicking it, or pressing Enter on it,
           opens the file picker. */
        row.addEventListener('click', function (event) {
            if (event.target === remove) {
                return;
            }

            file.click();
        });

        row.addEventListener('keydown', function (event) {
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
            row.addEventListener(type, function (event) {
                event.preventDefault();
                row.classList.add('is-dragover');
            });
        });

        ['dragleave', 'dragend'].forEach(function (type) {
            row.addEventListener(type, function () {
                row.classList.remove('is-dragover');
            });
        });

        row.addEventListener('drop', function (event) {
            event.preventDefault();
            row.classList.remove('is-dragover');
            acceptFile(event.dataTransfer && event.dataTransfer.files && event.dataTransfer.files.length > 0
                ? event.dataTransfer.files[0]
                : null);
        });

        remove.addEventListener('click', function (event) {
            event.stopPropagation();
            dialogIcon.svg = null;
            dialogIcon.name = '';
            dialogIcon.removed = true;
            dialogUsed = true;
            showError(null);
            updateRow();
        });

        updateRow();

        elements.dialogFields.appendChild(wrap);

        return wrap;
    }

    /* ----------------------------------------------------------------------
       The three forms that use the shared dialog
       ---------------------------------------------------------------------- */


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

        addTranslationGroup(isEdit ? entry : null);
        buildIconField();

        openDialog();

        /*
         * The first field takes the focus (see the spec of the dialog system):
         * the name, which is the one field nobody can skip.
         */
        dialogFields.name.control.focus();
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
        var firstBad = null;

        if (name === '') {
            setFieldError('name', t('dialog.errorNameRequired'));
            firstBad = firstBad || dialogFields.name.control;
        } else if (name.length > config.limits.name) {
            setFieldError('name', t('dialog.errorNameTooLong', { max: config.limits.name }));
            firstBad = firstBad || dialogFields.name.control;
        }

        ['name_en', 'name_de'].forEach(function (field) {
            var value = dialogFields[field].control.value.trim();

            if (value.length > config.limits.name) {
                setFieldError(field, t('dialog.errorNameTooLong', { max: config.limits.name }));
                firstBad = firstBad || dialogFields[field].control;
            }
        });

        if (firstBad !== null) {
            firstBad.focus();
            return null;
        }

        var payload = {
            name: name,
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
        dialogMapField = null;
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

        /*
         * Both languages of this card while the dialog is open. Switching a tab
         * never loses what was typed in the other one: the fields are written
         * into this draft before the language changes.
         */
        cardDraft = {
            de: {
                front: card === null ? '' : cardText(card, 'front', 'de'),
                back: card === null ? '' : cardText(card, 'back', 'de')
            },
            en: {
                front: card === null ? '' : cardText(card, 'front', 'en'),
                back: card === null ? '' : cardText(card, 'back', 'en')
            }
        };

        /* The language of the interface opens first, so nobody has to switch
           before typing. */
        cardTab = cardContentLanguages.indexOf(locale) === -1 ? cardContentLanguages[0] : locale;

        if (cardContentLanguages.length > 1) {
            elements.dialogFields.appendChild(buildLanguageTabs());
        }

        addField('front', 'textarea', {
            labelKey: 'dialog.card.frontLabel',
            placeholderKey: 'dialog.card.frontPlaceholder',
            maxLength: config.limits.cardText,
            rows: 2,
            value: cardDraft[cardTab].front
        });

        addField('back', 'textarea', {
            labelKey: 'dialog.card.backLabel',
            placeholderKey: 'dialog.card.backPlaceholder',
            maxLength: config.limits.cardText,
            rows: 2,
            value: cardDraft[cardTab].back
        });

        /* The optional map: area, region, and the marked map underneath. */
        addMapField(card !== null && typeof card.map_region === 'string' ? card.map_region : null);

        addField('is_bidirectional', 'checkbox', {
            labelKey: 'dialog.card.bidirectionalLabel',
            checked: card !== null && card.is_bidirectional === true
        });

        /* The live preview: the same card shape the study session shows. */
        elements.dialogFields.appendChild(buildCardPreview());

        /*
         * A new card can be typed one after another: the second button saves and
         * keeps the dialog open. While an existing card is edited there is no
         * "next" card, so the button stays away.
         */
        cardSaveAndNext = false;
        elements.dialogSaveNext.hidden = card !== null;
        elements.dialogSaveNext.textContent = t('dialog.card.saveNext');
        elements.dialogSaveNext.disabled = false;

        wireCardDialogShortcuts();

        openDialog();
        dialogFields.front.control.focus();
        updateCardPreview();
    }

    function validateCardForm() {
        /* What is in the fields belongs to the language that is open. */
        if (cardDraft !== null) {
            cardDraft[cardTab].front = dialogFields.front.control.value;
            cardDraft[cardTab].back = dialogFields.back.control.value;
        }

        var payload = {
            is_bidirectional: dialogFields.is_bidirectional.control.checked,
            /* null means "no map": the column is empty then. */
            map_region: dialogMapValue()
        };
        var complete = 0;
        var half = [];

        cardContentLanguages.forEach(function (code) {
            var front = cardDraft[code].front.trim();
            var back = cardDraft[code].back.trim();

            payload['front_' + code] = front;
            payload['back_' + code] = back;

            if (front !== '' && back !== '') {
                complete++;
            }

            if ((front === '') !== (back === '')) {
                half.push(code);
            }
        });

        /*
         * The two original columns of the table carry the German text, so the
         * same value travels under both names. A card may be German only,
         * English only or both.
         */
        if (cardContentLanguages.indexOf('de') !== -1) {
            payload.front = payload.front_de;
            payload.back = payload.back_de;
        }

        /*
         * A language that has only one of its two sides is the one mistake that
         * would store a card nobody can answer. The tab of that language opens,
         * so the missing side is right there.
         */
        if (half.length > 0 && complete === 0) {
            var broken = half[0];

            if (broken !== cardTab) {
                switchCardLanguage(broken);
            }

            var missingFront = cardDraft[broken].front.trim() === '';

            setFieldError(missingFront ? 'front' : 'back', t(missingFront ? 'dialog.errorFrontRequired' : 'dialog.errorBackRequired'));
            dialogFields[missingFront ? 'front' : 'back'].control.focus();

            return null;
        }

        if (complete === 0) {
            setDialogError(t('dialog.card.keepOneLanguage'));
            dialogFields.front.control.focus();

            return null;
        }

        return payload;
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

    /*
     * How much sits inside a category, as far as this page knows.
     *
     * Two sources, one shape: a category read on its own
     * (api/categories.php?id=N) carries a delete_preview for the whole subtree,
     * a tile from the list counts its direct subcategories and the cards of that
     * branch. null means "unknown" - then the dialog is opened instead of
     * guessing, and the server counts again inside its own transaction anyway.
     */
    function knownDependents(target) {
        if (target === null || typeof target !== 'object') {
            return null;
        }

        if (typeof target.delete_preview === 'object' && target.delete_preview !== null) {
            return {
                categories: Number(target.delete_preview.categories) || 0,
                cards: Number(target.delete_preview.cards) || 0
            };
        }

        if (typeof target.subcategory_count === 'number' && typeof target.card_count === 'number') {
            return { categories: target.subcategory_count, cards: target.card_count };
        }

        return null;
    }

    /*
     * The one entry point of every delete button in this app.
     *
     * Three cases, one rule: the more sits inside, the more is asked.
     *
     *   - the entry whose own page is open: nothing to decide, so it goes
     *     straight away (the page has to leave anyway)
     *   - a category with subcategories or cards: the shared dialog names the
     *     counts and asks once, nothing is typed
     *   - everything else - a card, or a category that is empty: it goes at
     *     once, and the message that appears can still take it back
     *     (see queueDelete)
     *
     * $node is the element that shows the entry; it is removed right away so the
     * page does not keep a row that the person has just deleted.
     */
    /*
     * Every deletion is asked about first - an empty entry as well.
     *
     * It used to be different: an empty entry disappeared straight away and only
     * one with content was asked about, so the same click sometimes deleted
     * something and sometimes did not. A question in front of every deletion is
     * the only behaviour a person can predict.
     */
    function requestDelete(kind, target, node) {
        /* Only one deletion waits at a time: a second one finishes the first. */
        finishPendingDelete();

        openDeleteDialog(kind, target, node === undefined ? null : node);
    }

    /* What happens after the question was answered with "Delete". */
    function confirmDelete(kind, target, node) {
        var isCategory = kind === 'category';
        var openEntry = isCategory && currentEntry !== null && currentEntry.id === target.id;

        if (openEntry) {
            /* The page itself is going away, so there is no row to take back. */
            sendDelete(kind, target, false, false);
            return;
        }

        /*
         * A row that stays on the page is taken off the screen first and really
         * deleted when the undo window has passed, so "Undo" can bring it back
         * with everything that belongs to it.
         */
        queueDelete(kind, target, node);
    }

    /*
     * The deletion waits, so "Undo" is possible.
     *
     * Nothing is sent to the server yet: the row is only taken off the screen,
     * and the request follows when the undo window has passed. Taking it back
     * therefore restores the entry exactly as it was - including every card and
     * every bit of learning progress, because none of it was ever touched.
     */
    function queueDelete(kind, target, node) {
        var label = displayName(target);

        if (node && node.parentNode) {
            node.parentNode.removeChild(node);
        }

        pendingDelete = {
            kind: kind,
            target: target,
            url: (kind === 'category' ? config.endpoints.category : config.endpoints.card)
                + '?id=' + encodeURIComponent(target.id),
            timer: window.setTimeout(function () {
                var entry = pendingDelete;
                pendingDelete = null;
                sendDelete(entry.kind, entry.target, false, true);
            }, UNDO_WINDOW_MS)
        };

        showFeedback(t('feedback.deleted', { name: label }), {
            actionLabel: t('feedback.undo'),
            onAction: undoPendingDelete,
            duration: UNDO_WINDOW_MS
        });
    }

    /* "Undo" was pressed: the deletion never happened, so the page is enough. */
    function undoPendingDelete() {
        if (pendingDelete === null) {
            return;
        }

        window.clearTimeout(pendingDelete.timer);
        pendingDelete = null;

        render();
        showFeedback(t('feedback.undone'));
    }

    /* Another deletion started: the waiting one is sent before it. */
    function finishPendingDelete() {
        if (pendingDelete === null) {
            return;
        }

        var entry = pendingDelete;
        window.clearTimeout(entry.timer);
        pendingDelete = null;

        sendDelete(entry.kind, entry.target, false, true);
    }

    /*
     * The page is being left while a deletion is still waiting.
     *
     * The waiting request is sent once more, this time with "keepalive", which
     * lets the browser finish it after the page is gone. Without this a closed
     * tab could leave an entry that the person was told is deleted. The message
     * is taken away before, because a button that leads nowhere must not stay on
     * the screen.
     */
    function flushPendingDelete() {
        if (pendingDelete === null) {
            return;
        }

        var entry = pendingDelete;
        window.clearTimeout(entry.timer);
        pendingDelete = null;
        hideFeedback();

        window.fetch(entry.url, {
            method: 'DELETE',
            headers: { Accept: 'application/json' },
            keepalive: true
        }).catch(function () {
            /* The page is going away; there is nothing left to show. */
        });
    }

    /*
     * The one place that really deletes something.
     *
     * $confirm is true only when the person agreed in the dialog, and it is what
     * the server asks for when subcategories or cards depend on the category.
     * $quiet suppresses the message, because the waiting deletion has already
     * shown its own.
     */
    function sendDelete(kind, target, confirm, quiet) {
        var isCategory = kind === 'category';
        var url = (isCategory ? config.endpoints.category : config.endpoints.card)
            + '?id=' + encodeURIComponent(target.id);
        var label = isCategory ? displayName(target) : target.front;
        var wasOpen = isCategory && currentEntry !== null && currentEntry.id === target.id;

        return apiRequest(url, 'DELETE', confirm === true ? { confirm: true } : undefined)
            .then(function (result) {
                if (!result.ok) {
                    /*
                     * The page knew less than the database: something sits inside
                     * this category after all. The category is read again - that
                     * answer carries the counts of the whole subtree - and the
                     * question is asked once more with the right numbers.
                     */
                    if (result.code === 'confirm_required') {
                        responseCache = {};
                        render();
                        askDeleteAgain(kind, target);
                        return;
                    }

                    /*
                     * A delete that answers 404 is not a failure of the request:
                     * the row really is gone (somebody else deleted it, or it was
                     * already removed). The page is reloaded so it shows the
                     * truth instead of an entry that can never be deleted.
                     */
                    if (result.status === 404) {
                        responseCache = {};
                        render();
                        showFeedback(t('dialog.errorAlreadyGone'));
                        return;
                    }

                    /*
                     * The row is still in the database and was taken off the
                     * screen while the request was on its way, so the page is
                     * built again from the API.
                     */
                    responseCache = {};
                    render();
                    showFeedback(t('dialog.errorDelete'));
                    return;
                }

                /* Everything the page shows comes from the API again. */
                responseCache = {};

                if (wasOpen) {
                    /* The page itself is gone, so the browser goes up one level. */
                    window.location.href = target.parent_id === null
                        ? 'index.php'
                        : 'index.php?category=' + encodeURIComponent(target.parent_id);
                    return;
                }

                render();

                if (quiet !== true) {
                    showFeedback(t('feedback.deleted', { name: label }));
                }
            });
    }

    /* Reads the category again and asks with the numbers that are true now. */
    function askDeleteAgain(kind, target) {
        apiRequest(config.endpoints.categories + '?id=' + encodeURIComponent(target.id), 'GET')
            .then(function (result) {
                if (!result.ok || typeof result.data !== 'object' || result.data === null) {
                    showFeedback(t('dialog.errorDelete'));
                    return;
                }

                openDeleteDialog(kind, result.data);
            });
    }

    /*
     * The one question this app asks before something with content disappears.
     *
     * Only a category that still has subcategories or cards reaches this
     * function - a card and an empty category are deleted without a question
     * (see requestDelete) - so the sentence always has numbers to name.
     *
     * It names the entry and, in the same sentence, what would go with it: the
     * counts from the database and the word "permanently". Nothing has to be
     * typed and there is no checkbox: the red button is the answer, and the
     * focus starts on Cancel, so the safe answer is the one that is already
     * selected.
     */
    function openDeleteDialog(kind, target, node) {
        dialogKind = 'delete';
        dialogEntry = { kind: kind, target: target, node: node };
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
        elements.dialogSubmit.textContent = t('dialog.delete.submit');

        /* The only red button of the application, and only while this question
           is open: closeDialog() takes the class away again. */
        elements.dialogSubmit.classList.add('dialog__button--danger-pill');

        var parts = deletePreviewParts(knownDependents(target) || { categories: 0, cards: 0 });

        elements.dialogTitle.textContent = t('dialog.delete.title', { name: displayName(target) });
        elements.dialogMessage.textContent = parts === ''
            ? t('dialog.delete.nothingBelow')
            : t('dialog.delete.consequence', { parts: parts });
        elements.dialogMessage.hidden = false;

        openDialog();
        elements.dialogCancel.focus();
    }

    /* "Delete" inside the edit form: close it, then take the same path. */
    function askDeleteAfterEdit(entry) {
        closeDialog();

        window.setTimeout(function () {
            requestDelete('category', entry, null);
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
            invalid_icon: 'icon',
            icon_too_large: 'icon'
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

        /*
         * The import dialog is not a form: the file is uploaded again and the
         * server writes the rows, so it takes its own path out of here.
         */
        if (dialogKind === 'import') {
            runImport();
            return;
        }

        var isDelete = dialogKind === 'delete';
        var isCard = dialogKind === 'card';

        /*
         * The question was answered with "Delete": the row leaves the screen and
         * the request follows when the undo window has passed. The dialog closes
         * first, so the focus goes back to where it came from.
         */
        if (isDelete) {
            var question = dialogEntry;
            closeDialog();
            confirmDelete(question.kind, question.target, question.node);
            return;
        }

        /*
         * Saving is a change of the same list a waiting deletion belongs to, so
         * the deletion is sent first instead of being sent into a page that is
         * about to be rebuilt.
         */
        finishPendingDelete();
        var payload = null;
        var url = '';
        var method = 'POST';
        var successMessage = '';

        if (isDelete) {
            var target = dialogEntry.target;
            var isCategory = dialogEntry.kind === 'category';

            /*
             * This path is only used for an entry that still has something inside
             * - an empty one is deleted at once, see requestDelete - so the
             * server gets the one thing it asks for: a plain confirmation flag.
             * Nothing is typed, and no body at all is sent for a card, because
             * the id in the URL already says what is meant.
             */
            payload = isCategory ? { confirm: true } : undefined;

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

            /* Remembered before the dialog closes: the "save and next" path needs
               it to open the empty form again for the same subcategory. */
            cardSubmitCategoryId = dialogParentId;

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

            /*
             * "Save and next card": the list is rebuilt from the API first, so the
             * card that was just saved really is in it, and then the empty form
             * opens again for the same subcategory.
             */
            if (isCard && cardSaveAndNext) {
                cardSaveAndNext = false;
                render();

                window.setTimeout(function () {
                    openCardForm(null, cardSubmitCategoryId);
                }, prefersReducedMotion() ? 0 : 140);

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

        /* Add follows the level that is open. */
        elements.addButton.addEventListener('click', openAddForCurrentEntry);


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

        /*
         * A deletion that is still waiting is finished when the page is left, so
         * a closed tab cannot leave an entry that the person was told is deleted.
         * "keepalive" lets the browser complete the request after the unload.
         */
        window.addEventListener('pagehide', flushPendingDelete);

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

        applyLocale(locale, false);
    }

    /* ----------------------------------------------------------------------
       The card list of a subcategory
       ---------------------------------------------------------------------- */

    /* Which subcategory a "save and next card" belongs to. */
    var cardSubmitCategoryId = null;

    /*
     * The three statuses a card can have, with the wording and the class name
     * that belongs to each. The status itself comes from the API: it counts what
     * is in the database, incl. whether a card is due, and the browser only
     * repeats it.
     */
    function cardStatusMeta(card) {
        var status = card.progress && typeof card.progress.status === 'string' ? card.progress.status : 'new';
        var name = status === 'known' ? 'known' : (status === 'unsure' ? 'unsure' : 'new');
        var hint = t('cards.status.' + name + 'Hint');

        /*
         * A card that is due says since when. The date is shown in the language
         * that is switched on, and it is only a hint: the word next to the dot
         * already says that something has to be done.
         */
        if (card.progress && card.progress.is_due === true && typeof card.progress.due_at === 'string') {
            hint = hint + ' · ' + t('cards.dueHint', { date: formatDueDate(card.progress.due_at) });
        }

        return {
            status: name,
            label: t('cards.status.' + name),
            hint: hint,
            className: 'row__status--' + name
        };
    }

    /* Turns "2026-09-21 15:04:05" into a short date in the current language. */
    function formatDueDate(value) {
        var parsed = new Date(String(value).replace(' ', 'T'));

        if (isNaN(parsed.getTime())) {
            return String(value);
        }

        return parsed.toLocaleDateString(locale === 'de' ? 'de-DE' : 'en-GB', {
            day: '2-digit',
            month: '2-digit',
            year: 'numeric'
        });
    }

    /*
     * The header of a card list: how many cards there are, how many of them are
     * waiting, and the bar that shows the three statuses as shares.
     *
     * null means "this page has no card list", and then the whole header goes.
     */
    function renderCardTools(summary) {
        var usable = summary !== null
            && typeof summary === 'object'
            && typeof summary.total === 'number'
            && summary.total > 0;

        elements.cardTools.hidden = !usable;

        if (!usable) {
            elements.cardSearchWrap.hidden = true;
            return;
        }

        var total = summary.total;
        var fresh = summary['new'] || 0;
        var unsure = summary.unsure || 0;
        var known = summary.known || 0;
        var due = summary.due || 0;

        /*
         * The number of cards is NOT repeated here: the quiet line under the head
         * already says "34 cards", and the same number twice in a row only made
         * the head harder to read. What stays is everything the count cannot say.
         */
        elements.cardToolsCount.hidden = true;

        elements.cardToolsDue.textContent = t('cards.due', { count: due });
        elements.cardToolsDue.hidden = due === 0;

        elements.cardToolsLegend.textContent = t('cards.legend', { 'new': fresh, unsure: unsure, known: known });
        elements.cardToolsBar.setAttribute(
            'aria-label',
            t('cards.distribution', { 'new': fresh, unsure: unsure, known: known })
        );

        setCardToolsPart(elements.cardToolsPartNew, fresh, total);
        setCardToolsPart(elements.cardToolsPartUnsure, unsure, total);
        setCardToolsPart(elements.cardToolsPartKnown, known, total);

        /* A search over three cards is more work than looking at them. */
        elements.cardSearchWrap.hidden = total < cardSearchMin;
        elements.cardSearch.setAttribute('placeholder', t('cards.searchPlaceholder'));
        elements.cardSearch.setAttribute('aria-label', t('cards.search'));
    }

    function setCardToolsPart(element, count, total) {
        element.hidden = count === 0;
        element.style.setProperty('--share', (total > 0 ? (count / total) * 100 : 0) + '%');
    }

    /*
     * Builds the rows that are visible right now.
     *
     * The filter runs over the list that is already loaded and asks the server
     * for nothing: a search is a view of the same data, and no query is built
     * from what somebody typed.
     */
    function renderCardList(categoryId) {
        var query = cardSearchQuery.trim().toLowerCase();
        var visible = [];
        var index;

        elements.entryList.textContent = '';

        for (index = 0; index < openCards.length; index++) {
            if (query === '' || cardMatches(openCards[index], query)) {
                visible.push({ card: openCards[index], index: index });
            }
        }

        /* Nothing matches the search: that is not "no cards yet". */
        elements.cardSearchEmpty.textContent = query === '' ? '' : t('cards.searchEmpty');
        elements.cardSearchEmpty.hidden = query === '' || visible.length > 0;

        if (visible.length === 0 && query !== '') {
            elements.entryList.hidden = true;
            return;
        }

        visible.forEach(function (entry) {
            elements.entryList.appendChild(buildCardRow(entry.card, entry.index));
        });

        elements.entryList.hidden = false;
    }

    function cardMatches(card, query) {
        var front = typeof card.front === 'string' ? card.front.toLowerCase() : '';
        var back = typeof card.back === 'string' ? card.back.toLowerCase() : '';

        return front.indexOf(query) !== -1 || back.indexOf(query) !== -1;
    }

    /*
     * The live preview inside the card dialog: the two text fields as they are
     * typed, in the shape the study session uses, so what is written is what will
     * be asked later.
     */
    function buildCardPreview() {
        var wrap = el('div', 'dialog__field dialog__field--preview');

        var head = el('div', 'dialog__preview-head');
        var label = el('p', 'dialog__label', t('dialog.card.preview'));
        label.setAttribute('data-i18n', 'dialog.card.preview');

        /* Which language is in the preview right now. */
        var language = el('span', 'dialog__preview-language', '');
        language.id = 'card-preview-language';

        head.appendChild(label);
        head.appendChild(language);

        /*
         * The preview can be turned over, so both sides of what is being written
         * are visible before saving. It is reachable with the keyboard like any
         * other control on the page.
         */
        var card = el('div', 'card-preview');
        card.setAttribute('role', 'button');
        card.setAttribute('tabindex', '0');
        card.setAttribute('aria-label', t('dialog.card.preview'));
        card.addEventListener('click', function () {
            card.classList.toggle('is-flipped');
        });
        card.addEventListener('keydown', function (event) {
            if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                card.classList.toggle('is-flipped');
            }
        });
        var frontLabel = el('p', 'card-preview__label', t('dialog.card.previewFront'));
        frontLabel.setAttribute('data-i18n', 'dialog.card.previewFront');

        var front = el('p', 'card-preview__text', '');
        front.id = 'card-preview-front';
        front.setAttribute('data-i18n-empty', 'dialog.card.frontPlaceholder');

        var backLabel = el('p', 'card-preview__label', t('dialog.card.previewBack'));
        backLabel.setAttribute('data-i18n', 'dialog.card.previewBack');

        var back = el('p', 'card-preview__text', '');
        back.id = 'card-preview-back';
        back.setAttribute('data-i18n-empty', 'dialog.card.backPlaceholder');

        /*
         * Two sides, exactly like the card in a session: the front is what is
         * asked, the back is what is answered, and the click on the preview
         * shows one or the other.
         */
        var frontSide = el('div', 'card-preview__side card-preview__side--front');
        frontSide.appendChild(frontLabel);
        frontSide.appendChild(front);

        var backSide = el('div', 'card-preview__side card-preview__side--back');
        backSide.appendChild(backLabel);
        backSide.appendChild(back);

        /* The live preview of the map, on the side that carries the answer. */
        var previewMap = el('div', 'card-map card-map--preview');
        previewMap.id = 'card-preview-map';
        previewMap.hidden = true;
        backSide.appendChild(previewMap);

        card.appendChild(frontSide);
        card.appendChild(backSide);

        var count = el('p', 'dialog__hint', '');
        count.id = 'card-preview-count';

        var hint = el('p', 'dialog__hint', t('dialog.card.hintShortcut'));
        hint.setAttribute('data-i18n', 'dialog.card.hintShortcut');

        wrap.appendChild(head);
        wrap.appendChild(card);
        wrap.appendChild(count);
        wrap.appendChild(hint);

        return wrap;
    }

    /* Writes the two fields into the preview, keeping the line breaks. */
    function updateCardPreview() {
        if (dialogKind !== 'card' || !dialogFields.front || !dialogFields.back) {
            return;
        }

        var front = document.getElementById('card-preview-front');
        var back = document.getElementById('card-preview-back');
        var count = document.getElementById('card-preview-count');

        if (front !== null) {
            front.textContent = dialogFields.front.control.value;
            front.classList.toggle('is-empty', dialogFields.front.control.value.trim() === '');
        }

        if (back !== null) {
            back.textContent = dialogFields.back.control.value;
            back.classList.toggle('is-empty', dialogFields.back.control.value.trim() === '');
        }

        showMap(document.getElementById('card-preview-map'), dialogMapValue(), 'card-map card-map--preview');

        if (count !== null) {
            count.textContent = t('dialog.card.frontCount', {
                count: dialogFields.front.control.value.length,
                max: config.limits.cardText
            });
        }

        var language = document.getElementById('card-preview-language');

        if (language !== null) {
            language.textContent = t(cardTab === 'en' ? 'dialog.card.languageEn' : 'dialog.card.languageDe');
        }
    }

    /*
     * The two keyboard shortcuts of the card dialog: typing in the fields updates
     * the preview, and Ctrl+Enter saves without reaching for the mouse. Tab is
     * left alone - it goes from the front to the back by itself, because that is
     * the order of the fields.
     */
    function wireCardDialogShortcuts() {
        ['front', 'back'].forEach(function (name) {
            var field = dialogFields[name];

            if (!field) {
                return;
            }

            field.control.addEventListener('input', function () {
                /* What is typed belongs to the language that is open. */
                if (cardDraft !== null) {
                    cardDraft[cardTab].front = dialogFields.front.control.value;
                    cardDraft[cardTab].back = dialogFields.back.control.value;
                }

                updateCardPreview();
                updateCardLanguageTabs();
            });

            field.control.addEventListener('keydown', function (event) {
                if (event.key === 'Enter' && (event.ctrlKey || event.metaKey)) {
                    event.preventDefault();
                    submitDialog();
                }
            });
        });
    }

    /* ----------------------------------------------------------------------
       The study session
       ---------------------------------------------------------------------- */

    /*
     * One session, held in memory only:
     *
     *   queue     the turns to work through, as the API ordered them
     *   index     the turn that is on screen
     *   flipped   whether the answer is showing
     *   results   card id -> the status the API stored for it
     *   ratings   every answer given, in order (for the summary)
     *   undo      the last answer, with everything needed to take it back
     *
     * Nothing about the schedule is calculated here. The queue order, the status
     * and the interval all come from the API; this side only shows them and
     * counts what it was told.
     */
    var learnSession = null;
    var learnTimer = null;

    function learnIsOpen() {
        return learnSession !== null;
    }

    /* Opens the session for the open subcategory. */
    function startLearning(mode, targetCategoryId, label) {
        /*
         * The session belongs to the entry that was clicked: the one that is
         * open, the subcategory behind a row of the list, or the whole learning
         * area behind "Study all". The server decides what belongs to it.
         */
        var categoryId = typeof targetCategoryId === 'number'
            ? targetCategoryId
            : (currentEntry === null ? null : currentEntry.id);

        if (categoryId === null) {
            return;
        }

        var url = config.endpoints.review
            + '?category_id=' + encodeURIComponent(categoryId)
            + '&language=' + encodeURIComponent(locale)
            + '&mode=' + (mode === 'difficult' ? 'difficult' : 'all');

        elements.learnButton.disabled = true;

        apiRequest(url, 'GET').then(function (result) {
            elements.learnButton.disabled = false;

            if (!result.ok || result.data === null || !Array.isArray(result.data.queue)) {
                showFeedback(errorMessage(result.code));
                return;
            }

            if (result.data.queue.length === 0) {
                showFeedback(t('learn.noCards'));
                return;
            }

            learnSession = {
                categoryId: categoryId,
                mode: result.data.mode === 'difficult' ? 'difficult' : 'all',
                label: typeof label === 'string' ? label : (currentEntry === null ? '' : displayName(currentEntry)),
                queue: result.data.queue.slice(),
                hasUser: result.data.has_user === true,
                index: 0,
                flipped: false,
                busy: false,
                results: {},
                ratings: [],
                again: {},
                undo: null,
                saved: 0
            };

            openLearnView();
            renderLearnCard();
        });
    }

    function openLearnView() {
        elements.learn.hidden = false;
        elements.learnSummary.hidden = true;
        elements.learnStage.hidden = false;
        elements.learnAsk.hidden = true;
        elements.learnNotice.hidden = true;
        document.body.classList.add('is-learning');

        /* The focus goes into the session, so the keyboard works right away. */
        elements.learnCard.focus();

        if (learnSession.hasUser !== true) {
            showLearnNotice(t('learn.noUser'), true);
        }
    }

    /*
     * Shows the turn that is on screen: the counter, the line, the two sides and
     * the four answers with the interval each of them would lead to.
     */
    function renderLearnCard() {
        if (learnSession === null) {
            return;
        }

        var entry = learnSession.queue[learnSession.index];

        if (entry === undefined) {
            showLearnSummary();
            return;
        }

        elements.learnCounter.textContent = t('learn.counter', {
            position: learnSession.index + 1,
            total: learnSession.queue.length
        });

        var percent = Math.min(100, Math.round((learnSession.index / learnSession.queue.length) * 100));
        elements.learnProgressFill.style.width = percent + '%';
        elements.learnProgress.setAttribute('aria-valuenow', String(percent));
        elements.learnProgress.setAttribute('aria-valuetext', t('learn.progress', { percent: percent }));

        elements.learnFrontText.textContent = entry.front;
        elements.learnBackText.textContent = entry.back;

        /*
         * The map belongs to the side that carries the ANSWER: on a card that asks
         * "where is Bavaria?" it is the back, so the person guesses first and then
         * sees the marked map. A card that is studied the other way round (the
         * answer is the question) shows it on the front instead.
         */
        showMap(elements.learnMapFront, null);
        showMap(elements.learnMapBack, null);
        showMap(
            entry.direction === 'reverse' ? elements.learnMapFront : elements.learnMapBack,
            entry.map_region,
            'card-map card-map--learn'
        );
        elements.learnCard.setAttribute('aria-label', learnSession.flipped ? entry.back : entry.front);

        /* Both faces are written; which one is visible is the flip. */
        elements.learnCard.classList.toggle('is-flipped', learnSession.flipped);
        elements.learnStage.classList.toggle('is-flipped', learnSession.flipped);
        elements.learnSideLabel.textContent = t(learnSession.flipped ? 'learn.answer' : 'learn.question');
        elements.learnSideLabel.setAttribute('data-i18n', learnSession.flipped ? 'learn.answer' : 'learn.question');
        elements.learnHint.hidden = learnSession.flipped;

        buildLearnButtons(entry);
    }

    /*
     * The four answers. The interval under each of them comes from the API, which
     * calculated it with the same scheduler that will store the answer.
     */
    function buildLearnButtons(entry) {
        var previews = entry.preview_minutes && typeof entry.preview_minutes === 'object' ? entry.preview_minutes : null;

        elements.learnButtons.textContent = '';

        learnRatingKeys().forEach(function (rating) {
            var button = el('button', 'learn__button learn__button--' + rating.name);
            button.type = 'button';
            button.disabled = !learnSession.flipped;
            button.dataset.rating = String(rating.value);

            var label = el('span', 'learn__button-label', t(rating.labelKey));
            label.setAttribute('data-i18n', rating.labelKey);

            var key = el('span', 'learn__button-key', rating.key);
            key.setAttribute('aria-hidden', 'true');

            button.appendChild(label);
            button.appendChild(key);

            if (previews !== null) {
                var minutes = previews[rating.name];
                var interval = el('span', 'learn__button-interval', '');
                interval.setAttribute(
                    'title',
                    t('learn.shortcut', { key: rating.key })
                );

                if (typeof minutes === 'number') {
                    interval.textContent = t('learn.nextInterval', { interval: formatLearnInterval(minutes) });
                }

                button.appendChild(interval);
            }

            button.setAttribute(
                'aria-label',
                t(rating.labelKey) + (typeof previews === 'object' && previews !== null && typeof previews[rating.name] === 'number'
                    ? ', ' + t('learn.nextInterval', { interval: formatLearnInterval(previews[rating.name]) })
                    : '')
            );

            button.addEventListener('click', function () {
                rateLearnCard(rating.value);
            });

            elements.learnButtons.appendChild(button);
        });
    }

    /* The four answers, in the order they are shown and pressed. */
    function learnRatingKeys() {
        return [
            { value: 1, key: '1', name: 'again', labelKey: 'learn.again' },
            { value: 2, key: '2', name: 'hard', labelKey: 'learn.hard' },
            { value: 3, key: '3', name: 'good', labelKey: 'learn.good' },
            { value: 4, key: '4', name: 'easy', labelKey: 'learn.easy' }
        ];
    }

    /* "10 min", "19 h", "1 day", "3 days": the number comes from the server. */
    function formatLearnInterval(minutes) {
        if (minutes < 60) {
            return t('learn.intervalMinutes', { count: minutes });
        }

        if (minutes < 60 * 24) {
            return t('learn.intervalHours', { count: Math.round(minutes / 60) });
        }

        var days = Math.round((minutes / (60 * 24)) * 10) / 10;

        return days === 1
            ? t('learn.intervalOneDay')
            : t('learn.intervalDays', { count: days });
    }

    function flipLearnCard() {
        if (learnSession === null || learnSession.busy) {
            return;
        }

        learnSession.flipped = !learnSession.flipped;

        elements.learnCard.classList.toggle('is-flipped', learnSession.flipped);
        elements.learnStage.classList.toggle('is-flipped', learnSession.flipped);
        elements.learnSideLabel.textContent = t(learnSession.flipped ? 'learn.answer' : 'learn.question');
        elements.learnHint.hidden = learnSession.flipped;

        var entry = learnSession.queue[learnSession.index];

        if (entry !== undefined) {
            elements.learnCard.setAttribute('aria-label', learnSession.flipped ? entry.back : entry.front);
        }

        /* The answers keep their place: before the flip they are there, but out
           of reach. */
        Array.prototype.forEach.call(elements.learnButtons.children, function (button) {
            button.disabled = !learnSession.flipped;
        });

        /*
         * The focus stays on the card while it is turned over. If it jumped to
         * the first answer, the space bar would press that answer instead of
         * turning the card back - and the space bar is meant to turn the card,
         * always. The four answers are reached with Tab, and their number keys
         * work at any time.
         */
        elements.learnCard.focus();
    }

    /*
     * Stores one answer. The API decides everything: the new status, the interval
     * and when the card comes back. This function only shows what came back.
     */
    function rateLearnCard(rating) {
        if (learnSession === null || learnSession.busy || !learnSession.flipped) {
            return;
        }

        if (learnSession.hasUser !== true) {
            showLearnNotice(t('learn.noUser'));
            return;
        }

        var entry = learnSession.queue[learnSession.index];

        if (entry === undefined) {
            return;
        }

        learnSession.busy = true;
        setLearnButtonsDisabled(true);

        apiRequest(config.endpoints.review, 'POST', {
            action: 'rate',
            category_id: learnSession.categoryId,
            card_id: entry.card_id,
            rating: rating
        }).then(function (result) {
            learnSession.busy = false;
            setLearnButtonsDisabled(false);

            if (!result.ok) {
                /* The answer was NOT stored, so the card stays where it is and
                   the message says why. */
                showLearnNotice(errorMessage(result.code));
                return;
            }

            var data = result.data;

            learnSession.saved++;
            learnSession.ratings.push(rating);
            learnSession.results[entry.card_id] = data.status;

            /*
             * "Again" comes back inside the same session. The card is put behind
             * everything that is left, twice at most, so a card that is simply
             * not there yet cannot keep the session running forever.
             */
            var repeats = learnSession.again[entry.card_id] || 0;
            var pushedAgain = false;

            if (rating === 1 && repeats < 2) {
                learnSession.again[entry.card_id] = repeats + 1;
                learnSession.queue.push(entry);
                pushedAgain = true;
            }

            learnSession.undo = {
                index: learnSession.index,
                cardId: entry.card_id,
                rating: rating,
                stored: data.stored,
                previous: data.previous_state,
                hadProgressBefore: data.had_progress_before === true,
                pushedAgain: pushedAgain
            };

            if (rating === 1) {
                showLearnNotice(t('learn.rateAgain'));
            } else {
                hideLearnNotice();
            }

            moveToNextLearnCard();
        });
    }

    function setLearnButtonsDisabled(disabled) {
        Array.prototype.forEach.call(elements.learnButtons.children, function (button) {
            button.disabled = disabled || !learnSession.flipped;
        });
    }

    /* The card leaves to the left, the next one comes in from the right. */
    function moveToNextLearnCard() {
        var reduced = prefersReducedMotion();

        elements.learnCard.classList.add('is-leaving-left');

        window.clearTimeout(learnTimer);
        learnTimer = window.setTimeout(function () {
            elements.learnCard.classList.remove('is-leaving-left', 'is-flipped');
            learnSession.index++;
            learnSession.flipped = false;

            if (learnSession.index >= learnSession.queue.length) {
                showLearnSummary();
                return;
            }

            renderLearnCard();
            elements.learnCard.classList.add('is-entering-right');

            learnTimer = window.setTimeout(function () {
                elements.learnCard.classList.remove('is-entering-right');
            }, reduced ? 0 : 260);
        }, reduced ? 0 : 250);
    }

    /*
     * Takes the last answer back. The API checks that nothing changed since, so a
     * card that was rated again in another tab is never overwritten silently.
     */
    function undoLearnRating() {
        if (learnSession === null || learnSession.busy || learnSession.undo === null) {
            return;
        }

        var undo = learnSession.undo;

        learnSession.busy = true;

        apiRequest(config.endpoints.review, 'POST', {
            action: 'undo',
            category_id: learnSession.categoryId,
            card_id: undo.cardId,
            stored: undo.stored,
            previous: undo.previous
        }).then(function (result) {
            learnSession.busy = false;

            if (!result.ok) {
                showLearnNotice(errorMessage(result.code));
                return;
            }

            learnSession.saved = Math.max(0, learnSession.saved - 1);
            learnSession.ratings.pop();
            delete learnSession.results[undo.cardId];

            if (undo.pushedAgain) {
                var last = learnSession.queue.length - 1;

                while (last > undo.index && learnSession.queue[last].card_id === undo.cardId) {
                    learnSession.queue.splice(last, 1);
                    last--;
                }
            }

            learnSession.undo = null;
            learnSession.index = undo.index;
            learnSession.flipped = false;

            elements.learnSummary.hidden = true;
            elements.learnStage.hidden = false;

            renderLearnCard();
            showLearnNotice(t('learn.undone'));
        });
    }

    /*
     * The end of a session: how many cards are known now, how the answers were
     * spread, and the two ways on.
     */
    function showLearnSummary() {
        if (learnSession === null) {
            return;
        }

        var counts = { again: 0, hard: 0, good: 0, easy: 0 };
        var known = 0;
        var total = 0;
        var seen = {};

        learnSession.ratings.forEach(function (rating) {
            var found = learnRatingKeys().filter(function (item) {
                return item.value === rating;
            })[0];

            if (found) {
                counts[found.name]++;
            }
        });

        Object.keys(learnSession.results).forEach(function (cardId) {
            seen[cardId] = true;
        });

        total = Math.max(1, Object.keys(seen).length);

        Object.keys(learnSession.results).forEach(function (cardId) {
            if (learnSession.results[cardId] === 'known') {
                known++;
            }
        });

        elements.learnStage.hidden = true;
        elements.learnSummary.hidden = false;
        elements.learnSummaryNumber.textContent = t('learn.done.known', { known: known, total: total });

        elements.learnSummaryBars.textContent = '';

        var highest = Math.max(1, counts.again, counts.hard, counts.good, counts.easy);

        learnRatingKeys().forEach(function (rating) {
            var item = el('li', 'learn__bar learn__bar--' + rating.name);
            var label = el('span', 'learn__bar-label', t(rating.labelKey));
            label.setAttribute('data-i18n', rating.labelKey);
            var track = el('span', 'learn__bar-track');
            var fill = el('span', 'learn__bar-fill');
            fill.style.setProperty('--share', (counts[rating.name] / highest) * 100 + '%');
            track.appendChild(fill);
            var value = el('span', 'learn__bar-value', String(counts[rating.name]));
            item.appendChild(label);
            item.appendChild(track);
            item.appendChild(value);
            elements.learnSummaryBars.appendChild(item);
        });

        /* The difficult cards of this session can be repeated right away. */
        var difficult = learnSession.ratings.filter(function (rating) {
            return rating === 1 || rating === 2;
        }).length;

        elements.learnRepeat.hidden = difficult === 0;
        elements.learnSummaryLeft.textContent = t('learn.done.left', { count: counts.again });
        elements.learnSummaryLeft.hidden = counts.again === 0 && difficult === 0;

        elements.learnFinish.focus();
    }

    /* Leaves the session and reloads the list, so the dots are up to date. */
    function closeLearnView() {
        window.clearTimeout(learnTimer);
        learnSession = null;

        elements.learn.hidden = true;
        elements.learnAsk.hidden = true;
        elements.learnNotice.hidden = true;
        elements.learnCard.classList.remove('is-flipped', 'is-leaving-left', 'is-entering-right');
        document.body.classList.remove('is-learning');

        /* Everything the page shows comes from the API again: the statuses of the
           cards just answered are not guessed from memory. */
        responseCache = {};
        render();

        /* Back to the way in that was used - if it is still on the page. */
        if (!elements.learnButton.hidden) {
            elements.learnButton.focus();
        }
    }

    /*
     * Closing asks first when answers were already stored. A session that has
     * only been looked at closes straight away - there is nothing to lose.
     */
    function askBeforeClosingLearn() {
        if (learnSession === null) {
            return;
        }

        if (learnSession.saved === 0) {
            closeLearnView();
            return;
        }

        elements.learnAskText.textContent = t('learn.askEnd.text', { count: learnSession.saved });
        elements.learnAsk.hidden = false;
        elements.learnAskCancel.focus();
    }

    function showLearnNotice(message, show) {
        elements.learnNotice.textContent = message;
        elements.learnNotice.hidden = show === false;
    }

    function hideLearnNotice() {
        elements.learnNotice.hidden = true;
        elements.learnNotice.textContent = '';
    }

    /* ----------------------------------------------------------------------
       Wiring: the study button, the search, the session keys and the swipe
       ---------------------------------------------------------------------- */

    function wireLearning() {
        elements.learnButton.addEventListener('click', function () {
            startLearning('all');
        });

        /* The search filters the loaded list; it never asks the server. */
        elements.cardSearch.addEventListener('input', function () {
            cardSearchQuery = elements.cardSearch.value;

            if (currentEntry !== null) {
                renderCardList(currentEntry.id);
            }
        });

        elements.learnClose.addEventListener('click', askBeforeClosingLearn);
        elements.learnAskCancel.addEventListener('click', function () {
            elements.learnAsk.hidden = true;
            elements.learnCard.focus();
        });
        elements.learnAskConfirm.addEventListener('click', closeLearnView);
        elements.learnFinish.addEventListener('click', closeLearnView);

        elements.learnRepeat.addEventListener('click', function () {
            startLearning('difficult');
        });

        /* A click on the card turns it over - on a phone this is the tap. */
        elements.learnCard.addEventListener('click', function (event) {
            if (event.target.closest('button') === null) {
                flipLearnCard();
            }
        });

        wireLearnKeyboard();
        wireLearnSwipe();
    }

    /*
     * The keys of a session: space and Enter turn the card over, 1 to 4 answer
     * it, the left arrow takes the last answer back and Escape ends the session.
     *
     * The listener sits on the document, so it works no matter which element has
     * the focus - but it stays out of the way while a button is focused, because
     * Enter and space belong to that button then.
     */
    function wireLearnKeyboard() {
        document.addEventListener('keydown', function (event) {
            if (learnSession === null) {
                return;
            }

            /* A question about ending the session owns the keyboard. */
            if (!elements.learnAsk.hidden) {
                if (event.key === 'Escape') {
                    event.preventDefault();
                    elements.learnAsk.hidden = true;
                    elements.learnCard.focus();
                }

                return;
            }

            if (event.key === 'Escape') {
                event.preventDefault();
                askBeforeClosingLearn();
                return;
            }

            if (event.key === 'ArrowLeft') {
                event.preventDefault();
                undoLearnRating();
                return;
            }

            var focusedIsButton = document.activeElement !== null
                && typeof document.activeElement.closest === 'function'
                && document.activeElement.closest('button') !== null;

            if (event.key === ' ' || event.key === 'Enter') {
                if (focusedIsButton && event.target !== elements.learnCard) {
                    return;
                }

                event.preventDefault();
                flipLearnCard();
                return;
            }

            if (event.key >= '1' && event.key <= '4') {
                event.preventDefault();
                rateLearnCard(Number(event.key));
            }
        });
    }

    /*
     * Swiping: to the left means "Again", to the right means "Good". The four
     * buttons stay where they are - a swipe is a shortcut, not the only way.
     */
    function wireLearnSwipe() {
        var start = null;

        elements.learnStage.addEventListener('pointerdown', function (event) {
            start = { x: event.clientX, y: event.clientY, id: event.pointerId };
        });

        elements.learnStage.addEventListener('pointerup', function (event) {
            if (start === null || start.id !== event.pointerId) {
                start = null;
                return;
            }

            var dx = event.clientX - start.x;
            var dy = event.clientY - start.y;
            start = null;

            if (learnSession === null || learnSession.busy || !learnSession.flipped) {
                return;
            }

            /* Only a clearly horizontal movement counts as a swipe. */
            if (Math.abs(dx) < 70 || Math.abs(dy) > 60) {
                return;
            }

            rateLearnCard(dx < 0 ? 1 : 3);
        });

        elements.learnStage.addEventListener('pointercancel', function () {
            start = null;
        });
    }

    wireLearning();
    /* ----------------------------------------------------------------------
       The two languages of a card
       ---------------------------------------------------------------------- */

    /*
     * Which languages a card can carry. There is one until the English columns
     * exist in the table - the API reports the truth and this side only follows
     * it, so the language tabs appear by themselves the moment the migration has
     * been run.
     */
    var cardContentLanguages = ['de'];

    /* What is in the two fields, per language, while the card dialog is open. */
    var cardDraft = null;
    var cardTab = 'de';
    var cardTabs = {};

    /*
     * One side of a card in one language.
     *
     * The German text lives in the two original columns (`front` and `back`), so
     * this falls back to them when the language-specific key is not there. That
     * keeps the dialog correct with and without the English columns.
     */
    function cardText(card, side, language) {
        var value = card[side + '_' + language];

        if (typeof value !== 'string' && language === 'de') {
            value = card[side];
        }

        return typeof value === 'string' ? value : '';
    }

    /* The name of a language, in the language of the interface. */
    function cardLanguageName(code) {
        return t(code === 'en' ? 'dialog.card.languageEn' : 'dialog.card.languageDe');
    }

    /*
     * The small switch above the two fields: "Deutsch" and "English". It only
     * exists while the table really holds two languages.
     */
    function buildLanguageTabs() {
        var wrap = el('div', 'dialog__field dialog__field--languages');
        var label = el('p', 'dialog__label', t('dialog.card.languageLabel'));
        label.setAttribute('data-i18n', 'dialog.card.languageLabel');

        var row = el('div', 'lang-tabs');
        row.setAttribute('role', 'tablist');

        cardTabs = {};

        cardContentLanguages.forEach(function (code) {
            var button = el('button', 'lang-tab');
            button.type = 'button';
            button.setAttribute('role', 'tab');

            var name = el('span', 'lang-tab__name', cardLanguageName(code));
            var mark = el('span', 'lang-tab__mark');
            mark.setAttribute('aria-hidden', 'true');

            button.appendChild(name);
            button.appendChild(mark);

            button.addEventListener('click', function () {
                switchCardLanguage(code);
            });

            row.appendChild(button);
            cardTabs[code] = button;
        });

        var hint = el('p', 'dialog__hint', t('dialog.card.languageHint'));
        hint.setAttribute('data-i18n', 'dialog.card.languageHint');

        wrap.appendChild(label);
        wrap.appendChild(row);
        wrap.appendChild(hint);

        updateCardLanguageTabs();

        return wrap;
    }

    /* Changes which language the two fields are showing. */
    function switchCardLanguage(code) {
        if (cardDraft === null || dialogFields.front === undefined || code === cardTab) {
            return;
        }

        /* Nothing is lost: what was typed stays with its language. */
        cardDraft[cardTab].front = dialogFields.front.control.value;
        cardDraft[cardTab].back = dialogFields.back.control.value;

        cardTab = code;

        dialogFields.front.control.value = cardDraft[code].front;
        dialogFields.back.control.value = cardDraft[code].back;

        updateCardLanguageTabs();
        updateCardPreview();

        var preview = document.querySelector('.card-preview');

        if (preview !== null) {
            preview.classList.remove('is-flipped');
        }

        dialogFields.front.control.focus();
    }

    /* Marks which language is open and which one still needs work. */
    function updateCardLanguageTabs() {
        if (cardDraft === null) {
            return;
        }

        Object.keys(cardTabs).forEach(function (code) {
            var button = cardTabs[code];
            var draft = cardDraft[code];
            var front = draft.front.trim();
            var back = draft.back.trim();
            var filled = front !== '' && back !== '';
            var half = (front !== '') !== (back !== '');
            var mark = button.querySelector('.lang-tab__mark');

            button.classList.toggle('is-active', code === cardTab);
            button.classList.toggle('is-filled', filled);
            button.classList.toggle('is-half', half);
            button.setAttribute('aria-selected', code === cardTab ? 'true' : 'false');
            button.setAttribute(
                'aria-label',
                cardLanguageName(code) + ', ' + t(filled ? 'dialog.card.languageFilled' : 'dialog.card.languageEmpty')
            );

            if (mark !== null) {
                mark.textContent = filled ? '\u2713' : (half ? '\u00b7' : '');
            }
        });
    }

    /* ----------------------------------------------------------------------
       The two actions in the head of an entry
       ---------------------------------------------------------------------- */

    /*
     * Fills the head of the detail view.
     *
     * level      'card' for a subcategory, 'area' for a learning area
     * cardCount  how many cards can be studied (the whole branch for an area)
     * hasEntries whether the list below has rows - if it has none, the empty
     *            state already offers the step of adding one, and a second
     *            button for the same thing would be one too many
     */
    /* ----------------------------------------------------------------------
       Importing cards from a CSV file
       ---------------------------------------------------------------------- */

    /*
     * The import writes into the subcategory that is open, so the button only
     * exists on a subcategory page. The dialog has two steps:
     *
     *   1. choose a file   -> the server reads it and answers with the summary,
     *                         the first rows and every row it cannot import
     *   2. press the button -> the same file is uploaded again, the server checks
     *                         it once more and writes all rows in ONE transaction
     *
     * Nothing is stored before step 2. A file with a single bad row is refused as
     * a whole, and the dialog says so before the button can be pressed at all.
     */
    function openImportDialog(categoryId) {
        dialogKind = 'import';
        dialogEntry = { categoryId: categoryId };
        dialogParentId = categoryId;
        dialogIcon = null;
        dialogUsed = false;
        dialogOpener = document.activeElement;
        dialogFields = {};
        importState = { categoryId: categoryId, file: null, preview: null, ready: false, busy: false };

        elements.dialogFields.textContent = '';
        clearDialogErrors();
        elements.dialogDanger.hidden = true;
        elements.dialogCancel.textContent = t('dialog.cancel');
        elements.dialogSubmit.classList.remove('dialog__button--danger-pill');
        elements.dialogSubmit.textContent = t('dialog.import.submit');
        elements.dialogSubmit.disabled = true;
        elements.dialogTitle.textContent = t('dialog.import.title');
        elements.dialogMessage.hidden = true;

        elements.dialogFields.appendChild(buildImportPanel());

        openDialog();
        importPanel.zone.focus();
    }

    /*
     * The dashed area, the format hint and the link to the sample file.
     *
     * The area is ONE button: a click, Enter and Space open the file chooser, and
     * a file can be dropped on it as well. The file input itself is invisible but
     * real - it is what the browser needs to hand a file over.
     */
    function buildImportPanel() {
        var wrap = el('div', 'import');

        var zone = el('div', 'dropzone');
        zone.tabIndex = 0;
        zone.setAttribute('role', 'button');
        zone.setAttribute('aria-controls', 'import-file');

        var icon = el('span', 'dropzone__icon');
        icon.setAttribute('aria-hidden', 'true');
        icon.innerHTML = UPLOAD_SVG;

        var title = el('p', 'dropzone__title', t('dialog.import.dropTitle'));
        title.setAttribute('data-i18n', 'dialog.import.dropTitle');

        var fileText = el('p', 'dropzone__file');
        fileText.hidden = true;

        var input = el('input', 'import__input');
        input.type = 'file';
        input.id = 'import-file';
        input.accept = '.csv,text/csv';
        input.setAttribute('tabindex', '-1');

        zone.appendChild(icon);
        zone.appendChild(title);
        zone.appendChild(fileText);

        wrap.appendChild(zone);
        wrap.appendChild(input);

        zone.addEventListener('click', function () {
            input.click();
        });

        zone.addEventListener('keydown', function (event) {
            if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                input.click();
            }
        });

        input.addEventListener('change', function () {
            if (input.files && input.files.length > 0) {
                dialogUsed = true;
                chooseImportFile(input.files[0]);
            }
        });

        zone.addEventListener('dragover', function (event) {
            event.preventDefault();
            zone.classList.add('is-dragging');
        });

        zone.addEventListener('dragleave', function () {
            zone.classList.remove('is-dragging');
        });

        zone.addEventListener('drop', function (event) {
            event.preventDefault();
            zone.classList.remove('is-dragging');

            if (event.dataTransfer && event.dataTransfer.files && event.dataTransfer.files.length > 0) {
                dialogUsed = true;
                chooseImportFile(event.dataTransfer.files[0]);
            }
        });

        var format = el('p', 'import__format', t('dialog.import.format'));
        format.setAttribute('data-i18n', 'dialog.import.format');

        var columns = el('code', 'import__columns', t('dialog.import.columns'));

        var languages = el('p', 'import__hint', t('dialog.import.languages'));
        languages.setAttribute('data-i18n', 'dialog.import.languages');

        var limits = el('p', 'import__hint', t('dialog.import.limits', {
            rows: config.limits.importRows,
            size: Math.round(config.limits.importBytes / (1024 * 1024))
        }));

        var sample = el('a', 'import__sample', t('dialog.import.sample'));
        sample.href = config.sampleCsv;
        sample.setAttribute('download', '');
        sample.setAttribute('data-i18n', 'dialog.import.sample');

        /* The answer of the server is put in here: summary, table, error list. */
        var result = el('div', 'import__result');

        wrap.appendChild(format);
        wrap.appendChild(columns);
        wrap.appendChild(languages);
        wrap.appendChild(limits);
        wrap.appendChild(sample);
        wrap.appendChild(result);

        importPanel = {
            zone: zone,
            input: input,
            fileText: fileText,
            result: result
        };

        return wrap;
    }

    /*
     * A file was chosen. The two obvious things are checked here so the answer is
     * immediate; everything else is decided by the server.
     */
    function chooseImportFile(file) {
        var name = String(file.name || '');
        var extension = name.indexOf('.') === -1 ? '' : name.slice(name.lastIndexOf('.') + 1).toLowerCase();

        importPanel.fileText.hidden = false;
        importPanel.fileText.textContent = name;
        importPanel.zone.classList.add('has-file');
        importState.ready = false;
        elements.dialogSubmit.disabled = true;

        if (extension !== 'csv') {
            showImportProblem(t('import.error.file_type'));
            return;
        }

        if (file.size > config.limits.importBytes) {
            showImportProblem(t('import.error.file_too_large'));
            return;
        }

        checkImportFile(file);
    }

    /* One sentence above the drop zone, and no preview below it. */
    function showImportProblem(message) {
        importPanel.result.textContent = '';
        setDialogError(message);
    }

    /* Sends the file for a check and shows what came back. */
    function checkImportFile(file) {
        importState.file = file;
        importState.busy = true;
        setDialogError(null);
        importPanel.result.textContent = '';
        importPanel.result.appendChild(el('p', 'import__checking', t('dialog.import.checking')));

        uploadImport('preview').then(function (result) {
            importState.busy = false;
            importPanel.result.textContent = '';

            if (!result.ok) {
                showImportProblem(importMessage(result.code));
                return;
            }

            importState.preview = result.data;
            renderImportResult(result.data);
        });
    }

    /*
     * The upload itself. The file goes along as a file (multipart), so the server
     * reads it with fgetcsv() - the browser never has to understand CSV.
     */
    function uploadImport(mode) {
        var body = new FormData();
        body.append('mode', mode);
        body.append('category_id', String(importState.categoryId));
        body.append('file', importState.file, importState.file.name);

        return window.fetch(config.endpoints.importCards, { method: 'POST', body: body })
            .then(function (response) {
                return response.json()
                    .catch(function () {
                        return null;
                    })
                    .then(function (payload) {
                        if (response.ok && payload && payload.success === true) {
                            return { ok: true, data: payload.data };
                        }

                        return {
                            ok: false,
                            code: payload && payload.error ? String(payload.error.code) : 'request_failed'
                        };
                    });
            })
            .catch(function () {
                /* The request never reached the server. */
                return { ok: false, code: 'network_error' };
            });
    }

    /* The summary, the first rows and every row that cannot be imported. */
    function renderImportResult(data) {
        importState.ready = false;

        if (data.fatal !== null && data.fatal !== undefined) {
            showImportProblem(importMessage(data.fatal.code, data.fatal.params));
            return;
        }

        var importable = Number(data.importable) || 0;
        var duplicates = Number(data.duplicates) || 0;
        var invalid = Number(data.invalid) || 0;

        importPanel.result.appendChild(el(
            'p',
            importable > 0 ? 'import__summary' : 'import__summary import__summary--quiet',
            importable === 1 ? t('dialog.import.summaryOne') : t('dialog.import.summary', { count: importable })
        ));

        if (duplicates > 0) {
            importPanel.result.appendChild(el('p', 'import__skipped', duplicates === 1
                ? t('dialog.import.duplicatesOne')
                : t('dialog.import.duplicates', { count: duplicates })));
        }

        if (invalid > 0) {
            importPanel.result.appendChild(el('p', 'import__problem', invalid === 1
                ? t('dialog.import.invalidOne')
                : t('dialog.import.invalid', { count: invalid })));

            var list = el('ul', 'import__errors');

            data.errors.forEach(function (entry) {
                list.appendChild(el('li', 'import__error', importRowMessage(entry)));
            });

            importPanel.result.appendChild(list);
            importPanel.result.appendChild(el('p', 'import__fix', t('dialog.import.fix')));
        }

        if (Array.isArray(data.preview) && data.preview.length > 0) {
            importPanel.result.appendChild(buildImportTable(data.preview, Number(data.hidden) || 0));
        }

        /*
         * Only a file without a single bad row and with at least one new card may
         * be imported - and only the button in the footer starts that.
         */
        if (invalid === 0 && importable > 0) {
            importState.ready = true;
            elements.dialogSubmit.disabled = false;
            elements.dialogSubmit.textContent = importable === 1
                ? t('dialog.import.submitOne')
                : t('dialog.import.submitMany', { count: importable });
        }
    }

    /* One row problem as a sentence, with the line number in front. */
    function importRowMessage(entry) {
        var params = entry.params || {};
        var key = 'import.row.' + String(entry.code || '');
        var sentence = t(key, {
            line: entry.line,
            found: params.found,
            expected: params.expected,
            max: params.max,
            value: params.value,
            language: cardLanguageName(params.language === 'en' ? 'en' : 'de')
        });

        /* An unknown code still says something useful. */
        return sentence === key ? t('dialog.import.fix') : sentence;
    }

    /* The first rows of the file, exactly as the server read them. */
    function buildImportTable(rows, hidden) {
        var wrap = el('div', 'import__table-wrap');
        var table = el('table', 'import__table');
        var head = el('thead');
        var headRow = el('tr');

        [
            'dialog.import.colLine',
            'dialog.import.colFrontDe',
            'dialog.import.colBackDe',
            'dialog.import.colFrontEn',
            'dialog.import.colBackEn',
            'dialog.import.colState'
        ].forEach(function (key) {
            var cell = el('th', '', t(key));
            cell.setAttribute('scope', 'col');
            cell.setAttribute('data-i18n', key);
            headRow.appendChild(cell);
        });

        head.appendChild(headRow);
        table.appendChild(head);

        var body = el('tbody');

        rows.forEach(function (row) {
            var state = String(row.state);
            var tr = el('tr', 'import__row import__row--' + state);
            var stateKey = state === 'ok'
                ? 'dialog.import.stateOk'
                : (state === 'duplicate' ? 'dialog.import.stateDuplicate' : 'dialog.import.stateInvalid');

            tr.appendChild(el('td', 'import__line', String(row.line)));
            tr.appendChild(el('td', 'import__text', String(row.front_de || '')));
            tr.appendChild(el('td', 'import__text', String(row.back_de || '')));
            tr.appendChild(el('td', 'import__text', String(row.front_en || '')));
            tr.appendChild(el('td', 'import__text', String(row.back_en || '')));

            var stateCell = el('td', 'import__state');
            var badge = el('span', 'import__badge import__badge--' + state, t(stateKey));
            badge.setAttribute('data-i18n', stateKey);
            stateCell.appendChild(badge);
            tr.appendChild(stateCell);

            body.appendChild(tr);
        });

        table.appendChild(body);
        wrap.appendChild(table);

        if (hidden > 0) {
            wrap.appendChild(el('p', 'import__more', t('dialog.import.andMore', { count: hidden })));
        }

        return wrap;
    }

    /* The button: upload the same file again and let the server write it. */
    function runImport() {
        if (importState === null || importState.ready !== true || importState.busy === true) {
            return;
        }

        importState.busy = true;
        setDialogError(null);
        elements.dialogSubmit.dataset.idleLabel = elements.dialogSubmit.textContent;
        setBusy(true);

        uploadImport('import').then(function (result) {
            importState.busy = false;
            setBusy(false);

            if (!result.ok) {
                showImportProblem(importMessage(result.code));
                return;
            }

            var count = Number(result.data.imported) || 0;

            /* Everything the page shows comes from the API again, so the new
               cards really are in the list. */
            closeDialog();
            responseCache = {};
            render();
            showFeedback(count === 1 ? t('feedback.importedOne') : t('feedback.imported', { count: count }));
        });
    }

    /*
     * A code from the import endpoint as a sentence. The import has its own texts
     * (they say more than the shared ones), and a code it does not know falls
     * back to the sentence every other request uses.
     */
    function importMessage(code, params) {
        var key = 'import.error.' + String(code || '');
        var sentence = t(key, params || {});

        return sentence === key ? errorMessage(code) : sentence;
    }

    /* ----------------------------------------------------------------------
       The map of a card
       ---------------------------------------------------------------------- */

    /*
     * A card may carry a map region: "DE:Bayern", "EU:FR" or "WORLD:CN". The area
     * decides which of three static files is shown, the region is the id of the
     * element inside it that is highlighted.
     *
     * Three rules make this safe and quick:
     *   * the value is checked against the same pattern the API uses. A value that
     *     does not match is ignored, and the card stays a text card.
     *   * the file is an application asset: it is fetched, parsed with DOMParser,
     *     cleaned (no <style>, no <script>, no inline style) and then copied. No
     *     value from the database is ever interpreted as markup - it is only used
     *     to look up one element by its id.
     *   * a file is fetched once per session and reused from memory afterwards,
     *     because the world map alone is about 1.2 MB.
     */
    var MAP_PATTERN = /^(DE|EU|WORLD):[A-Za-z0-9_äöüÄÖÜß-]{1,32}$/;
    var mapDocuments = {};
    var mapRequests = {};
    var dialogMapField = null;

    /* "DE:Bayern" -> { area, region, value }, or null when it is not a valid key. */
    function parseMapRegion(value) {
        if (typeof value !== 'string' || MAP_PATTERN.test(value) !== true) {
            return null;
        }

        var parts = value.split(':');

        return { area: parts[0], region: parts[1], value: value };
    }

    /* A readable name for a country code, in the language of the interface. */
    function countryName(code) {
        try {
            if (typeof window.Intl === 'object' && typeof window.Intl.DisplayNames === 'function') {
                var names = new window.Intl.DisplayNames([locale === 'de' ? 'de' : 'en'], { type: 'region' });
                var name = names.of(code);

                if (typeof name === 'string' && name !== '' && name !== code) {
                    return name;
                }
            }
        } catch (error) {
            /* An unknown code keeps the code itself as its name. */
        }

        return code;
    }

    /* The readable name of a region, for a tooltip or a screen reader. */
    function regionLabel(value) {
        var parsed = parseMapRegion(value);

        if (parsed === null) {
            return '';
        }

        if (parsed.area === 'DE') {
            var found = '';

            config.germanStates.forEach(function (state) {
                if (state.id === parsed.region) {
                    found = t(state.label);
                }
            });

            return found === '' ? parsed.region : found;
        }

        return countryName(parsed.region);
    }

    /*
     * Fetches one map file once and hands back a cleaned, inert SVG element.
     * Nothing here touches the page until the caller puts it somewhere.
     */
    function loadMap(area) {
        if (mapDocuments[area] !== undefined) {
            return Promise.resolve(mapDocuments[area]);
        }

        if (mapRequests[area] === undefined) {
            mapRequests[area] = window.fetch(config.maps[area], { headers: { Accept: 'image/svg+xml' } })
                .then(function (response) {
                    return response.ok ? response.text() : '';
                })
                .then(function (text) {
                    if (text.trim() === '') {
                        mapDocuments[area] = null;

                        return null;
                    }

                    var parsed = new window.DOMParser().parseFromString(text, 'image/svg+xml');
                    var root = parsed.documentElement;

                    if (root === null || String(root.nodeName).toLowerCase() !== 'svg'
                        || parsed.getElementsByTagName('parsererror').length > 0) {
                        mapDocuments[area] = null;

                        return null;
                    }

                    /*
                     * The file may carry its own colours and its own size. Both are
                     * removed: the colours come from the stylesheet of the app, and
                     * the size comes from the box the map is put into.
                     */
                    Array.prototype.forEach.call(root.querySelectorAll('style, script, title, desc'), function (node) {
                        if (node.parentNode !== null) {
                            node.parentNode.removeChild(node);
                        }
                    });

                    Array.prototype.forEach.call(root.querySelectorAll('[style]'), function (node) {
                        node.removeAttribute('style');
                    });

                    root.removeAttribute('style');
                    root.removeAttribute('width');
                    root.removeAttribute('height');
                    root.setAttribute('preserveAspectRatio', 'xMidYMid meet');
                    root.setAttribute('aria-hidden', 'true');
                    root.setAttribute('focusable', 'false');

                    mapDocuments[area] = root;

                    return root;
                })
                .catch(function () {
                    /* No file, no network, bad XML: the card simply stays a text card. */
                    mapDocuments[area] = null;

                    return null;
                });
        }

        return mapRequests[area];
    }

    /*
     * The regions of an area, ready for a select: the id that is stored and the
     * readable name.
     *
     * Germany: the sixteen states from the configuration, because their ids are
     * the ones germany.svg uses. Europe and the world: the two letter ids inside
     * the file, with the name the browser knows for that code.
     */
    function regionsOfArea(area) {
        if (area === 'DE') {
            return Promise.resolve(config.germanStates.map(function (state) {
                return { id: state.id, label: t(state.label) };
            }));
        }

        return loadMap(area).then(function (root) {
            if (root === null) {
                return [];
            }

            var found = [];
            var selector = area === 'WORLD' ? 'g[id]' : 'path[id], g[id]';

            Array.prototype.forEach.call(root.querySelectorAll(selector), function (node) {
                if (/^[A-Z]{2}$/.test(node.id)) {
                    found.push({ id: node.id, label: countryName(node.id) });
                }
            });

            found.sort(function (first, second) {
                return first.label.localeCompare(second.label, locale === 'de' ? 'de' : 'en');
            });

            return found;
        });
    }

    /*
     * One map for one card: a fresh copy of the file with exactly one region
     * marked. The copy is needed because an element can only be in one place, and
     * a list may show the same map several times.
     *
     * The returned element stays hidden while there is nothing to show, so a card
     * without a map (or with a region the file does not know) simply shows its
     * text.
     */
    function buildCardMap(mapRegion, className) {
        var parsed = parseMapRegion(mapRegion);
        var wrap = el('div', className || 'card-map');
        wrap.hidden = true;

        if (parsed === null || config.maps[parsed.area] === undefined) {
            return Promise.resolve(wrap);
        }

        return loadMap(parsed.area).then(function (root) {
            if (root === null) {
                return wrap;
            }

            var svg = root.cloneNode(true);
            var active = null;

            /* Looking the id up by walking the tree: an id may contain characters
               a CSS selector would have to escape. */
            Array.prototype.forEach.call(svg.querySelectorAll('*'), function (node) {
                if (active === null && node.id === parsed.region) {
                    active = node;
                }
            });

            if (active === null) {
                return wrap;
            }

            active.classList.add('is-active');
            wrap.appendChild(svg);
            wrap.hidden = false;
            wrap.dataset.region = parsed.value;
            wrap.setAttribute('title', regionLabel(parsed.value));

            return wrap;
        });
    }

    /* Puts one map into a container, or leaves the container empty. */
    function showMap(container, mapRegion, className) {
        if (container === null) {
            return;
        }

        container.textContent = '';
        container.hidden = true;

        if (parseMapRegion(mapRegion) === null) {
            return;
        }

        buildCardMap(mapRegion, className).then(function (map) {
            if (map.hidden || map.firstChild === null) {
                return;
            }

            /* The inner map moves into the container, so there is no box in a box. */
            container.appendChild(map.firstChild);
            container.hidden = false;
        });
    }

    /* ----------------------------------------------------------------------
       The map field of the card dialog
       ---------------------------------------------------------------------- */

    /*
     * First the area, then the region - nobody has to know an id by heart. The
     * map below the two selects shows the choice straight away, and "no map" is
     * the default: a region is always optional.
     */
    function addMapField(value) {
        var parsed = parseMapRegion(value);
        var wrap = el('div', 'dialog__field dialog__field--map');
        var label = el('p', 'dialog__label', t('dialog.card.mapLabel'));
        label.setAttribute('data-i18n', 'dialog.card.mapLabel');

        var row = el('div', 'map-picker');
        var area = el('select', 'dialog__select');
        var region = el('select', 'dialog__select');

        area.id = 'dialog-field-map-area';
        region.id = 'dialog-field-map-region';
        area.appendChild(new Option(t('dialog.card.mapNone'), ''));
        area.appendChild(new Option(t('dialog.card.mapAreaDe'), 'DE'));
        area.appendChild(new Option(t('dialog.card.mapAreaEu'), 'EU'));
        area.appendChild(new Option(t('dialog.card.mapAreaWorld'), 'WORLD'));

        var note = el('p', 'dialog__hint');
        note.hidden = true;

        var preview = el('div', 'card-map card-map--dialog');
        preview.hidden = true;

        var error = el('p', 'dialog__field-error');
        error.hidden = true;
        error.setAttribute('role', 'alert');

        row.appendChild(area);
        row.appendChild(region);
        wrap.appendChild(label);
        wrap.appendChild(row);
        wrap.appendChild(note);
        wrap.appendChild(preview);
        wrap.appendChild(error);
        elements.dialogFields.appendChild(wrap);

        /* The field is registered like every other one, so clearDialogErrors()
           and setFieldError() work on it. */
        dialogFields.map_region = { control: region, error: error, wrap: wrap };
        dialogMapField = { area: area, region: region, note: note, preview: preview };

        if (parsed !== null) {
            area.value = parsed.area;
        }

        fillRegionOptions(parsed === null ? null : parsed.region);

        area.addEventListener('change', function () {
            dialogUsed = true;
            fillRegionOptions(null);
        });

        region.addEventListener('change', function () {
            dialogUsed = true;
            updateMapPreview();
        });
    }

    /* What the two selects mean together, or null for "no map". */
    function dialogMapValue() {
        if (dialogMapField === null) {
            return null;
        }

        var area = dialogMapField.area.value;
        var region = dialogMapField.region.value;

        if (area === '' || dialogMapField.region.hidden || region === '') {
            return null;
        }

        return area + ':' + region;
    }

    /* Fills the second select for the chosen area and keeps the wanted region. */
    function fillRegionOptions(wanted) {
        var field = dialogMapField;

        if (field === null) {
            return;
        }

        var area = field.area.value;
        field.region.textContent = '';
        field.region.hidden = true;
        field.note.hidden = false;

        if (area === '') {
            field.note.textContent = t('dialog.card.mapChooseArea');
            updateMapPreview();

            return;
        }

        if (area === 'DE') {
            regionsOfArea('DE').then(function (regions) {
                if (dialogMapField !== field) {
                    return;
                }

                regions.forEach(function (item) {
                    field.region.appendChild(new Option(item.label, item.id));
                });

                if (wanted !== null) {
                    field.region.value = wanted;
                }

                field.region.hidden = false;
                field.note.hidden = true;
                updateMapPreview();
            });

            return;
        }

        /* Europe and the world: the ids are inside the file, so it has to be
           loaded - that is the moment the browser shows what it is doing. */
        field.note.textContent = t('dialog.card.mapLoading');

        regionsOfArea(area).then(function (regions) {
            if (dialogMapField !== field) {
                return;
            }

            if (regions.length === 0) {
                field.note.textContent = t('dialog.card.mapUnavailable');
                updateMapPreview();

                return;
            }

            regions.forEach(function (item) {
                field.region.appendChild(new Option(item.label, item.id));
            });

            if (wanted !== null) {
                Array.prototype.forEach.call(field.region.options, function (option) {
                    if (option.value === wanted) {
                        field.region.value = wanted;
                    }
                });
            }

            field.region.hidden = false;
            field.note.hidden = true;
            updateMapPreview();
        });
    }

    /* The map under the two selects shows the region that is chosen right now. */
    function updateMapPreview() {
        if (dialogMapField === null) {
            return;
        }

        showMap(dialogMapField.preview, dialogMapValue(), 'card-map card-map--dialog');
        clearFieldError('map_region');
    }

    function showHeadActions(level, categoryId, cardCount, hasEntries) {
        var isArea = level === 'area';
        var canStudy = typeof cardCount === 'number' && cardCount > 0;

        /*
         * Studying belongs to the card level: a session always runs over the cards
         * of one subcategory. On a learning area there is nothing to study, so the
         * button is not there at all.
         *
         * With no card to study it keeps its place, greyed out, and a tooltip says
         * why.
         */
        elements.learnButton.hidden = isArea;
        elements.learnButton.disabled = !canStudy;
        elements.learnButton.title = canStudy ? '' : t('learn.noCards');
        elements.learnButton.setAttribute('data-i18n-title', canStudy ? '' : 'learn.noCards');
        elements.learnButton.dataset.level = level;
        elements.learnButton.dataset.categoryId = String(categoryId);

        elements.learnLabel.textContent = t('cards.learn');
        elements.learnLabel.setAttribute('data-i18n', 'cards.learn');
        elements.learnButton.setAttribute(
            'aria-label',
            t('cards.learnThis', { name: elements.detailHeading.textContent })
        );

        elements.addEntryButton.hidden = hasEntries !== true;
        elements.addEntryButton.textContent = t(isArea ? 'cards.addSubcategoryShort' : 'cards.addCardShort');
        elements.addEntryButton.setAttribute('data-i18n', isArea ? 'cards.addSubcategoryShort' : 'cards.addCardShort');
        elements.addEntryButton.dataset.level = level;
        elements.addEntryButton.dataset.categoryId = String(categoryId);

        /*
         * Importing is a card-list action: a file of cards belongs to a
         * subcategory, which is the level that owns cards. A learning area only
         * holds subcategories, so the button stays away there.
         */
        elements.importButton.hidden = isArea;
        elements.importButton.dataset.level = level;
        elements.importButton.textContent = t('cards.import');
        elements.importButton.setAttribute('data-i18n', 'cards.import');
        elements.importButton.dataset.categoryId = String(categoryId);
    }

    function wireHeadActions() {
        elements.learnButton.addEventListener('click', function () {
            /* The button only exists in the card view, so it always starts the
               session over the cards of that subcategory. */
            startLearning('all', Number(elements.learnButton.dataset.categoryId), null);
        });

        elements.importButton.addEventListener('click', function () {
            openImportDialog(Number(elements.importButton.dataset.categoryId));
        });

        elements.addEntryButton.addEventListener('click', function () {
            var level = elements.addEntryButton.dataset.level;
            var categoryId = Number(elements.addEntryButton.dataset.categoryId);

            if (level === 'area') {
                openCategoryForm('create', null, categoryId);
                return;
            }

            openCardForm(null, categoryId);
        });
    }

    wireHeadActions();
    init();
})();
