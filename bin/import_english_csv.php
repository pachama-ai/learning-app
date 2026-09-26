<?php

declare(strict_types=1);

/**
 * Importiert CSV-Dateien als Unterkategorien unter einem vorhandenen Themengebiet.
 *
 *   php bin/import_english_csv.php                      # Probelauf, schreibt nichts
 *   php bin/import_english_csv.php --execute            # schreibt in die Datenbank
 *   php bin/import_english_csv.php --file=...csv --name="B1 Vokabeln" --execute
 *
 * Ohne --execute passiert nichts: das Skript liest, prueft, rechnet und zeigt,
 * was es tun WUERDE.
 *
 * Eine CSV-Datei = eine neue Unterkategorie unter dem Themengebiet (Vorgabe
 * English). Der Name steht in NAMEN; ein unbekannter Dateiname wird abgeleitet,
 * --name= ersetzt ihn.
 *
 * Karten
 *   Jede Zeile wird zu einer Karte (bei den Zeitformen zu zweien). Die vier
 *   Sprachspalten werden immer gefuellt, die deutsche Seite steht vorne:
 *
 *     front / front_de   die deutsche Seite
 *     back  / back_de    die englische Seite (oder die Erklaerung)
 *     front_en           die englische Seite
 *     back_en            die deutsche Seite
 *
 *   is_bidirectional steht in der Sorte: eine Vokabel fragt in beide
 *   Richtungen, ein Erklaerungstext nicht. map_region bleibt leer,
 *   card_exercises bekommt keine Zeile - diese Dateien enthalten keine
 *   Uebungsdaten.
 *
 * Es wird kein Schema angefasst und nichts Bestehendes geaendert oder geloescht:
 * nur INSERT in categories und cards, je Datei in einer Transaktion. Heisst die
 * Unterkategorie schon so, bricht das Skript fuer diese Datei ab und sagt es.
 */

require_once __DIR__ . '/../src/config/database.php';

/* -------------------------------------------------------------------------
   Der Name der Unterkategorie je Datei. Ohne "Englisch" davor - das steht im
   Breadcrumb - und mit Umlauten, weil es der sichtbare Name ist.
   ------------------------------------------------------------------------- */
const NAMEN = [
    'b1_vokabelliste_500_bereinigt.csv' => 'B1 Vokabelliste',
    'b2_vokabelliste_500_bereinigt.csv' => 'B2 Vokabelliste',
    'c1_vokabelliste_500_bereinigt.csv' => 'C1 Vokabelliste',
    'c2_vokabelliste_500_bereinigt.csv' => 'C2 Vokabelliste',
    'tennet_energie_fachvokabular_mit_kategorien_bereinigt.csv' => 'Energie-Fachvokabular',
    'englische_redewendungen_200_bereinigt.csv' => 'Redewendungen',
    'unregelmaessige_verben_gesamt_bereinigt.csv' => 'Unregelmäßige Verben',
    'englische_zeiten_uebersicht_bereinigt.csv' => 'Zeitformen',
];

/* -------------------------------------------------------------------------
   Die bekannten Kopfzeilen.

   'braucht'   Spalten, an denen die Sorte erkannt wird
   'englisch'  die englische Seite
   'deutsch'   die deutsche Seite
   'gegenrichtung' darf die Karte auch rueckwaerts gefragt werden?
   ------------------------------------------------------------------------- */
const SORTEN = [
    'vokabeln' => [
        'braucht' => ['English', 'Deutsch'],
        'englisch' => 'English',
        'deutsch' => 'Deutsch',
        'gegenrichtung' => true,
    ],
    'redewendung' => [
        'braucht' => ['English expression', 'Deutsch'],
        'englisch' => 'English expression',
        'deutsch' => 'Deutsch',
        'beispiel' => 'Example',
        'gegenrichtung' => false,
    ],
    'verben' => [
        'braucht' => ['Infinitive', 'Simple Past'],
        'englisch' => 'Infinitive',
        'deutsch' => 'Deutsch',
        'formen' => ['Simple Past', 'Past Participle'],
        'gegenrichtung' => true,
    ],
    'zeiten' => [
        'braucht' => ['Zeitform / Form', 'Deutsch'],
        'englisch' => 'Zeitform / Form',
        'deutsch' => 'Deutsch',
        'gegenrichtung' => false,
        'zwei_karten' => true,
    ],
    'fertig' => [
        'braucht' => ['front_de', 'back_de'],
        'fertig' => true,
        'gegenrichtung' => false,
    ],
];

