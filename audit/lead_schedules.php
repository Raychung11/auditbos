<?php
/**
 * /audit/lead_schedules.php?engagement_id=…
 *
 * Lead schedules: the bridge between the imported trial balance and the
 * working papers. Groups TB accounts into audit areas, shows
 * current-vs-prior with movement, lets the team reclassify accounts and
 * spin up (or open) a working paper per lead.
 */

declare(strict_types=1);
require_once __DIR__ . '/../includes/auth_guard.php';
require_once __DIR__ . '/../includes/workplan.php';
require_role(['firm_admin','audit_manager','senior_auditor','junior_auditor','reviewer']);
require_once __DIR__ . '/../includes/lead_schedules.php';
require_once __DIR__ . '/../includes/workflow.php';

$pdo    = db();
$firmId = current_firm_id();
$engagementId = isset($_GET['engagement_id']) ? (int) $_GET['engagement_id'] : 0;
$canEdit = role_allows(['firm_admin','audit_manager','senior_auditor']);

// ---------------------------------------------------------------------
// Engagement picker when no id is supplied.
// ---------------------------------------------------------------------
if ($engagementId <= 0) {
    $list = $pdo->prepare(
        'SELECT e.id, e.financial_year, e.engagement_type, e.status, c.company_name
           FROM engagements e
           JOIN clients c ON c.id = e.client_id
          WHERE e.firm_id = :f AND e.status NOT IN ("archived")
          ORDER BY c.company_name, e.financial_year DESC'
    );
    $list->execute([':f' => $firmId]);
    $engagements = $list->fetchAll();

    $pageTitle = 'Lead Schedules';
    require __DIR__ . '/../includes/header.php';
    ?>
    <div class="bg-white rounded-lg border border-slate-200 overflow-hidden">
        <div class="px-5 py-3 border-b border-slate-200">
            <h3 class="font-semibold text-slate-900">Pick an engagement</h3>
            <p class="text-xs text-slate-500 mt-0.5">
                Lead schedules group the imported trial balance into audit areas, ready to tie to working papers.
            </p>
        </div>
        <?php if (empty($engagements)): ?>
            <div class="p-8 text-center text-sm text-slate-500">No engagements available.</div>
        <?php else: ?>
            <table class="table-app">
                <thead><tr><th>Client</th><th>FY</th><th>Type</th><th>Status</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($engagements as $e): ?>
                    <tr>
                        <td class="font-medium"><?= e($e['company_name']) ?></td>
                        <td><?= e($e['financial_year']) ?></td>
                        <td><?= e(ucfirst($e['engagement_type'])) ?></td>
                        <td><?= badge($e['status']) ?></td>
                        <td class="text-right">
                            <a href="/audit/lead_schedules.php?engagement_id=<?= (int) $e['id'] ?>"
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
// Load + scope-check engagement.
// ---------------------------------------------------------------------
$stmt = $pdo->prepare(
    'SELECT e.*, c.company_name
       FROM engagements e
       JOIN clients c ON c.id = e.client_id
      WHERE e.id = :id AND e.firm_id = :fid'
);
$stmt->execute([':id'=>$engagementId, ':fid'=>$firmId]);
$engagement = $stmt->fetch();
if (!$engagement) {
    flash('error', 'Engagement not found.');
    redirect('/audit/lead_schedules.php');
}

// ---------------------------------------------------------------------
// POST: reclassify an account, or create a working paper for a lead.
// ---------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (!$canEdit) { http_response_code(403); exit('Forbidden'); }
    assert_engagement_open($engagementId);
    $action = $_POST['_action'] ?? '';
    $areas  = lead_areas();

    if ($action === 'reclassify') {
        $code = trim((string)($_POST['account_code'] ?? ''));
        $area = (string)($_POST['audit_area'] ?? '');
        if ($code !== '' && isset($areas[$area])) {
            // Confirm the account belongs to this engagement's TB.
            $chk = $pdo->prepare(
                'SELECT 1 FROM trial_balances WHERE engagement_id = :e AND account_code = :c LIMIT 1'
            );
            $chk->execute([':e'=>$engagementId, ':c'=>$code]);
            if ($chk->fetchColumn()) {
                $pdo->prepare(
                    'INSERT INTO lead_schedule_overrides (engagement_id, account_code, audit_area, created_by)
                     VALUES (:e, :c, :a, :u)
                     ON DUPLICATE KEY UPDATE audit_area = VALUES(audit_area), created_by = VALUES(created_by)'
                )->execute([':e'=>$engagementId, ':c'=>$code, ':a'=>$area, ':u'=>current_user_id()]);
                log_activity('lead.reclassify', 'engagement', $engagementId, "{$code} → {$area}");
                flash('success', "Reclassified {$code} to " . $areas[$area]['label'] . '.');
            }
        }
        redirect('/audit/lead_schedules.php?engagement_id=' . $engagementId);
    }

    if ($action === 'create_wp') {
        $area = (string)($_POST['audit_area'] ?? '');
        if (isset($areas[$area])) {
            // Don't duplicate — if a WP already covers this lead, just go there.
            $exists = $pdo->prepare(
                'SELECT id FROM audit_working_papers
                  WHERE engagement_id = :e AND lead_area = :a LIMIT 1'
            );
            $exists->execute([':e'=>$engagementId, ':a'=>$area]);
            if ($wpId = $exists->fetchColumn()) {
                redirect('/audit/working_paper_view.php?id=' . (int) $wpId);
            }

            // Resolve the matched section id (if any).
            $sectionId = null;
            if ($areas[$area]['section']) {
                $secStmt = $pdo->prepare(
                    'SELECT id FROM audit_sections
                      WHERE code = :code AND (firm_id IS NULL OR firm_id = :fid) AND status = "active"
                      ORDER BY firm_id IS NULL LIMIT 1'
                );
                $secStmt->execute([':code'=>$areas[$area]['section'], ':fid'=>$firmId]);
                $sectionId = $secStmt->fetchColumn() ?: null;
            }

            // Pre-fill the lead total into the notes for context.
            $leads = build_lead_schedules($engagementId)['leads'];
            $thisLead = null;
            foreach ($leads as $l) { if ($l['area'] === $area) { $thisLead = $l; break; } }
            $noteLines = '';
            if ($thisLead) {
                $noteLines = "Lead schedule total (current): " . number_format($thisLead['cur'], 2)
                    . " | prior: " . number_format($thisLead['pri'], 2)
                    . " | movement: " . number_format($thisLead['diff'], 2)
                    . " across " . $thisLead['account_count'] . " account(s).";
            }

            $pdo->prepare(
                'INSERT INTO audit_working_papers
                    (engagement_id, section_id, lead_area, reference_code, title,
                     notes, status, prepared_by)
                 VALUES (:e, :s, :a, :rc, :t, :n, "not_started", :u)'
            )->execute([
                ':e'  => $engagementId,
                ':s'  => $sectionId,
                ':a'  => $area,
                ':rc' => $areas[$area]['section'],
                ':t'  => $areas[$area]['label'] . ' — lead schedule',
                ':n'  => $noteLines,
                ':u'  => current_user_id(),
            ]);
            $newWpId = (int) $pdo->lastInsertId();
            log_activity('lead.create_wp', 'audit_working_paper', $newWpId, $areas[$area]['label']);
            flash('success', 'Working paper created for ' . $areas[$area]['label'] . '.');
            redirect('/audit/working_paper_view.php?id=' . $newWpId);
        }
    }
    redirect('/audit/lead_schedules.php?engagement_id=' . $engagementId);
}

