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
