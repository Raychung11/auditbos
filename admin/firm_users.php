<?php
/**
 * /admin/firm_users.php?firm_id=<id>
 *
 * Super-admin: manage firm-level users (firm_admin, audit_manager,
 * senior_auditor, junior_auditor, reviewer) for one firm. Invite,
 * resend invitation, revoke, reactivate, change role.
 *
 * Mirrors /firm/client_portal.php in design — invitations reuse the
 * password_reset_token machinery, so invitees set their own password
 * via /auth/accept_invite.php?token=...
 */

declare(strict_types=1);
require_once __DIR__ . '/../includes/auth_guard.php';
require_role(['super_admin']);

$pdo    = db();
$firmId = isset($_GET['firm_id']) ? (int) $_GET['firm_id'] : (int) ($_POST['firm_id'] ?? 0);
if ($firmId <= 0) {
    flash('error', 'Pick a firm first.');
    redirect('/admin/firms.php');
}

$firm = $pdo->prepare('SELECT id, name, email FROM firms WHERE id = :id');
$firm->execute([':id' => $firmId]);
$firm = $firm->fetch();
if (!$firm) {
    flash('error', 'Firm not found.');
    redirect('/admin/firms.php');
}

$firmRoles = ['firm_admin','audit_manager','senior_auditor','junior_auditor','reviewer'];
$inviteLink = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['_action'] ?? '';

    if ($action === 'invite') {
        $name  = trim((string)($_POST['name'] ?? ''));
        $email = trim((string)($_POST['email'] ?? ''));
        $role  = (string)($_POST['role'] ?? '');
        $dept  = trim((string)($_POST['department'] ?? '')) ?: null;

        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash('error', 'Name and a valid email are required.');
            redirect('/admin/firm_users.php?firm_id=' . $firmId);
        }
        if (!in_array($role, $firmRoles, true)) {
            flash('error', 'Invalid role.');
            redirect('/admin/firm_users.php?firm_id=' . $firmId);
        }
        $exists = $pdo->prepare('SELECT id FROM users WHERE email = :e');
        $exists->execute([':e' => $email]);
        if ($exists->fetch()) {
            flash('error', 'That email is already in use.');
            redirect('/admin/firm_users.php?firm_id=' . $firmId);
        }

        $token     = random_token(32);
        $tokenHash = hash('sha256', $token);
        $expiresAt = date('Y-m-d H:i:s', time() + TOKEN_TTL_SECONDS);
        $tempHash  = password_hash(random_token(16), PASSWORD_DEFAULT);

        $pdo->prepare(
            'INSERT INTO users
                (firm_id, role, name, email, phone, password_hash, department, status,
                 password_reset_token, password_reset_expires_at, created_by)
             VALUES (:fid, :r, :n, :e, NULL, :h, :d, "inactive", :tk, :x, :cb)'
        )->execute([
            ':fid'=>$firmId, ':r'=>$role, ':n'=>$name, ':e'=>$email,
            ':h'=>$tempHash, ':d'=>$dept, ':tk'=>$tokenHash, ':x'=>$expiresAt,
            ':cb'=>current_user_id(),
        ]);
        $newUserId = (int) $pdo->lastInsertId();

        $link    = absolute_url('/auth/accept_invite.php?token=' . $token);
        $subject = APP_NAME . " — You're invited to " . $firm['name'];
        $body    = "Hi {$name},\n\n"
            . "You have been invited to join " . $firm['name'] . " on " . APP_NAME . " as "
            . ucwords(str_replace('_',' ',$role)) . ".\n\n"
            . "Set up your account here (link expires in 24 hours):\n\n  {$link}\n\n";
        $mail = send_email($email, $subject, $body);
        if (!$mail['sent']) {
            $inviteLink = $link;
        }
        log_activity('firm_user.invite', 'user', $newUserId, "{$name} <{$email}> as {$role}");
        flash('success', "Invited {$name}." . ($mail['sent'] ? ' Email sent.' : ''));

    } elseif ($action === 'resend') {
        $userId = (int)($_POST['user_id'] ?? 0);
        $check = $pdo->prepare(
            'SELECT id, name, email FROM users
              WHERE id = :id AND firm_id = :fid AND role <> "client_user"'
        );
        $check->execute([':id'=>$userId, ':fid'=>$firmId]);
        $u = $check->fetch();
        if ($u) {
            $token     = random_token(32);
            $tokenHash = hash('sha256', $token);
            $expiresAt = date('Y-m-d H:i:s', time() + TOKEN_TTL_SECONDS);
            $pdo->prepare(
                'UPDATE users SET password_reset_token = :h,
                                  password_reset_expires_at = :x
                  WHERE id = :id'
            )->execute([':h'=>$tokenHash, ':x'=>$expiresAt, ':id'=>$userId]);

            $link = absolute_url('/auth/accept_invite.php?token=' . $token);
            $mail = send_email(
                $u['email'],
                APP_NAME . ' — New invitation / reset link',
                "Hi {$u['name']},\n\nHere is a new link (24-hour expiry):\n\n  {$link}\n\n"
            );
            if (!$mail['sent']) {
                $inviteLink = $link;
            }
            log_activity('firm_user.resend', 'user', $userId);
            flash('success', 'Invitation link rotated.' . ($mail['sent'] ? ' Email sent.' : ''));
        }
    } elseif ($action === 'revoke') {
        $userId = (int)($_POST['user_id'] ?? 0);
        $pdo->prepare(
            'UPDATE users SET status = "suspended",
                              password_reset_token = NULL,
                              password_reset_expires_at = NULL
              WHERE id = :id AND firm_id = :fid AND role <> "client_user"'
        )->execute([':id'=>$userId, ':fid'=>$firmId]);
        log_activity('firm_user.revoke', 'user', $userId);
        flash('success', 'User revoked.');
    } elseif ($action === 'reactivate') {
        $userId = (int)($_POST['user_id'] ?? 0);
        $pdo->prepare(
            'UPDATE users SET status = "active"
              WHERE id = :id AND firm_id = :fid AND role <> "client_user"'
        )->execute([':id'=>$userId, ':fid'=>$firmId]);
        log_activity('firm_user.reactivate', 'user', $userId);
        flash('success', 'User reactivated.');
    } elseif ($action === 'change_role') {
        $userId  = (int)($_POST['user_id'] ?? 0);
        $newRole = (string)($_POST['new_role'] ?? '');
        if (in_array($newRole, $firmRoles, true)) {
            $pdo->prepare(
                'UPDATE users SET role = :r
                  WHERE id = :id AND firm_id = :fid AND role <> "client_user"'
            )->execute([':r'=>$newRole, ':id'=>$userId, ':fid'=>$firmId]);
            log_activity('firm_user.change_role', 'user', $userId, $newRole);
            flash('success', 'Role updated.');
        }
    }

    if ($inviteLink === null) {
        redirect('/admin/firm_users.php?firm_id=' . $firmId);
    }
}

