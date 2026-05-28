<?php
/**
 * /firm/engagements.php
 *
 * Engagement management — list + create + edit.
 * Detail view (with documents, working papers, AI panel) lives in
 * /firm/engagement_view.php.
 */

declare(strict_types=1);
require_once __DIR__ . '/../includes/auth_guard.php';
require_role(['firm_admin','audit_manager','senior_auditor','junior_auditor','reviewer']);
require_once __DIR__ . '/../includes/workflow.php';
require_once __DIR__ . '/../includes/rollover.php';
require_once __DIR__ . '/../includes/workplan.php';

$pdo    = db();
$firmId = current_firm_id();
$mode   = $_GET['action'] ?? 'list';
$id     = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$canEdit = role_allows(['firm_admin','audit_manager']);

$statuses = ['draft','pending_documents','in_progress','under_review',
             'partner_review','completed','billed','archived'];
$types    = ['audit','review','compilation','tax','advisory'];

// ---------------------------------------------------------------------
// POST: create / edit
// ---------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (!$canEdit) { http_response_code(403); exit('Forbidden'); }

    $editId    = (int)($_POST['id'] ?? 0);
    if ($editId > 0) {
        assert_engagement_open($editId);
    }
    $clientId  = (int)($_POST['client_id'] ?? 0);
    $engCode   = trim((string)($_POST['engagement_code'] ?? '')) ?: null;
    $fy        = trim((string)($_POST['financial_year'] ?? ''));
    $pStart    = trim((string)($_POST['period_start'] ?? '')) ?: null;
    $pEnd      = trim((string)($_POST['period_end'] ?? '')) ?: null;
    $type      = (string)($_POST['engagement_type'] ?? 'audit');
    $fee       = $_POST['fee_amount'] !== '' ? (float)$_POST['fee_amount'] : null;
    $startDt   = trim((string)($_POST['start_date'] ?? '')) ?: null;
    $deadline  = trim((string)($_POST['deadline'] ?? '')) ?: null;
    $partnerId = (int)($_POST['partner_id'] ?? 0) ?: null;
    $managerId = (int)($_POST['manager_id'] ?? 0) ?: null;
    $status    = (string)($_POST['status'] ?? 'draft');

    // Validate client belongs to firm
    $cstmt = $pdo->prepare('SELECT id FROM clients WHERE id=:id AND firm_id=:fid');
    $cstmt->execute([':id'=>$clientId, ':fid'=>$firmId]);
    if (!$cstmt->fetch()) {
        flash('error', 'Invalid client.');
    } elseif ($fy === '' || !in_array($type, $types, true) || !in_array($status, $statuses, true)) {
        flash('error', 'Please fill all required fields.');
    } else {
        try {
            if ($editId > 0) {
                $check = $pdo->prepare('SELECT id FROM engagements WHERE id=:id AND firm_id=:fid');
                $check->execute([':id'=>$editId, ':fid'=>$firmId]);
                if (!$check->fetch()) {
                    flash('error', 'Engagement not found.');
                    redirect('/firm/engagements.php');
                }
                $pdo->prepare(
                    'UPDATE engagements SET client_id=:c, engagement_code=:code, financial_year=:fy,
                        period_start=:ps, period_end=:pe, engagement_type=:t, fee_amount=:f,
                        start_date=:sd, deadline=:dl, partner_id=:pi, manager_id=:mi, status=:s
                     WHERE id=:id AND firm_id=:fid'
                )->execute([
                    ':c'=>$clientId, ':code'=>$engCode, ':fy'=>$fy, ':ps'=>$pStart, ':pe'=>$pEnd,
                    ':t'=>$type, ':f'=>$fee, ':sd'=>$startDt, ':dl'=>$deadline,
                    ':pi'=>$partnerId, ':mi'=>$managerId, ':s'=>$status,
                    ':id'=>$editId, ':fid'=>$firmId,
                ]);
                log_activity('engagement.update', 'engagement', $editId);
                flash('success', 'Engagement updated.');
                redirect('/firm/engagement_view.php?id=' . $editId);
            } else {
                $pdo->prepare(
                    'INSERT INTO engagements (firm_id, client_id, engagement_code, financial_year,
                        period_start, period_end, engagement_type, fee_amount, start_date, deadline,
                        partner_id, manager_id, status, created_by)
                     VALUES (:fid, :c, :code, :fy, :ps, :pe, :t, :f, :sd, :dl, :pi, :mi, :s, :cb)'
                )->execute([
                    ':fid'=>$firmId, ':c'=>$clientId, ':code'=>$engCode, ':fy'=>$fy,
                    ':ps'=>$pStart, ':pe'=>$pEnd, ':t'=>$type, ':f'=>$fee,
                    ':sd'=>$startDt, ':dl'=>$deadline, ':pi'=>$partnerId, ':mi'=>$managerId,
                    ':s'=>$status, ':cb'=>current_user_id(),
                ]);
                $newId = (int) $pdo->lastInsertId();
                log_activity('engagement.create', 'engagement', $newId);
                $seeded = workplan_seed_engagement($newId);
                if ($seeded > 0) {
                    log_activity('engagement.workplan_seeded', 'engagement', $newId,
                        'Seeded ' . $seeded . ' workplan steps');
                }
                flash('success', 'Engagement created with the 27-step audit workplan. You can now seed the document checklist.');
                redirect('/firm/engagement_view.php?id=' . $newId);
            }
        } catch (Throwable $e) {
            error_log('[AuditBOS] engagement save: ' . $e->getMessage());
            flash('error', 'Could not save engagement.');
        }
        redirect('/firm/engagements.php');
    }
}

