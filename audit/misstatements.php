<?php
/**
 * /audit/misstatements.php
 *
 * Schedule of Uncorrected Misstatements (workplan step 24, ISA 450).
 * Aggregates raised misstatements and compares uncorrected PBT impact
 * against performance materiality and the CTT threshold.
 */

declare(strict_types=1);
require_once __DIR__ . '/../includes/auth_guard.php';
require_role(['firm_admin','audit_manager','senior_auditor','junior_auditor','reviewer']);
require_once __DIR__ . '/../includes/workflow.php';
require_once __DIR__ . '/../includes/workplan.php';
require_once __DIR__ . '/../includes/misstatements.php';
require_once __DIR__ . '/../includes/lead_schedules.php';

$pdo    = db();
$firmId = current_firm_id();
$engagementId = isset($_GET['engagement_id']) ? (int) $_GET['engagement_id'] : 0;

if ($engagementId <= 0) {
    $picker = $pdo->prepare(
        'SELECT e.id, e.financial_year, c.company_name
           FROM engagements e
           JOIN clients c ON c.id = e.client_id
          WHERE e.firm_id = :f ORDER BY e.updated_at DESC LIMIT 50'
    );
    $picker->execute([':f' => $firmId]);
    $engs = $picker->fetchAll();
    $pageTitle = 'Misstatements (SUM)';
    require __DIR__ . '/../includes/header.php';
    ?>
    <h2 class="text-xl font-semibold text-slate-900 mb-2">Misstatements (SUM)</h2>
    <p class="text-sm text-slate-500 mb-4">Pick an engagement.</p>
    <div class="bg-white rounded-lg border border-slate-200 divide-y divide-slate-100">
        <?php foreach ($engs as $e): ?>
            <a href="?engagement_id=<?= (int) $e['id'] ?>"
               class="block px-4 py-3 text-sm hover:bg-slate-50">
                <?= e($e['company_name']) ?> <span class="text-slate-500">· <?= e($e['financial_year']) ?></span>
            </a>
        <?php endforeach; ?>
    </div>
    <?php
    require __DIR__ . '/../includes/footer.php';
    exit;
}

$engStmt = $pdo->prepare(
    'SELECT e.*, c.company_name
       FROM engagements e
       JOIN clients c ON c.id = e.client_id
      WHERE e.id = :id AND e.firm_id = :f'
);
$engStmt->execute([':id' => $engagementId, ':f' => $firmId]);
$engagement = $engStmt->fetch();
if (!$engagement) {
    flash('error', 'Engagement not found.');
    redirect('/firm/engagements.php');
}

$canEdit = role_allows(['firm_admin','audit_manager','senior_auditor','junior_auditor']);

