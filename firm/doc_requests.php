<?php
/**
 * /firm/doc_requests.php?engagement_id=...
 *
 * Custom CRUD for document_requests on a specific engagement. Replaces
 * the engagement-workspace "Seed Default Checklist" affordance for
 * cases where the auditor needs to add bespoke items (e.g. a specific
 * subsidiary's lease schedule, a manual journal voucher batch).
 */

declare(strict_types=1);
require_once __DIR__ . '/../includes/auth_guard.php';
require_role(['firm_admin','audit_manager','senior_auditor']);

$pdo    = db();
$firmId = current_firm_id();
$engagementId = isset($_GET['engagement_id']) ? (int) $_GET['engagement_id'] : 0;
$mode = $_GET['action'] ?? 'list';
$id   = isset($_GET['id']) ? (int) $_GET['id'] : 0;

if ($engagementId <= 0) {
    flash('error', 'Pick an engagement first.');
    redirect('/firm/engagements.php');
}

// Scope-check engagement
$estmt = $pdo->prepare(
    'SELECT e.id, e.financial_year, c.company_name
       FROM engagements e
       JOIN clients c ON c.id = e.client_id
      WHERE e.id = :id AND e.firm_id = :fid'
);
$estmt->execute([':id'=>$engagementId, ':fid'=>$firmId]);
$engagement = $estmt->fetch();
if (!$engagement) {
    flash('error', 'Engagement not found.');
    redirect('/firm/engagements.php');
}

$allowedStatuses = ['pending','received','rejected','needs_clarification','waived'];

// ---------------------------------------------------------------------
// POST handlers
// ---------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['_action'] ?? 'save';

    if ($action === 'delete') {
        $deleteId = (int)($_POST['id'] ?? 0);
        // Refuse delete if files are still attached.
        $check = $pdo->prepare(
            'SELECT COUNT(*) FROM engagement_documents
              WHERE document_request_id = :id AND status IN ("uploaded","accepted")'
        );
        $check->execute([':id' => $deleteId]);
        if ((int) $check->fetchColumn() > 0) {
            flash('error', 'Cannot delete a request with files attached. Detach files first.');
        } else {
            $pdo->prepare(
                'DELETE FROM document_requests WHERE id = :id AND engagement_id = :eid'
            )->execute([':id'=>$deleteId, ':eid'=>$engagementId]);
            log_activity('docreq.delete', 'document_request', $deleteId);
            flash('success', 'Request deleted.');
        }
        redirect('/firm/doc_requests.php?engagement_id=' . $engagementId);
    }

    // save (create or edit)
    $editId      = (int)($_POST['id'] ?? 0);
    $title       = trim((string)($_POST['title'] ?? ''));
    $description = trim((string)($_POST['description'] ?? '')) ?: null;
    $categoryId  = (int)($_POST['category_id'] ?? 0) ?: null;
    $dueDate     = trim((string)($_POST['due_date'] ?? '')) ?: null;
    $isRequired  = !empty($_POST['is_required']) ? 1 : 0;
    $status      = (string)($_POST['status'] ?? 'pending');
    $adminNotes  = trim((string)($_POST['admin_notes'] ?? '')) ?: null;

    if ($title === '') {
        flash('error', 'Title is required.');
    } elseif (!in_array($status, $allowedStatuses, true)) {
        flash('error', 'Invalid status.');
    } else {
        if ($editId > 0) {
            $check = $pdo->prepare('SELECT id FROM document_requests WHERE id = :id AND engagement_id = :eid');
            $check->execute([':id'=>$editId, ':eid'=>$engagementId]);
            if (!$check->fetch()) {
                flash('error', 'Request not found.');
                redirect('/firm/doc_requests.php?engagement_id=' . $engagementId);
            }
            $pdo->prepare(
                'UPDATE document_requests
                    SET title = :t, description = :d, category_id = :c,
                        due_date = :dd, is_required = :ir, status = :s,
                        admin_notes = :an
                  WHERE id = :id'
            )->execute([
                ':t'=>$title, ':d'=>$description, ':c'=>$categoryId,
                ':dd'=>$dueDate, ':ir'=>$isRequired, ':s'=>$status,
                ':an'=>$adminNotes, ':id'=>$editId,
            ]);
            log_activity('docreq.update', 'document_request', $editId, $title);
            flash('success', 'Request updated.');
        } else {
            $pdo->prepare(
                'INSERT INTO document_requests
                    (engagement_id, category_id, title, description, is_required,
                     due_date, status, admin_notes, requested_by)
                 VALUES (:e, :c, :t, :d, :ir, :dd, :s, :an, :u)'
            )->execute([
                ':e'=>$engagementId, ':c'=>$categoryId, ':t'=>$title, ':d'=>$description,
                ':ir'=>$isRequired, ':dd'=>$dueDate, ':s'=>$status,
                ':an'=>$adminNotes, ':u'=>current_user_id(),
            ]);
            $newId = (int) $pdo->lastInsertId();
            log_activity('docreq.create', 'document_request', $newId, $title);
            flash('success', 'Request added.');
        }
    }
    redirect('/firm/doc_requests.php?engagement_id=' . $engagementId);
}

