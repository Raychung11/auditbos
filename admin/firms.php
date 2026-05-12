<?php
/**
 * /admin/firms.php
 *
 * Super-admin: list, create, edit firms + initial firm-admin user.
 * Each firm gets its own credit_wallet row on creation.
 */

declare(strict_types=1);
require_once __DIR__ . '/../includes/auth_guard.php';
require_role(['super_admin']);

$pdo  = db();
$mode = $_GET['action'] ?? 'list';
$id   = isset($_GET['id']) ? (int) $_GET['id'] : 0;

// ---------------------------------------------------------------------
// Save handler (create + edit share the form)
// ---------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $name    = trim((string)($_POST['name'] ?? ''));
    $regNo   = trim((string)($_POST['registration_no'] ?? '')) ?: null;
    $address = trim((string)($_POST['address'] ?? '')) ?: null;
    $contact = trim((string)($_POST['contact_person'] ?? '')) ?: null;
    $email   = trim((string)($_POST['email'] ?? '')) ?: null;
    $phone   = trim((string)($_POST['phone'] ?? '')) ?: null;
    $sub     = $_POST['subscription_status'] ?? 'trial';
    $status  = $_POST['status'] ?? 'active';
    $editId  = (int)($_POST['id'] ?? 0);

    $allowedSub = ['trial','active','suspended','cancelled'];
    $allowedSt  = ['active','inactive'];

    if ($name === '') {
        flash('error', 'Firm name is required.');
    } elseif (!in_array($sub, $allowedSub, true) || !in_array($status, $allowedSt, true)) {
        flash('error', 'Invalid status value.');
    } else {
        if ($editId > 0) {
            $stmt = $pdo->prepare(
                'UPDATE firms SET name=:n, registration_no=:r, address=:a, contact_person=:cp,
                                  email=:e, phone=:p, subscription_status=:ss, status=:st
                 WHERE id=:id'
            );
            $stmt->execute([
                ':n'=>$name, ':r'=>$regNo, ':a'=>$address, ':cp'=>$contact,
                ':e'=>$email, ':p'=>$phone, ':ss'=>$sub, ':st'=>$status, ':id'=>$editId,
            ]);
            log_activity('firm.update', 'firm', $editId, $name);
            flash('success', 'Firm updated.');
        } else {
            // New firm + optional first admin user (in one txn).
            $adminName  = trim((string)($_POST['admin_name'] ?? ''));
            $adminEmail = trim((string)($_POST['admin_email'] ?? ''));
            $adminPass  = (string)($_POST['admin_password'] ?? '');

            try {
                $pdo->beginTransaction();
                $stmt = $pdo->prepare(
                    'INSERT INTO firms (name, registration_no, address, contact_person, email,
                                        phone, subscription_status, status)
                     VALUES (:n,:r,:a,:cp,:e,:p,:ss,:st)'
                );
                $stmt->execute([
                    ':n'=>$name, ':r'=>$regNo, ':a'=>$address, ':cp'=>$contact,
                    ':e'=>$email, ':p'=>$phone, ':ss'=>$sub, ':st'=>$status,
                ]);
                $newFirmId = (int) $pdo->lastInsertId();

                // Auto-create wallet row.
                $pdo->prepare('INSERT INTO credit_wallet (firm_id) VALUES (:fid)')
                    ->execute([':fid' => $newFirmId]);

                // Optional first admin user.
                if ($adminName !== '' && filter_var($adminEmail, FILTER_VALIDATE_EMAIL) && strlen($adminPass) >= 8) {
                    $pdo->prepare(
                        'INSERT INTO users (firm_id, role, name, email, password_hash, status, created_by)
                         VALUES (:fid, "firm_admin", :n, :e, :h, "active", :cb)'
                    )->execute([
                        ':fid' => $newFirmId,
                        ':n'   => $adminName,
                        ':e'   => $adminEmail,
                        ':h'   => password_hash($adminPass, PASSWORD_DEFAULT),
                        ':cb'  => current_user_id(),
                    ]);
                }
                $pdo->commit();
                log_activity('firm.create', 'firm', $newFirmId, $name);
                flash('success', "Firm '{$name}' created.");
            } catch (Throwable $e) {
                $pdo->rollBack();
                error_log('[AuditBOS] firm create failed: ' . $e->getMessage());
                flash('error', 'Could not create firm — email may already exist.');
            }
        }
        redirect('/admin/firms.php');
    }
}

