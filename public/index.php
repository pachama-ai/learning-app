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

// Kurze Hilfe für die Vorlage unten: Standardsprache, schon maskiert.
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
    <!-- Das Symbol im Browserreiter. Das ist die Symboldatei des Browsers, kein Kategoriesymbol. -->
    <link rel="icon" href="assets/icons/browser_icon.svg" type="image/svg+xml">
    <!--
        Beide Stylesheets und das Skript unten tragen den Zeitpunkt ihrer letzten Änderung
        als Version (asset_url), damit eine Änderung beim nächsten Laden im Browser ankommt
        und nicht in seinem Zwischenspeicher liegen bleibt.
    -->
    <link rel="stylesheet" href="<?= escape_html(asset_url('assets/css/app.css')) ?>">
    <!-- Die Überlagerung für den allerersten Aufbau; sie hat keine weitere Regel. -->
    <link rel="stylesheet" href="<?= escape_html(asset_url('assets/css/boot.css')) ?>">

    <!--
        Die eine Regel, die vor dem ersten Zeichnen da sein muss: solange der
        Ladebildschirm läuft, bleibt die Seite darunter unsichtbar. Sie steht hier
        und nicht im Stylesheet, weil jedes Stylesheet eine eigene Anfrage braucht -
        und in dieser Lücke würde der Browser die Seite zeichnen, und genau das ist
        das kurze Aufblitzen, um das es geht.

        visibility (nicht display) mit Absicht: das Layout steht vom ersten
        Augenblick an, damit nichts springt, wenn der Ladebildschirm verschwindet.
    -->
    <style>
        html.is-booting .site-header,
        html.is-booting .main,
        html.is-booting .site-footer {
            visibility: hidden;
        }
    </style>
    <noscript>
        <!-- Ohne JavaScript kann kein Skript den Ladebildschirm wegräumen, deshalb
             wird er nicht gezeigt und die Seite steht sofort offen. -->
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
        Vorab geladen, damit die Schrift der Überschrift schon da ist, wenn das erste
        Zeichnen passiert. Sonst begänne das Einblenden der Überschrift in der
        Ersatzschrift und würde sichtbar umspringen. Der Pfad ist relativ zu dieser
        Datei in public/, und "crossorigin" ist nötig, weil Schriften immer im
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
        Drei weiche Lichtflächen, jede ein breiter, weichgezeichneter Farbverlauf.
        Sie haben keinen sichtbaren Rand: nichts hier hat eine Umrisslinie, und
        a hard border.

        Die Ebene liegt mit Absicht AUSSERHALB von .page und klebt am Fenster,
        damit eine Lichtfläche über die Inhaltsspalte und über die Fensterränder
        hinauslaufen kann, ohne eine harte senkrechte Kante zu hinterlassen. Sie
        behält z-index -1, also über der Seitenfarbe und unter allem echten Inhalt,
        damit keine Lichtfläche hinter einer Kachel, einer Überschrift oder sonstigem

        Nur Zierde - nichts hier ist anklickbar oder wird vorgelesen.
    -->
    <div class="bg-layer" aria-hidden="true">
        <span class="bg-blob bg-blob--a"></span>
        <span class="bg-blob bg-blob--b"></span>
        <span class="bg-blob bg-blob--c"></span>
    </div>

    <!--
        Die Überlagerung für den allerersten Aufbau. Sie erscheint nur, wenn die erste
        Ansicht einen Augenblick braucht (siehe die paar Zeilen an ihrem Ende), und
        verschwindet wieder, sobald die Ansicht wirklich steht.

        Sie ist absichtlich deckend: die Seitenfarbe, der Verlauf und die drei
        Lichtflächen bleiben sichtbar, weil die Überlagerung dieselben Flächen
        über ihren eigenen Hintergrund malt (siehe .boot__bg). Die Zeichnung besteht
        aus dünnen Linien in currentColor, wie jedes andere Symbol der Anwendung,
        und sie wirft keinen Schatten.

        Die Texte gehen als Datenattribute an den Browser, statt in das Skript
        geschrieben zu werden, damit die Übersetzung an einer Stelle bleibt - dieselben
        Schlüssel, die die übrige Oberfläche benutzt.
    -->
    <div class="boot" id="boot-overlay">
        <!--
            Der Hintergrund der Überlagerung: dieselben Lichtflächen, die die Seite
            selbst malt (siehe .bg-layer im Stylesheet). Die Überlagerung ist deckend,
            deshalb müssen sie hier erneut gemalt werden - und weil dieselben Klassen
            wie auf der Seite benutzt werden, gibt es nur eine Festlegung ihres Aussehens.
        -->
        <div class="bg-layer boot__bg" aria-hidden="true">
            <span class="bg-blob bg-blob--a"></span>
            <span class="bg-blob bg-blob--b"></span>
            <span class="bg-blob bg-blob--c"></span>
        </div>



        <div class="boot__error" id="boot-error" hidden>
            <p class="boot__text" id="boot-error-text"
               data-text-de="Das dauert länger als erwartet. Bitte noch einmal versuchen."
               data-text-en="This takes longer than expected. Please try again.">Das dauert länger als erwartet. Bitte noch einmal versuchen.</p>
            <button type="button" class="boot__retry" id="boot-retry"
                    data-text-de="Erneut versuchen" data-text-en="Try again">Erneut versuchen</button>
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
                    Das Konto als reiner Text: "Anmelden", solange niemand angemeldet ist,
                    sonst der Name der Person und "Abmelden". Es steht am linken Rand
                    der Inhaltsspalte, in derselben Zeile wie der Mond und der
                    Sprachumschalter, und app.js füllt es aus api/auth.php - diese
                    Datei spricht nie mit der Datenbank.
                -->
                <div class="account" id="account-slot"></div>

                <!--
                    Startet in beiden Ansichten leer, und ein leeres ".crumb" braucht keinen
                    Platz (siehe Stylesheet). Die Startseite zeigt hier mit Absicht
                    keine Beschriftung; auf einer tieferen Seite füllt app.js den Pfad ein,
                    und das ist Navigation.
                -->
                <p class="crumb" id="crumb"></p>
            </div>

            <div class="site-header__right">
                <!--
                    Das Symbol zeigt, was ein Klick bewirkt: der Mond im hellen Modus
                    (Klick -> dunkel), die Sonne im dunklen Modus (Klick -> hell).
                    Beide sind direkt mit viewBox und currentColor gezeichnet, der Wechsel
                    hängt also an keiner Symboldatei und braucht keinen Farbfilter.
                    Welches zu sehen ist, entscheidet [data-theme].
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

                <!-- Ein reiner Textumschalter mit Unterstrich, kein gerahmtes Bedienelement. -->
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
            Eine leise Zeile über dem Inhalt: "du wurdest abgemeldet", "deine
            Konto wurde gelöscht". app.js schreibt sie, lässt sie ausblenden und
            versteckt sie wieder; solange sie leer ist, braucht sie keinen Platz.
        -->
        <p class="page-note" id="page-note" role="status" aria-live="polite" hidden></p>

            <!-- Home view -->
            <section class="view view--start" id="view-home">
                <!--
                    Die Überschrift steht allein. Die Zahlen, die früher daneben standen,
                    sind weg: die Startseite zeigt die Zahlen auf den Kacheln, und eine
                    Unterkategorie zeigt sie in den Kacheln unter ihrem Kopf.
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
                        Das Zeichen über dem Satz: ein Stapel aus zwei Karten, direkt
                        gezeichnet wie jede andere Zeichnung der Anwendung, mit derselben
                        dünnen Linie (1.5) und in der Akzentfarbe des Themas. Es zeigt nur,
                        was die Überschrift darunter sagt, deshalb ist es für Vorlesehilfen
                        versteckt.
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
                        Die Wege hinaus. app.js zeigt einen davon: angemeldet ist es das
                        Formular, das einen Lernbereich anlegt, abgemeldet sind es die
                        Anmeldung und der Weg zu einem Konto. Der zweite Knopf bleibt
                        versteckt, solange jemand angemeldet ist.
                    -->
                    <div class="notice__actions">
                        <button type="button" class="notice__button" id="empty-action" hidden></button>
                        <button type="button" class="notice__button notice__button--quiet"
                                id="empty-action-secondary" hidden></button>
                    </div>
                </div>

                <!--
                    Die Kachelreihe. Sie ist ein waagerechter Scroller: sie zeigt nur
                    ganze Kacheln (wie viele je Ansicht steht im Stylesheet) und
                    rastet auf eine Kachel ein, damit keine halb abgeschnitten stehen
                    bleibt. app.js füllt die Reihe aus der Datenbank.
                -->
                <div class="tiles" id="tiles">
                    <div class="area-grid" id="area-grid"></div>
                </div>

                <!--
                    Die Scrolllinie der Kachelreihe. Sie trägt die Position und ist
                    zugleich die feine Linie über der Fußzeile, deshalb braucht die
                    Fußzeile keine eigene. Sie ist NIE versteckt: solange alle Kacheln
                    passen, bleibt sie ausgegraut an ihrem Platz. Die beiden Pfeile,
                    die dazugehören, sitzen in der Fußzeile links.
                -->
                <div class="tiles-nav" id="tiles-nav">
                    <div class="tiles-nav__track" id="tiles-nav-track">
                        <div class="tiles-nav__thumb" id="tiles-nav-thumb"></div>
                    </div>
                </div>
            </section>

            <!-- Kategorie-Ansicht: ein Lernbereich oder eine seiner Unterkategorien -->
            <section class="view view--detail" id="view-detail" hidden>
                <aside class="sidebar">
                    <p class="eyebrow" data-i18n="sidebar.label"><?= $text('sidebar.label') ?></p>
                    <nav class="sidebar__nav" id="sidebar-nav"></nav>
                </aside>

                <div class="detail">
                    <!--
                        Überschrift und die beiden Aktionen, die zum offenen Eintrag
                        gehören. Sie stehen NEBEN der Überschrift statt in einer Zeile
                        oder einer Kachel: eine Zeile ist ein Link, und ein Knopf in einem
                        Link lässt sich nicht zuverlässig anklicken.
                    -->
                    <!--
                        Der Kopf des offenen Eintrags: seine Zeichnung, sein Name und seine
                        Beschreibung. Die Zeichnung ist derselbe Kreis wie auf einer Kachel,
                        damit das Ankommen hier wie das Öffnen dieser Kachel wirkt. Die
                        Farbe der Zone kommt von der Position des Lernbereichs in der
                        Farbpalette (siehe app.js und das Stylesheet).
                    -->
                    <div class="detail__head">
                        <div class="detail__titles">
                            <span class="blob detail__blob" id="detail-blob" aria-hidden="true"></span>
                            <div class="detail__text">
                                <h1 class="heading heading--detail" id="detail-heading"></h1>
                            </div>
                        </div>

                        <!--
                            Die Aktionen dieses Eintrags. app.js setzt das eine Menü in diesen
                            Kasten, damit die Detailansicht genau dasselbe Bedienelement
                            anbietet wie eine Zeile oder eine Kachel.
                        -->
                        <div class="detail__actions" id="detail-actions" hidden></div>
                    </div>

                    <!--
                        Die Kacheln einer Unterkategorie: was ansteht, wie viel davon schon
                        sitzt, wie viel noch unsicher ist und wie viele Tage in Folge
                        gelernt wurde. Sie stehen direkt unter dem Kopf, vor den Zahlen und
                        den Knöpfen, weil sie zur Arbeit dieser Seite gehören.

                        app.js füllt sie aus den Zahlen, die die Schnittstelle ohnehin mit
                        der Kartenliste schickt - nichts davon wird im Browser gezählt -
                        und in einem Lernbereich lässt er den ganzen Abschnitt weg, denn
                        der enthält nur Unterkategorien und hat keine eigenen Karten.
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
                        Was der Eintrag enthält, in einer leisen Zeile: die Zahl der
                        Unterkategorien und die Zahl der Karteikarten. Beides zählt die
                        Schnittstelle, und die Formulierung folgt der Zahl.
                    -->
                    <p class="detail__figures" id="detail-stats" hidden>
                        <span class="detail__figure" id="detail-figure-count"><span id="detail-count" aria-live="polite">0</span> <span id="detail-stat-label"></span></span>
                        <span class="detail__figure" id="detail-figure-cards" hidden><span id="detail-card-count">0</span> <span id="detail-card-label"></span></span>
                    </p>

                    <!--
                        Eine leise Reihe von Aktionen, direkt unter den Zahlen: zuerst die
                        Hauptaktion, dann die Wege, etwas hinzuzufügen. app.js füllt die
                        Reihe für die Ebene, die offen ist:

                          eine Unterkategorie -> "Lernen", "+ Karte" und "Import"
                          a learning area -> "+ Subcategory"

                        Ein Knopf erscheint nur, wo er hingehört: kein "Lernen" ohne
                        Karten, kein "+ Karte", solange der leere Zustand diesen Schritt
                        schon anbietet, und kein "Import" in einem Lernbereich - eine
                        Datei mit Karten gehört zu einer Unterkategorie.
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
                        Die Suche einer Kartenliste. Die Zahlen dieser Liste stehen jetzt
                        in den Kacheln unter dem Kopf, hier bleibt also nur der Weg, eine
                        Karte zu suchen. app.js zeigt die Leiste ab ungefähr fünfzehn
                        Karten: drei Karten zu durchsuchen ist mehr Arbeit, als sie
                        anzusehen, und eine leere Leiste über einer Liste würde nur Platz
                        wegnehmen.
                    -->
                    <div class="card-tools" id="card-tools" hidden>
                        <!--
                            Der Filter der Kartenliste: Alle, Neu, Unsicher, Gewusst.

                            Das sind genau die drei Zustände, die eine Karte wirklich hat
                            (neu, unsicher, gewusst - siehe review_status_of() in
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
                                Ein Suchfeld, kein Anmeldefeld - und eines, von dem der Browser
                                die Finger lassen soll:

                                  * kein <form> darum, und ein Name, der nicht mit einer Anmeldung
                                    verwechselt werden kann ("card-search-term", nicht
                                    "email" oder "user"),
                                  * autocomplete="off" für die Browser, die sich daran halten,
                                  * readonly, bis das Feld wirklich benutzt wird. Das ist der Teil,
                                    der die Arbeit macht: Chrome überspringt schreibgeschützte
                                    Felder, wenn es eine Seite ausfüllt, während "off" allein
                                    ignoriert wird, sobald irgendwo im Dokument ein Anmeldeformular
                                    steht - und eines steht dort, der Kontodialog. app.js nimmt
                                    readonly beim ersten Fokus oder Klick wieder weg.
                            -->
                            <input type="search" class="card-tools__search-input" id="card-search"
                                   name="card-search-term" autocomplete="off" autocorrect="off"
                                   autocapitalize="off" spellcheck="false" readonly
                                   data-i18n-placeholder="cards.searchPlaceholder">
                        </div>
                    </div>

                    <p class="state state--quiet" id="card-search-empty" hidden></p>

                    <!-- Die Zeilen des offenen Eintrags: Unterkategorien oder Karteikarten. -->
                    <ul class="rows" id="entry-list"></ul>

                    <!--
                        Der leere Zustand einer Liste: der Kreis trägt die Zeichnung des
                        Bereichs, zu dem diese Seite gehört (oder den ersten Buchstaben
                        seines Namens), dann ein Satz und ein Knopf. app.js füllt den
                        Kreis und die beiden Texte.
                    -->
                    <div class="notice" id="entry-empty" hidden>
                        <span class="blob notice__blob" id="entry-empty-blob" aria-hidden="true"></span>
                        <h2 class="notice__title" id="entry-empty-title"></h2>
                        <!--
                            Nur der Hinweis "diesen Eintrag gibt es nicht mehr" benutzt diese
                            zweite Zeile. Eine leere Liste zeigt ihren einen Satz ohne sie,
                            deshalb startet sie versteckt.
                        -->
                        <p class="notice__hint" id="entry-empty-hint" hidden></p>
                        <button type="button" class="notice__button" id="entry-empty-action" hidden></button>
                    </div>

                    <!--
                        Karten, die direkt in einem Lernbereich liegen (nicht in einer
                        seiner Unterkategorien). Die Seite zeigt diesen Abschnitt nur,
                        wenn es solche Karten wirklich gibt, damit der Normalfall eine
                        schlichte Liste von Unterkategorien bleibt.
                    -->
                    <section class="detail__section" id="area-cards" hidden>
                        <h2 class="detail__section-title" data-i18n="cards.sectionTitle"><?= $text('cards.sectionTitle') ?></h2>
                        <ul class="rows" id="area-card-list"></ul>
                    </section>
                </div>
            </section>
        </main>

        <!--
            Dieselbe Fußzeile auf jeder Seite. Die feine Linie darüber ist die
            Scrolllinie der Kachelreihe weiter oben, deshalb steht hier kein <hr>,
            und die Zahl der Lernbereiche wird nirgends mehr gezeigt.
        -->
        <footer class="site-footer">
            <div class="site-footer__row">
                <!--
                    Die beiden Pfeile der Kachelreihe, links. app.js versteckt sie auf
                    jeder Seite, die keine Kachelreihe hat.
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
        Kurze Bestätigung nach dem Speichern oder Löschen, höflich angekündigt.

        Das Element ist nur der Rahmen: den Satz und die freiwillige Aktion
        schreibt app.js, damit eine Stelle im Markup sowohl eine schlichte Meldung
        als auch die Meldung bedient, die ein Löschen noch zurücknehmen lässt.
    -->
    <div class="feedback" id="feedback" role="status" aria-live="polite" hidden>
        <span class="feedback__text" id="feedback-text"></span>
        <button type="button" class="feedback__action" id="feedback-action" hidden></button>
    </div>

    <!--
        Ein <dialog> je Aufgabe. Ein eingebauter Dialog bringt Fokusfang,
        Schließen mit Escape und den verdunkelten Hintergrund ohne eigenen Code mit.
    -->

    <!--
        EIN Dialog für jedes Formular und jede Bestätigung dieser Anwendung.

        Die Felder in #app-dialog-fields baut app.js, deshalb braucht ein neuer
        Dialog nie neues Markup und jeder Dialog teilt dasselbe Verhalten:
        einen Weg zum Öffnen und Schließen, einen Weg zum Prüfen, einen
        Ladezustand, einen Fehlerbereich, eine Einblendung und eine Stelle,
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
        Das Konto: EIN Fenster mit ZWEI Schritten.

        Der erste Schritt zeigt, woraus das Konto besteht, und trägt den Weg zum
        Löschen an seinem Fuß. Dieser Weg schaltet dieses Fenster auf den zweiten
        Schritt um, statt ein zweites zu öffnen, damit niemand je vor zwei Fragen
        auf einmal steht - und zurück geht es mit einem Klick auf "Abbrechen",

        Es ist ein eingebauter <dialog> wie der Formulardialog oben, und das gibt
        ihm Fokusfang, Escape und den verdunkelten Hintergrund ohne eigenen Code.
        Die Felder darin baut app.js: die Liste aus den Kontodaten, die
        Formulierungen aus den Übersetzungen.
    -->
    <dialog class="account-dialog" id="account-dialog" aria-labelledby="account-dialog-title">
        <div class="account-dialog__panel">
            <button type="button" class="account-dialog__close" id="account-dialog-close"
                    aria-label="<?= $text('account.close') ?>" data-i18n-label="account.close">&#215;</button>

            <h2 class="account-dialog__title" id="account-dialog-title"
                data-i18n="account.title"><?= $text('account.title') ?></h2>

            <!-- Schritt eins: das Konto und sein Ende. -->
            <div class="account-dialog__view" id="account-view-data">
                <dl class="account-dialog__list" id="account-list"></dl>

                <div class="account-dialog__danger">
                    <h3 class="account-dialog__subtitle" data-i18n="account.deleteTitle"><?= $text('account.deleteTitle') ?></h3>
                    <p class="account-dialog__hint" data-i18n="account.deleteHint"><?= $text('account.deleteHint') ?></p>
                    <button type="button" class="account-dialog__text-button" id="account-delete-open"
                            data-i18n="account.deleteSubmit"><?= $text('account.deleteSubmit') ?></button>
                </div>
            </div>

            <!-- Schritt zwei: das Passwort und die letzte Frage. -->
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
        Die eine Frage vor dem Start einer Sitzung: wie viele neue Karten sie
        einführen soll. Ein eigenes Fenster und nicht der Formulardialog, weil es
        kein Formular ist - es ist eine Zahl, vier Schnellwahlen und zwei Wege

        Der Hinweis und die Schnellwahlen schreibt app.js: wie viele neue Karten
        wirklich da sind, weiß man erst, wenn die Sitzung nach ihrer Warteschlange
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
        Die bildschirmfüllende Lernsitzung.

        Sie ist kein <dialog>: eine Sitzung ist eine eigene Ansicht und keine
        Frage, die auf eine Antwort wartet, und sie muss Kopfzeile, Seitenleiste
        und Fußzeile ganz bedecken. Escape behandelt app.js, weil die Sitzung
        erst fragen muss, wenn schon Antworten gespeichert wurden.

        Die Karte selbst ist ein Element mit zwei Seiten: vorn steht die Frage,
        hinten die Antwort, und das Umdrehen ist eine Drehung um die senkrechte
        Achse. Die Reihenfolge der beiden Seiten legt das Stylesheet fest; den
        Text schreibt app.js.
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
                    Beide Seiten können die Landkarte der Karte tragen: die Frage zeigt sie
                    ohne Markierung und die Antwort zeigt sie mit markierter Region,
                    das Umdrehen ist also das, was die Antwort zeigt. app.js füllt beide
                    Seiten und lässt sie bei einer Karte ohne Region leer.
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
                Die vier Antworten. Sie behalten ihren Platz vom ersten Augenblick an,
                damit nichts springt, wenn die Karte umgedreht wird; vorher sind sie
                gesperrt und für eine Vorlesehilfe außer Reichweite.
            -->
            <div class="learn__rating" id="learn-rating">
                <p class="learn__rating-label" id="learn-rating-label" data-i18n="learn.ratingLabel"><?= $text('learn.ratingLabel') ?></p>
                <div class="learn__buttons" id="learn-buttons" role="group" aria-labelledby="learn-rating-label"></div>
            </div>
        </div>

        <!-- Das leise Ende einer Sitzung: eine Zahl, vier Balken, zwei Wege weiter. -->
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

        <!-- Die eine Frage, die die Sitzung stellt: aufhören oder weitermachen? -->
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
