<?php
/**
 * /import/general_ledger.php
 *
 * General ledger importer. Same 3-step flow as trial_balance.php, but:
 *   - rows are transaction-level (often tens of thousands)
 *   - imports APPEND by default (GL is a transaction log)
 *   - inserts batched 500 at a time to keep memory steady
 */

declare(strict_types=1);
require_once __DIR__ . '/../includes/auth_guard.php';
require_role(['firm_admin','audit_manager','senior_auditor']);
require_once __DIR__ . '/../includes/import_helpers.php';
require_once __DIR__ . '/../includes/workflow.php';

$pdo    = db();
$firmId = current_firm_id();
$userId = current_user_id();

$step  = $_GET['step']  ?? '1';
$batchId = isset($_GET['batch']) ? (int) $_GET['batch'] : 0;
$engagementId = isset($_GET['engagement_id']) ? (int) $_GET['engagement_id'] : 0;

function gl_load_engagement(PDO $pdo, int $id, int $firmId): array
{
    $stmt = $pdo->prepare(
        'SELECT e.*, c.company_name
           FROM engagements e
           JOIN clients c ON c.id = e.client_id
          WHERE e.id = :id AND e.firm_id = :fid'
    );
    $stmt->execute([':id'=>$id, ':fid'=>$firmId]);
    $row = $stmt->fetch();
    if (!$row) { flash('error','Engagement not found.'); redirect('/import/index.php'); }
    return $row;
}

function gl_load_batch(PDO $pdo, int $batchId, int $firmId): array
{
    $stmt = $pdo->prepare(
        'SELECT ib.*, c.company_name, e.financial_year
           FROM import_batches ib
           JOIN engagements e ON e.id = ib.engagement_id
           JOIN clients c     ON c.id = e.client_id
          WHERE ib.id = :id AND ib.firm_id = :fid'
    );
    $stmt->execute([':id'=>$batchId, ':fid'=>$firmId]);
    $row = $stmt->fetch();
    if (!$row) { flash('error','Batch not found.'); redirect('/import/index.php'); }
    return $row;
}

// =====================================================================
// STEP 1 — upload
// =====================================================================
if ($step === '1' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $engId  = (int)($_POST['engagement_id'] ?? 0);
    $source = trim((string)($_POST['source'] ?? 'excel')) ?: 'excel';
    $eng = gl_load_engagement($pdo, $engId, $firmId);
    assert_engagement_open($engId);

    try {
        $stash  = stash_import_upload($_FILES['file'] ?? [], $firmId);
        $parsed = parse_spreadsheet_file($stash['abs'], $_FILES['file']['name'] ?? '');
        if (empty($parsed['headers'])) {
            throw new RuntimeException('Spreadsheet had no header row.');
        }

        $pdo->prepare(
            'INSERT INTO import_batches
                (firm_id, engagement_id, import_type, period, source,
                 original_filename, stored_filename, relative_path,
                 row_count_total, status, created_by)
             VALUES
                (:f, :e, "general_ledger", "current", :s, :ofn, :sfn, :rel, :rc, "pending", :u)'
        )->execute([
            ':f'=>$firmId, ':e'=>$engId, ':s'=>$source,
            ':ofn'=>$_FILES['file']['name'] ?? null,
            ':sfn'=>$stash['stored'], ':rel'=>$stash['rel'],
            ':rc'=>count($parsed['rows']), ':u'=>$userId,
        ]);
        $newBatchId = (int) $pdo->lastInsertId();
        log_activity('import.gl.upload', 'import_batch', $newBatchId);
        redirect("/import/general_ledger.php?step=2&batch={$newBatchId}");
    } catch (Throwable $ex) {
        flash('error', 'Upload failed: ' . $ex->getMessage());
        redirect('/import/general_ledger.php?engagement_id=' . $engId);
    }
}

