<?php
/**
 * /audit/working_papers.php
 *
 * Working paper list / create. Filters by engagement_id if provided.
 * Detail view is /audit/working_paper_view.php.
 */

declare(strict_types=1);
require_once __DIR__ . '/../includes/auth_guard.php';
require_role(['firm_admin','audit_manager','senior_auditor','junior_auditor','reviewer']);
require_once __DIR__ . '/../includes/workflow.php';

$pdo    = db();
$firmId = current_firm_id();
$mode   = $_GET['action'] ?? 'list';
$id     = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$engagementId = isset($_GET['engagement_id']) ? (int) $_GET['engagement_id'] : 0;
$filterStatus = $_GET['status'] ?? null;

$statuses = ['not_started','prepared','pending_review','review_note_raised','cleared','completed'];
$risks    = ['low','medium','high','critical'];

// ---------------------------------------------------------------------
// POST: create or edit working paper
// ---------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $editId    = (int)($_POST['id'] ?? 0);
    $engId     = (int)($_POST['engagement_id'] ?? 0);
    $sectionId = (int)($_POST['section_id'] ?? 0) ?: null;
    $refCode   = trim((string)($_POST['reference_code'] ?? '')) ?: null;
    $title     = trim((string)($_POST['title'] ?? ''));
    $procedure = trim((string)($_POST['procedure'] ?? '')) ?: null;
    $conclusion= trim((string)($_POST['conclusion'] ?? '')) ?: null;
    $notes     = trim((string)($_POST['notes'] ?? '')) ?: null;
    $status    = (string)($_POST['status'] ?? 'not_started');
    $risk      = $_POST['risk_rating'] ?? '';
    $risk      = in_array($risk, $risks, true) ? $risk : null;

    // Verify engagement is in this firm
    $check = $pdo->prepare('SELECT id FROM engagements WHERE id=:id AND firm_id=:fid');
    $check->execute([':id'=>$engId, ':fid'=>$firmId]);
    if (!$check->fetch()) {
        flash('error', 'Invalid engagement.');
    } elseif (engagement_locked($engId)) {
        flash('error', 'This engagement is locked. Unlock it to edit working papers.');
        redirect('/firm/engagement_view.php?id=' . $engId);
    } elseif ($title === '' || !in_array($status, $statuses, true)) {
        flash('error', 'Title and a valid status are required.');
    } else {
        try {
            if ($editId > 0) {
                $verify = $pdo->prepare(
                    'SELECT wp.id FROM audit_working_papers wp
                       JOIN engagements e ON e.id = wp.engagement_id
                      WHERE wp.id = :id AND e.firm_id = :fid'
                );
                $verify->execute([':id'=>$editId, ':fid'=>$firmId]);
                if (!$verify->fetch()) {
                    flash('error', 'Working paper not found.');
                    redirect('/audit/working_papers.php');
                }
                $pdo->prepare(
                    'UPDATE audit_working_papers
                        SET section_id=:sid, reference_code=:rc, title=:t, `procedure`=:p,
                            conclusion=:c, notes=:n, status=:s, risk_rating=:r,
                            prepared_by = CASE WHEN status IN ("not_started") AND :s2 <> "not_started"
                                               THEN :uid ELSE prepared_by END,
                            prepared_at = CASE WHEN prepared_at IS NULL AND :s3 <> "not_started"
                                               THEN NOW() ELSE prepared_at END
                      WHERE id=:id'
                )->execute([
                    ':sid'=>$sectionId, ':rc'=>$refCode, ':t'=>$title, ':p'=>$procedure,
                    ':c'=>$conclusion, ':n'=>$notes, ':s'=>$status, ':r'=>$risk,
                    ':s2'=>$status, ':s3'=>$status, ':uid'=>current_user_id(), ':id'=>$editId,
                ]);
                log_activity('wp.update', 'audit_working_paper', $editId, $title);
                flash('success', 'Working paper updated.');
                redirect('/audit/working_paper_view.php?id=' . $editId);
            } else {
                $pdo->prepare(
                    'INSERT INTO audit_working_papers
                        (engagement_id, section_id, reference_code, title, `procedure`,
                         conclusion, notes, status, risk_rating, prepared_by, prepared_at)
                     VALUES
                        (:eid, :sid, :rc, :t, :p, :c, :n, :s, :r,
                         CASE WHEN :s2 <> "not_started" THEN :uid ELSE NULL END,
                         CASE WHEN :s3 <> "not_started" THEN NOW() ELSE NULL END)'
                )->execute([
                    ':eid'=>$engId, ':sid'=>$sectionId, ':rc'=>$refCode, ':t'=>$title,
                    ':p'=>$procedure, ':c'=>$conclusion, ':n'=>$notes, ':s'=>$status,
                    ':r'=>$risk, ':s2'=>$status, ':s3'=>$status, ':uid'=>current_user_id(),
                ]);
                $newId = (int) $pdo->lastInsertId();
                log_activity('wp.create', 'audit_working_paper', $newId, $title);
                flash('success', 'Working paper created.');
                redirect('/audit/working_paper_view.php?id=' . $newId);
            }
        } catch (Throwable $e) {
            error_log('[AuditBOS] WP save: ' . $e->getMessage());
            flash('error', 'Could not save working paper.');
        }
        redirect('/audit/working_papers.php');
    }
}

