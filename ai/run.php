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

$result = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $result = ai_run($fn, ['confirmed' => true, 'engagement_id' => $eid], $eid ?: null);
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
            Running this call will consume
            <span class="font-medium"><?= number_format(AI_CREDITS_PER_CALL, 2) ?></span>
            credit(s) from your firm wallet and is recorded in the AI audit log.
            <?php if (!AI_ENABLED): ?>
                <span class="block mt-2 text-amber-700">
                    AI provider not yet configured — a sample stub response will be returned.
                </span>
            <?php endif; ?>
        </p>

        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="fn" value="<?= e($fn) ?>">
            <?php if ($eid): ?>
                <input type="hidden" name="engagement_id" value="<?= (int) $eid ?>">
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
        <div class="bg-white rounded-lg border border-slate-200 p-5 max-w-3xl">
            <div class="text-xs text-slate-500 mb-2">
                Log ID #<?= (int) $result['log_id'] ?> · output type:
                <code><?= e($result['output_type']) ?></code>
            </div>
            <pre class="whitespace-pre-wrap text-sm text-slate-800 font-sans"><?= e($result['output']) ?></pre>
            <div class="mt-4 flex gap-2">
                <?php if ($engagement): ?>
                    <a href="/firm/engagement_view.php?id=<?= (int) $eid ?>"
                       class="rounded border border-slate-300 px-3 py-1.5 text-sm hover:bg-slate-50">
                        Back to engagement
                    </a>
                <?php endif; ?>
                <a href="/ai/run.php?fn=<?= e($fn) ?><?= $eid ? '&engagement_id=' . $eid : '' ?>"
                   class="text-sm text-brand-600 hover:underline">Run again</a>
            </div>
        </div>
    <?php endif; ?>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
