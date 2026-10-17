<?php

declare(strict_types=1);

/**
 * Gemeinsame Angaben zu den Fotos einer Lernkarte.
 *
 * Die Datenbank speichert nur den Dateinamen (cards.image_front und
 * cards.image_back). Wo die Datei liegt und wie sie im Browser heißt, steht
 * deshalb an genau einer Stelle: hier.
 *
 * Der Name wird beim Hochladen zufällig erzeugt. Ein selbst gewählter Name
 * könnte eine fremde Datei überschreiben, und ein aus der Datenbank gelesener
 * Wert darf niemals einen Pfad außerhalb von uploads/cards zeigen. Deshalb wird
 * jeder Name vor der Benutzung gegen CARD_IMAGE_FILENAME_PATTERN geprüft.
 */

/** Ordner der Fotos, relativ zu public/. */
const CARD_IMAGE_FOLDER = 'uploads/cards';

/** Größte Datei, die angenommen wird (2 MB, wie upload_max_filesize). */
const CARD_IMAGE_MAX_BYTES = 2097152;

/** Größte Kantenlänge der hochgeladenen Datei, bevor sie verkleinert wird. */
const CARD_IMAGE_MAX_SIDE = 4500;

/** Größte Kantenlänge, die gespeichert wird. Mehr braucht keine Lernkarte. */
const CARD_IMAGE_TARGET_SIDE = 1600;

/** Kleinste Kantenlänge, damit kein Bildpunkt als Foto durchgeht. */
const CARD_IMAGE_MIN_SIDE = 32;

/**
 * So sieht ein von dieser Anwendung erzeugter Dateiname aus: 32 Hexzeichen und
 * eine der drei erlaubten Endungen.
 */
const CARD_IMAGE_FILENAME_PATTERN = '/^[a-f0-9]{32}\.(jpg|png|webp)$/';

/**
 * Die Spalte zu einer Seite der Karte, oder null für eine unbekannte Seite.
 *
 * @return string|null
 */
function card_image_column_for_side(string $side): ?string
{
    if ($side === 'front') {
        return 'image_front';
    }

    if ($side === 'back') {
        return 'image_back';
    }

    return null;
}

/**
 * Ob ein gespeicherter Dateiname brauchbar ist.
 *
 * Eine leere Zeichenkette und NULL heißen beide "kein Foto" und sind deshalb
 * kein Fehler, sondern ergeben false.
 */
function card_image_filename_is_valid(?string $file): bool
{
    return is_string($file)
        && preg_match(CARD_IMAGE_FILENAME_PATTERN, $file) === 1;
}

/**
 * Der Ordner, in dem die Fotos liegen (absoluter Pfad).
 *
 * dirname(__DIR__, 2) führt von src/helpers zum Projektstamm; von dort aus
 * liegt public/ neben src/. Der Pfad wird nirgends an den Browser gegeben.
 */
function card_image_directory(): string
{
    return dirname(__DIR__, 2) . '/public/' . CARD_IMAGE_FOLDER;
}

/**
 * Der vollständige Pfad einer Bilddatei, oder null bei einem unbrauchbaren Namen.
 *
 * Die Prüfung des Namens ist hier Absicht: kein Aufrufer soll selbst einen Pfad
 * zusammensetzen und dabei "../" durchlassen.
 */
function card_image_path(?string $file): ?string
{
    if (!card_image_filename_is_valid($file)) {
        return null;
    }

    return card_image_directory() . '/' . $file;
}

/**
 * Die Adresse, die der Browser für ein Foto benutzt, oder null ohne Foto.
 *
 * Der Name ist bereits geprüft, deshalb enthält die Adresse keine Zeichen, die
 * maskiert werden müssten.
 */
function card_image_url(?string $file): ?string
{
    if (!card_image_filename_is_valid($file)) {
        return null;
    }

    return CARD_IMAGE_FOLDER . '/' . $file;
}

/**
 * Ob die Tabelle `cards` beide Foto-Spalten hat.
 *
 * @param list<string> $columns
 */
function card_image_columns_present(array $columns): bool
{
    return in_array('image_front', $columns, true)
        && in_array('image_back', $columns, true);
}
