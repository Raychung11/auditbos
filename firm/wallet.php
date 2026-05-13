<?php
/**
 * /firm/wallet.php
 *
 * Firm-admin view of the credit wallet: current balance, totals, and
 * transaction history (both credits and debits, with the AI function
 * name surfaced for debits sourced from ai_logs).
 */

declare(strict_types=1);
require_once __DIR__ . '/../includes/auth_guard.php';
require_role(['firm_admin','audit_manager']);

$pdo    = db();
$firmId = current_firm_id();

$pdo->prepare('INSERT IGNORE INTO credit_wallet (firm_id) VALUES (:f)')->execute([':f' => $firmId]);

$wallet = $pdo->prepare(
    'SELECT balance, total_topped_up, total_used, currency, status, updated_at
       FROM credit_wallet WHERE firm_id = :f'
);
$wallet->execute([':f' => $firmId]);
$w = $wallet->fetch() ?: [];

$txStmt = $pdo->prepare(
    'SELECT ct.*, u.name AS created_by_name
       FROM credit_transactions ct
       LEFT JOIN users u ON u.id = ct.created_by
      WHERE ct.firm_id = :f
      ORDER BY ct.created_at DESC
      LIMIT 200'
);
$txStmt->execute([':f' => $firmId]);
$txs = $txStmt->fetchAll();

// Aggregate usage by usage_type for the summary chart.
$byType = $pdo->prepare(
    'SELECT usage_type, SUM(amount) AS total
       FROM credit_transactions
      WHERE firm_id = :f AND direction = "debit"
      GROUP BY usage_type
      ORDER BY total DESC'
);
$byType->execute([':f' => $firmId]);
$usage = $byType->fetchAll();

$pageTitle = 'Credit Wallet';
$currency = $w['currency'] ?? 'USD';
require __DIR__ . '/../includes/header.php';
?>

<div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
    <div class="bg-gradient-to-br from-brand-50 to-white rounded-lg border border-slate-200 p-4">
        <div class="text-xs uppercase tracking-wide text-slate-500">Balance</div>
        <div class="text-3xl font-semibold text-brand-700">
            <?= e(money((float)($w['balance'] ?? 0), $currency)) ?>
        </div>
        <?php if (($w['status'] ?? 'active') !== 'active'): ?>
            <div class="text-xs text-rose-700 mt-1">Wallet status: <?= e($w['status']) ?></div>
        <?php endif; ?>
    </div>
    <div class="bg-white rounded-lg border border-slate-200 p-4">
        <div class="text-xs uppercase tracking-wide text-slate-500">Total topped up</div>
        <div class="text-2xl font-semibold text-emerald-700">
            <?= e(money((float)($w['total_topped_up'] ?? 0), $currency)) ?>
        </div>
    </div>
    <div class="bg-white rounded-lg border border-slate-200 p-4">
        <div class="text-xs uppercase tracking-wide text-slate-500">Total used</div>
        <div class="text-2xl font-semibold text-slate-700">
            <?= e(money((float)($w['total_used'] ?? 0), $currency)) ?>
        </div>
    </div>
</div>

<?php if (!empty($usage)): ?>
    <div class="bg-white rounded-lg border border-slate-200 p-5 mb-6">
        <h3 class="font-semibold text-slate-900 mb-3">Usage by category</h3>
        <?php
            $maxUsage = max(array_map(fn($u) => (float) $u['total'], $usage)) ?: 1.0;
        ?>
        <div class="space-y-2">
            <?php foreach ($usage as $u): $pct = (float) $u['total'] / $maxUsage * 100; ?>
                <div>
                    <div class="flex items-center justify-between text-xs">
                        <span class="font-mono"><?= e($u['usage_type'] ?? 'unknown') ?></span>
                        <span class="text-slate-500"><?= e(money((float) $u['total'], $currency)) ?></span>
                    </div>
                    <div class="w-full bg-slate-100 rounded h-2 mt-1">
                        <div class="bg-brand-500 h-2 rounded" style="width: <?= number_format($pct, 1) ?>%"></div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
<?php endif; ?>

<div class="bg-white rounded-lg border border-slate-200 overflow-hidden">
    <div class="px-5 py-3 border-b border-slate-200 flex items-center justify-between">
        <h3 class="font-semibold text-slate-900">Transaction history</h3>
        <span class="text-xs text-slate-500">Last 200</span>
    </div>
    <?php if (empty($txs)): ?>
        <div class="p-8 text-center text-sm text-slate-500">
            No transactions yet. Contact your platform administrator to top up.
        </div>
    <?php else: ?>
        <table class="table-app">
            <thead>
                <tr>
                    <th>When</th>
                    <th>Direction</th>
                    <th>Type</th>
                    <th class="text-right">Amount</th>
                    <th class="text-right">Balance after</th>
                    <th>By</th>
                    <th>Notes</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($txs as $tx):
                $sign = $tx['direction'] === 'credit' ? '+' : '−';
                $colour = $tx['direction'] === 'credit' ? 'text-emerald-700' : 'text-rose-700';
            ?>
                <tr>
                    <td class="text-xs"><?= e(datefmt($tx['created_at'], 'd M Y H:i')) ?></td>
                    <td><?= badge($tx['direction'] === 'credit' ? 'active' : 'rejected') ?></td>
                    <td class="text-xs"><?= e($tx['usage_type'] ?? '—') ?></td>
                    <td class="text-right tabular-nums <?= $colour ?>">
                        <?= $sign . ' ' . number_format((float) $tx['amount'], 4) ?>
                    </td>
                    <td class="text-right tabular-nums"><?= e(number_format((float) $tx['balance_after'], 4)) ?></td>
                    <td class="text-xs"><?= e($tx['created_by_name'] ?? '—') ?></td>
                    <td class="text-xs text-slate-500"><?= e($tx['notes'] ?? '') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