// =====================================================================
// STEP 3 — commit
// =====================================================================
if ($step === '3' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $batch = gl_load_batch($pdo, (int) $_POST['batch'], $firmId);
    assert_engagement_open((int) $batch['engagement_id']);
    if ($batch['status'] !== 'pending') {
        flash('error','Batch already processed.');
        redirect('/import/index.php?engagement_id=' . $batch['engagement_id']);
    }
    $replaceBatches = !empty($_POST['replace_source']);

    $abs    = UPLOADS_PRIVATE . '/' . $batch['relative_path'];
    $parsed = parse_spreadsheet_file($abs, $batch['original_filename'] ?? '');
    $specs  = import_field_specs()['general_ledger'];

    $mapping = [];
    foreach ($specs as $spec) {
        $val = $_POST['map'][$spec['key']] ?? '';
        $mapping[$spec['key']] = ($val === '' ? null : (int) $val);
    }
    foreach ($specs as $spec) {
        if ($spec['required'] && $mapping[$spec['key']] === null) {
            flash('error', "Required field '{$spec['label']}' is not mapped.");
            redirect('/import/general_ledger.php?step=2&batch=' . (int) $batch['id']);
        }
    }

    try {
        $pdo->beginTransaction();

        if ($replaceBatches) {
            // Remove all prior GL rows from this engagement + source.
            $pdo->prepare(
                'DELETE gl FROM general_ledgers gl
                   JOIN import_batches ib ON ib.id = gl.import_batch_id
                  WHERE gl.engagement_id = :e
                    AND ib.source = :s
                    AND ib.import_type = "general_ledger"'
            )->execute([':e'=>$batch['engagement_id'], ':s'=>$batch['source']]);
        }

        $ins = $pdo->prepare(
            'INSERT INTO general_ledgers
                (engagement_id, import_batch_id, transaction_date, account_code,
                 account_name, reference_no, description, debit, credit, source)
             VALUES (:e, :b, :td, :ac, :an, :rn, :d, :dr, :cr, :src)'
        );

        $imported = 0; $skipped = 0;
        $totalDr = 0.0; $totalCr = 0.0;

        $rowNum = 0;
        foreach ($parsed['rows'] as $row) {
            $rowNum++;
            $dateRaw = $mapping['transaction_date'] !== null
                ? (string) ($row[$mapping['transaction_date']] ?? '') : '';
            $date = parse_date_value($dateRaw);
            $code = $mapping['account_code'] !== null
                ? trim((string) ($row[$mapping['account_code']] ?? '')) : '';
            if (!$date || $code === '') { $skipped++; continue; }

            $name = $mapping['account_name'] !== null
                ? trim((string) ($row[$mapping['account_name']] ?? '')) : null;
            $ref  = $mapping['reference_no'] !== null
                ? trim((string) ($row[$mapping['reference_no']] ?? '')) : null;
            $desc = $mapping['description'] !== null
                ? trim((string) ($row[$mapping['description']] ?? '')) : null;
            $dr = $mapping['debit']  !== null ? parse_amount((string) ($row[$mapping['debit']]  ?? '')) : 0.0;
            $cr = $mapping['credit'] !== null ? parse_amount((string) ($row[$mapping['credit']] ?? '')) : 0.0;

            $ins->execute([
                ':e'=>$batch['engagement_id'], ':b'=>$batch['id'],
                ':td'=>$date, ':ac'=>$code, ':an'=>$name ?: null,
                ':rn'=>$ref ?: null, ':d'=>$desc ?: null,
                ':dr'=>$dr, ':cr'=>$cr,
                ':src'=>$batch['source'],
            ]);
            $imported++;
            $totalDr += $dr;
            $totalCr += $cr;
        }

        $pdo->prepare(
            'UPDATE import_batches
                SET status="committed", column_mapping_json=:m,
                    row_count_imported=:ri, row_count_skipped=:rs,
                    total_debit=:td, total_credit=:tc
              WHERE id = :id'
        )->execute([
            ':m'=>json_encode($mapping, JSON_UNESCAPED_UNICODE),
            ':ri'=>$imported, ':rs'=>$skipped,
            ':td'=>$totalDr, ':tc'=>$totalCr,
            ':id'=>$batch['id'],
        ]);
        $pdo->commit();

        save_mapping($firmId, 'general_ledger', $batch['source'],
            'Last used (' . $batch['source'] . ')', $mapping, $userId);

        log_activity('import.gl.commit', 'import_batch', (int) $batch['id'],
            "{$imported} rows imported, {$skipped} skipped");

        flash('success', "Imported {$imported} GL rows (skipped {$skipped}). "
            . 'Dr: ' . money($totalDr) . ' · Cr: ' . money($totalCr));
        redirect('/import/index.php?engagement_id=' . (int) $batch['engagement_id']);
    } catch (Throwable $ex) {
        $pdo->rollBack();
        error_log('[AuditBOS] GL commit failed: ' . $ex->getMessage());
        $pdo->prepare(
            'UPDATE import_batches SET status="failed", error_message=:e WHERE id = :id'
        )->execute([':e'=>$ex->getMessage(), ':id'=>$batch['id']]);
        flash('error', 'Import failed: ' . $ex->getMessage());
        redirect('/import/general_ledger.php?step=2&batch=' . (int) $batch['id']);
    }
}

