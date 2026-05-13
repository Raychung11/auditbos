<?php
/**
 * /firm/engagement_view.php
 *
 * Unified engagement workspace:
 *   - Engagement summary
 *   - Document checklist (with quick status updates)
 *   - Working papers list
 *   - AI assistant panel (stubbed; real calls live in /ai/*)
 */

declare(strict_types=1);
require_once __DIR__ . '/../includes/auth_guard.php';
require_role(['firm_admin','audit_manager','senior_auditor','junior_auditor','reviewer']);

$pdo    = db();
$firmId = current_firm_id();
$id     = isset($_GET['id']) ? (int) $_GET['id'] : 0;

if ($id <= 0) {
    flash('error', 'Invalid engagement.');
    redirect('/firm/engagements.php');
}

// Load engagement + scope-check
$stmt = $pdo->prepare(
    'SELECT e.*, c.company_name, c.industry, c.financial_year_end,
            pu.name AS partner_name, mu.name AS manager_name
       FROM engagements e
       JOIN clients c   ON c.id = e.client_id
       LEFT JOIN users pu ON pu.id = e.partner_id
       LEFT JOIN users mu ON mu.id = e.manager_id
      WHERE e.id = :id AND e.firm_id = :fid'
);
$stmt->execute([':id'=>$id, ':fid'=>$firmId]);
$eng = $stmt->fetch();
if (!$eng) {
    flash('error', 'Engagement not found.');
    redirect('/firm/engagements.php');
}

$canEdit = role_allows(['firm_admin','audit_manager','senior_auditor']);

// ---------------------------------------------------------------------
// POST actions: seed checklist / update request status / change eng status
// ---------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['_action'] ?? '';

    if ($action === 'seed_checklist' && $canEdit) {
        // Pull default categories (firm_id NULL or matching this firm) and
        // create one document_request per category. Idempotent: skip those
        // that already exist.
        $cats = $pdo->prepare(
            'SELECT id, name FROM document_categories
              WHERE (firm_id IS NULL OR firm_id = :fid) AND status = "active" AND is_default_required = 1
              ORDER BY sort_order'
        );
        $cats->execute([':fid' => $firmId]);
        $existing = $pdo->prepare(
            'SELECT category_id FROM document_requests
              WHERE engagement_id = :eid AND category_id IS NOT NULL'
        );
        $existing->execute([':eid' => $id]);
        $haveCats = array_column($existing->fetchAll(), 'category_id');

        $ins = $pdo->prepare(
            'INSERT INTO document_requests (engagement_id, category_id, title, is_required, status, requested_by)
             VALUES (:eid, :cid, :title, 1, "pending", :uid)'
        );
        $added = 0;
        foreach ($cats->fetchAll() as $cat) {
            if (in_array((int) $cat['id'], array_map('intval', $haveCats), true)) {
                continue;
            }
            $ins->execute([
                ':eid'   => $id,
                ':cid'   => (int) $cat['id'],
                ':title' => $cat['name'],
                ':uid'   => current_user_id(),
            ]);
            $added++;
        }
        log_activity('engagement.seed_checklist', 'engagement', $id, "Added {$added} requests");
        flash($added > 0 ? 'success' : 'info',
            $added > 0 ? "Seeded {$added} document requests." : 'Checklist already populated.');

    } elseif ($action === 'update_request_status' && $canEdit) {
        $reqId = (int)($_POST['request_id'] ?? 0);
        $newStatus = (string)($_POST['new_status'] ?? '');
        $allowed = ['pending','received','rejected','needs_clarification','waived'];
        if ($reqId > 0 && in_array($newStatus, $allowed, true)) {
            $upd = $pdo->prepare(
                'UPDATE document_requests SET status = :s
                  WHERE id = :id AND engagement_id = :eid'
            );
            $upd->execute([':s'=>$newStatus, ':id'=>$reqId, ':eid'=>$id]);
            log_activity('docreq.status', 'document_request', $reqId, $newStatus);
            flash('success', 'Request status updated.');
        } else {
            flash('error', 'Invalid status update.');
        }
    } elseif ($action === 'update_eng_status' && $canEdit) {
        $newStatus = (string)($_POST['new_status'] ?? '');
        $allowed = ['draft','pending_documents','in_progress','under_review',
                    'partner_review','completed','billed','archived'];
        if (in_array($newStatus, $allowed, true)) {
            $upd = $pdo->prepare(
                'UPDATE engagements SET status = :s WHERE id = :id AND firm_id = :fid'
            );
            $upd->execute([':s'=>$newStatus, ':id'=>$id, ':fid'=>$firmId]);
            log_activity('engagement.status', 'engagement', $id, $newStatus);
            flash('success', 'Engagement status updated.');
        }
    }
    redirect('/firm/engagement_view.php?id=' . $id);
}

