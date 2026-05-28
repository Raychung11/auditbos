<?php
/**
 * /audit/aging.php?engagement_id=…&type=debtor|creditor
 *
 * Debtor / creditor aging analysis: bucket summary, exposure analysis,
 * most-overdue parties, and an AI review (ECL focus for debtors,
 * overdue-creditor focus for creditors).
 */

declare(strict_types=1);
require_once __DIR__ . '/../includes/auth_guard.php';
require_once __DIR__ . '/../includes/workplan.php';
require_role(['firm_admin','audit_manager','senior_auditor','junior_auditor','reviewer']);
require_once __DIR__ . '/../includes/aging.php';

$pdo    = db();
$firmId = current_firm_id();
$engagementId = isset($_GET['engagement_id']) ? (int) $_GET['engagement_id'] : 0;
$type = ($_GET['type'] ?? 'debtor') === 'creditor' ? 'creditor' : 'debtor';

// Engagement picker
if ($engagementId <= 0) {
    $list = $pdo->prepare(
        'SELECT e.id, e.financial_year, e.status, c.company_name
           FROM engagements e JOIN clients c ON c.id = e.client_id
          WHERE e.firm_id = :f AND e.status NOT IN ("archived")
          ORDER BY c.company_name, e.financial_year DESC'
    );
    $list->execute([':f' => $firmId]);
    $engagements = $list->fetchAll();

    $pageTitle = 'Aging Analysis';
    require __DIR__ . '/../includes/header.php';
    ?>
    <div class="bg-white rounded-lg border border-slate-200 overflow-hidden">
        <div class="px-5 py-3 border-b border-slate-200"><h3 class="font-semibold text-slate-900">Pick an engagement</h3></div>
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
                            <a href="/audit/aging.php?engagement_id=<?= (int) $e['id'] ?>"
                               class="text-sm text-brand-600 hover:underline">Open</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
    <?php require __DIR__ . '/../includes/footer.php'; exit;
}

$stmt = $pdo->prepare(
    'SELECT e.*, c.company_name FROM engagements e
       JOIN clients c ON c.id = e.client_id
      WHERE e.id = :id AND e.firm_id = :fid'
);
$stmt->execute([':id'=>$engagementId, ':fid'=>$firmId]);
$engagement = $stmt->fetch();
if (!$engagement) { flash('error','Engagement not found.'); redirect('/audit/aging.php'); }

$summary   = aging_summary($engagementId, $type);
$top       = aging_top($engagementId, $type, 25);
$overdue   = aging_most_overdue($engagementId, $type, 15);
$label     = $type === 'debtor' ? 'Debtor (receivables)' : 'Creditor (payables)';

$pageTitle = 'Aging — ' . $engagement['company_name'];
require __DIR__ . '/../includes/header.php';

$pct = static fn(float $part, float $whole): string => $whole != 0.0 ? number_format($part/$whole*100, 1).'%' : '—';
?>

<a href="/audit/aging.php" class="text-sm text-brand-600 hover:underline">&larr; Change engagement</a>
<a href="/firm/engagement_view.php?id=<?= (int) $engagementId ?>"
   class="ml-3 text-sm text-brand-600 hover:underline">Engagement workspace</a>

<div class="mt-3">
<?php echo workplan_breadcrumb_html((int) $engagementId,
    workplan_step_for_context('module',
        $type === 'debtor' ? 'aging_debtor' : 'aging_creditor',
        (int) $engagementId)); ?>
</div>

<div class="flex flex-wrap items-end justify-between gap-3 mt-1 mb-4">
    <div>
        <h2 class="text-xl font-semibold text-slate-900"><?= e($engagement['company_name']) ?> — Aging</h2>
        <p class="text-sm text-slate-500"><?= e($engagement['financial_year']) ?></p>
    </div>
    <div class="flex items-center gap-2">
        <!-- Debtor / creditor toggle -->
        <div class="inline-flex rounded border border-slate-300 overflow-hidden text-sm">
            <a href="?engagement_id=<?= (int) $engagementId ?>&type=debtor"
               class="px-3 py-1.5 <?= $type === 'debtor' ? 'bg-brand-600 text-white' : 'bg-white text-slate-600 hover:bg-slate-50' ?>">Debtors</a>
            <a href="?engagement_id=<?= (int) $engagementId ?>&type=creditor"
               class="px-3 py-1.5 <?= $type === 'creditor' ? 'bg-brand-600 text-white' : 'bg-white text-slate-600 hover:bg-slate-50' ?>">Creditors</a>
        </div>
        <a href="/import/aging.php?engagement_id=<?= (int) $engagementId ?>&type=<?= e($type) ?>"
           class="rounded border border-slate-300 px-3 py-1.5 text-sm hover:bg-slate-50">Import listing</a>
        <?php if (defined('AI_ENABLED') && AI_ENABLED && $summary['has_data']): ?>
            <a href="/ai/run.php?fn=ai_analyze_aging&engagement_id=<?= (int) $engagementId ?>&aging_type=<?= e($type) ?>"
               class="rounded bg-brand-600 hover:bg-brand-700 text-white px-3 py-1.5 text-sm">AI review</a>
        <?php endif; ?>
    </div>
</div>

<?php if (!$summary['has_data']): ?>
    <div class="bg-white rounded-lg border border-slate-200 p-8 text-center text-sm text-slate-500">
        No <?= e($type) ?> aging imported yet.
        <a href="/import/aging.php?engagement_id=<?= (int) $engagementId ?>&type=<?= e($type) ?>"
           class="text-brand-600 hover:underline">Import a listing</a>.
    </div>