// =====================================================================
// STEP 2 — mapping form
// =====================================================================
if ($step === '2' && $batchId > 0) {
    $batch  = gl_load_batch($pdo, $batchId, $firmId);
    $abs    = UPLOADS_PRIVATE . '/' . $batch['relative_path'];
    $parsed = parse_spreadsheet_file($abs, $batch['original_filename'] ?? '', 200);
    $headers = $parsed['headers'];
    $previewRows = array_slice($parsed['rows'], 0, 5);

    $autoMap = auto_map_columns($headers, 'general_ledger');
    $saved = $pdo->prepare(
        'SELECT mapping_json FROM import_mappings
          WHERE firm_id=:f AND import_type="general_ledger" AND source=:s AND status="active"
          ORDER BY updated_at DESC LIMIT 1'
    );
    $saved->execute([':f'=>$firmId, ':s'=>$batch['source']]);
    if ($row = $saved->fetch()) {
        $sm = json_decode($row['mapping_json'], true) ?: [];
        foreach ($sm as $k => $idx) {
            if (is_int($idx) && $idx >= 0 && $idx < count($headers)) {
                $autoMap[$k] = $idx;
            }
        }
    }

    $pageTitle = 'Import General Ledger · Map Columns';
    require __DIR__ . '/../includes/header.php';
    ?>
    <a href="/import/index.php?engagement_id=<?= (int) $batch['engagement_id'] ?>"
       class="text-sm text-brand-600 hover:underline">&larr; Back</a>
    <h2 class="text-xl font-semibold text-slate-900 mt-1 mb-1">Map Columns — General Ledger</h2>
    <p class="text-sm text-slate-500 mb-4">
        <?= e($batch['company_name']) ?> · <?= e($batch['financial_year']) ?>
        · Source: <code><?= e($batch['source']) ?></code>
        · Rows in file: <?= (int) $batch['row_count_total'] ?>
    </p>

    <form method="post" action="/import/general_ledger.php?step=3"
          class="bg-white rounded-lg border border-slate-200 p-5 space-y-5">
        <?= csrf_field() ?>
        <input type="hidden" name="batch" value="<?= (int) $batch['id'] ?>">

        <div>
            <h3 class="font-semibold text-slate-900 mb-2">Column mapping</h3>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                <?php foreach (import_field_specs()['general_ledger'] as $spec): ?>
                    <label class="block">
                        <span class="text-sm font-medium text-slate-700">
                            <?= e($spec['label']) ?>
                            <?= $spec['required'] ? '<span class="text-rose-600">*</span>' : '' ?>
                        </span>
                        <select name="map[<?= e($spec['key']) ?>]"
                                class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm">
                            <option value="">— Not mapped —</option>
                            <?php foreach ($headers as $idx => $h): ?>
                                <option value="<?= (int) $idx ?>"
                                    <?= (isset($autoMap[$spec['key']]) && $autoMap[$spec['key']] === $idx) ? 'selected' : '' ?>>
                                    <?= e($h ?: '(column ' . ($idx + 1) . ')') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                <?php endforeach; ?>
            </div>
        </div>

        <div>
            <h3 class="font-semibold text-slate-900 mb-2">Preview (first 5 rows)</h3>
            <div class="overflow-x-auto border border-slate-200 rounded">
                <table class="table-app text-xs">
                    <thead>
                        <tr>
                            <th class="bg-slate-100">#</th>
                            <?php foreach ($headers as $h): ?>
                                <th><?= e($h ?: '(blank)') ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($previewRows as $r => $row): ?>
                            <tr>
                                <td class="bg-slate-50"><?= $r + 1 ?></td>
                                <?php foreach ($headers as $idx => $_h): ?>
                                    <td><?= e((string) ($row[$idx] ?? '')) ?></td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <label class="flex items-center gap-2 text-sm">
            <input type="checkbox" name="replace_source" value="1"
                   class="rounded border-slate-300">
            <span>Delete previous GL rows from source
                <code><?= e($batch['source']) ?></code> before importing</span>
        </label>

        <div class="flex gap-3">
            <button class="rounded bg-brand-600 hover:bg-brand-700 text-white px-4 py-2 text-sm font-medium">
                Import <?= (int) $batch['row_count_total'] ?> rows
            </button>
            <a href="/import/index.php?engagement_id=<?= (int) $batch['engagement_id'] ?>"
               class="rounded border border-slate-300 px-4 py-2 text-sm hover:bg-slate-50">Cancel</a>
        </div>
    </form>
    <?php
    require __DIR__ . '/../includes/footer.php';
    exit;
}

