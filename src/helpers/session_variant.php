<?php

declare(strict_types=1);

/**
 * Welche Variante einer Karte zuletzt zu sehen war.
 *
 * Eine Grammatik-Karte zeigt dieselbe Regel als einen von mehreren Beispielsätzen, und beim
 * nächsten Anzeigen soll möglichst nicht derselbe Satz wiederkommen - sonst lernt man den
 * Satz statt der Regel. Dafür muss sich etwas merken, WELCHER Satz zuletzt gezeigt wurde.
 * Und zwar nur das, sonst nichts:
 *
 *   - NICHT in `user_card_progress`. Der Fortschritt gehört zur Karte, nicht zum Satz. Eine
 *     Zeile je Variante wäre eine zweite Lernkarte, die es nicht gibt.
 *   - NICHT in `card_variants`. Dort stehen die Sätze, nicht ihre Reihenfolge.
 *   - Deshalb in der PHP-Sitzung: sie lebt so lange wie der Browser, der lernt, und
 *     verschwindet von selbst. Geht sie verloren, ist die einzige Folge, dass derselbe Satz
 *     einmal wiederholt werden kann - kein Fortschritt geht dabei verloren.
 *
 * Die Sitzung wird hier NICHT gestartet. Wer Karten zu sehen bekommt, ist angemeldet, und
 * eine angemeldete Anfrage hat ihre Sitzung längst (src/helpers/session_user.php). Ohne
 * Sitzung ist die Antwort null und es wird nichts gemerkt - was nichts kaputt macht.
 */

/** Der Schlüssel in der Sitzung: Karten-Id -> Id der zuletzt gezeigten Variante. */
const SESSION_VARIANT_KEY = 'variant_last';

/**
 * Die Id der Variante, die für diese Karte zuletzt gezeigt wurde.
 *
 * @return int|null null bedeutet "für diese Karte ist nichts gemerkt"
 */
function session_variant_last(int $cardId): ?int
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return null;
    }

    $value = $_SESSION[SESSION_VARIANT_KEY][$cardId] ?? null;

    /* "12" und 12 werden beide angenommen; alles andere heißt "nichts gemerkt". */
    if (is_string($value) && ctype_digit($value)) {
        $value = (int) $value;
    }

    return is_int($value) && $value > 0 ? $value : null;
}

/**
 * Merkt, welche Variante dieser Karte gerade gezeigt wird.
 */
function session_variant_remember(int $cardId, int $variantId): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }

    $_SESSION[SESSION_VARIANT_KEY][$cardId] = $variantId;
}
