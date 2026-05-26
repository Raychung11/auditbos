<?php
/**
 * /audit/gl_analytics.php?engagement_id=…
 *
 * General-ledger analytics & exception testing. Read-only screens over
 * the imported GL: duplicates, round numbers, weekend postings,
 * outliers, Benford, and account concentration. Each engagement can run
 * an AI summary of the exceptions via the "Analyse with AI" button.
 */

declare(strict_types=1);
require_once __DIR__ . '/../includes/auth_guard.php';
require_role(['firm_admin','audit_manager','senior_auditor','junior_auditor','reviewer']);
require_once __DIR__ . '/../includes/gl_analytics.php';

$pdo    = db();
$firmId = current_firm_id();
$engagementId = isset($_GET['engagement_id']) ? (int) $_GET['engagement_id'] : 0;

// ---------------------------------------------------------------------
// Engagement picker.
// ---------------------------------------------------------------------
if ($engagementId <= 0) {
    $list = $pdo->prepare(
        'SELECT e.id, e.financial_year, e.engagement_type, e.status, c.company_name,
                (SELECT COUNT(*) FROM general_ledgers gl WHERE gl.engagement_id = e.id) AS gl_rows
           FROM engagements e
           JOIN clients c ON c.id = e.client_id
          WHERE e.firm_id = :f AND e.status NOT IN ("archived")
          ORDER BY c.company_name, e.financial_year DESC'
    );
    $list->execute([':f' => $firmId]);
    $engagements = $list->fetchAll();

    $pageTitle = 'GL Analytics';
    require __DIR__ . '/../includes/header.php';
    ?>
    <div class="bg-white rounded-lg border border-slate-200 overflow-hidden">
        <div class="px-5 py-3 border-b border-slate-200">
            <h3 class="font-semibold text-slate-900">Pick an engagement</h3>
            <p class="text-xs text-slate-500 mt-0.5">Analytics run over the engagement's imported general ledger.</p>
        </div>
        <?php if (empty($engagements)): ?>
            <div class="p-8 text-center text-sm text-slate-500">No engagements available.</div>
        <?php else: ?>
            <table class="table-app">
                <thead><tr><th>Client</th><th>FY</th><th>Status</th><th class="text-right">GL rows</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($engagements as $e): ?>
                    <tr>
                        <td class="font-medium"><?= e($e['company_name']) ?></td>
                        <td><?= e($e['financial_year']) ?></td>
                        <td><?= badge($e['status']) ?></td>
                        <td class="text-right tabular-nums"><?= number_format((int) $e['gl_rows']) ?></td>
                        <td class="text-right">
                            <a href="/audit/gl_analytics.php?engagement_id=<?= (int) $e['id'] ?>"
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
    redirect('/audit/gl_analytics.php');
}

$a = gl_run_all($engagementId);
$summary = $a['summary'];

$pageTitle = 'GL Analytics — ' . $engagement['company_name'];
require __DIR__ . '/../includes/header.php';

// Small helper for rendering a row's amount.
$amt = static fn($v) => $v > 0 ? number_format((float) $v, 2) : '—';
?>

<a href="/audit/gl_analytics.php" class="text-sm text-brand-600 hover:underline">&larr; Change engagement</a>
<a href="/firm/engagement_view.php?id=<?= (int) $engagementId ?>"
   class="ml-3 text-sm text-brand-600 hover:underline">Engagement workspace</a>

<div class="flex flex-wrap items-end justify-between gap-3 mt-1 mb-5">
    <div>
        <h2 class="text-xl font-semibold text-slate-900"><?= e($engagement['company_name']) ?> — GL Analytics</h2>
        <p class="text-sm text-slate-500"><?= e($engagement['financial_year']) ?></p>
    </div>
    <?php if (defined('AI_ENABLED') && AI_ENABLED && $summary['rows'] > 0): ?>
        <a href="/ai/run.php?fn=ai_analyze_gl_exceptions&engagement_id=<?= (int) $engagementId ?>"
           class="rounded bg-brand-600 hover:bg-brand-700 text-white px-3 py-1.5 text-sm">
            Analyse with AI
        </a>
    <?php endif; ?>
</div>

<?php if ($summary['rows'] === 0): ?>
    <div class="bg-white rounded-lg border border-slate-200 p-8 text-center text-sm text-slate-500">
        No general ledger imported for this engagement.
        <a href="/import/general_ledger.php?engagement_id=<?= (int) $engagementId ?>"
           class="text-brand-600 hover:underline">Import one now</a>.
    </div>
