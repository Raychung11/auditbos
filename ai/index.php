<?php
/**
 * /ai/index.php
 *
 * AI Assistant home — catalogue of available functions + recent logs.
 */

declare(strict_types=1);
require_once __DIR__ . '/../includes/auth_guard.php';
require_role(['firm_admin','audit_manager','senior_auditor','reviewer']);
require_once __DIR__ . '/ai_service.php';

$pdo = db();
$firmId = current_firm_id();

$recent = $pdo->prepare(
    'SELECT al.*, u.name AS user_name,
            (SELECT company_name FROM clients c
              JOIN engagements e ON e.client_id = c.id
              WHERE e.id = al.engagement_id) AS client_name
       FROM ai_logs al
       LEFT JOIN users u ON u.id = al.user_id
      WHERE al.firm_id = :fid
      ORDER BY al.created_at DESC
      LIMIT 15'
);
$recent->execute([':fid' => $firmId]);
$logs = $recent->fetchAll();

$wallet = $pdo->prepare(
    'SELECT balance, total_used FROM credit_wallet WHERE firm_id = :fid'
);
$wallet->execute([':fid' => $firmId]);
$walletRow = $wallet->fetch();

$pageTitle = 'AI Assistant';
require __DIR__ . '/../includes/header.php';
?>

<div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
    <div class="bg-white rounded-lg border border-slate-200 p-4">
        <div class="text-xs text-slate-500">Provider</div>
        <div class="font-medium"><?= e(AI_PROVIDER) ?> · <?= e(AI_MODEL) ?></div>
        <?php if (!AI_ENABLED): ?>
            <div class="text-xs text-amber-700 mt-1">No API key configured — stub mode.</div>
        <?php else: ?>
            <div class="text-xs text-emerald-700 mt-1">Live (real calls will run).</div>
        <?php endif; ?>
    </div>
    <div class="bg-white rounded-lg border border-slate-200 p-4">
        <div class="text-xs text-slate-500">Credit balance</div>
        <div class="text-2xl font-semibold">
            <?= e(money($walletRow ? (float) $walletRow['balance'] : 0.0)) ?>
        </div>
    </div>
    <div class="bg-white rounded-lg border border-slate-200 p-4">
        <div class="text-xs text-slate-500">Total used</div>
        <div class="text-2xl font-semibold">
            <?= e(money($walletRow ? (float) $walletRow['total_used'] : 0.0)) ?>
        </div>
    </div>
</div>

<h3 class="text-sm font-semibold text-slate-900 uppercase tracking-wide mb-3">Functions</h3>
<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4 mb-8">
    <?php foreach (ai_registry() as $key => $cfg): ?>
        <div class="bg-white rounded-lg border border-slate-200 p-4 flex flex-col">
            <h4 class="font-semibold text-slate-900"><?= e($cfg['title']) ?></h4>
            <p class="text-xs text-slate-500 mt-1 flex-1"><?= e($cfg['prompt']) ?></p>
            <a href="/ai/run.php?fn=<?= e($key) ?>"
               class="mt-3 inline-flex items-center justify-center rounded bg-brand-600 hover:bg-brand-700 text-white px-3 py-1.5 text-sm font-medium">
                Run
            </a>
        </div>
    <?php endforeach; ?>
</div>

<h3 class="text-sm font-semibold text-slate-900 uppercase tracking-wide mb-3">Recent calls</h3>
<div class="bg-white rounded-lg border border-slate-200 overflow-hidden">
    <?php if (empty($logs)): ?>
        <div class="p-8 text-center text-sm text-slate-500">No AI calls yet.</div>
    <?php else: ?>
        <table class="table-app">
            <thead>
                <tr>
                    <th>When</th>
                    <th>Function</th>
                    <th>Client</th>
                    <th>User</th>
                    <th>Status</th>
                    <th>Credits</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($logs as $l): ?>
                    <tr>
                        <td class="text-xs"><?= e(datefmt($l['created_at'], 'd M Y H:i')) ?></td>
                        <td class="text-xs font-mono"><?= e($l['function_name']) ?></td>
                        <td><?= e($l['client_name'] ?? '-') ?></td>
                        <td><?= e($l['user_name'] ?? '-') ?></td>
                        <td><?= badge($l['status']) ?></td>
                        <td class="text-xs"><?= e(number_format((float) $l['credits_used'], 2)) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