// ---------------------------------------------------------------------
// Load record + dropdowns for new/edit
// ---------------------------------------------------------------------
$record = null;
if ($mode === 'edit' && $id > 0) {
    $stmt = $pdo->prepare(
        'SELECT wp.* FROM audit_working_papers wp
           JOIN engagements e ON e.id = wp.engagement_id
          WHERE wp.id = :id AND e.firm_id = :fid'
    );
    $stmt->execute([':id'=>$id, ':fid'=>$firmId]);
    $record = $stmt->fetch();
    if (!$record) { flash('error', 'Not found.'); redirect('/audit/working_papers.php'); }
    $engagementId = (int) $record['engagement_id'];
}

$engagementsList = [];
$sectionsList    = [];
if (in_array($mode, ['new','edit'], true)) {
    $stmt = $pdo->prepare(
        'SELECT e.id, e.financial_year, c.company_name
           FROM engagements e
           JOIN clients c ON c.id = e.client_id
          WHERE e.firm_id = :fid AND e.status NOT IN ("archived")
          ORDER BY c.company_name, e.financial_year DESC'
    );
    $stmt->execute([':fid' => $firmId]);
    $engagementsList = $stmt->fetchAll();

    $stmt = $pdo->prepare(
        'SELECT id, code, name FROM audit_sections
          WHERE (firm_id IS NULL OR firm_id = :fid) AND status = "active"
          ORDER BY sort_order'
    );
    $stmt->execute([':fid' => $firmId]);
    $sectionsList = $stmt->fetchAll();
}

// List view
$workingPapers = [];
if ($mode === 'list') {
    $sql = 'SELECT wp.*, e.financial_year, c.company_name, asec.name AS section_name,
                   (SELECT COUNT(*) FROM audit_review_notes arn
                     WHERE arn.working_paper_id = wp.id AND arn.status = "open") AS open_notes
              FROM audit_working_papers wp
              JOIN engagements e ON e.id = wp.engagement_id
              JOIN clients c     ON c.id = e.client_id
              LEFT JOIN audit_sections asec ON asec.id = wp.section_id
             WHERE e.firm_id = :fid';
    $params = [':fid' => $firmId];
    if ($engagementId > 0) {
        $sql .= ' AND wp.engagement_id = :eid';
        $params[':eid'] = $engagementId;
    }
    if ($filterStatus && in_array($filterStatus, $statuses, true)) {
        $sql .= ' AND wp.status = :s';
        $params[':s'] = $filterStatus;
    }
    $sql .= ' ORDER BY wp.updated_at DESC LIMIT 200';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $workingPapers = $stmt->fetchAll();
}

$pageTitle = 'Working Papers';
require __DIR__ . '/../includes/header.php';
?>