$data  = build_lead_schedules($engagementId);
$leads = $data['leads'];
$areas = lead_areas();

$pageTitle = 'Lead Schedules — ' . $engagement['company_name'];
require __DIR__ . '/../includes/header.php';
?>

<a href="/audit/lead_schedules.php" class="text-sm text-brand-600 hover:underline">&larr; Change engagement</a>
<a href="/firm/engagement_view.php?id=<?= (int) $engagementId ?>"
   class="ml-3 text-sm text-brand-600 hover:underline">Engagement workspace</a>

<?php
// If the user filtered to a single area, surface the matching SOP step.
// Otherwise the schedule covers many — just link back to workplan.
$filterArea = $_GET['area'] ?? null;
if ($filterArea):
    echo '<div class="mt-3">'
        . workplan_breadcrumb_html((int) $engagementId,
            workplan_step_for_context('wp_lead_area', (string) $filterArea, (int) $engagementId))
        . '</div>';
endif; ?>

<div class="flex flex-wrap items-end justify-between gap-3 mt-1 mb-5">
    <div>
        <h2 class="text-xl font-semibold text-slate-900"><?= e($engagement['company_name']) ?> — Lead Schedules</h2>
        <p class="text-sm text-slate-500">
            <?= e($engagement['financial_year']) ?>
            · <?= count($leads) ?> audit area(s) from the imported trial balance
        </p>
    </div>
    <a href="/import/trial_balance_view.php?engagement_id=<?= (int) $engagementId ?>"
       class="text-sm text-brand-600 hover:underline">Trial balance &amp; variance &rarr;</a>