if (!table_exists('misstatements')) {
    $pageTitle = 'Misstatements — ' . $engagement['company_name'];
    require __DIR__ . '/../includes/header.php';
    ?>
    <div class="bg-amber-50 border border-amber-200 rounded p-4 text-sm text-amber-900">
        <strong>Migration not applied.</strong> Run <code>/sql/010_gap_modules.sql</code>.
    </div>
    <?php require __DIR__ . '/../includes/footer.php';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (!$canEdit) { http_response_code(403); exit('Forbidden'); }
    assert_engagement_open($engagementId);
    $action = $_POST['_action'] ?? '';

    if ($action === 'create') {
        $desc  = trim((string) ($_POST['description'] ?? ''));
        $cat   = (string) ($_POST['category'] ?? 'factual');
        $lead  = trim((string) ($_POST['lead_area'] ?? '')) ?: null;
        $wpId  = (int) ($_POST['working_paper_id'] ?? 0) ?: null;
        $aPbt  = (float) ($_POST['amount_pbt'] ?? 0);
        $aAst  = (float) ($_POST['amount_assets'] ?? 0);
        $aLib  = (float) ($_POST['amount_liabilities'] ?? 0);
        $notes = trim((string) ($_POST['notes'] ?? '')) ?: null;
        $catOk = in_array($cat, array_keys(mis_categories()), true);

        if ($desc === '' || !$catOk) {
            flash('error', 'Description and a valid category are required.');
        } else {
            $pdo->prepare(
                'INSERT INTO misstatements
                    (engagement_id, working_paper_id, lead_area, category, description,
                     amount_assets, amount_liabilities, amount_pbt, status, notes, raised_by)
                 VALUES (:e, :w, :la, :c, :d, :aa, :al, :ap, "uncorrected", :n, :u)'
            )->execute([
                ':e'=>$engagementId, ':w'=>$wpId, ':la'=>$lead, ':c'=>$cat, ':d'=>$desc,
                ':aa'=>$aAst, ':al'=>$aLib, ':ap'=>$aPbt, ':n'=>$notes, ':u'=>current_user_id(),
            ]);
            log_activity('misstatement.create', 'engagement', $engagementId, $desc);
            flash('success', 'Misstatement logged.');
        }
    } elseif ($action === 'status') {
        $id = (int) ($_POST['id'] ?? 0);
        $st = (string) ($_POST['status'] ?? 'uncorrected');
        if (!in_array($st, array_keys(mis_statuses()), true)) {
            flash('error', 'Invalid status.');
        } else {
            $pdo->prepare(
                'UPDATE misstatements SET status=:s WHERE id=:id AND engagement_id=:e'
            )->execute([':s'=>$st, ':id'=>$id, ':e'=>$engagementId]);
            flash('success', 'Status updated.');
        }
    } elseif ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        $pdo->prepare('DELETE FROM misstatements WHERE id=:id AND engagement_id=:e')
            ->execute([':id'=>$id, ':e'=>$engagementId]);
        flash('success', 'Removed.');
    }
    redirect('/audit/misstatements.php?engagement_id=' . $engagementId);
}

workplan_sync_status($engagementId);
$items   = mis_load($engagementId);
$summary = mis_summary($engagementId);
$areas   = lead_areas();

// WP list for the "raise against" dropdown
$wps = $pdo->prepare(
    'SELECT id, reference_code, title, lead_area FROM audit_working_papers
      WHERE engagement_id = :e ORDER BY reference_code, id'
);
$wps->execute([':e' => $engagementId]);
$wpRows = $wps->fetchAll();

$pageTitle = 'Misstatements — ' . $engagement['company_name'];
require __DIR__ . '/../includes/header.php';
?>

<a href="/audit/misstatements.php" class="text-sm text-brand-600 hover:underline">&larr; Change engagement</a>
<a href="/firm/engagement_view.php?id=<?= (int) $engagementId ?>"
   class="ml-3 text-sm text-brand-600 hover:underline">Engagement workspace</a>

<div class="mt-3">
<?php echo workplan_breadcrumb_html((int) $engagementId,
    workplan_step_for_context('module', 'misstatements', (int) $engagementId)); ?>
</div>

<div class="mt-1 mb-4 flex flex-wrap items-end justify-between gap-3">
    <div>
        <h2 class="text-xl font-semibold text-slate-900">
            <?= e($engagement['company_name']) ?> — Schedule of Uncorrected Misstatements
        </h2>
        <p class="text-sm text-slate-500"><?= e($engagement['financial_year']) ?> · ISA 450</p>
    </div>
    <?php if (defined('AI_ENABLED') && AI_ENABLED && !empty($items)): ?>
        <a href="/ai/run.php?fn=ai_review_misstatements&engagement_id=<?= (int) $engagementId ?>"
           class="inline-flex items-center gap-1 rounded bg-violet-600 hover:bg-violet-700 text-white px-3 py-1.5 text-sm font-medium">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/>
            </svg>
            AI misstatement review
        </a>
    <?php endif; ?>
</div>

