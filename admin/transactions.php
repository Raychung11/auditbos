<?php
/**
 * /admin/transactions.php
 *
 * Super-admin: cross-firm credit transaction ledger with filters
 * (firm, direction, usage_type, date range). Useful for billing
 * reconciliation and spotting suspicious activity.
 */

declare(strict_types=1);
require_once __DIR__ . '/../includes/auth_guard.php';
require_role(['super_admin']);

$pdo = db();

$firmId    = isset($_GET['firm_id']) ? (int) $_GET['firm_id'] : 0;
$direction = $_GET['direction'] ?? '';
$usageType = $_GET['usage_type'] ?? '';
$from      = trim((string)($_GET['from'] ?? '')) ?: null;
$to        = trim((string)($_GET['to']   ?? '')) ?: null;
$limit     = max(50, min(500, (int)($_GET['limit'] ?? 200)));

$sql = 'SELECT ct.*, f.name AS firm_name, u.name AS created_by_name
          FROM credit_transactions ct
          JOIN firms f ON f.id = ct.firm_id
          LEFT JOIN users u ON u.id = ct.created_by
         WHERE 1=1';
$params = [];
if ($firmId > 0) { $sql .= ' AND ct.firm_id = :fid';            $params[':fid'] = $firmId; }
if (in_array($direction, ['credit','debit'], true)) {
    $sql .= ' AND ct.direction = :dir';                          $params[':dir'] = $direction;
}
if ($usageType !== '' && preg_match('/^[a-z_]+$/', $usageType)) {
    $sql .= ' AND ct.usage_type = :ut';                          $params[':ut']  = $usageType;
}
if ($from) { $sql .= ' AND ct.created_at >= :from';              $params[':from'] = $from . ' 00:00:00'; }
if ($to)   { $sql .= ' AND ct.created_at <= :to';                $params[':to']   = $to   . ' 23:59:59'; }
$sql .= ' ORDER BY ct.created_at DESC LIMIT ' . (int) $limit;

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

// Usage-type dropdown options drawn from existing data
$types = $pdo->query('SELECT DISTINCT usage_type FROM credit_transactions WHERE usage_type IS NOT NULL ORDER BY usage_type')->fetchAll(PDO::FETCH_COLUMN);

$firms = $pdo->query('SELECT id, name FROM firms ORDER BY name')->fetchAll();

// Aggregates over current filter
$agg = $pdo->prepare(
    'SELECT
       COALESCE(SUM(CASE WHEN direction = "credit" THEN amount ELSE 0 END), 0) AS credits,
       COALESCE(SUM(CASE WHEN direction = "debit"  THEN amount ELSE 0 END), 0) AS debits,
       COUNT(*) AS row_count'
    . ' FROM credit_transactions WHERE 1=1'
    . ($firmId > 0 ? ' AND firm_id = :fid' : '')
    . (in_array($direction, ['credit','debit'], true) ? ' AND direction = :dir' : '')
    . ($usageType !== '' && preg_match('/^[a-z_]+$/', $usageType) ? ' AND usage_type = :ut' : '')
    . ($from ? ' AND created_at >= :from' : '')
    . ($to   ? ' AND created_at <= :to'   : '')
);
$agg->execute($params);
$totals = $agg->fetch() ?: ['credits'=>0, 'debits'=>0, 'row_count'=>0];

$pageTitle = 'Credit Transactions';
require __DIR__ . '/../includes/header.php';
?>

<div class="flex items-end justify-between gap-3 mb-4">
    <p class="text-sm text-slate-600">
        Cross-firm credit ledger. <?= (int) $totals['row_count'] ?> rows in filter ·
        topped <?= e(money((float) $totals['credits'])) ?> ·
        used <?= e(money((float) $totals['debits'])) ?>
    </p>
    <a href="/admin/wallets.php" class="text-sm text-brand-600 hover:underline">&larr; Wallets overview</a>
</div>

<form method="get" class="bg-white rounded-lg border border-slate-200 p-4 mb-5 grid grid-cols-1 md:grid-cols-6 gap-3 text-sm">
    <label class="block md:col-span-2">
        <span class="text-xs font-medium text-slate-700">Firm</span>
        <select name="firm_id" class="mt-1 w-full rounded border border-slate-300 px-2 py-1.5">
            <option value="0">All firms</option>
            <?php foreach ($firms as $f): ?>
                <option value="<?= (int) $f['id'] ?>" <?= $firmId === (int) $f['id'] ? 'selected' : '' ?>>
                    <?= e($f['name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </label>
    <label class="block">
        <span class="text-xs font-medium text-slate-700">Direction</span>
        <select name="direction" class="mt-1 w-full rounded border border-slate-300 px-2 py-1.5">
            <option value="">All</option>
            <option value="credit" <?= $direction === 'credit' ? 'selected' : '' ?>>Credit (top-up)</option>
            <option value="debit"  <?= $direction === 'debit'  ? 'selected' : '' ?>>Debit (usage)</option>
        </select>
    </label>
    <label class="block">
        <span class="text-xs font-medium text-slate-700">Type</span>
        <select name="usage_type" class="mt-1 w-full rounded border border-slate-300 px-2 py-1.5">
            <option value="">All</option>
            <?php foreach ($types as $t): ?>
                <option value="<?= e($t) ?>" <?= $usageType === $t ? 'selected' : '' ?>>
                    <?= e($t) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </label>
    <label class="block">
        <span class="text-xs font-medium text-slate-700">From</span>
        <input type="date" name="from" value="<?= e($from ?? '') ?>"
               class="mt-1 w-full rounded border border-slate-300 px-2 py-1.5">
    </label>
    <label class="block">
        <span class="text-xs font-medium text-slate-700">To</span>
        <input type="date" name="to" value="<?= e($to ?? '') ?>"
               class="mt-1 w-full rounded border border-slate-300 px-2 py-1.5">
    </label>
    <div class="flex items-end gap-2">
        <button class="rounded bg-brand-600 hover:bg-brand-700 text-white px-3 py-1.5 text-sm">Apply</button>
        <a href="/admin/transactions.php" class="rounded border border-slate-300 px-3 py-1.5 text-sm hover:bg-slate-50">Reset</a>
    </div>
</form>

<div class="bg-white rounded-lg border border-slate-200 overflow-hidden">
    <?php if (empty($rows)): ?>
        <div class="p-8 text-center text-sm text-slate-500">No transactions match the current filter.</div>
    <?php else: ?>
        <table class="table-app">
            <thead>
                <tr>
                    <th>When</th>
                    <th>Firm</th>
                    <th>Direction</th>
                    <th>Type</th>
                    <th class="text-right">Amount</th>
                    <th class="text-right">Balance after</th>
                    <th>By</th>
                    <th>Notes</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $tx):
                    $sign = $tx['direction'] === 'credit' ? '+' : '−';
                    $colour = $tx['direction'] === 'credit' ? 'text-emerald-700' : 'text-rose-700';
                ?>
                    <tr>
                        <td class="text-xs"><?= e(datefmt($tx['created_at'], 'd M Y H:i')) ?></td>
                        <td><?= e($tx['firm_name']) ?></td>
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
