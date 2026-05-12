<?php
/**
 * /index.php
 *
 * Root router. Sends signed-in users to /dashboard.php and everyone else
 * to /auth/login.php.
 */

declare(strict_types=1);

define('AUDITBOS_PUBLIC', true);
require_once __DIR__ . '/includes/auth_guard.php';

redirect(is_logged_in() ? '/dashboard.php' : '/auth/login.php');
