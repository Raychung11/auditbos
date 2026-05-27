-- =====================================================================
-- AI Audit BOS - Phase 2: Materiality
-- File: /sql/006_materiality.sql
-- =====================================================================
-- Stores the materiality assessment per engagement (one row each).
-- Going-concern ratios are computed on the fly from the TB and are not
-- persisted.
-- =====================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `engagement_materiality` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `engagement_id` INT UNSIGNED NOT NULL,
    `basis` ENUM('revenue','total_assets','pbt','total_expenses','net_assets') NOT NULL DEFAULT 'pbt',
    `basis_amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    `planning_pct` DECIMAL(6,3) NOT NULL DEFAULT 5.000,
    `planning_amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    `performance_pct` DECIMAL(6,3) NOT NULL DEFAULT 75.000,
    `performance_amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    `ctt_pct` DECIMAL(6,3) NOT NULL DEFAULT 5.000,
    `ctt_amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    `rationale` TEXT DEFAULT NULL,
    `set_by` INT UNSIGNED DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_materiality_eng` (`engagement_id`),
    CONSTRAINT `fk_mat_eng` FOREIGN KEY (`engagement_id`) REFERENCES `engagements` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_mat_user` FOREIGN KEY (`set_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
