<?php
/**
 * /import/aging.php?engagement_id=…&type=debtor|creditor
 *
 * Two-step aging-listing import:
 *   1. Pick type + upload CSV/XLSX → file stashed, headers read, held
 *      in the session.
 *   2. Map the bucket columns (auto-detected), preview, commit. Commit
 *      replaces all aging_items for (engagement, type).
 */

declare(strict_types=1);
require_once __DIR__ . '/../includes/auth_guard.php';
require_role(['firm_admin','audit_manager','senior_auditor']);
require_once __DIR__ . '/../includes/import_helpers.php';
require_once __DIR__ . '/../includes/aging.php';
require_once __DIR__ . '/../includes/workflow.php';

$pdo    = db();
$firmId = current_firm_id();
$engagementId = isset($_GET['engagement_id']) ? (int) $_GET['engagement_id'] : 0;
$type = ($_GET['type'] ?? $_POST['type'] ?? 'debtor') === 'creditor' ? 'creditor' : 'debtor';
$step = $_GET['step'] ?? '1';

function aging_engagement(PDO $pdo, int $id, int $firmId): array
{
    $s = $pdo->prepare(
        'SELECT e.id, e.financial_year, c.company_name
           FROM engagements e JOIN clients c ON c.id = e.client_id
          WHERE e.id = :id AND e.firm_id = :fid'
    );
    $s->execute([':id'=>$id, ':fid'=>$firmId]);
    $r = $s->fetch();
    if (!$r) { flash('error','Engagement not found.'); redirect('/import/index.php'); }
    return $r;
}

if ($engagementId <= 0) {
    flash('error', 'Pick an engagement first.');
    redirect('/import/index.php');
}
$engagement = aging_engagement($pdo, $engagementId, $firmId);

// =====================================================================
// STEP 1 POST — stash upload + parse headers into the session.
// =====================================================================
if ($step === '1' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    assert_engagement_open($engagementId);
    try {
        $stash  = stash_import_upload($_FILES['file'] ?? [], $firmId);
        $parsed = parse_spreadsheet_file($stash['abs'], $_FILES['file']['name'] ?? '');
        if (empty($parsed['headers'])) {
            throw new RuntimeException('Spreadsheet had no header row.');
        }
        $_SESSION['pending_aging'] = [
            'engagement_id' => $engagementId,
            'type'          => $type,
            'rel'           => $stash['rel'],
            'name'          => $_FILES['file']['name'] ?? '',
        ];
        redirect("/import/aging.php?engagement_id={$engagementId}&type={$type}&step=2");
    } catch (Throwable $ex) {
        flash('error', 'Upload failed: ' . $ex->getMessage());
        redirect("/import/aging.php?engagement_id={$engagementId}&type={$type}");
    }
}

