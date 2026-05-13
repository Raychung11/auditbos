<?php
/**
 * /admin/firms_topup.php?firm_id=...
 *
 * Super admin records a credit top-up for a firm wallet. No payment
 * gateway yet — this is a manual ledger entry: amount + reference
 * (e.g. invoice number / bank reference) + optional notes.
 *
 * Done in a transaction:
 *   - INSERT IGNORE wallet row (in case it's missing for legacy firms)
 *   - UPDATE credit_wallet (balance, total_topped_up)
 *   - INSERT credit_transactions (direction=credit, usage_type=top_up)
 *   - UPDATE firms.credit_balance mirror
 */

declare(strict_types=1);
require_once __DIR__ . '/../includes/auth_guard.php';
require_role(['super_admin']);

$pdo    = db();
$firmId = isset($_GET['firm_id']) ? (int) $_GET['firm_id'] : (int) ($_POST['firm_id'] ?? 0);
if ($firmId <= 0) {
    flash('error', 'Pick a firm first.');
    redirect('/admin/firms.php');
}

$firmStmt = $pdo->prepare('SELECT id, name, credit_balance FROM firms WHERE id = :id');
$firmStmt->execute([':id' => $firmId]);
$firm = $firmStmt->fetch();
if (!$firm) {
    flash('error', 'Firm not found.');
    redirect('/admin/firms.php');
}

$walletStmt = $pdo->prepare('SELECT balance, total_topped_up, total_used, currency FROM credit_wallet WHERE firm_id = :f');
$walletStmt->execute([':f' => $firmId]);
$wallet = $walletStmt->fetch() ?: ['balance'=>0, 'total_topped_up'=>0, 'total_used'=>0, 'currency'=>'MYR'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $amount    = (float)($_POST['amount'] ?? 0);
    $reference = trim((string)($_POST['reference'] ?? '')) ?: null;
    $notes     = trim((string)($_POST['notes'] ?? '')) ?: null;

    if ($amount <= 0) {
        flash('error', 'Amount must be greater than zero.');
        redirect('/admin/firms_topup.php?firm_id=' . $firmId);
    }
    if ($amount > 1_000_000) {
        flash('error', 'Top-up amount exceeds sane limit.');
        redirect('/admin/firms_topup.php?firm_id=' . $firmId);
    }

    try {
        $pdo->beginTransaction();
        $pdo->prepare('INSERT IGNORE INTO credit_wallet (firm_id) VALUES (:f)')
            ->execute([':f' => $firmId]);

        $stmt = $pdo->prepare(
            'SELECT id, balance FROM credit_wallet WHERE firm_id = :f FOR UPDATE'
        );
        $stmt->execute([':f' => $firmId]);
        $w = $stmt->fetch();

        $newBalance = (float) $w['balance'] + $amount;
        $pdo->prepare(
            'UPDATE credit_wallet
                SET balance = :b,
                    total_topped_up = total_topped_up + :a
              WHERE id = :id'
        )->execute([':b'=>$newBalance, ':a'=>$amount, ':id'=>$w['id']]);

        $pdo->prepare(
            'INSERT INTO credit_transactions
                (firm_id, direction, amount, balance_after, usage_type,
                 reference_type, reference_id, notes, created_by)
             VALUES (:f, "credit", :a, :b, "top_up", "manual", NULL, :n, :u)'
        )->execute([
            ':f'=>$firmId, ':a'=>$amount, ':b'=>$newBalance,
            ':n'=>trim(($reference ? "Ref: {$reference}. " : '') . ($notes ?? '')),
            ':u'=>current_user_id(),
        ]);

        $pdo->prepare('UPDATE firms SET credit_balance = :b WHERE id = :id')
            ->execute([':b'=>$newBalance, ':id'=>$firmId]);

        $pdo->commit();
        log_activity('wallet.topup', 'firm', $firmId, "Credit " . number_format($amount, 2));
        flash('success', "Credited " . number_format($amount, 2) . " to {$firm['name']}.");
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('[AuditBOS] top-up failed: ' . $e->getMessage());
        flash('error', 'Top-up failed.');
    }
    redirect('/admin/firms.php');
}

// Recent transactions for context
$txStmt = $pdo->prepare(
    'SELECT ct.*, u.name AS created_by_name
       FROM credit_transactions ct
       LEFT JOIN users u ON u.id = ct.created_by
      WHERE ct.firm_id = :f
      ORDER BY ct.created_at DESC
      LIMIT 25'
);
$txStmt->execute([':f' => $firmId]);
$txs = $txStmt->fetchAll();

$pageTitle = 'Top up · ' . $firm['name'];
require __DIR__ . '/../includes/header.php';
?>

<a href="/admin/firms.php" class="text-sm text-brand-600 hover:underline">&larr; Back to firms</a>

<div class="flex items-end justify-between gap-3 mt-1 mb-5">
    <div>
        <h2 class="text-xl font-semibold text-slate-900"><?= e($firm['name']) ?> — Top up credits</h2>
        <p class="text-sm text-slate-500">Record a manual top-up to the firm's wallet.</p>
    </div>
    <div class="text-right">
        <div class="text-xs text-slate-500">Current balance</div>
        <div class="text-2xl font-semibold"><?= e(money((float) $wallet['balance'], $wallet['currency'] ?? 'MYR')) ?></div>
        <div class="text-xs text-slate-500 mt-1">
            Topped up: <?= e(money((float) $wallet['total_topped_up'], $wallet['currency'] ?? 'MYR')) ?>
            · Used: <?= e(money((float) $wallet['total_used'], $wallet['currency'] ?? 'MYR')) ?>
        </div>
    </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <div class="bg-white rounded-lg border border-slate-200 p-5">
        <h3 class="font-semibold text-slate-900 mb-3">New top-up</h3>
        <form method="post" class="space-y-3">
            <?= csrf_field() ?>
            <input type="hidden" name="firm_id" value="<?= (int) $firmId ?>">
            <label class="block">
                <span class="text-sm font-medium text-slate-700">Amount *</span>
                <input type="number" step="0.01" min="0.01" max="1000000" name="amount" required
                       class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm">
            </label>
            <label class="block">
                <span class="text-sm font-medium text-slate-700">Reference</span>
                <input name="reference" placeholder="Invoice / bank ref"
                       class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm">
            </label>
            <label class="block">
                <span class="text-sm font-medium text-slate-700">Notes</span>
                <textarea name="notes" rows="2"
                          class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"></textarea>
            </label>
            <button class="w-full rounded bg-brand-600 hover:bg-brand-700 text-white px-3 py-2 text-sm font-medium">
                Credit wallet
            </button>
        </form>
    </div>

    <div class="lg:col-span-2 bg-white rounded-lg border border-slate-200 overflow-hidden">
        <div class="px-5 py-3 border-b border-slate-200">
            <h3 class="font-semibold text-slate-900">Recent transactions</h3>
        </div>
        <?php if (empty($txs)): ?>
            <div class="p-8 text-center text-sm text-slate-500">No transactions yet.</div>
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
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
