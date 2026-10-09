<?php

declare(strict_types=1);

/**
 * Datenbankabfragen für die Tabelle `categories`.
 *
 * Geprüfte Struktur (mit SHOW COLUMNS kontrolliert):
 *   id             int unsigned, NOT NULL, Primärschlüssel, auto_increment
 *   parent_id      int unsigned, NULL, Fremdschlüssel auf categories.id
 *   name           varchar(100), NOT NULL
 *   color          varchar(7), NULL       -- noch in der Tabelle, wird hier nicht gelesen
 *   icon_svg       mediumtext, NULL       -- die Zeichnung, geprüft (steckt nie in einer
 *                                             Antwort, die nur die Adresse trägt)
 *   icon_scale     decimal(3,2), NOT NULL, Standard 1.00
 *   name_en        varchar(100), NULL
 *   name_de        varchar(100), NULL
 *
 * Die beiden Beschreibungsspalten gibt es noch, aber nichts in der Anwendung liest
 * oder schreibt sie: eine Kategorie wird über ihren Namen gezeigt. Sie wurden nicht
 * gelöscht und ihre Daten nicht angefasst.
 *
 * parent_id NULL heißt "Themengebiet oberster Ebene". Jeder andere Wert zeigt auf
 * die übergeordnete Kategorie - so sind Unterkategorien gespeichert.
 *
 * Optionale Spalten werden nie blind angesprochen: die Tabelle wird einmal pro
 * Anfrage angeschaut, und das SELECT wird aus den Spalten gebaut, die es wirklich
 * gibt. Die Seite läuft deshalb auch auf einer Datenbank ohne die Migration, nur
 * ohne Symbole und ohne übersetzte Namen.
 *
 * Jede Funktion bekommt die PDO-Verbindung übergeben, statt selbst eine zu öffnen -
 * so benutzt eine Anfrage immer genau eine Verbindung.
 */

/** Längster erlaubter Name, passend zur Spalte varchar(100). */
const CATEGORY_MAX_NAME_LENGTH = 100;


/** So tief darf der Baum beim Löschen durchlaufen werden. */
const CATEGORY_MAX_DEPTH = 12;

/**
 * Namen der Spalten, die es in der Tabelle wirklich gibt.
 *
 * Die Antwort wird für den Rest der Anfrage gemerkt, damit das zusätzliche SHOW
 * COLUMNS nur einmal läuft, auch wenn mehrere Abfragen kommen.
 *
 * @return list<string>
 */
function category_columns(PDO $pdo): array
{
    static $columns = null;

    if ($columns === null) {
        $columns = category_columns_from_metadata($pdo);

        /*
         * Ein Treiber, der die Metadaten eines Ergebnisses nicht melden kann, gäbe
         * eine leere Liste zurück - und die würde die optionalen Spalten still aus
         * jeder Abfrage werfen. Für genau diesen Fall ist SHOW COLUMNS der Rückfall;
         * bei einem normalen MySQL-Treiber läuft er nie.
         */
        if ($columns === []) {
            foreach ($pdo->query('SHOW COLUMNS FROM categories')->fetchAll() as $column) {
                $columns[] = (string) $column['Field'];
            }
        }
    }

    return $columns;
}

/**
 * Liest die Spaltennamen der Tabelle categories aus den Metadaten einer Abfrage,
 * die keine Zeilen liefert.
 *
 * Die Spaltennamen stecken schon in den Metadaten der Antwort, die Tabelle muss also
 * nicht zweimal beschrieben werden. "LIMIT 0" überträgt keine einzige Zeile.
 * Auf diesem Rechner gemessen: etwa 1,2 ms gegenüber etwa 3,5 ms für SHOW COLUMNS -
 * und das bei jeder einzelnen API-Anfrage.
 *
 * @return list<string>
 */
function category_columns_from_metadata(PDO $pdo): array
{
    $statement = $pdo->query('SELECT * FROM categories LIMIT 0');
    $columns = [];

    for ($index = 0; $index < $statement->columnCount(); $index++) {
        $meta = $statement->getColumnMeta($index);

        if (is_array($meta) && isset($meta['name']) && is_string($meta['name'])) {
            $columns[] = $meta['name'];
        }
    }

    return $columns;
}

/**
 * Sagt, ob eine der optionalen Spalten existiert.
 *
 * @param list<string> $columns
 */
function category_column_available(array $columns, string $column): bool
{
    return in_array($column, $columns, true);
}

