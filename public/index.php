<?php

declare(strict_types=1);

/**
 * Front controller for the learning area browser.
 *
 * Routes:
 *   index.php              -> the learning areas (home)
 *   index.php?category=3   -> what is inside category 3: the subcategories of a
 *                             learning area, or the flashcards of a subcategory
 *
 * This file only builds the HTML shell. It prints every visible string through
 * the translation system and hands the same translations plus the API URLs to
 * JavaScript as JSON. The rows come from the API, so PHP never writes database
 * rows into the markup and nothing that a person typed can end up in the HTML.
 *
 * The API, the services and the database are not touched by this file.
 */

require_once __DIR__ . '/../src/helpers/html.php';
require_once __DIR__ . '/../src/helpers/translations.php';

$translations = learning_app_translations();
$defaultLocale = 'en';

// The id only decides which view is built.
$requestedCategoryId = null;
$rawCategoryId = $_GET['category'] ?? null;

if (is_string($rawCategoryId) && ctype_digit($rawCategoryId) && (int) $rawCategoryId > 0) {
    $requestedCategoryId = (int) $rawCategoryId;
}

// Everything the browser needs. It contains no credentials, no connection
// details and no server file paths.
$appConfig = [
    'endpoints' => [
        'categories' => 'api/categories.php',
        'category' => 'api/category.php',
        'cards' => 'api/cards.php',
        'card' => 'api/card.php',
    ],
    'storageKeys' => [
        'theme' => 'lernkartei.theme',
        'language' => 'lernkartei.language',
    ],
    'limits' => [
        // Must match the limits the API enforces.
        'name' => 100,
        'description' => 1000,
        'cardText' => 2000,
        'iconBytes' => 307200,
    ],
    'defaultLocale' => $defaultLocale,
    'supportedLocales' => array_keys($translations),
    'categoryId' => $requestedCategoryId,
    'translations' => $translations,
];

