<?php
/**
 * /auth/accept_invite.php?token=...
 *
 * Thin alias for /auth/reset_password.php — invitations use the same
 * single-use token mechanism (users.password_reset_token) so reaching
 * either URL with a valid token brings the invitee to the password-set
 * form. The status flip from inactive → active happens in
 * reset_password.php's POST handler.
 */

declare(strict_types=1);

define('AUDITBOS_PUBLIC', true);
require_once __DIR__ . '/../includes/auth_guard.php';

$token = trim((string)($_GET['token'] ?? ''));
redirect('/auth/reset_password.php' . ($token !== '' ? '?token=' . urlencode($token) : ''));