// ---------------------------------------------------------------------
// POST: roll over a prior-year engagement into a new one.
// ---------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'rollover') {
    csrf_check();
    if (!$canEdit) { http_response_code(403); exit('Forbidden'); }
    $sourceId = (int)($_POST['source_id'] ?? 0);
    try {
        $newId = rollover_engagement($sourceId, $firmId, [
            'financial_year'  => $_POST['financial_year']  ?? '',
            'period_start'    => $_POST['period_start']    ?? '',
            'period_end'      => $_POST['period_end']      ?? '',
            'deadline'        => $_POST['deadline']        ?? '',
            'engagement_code' => $_POST['engagement_code'] ?? '',
        ], current_user_id());
        flash('success', 'Engagement rolled over. Update the financial period and import the new TB to continue.');
        redirect('/firm/engagement_view.php?id=' . $newId);
    } catch (Throwable $ex) {
        flash('error', 'Rollover failed: ' . $ex->getMessage());
        redirect('/firm/engagements.php?action=rollover&source_id=' . $sourceId);
    }
}

// ---------------------------------------------------------------------
// Rollover form: pick the new FY + dates for a chosen source engagement.
// ---------------------------------------------------------------------
if ($mode === 'rollover') {
    $sourceId = isset($_GET['source_id']) ? (int) $_GET['source_id'] : 0;
    $src = $pdo->prepare(
        'SELECT e.*, c.company_name FROM engagements e
           JOIN clients c ON c.id = e.client_id
          WHERE e.id = :id AND e.firm_id = :fid'
    );
    $src->execute([':id'=>$sourceId, ':fid'=>$firmId]);
    $source = $src->fetch();
    if (!$source) {
        flash('error', 'Source engagement not found.');
        redirect('/firm/engagements.php');
    }

    // Suggested defaults: next FY, +12 months on the period dates.
    $suggestedFy   = preg_match('/FY(\d{4})/i', $source['financial_year'], $m)
        ? 'FY' . ((int) $m[1] + 1) : $source['financial_year'];
    $shiftDate = static fn(?string $d) => $d
        ? date('Y-m-d', strtotime($d . ' +1 year'))
        : '';
    $suggestedStart = $shiftDate($source['period_start']);
    $suggestedEnd   = $shiftDate($source['period_end']);
    $suggestedDl    = $shiftDate($source['deadline']);

    $pageTitle = 'Roll over engagement';
    require __DIR__ . '/../includes/header.php';
    ?>
    <a href="/firm/engagement_view.php?id=<?= (int) $source['id'] ?>"
       class="text-sm text-brand-600 hover:underline">&larr; Back</a>
    <h2 class="text-xl font-semibold text-slate-900 mt-1 mb-1">Roll over engagement</h2>
    <p class="text-sm text-slate-500 mb-4">
        Cloning structure from <strong><?= e($source['company_name']) ?></strong> ·
        <?= e($source['financial_year']) ?>. The new engagement will copy working papers
        (status reset), document requests, lead-schedule overrides, materiality basis,
        and the engagement team. Trial balance, GL, documents, review notes and AI
        outputs are <strong>not</strong> copied — those start fresh.
    </p>

    <form method="post" class="bg-white rounded-lg border border-slate-200 p-6 space-y-4 max-w-3xl">
        <?= csrf_field() ?>
        <input type="hidden" name="_action" value="rollover">
        <input type="hidden" name="source_id" value="<?= (int) $source['id'] ?>">

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <label class="block">
                <span class="text-sm font-medium text-slate-700">New financial year *</span>
                <input name="financial_year" required value="<?= e($suggestedFy) ?>"
                       class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm">
            </label>
            <label class="block">
                <span class="text-sm font-medium text-slate-700">Engagement code</span>
                <input name="engagement_code"
                       class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm">
            </label>
            <label class="block">
                <span class="text-sm font-medium text-slate-700">Period start</span>
                <input type="date" name="period_start" value="<?= e($suggestedStart) ?>"
                       class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm">
            </label>
            <label class="block">
                <span class="text-sm font-medium text-slate-700">Period end</span>
                <input type="date" name="period_end" value="<?= e($suggestedEnd) ?>"
                       class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm">
            </label>
            <label class="block">
                <span class="text-sm font-medium text-slate-700">Deadline</span>
                <input type="date" name="deadline" value="<?= e($suggestedDl) ?>"
                       class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm">
            </label>
        </div>

        <div class="flex gap-3 pt-2">
            <button class="rounded bg-brand-600 hover:bg-brand-700 text-white px-4 py-2 text-sm font-medium">
                Create new engagement &amp; clone
            </button>
            <a href="/firm/engagement_view.php?id=<?= (int) $source['id'] ?>"
               class="rounded border border-slate-300 px-4 py-2 text-sm hover:bg-slate-50">Cancel</a>
        </div>
    </form>
    <?php
    require __DIR__ . '/../includes/footer.php';
    exit;
}

