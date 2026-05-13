<?php
/**
 * /ai/run.php
 *
 * UI entrypoint: invokes an AI function and renders the result.
 * Accepts GET ?fn=<function>&engagement_id=<id> for safe link-from-buttons,
 * but only after re-confirming via POST (one-click ↦ confirm pattern).
 *
 * Heavier inputs (e.g. trial-balance JSON) can be wired in a later phase
 * by posting JSON to /ai/api.php — kept lightweight for the MVP.
 */

declare(strict_types=1);
require_once __DIR__ . '/../includes/auth_guard.php';
require_role(['firm_admin','audit_manager','senior_auditor','reviewer']);

require_once __DIR__ . '/ai_service.php';

$pdo = db();
$firmId = current_firm_id();

$fn  = $_REQUEST['fn'] ?? '';
$eid = isset($_REQUEST['engagement_id']) ? (int) $_REQUEST['engagement_id'] : 0;

$registry = ai_registry();
if (!isset($registry[$fn])) {
    flash('error', 'Unknown AI function.');
    redirect('/dashboard.php');
}
$config = $registry[$fn];

// Scope-check engagement (if provided)
$engagement = null;
if ($eid > 0) {
    $stmt = $pdo->prepare(
        'SELECT e.*, c.company_name
           FROM engagements e
           JOIN clients c ON c.id = e.client_id
          WHERE e.id = :id AND e.firm_id = :fid'
    );
    $stmt->execute([':id'=>$eid, ':fid'=>$firmId]);
    $engagement = $stmt->fetch();
    if (!$engagement) {
        flash('error', 'Engagement not found.');
        redirect('/firm/engagements.php');
    }
}

// Optional entity context (e.g. working_paper_id for ai_review_working_paper)
$workingPaperId = isset($_REQUEST['working_paper_id']) ? (int) $_REQUEST['working_paper_id'] : 0;

$result = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $payload = ['confirmed' => true];
    if ($workingPaperId > 0) {
        $payload['working_paper_id'] = $workingPaperId;
    }
    $result = ai_run($fn, $payload, $eid ?: null);
    if ($result['ok']) {
        log_activity('ai.run', 'engagement', $eid ?: null, $fn);
    }
}

$pageTitle = $config['title'] ?? 'AI Assistant';
require __DIR__ . '/../includes/header.php';
?>

<?php if ($engagement): ?>
    <a href="/firm/engagement_view.php?id=<?= (int) $eid ?>"
       class="text-sm text-brand-600 hover:underline">&larr; Back to engagement</a>
<?php endif; ?>

<h2 class="text-xl font-semibold text-slate-900 mt-1 mb-2"><?= e($config['title']) ?></h2>
<?php if ($engagement): ?>
    <p class="text-sm text-slate-500 mb-4">
        <?= e($engagement['company_name']) ?> · <?= e($engagement['financial_year']) ?>
    </p>
<?php endif; ?>

<?php if (!$result): ?>
    <div class="bg-white rounded-lg border border-slate-200 p-5 max-w-3xl">
        <p class="text-sm text-slate-700 mb-4">
            <?= e($config['prompt']) ?>
        </p>
        <p class="text-xs text-slate-500 mb-4">
            <?php if (AI_ENABLED): ?>
                Real Claude API calls will run via <code><?= e(AI_MODEL) ?></code>
                (effort=<code><?= e(AI_EFFORT) ?></code>, adaptive thinking).
                Costs from token usage are billed to your firm wallet.
            <?php else: ?>
                <span class="text-amber-700">
                    AI provider not yet configured — stub output will be returned.
                    Set <code>AI_API_KEY</code> in <code>/config/db_config.local.php</code> to enable.
                </span>
            <?php endif; ?>
        </p>

        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="fn" value="<?= e($fn) ?>">
            <?php if ($eid): ?>
                <input type="hidden" name="engagement_id" value="<?= (int) $eid ?>">
            <?php endif; ?>
            <?php if ($workingPaperId): ?>
                <input type="hidden" name="working_paper_id" value="<?= (int) $workingPaperId ?>">
            <?php endif; ?>
            <button class="rounded bg-brand-600 hover:bg-brand-700 text-white px-4 py-2 text-sm font-medium">
                Run AI assistant
            </button>
        </form>
    </div>
<?php else: ?>
    <?php if (!$result['ok']): ?>
        <div class="rounded border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">
            <?= e($result['output']) ?>
        </div>
    <?php else: ?>
        <div class="bg-white rounded-lg border border-slate-200 p-6 prose-sm max-w-none text-slate-800 leading-relaxed">
            <?= md_to_html((string) $result['output']) ?>
        </div>
        <div class="mt-3 flex flex-wrap items-center gap-3 text-xs text-slate-500">
            <span>Log #<?= (int) $result['log_id'] ?></span>
            <?php if (!empty($result['tokens']['total'])): ?>
                <span>
                    <?= number_format((int)($result['tokens']['input'] ?? 0)) ?> in
                    / <?= number_format((int)($result['tokens']['output'] ?? 0)) ?> out
                </span>
            <?php endif; ?>
            <?php if (($result['credits'] ?? 0) > 0): ?>
                <span>$<?= number_format((float) $result['credits'], 4) ?></span>
            <?php endif; ?>
            <?php if (!empty($result['latency_ms'])): ?>
                <span><?= number_format((int) $result['latency_ms']) ?>ms</span>
            <?php endif; ?>
        </div>
        <div class="mt-4 flex flex-wrap gap-2">
            <a href="/ai/outputs.php<?= $eid ? '?engagement_id=' . (int) $eid : '' ?>"
               class="rounded bg-brand-600 hover:bg-brand-700 text-white px-3 py-1.5 text-sm">
                Open in AI Outputs
            </a>
            <?php if ($engagement): ?>
                <a href="/firm/engagement_view.php?id=<?= (int) $eid ?>"
                   class="rounded border border-slate-300 px-3 py-1.5 text-sm hover:bg-slate-50">
                    Back to engagement
                </a>
            <?php endif; ?>
            <a href="/ai/run.php?fn=<?= e($fn) ?><?= $eid ? '&engagement_id=' . (int) $eid : '' ?><?= $workingPaperId ? '&working_paper_id=' . (int) $workingPaperId : '' ?>"
               class="text-sm text-brand-600 hover:underline self-center">Run again</a>
        </div>
    <?php endif; ?>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
