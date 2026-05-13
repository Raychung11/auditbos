<?php
/**
 * /import/index.php
 *
 * Import home: pick an engagement, see recent import batches, and
 * deep-link to the TB / GL importers.
 */

declare(strict_types=1);
require_once __DIR__ . '/../includes/auth_guard.php';
require_role(['firm_admin','audit_manager','senior_auditor','junior_auditor','reviewer']);

$pdo    = db();
$firmId = current_firm_id();
$engagementId = isset($_GET['engagement_id']) ? (int) $_GET['engagement_id'] : 0;

// Engagement list (firm-scoped, non-archived).
$stmt = $pdo->prepare(
    'SELECT e.id, e.financial_year, e.engagement_type, e.status,
            c.company_name
       FROM engagements e
       JOIN clients c ON c.id = e.client_id
      WHERE e.firm_id = :fid AND e.status NOT IN ("archived")
      ORDER BY c.company_name, e.financial_year DESC'
);
$stmt->execute([':fid' => $firmId]);
$engagements = $stmt->fetchAll();

$engagement = null;
$batches = [];
if ($engagementId > 0) {
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
        redirect('/import/index.php');
    }
    $bstmt = $pdo->prepare(
        'SELECT ib.*, u.name AS uploaded_by_name
           FROM import_batches ib
           LEFT JOIN users u ON u.id = ib.created_by
          WHERE ib.engagement_id = :eid
          ORDER BY ib.created_at DESC
          LIMIT 30'
    );
    $bstmt->execute([':eid' => $engagementId]);
    $batches = $bstmt->fetchAll();
}

$pageTitle = 'Accounting Data Import';
require __DIR__ . '/../includes/header.php';
?>

<?php if ($engagementId === 0): ?>
    <div class="bg-white rounded-lg border border-slate-200 overflow-hidden">
        <div class="px-5 py-3 border-b border-slate-200">
            <h3 class="font-semibold text-slate-900">Select an engagement</h3>
            <p class="text-xs text-slate-500 mt-0.5">
                Import the client's trial balance and general ledger so the AI assistant
                can run variance analysis and the working papers can auto-populate.
            </p>
        </div>
        <?php if (empty($engagements)): ?>
            <div class="p-8 text-center text-sm text-slate-500">
                No engagements available.
                <a href="/firm/engagements.php?action=new" class="text-brand-600 hover:underline">Create one first</a>.
            </div>
        <?php else: ?>
            <table class="table-app">
                <thead>
                    <tr><th>Client</th><th>FY</th><th>Type</th><th>Status</th><th></th></tr>
                </thead>
                <tbody>
                    <?php foreach ($engagements as $e): ?>
                        <tr>
                            <td class="font-medium"><?= e($e['company_name']) ?></td>
                            <td><?= e($e['financial_year']) ?></td>
                            <td><?= e(ucfirst($e['engagement_type'])) ?></td>
                            <td><?= badge($e['status']) ?></td>
                            <td class="text-right">
                                <a href="/import/index.php?engagement_id=<?= (int) $e['id'] ?>"
                                   class="text-sm text-brand-600 hover:underline">Open</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

<?php else: ?>
    <a href="/import/index.php" class="text-sm text-brand-600 hover:underline">&larr; Change engagement</a>
    <div class="flex items-center justify-between mt-1 mb-5">
        <div>
            <h2 class="text-xl font-semibold text-slate-900"><?= e($engagement['company_name']) ?></h2>
            <p class="text-sm text-slate-500"><?= e($engagement['financial_year']) ?> · <?= e(ucfirst($engagement['engagement_type'])) ?></p>
        </div>
        <a href="/firm/engagement_view.php?id=<?= (int) $engagementId ?>"
           class="text-sm text-brand-600 hover:underline">Engagement workspace &rarr;</a>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
        <a href="/import/trial_balance.php?engagement_id=<?= (int) $engagementId ?>"
           class="bg-white rounded-lg border border-slate-200 p-5 hover:shadow-md transition block">
            <div class="text-xs uppercase tracking-wide text-slate-500">Step 1</div>
            <div class="text-lg font-semibold mt-1">Import Trial Balance</div>
            <p class="text-xs text-slate-500 mt-1">CSV upload, column mapping, current or prior period.</p>
        </a>
        <a href="/import/trial_balance_view.php?engagement_id=<?= (int) $engagementId ?>"
           class="bg-white rounded-lg border border-slate-200 p-5 hover:shadow-md transition block">
            <div class="text-xs uppercase tracking-wide text-slate-500">View</div>
            <div class="text-lg font-semibold mt-1">Trial Balance & Variance</div>
            <p class="text-xs text-slate-500 mt-1">Current vs prior with variance % per account.</p>
        </a>
        <a href="/import/general_ledger.php?engagement_id=<?= (int) $engagementId ?>"
           class="bg-white rounded-lg border border-slate-200 p-5 hover:shadow-md transition block">
            <div class="text-xs uppercase tracking-wide text-slate-500">Step 2</div>
            <div class="text-lg font-semibold mt-1">Import General Ledger</div>
            <p class="text-xs text-slate-500 mt-1">Transaction-level data, batched for large files.</p>
        </a>
    </div>

    <h3 class="text-sm font-semibold text-slate-900 uppercase tracking-wide mb-3">Recent import batches</h3>
    <div class="bg-white rounded-lg border border-slate-200 overflow-hidden">
        <?php if (empty($batches)): ?>
            <div class="p-8 text-center text-sm text-slate-500">No imports yet for this engagement.</div>
        <?php else: ?>
            <table class="table-app">
                <thead>
                    <tr>
                        <th>When</th>
                        <th>Type</th>
                        <th>Period</th>
                        <th>Source</th>
                        <th>File</th>
                        <th>Rows</th>
                        <th>Totals (Dr / Cr)</th>
                        <th>Status</th>
                        <th>By</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($batches as $b): ?>
                        <tr>
                            <td class="text-xs"><?= e(datefmt($b['created_at'], 'd M Y H:i')) ?></td>
                            <td><?= e(str_replace('_',' ', $b['import_type'])) ?></td>
                            <td><?= e($b['period']) ?></td>
                            <td class="text-xs"><?= e($b['source']) ?></td>
                            <td class="text-xs truncate max-w-xs" title="<?= e($b['original_filename'] ?? '') ?>">
                                <?= e($b['original_filename'] ?? '-') ?>
                            </td>
                            <td class="text-xs"><?= (int) $b['row_count_imported'] ?> / <?= (int) $b['row_count_total'] ?></td>
                            <td class="text-xs">
                                <?= e(money((float) $b['total_debit'])) ?>
                                / <?= e(money((float) $b['total_credit'])) ?>
                            </td>
                            <td><?= badge($b['status']) ?></td>
                            <td class="text-xs"><?= e($b['uploaded_by_name'] ?? '-') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