// ---------------------------------------------------------------------
// Load record for edit
// ---------------------------------------------------------------------
$record = null;
if ($mode === 'edit' && $id > 0) {
    $stmt = $pdo->prepare('SELECT * FROM engagements WHERE id=:id AND firm_id=:fid');
    $stmt->execute([':id'=>$id, ':fid'=>$firmId]);
    $record = $stmt->fetch();
    if (!$record) { flash('error', 'Engagement not found.'); redirect('/firm/engagements.php'); }
}

// Dropdown data
$clientsList = [];
$staffList   = [];
if (in_array($mode, ['new','edit'], true)) {
    $stmt = $pdo->prepare('SELECT id, company_name FROM clients WHERE firm_id=:fid AND status="active" ORDER BY company_name');
    $stmt->execute([':fid' => $firmId]);
    $clientsList = $stmt->fetchAll();

    $stmt = $pdo->prepare(
        "SELECT id, name, role FROM users
          WHERE firm_id=:fid AND status='active' AND role <> 'client_user'
          ORDER BY name"
    );
    $stmt->execute([':fid' => $firmId]);
    $staffList = $stmt->fetchAll();
}

// List view with filters: status, year-end month (MM), industry,
// partner, manager, client text search.
$filterStatus    = $_GET['status']    ?? null;
$filterFyeMonth  = $_GET['fye_month'] ?? '';
$filterIndustry  = trim((string)($_GET['industry'] ?? ''));
$filterPartner   = isset($_GET['partner_id']) ? (int) $_GET['partner_id'] : 0;
$filterManager   = isset($_GET['manager_id']) ? (int) $_GET['manager_id'] : 0;
$filterSearch    = trim((string)($_GET['q'] ?? ''));

$engagements  = [];
$filterFacets = ['industries' => [], 'staff' => []];

