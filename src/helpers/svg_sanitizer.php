<?php

declare(strict_types=1);

/**
 * Sanitises an SVG before it is stored in the database.
 *
 * An uploaded icon is untrusted input, exactly like a text field. It is stored
 * in the database and later served again, so everything dangerous is removed
 * before it is written:
 *   - <script> and event handlers (onclick="...") can run code
 *   - <foreignObject>, <iframe>, <object>, <embed> can pull in a whole document
 *   - <image> and <feImage> can fetch a file from another server
 *   - "javascript:" and "data:text/html" URLs can run code when they are opened
 *   - <use href="http://..."> can load something from outside this file
 *   - a doctype or an entity definition can make a small file explode into a
 *     huge one or load something from outside
 *
 * The icon is always drawn through an <img> tag, and an <img> never runs a
 * script inside an SVG. This file is the second layer of that protection, not
 * the only one.
 *
 * HOW IT WORKS
 *
 * The file is parsed with a real XML parser (libxml through DOMDocument), not
 * with text patterns. That has three consequences, and all three are wanted:
 *
 *   1. It is fast and predictable. A 350 KB drawing is one parse and one walk
 *      over its elements; no pattern has to scan the whole text again and
 *      again, and nothing can backtrack.
 *   2. A file that is not valid XML is refused instead of being patched with
 *      text replacements. A half repaired drawing is worse than no drawing.
 *   3. Only the <svg> element is written back (saveXML of the root element).
 *      Text before or after the drawing cannot survive, because it was never
 *      part of the element that is written.
 *
 * Character references are resolved by the parser before the checks run, so
 * "&#106;avascript:" cannot hide anything.
 *
 * The stored value does not have to be squeezed into 65 KB any more: the
 * `icon_svg` column is MEDIUMTEXT now, so a drawing is stored as it is drawn -
 * nothing is rounded or shortened. The limit is the upload limit.
 */

/** Largest SVG accepted for upload: 350 KB (358 400 bytes). */
const SVG_MAX_UPLOAD_BYTES = 358400;

/**
 * Nothing larger than this is written into the column. It is the same number as
 * the upload limit: the parser only ever makes a file smaller (it drops what is
 * not allowed), never larger.
 */
const SVG_MAX_STORED_BYTES = 358400;

/**
 * Elements that are removed together with everything inside them.
 *
 * Compared in lower case, so the list is written in lower case.
 */
const SVG_BLOCKED_ELEMENTS = [
    'script',
    'foreignobject',
    'iframe',
    'object',
    'embed',
    'image',
    'feimage',
    'audio',
    'video',
    'handler',
    'listener',
    'metadata',
];

/**
 * The same names as a lookup table.
 *
 * A drawing of 350 KB has thousands of elements, so "is this name blocked?" is
 * asked thousands of times. A key lookup answers that in one step instead of
 * walking a list.
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
 * Returns a safe SVG, or null when the input cannot be used.
 *
 * The caller only has to know these two answers: the drawing is usable, or it
 * is not. Every reason to refuse ends in null.
 */
function svg_sanitize(string $svg): ?string
{
    $svg = trim($svg);

    if ($svg === '' || strlen($svg) > SVG_MAX_UPLOAD_BYTES || !mb_check_encoding($svg, 'UTF-8')) {
        return null;
    }

    /*
     * A doctype or an entity definition is refused before the parser sees it.
     * These are the two constructs that could load something from outside or
     * expand into a much larger document, and no icon needs them. The check is
     * a plain text search, so it costs nothing.
     */
    if (stripos($svg, '<!doctype') !== false || stripos($svg, '<!entity') !== false) {
        return null;
    }

    $document = new DOMDocument();

    /*
     * libxml reports through its own error queue while it parses; that queue is
     * emptied here because this function answers with null, not with a warning
     * on the page. LIBXML_NONET forbids every network access while parsing.
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
 * Walks the children of one element: comments, the blocked elements and the
 * dangerous attributes are removed, the rest is checked one level deeper.
 *
 * The walk is written iteratively over the siblings (not with a fixed depth
 * limit), so a deeply nested drawing cannot stop it.
 */
function svg_clean_children(DOMElement $parent): void
{
    $child = $parent->firstChild;

    while ($child !== null) {
        $next = $child->nextSibling;

        if ($child instanceof DOMComment || $child instanceof DOMProcessingInstruction) {
            /* Editor comments and processing instructions carry no drawing. */
            $parent->removeChild($child);
            $child = $next;
            continue;
        }

        if ($child instanceof DOMElement) {
            $name = strtolower($child->localName);

            if (isset(SVG_BLOCKED_ELEMENT_LOOKUP[$name])) {
                /* Removed with everything inside it. */
                $parent->removeChild($child);
                $child = $next;
                continue;
            }

            if ($name === 'use' && !svg_use_points_inside($child)) {
                /* <use> may only reuse something from this very file. */
                $parent->removeChild($child);
                $child = $next;
                continue;
            }

            if ($name === 'style' && stripos($child->textContent, '@import') !== false) {
                /* A stylesheet that pulls in another file. */
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
 * Removes the dangerous attributes of one element.
 *
 * The names are collected first and removed afterwards: an attribute list is
 * not changed while it is being read.
 */
function svg_clean_element(DOMElement $element): void
{
    $remove = [];

    foreach ($element->attributes as $attribute) {
        $name = strtolower($attribute->nodeName);
        $value = (string) $attribute->nodeValue;

        /* Event handlers: onclick, onload, onmouseover, ... */
        if (strlen($name) > 2 && strpos($name, 'on') === 0) {
            $remove[] = $attribute->nodeName;
            continue;
        }

        /* A URL that could run code, in any attribute and any quoting style.
           Character references were already resolved by the parser, so nothing
           can hide behind &#106;avascript: */
        if (svg_text_is_dangerous($value)) {
            $remove[] = $attribute->nodeName;
            continue;
        }

        /* A link that leaves this file. The one exception is a reference to an
           element inside the same drawing, which is how <use> works. */
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
 * Reports whether a <use> element points at something inside this same file.
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
 * Reports whether a value contains something that could run code.
 *
 * Whitespace and control characters are taken out before the search, so
 * "java\nscript:" is found as well.
 */
function svg_text_is_dangerous(string $value): bool
{
    /*
     * Nearly every value in a drawing is a number, a colour or path data - and
     * none of them contains a colon. A URL scheme always does, so this one
     * comparison answers most of the thousands of attributes of a 350 KB file
     * without doing any work at all.
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
 * The last look at the result, before it is stored and served again.
 *
 * Everything above should have removed all of this already. Should one of these
 * ever appear here, the file is refused instead of being stored - a second look
 * that costs a few plain text searches and no pattern matching.
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