/* -------------------------------------------------------------------------
   Argumente
   ------------------------------------------------------------------------- */
$optionen = [
    'dir' => dirname(__DIR__) . '/database/import',
    'area' => 'English',
    'name' => null,
    'file' => null,
    'ausfuehren' => false,
    'owner' => null,
];

foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--execute') {
        $optionen['ausfuehren'] = true;
        continue;
    }

    if ($argument === '--dry-run') {
        $optionen['ausfuehren'] = false;
        continue;
    }

    if (preg_match('/^--(dir|area|name|file|owner)=(.*)$/u', $argument, $treffer) === 1) {
        $optionen[$treffer[1]] = $treffer[2];
        continue;
    }

    fwrite(STDERR, 'Unbekannte Angabe: ' . $argument . PHP_EOL);
    fwrite(STDERR, 'Erlaubt: --execute, --dry-run, --dir=..., --area=..., --name=..., --file=..., --owner=...' . PHP_EOL);

    exit(2);
}

/* -------------------------------------------------------------------------
   Helfer
   ------------------------------------------------------------------------- */

function meldung(string $text): void
{
    echo $text . PHP_EOL;
}

function titelzeile(string $text): void
{
    meldung('');
    meldung(str_repeat('=', 76));
    meldung($text);
    meldung(str_repeat('=', 76));
}

function kurz(string $text, int $laenge = 100): string
{
    return str_replace("\n", ' | ', mb_strimwidth($text, 0, $laenge, '...'));
}

/**
 * Liest eine CSV-Datei als Liste von Zeilen mit Spaltennamen.
 *
 * Die Dateien sind UTF-8 mit BOM, durch Semikola getrennt, mit CRLF. Der BOM
 * faellt weg, sonst hiesse die erste Spalte "\xEF\xBB\xBFEnglish"; getrennt wird
 * mit str_getcsv, damit ein Semikolon IN einem Feld ("aufgeben; verlassen") die
 * Zeile nicht auseinanderreisst.
 *
 * @return array{header: list<string>, zeilen: list<array<string, string>>}
 */
function csv_lesen(string $pfad): array
{
    $inhalt = file_get_contents($pfad);

    if ($inhalt === false) {
        throw new RuntimeException('Datei nicht lesbar: ' . $pfad);
    }

    if (str_starts_with($inhalt, "\xEF\xBB\xBF")) {
        $inhalt = substr($inhalt, 3);
    }

    $inhalt = str_replace(["\r\n", "\r"], "\n", $inhalt);
    $header = null;
    $daten = [];

    foreach (explode("\n", $inhalt) as $zeile) {
        if (trim($zeile) === '') {
            continue;
        }

        $felder = str_getcsv($zeile, ';', '"', '\\');

        if ($header === null) {
            $header = array_map(static fn ($name) => trim((string) $name), $felder);
            continue;
        }

        $eintrag = [];

        foreach ($header as $nummer => $spalte) {
            $eintrag[$spalte] = isset($felder[$nummer]) ? trim((string) $felder[$nummer]) : '';
        }

        $daten[] = $eintrag;
    }

    return ['header' => $header ?? [], 'zeilen' => $daten];
}

/**
 * Erkennt die Sorte einer Datei an ihrer Kopfzeile.
 *
 * @param list<string> $header
 * @return array<string, mixed>|null
 */
function sorte_finden(array $header): ?array
{
    foreach (SORTEN as $name => $sorte) {
        $passt = true;

        foreach ($sorte['braucht'] as $spalte) {
            if (!in_array($spalte, $header, true)) {
                $passt = false;
                break;
            }
        }

        if ($passt) {
            return $sorte + ['name' => $name];
        }
    }

    return null;
}

/**
 * Die deutsche und die englische Seite einer Zeile.
 *
 * @param array<string, string> $zeile
 * @param array<string, mixed> $sorte
 * @return array{deutsch: string, englisch: string}|null
 */
