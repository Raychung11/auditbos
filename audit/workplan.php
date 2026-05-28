<?php
/**
 * /audit/workplan.php
 *
 * The 27-step audit SOP workplan for an engagement. Lists every lead in
 * order, grouped by phase (Planning / Fieldwork / Completion / Reporting).
 *
 * Each step supports:
 *   - Inline status change (not_started → in_progress → prepared →
 *     reviewed → cleared, plus not_applicable)
 *   - Owner + reviewer assignment from firm staff
 *   - Due date
 *   - Notes
 *
 * Steps with an automation_hook are auto-progressed by workplan_sync_status
 * when the underlying feature reaches its done state (e.g. a TB import
 * clears step 2 automatically).
 */

declare(strict_types=1);
require_once __DIR__ . '/../includes/auth_guard.php';
require_role(['firm_admin','audit_manager','senior_auditor','junior_auditor','reviewer']);
require_once __DIR__ . '/../includes/workflow.php';
require_once __DIR__ . '/../includes/workplan.php';

$pdo    = db();
$firmId = current_firm_id();
$eid    = isset($_GET['eid']) ? (int) $_GET['eid'] : 0;

if ($eid <= 0) {
    flash('error', 'Pick an engagement first.');
    redirect('/firm/engagements.php');
}

// Scope check
$eStmt = $pdo->prepare(
    'SELECT e.*, c.company_name
       FROM engagements e
       JOIN clients c ON c.id = e.client_id
      WHERE e.id = :id AND e.firm_id = :fid'
);
$eStmt->execute([':id' => $eid, ':fid' => $firmId]);
$eng = $eStmt->fetch();
if (!$eng) {
    flash('error', 'Engagement not found.');
    redirect('/firm/engagements.php');
}

$canEdit = role_allows(['firm_admin','audit_manager','senior_auditor','junior_auditor','reviewer']);

if (!table_exists('engagement_workplan')) {
    $pageTitle = 'Audit workplan · ' . $eng['company_name'];
    require __DIR__ . '/../includes/header.php';
    ?>
    <div class="bg-amber-50 border border-amber-200 rounded p-4 text-sm text-amber-900">
        <strong>Migration not applied.</strong>
        Run <code>/sql/009_workplan.sql</code> to enable the 27-step audit
        workplan. Once the table exists, this page will auto-seed the
        engagement.
    </div>
    <?php
    require __DIR__ . '/../includes/footer.php';
    exit;
}

// ---------------------------------------------------------------------
// POST: update a single step.
// ---------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (!$canEdit) { http_response_code(403); exit('Forbidden'); }
    assert_engagement_open($eid);

    $stepNo = (int) ($_POST['step_no'] ?? 0);
    $action = (string) ($_POST['_action'] ?? '');

    if ($stepNo <= 0 || $stepNo > 27) {
        flash('error', 'Invalid step.');
        redirect('/audit/workplan.php?eid=' . $eid);
    }

    if ($action === 'status') {
        $st = (string) ($_POST['status'] ?? '');
        workplan_update_step($eid, $stepNo, ['status' => $st]);
        log_activity('workplan.status', 'engagement', $eid,
            sprintf('Step %d → %s', $stepNo, $st));
        flash('success', 'Step status updated.');
    } elseif ($action === 'assign') {
        $own = (int) ($_POST['owner_user_id'] ?? 0) ?: null;
        $rev = (int) ($_POST['reviewer_user_id'] ?? 0) ?: null;
        $due = trim((string) ($_POST['due_date'] ?? '')) ?: null;
        $notes = trim((string) ($_POST['notes'] ?? '')) ?: null;
        workplan_update_step($eid, $stepNo, [
            'owner_user_id'    => $own,
            'reviewer_user_id' => $rev,
            'due_date'         => $due,
            'notes'            => $notes,
        ]);
        log_activity('workplan.assign', 'engagement', $eid,
            sprintf('Step %d assigned (owner=%s, reviewer=%s)', $stepNo, $own ?? '-', $rev ?? '-'));
        flash('success', 'Step assignment updated.');
    } elseif ($action === 'reseed') {
        // Re-runs the seeder; INSERT IGNORE means only missing steps are added.
        $n = workplan_seed_engagement($eid);
        flash('success', $n > 0
            ? "Workplan seeded ({$n} new steps)."
            : 'Workplan already complete (all 27 steps present).');
    } elseif ($action === 'kickoff') {
        // Reverse hook: start the step + land in the right module
        // (creates a WP for lead-area steps if one doesn't yet exist).
        $route = workplan_kickoff_step($eid, $stepNo, current_user_id());
        if ($route) {
            flash('success', 'Step started — opening the matching module.');
            redirect($route);
        }
        flash('success', 'Step started.');
    }
    redirect('/audit/workplan.php?eid=' . $eid);
}