if ($mode === 'list') {
    $sql = 'SELECT e.*, c.company_name, c.industry, c.financial_year_end,
                   pu.name AS partner_name, mu.name AS manager_name
              FROM engagements e
              JOIN clients c   ON c.id = e.client_id
              LEFT JOIN users pu ON pu.id = e.partner_id
              LEFT JOIN users mu ON mu.id = e.manager_id
             WHERE e.firm_id = :fid';
    $params = [':fid' => $firmId];

    if ($filterStatus && in_array($filterStatus, $statuses, true)) {
        $sql .= ' AND e.status = :s'; $params[':s'] = $filterStatus;
    }
    if ($filterFyeMonth !== '' && preg_match('/^(0[1-9]|1[0-2])$/', $filterFyeMonth)) {
        // financial_year_end is stored "MM-DD"
        $sql .= ' AND c.financial_year_end LIKE :fye';
        $params[':fye'] = $filterFyeMonth . '-%';
    }
    if ($filterIndustry !== '') {
        $sql .= ' AND c.industry = :ind'; $params[':ind'] = $filterIndustry;
    }
    if ($filterPartner > 0) {
        $sql .= ' AND e.partner_id = :pid'; $params[':pid'] = $filterPartner;
    }
    if ($filterManager > 0) {
        $sql .= ' AND e.manager_id = :mid'; $params[':mid'] = $filterManager;
    }
    if ($filterSearch !== '') {
        $sql .= ' AND (c.company_name LIKE :q1 OR e.engagement_code LIKE :q2 OR e.financial_year LIKE :q3)';
        $like = '%' . $filterSearch . '%';
        $params[':q1'] = $like; $params[':q2'] = $like; $params[':q3'] = $like;
    }
    $sql .= ' ORDER BY e.updated_at DESC LIMIT 200';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $engagements = $stmt->fetchAll();

    // Filter facets — distinct industries and active firm staff (for the dropdowns).
    $facets = $pdo->prepare(
        'SELECT DISTINCT c.industry FROM clients c
          WHERE c.firm_id = :f AND c.industry IS NOT NULL AND c.industry <> ""
          ORDER BY c.industry'
    );
    $facets->execute([':f' => $firmId]);
    $filterFacets['industries'] = array_filter(array_column($facets->fetchAll(), 'industry'));

    $staff = $pdo->prepare(
        "SELECT id, name, role FROM users
          WHERE firm_id = :f AND status = 'active' AND role <> 'client_user'
          ORDER BY name"
    );
    $staff->execute([':f' => $firmId]);
    $filterFacets['staff'] = $staff->fetchAll();
}

$pageTitle = 'Engagements';
require __DIR__ . '/../includes/header.php';
?>

