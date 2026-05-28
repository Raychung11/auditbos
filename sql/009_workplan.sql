-- =====================================================================
-- AI Audit BOS - 27-step audit workplan SOP
-- File: /sql/009_workplan.sql
-- =====================================================================
-- Layers a templated, sequenced audit programme on top of the existing
-- working papers + lead schedules. Every engagement is seeded with the
-- same 27 steps (Client Acceptance → ... → Audit File Locking) so the
-- firm gets a consistent SOP across all jobs.
--
--   * `engagement_workplan` — one row per step per engagement.
--     The master template lives in PHP (workplan_master_template())
--     so it can be edited / extended without a schema migration.
--
-- Existing features wire INTO these rows via automation_hook (e.g. the
-- TB import marks step 2 cleared, materiality save marks step 4 cleared,
-- etc.). Staff assign owners and reviewers per step; partner sees one
-- progress bar (N / 27 cleared) on the workspace.
-- =====================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `engagement_workplan` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `engagement_id` INT UNSIGNED NOT NULL,
    `step_no` SMALLINT UNSIGNED NOT NULL COMMENT '1..27 from the master SOP',
    `code` VARCHAR(40) NOT NULL COMMENT 'stable lead key e.g. cash_bank, trade_recv',
    `phase` ENUM('planning','fieldwork','completion','reporting') NOT NULL DEFAULT 'fieldwork',
    `title` VARCHAR(150) NOT NULL,
    `procedures_md` TEXT DEFAULT NULL COMMENT 'embedded audit method as Markdown',
    `automation_hook` VARCHAR(60) DEFAULT NULL COMMENT 'feature key that auto-progresses status, e.g. tb_import, materiality_set, ai_going_concern',
    `depends_on` VARCHAR(60) DEFAULT NULL COMMENT 'CSV of step_no that must be cleared first',
    `status` ENUM('not_started','in_progress','prepared','reviewed','cleared','not_applicable')
        NOT NULL DEFAULT 'not_started',
    `owner_user_id` INT UNSIGNED DEFAULT NULL,
    `reviewer_user_id` INT UNSIGNED DEFAULT NULL,
    `due_date` DATE DEFAULT NULL,
    `started_at` DATETIME DEFAULT NULL,
    `prepared_at` DATETIME DEFAULT NULL,
    `reviewed_at` DATETIME DEFAULT NULL,
    `cleared_at` DATETIME DEFAULT NULL,
    `notes` TEXT DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_workplan_eng_step` (`engagement_id`, `step_no`),
    KEY `idx_workplan_owner` (`owner_user_id`),
    KEY `idx_workplan_reviewer` (`reviewer_user_id`),
    KEY `idx_workplan_phase` (`phase`),
    KEY `idx_workplan_status` (`status`),
    CONSTRAINT `fk_workplan_eng` FOREIGN KEY (`engagement_id`)
        REFERENCES `engagements` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_workplan_owner` FOREIGN KEY (`owner_user_id`)
        REFERENCES `users` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_workplan_reviewer` FOREIGN KEY (`reviewer_user_id`)
        REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
