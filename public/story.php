<?php

declare(strict_types=1);

/**
 * „Story Together" - die gemeinsame Geschichte von Xenia und Selina.
 *
 *   story.php        die Seite: Titel, die Geschichte, der Schreibbereich, die Zahlen
 *   story.php#ende   dieselbe Seite, nur weiter unten
 *
 * Diese Datei baut die Hülle. Der Inhalt kommt als JSON aus api/story.php und wird vom
 * Skript gezeichnet - wie die Wissensmodule der Nachschlageseite. Der Grund ist auch hier
 * der Sprachumschalter: er tauscht alle Texte aus, ohne die Seite neu zu laden.
 *
 * ZWEI Prüfungen, eine Meinung
 * ----------------------------
 * Unten entscheidet die SITZUNG, ob diese Seite überhaupt gezeigt wird: die Id kommt aus
 * der Sitzung, die erlaubten Ids aus src/helpers/story_writers.php. Dafür braucht diese
 * Datei keine Datenbank. Das ist die Bequemlichkeit - wer nicht mitschreibt, sieht gleich
 * eine ruhige Erklärung statt einer halb aufgebauten Seite.
 *
 * Verlassen kann man sich darauf NICHT, und das soll man auch nicht: api/story.php prüft
 * dasselbe noch einmal, bevor es eine einzige Zeile liest oder schreibt. Ein POST lässt
 * sich jederzeit von Hand abschicken, und dann ist die Antwort eine 403.
 *
 * Die Seite darf nicht zwischengespeichert werden: sie trägt die Übersetzungen und die
 * Versionsnummern der Dateien.
 */

header('Cache-Control: no-store, must-revalidate');

require_once __DIR__ . '/../src/helpers/html.php';
require_once __DIR__ . '/../src/helpers/translations.php';
require_once __DIR__ . '/../src/helpers/site_header.php';
require_once __DIR__ . '/../src/helpers/page_head.php';
require_once __DIR__ . '/../src/helpers/session_user.php';
require_once __DIR__ . '/../src/helpers/story_writers.php';

$translations = learning_app_translations();
$defaultLocale = 'en';
$viewerKey = story_writer_key(session_user_id_from_php_session());

/* Kurze Hilfe für die Vorlage unten: Standardsprache, schon maskiert. */
$text = fn (string $key): string => escape_html(t($defaultLocale, $key));