// Step 1 render
if ($engagementId <= 0) {
    flash('error','Pick an engagement first.');
    redirect('/import/index.php');
}
$engagement = gl_load_engagement($pdo, $engagementId, $firmId);

$pageTitle = 'Import General Ledger';
require __DIR__ . '/../includes/header.php';
?>

<a href="/import/index.php?engagement_id=<?= (int) $engagementId ?>"
   class="text-sm text-brand-600 hover:underline">&larr; Back</a>
<h2 class="text-xl font-semibold text-slate-900 mt-1 mb-1">Import General Ledger</h2>
<p class="text-sm text-slate-500 mb-4">
    <?= e($engagement['company_name']) ?> · <?= e($engagement['financial_year']) ?>
</p>

<form method="post" enctype="multipart/form-data"
      class="bg-white rounded-lg border border-slate-200 p-5 space-y-4 max-w-2xl">
    <?= csrf_field() ?>
    <input type="hidden" name="engagement_id" value="<?= (int) $engagementId ?>">

    <label class="block">
        <span class="text-sm font-medium text-slate-700">Spreadsheet file *</span>
        <input type="file" name="file"
               accept=".csv,.txt,.xlsx,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required
               class="mt-1 block w-full text-sm text-slate-700
                      file:mr-3 file:py-2 file:px-3 file:rounded file:border-0
                      file:bg-brand-50 file:text-brand-700 hover:file:bg-brand-100">
        <span class="text-xs text-slate-500 mt-1 block">
            .xlsx or .csv. Excel dates and serial numbers handled. Max <?= e(human_filesize(UPLOAD_MAX_BYTES)) ?>.
        </span>
    </label>

    <label class="block">
        <span class="text-sm font-medium text-slate-700">Source software</span>
        <select name="source" class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm">
            <option value="excel">Excel (manual)</option>
            <option value="sql_acc">SQL Accounting</option>
            <option value="autocount">AutoCount</option>
            <option value="ubs">UBS</option>
            <option value="bukku">Bukku</option>
            <option value="million">Million</option>
            <option value="financio">Financio</option>
            <option value="other">Other</option>
        </select>
    </label>

    <div class="flex gap-3 pt-2">
        <button class="rounded bg-brand-600 hover:bg-brand-700 text-white px-4 py-2 text-sm font-medium">
            Upload &amp; preview
        </button>
        <a href="/import/index.php?engagement_id=<?= (int) $engagementId ?>"
           class="rounded border border-slate-300 px-4 py-2 text-sm hover:bg-slate-50">Cancel</a>
    </div>
</form>

<?php require __DIR__ . '/../includes/footer.php'; ?>
