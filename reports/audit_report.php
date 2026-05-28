<?php
/**
 * /reports/audit_report.php?engagement_id=…
 *
 * Generate and view the Independent Auditor's Report draft. Pick the
 * opinion type + parameters, the AI drafts the full ISA 700 / Companies
 * Act 2016 report, stored as an ai_outputs row (output_type=audit_report)
 * and rendered with print/PDF support.
 */

declare(strict_types=1);
require_once __DIR__ . '/../includes/auth_guard.php';
require_once __DIR__ . '/../includes/workplan.php';
require_role(['firm_admin','audit_manager','senior_auditor','reviewer']);
require_once __DIR__ . '/../includes/workflow.php';

$pdo    = db();
$firmId = current_firm_id();
$engagementId = isset($_GET['engagement_id']) ? (int) $_GET['engagement_id'] : 0;

// ---------------------------------------------------------------------
// Engagement picker.
// ---------------------------------------------------------------------
if ($engagementId <= 0) {
    $list = $pdo->prepare(
        'SELECT e.id, e.financial_year, e.status, c.company_name
           FROM engagements e JOIN clients c ON c.id = e.client_id
          WHERE e.firm_id = :f AND e.status NOT IN ("archived")
          ORDER BY c.company_name, e.financial_year DESC'
    );
    $list->execute([':f' => $firmId]);
    $engagements = $list->fetchAll();

    $pageTitle = 'Auditor\'s Report';
    require __DIR__ . '/../includes/header.php';
    ?>
    <div class="bg-white rounded-lg border border-slate-200 overflow-hidden">
        <div class="px-5 py-3 border-b border-slate-200">
            <h3 class="font-semibold text-slate-900">Pick an engagement</h3>
        </div>
        <?php if (empty($engagements)): ?>
            <div class="p-8 text-center text-sm text-slate-500">No engagements available.</div>
        <?php else: ?>
            <table class="table-app">
                <thead><tr><th>Client</th><th>FY</th><th>Status</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($engagements as $e): ?>
                    <tr>
                        <td class="font-medium"><?= e($e['company_name']) ?></td>
                        <td><?= e($e['financial_year']) ?></td>
                        <td><?= badge($e['status']) ?></td>
                        <td class="text-right">
                            <a href="/reports/audit_report.php?engagement_id=<?= (int) $e['id'] ?>"
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
// Load + scope-check.
// ---------------------------------------------------------------------
$stmt = $pdo->prepare(
    'SELECT e.*, c.company_name FROM engagements e
       JOIN clients c ON c.id = e.client_id
      WHERE e.id = :id AND e.firm_id = :fid'
);
$stmt->execute([':id'=>$engagementId, ':fid'=>$firmId]);
$engagement = $stmt->fetch();
if (!$engagement) {
    flash('error', 'Engagement not found.');
    redirect('/reports/audit_report.php');
}

// ---------------------------------------------------------------------
// POST: generate the report draft.
// ---------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    require_once __DIR__ . '/../ai/ai_service.php';

    $opinionType = (string)($_POST['opinion_type'] ?? 'unmodified');
    if (!in_array($opinionType, ['unmodified','qualified','adverse','disclaimer'], true)) {
        $opinionType = 'unmodified';
    }
    $payload = [
        'opinion_type' => $opinionType,
        'basis'        => trim((string)($_POST['basis'] ?? '')),
        'include_kam'  => !empty($_POST['include_kam']),
        'report_date'  => trim((string)($_POST['report_date'] ?? '')),
        'firm_name'    => trim((string)($_POST['firm_name'] ?? '')),
        'place'        => trim((string)($_POST['place'] ?? '')),
    ];
    $result = ai_run('ai_generate_audit_report', $payload, $engagementId);
    flash($result['ok'] ? 'success' : 'error',
        $result['ok'] ? 'Auditor\'s report draft generated.' : $result['output']);
    redirect('/reports/audit_report.php?engagement_id=' . $engagementId);
}

// Latest generated report for this engagement.
$latest = $pdo->prepare(
    'SELECT ao.*, u.name AS by_name
       FROM ai_outputs ao
       LEFT JOIN users u ON u.id = ao.created_by
      WHERE ao.engagement_id = :e AND ao.output_type = "audit_report"
      ORDER BY ao.created_at DESC LIMIT 1'
);
$latest->execute([':e' => $engagementId]);
$report = $latest->fetch();

$pageTitle = 'Auditor\'s Report — ' . $engagement['company_name'];
require __DIR__ . '/../includes/header.php';
?>

<style>
    @media print {
        .ar-no-print { display: none !important; }
        body { background: white; }
        .ar-doc { border: 0 !important; box-shadow: none !important; padding: 0 !important; }
    }
</style>

