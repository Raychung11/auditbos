-- =====================================================================
-- AI Audit BOS - Year-end rollover support
-- File: /sql/008_rollover.sql
-- =====================================================================
-- Track which engagement a new one was rolled over from, so we can show
-- "Rolled over from FY2023" provenance on the workspace and traverse
-- prior-year history for analytics.
-- =====================================================================

SET NAMES utf8mb4;

ALTER TABLE `engagements`
    ADD COLUMN `rolled_over_from_id` INT UNSIGNED DEFAULT NULL AFTER `status`,
    ADD KEY `idx_eng_rollover` (`rolled_over_from_id`),
    ADD CONSTRAINT `fk_eng_rollover` FOREIGN KEY (`rolled_over_from_id`)
        REFERENCES `engagements` (`id`) ON DELETE SET NULL;
