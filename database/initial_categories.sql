-- ============================================================================
-- Learning App - die ersten Kategorien
--
-- Legt die vier obersten Lernbereiche an (parent_id = NULL) und die acht
-- Mathematik-Unterbereiche, die über parent_id an Mathematik hängen.
--
-- Wiederholbar: jede Anweisung fügt eine Zeile nur ein, solange diese Zeile noch
-- fehlt, ein zweiter Lauf dieser Datei erzeugt also nie Doppel. Beim zweiten Lauf
-- meldet MySQL für alle Anweisungen "0 rows inserted".
--
-- Es werden nur INSERT-Anweisungen benutzt. Es gibt kein ALTER, DROP, DELETE,
-- TRUNCATE oder UPDATE, und die Tabellenstruktur wird nicht angefasst.
--
-- So ausführen: phpMyAdmin -> Datenbank `learning_app` -> Reiter "SQL" -> den
-- Inhalt dieser Datei einfügen -> Go.
--
-- Eine Anmerkung zur Form der Abfragen: eine Unterabfrage darf nicht dieselbe
-- Tabelle lesen, in die gerade eingefügt wird, die Prüfung liest deshalb aus einer
-- abgeleiteten Tabelle. Eine abgeleitete Tabelle wird zuerst materialisiert, und
-- das lässt MySQL und MariaDB zufrieden.
-- ============================================================================


-- ----------------------------------------------------------------------------
-- 1 - 4: die obersten Lernbereiche
-- ----------------------------------------------------------------------------

INSERT INTO categories (parent_id, name)
SELECT NULL, 'Mathematics'
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1
    FROM (SELECT name, parent_id FROM categories) AS existing
    WHERE existing.name = 'Mathematics'
      AND existing.parent_id IS NULL
);

INSERT INTO categories (parent_id, name)
SELECT NULL, 'Energy'
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1
    FROM (SELECT name, parent_id FROM categories) AS existing
    WHERE existing.name = 'Energy'
      AND existing.parent_id IS NULL
);

INSERT INTO categories (parent_id, name)
SELECT NULL, 'Geography'
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1
    FROM (SELECT name, parent_id FROM categories) AS existing
    WHERE existing.name = 'Geography'
      AND existing.parent_id IS NULL
);

INSERT INTO categories (parent_id, name)
SELECT NULL, 'English'
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1
    FROM (SELECT name, parent_id FROM categories) AS existing
    WHERE existing.name = 'English'
      AND existing.parent_id IS NULL
);


-- ----------------------------------------------------------------------------
-- 5 - 12: die Mathematik-Unterbereiche
--
-- `FROM categories AS parent` liefert die Zeile von Mathematik, der Unterbereich
-- wird also an die echte Id dieser Kategorie gehängt. Gibt es Mathematik nicht,
-- liefert das SELECT keine Zeilen und es wird nichts eingefügt.
-- ----------------------------------------------------------------------------

INSERT INTO categories (parent_id, name)
SELECT parent.id, 'Number systems'
FROM categories AS parent
WHERE parent.name = 'Mathematics'
  AND parent.parent_id IS NULL
  AND NOT EXISTS (
      SELECT 1
      FROM (SELECT parent_id, name FROM categories) AS existing
      WHERE existing.parent_id = parent.id
        AND existing.name = 'Number systems'
  );

INSERT INTO categories (parent_id, name)
SELECT parent.id, 'Basic arithmetic'
FROM categories AS parent
WHERE parent.name = 'Mathematics'
  AND parent.parent_id IS NULL
  AND NOT EXISTS (
      SELECT 1
      FROM (SELECT parent_id, name FROM categories) AS existing
      WHERE existing.parent_id = parent.id
        AND existing.name = 'Basic arithmetic'
  );

INSERT INTO categories (parent_id, name)
SELECT parent.id, 'Fractions and powers'
FROM categories AS parent
WHERE parent.name = 'Mathematics'
  AND parent.parent_id IS NULL
  AND NOT EXISTS (
      SELECT 1
      FROM (SELECT parent_id, name FROM categories) AS existing
      WHERE existing.parent_id = parent.id
        AND existing.name = 'Fractions and powers'
  );

INSERT INTO categories (parent_id, name)
SELECT parent.id, 'Percentages and interest'
FROM categories AS parent
WHERE parent.name = 'Mathematics'
  AND parent.parent_id IS NULL
  AND NOT EXISTS (
      SELECT 1
      FROM (SELECT parent_id, name FROM categories) AS existing
      WHERE existing.parent_id = parent.id
        AND existing.name = 'Percentages and interest'
  );

INSERT INTO categories (parent_id, name)
SELECT parent.id, 'Equations and inequalities'
FROM categories AS parent
WHERE parent.name = 'Mathematics'
  AND parent.parent_id IS NULL
  AND NOT EXISTS (
      SELECT 1
      FROM (SELECT parent_id, name FROM categories) AS existing
      WHERE existing.parent_id = parent.id
        AND existing.name = 'Equations and inequalities'
  );

INSERT INTO categories (parent_id, name)
SELECT parent.id, 'Geometry and trigonometry'
FROM categories AS parent
WHERE parent.name = 'Mathematics'
  AND parent.parent_id IS NULL
  AND NOT EXISTS (
      SELECT 1
      FROM (SELECT parent_id, name FROM categories) AS existing
      WHERE existing.parent_id = parent.id
        AND existing.name = 'Geometry and trigonometry'
  );

INSERT INTO categories (parent_id, name)
SELECT parent.id, 'Statistics and probability'
FROM categories AS parent
WHERE parent.name = 'Mathematics'
  AND parent.parent_id IS NULL
  AND NOT EXISTS (
      SELECT 1
      FROM (SELECT parent_id, name FROM categories) AS existing
      WHERE existing.parent_id = parent.id
        AND existing.name = 'Statistics and probability'
  );

INSERT INTO categories (parent_id, name)
SELECT parent.id, 'Differential calculus'
FROM categories AS parent
WHERE parent.name = 'Mathematics'
  AND parent.parent_id IS NULL
  AND NOT EXISTS (
      SELECT 1
      FROM (SELECT parent_id, name FROM categories) AS existing
      WHERE existing.parent_id = parent.id
        AND existing.name = 'Differential calculus'
  );