<a href="/reports/audit_report.php" class="ar-no-print text-sm text-brand-600 hover:underline">&larr; Change engagement</a>
<a href="/firm/engagement_view.php?id=<?= (int) $engagementId ?>"
   class="ar-no-print ml-3 text-sm text-brand-600 hover:underline">Engagement workspace</a>

<?php echo '<div class="ar-no-print mt-3">'
    . workplan_breadcrumb_html((int) $engagementId,
        workplan_step_for_context('module', 'audit_report', (int) $engagementId))
    . '</div>'; ?>

<div class="ar-no-print flex flex-wrap items-end justify-between gap-3 mt-1 mb-5">
    <div>
        <h2 class="text-xl font-semibold text-slate-900"><?= e($engagement['company_name']) ?> — Auditor's Report</h2>
        <p class="text-sm text-slate-500"><?= e($engagement['financial_year']) ?></p>
    </div>
    <?php if ($report): ?>
        <div class="flex gap-2">
            <a href="/ai/output_view.php?id=<?= (int) $report['id'] ?>"
               class="rounded border border-slate-300 px-3 py-1.5 text-sm hover:bg-slate-50">Open in AI Outputs</a>
            <button onclick="window.print()" type="button"
                    class="rounded bg-brand-600 hover:bg-brand-700 text-white px-3 py-1.5 text-sm">Print / PDF</button>
        </div>
    <?php endif; ?>
</div>

<!-- Generator form -->
<div class="ar-no-print bg-white rounded-lg border border-slate-200 p-5 mb-6 max-w-3xl">
    <h3 class="font-semibold text-slate-900 mb-3">Generate / regenerate draft</h3>
    <?php if (!AI_ENABLED): ?>
        <p class="text-xs text-amber-700 mb-3">
            AI provider not configured — a stub will be produced. Set <code>AI_API_KEY</code> to enable real drafting.
        </p>
    <?php endif; ?>
    <form method="post" class="space-y-3">
        <?= csrf_field() ?>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
            <label class="block">
                <span class="text-sm font-medium text-slate-700">Opinion type</span>
                <select name="opinion_type" id="opinion_type"
                        class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm">
                    <option value="unmodified">Unmodified (clean)</option>
                    <option value="qualified">Qualified</option>
                    <option value="adverse">Adverse</option>
                    <option value="disclaimer">Disclaimer of opinion</option>
                </select>
            </label>
            <label class="block">
                <span class="text-sm font-medium text-slate-700">Report date</span>
                <input type="date" name="report_date"
                       class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm">
            </label>
            <label class="block">
                <span class="text-sm font-medium text-slate-700">Audit firm name</span>
                <input name="firm_name" placeholder="e.g. ABC & Associates"
                       class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm">
            </label>
            <label class="block">
                <span class="text-sm font-medium text-slate-700">Place of signature</span>
                <input name="place" placeholder="e.g. Kuala Lumpur"
                       class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm">
            </label>
        </div>
        <label class="block" id="basis_wrap" style="display:none">
            <span class="text-sm font-medium text-slate-700">Basis for modification</span>
            <textarea name="basis" rows="2"
                      placeholder="Describe the matter giving rise to the modified opinion"
                      class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"></textarea>
        </label>
        <label class="flex items-center gap-2 text-sm">
            <input type="checkbox" name="include_kam" value="1" class="rounded border-slate-300">
            Include a Key Audit Matters section
        </label>
        <button class="rounded bg-brand-600 hover:bg-brand-700 text-white px-4 py-2 text-sm font-medium">
            <?= $report ? 'Regenerate draft' : 'Generate draft' ?>
        </button>
    </form>
</div>

<!-- Rendered report -->
<?php if ($report): ?>
    <div class="ar-doc bg-white rounded-lg border border-slate-200 p-8 max-w-4xl mx-auto leading-relaxed text-slate-800">
        <div class="ar-no-print text-xs text-slate-400 mb-4">
            Generated <?= e(datefmt($report['created_at'], 'd M Y H:i')) ?>
            <?= $report['by_name'] ? ' by ' . e($report['by_name']) : '' ?>
            · <?= badge($report['status']) ?>
        </div>
        <?= md_to_html((string) $report['content']) ?>
    </div>
    <p class="ar-no-print mt-3 text-xs text-slate-500 text-center">
        AI-generated draft for partner review. Verify every figure, opinion wording and statutory reference before issue.
    </p>
<?php else: ?>
    <div class="bg-white rounded-lg border border-slate-200 p-8 text-center text-sm text-slate-500 max-w-3xl">
        No report drafted yet. Choose an opinion type above and click Generate.
    </div>
<?php endif; ?>

<script>
(function () {
    var sel = document.getElementById('opinion_type');
    var wrap = document.getElementById('basis_wrap');
    function sync() { wrap.style.display = sel.value === 'unmodified' ? 'none' : 'block'; }
    sel.addEventListener('change', sync); sync();
})();
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