// =====================================================================
// STEP 2 POST — commit with the chosen mapping.
// =====================================================================
if ($step === '3' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    assert_engagement_open($engagementId);
    $pending = $_SESSION['pending_aging'] ?? null;
    if (!$pending || (int) $pending['engagement_id'] !== $engagementId) {
        flash('error', 'Upload session expired. Please upload again.');
        redirect("/import/aging.php?engagement_id={$engagementId}&type={$type}");
    }

    $abs = UPLOADS_PRIVATE . '/' . $pending['rel'];
    $parsed = parse_spreadsheet_file($abs, $pending['name'] ?? '');
    $specs  = aging_field_specs();

    $mapping = [];
    foreach ($specs as $spec) {
        $v = $_POST['map'][$spec['key']] ?? '';
        $mapping[$spec['key']] = ($v === '' ? null : (int) $v);
    }
    if ($mapping['party_name'] === null) {
        flash('error', 'Party / customer name must be mapped.');
        redirect("/import/aging.php?engagement_id={$engagementId}&type={$type}&step=2");
    }

    try {
        $pdo->beginTransaction();
        $pdo->prepare('DELETE FROM aging_items WHERE engagement_id = :e AND aging_type = :t')
            ->execute([':e'=>$engagementId, ':t'=>$pending['type']]);

        $ins = $pdo->prepare(
            'INSERT INTO aging_items
                (engagement_id, aging_type, party_name, party_code, total,
                 current_amt, days_1_30, days_31_60, days_61_90, days_91_120, days_over_120, uploaded_by)
             VALUES (:e, :t, :pn, :pc, :tot, :c, :d1, :d2, :d3, :d4, :d5, :u)'
        );
        $val = static function (array $row, ?int $idx): float {
            return $idx !== null ? parse_amount((string)($row[$idx] ?? '')) : 0.0;
        };
        $imported = 0; $skipped = 0;
        foreach ($parsed['rows'] as $row) {
            $name = $mapping['party_name'] !== null ? trim((string)($row[$mapping['party_name']] ?? '')) : '';
            if ($name === '') { $skipped++; continue; }
            // Skip obvious total/footer rows.
            if (preg_match('/^(total|grand total|sub-?total)\b/i', $name)) { $skipped++; continue; }

            $code = $mapping['party_code'] !== null ? trim((string)($row[$mapping['party_code']] ?? '')) : null;
            $c  = $val($row, $mapping['current_amt']);
            $d1 = $val($row, $mapping['days_1_30']);
            $d2 = $val($row, $mapping['days_31_60']);
            $d3 = $val($row, $mapping['days_61_90']);
            $d4 = $val($row, $mapping['days_91_120']);
            $d5 = $val($row, $mapping['days_over_120']);
            $tot = $mapping['total'] !== null ? $val($row, $mapping['total']) : ($c+$d1+$d2+$d3+$d4+$d5);

            $ins->execute([
                ':e'=>$engagementId, ':t'=>$pending['type'], ':pn'=>$name, ':pc'=>$code ?: null,
                ':tot'=>$tot, ':c'=>$c, ':d1'=>$d1, ':d2'=>$d2, ':d3'=>$d3, ':d4'=>$d4, ':d5'=>$d5,
                ':u'=>current_user_id(),
            ]);
            $imported++;
        }
        $pdo->commit();
        unset($_SESSION['pending_aging']);
        log_activity('import.aging.commit', 'engagement', $engagementId,
            ucfirst($pending['type']) . ": {$imported} rows");
        flash('success', "Imported {$imported} {$pending['type']} aging rows ({$skipped} skipped).");
        redirect("/audit/aging.php?engagement_id={$engagementId}&type={$pending['type']}");
    } catch (Throwable $ex) {
        $pdo->rollBack();
        error_log('[AuditBOS] aging import failed: ' . $ex->getMessage());
        flash('error', 'Import failed: ' . $ex->getMessage());
        redirect("/import/aging.php?engagement_id={$engagementId}&type={$type}&step=2");
    }
}

// =====================================================================
// STEP 2 render — mapping form.
// =====================================================================
if ($step === '2') {
    $pending = $_SESSION['pending_aging'] ?? null;
    if (!$pending || (int) $pending['engagement_id'] !== $engagementId) {
        flash('error', 'Upload session expired. Please upload again.');
        redirect("/import/aging.php?engagement_id={$engagementId}&type={$type}");
    }
    $type = $pending['type'];
    $abs = UPLOADS_PRIVATE . '/' . $pending['rel'];
    $parsed = parse_spreadsheet_file($abs, $pending['name'] ?? '', 200);
    $headers = $parsed['headers'];
    $preview = array_slice($parsed['rows'], 0, 5);
    $autoMap = aging_auto_map($headers);

    $pageTitle = 'Import aging — map columns';
    require __DIR__ . '/../includes/header.php';
    ?>
    <a href="/audit/aging.php?engagement_id=<?= (int) $engagementId ?>&type=<?= e($type) ?>"
       class="text-sm text-brand-600 hover:underline">&larr; Back</a>
    <h2 class="text-xl font-semibold text-slate-900 mt-1 mb-1">
        Map columns — <?= e(ucfirst($type)) ?> aging
    </h2>
    <p class="text-sm text-slate-500 mb-4">
        <?= e($engagement['company_name']) ?> · <?= e($engagement['financial_year']) ?>
        · <?= count($parsed['rows']) ?> rows in file
    </p>

    <form method="post" action="/import/aging.php?engagement_id=<?= (int) $engagementId ?>&type=<?= e($type) ?>&step=3"
          class="bg-white rounded-lg border border-slate-200 p-5 space-y-5">
        <?= csrf_field() ?>
        <input type="hidden" name="type" value="<?= e($type) ?>">
        <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
            <?php foreach (aging_field_specs() as $spec): ?>
                <label class="block">
                    <span class="text-sm font-medium text-slate-700">
                        <?= e($spec['label']) ?><?= $spec['required'] ? ' *' : '' ?>
                    </span>
                    <select name="map[<?= e($spec['key']) ?>]"
                            class="mt-1 w-full rounded border border-slate-300 px-2 py-1.5 text-sm">
                        <option value="">— Not mapped —</option>
                        <?php foreach ($headers as $idx => $h): ?>
                            <option value="<?= (int) $idx ?>"
                                <?= (isset($autoMap[$spec['key']]) && $autoMap[$spec['key']] === $idx) ? 'selected' : '' ?>>
                                <?= e($h ?: '(col ' . ($idx+1) . ')') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
            <?php endforeach; ?>
        </div>

        <div>
            <h3 class="font-semibold text-slate-900 mb-2 text-sm">Preview (first 5 rows)</h3>
            <div class="overflow-x-auto border border-slate-200 rounded">
                <table class="table-app text-xs">
                    <thead><tr><th class="bg-slate-100">#</th>
                        <?php foreach ($headers as $h): ?><th><?= e($h ?: '(blank)') ?></th><?php endforeach; ?>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($preview as $r => $row): ?>
                        <tr><td class="bg-slate-50"><?= $r+1 ?></td>
                            <?php foreach ($headers as $idx => $_h): ?><td><?= e((string)($row[$idx] ?? '')) ?></td><?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="rounded border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800">
            Committing replaces any existing <?= e($type) ?> aging for this engagement.
        </div>

        <div class="flex gap-3">
            <button class="rounded bg-brand-600 hover:bg-brand-700 text-white px-4 py-2 text-sm font-medium">Import</button>
            <a href="/audit/aging.php?engagement_id=<?= (int) $engagementId ?>&type=<?= e($type) ?>"
               class="rounded border border-slate-300 px-4 py-2 text-sm hover:bg-slate-50">Cancel</a>
        </div>
    </form>
    <?php
    require __DIR__ . '/../includes/footer.php';
    exit;
}

