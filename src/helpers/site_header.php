<?php

declare(strict_types=1);

/**
 * Die Kopfzeile, die alle Seiten tragen.
 *
 * Sie stand früher in public/index.php und wird jetzt von allen drei Seiten benutzt: der
 * Lernkartei, der Nachschlageseite "Energie verstehen" und der gemeinsamen Geschichte.
 * Damit gibt es genau eine Stelle, an der das Konto, der Wechsel des Erscheinungsbilds
 * und der Sprachumschalter stehen - eine zweite Kopie würde beim nächsten Umbau still
 * auseinanderlaufen.
 *
 * Die Wege zwischen den Seiten standen früher hier als eigene Zeile in der Mitte. Sie
 * sind entfernt: die Geschichte wird wie ein Lernbereich erreicht (Kachel auf der
 * Startseite, Eintrag in der Seitenleiste), und "Energie verstehen" bleibt der eine
 * Nachschlageweg, auf den die Lernkartei selbst verlinkt.
 *
 * Was hier NICHT passiert:
 *   - kein Datenbankzugriff (das Konto füllt JavaScript über api/auth.php)
 *   - kein sichtbarer Text im Code (jeder Satz kommt aus translations.php)
 *   - keine Logik. Die Datei schreibt Markup und sonst nichts.
 */

require_once __DIR__ . '/html.php';
require_once __DIR__ . '/translations.php';

/**
 * Schreibt die Kopfzeile.
 *
 * Das Element mit einer Id ist der Grund, warum die Kopfzeile auf allen Seiten gleich
 * aussieht UND gleich bedient wird: #account-slot gehört dem JavaScript jeder Seite.
 * #crumb füllt nur die Lernkartei. Alles andere ist festes Markup.
 *
 * @param string $locale die Sprache der festen Texte
 * @return void
 */
function render_site_header(string $locale): void
{
    /* Kurze Hilfe: Übersetzung holen und für HTML maskieren, in einem Schritt. */
    $text = static fn (string $key): string => escape_html(t($locale, $key));

    ?>
    <header class="site-header">
        <div class="site-header__left">
            <!--
                Das Konto als reiner Text: "Anmelden", solange niemand angemeldet ist, sonst
                der Name der Person und "Abmelden". Gefüllt wird der Platz vom JavaScript der
                jeweiligen Seite aus api/auth.php - diese Datei spricht nie mit der Datenbank.
            -->
            <div class="account" id="account-slot"></div>

            <!--
                Startet leer, und ein leeres ".crumb" braucht keinen Platz (siehe
                Stylesheet). Die Lernkartei füllt hier den Pfad zur offenen Ansicht ein,
                die beiden anderen Seiten lassen ihn leer.
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

            <!--
                Ein feiner Strich zwischen dem Themenwechsler und der Sprache. Er ist das,
                was aus drei Bedienelementen eine Gruppe macht: Sonne/Mond und der
                Sprachumschalter gehören zusammen, sind aber nicht dasselbe. Reine Zierde,
                deshalb für Vorlesehilfen versteckt.
            -->
            <span class="site-header__divider" aria-hidden="true"></span>

            <!-- Ein reiner Textumschalter mit Unterstrich, kein gerahmtes Bedienelement. -->
            <div class="lang-switch" role="group" aria-label="<?= $text('language.label') ?>" data-i18n-label="language.label">
                <button type="button" class="lang-switch__option" data-locale="en" data-i18n="language.en"><?= $text('language.en') ?></button>
                <span class="lang-switch__separator" aria-hidden="true">|</span>
                <button type="button" class="lang-switch__option" data-locale="de" data-i18n="language.de"><?= $text('language.de') ?></button>
                <span class="lang-switch__underline" id="lang-underline" aria-hidden="true"></span>
            </div>
        </div>
    </header>
    <?php
}