// Load record for edit
$record = null;
if ($mode === 'edit' && $id > 0) {
    $stmt = $pdo->prepare('SELECT * FROM document_requests WHERE id = :id AND engagement_id = :eid');
    $stmt->execute([':id'=>$id, ':eid'=>$engagementId]);
    $record = $stmt->fetch();
    if (!$record) {
        flash('error', 'Request not found.');
        redirect('/firm/doc_requests.php?engagement_id=' . $engagementId);
    }
}

// Categories dropdown
$cats = $pdo->prepare(
    'SELECT id, name FROM document_categories
      WHERE (firm_id IS NULL OR firm_id = :fid) AND status = "active"
      ORDER BY sort_order'
);
$cats->execute([':fid' => $firmId]);
$categories = $cats->fetchAll();

// List
$requests = [];
if ($mode === 'list') {
    $stmt = $pdo->prepare(
        'SELECT dr.*, dc.name AS category_name,
                (SELECT COUNT(*) FROM engagement_documents ed
                   WHERE ed.document_request_id = dr.id AND ed.status IN ("uploaded","accepted")) AS file_count
           FROM document_requests dr
           LEFT JOIN document_categories dc ON dc.id = dr.category_id
          WHERE dr.engagement_id = :eid
          ORDER BY dr.status = "pending" DESC, dc.sort_order, dr.id'
    );
    $stmt->execute([':eid' => $engagementId]);
    $requests = $stmt->fetchAll();
}

$pageTitle = 'Document Requests';
require __DIR__ . '/../includes/header.php';
?>

<a href="/firm/engagement_view.php?id=<?= (int) $engagementId ?>"
   class="text-sm text-brand-600 hover:underline">&larr; Back to engagement</a>

<div class="flex flex-wrap items-center justify-between gap-3 mt-1 mb-5">
    <div>
        <h2 class="text-xl font-semibold text-slate-900">
            <?= e($engagement['company_name']) ?> · <?= e($engagement['financial_year']) ?>
        </h2>
        <p class="text-sm text-slate-500">Document checklist for this engagement.</p>
    </div>
</div>

