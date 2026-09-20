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
 *
 * The icon is always drawn through an <img> tag, and an <img> never runs a
 * script inside an SVG. This file is the second layer of that protection, not
 * the only one. After cleaning, the result is checked again: anything that
 * still looks dangerous is refused instead of being stored.
 *
 * The stored value also has to fit into the `icon_svg` column, which is TEXT
 * and therefore holds at most 65,535 BYTES. The Inkscape files in this project
 * are up to 113 KB of coordinates, so the drawing is compacted: whitespace is
 * collapsed and coordinates are rounded. An icon is drawn 34 px wide here, so
 * a rounding of 0.1 unit inside a ~1000 unit drawing is far below one pixel and
 * nothing visible changes.
 */

/** Largest SVG accepted for upload, before any processing. */
const SVG_MAX_UPLOAD_BYTES = 307200;

/** Largest SVG that may be written into the TEXT column. */
const SVG_MAX_STORED_BYTES = 63000;

/**
 * Elements that are removed together with everything inside them.
 *
 * The group is written as a real group (?:...) because it is used both for the
 * opening and for the closing tag; without the parentheses the closing pattern
 * would become a list of alternatives instead of one tag name.
 */
const SVG_BLOCKED_ELEMENTS = '(?:script|foreignObject|iframe|object|embed|image|feImage|audio|video|handler|listener)';

/**
 * Removes everything that could execute code or load a foreign document.
 */
function svg_strip_dangerous(string $svg): string
{
    $blocked = SVG_BLOCKED_ELEMENTS;

    // XML prolog, doctype and comments carry no drawing information.
    $svg = (string) preg_replace('/<\?xml[^>]*\?>/i', '', $svg);
    $svg = (string) preg_replace('/<!DOCTYPE[^>]*>/i', '', $svg);
    $svg = (string) preg_replace('/<!--.*?-->/s', '', $svg);

    // Blocked elements WITH their content, then the ones without a closing tag,
    // then any closing tag that is left over.
    $svg = (string) preg_replace('/<' . $blocked . '\b.*?<\/' . $blocked . '\s*>/is', '', $svg);
    $svg = (string) preg_replace('/<' . $blocked . '\b[^>]*>/is', '', $svg);
    $svg = (string) preg_replace('/<\/' . $blocked . '\s*>/is', '', $svg);

    // Editor metadata that is never part of the drawing.
    $svg = (string) preg_replace('/<metadata\b.*?<\/metadata\s*>/is', '', $svg);
    $svg = (string) preg_replace('/<sodipodi:namedview\b.*?\/?>/is', '', $svg);
    $svg = (string) preg_replace('/<inkscape:perspective\b[^>]*>/is', '', $svg);

    // <use> is allowed only when it points inside this same file.
    $svg = (string) preg_replace_callback(
        '/<use\b[^>]*\/?>/is',
        static function (array $match): string {
            return preg_match('/\b(?:xlink:)?href\s*=\s*["\']#/i', $match[0]) === 1 ? $match[0] : '';
        },
        $svg
    );

    // Event handler attributes: onclick, onload, onmouseover, ...
    $svg = (string) preg_replace('/\son[a-z]+\s*=\s*"[^"]*"/i', '', $svg);
    $svg = (string) preg_replace('/\son[a-z]+\s*=\s*\'[^\']*\'/i', '', $svg);
    $svg = (string) preg_replace('/\son[a-z]+\s*=\s*[^\s>]+/i', '', $svg);

    // Active URLs, in any attribute and in any quoting style.
    $svg = (string) preg_replace('/javascript\s*:/i', '', $svg);
    $svg = (string) preg_replace('/vbscript\s*:/i', '', $svg);
    $svg = (string) preg_replace('/data\s*:\s*text\s*\/\s*html/i', '', $svg);

    // A stylesheet inside an SVG may import something from outside.
    $svg = (string) preg_replace('/@import[^;]*;?/i', '', $svg);

    // Entities can be used to expand a document into something much larger.
    $svg = (string) preg_replace('/<!ENTITY[^>]*>/i', '', $svg);

    return $svg;
}

