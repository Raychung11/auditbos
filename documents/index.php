<?php
/**
 * /documents/index.php
 *
 * Document collection hub. Behaviour depends on role:
 *   - client_user → only sees engagements for their client_id
 *   - firm staff  → sees engagements in their firm; can pick engagement via ?engagement_id
 *
 * Shows the checklist on the left and uploaded files on the right.
 * Upload form posts to /documents/upload.php; downloads go through
 * /documents/download.php (no direct filesystem URLs).
 */

declare(strict_types=1);
require_once __DIR__ . '/../includes/auth_guard.php';
require_role(['firm_admin','audit_manager','senior_auditor','junior_auditor','reviewer','client_user']);

$pdo  = db();
$role = current_role();
$user = current_user();
$engagementId = isset($_GET['engagement_id']) ? (int) $_GET['engagement_id'] : 0;

// ---------------------------------------------------------------------
// Build the list of engagements visible to this user.
// ---------------------------------------------------------------------
if ($role === 'client_user') {
    if (empty($user['client_id'])) {
        flash('error', 'No client linked to your account. Contact your audit firm.');
        $engagements = [];
    } else {
        $stmt = $pdo->prepare(
            'SELECT e.id, e.financial_year, e.engagement_type, e.status, e.deadline,
                    c.company_name
               FROM engagements e
               JOIN clients c ON c.id = e.client_id
              WHERE e.client_id = :cid
                AND e.status NOT IN ("archived")
              ORDER BY e.updated_at DESC'
        );
        $stmt->execute([':cid' => $user['client_id']]);
        $engagements = $stmt->fetchAll();
    }
} else {
    $stmt = $pdo->prepare(
        'SELECT e.id, e.financial_year, e.engagement_type, e.status, e.deadline,
                c.company_name
           FROM engagements e
           JOIN clients c ON c.id = e.client_id
          WHERE e.firm_id = :fid
            AND e.status NOT IN ("archived")
          ORDER BY e.updated_at DESC'
    );
    $stmt->execute([':fid' => current_firm_id()]);
    $engagements = $stmt->fetchAll();
}

// ---------------------------------------------------------------------
// Pick an engagement (URL param, or the only one available).
// ---------------------------------------------------------------------
if ($engagementId === 0 && count($engagements) === 1) {
    $engagementId = (int) $engagements[0]['id'];
}

$engagement = null;
$docRequests = [];
$documents = [];

