-- =====================================================================
-- AI Audit BOS - Phase 2: Accounting Data Import
-- File: /sql/002_import_module.sql
-- =====================================================================
-- Adds:
--   * import_batches  — one row per upload (history, totals, status)
--   * import_mappings — reusable column mappings per firm + source
--
-- Both layer on top of the trial_balances / general_ledgers /
-- chart_of_accounts tables created in 001.
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- import_batches: tracks every TB / GL import.
-- A batch can be rolled back by deleting rows whose batch_id matches.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `import_batches` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `firm_id` INT UNSIGNED NOT NULL,
    `engagement_id` INT UNSIGNED NOT NULL,
    `import_type` ENUM('trial_balance','general_ledger','chart_of_accounts') NOT NULL,
    `period` ENUM('current','prior') NOT NULL DEFAULT 'current',
    `source` VARCHAR(50) NOT NULL DEFAULT 'excel'
        COMMENT 'sql_acc / autocount / ubs / bukku / million / financio / excel',
    `original_filename` VARCHAR(255) DEFAULT NULL,
    `stored_filename` VARCHAR(255) DEFAULT NULL
        COMMENT 'Optional copy of the source CSV under /uploads_private',
    `relative_path` VARCHAR(500) DEFAULT NULL,
    `column_mapping_json` TEXT DEFAULT NULL,
    `row_count_total` INT UNSIGNED NOT NULL DEFAULT 0,
    `row_count_imported` INT UNSIGNED NOT NULL DEFAULT 0,
    `row_count_skipped` INT UNSIGNED NOT NULL DEFAULT 0,
    `total_debit` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    `total_credit` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    `status` ENUM('pending','committed','rolled_back','failed') NOT NULL DEFAULT 'pending',
    `error_message` TEXT DEFAULT NULL,
    `created_by` INT UNSIGNED DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_imp_firm` (`firm_id`),
    KEY `idx_imp_eng` (`engagement_id`),
    KEY `idx_imp_type` (`import_type`),
    KEY `idx_imp_status` (`status`),
    CONSTRAINT `fk_imp_firm` FOREIGN KEY (`firm_id`) REFERENCES `firms` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_imp_eng`  FOREIGN KEY (`engagement_id`) REFERENCES `engagements` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_imp_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- import_mappings: when a user maps "the column called 'Db' is debit"
-- the choice is saved so the next upload from the same source remembers.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `import_mappings` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `firm_id` INT UNSIGNED NOT NULL,
    `import_type` ENUM('trial_balance','general_ledger','chart_of_accounts') NOT NULL,
    `source` VARCHAR(50) NOT NULL DEFAULT 'excel',
    `label` VARCHAR(150) DEFAULT NULL COMMENT 'Friendly name shown in dropdowns',
    `mapping_json` TEXT NOT NULL,
    `is_default` TINYINT(1) NOT NULL DEFAULT 0,
    `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
    `created_by` INT UNSIGNED DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_map_firm_source_type` (`firm_id`, `source`, `import_type`, `label`),
    KEY `idx_map_firm` (`firm_id`),
    CONSTRAINT `fk_map_firm` FOREIGN KEY (`firm_id`) REFERENCES `firms` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_map_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- trial_balances / general_ledgers: add batch_id link so an import can
-- be rolled back atomically.
-- ---------------------------------------------------------------------
ALTER TABLE `trial_balances`
    ADD COLUMN `import_batch_id` BIGINT UNSIGNED DEFAULT NULL AFTER `engagement_id`,
    ADD KEY `idx_tb_batch` (`import_batch_id`),
    ADD CONSTRAINT `fk_tb_batch` FOREIGN KEY (`import_batch_id`)
        REFERENCES `import_batches` (`id`) ON DELETE SET NULL;

ALTER TABLE `general_ledgers`
    ADD COLUMN `import_batch_id` BIGINT UNSIGNED DEFAULT NULL AFTER `engagement_id`,
    ADD KEY `idx_gl_batch` (`import_batch_id`),
    ADD CONSTRAINT `fk_gl_batch` FOREIGN KEY (`import_batch_id`)
        REFERENCES `import_batches` (`id`) ON DELETE SET NULL;
