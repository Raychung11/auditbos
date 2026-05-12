<?php
/**
 * /auth/logout.php
 *
 * Destroy the session and bounce to login.
 */

declare(strict_types=1);

define('AUDITBOS_PUBLIC', true);
require_once __DIR__ . '/../includes/auth_guard.php';

if (is_logged_in()) {
    log_activity('user.logout', 'user', current_user_id());
}

$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params['path'],
        $params['domain'],
        $params['secure'],
        $params['httponly']
    );
}
session_destroy();

redirect('/auth/login.php');