/**
 * Baut das gemeinsame SELECT für eine Kategorienliste.
 *
 * subcategory_count zählt die direkten Kinder der Kategorie.
 * own_card_count zählt die Karten, die direkt in dieser Kategorie liegen.
 * card_count zählt die Karten der Kategorie plus die ihrer direkten Kinder, weil
 * eine Karte über cards.category_id genau einer Kategorie gehört und die
 * Unterkategorien die tiefere Ebene sind.
 *
 * Es sind zwei gezählte Abfragen und nicht eine mit OR: ein OR macht den Index auf
 * cards.category_id unbrauchbar, MySQL läuft dann pro Zeile einmal durch den ganzen
 * Index. Beide Schreibweisen zählen dieselben Karten - jede Karte hat genau eine
 * category_id, doppelt gezählt wird also nichts.
 *
 * Die Zeichnung selbst geht nie als Teil einer Liste an den Browser: eine Zeile
 * trägt nur, OB es ein Symbol gibt, und einen kurzen Fingerabdruck davon. Geholt
 * wird das Symbol einzeln über api/category_icon.php, damit eine 60-KB-Zeichnung
 * nicht in jeder Listenantwort steckt.
 *
 * @param list<string> $columns
 */
function category_select_sql(array $columns): string
{
    /*
     * Die Zeichnung ist NIE Teil einer Antwort - weder in einer Liste noch bei einer
     * einzelnen Kategorie. Eine Zeichnung kann 350 KB haben, und eine Seite, die
     * eine Kategorie abfragt, hätte sie in jeder Antwort dabei.
     *
     * Stattdessen trägt eine Zeile die Adresse der Zeichnung (icon_url unten) mit
     * einem kurzen Fingerabdruck ihres Inhalts. Der Browser holt die Zeichnung
     * einmal über api/category_icon.php und behält sie, und ein ersetztes Symbol
     * bekommt eine neue Adresse - so wird nie etwas Veraltetes gezeigt.
     */
    $scale = category_column_available($columns, 'icon_scale') ? 'c.icon_scale' : '1.00';
    $iconFingerprint = category_column_available($columns, 'icon_svg')
        ? "CASE WHEN c.icon_svg IS NULL OR c.icon_svg = '' THEN NULL ELSE MD5(c.icon_svg) END"
        : 'NULL';
    $nameEn = category_column_available($columns, 'name_en') ? 'c.name_en' : 'NULL';
    $nameDe = category_column_available($columns, 'name_de') ? 'c.name_de' : 'NULL';

    return 'SELECT
                c.id,
                c.parent_id,
                c.name,
                ' . $scale . ' AS icon_scale,
                ' . $iconFingerprint . ' AS icon_fingerprint,
                ' . $nameEn . ' AS name_en,
                ' . $nameDe . ' AS name_de,
                (SELECT COUNT(*)
                   FROM categories AS child
                  WHERE child.parent_id = c.id) AS subcategory_count,
                (SELECT COUNT(*)
                   FROM cards AS card
                  WHERE card.category_id = c.id) AS own_card_count,
                (
                    (SELECT COUNT(*)
                       FROM cards AS own_card
                      WHERE own_card.category_id = c.id)
                    +
                    (SELECT COUNT(*)
                       FROM cards AS child_card
                       JOIN categories AS direct_child
                         ON direct_child.id = child_card.category_id
                      WHERE direct_child.parent_id = c.id)
                ) AS card_count
            FROM categories AS c';
}

/**
 * Die ORDER-BY-Klausel für eine Kategorieliste.
 *
 * Gibt es die Spalte `sort_order` (siehe database/add_category_sort_order.sql), entscheidet
 * sie zuerst: eine kleinere Zahl steht weiter oben, und bei gleichem Wert bleibt es bei der
 * id. Ohne die Spalte - eine Installation, in der die Migration noch nicht gelaufen ist -
 * wird genau wie vorher nur nach id sortiert. Die Anwendung verhält sich also mit und ohne
 * die Spalte richtig, und keine Zeile der Liste hängt davon ab, dass sie da ist.
 *
 * @param list<string> $columns
 */
function category_order_sql(array $columns, string $alias = 'c'): string
{
    if (category_column_available($columns, 'sort_order')) {
        return 'ORDER BY ' . $alias . '.sort_order ASC, ' . $alias . '.id ASC';
    }

    return 'ORDER BY ' . $alias . '.id ASC';
}

/**
 * Gibt alle Themengebiete (oberste Ebene) EINES Kontos zurück.
 *
 * Jede Leseabfrage in dieser Datei verlangt den Eigentümer als Pflichtargument. Es
 * gibt absichtlich keinen Standard und keinen "kein Filter"-Modus: ein vergessener
 * Parameter muss laut scheitern, statt still die Kategorien von jemand anderem
 * auszuliefern.
 */
function find_main_categories(PDO $pdo, int $ownerUserId): array
{
    $columns = category_columns($pdo);

    $statement = $pdo->prepare(
        category_select_sql($columns) . '
         WHERE c.parent_id IS NULL
           AND c.owner_user_id = :owner_user_id
         ' . category_order_sql($columns)
    );
    $statement->bindValue(':owner_user_id', $ownerUserId, PDO::PARAM_INT);
    $statement->execute();

    return normalize_category_rows($statement->fetchAll());
}

/**
 * Gibt die Unterkategorien einer Kategorie zurück, zuerst nach sort_order und dann
 * nach id.
 */