// ---------------------------------------------------------------------
// Load related data
// ---------------------------------------------------------------------
$docReqStmt = $pdo->prepare(
    'SELECT dr.*, dc.name AS category_name,
            (SELECT COUNT(*) FROM engagement_documents ed
               WHERE ed.document_request_id = dr.id
                 AND ed.status IN ("uploaded","accepted")) AS file_count
       FROM document_requests dr
       LEFT JOIN document_categories dc ON dc.id = dr.category_id
      WHERE dr.engagement_id = :eid
      ORDER BY dr.status = "pending" DESC, dc.sort_order ASC, dr.id ASC'
);
$docReqStmt->execute([':eid' => $id]);
$docRequests = $docReqStmt->fetchAll();

$wpStmt = $pdo->prepare(
    'SELECT wp.*, asec.name AS section_name,
            (SELECT COUNT(*) FROM audit_review_notes arn
              WHERE arn.working_paper_id = wp.id AND arn.status = "open") AS open_notes
       FROM audit_working_papers wp
       LEFT JOIN audit_sections asec ON asec.id = wp.section_id
      WHERE wp.engagement_id = :eid
      ORDER BY asec.sort_order ASC, wp.id ASC'
);
$wpStmt->execute([':eid' => $id]);
$workingPapers = $wpStmt->fetchAll();

$aiStmt = $pdo->prepare(
    'SELECT id, output_type, title, content, status, created_at
       FROM ai_outputs
      WHERE engagement_id = :eid
      ORDER BY created_at DESC
      LIMIT 5'
);
$aiStmt->execute([':eid' => $id]);
$aiOutputs = $aiStmt->fetchAll();

// Accounting data summary (TB rows per period + GL row count)
$dataStmt = $pdo->prepare(
    'SELECT
        (SELECT COUNT(*) FROM trial_balances WHERE engagement_id = :e AND period = "current") AS tb_current,
        (SELECT COUNT(*) FROM trial_balances WHERE engagement_id = :e2 AND period = "prior")   AS tb_prior,
        (SELECT COUNT(*) FROM general_ledgers WHERE engagement_id = :e3) AS gl_rows'
);
$dataStmt->execute([':e'=>$id, ':e2'=>$id, ':e3'=>$id]);
$dataSummary = $dataStmt->fetch() ?: ['tb_current'=>0, 'tb_prior'=>0, 'gl_rows'=>0];

// Engagement-scoped activity timeline (last 60 events).
require_once __DIR__ . '/../includes/timeline.php';
$timeline = engagement_timeline($id, 60);

$pageTitle = $eng['company_name'] . ' · ' . $eng['financial_year'];
require __DIR__ . '/../includes/header.php';
?>