// Auto-sync hook-based steps before render.
workplan_sync_status($eid);

$steps     = workplan_load($eid);
$summary   = workplan_summary($eid);
$staff     = workplan_staff($firmId);
$phases    = workplan_phases();
$statuses  = workplan_statuses();
$wpCounts  = workplan_wp_counts($eid);
$aiCounts  = workplan_ai_counts($eid);

// Group steps by phase for the rendered sections.
$byPhase = array_fill_keys(array_keys($phases), []);
foreach ($steps as $s) {
    $byPhase[$s['phase']][] = $s;
}

$pageTitle = 'Audit workplan · ' . $eng['company_name'];
require __DIR__ . '/../includes/header.php';
?>

<div class="flex flex-wrap items-start justify-between gap-3 mb-4">
    <div>
        <a href="/firm/engagement_view.php?id=<?= (int) $eid ?>"
           class="text-sm text-brand-600 hover:underline">&larr; Back to workspace</a>
        <h2 class="text-xl font-semibold text-slate-900 mt-1">
            Audit workplan
            <span class="text-base font-normal text-slate-500">
                · <?= e($eng['company_name']) ?> · <?= e($eng['financial_year']) ?>
            </span>
        </h2>
        <p class="text-sm text-slate-500 mt-1">
            The 27-step SOP every audit follows. Assign owners + reviewers, track status,
            and let underlying features auto-advance hooked steps.
        </p>
    </div>
    <?php if ($canEdit && !engagement_locked($eid)): ?>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="_action" value="reseed">
            <input type="hidden" name="step_no" value="1">
            <button class="rounded bg-slate-100 hover:bg-slate-200 text-slate-700 px-3 py-1.5 text-sm">
                Re-seed missing steps
            </button>
        </form>
    <?php endif; ?>
</div>

<!-- Progress summary -->
<div class="bg-white rounded-lg border border-slate-200 p-5 mb-6">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div>
            <div class="text-3xl font-bold text-slate-900">
                <?= (int) $summary['cleared'] ?> / <?= (int) $summary['total'] ?>
                <span class="text-base text-slate-500 font-medium">steps cleared</span>
            </div>
            <div class="text-xs text-slate-500 mt-1">
                <?= (int) $summary['in_progress'] ?> in progress ·
                <?= (int) $summary['not_started'] ?> not started
            </div>
        </div>
        <div class="flex-1 max-w-md">
            <div class="h-3 bg-slate-100 rounded-full overflow-hidden">
                <div class="h-full bg-emerald-500 transition-all" style="width: <?= (int) $summary['pct'] ?>%"></div>
            </div>
        </div>
    </div>
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-2 mt-4">
        <?php foreach ($phases as $key => $label):
            $p   = $summary['by_phase'][$key] ?? ['total'=>0,'cleared'=>0];
            $pct = $p['total'] > 0 ? (int) round(($p['cleared'] / $p['total']) * 100) : 0;
        ?>
            <div class="rounded border border-slate-200 px-3 py-2">
                <div class="text-xs uppercase tracking-wide text-slate-500"><?= e($label) ?></div>
                <div class="text-sm font-semibold text-slate-900 mt-0.5">
                    <?= (int) $p['cleared'] ?> / <?= (int) $p['total'] ?>
                    <span class="text-xs text-slate-500 font-normal">(<?= $pct ?>%)</span>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<?php foreach ($phases as $phaseKey => $phaseLabel):
    $phaseSteps = $byPhase[$phaseKey] ?? [];
    if (empty($phaseSteps)) { continue; }
