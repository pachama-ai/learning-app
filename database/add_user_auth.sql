-- ---------------------------------------------------------------------------
-- Signing in: the columns the users table needs for it
--
-- REVIEW THIS FIRST, THEN RUN IT BY HAND in phpMyAdmin. Nothing in the
-- application runs this file, and nothing changes the structure on its own.
--
-- Why these three columns:
--
--   password_hash  The password is never stored, only the hash that
--                  password_hash() with PASSWORD_DEFAULT produces (bcrypt, so
--                  255 characters are plenty). NULL means: this row cannot sign
--                  in - useful for an account that is created another way.
--   email          Optional. It lets somebody sign in with an address instead
--                  of a name. An account without one simply keeps NULL.
--   created_at     When the account was made. Informational only.
--
-- Why the two unique keys:
--   The name and the address are what somebody signs in WITH. Two rows sharing
--   one of them would make a sign-in ambiguous, so the database refuses it.
--   Several NULL values in email are allowed (MySQL and MariaDB both allow it in
--   a unique index), which is what an account without an address needs.
--
-- What this file does NOT do: it deletes no row, renames no column, drops no
-- index and touches no other table. The users table is empty at the moment
-- (0 rows), so no existing data can be affected either way.
-- ---------------------------------------------------------------------------

ALTER TABLE `users`
    ADD COLUMN `email` VARCHAR(190) NULL AFTER `name`,
    ADD COLUMN `password_hash` VARCHAR(255) NULL AFTER `email`,
    ADD COLUMN `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER `password_hash`,
    ADD UNIQUE KEY `uniq_users_name` (`name`),
    ADD UNIQUE KEY `uniq_users_email` (`email`);

-- Undo, if it is ever wanted:
--
-- ALTER TABLE `users`
--     DROP INDEX `uniq_users_email`,
--     DROP INDEX `uniq_users_name`,
--     DROP COLUMN `created_at`,
--     DROP COLUMN `password_hash`,
--     DROP COLUMN `email`;
