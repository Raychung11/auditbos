<?php
/**
 * /audit/completion.php
 *
 * Audit completion checklist (workplan step 25). Auto-driven items
 * read engagement state directly; manual items are signed off here.
 * When every item is ticked, the page nudges the partner to advance
 * the engagement to partner_review.
 */

declare(strict_types=1);
require_once __DIR__ . '/../includes/auth_guard.php';
require_role(['firm_admin','audit_manager','senior_auditor','reviewer']);
require_once __DIR__ . '/../includes/workflow.php';
require_once __DIR__ . '/../includes/workplan.php';
require_once __DIR__ . '/../includes/completion.php';

$pdo    = db();
$firmId = current_firm_id();
$engagementId = isset($_GET['engagement_id']) ? (int) $_GET['engagement_id'] : 0;

if ($engagementId <= 0) {
    $picker = $pdo->prepare(
        'SELECT e.id, e.financial_year, c.company_name
           FROM engagements e
           JOIN clients c ON c.id = e.client_id
          WHERE e.firm_id = :f ORDER BY e.updated_at DESC LIMIT 50'
    );
    $picker->execute([':f' => $firmId]);
    $engs = $picker->fetchAll();
    $pageTitle = 'Audit completion';
    require __DIR__ . '/../includes/header.php';
    ?>
    <h2 class="text-xl font-semibold text-slate-900 mb-2">Audit completion</h2>
    <p class="text-sm text-slate-500 mb-4">Pick an engagement.</p>
    <div class="bg-white rounded-lg border border-slate-200 divide-y divide-slate-100">
        <?php foreach ($engs as $e): ?>
            <a href="?engagement_id=<?= (int) $e['id'] ?>"
               class="block px-4 py-3 text-sm hover:bg-slate-50">
                <?= e($e['company_name']) ?> <span class="text-slate-500">· <?= e($e['financial_year']) ?></span>
            </a>
        <?php endforeach; ?>
    </div>
    <?php
    require __DIR__ . '/../includes/footer.php';
    exit;
}

$engStmt = $pdo->prepare(
    'SELECT e.*, c.company_name
       FROM engagements e
       JOIN clients c ON c.id = e.client_id
      WHERE e.id = :id AND e.firm_id = :f'
);
$engStmt->execute([':id' => $engagementId, ':f' => $firmId]);
$engagement = $engStmt->fetch();
if (!$engagement) {
    flash('error', 'Engagement not found.');
    redirect('/firm/engagements.php');
}

$canEdit = role_allows(['firm_admin','audit_manager','senior_auditor']);

if (!table_exists('completion_checklist')) {
    $pageTitle = 'Completion — ' . $engagement['company_name'];
    require __DIR__ . '/../includes/header.php';
    ?>
    <div class="bg-amber-50 border border-amber-200 rounded p-4 text-sm text-amber-900">
        <strong>Migration not applied.</strong> Run <code>/sql/010_gap_modules.sql</code>.
    </div>
    <?php require __DIR__ . '/../includes/footer.php';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (!$canEdit) { http_response_code(403); exit('Forbidden'); }
    assert_engagement_open($engagementId);

    $itemKey = (string) ($_POST['item_key'] ?? '');
    $isDone  = !empty($_POST['is_done']);
    $notes   = trim((string) ($_POST['notes'] ?? '')) ?: null;

    // Only manual items can be set here — auto items derive from state.
    $template = completion_items_template();
    $manualKeys = array_column(array_filter($template, fn($i) => !$i['auto']), 'key');
    if (!in_array($itemKey, $manualKeys, true)) {
        flash('error', 'That item is auto-driven and cannot be ticked manually.');
    } else {
        completion_set_item($engagementId, $itemKey, $isDone, $notes, current_user_id());
        log_activity('completion.set', 'engagement', $engagementId,
            $itemKey . ' → ' . ($isDone ? 'done' : 'pending'));
        flash('success', 'Checklist updated.');
    }
    redirect('/audit/completion.php?engagement_id=' . $engagementId);
}

workplan_sync_status($engagementId);
$items   = completion_checklist($engagementId);
$doneN   = count(array_filter($items, fn($i) => $i['is_done']));
$totalN  = count($items);
$isReady = $doneN === $totalN;

$pageTitle = 'Completion — ' . $engagement['company_name'];
require __DIR__ . '/../includes/header.php';
?>

<a href="/audit/completion.php" class="text-sm text-brand-600 hover:underline">&larr; Change engagement</a>
<a href="/firm/engagement_view.php?id=<?= (int) $engagementId ?>"
   class="ml-3 text-sm text-brand-600 hover:underline">Engagement workspace</a>

