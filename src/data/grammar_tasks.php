<?php

declare(strict_types=1);

/**
 * Die Aufgaben der "Grammar Challenge" neben dem Schreibeditor.
 *
 * Diese Datei ist reine Beschreibung und hat keine Logik: sie gibt eine Liste von
 * Schreibaufgaben zurück, aus der die Story-Seite ihre Leiste baut. Eine neue Aufgabe ist
 * damit eine neue Zeile hier - und keine Änderung an der Vorlage, am Blatt oder am
 * Skript. Genau wie die Wissensmodule der Nachschlageseite (src/data/energy_modules.php)
 * liegen die Inhalte damit im Projekt und nicht in der Datenbank: die Datenbank trägt,
 * was die Nutzer schreiben, nicht was die Anwendung anbietet.
 *
 * Gezeigt werden auf der Seite nur `topic` und `level` (die Zeitform und ihr Niveau).
 * Anweisung, Beispielsatz und Eselsbrücke stehen trotzdem hier, damit eine Aufgabe
 * vollständig an einer Stelle liegt und die Liste die Grundlage bleibt, wenn die Aufgabe
 * wieder mehr zeigen soll.
 *
 * Aufbau einer Aufgabe
 *
 *   id              stabil und eindeutig. Sie geht als Kennung an den Browser und steht
 *                   im Fehlerfall auch im Protokoll - deshalb nie mitten in der Liste
 *                   umbenennen.
 *   topic           das Grammatikthema als Überschrift der Karte. Es ist ein Fachbegriff
 *                   und heißt in beiden Sprachen gleich ("Past Perfect"), deshalb steht es
 *                   hier als einfache Zeichenkette und nicht zweisprachig.
 *   instruction_en  die Schreibanweisung, englisch. Sie sagt, WAS in diesem Beitrag
 *                   vorkommen soll, und klingt wie eine Bitte an eine Autorin - nicht wie
 *                   eine Aufgabe aus einem Test.
 *   example_en      ein einfacher englischer Beispielsatz, der die Form zeigt.
 *   explanation_de  die kurze deutsche Erklärung. Sie bleibt in beiden Sprachen deutsch:
 *                   sie ist die Eselsbrücke für die beiden Schreiberinnen und keine
 *                   Beschriftung der Oberfläche.
 *   level           die Stufe nach dem gemeinsamen europäischen Referenzrahmen. Sie steht
 *                   als kleines "B1" hinter der Zeitform in der Leiste.
 *   sort_order      die Reihenfolge von einfach nach komplex.
 *
 * Was hier NICHT passiert
 * -----------------------
 * Es wird nicht geprüft, ob eine Anweisung eingehalten wurde. Keine Farbanalyse, keine
 * Korrektur, keine Bewertung: die Aufgabe ist ein Vorschlag, und der Beitrag darf ohne sie
 * veröffentlicht werden. Das ist Absicht und nicht eine noch fehlende Funktion.
 */

/**
 * Alle Aufgaben, in der Reihenfolge von einfach nach komplex.
 *
 * @return list<array<string, mixed>>
 */