<!-- Summary vs materiality -->
<div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-5">
    <div class="bg-white rounded border border-slate-200 px-3 py-3">
        <div class="text-xs uppercase tracking-wide text-slate-500">Uncorrected — PBT impact</div>
        <div class="text-xl font-semibold text-slate-900">MYR <?= number_format(abs($summary['uncorrected']['pbt']), 2) ?></div>
        <div class="text-xs text-slate-500"><?= $summary['uncorrected']['count'] ?> item(s)</div>
    </div>
    <div class="bg-white rounded border border-slate-200 px-3 py-3">
        <div class="text-xs uppercase tracking-wide text-slate-500">Performance materiality</div>
        <div class="text-xl font-semibold text-slate-900">
            <?= $summary['pm'] !== null ? 'MYR ' . number_format($summary['pm'], 2) : '—' ?>
        </div>
        <?php if ($summary['pm'] === null): ?>
            <div class="text-xs text-amber-700">Set in Materiality page</div>
        <?php endif; ?>
    </div>
    <div class="bg-white rounded border border-slate-200 px-3 py-3 <?= $summary['breach_pm'] ? 'border-rose-200 bg-rose-50' : '' ?>">
        <div class="text-xs uppercase tracking-wide text-slate-500">Breach of PM</div>
        <div class="text-xl font-semibold <?= $summary['breach_pm'] ? 'text-rose-700' : 'text-emerald-700' ?>">
            <?= $summary['breach_pm'] ? 'Yes' : 'No' ?>
        </div>
    </div>
    <div class="bg-white rounded border border-slate-200 px-3 py-3">
        <div class="text-xs uppercase tracking-wide text-slate-500">Corrected — PBT impact</div>
        <div class="text-xl font-semibold text-slate-900">MYR <?= number_format(abs($summary['corrected']['pbt']), 2) ?></div>
        <div class="text-xs text-slate-500"><?= $summary['corrected']['count'] ?> item(s)</div>
    </div>
</div>