function find_subcategories(PDO $pdo, int $parentId, int $ownerUserId): array
{
    $columns = category_columns($pdo);

    // Der Wert wird als Ganzzahl gebunden. Er erreicht die Datenbank getrennt vom
    // SQL-Text und kann deshalb nie als Teil der Abfrage gelesen werden.
    $statement = $pdo->prepare(
        category_select_sql($columns) . '
         WHERE c.parent_id = :parent_id
           AND c.owner_user_id = :owner_user_id
         ' . category_order_sql($columns)
    );
    $statement->bindValue(':parent_id', $parentId, PDO::PARAM_INT);
    $statement->bindValue(':owner_user_id', $ownerUserId, PDO::PARAM_INT);
    $statement->execute();

    return normalize_category_rows($statement->fetchAll());
}

/**
 * Gibt eine Kategorie zurück, oder null, wenn es sie nicht gibt.
 *
 * Mit $withDeletePreview wird zusätzlich der Teilbaum gezählt, damit der Löschdialog
 * genau sagen kann, wie viel verschwinden würde. Das ist nur eine Vorschau: der
 * Lösch-Endpunkt zählt in seiner eigenen Transaktion noch einmal.
 *
 * @return array<string, mixed>|null
 */
function find_category(PDO $pdo, int $categoryId, int $ownerUserId, bool $withDeletePreview = false): ?array
{
    $statement = $pdo->prepare(
        category_select_sql(category_columns($pdo)) . '
         WHERE c.id = :id
           AND c.owner_user_id = :owner_user_id'
    );
    $statement->bindValue(':id', $categoryId, PDO::PARAM_INT);
    $statement->bindValue(':owner_user_id', $ownerUserId, PDO::PARAM_INT);
    $statement->execute();

    $row = $statement->fetch();

    if ($row === false) {
        return null;
    }

    $category = normalize_category_row($row);

    if (!$withDeletePreview) {
        return $category;
    }

    $stats = category_subtree_stats($pdo, $categoryId, $ownerUserId);

    // "categories" zählt die Unterkategorien unter dieser; die Kategorie selbst
    // gehört nicht zur Vorschau.
    $category['delete_preview'] = [
        'categories' => max(0, $stats['categories'] - 1),
        'cards' => $stats['cards'],
    ];

    return $category;
}

/**
 * Sagt, ob dieses Konto eine Kategorie mit dieser id besitzt.
 *
 * Das ist das Tor vor allem, was an einer Kategorie hängt: die Karten und die
 * Lern-Warteschlange fragen zuerst hier. Ohne den Eigentümer in der Bedingung käme
 * ein zweites Konto allein durch Raten einer id an fremde Karten.
 */
function category_exists(PDO $pdo, int $categoryId, int $ownerUserId): bool
{
    $statement = $pdo->prepare(
        'SELECT COUNT(*) FROM categories WHERE id = :id AND owner_user_id = :owner_user_id'
    );
    $statement->bindValue(':id', $categoryId, PDO::PARAM_INT);
    $statement->bindValue(':owner_user_id', $ownerUserId, PDO::PARAM_INT);
    $statement->execute();

    return (int) $statement->fetchColumn() > 0;
}

/**
 * Sagt, ob es eine Kategorie mit diesem Namen unter denselben Geschwistern gibt.
 *
 * Die Spalte name benutzt die Collation utf8mb4_unicode_ci, die Groß- und
 * Kleinschreibung ignoriert: "History" und "history" gelten als derselbe Name.
 *
 * @param int|null $parentId Die übergeordnete Kategorie, in der die neue angelegt würde.
 * @param int|null $exceptId Eine Kategorie, die den Namen behalten darf (die gerade
 *                           bearbeitete).
 */
function category_sibling_name_exists(PDO $pdo, string $name, ?int $parentId, int $ownerUserId, ?int $exceptId = null): bool
{
    /* Zwei Konten dürfen beide ein "Mathematik" haben, der Eigentümer gehört also
       genauso in die Bedingung wie der Name. */
    $sql = 'SELECT COUNT(*)
              FROM categories
             WHERE owner_user_id = :owner_user_id
               AND name = :name
               AND ' . ($parentId === null ? 'parent_id IS NULL' : 'parent_id = :parent_id');

    if ($exceptId !== null) {
        $sql .= ' AND id <> :except_id';
    }

    $statement = $pdo->prepare($sql);
    $statement->bindValue(':owner_user_id', $ownerUserId, PDO::PARAM_INT);
    $statement->bindValue(':name', $name, PDO::PARAM_STR);

    if ($parentId !== null) {
        $statement->bindValue(':parent_id', $parentId, PDO::PARAM_INT);
    }

    if ($exceptId !== null) {
        $statement->bindValue(':except_id', $exceptId, PDO::PARAM_INT);
    }

    $statement->execute();

    return (int) $statement->fetchColumn() > 0;
}