$appConfig = [
    'defaultLocale' => $defaultLocale,
    'supportedLocales' => array_keys($translations),
    'translations' => $translations,
    'storageKeys' => [
        'theme' => 'lernkartei.theme',
        'language' => 'lernkartei.language',
    ],
    'endpoints' => [
        /* Alles, was diese Seite braucht: die Geschichte, der Stand der Reihe, die
           Zahlen und der Token für das Veröffentlichen. */
        'story' => 'api/story.php',
        /* Nur für den Kontobereich in der Kopfzeile: dort steht, wer angemeldet ist,
           und "Abmelden" geht über dieselbe Adresse wie überall. */
        'auth' => 'api/auth.php',
        /* Für die Seitenleiste: nur die Lernbereiche, dieselbe Antwort, aus der auch die
           Kacheln der Startseite ihre Namen haben. Der Aufbau der ganzen Seite
           (api/bootstrap.php) wäre dafür viel zu viel - er trägt Karten und Zahlen mit,
           die hier niemand braucht. */
        'categories' => 'api/categories.php',
    ],
    /* Wer hier steht, weiß der Server schon - die Seite muss es nicht erfragen. */
    'viewer' => ['key' => $viewerKey],
    /* Der Weg zu dieser Seite selbst. Die Seitenleiste verlinkt sie damit als den offenen
       Eintrag - genau wie auf der Startseite, wo dieselbe Angabe an der Kachel hängt. */
    'story' => ['href' => 'story.php'],
];
?>
<!DOCTYPE html>
<html lang="<?= escape_html($defaultLocale) ?>">
<head>
    <?php render_head_basics(); ?>
    <title><?= escape_html(t($defaultLocale, 'story.title') . ' · ' . t($defaultLocale, 'app.title')) ?></title>

    <link rel="stylesheet" href="<?= escape_html(asset_url('assets/css/app.css')) ?>">
    <link rel="stylesheet" href="<?= escape_html(asset_url('assets/css/story.css')) ?>">

    <?php render_theme_init($appConfig['storageKeys']['theme']); ?>

    <script type="application/json" id="app-config"><?= json_encode($appConfig, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?></script>
</head>
<body>
    <div class="bg-layer" aria-hidden="true">
        <span class="bg-blob bg-blob--a"></span>
        <span class="bg-blob bg-blob--b"></span>
        <span class="bg-blob bg-blob--c"></span>
    </div>

    <div class="page page--story">
        <?php render_site_header($defaultLocale); ?>

        <noscript>
            <p class="state state--error"><?= $text('state.noscript') ?></p>
        </noscript>

        <main class="main" id="main">
            <!-- Die leise Zeile über dem Inhalt: "gespeichert", "nicht dein Zug" und so fort. -->
            <p class="page-note" id="story-note" role="status" aria-live="polite" hidden></p>
        <?php if ($viewerKey === null): ?>
            <!--
                Dieses Konto schreibt nicht mit. Die Erklärung steht hier und nicht im
                Skript, weil die Sitzung schon beim Aufbau der Seite bekannt ist - es gibt
                also nichts nachzuladen und nichts zu verstecken.
            -->
            <div class="story-denied">
                <h1 class="story-denied__title" data-i18n="story.forbidden.title"><?= $text('story.forbidden.title') ?></h1>
                <p class="story-denied__hint" data-i18n="story.forbidden.hint"><?= $text('story.forbidden.hint') ?></p>
            </div>
        <?php else: ?>
            <!--
                Dasselbe Gerüst wie eine Unterkategorie (siehe public/index.php): Seitenleiste
                links, Inhaltsspalte rechts. Damit sitzt die Geschichte in derselben Flucht
                wie jede andere Seite, und der Weg zu den Lernbereichen ist von hier aus
                derselbe wie überall.
            -->
            <section class="view view--detail" id="story-view" data-writer="<?= escape_html((string) $viewerKey) ?>">
                <!--
                    Die Themenliste.

                    Auf dem Rechner steht die Beschriftung einfach da. Auf dem Handy ist
                    sie eine Zeile zum Auf- und Zuklappen - ein `details`-Element, das der
                    Browser von sich aus kann, ohne Skript. app.js klappt sie dort beim
                    Laden zu, und das Zeichen rechts erscheint nur auf dem Handy
                    (siehe app.css).
                -->
                <aside class="sidebar">
                    <details class="sidebar__fold" id="sidebar-fold" open>
                        <summary class="sidebar__summary">
                            <span class="eyebrow" data-i18n="sidebar.label"><?= $text('sidebar.label') ?></span>
                            <svg class="sidebar__chevron" viewBox="0 0 24 24" width="16" height="16" fill="none"
                                 stroke="currentColor" stroke-width="1.5" stroke-linecap="round"
                                 stroke-linejoin="round" focusable="false" aria-hidden="true">
                                <path d="M6 9.5 12 15.5 18 9.5"/>
                            </svg>
                        </summary>
                        <nav class="sidebar__nav" id="sidebar-nav"></nav>
                    </details>
                </aside>

                <div class="detail">
                    <!--
                        Der Kopf: dieselbe farbige Karte wie bei einem Lernbereich - die
                        Zeichnung im Kreis, daneben der Titel. Darüber das kleine Label, das
                        sagt, worum es hier geht.

                        Die Farbe kommt aus --detail-palette (siehe story.css) und ist damit
                        das gedeckte Salbeigrün, dieselbe Farbe wie die Kachel auf der
                        Startseite und der Punkt in der Seitenleiste.
                    -->
                    <div class="detail__head">
                        <div class="detail__titles">
                            <span class="blob detail__blob" aria-hidden="true">
                                <img class="blob__icon" alt="" width="34" height="34"
                                     src="<?= escape_html(asset_url('assets/icons/story_icon.svg')) ?>">
                            </span>
                            <div class="detail__text">
                                <p class="eyebrow" data-i18n="story.label"><?= $text('story.label') ?></p>
                                <h1 class="heading heading--detail" id="story-title"></h1>
                            </div>
                        </div>
                    </div>

                    <!--
                        Die Zahlen als EINE Zeile: Wörter, Beiträge, Tage, dahinter wie viel
                        jede Person geschrieben hat und seit wann es die Geschichte gibt.
                        Klein und leise, direkt unter dem Kopf - sie ist eine Fußnote zur
                        Geschichte, kein eigener Seitenabschnitt.

                        Was in der Zeile steht, baut das Skript (story.js): die Zahlen
                        ändern sich ja mit jedem Beitrag.
                    -->
                    <section class="story-stats" id="story-stats" hidden>
                        <p class="story-line">
                            <span class="story-line__figures" id="story-figures"></span>
                            <span class="story-line__share" id="story-share"></span>
                            <span class="story-line__since" id="story-since"></span>
                        </p>
                    </section>

                    <!--
                        Die Grammar Challenge: eine schmale Leiste, mehr nicht. Links das
                        Markenzeichen und die Beschriftung, rechts die Zeitform der nächsten
                        Tage. Die Anregung selbst - Anweisung, Beispielsatz, Eselsbrücke -
                        steht bewusst NICHT auf der Seite: sie wäre ein zweiter Mittelpunkt
                        neben der Geschichte.

                        Den Inhalt schickt die API (Feld `challenge`), berechnet aus dem
                        Datum in Europe/Berlin: dieselbe Zeitform für beide Konten, den
                        ganzen Tag über dieselbe, und alle zwei Kalendertage die nächste.
                        Ohne Aufgabe bleibt die Leiste versteckt statt als leerer Balken.
                    -->
                    <section class="story-challenge" id="story-challenge" aria-labelledby="story-challenge-label" hidden>
                        <div class="challenge">
                            <img class="challenge__mark" aria-hidden="true" alt=""
                                 src="<?= escape_html(asset_url('assets/icons/story_icon.svg')) ?>">
                            <span class="challenge__label" id="story-challenge-label"
                                  data-i18n="story.challenge.label"><?= $text('story.challenge.label') ?></span>
                            <span class="challenge__topic" id="story-challenge-topic"></span>
                            <span class="challenge__level" id="story-challenge-level"></span>
                        </div>
                    </section>

                    <article class="story" id="story">
                        <p class="state" id="story-loading" data-i18n="state.loading"><?= $text('state.loading') ?></p>
                        <p class="state state--error" id="story-error" data-i18n="story.error.load" hidden><?= $text('story.error.load') ?></p>

                        <!--
                            Der Lesebereich. Er hat eine feste Höhe und scrollt in sich
                            selbst, damit die Seite nicht endlos lang wird: gelesen wird oben,
                            der Editor steht darunter immer an derselben Stelle. Beim Öffnen
                            steht er unten beim neuesten Beitrag - wie ein Buch, das man auf
                            der letzten Seite aufschlägt.

                            `tabindex="0"` macht ihn mit der Tastatur scrollbar; den Fade an
                            den Rändern zeichnet das Blatt (story.css).
                        -->
                        <div class="story-scroll" id="story-scroll" role="region" tabindex="0"
                             aria-labelledby="story-title">
                            <!--
                                Die Geschichte selbst. Jeder Beitrag wird ein <article> mit einem
                                schmalen farbigen Strich links - die Farbe sagt, wer geschrieben hat.
                            -->
                            <section class="story-body" id="story-body" aria-label="<?= $text('story.label') ?>"></section>

                            <p class="story-hint" id="story-empty" data-i18n="story.meta.none" hidden><?= $text('story.meta.none') ?></p>
                        </div>
                    </article>

                        <!--
                            Der Schreibbereich - ganz unten, direkt unter dem Gelesenen: man
                            liest oben und schreibt darunter weiter. Er sieht aus wie Text auf
                            der Seite und wird erst beim Anklicken zum Feld (siehe story.css).

                            Wer heute nicht dran ist, sieht ihn ausgegraut: sichtbar, aber
                            außer Betrieb. Gespeichert wird ohnehin serverseitig geprüft.
                        -->
                        <section class="story-write" id="story-write" hidden>
                            <div class="story-write__box" id="story-box">
                                <!-- Der Titel wechselt zwischen "fortsetzen" und "ändern"; die Id ist
                                     für das Skript da, das ihn umschreibt. -->
                                <h2 class="story-write__title" id="story-write-title"
                                    data-i18n="story.write.title"><?= $text('story.write.title') ?></h2>

                                <textarea class="story-write__field" id="story-field" rows="9"
                                          spellcheck="true"
                                          placeholder="<?= $text('story.write.placeholder') ?>"
                                          data-i18n-placeholder="story.write.placeholder"></textarea>

                                <div class="story-write__foot">
                                    <span class="story-write__count" id="story-count"></span>
                                    <span class="story-write__actions">
                                        <!-- Beim Ändern eines Beitrags wird der zweite Knopf sichtbar. -->
                                        <button type="button" class="story-button story-button--quiet" id="story-cancel"
                                                data-i18n="story.edit.cancel" hidden><?= $text('story.edit.cancel') ?></button>
                                        <button type="button" class="story-button" id="story-publish"
                                                data-i18n="story.write.publish"><?= $text('story.write.publish') ?></button>
                                    </span>
                                </div>

                                <p class="story-write__limit" id="story-limit" role="status" aria-live="polite" hidden></p>
                            </div>

                            <p class="story-write__status" id="story-status"></p>
                        </section>
                </div>
            </section>
        <?php endif; ?>
        </main>
    </div>

    <script src="<?= escape_html(asset_url('assets/js/story.js')) ?>"></script>
</body>
</html>