<div class="mt-3">
<?php echo workplan_breadcrumb_html((int) $engagementId,
    workplan_step_for_context('module', 'completion', (int) $engagementId)); ?>
</div>

<div class="mt-1 mb-4 flex flex-wrap items-end justify-between gap-3">
    <div>
        <h2 class="text-xl font-semibold text-slate-900">
            <?= e($engagement['company_name']) ?> — Completion checklist
        </h2>
        <p class="text-sm text-slate-500"><?= e($engagement['financial_year']) ?> · gate before partner sign-off</p>
    </div>
    <div class="flex items-end gap-4">
        <?php if (defined('AI_ENABLED') && AI_ENABLED): ?>
            <a href="/ai/run.php?fn=ai_summarize_engagement_status&engagement_id=<?= (int) $engagementId ?>"
               class="inline-flex items-center gap-1 rounded bg-violet-600 hover:bg-violet-700 text-white px-3 py-1.5 text-sm font-medium">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/>
                </svg>
                AI completion summary
            </a>
        <?php endif; ?>
        <div class="text-right">
            <div class="text-2xl font-bold <?= $isReady ? 'text-emerald-700' : 'text-slate-900' ?>">
                <?= $doneN ?> / <?= $totalN ?>
            </div>
            <div class="text-xs text-slate-500">items cleared</div>
        </div>
    </div>
</div>

<?php if ($isReady): ?>
    <div class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 mb-4 text-sm text-emerald-900">
        <strong>Ready for partner sign-off.</strong> All completion items cleared.
        Advance the workflow from the
        <a href="/firm/engagement_view.php?id=<?= (int) $engagementId ?>#workflow"
           class="underline hover:no-underline">engagement workspace</a>.
    </div>
<?php endif; ?>

<div class="space-y-2">
    <?php foreach ($items as $item):
        $isDone = $item['is_done'];
        $rowCls = $isDone ? 'bg-emerald-50/40 border-emerald-200' : 'bg-white border-slate-200';
    ?>
        <div class="rounded-lg border <?= $rowCls ?> p-4">
            <div class="flex items-start gap-3">
                <span class="shrink-0 mt-0.5 inline-flex w-6 h-6 rounded-full items-center justify-center
                            <?= $isDone ? 'bg-emerald-500 text-white' : 'bg-slate-200 text-slate-500' ?>">
                    <?php if ($isDone): ?>
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/>
                        </svg>
                    <?php endif; ?>
                </span>
                <div class="flex-1 min-w-0">
                    <div class="flex items-center gap-2 flex-wrap">
                        <span class="font-medium text-slate-900"><?= e($item['label']) ?></span>
                        <span class="text-[10px] uppercase tracking-wide
                                    <?= $item['auto'] ? 'text-violet-700' : 'text-slate-400' ?>">
                            <?= $item['auto'] ? 'auto' : 'manual sign-off' ?>
                        </span>
                    </div>
                    <div class="text-xs text-slate-500 mt-0.5"><?= e($item['desc']) ?></div>

                    <?php if (!$item['auto'] && $item['signed_off_by_name']): ?>
                        <div class="text-xs text-slate-500 mt-1">
                            Signed off by <strong><?= e($item['signed_off_by_name']) ?></strong>
                            on <?= e(datefmt($item['signed_off_at'], 'd M Y H:i')) ?>
                        </div>
                    <?php endif; ?>

                    <?php if (!$item['auto'] && $item['notes']): ?>
                        <div class="text-xs text-slate-700 mt-1 italic">&ldquo;<?= e($item['notes']) ?>&rdquo;</div>
                    <?php endif; ?>

                    <?php if (!$item['auto'] && $canEdit && !engagement_locked($engagementId)): ?>
                        <form method="post" class="mt-2 flex flex-wrap items-start gap-2">
                            <?= csrf_field() ?>
                            <input type="hidden" name="item_key" value="<?= e($item['key']) ?>">
                            <label class="inline-flex items-center gap-2 text-sm">
                                <input type="checkbox" name="is_done" value="1" <?= $isDone ? 'checked' : '' ?>>
                                Mark done
                            </label>
                            <input name="notes" value="<?= e($item['notes']) ?>" placeholder="Sign-off note (optional)"
                                   class="flex-1 min-w-[200px] rounded border border-slate-300 px-2 py-1 text-sm">
                            <button class="rounded bg-brand-600 hover:bg-brand-700 text-white text-xs px-3 py-1">
                                Save
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<?php
require __DIR__ . '/../includes/footer.php';
