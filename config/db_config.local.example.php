<?php
/**
 * /config/db_config.local.example.php
 *
 * Copy this file to /config/db_config.local.php and fill in your values.
 * The local file is .gitignored — never commit secrets.
 */

declare(strict_types=1);

define('DB_HOST', '127.0.0.1');
define('DB_PORT', '3306');
define('DB_NAME', 'auditbos');
define('DB_USER', 'auditbos');
define('DB_PASS', 'replace-me');

// ---------------------------------------------------------------------
// AI provider (optional — leave AI_API_KEY empty to keep the stub on).
// ---------------------------------------------------------------------
define('AI_PROVIDER', 'anthropic');
define('AI_MODEL',    'claude-opus-4-7');
define('AI_API_KEY',  '');       // set to enable real AI calls
define('AI_CREDITS_PER_CALL', 1.00);

// ---------------------------------------------------------------------
// Optional overrides
// ---------------------------------------------------------------------
// define('APP_BASE_URL', 'https://app.your-firm.com');
// define('UPLOAD_MAX_BYTES', 60 * 1024 * 1024);
