<?php

declare(strict_types=1);

/**
 * Macht ein SVG sicher, bevor es in die Datenbank kommt.
 *
 * Eine hochgeladene Zeichnung ist ungeprüfte Eingabe, wie ein Textfeld auch. Sie
 * wird gespeichert und später wieder ausgeliefert, also fliegt vorher alles
 * Gefährliche raus:
 *   - <script> und Ereignis-Attribute (onclick="...") führen Code aus
 *   - <foreignObject>, <iframe>, <object>, <embed> ziehen ein ganzes Dokument nach
 *   - <image> und <feImage> holen Dateien von fremden Servern
 *   - "javascript:" und "data:text/html" führen beim Öffnen Code aus
 *   - <use href="http://..."> lädt etwas von außerhalb der Datei
 *   - ein Doctype oder eine Entität bläht eine kleine Datei riesig auf
 *
 * Das Icon wird immer über <img> gezeichnet, und ein <img> führt kein Skript im
 * SVG aus. Diese Datei ist die zweite Schutzschicht, nicht die einzige.
 *
 * WIE ES FUNKTIONIERT
 *
 * Geparst wird mit einem echten XML-Parser (libxml über DOMDocument), nicht mit
 * Textmustern. Drei Folgen, alle gewollt:
 *
 *   1. Schnell und berechenbar: eine 350-KB-Zeichnung ist ein Durchlauf, kein
 *      Muster, das immer wieder über den ganzen Text läuft.
 *   2. Kein gültiges XML heißt: abgelehnt. Eine halb geflickte Zeichnung ist
 *      schlimmer als keine.
 *   3. Zurückgeschrieben wird nur das <svg>-Element. Text davor oder danach kann
 *      nicht überleben, weil er nie Teil dieses Elements war.
 *
 * Zeichen-Referenzen löst der Parser auf, bevor geprüft wird - hinter
 * "&#106;avascript:" kann sich also nichts verstecken.
 *
 * In die Spalte muss nichts mehr gequetscht werden: `icon_svg` ist MEDIUMTEXT,
 * eine Zeichnung wird so gespeichert, wie sie ist. Grenze ist das Upload-Limit.
 */

/** Größtes SVG, das hochgeladen werden darf: 350 KB (358 400 Bytes). */
const SVG_MAX_UPLOAD_BYTES = 358400;

/**
 * Größer wird nichts in die Spalte geschrieben. Dieselbe Zahl wie beim Upload:
 * der Parser macht eine Datei nur kleiner, weil er wegwirft, was verboten ist.
 */
const SVG_MAX_STORED_BYTES = 358400;

/**
 * Dieselben Namen als Nachschlagetabelle.
 *
 * Eine 350-KB-Zeichnung hat Tausende Elemente, "ist dieser Name gesperrt?" wird
 * also Tausende Male gefragt. Ein Schlüssel-Lookup antwortet in einem Schritt,
 * statt eine Liste durchzugehen.
 */
const SVG_BLOCKED_ELEMENT_LOOKUP = [
    'script' => true,
    'foreignobject' => true,
    'iframe' => true,
    'object' => true,
    'embed' => true,
    'image' => true,
    'feimage' => true,
    'audio' => true,
    'video' => true,
    'handler' => true,
    'listener' => true,
    'metadata' => true,
];

/**
 * Gibt ein sicheres SVG zurück oder null, wenn die Eingabe nicht taugt.
 *
 * Der Aufrufer muss nur diese zwei Antworten kennen. Jeder Grund abzulehnen
 * endet in null.
 */
function svg_sanitize(string $svg): ?string
{
    $svg = trim($svg);

    if ($svg === '' || strlen($svg) > SVG_MAX_UPLOAD_BYTES || !mb_check_encoding($svg, 'UTF-8')) {
        return null;
    }

    /*
     * Doctype und Entitätsdefinition fliegen raus, bevor der Parser sie sieht:
     * die zwei Konstrukte, die etwas von außen laden oder sich stark aufblähen
     * könnten - und kein Icon braucht sie. Reine Textsuche, kostet nichts.
     */
    if (stripos($svg, '<!doctype') !== false || stripos($svg, '<!entity') !== false) {
        return null;
    }

    $document = new DOMDocument();

    /*
     * libxml sammelt Fehler in einer eigenen Warteschlange; die wird hier
     * geleert, weil diese Funktion mit null antwortet und nicht mit einer
     * Warnung auf der Seite. LIBXML_NONET verbietet jeden Netzzugriff.
     */
    $previous = libxml_use_internal_errors(true);
    $loaded = $document->loadXML($svg, LIBXML_NONET | LIBXML_COMPACT);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    $root = $loaded ? $document->documentElement : null;

    if (!$root instanceof DOMElement || strtolower($root->localName) !== 'svg') {
        return null;
    }

    svg_clean_children($root);
    svg_clean_element($root);

    $clean = $document->saveXML($root);

    if ($clean === false) {
        return null;
    }

    $clean = trim($clean);

    if (strlen($clean) > SVG_MAX_STORED_BYTES
        || stripos($clean, '<svg') !== 0
        || substr($clean, -6) !== '</svg>'
        || svg_looks_risky($clean)) {
        return null;
    }

    return $clean;
}