</div>

<?php if (!$data['has_tb']): ?>
    <div class="bg-white rounded-lg border border-slate-200 p-8 text-center text-sm text-slate-500">
        No trial balance imported yet for this engagement.
        <a href="/import/trial_balance.php?engagement_id=<?= (int) $engagementId ?>"
           class="text-brand-600 hover:underline">Import one now</a>.
    </div>
<?php else: ?>
    <div class="bg-white rounded-lg border border-slate-200 overflow-hidden" data-leads>
        <table class="table-app">
            <thead>
                <tr>
                    <th>Audit area</th>
                    <th class="text-right">Accounts</th>
                    <th class="text-right">Current</th>
                    <th class="text-right">Prior</th>
                    <th class="text-right">Movement</th>
                    <th class="text-right">Δ %</th>
                    <th>Working paper</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($leads as $i => $lead):
                $pctStr = $lead['pct'] === null ? '—' : number_format($lead['pct'], 1) . '%';
                $flagged = $lead['pct'] !== null && abs($lead['pct']) >= 15;
            ?>
                <tr class="cursor-pointer hover:bg-slate-50" data-lead-row="<?= $i ?>">
                    <td class="font-medium">
                        <span class="inline-block w-4 text-slate-400" data-chevron="<?= $i ?>">&rsaquo;</span>
                        <?= e($lead['label']) ?>
                    </td>
                    <td class="text-right tabular-nums"><?= (int) $lead['account_count'] ?></td>
                    <td class="text-right tabular-nums"><?= number_format($lead['cur'], 2) ?></td>
                    <td class="text-right tabular-nums text-slate-500"><?= number_format($lead['pri'], 2) ?></td>
                    <td class="text-right tabular-nums <?= $lead['diff'] < 0 ? 'text-rose-700' : '' ?>">
                        <?= number_format($lead['diff'], 2) ?>
                    </td>
                    <td class="text-right tabular-nums <?= $flagged ? 'text-rose-700 font-medium' : 'text-slate-500' ?>">
                        <?= e($pctStr) ?>
                    </td>
                    <td>
                        <?php if ($lead['wp']): ?>
                            <a href="/audit/working_paper_view.php?id=<?= (int) $lead['wp']['id'] ?>"
                               class="text-sm text-brand-600 hover:underline"
                               onclick="event.stopPropagation()">
                                <?= e($lead['wp']['reference_code'] ?? 'WP') ?>
                            </a>
                            <?= badge($lead['wp']['status']) ?>
                        <?php else: ?>
                            <span class="text-xs text-slate-400">not linked</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-right">
                        <?php if ($canEdit && !$lead['wp']): ?>
                            <form method="post" class="inline" onclick="event.stopPropagation()">
                                <?= csrf_field() ?>
                                <input type="hidden" name="_action" value="create_wp">
                                <input type="hidden" name="audit_area" value="<?= e($lead['area']) ?>">
                                <button class="text-xs rounded bg-brand-600 hover:bg-brand-700 text-white px-2 py-1 whitespace-nowrap">
                                    Create WP
                                </button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
                <!-- Drill-down row -->
                <tr data-lead-detail="<?= $i ?>" hidden>
                    <td colspan="8" class="bg-slate-50 px-6 py-3">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="text-xs uppercase tracking-wide text-slate-500">
                                    <th class="text-left py-1">Code</th>
                                    <th class="text-left py-1">Account</th>
                                    <th class="text-right py-1">Current</th>
                                    <th class="text-right py-1">Prior</th>
                                    <?php if ($canEdit): ?><th class="text-right py-1">Move to</th><?php endif; ?>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($lead['accounts'] as $a): ?>
                                <tr class="border-t border-slate-200">
                                    <td class="font-mono text-xs py-1.5"><?= e($a['code']) ?></td>
                                    <td class="py-1.5">
                                        <?= e($a['name']) ?>
                                        <?php if ($a['overridden']): ?>
                                            <span class="ml-1 text-[10px] text-brand-600 align-middle">reclassified</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-right tabular-nums py-1.5"><?= number_format($a['cur'], 2) ?></td>
                                    <td class="text-right tabular-nums py-1.5 text-slate-500"><?= number_format($a['pri'], 2) ?></td>
                                    <?php if ($canEdit): ?>
                                        <td class="text-right py-1.5">
                                            <form method="post" class="inline-flex items-center gap-1 justify-end">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="_action" value="reclassify">
                                                <input type="hidden" name="account_code" value="<?= e($a['code']) ?>">
                                                <select name="audit_area" class="text-xs rounded border border-slate-300 px-1.5 py-0.5">
                                                    <?php foreach ($areas as $k => $meta): ?>
                                                        <option value="<?= e($k) ?>" <?= $k === $lead['area'] ? 'selected' : '' ?>>
                                                            <?= e($meta['label']) ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <button class="text-xs rounded bg-slate-700 text-white px-2 py-0.5">Set</button>
                                            </form>
                                        </td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr class="bg-slate-50 font-semibold">
                    <td>Total (signed, agrees to TB)</td>
                    <td></td>
                    <td class="text-right tabular-nums"><?= number_format($data['total_cur'], 2) ?></td>
                    <td class="text-right tabular-nums text-slate-500"><?= number_format($data['total_pri'], 2) ?></td>
                    <td class="text-right tabular-nums"><?= number_format($data['total_cur'] - $data['total_pri'], 2) ?></td>
                    <td colspan="3"></td>
                </tr>
            </tfoot>
        </table>
    </div>

    <p class="mt-3 text-xs text-slate-500">
        Balances are shown signed (debit positive, credit negative) so the total agrees to the trial balance.
        Click any row to see the accounts inside. Movements ≥ 15% are flagged.
    </p>
<?php endif; ?>

<script>
(function () {
    document.querySelectorAll('[data-lead-row]').forEach(function (row) {
        row.addEventListener('click', function () {
            var i = row.getAttribute('data-lead-row');
            var detail = document.querySelector('[data-lead-detail="' + i + '"]');
            var chevron = document.querySelector('[data-chevron="' + i + '"]');
            if (detail) {
                detail.hidden = !detail.hidden;
                if (chevron) chevron.style.transform = detail.hidden ? '' : 'rotate(90deg)';
                if (chevron) chevron.style.display = 'inline-block';
            }
        });
    });
})();
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