function seiten_bauen(array $zeile, array $sorte): ?array
{
    if (isset($sorte['fertig']) && $sorte['fertig'] === true) {
        $deutsch = $zeile['front_de'] ?? '';
        $englisch = $zeile['back_de'] ?? ($zeile['front_en'] ?? '');

        return $deutsch === '' || $englisch === '' ? null : ['deutsch' => $deutsch, 'englisch' => $englisch];
    }

    $englisch = $zeile[$sorte['englisch']] ?? '';
    $deutsch = $zeile[$sorte['deutsch']] ?? '';

    if ($englisch === '' || $deutsch === '') {
        return null;
    }

    /* Unregelmaessige Verben: beide Formen gehoeren in die Antwort, damit die
       Rueckseite fuer sich verstaendlich ist. */
    if (isset($sorte['formen'])) {
        $teile = [];

        foreach ($sorte['formen'] as $spalte) {
            $wert = $zeile[$spalte] ?? '';

            if ($wert !== '') {
                $teile[] = $spalte . ': ' . $wert;
            }
        }

        $teile[] = 'Deutsch: ' . $deutsch;

        /* Vorne steht der Infinitiv - mit der deutschen Bedeutung als Stuetze,
           damit keine Karte ohne erkennbaren Sinn entsteht. */
        return ['deutsch' => $englisch . ' (' . $deutsch . ')', 'englisch' => implode("\n", $teile), 'vorne_englisch' => true];
    }

    /* Redewendungen: der Beispielsatz steht unter der deutschen Bedeutung. */
    if (isset($sorte['beispiel']) && ($zeile[$sorte['beispiel']] ?? '') !== '') {
        $deutsch .= "\n" . $zeile[$sorte['beispiel']];
    }

    return ['deutsch' => $deutsch, 'englisch' => $englisch];
}

/**
 * Die Zeitformen ergeben ZWEI Karten je Zeile: eine fuer die Bildung, eine fuer
 * die Verwendung. So steht auf jeder Karte genau eine Frage.
 *
 * @param array<string, string> $zeile
 * @return list<array{deutsch: string, englisch: string}>
 */
function zeiten_karten(array $zeile): array
{
    $form = $zeile['Zeitform / Form'] ?? '';

    if ($form === '') {
        return [];
    }

    $bildung = $zeile['Bildung'] ?? '';
    $karten = [];

    if ($bildung !== '') {
        $karten[] = [
            'deutsch' => $form . ' - Bildung',
            'englisch' => 'Bildung: ' . $bildung,
        ];
    }

    $teile = [];

    foreach ([
        'Wann verwenden?' => 'Wann',
        'Typische Signalwörter/Hinweise' => 'Signalwörter',
        'Beispiel' => 'Beispiel',
        'Wichtige Abgrenzung' => 'Abgrenzung',
    ] as $spalte => $wort) {
        $wert = $zeile[$spalte] ?? '';

        if ($wert !== '') {
            $teile[] = $wort . ': ' . $wert;
        }
    }

    if ($teile !== []) {
        $karten[] = [
            'deutsch' => $form . ' - Verwendung',
            'englisch' => implode("\n", $teile),
        ];
    }

    return $karten;
}

/**
 * Der Name der Unterkategorie: erst die feste Liste, dann --name=, dann
 * abgeleitet aus dem Dateinamen.
 */
