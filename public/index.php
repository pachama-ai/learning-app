<?php

declare(strict_types=1);

/**
 * Einstiegspunkt für den Karten-Browser.
 *
 * Adressen:
 *   index.php              -> die Lernbereiche (Startseite)
 *   index.php?category=3   -> was in Kategorie 3 liegt: die Unterkategorien eines
 *                             Lernbereichs oder die Lernkarten einer Unterkategorie
 *
 * Diese Datei baut nur die HTML-Hülle. Jeden sichtbaren Text gibt sie über die
 * Übersetzungen aus und reicht dieselben Übersetzungen samt der API-Adressen als JSON
 * an JavaScript weiter. Die Zeilen kommen aus der API, PHP schreibt also nie
 * Datenbankzeilen ins Markup, und nichts, was eine Person eingetippt hat, kann im HTML
 * landen.
 *
 * Die API, die Services und die Datenbank werden von dieser Datei nicht angefasst.
 */

require_once __DIR__ . '/../src/helpers/html.php';
require_once __DIR__ . '/../src/helpers/translations.php';
require_once __DIR__ . '/../src/services/exercise_service.php';

/*
 * Diese Seite darf der Browser nicht zwischenspeichern. Sie trägt die
 * Übersetzungen und die Versionsnummern der Dateien; eine alte Kopie zeigt
 * genau die alten Texte, obwohl im Code schon neue stehen. Das ist passiert, als
 * die Anmeldung von "Name oder E-Mail" auf "E-Mail" umgestellt wurde: der Server
 * lieferte längst "E-Mail", der Browser zeigte weiter den alten Text.
 */
header('Cache-Control: no-store, must-revalidate');

$translations = learning_app_translations();
$defaultLocale = 'en';

// Die Id entscheidet nur, welche Ansicht gebaut wird.
$requestedCategoryId = null;
$rawCategoryId = $_GET['category'] ?? null;

if (is_string($rawCategoryId) && ctype_digit($rawCategoryId) && (int) $rawCategoryId > 0) {
    $requestedCategoryId = (int) $rawCategoryId;
}

