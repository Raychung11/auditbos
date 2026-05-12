<?php
/**
 * /firm/staff.php
 *
 * Firm admin: manage staff within their own firm. Strict firm scoping —
 * every query is filtered by current_firm_id().
 */

declare(strict_types=1);
require_once __DIR__ . '/../includes/auth_guard.php';
require_role(['firm_admin']);

$pdo    = db();
$firmId = current_firm_id();
$mode   = $_GET['action'] ?? 'list';
$id     = isset($_GET['id']) ? (int) $_GET['id'] : 0;

$staffRoles = ['firm_admin','audit_manager','senior_auditor','junior_auditor','reviewer'];

// ---------------------------------------------------------------------
// POST: create or update staff
// ---------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $editId   = (int)($_POST['id'] ?? 0);
    $name     = trim((string)($_POST['name'] ?? ''));
    $email    = trim((string)($_POST['email'] ?? ''));
    $phone    = trim((string)($_POST['phone'] ?? '')) ?: null;
    $role     = (string)($_POST['role'] ?? '');
    $dept     = trim((string)($_POST['department'] ?? '')) ?: null;
    $status   = (string)($_POST['status'] ?? 'active');
    $password = (string)($_POST['password'] ?? '');

    if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        flash('error', 'Name and a valid email are required.');
    } elseif (!in_array($role, $staffRoles, true)) {
        flash('error', 'Invalid role.');
    } elseif (!in_array($status, ['active','inactive','suspended'], true)) {
        flash('error', 'Invalid status.');
    } else {
        try {
            if ($editId > 0) {
                // Confirm record belongs to this firm before updating.
                $stmt = $pdo->prepare('SELECT id FROM users WHERE id=:id AND firm_id=:fid');
                $stmt->execute([':id'=>$editId, ':fid'=>$firmId]);
                if (!$stmt->fetch()) {
                    flash('error', 'Staff record not found.');
                    redirect('/firm/staff.php');
                }
                if ($password !== '' && strlen($password) < 8) {
                    flash('error', 'Password must be at least 8 characters.');
                    redirect("/firm/staff.php?action=edit&id={$editId}");
                }
                $sql = 'UPDATE users SET name=:n, email=:e, phone=:p, role=:r, department=:d, status=:s';
                $params = [
                    ':n'=>$name, ':e'=>$email, ':p'=>$phone, ':r'=>$role,
                    ':d'=>$dept, ':s'=>$status,
                ];
                if ($password !== '') {
                    $sql .= ', password_hash=:h';
                    $params[':h'] = password_hash($password, PASSWORD_DEFAULT);
                }
                $sql .= ' WHERE id=:id AND firm_id=:fid';
                $params[':id']  = $editId;
                $params[':fid'] = $firmId;
                $pdo->prepare($sql)->execute($params);
                log_activity('staff.update', 'user', $editId, $name);
                flash('success', 'Staff updated.');
            } else {
                if (strlen($password) < 8) {
                    flash('error', 'Password must be at least 8 characters.');
                    redirect('/firm/staff.php?action=new');
                }
                $pdo->prepare(
                    'INSERT INTO users (firm_id, role, name, email, phone, password_hash,
                                        department, status, created_by)
                     VALUES (:fid, :r, :n, :e, :p, :h, :d, :s, :cb)'
                )->execute([
                    ':fid'=>$firmId, ':r'=>$role, ':n'=>$name, ':e'=>$email, ':p'=>$phone,
                    ':h'=>password_hash($password, PASSWORD_DEFAULT),
                    ':d'=>$dept, ':s'=>$status, ':cb'=>current_user_id(),
                ]);
                $newId = (int) $pdo->lastInsertId();
                log_activity('staff.create', 'user', $newId, $name);
                flash('success', 'Staff added.');
            }
        } catch (Throwable $e) {
            error_log('[AuditBOS] staff save failed: ' . $e->getMessage());
            flash('error', 'Could not save — email may already be in use.');
        }
        redirect('/firm/staff.php');
    }
}

// ---------------------------------------------------------------------
// Load record for edit / list staff
// ---------------------------------------------------------------------
$record = null;
if ($mode === 'edit' && $id > 0) {
    $stmt = $pdo->prepare('SELECT * FROM users WHERE id=:id AND firm_id=:fid');
    $stmt->execute([':id'=>$id, ':fid'=>$firmId]);
    $record = $stmt->fetch();
    if (!$record) {
        flash('error', 'Staff record not found.');
        redirect('/firm/staff.php');
    }
}

$staff = [];
if ($mode === 'list') {
    $stmt = $pdo->prepare(
        "SELECT id, name, email, phone, role, department, status, last_login_at, created_at
           FROM users
          WHERE firm_id = :fid AND role <> 'client_user'
          ORDER BY name ASC"
    );
    $stmt->execute([':fid' => $firmId]);
    $staff = $stmt->fetchAll();
}

