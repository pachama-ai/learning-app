<?php

declare(strict_types=1);

/**
 * Wer die gemeinsame Geschichte schreiben darf.
 *
 * Die beiden Konten stehen hier an GENAU EINER Stelle, weil drei Dinge dieselbe Liste
 * brauchen: die Startseite (soll sie die Kachel zeigen?), die Geschichte-Seite (darf
 * diese Sitzung überhaupt hier sein?) und der Endpunkt (darf dieser Beitrag gespeichert
 * werden?). Zwei Listen wären zwei Wahrheiten, und die zweite wäre irgendwann falsch.
 *
 * Der Schlüssel ist die echte Nutzer-Id aus der Tabelle `users`, der Wert die Rolle im
 * Text. Die Rollen sind absichtlich keine Namen: wie die beiden im Text heißen, steht in
 * src/helpers/translations.php ('story.author.xenia' usw.), und das ist der einzige Ort,
 * an dem ein sichtbarer Name steht.
 *
 *   id 6  selina.schneider        selina.schneider@gmail.com       (Selina)
 *   id 8  schneider.xenia         schneider.xenia@googlemail.com   (Xenia)
 *
 * Achtung: Die Ids sind in dieser Datenbank geprüft. Die Prüfung selbst hängt nicht an
 * der Id, sondern an ihr UND an der Sitzung: die Id kommt ausschließlich aus der
 * angemeldeten Sitzung des Servers (src/helpers/session_user.php) und nie aus einer
 * Anfrage.
 */

/** @var array<int, string> Nutzer-Id => Rolle im Text */
const STORY_WRITERS = [
    6 => 'selina',
    8 => 'xenia',
];

/**
 * Die Rolle einer Nutzer-Id, oder null, wenn dieses Konto nicht mitschreibt.
 *
 * @param int|null $userId die Id aus der Sitzung
 * @return string|null 'selina', 'xenia' oder null
 */
function story_writer_key(?int $userId): ?string
{
    if ($userId === null) {
        return null;
    }

    return STORY_WRITERS[$userId] ?? null;
}

/**
 * Die andere Rolle. Bei zwei Schreibern ist das genau eine; die Funktion zählt trotzdem,
 * damit eine dritte Zeile in STORY_WRITERS nicht stillschweigend etwas Falsches tut.
 *
 * @param string $key 'selina' oder 'xenia'
 * @return string|null
 */
function story_other_writer(string $key): ?string
{
    foreach (STORY_WRITERS as $candidate) {
        if ($candidate !== $key) {
            return $candidate;
        }
    }

    return null;
}

/**
 * Alle Rollen in der Reihenfolge der Liste.
 *
 * @return list<string>
 */
function story_writer_keys(): array
{
    return array_values(array_unique(STORY_WRITERS));
}
