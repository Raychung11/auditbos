<?php
/**
 * /firm/client_portal.php?id=<client_id>
 *
 * Manage portal users (role=client_user) for one client. Firm admin /
 * audit manager can:
 *   - Invite a new portal user (creates an inactive user + invitation
 *     token; user activates on first password-set via the same flow
 *     used by /auth/reset_password.php)
 *   - Resend an invitation (rotates the token)
 *   - Revoke a portal user (suspends them; they can be re-invited later)
 *
 * Invitations and password resets share the same token storage —
 * `users.password_reset_token` (sha256) and `users.password_reset_expires_at`.
 */

declare(strict_types=1);
require_once __DIR__ . '/../includes/auth_guard.php';
require_role(['firm_admin','audit_manager']);

$pdo    = db();
$firmId = current_firm_id();
$id     = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($id <= 0) {
    flash('error', 'Invalid client.');
    redirect('/firm/clients.php');
}

// Scope-check
$stmt = $pdo->prepare(
    'SELECT id, company_name, email, contact_person
       FROM clients WHERE id = :id AND firm_id = :fid'
);
$stmt->execute([':id'=>$id, ':fid'=>$firmId]);
$client = $stmt->fetch();
if (!$client) {
    flash('error', 'Client not found.');
    redirect('/firm/clients.php');
}

$inviteLink = null; // surfaces in UI when MAIL_ENABLED is false

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['_action'] ?? '';

    if ($action === 'invite') {
        $name  = trim((string)($_POST['name']  ?? ''));
        $email = trim((string)($_POST['email'] ?? ''));

        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash('error', 'Name and a valid email are required.');
            redirect('/firm/client_portal.php?id=' . $id);
        }
        // Email must be free across the platform.
        $exists = $pdo->prepare('SELECT id FROM users WHERE email = :e');
        $exists->execute([':e' => $email]);
        if ($exists->fetch()) {
            flash('error', 'That email is already in use.');
            redirect('/firm/client_portal.php?id=' . $id);
        }

        $token     = random_token(32);
        $tokenHash = hash('sha256', $token);
        $expiresAt = date('Y-m-d H:i:s', time() + TOKEN_TTL_SECONDS);

        // Random placeholder hash — replaced when invitee sets a password.
        $tempHash = password_hash(random_token(16), PASSWORD_DEFAULT);

        $pdo->prepare(
            'INSERT INTO users
                (firm_id, client_id, role, name, email, password_hash, status,
                 password_reset_token, password_reset_expires_at, created_by)
             VALUES (NULL, :cid, "client_user", :n, :e, :h, "inactive", :tk, :x, :cb)'
        )->execute([
            ':cid' => $id,
            ':n'   => $name,
            ':e'   => $email,
            ':h'   => $tempHash,
            ':tk'  => $tokenHash,
            ':x'   => $expiresAt,
            ':cb'  => current_user_id(),
        ]);
        $newUserId = (int) $pdo->lastInsertId();

        $link    = absolute_url('/auth/accept_invite.php?token=' . $token);
        $subject = APP_NAME . ' — You\'re invited to ' . $client['company_name'] . '\'s audit portal';
        $body    = "Hi {$name},\n\n"
            . "Your auditor has invited you to use the " . APP_NAME . " portal to share documents and track engagement progress for " . $client['company_name'] . ".\n\n"
            . "Set up your account here (link expires in 24 hours):\n\n"
            . "  {$link}\n\n"
            . "If you weren't expecting this, you can ignore the email.";
        $mail = send_email($email, $subject, $body);
        if (!$mail['sent']) {
            $inviteLink = $link;
        }

        log_activity('client_user.invite', 'user', $newUserId, "{$name} <{$email}>");
        flash('success', "Invited {$name}." . ($mail['sent'] ? ' Email sent.' : ''));

    } elseif ($action === 'resend') {
        $userId = (int)($_POST['user_id'] ?? 0);
        $check = $pdo->prepare(
            'SELECT id, name, email FROM users WHERE id = :id AND client_id = :cid AND role = "client_user"'
        );
        $check->execute([':id'=>$userId, ':cid'=>$id]);
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
                APP_NAME . ' — New invitation link',
                "Hi {$u['name']},\n\nHere is a new invitation link (24-hour expiry):\n\n  {$link}\n\n"
            );
            if (!$mail['sent']) {
                $inviteLink = $link;
            }
            log_activity('client_user.resend', 'user', $userId);
            flash('success', 'Invitation link rotated.' . ($mail['sent'] ? ' Email sent.' : ''));
        }
    } elseif ($action === 'revoke') {
        $userId = (int)($_POST['user_id'] ?? 0);
        $pdo->prepare(
            'UPDATE users SET status = "suspended",
                              password_reset_token = NULL,
                              password_reset_expires_at = NULL
              WHERE id = :id AND client_id = :cid AND role = "client_user"'
        )->execute([':id'=>$userId, ':cid'=>$id]);
        log_activity('client_user.revoke', 'user', $userId);
        flash('success', 'Portal user revoked.');
    } elseif ($action === 'reactivate') {
        $userId = (int)($_POST['user_id'] ?? 0);
        $pdo->prepare(
            'UPDATE users SET status = "active"
              WHERE id = :id AND client_id = :cid AND role = "client_user"'
        )->execute([':id'=>$userId, ':cid'=>$id]);
        log_activity('client_user.reactivate', 'user', $userId);
        flash('success', 'Portal user reactivated.');
    }

    if ($inviteLink === null) {
        redirect('/firm/client_portal.php?id=' . $id);
    }
}