$pageTitle = 'Staff';
require __DIR__ . '/../includes/header.php';
?>

<?php if ($mode === 'new' || $mode === 'edit'): ?>
    <div class="max-w-3xl">
        <a href="/firm/staff.php" class="text-sm text-brand-600 hover:underline">&larr; Back to staff</a>
        <h2 class="text-xl font-semibold text-slate-900 mt-1 mb-4">
            <?= $mode === 'edit' ? 'Edit Staff Member' : 'New Staff Member' ?>
        </h2>

        <form method="post" class="bg-white rounded-lg border border-slate-200 p-6 space-y-4">
            <?= csrf_field() ?>
            <?php if ($mode === 'edit'): ?>
                <input type="hidden" name="id" value="<?= (int) $record['id'] ?>">
            <?php endif; ?>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <label class="block">
                    <span class="text-sm font-medium text-slate-700">Name *</span>
                    <input name="name" required value="<?= e($record['name'] ?? '') ?>"
                           class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm">
                </label>
                <label class="block">
                    <span class="text-sm font-medium text-slate-700">Email *</span>
                    <input type="email" name="email" required value="<?= e($record['email'] ?? '') ?>"
                           class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm">
                </label>
                <label class="block">
                    <span class="text-sm font-medium text-slate-700">Phone</span>
                    <input name="phone" value="<?= e($record['phone'] ?? '') ?>"
                           class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm">
                </label>
                <label class="block">
                    <span class="text-sm font-medium text-slate-700">Department</span>
                    <input name="department" value="<?= e($record['department'] ?? '') ?>"
                           class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm">
                </label>
                <label class="block">
                    <span class="text-sm font-medium text-slate-700">Role *</span>
                    <select name="role" required
                            class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm">
                        <?php foreach ($staffRoles as $r): ?>
                            <option value="<?= $r ?>"
                                <?= ($record['role'] ?? '') === $r ? 'selected' : '' ?>>
                                <?= ucwords(str_replace('_', ' ', $r)) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="block">
                    <span class="text-sm font-medium text-slate-700">Status</span>
                    <select name="status"
                            class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm">
                        <?php foreach (['active','inactive','suspended'] as $s): ?>
                            <option value="<?= $s ?>" <?= ($record['status'] ?? 'active') === $s ? 'selected' : '' ?>>
                                <?= ucfirst($s) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="block md:col-span-2">
                    <span class="text-sm font-medium text-slate-700">
                        Password <?= $mode === 'edit' ? '(leave blank to keep current)' : '*' ?>
                    </span>
                    <input type="password" name="password"
                           <?= $mode === 'edit' ? '' : 'required minlength="8"' ?>
                           class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm">
                    <span class="text-xs text-slate-500">Minimum 8 characters.</span>
                </label>
            </div>

            <div class="flex gap-3 pt-2">
                <button type="submit"
                        class="rounded bg-brand-600 hover:bg-brand-700 text-white px-4 py-2 text-sm font-medium">
                    <?= $mode === 'edit' ? 'Save changes' : 'Add staff' ?>
                </button>
                <a href="/firm/staff.php"
                   class="rounded border border-slate-300 px-4 py-2 text-sm hover:bg-slate-50">Cancel</a>
            </div>
        </form>
    </div>

<?php else: ?>
    <div class="flex items-center justify-between mb-4">
        <p class="text-sm text-slate-600">Audit team members within your firm.</p>
        <a href="/firm/staff.php?action=new"
           class="rounded bg-brand-600 hover:bg-brand-700 text-white px-4 py-2 text-sm font-medium">
            + Add Staff
        </a>
    </div>

    <div class="bg-white rounded-lg border border-slate-200 overflow-hidden">
        <?php if (empty($staff)): ?>
            <div class="p-8 text-center text-sm text-slate-500">No staff yet.</div>
        <?php else: ?>
            <table class="table-app">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Role</th>
                        <th>Department</th>
                        <th>Last login</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($staff as $s): ?>
                        <tr>
                            <td class="font-medium"><?= e($s['name']) ?></td>
                            <td><?= e($s['email']) ?></td>
                            <td><?= e(ucwords(str_replace('_', ' ', $s['role']))) ?></td>
                            <td><?= e($s['department'] ?? '-') ?></td>
                            <td><?= e(datefmt($s['last_login_at'], 'd M Y H:i')) ?></td>
                            <td><?= badge($s['status']) ?></td>
                            <td class="text-right">
                                <a href="/firm/staff.php?action=edit&id=<?= (int) $s['id'] ?>"
                                   class="text-sm text-brand-600 hover:underline">Edit</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
