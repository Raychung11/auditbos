-- =====================================================================
-- AI Audit BOS - Phase 3 (workflow): Lead Schedules
-- File: /sql/003_lead_schedules.sql
-- =====================================================================
-- A lead schedule groups trial-balance accounts into an audit area
-- (Cash, Receivables, Payables, Inventory, PPE, Revenue, Payroll, ...)
-- and ties that area to a working paper. Grouping is computed on the
-- fly from the TB via a classifier; this migration adds:
--   * a per-engagement override so auditors can move an account to a
--     different area than the heuristic chose
--   * a lead_area tag on audit_working_papers so a WP can "cover" a lead
-- =====================================================================

SET NAMES utf8mb4;

-- Tag a working paper with the audit area / lead it covers.
ALTER TABLE `audit_working_papers`
    ADD COLUMN `lead_area` VARCHAR(50) DEFAULT NULL AFTER `section_id`,
    ADD KEY `idx_wp_lead_area` (`engagement_id`, `lead_area`);

-- Per-engagement, per-account override of the auto-classification.
CREATE TABLE IF NOT EXISTS `lead_schedule_overrides` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `engagement_id` INT UNSIGNED NOT NULL,
    `account_code` VARCHAR(50) NOT NULL,
    `audit_area` VARCHAR(50) NOT NULL,
    `created_by` INT UNSIGNED DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_lso_eng_account` (`engagement_id`, `account_code`),
    KEY `idx_lso_eng` (`engagement_id`),
    CONSTRAINT `fk_lso_eng` FOREIGN KEY (`engagement_id`) REFERENCES `engagements` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_lso_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