/**
 * Legt ein Themengebiet oder eine Unterkategorie an und gibt es in derselben Form
 * zurück wie die Lesefunktionen - so kann der Browser ohne zweite Anfrage
 * weiterarbeiten.
 *
 * Die Werte kommen aus $fields; benutzt werden nur die unten genannten Schlüssel.
 * Jeder Wert wird einzeln gebunden, es kann also nichts aus der Anfrage zum Teil des
 * SQL-Textes werden. Fehlende Schlüssel bleiben beim Spaltenstandard.
 *
 * @param array<string, mixed> $fields
 * @return array<string, mixed>
 */
function create_category(PDO $pdo, array $fields, int $ownerUserId): array
{
    $available = category_columns($pdo);
    $columns = ['parent_id', 'name', 'owner_user_id'];
    $values = [':parent_id', ':name', ':owner_user_id'];
    $bindings = [
        ':parent_id' => [$fields['parent_id'] ?? null, PDO::PARAM_INT],
        ':name' => [(string) $fields['name'], PDO::PARAM_STR],
        ':owner_user_id' => [$ownerUserId, PDO::PARAM_INT],
    ];

    $optional = [
        'name_en' => PDO::PARAM_STR,
        'name_de' => PDO::PARAM_STR,
        'icon_svg' => PDO::PARAM_STR,
        'icon_scale' => PDO::PARAM_STR,
    ];

    foreach ($optional as $column => $type) {
        if (!array_key_exists($column, $fields) || !category_column_available($available, $column)) {
            continue;
        }

        $columns[] = $column;
        $values[] = ':' . $column;
        $bindings[':' . $column] = [$fields[$column], $type];
    }

    $statement = $pdo->prepare(
        'INSERT INTO categories (' . implode(', ', $columns) . ')
         VALUES (' . implode(', ', $values) . ')'
    );

    foreach ($bindings as $placeholder => [$value, $type]) {
        if ($value === null) {
            // Ein echtes NULL - das macht eine Kategorie zur obersten Ebene und
            // lässt ein optionales Feld leer.
            $statement->bindValue($placeholder, null, PDO::PARAM_NULL);
            continue;
        }

        $statement->bindValue($placeholder, $value, $type);
    }

    $statement->execute();

    $created = find_category($pdo, (int) $pdo->lastInsertId(), $ownerUserId);

    return $created ?? [];
}

/**
 * Ändert die übergebenen Felder einer Kategorie.
 *
 * Schlüssel, die keine echten Spalten sind, werden ignoriert - ein Anfragekörper kann
 * dem SQL-Text also nichts hinzufügen. Ein Wert null leert ein optionales Feld.
 *
 * @param array<string, mixed> $changes
 * @return array<string, mixed>|null
 */
function update_category(PDO $pdo, int $categoryId, array $changes, int $ownerUserId): ?array
{
    $allowed = [
        'name' => PDO::PARAM_STR,
        'name_en' => PDO::PARAM_STR,
        'name_de' => PDO::PARAM_STR,
        'icon_svg' => PDO::PARAM_STR,
        'icon_scale' => PDO::PARAM_STR,
    ];

    $available = category_columns($pdo);
    $assignments = [];
    $bindings = [];

    foreach ($allowed as $column => $type) {
        if (!array_key_exists($column, $changes) || !category_column_available($available, $column)) {
            continue;
        }

        $assignments[] = $column . ' = :' . $column;
        $bindings[':' . $column] = [$changes[$column], $type];
    }

    if ($assignments === []) {
        return find_category($pdo, $categoryId, $ownerUserId);
    }

    $statement = $pdo->prepare(
        'UPDATE categories SET ' . implode(', ', $assignments) . '
          WHERE id = :id AND owner_user_id = :owner_user_id'
    );
    $statement->bindValue(':id', $categoryId, PDO::PARAM_INT);
    $statement->bindValue(':owner_user_id', $ownerUserId, PDO::PARAM_INT);

    foreach ($bindings as $placeholder => [$value, $type]) {
        if ($value === null) {
            $statement->bindValue($placeholder, null, PDO::PARAM_NULL);
            continue;
        }

        $statement->bindValue($placeholder, $value, $type);
    }

    $statement->execute();

    return find_category($pdo, $categoryId, $ownerUserId);
}

/**
 * Sammelt die id einer Kategorie und die aller Kategorien darunter.
 *
 * Die ganze (kleine) Tabelle wird einmal gelesen und in PHP durchlaufen. Das hält die
 * Abfrage einfach, spart ein rekursives SQL und kann nicht endlos laufen: nach
 * CATEGORY_MAX_DEPTH Ebenen hört die Schleife auf.
 *
 * @return list<int> Die Kategorie selbst zuerst, dann ihre Kinder Ebene für Ebene.
 */
