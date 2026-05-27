<?php
/**
 * /audit/analytical_review.php?engagement_id=…
 *
 * Materiality calculation + going-concern / financial-health ratios,
 * both derived from the imported trial balance.
 */

declare(strict_types=1);
require_once __DIR__ . '/../includes/auth_guard.php';
require_role(['firm_admin','audit_manager','senior_auditor','junior_auditor','reviewer']);
require_once __DIR__ . '/../includes/analytical.php';
require_once __DIR__ . '/../includes/workflow.php';

$pdo    = db();
$firmId = current_firm_id();
$engagementId = isset($_GET['engagement_id']) ? (int) $_GET['engagement_id'] : 0;
$canEdit = role_allows(['firm_admin','audit_manager','senior_auditor']);

// ---------------------------------------------------------------------
// Engagement picker.
// ---------------------------------------------------------------------
if ($engagementId <= 0) {
    $list = $pdo->prepare(
        'SELECT e.id, e.financial_year, e.status, c.company_name
           FROM engagements e JOIN clients c ON c.id = e.client_id
          WHERE e.firm_id = :f AND e.status NOT IN ("archived")
          ORDER BY c.company_name, e.financial_year DESC'
    );
    $list->execute([':f' => $firmId]);
    $engagements = $list->fetchAll();

    $pageTitle = 'Materiality & Ratios';
    require __DIR__ . '/../includes/header.php';
    ?>
    <div class="bg-white rounded-lg border border-slate-200 overflow-hidden">
        <div class="px-5 py-3 border-b border-slate-200">
            <h3 class="font-semibold text-slate-900">Pick an engagement</h3>
            <p class="text-xs text-slate-500 mt-0.5">Materiality and ratios are derived from the imported trial balance.</p>
        </div>
        <?php if (empty($engagements)): ?>
            <div class="p-8 text-center text-sm text-slate-500">No engagements available.</div>
        <?php else: ?>
            <table class="table-app">
                <thead><tr><th>Client</th><th>FY</th><th>Status</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($engagements as $e): ?>
                    <tr>
                        <td class="font-medium"><?= e($e['company_name']) ?></td>
                        <td><?= e($e['financial_year']) ?></td>
                        <td><?= badge($e['status']) ?></td>
                        <td class="text-right">
                            <a href="/audit/analytical_review.php?engagement_id=<?= (int) $e['id'] ?>"
                               class="text-sm text-brand-600 hover:underline">Open</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
    <?php
    require __DIR__ . '/../includes/footer.php';
    exit;
}

// ---------------------------------------------------------------------
// Load + scope-check.
// ---------------------------------------------------------------------
$stmt = $pdo->prepare(
    'SELECT e.*, c.company_name FROM engagements e
       JOIN clients c ON c.id = e.client_id
      WHERE e.id = :id AND e.firm_id = :fid'
);
$stmt->execute([':id'=>$engagementId, ':fid'=>$firmId]);
$engagement = $stmt->fetch();
if (!$engagement) {
    flash('error', 'Engagement not found.');
    redirect('/audit/analytical_review.php');
}

$bases = materiality_bases();

// ---------------------------------------------------------------------
// POST: save materiality.
// ---------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canEdit) {
    csrf_check();
    assert_engagement_open($engagementId);

    $basis        = (string)($_POST['basis'] ?? 'pbt');
    if (!isset($bases[$basis])) { $basis = 'pbt'; }
    $planningPct  = max(0.001, min(100, (float)($_POST['planning_pct'] ?? 5)));
    $performance  = max(0.001, min(100, (float)($_POST['performance_pct'] ?? 75)));
    $cttPct       = max(0.001, min(100, (float)($_POST['ctt_pct'] ?? 5)));
    $rationale    = trim((string)($_POST['rationale'] ?? '')) ?: null;

    $basisAmt     = materiality_basis_amount($engagementId, $basis);
    $planningAmt  = $basisAmt * $planningPct / 100;
    $performAmt   = $planningAmt * $performance / 100;
    $cttAmt       = $planningAmt * $cttPct / 100;

    $pdo->prepare(
        'INSERT INTO engagement_materiality
            (engagement_id, basis, basis_amount, planning_pct, planning_amount,
             performance_pct, performance_amount, ctt_pct, ctt_amount, rationale, set_by)
         VALUES (:e, :b, :ba, :pp, :pa, :fp, :fa, :cp, :ca, :r, :u)
         ON DUPLICATE KEY UPDATE
            basis = VALUES(basis), basis_amount = VALUES(basis_amount),
            planning_pct = VALUES(planning_pct), planning_amount = VALUES(planning_amount),
            performance_pct = VALUES(performance_pct), performance_amount = VALUES(performance_amount),
            ctt_pct = VALUES(ctt_pct), ctt_amount = VALUES(ctt_amount),
            rationale = VALUES(rationale), set_by = VALUES(set_by)'
    )->execute([
        ':e'=>$engagementId, ':b'=>$basis, ':ba'=>$basisAmt,
        ':pp'=>$planningPct, ':pa'=>$planningAmt,
        ':fp'=>$performance, ':fa'=>$performAmt,
        ':cp'=>$cttPct, ':ca'=>$cttAmt, ':r'=>$rationale, ':u'=>current_user_id(),
    ]);
    log_activity('materiality.set', 'engagement', $engagementId,
        $bases[$basis]['label'] . ' @ ' . $planningPct . '%');
    flash('success', 'Materiality saved.');
    redirect('/audit/analytical_review.php?engagement_id=' . $engagementId);
}

