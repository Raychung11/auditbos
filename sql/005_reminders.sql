-- =====================================================================
-- AI Audit BOS - Phase 2: Automated reminders
-- File: /sql/005_reminders.sql
-- =====================================================================
-- Logs every reminder sent (client document chasing, staff nudges) so
-- the firm has a history and the cron job knows what was already sent.
-- document_requests.last_reminder_at (already in 001) records the last
-- time a client was nudged about an engagement's outstanding documents.
-- =====================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `reminders` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `firm_id` INT UNSIGNED NOT NULL,
    `engagement_id` INT UNSIGNED DEFAULT NULL,
    `client_id` INT UNSIGNED DEFAULT NULL,
    `target_user_id` INT UNSIGNED DEFAULT NULL COMMENT 'For staff nudges',
    `reminder_type` ENUM(
        'client_documents',
        'staff_overdue_wp',
        'staff_pending_review',
        'engagement_deadline'
    ) NOT NULL,
    `channel` ENUM('email','whatsapp','in_app','manual') NOT NULL DEFAULT 'manual',
    `recipient` VARCHAR(190) DEFAULT NULL,
    `subject` VARCHAR(255) DEFAULT NULL,
    `body` MEDIUMTEXT DEFAULT NULL,
    `status` ENUM('sent','failed','manual') NOT NULL DEFAULT 'manual',
    `triggered_by` ENUM('user','cron') NOT NULL DEFAULT 'user',
    `created_by` INT UNSIGNED DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_rem_firm` (`firm_id`),
    KEY `idx_rem_eng` (`engagement_id`),
    KEY `idx_rem_type` (`reminder_type`),
    KEY `idx_rem_created` (`created_at`),
    CONSTRAINT `fk_rem_firm` FOREIGN KEY (`firm_id`) REFERENCES `firms` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_rem_eng` FOREIGN KEY (`engagement_id`) REFERENCES `engagements` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_rem_client` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_rem_target` FOREIGN KEY (`target_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_rem_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
