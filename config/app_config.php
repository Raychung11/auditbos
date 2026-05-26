<?php
/**
 * /config/app_config.php
 *
 * Application-wide constants & sane PHP defaults.
 */

declare(strict_types=1);

// ---------------------------------------------------------------------
// Branding & paths
// ---------------------------------------------------------------------
if (!defined('APP_NAME'))         define('APP_NAME',         'AI Audit BOS');
if (!defined('APP_VERSION'))      define('APP_VERSION',      '0.1.0');
if (!defined('APP_BASE_URL'))     define('APP_BASE_URL',     getenv('APP_BASE_URL') ?: '');

// Project root = parent of /config
if (!defined('APP_ROOT'))         define('APP_ROOT', dirname(__DIR__));
if (!defined('UPLOADS_PRIVATE'))  define('UPLOADS_PRIVATE', APP_ROOT . '/uploads_private');

// ---------------------------------------------------------------------
// Session & security
// ---------------------------------------------------------------------
if (!defined('SESSION_NAME'))           define('SESSION_NAME',           'AUDITBOS_SID');
if (!defined('SESSION_LIFETIME'))       define('SESSION_LIFETIME',       60 * 60 * 8); // 8h
if (!defined('LOGIN_MAX_ATTEMPTS'))     define('LOGIN_MAX_ATTEMPTS',     6);
if (!defined('LOGIN_LOCKOUT_SECONDS'))  define('LOGIN_LOCKOUT_SECONDS',  900); // 15 min

// ---------------------------------------------------------------------
// Uploads
// ---------------------------------------------------------------------
if (!defined('UPLOAD_MAX_BYTES'))   define('UPLOAD_MAX_BYTES',   30 * 1024 * 1024); // 30MB
if (!defined('UPLOAD_ALLOWED_EXT')) define('UPLOAD_ALLOWED_EXT', [
    'pdf','xls','xlsx','csv','doc','docx','ppt','pptx',
    'png','jpg','jpeg','gif','txt','zip','7z','rtf','xml','json'
]);
if (!defined('UPLOAD_ALLOWED_MIME')) define('UPLOAD_ALLOWED_MIME', [
    'application/pdf',
    'application/vnd.ms-excel',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.template',
    'application/msword',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'application/vnd.ms-powerpoint',
    'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    'text/csv',
    'text/plain',
    'image/png',
    'image/jpeg',
    'image/gif',
    'application/zip',
    'application/x-7z-compressed',
    'application/rtf',
    'application/xml',
    'text/xml',
    'application/json',
    // Common fallbacks for older browsers / older Office files
    'application/octet-stream',
]);

// ---------------------------------------------------------------------
// AI provider (stub — wire to real keys in db_config.local.php)
// ---------------------------------------------------------------------
if (!defined('AI_PROVIDER'))     define('AI_PROVIDER',     getenv('AI_PROVIDER')     ?: 'anthropic');
if (!defined('AI_MODEL'))        define('AI_MODEL',        getenv('AI_MODEL')        ?: 'claude-opus-4-7');
if (!defined('AI_API_KEY'))      define('AI_API_KEY',      getenv('AI_API_KEY')      ?: '');
if (!defined('AI_ENABLED'))      define('AI_ENABLED',      (bool) AI_API_KEY);
if (!defined('AI_CREDITS_PER_CALL')) define('AI_CREDITS_PER_CALL', 1.00);

// ---------------------------------------------------------------------
// Outbound mail. Until SMTP is configured, MAIL_ENABLED stays false and
// password-reset / invitation flows surface a copyable link in the UI
// for manual relay (suits internal tooling on shared hosting).
// ---------------------------------------------------------------------
if (!defined('MAIL_FROM'))      define('MAIL_FROM',      getenv('MAIL_FROM')      ?: '');
if (!defined('MAIL_FROM_NAME')) define('MAIL_FROM_NAME', getenv('MAIL_FROM_NAME') ?: APP_NAME);
if (!defined('MAIL_ENABLED'))   define('MAIL_ENABLED',   (bool) MAIL_FROM);
if (!defined('TOKEN_TTL_SECONDS')) define('TOKEN_TTL_SECONDS', 60 * 60 * 24); // 24h for reset / invite tokens

// ---------------------------------------------------------------------
// Reminders. CRON_TOKEN guards /cron/reminders.php so only your
// scheduler can trigger automated sends. REMINDER_COOLDOWN_DAYS stops
// the same client being nudged more often than this.
// ---------------------------------------------------------------------
if (!defined('CRON_TOKEN'))             define('CRON_TOKEN',             getenv('CRON_TOKEN') ?: '');
if (!defined('REMINDER_COOLDOWN_DAYS')) define('REMINDER_COOLDOWN_DAYS', 3);

// ---------------------------------------------------------------------
// PHP hardening (no display_errors in prod)
// ---------------------------------------------------------------------
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);
date_default_timezone_set(getenv('APP_TZ') ?: 'Asia/Kuala_Lumpur');

// Pre-flight: ensure private upload dir exists & is writable.
if (!is_dir(UPLOADS_PRIVATE)) {
    @mkdir(UPLOADS_PRIVATE, 0750, true);
}