<?php if ($mode === 'new' || $mode === 'edit'): ?>
    <div class="max-w-4xl">
        <a href="/firm/engagements.php" class="text-sm text-brand-600 hover:underline">&larr; Back to engagements</a>
        <h2 class="text-xl font-semibold text-slate-900 mt-1 mb-4">
            <?= $mode === 'edit' ? 'Edit Engagement' : 'New Engagement' ?>
        </h2>

        <form method="post" class="bg-white rounded-lg border border-slate-200 p-6 space-y-4">
            <?= csrf_field() ?>
            <?php if ($mode === 'edit'): ?>
                <input type="hidden" name="id" value="<?= (int) $record['id'] ?>">
            <?php endif; ?>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <label class="block">
                    <span class="text-sm font-medium text-slate-700">Client *</span>
                    <select name="client_id" required class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm">
                        <option value="">— Select client —</option>
                        <?php foreach ($clientsList as $c): ?>
                            <option value="<?= (int) $c['id'] ?>"
                                <?= (int)($record['client_id'] ?? 0) === (int) $c['id'] ? 'selected' : '' ?>>
                                <?= e($c['company_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="block">
                    <span class="text-sm font-medium text-slate-700">Engagement code</span>
                    <input name="engagement_code" placeholder="e.g. AUD-2024-001"
                           value="<?= e($record['engagement_code'] ?? '') ?>"
                           class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm">
                </label>
                <label class="block">
                    <span class="text-sm font-medium text-slate-700">Financial year *</span>
                    <input name="financial_year" required placeholder="FY2024"
                           value="<?= e($record['financial_year'] ?? '') ?>"
                           class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm">
                </label>
                <label class="block">
                    <span class="text-sm font-medium text-slate-700">Engagement type</span>
                    <select name="engagement_type" class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm">
                        <?php foreach ($types as $t): ?>
                            <option value="<?= $t ?>" <?= ($record['engagement_type'] ?? 'audit') === $t ? 'selected' : '' ?>>
                                <?= ucfirst($t) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="block">
                    <span class="text-sm font-medium text-slate-700">Period start</span>
                    <input type="date" name="period_start" value="<?= e($record['period_start'] ?? '') ?>"
                           class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm">
                </label>
                <label class="block">
                    <span class="text-sm font-medium text-slate-700">Period end</span>
                    <input type="date" name="period_end" value="<?= e($record['period_end'] ?? '') ?>"
                           class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm">
                </label>
                <label class="block">
                    <span class="text-sm font-medium text-slate-700">Fee amount</span>
                    <input type="number" step="0.01" name="fee_amount" value="<?= e($record['fee_amount'] ?? '') ?>"
                           class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm">
                </label>
                <label class="block">
                    <span class="text-sm font-medium text-slate-700">Deadline</span>
                    <input type="date" name="deadline" value="<?= e($record['deadline'] ?? '') ?>"
                           class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm">
                </label>
                <label class="block">
                    <span class="text-sm font-medium text-slate-700">Start date</span>
                    <input type="date" name="start_date" value="<?= e($record['start_date'] ?? '') ?>"
                           class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm">
                </label>
                <label class="block">
                    <span class="text-sm font-medium text-slate-700">Status</span>
                    <select name="status" class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm">
                        <?php foreach ($statuses as $s): ?>
                            <option value="<?= $s ?>" <?= ($record['status'] ?? 'draft') === $s ? 'selected' : '' ?>>
                                <?= ucwords(str_replace('_',' ',$s)) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="block">
                    <span class="text-sm font-medium text-slate-700">Partner in charge</span>
                    <select name="partner_id" class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm">
                        <option value="">— None —</option>
                        <?php foreach ($staffList as $s): ?>
                            <option value="<?= (int) $s['id'] ?>"
                                <?= (int)($record['partner_id'] ?? 0) === (int) $s['id'] ? 'selected' : '' ?>>
                                <?= e($s['name']) ?> · <?= e(ucwords(str_replace('_',' ',$s['role']))) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="block">
                    <span class="text-sm font-medium text-slate-700">Manager in charge</span>
                    <select name="manager_id" class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm">
                        <option value="">— None —</option>
                        <?php foreach ($staffList as $s): ?>
                            <option value="<?= (int) $s['id'] ?>"
                                <?= (int)($record['manager_id'] ?? 0) === (int) $s['id'] ? 'selected' : '' ?>>
                                <?= e($s['name']) ?> · <?= e(ucwords(str_replace('_',' ',$s['role']))) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </div>

            <div class="flex gap-3 pt-2">
                <button type="submit"
                        class="rounded bg-brand-600 hover:bg-brand-700 text-white px-4 py-2 text-sm font-medium">
                    <?= $mode === 'edit' ? 'Save changes' : 'Create engagement' ?>
                </button>
                <a href="/firm/engagements.php"
                   class="rounded border border-slate-300 px-4 py-2 text-sm hover:bg-slate-50">Cancel</a>
            </div>
        </form>
    </div>

<?php else: ?>
    <div class="flex items-center justify-between mb-4">
        <p class="text-sm text-slate-600">All audit engagements for your firm. <?= count($engagements) ?> result(s).</p>
        <?php if ($canEdit): ?>
            <a href="/firm/engagements.php?action=new"
               class="rounded bg-brand-600 hover:bg-brand-700 text-white px-4 py-2 text-sm font-medium">
                + New Engagement
            </a>
        <?php endif; ?>
    </div>

    <!-- Filter bar -->
    <form method="get" class="bg-white rounded-lg border border-slate-200 p-4 mb-4 grid grid-cols-1 md:grid-cols-6 gap-3 text-sm">
        <label class="block md:col-span-2">
            <span class="text-xs font-medium text-slate-700">Search</span>
            <input name="q" value="<?= e($filterSearch) ?>" placeholder="Client / code / FY"
                   class="mt-1 w-full rounded border border-slate-300 px-2 py-1.5">
        </label>
        <label class="block">
            <span class="text-xs font-medium text-slate-700">Status</span>
            <select name="status" class="mt-1 w-full rounded border border-slate-300 px-2 py-1.5">
                <option value="">All</option>
                <?php foreach ($statuses as $st): ?>
                    <option value="<?= $st ?>" <?= $filterStatus === $st ? 'selected' : '' ?>>
                        <?= ucwords(str_replace('_',' ',$st)) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="block">
            <span class="text-xs font-medium text-slate-700">Year end (month)</span>
            <select name="fye_month" class="mt-1 w-full rounded border border-slate-300 px-2 py-1.5">
                <option value="">Any</option>
                <?php
                $monthNames = [1=>'Jan',2=>'Feb',3=>'Mar',4=>'Apr',5=>'May',6=>'Jun',
                               7=>'Jul',8=>'Aug',9=>'Sep',10=>'Oct',11=>'Nov',12=>'Dec'];
                for ($m = 1; $m <= 12; $m++):
                    $mm = sprintf('%02d', $m);
                ?>
                    <option value="<?= $mm ?>" <?= $filterFyeMonth === $mm ? 'selected' : '' ?>>
                        <?= $monthNames[$m] ?> (<?= $mm ?>)
                    </option>
                <?php endfor; ?>
            </select>
        </label>
        <label class="block">
            <span class="text-xs font-medium text-slate-700">Industry</span>
            <select name="industry" class="mt-1 w-full rounded border border-slate-300 px-2 py-1.5">
                <option value="">Any</option>
                <?php foreach ($filterFacets['industries'] as $ind): ?>
                    <option value="<?= e($ind) ?>" <?= $filterIndustry === $ind ? 'selected' : '' ?>>
                        <?= e($ind) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="block">
            <span class="text-xs font-medium text-slate-700">Partner</span>
            <select name="partner_id" class="mt-1 w-full rounded border border-slate-300 px-2 py-1.5">
                <option value="0">Any</option>
                <?php foreach ($filterFacets['staff'] as $u): ?>
                    <option value="<?= (int) $u['id'] ?>" <?= $filterPartner === (int) $u['id'] ? 'selected' : '' ?>>
                        <?= e($u['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="block">
            <span class="text-xs font-medium text-slate-700">Manager</span>
            <select name="manager_id" class="mt-1 w-full rounded border border-slate-300 px-2 py-1.5">
                <option value="0">Any</option>
                <?php foreach ($filterFacets['staff'] as $u): ?>
                    <option value="<?= (int) $u['id'] ?>" <?= $filterManager === (int) $u['id'] ? 'selected' : '' ?>>
                        <?= e($u['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <div class="md:col-span-6 flex items-end gap-2">
            <button class="rounded bg-brand-600 hover:bg-brand-700 text-white px-3 py-1.5 text-sm">Apply</button>
            <a href="/firm/engagements.php" class="rounded border border-slate-300 px-3 py-1.5 text-sm hover:bg-slate-50">Reset</a>
        </div>
    </form>

    <div class="bg-white rounded-lg border border-slate-200 overflow-hidden">
        <?php if (empty($engagements)): ?>
            <div class="p-8 text-center text-sm text-slate-500">No engagements yet.</div>
        <?php else: ?>
            <table class="table-app">
                <thead>
                    <tr>
                        <th>Code</th>
                        <th>Client</th>
                        <th>FY</th>
                        <th>Type</th>
                        <th>Partner / Manager</th>
                        <th>Deadline</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($engagements as $eng):
                        $overdue = !empty($eng['deadline']) && strtotime($eng['deadline']) < strtotime('today')
                            && !in_array($eng['status'], ['completed','billed','archived'], true);
                    ?>
                        <tr>
                            <td class="font-mono text-xs"><?= e($eng['engagement_code'] ?? '-') ?></td>
                            <td class="font-medium"><?= e($eng['company_name']) ?></td>
                            <td><?= e($eng['financial_year']) ?></td>
                            <td><?= e(ucfirst($eng['engagement_type'])) ?></td>
                            <td>
                                <div class="text-xs"><?= e($eng['partner_name'] ?? '-') ?></div>
                                <div class="text-xs text-slate-500"><?= e($eng['manager_name'] ?? '-') ?></div>
                            </td>
                            <td>
                                <?= e(datefmt($eng['deadline'])) ?>
                                <?php if ($overdue): ?>
                                    <span class="ml-1 text-[10px] text-rose-700 font-semibold">OVERDUE</span>
                                <?php endif; ?>
                            </td>
                            <td><?= badge($eng['status']) ?></td>
                            <td class="text-right">
                                <a href="/firm/engagement_view.php?id=<?= (int) $eng['id'] ?>"
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
