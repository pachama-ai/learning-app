<?php

declare(strict_types=1);

/*
    ------------------------------------------------------------------------
    STILLGELEGT am 11.10.2026 - auf Wunsch bleibt diese Seite aus.
    ------------------------------------------------------------------------

    Die Adresse antwortet nur noch mit "nicht gefunden". Der Code darunter steht
    unverändert da (nichts gelöscht, nichts umgeschrieben), er wird nur nicht mehr
    erreicht - so, als wäre er auskommentiert.

    Zum Wiederanschalten genügt es, die vier Zeilen bis einschließlich `exit;` zu
    entfernen; die Seite ist dann sofort wieder da, wie sie war.

    Was zu dieser Seite gehört und damit gerade nichts tut:
      public/energy.php, public/assets/css/energy.css,
      public/assets/js/energy.js, public/assets/js/energy_visuals.js,
      public/assets/vendor/katex/ (der Formelsatz),
      src/data/energy_modules.php (die Wissensmodule).
    In der Lernkartei selbst war sie nie verlinkt.
*/
http_response_code(404);
header('Content-Type: text/plain; charset=utf-8');
echo "Not found.\n";
exit;

/**
 * "Energie verstehen" - das interaktive Nachschlagewerk.
 *
 * Diese Seite hat ihre eigene Adresse, damit sie in einem zweiten Fenster neben der
 * Lernkartei offen stehen kann:
 *
 *   energy.php                  -> die ganze Seite, Suche und Filter sind sofort da
 *   energy.php#ohms_law         -> dieselbe Seite, auf das Modul gescrollt
 *
 * Sie baut nur die Hülle. Die Wissensmodule selbst kommen als JSON aus
 * src/data/energy_modules.php in den Browser, und von dort zeichnet sie das Skript - so
 * wie die Lernkartei ihre Karten nicht aus PHP ins Markup schreibt. Der Grund ist hier
 * ein sichtbarer: der Sprachumschalter tauscht die Texte ohne Neuladen aus, und dazu
 * müssen beide Sprachen im Browser liegen.
 *
 * Mit der Datenbank spricht diese Seite NICHT. Sie zeigt nichts an, was einer Person
 * gehört, und schreibt nichts. Das Konto in der Kopfzeile holt das Skript über
 * api/auth.php, wie die Lernkartei es auch tut.
 *
 * Diese Datei darf nicht zwischengespeichert werden: sie trägt die Übersetzungen und
 * die Versionsnummern der Dateien. Siehe die Begründung in public/index.php.
 */

header('Cache-Control: no-store, must-revalidate');

require_once __DIR__ . '/../src/helpers/html.php';
require_once __DIR__ . '/../src/helpers/translations.php';
require_once __DIR__ . '/../src/helpers/site_header.php';
require_once __DIR__ . '/../src/helpers/page_head.php';
require_once __DIR__ . '/../src/data/energy_modules.php';

$translations = learning_app_translations();
$defaultLocale = 'en';
$modules = energy_modules();

/* Kurze Hilfe für die Vorlage unten: Standardsprache, schon maskiert. */
$text = fn (string $key): string => escape_html(t($defaultLocale, $key));

/*
 * Alles, was der Browser braucht. Darin stehen keine Zugangsdaten, keine
 * Verbindungsdaten und keine Server-Dateipfade.
 */
