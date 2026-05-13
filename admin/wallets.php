<?php
/**
 * /admin/wallets.php
 *
 * Super-admin: cross-firm wallet overview. One row per firm with the
 * current balance, totals (topped up / used / AI usage in the last
 * 30 days), and a quick top-up link.
 *
 * Acts as a billing landing page — drill into a firm to see its
 * transaction history at /admin/firms_topup.php.
 */

declare(strict_types=1);
require_once __DIR__ . '/../includes/auth_guard.php';
require_role(['super_admin']);

$pdo = db();

// Make sure every firm has a wallet row (older firms predate the auto-create).
$pdo->exec(
    'INSERT IGNORE INTO credit_wallet (firm_id)
       SELECT id FROM firms WHERE id NOT IN (SELECT firm_id FROM credit_wallet)'
);

$rows = $pdo->query(
    'SELECT f.id AS firm_id, f.name, f.subscription_status, f.status,
            f.credit_balance AS firm_balance,
            cw.balance, cw.total_topped_up, cw.total_used, cw.currency,
            cw.status AS wallet_status, cw.updated_at,
            (SELECT COALESCE(SUM(amount), 0) FROM credit_transactions
              WHERE firm_id = f.id AND direction = "debit"
                AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)) AS used_30d,
            (SELECT COUNT(*) FROM ai_logs
              WHERE firm_id = f.id
                AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)) AS ai_calls_30d
       FROM firms f
       LEFT JOIN credit_wallet cw ON cw.firm_id = f.id
      ORDER BY (cw.balance IS NULL), cw.balance ASC, f.name'
)->fetchAll();

// Platform totals
$totals = $pdo->query(
    'SELECT COALESCE(SUM(balance), 0) AS bal,
            COALESCE(SUM(total_topped_up), 0) AS topped,
            COALESCE(SUM(total_used), 0) AS used
       FROM credit_wallet'
)->fetch();

$pageTitle = 'Wallets';
require __DIR__ . '/../includes/header.php';
?>

<div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
    <div class="bg-gradient-to-br from-brand-50 to-white rounded-lg border border-slate-200 p-4">
        <div class="text-xs uppercase tracking-wide text-slate-500">Total balance (platform)</div>
        <div class="text-3xl font-semibold text-brand-700">
            <?= e(money((float) $totals['bal'])) ?>
        </div>
    </div>
    <div class="bg-white rounded-lg border border-slate-200 p-4">
        <div class="text-xs uppercase tracking-wide text-slate-500">Total topped up (all-time)</div>
        <div class="text-2xl font-semibold text-emerald-700">
            <?= e(money((float) $totals['topped'])) ?>
        </div>
    </div>
    <div class="bg-white rounded-lg border border-slate-200 p-4">
        <div class="text-xs uppercase tracking-wide text-slate-500">Total used (all-time)</div>
        <div class="text-2xl font-semibold text-slate-700">
            <?= e(money((float) $totals['used'])) ?>
        </div>
    </div>
</div>

<div class="bg-white rounded-lg border border-slate-200 overflow-hidden">
    <div class="px-5 py-3 border-b border-slate-200 flex items-center justify-between">
        <h3 class="font-semibold text-slate-900">All firm wallets</h3>
        <span class="text-xs text-slate-500"><?= count($rows) ?> firms</span>
    </div>
    <?php if (empty($rows)): ?>
        <div class="p-8 text-center text-sm text-slate-500">
            No firms yet. <a href="/admin/firms.php?action=new" class="text-brand-600 hover:underline">Create one</a>.
        </div>
    <?php else: ?>
        <table class="table-app">
            <thead>
                <tr>
                    <th>Firm</th>
                    <th>Subscription</th>
                    <th class="text-right">Balance</th>
                    <th class="text-right">Topped up</th>
                    <th class="text-right">Used (all)</th>
                    <th class="text-right">Used (30d)</th>
                    <th class="text-right">AI calls (30d)</th>
                    <th>Wallet</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $r):
                    $low = (float) ($r['balance'] ?? 0) < 5.0;
                ?>
                    <tr>
                        <td>
                            <div class="font-medium"><?= e($r['name']) ?></div>
                            <?php if ($r['status'] !== 'active'): ?>
                                <?= badge($r['status']) ?>
                            <?php endif; ?>
                        </td>
                        <td><?= badge($r['subscription_status']) ?></td>
                        <td class="text-right tabular-nums <?= $low ? 'text-rose-700 font-medium' : '' ?>">
                            <?= e(money((float) ($r['balance'] ?? 0), $r['currency'] ?? 'USD')) ?>
                        </td>
                        <td class="text-right tabular-nums text-slate-600">
                            <?= e(money((float) ($r['total_topped_up'] ?? 0), $r['currency'] ?? 'USD')) ?>
                        </td>
                        <td class="text-right tabular-nums text-slate-600">
                            <?= e(money((float) ($r['total_used'] ?? 0), $r['currency'] ?? 'USD')) ?>
                        </td>
                        <td class="text-right tabular-nums text-slate-600">
                            <?= e(money((float) $r['used_30d'], $r['currency'] ?? 'USD')) ?>
                        </td>
                        <td class="text-right tabular-nums"><?= (int) $r['ai_calls_30d'] ?></td>
                        <td><?= badge($r['wallet_status'] ?? 'active') ?></td>
                        <td class="text-right">
                            <a href="/admin/firms_topup.php?firm_id=<?= (int) $r['firm_id'] ?>"
                               class="text-sm text-emerald-700 hover:underline mr-2">Top up</a>
                            <a href="/admin/transactions.php?firm_id=<?= (int) $r['firm_id'] ?>"
                               class="text-sm text-brand-600 hover:underline">History</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