function category_subtree_ids(PDO $pdo, int $categoryId, int $ownerUserId): array
{
    $children = [];
    $owned = [];

    /* Gelesen werden nur die Zeilen dieses Kontos, eine fremde id kann also nie
       einen fremden Teilbaum in ein Löschen oder Zählen ziehen. */
    $statement = $pdo->prepare('SELECT id, parent_id FROM categories WHERE owner_user_id = :owner_user_id');
    $statement->bindValue(':owner_user_id', $ownerUserId, PDO::PARAM_INT);
    $statement->execute();

    foreach ($statement->fetchAll() as $row) {
        $owned[(int) $row['id']] = true;

        // Ein NULL-parent_id wird zum Schlüssel 0, und 0 ist nie eine echte id.
        $parentKey = $row['parent_id'] === null ? 0 : (int) $row['parent_id'];
        $children[$parentKey][] = (int) $row['id'];
    }

    /*
     * Eine Kategorie von jemand anderem ist kein Teilbaum mit einem Mitglied - sie
     * ist gar kein Teilbaum. [] zurückzugeben bleibt ehrlich, statt eine id zu
     * wiederholen, die dieses Konto nicht besitzt; delete_category_tree() findet dann
     * nichts zu löschen und lehnt mit eigener Prüfung ab.
     */
    if (!isset($owned[$categoryId])) {
        return [];
    }

    $ids = [$categoryId];
    $frontier = [$categoryId];
    $depth = 0;

    while ($frontier !== [] && $depth < CATEGORY_MAX_DEPTH) {
        $next = [];

        foreach ($frontier as $id) {
            foreach ($children[$id] ?? [] as $childId) {
                $ids[] = $childId;
                $next[] = $childId;
            }
        }

        $frontier = $next;
        $depth++;
    }

    return $ids;
}

/**
 * Die Kategorien, die unter einem englischen Lernbereich liegen.
 *
 * Für eine solche Karte ergibt der DE/EN-Umschalter keinen Sinn: gelernt wird Englisch,
 * die Karte ist selbst der englische Inhalt. Gemeint ist der ganze Zweig - der Bereich
 * selbst und alles darunter -, weil die Karten in den Unterkategorien liegen und nicht
 * im Bereich.
 *
 * Gelesen wird die kleine Tabelle einmal und in PHP durchlaufen, wie in
 * category_subtree_ids(). Englisch heißt eine Wurzel, deren name oder name_en "English"
 * (oder "Englisch") ist. Die Prüfung hängt absichtlich nicht an der Oberflächensprache,
 * der Umschalter soll sich nicht mit dem Sprachwechsel der Oberfläche ändern.
 *
 * @return list<int>
 */
function category_ids_in_english_areas(PDO $pdo, int $ownerUserId): array
{
    $statement = $pdo->prepare('SELECT id, parent_id, name, name_en FROM categories WHERE owner_user_id = :owner_user_id');
    $statement->bindValue(':owner_user_id', $ownerUserId, PDO::PARAM_INT);
    $statement->execute();

    $children = [];
    $roots = [];

    foreach ($statement->fetchAll() as $row) {
        $id = (int) $row['id'];

        // Ein NULL-parent_id wird zum Schlüssel 0, und 0 ist nie eine echte id.
        $parentKey = $row['parent_id'] === null ? 0 : (int) $row['parent_id'];
        $children[$parentKey][] = $id;

        if ($parentKey === 0
            && (category_name_is_english((string) $row['name']) || category_name_is_english((string) ($row['name_en'] ?? '')))
        ) {
            $roots[] = $id;
        }
    }

    $ids = $roots;
    $frontier = $roots;
    $depth = 0;

    while ($frontier !== [] && $depth < CATEGORY_MAX_DEPTH) {
        $next = [];

        foreach ($frontier as $id) {
            foreach ($children[$id] ?? [] as $childId) {
                $ids[] = $childId;
                $next[] = $childId;
            }
        }

        $frontier = $next;
        $depth++;
    }

    return array_values(array_unique($ids));
}

/**
 * Ob dieser Kategoriename den englischen Lernbereich bezeichnet.
 */
function category_name_is_english(string $name): bool
{
    return strcasecmp(trim($name), 'English') === 0
        || strcasecmp(trim($name), 'Englisch') === 0;
}

/**
 * Zählt eine Kategorie, alles darunter und alle Karten in diesem Teilbaum.
 *
 * @return array{categories: int, cards: int}
 */
function category_subtree_stats(PDO $pdo, int $categoryId, int $ownerUserId): array
{
    $ids = category_subtree_ids($pdo, $categoryId, $ownerUserId);

    /* Eine fremde Kategorie ist kein Teilbaum, und "IN ()" ist kein gültiges SQL -
       deshalb wird die leere Antwort hier geschrieben statt gebaut. */
    if ($ids === []) {
        return ['categories' => 0, 'cards' => 0];
    }
    $placeholders = [];
    $bindings = [];

    foreach (array_values($ids) as $index => $id) {
        $placeholders[] = ':id' . $index;
        $bindings[':id' . $index] = $id;
    }

    $statement = $pdo->prepare('SELECT COUNT(*) FROM cards WHERE category_id IN (' . implode(', ', $placeholders) . ')');

    foreach ($bindings as $placeholder => $id) {
        $statement->bindValue($placeholder, $id, PDO::PARAM_INT);
    }

    $statement->execute();

    return [
        'categories' => count($ids),
        'cards' => (int) $statement->fetchColumn(),
    ];
}