<!-- Header card -->
<div class="bg-white rounded-lg border border-slate-200 p-5 mb-6">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <div class="flex items-center gap-3">
                <h2 class="text-xl font-semibold text-slate-900"><?= e($eng['company_name']) ?></h2>
                <?= badge($eng['status']) ?>
            </div>
            <p class="text-sm text-slate-500 mt-1">
                <?= e($eng['financial_year']) ?>
                · <?= e(ucfirst($eng['engagement_type'])) ?>
                <?= $eng['engagement_code'] ? ' · ' . e($eng['engagement_code']) : '' ?>
            </p>
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mt-4 text-sm">
                <div>
                    <div class="text-xs text-slate-500">Partner</div>
                    <div class="font-medium"><?= e($eng['partner_name'] ?? '-') ?></div>
                </div>
                <div>
                    <div class="text-xs text-slate-500">Manager</div>
                    <div class="font-medium"><?= e($eng['manager_name'] ?? '-') ?></div>
                </div>
                <div>
                    <div class="text-xs text-slate-500">Deadline</div>
                    <div class="font-medium"><?= e(datefmt($eng['deadline'])) ?></div>
                </div>
                <div>
                    <div class="text-xs text-slate-500">Fee</div>
                    <div class="font-medium"><?= e(money($eng['fee_amount'] ? (float) $eng['fee_amount'] : null)) ?></div>
                </div>
            </div>
        </div>
        <div class="flex flex-col gap-2 items-end">
            <?php if ($canEdit): ?>
                <a href="/firm/engagements.php?action=edit&id=<?= (int) $eng['id'] ?>"
                   class="rounded border border-slate-300 px-3 py-1.5 text-sm hover:bg-slate-50">Edit</a>
                <form method="post" class="flex items-center gap-2">
                    <?= csrf_field() ?>
                    <input type="hidden" name="_action" value="update_eng_status">
                    <select name="new_status"
                            class="rounded border border-slate-300 text-xs px-2 py-1">
                        <?php foreach (['draft','pending_documents','in_progress','under_review','partner_review','completed','billed','archived'] as $st): ?>
                            <option value="<?= $st ?>" <?= $eng['status'] === $st ? 'selected' : '' ?>>
                                <?= ucwords(str_replace('_',' ',$st)) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <button class="text-xs rounded bg-brand-600 text-white px-2 py-1">Update</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

    <!-- Document checklist (2/3 width) -->
    <div class="lg:col-span-2 space-y-6">
        <div class="bg-white rounded-lg border border-slate-200">
            <div class="px-5 py-3 border-b border-slate-200 flex items-center justify-between">
                <div>
                    <h3 class="font-semibold text-slate-900">Document Checklist</h3>
                    <p class="text-xs text-slate-500">
                        <?= count(array_filter($docRequests, fn($r) => $r['status'] === 'received')) ?> received
                        / <?= count($docRequests) ?> total
                    </p>
                </div>
                <div class="flex items-center gap-2">
                    <?php if ($canEdit && empty($docRequests)): ?>
                        <form method="post" class="inline">
                            <?= csrf_field() ?>
                            <input type="hidden" name="_action" value="seed_checklist">
                            <button class="rounded bg-brand-600 hover:bg-brand-700 text-white px-3 py-1.5 text-sm">
                                Seed Default Checklist
                            </button>
                        </form>
                    <?php endif; ?>
                    <?php if ($canEdit): ?>
                        <a href="/firm/doc_requests.php?engagement_id=<?= (int) $eng['id'] ?>"
                           class="rounded border border-slate-300 px-3 py-1.5 text-sm hover:bg-slate-50">
                            Manage requests
                        </a>
                    <?php endif; ?>
                </div>
            </div>
            <?php if (empty($docRequests)): ?>
                <div class="p-8 text-center text-sm text-slate-500">
                    No document requests yet. Seed the default checklist or add custom requests
                    from the <a href="/documents/index.php?engagement_id=<?= (int) $eng['id'] ?>" class="text-brand-600 hover:underline">documents module</a>.
                </div>
            <?php else: ?>
                <table class="table-app">
                    <thead>
                        <tr>
                            <th>Document</th>
                            <th>Category</th>
                            <th>Files</th>
                            <th>Status</th>
                            <?php if ($canEdit): ?><th></th><?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($docRequests as $dr): ?>
                        <tr>
                            <td>
                                <div class="font-medium"><?= e($dr['title']) ?></div>
                                <?php if (!empty($dr['description'])): ?>
                                    <div class="text-xs text-slate-500"><?= e($dr['description']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="text-xs text-slate-600"><?= e($dr['category_name'] ?? '-') ?></td>
                            <td><?= (int) $dr['file_count'] ?></td>
                            <td><?= badge($dr['status']) ?></td>
                            <?php if ($canEdit): ?>
                                <td class="text-right">
                                    <form method="post" class="flex items-center gap-1 justify-end">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="_action" value="update_request_status">
                                        <input type="hidden" name="request_id" value="<?= (int) $dr['id'] ?>">
                                        <select name="new_status"
                                                class="text-xs rounded border border-slate-300 px-1.5 py-1">
                                            <?php foreach (['pending','received','rejected','needs_clarification','waived'] as $st): ?>
                                                <option value="<?= $st ?>" <?= $dr['status'] === $st ? 'selected' : '' ?>>
                                                    <?= ucwords(str_replace('_',' ',$st)) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button class="text-xs rounded bg-slate-700 text-white px-2 py-1">Set</button>
                                    </form>
                                </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <!-- Working papers -->
        <div class="bg-white rounded-lg border border-slate-200">
            <div class="px-5 py-3 border-b border-slate-200 flex items-center justify-between">
                <h3 class="font-semibold text-slate-900">Working Papers</h3>
                <a href="/audit/working_papers.php?engagement_id=<?= (int) $eng['id'] ?>"
                   class="text-sm text-brand-600 hover:underline">Manage</a>
            </div>
            <?php if (empty($workingPapers)): ?>
                <div class="p-8 text-center text-sm text-slate-500">
                    No working papers yet.
                    <a href="/audit/working_papers.php?engagement_id=<?= (int) $eng['id'] ?>&action=new"
                       class="text-brand-600 hover:underline">Create one</a>.
                </div>
            <?php else: ?>
                <table class="table-app">
                    <thead>
                        <tr>
                            <th>Ref</th>
                            <th>Section / Title</th>
                            <th>Risk</th>
                            <th>Status</th>
                            <th>Open notes</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($workingPapers as $wp): ?>
                        <tr>
                            <td class="font-mono text-xs"><?= e($wp['reference_code'] ?? '-') ?></td>
                            <td>
                                <div class="text-xs text-slate-500"><?= e($wp['section_name'] ?? '') ?></div>
                                <div class="font-medium">
                                    <a href="/audit/working_paper_view.php?id=<?= (int) $wp['id'] ?>"
                                       class="hover:underline"><?= e($wp['title']) ?></a>
                                </div>
                            </td>
                            <td><?= $wp['risk_rating'] ? badge($wp['risk_rating']) : '<span class="text-xs text-slate-400">—</span>' ?></td>
                            <td><?= badge($wp['status']) ?></td>
                            <td><?= (int) $wp['open_notes'] ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>

    <!-- Right column: data + AI -->
    <div class="space-y-6">

        <!-- Accounting data -->
        <div class="bg-white rounded-lg border border-slate-200">
            <div class="px-5 py-3 border-b border-slate-200 flex items-center justify-between">
                <h3 class="font-semibold text-slate-900">Accounting Data</h3>
                <a href="/import/index.php?engagement_id=<?= (int) $eng['id'] ?>"
                   class="text-sm text-brand-600 hover:underline">Import</a>
            </div>
            <div class="p-4 space-y-2 text-sm">
                <div class="flex items-center justify-between">
                    <span class="text-slate-600">TB rows (current)</span>
                    <span class="font-medium"><?= (int) $dataSummary['tb_current'] ?></span>
                </div>
                <div class="flex items-center justify-between">
                    <span class="text-slate-600">TB rows (prior)</span>
                    <span class="font-medium"><?= (int) $dataSummary['tb_prior'] ?></span>
                </div>
                <div class="flex items-center justify-between">
                    <span class="text-slate-600">GL transactions</span>
                    <span class="font-medium"><?= number_format((int) $dataSummary['gl_rows']) ?></span>
                </div>
                <a href="/import/trial_balance_view.php?engagement_id=<?= (int) $eng['id'] ?>"
                   class="mt-2 block text-center rounded border border-slate-300 px-3 py-1.5 text-xs hover:bg-slate-50">
                    View trial balance &amp; variance
                </a>
            </div>
        </div>

        <div class="bg-white rounded-lg border border-slate-200">
            <div class="px-5 py-3 border-b border-slate-200">
                <h3 class="font-semibold text-slate-900">AI Assistant</h3>
                <p class="text-xs text-slate-500 mt-0.5">Latest outputs for this engagement</p>
            </div>
            <div class="p-4 space-y-3">
                <?php if (AI_ENABLED): ?>
                    <a href="/ai/run.php?engagement_id=<?= (int) $eng['id'] ?>&fn=ai_summarize_engagement_status"
                       class="block w-full text-center rounded bg-brand-600 hover:bg-brand-700 text-white px-3 py-2 text-sm font-medium">
                        Summarise engagement status
                    </a>
                    <a href="/ai/run.php?engagement_id=<?= (int) $eng['id'] ?>&fn=ai_analyze_trial_balance"
                       class="block w-full text-center rounded border border-brand-600 text-brand-700 px-3 py-2 text-sm font-medium hover:bg-brand-50">
                        Analyse trial balance
                    </a>
                    <a href="/ai/run.php?engagement_id=<?= (int) $eng['id'] ?>&fn=ai_generate_audit_queries"
                       class="block w-full text-center rounded border border-slate-300 text-slate-700 px-3 py-2 text-sm hover:bg-slate-50">
                        Draft audit queries
                    </a>
                <?php else: ?>
                    <div class="text-xs text-slate-500 bg-slate-50 rounded p-3">
                        AI provider not yet configured. Set <code>AI_API_KEY</code> in
                        <code>/config/db_config.local.php</code> to enable.
                    </div>
                <?php endif; ?>
            </div>
            <?php if (!empty($aiOutputs)): ?>
                <div class="border-t border-slate-200 px-5 py-3">
                    <div class="flex items-center justify-between mb-2">
                        <h4 class="text-xs uppercase tracking-wide text-slate-500">Recent outputs</h4>
                        <a href="/ai/outputs.php?engagement_id=<?= (int) $eng['id'] ?>"
                           class="text-xs text-brand-600 hover:underline">View all</a>
                    </div>
                    <ul class="space-y-2">
                        <?php foreach ($aiOutputs as $out): ?>
                            <li class="text-sm">
                                <a href="/ai/output_view.php?id=<?= (int) $out['id'] ?>"
                                   class="font-medium text-slate-800 hover:text-brand-700 hover:underline block">
                                    <?= e($out['title'] ?? $out['output_type']) ?>
                                </a>
                                <div class="flex items-center gap-2 mt-0.5">
                                    <span class="text-xs text-slate-500"><?= e(datefmt($out['created_at'], 'd M Y H:i')) ?></span>
                                    <?= badge($out['status']) ?>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Activity timeline -->
<div class="mt-8 bg-white rounded-lg border border-slate-200">
    <div class="px-5 py-3 border-b border-slate-200 flex items-center justify-between">
        <h3 class="font-semibold text-slate-900">Activity timeline</h3>
        <span class="text-xs text-slate-500">Latest <?= count($timeline) ?> events</span>
    </div>
    <?php if (empty($timeline)): ?>
        <div class="p-8 text-center text-sm text-slate-500">
            No recorded activity yet.
        </div>
    <?php else: ?>
        <ol class="relative px-5 py-4">
            <span class="absolute left-7 top-4 bottom-4 w-px bg-slate-200" aria-hidden="true"></span>
            <?php foreach ($timeline as $t):
                $meta = timeline_action_meta($t['action']);
                $dot  = timeline_dot_classes($meta['tone']);
            ?>
                <li class="relative pl-8 py-2">
                    <span class="absolute left-1.5 top-3 w-3 h-3 rounded-full <?= $dot ?> ring-4 ring-white"></span>
                    <div class="flex items-baseline justify-between gap-3">
                        <div class="min-w-0">
                            <div class="text-sm text-slate-800">
                                <span class="font-medium"><?= e($meta['label']) ?></span>
                                <?php if (!empty($t['description'])): ?>
                                    <span class="text-slate-500">·</span>
                                    <span class="text-slate-600"><?= e($t['description']) ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="text-xs text-slate-500 mt-0.5">
                                <?= e($t['user_name'] ?? 'System') ?>
                                <?php if ($t['user_role']): ?>
                                    · <?= e(ucwords(str_replace('_',' ',$t['user_role']))) ?>
                                <?php endif; ?>
                                · <code class="text-[10px] font-mono text-slate-400"><?= e($t['action']) ?></code>
                            </div>
                        </div>
                        <time class="text-xs text-slate-500 whitespace-nowrap" datetime="<?= e($t['created_at']) ?>">
                            <?= e(datefmt($t['created_at'], 'd M Y H:i')) ?>
                        </time>
                    </div>
                </li>
            <?php endforeach; ?>
        </ol>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
