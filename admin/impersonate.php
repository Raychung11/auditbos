<?php
/**
 * /admin/impersonate.php
 *
 * Lets a super admin temporarily "become" a firm user — without
 * signing out and back in — for support, debugging, and verifying
 * what a firm sees.
 *
 * POST _action=start &user_id=N
 *   Snapshots the super-admin session payload into $_SESSION['real_user']
 *   and overwrites $_SESSION['user'] with the target firm user's
 *   payload. All scoping (current_firm_id, current_role) then flows
 *   naturally — pages render as the impersonated user.
 *
 * POST _action=stop
 *   Restores the real super-admin session and clears the impersonation
 *   pointer.
 *
 * Security:
 * - Start is gated to the *real* super_admin role (we read the real
 *   user, not current_user, so nested impersonation is impossible).
 * - Target must be an active firm-level user; super_admin and
 *   client_user roles are off-limits.
 * - Session ID is regenerated on every swap.
 * - Both start and stop are logged.
 */

declare(strict_types=1);
require_once __DIR__ . '/../includes/auth_guard.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/admin/firms.php');
}
csrf_check();

$pdo    = db();
$action = $_POST['_action'] ?? '';

// =====================================================================
// STOP — restore the real super-admin session.
// =====================================================================
if ($action === 'stop') {
    if (is_impersonating()) {
        $imp = current_user();
        log_activity('impersonation.stop', 'user', $imp['id'] ?? null,
            'Stopped impersonating ' . ($imp['name'] ?? '?'));
        $_SESSION['user'] = $_SESSION['real_user'];
        unset($_SESSION['real_user']);
        session_regenerate_id(true);
        flash('success', 'Back to your super-admin session.');
    }
    redirect($_POST['return_to'] ?? '/admin/firms.php');
}

// =====================================================================
// START — only the real super admin may do this, and never to nest.
// =====================================================================
if ($action !== 'start') {
    redirect('/admin/firms.php');
}

if (is_impersonating()) {
    flash('error', 'You are already impersonating. Stop the current session before starting another.');
    redirect('/admin/firms.php');
}
$realRole = $_SESSION['user']['role'] ?? null;
if ($realRole !== 'super_admin') {
    http_response_code(403);
    exit('Only super admins can impersonate.');
}

$targetId = (int)($_POST['user_id'] ?? 0);
if ($targetId <= 0) {
    flash('error', 'Pick a user to impersonate.');
    redirect('/admin/firms.php');
}

$stmt = $pdo->prepare(
    'SELECT id, firm_id, client_id, role, name, email, status
       FROM users WHERE id = :id'
);
$stmt->execute([':id' => $targetId]);
$target = $stmt->fetch();

if (!$target) {
    flash('error', 'User not found.');
    redirect('/admin/firms.php');
}
if ($target['status'] !== 'active') {
    flash('error', 'Cannot impersonate an inactive or suspended user.');
    redirect('/admin/firms.php');
}
if ($target['role'] === 'super_admin') {
    flash('error', 'Cannot impersonate another super admin.');
    redirect('/admin/firms.php');
}
if ($target['role'] === 'client_user') {
    // Allowable in theory but out of scope for v1 — keeps the model simple
    // (super admin → firm staff only). Easy to relax later.
    flash('error', 'Client portal users cannot be impersonated yet.');
    redirect('/admin/firms.php');
}
if (empty($target['firm_id'])) {
    flash('error', 'Target user has no firm context.');
    redirect('/admin/firms.php');
}

// Log BEFORE swapping the session so the real super-admin user_id is recorded.
log_activity('impersonation.start', 'user', (int) $target['id'],
    'Began impersonating ' . $target['name'] . ' (' . $target['role'] . ')');

$_SESSION['real_user'] = $_SESSION['user'];
$_SESSION['user'] = [
    'id'        => (int) $target['id'],
    'firm_id'   => (int) $target['firm_id'],
    'client_id' => $target['client_id'] !== null ? (int) $target['client_id'] : null,
    'role'      => $target['role'],
    'name'      => $target['name'],
    'email'     => $target['email'],
];
$_SESSION['_last_activity'] = time();
session_regenerate_id(true);

flash('success', 'Now viewing as ' . $target['name'] . '. Click "Stop impersonating" at the top to return.');
redirect('/dashboard.php');