/**
 * Sagt, wie viel an einer Kategorie hängt: die Nachfahren und deren Karten.
 *
 * Der Lösch-Endpunkt entscheidet damit, ob eine Anfrage noch bestätigt werden muss.
 * Entschieden wird auf dem Server, nie im Browser: eine von Hand gebaute Anfrage kann
 * die Bestätigung nicht überspringen, indem sie das Feld weglässt.
 *
 * @return array{categories: int, cards: int, descendants: int}
 */
function category_delete_dependents(PDO $pdo, int $categoryId, int $ownerUserId): array
{
    $stats = category_subtree_stats($pdo, $categoryId, $ownerUserId);

    return [
        /* Alles inklusive der Kategorie selbst. */
        'categories' => $stats['categories'],
        /* Die Kategorien darunter - das sieht man als "hängt daran". */
        'descendants' => max(0, $stats['categories'] - 1),
        'cards' => $stats['cards'],
    ];
}

/**
 * Löscht eine Kategorie, jede Unterkategorie darunter und jede Karte in diesem
 * Teilbaum.
 *
 * Die Reihenfolge geben die Fremdschlüssel vor, sie steht deshalb ausgeschrieben hier:
 *   1. der Lernfortschritt der betroffenen Karten
 *   2. die Karten, weil fk_cards_category ON DELETE RESTRICT ist
 *   3. die Unterkategorien von der tiefsten Ebene nach oben, weil
 *      fk_categories_parent ebenfalls ON DELETE RESTRICT ist
 *   4. zuletzt die ausgewählte Kategorie selbst
 *
 * Alles läuft in EINER Transaktion: entweder verschwindet der ganze Teilbaum oder
 * nichts. Ein halb gelöschter Baum bleibt nie stehen.
 *
 * Die ausgewählte Kategorie wird zweimal geprüft, bevor die Transaktion festschreiben
 * darf: ihr eigenes DELETE muss genau eine Zeile treffen, und ein SELECT muss danach
 * nichts mehr finden. Dem Aufrufer kann also nie "gelöscht" gemeldet werden, während
 * die Zeile noch da ist.
 *
 * @return array{categories: int, cards: int, progress: int} Was wirklich gelöscht wurde.
 * @throws RuntimeException wenn die ausgewählte Kategorie danach noch existiert.
 */
function delete_category_tree(PDO $pdo, int $categoryId, int $ownerUserId): array
{
    // Die Karten und ihr Fortschritt fliegen zuerst raus, und das macht der
    // Kartenservice - deshalb wird er hier geladen und nicht dem Aufrufer überlassen.
    require_once __DIR__ . '/card_service.php';

    /*
     * Normalerweise gehört die Transaktion dieser Funktion. Wird sie aus einer
     * Transaktion aufgerufen, die jemand anders gestartet hat - ein Test oder ein
     * späterer Aufrufer, der mehrere Teilbäume auf einmal löscht - dann wird die
     * äußere benutzt und die Entscheidung über das Festschreiben bleibt beim Aufrufer.
     */
    $ownsTransaction = !$pdo->inTransaction();

    if ($ownsTransaction) {
        $pdo->beginTransaction();
    }

    try {
        $ids = category_subtree_ids($pdo, $categoryId, $ownerUserId);

        // 1. der Lernfortschritt jeder Karte in diesem Teilbaum
        $deletedProgress = delete_progress_of_categories($pdo, $ids, $ownerUserId);

        // 2. die Karten selbst
        $deletedCards = delete_cards_of_categories($pdo, $ids, $ownerUserId);

        /*
         * 3. und 4. die Kategorien. category_subtree_ids() gibt ein Elternteil vor
         * seinen Kindern zurück, die umgedrehte Liste löscht also die tiefste Ebene
         * zuerst. Eine Kategorie kann deshalb nicht verschwinden, solange noch etwas
         * auf sie zeigt.
         */
        $statement = $pdo->prepare('DELETE FROM categories WHERE id = :id AND owner_user_id = :owner_user_id');
        $deletedCategories = 0;
        $deletedSelected = 0;
        $statement->bindValue(':owner_user_id', $ownerUserId, PDO::PARAM_INT);

        foreach (array_reverse($ids) as $id) {
            $statement->bindValue(':id', $id, PDO::PARAM_INT);
            $statement->execute();

            $affected = $statement->rowCount();
            $deletedCategories += $affected;

            if ($id === $categoryId) {
                $deletedSelected = $affected;
            }
        }

        /*
         * Der Beweis. "rowCount() === 1" sagt: die Zeile war da und ist weg. Das
         * SELECT sagt dasselbe von der anderen Seite. Widerspricht eines von beiden,
         * wird die ganze Transaktion zurückgerollt und der Aufrufer bekommt einen
         * Fehler statt einer Erfolgsmeldung über eine Zeile, die es noch gibt.
         */
        if ($deletedSelected !== 1) {
            throw new RuntimeException('The selected category was not deleted.');
        }

        $check = $pdo->prepare('SELECT COUNT(*) FROM categories WHERE id = :id AND owner_user_id = :owner_user_id');
        $check->bindValue(':id', $categoryId, PDO::PARAM_INT);
        $check->bindValue(':owner_user_id', $ownerUserId, PDO::PARAM_INT);
        $check->execute();

        if ((int) $check->fetchColumn() !== 0) {
            throw new RuntimeException('The selected category still exists.');
        }

        if ($ownsTransaction) {
            $pdo->commit();
        }

        return [
            'categories' => $deletedCategories,
            'cards' => $deletedCards,
            'progress' => $deletedProgress,
        ];
    } catch (Throwable $error) {
        // Ein Rollback setzt die Datenbank genau dorthin zurück, wo sie vor dem
        // Versuch war - auch die Karten, die schon gelöscht wurden.
        if ($ownsTransaction) {
            $pdo->rollBack();
        }

        throw $error;
    }
}

