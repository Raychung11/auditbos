<?php
/**
 * /firm/clients.php
 *
 * Client (auditee) management within a firm.
 */

declare(strict_types=1);
require_once __DIR__ . '/../includes/auth_guard.php';
require_role(['firm_admin','audit_manager','senior_auditor','junior_auditor','reviewer']);

$pdo    = db();
$firmId = current_firm_id();
$mode   = $_GET['action'] ?? 'list';
$id     = isset($_GET['id']) ? (int) $_GET['id'] : 0;

// Only firm_admin / manager may create or edit.
$canEdit = role_allows(['firm_admin','audit_manager']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (!$canEdit) {
        http_response_code(403); exit('Forbidden');
    }

    $editId    = (int)($_POST['id'] ?? 0);
    $name      = trim((string)($_POST['company_name'] ?? ''));
    $regNo     = trim((string)($_POST['registration_no'] ?? '')) ?: null;
    $bizType   = trim((string)($_POST['business_type'] ?? '')) ?: null;
    $industry  = trim((string)($_POST['industry'] ?? '')) ?: null;
    $fye       = trim((string)($_POST['financial_year_end'] ?? '')) ?: null;
    $directors = trim((string)($_POST['directors'] ?? '')) ?: null;
    $shares    = trim((string)($_POST['shareholders'] ?? '')) ?: null;
    $contact   = trim((string)($_POST['contact_person'] ?? '')) ?: null;
    $email     = trim((string)($_POST['email'] ?? '')) ?: null;
    $phone     = trim((string)($_POST['phone'] ?? '')) ?: null;
    $address   = trim((string)($_POST['address'] ?? '')) ?: null;
    $audStatus = (string)($_POST['audit_status'] ?? 'active');
    $mgrId     = (int)($_POST['assigned_manager_id'] ?? 0) ?: null;
    $status    = (string)($_POST['status'] ?? 'active');

    if ($name === '') {
        flash('error', 'Company name is required.');
    } elseif (!in_array($audStatus, ['prospect','onboarding','active','dormant','closed'], true)) {
        flash('error', 'Invalid audit status.');
    } else {
        try {
            if ($editId > 0) {
                $check = $pdo->prepare('SELECT id FROM clients WHERE id=:id AND firm_id=:fid');
                $check->execute([':id'=>$editId, ':fid'=>$firmId]);
                if (!$check->fetch()) {
                    flash('error', 'Client not found.');
                    redirect('/firm/clients.php');
                }
                $pdo->prepare(
                    'UPDATE clients SET company_name=:n, registration_no=:r, business_type=:bt,
                        industry=:i, financial_year_end=:f, directors=:d, shareholders=:sh,
                        contact_person=:cp, email=:e, phone=:p, address=:a, audit_status=:as,
                        assigned_manager_id=:m, status=:s
                     WHERE id=:id AND firm_id=:fid'
                )->execute([
                    ':n'=>$name, ':r'=>$regNo, ':bt'=>$bizType, ':i'=>$industry, ':f'=>$fye,
                    ':d'=>$directors, ':sh'=>$shares, ':cp'=>$contact, ':e'=>$email,
                    ':p'=>$phone, ':a'=>$address, ':as'=>$audStatus, ':m'=>$mgrId,
                    ':s'=>$status, ':id'=>$editId, ':fid'=>$firmId,
                ]);
                log_activity('client.update', 'client', $editId, $name);
                flash('success', 'Client updated.');
            } else {
                $pdo->prepare(
                    'INSERT INTO clients (firm_id, company_name, registration_no, business_type, industry,
                        financial_year_end, directors, shareholders, contact_person, email, phone, address,
                        audit_status, assigned_manager_id, status, created_by)
                     VALUES (:fid, :n, :r, :bt, :i, :f, :d, :sh, :cp, :e, :p, :a, :as, :m, :s, :cb)'
                )->execute([
                    ':fid'=>$firmId, ':n'=>$name, ':r'=>$regNo, ':bt'=>$bizType, ':i'=>$industry,
                    ':f'=>$fye, ':d'=>$directors, ':sh'=>$shares, ':cp'=>$contact,
                    ':e'=>$email, ':p'=>$phone, ':a'=>$address, ':as'=>$audStatus,
                    ':m'=>$mgrId, ':s'=>$status, ':cb'=>current_user_id(),
                ]);
                $newId = (int) $pdo->lastInsertId();
                log_activity('client.create', 'client', $newId, $name);
                flash('success', "Client '{$name}' added.");
            }
        } catch (Throwable $e) {
            error_log('[AuditBOS] client save: ' . $e->getMessage());
            flash('error', 'Could not save client.');
        }
        redirect('/firm/clients.php');
    }
}

