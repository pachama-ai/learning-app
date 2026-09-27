<?php

declare(strict_types=1);

/**
 * Kleine Helfer, um HTML sicher zu bauen.
 */

/**
 * Maskiert einen Wert, damit er in HTML oder in einem HTML-Attribut stehen darf.
 *
 * ENT_QUOTES maskiert das einfache und das doppelte Anfuehrungszeichen - genau
 * das macht das Ergebnis in einem Attribut wie aria-label="..." sicher.
 * Die Angabe UTF-8 sagt htmlspecialchars, wie die Eingabe zu lesen ist; sonst
 * wuerden Umlaute und andere Zeichen ausserhalb von ASCII zu kaputten Bytes.
 */
function escape_html(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

/**
 * Die Adresse einer Datei aus public/, mit dem Zeitpunkt ihrer letzten Aenderung
 * als Version.
 *
 * Blatt und Skript werden einmal geholt und dann vom Browser behalten: dieser
 * Server schickt keine Cache-Kopfzeilen und kein "last modified", deshalb fragt
 * eine Seite, in der man nur innerhalb der Anwendung blaettert, die neuen Dateien
 * nie nach - eine Aenderung bleibt unsichtbar, bis jemand bewusst neu laedt. Mit
 * dem Zeitstempel in der Adresse ist jede Aenderung eine neue Adresse und kommt
 * beim naechsten Seitenaufruf an, waehrend eine unveraenderte Datei ihre Adresse
 * behaelt - und damit ihren Platz im Browser-Cache.
 *
 * Eine Datei, die es nicht gibt, bekommt den reinen Pfad zurueck: ein Tippfehler
 * soll als fehlende Datei auffallen und nicht als kaputte Adresse.
 */
function asset_url(string $path): string
{
    $file = __DIR__ . '/../../public/' . ltrim($path, '/');

    if (!is_file($file)) {
        return $path;
    }

    return $path . '?v=' . (int) filemtime($file);
}