function grammar_tasks(): array
{
    return [
        [
            'id' => 'present_simple',
            'topic' => 'Present Simple',
            'instruction_en' => 'Start with something that happens again and again in your story - a habit, a rule or a fact that simply is true.',
            'example_en' => 'Every morning the old ferry crosses the river before the sun is up.',
            'explanation_de' => 'Present Simple: was immer so ist, was regelmäßig passiert (he crosses, she waits). Signalwörter: every day, usually, never.',
            'level' => 'B1',
            'sort_order' => 1,
        ],
        [
            'id' => 'past_simple',
            'topic' => 'Past Simple',
            'instruction_en' => 'Tell the next part step by step in the past: one thing happened, then the next one.',
            'example_en' => 'The lights went out, and nobody said a word.',
            'explanation_de' => 'Past Simple: abgeschlossene Handlungen in der Vergangenheit, eine nach der anderen (went, said, opened).',
            'level' => 'B1',
            'sort_order' => 2,
        ],
        [
            'id' => 'past_continuous',
            'topic' => 'Past Continuous',
            'instruction_en' => 'Set the scene first: something was already going on when your next event began.',
            'example_en' => 'Rain was falling on the roof when the door opened.',
            'explanation_de' => 'Past Continuous: eine Handlung war im Gang (was falling), als eine andere einsetzte (when the door opened).',
            'level' => 'B1',
            'sort_order' => 3,
        ],
        [
            'id' => 'present_perfect',
            'topic' => 'Present Perfect',
            'instruction_en' => 'Let something from the past still matter for what happens now.',
            'example_en' => 'She has kept the letter in her coat since that night.',
            'explanation_de' => 'Present Perfect: die Handlung ist vorbei, ihre Wirkung reicht bis jetzt (has kept ... since/for).',
            'level' => 'B1',
            'sort_order' => 4,
        ],
        [
            'id' => 'past_perfect',
            'topic' => 'Past Perfect',
            'instruction_en' => 'Go back one step: say what had already happened before the scene you are telling.',
            'example_en' => 'He had never seen the sea before that afternoon.',
            'explanation_de' => 'Past Perfect: die Vorvergangenheit - was vor dem Erzählten schon vorbei war (had seen).',
            'level' => 'B1',
            'sort_order' => 5,
        ],
        [
            'id' => 'future_forms',
            'topic' => 'Future Forms',
            'instruction_en' => 'Give one of your characters a plan and the other a decision made on the spot.',
            'example_en' => '"We are going to leave at six," she said, "and I will call you if anything changes."',
            'explanation_de' => '"going to" für einen Plan, "will" für eine Entscheidung im Augenblick.',
            'level' => 'B1',
            'sort_order' => 6,
        ],
        [
            'id' => 'modal_verbs',
            'topic' => 'Modal Verbs',
            'instruction_en' => 'Use a modal verb to show how sure someone is, or what they are allowed to do.',
            'example_en' => 'You must not go alone, but you may take the bicycle.',
            'explanation_de' => 'Modalverben wie must, may, can, might, should: Notwendigkeit, Erlaubnis, Möglichkeit.',
            'level' => 'B1',
            'sort_order' => 7,
        ],
        [
            'id' => 'relative_clauses',
            'topic' => 'Relative Clauses',
            'instruction_en' => 'Describe a person or a thing in the same sentence instead of adding a second one.',
            'example_en' => 'The girl who was waiting at the gate held a key that nobody had seen before.',
            'explanation_de' => 'Relativsätze mit who, which, that hängen die Beschreibung an den Satz und ersetzen einen zweiten.',
            'level' => 'B1',
            'sort_order' => 8,
        ],
        [
            'id' => 'conditionals',
            'topic' => 'Conditional Sentences',
            'instruction_en' => 'Give your story one real condition and one imagined one.',
            'example_en' => 'If the boat leaves tonight, we will be there by morning. If we had a car, we would not need the boat.',
            'explanation_de' => 'Typ 1: echte Bedingung (if + present, will). Typ 2: gedachte Bedingung (if + past, would).',
            'level' => 'B1',
            'sort_order' => 9,
        ],
        [
            'id' => 'passive_voice',
            'topic' => 'Passive Voice',
            'instruction_en' => 'Let one sentence say what was done instead of who did it.',
            'example_en' => 'The window was opened from the inside, and the lamp had been left burning.',
            'explanation_de' => 'Passiv (was opened, had been left): die Handlung steht im Vordergrund, nicht die Person.',
            'level' => 'B1',
            'sort_order' => 10,
        ],
    ];
}

/**
 * Eine Aufgabe anhand ihrer Kennung.
 *
 * @param string $id die Kennung aus dem Array oben
 * @return array<string, mixed>|null null, wenn es keine Aufgabe mit dieser Kennung gibt
 */
function grammar_task_by_id(string $id): ?array
{
    foreach (grammar_tasks() as $task) {
        if ($task['id'] === $id) {
            return $task;
        }
    }

    return null;
}