if ($engagementId > 0) {
    // Scope-check
    $params = [':id' => $engagementId];
    if ($role === 'client_user') {
        $sql = 'SELECT e.*, c.company_name, c.firm_id
                  FROM engagements e
                  JOIN clients c ON c.id = e.client_id
                 WHERE e.id = :id AND e.client_id = :cid';
        $params[':cid'] = $user['client_id'];
    } else {
        $sql = 'SELECT e.*, c.company_name
                  FROM engagements e
                  JOIN clients c ON c.id = e.client_id
                 WHERE e.id = :id AND e.firm_id = :fid';
        $params[':fid'] = current_firm_id();
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $engagement = $stmt->fetch();
    if (!$engagement) {
        flash('error', 'Engagement not found or you do not have access.');
        redirect('/documents/index.php');
    }

    // Requests for this engagement
    $reqStmt = $pdo->prepare(
        'SELECT dr.*, dc.name AS category_name,
                (SELECT COUNT(*) FROM engagement_documents ed
                   WHERE ed.document_request_id = dr.id
                     AND ed.status IN ("uploaded","accepted")) AS file_count
           FROM document_requests dr
           LEFT JOIN document_categories dc ON dc.id = dr.category_id
          WHERE dr.engagement_id = :eid
          ORDER BY dr.status = "pending" DESC, dc.sort_order ASC, dr.id ASC'
    );
    $reqStmt->execute([':eid' => $engagementId]);
    $docRequests = $reqStmt->fetchAll();

    // Uploaded files for this engagement
    $docStmt = $pdo->prepare(
        'SELECT ed.*, u.name AS uploader_name, dr.title AS request_title
           FROM engagement_documents ed
           LEFT JOIN users u ON u.id = ed.uploaded_by
           LEFT JOIN document_requests dr ON dr.id = ed.document_request_id
          WHERE ed.engagement_id = :eid AND ed.status <> "archived"
          ORDER BY ed.created_at DESC'
    );
    $docStmt->execute([':eid' => $engagementId]);
    $documents = $docStmt->fetchAll();
}

$pageTitle = 'Documents';
require __DIR__ . '/../includes/header.php';
?>

<?php if ($engagementId === 0): ?>
    <!-- Engagement picker -->
    <div class="bg-white rounded-lg border border-slate-200 overflow-hidden">
        <div class="px-5 py-3 border-b border-slate-200">
            <h3 class="font-semibold text-slate-900">Select an engagement</h3>
        </div>
        <?php if (empty($engagements)): ?>
            <div class="p-8 text-center text-sm text-slate-500">
                No engagements available.
            </div>
        <?php else: ?>
            <table class="table-app">
                <thead>
                    <tr><th>Client</th><th>FY</th><th>Type</th><th>Status</th><th>Deadline</th><th></th></tr>
                </thead>
                <tbody>
                    <?php foreach ($engagements as $e): ?>
                        <tr>
                            <td class="font-medium"><?= e($e['company_name']) ?></td>
                            <td><?= e($e['financial_year']) ?></td>
                            <td><?= e(ucfirst($e['engagement_type'])) ?></td>
                            <td><?= badge($e['status']) ?></td>
                            <td><?= e(datefmt($e['deadline'])) ?></td>
                            <td class="text-right">
                                <a href="/documents/index.php?engagement_id=<?= (int) $e['id'] ?>"
                                   class="text-sm text-brand-600 hover:underline">Open</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

<?php else: ?>
    <a href="/documents/index.php" class="text-sm text-brand-600 hover:underline">&larr; Change engagement</a>
    <div class="flex items-center justify-between mt-1 mb-4">
        <div>
            <h2 class="text-xl font-semibold text-slate-900"><?= e($engagement['company_name']) ?></h2>
            <p class="text-sm text-slate-500"><?= e($engagement['financial_year']) ?> · <?= e(ucfirst($engagement['engagement_type'])) ?></p>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <!-- Requests / checklist -->
        <div class="bg-white rounded-lg border border-slate-200">
            <div class="px-5 py-3 border-b border-slate-200">
                <h3 class="font-semibold text-slate-900">Required Documents</h3>
                <p class="text-xs text-slate-500">
                    <?= count(array_filter($docRequests, fn($r) => $r['status'] === 'received')) ?> received
                    / <?= count($docRequests) ?> total
                </p>
            </div>
            <?php if (empty($docRequests)): ?>
                <div class="p-8 text-center text-sm text-slate-500">
                    No requests for this engagement yet.
                </div>
            <?php else: ?>
                <ul class="divide-y divide-slate-200">
                    <?php foreach ($docRequests as $dr): ?>
                        <li class="px-5 py-3">
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <div class="font-medium text-slate-900"><?= e($dr['title']) ?></div>
                                    <div class="text-xs text-slate-500">
                                        <?= e($dr['category_name'] ?? '') ?>
                                        <?php if ($dr['due_date']): ?>
                                            · Due <?= e(datefmt($dr['due_date'])) ?>
                                        <?php endif; ?>
                                        · <?= (int) $dr['file_count'] ?> file(s)
                                    </div>
                                </div>
                                <div class="text-right shrink-0">
                                    <?= badge($dr['status']) ?>
                                </div>
                            </div>

                            <?php if (!in_array($dr['status'], ['waived'], true)): ?>
                                <!-- Inline upload form for this request -->
                                <form method="post" action="/documents/upload.php"
                                      enctype="multipart/form-data"
                                      class="mt-3 flex items-center gap-2">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="engagement_id" value="<?= (int) $engagementId ?>">
                                    <input type="hidden" name="document_request_id" value="<?= (int) $dr['id'] ?>">
                                    <input type="file" name="file" required
                                           class="block w-full text-xs text-slate-700
                                                  file:mr-3 file:py-1.5 file:px-3 file:rounded file:border-0
                                                  file:bg-brand-50 file:text-brand-700 hover:file:bg-brand-100">
                                    <button class="rounded bg-brand-600 hover:bg-brand-700 text-white px-3 py-1.5 text-xs whitespace-nowrap">
                                        Upload
                                    </button>
                                </form>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>

        <!-- Uploaded documents -->
        <div class="bg-white rounded-lg border border-slate-200">
            <div class="px-5 py-3 border-b border-slate-200 flex items-center justify-between">
                <h3 class="font-semibold text-slate-900">Uploaded Files</h3>
                <span class="text-xs text-slate-500"><?= count($documents) ?> file(s)</span>
            </div>

            <!-- Generic upload (no request) -->
            <form method="post" action="/documents/upload.php"
                  enctype="multipart/form-data"
                  class="px-5 py-3 border-b border-slate-200 bg-slate-50 flex items-center gap-2">
                <?= csrf_field() ?>
                <input type="hidden" name="engagement_id" value="<?= (int) $engagementId ?>">
                <input type="file" name="file" required
                       class="block w-full text-xs text-slate-700
                              file:mr-3 file:py-1.5 file:px-3 file:rounded file:border-0
                              file:bg-brand-50 file:text-brand-700 hover:file:bg-brand-100">
                <button class="rounded bg-slate-700 hover:bg-slate-800 text-white px-3 py-1.5 text-xs whitespace-nowrap">
                    Upload (unlinked)
                </button>
            </form>

            <?php if (empty($documents)): ?>
                <div class="p-8 text-center text-sm text-slate-500">No files uploaded yet.</div>
            <?php else: ?>
                <ul class="divide-y divide-slate-200">
                    <?php foreach ($documents as $d): ?>
                        <li class="px-5 py-3 flex items-center justify-between gap-3">
                            <div class="min-w-0">
                                <a href="/documents/download.php?id=<?= (int) $d['id'] ?>"
                                   class="font-medium text-slate-900 hover:text-brand-700 hover:underline truncate block">
                                    <?= e($d['original_filename']) ?>
                                </a>
                                <div class="text-xs text-slate-500">
                                    <?= e($d['request_title'] ?? 'Unlinked') ?>
                                    · <?= e(human_filesize((int) $d['file_size'])) ?>
                                    · <?= e($d['uploader_name'] ?? '') ?>
                                    · <?= e(datefmt($d['created_at'], 'd M Y H:i')) ?>
                                </div>
                            </div>
                            <div class="text-right shrink-0">
                                <?= badge($d['status']) ?>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
