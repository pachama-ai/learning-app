-- ============================================================================
-- Learning App - initial categories
--
-- Creates the four top-level learning areas (parent_id = NULL) and the eight
-- Mathematics subareas that hang off Mathematics through parent_id.
--
-- Idempotent: every statement inserts a row only while that row is still
-- missing, so running this file twice never creates duplicates. On a second
-- run MySQL reports "0 rows inserted" for all statements.
--
-- Only INSERT statements are used. There is no ALTER, DROP, DELETE, TRUNCATE
-- or UPDATE, and the table structure is not touched.
--
-- How to run it: phpMyAdmin -> database `learning_app` -> tab "SQL" -> paste
-- this file's content -> Go.
--
-- Note on the shape of the queries: a subquery may not read the same table that
-- is being inserted into, so the check reads from a derived table. A derived
-- table is materialised first, which keeps MySQL and MariaDB happy.
-- ============================================================================


-- ----------------------------------------------------------------------------
-- 1 - 4: the top-level learning areas
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
-- 5 - 12: the Mathematics subareas
--
-- `FROM categories AS parent` supplies the Mathematics row, so the subarea is
-- attached to the real id of that category. If Mathematics does not exist, the
-- SELECT returns no rows and nothing is inserted.
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
