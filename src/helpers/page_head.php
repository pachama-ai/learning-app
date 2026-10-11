<?php

declare(strict_types=1);

/**
 * Die Zeilen des <head>, die beide Seiten gleich brauchen.
 *
 * Warum das ausgelagert ist: die Zeichenkodierung, das Symbol im Browserreiter, die
 * vorgezogene Schrift und das Setzen des Erscheinungsbilds sind auf jeder Seite
 * buchstabengleich. Zwei Kopien würden beim nächsten Umbau still auseinanderlaufen -
 * und ausgerechnet beim Erscheinungsbild sieht man das als farbiges Aufblitzen.
 *
 * Nicht hier: der <title>, die Stylesheets und alles, was nur eine Seite braucht.
 */

require_once __DIR__ . '/html.php';

/**
 * Zeichenkodierung, Ansichtsfenster, Browser-Symbol und die vorgezogene Schrift.
 *
 * Die Schrift wird vorgezogen, damit die Überschrift schon in ihrer eigenen Schrift
 * beginnt und nicht sichtbar umspringt. Der Pfad ist relativ zu der Seite in public/,
 * und crossorigin ist nötig, weil Schriften immer als eigene Herkunft geholt werden.
 *
 * @return void
 */
function render_head_basics(): void
{
    ?>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <!-- Das Symbol im Browserreiter. Das ist die Symboldatei des Browsers, kein Kategoriesymbol. -->
    <link rel="icon" href="assets/icons/browser_icon.svg" type="image/svg+xml">
    <link rel="preload" href="assets/fonts/inter-tight-latin-wght-normal.woff2" as="font" type="font/woff2" crossorigin>
    <?php
}

/**
 * Setzt das gespeicherte Erscheinungsbild, bevor die Seite gezeichnet wird.
 *
 * Das MUSS inline im Kopf laufen und darf keine eigene Datei sein: jede Datei wäre eine
 * weitere Anfrage, und in dieser Lücke zeichnet der Browser die Seite schon - zuerst
 * hell und dann dunkel. Genau dieses Aufblitzen soll hier nicht passieren.
 *
 * Gespeichert ist nur "light" oder "dark". Steht dort nichts Brauchbares, gilt die
 * Einstellung des Systems. Der private Modus kann localStorage sperren, deshalb steht
 * jeder Zugriff in einem eigenen try.
 *
 * @param string $storageKey der Schlüssel, unter dem die Wahl liegt
 * @return void
 */
function render_theme_init(string $storageKey): void
{
    ?>
    <script>
        (function () {
            var storageKey = <?= json_encode($storageKey, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
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
    <?php
}