$portalUsers = $pdo->prepare(
    'SELECT id, name, email, status, last_login_at, created_at,
            password_reset_expires_at
       FROM users
      WHERE client_id = :cid AND role = "client_user"
      ORDER BY created_at DESC'
);
$portalUsers->execute([':cid' => $id]);
$users = $portalUsers->fetchAll();

$pageTitle = 'Portal Users · ' . $client['company_name'];
require __DIR__ . '/../includes/header.php';
?>

<a href="/firm/clients.php?action=edit&id=<?= (int) $id ?>"
   class="text-sm text-brand-600 hover:underline">&larr; Back to client</a>

<div class="flex items-center justify-between mt-1 mb-5">
    <div>
        <h2 class="text-xl font-semibold text-slate-900"><?= e($client['company_name']) ?> — Portal Users</h2>
        <p class="text-sm text-slate-500">
            Client users who can sign in to upload documents and track engagements.
        </p>
    </div>
</div>

<?php if ($inviteLink !== null): ?>
    <div class="mb-5 rounded border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
        <div class="font-medium mb-1">Mail sending isn't configured</div>
        <div class="text-xs mb-2">
            Share this invitation link with the user out-of-band. It expires in 24 hours.
        </div>
        <div class="break-all font-mono text-[11px] bg-white border border-amber-200 rounded p-2">
            <?= e($inviteLink) ?>
        </div>
    </div>
<?php endif; ?>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <!-- Portal users table -->
    <div class="lg:col-span-2 bg-white rounded-lg border border-slate-200 overflow-hidden">
        <div class="px-5 py-3 border-b border-slate-200">
            <h3 class="font-semibold text-slate-900">Existing portal users</h3>
        </div>
        <?php if (empty($users)): ?>
            <div class="p-8 text-center text-sm text-slate-500">No portal users yet.</div>
        <?php else: ?>
            <table class="table-app">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Status</th>
                        <th>Last login</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($users as $u):
                    $expiresAt = $u['password_reset_expires_at']
                        ? strtotime($u['password_reset_expires_at']) : null;
                    $pendingInvite = $u['status'] === 'inactive' && $expiresAt && $expiresAt > time();
                ?>
                    <tr>
                        <td class="font-medium"><?= e($u['name']) ?></td>
                        <td class="text-xs"><?= e($u['email']) ?></td>
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
                                            Resend invite
                                        </button>
                                    </form>
                                <?php endif; ?>
                                <?php if ($u['status'] === 'active'): ?>
                                    <form method="post" class="inline" onsubmit="return confirm('Revoke this portal user?');">
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

    <!-- Invite form -->
    <div class="bg-white rounded-lg border border-slate-200">
        <div class="px-5 py-3 border-b border-slate-200">
            <h3 class="font-semibold text-slate-900">Invite a portal user</h3>
            <p class="text-xs text-slate-500 mt-0.5">
                They'll receive a 24-hour invitation link to set their own password.
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
                       value="<?= e($client['email'] ?? '') ?>"
                       class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm">
            </label>
            <button class="w-full rounded bg-brand-600 hover:bg-brand-700 text-white px-3 py-2 text-sm font-medium">
                Send invitation
            </button>
        </form>
    </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