<?php else: ?>

    <!-- Summary cards -->
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
        <div class="bg-white rounded-lg border border-slate-200 p-4">
            <div class="text-xs uppercase tracking-wide text-slate-500">GL transactions</div>
            <div class="text-2xl font-semibold"><?= number_format($summary['rows']) ?></div>
        </div>
        <div class="bg-white rounded-lg border border-slate-200 p-4">
            <div class="text-xs uppercase tracking-wide text-slate-500">Accounts</div>
            <div class="text-2xl font-semibold"><?= number_format($summary['accounts']) ?></div>
        </div>
        <div class="bg-white rounded-lg border border-slate-200 p-4">
            <div class="text-xs uppercase tracking-wide text-slate-500">Total debits</div>
            <div class="text-2xl font-semibold"><?= number_format($summary['total_debit'], 2) ?></div>
        </div>
        <div class="bg-white rounded-lg border border-slate-200 p-4">
            <div class="text-xs uppercase tracking-wide text-slate-500">Period</div>
            <div class="text-sm font-medium mt-1">
                <?= e(datefmt($summary['date_min'])) ?> –<br><?= e(datefmt($summary['date_max'])) ?>
            </div>
        </div>
    </div>

    <!-- Exception count strip -->
    <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-8">
        <?php
        $balanced = abs($summary['total_debit'] - $summary['total_credit']) < 0.5;
        $flags = [
            ['Potential duplicates', count($a['duplicates']), 'rose',   '#duplicates'],
            ['Round-number postings', count($a['round']),     'amber',  '#round'],
            ['Weekend postings',      count($a['weekend']),   'amber',  '#weekend'],
            ['Benford max deviation', round($a['benford']['max_deviation'], 1) . '%', $a['benford']['max_deviation'] > 5 ? 'rose' : 'emerald', '#benford'],
        ];
        $toneCls = ['rose'=>'text-rose-700','amber'=>'text-amber-700','emerald'=>'text-emerald-700'];
        foreach ($flags as [$label, $val, $tone, $anchor]):
        ?>
            <a href="<?= $anchor ?>" class="bg-white rounded-lg border border-slate-200 p-4 hover:shadow-md transition block">
                <div class="text-xs uppercase tracking-wide text-slate-500"><?= e($label) ?></div>
                <div class="text-2xl font-semibold <?= $toneCls[$tone] ?>"><?= e((string) $val) ?></div>
            </a>
        <?php endforeach; ?>
    </div>
    <?php if (!$balanced): ?>
        <div class="mb-6 rounded border border-amber-200 bg-amber-50 px-4 py-2 text-sm text-amber-800">
            GL is not in balance — total debits (<?= number_format($summary['total_debit'], 2) ?>)
            ≠ total credits (<?= number_format($summary['total_credit'], 2) ?>).
            Difference <?= number_format($summary['total_debit'] - $summary['total_credit'], 2) ?>.
        </div>
    <?php endif; ?>

    <!-- Duplicates -->
    <section id="duplicates" class="bg-white rounded-lg border border-slate-200 overflow-hidden mb-6">
        <div class="px-5 py-3 border-b border-slate-200">
            <h3 class="font-semibold text-slate-900">Potential duplicate payments</h3>
            <p class="text-xs text-slate-500">Same account + same debit amount on the same date, posted more than once.</p>
        </div>
        <?php if (empty($a['duplicates'])): ?>
            <div class="p-6 text-center text-sm text-slate-500">No duplicates found.</div>
        <?php else: ?>
            <table class="table-app">
                <thead><tr><th>Date</th><th>Account</th><th class="text-right">Amount</th><th class="text-right">Times</th><th>References</th></tr></thead>
                <tbody>
                <?php foreach ($a['duplicates'] as $d): ?>
                    <tr>
                        <td class="text-xs"><?= e(datefmt($d['transaction_date'])) ?></td>
                        <td><span class="font-mono text-xs"><?= e($d['account_code']) ?></span> <?= e($d['account_name'] ?? '') ?></td>
                        <td class="text-right tabular-nums text-rose-700 font-medium"><?= number_format((float) $d['debit'], 2) ?></td>
                        <td class="text-right tabular-nums"><?= (int) $d['occurrences'] ?></td>
                        <td class="text-xs text-slate-500"><?= e($d['refs']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>

    <!-- Round numbers -->
    <section id="round" class="bg-white rounded-lg border border-slate-200 overflow-hidden mb-6">
        <div class="px-5 py-3 border-b border-slate-200">
            <h3 class="font-semibold text-slate-900">Round-number postings</h3>
            <p class="text-xs text-slate-500">Exact multiples of 1,000 (≥ 1,000) — often estimates or manual journals.</p>
        </div>
        <?php if (empty($a['round'])): ?>
            <div class="p-6 text-center text-sm text-slate-500">None found.</div>
        <?php else: ?>
            <table class="table-app">
                <thead><tr><th>Date</th><th>Account</th><th>Reference</th><th>Description</th><th class="text-right">Amount</th></tr></thead>
                <tbody>
                <?php foreach ($a['round'] as $r): ?>
                    <tr>
                        <td class="text-xs"><?= e(datefmt($r['transaction_date'])) ?></td>
                        <td><span class="font-mono text-xs"><?= e($r['account_code']) ?></span> <?= e($r['account_name'] ?? '') ?></td>
                        <td class="text-xs"><?= e($r['reference_no'] ?? '—') ?></td>
                        <td class="text-xs text-slate-500"><?= e($r['description'] ?? '') ?></td>
                        <td class="text-right tabular-nums"><?= number_format((float) $r['amount'], 2) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>

    <!-- Weekend -->
    <section id="weekend" class="bg-white rounded-lg border border-slate-200 overflow-hidden mb-6">
        <div class="px-5 py-3 border-b border-slate-200">
            <h3 class="font-semibold text-slate-900">Weekend postings</h3>
            <p class="text-xs text-slate-500">Transactions dated on a Saturday or Sunday.</p>
        </div>
        <?php if (empty($a['weekend'])): ?>
            <div class="p-6 text-center text-sm text-slate-500">None found.</div>
        <?php else: ?>
            <table class="table-app">
                <thead><tr><th>Date</th><th>Day</th><th>Account</th><th>Reference</th><th class="text-right">Debit</th><th class="text-right">Credit</th></tr></thead>
                <tbody>
                <?php foreach ($a['weekend'] as $w): ?>
                    <tr>
                        <td class="text-xs"><?= e(datefmt($w['transaction_date'])) ?></td>
                        <td class="text-xs text-amber-700"><?= e($w['day_name']) ?></td>
                        <td><span class="font-mono text-xs"><?= e($w['account_code']) ?></span> <?= e($w['account_name'] ?? '') ?></td>
                        <td class="text-xs"><?= e($w['reference_no'] ?? '—') ?></td>
                        <td class="text-right tabular-nums"><?= $amt($w['debit']) ?></td>
                        <td class="text-right tabular-nums"><?= $amt($w['credit']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
        <!-- Outliers -->
        <section class="bg-white rounded-lg border border-slate-200 overflow-hidden">
            <div class="px-5 py-3 border-b border-slate-200">
                <h3 class="font-semibold text-slate-900">Largest transactions</h3>
            </div>
            <table class="table-app">
                <thead><tr><th>Date</th><th>Account</th><th class="text-right">Amount</th></tr></thead>
                <tbody>
                <?php foreach ($a['outliers'] as $o): ?>
                    <tr>
                        <td class="text-xs"><?= e(datefmt($o['transaction_date'])) ?></td>
                        <td class="text-xs"><span class="font-mono"><?= e($o['account_code']) ?></span> <?= e($o['account_name'] ?? '') ?></td>
                        <td class="text-right tabular-nums"><?= number_format((float) $o['amount'], 2) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </section>

        <!-- Top accounts -->
        <section class="bg-white rounded-lg border border-slate-200 overflow-hidden">
            <div class="px-5 py-3 border-b border-slate-200">
                <h3 class="font-semibold text-slate-900">Account concentration</h3>
                <p class="text-xs text-slate-500">Top accounts by total value (Dr + Cr).</p>
            </div>
            <table class="table-app">
                <thead><tr><th>Account</th><th class="text-right">Txns</th><th class="text-right">Total value</th></tr></thead>
                <tbody>
                <?php foreach ($a['top'] as $t): ?>
                    <tr>
                        <td class="text-xs"><span class="font-mono"><?= e($t['account_code']) ?></span> <?= e($t['account_name'] ?? '') ?></td>
                        <td class="text-right tabular-nums"><?= number_format((int) $t['txns']) ?></td>
                        <td class="text-right tabular-nums"><?= number_format((float) $t['total_value'], 2) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </section>
    </div>

    <!-- Benford -->
    <section id="benford" class="bg-white rounded-lg border border-slate-200 overflow-hidden mb-6">
        <div class="px-5 py-3 border-b border-slate-200">
            <h3 class="font-semibold text-slate-900">Benford's Law — first-digit analysis</h3>
            <p class="text-xs text-slate-500">
                Sample: <?= number_format($a['benford']['sample']) ?> amounts.
                Large deviations from the expected curve can indicate fabricated or manipulated figures.
            </p>
        </div>
        <div class="p-5 space-y-2">
            <?php foreach ($a['benford']['rows'] as $b):
                $obs = $b['observed_pct']; $exp = $b['expected_pct'];
                $dev = $b['deviation'];
                $devCls = abs($dev) > 5 ? 'text-rose-700 font-medium' : 'text-slate-500';
            ?>
                <div>
                    <div class="flex items-center justify-between text-xs mb-1">
                        <span class="font-medium">Digit <?= (int) $b['digit'] ?></span>
                        <span class="<?= $devCls ?>">
                            <?= number_format($obs, 1) ?>% observed · <?= number_format($exp, 1) ?>% expected
                            (<?= ($dev >= 0 ? '+' : '') . number_format($dev, 1) ?>%)
                        </span>
                    </div>
                    <div class="relative w-full bg-slate-100 rounded h-3">
                        <!-- expected marker -->
                        <div class="absolute top-0 bottom-0 w-px bg-slate-400"
                             style="left: <?= min(100, $exp * 3) ?>%" title="expected"></div>
                        <!-- observed bar -->
                        <div class="bg-brand-500 h-3 rounded" style="width: <?= min(100, $obs * 3) ?>%"></div>
                    </div>
                </div>
            <?php endforeach; ?>
            <p class="text-xs text-slate-400 mt-2">Bars scaled ×3 for visibility. Vertical line = Benford-expected percentage.</p>
        </div>
    </section>

    <p class="text-xs text-slate-500">
        These are screening procedures, not conclusions. Investigate flagged items before drawing inferences.
    </p>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
