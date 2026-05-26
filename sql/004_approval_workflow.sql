-- =====================================================================
-- AI Audit BOS - Phase 3 (workflow): Approval chain + archive locking
-- File: /sql/004_approval_workflow.sql
-- =====================================================================
-- Adds a formal sign-off chain on top of the engagement:
--   Preparation → Senior review → Manager review → Partner review →
--   Signed off → (Locked / read-only archive)
--
--   * engagements.review_stage  — where in the chain the file sits
--   * engagements.locked_at / locked_by — archive lock
--   * engagement_signoffs       — append-only log of every approve/return
-- =====================================================================

SET NAMES utf8mb4;

ALTER TABLE `engagements`
    ADD COLUMN `review_stage` ENUM(
        'preparation',
        'senior_review',
        'manager_review',
        'partner_review',
        'signed_off'
    ) NOT NULL DEFAULT 'preparation' AFTER `status`,
    ADD COLUMN `locked_at` DATETIME DEFAULT NULL AFTER `review_stage`,
    ADD COLUMN `locked_by` INT UNSIGNED DEFAULT NULL AFTER `locked_at`,
    ADD KEY `idx_eng_review_stage` (`review_stage`),
    ADD CONSTRAINT `fk_eng_locked_by` FOREIGN KEY (`locked_by`)
        REFERENCES `users` (`id`) ON DELETE SET NULL;

CREATE TABLE IF NOT EXISTS `engagement_signoffs` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `engagement_id` INT UNSIGNED NOT NULL,
    `from_stage` VARCHAR(30) DEFAULT NULL,
    `to_stage` VARCHAR(30) DEFAULT NULL,
    `action` ENUM('submit','approve','return','sign_off','lock','unlock') NOT NULL,
    `user_id` INT UNSIGNED DEFAULT NULL,
    `notes` TEXT DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_signoff_eng` (`engagement_id`),
    CONSTRAINT `fk_signoff_eng` FOREIGN KEY (`engagement_id`)
        REFERENCES `engagements` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_signoff_user` FOREIGN KEY (`user_id`)
        REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