$metrics   = analytical_metrics($engagementId);
$ratios    = analytical_ratios($engagementId);
$mat       = materiality_load($engagementId);
$basisAmts = [];
foreach ($bases as $k => $_) {
    $basisAmts[$k] = materiality_basis_amount($engagementId, $k);
}

$pageTitle = 'Materiality & Ratios — ' . $engagement['company_name'];
require __DIR__ . '/../includes/header.php';

$fmtRatio = static function (?float $v, string $unit): string {
    if ($v === null) return '—';
    return match ($unit) {
        '%'      => number_format($v, 1) . '%',
        'x'      => number_format($v, 2) . '×',
        'amount' => number_format($v, 2),
        default  => number_format($v, 2),
    };
};
?>

<a href="/audit/analytical_review.php" class="text-sm text-brand-600 hover:underline">&larr; Change engagement</a>
<a href="/firm/engagement_view.php?id=<?= (int) $engagementId ?>"
   class="ml-3 text-sm text-brand-600 hover:underline">Engagement workspace</a>

<div class="flex flex-wrap items-end justify-between gap-3 mt-1 mb-5">
    <div>
        <h2 class="text-xl font-semibold text-slate-900"><?= e($engagement['company_name']) ?> — Materiality &amp; Ratios</h2>
        <p class="text-sm text-slate-500"><?= e($engagement['financial_year']) ?></p>
    </div>
    <?php if (defined('AI_ENABLED') && AI_ENABLED && $metrics['has_tb']): ?>
        <a href="/ai/run.php?fn=ai_going_concern_assessment&engagement_id=<?= (int) $engagementId ?>"
           class="rounded bg-brand-600 hover:bg-brand-700 text-white px-3 py-1.5 text-sm">
            AI going-concern review
        </a>
    <?php endif; ?>
</div>

<?php if (!$metrics['has_tb']): ?>
    <div class="bg-white rounded-lg border border-slate-200 p-8 text-center text-sm text-slate-500">
        No trial balance imported for this engagement.
        <a href="/import/trial_balance.php?engagement_id=<?= (int) $engagementId ?>"
           class="text-brand-600 hover:underline">Import one now</a>.
    </div>