$record = null;
if ($mode === 'edit' && $id > 0) {
    $stmt = $pdo->prepare('SELECT * FROM clients WHERE id=:id AND firm_id=:fid');
    $stmt->execute([':id'=>$id, ':fid'=>$firmId]);
    $record = $stmt->fetch();
    if (!$record) { flash('error', 'Client not found.'); redirect('/firm/clients.php'); }
}

// For the assigned-manager dropdown
$managers = [];
if (in_array($mode, ['new', 'edit'], true)) {
    $stmt = $pdo->prepare(
        "SELECT id, name FROM users
          WHERE firm_id=:fid AND role IN ('firm_admin','audit_manager') AND status='active'
          ORDER BY name"
    );
    $stmt->execute([':fid' => $firmId]);
    $managers = $stmt->fetchAll();
}

$clients = [];
if ($mode === 'list') {
    $stmt = $pdo->prepare(
        'SELECT c.*, u.name AS manager_name,
                (SELECT COUNT(*) FROM engagements e
                  WHERE e.client_id = c.id
                    AND e.status NOT IN ("completed","billed","archived")) AS active_eng
           FROM clients c
           LEFT JOIN users u ON u.id = c.assigned_manager_id
          WHERE c.firm_id = :fid
          ORDER BY c.company_name ASC'
    );
    $stmt->execute([':fid' => $firmId]);
    $clients = $stmt->fetchAll();
}

$pageTitle = 'Clients';
require __DIR__ . '/../includes/header.php';
?>

