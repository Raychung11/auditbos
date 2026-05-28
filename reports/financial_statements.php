<?php
/**
 * /reports/financial_statements.php?engagement_id=<id>[&export=csv&fs=sofp|soci]
 *
 * Renders the Statement of Financial Position and Statement of
 * Comprehensive Income from the imported trial balance. Supports:
 *   - HTML view (default)
 *   - CSV export per statement (?export=csv&fs=sofp or fs=soci)
 *   - Print-to-PDF via the browser's native print dialog (existing
 *     @media print CSS suppresses chrome and prints clean tables).
 */

declare(strict_types=1);
require_once __DIR__ . '/../includes/auth_guard.php';
require_role(['firm_admin','audit_manager','senior_auditor','junior_auditor','reviewer']);
require_once __DIR__ . '/../includes/fs_builder.php';

$pdo    = db();
$firmId = current_firm_id();
$engagementId = isset($_GET['engagement_id']) ? (int) $_GET['engagement_id'] : 0;
$showAccounts = !empty($_GET['detail']);

// ---------------------------------------------------------------------
// Engagement picker (when no id supplied).
// ---------------------------------------------------------------------
if ($engagementId <= 0) {
    $list = $pdo->prepare(
        'SELECT e.id, e.financial_year, e.engagement_type, e.status, c.company_name
           FROM engagements e
           JOIN clients c ON c.id = e.client_id
          WHERE e.firm_id = :f AND e.status NOT IN ("archived")
          ORDER BY c.company_name, e.financial_year DESC'
    );
    $list->execute([':f' => $firmId]);
    $engagements = $list->fetchAll();

    $pageTitle = 'Financial Statements';
    require __DIR__ . '/../includes/header.php';
    ?>
    <div class="bg-white rounded-lg border border-slate-200 overflow-hidden">
        <div class="px-5 py-3 border-b border-slate-200">
            <h3 class="font-semibold text-slate-900">Pick an engagement</h3>
            <p class="text-xs text-slate-500 mt-0.5">
                The SOFP and SOCI are generated directly from this engagement's imported trial balance.
            </p>
        </div>
        <?php if (empty($engagements)): ?>
            <div class="p-8 text-center text-sm text-slate-500">
                No engagements available.
            </div>
        <?php else: ?>
            <table class="table-app">
                <thead>
                    <tr><th>Client</th><th>FY</th><th>Type</th><th>Status</th><th></th></tr>
                </thead>
                <tbody>
                <?php foreach ($engagements as $e): ?>
                    <tr>
                        <td class="font-medium"><?= e($e['company_name']) ?></td>
                        <td><?= e($e['financial_year']) ?></td>
                        <td><?= e(ucfirst($e['engagement_type'])) ?></td>
                        <td><?= badge($e['status']) ?></td>
                        <td class="text-right">
                            <a href="/reports/financial_statements.php?engagement_id=<?= (int) $e['id'] ?>"
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
// Load + scope-check engagement.
// ---------------------------------------------------------------------
$stmt = $pdo->prepare(
    'SELECT e.*, c.company_name, c.registration_no, c.financial_year_end
       FROM engagements e
       JOIN clients c ON c.id = e.client_id
      WHERE e.id = :id AND e.firm_id = :fid'
);
$stmt->execute([':id'=>$engagementId, ':fid'=>$firmId]);
$engagement = $stmt->fetch();
if (!$engagement) {
    flash('error', 'Engagement not found.');
    redirect('/reports/financial_statements.php');
}

$sofp = fs_build_sofp($engagementId);
$soci = fs_build_soci($engagementId);

// ---------------------------------------------------------------------
// CSV export — emits one statement and exits.
// ---------------------------------------------------------------------
if (($_GET['export'] ?? '') === 'csv') {
    $which = $_GET['fs'] ?? 'sofp';
    $stamp = date('Ymd');
    $safe  = preg_replace('/[^A-Za-z0-9._-]/', '_', $engagement['company_name'] . '_' . $engagement['financial_year']);
    header('Content-Type: text/csv; charset=utf-8');
    header("Content-Disposition: attachment; filename=\"{$safe}_{$which}_{$stamp}.csv\"");
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Label', 'Current period', 'Prior period']);
    if ($which === 'sofp') {
        foreach ($sofp['sections'] as $sec) {
            fputcsv($out, [$sec['heading'], '', '']);
            foreach ($sec['groups'] as $g) {
                fputcsv($out, ['  ' . $g['label'],
                    number_format($g['cur'], 2, '.', ''),
                    number_format($g['pri'], 2, '.', '')]);
                if ($showAccounts) {
                    foreach ($g['accounts'] as $a) {
                        fputcsv($out, ['    ' . $a['code'] . ' ' . $a['name'],
                            number_format($a['cur'], 2, '.', ''),
                            number_format($a['pri'], 2, '.', '')]);
                    }
                }
            }
            fputcsv($out, [$sec['subtotal_label'],
                number_format($sec['subtotal_cur'], 2, '.', ''),
                number_format($sec['subtotal_pri'], 2, '.', '')]);
        }
        fputcsv($out, ['Total assets',
            number_format($sofp['total_assets_cur'], 2, '.', ''),
            number_format($sofp['total_assets_pri'], 2, '.', '')]);
        fputcsv($out, ['Total equity & liabilities',
            number_format($sofp['total_equity_liab_cur'], 2, '.', ''),
            number_format($sofp['total_equity_liab_pri'], 2, '.', '')]);
    } else {
        foreach ($soci['lines'] as $ln) {
            fputcsv($out, [$ln['label'],
                number_format($ln['cur'], 2, '.', ''),
                number_format($ln['pri'], 2, '.', '')]);
        }
    }
    fclose($out);
    exit;
}

$pageTitle = 'Financial Statements — ' . $engagement['company_name'];
require __DIR__ . '/../includes/header.php';
?>

<style>
    @media print {
        .fs-no-print { display: none !important; }
        body { background: white; }
        .fs-table th, .fs-table td { padding: 4px 8px; }
    }
    .fs-table { width: 100%; }
    .fs-table th, .fs-table td { padding: 8px 12px; }
    .fs-table th { font-size: 11px; text-transform: uppercase; letter-spacing: 0.05em; color: rgb(71 85 105); border-bottom: 1px solid rgb(226 232 240); text-align: left; }
    .fs-table td.num, .fs-table th.num { text-align: right; font-variant-numeric: tabular-nums; }
    .fs-table tr.row-group td { font-weight: 500; }
    .fs-table tr.row-account td { color: rgb(71 85 105); font-size: 13px; padding-left: 24px; }
    .fs-table tr.row-account td:first-child { padding-left: 36px; }
    .fs-table tr.row-section td { font-weight: 600; padding-top: 16px; color: rgb(15 23 42); }
    .fs-table tr.row-subtotal td { border-top: 1px solid rgb(226 232 240); font-weight: 600; }
    .fs-table tr.row-grand td { border-top: 2px solid rgb(15 23 42); border-bottom: 2px solid rgb(15 23 42); font-weight: 700; font-size: 15px; }
    .fs-table tr.row-total td { font-weight: 700; }
</style>

<a href="/reports/financial_statements.php" class="fs-no-print text-sm text-brand-600 hover:underline">&larr; Change engagement</a>
<a href="/firm/engagement_view.php?id=<?= (int) $engagementId ?>"
   class="fs-no-print ml-3 text-sm text-brand-600 hover:underline">Engagement workspace</a>

<div class="flex flex-wrap items-end justify-between gap-3 mt-1 mb-5">
    <div>
        <h2 class="text-2xl font-semibold text-slate-900"><?= e($engagement['company_name']) ?></h2>
        <p class="text-sm text-slate-500 mt-1">
            <?= e($engagement['registration_no'] ?? '') ?>
            · Financial year <?= e($engagement['financial_year']) ?>
            <?php if ($engagement['period_start'] || $engagement['period_end']): ?>
                · <?= e(datefmt($engagement['period_start'])) ?> to <?= e(datefmt($engagement['period_end'])) ?>
            <?php endif; ?>
        </p>
    </div>
    <div class="fs-no-print flex flex-wrap items-center gap-2">
        <a href="?engagement_id=<?= (int) $engagementId ?><?= $showAccounts ? '' : '&detail=1' ?>"
           class="rounded border border-slate-300 px-3 py-1.5 text-sm hover:bg-slate-50">
            <?= $showAccounts ? 'Hide account detail' : 'Show account detail' ?>
        </a>
        <a href="?engagement_id=<?= (int) $engagementId ?>&export=csv&fs=sofp<?= $showAccounts ? '&detail=1' : '' ?>"
           class="rounded border border-slate-300 px-3 py-1.5 text-sm hover:bg-slate-50">
            Export SOFP CSV
        </a>
        <a href="?engagement_id=<?= (int) $engagementId ?>&export=csv&fs=soci"
           class="rounded border border-slate-300 px-3 py-1.5 text-sm hover:bg-slate-50">
            Export SOCI CSV
        </a>
        <button onclick="window.print()" type="button"
                class="rounded bg-brand-600 hover:bg-brand-700 text-white px-3 py-1.5 text-sm">
            Print / PDF
        </button>
    </div>
</div>

<!-- SOFP -->
<div class="bg-white rounded-lg border border-slate-200 mb-8">
    <div class="px-5 py-3 border-b border-slate-200 flex items-center justify-between">
        <div>
            <h3 class="font-semibold text-slate-900">Statement of Financial Position</h3>
            <p class="text-xs text-slate-500">As at <?= e(datefmt($engagement['period_end'])) ?></p>
        </div>
    </div>
    <table class="fs-table">
        <thead>
            <tr>
                <th>&nbsp;</th>
                <th class="num">Current</th>
                <th class="num">Prior</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($sofp['sections'] as $sec): ?>
                <tr class="row-section">
                    <td><?= e($sec['heading']) ?></td><td></td><td></td>
                </tr>
                <?php foreach ($sec['groups'] as $g): ?>
                    <tr class="row-group">
                        <td><?= e($g['label']) ?></td>
                        <td class="num"><?= number_format($g['cur'], 2) ?></td>
                        <td class="num"><?= number_format($g['pri'], 2) ?></td>
                    </tr>
                    <?php if ($showAccounts && !empty($g['accounts'])): ?>
                        <?php foreach ($g['accounts'] as $a): ?>
                            <tr class="row-account">
                                <td><?= e($a['code']) ?> · <?= e($a['name']) ?></td>
                                <td class="num"><?= number_format($a['cur'], 2) ?></td>
                                <td class="num"><?= number_format($a['pri'], 2) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                <?php endforeach; ?>
                <tr class="row-subtotal">
                    <td><?= e($sec['subtotal_label']) ?></td>
                    <td class="num"><?= number_format($sec['subtotal_cur'], 2) ?></td>
                    <td class="num"><?= number_format($sec['subtotal_pri'], 2) ?></td>
                </tr>
            <?php endforeach; ?>
            <tr class="row-grand">
                <td>Total assets</td>
                <td class="num"><?= number_format($sofp['total_assets_cur'], 2) ?></td>
                <td class="num"><?= number_format($sofp['total_assets_pri'], 2) ?></td>
            </tr>
            <tr class="row-grand">
                <td>Total equity and liabilities</td>
                <td class="num"><?= number_format($sofp['total_equity_liab_cur'], 2) ?></td>
                <td class="num"><?= number_format($sofp['total_equity_liab_pri'], 2) ?></td>
            </tr>
            <?php
                $diffCur = $sofp['balance_diff_cur'];
                $diffPri = $sofp['balance_diff_pri'];
                $tolerance = 0.5;
                if (abs($diffCur) > $tolerance || abs($diffPri) > $tolerance):
            ?>
                <tr>
                    <td colspan="3" class="bg-rose-50 text-rose-800 text-sm">
                        ⚠ Balance check: Δ current = <?= number_format($diffCur, 2) ?>,
                        Δ prior = <?= number_format($diffPri, 2) ?>.
                        Likely caused by retained-earnings rollforward, unposted P&L,
                        or unmapped accounts (see table below).
                    </td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- SOCI -->
<div class="bg-white rounded-lg border border-slate-200 mb-8">
    <div class="px-5 py-3 border-b border-slate-200">
        <h3 class="font-semibold text-slate-900">Statement of Comprehensive Income</h3>
        <p class="text-xs text-slate-500">For the period ending <?= e(datefmt($engagement['period_end'])) ?></p>
    </div>
    <table class="fs-table">
        <thead>
            <tr>
                <th>&nbsp;</th>
                <th class="num">Current</th>
                <th class="num">Prior</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($soci['lines'] as $ln): ?>
                <tr class="<?= $ln['is_total'] ? 'row-total' : 'row-group' ?>">
                    <td><?= e($ln['label']) ?></td>
                    <td class="num"><?= number_format($ln['cur'], 2) ?></td>
                    <td class="num"><?= number_format($ln['pri'], 2) ?></td>
                </tr>
                <?php if ($showAccounts && !$ln['is_total'] && !empty($ln['accounts'])): ?>
                    <?php foreach ($ln['accounts'] as $a): ?>
                        <tr class="row-account">
                            <td><?= e($a['code']) ?> · <?= e($a['name']) ?></td>
                            <td class="num"><?= number_format($a['cur'], 2) ?></td>
                            <td class="num"><?= number_format($a['pri'], 2) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php
$unmapped = array_merge($sofp['unmapped'], $soci['unmapped']);
if (!empty($unmapped)):
?>
    <div class="fs-no-print bg-amber-50 border border-amber-200 rounded-lg p-4 mb-6">
        <h4 class="font-semibold text-amber-900 mb-1">Unclassified accounts</h4>
        <p class="text-xs text-amber-800 mb-2">
            These accounts could not be mapped to a standard FS line by code or name.
            Rename them following the project CoA conventions
            (1xxx assets, 2xxx liabilities, 3xxx equity, 4xxx revenue, 5xxx COS,
            6xxx opex, 7xxx finance, 8xxx tax) and re-open this page.
        </p>
        <table class="text-xs w-full">
            <thead>
                <tr class="text-amber-900">
                    <th class="text-left py-1">Code</th>
                    <th class="text-left py-1">Name</th>
                    <th class="text-right py-1">Current</th>
                    <th class="text-right py-1">Prior</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($unmapped as $u): ?>
                <tr>
                    <td class="font-mono py-0.5"><?= e($u['code']) ?></td>
                    <td class="py-0.5"><?= e($u['name']) ?></td>
                    <td class="text-right tabular-nums py-0.5"><?= number_format($u['cur'], 2) ?></td>
                    <td class="text-right tabular-nums py-0.5"><?= number_format($u['pri'], 2) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<div class="fs-no-print text-xs text-slate-500">
    Auto-generated from the imported trial balance. v1 uses code + type
    heuristics (no per-account mapping UI yet). Profit-for-the-year is computed
    bottom-up from the SOCI lines.
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