<?php if ($mode === 'new' || $mode === 'edit'): ?>
    <div class="max-w-4xl">
        <a href="/audit/working_papers.php" class="text-sm text-brand-600 hover:underline">&larr; Back</a>
        <h2 class="text-xl font-semibold text-slate-900 mt-1 mb-4">
            <?= $mode === 'edit' ? 'Edit Working Paper' : 'New Working Paper' ?>
        </h2>

        <form method="post" class="bg-white rounded-lg border border-slate-200 p-6 space-y-4">
            <?= csrf_field() ?>
            <?php if ($mode === 'edit'): ?>
                <input type="hidden" name="id" value="<?= (int) $record['id'] ?>">
            <?php endif; ?>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <label class="block">
                    <span class="text-sm font-medium text-slate-700">Engagement *</span>
                    <select name="engagement_id" required <?= $mode === 'edit' ? 'disabled' : '' ?>
                            class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm <?= $mode === 'edit' ? 'bg-slate-50' : '' ?>">
                        <option value="">— Select —</option>
                        <?php foreach ($engagementsList as $eOpt): ?>
                            <option value="<?= (int) $eOpt['id'] ?>"
                                <?= (int)($record['engagement_id'] ?? $engagementId) === (int) $eOpt['id'] ? 'selected' : '' ?>>
                                <?= e($eOpt['company_name']) ?> — <?= e($eOpt['financial_year']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if ($mode === 'edit'): ?>
                        <input type="hidden" name="engagement_id" value="<?= (int) $record['engagement_id'] ?>">
                    <?php endif; ?>
                </label>
                <label class="block">
                    <span class="text-sm font-medium text-slate-700">Section</span>
                    <select name="section_id" class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm">
                        <option value="">— None —</option>
                        <?php foreach ($sectionsList as $sec): ?>
                            <option value="<?= (int) $sec['id'] ?>"
                                <?= (int)($record['section_id'] ?? 0) === (int) $sec['id'] ? 'selected' : '' ?>>
                                <?= e($sec['code']) ?> — <?= e($sec['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="block">
                    <span class="text-sm font-medium text-slate-700">Reference code</span>
                    <input name="reference_code" placeholder="e.g. B-100"
                           value="<?= e($record['reference_code'] ?? '') ?>"
                           class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm">
                </label>
                <label class="block">
                    <span class="text-sm font-medium text-slate-700">Title *</span>
                    <input name="title" required value="<?= e($record['title'] ?? '') ?>"
                           class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm">
                </label>
                <label class="block md:col-span-2">
                    <span class="text-sm font-medium text-slate-700">Audit procedure</span>
                    <textarea name="procedure" rows="4"
                              class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"><?= e($record['procedure'] ?? '') ?></textarea>
                </label>
                <label class="block md:col-span-2">
                    <span class="text-sm font-medium text-slate-700">Conclusion</span>
                    <textarea name="conclusion" rows="3"
                              class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"><?= e($record['conclusion'] ?? '') ?></textarea>
                </label>
                <label class="block md:col-span-2">
                    <span class="text-sm font-medium text-slate-700">Notes</span>
                    <textarea name="notes" rows="2"
                              class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"><?= e($record['notes'] ?? '') ?></textarea>
                </label>
                <label class="block">
                    <span class="text-sm font-medium text-slate-700">Status</span>
                    <select name="status" class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm">
                        <?php foreach ($statuses as $s): ?>
                            <option value="<?= $s ?>" <?= ($record['status'] ?? 'not_started') === $s ? 'selected' : '' ?>>
                                <?= ucwords(str_replace('_',' ',$s)) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="block">
                    <span class="text-sm font-medium text-slate-700">Risk rating</span>
                    <select name="risk_rating" class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm">
                        <option value="">— None —</option>
                        <?php foreach ($risks as $r): ?>
                            <option value="<?= $r ?>" <?= ($record['risk_rating'] ?? '') === $r ? 'selected' : '' ?>>
                                <?= ucfirst($r) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </div>
            <div class="flex gap-3 pt-2">
                <button type="submit"
                        class="rounded bg-brand-600 hover:bg-brand-700 text-white px-4 py-2 text-sm font-medium">
                    <?= $mode === 'edit' ? 'Save changes' : 'Create working paper' ?>
                </button>
                <a href="/audit/working_papers.php"
                   class="rounded border border-slate-300 px-4 py-2 text-sm hover:bg-slate-50">Cancel</a>
            </div>
        </form>
    </div>

<?php else: ?>
    <div class="flex items-center justify-between mb-4">
        <div>
            <p class="text-sm text-slate-600">All audit working papers across your firm.</p>
            <?php if ($filterStatus): ?>
                <p class="text-xs text-slate-500 mt-1">Filter: <?= badge($filterStatus) ?>
                    <a href="/audit/working_papers.php" class="ml-2 text-brand-600 hover:underline">clear</a></p>
            <?php endif; ?>
        </div>
        <a href="/audit/working_papers.php?action=new<?= $engagementId ? '&engagement_id=' . $engagementId : '' ?>"
           class="rounded bg-brand-600 hover:bg-brand-700 text-white px-4 py-2 text-sm font-medium">
            + New Working Paper
        </a>
    </div>

    <div class="bg-white rounded-lg border border-slate-200 overflow-hidden">
        <?php if (empty($workingPapers)): ?>
            <div class="p-8 text-center text-sm text-slate-500">No working papers yet.</div>
        <?php else: ?>
            <table class="table-app">
                <thead>
                    <tr>
                        <th>Ref</th>
                        <th>Engagement</th>
                        <th>Section / Title</th>
                        <th>Risk</th>
                        <th>Open notes</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($workingPapers as $wp): ?>
                        <tr>
                            <td class="font-mono text-xs"><?= e($wp['reference_code'] ?? '-') ?></td>
                            <td>
                                <div class="text-xs text-slate-500"><?= e($wp['company_name']) ?></div>
                                <div class="text-xs"><?= e($wp['financial_year']) ?></div>
                            </td>
                            <td>
                                <div class="text-xs text-slate-500"><?= e($wp['section_name'] ?? '') ?></div>
                                <div class="font-medium"><?= e($wp['title']) ?></div>
                            </td>
                            <td><?= $wp['risk_rating'] ? badge($wp['risk_rating']) : '<span class="text-xs text-slate-400">—</span>' ?></td>
                            <td><?= (int) $wp['open_notes'] ?></td>
                            <td><?= badge($wp['status']) ?></td>
                            <td class="text-right">
                                <a href="/audit/working_paper_view.php?id=<?= (int) $wp['id'] ?>"
                                   class="text-sm text-brand-600 hover:underline">Open</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