<!-- Add misstatement -->
<?php if ($canEdit && !engagement_locked($engagementId)): ?>
<details class="bg-white rounded-lg border border-slate-200 mb-5">
    <summary class="cursor-pointer px-4 py-3 font-medium text-slate-900">+ Raise misstatement</summary>
    <form method="post" class="p-4 grid grid-cols-1 md:grid-cols-3 gap-3 border-t border-slate-100">
        <?= csrf_field() ?>
        <input type="hidden" name="_action" value="create">
        <label class="block md:col-span-3">
            <span class="text-sm font-medium text-slate-700">Description *</span>
            <input name="description" required class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm">
        </label>
        <label class="block">
            <span class="text-sm font-medium text-slate-700">Category *</span>
            <select name="category" required class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm">
                <?php foreach (mis_categories() as $k => $v): ?>
                    <option value="<?= e($k) ?>"><?= e($v) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="block">
            <span class="text-sm font-medium text-slate-700">Lead area</span>
            <select name="lead_area" class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm">
                <option value="">—</option>
                <?php foreach ($areas as $k => $a): ?>
                    <option value="<?= e($k) ?>"><?= e($a['label']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="block">
            <span class="text-sm font-medium text-slate-700">Working paper</span>
            <select name="working_paper_id" class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm">
                <option value="">—</option>
                <?php foreach ($wpRows as $w): ?>
                    <option value="<?= (int) $w['id'] ?>">
                        <?= e(($w['reference_code'] ? $w['reference_code'] . ' · ' : '') . $w['title']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="block">
            <span class="text-sm font-medium text-slate-700">PBT impact (+ overstates / − understates)</span>
            <input type="number" step="0.01" name="amount_pbt" value="0"
                   class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm">
        </label>
        <label class="block">
            <span class="text-sm font-medium text-slate-700">Assets impact</span>
            <input type="number" step="0.01" name="amount_assets" value="0"
                   class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm">
        </label>
        <label class="block">
            <span class="text-sm font-medium text-slate-700">Liabilities impact</span>
            <input type="number" step="0.01" name="amount_liabilities" value="0"
                   class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm">
        </label>
        <label class="block md:col-span-3">
            <span class="text-sm font-medium text-slate-700">Notes</span>
            <textarea name="notes" rows="2" class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"></textarea>
        </label>
        <div class="md:col-span-3">
            <button class="rounded bg-brand-600 hover:bg-brand-700 text-white text-sm px-3 py-1.5">Raise</button>
        </div>
    </form>
</details>
<?php endif; ?>

<!-- List -->
<div class="bg-white rounded-lg border border-slate-200 overflow-hidden">
    <div class="px-4 py-3 border-b border-slate-100 font-medium text-slate-900">All misstatements</div>
    <?php if (empty($items)): ?>
        <div class="p-5 text-sm text-slate-500">No misstatements raised yet.</div>
    <?php else: ?>
        <table class="min-w-full text-sm">
            <thead class="bg-slate-50 text-xs uppercase text-slate-500">
                <tr>
                    <th class="px-3 py-2 text-left">Description</th>
                    <th class="px-3 py-2 text-left">Category</th>
                    <th class="px-3 py-2 text-left">Lead / WP</th>
                    <th class="px-3 py-2 text-right">PBT</th>
                    <th class="px-3 py-2 text-right">Assets</th>
                    <th class="px-3 py-2 text-right">Liab.</th>
                    <th class="px-3 py-2 text-left">Status</th>
                    <th class="px-3 py-2"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
            <?php foreach ($items as $m): ?>
                <tr class="<?= $m['status'] === 'uncorrected' ? '' : 'bg-slate-50/40' ?>">
                    <td class="px-3 py-2 text-slate-900"><?= e($m['description']) ?></td>
                    <td class="px-3 py-2 text-slate-600"><?= e(mis_categories()[$m['category']] ?? $m['category']) ?></td>
                    <td class="px-3 py-2 text-slate-600 text-xs">
                        <?php if (!empty($m['lead_area'])): ?>
                            <?= e($areas[$m['lead_area']]['label'] ?? $m['lead_area']) ?><br>
                        <?php endif; ?>
                        <?php if (!empty($m['wp_ref']) || !empty($m['wp_title'])): ?>
                            <span class="text-slate-500"><?= e($m['wp_ref']) ?> <?= e($m['wp_title']) ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="px-3 py-2 text-right tabular-nums"><?= number_format((float) $m['amount_pbt'], 2) ?></td>
                    <td class="px-3 py-2 text-right tabular-nums"><?= number_format((float) $m['amount_assets'], 2) ?></td>
                    <td class="px-3 py-2 text-right tabular-nums"><?= number_format((float) $m['amount_liabilities'], 2) ?></td>
                    <td class="px-3 py-2">
                        <?php if ($canEdit && !engagement_locked($engagementId)): ?>
                            <form method="post" class="inline-flex items-center gap-1">
                                <?= csrf_field() ?>
                                <input type="hidden" name="_action" value="status">
                                <input type="hidden" name="id" value="<?= (int) $m['id'] ?>">
                                <select name="status" onchange="this.form.submit()"
                                        class="rounded border border-slate-300 px-2 py-1 text-xs">
                                    <?php foreach (mis_statuses() as $k => $v): ?>
                                        <option value="<?= e($k) ?>" <?= $m['status'] === $k ? 'selected' : '' ?>>
                                            <?= e($v) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </form>
                        <?php else: ?>
                            <?= e(mis_statuses()[$m['status']] ?? $m['status']) ?>
                        <?php endif; ?>
                    </td>
                    <td class="px-3 py-2 text-right">
                        <?php if ($canEdit && !engagement_locked($engagementId)): ?>
                            <form method="post" class="inline">
                                <?= csrf_field() ?>
                                <input type="hidden" name="_action" value="delete">
                                <input type="hidden" name="id" value="<?= (int) $m['id'] ?>">
                                <button class="text-rose-600 hover:underline text-xs">Delete</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<?php
require __DIR__ . '/../includes/footer.php';