<?php else: ?>
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

        <!-- Materiality -->
        <div class="bg-white rounded-lg border border-slate-200">
            <div class="px-5 py-3 border-b border-slate-200">
                <h3 class="font-semibold text-slate-900">Materiality</h3>
                <p class="text-xs text-slate-500">Set the benchmark and percentages; amounts compute from the current-year TB.</p>
            </div>
            <div class="p-5">
                <?php if (!$canEdit && !$mat): ?>
                    <p class="text-sm text-slate-500">Materiality not yet set.</p>
                <?php else: ?>
                <form method="post" class="space-y-3">
                    <?= csrf_field() ?>
                    <label class="block">
                        <span class="text-sm font-medium text-slate-700">Benchmark basis</span>
                        <select name="basis" <?= $canEdit ? '' : 'disabled' ?>
                                class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm">
                            <?php foreach ($bases as $k => $meta): ?>
                                <option value="<?= e($k) ?>" <?= ($mat['basis'] ?? 'pbt') === $k ? 'selected' : '' ?>>
                                    <?= e($meta['label']) ?> — <?= number_format($basisAmts[$k], 2) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <div class="grid grid-cols-3 gap-3">
                        <label class="block">
                            <span class="text-xs font-medium text-slate-700">Planning %</span>
                            <input type="number" step="0.01" name="planning_pct" <?= $canEdit ? '' : 'disabled' ?>
                                   value="<?= e($mat['planning_pct'] ?? 5) ?>"
                                   class="mt-1 w-full rounded border border-slate-300 px-2 py-1.5 text-sm">
                        </label>
                        <label class="block">
                            <span class="text-xs font-medium text-slate-700">Performance %</span>
                            <input type="number" step="0.01" name="performance_pct" <?= $canEdit ? '' : 'disabled' ?>
                                   value="<?= e($mat['performance_pct'] ?? 75) ?>"
                                   class="mt-1 w-full rounded border border-slate-300 px-2 py-1.5 text-sm">
                        </label>
                        <label class="block">
                            <span class="text-xs font-medium text-slate-700">Trivial %</span>
                            <input type="number" step="0.01" name="ctt_pct" <?= $canEdit ? '' : 'disabled' ?>
                                   value="<?= e($mat['ctt_pct'] ?? 5) ?>"
                                   class="mt-1 w-full rounded border border-slate-300 px-2 py-1.5 text-sm">
                        </label>
                    </div>
                    <label class="block">
                        <span class="text-sm font-medium text-slate-700">Rationale</span>
                        <textarea name="rationale" rows="2" <?= $canEdit ? '' : 'disabled' ?>
                                  class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"><?= e($mat['rationale'] ?? '') ?></textarea>
                    </label>
                    <?php if ($canEdit): ?>
                        <button class="rounded bg-brand-600 hover:bg-brand-700 text-white px-4 py-2 text-sm font-medium">
                            Save materiality
                        </button>
                    <?php endif; ?>
                </form>
                <?php endif; ?>

                <?php if ($mat): ?>
                    <div class="mt-5 pt-4 border-t border-slate-200 space-y-2 text-sm">
                        <div class="flex justify-between">
                            <span class="text-slate-600">Benchmark (<?= e($bases[$mat['basis']]['label'] ?? $mat['basis']) ?>)</span>
                            <span class="font-medium tabular-nums"><?= number_format((float) $mat['basis_amount'], 2) ?></span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-slate-600">Planning materiality (<?= rtrim(rtrim((string)$mat['planning_pct'],'0'),'.') ?>%)</span>
                            <span class="font-semibold text-brand-700 tabular-nums"><?= number_format((float) $mat['planning_amount'], 2) ?></span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-slate-600">Performance materiality (<?= rtrim(rtrim((string)$mat['performance_pct'],'0'),'.') ?>%)</span>
                            <span class="font-medium tabular-nums"><?= number_format((float) $mat['performance_amount'], 2) ?></span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-slate-600">Clearly trivial threshold (<?= rtrim(rtrim((string)$mat['ctt_pct'],'0'),'.') ?>%)</span>
                            <span class="font-medium tabular-nums"><?= number_format((float) $mat['ctt_amount'], 2) ?></span>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Ratios -->
        <div class="bg-white rounded-lg border border-slate-200 overflow-hidden">
            <div class="px-5 py-3 border-b border-slate-200">
                <h3 class="font-semibold text-slate-900">Going-concern &amp; health ratios</h3>
                <p class="text-xs text-slate-500">Computed from current vs prior trial balance. Concern flags in red.</p>
            </div>
            <table class="table-app">
                <thead>
                    <tr><th>Ratio</th><th class="text-right">Current</th><th class="text-right">Prior</th></tr>
                </thead>
                <tbody>
                <?php foreach ($ratios as $r): ?>
                    <tr title="<?= e($r['hint']) ?>">
                        <td>
                            <?= e($r['label']) ?>
                            <?php if ($r['concern']): ?>
                                <span class="ml-1 text-[10px] uppercase text-rose-700 font-semibold">flag</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-right tabular-nums <?= $r['concern'] ? 'text-rose-700 font-medium' : '' ?>">
                            <?= e($fmtRatio($r['cur'], $r['unit'])) ?>
                        </td>
                        <td class="text-right tabular-nums text-slate-500">
                            <?= e($fmtRatio($r['pri'], $r['unit'])) ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <p class="px-5 py-3 text-xs text-slate-400">Hover a row for the formula and interpretation.</p>
        </div>
    </div>

    <p class="mt-4 text-xs text-slate-500">
        Aging analysis (debtor / creditor 30/60/90/120+) needs an open-item aging listing — upload it as a document and it can be wired in a later build.
    </p>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
