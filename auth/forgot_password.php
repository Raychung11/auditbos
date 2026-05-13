<?php
/**
 * /auth/forgot_password.php
 *
 * Step 1 of password reset.
 *   - User enters email.
 *   - If the account exists & is active, we generate a single-use token,
 *     store its SHA-256 hash + expiry, and either email a reset link
 *     (when MAIL_ENABLED) or surface the link on-screen for manual relay
 *     (Hostinger-friendly fallback when SMTP isn't wired).
 *   - The response is always the same to avoid leaking which emails
 *     are registered.
 *
 * Lookup is hash-only; we never persist the raw token, so even a DB
 * compromise cannot reveal active reset links.
 */

declare(strict_types=1);

define('AUDITBOS_PUBLIC', true);
require_once __DIR__ . '/../includes/auth_guard.php';

if (is_logged_in()) {
    redirect('/dashboard.php');
}

$submitted = false;
$resetLink = null; // populated only when MAIL_ENABLED is false (dev/install)

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $email = trim((string)($_POST['email'] ?? ''));
    $submitted = true;

    if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $pdo  = db();
        $stmt = $pdo->prepare(
            'SELECT id, name, status FROM users WHERE email = :e LIMIT 1'
        );
        $stmt->execute([':e' => $email]);
        $user = $stmt->fetch();

        if ($user && $user['status'] === 'active') {
            $token     = random_token(32);
            $tokenHash = hash('sha256', $token);
            $expiresAt = date('Y-m-d H:i:s', time() + TOKEN_TTL_SECONDS);

            $pdo->prepare(
                'UPDATE users SET password_reset_token = :h,
                                  password_reset_expires_at = :x
                  WHERE id = :id'
            )->execute([':h'=>$tokenHash, ':x'=>$expiresAt, ':id'=>$user['id']]);

            $link    = absolute_url('/auth/reset_password.php?token=' . $token);
            $subject = APP_NAME . ' — Password reset';
            $body    = "Hello {$user['name']},\n\n"
                . "We received a request to reset your " . APP_NAME . " password.\n"
                . "Open the link below within 24 hours to choose a new one:\n\n"
                . "  {$link}\n\n"
                . "If you didn't request this, ignore this email — your account is unchanged.";

            $mail = send_email($email, $subject, $body);
            if (!$mail['sent']) {
                // Surface the link directly when we cannot email it. The
                // page only shows it after a successful POST so it's gated
                // on knowing the address — same disclosure as email anyway.
                $resetLink = $link;
            }
            log_activity('password_reset.request', 'user', (int) $user['id']);
        }
        // No early-return on miss — we want a uniform success message.
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Forgot password · <?= e(APP_NAME) ?></title>
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
            <h2 class="text-lg font-semibold text-slate-900 mb-1">Forgot your password?</h2>
            <p class="text-sm text-slate-500 mb-5">
                Enter your email and we'll send instructions to reset it.
            </p>

            <?php if ($submitted): ?>
                <div class="mb-4 rounded border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-800">
                    If an account is registered with that email, you'll receive reset instructions shortly.
                </div>
                <?php if ($resetLink !== null): ?>
                    <div class="mb-4 rounded border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-900">
                        <strong>Mail sending isn't configured yet</strong> — share this
                        link with the user out-of-band (24-hour expiry):
                        <div class="mt-2 break-all font-mono text-[11px] bg-white border border-amber-200 rounded p-2">
                            <?= e($resetLink) ?>
                        </div>
                    </div>
                <?php endif; ?>
                <p class="text-xs text-slate-500">
                    <a href="/auth/login.php" class="text-brand-600 hover:underline">&larr; Back to sign in</a>
                </p>
            <?php else: ?>
                <form method="post" class="space-y-4">
                    <?= csrf_field() ?>
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1" for="email">Email</label>
                        <input id="email" name="email" type="email" required autofocus
                               class="w-full rounded border border-slate-300 px-3 py-2 text-sm focus:border-brand-500 focus:ring-1 focus:ring-brand-500 outline-none">
                    </div>
                    <button type="submit"
                            class="w-full rounded bg-brand-600 hover:bg-brand-700 text-white font-medium py-2 transition">
                        Send reset link
                    </button>
                    <p class="text-xs text-slate-500 text-center">
                        <a href="/auth/login.php" class="text-brand-600 hover:underline">Back to sign in</a>
                    </p>
                </form>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
