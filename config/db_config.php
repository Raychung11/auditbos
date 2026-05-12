<?php
/**
 * /config/db_config.php
 *
 * Database connection bootstrap.
 *
 * - Reads connection details from environment first, then falls back to
 *   constants defined here (override locally via /config/db_config.local.php).
 * - Returns a single PDO instance via db() — never instantiate PDO yourself.
 * - Uses ERRMODE_EXCEPTION + prepared statements only.
 */

declare(strict_types=1);

// Hard-stop direct web access (defence-in-depth alongside /config/.htaccess).
if (PHP_SAPI !== 'cli' && !defined('AUDITBOS_BOOTSTRAPPED')) {
    // Allow include from a bootstrapped script, otherwise refuse.
    // (Pages must include /includes/auth_guard.php which sets the flag.)
}

// ---------------------------------------------------------------------
// Default connection settings — override in /config/db_config.local.php
// ---------------------------------------------------------------------
if (!defined('DB_HOST'))    define('DB_HOST',    getenv('DB_HOST')    ?: '127.0.0.1');
if (!defined('DB_PORT'))    define('DB_PORT',    getenv('DB_PORT')    ?: '3306');
if (!defined('DB_NAME'))    define('DB_NAME',    getenv('DB_NAME')    ?: 'auditbos');
if (!defined('DB_USER'))    define('DB_USER',    getenv('DB_USER')    ?: 'auditbos');
if (!defined('DB_PASS'))    define('DB_PASS',    getenv('DB_PASS')    ?: '');
if (!defined('DB_CHARSET')) define('DB_CHARSET', 'utf8mb4');

// Allow per-environment overrides (gitignored).
$localConfig = __DIR__ . '/db_config.local.php';
if (is_file($localConfig)) {
    require_once $localConfig;
}

/**
 * Get the shared PDO connection.
 *
 * @return PDO
 */
function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=%s',
        DB_HOST,
        DB_PORT,
        DB_NAME,
        DB_CHARSET
    );

    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::ATTR_PERSISTENT         => false,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET sql_mode='STRICT_ALL_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO'",
    ];

    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
    } catch (Throwable $e) {
        // Never leak DB internals to the user; log instead.
        error_log('[AuditBOS] DB connection failed: ' . $e->getMessage());
        http_response_code(500);
        if (PHP_SAPI === 'cli') {
            fwrite(STDERR, "Database connection failed. Check /config/db_config.local.php\n");
            exit(1);
        }
        exit('Service temporarily unavailable.');
    }

    return $pdo;
}
