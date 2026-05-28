<?php
/**
 * /admin/activity.php
 *
 * Super-admin: platform-wide activity log (read-only). Filters by
 * firm, user, action, and date range so support engineers can trace
 * what happened during an incident.
 */

declare(strict_types=1);
require_once __DIR__ . '/../includes/auth_guard.php';
require_role(['super_admin']);

$pdo = db();

$firmId = isset($_GET['firm_id']) ? (int) $_GET['firm_id'] : 0;
$userId = isset($_GET['user_id']) ? (int) $_GET['user_id'] : 0;
$action = trim((string)($_GET['action'] ?? ''));
$from   = trim((string)($_GET['from'] ?? '')) ?: null;
$to     = trim((string)($_GET['to']   ?? '')) ?: null;
$limit  = max(50, min(500, (int)($_GET['limit'] ?? 200)));

$sql = 'SELECT al.*, f.name AS firm_name, u.name AS user_name, u.email AS user_email, u.role AS user_role
          FROM activity_logs al
          LEFT JOIN firms f ON f.id = al.firm_id
          LEFT JOIN users u ON u.id = al.user_id
         WHERE 1=1';
$params = [];
if ($firmId > 0) { $sql .= ' AND al.firm_id = :fid'; $params[':fid'] = $firmId; }
if ($userId > 0) { $sql .= ' AND al.user_id = :uid'; $params[':uid'] = $userId; }
if ($action !== '' && preg_match('/^[a-z._]+$/', $action)) {
    $sql .= ' AND al.action LIKE :act'; $params[':act'] = $action . '%';
}
if ($from) { $sql .= ' AND al.created_at >= :from'; $params[':from'] = $from . ' 00:00:00'; }
if ($to)   { $sql .= ' AND al.created_at <= :to';   $params[':to']   = $to   . ' 23:59:59'; }
$sql .= ' ORDER BY al.created_at DESC LIMIT ' . (int) $limit;

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$firms   = $pdo->query('SELECT id, name FROM firms ORDER BY name')->fetchAll();
$actions = $pdo->query(
    'SELECT DISTINCT SUBSTRING_INDEX(action, ".", 1) AS prefix
       FROM activity_logs
      ORDER BY prefix'
)->fetchAll(PDO::FETCH_COLUMN);

$pageTitle = 'Activity Log';
require __DIR__ . '/../includes/header.php';
?>

<div class="flex items-end justify-between gap-3 mb-4">
    <p class="text-sm text-slate-600">
        Platform-wide audit trail. <?= count($rows) ?> rows in filter (capped at <?= (int) $limit ?>).
    </p>
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
        <span class="text-xs font-medium text-slate-700">Action (prefix)</span>
        <select name="action" class="mt-1 w-full rounded border border-slate-300 px-2 py-1.5">
            <option value="">All</option>
            <?php foreach ($actions as $a): if ($a === null || $a === '') continue; ?>
                <option value="<?= e($a) ?>" <?= $action === $a ? 'selected' : '' ?>>
                    <?= e($a) ?>.*
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
        <a href="/admin/activity.php" class="rounded border border-slate-300 px-3 py-1.5 text-sm hover:bg-slate-50">Reset</a>
    </div>
</form>

<div class="bg-white rounded-lg border border-slate-200 overflow-hidden">
    <?php if (empty($rows)): ?>
        <div class="p-8 text-center text-sm text-slate-500">No activity matches the current filter.</div>
    <?php else: ?>
        <table class="table-app">
            <thead>
                <tr>
                    <th>When</th>
                    <th>Firm</th>
                    <th>User</th>
                    <th>Action</th>
                    <th>Entity</th>
                    <th>Description</th>
                    <th>IP</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td class="text-xs"><?= e(datefmt($r['created_at'], 'd M Y H:i:s')) ?></td>
                        <td class="text-xs"><?= e($r['firm_name'] ?? '—') ?></td>
                        <td class="text-xs">
                            <?php if ($r['user_name']): ?>
                                <?= e($r['user_name']) ?>
                                <div class="text-slate-500"><?= e($r['user_role'] ?? '') ?></div>
                            <?php else: ?>
                                <span class="text-slate-400">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-xs font-mono"><?= e($r['action']) ?></td>
                        <td class="text-xs text-slate-500">
                            <?= e($r['entity_type'] ?? '—') ?>
                            <?php if ($r['entity_id']): ?>#<?= (int) $r['entity_id'] ?><?php endif; ?>
                        </td>
                        <td class="text-xs text-slate-500"><?= e($r['description'] ?? '') ?></td>
                        <td class="text-xs text-slate-400"><?= e($r['ip_address'] ?? '') ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
