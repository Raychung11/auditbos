-- =====================================================================
-- AI Audit BOS - Gap modules for the 27-step SOP
-- File: /sql/010_gap_modules.sql
-- =====================================================================
-- Fills the four workplan steps that previously had only a procedures
-- template but no dedicated screen:
--
--   * Step 20 — Related parties:  related_parties + related_party_transactions
--   * Step 21 — Tax computation:  tax_computations + tax_adjustments
--   * Step 24 — Misstatements:    misstatements (factual/judgemental/projected)
--   * Step 25 — Audit completion: completion_checklist (manual sign-off items
--                                  + auto-driven items)
--
-- All scope through engagement_id → engagements.firm_id (multi-tenant
-- isolation already enforced upstream); none of these are queried
-- cross-firm so they don't need a firm_id of their own.
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- Related parties (MFRS 124)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `related_parties` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `engagement_id` INT UNSIGNED NOT NULL,
    `party_name` VARCHAR(255) NOT NULL,
    `party_code` VARCHAR(50) DEFAULT NULL COMMENT 'optional COA mapping',
    `relationship_type` ENUM(
        'director','holding_company','subsidiary','fellow_subsidiary',
        'associate','joint_venture','key_management','close_family','other'
    ) NOT NULL DEFAULT 'other',
    `registration_no` VARCHAR(100) DEFAULT NULL,
    `notes` TEXT DEFAULT NULL,
    `created_by` INT UNSIGNED DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_rp_eng` (`engagement_id`),
    CONSTRAINT `fk_rp_eng` FOREIGN KEY (`engagement_id`)
        REFERENCES `engagements` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `related_party_transactions` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `engagement_id` INT UNSIGNED NOT NULL,
    `related_party_id` INT UNSIGNED NOT NULL,
    `txn_type` ENUM(
        'sale','purchase','loan_given','loan_received','advance',
        'salary','dividend','interest','rental','other'
    ) NOT NULL DEFAULT 'other',
    `txn_amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    `balance_outstanding` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    `arm_length` ENUM('yes','no','na','tbd') NOT NULL DEFAULT 'tbd',
    `period_label` VARCHAR(50) DEFAULT NULL,
    `notes` TEXT DEFAULT NULL,
    `created_by` INT UNSIGNED DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_rpt_eng` (`engagement_id`),
    KEY `idx_rpt_party` (`related_party_id`),
    CONSTRAINT `fk_rpt_eng` FOREIGN KEY (`engagement_id`)
        REFERENCES `engagements` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_rpt_party` FOREIGN KEY (`related_party_id`)
        REFERENCES `related_parties` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Tax computation (book profit → chargeable income → tax expense)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tax_computations` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `engagement_id` INT UNSIGNED NOT NULL,
    `accounting_profit` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    `tax_rate_pct` DECIMAL(5,2) NOT NULL DEFAULT 24.00 COMMENT 'Malaysia default 24%',
    `deferred_tax_movement` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    `tax_paid` DECIMAL(18,2) NOT NULL DEFAULT 0.00 COMMENT 'CP204 / instalments paid',
    `notes` TEXT DEFAULT NULL,
    `prepared_by` INT UNSIGNED DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_tax_eng` (`engagement_id`),
    CONSTRAINT `fk_tax_eng` FOREIGN KEY (`engagement_id`)
        REFERENCES `engagements` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `tax_adjustments` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `engagement_id` INT UNSIGNED NOT NULL,
    `sort_order` INT NOT NULL DEFAULT 0,
    `category` ENUM('add_back','deduction','capital_allowance','other')
        NOT NULL DEFAULT 'add_back',
    `line_item` VARCHAR(255) NOT NULL,
    `amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    `mfrs_reference` VARCHAR(50) DEFAULT NULL,
    `notes` TEXT DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_taxadj_eng` (`engagement_id`),
    KEY `idx_taxadj_cat` (`engagement_id`, `category`),
    CONSTRAINT `fk_taxadj_eng` FOREIGN KEY (`engagement_id`)
        REFERENCES `engagements` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Misstatements (SUM) — ISA 450 aggregation
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `misstatements` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `engagement_id` INT UNSIGNED NOT NULL,
    `working_paper_id` INT UNSIGNED DEFAULT NULL,
    `lead_area` VARCHAR(40) DEFAULT NULL,
    `category` ENUM('factual','judgemental','projected') NOT NULL DEFAULT 'factual',
    `description` VARCHAR(500) NOT NULL,
    `amount_assets` DECIMAL(18,2) NOT NULL DEFAULT 0.00
        COMMENT '+ overstate assets / − understate assets',
    `amount_liabilities` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    `amount_pbt` DECIMAL(18,2) NOT NULL DEFAULT 0.00
        COMMENT '+ overstate profit / − understate profit',
    `status` ENUM('uncorrected','corrected','waived') NOT NULL DEFAULT 'uncorrected',
    `notes` TEXT DEFAULT NULL,
    `raised_by` INT UNSIGNED DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_mis_eng` (`engagement_id`),
    KEY `idx_mis_eng_status` (`engagement_id`, `status`),
    KEY `idx_mis_wp` (`working_paper_id`),
    CONSTRAINT `fk_mis_eng` FOREIGN KEY (`engagement_id`)
        REFERENCES `engagements` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_mis_wp` FOREIGN KEY (`working_paper_id`)
        REFERENCES `audit_working_papers` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Audit completion checklist (manual sign-off + auto-driven gates)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `completion_checklist` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `engagement_id` INT UNSIGNED NOT NULL,
    `item_key` VARCHAR(60) NOT NULL,
    `is_done` TINYINT(1) NOT NULL DEFAULT 0,
    `notes` TEXT DEFAULT NULL,
    `signed_off_by` INT UNSIGNED DEFAULT NULL,
    `signed_off_at` DATETIME DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_completion_item` (`engagement_id`, `item_key`),
    CONSTRAINT `fk_completion_eng` FOREIGN KEY (`engagement_id`)
        REFERENCES `engagements` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_completion_user` FOREIGN KEY (`signed_off_by`)
        REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
