<?php
/**
 * /includes/auth_guard.php
 *
 * The single entrypoint every authenticated page must require_once at the
 * very top. It:
 *   1. Starts a hardened session
 *   2. Loads config + helpers + CSRF
 *   3. Enforces login (or redirects to /auth/login.php)
 *   4. Optionally enforces role-based access via require_role()
 *
 * Usage at the top of every protected PHP page:
 *
 *     <?php
 *     declare(strict_types=1);
 *     require_once __DIR__ . '/../includes/auth_guard.php';
 *     require_role(['firm_admin', 'audit_manager']);
 *     ?>
 */

declare(strict_types=1);

// Tell config/include files they were loaded by the framework, not direct hit.
if (!defined('AUDITBOS_BOOTSTRAPPED')) {
    define('AUDITBOS_BOOTSTRAPPED', true);
}

require_once __DIR__ . '/../config/app_config.php';
require_once __DIR__ . '/../config/db_config.php';

// ---------------------------------------------------------------------
// Session bootstrap — hardened cookie settings.
// ---------------------------------------------------------------------
if (session_status() === PHP_SESSION_NONE) {
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

    session_name(SESSION_NAME);
    session_set_cookie_params([
        'lifetime' => SESSION_LIFETIME,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');

    session_start();

    // Rotating ID after login is handled in login.php; here we just enforce
    // an absolute idle timeout.
    if (isset($_SESSION['_last_activity'])
        && (time() - (int) $_SESSION['_last_activity']) > SESSION_LIFETIME) {
        $_SESSION = [];
        session_destroy();
        session_start();
    }
    $_SESSION['_last_activity'] = time();
}

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/csrf.php';

// ---------------------------------------------------------------------
// Current-user accessors.
// ---------------------------------------------------------------------

function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

function current_user_id(): ?int
{
    $u = current_user();
    return $u ? (int) $u['id'] : null;
}

function current_firm_id(): ?int
{
    $u = current_user();
    return $u && !empty($u['firm_id']) ? (int) $u['firm_id'] : null;
}

function current_role(): ?string
{
    $u = current_user();
    return $u['role'] ?? null;
}

function is_logged_in(): bool
{
    return current_user() !== null;
}

function require_login(): void
{
    if (!is_logged_in()) {
        // Save intended destination for post-login redirect.
        $_SESSION['_intended_url'] = $_SERVER['REQUEST_URI'] ?? '/dashboard.php';
        redirect('/auth/login.php');
    }
}

/**
 * Enforce that the current user holds one of the allowed roles.
 *
 * @param array<int, string> $allowedRoles
 */
function require_role(array $allowedRoles): void
{
    require_login();
    $role = current_role();
    if (!in_array($role, $allowedRoles, true)) {
        http_response_code(403);
        // Minimal 403 page — avoids leaking layout to unauthorised users.
        echo '<!doctype html><meta charset="utf-8"><title>403</title>'
            . '<div style="font-family:system-ui;padding:48px;text-align:center">'
            . '<h1 style="font-size:48px;margin:0">403</h1>'
            . '<p>You do not have permission to access this page.</p>'
            . '<p><a href="/dashboard.php">Back to dashboard</a></p>'
            . '</div>';
        exit;
    }
}

/**
 * Returns true if current user is allowed any of the listed roles
 * (does NOT exit). Useful for conditional UI rendering.
 */
function role_allows(array $allowedRoles): bool
{
    return in_array(current_role(), $allowedRoles, true);
}

// ---------------------------------------------------------------------
// Auto-enforce login on every page that includes this guard, UNLESS the
// page declared AUDITBOS_PUBLIC = true beforehand (login/forgot password).
// ---------------------------------------------------------------------
if (!defined('AUDITBOS_PUBLIC') || AUDITBOS_PUBLIC !== true) {
    require_login();
}

// CSRF verification runs automatically on POST. Pages can call csrf_check()
// explicitly too — it's idempotent.
csrf_check();