$users = $pdo->prepare(
    'SELECT id, name, email, role, department, status, last_login_at,
            password_reset_expires_at, created_at
       FROM users
      WHERE firm_id = :fid AND role <> "client_user"
      ORDER BY created_at DESC'
);
$users->execute([':fid' => $firmId]);
$rows = $users->fetchAll();

$pageTitle = 'Firm Users · ' . $firm['name'];
require __DIR__ . '/../includes/header.php';
?>

<a href="/admin/firms.php?action=edit&id=<?= (int) $firmId ?>"
   class="text-sm text-brand-600 hover:underline">&larr; Back to firm</a>

<div class="flex items-end justify-between gap-3 mt-1 mb-5">
    <div>
        <h2 class="text-xl font-semibold text-slate-900"><?= e($firm['name']) ?> — Firm Users</h2>
        <p class="text-sm text-slate-500">
            Manage the audit firm's internal team. Use this when no firm admin
            exists yet, or to add/remove team members for the firm.
        </p>
    </div>
</div>

<?php if ($inviteLink !== null): ?>
    <div class="mb-5 rounded border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
        <div class="font-medium mb-1">Mail sending isn't configured</div>
        <div class="text-xs mb-2">
            Share this link with the user (24-hour expiry):
        </div>
        <div class="break-all font-mono text-[11px] bg-white border border-amber-200 rounded p-2">
            <?= e($inviteLink) ?>
        </div>
    </div>