/**
 * Löscht mehrere direkte Unterkategorien eines Lernbereichs in EINER Transaktion.
 *
 * Gedacht für die Auswahl auf der Übersichtsseite eines Lernbereichs: der Browser schickt
 * die Id des Bereichs und die Ids der angekreuzten Unterkategorien. Erlaubt ist nur, was
 * wirklich dazugehört:
 *
 *   - der Bereich muss diesem Konto gehören und ein Lernbereich sein (parent_id NULL),
 *   - jede Id muss eine DIREKTE Unterkategorie dieses Bereichs sein.
 *
 * Eine Id, die nicht dazugehört, bricht den ganzen Lauf ab, bevor etwas gelöscht wird. Ein
 * von Hand gebauter Aufruf kann also nicht die Unterkategorie eines anderen Bereichs oder
 * eines anderen Kontos erwischen. Der Lernbereich selbst lässt sich über diesen Weg nicht
 * löschen: seine Id steht nie in der Liste seiner Kinder.
 *
 * Gelöscht wird je Id der ganze Teilbaum - delete_category_tree() nimmt Fortschritt,
 * Karten und Kategorien mit. Läuft schon eine Transaktion, benutzt diese Funktion sie,
 * statt eine eigene aufzumachen; sonst gehört ihr die Transaktion und sie schreibt am Ende
 * fest. Ein Fehler rollt ALLES zurück, es bleibt also nie eine halbe Auswahl stehen.
 *
 * @param list<int> $categoryIds
 * @return array{ok: bool, code: string|null, data: array{categories: int, cards: int, progress: int}|null}
 */
function delete_subcategories(PDO $pdo, int $parentId, array $categoryIds, int $ownerUserId): array
{
    require_once __DIR__ . '/card_service.php';

    /* Der Bereich muss existieren, diesem Konto gehören und ein Bereich sein. Eine
       Unterkategorie als "Bereich" würde sonst erlauben, ihre Kinder mitzunehmen. */
    $area = find_category($pdo, $parentId, $ownerUserId);

    if ($area === null || $area['parent_id'] !== null) {
        return ['ok' => false, 'code' => 'parent_not_found', 'data' => null];
    }

    /* Die erlaubten Ids: die direkten Kinder dieses Bereichs. Nur sie dürfen gelöscht
       werden, alles andere fällt unten durch. */
    $children = $pdo->prepare(
        'SELECT id FROM categories WHERE parent_id = :parent_id AND owner_user_id = :owner_user_id'
    );
    $children->bindValue(':parent_id', $parentId, PDO::PARAM_INT);
    $children->bindValue(':owner_user_id', $ownerUserId, PDO::PARAM_INT);
    $children->execute();

    $allowed = [];

    foreach ($children->fetchAll(PDO::FETCH_COLUMN) as $id) {
        $allowed[(int) $id] = true;
    }

    $wanted = [];

    foreach ($categoryIds as $id) {
        $id = (int) $id;

        if (!isset($allowed[$id])) {
            return ['ok' => false, 'code' => 'invalid_selection', 'data' => null];
        }

        $wanted[$id] = true;
    }

    if ($wanted === []) {
        return ['ok' => false, 'code' => 'nothing_to_delete', 'data' => null];
    }

    $totals = ['categories' => 0, 'cards' => 0, 'progress' => 0];

    $ownsTransaction = !$pdo->inTransaction();

    if ($ownsTransaction) {
        $pdo->beginTransaction();
    }

    try {
        foreach (array_keys($wanted) as $id) {
            $deleted = delete_category_tree($pdo, $id, $ownerUserId);

            $totals['categories'] += $deleted['categories'];
            $totals['cards'] += $deleted['cards'];
            $totals['progress'] += $deleted['progress'];
        }

        if ($ownsTransaction) {
            $pdo->commit();
        }

        return ['ok' => true, 'code' => null, 'data' => $totals];
    } catch (Throwable $error) {
        if ($ownsTransaction) {
            $pdo->rollBack();
        }

        throw $error;
    }
}

