-- =====================================================================
-- AI Audit BOS - Initial Database Schema
-- File: /sql/001_initial_schema.sql
-- Target: MySQL 5.7+ / MariaDB 10.3+
-- Charset: utf8mb4 (full Unicode incl. emoji, CJK, Bahasa Malaysia)
-- =====================================================================
-- Conventions:
--   * Every table has: id, created_at, updated_at
--   * Soft-mutable tables include status + created_by
--   * All FKs use ON DELETE RESTRICT unless cascading is required
--   * Multi-tenancy is enforced via firm_id on every firm-owned record
-- =====================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";

-- ---------------------------------------------------------------------
-- 1. FIRMS  (multi-tenant root)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `firms` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(200) NOT NULL,
    `registration_no` VARCHAR(100) DEFAULT NULL,
    `address` TEXT DEFAULT NULL,
    `contact_person` VARCHAR(150) DEFAULT NULL,
    `email` VARCHAR(190) DEFAULT NULL,
    `phone` VARCHAR(50) DEFAULT NULL,
    `subscription_status` ENUM('trial','active','suspended','cancelled') NOT NULL DEFAULT 'trial',
    `subscription_expires_at` DATE DEFAULT NULL,
    `credit_balance` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_firms_email` (`email`),
    KEY `idx_firms_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 2. USERS  (all humans: firm staff + client portal users + super admins)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `users` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `firm_id` INT UNSIGNED DEFAULT NULL COMMENT 'NULL only for super_admin',
    `client_id` INT UNSIGNED DEFAULT NULL COMMENT 'Set when role = client_user',
    `role` ENUM(
        'super_admin',
        'firm_admin',
        'audit_manager',
        'senior_auditor',
        'junior_auditor',
        'reviewer',
        'client_user'
    ) NOT NULL,
    `name` VARCHAR(150) NOT NULL,
    `email` VARCHAR(190) NOT NULL,
    `phone` VARCHAR(50) DEFAULT NULL,
    `password_hash` VARCHAR(255) NOT NULL,
    `department` VARCHAR(100) DEFAULT NULL,
    `last_login_at` DATETIME DEFAULT NULL,
    `last_login_ip` VARCHAR(45) DEFAULT NULL,
    `failed_login_count` INT UNSIGNED NOT NULL DEFAULT 0,
    `locked_until` DATETIME DEFAULT NULL,
    `password_reset_token` VARCHAR(100) DEFAULT NULL,
    `password_reset_expires_at` DATETIME DEFAULT NULL,
    `status` ENUM('active','inactive','suspended') NOT NULL DEFAULT 'active',
    `created_by` INT UNSIGNED DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_users_email` (`email`),
    KEY `idx_users_firm` (`firm_id`),
    KEY `idx_users_client` (`client_id`),
    KEY `idx_users_role_status` (`role`, `status`),
    CONSTRAINT `fk_users_firm` FOREIGN KEY (`firm_id`) REFERENCES `firms` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 3. CLIENTS  (auditee companies)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `clients` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `firm_id` INT UNSIGNED NOT NULL,
    `company_name` VARCHAR(255) NOT NULL,
    `registration_no` VARCHAR(100) DEFAULT NULL,
    `business_type` VARCHAR(100) DEFAULT NULL,
    `industry` VARCHAR(100) DEFAULT NULL,
    `financial_year_end` VARCHAR(10) DEFAULT NULL COMMENT 'MM-DD e.g. 12-31',
    `directors` TEXT DEFAULT NULL,
    `shareholders` TEXT DEFAULT NULL,
    `contact_person` VARCHAR(150) DEFAULT NULL,
    `email` VARCHAR(190) DEFAULT NULL,
    `phone` VARCHAR(50) DEFAULT NULL,
    `address` TEXT DEFAULT NULL,
    `audit_status` ENUM('prospect','onboarding','active','dormant','closed') NOT NULL DEFAULT 'active',
    `assigned_manager_id` INT UNSIGNED DEFAULT NULL,
    `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
    `created_by` INT UNSIGNED DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_clients_firm` (`firm_id`),
    KEY `idx_clients_manager` (`assigned_manager_id`),
    KEY `idx_clients_status` (`audit_status`),
    CONSTRAINT `fk_clients_firm` FOREIGN KEY (`firm_id`) REFERENCES `firms` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_clients_manager` FOREIGN KEY (`assigned_manager_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- After clients exists, add the FK from users.client_id
ALTER TABLE `users`
    ADD CONSTRAINT `fk_users_client` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE;

-- ---------------------------------------------------------------------
-- 4. CLIENT CONTACTS  (multiple contacts per client)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `client_contacts` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `client_id` INT UNSIGNED NOT NULL,
    `name` VARCHAR(150) NOT NULL,
    `position` VARCHAR(100) DEFAULT NULL,
    `email` VARCHAR(190) DEFAULT NULL,
    `phone` VARCHAR(50) DEFAULT NULL,
    `is_primary` TINYINT(1) NOT NULL DEFAULT 0,
    `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_contacts_client` (`client_id`),
    CONSTRAINT `fk_contacts_client` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 5. ENGAGEMENTS
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `engagements` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `firm_id` INT UNSIGNED NOT NULL,
    `client_id` INT UNSIGNED NOT NULL,
    `engagement_code` VARCHAR(50) DEFAULT NULL,
    `financial_year` VARCHAR(20) NOT NULL COMMENT 'e.g. FY2024',
    `period_start` DATE DEFAULT NULL,
    `period_end` DATE DEFAULT NULL,
    `engagement_type` ENUM('audit','review','compilation','tax','advisory') NOT NULL DEFAULT 'audit',
    `fee_amount` DECIMAL(12,2) DEFAULT NULL,
    `start_date` DATE DEFAULT NULL,
    `deadline` DATE DEFAULT NULL,
    `partner_id` INT UNSIGNED DEFAULT NULL,
    `manager_id` INT UNSIGNED DEFAULT NULL,
    `status` ENUM(
        'draft',
        'pending_documents',
        'in_progress',
        'under_review',
        'partner_review',
        'completed',
        'billed',
        'archived'
    ) NOT NULL DEFAULT 'draft',
    `created_by` INT UNSIGNED DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_engagement_code` (`firm_id`, `engagement_code`),
    KEY `idx_eng_firm` (`firm_id`),
    KEY `idx_eng_client` (`client_id`),
    KEY `idx_eng_status` (`status`),
    KEY `idx_eng_partner` (`partner_id`),
    KEY `idx_eng_manager` (`manager_id`),
    CONSTRAINT `fk_eng_firm` FOREIGN KEY (`firm_id`) REFERENCES `firms` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_eng_client` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_eng_partner` FOREIGN KEY (`partner_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_eng_manager` FOREIGN KEY (`manager_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 6. ENGAGEMENT TEAM  (many-to-many: engagement <-> users)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `engagement_team` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `engagement_id` INT UNSIGNED NOT NULL,
    `user_id` INT UNSIGNED NOT NULL,
    `team_role` ENUM('partner','manager','senior','junior','reviewer','other') NOT NULL DEFAULT 'junior',
    `assigned_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_eng_user` (`engagement_id`, `user_id`),
    KEY `idx_team_user` (`user_id`),
    CONSTRAINT `fk_team_eng` FOREIGN KEY (`engagement_id`) REFERENCES `engagements` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_team_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 7. DOCUMENT CATEGORIES  (firm-customisable taxonomy)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `document_categories` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `firm_id` INT UNSIGNED DEFAULT NULL COMMENT 'NULL = system default; firm-level overrides allowed',
    `code` VARCHAR(50) NOT NULL,
    `name` VARCHAR(150) NOT NULL,
    `description` TEXT DEFAULT NULL,
    `sort_order` INT NOT NULL DEFAULT 0,
    `is_default_required` TINYINT(1) NOT NULL DEFAULT 1,
    `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_doc_cat_firm_code` (`firm_id`, `code`),
    CONSTRAINT `fk_doc_cat_firm` FOREIGN KEY (`firm_id`) REFERENCES `firms` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 8. DOCUMENT REQUESTS  (the checklist per engagement)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `document_requests` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `engagement_id` INT UNSIGNED NOT NULL,
    `category_id` INT UNSIGNED DEFAULT NULL,
    `title` VARCHAR(255) NOT NULL,
    `description` TEXT DEFAULT NULL,
    `is_required` TINYINT(1) NOT NULL DEFAULT 1,
    `due_date` DATE DEFAULT NULL,
    `status` ENUM('pending','received','rejected','needs_clarification','waived') NOT NULL DEFAULT 'pending',
    `admin_notes` TEXT DEFAULT NULL,
    `client_notes` TEXT DEFAULT NULL,
    `requested_by` INT UNSIGNED DEFAULT NULL,
    `last_reminder_at` DATETIME DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_docreq_eng` (`engagement_id`),
    KEY `idx_docreq_status` (`status`),
    CONSTRAINT `fk_docreq_eng` FOREIGN KEY (`engagement_id`) REFERENCES `engagements` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_docreq_cat` FOREIGN KEY (`category_id`) REFERENCES `document_categories` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_docreq_user` FOREIGN KEY (`requested_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 9. ENGAGEMENT DOCUMENTS  (uploaded files - private storage)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `engagement_documents` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `engagement_id` INT UNSIGNED NOT NULL,
    `document_request_id` INT UNSIGNED DEFAULT NULL,
    `category_id` INT UNSIGNED DEFAULT NULL,
    `title` VARCHAR(255) NOT NULL,
    `original_filename` VARCHAR(255) NOT NULL,
    `stored_filename` VARCHAR(255) NOT NULL COMMENT 'Random name in /uploads_private',
    `relative_path` VARCHAR(500) NOT NULL COMMENT 'Path relative to uploads_private root',
    `mime_type` VARCHAR(100) DEFAULT NULL,
    `file_size` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `file_hash_sha256` CHAR(64) DEFAULT NULL,
    `status` ENUM('uploaded','accepted','rejected','superseded','archived') NOT NULL DEFAULT 'uploaded',
    `review_notes` TEXT DEFAULT NULL,
    `uploaded_by` INT UNSIGNED DEFAULT NULL,
    `reviewed_by` INT UNSIGNED DEFAULT NULL,
    `reviewed_at` DATETIME DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_engdoc_eng` (`engagement_id`),
    KEY `idx_engdoc_req` (`document_request_id`),
    KEY `idx_engdoc_status` (`status`),
    CONSTRAINT `fk_engdoc_eng` FOREIGN KEY (`engagement_id`) REFERENCES `engagements` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_engdoc_req` FOREIGN KEY (`document_request_id`) REFERENCES `document_requests` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_engdoc_cat` FOREIGN KEY (`category_id`) REFERENCES `document_categories` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_engdoc_uploader` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_engdoc_reviewer` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 10. AUDIT SECTIONS  (template library of working-paper sections)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `audit_sections` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `firm_id` INT UNSIGNED DEFAULT NULL COMMENT 'NULL = global template',
    `code` VARCHAR(50) NOT NULL,
    `name` VARCHAR(200) NOT NULL,
    `parent_id` INT UNSIGNED DEFAULT NULL,
    `sort_order` INT NOT NULL DEFAULT 0,
    `description` TEXT DEFAULT NULL,
    `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_section_firm_code` (`firm_id`, `code`),
    KEY `idx_section_parent` (`parent_id`),
    CONSTRAINT `fk_section_firm` FOREIGN KEY (`firm_id`) REFERENCES `firms` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_section_parent` FOREIGN KEY (`parent_id`) REFERENCES `audit_sections` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 11. AUDIT WORKING PAPERS
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `audit_working_papers` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `engagement_id` INT UNSIGNED NOT NULL,
    `section_id` INT UNSIGNED DEFAULT NULL,
    `reference_code` VARCHAR(50) DEFAULT NULL COMMENT 'e.g. A-100, B-200',
    `title` VARCHAR(255) NOT NULL,
    `procedure` TEXT DEFAULT NULL,
    `conclusion` TEXT DEFAULT NULL,
    `notes` TEXT DEFAULT NULL,
    `prepared_by` INT UNSIGNED DEFAULT NULL,
    `prepared_at` DATETIME DEFAULT NULL,
    `reviewed_by` INT UNSIGNED DEFAULT NULL,
    `reviewed_at` DATETIME DEFAULT NULL,
    `status` ENUM(
        'not_started',
        'prepared',
        'pending_review',
        'review_note_raised',
        'cleared',
        'completed'
    ) NOT NULL DEFAULT 'not_started',
    `risk_rating` ENUM('low','medium','high','critical') DEFAULT NULL,
    `ai_summary` TEXT DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_wp_eng` (`engagement_id`),
    KEY `idx_wp_section` (`section_id`),
    KEY `idx_wp_status` (`status`),
    CONSTRAINT `fk_wp_eng` FOREIGN KEY (`engagement_id`) REFERENCES `engagements` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_wp_section` FOREIGN KEY (`section_id`) REFERENCES `audit_sections` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_wp_prep` FOREIGN KEY (`prepared_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_wp_rev` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 12. AUDIT REVIEW NOTES  (Q&A trail between preparer / reviewer / partner)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `audit_review_notes` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `working_paper_id` INT UNSIGNED NOT NULL,
    `raised_by` INT UNSIGNED DEFAULT NULL,
    `assigned_to` INT UNSIGNED DEFAULT NULL,
    `note` TEXT NOT NULL,
    `response` TEXT DEFAULT NULL,
    `severity` ENUM('info','minor','major','critical') NOT NULL DEFAULT 'minor',
    `status` ENUM('open','responded','cleared','reopened') NOT NULL DEFAULT 'open',
    `cleared_by` INT UNSIGNED DEFAULT NULL,
    `cleared_at` DATETIME DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_revnote_wp` (`working_paper_id`),
    KEY `idx_revnote_status` (`status`),
    CONSTRAINT `fk_revnote_wp` FOREIGN KEY (`working_paper_id`) REFERENCES `audit_working_papers` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_revnote_raised` FOREIGN KEY (`raised_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_revnote_assigned` FOREIGN KEY (`assigned_to`) REFERENCES `users` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_revnote_cleared` FOREIGN KEY (`cleared_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 13. CHART OF ACCOUNTS  (per engagement / per client)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `chart_of_accounts` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `engagement_id` INT UNSIGNED NOT NULL,
    `account_code` VARCHAR(50) NOT NULL,
    `account_name` VARCHAR(255) NOT NULL,
    `account_type` ENUM('asset','liability','equity','income','expense','other') NOT NULL DEFAULT 'other',
    `parent_code` VARCHAR(50) DEFAULT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_coa_eng_code` (`engagement_id`, `account_code`),
    KEY `idx_coa_type` (`account_type`),
    CONSTRAINT `fk_coa_eng` FOREIGN KEY (`engagement_id`) REFERENCES `engagements` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 14. TRIAL BALANCES
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `trial_balances` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `engagement_id` INT UNSIGNED NOT NULL,
    `account_code` VARCHAR(50) NOT NULL,
    `account_name` VARCHAR(255) NOT NULL,
    `period` ENUM('current','prior') NOT NULL DEFAULT 'current',
    `debit` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    `credit` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    `balance` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_tb_eng_period` (`engagement_id`, `period`),
    KEY `idx_tb_code` (`account_code`),
    CONSTRAINT `fk_tb_eng` FOREIGN KEY (`engagement_id`) REFERENCES `engagements` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 15. GENERAL LEDGER  (transaction-level)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `general_ledgers` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `engagement_id` INT UNSIGNED NOT NULL,
    `transaction_date` DATE NOT NULL,
    `account_code` VARCHAR(50) NOT NULL,
    `account_name` VARCHAR(255) DEFAULT NULL,
    `reference_no` VARCHAR(100) DEFAULT NULL,
    `description` TEXT DEFAULT NULL,
    `debit` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    `credit` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    `source` VARCHAR(50) DEFAULT NULL COMMENT 'sql_acc / autocount / excel / ubs / etc',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_gl_eng_date` (`engagement_id`, `transaction_date`),
    KEY `idx_gl_account` (`account_code`),
    CONSTRAINT `fk_gl_eng` FOREIGN KEY (`engagement_id`) REFERENCES `engagements` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 16. AI LOGS  (every prompt + response, billable)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ai_logs` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `firm_id` INT UNSIGNED NOT NULL,
    `engagement_id` INT UNSIGNED DEFAULT NULL,
    `user_id` INT UNSIGNED DEFAULT NULL,
    `function_name` VARCHAR(100) NOT NULL COMMENT 'e.g. ai_analyze_trial_balance',
    `provider` VARCHAR(50) DEFAULT NULL COMMENT 'anthropic / openai / local',
    `model` VARCHAR(100) DEFAULT NULL,
    `prompt` MEDIUMTEXT DEFAULT NULL,
    `input_payload` MEDIUMTEXT DEFAULT NULL,
    `output` MEDIUMTEXT DEFAULT NULL,
    `input_tokens` INT UNSIGNED DEFAULT NULL,
    `output_tokens` INT UNSIGNED DEFAULT NULL,
    `total_tokens` INT UNSIGNED DEFAULT NULL,
    `credits_used` DECIMAL(10,4) NOT NULL DEFAULT 0.0000,
    `latency_ms` INT UNSIGNED DEFAULT NULL,
    `status` ENUM('pending','success','failed','timeout') NOT NULL DEFAULT 'pending',
    `error_message` TEXT DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_ailog_firm` (`firm_id`),
    KEY `idx_ailog_eng` (`engagement_id`),
    KEY `idx_ailog_user` (`user_id`),
    KEY `idx_ailog_fn` (`function_name`),
    KEY `idx_ailog_status` (`status`),
    CONSTRAINT `fk_ailog_firm` FOREIGN KEY (`firm_id`) REFERENCES `firms` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_ailog_eng` FOREIGN KEY (`engagement_id`) REFERENCES `engagements` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_ailog_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 17. AI OUTPUTS  (curated AI artefacts surfaced inside the UI)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ai_outputs` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `ai_log_id` BIGINT UNSIGNED DEFAULT NULL,
    `firm_id` INT UNSIGNED NOT NULL,
    `engagement_id` INT UNSIGNED DEFAULT NULL,
    `entity_type` VARCHAR(50) NOT NULL COMMENT 'e.g. working_paper, document_request, trial_balance',
    `entity_id` BIGINT UNSIGNED DEFAULT NULL,
    `output_type` VARCHAR(50) NOT NULL COMMENT 'variance_analysis, audit_query, management_letter, ...',
    `title` VARCHAR(255) DEFAULT NULL,
    `content` MEDIUMTEXT NOT NULL,
    `status` ENUM('draft','accepted','rejected','published') NOT NULL DEFAULT 'draft',
    `created_by` INT UNSIGNED DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_aiout_firm` (`firm_id`),
    KEY `idx_aiout_eng` (`engagement_id`),
    KEY `idx_aiout_entity` (`entity_type`, `entity_id`),
    KEY `idx_aiout_log` (`ai_log_id`),
    CONSTRAINT `fk_aiout_firm` FOREIGN KEY (`firm_id`) REFERENCES `firms` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_aiout_eng` FOREIGN KEY (`engagement_id`) REFERENCES `engagements` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_aiout_log` FOREIGN KEY (`ai_log_id`) REFERENCES `ai_logs` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 18. STAFF KPI  (daily snapshot, aggregated by job)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `staff_kpi` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `firm_id` INT UNSIGNED NOT NULL,
    `user_id` INT UNSIGNED NOT NULL,
    `as_of_date` DATE NOT NULL,
    `jobs_assigned` INT UNSIGNED NOT NULL DEFAULT 0,
    `jobs_completed` INT UNSIGNED NOT NULL DEFAULT 0,
    `working_papers_prepared` INT UNSIGNED NOT NULL DEFAULT 0,
    `review_notes_received` INT UNSIGNED NOT NULL DEFAULT 0,
    `review_notes_cleared` INT UNSIGNED NOT NULL DEFAULT 0,
    `avg_completion_days` DECIMAL(8,2) DEFAULT NULL,
    `overdue_tasks` INT UNSIGNED NOT NULL DEFAULT 0,
    `productivity_score` DECIMAL(6,2) DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_kpi_user_date` (`user_id`, `as_of_date`),
    KEY `idx_kpi_firm` (`firm_id`),
    CONSTRAINT `fk_kpi_firm` FOREIGN KEY (`firm_id`) REFERENCES `firms` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_kpi_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 19. CREDIT WALLET  (per firm)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `credit_wallet` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `firm_id` INT UNSIGNED NOT NULL,
    `balance` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `total_topped_up` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `total_used` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `currency` VARCHAR(10) NOT NULL DEFAULT 'MYR',
    `status` ENUM('active','frozen') NOT NULL DEFAULT 'active',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_wallet_firm` (`firm_id`),
    CONSTRAINT `fk_wallet_firm` FOREIGN KEY (`firm_id`) REFERENCES `firms` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 20. CREDIT TRANSACTIONS  (ledger of top-ups & usage)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `credit_transactions` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `firm_id` INT UNSIGNED NOT NULL,
    `direction` ENUM('credit','debit') NOT NULL,
    `amount` DECIMAL(12,4) NOT NULL,
    `balance_after` DECIMAL(12,4) NOT NULL,
    `usage_type` VARCHAR(50) DEFAULT NULL COMMENT 'top_up, ai_review, ml_generation, fs_review, ocr',
    `reference_type` VARCHAR(50) DEFAULT NULL,
    `reference_id` BIGINT UNSIGNED DEFAULT NULL,
    `notes` TEXT DEFAULT NULL,
    `created_by` INT UNSIGNED DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_credittx_firm` (`firm_id`),
    KEY `idx_credittx_usage` (`usage_type`),
    CONSTRAINT `fk_credittx_firm` FOREIGN KEY (`firm_id`) REFERENCES `firms` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_credittx_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 21. ACTIVITY LOGS  (audit trail of user actions)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `activity_logs` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `firm_id` INT UNSIGNED DEFAULT NULL,
    `user_id` INT UNSIGNED DEFAULT NULL,
    `action` VARCHAR(100) NOT NULL,
    `entity_type` VARCHAR(50) DEFAULT NULL,
    `entity_id` BIGINT UNSIGNED DEFAULT NULL,
    `description` TEXT DEFAULT NULL,
    `ip_address` VARCHAR(45) DEFAULT NULL,
    `user_agent` VARCHAR(255) DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_act_firm` (`firm_id`),
    KEY `idx_act_user` (`user_id`),
    KEY `idx_act_entity` (`entity_type`, `entity_id`),
    KEY `idx_act_created` (`created_at`),
    CONSTRAINT `fk_act_firm` FOREIGN KEY (`firm_id`) REFERENCES `firms` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_act_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- =====================================================================
-- SEED DATA: system-default document categories & audit sections
-- (firm_id = NULL means "available to all firms")
-- =====================================================================

INSERT INTO `document_categories` (`firm_id`, `code`, `name`, `sort_order`, `is_default_required`) VALUES
    (NULL, 'TB',       'Trial Balance',                10, 1),
    (NULL, 'GL',       'General Ledger',               20, 1),
    (NULL, 'BANK',     'Bank Statements',              30, 1),
    (NULL, 'SALES',    'Sales Invoices',               40, 1),
    (NULL, 'PURCH',    'Purchase Invoices',            50, 1),
    (NULL, 'PAYROLL',  'Payroll Records',              60, 1),
    (NULL, 'TAX',      'Tax Documents',                70, 1),
    (NULL, 'AGR',      'Agreements & Contracts',       80, 0),
    (NULL, 'PYFS',     'Prior Year Financial Stmts',   90, 1),
    (NULL, 'COSEC',    'Company Secretary Docs',      100, 0),
    (NULL, 'FA',       'Fixed Asset Listing',         110, 1),
    (NULL, 'INV',      'Inventory Listing',           120, 0),
    (NULL, 'AR',       'Debtor Aging',                130, 1),
    (NULL, 'AP',       'Creditor Aging',              140, 1);

INSERT INTO `audit_sections` (`firm_id`, `code`, `name`, `sort_order`) VALUES
    (NULL, 'A-100', 'Planning',                       10),
    (NULL, 'A-200', 'Risk Assessment',                20),
    (NULL, 'B-100', 'Cash and Bank',                  30),
    (NULL, 'B-200', 'Sales and Receivables',          40),
    (NULL, 'B-300', 'Purchases and Payables',         50),
    (NULL, 'B-400', 'Inventory',                      60),
    (NULL, 'B-500', 'Fixed Assets',                   70),
    (NULL, 'B-600', 'Payroll',                        80),
    (NULL, 'B-700', 'Taxation',                       90),
    (NULL, 'C-100', 'Related Party Transactions',    100),
    (NULL, 'C-200', 'Going Concern',                 110),
    (NULL, 'C-300', 'Subsequent Events',             120),
    (NULL, 'D-100', 'Financial Statements Review',   130),
    (NULL, 'E-100', 'Completion',                    140);

-- =====================================================================
-- Super Admin user is NOT seeded here, because a real bcrypt hash must
-- be generated by PHP (password_hash with PASSWORD_DEFAULT). Run the
-- one-shot installer instead:
--
--     php /sql/install_super_admin.php
--
-- It will prompt for an email + password and insert the super_admin row.
-- =====================================================================