<?php endif; ?>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <div class="lg:col-span-2 bg-white rounded-lg border border-slate-200 overflow-hidden">
        <div class="px-5 py-3 border-b border-slate-200">
            <h3 class="font-semibold text-slate-900">Existing users (<?= count($rows) ?>)</h3>
        </div>
        <?php if (empty($rows)): ?>
            <div class="p-8 text-center text-sm text-slate-500">
                No users yet. Use the form on the right to invite the first firm admin.
            </div>
        <?php else: ?>
            <table class="table-app">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Role</th>
                        <th>Status</th>
                        <th>Last login</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $u):
                    $expiresAt = $u['password_reset_expires_at']
                        ? strtotime($u['password_reset_expires_at']) : null;
                    $pendingInvite = $u['status'] === 'inactive' && $expiresAt && $expiresAt > time();
                ?>
                    <tr>
                        <td>
                            <div class="font-medium"><?= e($u['name']) ?></div>
                            <?php if ($u['department']): ?>
                                <div class="text-xs text-slate-500"><?= e($u['department']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="text-xs"><?= e($u['email']) ?></td>
                        <td>
                            <form method="post" class="flex items-center gap-1">
                                <?= csrf_field() ?>
                                <input type="hidden" name="_action" value="change_role">
                                <input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
                                <select name="new_role" class="text-xs rounded border border-slate-300 px-1.5 py-1">
                                    <?php foreach ($firmRoles as $r): ?>
                                        <option value="<?= $r ?>" <?= $u['role'] === $r ? 'selected' : '' ?>>
                                            <?= ucwords(str_replace('_',' ',$r)) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <button class="text-xs rounded bg-slate-700 text-white px-2 py-1">Set</button>
                            </form>
                        </td>
                        <td>
                            <?= badge($u['status']) ?>
                            <?php if ($pendingInvite): ?>
                                <span class="ml-1 text-xs text-amber-700">pending invite</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-xs"><?= e(datefmt($u['last_login_at'], 'd M Y H:i')) ?></td>
                        <td class="text-right">
                            <div class="flex items-center gap-2 justify-end">
                                <?php if ($u['status'] !== 'active'): ?>
                                    <form method="post" class="inline">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="_action" value="resend">
                                        <input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
                                        <button class="text-xs rounded border border-slate-300 px-2 py-1 hover:bg-slate-50">
                                            Send link
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <form method="post" class="inline">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="_action" value="resend">
                                        <input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
                                        <button class="text-xs rounded border border-slate-300 px-2 py-1 hover:bg-slate-50"
                                                title="Generate a fresh password-reset link">
                                            Reset
                                        </button>
                                    </form>
                                <?php endif; ?>
                                <?php if ($u['status'] === 'active'): ?>
                                    <form method="post" class="inline" onsubmit="return confirm('Revoke this user?');">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="_action" value="revoke">
                                        <input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
                                        <button class="text-xs rounded border border-rose-300 text-rose-700 px-2 py-1 hover:bg-rose-50">
                                            Revoke
                                        </button>
                                    </form>
                                <?php elseif ($u['status'] === 'suspended'): ?>
                                    <form method="post" class="inline">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="_action" value="reactivate">
                                        <input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
                                        <button class="text-xs rounded border border-emerald-300 text-emerald-700 px-2 py-1 hover:bg-emerald-50">
                                            Reactivate
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <div class="bg-white rounded-lg border border-slate-200">
        <div class="px-5 py-3 border-b border-slate-200">
            <h3 class="font-semibold text-slate-900">Invite firm user</h3>
            <p class="text-xs text-slate-500 mt-0.5">
                They'll receive a 24-hour link to set their own password.
            </p>
        </div>
        <form method="post" class="p-5 space-y-3">
            <?= csrf_field() ?>
            <input type="hidden" name="_action" value="invite">
            <label class="block">
                <span class="text-sm font-medium text-slate-700">Name *</span>
                <input name="name" required
                       class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm">
            </label>
            <label class="block">
                <span class="text-sm font-medium text-slate-700">Email *</span>
                <input type="email" name="email" required
                       value="<?= e($firm['email'] ?? '') ?>"
                       class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm">
            </label>
            <label class="block">
                <span class="text-sm font-medium text-slate-700">Role *</span>
                <select name="role" required
                        class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm">
                    <?php foreach ($firmRoles as $r): ?>
                        <option value="<?= $r ?>" <?= $r === 'firm_admin' ? 'selected' : '' ?>>
                            <?= ucwords(str_replace('_',' ',$r)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="block">
                <span class="text-sm font-medium text-slate-700">Department</span>
                <input name="department"
                       class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm">
            </label>
            <button class="w-full rounded bg-brand-600 hover:bg-brand-700 text-white px-3 py-2 text-sm font-medium">
                Send invitation
            </button>
        </form>
    </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
