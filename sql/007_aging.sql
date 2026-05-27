-- =====================================================================
-- AI Audit BOS - Phase 2/3: Aging analysis
-- File: /sql/007_aging.sql
-- =====================================================================
-- Stores imported debtor / creditor aging listings (pre-bucketed, as
-- exported by most accounting systems). One import replaces all rows
-- for an (engagement, aging_type) pair.
-- =====================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `aging_items` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `engagement_id` INT UNSIGNED NOT NULL,
    `aging_type` ENUM('debtor','creditor') NOT NULL,
    `party_name` VARCHAR(255) NOT NULL,
    `party_code` VARCHAR(100) DEFAULT NULL,
    `total` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    `current_amt` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    `days_1_30` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    `days_31_60` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    `days_61_90` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    `days_91_120` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    `days_over_120` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    `uploaded_by` INT UNSIGNED DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_aging_eng_type` (`engagement_id`, `aging_type`),
    CONSTRAINT `fk_aging_eng` FOREIGN KEY (`engagement_id`) REFERENCES `engagements` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_aging_user` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
