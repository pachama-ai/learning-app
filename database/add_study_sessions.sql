-- ==========================================================================
-- Learning sessions: how much was learned today, and in which run
-- ==========================================================================
--
-- REVIEW THIS FIRST, THEN RUN IT BY HAND (phpMyAdmin or the mysql client).
-- Nothing in the application runs this file, and Copilot never executes a
-- structural change on its own.
--
-- Command line:
--   mysql -u <user> -p learning_app < database/add_study_sessions.sql
--
-- Why a table of its own
--   `user_card_progress` answers the question "where is this card for this
--   person" - one row per card. It cannot answer "how much did I do today",
--   because a card that was rated five times looks exactly like a card that was
--   rated once, and because the day a card was first learned is overwritten on
--   every later rating.
--
--   A session is therefore its own thing: one row per run of the learning view.
--   It is written next to the progress, never instead of it, so the repeating
--   itself stays exactly as it is.
--
-- The columns
--
--   id             The session's own number.
--   user_id        Whose session it is. Deleting an account takes its sessions
--                  with it (ON DELETE CASCADE), the same rule the progress rows
--                  already follow.
--   started_at     When this session really began: with the FIRST rating, not
--                  when the learning view was opened. A run that is opened and
--                  closed again without rating anything therefore leaves no row
--                  behind, which is the point of that rule.
--   ended_at       When the learning view was closed - whether the queue ran out
--                  or the person stopped early. NULL while the session is still
--                  running.
--
--                  One honest note: if a browser tab is simply killed, no "close"
--                  ever arrives and the row stays open with ended_at = NULL. That
--                  is harmless, because the statistics add up the sessions by
--                  started_at and cards_studied - an open row is counted like any
--                  other. Nothing is ever guessed or repaired behind your back.
--   cards_studied  How many ratings happened in this session. Every rating
--                  counts, so rating the same card twice (after "Again") is two.
--                  That is what the counter in the learning view shows.
--   cards_known    How many of those were rated "Good" or "Easy" - the two
--                  answers of the existing scale (Again, Hard, Good, Easy) that
--                  mean "I knew it". "Hard" deliberately does not count: it is a
--                  success for the interval, but not a "I knew it".
--
-- What it does
--   Creates exactly one new table with one index for the daily question.
--
-- What it does NOT do
--   * no existing table is touched: `users`, `categories`, `cards`,
--     `user_card_progress` and `card_exercises` keep their columns exactly as
--     they are
--   * no DROP, no RENAME, no TRUNCATE, no DELETE, no UPDATE of existing values
--   * no gamification: no streaks, no points, no badges, no goals - there is no
--     column for any of that, and none is planned
--
-- Safety
--   "IF NOT EXISTS" makes a second run change nothing. The table is empty when
--   it is created, so no data can be lost either way.
--
-- Rollback (only if you ever want the old state back)
--   DROP TABLE `study_sessions`;
--
--   The learning progress itself lives in `user_card_progress` and is not
--   touched by that, so nothing about the repeating would be lost.
-- ==========================================================================

CREATE TABLE IF NOT EXISTS `study_sessions` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT
        COMMENT 'The session itself; one row per run of the learning view',
    `user_id` INT UNSIGNED NOT NULL
        COMMENT 'Whose session this is; deleted with the account',
    `started_at` DATETIME NOT NULL
        COMMENT 'When the first rating of this session happened, not when the view was opened',
    `ended_at` DATETIME NULL
        COMMENT 'When the learning view was closed; NULL while the session runs or when the tab was killed',
    `cards_studied` INT UNSIGNED NOT NULL DEFAULT 0
        COMMENT 'How many ratings happened in this session; every rating counts',
    `cards_known` INT UNSIGNED NOT NULL DEFAULT 0
        COMMENT 'How many of them were rated Good or Easy',
    PRIMARY KEY (`id`),
    KEY `idx_study_sessions_user_started` (`user_id`, `started_at`),
    CONSTRAINT `fk_study_sessions_user`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
