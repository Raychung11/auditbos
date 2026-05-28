<?php
/**
 * /audit/tax_computation.php
 *
 * Malaysian corporate tax computation per engagement (workplan step 21).
 * Accounting profit → add-backs + deductions + capital allowances →
 * chargeable income → tax at 24% (default, editable).
 */

declare(strict_types=1);
require_once __DIR__ . '/../includes/auth_guard.php';
require_role(['firm_admin','audit_manager','senior_auditor','junior_auditor','reviewer']);
require_once __DIR__ . '/../includes/workflow.php';
require_once __DIR__ . '/../includes/workplan.php';
require_once __DIR__ . '/../includes/tax_computation.php';

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
    $pageTitle = 'Tax computation';
    require __DIR__ . '/../includes/header.php';
    ?>
    <h2 class="text-xl font-semibold text-slate-900 mb-2">Tax computation</h2>
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

if (!table_exists('tax_computations')) {
    $pageTitle = 'Tax computation — ' . $engagement['company_name'];
    require __DIR__ . '/../includes/header.php';
    ?>
    <div class="bg-amber-50 border border-amber-200 rounded p-4 text-sm text-amber-900">
        <strong>Migration not applied.</strong> Run <code>/sql/010_gap_modules.sql</code> to enable this module.
    </div>
    <?php
    require __DIR__ . '/../includes/footer.php';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (!$canEdit) { http_response_code(403); exit('Forbidden'); }
    assert_engagement_open($engagementId);
    $action = $_POST['_action'] ?? '';

    if ($action === 'comp_save') {
        $ap   = (float) ($_POST['accounting_profit'] ?? 0);
        $rate = (float) ($_POST['tax_rate_pct'] ?? 24);
        $def  = (float) ($_POST['deferred_tax_movement'] ?? 0);
        $paid = (float) ($_POST['tax_paid'] ?? 0);
        $notes = trim((string) ($_POST['notes'] ?? '')) ?: null;
        $pdo->prepare(
            'INSERT INTO tax_computations
                (engagement_id, accounting_profit, tax_rate_pct, deferred_tax_movement,
                 tax_paid, notes, prepared_by)
             VALUES (:e, :ap, :r, :d, :tp, :n, :u)
             ON DUPLICATE KEY UPDATE
                accounting_profit=VALUES(accounting_profit),
                tax_rate_pct=VALUES(tax_rate_pct),
                deferred_tax_movement=VALUES(deferred_tax_movement),
                tax_paid=VALUES(tax_paid),
                notes=VALUES(notes),
                prepared_by=VALUES(prepared_by)'
        )->execute([
            ':e'=>$engagementId, ':ap'=>$ap, ':r'=>$rate, ':d'=>$def, ':tp'=>$paid,
            ':n'=>$notes, ':u'=>current_user_id(),
        ]);
        log_activity('tax.save', 'engagement', $engagementId);
        flash('success', 'Tax computation saved.');
    } elseif ($action === 'seed_defaults') {
        $n = tax_seed_default_lines($engagementId);
        flash('success', $n > 0
            ? "Inserted {$n} default Malaysian template lines."
            : 'Lines already exist — skipped seeding.');
    } elseif ($action === 'adj_create') {
        $cat = (string) ($_POST['category'] ?? 'add_back');
        $li  = trim((string) ($_POST['line_item'] ?? ''));
        $amt = (float) ($_POST['amount'] ?? 0);
        $ref = trim((string) ($_POST['mfrs_reference'] ?? '')) ?: null;
        $notes = trim((string) ($_POST['notes'] ?? '')) ?: null;
        if ($li === '' || !in_array($cat, array_keys(tax_adjustment_categories()), true)) {
            flash('error', 'Line item and a valid category are required.');
        } else {
            $pdo->prepare(
                'INSERT INTO tax_adjustments (engagement_id, category, line_item, amount, mfrs_reference, notes)
                 VALUES (:e, :c, :li, :a, :r, :n)'
            )->execute([
                ':e'=>$engagementId, ':c'=>$cat, ':li'=>$li, ':a'=>$amt, ':r'=>$ref, ':n'=>$notes,
            ]);
            flash('success', 'Adjustment added.');
        }
    } elseif ($action === 'adj_update') {
        $id  = (int) ($_POST['id'] ?? 0);
        $amt = (float) ($_POST['amount'] ?? 0);
        $pdo->prepare(
            'UPDATE tax_adjustments SET amount=:a WHERE id=:id AND engagement_id=:e'
        )->execute([':a'=>$amt, ':id'=>$id, ':e'=>$engagementId]);
        flash('success', 'Amount updated.');
    } elseif ($action === 'adj_delete') {
        $id = (int) ($_POST['id'] ?? 0);
        $pdo->prepare('DELETE FROM tax_adjustments WHERE id=:id AND engagement_id=:e')
            ->execute([':id'=>$id, ':e'=>$engagementId]);
        flash('success', 'Adjustment removed.');
    }
    redirect('/audit/tax_computation.php?engagement_id=' . $engagementId);
}

