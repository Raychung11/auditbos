<?php
/**
 * /import/trial_balance_view.php
 *
 * Trial balance display for an engagement, showing current vs prior side
 * by side with absolute and percentage variance per account. This is the
 * data foundation the AI variance-analysis function reads from.
 */

declare(strict_types=1);
require_once __DIR__ . '/../includes/auth_guard.php';
require_role(['firm_admin','audit_manager','senior_auditor','junior_auditor','reviewer']);

$pdo    = db();
$firmId = current_firm_id();
$engagementId = isset($_GET['engagement_id']) ? (int) $_GET['engagement_id'] : 0;
$thresholdPct = isset($_GET['threshold']) ? max(0, (int) $_GET['threshold']) : 10;

if ($engagementId <= 0) {
    flash('error', 'Pick an engagement first.');
    redirect('/import/index.php');
}

// Scope check
$stmt = $pdo->prepare(
    'SELECT e.*, c.company_name FROM engagements e
       JOIN clients c ON c.id = e.client_id
      WHERE e.id = :id AND e.firm_id = :fid'
);
$stmt->execute([':id'=>$engagementId, ':fid'=>$firmId]);
$engagement = $stmt->fetch();
if (!$engagement) {
    flash('error', 'Engagement not found.');
    redirect('/import/index.php');
}

// Pivot current & prior period rows into one result set keyed by account_code.
$stmt = $pdo->prepare(
    'SELECT
        COALESCE(cur.account_code, pri.account_code) AS account_code,
        COALESCE(cur.account_name, pri.account_name) AS account_name,
        cur.debit   AS cur_debit,
        cur.credit  AS cur_credit,
        cur.balance AS cur_balance,
        pri.debit   AS pri_debit,
        pri.credit  AS pri_credit,
        pri.balance AS pri_balance
       FROM (
              SELECT account_code, account_name, debit, credit, balance
                FROM trial_balances
               WHERE engagement_id = :e AND period = "current"
            ) cur
       LEFT JOIN (
              SELECT account_code, account_name, debit, credit, balance
                FROM trial_balances
               WHERE engagement_id = :e2 AND period = "prior"
            ) pri ON pri.account_code = cur.account_code
     UNION
     SELECT
        pri.account_code, pri.account_name,
        cur.debit, cur.credit, cur.balance,
        pri.debit, pri.credit, pri.balance
       FROM (
              SELECT account_code, account_name, debit, credit, balance
                FROM trial_balances
               WHERE engagement_id = :e3 AND period = "prior"
            ) pri
       LEFT JOIN (
              SELECT account_code, account_name, debit, credit, balance
                FROM trial_balances
               WHERE engagement_id = :e4 AND period = "current"
            ) cur ON cur.account_code = pri.account_code
      WHERE cur.account_code IS NULL
      ORDER BY account_code'
);
$stmt->execute([':e'=>$engagementId, ':e2'=>$engagementId, ':e3'=>$engagementId, ':e4'=>$engagementId]);
$rows = $stmt->fetchAll();

// Totals + flag rows whose movement >= threshold %.
$totals = ['cur_debit'=>0.0, 'cur_credit'=>0.0, 'pri_debit'=>0.0, 'pri_credit'=>0.0,
           'cur_balance'=>0.0, 'pri_balance'=>0.0];
foreach ($rows as &$row) {
    $cur = (float) ($row['cur_balance'] ?? 0);
    $pri = (float) ($row['pri_balance'] ?? 0);
    $diff = $cur - $pri;
    $pct  = $pri != 0 ? ($diff / abs($pri)) * 100 : ($cur != 0 ? null : 0);
    $row['_diff']    = $diff;
    $row['_pct']     = $pct;
    $row['_flagged'] = $pct === null
        ? ($cur != 0)
        : (abs($pct) >= $thresholdPct);
    foreach (['cur_debit','cur_credit','pri_debit','pri_credit','cur_balance','pri_balance'] as $k) {
        $totals[$k] += (float) ($row[$k] ?? 0);
    }
}
unset($row);

$flaggedCount = count(array_filter($rows, fn($r) => !empty($r['_flagged'])));

$pageTitle = 'Trial Balance · Variance';
require __DIR__ . '/../includes/header.php';
?>