<?php if ($mode === 'new' || $mode === 'edit'): ?>
    <div class="max-w-3xl">
        <h3 class="text-lg font-semibold text-slate-900 mb-3">
            <?= $mode === 'edit' ? 'Edit Request' : 'New Request' ?>
        </h3>
        <form method="post" class="bg-white rounded-lg border border-slate-200 p-6 space-y-4">
            <?= csrf_field() ?>
            <?php if ($mode === 'edit'): ?>
                <input type="hidden" name="id" value="<?= (int) $record['id'] ?>">
            <?php endif; ?>

            <label class="block">
                <span class="text-sm font-medium text-slate-700">Title *</span>
                <input name="title" required value="<?= e($record['title'] ?? '') ?>"
                       class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm">
            </label>
            <label class="block">
                <span class="text-sm font-medium text-slate-700">Description</span>
                <textarea name="description" rows="2"
                          class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"><?= e($record['description'] ?? '') ?></textarea>
            </label>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <label class="block">
                    <span class="text-sm font-medium text-slate-700">Category</span>
                    <select name="category_id" class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm">
                        <option value="">— None —</option>
                        <?php foreach ($categories as $c): ?>
                            <option value="<?= (int) $c['id'] ?>"
                                <?= (int)($record['category_id'] ?? 0) === (int) $c['id'] ? 'selected' : '' ?>>
                                <?= e($c['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="block">
                    <span class="text-sm font-medium text-slate-700">Due date</span>
                    <input type="date" name="due_date" value="<?= e($record['due_date'] ?? '') ?>"
                           class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm">
                </label>
                <label class="block">
                    <span class="text-sm font-medium text-slate-700">Status</span>
                    <select name="status" class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm">
                        <?php foreach ($allowedStatuses as $s): ?>
                            <option value="<?= $s ?>" <?= ($record['status'] ?? 'pending') === $s ? 'selected' : '' ?>>
                                <?= ucwords(str_replace('_',' ',$s)) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="block">
                    <span class="text-sm font-medium text-slate-700">Required?</span>
                    <div class="mt-2">
                        <label class="inline-flex items-center gap-2 text-sm">
                            <input type="checkbox" name="is_required" value="1"
                                <?= !empty($record['is_required']) || $mode === 'new' ? 'checked' : '' ?>>
                            Yes, mandatory
                        </label>
                    </div>
                </label>
            </div>

            <label class="block">
                <span class="text-sm font-medium text-slate-700">Admin notes (internal)</span>
                <textarea name="admin_notes" rows="2"
                          class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"><?= e($record['admin_notes'] ?? '') ?></textarea>
                <span class="text-xs text-slate-500">Not visible to the client.</span>
            </label>

            <div class="flex gap-3 pt-2">
                <button class="rounded bg-brand-600 hover:bg-brand-700 text-white px-4 py-2 text-sm font-medium">
                    <?= $mode === 'edit' ? 'Save changes' : 'Add request' ?>
                </button>
                <a href="/firm/doc_requests.php?engagement_id=<?= (int) $engagementId ?>"
                   class="rounded border border-slate-300 px-4 py-2 text-sm hover:bg-slate-50">Cancel</a>
            </div>
        </form>
    </div>

<?php else: ?>
    <div class="flex items-center justify-between mb-4">
        <p class="text-sm text-slate-600">
            <?= count(array_filter($requests, fn($r) => $r['status'] === 'received')) ?> received
            / <?= count($requests) ?> total.
        </p>
        <a href="/firm/doc_requests.php?engagement_id=<?= (int) $engagementId ?>&action=new"
           class="rounded bg-brand-600 hover:bg-brand-700 text-white px-4 py-2 text-sm font-medium">
            + New Request
        </a>
    </div>

    <div class="bg-white rounded-lg border border-slate-200 overflow-hidden">
        <?php if (empty($requests)): ?>
            <div class="p-8 text-center text-sm text-slate-500">
                No document requests yet.
                <a href="/firm/engagement_view.php?id=<?= (int) $engagementId ?>"
                   class="text-brand-600 hover:underline">Seed the default checklist</a>
                or add a custom request above.
            </div>
        <?php else: ?>
            <table class="table-app">
                <thead>
                    <tr>
                        <th>Document</th>
                        <th>Category</th>
                        <th>Due</th>
                        <th>Required?</th>
                        <th>Files</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($requests as $r): ?>
                    <tr>
                        <td>
                            <div class="font-medium"><?= e($r['title']) ?></div>
                            <?php if (!empty($r['description'])): ?>
                                <div class="text-xs text-slate-500"><?= e($r['description']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="text-xs text-slate-600"><?= e($r['category_name'] ?? '-') ?></td>
                        <td class="text-xs"><?= e(datefmt($r['due_date'])) ?></td>
                        <td class="text-xs">
                            <?= $r['is_required'] ? 'Yes' : 'Optional' ?>
                        </td>
                        <td class="text-xs"><?= (int) $r['file_count'] ?></td>
                        <td><?= badge($r['status']) ?></td>
                        <td class="text-right">
                            <a href="/firm/doc_requests.php?engagement_id=<?= (int) $engagementId ?>&action=edit&id=<?= (int) $r['id'] ?>"
                               class="text-sm text-brand-600 hover:underline mr-2">Edit</a>
                            <form method="post" class="inline" onsubmit="return confirm('Delete this request?');">
                                <?= csrf_field() ?>
                                <input type="hidden" name="_action" value="delete">
                                <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                                <button class="text-sm text-rose-600 hover:underline">Delete</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