// =====================================================================
// STEP 1 render — upload form.
// =====================================================================
$pageTitle = 'Import aging listing';
require __DIR__ . '/../includes/header.php';
?>
<a href="/audit/aging.php?engagement_id=<?= (int) $engagementId ?>&type=<?= e($type) ?>"
   class="text-sm text-brand-600 hover:underline">&larr; Back</a>
<h2 class="text-xl font-semibold text-slate-900 mt-1 mb-1">Import aging listing</h2>
<p class="text-sm text-slate-500 mb-4">
    <?= e($engagement['company_name']) ?> · <?= e($engagement['financial_year']) ?>
</p>

<form method="post" enctype="multipart/form-data"
      class="bg-white rounded-lg border border-slate-200 p-5 space-y-4 max-w-2xl">
    <?= csrf_field() ?>
    <label class="block">
        <span class="text-sm font-medium text-slate-700">Aging type *</span>
        <select name="type" class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm">
            <option value="debtor"   <?= $type === 'debtor'   ? 'selected' : '' ?>>Debtor (trade receivables)</option>
            <option value="creditor" <?= $type === 'creditor' ? 'selected' : '' ?>>Creditor (trade payables)</option>
        </select>
    </label>
    <label class="block">
        <span class="text-sm font-medium text-slate-700">Aging listing file *</span>
        <input type="file" name="file"
               accept=".csv,.txt,.xlsx,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required
               class="mt-1 block w-full text-sm text-slate-700
                      file:mr-3 file:py-2 file:px-3 file:rounded file:border-0
                      file:bg-brand-50 file:text-brand-700 hover:file:bg-brand-100">
        <span class="text-xs text-slate-500 mt-1 block">
            .xlsx or .csv with pre-bucketed columns (Current, 1-30, 31-60, 61-90, 91-120, 120+). Max <?= e(human_filesize(UPLOAD_MAX_BYTES)) ?>.
        </span>
    </label>
    <div class="flex gap-3 pt-2">
        <button class="rounded bg-brand-600 hover:bg-brand-700 text-white px-4 py-2 text-sm font-medium">Upload &amp; map</button>
        <a href="/audit/aging.php?engagement_id=<?= (int) $engagementId ?>&type=<?= e($type) ?>"
           class="rounded border border-slate-300 px-4 py-2 text-sm hover:bg-slate-50">Cancel</a>
    </div>
</form>

<?php require __DIR__ . '/../includes/footer.php'; ?>
