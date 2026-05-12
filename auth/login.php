<?php
/**
 * /auth/login.php
 *
 * Login form & POST handler.
 * - bcrypt password verification
 * - per-user lockout (LOGIN_MAX_ATTEMPTS / LOGIN_LOCKOUT_SECONDS)
 * - session ID regenerated on successful login
 * - redirects to role-appropriate landing page
 */

declare(strict_types=1);

// This page is reachable without an existing session, so flag it public
// BEFORE including the auth guard.
define('AUDITBOS_PUBLIC', true);
require_once __DIR__ . '/../includes/auth_guard.php';

// Already logged in? Bounce to dashboard.
if (is_logged_in()) {
    redirect('/dashboard.php');
}

$error = null;
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $email    = trim((string)($_POST['email'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    if ($email === '' || $password === '') {
        $error = 'Please enter both email and password.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } else {
        $stmt = db()->prepare(
            'SELECT id, firm_id, client_id, role, name, email, password_hash,
                    status, failed_login_count, locked_until
             FROM users WHERE email = :email LIMIT 1'
        );
        $stmt->execute([':email' => $email]);
        $user = $stmt->fetch();

        $genericError = 'Invalid email or password.';

        if (!$user) {
            // Constant-ish work to dampen user enumeration.
            password_verify($password, '$2y$12$invalidhashinvalidhashinvalidhashinvalidhashinvalidhashinval');
            $error = $genericError;
        } elseif ($user['status'] !== 'active') {
            $error = 'Your account is not active. Contact your firm admin.';
        } elseif ($user['locked_until'] && strtotime($user['locked_until']) > time()) {
            $remaining = (int) ((strtotime($user['locked_until']) - time()) / 60) + 1;
            $error = "Account temporarily locked. Try again in {$remaining} minute(s).";
        } elseif (!password_verify($password, $user['password_hash'])) {
            // Bump failed counter and lock if threshold hit.
            $attempts = (int) $user['failed_login_count'] + 1;
            $lockedUntil = null;
            if ($attempts >= LOGIN_MAX_ATTEMPTS) {
                $lockedUntil = date('Y-m-d H:i:s', time() + LOGIN_LOCKOUT_SECONDS);
                $attempts = 0;
            }
            $upd = db()->prepare(
                'UPDATE users SET failed_login_count = :n, locked_until = :lu WHERE id = :id'
            );
            $upd->execute([
                ':n'  => $attempts,
                ':lu' => $lockedUntil,
                ':id' => $user['id'],
            ]);
            $error = $genericError;
        } else {
            // Success — rehash if needed, reset counters, regenerate session.
            if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
                $newHash = password_hash($password, PASSWORD_DEFAULT);
                db()->prepare('UPDATE users SET password_hash = :h WHERE id = :id')
                    ->execute([':h' => $newHash, ':id' => $user['id']]);
            }
            db()->prepare(
                'UPDATE users
                   SET failed_login_count = 0, locked_until = NULL,
                       last_login_at = NOW(), last_login_ip = :ip
                 WHERE id = :id'
            )->execute([
                ':ip' => $_SERVER['REMOTE_ADDR'] ?? null,
                ':id' => $user['id'],
            ]);

            session_regenerate_id(true);
            $_SESSION['user'] = [
                'id'        => (int) $user['id'],
                'firm_id'   => $user['firm_id'] !== null ? (int) $user['firm_id'] : null,
                'client_id' => $user['client_id'] !== null ? (int) $user['client_id'] : null,
                'role'      => $user['role'],
                'name'      => $user['name'],
                'email'     => $user['email'],
            ];
            $_SESSION['_last_activity'] = time();

            log_activity('user.login', 'user', (int) $user['id'], 'Successful login');

            $intended = $_SESSION['_intended_url'] ?? null;
            unset($_SESSION['_intended_url']);

            // Role-based default landing page if no intended URL set.
            if (!$intended) {
                $intended = match ($user['role']) {
                    'super_admin' => '/admin/firms.php',
                    'client_user' => '/documents/index.php',
                    default       => '/dashboard.php',
                };
            }
            redirect($intended);
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sign in · <?= e(APP_NAME) ?></title>
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
            <p class="text-brand-100/80 text-sm mt-1">AI-powered Business Operating System for Audit Firms</p>
        </div>

        <div class="bg-white rounded-lg shadow-xl p-7">
            <h2 class="text-lg font-semibold text-slate-900 mb-1">Sign in to your account</h2>
            <p class="text-sm text-slate-500 mb-5">Enter your credentials to continue.</p>

            <?php if ($error): ?>
                <div class="mb-4 rounded border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-800">
                    <?= e($error) ?>
                </div>
            <?php endif; ?>

            <form method="post" class="space-y-4" autocomplete="on">
                <?= csrf_field() ?>
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1" for="email">Email</label>
                    <input id="email" name="email" type="email" required autofocus
                           value="<?= e($email) ?>"
                           class="w-full rounded border border-slate-300 px-3 py-2 text-sm focus:border-brand-500 focus:ring-1 focus:ring-brand-500 outline-none">
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1" for="password">Password</label>
                    <input id="password" name="password" type="password" required
                           class="w-full rounded border border-slate-300 px-3 py-2 text-sm focus:border-brand-500 focus:ring-1 focus:ring-brand-500 outline-none">
                </div>
                <button type="submit"
                        class="w-full rounded bg-brand-600 hover:bg-brand-700 text-white font-medium py-2 transition">
                    Sign in
                </button>
            </form>

            <p class="text-xs text-slate-400 text-center mt-5">
                &copy; <?= date('Y') ?> <?= e(APP_NAME) ?> · v<?= e(APP_VERSION) ?>
            </p>
        </div>
    </div>
</body>
</html>