/**
 * Reports whether the cleaned SVG still contains something dangerous.
 *
 * This runs after cleaning on purpose. A pattern that slipped through the
 * removal rules is then refused instead of being stored and served again.
 */
function svg_looks_risky(string $svg): bool
{
    $patterns = [
        '/<script/i',
        '/<' . SVG_BLOCKED_ELEMENTS . '/i',
        '/\son[a-z]+\s*=/i',
        '/javascript\s*:/i',
        '/vbscript\s*:/i',
        '/data\s*:\s*text\s*\/\s*html/i',
        '/<!ENTITY/i',
        '/@import/i',
        // A link that does not point inside this same file.
        '/\b(?:xlink:)?href\s*=\s*["\']?(?!\s*#)/i',
    ];

    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $svg) === 1) {
            return true;
        }
    }

    return false;
}

/**
 * Rounds the numbers inside one attribute value.
 *
 * Values smaller than 1 keep three decimals by default, so a hairline stroke or
 * a small opacity does not change its weight. For path data that caution is not
 * needed - there a small number is just a coordinate - so $roundSmall is used
 * there and every number is rounded the same way.
 */
function svg_round_numbers(string $value, int $decimals = 1, bool $roundSmall = false): string
{
    return (string) preg_replace_callback(
        '/-?\d+\.\d+/',
        static function (array $match) use ($decimals, $roundSmall): string {
            $number = (float) $match[0];
            $digits = (!$roundSmall && abs($number) < 1.0) ? max($decimals, 3) : $decimals;
            $text = rtrim(rtrim(number_format($number, $digits, '.', ''), '0'), '.');

            if ($text === '' || $text === '-') {
                return '0';
            }

            return $text;
        },
        $value
    );
}

/**
 * Shrinks the file without changing how it looks.
 */
function svg_compact(string $svg, int $decimals = 1): string
{
    $svg = (string) preg_replace('/\s+/', ' ', $svg);
    $svg = (string) preg_replace('/>\s+</', '><', $svg);

    // Path data first: that is where nearly all of the bytes are.
    $svg = (string) preg_replace_callback(
        '/\bd="([^"]*)"/',
        static fn (array $match): string => 'd="' . svg_round_numbers($match[1], $decimals, true) . '"',
        $svg
    );

    // Every other attribute, with the careful rule for small values.
    return (string) preg_replace_callback(
        '/="([^"]*)"/',
        static fn (array $match): string => '="' . svg_round_numbers($match[1], $decimals) . '"',
        $svg
    );
}

/**
 * Returns a safe, compact SVG, or null when the input cannot be used.
 *
 * The result always starts with the <svg> element and ends with </svg>, so no
 * text and no markup can be smuggled in around the drawing.
 */
function svg_sanitize(string $svg): ?string
{
    $svg = trim($svg);

    if ($svg === '' || strlen($svg) > SVG_MAX_UPLOAD_BYTES || !mb_check_encoding($svg, 'UTF-8')) {
        return null;
    }

    /* Everything before the opening <svg> tag is dropped: an XML prolog, a
       doctype, comments - and any text someone put in front of the drawing. */
    $start = stripos($svg, '<svg');

    if ($start === false) {
        return null;
    }

    $svg = substr($svg, $start);

    /* Everything after the last </svg> is dropped as well. */
    $end = strripos($svg, '</svg>');

    if ($end === false) {
        return null;
    }

    $svg = svg_strip_dangerous(substr($svg, 0, $end + 6));
    $svg = svg_compact($svg);

    /* Still too large for the column? A coarser rounding of the coordinates is
       tried once, and if even that does not fit the file is refused instead of
       being stored in a shortened, broken form. */
    if (strlen($svg) > SVG_MAX_STORED_BYTES) {
        $svg = svg_compact($svg, 0);
    }

    if (strlen($svg) > SVG_MAX_STORED_BYTES || stripos($svg, '<svg') !== 0 || svg_looks_risky($svg)) {
        return null;
    }

    return $svg;
}