<?php if ($mode === 'new' || $mode === 'edit'): ?>
    <div class="max-w-4xl">
        <a href="/firm/clients.php" class="text-sm text-brand-600 hover:underline">&larr; Back to clients</a>
        <h2 class="text-xl font-semibold text-slate-900 mt-1 mb-4">
            <?= $mode === 'edit' ? 'Edit Client' : 'New Client' ?>
        </h2>

        <form method="post" class="bg-white rounded-lg border border-slate-200 p-6 space-y-4">
            <?= csrf_field() ?>
            <?php if ($mode === 'edit'): ?>
                <input type="hidden" name="id" value="<?= (int) $record['id'] ?>">
            <?php endif; ?>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <label class="block md:col-span-2">
                    <span class="text-sm font-medium text-slate-700">Company name *</span>
                    <input name="company_name" required value="<?= e($record['company_name'] ?? '') ?>"
                           class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm">
                </label>
                <label class="block">
                    <span class="text-sm font-medium text-slate-700">Registration No.</span>
                    <input name="registration_no" value="<?= e($record['registration_no'] ?? '') ?>"
                           class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm">
                </label>
                <label class="block">
                    <span class="text-sm font-medium text-slate-700">Business type</span>
                    <input name="business_type" value="<?= e($record['business_type'] ?? '') ?>"
                           class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm">
                </label>
                <label class="block">
                    <span class="text-sm font-medium text-slate-700">Industry</span>
                    <input name="industry" value="<?= e($record['industry'] ?? '') ?>"
                           class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm">
                </label>
                <label class="block">
                    <span class="text-sm font-medium text-slate-700">Financial year end (MM-DD)</span>
                    <input name="financial_year_end" placeholder="12-31"
                           pattern="[0-1][0-9]-[0-3][0-9]"
                           value="<?= e($record['financial_year_end'] ?? '') ?>"
                           class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm">
                </label>
                <label class="block">
                    <span class="text-sm font-medium text-slate-700">Contact person</span>
                    <input name="contact_person" value="<?= e($record['contact_person'] ?? '') ?>"
                           class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm">
                </label>
                <label class="block">
                    <span class="text-sm font-medium text-slate-700">Email</span>
                    <input type="email" name="email" value="<?= e($record['email'] ?? '') ?>"
                           class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm">
                </label>
                <label class="block">
                    <span class="text-sm font-medium text-slate-700">Phone</span>
                    <input name="phone" value="<?= e($record['phone'] ?? '') ?>"
                           class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm">
                </label>
                <label class="block md:col-span-2">
                    <span class="text-sm font-medium text-slate-700">Address</span>
                    <textarea name="address" rows="2"
                              class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"><?= e($record['address'] ?? '') ?></textarea>
                </label>
                <label class="block">
                    <span class="text-sm font-medium text-slate-700">Directors</span>
                    <textarea name="directors" rows="2"
                              class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"><?= e($record['directors'] ?? '') ?></textarea>
                </label>
                <label class="block">
                    <span class="text-sm font-medium text-slate-700">Shareholders</span>
                    <textarea name="shareholders" rows="2"
                              class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"><?= e($record['shareholders'] ?? '') ?></textarea>
                </label>
                <label class="block">
                    <span class="text-sm font-medium text-slate-700">Audit status</span>
                    <select name="audit_status" class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm">
                        <?php foreach (['prospect','onboarding','active','dormant','closed'] as $opt): ?>
                            <option value="<?= $opt ?>" <?= ($record['audit_status'] ?? 'active') === $opt ? 'selected' : '' ?>>
                                <?= ucfirst($opt) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="block">
                    <span class="text-sm font-medium text-slate-700">Assigned manager</span>
                    <select name="assigned_manager_id" class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm">
                        <option value="">— None —</option>
                        <?php foreach ($managers as $m): ?>
                            <option value="<?= (int) $m['id'] ?>"
                                <?= (int)($record['assigned_manager_id'] ?? 0) === (int) $m['id'] ? 'selected' : '' ?>>
                                <?= e($m['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="block">
                    <span class="text-sm font-medium text-slate-700">Status</span>
                    <select name="status" class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm">
                        <option value="active"   <?= ($record['status'] ?? 'active') === 'active' ? 'selected' : '' ?>>Active</option>
                        <option value="inactive" <?= ($record['status'] ?? '') === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                    </select>
                </label>
            </div>

            <div class="flex gap-3 pt-2">
                <button type="submit"
                        class="rounded bg-brand-600 hover:bg-brand-700 text-white px-4 py-2 text-sm font-medium">
                    <?= $mode === 'edit' ? 'Save changes' : 'Add client' ?>
                </button>
                <a href="/firm/clients.php"
                   class="rounded border border-slate-300 px-4 py-2 text-sm hover:bg-slate-50">Cancel</a>
                <?php if ($mode === 'edit'): ?>
                    <a href="/firm/client_portal.php?id=<?= (int) $record['id'] ?>"
                       class="ml-auto rounded border border-slate-300 px-4 py-2 text-sm hover:bg-slate-50">
                        Manage portal users &rarr;
                    </a>
                <?php endif; ?>
            </div>
        </form>
    </div>

<?php else: ?>
    <div class="flex items-center justify-between mb-4">
        <p class="text-sm text-slate-600">Auditee companies under your firm.</p>
        <?php if ($canEdit): ?>
            <a href="/firm/clients.php?action=new"
               class="rounded bg-brand-600 hover:bg-brand-700 text-white px-4 py-2 text-sm font-medium">
                + Add Client
            </a>
        <?php endif; ?>
    </div>

    <div class="bg-white rounded-lg border border-slate-200 overflow-hidden">
        <?php if (empty($clients)): ?>
            <div class="p-8 text-center text-sm text-slate-500">No clients yet.</div>
        <?php else: ?>
            <table class="table-app">
                <thead>
                    <tr>
                        <th>Company</th>
                        <th>Industry</th>
                        <th>FYE</th>
                        <th>Manager</th>
                        <th>Active engagements</th>
                        <th>Audit status</th>
                        <?php if ($canEdit): ?><th></th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($clients as $c): ?>
                        <tr>
                            <td>
                                <div class="font-medium"><?= e($c['company_name']) ?></div>
                                <div class="text-xs text-slate-500"><?= e($c['registration_no'] ?? '') ?></div>
                            </td>
                            <td><?= e($c['industry'] ?? '-') ?></td>
                            <td><?= e($c['financial_year_end'] ?? '-') ?></td>
                            <td><?= e($c['manager_name'] ?? '-') ?></td>
                            <td><?= (int) $c['active_eng'] ?></td>
                            <td><?= badge($c['audit_status']) ?></td>
                            <?php if ($canEdit): ?>
                                <td class="text-right">
                                    <a href="/firm/client_portal.php?id=<?= (int) $c['id'] ?>"
                                       class="text-sm text-slate-600 hover:underline mr-3">Portal</a>
                                    <a href="/firm/clients.php?action=edit&id=<?= (int) $c['id'] ?>"
                                       class="text-sm text-brand-600 hover:underline">Edit</a>
                                </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
