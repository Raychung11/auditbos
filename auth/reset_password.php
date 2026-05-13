<?php
/**
 * /auth/reset_password.php?token=...
 *
 * Step 2 of password reset (also used as the accept-invitation endpoint
 * since the token shape is identical — see /auth/accept_invite.php which
 * delegates here via the same token field).
 *
 * On valid token + matching expiry:
 *   - Show the new-password form
 *   - On POST, set password_hash, clear token + expiry, clear lockout,
 *     activate account (handles invitation case where status is "inactive")
 *   - Regenerate session and sign the user straight in
 */

declare(strict_types=1);

define('AUDITBOS_PUBLIC', true);
require_once __DIR__ . '/../includes/auth_guard.php';

if (is_logged_in()) {
    redirect('/dashboard.php');
}

$token = trim((string)($_GET['token'] ?? $_POST['token'] ?? ''));
if ($token === '') {
    flash('error', 'Missing reset token.');
    redirect('/auth/forgot_password.php');
}

$tokenHash = hash('sha256', $token);

$pdo = db();
$stmt = $pdo->prepare(
    'SELECT id, name, email, status, password_reset_expires_at
       FROM users
      WHERE password_reset_token = :h
      LIMIT 1'
);
$stmt->execute([':h' => $tokenHash]);
$user = $stmt->fetch();

$invalid = false;
if (!$user) {
    $invalid = true;
} elseif (!$user['password_reset_expires_at']
       || strtotime($user['password_reset_expires_at']) < time()) {
    $invalid = true;
} elseif ($user['status'] === 'suspended') {
    $invalid = true;
}

$error = null;

if (!$invalid && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $pw      = (string)($_POST['password'] ?? '');
    $confirm = (string)($_POST['password_confirm'] ?? '');

    if (strlen($pw) < 12) {
        $error = 'Password must be at least 12 characters.';
    } elseif ($pw !== $confirm) {
        $error = 'Passwords do not match.';
    } else {
        $hash = password_hash($pw, PASSWORD_DEFAULT);
        $pdo->prepare(
            'UPDATE users
                SET password_hash = :h,
                    password_reset_token = NULL,
                    password_reset_expires_at = NULL,
                    failed_login_count = 0,
                    locked_until = NULL,
                    status = CASE WHEN status = "inactive" THEN "active" ELSE status END
              WHERE id = :id'
        )->execute([':h'=>$hash, ':id'=>$user['id']]);

        // Re-fetch active row for session payload
        $fresh = $pdo->prepare(
            'SELECT id, firm_id, client_id, role, name, email FROM users WHERE id = :id'
        );
        $fresh->execute([':id' => $user['id']]);
        $u = $fresh->fetch();

        session_regenerate_id(true);
        $_SESSION['user'] = [
            'id'        => (int) $u['id'],
            'firm_id'   => $u['firm_id'] !== null ? (int) $u['firm_id'] : null,
            'client_id' => $u['client_id'] !== null ? (int) $u['client_id'] : null,
            'role'      => $u['role'],
            'name'      => $u['name'],
            'email'     => $u['email'],
        ];
        $_SESSION['_last_activity'] = time();

        log_activity('password_reset.complete', 'user', (int) $u['id']);

        $landing = match ($u['role']) {
            'super_admin' => '/admin/firms.php',
            'client_user' => '/documents/index.php',
            default       => '/dashboard.php',
        };
        flash('success', 'Password set. You are signed in.');
        redirect($landing);
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Reset password · <?= e(APP_NAME) ?></title>
    <meta name="robots" content="noindex,nofollow">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = { theme: { extend: { colors: { brand: {
            50:'#eef2ff',100:'#e0e7ff',500:'#4f46e5',600:'#4338ca',700:'#3730a3',900:'#1e1b4b'
        }}}}};
    </script>
</head>
<body class="min-h-screen bg-gradient-to-br from-brand-900 via-brand-700 to-brand-500 flex items-center justify-center px-4">
    <div class="w-full max-w-md">
        <div class="text-center mb-6 text-white">
            <div class="inline-flex items-center justify-center w-12 h-12 rounded bg-white/10 backdrop-blur mb-3">
                <span class="text-2xl font-bold">A</span>
            </div>
            <h1 class="text-2xl font-semibold"><?= e(APP_NAME) ?></h1>
        </div>

        <div class="bg-white rounded-lg shadow-xl p-7">
            <?php if ($invalid): ?>
                <h2 class="text-lg font-semibold text-slate-900 mb-1">Link expired or invalid</h2>
                <p class="text-sm text-slate-500 mb-5">
                    This reset link is no longer valid. Request a new one to continue.
                </p>
                <a href="/auth/forgot_password.php"
                   class="block text-center w-full rounded bg-brand-600 hover:bg-brand-700 text-white font-medium py-2 transition">
                    Request new reset link
                </a>
                <p class="text-xs text-slate-500 text-center mt-4">
                    <a href="/auth/login.php" class="text-brand-600 hover:underline">Back to sign in</a>
                </p>
            <?php else: ?>
                <h2 class="text-lg font-semibold text-slate-900 mb-1">Choose a new password</h2>
                <p class="text-sm text-slate-500 mb-5">
                    Resetting for <strong><?= e($user['email']) ?></strong>.
                </p>

                <?php if ($error): ?>
                    <div class="mb-4 rounded border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-800">
                        <?= e($error) ?>
                    </div>
                <?php endif; ?>

                <form method="post" class="space-y-4" autocomplete="off">
                    <?= csrf_field() ?>
                    <input type="hidden" name="token" value="<?= e($token) ?>">
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1" for="password">
                            New password
                        </label>
                        <input id="password" name="password" type="password" minlength="12" required autofocus
                               class="w-full rounded border border-slate-300 px-3 py-2 text-sm focus:border-brand-500 focus:ring-1 focus:ring-brand-500 outline-none">
                        <span class="text-xs text-slate-500 mt-1 block">Minimum 12 characters.</span>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1" for="password_confirm">
                            Confirm password
                        </label>
                        <input id="password_confirm" name="password_confirm" type="password" minlength="12" required
                               class="w-full rounded border border-slate-300 px-3 py-2 text-sm focus:border-brand-500 focus:ring-1 focus:ring-brand-500 outline-none">
                    </div>
                    <button type="submit"
                            class="w-full rounded bg-brand-600 hover:bg-brand-700 text-white font-medium py-2 transition">
                        Set password &amp; sign in
                    </button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