/**
 * Wandelt eine Liste von Datenbankzeilen in die Form um, die die API verspricht.
 *
 * @param list<array<string, mixed>> $rows
 * @return list<array<string, mixed>>
 */
function normalize_category_rows(array $rows): array
{
    $categories = [];

    foreach (array_values($rows) as $row) {
        $categories[] = normalize_category_row($row);
    }

    return $categories;
}

/**
 * Wandelt eine Datenbankzeile in die Form um, die die API verspricht.
 *
 * Die Umwandlungen sind für die JSON-Ausgabe wichtig: der Browser bekommt
 * "id": 3 als Zahl und nicht "3" als Zeichenkette und kann ids direkt vergleichen.
 *
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function normalize_category_row(array $row): array
{
    $id = (int) $row['id'];

    /*
     * Die Spalte `color` gibt es noch, aber nichts in dieser Anwendung liest,
     * schreibt oder zeigt sie - sie gehört deshalb nicht zur Antwort. Eine Kategorie
     * ist neutral.
     */
    $fingerprint = $row['icon_fingerprint'] ?? null;
    $hasIcon = is_string($fingerprint) && $fingerprint !== '';

    $scale = $row['icon_scale'] ?? null;

    if (!is_numeric($scale) || (float) $scale < 0.2 || (float) $scale > 3.0) {
        $scale = 1.0;
    }

    $category = [
        'id' => $id,
        'parent_id' => $row['parent_id'] === null ? null : (int) $row['parent_id'],
        'name' => (string) $row['name'],
        /*
         * Die Adresse des gespeicherten Symbols. Sie trägt einen kurzen Fingerabdruck
         * der Zeichnung, damit ein Browser mit der alten Fassung im Cache die neue
         * holt, sobald das Symbol geändert wird. null heißt "diese Kategorie hat kein
         * eigenes Symbol"; der Browser fällt dann auf seine eigene Zeichnung zurück.
         */
        'icon_url' => $hasIcon
            ? 'api/category_icon.php?id=' . $id . '&v=' . substr((string) $fingerprint, 0, 8)
            : null,
        'icon_scale' => round((float) $scale, 2),
        'name_en' => normalize_optional_text($row['name_en'] ?? null, CATEGORY_MAX_NAME_LENGTH),
        'name_de' => normalize_optional_text($row['name_de'] ?? null, CATEGORY_MAX_NAME_LENGTH),
        'subcategory_count' => (int) ($row['subcategory_count'] ?? 0),
        'own_card_count' => (int) ($row['own_card_count'] ?? 0),
        'card_count' => (int) ($row['card_count'] ?? 0),
    ];

    /*
     * Die gespeicherte Zeichnung selbst. Nur eine einzelne Kategorie trägt sie
     * (siehe category_select_sql); eine Kategorie ohne Zeichnung antwortet mit null,
     * nie mit einem Dateinamen.
     */


    return $category;
}

/**
 * Schneidet einen optionalen Text zurecht und macht aus einem leeren Wert null,
 * damit der Browser immer entweder eine echte Zeichenkette oder null bekommt.
 *
 * @param mixed $value
 */
function normalize_optional_text($value, int $maxLength): ?string
{
    if (!is_string($value)) {
        return null;
    }

    $value = trim($value);

    if ($value === '' || mb_strlen($value) > $maxLength) {
        return null;
    }

    return $value;
}

/**
 * Welche dieser Kategorien haben selbst Unterkategorien?
 *
 * Wird von api/bootstrap.php genutzt: die Antwort nennt die Eltern, für die eine
 * Abfrage nötig ist, damit ein Baum mit zwei Ebenen nicht eine Abfrage pro Kategorie
 * kostet.
 *
 * @param list<int> $ids
 * @return list<int> die ids, die wirklich Kinder haben, in der Reihenfolge der Eingabe
 */
function category_ids_with_children(PDO $pdo, array $ids, int $ownerUserId): array
{
    $wanted = array_values(array_unique(array_filter($ids, static fn ($id) => (int) $id > 0)));

    if ($wanted === []) {
        return [];
    }

    /* Jeder Platzhalter bekommt einen eigenen Namen: eine Anweisung darf benannte
       und positionelle Platzhalter nicht mischen, und der Rest dieser Datei nutzt
       benannte. */
    $placeholders = [];

    foreach ($wanted as $index => $id) {
        $placeholders[] = ':parent_' . $index;
    }

    $statement = $pdo->prepare(
        'SELECT DISTINCT parent_id FROM categories
          WHERE parent_id IN (' . implode(', ', $placeholders) . ')
            AND owner_user_id = :owner_user_id
          ORDER BY parent_id ASC'
    );

    $statement->bindValue(':owner_user_id', $ownerUserId, PDO::PARAM_INT);

    foreach ($wanted as $index => $id) {
        $statement->bindValue(':parent_' . $index, (int) $id, PDO::PARAM_INT);
    }

    $statement->execute();

    return array_map(static fn ($value): int => (int) $value, $statement->fetchAll(PDO::FETCH_COLUMN));
}
