<?php
/**
 * /firm/kpi.php
 *
 * Staff KPI dashboard. One row per firm staff member with their
 * productivity metrics — designed for the partner / firm admin who
 * needs to see workload distribution and identify bottlenecks.
 */

declare(strict_types=1);
require_once __DIR__ . '/../includes/auth_guard.php';
require_role(['firm_admin','audit_manager','reviewer']);
require_once __DIR__ . '/../includes/kpi.php';

$firmId = current_firm_id();
$rows   = kpi_for_firm($firmId);

// Aggregate totals for the headline cards.
$totalJobs       = array_sum(array_column($rows, 'jobs_assigned'));
$totalWPs        = array_sum(array_column($rows, 'wp_prepared'));
$totalNotesOpen  = array_sum(array_map(
    static fn($r) => max(0, ($r['notes_received'] ?? 0) - ($r['notes_cleared'] ?? 0)), $rows));
$totalOverdue    = array_sum(array_column($rows, 'overdue_tasks'));
$maxScore        = max(array_map(static fn($r) => $r['productivity_score'] ?? 0, $rows ?: [0]));
if ($maxScore <= 0) { $maxScore = 1; }

$pageTitle = 'Staff KPI';
require __DIR__ . '/../includes/header.php';
?>

<div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
    <div class="bg-white rounded-lg border border-slate-200 p-4">
        <div class="text-xs uppercase tracking-wide text-slate-500">Active staff</div>
        <div class="text-3xl font-semibold text-brand-700"><?= count($rows) ?></div>
    </div>
    <div class="bg-white rounded-lg border border-slate-200 p-4">
        <div class="text-xs uppercase tracking-wide text-slate-500">Working papers prepared</div>
        <div class="text-3xl font-semibold"><?= number_format($totalWPs) ?></div>
    </div>
    <div class="bg-white rounded-lg border border-slate-200 p-4">
        <div class="text-xs uppercase tracking-wide text-slate-500">Open review notes</div>
        <div class="text-3xl font-semibold text-amber-700"><?= number_format($totalNotesOpen) ?></div>
    </div>
    <div class="bg-white rounded-lg border border-slate-200 p-4">
        <div class="text-xs uppercase tracking-wide text-slate-500">Overdue engagements</div>
        <div class="text-3xl font-semibold <?= $totalOverdue > 0 ? 'text-rose-700' : 'text-slate-700' ?>">
            <?= number_format($totalOverdue) ?>
        </div>
    </div>
</div>

<div class="bg-white rounded-lg border border-slate-200 overflow-hidden">
    <div class="px-5 py-3 border-b border-slate-200 flex items-center justify-between">
        <h3 class="font-semibold text-slate-900">Staff productivity</h3>
        <span class="text-xs text-slate-500">Sorted by productivity score</span>
    </div>
    <?php if (empty($rows)): ?>
        <div class="p-8 text-center text-sm text-slate-500">
            No staff yet. <a href="/firm/staff.php?action=new" class="text-brand-600 hover:underline">Add staff</a>.
        </div>
    <?php else: ?>
        <table class="table-app">
            <thead>
                <tr>
                    <th>Staff</th>
                    <th>Role</th>
                    <th class="text-right">Jobs</th>
                    <th class="text-right">WPs prepared</th>
                    <th class="text-right">Pending review</th>
                    <th class="text-right">Notes (cleared / received)</th>
                    <th class="text-right">Avg WP cycle</th>
                    <th class="text-right">Overdue</th>
                    <th>Score</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $r):
                    $score = (float) $r['productivity_score'];
                    $pct = max(0, min(100, ($score / $maxScore) * 100));
                    $barColour = $score < 0 ? 'bg-rose-500'
                               : ($score < $maxScore * 0.4 ? 'bg-amber-500' : 'bg-emerald-500');
                ?>
                    <tr>
                        <td>
                            <div class="font-medium"><?= e($r['name']) ?></div>
                            <div class="text-xs text-slate-500"><?= e($r['email']) ?></div>
                            <?php if ($r['last_login_at']): ?>
                                <div class="text-[11px] text-slate-400">Last seen <?= e(datefmt($r['last_login_at'], 'd M H:i')) ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="text-xs"><?= e(ucwords(str_replace('_',' ',$r['role']))) ?></td>
                        <td class="text-right tabular-nums">
                            <?= (int) $r['jobs_assigned'] ?>
                            <span class="text-xs text-slate-500">/ <?= (int) $r['jobs_completed'] ?> done</span>
                        </td>
                        <td class="text-right tabular-nums"><?= (int) $r['wp_prepared'] ?></td>
                        <td class="text-right tabular-nums <?= $r['wp_pending_review'] > 0 ? 'text-amber-700' : '' ?>">
                            <?= (int) $r['wp_pending_review'] ?>
                        </td>
                        <td class="text-right tabular-nums">
                            <?= (int) $r['notes_cleared'] ?> / <?= (int) $r['notes_received'] ?>
                        </td>
                        <td class="text-right tabular-nums">
                            <?php if ($r['avg_completion_days'] !== null): ?>
                                <?= number_format((float) $r['avg_completion_days'], 1) ?>d
                            <?php else: ?>
                                <span class="text-slate-400">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-right tabular-nums <?= $r['overdue_tasks'] > 0 ? 'text-rose-700 font-medium' : '' ?>">
                            <?= (int) $r['overdue_tasks'] ?>
                        </td>
                        <td>
                            <div class="flex items-center gap-2">
                                <div class="w-24 bg-slate-100 rounded h-2 overflow-hidden">
                                    <div class="<?= $barColour ?> h-2" style="width: <?= number_format($pct, 1) ?>%"></div>
                                </div>
                                <span class="text-xs tabular-nums"><?= number_format($score, 0) ?></span>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<div class="mt-3 text-xs text-slate-500">
    Score = WP prepared × 2 + notes cleared × 3 + jobs completed × 5 − overdue × 5. Recomputed on every load from live data.
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