function kategorie_name(string $datei, ?string $vorgabe): string
{
    if (is_string($vorgabe) && trim($vorgabe) !== '') {
        return trim($vorgabe);
    }

    if (isset(NAMEN[$datei])) {
        return NAMEN[$datei];
    }

    $name = str_replace('_bereinigt', '', pathinfo($datei, PATHINFO_FILENAME));
    $teile = preg_split('/[_\s]+/u', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $sauber = [];

    foreach ($teile as $teil) {
        if (preg_match('/^(a1|a2|b1|b2|c1|c2)$/i', $teil) === 1) {
            $sauber[] = strtoupper($teil);
            continue;
        }

        if (preg_match('/^\d+$/', $teil) === 1) {
            continue;
        }

        $sauber[] = mb_convert_case($teil, MB_CASE_TITLE, 'UTF-8');
    }

    return implode(' ', $sauber);
}

/* -------------------------------------------------------------------------
   Dateien sammeln
   ------------------------------------------------------------------------- */
$dateien = [];

if (is_string($optionen['file']) && $optionen['file'] !== '') {
    $dateien[] = $optionen['file'];
} else {
    foreach (glob(rtrim($optionen['dir'], '/') . '/*.csv') ?: [] as $treffer) {
        $dateien[] = $treffer;
    }

    sort($dateien);
}

if ($dateien === []) {
    fwrite(STDERR, 'Keine CSV-Datei gefunden in ' . $optionen['dir'] . PHP_EOL);

    exit(1);
}

titelzeile($optionen['ausfuehren'] ? 'IMPORT (schreibt in die Datenbank)' : 'PROBELAUF (schreibt nichts)');
meldung('Ordner : ' . $optionen['dir']);
meldung('Dateien: ' . count($dateien));

$pdo = create_database_connection();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

/*
 * Der Besitzer: jede Kategorie braucht eine owner_user_id. Der Wert kommt aus
 * --owner=<id> und wird geprueft, bevor irgendetwas geschrieben wird - so
 * laeuft der Import nie in einen fremden Bestand hinein.
 */
if ($optionen['owner'] === null || preg_match('/^\d+$/', (string) $optionen['owner']) !== 1) {
    fwrite(STDERR, 'Bitte --owner=<id> angeben (Zahl, id aus der Tabelle users).' . PHP_EOL);

    exit(2);
}

$ownerId = (int) $optionen['owner'];

$besitzer = $pdo->prepare('SELECT id FROM users WHERE id = :id');
$besitzer->execute([':id' => $ownerId]);

if ($besitzer->fetchColumn() === false) {
    fwrite(STDERR, 'Benutzer nicht gefunden: id ' . $ownerId . PHP_EOL);

    exit(1);
}

meldung('Besitzer: id ' . $ownerId);

/* Drei eigene Platzhalter: mit echter Vorbereitung (EMULATE_PREPARES = false)
   darf derselbe benannte Platzhalter nicht mehrfach in einem Befehl stehen. */
$bereich = $pdo->prepare(
    'SELECT id, name, name_de FROM categories
      WHERE parent_id IS NULL
        AND owner_user_id = :owner_user_id
        AND (name = :name OR name_en = :name_en OR name_de = :name_de)
      LIMIT 1'
);
$bereich->execute([
    ':owner_user_id' => $ownerId,
    ':name' => $optionen['area'],
    ':name_en' => $optionen['area'],
    ':name_de' => $optionen['area'],
]);
$bereichsZeile = $bereich->fetch();

if ($bereichsZeile === false) {
    fwrite(STDERR, 'Themengebiet nicht gefunden: ' . $optionen['area'] . PHP_EOL);

    exit(1);
}

meldung('Bereich: id ' . $bereichsZeile['id'] . ' (' . $bereichsZeile['name'] . ' / ' . $bereichsZeile['name_de'] . ')');

$pruefen = $pdo->prepare(
    'SELECT id FROM categories WHERE parent_id = :parent_id AND name = :name AND owner_user_id = :owner_user_id LIMIT 1'
);
$bericht = [];
$gesamtKarten = 0;

foreach ($dateien as $pfad) {
    $dateiName = basename($pfad);

    try {
        $csv = csv_lesen($pfad);
    } catch (Throwable $fehler) {
        $bericht[] = ['datei' => $dateiName, 'zustand' => 'uebersprungen', 'grund' => $fehler->getMessage()];

        continue;
    }

    $sorte = sorte_finden($csv['header']);

    if ($sorte === null) {
        $bericht[] = [
            'datei' => $dateiName,
            'zustand' => 'uebersprungen',
            'grund' => 'Kopfzeile unbekannt: ' . implode('; ', $csv['header']),
        ];

        continue;
    }

    $name = kategorie_name($dateiName, $optionen['name']);

    $pruefen->execute([
        ':parent_id' => $bereichsZeile['id'],
        ':name' => $name,
        ':owner_user_id' => $ownerId,
    ]);

    if ($pruefen->fetchColumn() !== false) {
        $bericht[] = [
            'datei' => $dateiName,
            'zustand' => 'uebersprungen',
            'grund' => 'Unterkategorie "' . $name . '" gibt es unter ' . $bereichsZeile['name'] . ' schon - bitte --name= angeben',
        ];

        continue;
    }

    $karten = [];
    $uebersprungen = [];

    foreach ($csv['zeilen'] as $nummer => $zeile) {
        if (isset($sorte['zwei_karten'])) {
            $paar = zeiten_karten($zeile);

            if ($paar === []) {
                $uebersprungen[] = 'Zeile ' . ($nummer + 2) . ': Zeitform oder Inhalt leer';

                continue;
            }

            foreach ($paar as $eine) {
                $karten[] = $eine;
            }

            continue;
        }

        $seiten = seiten_bauen($zeile, $sorte);

        if ($seiten === null) {
            $uebersprungen[] = 'Zeile ' . ($nummer + 2) . ': eine der beiden Seiten ist leer';

            continue;
        }

        $karten[] = $seiten;
    }

    /* Die Summe zaehlt in beiden Faellen gleich: der Probelauf soll dieselben
       Zahlen zeigen wie der echte Lauf. */
    $gesamtKarten += count($karten);

    $bericht[] = [
        'datei' => $dateiName,
        'zustand' => $optionen['ausfuehren'] ? 'geschrieben' : 'geprueft',
        'name' => $name,
        'sorte' => $sorte['name'],
        'gegenrichtung' => $sorte['gegenrichtung'] === true ? 1 : 0,
        'zeilen' => count($csv['zeilen']),
        'karten' => count($karten),
        'beispiel' => array_slice($karten, 0, isset($sorte['zwei_karten']) ? 2 : 1),
        'uebersprungen' => $uebersprungen,
    ];

    if (!$optionen['ausfuehren']) {
        continue;
    }

    $pdo->beginTransaction();

    try {
        $einfuegenKategorie = $pdo->prepare(
            'INSERT INTO categories (parent_id, name, name_en, name_de, owner_user_id)
             VALUES (:parent_id, :name, :name_en, :name_de, :owner_user_id)'
        );
        $einfuegenKategorie->execute([
            ':parent_id' => $bereichsZeile['id'],
            ':name' => $name,
            ':name_en' => $name,
            ':name_de' => $name,
            ':owner_user_id' => $ownerId,
        ]);

        $kategorieId = (int) $pdo->lastInsertId();

        $einfuegenKarte = $pdo->prepare(
            'INSERT INTO cards (category_id, front, back, front_de, back_de, front_en, back_en, is_bidirectional)
             VALUES (:category_id, :front, :back, :front_de, :back_de, :front_en, :back_en, :gegenrichtung)'
        );

        foreach ($karten as $karte) {
            /*
             * front/front_de tragen die deutsche Seite, back/back_de die andere.
             * front_en/back_en spiegeln dasselbe Paar: eine Vokabelkarte ist in
             * beiden Sprachen dieselbe Karte mit getauschten Rollen.
             */
            $einfuegenKarte->execute([
                ':category_id' => $kategorieId,
                ':front' => $karte['deutsch'],
                ':back' => $karte['englisch'],
                ':front_de' => $karte['deutsch'],
                ':back_de' => $karte['englisch'],
                ':front_en' => $karte['englisch'],
                ':back_en' => $karte['deutsch'],
                ':gegenrichtung' => $sorte['gegenrichtung'] === true ? 1 : 0,
            ]);
        }

        $pdo->commit();
        $bericht[count($bericht) - 1]['kategorie_id'] = $kategorieId;
    } catch (Throwable $fehler) {
        $pdo->rollBack();
        $bericht[count($bericht) - 1]['zustand'] = 'fehlgeschlagen';
        $bericht[count($bericht) - 1]['grund'] = $fehler->getMessage();
    }
}

/* -------------------------------------------------------------------------
   Zusammenfassung
   ------------------------------------------------------------------------- */
titelzeile('Zusammenfassung');
meldung(sprintf('%-52s %-11s %7s %7s %6s  %s', 'Datei', 'Zustand', 'Zeilen', 'Karten', 'ID', 'Name der Unterkategorie'));
meldung(str_repeat('-', 76));

foreach ($bericht as $zeile) {
    meldung(sprintf(
        '%-52s %-11s %7s %7s %6s  %s',
        mb_strimwidth($zeile['datei'], 0, 50, '..'),
        $zeile['zustand'],
        isset($zeile['zeilen']) ? (string) $zeile['zeilen'] : '-',
        isset($zeile['karten']) ? (string) $zeile['karten'] : '-',
        isset($zeile['kategorie_id']) ? (string) $zeile['kategorie_id'] : '-',
        $zeile['name'] ?? ''
    ));

    if (isset($zeile['sorte'])) {
        meldung('      Vorlage ' . $zeile['sorte'] . ', is_bidirectional = ' . $zeile['gegenrichtung']);
    }

    foreach ($zeile['beispiel'] ?? [] as $nummer => $karte) {
        meldung('      Karte ' . ($nummer + 1) . ' vorne : ' . kurz($karte['deutsch']));
        meldung('      Karte ' . ($nummer + 1) . ' hinten: ' . kurz($karte['englisch'], 160));
    }

    if (isset($zeile['grund'])) {
        meldung('      Grund: ' . $zeile['grund']);
    }

    foreach ($zeile['uebersprungen'] ?? [] as $grund) {
        meldung('      uebersprungen: ' . $grund);
    }

    meldung('');
}

meldung('Karten gesamt: ' . $gesamtKarten);

if (!$optionen['ausfuehren']) {
    meldung('Probelauf: es wurde NICHTS geschrieben. Zum Schreiben --execute anhaengen.');
}