// ---------------------------------------------------------------------
// Load record for edit mode.
// ---------------------------------------------------------------------
$record = null;
if ($mode === 'edit' && $id > 0) {
    $stmt = $pdo->prepare('SELECT * FROM firms WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $record = $stmt->fetch();
    if (!$record) {
        flash('error', 'Firm not found.');
        redirect('/admin/firms.php');
    }
}

$firms = $pdo->query(
    'SELECT f.*,
            (SELECT COUNT(*) FROM users u WHERE u.firm_id = f.id) AS user_count,
            (SELECT COUNT(*) FROM clients c WHERE c.firm_id = f.id) AS client_count
       FROM firms f
       ORDER BY f.created_at DESC'
)->fetchAll();

$pageTitle = 'Firms';
require __DIR__ . '/../includes/header.php';
?>

<?php if ($mode === 'new' || $mode === 'edit'): ?>
    <div class="max-w-3xl">
        <a href="/admin/firms.php" class="text-sm text-brand-600 hover:underline">&larr; Back to firms</a>
        <h2 class="text-xl font-semibold text-slate-900 mt-1 mb-4">
            <?= $mode === 'edit' ? 'Edit Firm' : 'New Firm' ?>
        </h2>

        <form method="post" class="bg-white rounded-lg border border-slate-200 p-6 space-y-4">
            <?= csrf_field() ?>
            <?php if ($mode === 'edit'): ?>
                <input type="hidden" name="id" value="<?= (int) $record['id'] ?>">
            <?php endif; ?>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <label class="block">
                    <span class="text-sm font-medium text-slate-700">Firm name *</span>
                    <input name="name" required
                           value="<?= e($record['name'] ?? '') ?>"
                           class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm">
                </label>
                <label class="block">
                    <span class="text-sm font-medium text-slate-700">Registration No.</span>
                    <input name="registration_no"
                           value="<?= e($record['registration_no'] ?? '') ?>"
                           class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm">
                </label>
                <label class="block md:col-span-2">
                    <span class="text-sm font-medium text-slate-700">Address</span>
                    <textarea name="address" rows="2"
                              class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"><?= e($record['address'] ?? '') ?></textarea>
                </label>
                <label class="block">
                    <span class="text-sm font-medium text-slate-700">Contact person</span>
                    <input name="contact_person"
                           value="<?= e($record['contact_person'] ?? '') ?>"
                           class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm">
                </label>
                <label class="block">
                    <span class="text-sm font-medium text-slate-700">Email</span>
                    <input type="email" name="email"
                           value="<?= e($record['email'] ?? '') ?>"
                           class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm">
                </label>
                <label class="block">
                    <span class="text-sm font-medium text-slate-700">Phone</span>
                    <input name="phone"
                           value="<?= e($record['phone'] ?? '') ?>"
                           class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm">
                </label>
                <label class="block">
                    <span class="text-sm font-medium text-slate-700">Subscription</span>
                    <select name="subscription_status"
                            class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm">
                        <?php foreach (['trial','active','suspended','cancelled'] as $opt): ?>
                            <option value="<?= $opt ?>" <?= ($record['subscription_status'] ?? 'trial') === $opt ? 'selected' : '' ?>>
                                <?= ucfirst($opt) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="block">
                    <span class="text-sm font-medium text-slate-700">Status</span>
                    <select name="status"
                            class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm">
                        <?php foreach (['active','inactive'] as $opt): ?>
                            <option value="<?= $opt ?>" <?= ($record['status'] ?? 'active') === $opt ? 'selected' : '' ?>>
                                <?= ucfirst($opt) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </div>

            <?php if ($mode === 'new'): ?>
                <div class="border-t border-slate-200 pt-4">
                    <h3 class="text-sm font-semibold text-slate-900 mb-3">
                        First Firm Admin (optional)
                    </h3>
                    <p class="text-xs text-slate-500 mb-3">
                        If provided, a firm-admin user will be created with this login.
                        Password must be at least 8 characters.
                    </p>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <label class="block">
                            <span class="text-sm font-medium text-slate-700">Admin name</span>
                            <input name="admin_name"
                                   class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm">
                        </label>
                        <label class="block">
                            <span class="text-sm font-medium text-slate-700">Admin email</span>
                            <input type="email" name="admin_email"
                                   class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm">
                        </label>
                        <label class="block">
                            <span class="text-sm font-medium text-slate-700">Password</span>
                            <input type="password" name="admin_password" minlength="8"
                                   class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm">
                        </label>
                    </div>
                </div>
            <?php endif; ?>

            <div class="flex gap-3 pt-2">
                <button type="submit"
                        class="rounded bg-brand-600 hover:bg-brand-700 text-white px-4 py-2 text-sm font-medium">
                    <?= $mode === 'edit' ? 'Save changes' : 'Create firm' ?>
                </button>
                <a href="/admin/firms.php"
                   class="rounded border border-slate-300 px-4 py-2 text-sm hover:bg-slate-50">Cancel</a>
            </div>
        </form>
    </div>

<?php else: ?>
    <div class="flex items-center justify-between mb-4">
        <p class="text-sm text-slate-600">Manage all audit firms on the platform.</p>
        <a href="/admin/firms.php?action=new"
           class="rounded bg-brand-600 hover:bg-brand-700 text-white px-4 py-2 text-sm font-medium">
            + New Firm
        </a>
    </div>

    <div class="bg-white rounded-lg border border-slate-200 overflow-hidden">
        <?php if (empty($firms)): ?>
            <div class="p-8 text-center text-sm text-slate-500">No firms yet.</div>
        <?php else: ?>
            <table class="table-app">
                <thead>
                    <tr>
                        <th>Firm</th>
                        <th>Subscription</th>
                        <th>Users</th>
                        <th>Clients</th>
                        <th>Credits</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($firms as $f): ?>
                        <tr>
                            <td>
                                <div class="font-medium"><?= e($f['name']) ?></div>
                                <div class="text-xs text-slate-500"><?= e($f['email'] ?? '') ?></div>
                            </td>
                            <td><?= badge($f['subscription_status']) ?></td>
                            <td><?= (int) $f['user_count'] ?></td>
                            <td><?= (int) $f['client_count'] ?></td>
                            <td><?= e(money((float) $f['credit_balance'])) ?></td>
                            <td><?= badge($f['status']) ?></td>
                            <td class="text-right">
                                <a href="/admin/firms.php?action=edit&id=<?= (int) $f['id'] ?>"
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
