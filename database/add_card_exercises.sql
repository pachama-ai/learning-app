-- ==========================================================================
-- A second kind of card: the exercise whose numbers are drawn anew each time
-- ==========================================================================
--
-- REVIEW THIS FIRST, THEN RUN IT BY HAND (phpMyAdmin or the mysql client).
-- Nothing in the application runs this file, and Copilot never executes a
-- structural change on its own.
--
-- Command line:
--   mysql -u <user> -p learning_app < database/add_card_exercises.sql
--
-- Why a table of its own
--   A fixed card shows the text somebody wrote into `cards.front` and
--   `cards.back`. An exercise card shows a task that is built when the card is
--   displayed, with numbers that are drawn again on every display. So the
--   exercise needs three things a fixed card does not have: which kind of task
--   it is, and the lowest and the highest number that may appear in it.
--
--   Those three values are the only thing stored. The task itself - "34 + 58",
--   the answer "92", all of it - is produced by the code in
--   `src/services/exercise_service.php`, and only for the kinds of task that
--   are listed in that file. Nothing here holds a formula, and no formula is
--   ever taken from the database and worked out at run time. A new kind of task
--   therefore only comes into being by writing new PHP.
--
-- What it does
--   Creates exactly one new table, `card_exercises`:
--
--     card_id        the card this exercise belongs to. It is the primary key
--                    at the same time, so a card carries at most one exercise,
--                    and deleting the card deletes its exercise with it.
--     exercise_type  the key of one of the kinds of task that the service
--                    knows, for example 'add' or 'multiply'. The code refuses
--                    any key it does not know, so a wrong value in this column
--                    cannot do anything - it only makes the card fall back to
--                    being shown as a fixed card.
--     range_min/_max the numbers the task may be built from. For 'add' these
--                    are the two summands, for 'multiply' the two factors.
--                    How exactly they are used is written down next to each
--                    kind of task in the service.
--
-- What it does NOT do
--   * it does not touch `cards`: no new column, no changed column, no removed
--     column. Every card that exists stays a fixed card, and the application
--     works exactly as before while this table is still empty
--   * no DROP, no RENAME, no TRUNCATE, no DELETE, no UPDATE of existing values
--   * no other table is touched: `users`, `categories`, `cards` and
--     `user_card_progress` are left exactly as they are
--
-- Safety
--   The statement carries "IF NOT EXISTS", so running the file twice changes
--   nothing the second time. The table is empty when it is created, so there is
--   no data that could be lost.
--
-- Rollback (only if you ever want the old state back)
--   DROP TABLE `card_exercises`;
--
--   That is harmless for the cards themselves: `cards` was never changed, so
--   every exercise card simply shows its front and back text again.
-- ==========================================================================

CREATE TABLE IF NOT EXISTS `card_exercises` (
    `card_id` INT UNSIGNED NOT NULL
        COMMENT 'The card this exercise belongs to; also the primary key, so a card carries at most one exercise',
    `exercise_type` VARCHAR(32) NOT NULL
        COMMENT 'Key of one of the kinds of task defined in src/services/exercise_service.php; unknown keys fall back to a fixed card',
    `range_min` INT NOT NULL DEFAULT 1
        COMMENT 'Lowest number the task may be built from',
    `range_max` INT NOT NULL DEFAULT 20
        COMMENT 'Highest number the task may be built from',
    PRIMARY KEY (`card_id`),
    CONSTRAINT `fk_exercises_card`
        FOREIGN KEY (`card_id`) REFERENCES `cards` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