// Alles, was der Browser braucht. Darin stehen keine Zugangsdaten, keine
// Verbindungsdaten und keine Server-Dateipfade.
$appConfig = [
    'endpoints' => [
        'categories' => 'api/categories.php',
        /* Alles, was die erste Ansicht braucht, in einer Antwort. */
        'bootstrap' => 'api/bootstrap.php',
        'category' => 'api/category.php',
        'cards' => 'api/cards.php',
        'card' => 'api/card.php',
        'review' => 'api/review.php',
        'importCards' => 'api/import_cards.php',
        'auth' => 'api/auth.php',
        /* Das Konto selbst: die Form seines Symbols und sein Ende. */
        'account' => 'api/account.php',
        /* Das eine Beispiel, das der Kartendialog zeigt, während eine Aufgabenart
           gewählt wird. Es entsteht auf dem Server, damit Dialog und Karte ihre
           Zahlen nie aus zwei verschiedenen Erzeugern ziehen. */
        'exercisePreview' => 'api/exercise_preview.php',
    ],

    // Die Beispieldatei, die der Importdialog anbietet, und sonst nichts.
    'sampleCsv' => 'assets/samples/cards-import-sample.csv',
    'storageKeys' => [
        'theme' => 'lernkartei.theme',
        'language' => 'lernkartei.language',
    ],
    'limits' => [
        // Muss zu den Grenzen passen, die die API durchsetzt.
        'name' => 100,
        'cardText' => 2000,
        /* Muss zu SVG_MAX_UPLOAD_BYTES auf dem Server passen (350 KB). */
        'iconBytes' => 358400,
        /* Muss zu CARD_IMPORT_MAX_BYTES und CARD_IMPORT_MAX_ROWS in
           src/services/card_import_service.php passen. */
        'importBytes' => 1048576,
        'importRows' => 1000,
    ],
    /*
     * Die drei Kartendateien und die sechzehn deutschen Länder. Die Länder tragen die
     * Id, die germany.svg benutzt (das ist der Wert, den die Datenbank speichert) und
     * einen Übersetzungsschlüssel, damit die Auswahl in beiden Sprachen einen lesbaren
     * Namen anbieten kann. Nirgendwo sonst steht eine zweite Liste von Ids.
     */
    /*
     * Die Kartendateien, für die ein Gebietsname steht. Der Schlüssel ist die erste
     * Hälfte einer map_region, der Wert ist die Datei, die der Browser dafür holt.
     *
     * Diese Liste ist die einzige Quelle für die Auswahl im Kartendialog: angeboten
     * wird genau das, wofür es hier eine Datei gibt. Nimmt man ein Gebiet heraus, wird
     * es einfach nicht mehr angeboten, und eine Karte, die so eine Region noch trägt,
     * behält ihren gespeicherten Wert (siehe dialogMapValue() in app.js).
     */
    'maps' => [
        'DE' => 'assets/maps/germany.svg',
        'EU' => 'assets/maps/europe.svg',
        'WORLD' => 'assets/maps/world.svg',
    ],
    /*
     * Die Aufgabenarten, die eine Übungskarte zeigen kann, direkt aus
     * exercise_catalog(): der Kartendialog darf nur anbieten, was der Erzeuger
     * wirklich kennt. Die Namen sind Übersetzungsschlüssel, wie jeder andere Text
     * dieser Oberfläche.
     */
    'exerciseTypes' => exercise_catalog(),
    'germanStates' => [
        ['id' => 'Baden__x26__Württemberg', 'label' => 'map.state.bw'],
        ['id' => 'Bayern', 'label' => 'map.state.by'],
        ['id' => 'Berlin', 'label' => 'map.state.be'],
        ['id' => 'Brandenburg', 'label' => 'map.state.bb'],
        ['id' => 'Bremen', 'label' => 'map.state.hb'],
        ['id' => 'Hamburg', 'label' => 'map.state.hh'],
        ['id' => 'Hessen', 'label' => 'map.state.he'],
        ['id' => 'Mecklenburg-Vorpommern', 'label' => 'map.state.mv'],
        ['id' => 'Niedersachsen', 'label' => 'map.state.ni'],
        ['id' => 'Nordrhein-Westfalen', 'label' => 'map.state.nw'],
        ['id' => 'Rheinland-Pfalz', 'label' => 'map.state.rp'],
        ['id' => 'Saarland', 'label' => 'map.state.sl'],
        ['id' => 'Sachsen', 'label' => 'map.state.sn'],
        ['id' => 'Sachsen-Anhalt', 'label' => 'map.state.st'],
        ['id' => 'Schleswig-Holstein', 'label' => 'map.state.sh'],
        ['id' => 'Thüringen', 'label' => 'map.state.th'],
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
<!--
    "is-booting" steht von der ersten Zeile an im HTML und NICHT in einem Skript:
    nur so kann das Blatt im Kopf den Seiteninhalt verdecken, bevor der Browser
    ihn zum ersten Mal zeichnet. Ohne JavaScript bliebe der Inhalt verdeckt -
    dafuer sorgt der notnagel weiter unten.
-->
<html lang="<?= escape_html($defaultLocale) ?>" class="is-booting">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $text('app.title') ?></title>
    <!-- The browser tab icon. It is the browser icon file, not a category icon. -->
    <link rel="icon" href="assets/icons/browser_icon.svg" type="image/svg+xml">
    <!--
        Both stylesheets and the script below carry the time of their last change
        as a version (asset_url), so an edit reaches the browser at the next load
        instead of sitting in its cache.
    -->
    <link rel="stylesheet" href="<?= escape_html(asset_url('assets/css/app.css')) ?>">
    <!-- The overlay for the very first load; it owns no other rule. -->
    <link rel="stylesheet" href="<?= escape_html(asset_url('assets/css/boot.css')) ?>">

    <!--
        The one rule that must be there before anything is painted: while the
        loading screen is on, the page underneath stays invisible. It is written
        here and not in a stylesheet because every stylesheet needs a request of
        its own - and in that gap the browser would draw the page, which is the
        flash this is about.

        visibility (not display) on purpose: the layout is complete from the
        first moment, so nothing jumps when the loading screen goes away.
    -->
    <style>
        html.is-booting .site-header,
        html.is-booting .main,
        html.is-booting .site-footer {
            visibility: hidden;
        }
    </style>
    <noscript>
        <!-- Without JavaScript no script can take the loading screen away, so
             it is not shown and the page stands open right away. -->
        <style>
            .boot {
                display: none;
            }

            html.is-booting .site-header,
            html.is-booting .main,
            html.is-booting .site-footer {
                visibility: visible;
            }
        </style>
    </noscript>

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
         * Setzt das gespeicherte Erscheinungsbild, bevor die Seite gezeichnet wird,
         * damit der Browser nie zuerst helle Farben zeigt und dann auf dunkle springt.
         * Das muss inline im Kopf laufen, weil app.js zu spät geladen wird.
         * Am Verhalten von localStorage ändert sich nichts.
         */
        (function () {
            var storageKey = 'lernkartei.theme';

            try {
                storageKey = JSON.parse(document.getElementById('app-config').textContent).storageKeys.theme;
            } catch (error) {
                /* Den Schlüssel oben behalten, wenn sich der Konfigurationsblock
                   nicht lesen lässt. */
            }

            var saved = null;

            try {
                saved = window.localStorage.getItem(storageKey);
            } catch (error) {
                /* Der private Modus kann localStorage sperren; dann gilt die
                   Einstellung des Systems. */
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

    <!--
        The overlay for the very first load. It is shown only when the first view
        takes a moment to appear (see the few lines at its end), and it is hidden
        again as soon as the view is really there.

        It is opaque on purpose: the page colour, the gradient and the three
        light pools stay visible, because the overlay paints the same pools on
        top of its own background (see .boot__bg). The drawing is thin lines in
        currentColor, like every other icon of the application, and it carries
        no shadow.

        The texts are handed to the browser as data attributes instead of being
        written into the script, so the translation stays in one place - the same
        keys the rest of the interface uses.
    -->
    <div class="boot" id="boot-overlay">
        <!--
            The background of the overlay: the very same pools the page itself
            paints (see .bg-layer in the stylesheet). The overlay is opaque, so
            they have to be painted again here - and taking the classes of the
            page means there is only one definition of how they look.
        -->
        <div class="bg-layer boot__bg" aria-hidden="true">
            <span class="bg-blob bg-blob--a"></span>
            <span class="bg-blob bg-blob--b"></span>
            <span class="bg-blob bg-blob--c"></span>
        </div>



        <div class="boot__error" id="boot-error" hidden>
            <p class="boot__text" id="boot-error-text"
               data-text-de="Das dauert länger als erwartet. Bitte noch einmal versuchen."
               data-text-en="This takes longer than expected. Please try again.">This takes longer than expected. Please try again.</p>
            <button type="button" class="boot__retry" id="boot-retry"
                    data-text-de="Erneut versuchen" data-text-en="Try again">Try again</button>
        </div>
    </div>

    <script>
        /*
         * Zeigt und versteckt die Überlagerung oben. Absichtlich ein eigenes kleines
         * Skript und kein Teil von app.js: ein kaputtes Anwendungsskript muss immer
         * noch bei der Meldung mit dem Knopf "Erneut versuchen" enden, nie in einer
         * Animation, die ewig läuft.
         *
         * Die Regeln, alle billig:
         *   - sie steht von der allerersten gezeichneten Zeile an da, die Seite ist
         *     also nie unbedeckt zu sehen - nicht einmal für ein Bild
         *   - sie verschwindet, sobald echter Inhalt da ist, in einem 240 ms langen
         *     Ausblenden
         *   - nichts nach 8 Sekunden heißt: etwas stimmt nicht, dann erscheint die
         *     Meldung mit dem Knopf "Erneut versuchen" - und kommt die Antwort
         *     trotzdem, später, verschwindet die Überlagerung wie gewohnt
         *   - scheitert etwas davon, bleibt die Seite einfach, wie sie ist: die
         *     Überlagerung wird nie gezeigt und nichts blockiert
         */
        (function () {
            /*
             * Der Ladebildschirm des ersten Aufbaus und sonst nichts: ein Klick in der
             * Anwendung zeigt ihn nie wieder, weil die Seite nicht erneut geladen wird.
             *
             * Er verschwindet, sobald die erste Ansicht wirklich steht, in einem 240 ms
             * langen Ausblenden. Nichts nach acht Sekunden heißt: etwas stimmt nicht, und
             * die Meldung mit dem Knopf "Erneut versuchen" erscheint - ein Aufbau, der
             * danach doch gelingt, beendet die Überlagerung trotzdem.
             */
            var overlay = document.getElementById('boot-overlay');

            if (overlay === null) {
                return;
            }

            /* Die Texte folgen der gespeicherten Sprache, wie das Erscheinungsbild
               weiter oben. */
            var language = 'en';

            try {
                language = window.localStorage.getItem('lernkartei.language') === 'de' ? 'de' : 'en';
            } catch (error) {
                /* Der private Modus kann localStorage sperren; Englisch ist die
                   Vorgabe. */
            }

            Array.prototype.forEach.call(document.querySelectorAll('[data-text-' + language + ']'), function (node) {
                node.textContent = node.getAttribute('data-text-' + language);
            });

            /*
             * Das Dokument trägt die Sprache der Seite von PHP aus, und das ist die
             * Vorgabe und nicht die Wahl dieser Person. app.js korrigiert das später -
             * diese Überlagerung ist vorher auf dem Bildschirm, sie sagt es also selbst:
             * eine deutsche Ladezeile gehört in ein deutsches Dokument.
             */
            document.documentElement.setAttribute('lang', language);

            /* Gesetzt, solange die Meldung mit dem Knopf "Erneut versuchen" auf dem
               Bildschirm steht. Das sagt nur "die Meldung wurde gezeigt" und nicht
               "das ist vorbei": ein Aufbau, der danach gelingt, beendet die
               Überlagerung trotzdem. */
            var failed = false;
            var finished = false;

            /*
             * Acht Sekunden ohne erste Ansicht heißen: etwas stimmt nicht. Dann
             * übernimmt die Meldung mit dem Knopf "Erneut versuchen". Sie ist kein
             * Endzustand - eine Antwort, die danach eintrifft, beendet die
             * Überlagerung trotzdem.
             */
            var failTimer = window.setTimeout(function () {
                if (!isReady()) {
                    fail();
                }
            }, 8000);

            /*
             * Fertig heißt: die erste Ansicht steht wirklich da - eine Kachel, eine
             * Zeile, eine Unterkategorie oder der leere Zustand. "Das Dokument ist
             * geladen" reicht NICHT: alle Dateien können da sein, während die erste
             * Antwort von api/bootstrap.php noch unterwegs ist, und das feste Markup
             * der Seite ist ohnehin vom ersten Moment an da.
             *
             * Der Fall "das Anwendungsskript kommt nie so weit" bleibt abgedeckt:
             * die Meldung mit dem Knopf "Erneut versuchen", sobald die drei
             * Durchläufe vorbei sind und nichts zu sehen ist.
             */
            function isReady() {
                /*
                 * Der leere Hinweis (#empty-state auf der Startseite, #entry-empty in
                 * der Detailansicht) steht vom ersten Moment an im Markup, nur
                 * versteckt. Er darf also nur zählen, solange er wirklich gezeigt wird:
                 * ohne das "nicht versteckt" wäre der Ladebildschirm schon weg, bevor
                 * die erste Antwort des Servers eingetroffen ist.
                 */
                return document.querySelector('.area-card, .row--card, .row--category, .learn-stage, .notice:not([hidden])') !== null;
            }

            function hide() {
                if (finished) {
                    return;
                }

                finished = true;
                window.clearTimeout(failTimer);

                /*
                 * Eine ausblendende Überlagerung darf keine Klicks schlucken, und sie
                 * ist auch nicht mehr gescheitert - sie geht gerade weg.
                 */
                overlay.classList.remove('is-failed');
                overlay.classList.add('is-hiding');

                window.setTimeout(function () {
                    overlay.hidden = true;

                    /* Und jetzt darf die Seite darunter gesehen werden. */
                    document.documentElement.classList.remove('is-booting');
                }, 260);
            }

            function fail() {
                if (failed || finished) {
                    return;
                }

                failed = true;
                overlay.classList.add('is-failed');
                /*
                 * Die Ladezeile der älteren Überlagerung ist weg - die Stationen sagen
                 * selbst, was gerade passiert. Sie wird nur versteckt, wenn es sie noch
                 * gibt, damit das hier nie einen Fehler wirft.
                 */
                var loadingLine = document.getElementById('boot-text');

                if (loadingLine !== null) {
                    loadingLine.hidden = true;
                }

                document.getElementById('boot-error').hidden = false;
                document.getElementById('boot-retry').focus();
            }

            /*
             * Jedes Signal "die erste Ansicht ist da" endet hier: sobald die Seite
             * wirklich steht, blendet die Überlagerung weg - sie ist für das Laden da,
             * nicht für eine feste Zahl von Sekunden.
             */
            function onReadySignal() {
                if (!isReady() || finished) {
                    return;
                }

                /* Die Antwort kam nach der Meldung: jetzt wird die Seite gezeigt. */
                if (failed) {
                    hide();

                    return;
                }

                observer.disconnect();
                hide();
            }

            var observer = new MutationObserver(onReadySignal);

            try {
                observer.observe(document.documentElement, { childList: true, subtree: true });
            } catch (error) {
                /* Kein Beobachter: das load-Ereignis weiter unten beendet die
                   Überlagerung trotzdem. */
            }
            document.addEventListener('lernkartei:ready', onReadySignal);

            window.addEventListener('load', function () {
                window.setTimeout(onReadySignal, 40);
            });

            document.getElementById('boot-retry').addEventListener('click', function () {
                window.location.reload();
            });
        })();
    </script>

    <div class="page">
        <header class="site-header">
            <div class="site-header__left">
                <!--
                    The account, as plain text: "Anmelden" while nobody is signed
                    in, otherwise the name of the person and "Abmelden". It stands
                    on the left edge of the content column, on the same line as the
                    moon and the language switch, and app.js fills it from
                    api/auth.php - this file never talks to the database.
                -->
                <div class="account" id="account-slot"></div>

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
        <!--
            One quiet line above the content: "you have been signed out", "your
            account has been deleted". app.js writes it, lets it fade away and
            hides it again; while it is empty it takes no space at all.
        -->
        <p class="page-note" id="page-note" role="status" aria-live="polite" hidden></p>

            <!-- Home view -->
            <section class="view view--start" id="view-home">
                <!--
                    The heading stands alone. The counts that used to sit beside
                    it are gone: the start page shows numbers on the tiles, and a
                    subcategory shows them in the tiles under its head.
                -->
                <header class="start-header reveal" id="home-header">
                    <div class="start-header__intro">
                        <h1 class="heading" id="home-heading"><?= $text('home.heading') ?></h1>
                    </div>
                </header>

                <p class="state" id="loading-state" data-i18n="state.loading" hidden><?= $text('state.loading') ?></p>
                <p class="state state--error" id="error-state" data-i18n="state.error" hidden><?= $text('state.error') ?></p>

                <div class="notice" id="empty-state" hidden>
                    <!--
                        The mark above the sentence: a stack of two cards, drawn
                        inline like every other drawing of the application, with
                        the same thin line (1.5) and in the accent colour of the
                        theme. It only shows what the heading below says, so it is
                        hidden from screen readers.
                    -->
                    <svg class="notice__icon" viewBox="0 0 24 24" width="30" height="30" fill="none"
                         stroke="currentColor" stroke-width="1.5" stroke-linecap="round"
                         stroke-linejoin="round" focusable="false" aria-hidden="true">
                        <rect x="3.5" y="7.5" width="12.5" height="13" rx="3"/>
                        <path d="M8.5 4.5h8a3 3 0 0 1 3 3v9.5"/>
                    </svg>
                    <h2 class="notice__title" id="empty-title"></h2>
                    <p class="notice__hint" id="empty-hint"></p>
                    <!--
                        The ways out. app.js shows one of them: signed in it is the
                        form that creates a learning area, signed out it is the
                        sign-in and the way to an account. The second button stays
                        hidden while somebody is signed in.
                    -->
                    <div class="notice__actions">
                        <button type="button" class="notice__button" id="empty-action" hidden></button>
                        <button type="button" class="notice__button notice__button--quiet"
                                id="empty-action-secondary" hidden></button>
                    </div>
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
                    <!--
                        The head of the open entry: its drawing, its name and its
                        description. The drawing is the same circle a tile uses,
                        so arriving here feels like opening that tile. The colour
                        of the zone comes from the palette position of the
                        learning area (see app.js and the stylesheet).
                    -->
                    <div class="detail__head">
                        <div class="detail__titles">
                            <span class="blob detail__blob" id="detail-blob" aria-hidden="true"></span>
                            <div class="detail__text">
                                <h1 class="heading heading--detail" id="detail-heading"></h1>
                            </div>
                        </div>

                        <!--
                            The actions of this entry. app.js puts the one menu
                            into this container, so a detail view offers exactly
                            the same control as a row or a tile does.
                        -->
                        <div class="detail__actions" id="detail-actions" hidden></div>
                    </div>

                    <!--
                        The tiles of a subcategory: what is due, how much of it
                        already sits, how much is still unsure and how many days in
                        a row somebody studied. They stand directly under the head,
                        before the counts and the buttons, because they belong to
                        the work of this page.

                        app.js fills them from the numbers the API already sends
                        with the card list - nothing here is counted in the
                        browser - and it keeps the whole section away on a learning
                        area, which only holds subcategories and has no cards of
                        its own.
                    -->
                    <section class="dash" id="detail-dashboard" hidden>
                        <div class="dash__tile">
                            <p class="dash__label" data-i18n="dash.due"><?= $text('dash.due') ?></p>
                            <p class="dash__value" id="dash-due-value">&#8211;</p>
                        </div>

                        <div class="dash__tile">
                            <p class="dash__label" data-i18n="dash.known"><?= $text('dash.known') ?></p>
                            <p class="dash__value" id="dash-known-value">&#8211;</p>
                            <div class="dash__track">
                                <span class="dash__track-fill" id="dash-known-fill"></span>
                            </div>
                        </div>

                        <div class="dash__tile dash__tile--unsure">
                            <p class="dash__label" data-i18n="dash.unsure"><?= $text('dash.unsure') ?></p>
                            <p class="dash__value" id="dash-unsure-value">&#8211;</p>
                        </div>

                        <div class="dash__tile">
                            <p class="dash__label" data-i18n="dash.streak"><?= $text('dash.streak') ?></p>
                            <p class="dash__value" id="dash-streak-value">&#8211;</p>
                            <p class="dash__note" id="dash-streak-note" hidden></p>
                        </div>
                    </section>

                    <!--
                        What the entry holds, in one quiet line: the number of
                        subcategories and the number of flashcards. Both are
                        counted by the API, and the wording follows the number.
                    -->
                    <p class="detail__figures" id="detail-stats" hidden>
                        <span class="detail__figure" id="detail-figure-count"><span id="detail-count" aria-live="polite">0</span> <span id="detail-stat-label"></span></span>
                        <span class="detail__figure" id="detail-figure-cards" hidden><span id="detail-card-count">0</span> <span id="detail-card-label"></span></span>
                    </p>

                    <!--
                        One quiet row of actions, directly under the counts: the
                        main action first, then the ways to add something. app.js
                        fills the row for the level that is open:

                          a subcategory  -> "Study", "+ Card" and "Import"
                          a learning area -> "+ Subcategory"

                        A button only appears where it belongs: no "Study" without
                        cards, no "+ Card" while the empty state already offers that
                        step, and no "Import" on a learning area - a file of cards
                        belongs to a subcategory.
                    -->
                    <div class="detail__learn">
                        <button type="button" class="learn-button" id="learn-button" hidden>
                            <span class="learn-button__icon" aria-hidden="true">
                                <svg viewBox="0 0 24 24" width="14" height="14" fill="currentColor" focusable="false">
                                    <path d="M8 5.2v13.6L18.4 12 8 5.2z"/>
                                </svg>
                            </span>
                            <span class="learn-button__label" id="learn-button-label"></span>
                        </button>

                        <button type="button" class="add-entry-button" id="add-entry-button" hidden></button>

                        <button type="button" class="add-entry-button import-button" id="import-button" hidden></button>
                    </div>

                    <!--
                        The search of a card list. The numbers of this list stand in
                        the tiles under the head now, so the only thing left here is
                        the way to look for one card. app.js shows the strip from
                        about fifteen cards on: searching three cards is more work
                        than looking at them, and an empty strip above a list would
                        only take room.
                    -->
                    <div class="card-tools" id="card-tools" hidden>
                        <!--
                            Der Filter der Kartenliste: Alle, Neu, Unsicher, Gewusst.

                            Das sind genau die drei Zustände, die eine Karte wirklich hat
                            (new, unsure, known - siehe review_status_of() in
                            review_service.php); mehr gibt es nicht. Die Beschriftungen sind
                            dieselben Übersetzungsschlüssel wie der Status in der Zeile,
                            damit Chip und Zeile nie zwei verschiedene Wörter zeigen.

                            Gefiltert wird im Browser über die Liste, die schon da ist: kein
                            neuer Aufruf an die API, und der Fortschritt selbst kommt weiter
                            allein vom Server.
                        -->
                        <div class="card-tools__filter" id="card-filter" role="group"
                             data-i18n-label="cards.filter.label" hidden>
                            <button type="button" class="card-filter__chip" data-status="all"
                                    aria-pressed="true" data-i18n="cards.filter.all"></button>
                            <button type="button" class="card-filter__chip" data-status="new"
                                    aria-pressed="false" data-i18n="cards.status.new"></button>
                            <button type="button" class="card-filter__chip" data-status="unsure"
                                    aria-pressed="false" data-i18n="cards.status.unsure"></button>
                            <button type="button" class="card-filter__chip" data-status="known"
                                    aria-pressed="false" data-i18n="cards.status.known"></button>
                        </div>

                        <div class="card-tools__search" id="card-tools-search" hidden>
                            <!--
                                A search field, not a sign-in field - and one the
                                browser is told to keep its hands off:

                                  * no <form> around it, and a name that cannot be
                                    mistaken for a login ("card-search-term", not
                                    "email" or "user"),
                                  * autocomplete="off" for the browsers that honour
                                    it,
                                  * readonly until the field is really used. This is
                                    the part that does the work: Chrome skips
                                    read-only fields when it fills a page in, while
                                    "off" alone is ignored as soon as any sign-in
                                    form exists somewhere in the document - and one
                                    does, the account dialog. app.js drops readonly
                                    again on the first focus or click.
                            -->
                            <input type="search" class="card-tools__search-input" id="card-search"
                                   name="card-search-term" autocomplete="off" autocorrect="off"
                                   autocapitalize="off" spellcheck="false" readonly
                                   data-i18n-placeholder="cards.searchPlaceholder">
                        </div>
                    </div>

                    <p class="state state--quiet" id="card-search-empty" hidden></p>

                    <!-- The rows of the open entry: subcategories or flashcards. -->
                    <ul class="rows" id="entry-list"></ul>

                    <!--
                        The empty state of a list: the circle carries the drawing
                        of the area this page belongs to (or the first letter of
                        its name), then one sentence and one button. app.js fills
                        the circle and the two texts.
                    -->
                    <div class="notice" id="entry-empty" hidden>
                        <span class="blob notice__blob" id="entry-empty-blob" aria-hidden="true"></span>
                        <h2 class="notice__title" id="entry-empty-title"></h2>
                        <!--
                            Only the "this entry no longer exists" notice uses this
                            second line. An empty list shows its one sentence
                            without it, which is why it starts hidden.
                        -->
                        <p class="notice__hint" id="entry-empty-hint" hidden></p>
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
                            <path d="M15 6l-6 6 6 6"/>
                        </svg>
                    </button>

                    <button type="button" class="tiles-nav__button" id="tiles-nav-next"
                            data-i18n-label="scroller.next">
                        <svg viewBox="0 0 24 24" width="18" height="18" fill="none"
                             stroke="currentColor" stroke-width="1.5" stroke-linecap="round"
                             stroke-linejoin="round" focusable="false" aria-hidden="true">
                            <path d="M9 6l6 6-6 6"/>
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
            <!--
                Das X oben rechts ist der Weg aus jedem Dialog heraus; Escape tut
                dasselbe. Einen zweiten Knopf dafuer gibt es bewusst nicht: ein
                Weg, der immer gleich aussieht, statt zwei, die auseinander
                laufen koennen.
            -->
            <button type="button" class="dialog__close" id="app-dialog-close"
                    aria-label="<?= $text('dialog.close') ?>"
                    data-i18n-label="dialog.close">&#215;</button>

            <h2 class="dialog__title" id="app-dialog-title"></h2>
            <p class="dialog__message" id="app-dialog-message" hidden></p>
            <div class="dialog__fields" id="app-dialog-fields"></div>
            <p class="dialog__error" id="app-dialog-error" role="alert" hidden></p>
            <!--
                Nur die Knoepfe, die etwas abschliessen. Loeschen hat seinen
                Platz im Drei-Punkte-Menue seiner Kachel oder Zeile und
                absichtlich nicht in diesem Formular.
            -->
            <div class="dialog__actions" id="app-dialog-actions">
                <!--
                    Die Tastenkuerzel stehen nur im Kartendialog da - nur dort tun
                    sie wirklich etwas. Als Chip links in der Fusszeile, damit sie
                    nicht mit dem Formular mitscrollen.
                -->
                <p class="dialog__shortcuts" id="app-dialog-shortcuts" hidden>
                    <kbd class="dialog__key" data-i18n="dialog.card.shortcutSave"><?= $text('dialog.card.shortcutSave') ?></kbd>
                    <kbd class="dialog__key" data-i18n="dialog.card.shortcutBack"><?= $text('dialog.card.shortcutBack') ?></kbd>
                </p>

                <button type="button" class="dialog__button dialog__button--secondary" id="app-dialog-save-next" hidden></button>
                <button type="submit" class="dialog__button dialog__button--primary" id="app-dialog-submit"></button>
            </div>
        </form>
    </dialog>

    <!--
        The account: ONE window with TWO steps.

        The first step shows what the account is made of and carries the way to
        delete it at its foot. That way switches this window to the second step
        instead of opening a second one, so nobody ever faces two questions at
        once - and going back is a click on "Cancel", not a closed window.

        It is a native <dialog> like the form dialog above, which is what gives it
        the focus trap, Escape and the darkened backdrop without extra code. The
        fields inside are built by app.js: the list from the account data, the
        wording from the translations.
    -->
    <dialog class="account-dialog" id="account-dialog" aria-labelledby="account-dialog-title">
        <div class="account-dialog__panel">
            <button type="button" class="account-dialog__close" id="account-dialog-close"
                    aria-label="<?= $text('account.close') ?>" data-i18n-label="account.close">&#215;</button>

            <h2 class="account-dialog__title" id="account-dialog-title"
                data-i18n="account.title"><?= $text('account.title') ?></h2>

            <!-- Step one: the account, and the end of it. -->
            <div class="account-dialog__view" id="account-view-data">
                <dl class="account-dialog__list" id="account-list"></dl>

                <div class="account-dialog__danger">
                    <h3 class="account-dialog__subtitle" data-i18n="account.deleteTitle"><?= $text('account.deleteTitle') ?></h3>
                    <p class="account-dialog__hint" data-i18n="account.deleteHint"><?= $text('account.deleteHint') ?></p>
                    <button type="button" class="account-dialog__text-button" id="account-delete-open"
                            data-i18n="account.deleteSubmit"><?= $text('account.deleteSubmit') ?></button>
                </div>
            </div>

            <!-- Step two: the password, and the last question. -->
            <div class="account-dialog__view" id="account-view-confirm" hidden>
                <p class="account-dialog__warning" data-i18n="account.deleteWarning"><?= $text('account.deleteWarning') ?></p>

                <div class="account-dialog__field">
                    <label class="account-dialog__label" for="account-password"
                           data-i18n="auth.password"><?= $text('auth.password') ?></label>
                    <input class="account-dialog__input" id="account-password" name="password"
                           type="password" autocomplete="current-password">
                </div>

                <p class="account-dialog__error" id="account-error" role="alert" hidden></p>

                <div class="account-dialog__actions">
                    <button type="button" class="account-dialog__button" id="account-cancel"
                            data-i18n="dialog.cancel"><?= $text('dialog.cancel') ?></button>
                    <button type="button" class="account-dialog__button account-dialog__button--danger" id="account-confirm"
                            data-i18n="account.deleteConfirm"><?= $text('account.deleteConfirm') ?></button>
                </div>
            </div>
        </div>
    </dialog>

    <!--
        The one question before a session starts: how many new cards should this
        one introduce. A window of its own and not the form dialog, because it is
        not a form - it is one number, four shortcuts and two ways out.

        The hint and the shortcuts are written by app.js: how many new cards are
        really there is known only after the session was asked for its queue.
    -->
    <dialog class="count-dialog" id="new-cards-dialog" aria-labelledby="new-cards-dialog-title">
        <div class="count-dialog__panel">
            <button type="button" class="count-dialog__close" id="new-cards-dialog-close"
                    aria-label="<?= $text('dialog.close') ?>"
                    data-i18n-label="dialog.close">&#215;</button>

            <h2 class="count-dialog__title" id="new-cards-dialog-title"
                data-i18n="learn.newCardsTitle"><?= $text('learn.newCardsTitle') ?></h2>

            <p class="count-dialog__hint" id="new-cards-dialog-hint"></p>

            <div class="count-dialog__quick" id="new-cards-dialog-quick"></div>

            <div class="count-dialog__field">
                <label class="count-dialog__label" for="new-cards-dialog-input"
                       data-i18n="learn.newCardsField"><?= $text('learn.newCardsField') ?></label>
                <input class="count-dialog__input" id="new-cards-dialog-input" name="new-cards-count"
                       type="number" min="0" step="1" inputmode="numeric" autocomplete="off">
            </div>

            <p class="count-dialog__error" id="new-cards-dialog-error" role="alert" hidden></p>

            <div class="count-dialog__actions">
                <button type="button" class="count-dialog__button" id="new-cards-dialog-cancel"
                        data-i18n="dialog.cancel"><?= $text('dialog.cancel') ?></button>
                <button type="button" class="count-dialog__button count-dialog__button--primary"
                        id="new-cards-dialog-start"
                        data-i18n="learn.newCardsStart"><?= $text('learn.newCardsStart') ?></button>
            </div>
        </div>
    </dialog>

    <!--
        The full screen study session.

        It is not a <dialog>: a session is a view of its own and not a question
        waiting for an answer, and it must cover the header, the sidebar and the
        footer completely. Escape is handled in app.js, because the session may
        have to ask first when answers were already saved.

        The card itself is one element with two faces: the front is the question,
        the back is the answer, and the flip is a rotation around the vertical
        axis. The order of the two faces is what the stylesheet puts behind each
        other; the text is written by app.js.
    -->
    <div class="learn" id="learn" hidden>
        <div class="learn__progress" id="learn-progress" role="progressbar"
             aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">
            <span class="learn__progress-fill" id="learn-progress-fill"></span>
        </div>

        <div class="learn__top">
            <button type="button" class="learn__close" id="learn-close"
                    aria-label="<?= $text('learn.close') ?>" data-i18n-label="learn.close">
                <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor"
                     stroke-width="1.6" stroke-linecap="round" focusable="false" aria-hidden="true">
                    <path d="M6 6l12 12M18 6L6 18"/>
                </svg>
            </button>

            <p class="learn__counter" id="learn-counter" aria-live="polite"></p>
        </div>

        <div class="learn__stage" id="learn-stage">
            <div class="learn__card" id="learn-card" tabindex="0" role="group">
                <!--
                    Both faces can carry the map of the card: the question shows it
                    without a marking and the answer shows it with the region marked,
                    so turning the card is what reveals the answer. app.js fills both
                    sides and leaves them empty for a card without a region.
                -->
                <div class="learn__face learn__face--front">
                    <p class="learn__label" id="learn-side-label"><?= $text('learn.question') ?></p>
                    <p class="learn__text" id="learn-front-text"></p>
                    <div class="card-map card-map--learn" id="learn-map-front" hidden></div>
                </div>

                <div class="learn__face learn__face--back">
                    <p class="learn__label" data-i18n="learn.answer"><?= $text('learn.answer') ?></p>
                    <p class="learn__text" id="learn-back-text"></p>
                    <div class="card-map card-map--learn" id="learn-map-back" hidden></div>
                </div>

                <p class="learn__hint" id="learn-hint" data-i18n="learn.flipHint"><?= $text('learn.flipHint') ?></p>
            </div>

            <!--
                The four answers. They keep their place from the first moment, so
                nothing jumps when the card is flipped; before that they are
                disabled and out of reach for a screen reader.
            -->
            <div class="learn__rating" id="learn-rating">
                <p class="learn__rating-label" id="learn-rating-label" data-i18n="learn.ratingLabel"><?= $text('learn.ratingLabel') ?></p>
                <div class="learn__buttons" id="learn-buttons" role="group" aria-labelledby="learn-rating-label"></div>
            </div>
        </div>

        <!-- The quiet end of a session: one number, four bars, two ways on. -->
        <div class="learn__summary" id="learn-summary" hidden>
            <h2 class="learn__summary-title" data-i18n="learn.done.title"><?= $text('learn.done.title') ?></h2>
            <p class="learn__summary-number" id="learn-summary-number"></p>
            <ul class="learn__bars" id="learn-summary-bars"></ul>
            <p class="learn__summary-left" id="learn-summary-left" hidden></p>

            <div class="learn__summary-actions">
                <button type="button" class="learn__action learn__action--ghost" id="learn-repeat"
                        data-i18n="learn.done.repeat"><?= $text('learn.done.repeat') ?></button>
                <button type="button" class="learn__action learn__action--primary" id="learn-finish"
                        data-i18n="learn.done.finish"><?= $text('learn.done.finish') ?></button>
            </div>
        </div>

        <!-- The one question the session asks: leave, or keep going? -->
        <div class="learn__ask" id="learn-ask" hidden>
            <div class="learn__ask-box" role="alertdialog" aria-labelledby="learn-ask-title">
                <h2 class="learn__ask-title" id="learn-ask-title" data-i18n="learn.askEnd.title"><?= $text('learn.askEnd.title') ?></h2>
                <p class="learn__ask-text" id="learn-ask-text"></p>

                <div class="learn__ask-actions">
                    <button type="button" class="learn__action learn__action--ghost" id="learn-ask-cancel"
                            data-i18n="learn.askEnd.cancel"><?= $text('learn.askEnd.cancel') ?></button>
                    <button type="button" class="learn__action learn__action--danger" id="learn-ask-confirm"
                            data-i18n="learn.askEnd.confirm"><?= $text('learn.askEnd.confirm') ?></button>
                </div>
            </div>
        </div>

        <p class="learn__notice" id="learn-notice" role="status" aria-live="polite" hidden></p>
    </div>

    <script src="<?= escape_html(asset_url('assets/js/app.js')) ?>"></script>
</body>
</html>
