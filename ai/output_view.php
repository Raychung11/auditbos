<?php
/**
 * /ai/output_view.php
 *
 * Single AI output viewer with markdown rendering, raw-text toggle,
 * and accept/reject actions. Status changes are scope-checked to the
 * current firm.
 */

declare(strict_types=1);
require_once __DIR__ . '/../includes/auth_guard.php';
require_role(['firm_admin','audit_manager','senior_auditor','reviewer']);

$pdo    = db();
$firmId = current_firm_id();
$id     = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($id <= 0) {
    flash('error', 'Invalid output.');
    redirect('/ai/outputs.php');
}

// POST: status update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['_action'] ?? '';
    $newStatus = match ($action) {
        'accept'    => 'accepted',
        'reject'    => 'rejected',
        'publish'   => 'published',
        'reopen'    => 'draft',
        default     => null,
    };
    if ($newStatus !== null) {
        $stmt = $pdo->prepare(
            'UPDATE ai_outputs SET status = :s WHERE id = :id AND firm_id = :fid'
        );
        $stmt->execute([':s'=>$newStatus, ':id'=>$id, ':fid'=>$firmId]);
        log_activity('ai_output.' . $action, 'ai_output', $id, $newStatus);
        flash('success', "Output marked {$newStatus}.");
    }
    redirect('/ai/output_view.php?id=' . $id);
}

$stmt = $pdo->prepare(
    'SELECT ao.*, e.financial_year, c.company_name, u.name AS created_by_name,
            al.input_tokens, al.output_tokens, al.credits_used, al.latency_ms,
            al.function_name, al.prompt
       FROM ai_outputs ao
       LEFT JOIN engagements e ON e.id = ao.engagement_id
       LEFT JOIN clients c     ON c.id = e.client_id
       LEFT JOIN users u       ON u.id = ao.created_by
       LEFT JOIN ai_logs al    ON al.id = ao.ai_log_id
      WHERE ao.id = :id AND ao.firm_id = :fid'
);
$stmt->execute([':id'=>$id, ':fid'=>$firmId]);
$out = $stmt->fetch();
if (!$out) {
    flash('error', 'Output not found.');
    redirect('/ai/outputs.php');
}

$pageTitle = $out['title'] ?? 'AI Output';
require __DIR__ . '/../includes/header.php';
?>

<a href="/ai/outputs.php" class="text-sm text-brand-600 hover:underline">&larr; All AI outputs</a>
<?php if ($out['engagement_id']): ?>
    · <a href="/firm/engagement_view.php?id=<?= (int) $out['engagement_id'] ?>"
         class="text-sm text-brand-600 hover:underline">Engagement workspace</a>
<?php endif; ?>

<div class="flex flex-wrap items-start justify-between gap-3 mt-1 mb-4">
    <div>
        <h2 class="text-xl font-semibold text-slate-900"><?= e($out['title'] ?? $out['output_type']) ?></h2>
        <p class="text-sm text-slate-500 mt-1">
            <?php if ($out['company_name']): ?>
                <?= e($out['company_name']) ?> · <?= e($out['financial_year'] ?? '') ?> ·
            <?php endif; ?>
            <code class="text-xs"><?= e($out['function_name'] ?? $out['output_type']) ?></code>
            · <?= e(datefmt($out['created_at'], 'd M Y H:i')) ?>
            <?php if ($out['created_by_name']): ?>
                · <?= e($out['created_by_name']) ?>
            <?php endif; ?>
        </p>
        <div class="mt-2 flex items-center gap-2">
            <?= badge($out['status']) ?>
            <?php if ($out['input_tokens'] || $out['output_tokens']): ?>
                <span class="text-xs text-slate-500">
                    <?= number_format((int) $out['input_tokens']) ?> in
                    / <?= number_format((int) $out['output_tokens']) ?> out
                    <?php if ($out['credits_used']): ?>
                        · MYR <?= number_format((float) $out['credits_used'], 4) ?>
                    <?php endif; ?>
                    <?php if ($out['latency_ms']): ?>
                        · <?= number_format((int) $out['latency_ms']) ?>ms
                    <?php endif; ?>
                </span>
            <?php endif; ?>
        </div>
    </div>

    <!-- Workflow actions -->
    <div class="flex flex-wrap items-center gap-2">
        <?php if ($out['status'] === 'draft'): ?>
            <form method="post" class="inline">
                <?= csrf_field() ?>
                <input type="hidden" name="_action" value="accept">
                <button class="rounded bg-emerald-600 hover:bg-emerald-700 text-white px-3 py-1.5 text-sm">Accept</button>
            </form>
            <form method="post" class="inline">
                <?= csrf_field() ?>
                <input type="hidden" name="_action" value="reject">
                <button class="rounded bg-rose-600 hover:bg-rose-700 text-white px-3 py-1.5 text-sm">Reject</button>
            </form>
        <?php elseif ($out['status'] === 'accepted'): ?>
            <form method="post" class="inline">
                <?= csrf_field() ?>
                <input type="hidden" name="_action" value="publish">
                <button class="rounded bg-brand-600 hover:bg-brand-700 text-white px-3 py-1.5 text-sm">Publish</button>
            </form>
            <form method="post" class="inline">
                <?= csrf_field() ?>
                <input type="hidden" name="_action" value="reopen">
                <button class="rounded border border-slate-300 px-3 py-1.5 text-sm hover:bg-slate-50">Reopen</button>
            </form>
        <?php else: ?>
            <form method="post" class="inline">
                <?= csrf_field() ?>
                <input type="hidden" name="_action" value="reopen">
                <button class="rounded border border-slate-300 px-3 py-1.5 text-sm hover:bg-slate-50">Reopen as draft</button>
            </form>
        <?php endif; ?>
        <button id="toggle-raw" type="button"
                class="rounded border border-slate-300 px-3 py-1.5 text-sm hover:bg-slate-50">
            Show raw
        </button>
        <button id="copy-output" type="button"
                class="rounded border border-slate-300 px-3 py-1.5 text-sm hover:bg-slate-50">
            Copy
        </button>
    </div>
</div>

<div id="rendered-output" class="bg-white rounded-lg border border-slate-200 p-6 prose-sm max-w-none text-slate-800 leading-relaxed">
    <?= md_to_html((string) $out['content']) ?>
</div>
<pre id="raw-output" class="hidden bg-slate-900 text-slate-100 rounded-lg p-5 text-xs whitespace-pre-wrap"><?= e((string) $out['content']) ?></pre>

<script>
(function () {
    var btn = document.getElementById('toggle-raw');
    var rendered = document.getElementById('rendered-output');
    var raw = document.getElementById('raw-output');
    btn.addEventListener('click', function () {
        var showingRaw = !raw.classList.contains('hidden');
        if (showingRaw) {
            raw.classList.add('hidden');
            rendered.classList.remove('hidden');
            btn.textContent = 'Show raw';
        } else {
            raw.classList.remove('hidden');
            rendered.classList.add('hidden');
            btn.textContent = 'Show formatted';
        }
    });
    document.getElementById('copy-output').addEventListener('click', function () {
        var text = <?= json_encode((string) $out['content']) ?>;
        if (navigator.clipboard) {
            navigator.clipboard.writeText(text).then(function () {
                var btn = document.getElementById('copy-output');
                var orig = btn.textContent;
                btn.textContent = 'Copied!';
                setTimeout(function () { btn.textContent = orig; }, 1500);
            });
        }
    });
})();
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