/**
 * Geht die Kinder eines Elements durch: Kommentare, gesperrte Elemente und
 * gefährliche Attribute fliegen raus, der Rest wird eine Ebene tiefer geprüft.
 *
 * Gelaufen wird iterativ über die Geschwister (ohne feste Tiefengrenze), damit
 * auch eine tief verschachtelte Zeichnung durchläuft.
 */
function svg_clean_children(DOMElement $parent): void
{
    $child = $parent->firstChild;

    while ($child !== null) {
        $next = $child->nextSibling;

        if ($child instanceof DOMComment || $child instanceof DOMProcessingInstruction) {
            /* Editor-Kommentare und Verarbeitungsanweisungen zeichnen nichts. */
            $parent->removeChild($child);
            $child = $next;
            continue;
        }

        if ($child instanceof DOMElement) {
            $name = strtolower($child->localName);

            if (isset(SVG_BLOCKED_ELEMENT_LOOKUP[$name])) {
                /* Kommt mit allem darin weg. */
                $parent->removeChild($child);
                $child = $next;
                continue;
            }

            if ($name === 'use' && !svg_use_points_inside($child)) {
                /* <use> darf nur etwas aus derselben Datei wiederverwenden. */
                $parent->removeChild($child);
                $child = $next;
                continue;
            }

            if ($name === 'style' && stripos($child->textContent, '@import') !== false) {
                /* Ein Stylesheet, das eine andere Datei nachlädt. */
                $parent->removeChild($child);
                $child = $next;
                continue;
            }

            svg_clean_element($child);
            svg_clean_children($child);
        }

        $child = $next;
    }
}

/**
 * Entfernt die gefährlichen Attribute eines Elements.
 *
 * Erst werden die Namen gesammelt, dann entfernt: eine Attributliste darf nicht
 * verändert werden, während sie gelesen wird.
 */
function svg_clean_element(DOMElement $element): void
{
    $remove = [];

    foreach ($element->attributes as $attribute) {
        $name = strtolower($attribute->nodeName);
        $value = (string) $attribute->nodeValue;

        /* Ereignis-Attribute: onclick, onload, onmouseover, ... */
        if (strlen($name) > 2 && strpos($name, 'on') === 0) {
            $remove[] = $attribute->nodeName;
            continue;
        }

        /* Eine URL, die Code ausführen könnte - egal in welchem Attribut und in
           welcher Schreibweise. Die Zeichen-Referenzen hat der Parser schon
           aufgelöst, hinter &#106;avascript: kann sich also nichts verstecken. */
        if (svg_text_is_dangerous($value)) {
            $remove[] = $attribute->nodeName;
            continue;
        }

        /* Ein Verweis, der aus dieser Datei herausführt. Ausnahme: der Verweis
           auf ein Element in derselben Zeichnung, so arbeitet <use>. */
        if ($name === 'href' || $name === 'xlink:href') {
            if (strpos(ltrim($value), '#') !== 0) {
                $remove[] = $attribute->nodeName;
            }
        }
    }

    foreach ($remove as $name) {
        $element->removeAttribute($name);
    }
}

/**
 * Sagt, ob ein <use>-Element auf etwas in derselben Datei zeigt.
 */
function svg_use_points_inside(DOMElement $use): bool
{
    $href = $use->getAttribute('href');

    if ($href === '') {
        $href = $use->getAttributeNS('http://www.w3.org/1999/xlink', 'href');
    }

    return strpos(ltrim((string) $href), '#') === 0;
}

/**
 * Sagt, ob ein Wert etwas enthält, das Code ausführen könnte.
 *
 * Leerzeichen und Steuerzeichen fliegen vor der Suche raus, damit auch
 * "java\nscript:" gefunden wird.
 */
function svg_text_is_dangerous(string $value): bool
{
    /*
     * Fast jeder Wert in einer Zeichnung ist eine Zahl, eine Farbe oder
     * Pfaddaten - keiner davon enthält einen Doppelpunkt. Ein URL-Schema hat
     * immer einen, deshalb reicht dieser eine Vergleich für die meisten der
     * Tausenden Attribute einer 350-KB-Datei.
     */
    if ($value === '' || strpos($value, ':') === false) {
        return false;
    }

    $flat = strtolower((string) preg_replace('/[\x00-\x20]+/', '', $value));

    foreach (['javascript:', 'vbscript:', 'data:text/html'] as $needle) {
        if (strpos($flat, $needle) !== false) {
            return true;
        }
    }

    return false;
}

/**
 * Der letzte Blick auf das Ergebnis, bevor es gespeichert wird.
 *
 * Alles hier sollte oben schon entfernt worden sein. Taucht trotzdem etwas auf,
 * wird die Datei abgelehnt statt gespeichert - ein zweiter Blick, der nur ein
 * paar Textsuchen kostet.
 */
function svg_looks_risky(string $svg): bool
{
    $needles = [
        '<script',
        '<!doctype',
        '<!entity',
        '<foreignobject',
        '<image',
        'onload=',
        'onclick=',
        'javascript:',
        'vbscript:',
        'data:text/html',
    ];

    foreach ($needles as $needle) {
        if (stripos($svg, $needle) !== false) {
            return true;
        }
    }

    return false;
}