<a href="/import/index.php?engagement_id=<?= (int) $engagementId ?>"
   class="text-sm text-brand-600 hover:underline">&larr; Back</a>
<div class="flex flex-wrap items-end justify-between gap-3 mt-1 mb-5">
    <div>
        <h2 class="text-xl font-semibold text-slate-900">
            <?= e($engagement['company_name']) ?> — Trial Balance
        </h2>
        <p class="text-sm text-slate-500">
            <?= e($engagement['financial_year']) ?>
            · <?= count($rows) ?> account(s)
            · <?= $flaggedCount ?> flagged (movement &ge; <?= (int) $thresholdPct ?>%)
        </p>
    </div>
    <form method="get" class="flex items-center gap-2 text-sm">
        <input type="hidden" name="engagement_id" value="<?= (int) $engagementId ?>">
        <label class="text-slate-600">Flag threshold</label>
        <input type="number" name="threshold" min="0" max="500"
               value="<?= (int) $thresholdPct ?>"
               class="w-20 rounded border border-slate-300 px-2 py-1 text-sm">
        <span class="text-slate-500">%</span>
        <button class="rounded border border-slate-300 px-2 py-1 hover:bg-slate-50">Apply</button>
        <a href="/ai/run.php?fn=ai_analyze_trial_balance&amp;engagement_id=<?= (int) $engagementId ?>"
           class="ml-2 rounded bg-brand-600 hover:bg-brand-700 text-white px-3 py-1.5 text-sm">
            AI variance analysis
        </a>
    </form>
</div>

<?php if (empty($rows)): ?>
    <div class="bg-white rounded-lg border border-slate-200 p-8 text-center text-sm text-slate-500">
        No trial balance imported yet for this engagement.
        <a href="/import/trial_balance.php?engagement_id=<?= (int) $engagementId ?>"
           class="text-brand-600 hover:underline">Import one now</a>.
    </div>
<?php else: ?>
    <div class="bg-white rounded-lg border border-slate-200 overflow-x-auto">
        <table class="table-app text-sm">
            <thead>
                <tr>
                    <th>Code</th>
                    <th>Account</th>
                    <th class="text-right">Current balance</th>
                    <th class="text-right">Prior balance</th>
                    <th class="text-right">Δ</th>
                    <th class="text-right">Δ %</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $r):
                    $rowClass = !empty($r['_flagged']) ? 'bg-rose-50/40' : '';
                    $pctDisplay = $r['_pct'] === null ? 'new' : number_format($r['_pct'], 1) . '%';
                ?>
                    <tr class="<?= $rowClass ?>">
                        <td class="font-mono text-xs"><?= e($r['account_code']) ?></td>
                        <td><?= e($r['account_name'] ?? '') ?></td>
                        <td class="text-right tabular-nums"><?= e(number_format((float) ($r['cur_balance'] ?? 0), 2)) ?></td>
                        <td class="text-right tabular-nums text-slate-500"><?= e(number_format((float) ($r['pri_balance'] ?? 0), 2)) ?></td>
                        <td class="text-right tabular-nums <?= $r['_diff'] < 0 ? 'text-rose-700' : 'text-slate-700' ?>">
                            <?= e(number_format((float) $r['_diff'], 2)) ?>
                        </td>
                        <td class="text-right tabular-nums <?= !empty($r['_flagged']) ? 'text-rose-700 font-medium' : 'text-slate-500' ?>">
                            <?= e($pctDisplay) ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr class="bg-slate-50 font-medium">
                    <td colspan="2">Totals</td>
                    <td class="text-right tabular-nums"><?= e(number_format($totals['cur_balance'], 2)) ?></td>
                    <td class="text-right tabular-nums text-slate-500"><?= e(number_format($totals['pri_balance'], 2)) ?></td>
                    <td class="text-right tabular-nums"><?= e(number_format($totals['cur_balance'] - $totals['pri_balance'], 2)) ?></td>
                    <td></td>
                </tr>
            </tfoot>
        </table>
    </div>

    <div class="mt-3 text-xs text-slate-500">
        Totals across all accounts (signed). Use the AI variance analysis for narrative insight.
    </div>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