?>
    <div class="mb-6">
        <div class="flex items-center gap-2 mb-2">
            <h3 class="font-semibold text-slate-900"><?= e($phaseLabel) ?></h3>
            <span class="text-xs text-slate-500">
                <?= count(array_filter($phaseSteps, fn($s) => $s['status'] === 'cleared' || $s['status'] === 'not_applicable')) ?>
                / <?= count($phaseSteps) ?> cleared
            </span>
        </div>
        <div class="space-y-2">
            <?php foreach ($phaseSteps as $s):
                $isClosed = in_array($s['status'], ['cleared','not_applicable'], true);
                $hasHook  = !empty($s['automation_hook']);
                $isLocked = engagement_locked($eid);
                $spec     = workplan_step_to_wp_spec($s['code']);
                $wpStat   = $spec ? ($wpCounts[$spec['lead_area']] ?? null) : null;
                // AI runs linked to this step (matched by output_type via the
                // hook map — only a few step codes have a direct AI output).
                static $aiKeyByCode = [
                    'subsequent_events' => 'variance_detect',
                    'going_concern'     => 'going_concern',
                    'audit_report'      => 'audit_report',
                    'completion'        => 'engagement_summary',
                ];
                $aiKey  = $aiKeyByCode[$s['code']] ?? null;
                $aiStat = $aiKey ? ($aiCounts[$aiKey] ?? null) : null;
            ?>
                <details class="bg-white rounded-lg border border-slate-200 group">
                    <summary class="cursor-pointer list-none flex items-center gap-3 p-3 hover:bg-slate-50 rounded-lg">
                        <span class="shrink-0 w-8 h-8 rounded-full flex items-center justify-center text-xs font-semibold
                                    <?= $isClosed
                                        ? 'bg-emerald-100 text-emerald-800'
                                        : 'bg-slate-100 text-slate-600' ?>">
                            <?= (int) $s['step_no'] ?>
                        </span>
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-2 flex-wrap">
                                <span class="font-medium text-slate-900 text-sm"><?= e($s['title']) ?></span>
                                <?= workplan_status_badge($s['status']) ?>
                                <?php if ($hasHook): ?>
                                    <span class="text-[10px] uppercase tracking-wide text-slate-400">auto</span>
                                <?php endif; ?>
                            </div>
                            <div class="text-xs text-slate-500 mt-0.5 flex items-center gap-3 flex-wrap">
                                <?php if (!empty($s['owner_name'])): ?>
                                    <span>Owner: <?= e($s['owner_name']) ?></span>
                                <?php endif; ?>
                                <?php if (!empty($s['reviewer_name'])): ?>
                                    <span>Reviewer: <?= e($s['reviewer_name']) ?></span>
                                <?php endif; ?>
                                <?php if (!empty($s['due_date'])): ?>
                                    <span>Due <?= e(datefmt($s['due_date'])) ?></span>
                                <?php endif; ?>
                                <?php if ($wpStat): ?>
                                    <span class="rounded bg-slate-100 px-1.5 py-0.5 text-[11px]">
                                        WPs <?= (int) $wpStat['cleared'] ?>/<?= (int) $wpStat['total'] ?>
                                    </span>
                                <?php endif; ?>
                                <?php if ($aiStat): ?>
                                    <span class="rounded bg-violet-50 text-violet-700 px-1.5 py-0.5 text-[11px]">
                                        AI <?= (int) $aiStat['accepted'] ?>/<?= (int) $aiStat['runs'] ?>
                                    </span>
                                <?php endif; ?>
                                <?php if (!empty($s['depends_on'])): ?>
                                    <span class="text-slate-400">depends on §<?= e($s['depends_on']) ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <svg class="w-4 h-4 text-slate-400 group-open:rotate-180 transition" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                        </svg>
                    </summary>

                    <div class="border-t border-slate-200 px-4 py-3 space-y-4">
                        <?php if (!empty($s['procedures_md'])): ?>
                            <div>
                                <div class="text-xs uppercase tracking-wide text-slate-500 mb-1">Embedded procedures</div>
                                <div class="prose prose-sm max-w-none text-slate-700">
                                    <?= md_to_html($s['procedures_md']) ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php
                        // Step-specific deep links to the existing modules that
                        // do the work for this lead.
                        $links = workplan_step_links($s['code'], (int) $eid);
                        ?>
                        <?php
                        $aiHook = workplan_step_ai($s['code']);
                        $aiUrl  = $aiHook ? workplan_step_ai_url($s['code'], (int) $eid) : null;
                        ?>
                        <div class="flex flex-wrap gap-2 items-center">
                            <?php if ($canEdit && !$isLocked && !$isClosed): ?>
                                <form method="post" class="inline">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="_action" value="kickoff">
                                    <input type="hidden" name="step_no" value="<?= (int) $s['step_no'] ?>">
                                    <button class="inline-flex items-center gap-1 rounded bg-emerald-600 hover:bg-emerald-700 text-white text-xs px-2.5 py-1 font-medium">
                                        <?= $spec ? 'Start step &amp; create WP' : 'Start step' ?> &rarr;
                                    </button>
                                </form>
                            <?php endif; ?>
                            <?php if ($aiHook && $aiUrl): ?>
                                <a href="<?= e($aiUrl) ?>"
                                   class="inline-flex items-center gap-1 rounded bg-violet-600 hover:bg-violet-700 text-white text-xs px-2.5 py-1 font-medium">
                                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                              d="M13 10V3L4 14h7v7l9-11h-7z"/>
                                    </svg>
                                    <?= e($aiHook['label']) ?>
                                </a>
                            <?php endif; ?>
                            <?php foreach ($links as $lnk): ?>
                                <a href="<?= e($lnk['href']) ?>"
                                   class="inline-flex items-center gap-1 rounded bg-brand-50 hover:bg-brand-100 text-brand-700 text-xs px-2 py-1">
                                    <?= e($lnk['label']) ?> &rarr;
                                </a>
                            <?php endforeach; ?>
                        </div>

                        <?php if ($canEdit && !$isLocked): ?>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                                <form method="post" class="rounded border border-slate-200 p-3 space-y-2">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="_action" value="status">
                                    <input type="hidden" name="step_no" value="<?= (int) $s['step_no'] ?>">
                                    <label class="block text-xs font-medium text-slate-700">Set status</label>
                                    <div class="flex gap-2">
                                        <select name="status" class="flex-1 rounded border border-slate-300 px-2 py-1 text-sm">
                                            <?php foreach ($statuses as $stKey => $stMeta): ?>
                                                <option value="<?= e($stKey) ?>" <?= $s['status'] === $stKey ? 'selected' : '' ?>>
                                                    <?= e($stMeta['label']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button class="rounded bg-brand-600 hover:bg-brand-700 text-white text-sm px-3 py-1">
                                            Save
                                        </button>
                                    </div>
                                </form>

                                <form method="post" class="rounded border border-slate-200 p-3 space-y-2">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="_action" value="assign">
                                    <input type="hidden" name="step_no" value="<?= (int) $s['step_no'] ?>">
                                    <label class="block text-xs font-medium text-slate-700">Assign owner / reviewer / due date</label>
                                    <div class="grid grid-cols-2 gap-2">
                                        <select name="owner_user_id" class="rounded border border-slate-300 px-2 py-1 text-sm">
                                            <option value="">— Owner —</option>
                                            <?php foreach ($staff as $u): ?>
                                                <option value="<?= (int) $u['id'] ?>"
                                                        <?= (int) $s['owner_user_id'] === (int) $u['id'] ? 'selected' : '' ?>>
                                                    <?= e($u['name']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <select name="reviewer_user_id" class="rounded border border-slate-300 px-2 py-1 text-sm">
                                            <option value="">— Reviewer —</option>
                                            <?php foreach ($staff as $u): ?>
                                                <option value="<?= (int) $u['id'] ?>"
                                                        <?= (int) $s['reviewer_user_id'] === (int) $u['id'] ? 'selected' : '' ?>>
                                                    <?= e($u['name']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <input type="date" name="due_date" value="<?= e($s['due_date']) ?>"
                                               class="rounded border border-slate-300 px-2 py-1 text-sm">
                                        <button class="rounded bg-slate-700 hover:bg-slate-800 text-white text-sm px-3 py-1">
                                            Save
                                        </button>
                                    </div>
                                    <textarea name="notes" rows="2" placeholder="Step notes / coaching for owner..."
                                              class="mt-1 w-full rounded border border-slate-300 px-2 py-1 text-sm"><?= e($s['notes']) ?></textarea>
                                </form>
                            </div>
                        <?php elseif ($isLocked): ?>
                            <div class="text-xs text-slate-500 italic">Engagement is locked — workplan is read-only.</div>
                        <?php endif; ?>
                    </div>
                </details>
            <?php endforeach; ?>
        </div>
    </div>
<?php endforeach; ?>

<?php
require __DIR__ . '/../includes/footer.php';
