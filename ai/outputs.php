<?php
/**
 * /ai/outputs.php
 *
 * AI output catalogue — every curated AI artefact for the firm, with an
 * engagement filter and quick accept/reject actions.
 */

declare(strict_types=1);
require_once __DIR__ . '/../includes/auth_guard.php';
require_role(['firm_admin','audit_manager','senior_auditor','reviewer']);

$pdo    = db();
$firmId = current_firm_id();

$engagementId = isset($_GET['engagement_id']) ? (int) $_GET['engagement_id'] : 0;
$filterType   = $_GET['type'] ?? '';
$filterStatus = $_GET['status'] ?? '';

$sql = 'SELECT ao.*, e.financial_year, c.company_name, u.name AS created_by_name,
               al.input_tokens, al.output_tokens, al.credits_used
          FROM ai_outputs ao
          LEFT JOIN engagements e ON e.id = ao.engagement_id
          LEFT JOIN clients c     ON c.id = e.client_id
          LEFT JOIN users u       ON u.id = ao.created_by
          LEFT JOIN ai_logs al    ON al.id = ao.ai_log_id
         WHERE ao.firm_id = :fid';
$params = [':fid' => $firmId];
if ($engagementId > 0) {
    $sql .= ' AND ao.engagement_id = :eid';
    $params[':eid'] = $engagementId;
}
if ($filterType !== '' && preg_match('/^[a-z_]+$/', $filterType)) {
    $sql .= ' AND ao.output_type = :ot';
    $params[':ot'] = $filterType;
}
if (in_array($filterStatus, ['draft','accepted','rejected','published'], true)) {
    $sql .= ' AND ao.status = :st';
    $params[':st'] = $filterStatus;
}
$sql .= ' ORDER BY ao.created_at DESC LIMIT 100';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$outputs = $stmt->fetchAll();

$pageTitle = 'AI Outputs';
require __DIR__ . '/../includes/header.php';
?>

<div class="flex flex-wrap items-end justify-between gap-3 mb-4">
    <div>
        <p class="text-sm text-slate-600">All AI-generated artefacts for your firm. Drafts can be accepted or rejected.</p>
        <?php if ($engagementId > 0): ?>
            <p class="text-xs text-slate-500 mt-1">
                Filtered to engagement #<?= (int) $engagementId ?>
                <a href="/ai/outputs.php" class="ml-2 text-brand-600 hover:underline">clear</a>
            </p>
        <?php endif; ?>
    </div>
    <form method="get" class="flex items-center gap-2 text-sm">
        <?php if ($engagementId > 0): ?>
            <input type="hidden" name="engagement_id" value="<?= (int) $engagementId ?>">
        <?php endif; ?>
        <select name="status" class="rounded border border-slate-300 px-2 py-1">
            <option value="">All statuses</option>
            <?php foreach (['draft','accepted','rejected','published'] as $s): ?>
                <option value="<?= $s ?>" <?= $filterStatus === $s ? 'selected' : '' ?>>
                    <?= ucfirst($s) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <button class="rounded border border-slate-300 px-2 py-1 hover:bg-slate-50">Filter</button>
    </form>
</div>

<div class="bg-white rounded-lg border border-slate-200 overflow-hidden">
    <?php if (empty($outputs)): ?>
        <div class="p-8 text-center text-sm text-slate-500">No AI outputs yet.</div>
    <?php else: ?>
        <table class="table-app">
            <thead>
                <tr>
                    <th>When</th>
                    <th>Title</th>
                    <th>Engagement</th>
                    <th>Type</th>
                    <th>Tokens</th>
                    <th>Cost</th>
                    <th>Status</th>
                    <th>By</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($outputs as $o):
                    $tokens = ($o['input_tokens'] ?? 0) + ($o['output_tokens'] ?? 0);
                ?>
                    <tr>
                        <td class="text-xs"><?= e(datefmt($o['created_at'], 'd M Y H:i')) ?></td>
                        <td class="font-medium"><?= e($o['title'] ?? $o['output_type']) ?></td>
                        <td>
                            <?php if ($o['company_name']): ?>
                                <div class="text-xs"><?= e($o['company_name']) ?></div>
                                <div class="text-xs text-slate-500"><?= e($o['financial_year'] ?? '') ?></div>
                            <?php else: ?>
                                <span class="text-xs text-slate-400">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-xs font-mono text-slate-500"><?= e($o['output_type']) ?></td>
                        <td class="text-xs tabular-nums"><?= $tokens > 0 ? number_format($tokens) : '—' ?></td>
                        <td class="text-xs tabular-nums">
                            <?= $o['credits_used'] !== null && (float)$o['credits_used'] > 0
                                ? 'MYR ' . number_format((float) $o['credits_used'], 4)
                                : '—' ?>
                        </td>
                        <td><?= badge($o['status']) ?></td>
                        <td class="text-xs"><?= e($o['created_by_name'] ?? '—') ?></td>
                        <td class="text-right">
                            <a href="/ai/output_view.php?id=<?= (int) $o['id'] ?>"
                               class="text-sm text-brand-600 hover:underline">Open</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