$appConfig = [
    'defaultLocale' => $defaultLocale,
    'supportedLocales' => array_keys($translations),
    'translations' => $translations,
    'storageKeys' => [
        'theme' => 'lernkartei.theme',
        'language' => 'lernkartei.language',
        /* Suche und Filter überleben das Neuladen der Seite - aber nur diese Sitzung,
           ein neuer Reiter fängt wieder mit allem an. */
        'filters' => 'lernkartei.energy.filters',
    ],
    'endpoints' => [
        /* Nur das Konto in der Kopfzeile. Die Module liegen schon im Browser. */
        'auth' => 'api/auth.php',
    ],
    /* Die Ids der Seiten, zwischen denen die Kopfzeile umschaltet (siehe site_header.php). */
    'pages' => ['cards' => 'index.php', 'energy' => 'energy.php'],
    'modules' => $modules,
    /* Die Themen, zu denen es wirklich ein Modul gibt, in fester Reihenfolge. */
    'groups' => energy_groups_of($modules),
    /*
     * Der Formelsatz. Er läuft im Browser und nur auf dieser Seite; die Datei liegt
     * unter public/assets/vendor/katex/ (KaTeX 0.16.11, MIT-Lizenz, siehe LICENSE dort).
     * Diese zwei Optionen sind die einzige Stelle, an der Formeln erzeugt werden.
     */
    'katex' => [
        'throwOnError' => false,
        'strict' => false,
        'output' => 'htmlAndMathml',
    ],
];
?>
<!DOCTYPE html>
<html lang="<?= escape_html($defaultLocale) ?>">
<head>
    <?php render_head_basics(); ?>
    <title><?= escape_html(t($defaultLocale, 'energy.title') . ' · ' . t($defaultLocale, 'app.title')) ?></title>

    <!--
        app.css trägt die Farben, die Schriften und die Kopfzeile; es ist dasselbe Blatt
        wie in der Lernkartei, damit beide Seiten wie eine Anwendung aussehen. energy.css
        fügt nur hinzu, was diese Seite allein braucht.
    -->
    <link rel="stylesheet" href="<?= escape_html(asset_url('assets/css/app.css')) ?>">
    <link rel="stylesheet" href="<?= escape_html(asset_url('assets/vendor/katex/katex.min.css')) ?>">
    <link rel="stylesheet" href="<?= escape_html(asset_url('assets/css/energy.css')) ?>">

    <?php render_theme_init($appConfig['storageKeys']['theme']); ?>

    <script type="application/json" id="app-config"><?= json_encode($appConfig, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?></script>
</head>
<body>
    <!--
        Dieselben drei weichen Lichtflächen wie in der Lernkartei. Im hellen Modus sind
        sie unsichtbar, im dunklen geben sie der Seite ihren Grund. Nur Zierde: nichts
        hier ist anklickbar oder wird vorgelesen.
    -->
    <div class="bg-layer" aria-hidden="true">
        <span class="bg-blob bg-blob--a"></span>
        <span class="bg-blob bg-blob--b"></span>
        <span class="bg-blob bg-blob--c"></span>
    </div>

    <div class="page">
        <?php /* Dieselbe Kopfzeile wie in der Lernkartei, siehe src/helpers/site_header.php. */ ?>
        <?php render_site_header($defaultLocale); ?>

        <noscript>
            <p class="state state--error"><?= $text('state.noscript') ?></p>
        </noscript>

        <main class="main" id="main">
            <div class="energy-head">
                <h1 class="energy-title" data-i18n="energy.title"><?= $text('energy.title') ?></h1>
                <p class="energy-lead" data-i18n="energy.lead"><?= $text('energy.lead') ?></p>
            </div>

            <!--
                Die Filterleiste. Sie bleibt beim Scrollen oben stehen (siehe .energy-bar
                in energy.css) und ist damit der einzige Teil der Seite, der immer
                erreichbar ist.

                Zwei Auswahlfelder, die voneinander abhängen: links der Themenbereich,
                rechts das konkrete Thema daraus. Beide Listen baut das Skript, weil sie
                aus den Daten kommen: ein Themenbereich ohne Module wird gar nicht
                angeboten, und ein neues Modul erscheint von allein in der zweiten Liste.
            -->
            <div class="energy-bar" id="energy-bar">
                <div class="energy-bar__row">
                    <span class="energy-field">
                        <label class="energy-field__label" for="energy-group" data-i18n="energy.filter.group"><?= $text('energy.filter.group') ?></label>
                        <select class="energy-select" id="energy-group"></select>
                    </span>

                    <span class="energy-field">
                        <label class="energy-field__label" for="energy-topic" data-i18n="energy.filter.topic"><?= $text('energy.filter.topic') ?></label>
                        <select class="energy-select" id="energy-topic"></select>
                    </span>

                    <button type="button" class="energy-chip energy-chip--plain" id="energy-formulas"
                            aria-pressed="false" data-i18n="energy.filter.formulas"><?= $text('energy.filter.formulas') ?></button>

                    <button type="button" class="energy-reset" id="energy-reset" data-i18n="energy.reset"><?= $text('energy.reset') ?></button>

                    <p class="energy-count" id="energy-count" role="status" aria-live="polite"></p>
                </div>
            </div>

            <!--
                Hier stehen die Wissensmodule. Gefüllt wird der Block vom Skript, aus den
                Daten in src/data/energy_modules.php.
            -->
            <div class="energy-modules" id="energy-modules"></div>

            <div class="energy-empty" id="energy-empty" hidden>
                <p class="energy-empty__title" data-i18n="energy.empty.title"><?= $text('energy.empty.title') ?></p>
                <p class="energy-empty__hint" data-i18n="energy.empty.hint"><?= $text('energy.empty.hint') ?></p>
            </div>
        </main>
    </div>

    <!--
        Der Formelsatz, die Zeichnungen und die Seite selbst. Die Reihenfolge ist wichtig:
        die Zeichnungen melden sich an, bevor die Seite sie sucht.
    -->
    <script src="<?= escape_html(asset_url('assets/vendor/katex/katex.min.js')) ?>"></script>
    <script src="<?= escape_html(asset_url('assets/js/energy_visuals.js')) ?>"></script>
    <script src="<?= escape_html(asset_url('assets/js/energy.js')) ?>"></script>
</body>
</html>
