<?php

declare(strict_types=1);

/**
 * Small helpers for building HTML safely.
 */

/**
 * Escapes a value so it can be printed inside HTML or inside an HTML attribute.
 *
 * ENT_QUOTES escapes both the single and the double quote, which is what makes
 * the result safe inside an attribute such as aria-label="...".
 * Passing UTF-8 tells htmlspecialchars how to read the input, so umlauts and
 * other non-ASCII characters are not turned into broken bytes.
 */
function escape_html(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

/**
 * The URL of a file inside public/, with the time of its last change as a version.
 *
 * The stylesheet and the script are fetched once and then kept by the browser:
 * this server sends no cache headers and no "last modified", so a page that is
 * only navigated inside the application never asks for the new files, and an edit
 * stays invisible until somebody forces a reload. With the timestamp in the URL
 * every change is a new address and reaches the browser at the next page load,
 * while a file that did not change keeps the address it had - and therefore keeps
 * its place in the browser cache.
 *
 * A file that is not there gets the plain path back: a typo in a name must show up
 * as a missing file and not as a broken URL.
 */
function asset_url(string $path): string
{
    $file = __DIR__ . '/../../public/' . ltrim($path, '/');

    if (!is_file($file)) {
        return $path;
    }

    return $path . '?v=' . (int) filemtime($file);
}