<?php else: ?>

    <!-- Summary cards -->
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
        <div class="bg-white rounded-lg border border-slate-200 p-4">
            <div class="text-xs uppercase tracking-wide text-slate-500"><?= e($label) ?> total</div>
            <div class="text-2xl font-semibold"><?= number_format($summary['total'], 2) ?></div>
            <div class="text-xs text-slate-500 mt-1"><?= (int) $summary['count'] ?> parties</div>
        </div>
        <div class="bg-white rounded-lg border border-slate-200 p-4">
            <div class="text-xs uppercase tracking-wide text-slate-500">Overdue</div>
            <div class="text-2xl font-semibold text-amber-700"><?= number_format($summary['overdue'], 2) ?></div>
            <div class="text-xs text-slate-500 mt-1"><?= $summary['overdue_pct'] !== null ? number_format($summary['overdue_pct'],1).'% of total' : '' ?></div>
        </div>
        <div class="bg-white rounded-lg border border-slate-200 p-4">
            <div class="text-xs uppercase tracking-wide text-slate-500">Over 90 days</div>
            <div class="text-2xl font-semibold text-rose-700"><?= number_format($summary['over_90'], 2) ?></div>
            <div class="text-xs text-slate-500 mt-1"><?= $summary['over_90_pct'] !== null ? number_format($summary['over_90_pct'],1).'% of total' : '' ?></div>
        </div>
        <div class="bg-white rounded-lg border border-slate-200 p-4">
            <div class="text-xs uppercase tracking-wide text-slate-500">Current (not due)</div>
            <div class="text-2xl font-semibold text-emerald-700"><?= number_format($summary['buckets']['current'], 2) ?></div>
            <div class="text-xs text-slate-500 mt-1"><?= $pct($summary['buckets']['current'], $summary['total']) ?> of total</div>
        </div>
    </div>

    <!-- Bucket distribution -->
    <div class="bg-white rounded-lg border border-slate-200 p-5 mb-6">
        <h3 class="font-semibold text-slate-900 mb-3">Aging distribution</h3>
        <?php
        $bk = $summary['buckets'];
        $segments = [
            ['Current',   $bk['current'],  'bg-emerald-500'],
            ['1–30',      $bk['d1_30'],    'bg-lime-500'],
            ['31–60',     $bk['d31_60'],   'bg-amber-500'],
            ['61–90',     $bk['d61_90'],   'bg-orange-500'],
            ['91–120',    $bk['d91_120'],  'bg-rose-500'],
            ['Over 120',  $bk['over_120'], 'bg-rose-700'],
        ];
        $tot = $summary['total'] ?: 1;
        ?>
        <div class="flex h-4 w-full overflow-hidden rounded mb-3">
            <?php foreach ($segments as [$lbl, $val, $cls]):
                $w = $val / $tot * 100; if ($w <= 0) continue; ?>
                <div class="<?= $cls ?>" style="width: <?= number_format($w, 3) ?>%"
                     title="<?= e($lbl) ?>: <?= number_format($val, 2) ?>"></div>
            <?php endforeach; ?>
        </div>
        <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-6 gap-2 text-xs">
            <?php foreach ($segments as [$lbl, $val, $cls]): ?>
                <div class="flex items-center gap-2">
                    <span class="inline-block w-2.5 h-2.5 rounded-sm <?= $cls ?>"></span>
                    <span class="text-slate-600"><?= e($lbl) ?></span>
                    <span class="ml-auto tabular-nums font-medium"><?= number_format($val, 0) ?></span>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <!-- Top exposures -->
        <div class="bg-white rounded-lg border border-slate-200 overflow-hidden">
            <div class="px-5 py-3 border-b border-slate-200"><h3 class="font-semibold text-slate-900">Largest balances</h3></div>
            <table class="table-app">
                <thead><tr><th>Party</th><th class="text-right">Total</th><th class="text-right">Over 90</th></tr></thead>
                <tbody>
                <?php foreach ($top as $t): ?>
                    <tr>
                        <td><?= e($t['party_name']) ?><?= $t['party_code'] ? ' <span class="text-xs text-slate-400">'.e($t['party_code']).'</span>' : '' ?></td>
                        <td class="text-right tabular-nums"><?= number_format((float)$t['total'], 2) ?></td>
                        <td class="text-right tabular-nums <?= (float)$t['over_90'] > 0 ? 'text-rose-700' : 'text-slate-400' ?>">
                            <?= (float)$t['over_90'] > 0 ? number_format((float)$t['over_90'], 2) : '—' ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Most overdue -->
        <div class="bg-white rounded-lg border border-slate-200 overflow-hidden">
            <div class="px-5 py-3 border-b border-slate-200">
                <h3 class="font-semibold text-slate-900">Most overdue (over 90 days)</h3>
                <p class="text-xs text-slate-500"><?= $type === 'debtor' ? 'ECL / recoverability focus' : 'Disputed or long-outstanding payables' ?></p>
            </div>
            <?php if (empty($overdue)): ?>
                <div class="p-6 text-center text-sm text-slate-500">Nothing over 90 days.</div>
            <?php else: ?>
                <table class="table-app">
                    <thead><tr><th>Party</th><th class="text-right">Over 90</th><th class="text-right">Of which 120+</th></tr></thead>
                    <tbody>
                    <?php foreach ($overdue as $o): ?>
                        <tr>
                            <td><?= e($o['party_name']) ?></td>
                            <td class="text-right tabular-nums text-rose-700 font-medium"><?= number_format((float)$o['over_90'], 2) ?></td>
                            <td class="text-right tabular-nums"><?= number_format((float)$o['days_over_120'], 2) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
