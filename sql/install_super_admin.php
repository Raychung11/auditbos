<?php
/**
 * /sql/install_super_admin.php
 *
 * One-shot CLI installer to create the initial super_admin user.
 *
 * Usage (from project root):
 *     php sql/install_super_admin.php
 *
 *     # or non-interactive:
 *     SA_EMAIL=you@firm.com SA_PASSWORD=ChangeMe!2026 SA_NAME="Super Admin" \
 *         php sql/install_super_admin.php
 *
 * Re-running with the same email is a no-op; it will refuse to overwrite
 * an existing user unless you pass --reset which only resets the password.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script may only be run from the command line.\n");
}

define('AUDITBOS_BOOTSTRAPPED', true);
require __DIR__ . '/../config/app_config.php';
require __DIR__ . '/../config/db_config.php';

$pdo = db();

// ---------------------------------------------------------------------
// Gather inputs (env first, then prompts).
// ---------------------------------------------------------------------
$email = getenv('SA_EMAIL')    ?: prompt('Super admin email: ');
$name  = getenv('SA_NAME')     ?: prompt('Display name [Super Admin]: ', 'Super Admin');
$pass  = getenv('SA_PASSWORD') ?: prompt_secret('Password (min 12 chars): ');

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Invalid email.\n"); exit(1);
}
if (strlen($pass) < 12) {
    fwrite(STDERR, "Password must be at least 12 characters.\n"); exit(1);
}

$hash = password_hash($pass, PASSWORD_DEFAULT);

// ---------------------------------------------------------------------
// Insert / refuse-to-overwrite logic.
// ---------------------------------------------------------------------
$existing = $pdo->prepare('SELECT id, role FROM users WHERE email = :e');
$existing->execute([':e' => $email]);
$row = $existing->fetch();

$reset = in_array('--reset', $argv ?? [], true);

if ($row && !$reset) {
    fwrite(STDERR, "User '{$email}' already exists. Re-run with --reset to update password only.\n");
    exit(1);
}

if ($row && $reset) {
    $pdo->prepare(
        'UPDATE users SET password_hash = :h, status = "active", failed_login_count = 0,
                          locked_until = NULL
                    WHERE id = :id'
    )->execute([':h'=>$hash, ':id'=>$row['id']]);
    echo "Password reset for existing user '{$email}' (id={$row['id']}).\n";
    exit(0);
}

$pdo->prepare(
    'INSERT INTO users (firm_id, client_id, role, name, email, password_hash, status)
     VALUES (NULL, NULL, "super_admin", :n, :e, :h, "active")'
)->execute([':n'=>$name, ':e'=>$email, ':h'=>$hash]);

echo "Super admin created: {$email}\n";
echo "You can now sign in at /auth/login.php\n";
exit(0);

// ---------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------
function prompt(string $label, string $default = ''): string
{
    fwrite(STDOUT, $label);
    $line = rtrim((string) fgets(STDIN));
    return $line !== '' ? $line : $default;
}

function prompt_secret(string $label): string
{
    fwrite(STDOUT, $label);
    if (DIRECTORY_SEPARATOR === '/') {
        system('stty -echo');
    }
    $line = rtrim((string) fgets(STDIN));
    if (DIRECTORY_SEPARATOR === '/') {
        system('stty echo');
    }
    fwrite(STDOUT, "\n");
    return $line;
}