workplan_sync_status($engagementId);
$data = tax_load($engagementId);
$categories = tax_adjustment_categories();

$pageTitle = 'Tax computation — ' . $engagement['company_name'];
require __DIR__ . '/../includes/header.php';
?>

<a href="/audit/tax_computation.php" class="text-sm text-brand-600 hover:underline">&larr; Change engagement</a>
<a href="/firm/engagement_view.php?id=<?= (int) $engagementId ?>"
   class="ml-3 text-sm text-brand-600 hover:underline">Engagement workspace</a>

<div class="mt-3">
<?php echo workplan_breadcrumb_html((int) $engagementId,
    workplan_step_for_context('module', 'tax_computation', (int) $engagementId)); ?>
</div>

<div class="flex flex-wrap items-end justify-between gap-3 mt-1 mb-4">
    <div>
        <h2 class="text-xl font-semibold text-slate-900">
            <?= e($engagement['company_name']) ?> — Tax computation
        </h2>
        <p class="text-sm text-slate-500"><?= e($engagement['financial_year']) ?></p>
    </div>
    <?php if ($canEdit && !engagement_locked($engagementId) && (!$data || empty($data['adjustments']))): ?>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="_action" value="seed_defaults">
            <button class="rounded bg-slate-100 hover:bg-slate-200 text-slate-700 text-sm px-3 py-1.5">
                Seed Malaysian template
            </button>
        </form>
    <?php endif; ?>
</div>

<!-- Computation header -->
<form method="post" class="bg-white rounded-lg border border-slate-200 p-5 mb-5 grid grid-cols-1 md:grid-cols-4 gap-4">
    <?= csrf_field() ?>
    <input type="hidden" name="_action" value="comp_save">
    <label class="block">
        <span class="text-sm font-medium text-slate-700">Accounting profit (PBT)</span>
        <input type="number" step="0.01" name="accounting_profit"
               value="<?= e($data['comp']['accounting_profit'] ?? '0') ?>"
               class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm">
    </label>
    <label class="block">
        <span class="text-sm font-medium text-slate-700">Tax rate (%)</span>
        <input type="number" step="0.01" name="tax_rate_pct"
               value="<?= e($data['comp']['tax_rate_pct'] ?? '24.00') ?>"
               class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm">
    </label>
    <label class="block">
        <span class="text-sm font-medium text-slate-700">Deferred tax movement</span>
        <input type="number" step="0.01" name="deferred_tax_movement"
               value="<?= e($data['comp']['deferred_tax_movement'] ?? '0') ?>"
               class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm">
    </label>
    <label class="block">
        <span class="text-sm font-medium text-slate-700">Tax paid (CP204)</span>
        <input type="number" step="0.01" name="tax_paid"
               value="<?= e($data['comp']['tax_paid'] ?? '0') ?>"
               class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm">
    </label>
    <label class="block md:col-span-4">
        <span class="text-sm font-medium text-slate-700">Notes</span>
        <textarea name="notes" rows="2" class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"><?= e($data['comp']['notes'] ?? '') ?></textarea>
    </label>
    <?php if ($canEdit && !engagement_locked($engagementId)): ?>
        <div class="md:col-span-4">
            <button class="rounded bg-brand-600 hover:bg-brand-700 text-white text-sm px-3 py-1.5">Save header</button>
        </div>
    <?php endif; ?>
</form>

<!-- Results summary (only after computation exists) -->
<?php if ($data): ?>
    <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-5">
        <div class="bg-white rounded border border-slate-200 px-3 py-3">
            <div class="text-xs uppercase tracking-wide text-slate-500">Chargeable income</div>
            <div class="text-xl font-semibold text-slate-900">MYR <?= number_format($data['chargeable_income'], 2) ?></div>
        </div>
        <div class="bg-white rounded border border-slate-200 px-3 py-3">
            <div class="text-xs uppercase tracking-wide text-slate-500">Tax expense</div>
            <div class="text-xl font-semibold text-slate-900">MYR <?= number_format($data['tax_expense'], 2) ?></div>
        </div>
        <div class="bg-white rounded border border-slate-200 px-3 py-3">
            <div class="text-xs uppercase tracking-wide text-slate-500">Effective rate</div>
            <div class="text-xl font-semibold text-slate-900">
                <?= $data['effective_rate'] !== null ? number_format($data['effective_rate'], 2) . '%' : '—' ?>
            </div>
        </div>
        <div class="bg-white rounded border border-slate-200 px-3 py-3">
            <div class="text-xs uppercase tracking-wide text-slate-500">Net payable / (refund)</div>
            <div class="text-xl font-semibold text-slate-900">MYR
                <?= number_format($data['tax_expense'] - (float) $data['comp']['tax_paid'], 2) ?>
            </div>
        </div>
    </div>
