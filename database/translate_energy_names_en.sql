START TRANSACTION;

UPDATE categories
   SET name_en = 'Energy basics & energy transition'
 WHERE id = 213
   AND owner_user_id = 6
   AND parent_id = 3
   AND name_de = 'Energiegrundlagen & Energiewende'
   AND (name_en IS NULL OR TRIM(name_en) = '');

UPDATE categories
   SET name_en = 'Power grid & system operation'
 WHERE id = 214
   AND owner_user_id = 6
   AND parent_id = 3
   AND name_de = 'Stromnetz & Systembetrieb'
   AND (name_en IS NULL OR TRIM(name_en) = '');

UPDATE categories
   SET name_en = 'Electricity market & energy economics'
 WHERE id = 215
   AND owner_user_id = 6
   AND parent_id = 3
   AND name_de = 'Strommarkt & Energiewirtschaft'
   AND (name_en IS NULL OR TRIM(name_en) = '');

UPDATE categories
   SET name_en = 'Energy data & market communication'
 WHERE id = 216
   AND owner_user_id = 6
   AND parent_id = 3
   AND name_de = 'Energiedaten & Marktkommunikation'
   AND (name_en IS NULL OR TRIM(name_en) = '');

SELECT id, name, name_de, name_en
  FROM categories
 WHERE id IN (213, 214, 215, 216)
   AND owner_user_id = 6
   AND parent_id = 3
 ORDER BY id;

COMMIT;