// Short helper for the template below: default language, already escaped.
$text = fn (string $key): string => escape_html(t($defaultLocale, $key));
?>
<!DOCTYPE html>
<html lang="<?= escape_html($defaultLocale) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $text('app.title') ?></title>
    <!-- The browser tab icon. It is the browser icon file, not a category icon. -->
    <link rel="icon" href="assets/icons/browser_icon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="assets/css/app.css">

    <!--
        Preloaded so the heading face is already there when the first paint
        happens. Without this the heading reveal could start in the fallback font
        and then visibly swap. The path is relative to this file in public/, and
        "crossorigin" is required because fonts are always fetched in CORS mode.
    -->
    <link rel="preload" href="assets/fonts/inter-tight-latin-wght-normal.woff2" as="font" type="font/woff2" crossorigin>

    <script type="application/json" id="app-config"><?= json_encode($appConfig, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?></script>

    <script>
        /*
         * Applies the saved theme before the page is painted, so the browser
         * never shows light colours first and then jumps to dark ones.
         * This has to run inline in the head, because app.js loads too late.
         * The localStorage behaviour is unchanged.
         */
        (function () {
            var storageKey = 'lernkartei.theme';

            try {
                storageKey = JSON.parse(document.getElementById('app-config').textContent).storageKeys.theme;
            } catch (error) {
                /* Keep the key above when the config block cannot be read. */
            }

            var saved = null;

            try {
                saved = window.localStorage.getItem(storageKey);
            } catch (error) {
                /* Private mode can block localStorage; the system setting is used then. */
            }

            if (saved !== 'light' && saved !== 'dark') {
                saved = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches
                    ? 'dark'
                    : 'light';
            }

            document.documentElement.setAttribute('data-theme', saved);
        })();
    </script>
</head>
<body>
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

    <div class="page">
        <header class="site-header">
            <div class="site-header__left">
                <!--
                    Starts empty on both views, and an empty ".crumb" takes no
                    space (see the stylesheet). The home page deliberately shows
                    no label here; on a deeper page app.js fills this with the
                    path, which is navigation.
                -->
                <p class="crumb" id="crumb"></p>
            </div>

            <div class="site-header__right">
                <!--
                    The icon shows what a click will do: the moon in light mode
                    (click -> dark), the sun in dark mode (click -> light).
                    Both are drawn inline with viewBox and currentColor, so the
                    switch depends on no icon file and needs no colour filter.
                    Which one is visible is decided by [data-theme].
                -->
                <button type="button" class="theme-toggle" id="theme-toggle"
                        aria-pressed="false"
                        aria-label="<?= $text('theme.switch.toDark') ?>"
                        data-i18n-label="theme.switch.toDark">
                    <svg class="theme-toggle__icon theme-toggle__icon--light"
                         viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                        <path d="M20.9 14.4A8.5 8.5 0 0 1 9.6 3.1 8.5 8.5 0 1 0 20.9 14.4Z"/>
                    </svg>

                    <svg class="theme-toggle__icon theme-toggle__icon--dark"
                         viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                        <circle class="sun-core" cx="12" cy="12" r="4"/>
                        <path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/>
                    </svg>
                </button>

                <!-- Plain text switch with an underline, not a framed control. -->
                <div class="lang-switch" role="group" aria-label="<?= $text('language.label') ?>" data-i18n-label="language.label">
                    <button type="button" class="lang-switch__option" data-locale="en" data-i18n="language.en"><?= $text('language.en') ?></button>
                    <span class="lang-switch__separator" aria-hidden="true">|</span>
                    <button type="button" class="lang-switch__option" data-locale="de" data-i18n="language.de"><?= $text('language.de') ?></button>
                    <span class="lang-switch__underline" id="lang-underline" aria-hidden="true"></span>
                </div>
            </div>
        </header>

        <noscript>
            <p class="state state--error"><?= $text('state.noscript') ?></p>
        </noscript>

        <main class="main" id="main">
            <!-- Home view -->
            <section class="view view--start" id="view-home">
                <!--
                    The heading stands alone. The counts that used to sit beside
                    it are still in the footer counter, so nothing is lost.
                -->
                <header class="start-header reveal">
                    <div class="start-header__intro">
                        <h1 class="heading" id="home-heading"><?= $text('home.heading') ?></h1>
                    </div>
                </header>

                <p class="state" id="loading-state" data-i18n="state.loading" hidden><?= $text('state.loading') ?></p>
                <p class="state state--error" id="error-state" data-i18n="state.error" hidden><?= $text('state.error') ?></p>

                <div class="notice" id="empty-state" hidden>
                    <h2 class="notice__title" id="empty-title"></h2>
                    <p class="notice__hint" id="empty-hint"></p>
                    <button type="button" class="notice__button" id="empty-action" hidden></button>
                </div>

                <!--
                    The tile row. It is a horizontal scroller: it shows whole
                    tiles only (the count per view lives in the stylesheet) and
                    snaps to a tile, so a tile is never left half cut off. app.js
                    fills the row from the database.
                -->
                <div class="tiles" id="tiles">
                    <div class="area-grid" id="area-grid"></div>
                </div>

                <!--
                    Shown only while the edit mode is on: it says what the menu
                    in the corner of a tile does. The text comes from the
                    translation table, so it follows the language switch.
                -->
                <p class="eyebrow edit-hint" id="edit-hint" role="status" data-i18n="editMode.hint" hidden><?= $text('editMode.hint') ?></p>

                <!--
                    The scroll line of the tile row. It carries the position as
                    well as being the hairline above the footer, so the footer
                    needs none of its own. It is NEVER hidden: while every tile
                    fits it stays in place, greyed out. The two arrows that belong
                    to it sit in the footer, on the left.
                -->
                <div class="tiles-nav" id="tiles-nav">
                    <div class="tiles-nav__track" id="tiles-nav-track">
                        <div class="tiles-nav__thumb" id="tiles-nav-thumb"></div>
                    </div>
                </div>
            </section>

            <!-- Category (detail) view: a learning area or one of its subcategories -->
            <section class="view view--detail" id="view-detail" hidden>
                <aside class="sidebar">
                    <p class="eyebrow" data-i18n="sidebar.label"><?= $text('sidebar.label') ?></p>
                    <nav class="sidebar__nav" id="sidebar-nav"></nav>
                </aside>

                <div class="detail">
                    <!--
                        Heading and the two actions that belong to the entry that
                        is open. They sit NEXT to the heading instead of inside a
                        row or a tile: a row is one link, and a button inside a
                        link cannot be clicked reliably.
                    -->
                    <div class="detail__head">
                        <h1 class="heading" id="detail-heading"></h1>

                        <div class="detail__actions" id="detail-actions" hidden>
                            <button type="button" class="text-button text-button--icon" id="edit-entry">
                                <svg viewBox="0 0 24 24" width="16" height="16" fill="none"
                                     stroke="currentColor" stroke-width="1.5" stroke-linecap="round"
                                     stroke-linejoin="round" focusable="false" aria-hidden="true">
                                    <path d="M4 20h4L20 8l-4-4L4 16v4Z"/>
                                </svg>
                                <span data-i18n="action.edit"><?= $text('action.edit') ?></span>
                            </button>

                            <button type="button" class="text-button text-button--icon text-button--danger" id="delete-entry">
                                <svg viewBox="0 0 24 24" width="16" height="16" fill="none"
                                     stroke="currentColor" stroke-width="1.5" stroke-linecap="round"
                                     stroke-linejoin="round" focusable="false" aria-hidden="true">
                                    <path d="M5 7h14M10 7V5h4v2M7 7l1 13h8l1-13"/>
                                </svg>
                                <span data-i18n="action.delete"><?= $text('action.delete') ?></span>
                            </button>
                        </div>
                    </div>

                    <dl class="stats stats--detail" id="detail-stats">
                        <div class="stats__item stat-pill stats__item--blue">
                            <dt class="stats__label">
                                <span class="stats__dot" id="detail-dot" aria-hidden="true"></span>
                                <span id="detail-stat-label" data-i18n="detail.subareas"><?= $text('detail.subareas') ?></span>
                            </dt>
                            <dd class="stats__value" id="detail-count" aria-live="polite">0</dd>
                        </div>
                    </dl>

                    <!-- The rows of the open entry: subcategories or flashcards. -->
                    <ul class="rows" id="entry-list"></ul>

                    <div class="notice" id="entry-empty" hidden>
                        <h2 class="notice__title" id="entry-empty-title"></h2>
                        <p class="notice__hint" id="entry-empty-hint"></p>
                        <button type="button" class="notice__button" id="entry-empty-action" hidden></button>
                    </div>

                    <!--
                        Cards that sit directly in a learning area (not in one of
                        its subcategories). The page shows this section only when
                        there really are such cards, so the normal case stays a
                        plain list of subcategories.
                    -->
                    <section class="detail__section" id="area-cards" hidden>
                        <h2 class="detail__section-title" data-i18n="cards.sectionTitle"><?= $text('cards.sectionTitle') ?></h2>
                        <ul class="rows" id="area-card-list"></ul>
                    </section>
                </div>
            </section>
        </main>

        <!--
            The same footer on every page. The hairline above it is the scroll
            line of the tile row further up, so there is no <hr> in here, and the
            number of learning areas is no longer shown anywhere.
        -->
        <footer class="site-footer">
            <div class="site-footer__row">
                <!--
                    The two arrows of the tile row, on the left. app.js hides them
                    on every page that has no tile row.
                -->
                <div class="site-footer__nav" id="tiles-nav-buttons" hidden>
                    <button type="button" class="tiles-nav__button" id="tiles-nav-prev"
                            data-i18n-label="scroller.previous">
                        <svg viewBox="0 0 24 24" width="18" height="18" fill="none"
                             stroke="currentColor" stroke-width="1.5" stroke-linecap="round"
                             stroke-linejoin="round" focusable="false" aria-hidden="true">
                            <path d="M14 6l-6 6 6 6"/>
                        </svg>
                    </button>

                    <button type="button" class="tiles-nav__button" id="tiles-nav-next"
                            data-i18n-label="scroller.next">
                        <svg viewBox="0 0 24 24" width="18" height="18" fill="none"
                             stroke="currentColor" stroke-width="1.5" stroke-linecap="round"
                             stroke-linejoin="round" focusable="false" aria-hidden="true">
                            <path d="M10 6l6 6-6 6"/>
                        </svg>
                    </button>
                </div>

                <button type="button" class="plus-button" id="add-button"
                        aria-label="<?= $text('footer.addAria') ?>"
                        data-i18n-label="footer.addAria">
                    <svg class="plus-button__icon" viewBox="0 0 24 24" width="18" height="18" fill="none"
                         stroke="currentColor" stroke-width="1.5" stroke-linecap="round"
                         focusable="false" aria-hidden="true">
                        <path d="M12 5v14M5 12h14"/>
                    </svg>
                </button>

                <button type="button" class="text-button" id="edit-button" data-i18n="footer.edit"><?= $text('footer.edit') ?></button>
            </div>
        </footer>
    </div>

    <!--
        Short confirmation after saving or deleting, announced politely.

        The element is only a frame: the sentence and the optional action are
        written by app.js, so one place in the markup serves both a plain message
        and the message that still offers to take a deletion back.
    -->
    <div class="feedback" id="feedback" role="status" aria-live="polite" hidden>
        <span class="feedback__text" id="feedback-text"></span>
        <button type="button" class="feedback__action" id="feedback-action" hidden></button>
    </div>

    <!--
        One <dialog> per job. A native dialog gives focus trapping, closing with
        Escape and the backdrop without any extra code.
    -->

    <!--
        ONE dialog for every form and every confirmation of this app.

        The fields inside #app-dialog-fields are built by app.js, so a new
        dialog never needs new markup and every dialog shares the same
        behaviour: one open and close path, one validation path, one loading
        state, one error area, one toast and one place that decides where the
        focus goes.
    -->
    <dialog class="dialog" id="app-dialog" aria-labelledby="app-dialog-title">
        <form class="dialog__form" id="app-dialog-form" novalidate>
            <h2 class="dialog__title" id="app-dialog-title"></h2>
            <p class="dialog__message" id="app-dialog-message" hidden></p>
            <div class="dialog__fields" id="app-dialog-fields"></div>
            <p class="dialog__error" id="app-dialog-error" role="alert" hidden></p>
            <div class="dialog__actions">
                <button type="button" class="dialog__button dialog__button--danger" id="app-dialog-danger" hidden></button>
                <button type="button" class="dialog__button--text" id="app-dialog-cancel"></button>
                <button type="submit" class="dialog__button dialog__button--primary" id="app-dialog-submit"></button>
            </div>
        </form>
    </dialog>

    <!--
        Film grain: one fixed layer above everything (z-index 9999) that takes no
        pointer events, so the noise sits over the whole page - colour, tiles and
        text alike - and cannot be clicked, hovered or scrolled.
    -->
    <div class="grain" aria-hidden="true"></div>

    <script src="assets/js/app.js"></script>
</body>
</html>