<?php endif; ?>

<!-- Adjustments by category -->
<?php foreach ($categories as $catKey => $catMeta):
    $rows = array_values(array_filter($data['adjustments'] ?? [], fn($r) => $r['category'] === $catKey));
    $sub  = $data['totals'][$catKey] ?? 0.0;
    $sign = $catMeta['sign'];
?>
    <div class="bg-white rounded-lg border border-slate-200 mb-4">
        <div class="px-4 py-3 border-b border-slate-100 flex items-center justify-between">
            <div>
                <div class="font-medium text-slate-900"><?= e($catMeta['label']) ?></div>
                <div class="text-xs text-slate-500">
                    <?= $sign === 1 ? 'Increases chargeable income' :
                        ($sign === -1 ? 'Reduces chargeable income' : 'Tracked separately') ?>
                </div>
            </div>
            <div class="text-sm font-semibold text-slate-900 tabular-nums">
                MYR <?= number_format($sub, 2) ?>
            </div>
        </div>

        <?php if (!empty($rows)): ?>
            <table class="min-w-full text-sm">
                <thead class="bg-slate-50 text-xs uppercase text-slate-500">
                    <tr>
                        <th class="px-3 py-2 text-left">Line item</th>
                        <th class="px-3 py-2 text-left">MFRS / ITA ref</th>
                        <th class="px-3 py-2 text-right">Amount</th>
                        <th class="px-3 py-2"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td class="px-3 py-2">
                            <div class="font-medium text-slate-900"><?= e($r['line_item']) ?></div>
                            <?php if (!empty($r['notes'])): ?>
                                <div class="text-xs text-slate-500"><?= e($r['notes']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="px-3 py-2 text-slate-600"><?= e($r['mfrs_reference'] ?? '—') ?></td>
                        <td class="px-3 py-2 text-right tabular-nums">
                            <?php if ($canEdit && !engagement_locked($engagementId)): ?>
                                <form method="post" class="inline-flex items-center gap-1">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="_action" value="adj_update">
                                    <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                                    <input type="number" step="0.01" name="amount" value="<?= e($r['amount']) ?>"
                                           class="rounded border border-slate-300 px-2 py-1 text-sm text-right w-32">
                                    <button class="text-xs text-brand-600 hover:underline">Save</button>
                                </form>
                            <?php else: ?>
                                <?= number_format((float) $r['amount'], 2) ?>
                            <?php endif; ?>
                        </td>
                        <td class="px-3 py-2 text-right">
                            <?php if ($canEdit && !engagement_locked($engagementId)): ?>
                                <form method="post" class="inline">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="_action" value="adj_delete">
                                    <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                                    <button class="text-xs text-rose-600 hover:underline">Delete</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <?php if ($canEdit && !engagement_locked($engagementId)): ?>
            <form method="post" class="border-t border-slate-100 p-3 grid grid-cols-1 md:grid-cols-12 gap-2">
                <?= csrf_field() ?>
                <input type="hidden" name="_action" value="adj_create">
                <input type="hidden" name="category" value="<?= e($catKey) ?>">
                <input name="line_item" placeholder="Line item" required
                       class="md:col-span-5 rounded border border-slate-300 px-3 py-1.5 text-sm">
                <input name="mfrs_reference" placeholder="MFRS / ITA ref"
                       class="md:col-span-3 rounded border border-slate-300 px-3 py-1.5 text-sm">
                <input type="number" step="0.01" name="amount" placeholder="Amount" value="0"
                       class="md:col-span-2 rounded border border-slate-300 px-3 py-1.5 text-sm text-right">
                <button class="md:col-span-2 rounded bg-brand-600 hover:bg-brand-700 text-white text-sm px-3 py-1.5">Add</button>
            </form>
        <?php endif; ?>
    </div>
<?php endforeach; ?>

<?php
require __DIR__ . '/../includes/footer.php';
