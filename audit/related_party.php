<?php
/**
 * /audit/related_party.php
 *
 * Related-party register + transactions for an engagement. Implements
 * workplan step 20 (MFRS 124 disclosure-driven).
 */

declare(strict_types=1);
require_once __DIR__ . '/../includes/auth_guard.php';
require_role(['firm_admin','audit_manager','senior_auditor','junior_auditor','reviewer']);
require_once __DIR__ . '/../includes/workflow.php';
require_once __DIR__ . '/../includes/workplan.php';
require_once __DIR__ . '/../includes/related_party.php';

$pdo    = db();
$firmId = current_firm_id();
$engagementId = isset($_GET['engagement_id']) ? (int) $_GET['engagement_id'] : 0;

if ($engagementId <= 0) {
    $picker = $pdo->prepare(
        'SELECT e.id, e.financial_year, c.company_name
           FROM engagements e
           JOIN clients c ON c.id = e.client_id
          WHERE e.firm_id = :f
          ORDER BY e.updated_at DESC LIMIT 50'
    );
    $picker->execute([':f' => $firmId]);
    $engs = $picker->fetchAll();
    $pageTitle = 'Related parties';
    require __DIR__ . '/../includes/header.php';
    ?>
    <h2 class="text-xl font-semibold text-slate-900 mb-2">Related parties</h2>
    <p class="text-sm text-slate-500 mb-4">Pick an engagement to manage its MFRS 124 register.</p>
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

$canEdit = role_allows(['firm_admin','audit_manager','senior_auditor','junior_auditor']);

if (!table_exists('related_parties')) {
    $pageTitle = 'Related parties — ' . $engagement['company_name'];
    require __DIR__ . '/../includes/header.php';
    ?>
    <div class="bg-amber-50 border border-amber-200 rounded p-4 text-sm text-amber-900">
        <strong>Migration not applied.</strong> Run <code>/sql/010_gap_modules.sql</code> to enable this module.
    </div>
    <?php
    require __DIR__ . '/../includes/footer.php';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (!$canEdit) { http_response_code(403); exit('Forbidden'); }
    assert_engagement_open($engagementId);
    $action = $_POST['_action'] ?? '';

    if ($action === 'party_create' || $action === 'party_update') {
        $id   = (int) ($_POST['id'] ?? 0);
        $name = trim((string) ($_POST['party_name'] ?? ''));
        $rel  = (string) ($_POST['relationship_type'] ?? 'other');
        $reg  = trim((string) ($_POST['registration_no'] ?? '')) ?: null;
        $code = trim((string) ($_POST['party_code'] ?? '')) ?: null;
        $notes = trim((string) ($_POST['notes'] ?? '')) ?: null;
        $relTypes = array_keys(rp_relationship_types());

        if ($name === '' || !in_array($rel, $relTypes, true)) {
            flash('error', 'Party name and relationship are required.');
        } elseif ($action === 'party_create') {
            $pdo->prepare(
                'INSERT INTO related_parties
                    (engagement_id, party_name, party_code, relationship_type, registration_no, notes, created_by)
                 VALUES (:e, :n, :c, :r, :rn, :nt, :u)'
            )->execute([
                ':e'=>$engagementId, ':n'=>$name, ':c'=>$code, ':r'=>$rel,
                ':rn'=>$reg, ':nt'=>$notes, ':u'=>current_user_id(),
            ]);
            log_activity('related_party.create', 'engagement', $engagementId, $name);
            flash('success', 'Related party added.');
        } else {
            $pdo->prepare(
                'UPDATE related_parties
                    SET party_name=:n, party_code=:c, relationship_type=:r,
                        registration_no=:rn, notes=:nt
                  WHERE id=:id AND engagement_id=:e'
            )->execute([
                ':n'=>$name, ':c'=>$code, ':r'=>$rel, ':rn'=>$reg, ':nt'=>$notes,
                ':id'=>$id, ':e'=>$engagementId,
            ]);
            log_activity('related_party.update', 'engagement', $engagementId, $name);
            flash('success', 'Related party updated.');
        }
    } elseif ($action === 'party_delete') {
        $id = (int) ($_POST['id'] ?? 0);
        $pdo->prepare('DELETE FROM related_parties WHERE id=:id AND engagement_id=:e')
            ->execute([':id'=>$id, ':e'=>$engagementId]);
        log_activity('related_party.delete', 'engagement', $engagementId, '#' . $id);
        flash('success', 'Party removed.');
    } elseif ($action === 'txn_create') {
        $partyId = (int) ($_POST['related_party_id'] ?? 0);
        $type    = (string) ($_POST['txn_type'] ?? 'other');
        $amt     = (float) ($_POST['txn_amount'] ?? 0);
        $bal     = (float) ($_POST['balance_outstanding'] ?? 0);
        $arm     = (string) ($_POST['arm_length'] ?? 'tbd');
        $period  = trim((string) ($_POST['period_label'] ?? '')) ?: null;
        $notes   = trim((string) ($_POST['notes'] ?? '')) ?: null;
        $typeOk  = in_array($type, array_keys(rp_txn_types()), true);
        $armOk   = in_array($arm, ['yes','no','na','tbd'], true);

        if (!$partyId || !$typeOk || !$armOk) {
            flash('error', 'Pick a party and a valid transaction type.');
        } else {
            $check = $pdo->prepare('SELECT id FROM related_parties WHERE id=:id AND engagement_id=:e');
            $check->execute([':id'=>$partyId, ':e'=>$engagementId]);
            if (!$check->fetch()) {
                flash('error', 'Invalid party.');
            } else {
                $pdo->prepare(
                    'INSERT INTO related_party_transactions
                        (engagement_id, related_party_id, txn_type, txn_amount, balance_outstanding,
                         arm_length, period_label, notes, created_by)
                     VALUES (:e, :p, :t, :a, :b, :al, :pl, :n, :u)'
                )->execute([
                    ':e'=>$engagementId, ':p'=>$partyId, ':t'=>$type, ':a'=>$amt, ':b'=>$bal,
                    ':al'=>$arm, ':pl'=>$period, ':n'=>$notes, ':u'=>current_user_id(),
                ]);
                log_activity('related_party.txn', 'engagement', $engagementId);
                flash('success', 'Transaction logged.');
            }
        }
    } elseif ($action === 'txn_delete') {
        $id = (int) ($_POST['id'] ?? 0);
        $pdo->prepare('DELETE FROM related_party_transactions WHERE id=:id AND engagement_id=:e')
            ->execute([':id'=>$id, ':e'=>$engagementId]);
        flash('success', 'Transaction removed.');
    }
    redirect('/audit/related_party.php?engagement_id=' . $engagementId);
}

workplan_sync_status($engagementId);
$parties      = rp_parties($engagementId);
$transactions = rp_transactions($engagementId);
$totalOut     = array_sum(array_column($parties, 'total_outstanding'));
$flagged      = array_sum(array_column($parties, 'not_arm_length'));

$pageTitle = 'Related parties — ' . $engagement['company_name'];
require __DIR__ . '/../includes/header.php';
?>

<a href="/audit/related_party.php" class="text-sm text-brand-600 hover:underline">&larr; Change engagement</a>
<a href="/firm/engagement_view.php?id=<?= (int) $engagementId ?>"
   class="ml-3 text-sm text-brand-600 hover:underline">Engagement workspace</a>

<div class="mt-3">
<?php echo workplan_breadcrumb_html((int) $engagementId,
    workplan_step_for_context('module', 'related_party', (int) $engagementId)); ?>
</div>

<div class="flex flex-wrap items-end justify-between gap-3 mt-1 mb-4">
    <div>
        <h2 class="text-xl font-semibold text-slate-900">
            <?= e($engagement['company_name']) ?> — Related parties
        </h2>
        <p class="text-sm text-slate-500"><?= e($engagement['financial_year']) ?></p>
    </div>
    <?php if (defined('AI_ENABLED') && AI_ENABLED): ?>
        <a href="/ai/run.php?fn=ai_review_related_parties&engagement_id=<?= (int) $engagementId ?>"
           class="inline-flex items-center gap-1 rounded bg-violet-600 hover:bg-violet-700 text-white px-3 py-1.5 text-sm font-medium">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/>
            </svg>
            AI related-party review
        </a>
    <?php endif; ?>
</div>

<div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-5">
    <div class="bg-white rounded border border-slate-200 px-3 py-3">
        <div class="text-xs uppercase tracking-wide text-slate-500">Parties logged</div>
        <div class="text-xl font-semibold text-slate-900"><?= count($parties) ?></div>
    </div>
    <div class="bg-white rounded border border-slate-200 px-3 py-3">
        <div class="text-xs uppercase tracking-wide text-slate-500">Transactions</div>
        <div class="text-xl font-semibold text-slate-900"><?= count($transactions) ?></div>
    </div>
    <div class="bg-white rounded border border-slate-200 px-3 py-3">
        <div class="text-xs uppercase tracking-wide text-slate-500">Total outstanding</div>
        <div class="text-xl font-semibold text-slate-900">MYR <?= number_format($totalOut, 2) ?></div>
    </div>
    <div class="bg-white rounded border border-slate-200 px-3 py-3 <?= $flagged > 0 ? 'border-rose-200 bg-rose-50' : '' ?>">
        <div class="text-xs uppercase tracking-wide text-slate-500">Non-arm's-length flagged</div>
        <div class="text-xl font-semibold <?= $flagged > 0 ? 'text-rose-700' : 'text-slate-900' ?>"><?= (int) $flagged ?></div>
    </div>
</div>

<!-- Add party form -->
<?php if ($canEdit && !engagement_locked($engagementId)): ?>
<details class="bg-white rounded-lg border border-slate-200 mb-5">
    <summary class="cursor-pointer px-4 py-3 font-medium text-slate-900">+ Add related party</summary>
    <form method="post" class="p-4 grid grid-cols-1 md:grid-cols-2 gap-3 border-t border-slate-100">
        <?= csrf_field() ?>
        <input type="hidden" name="_action" value="party_create">
        <label class="block">
            <span class="text-sm font-medium text-slate-700">Party name *</span>
            <input name="party_name" required class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm">
        </label>
        <label class="block">
            <span class="text-sm font-medium text-slate-700">Relationship *</span>
            <select name="relationship_type" required class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm">
                <?php foreach (rp_relationship_types() as $k => $v): ?>
                    <option value="<?= e($k) ?>"><?= e($v) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="block">
            <span class="text-sm font-medium text-slate-700">Registration / IC no</span>
            <input name="registration_no" class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm">
        </label>
        <label class="block">
            <span class="text-sm font-medium text-slate-700">COA code (optional)</span>
            <input name="party_code" class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm">
        </label>
        <label class="block md:col-span-2">
            <span class="text-sm font-medium text-slate-700">Notes</span>
            <textarea name="notes" rows="2" class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"></textarea>
        </label>
        <div class="md:col-span-2">
            <button class="rounded bg-brand-600 hover:bg-brand-700 text-white px-3 py-1.5 text-sm">Add party</button>
        </div>
    </form>
</details>
<?php endif; ?>

<!-- Parties list -->
<div class="bg-white rounded-lg border border-slate-200 overflow-hidden mb-6">
    <div class="px-4 py-3 border-b border-slate-100 font-medium text-slate-900">Register</div>
    <?php if (empty($parties)): ?>
        <div class="p-5 text-sm text-slate-500">No related parties recorded yet.</div>
    <?php else: ?>
        <table class="min-w-full text-sm">
            <thead class="bg-slate-50 text-xs uppercase text-slate-500">
                <tr>
                    <th class="px-3 py-2 text-left">Party</th>
                    <th class="px-3 py-2 text-left">Relationship</th>
                    <th class="px-3 py-2 text-right">Transactions</th>
                    <th class="px-3 py-2 text-right">Total amount</th>
                    <th class="px-3 py-2 text-right">Outstanding</th>
                    <th class="px-3 py-2 text-center">Flags</th>
                    <th class="px-3 py-2"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
            <?php foreach ($parties as $p): ?>
                <tr>
                    <td class="px-3 py-2">
                        <div class="font-medium text-slate-900"><?= e($p['party_name']) ?></div>
                        <?php if (!empty($p['registration_no'])): ?>
                            <div class="text-xs text-slate-500"><?= e($p['registration_no']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td class="px-3 py-2 text-slate-600">
                        <?= e(rp_relationship_types()[$p['relationship_type']] ?? $p['relationship_type']) ?>
                    </td>
                    <td class="px-3 py-2 text-right"><?= (int) $p['txn_count'] ?></td>
                    <td class="px-3 py-2 text-right tabular-nums"><?= number_format((float) $p['total_txn'], 2) ?></td>
                    <td class="px-3 py-2 text-right tabular-nums"><?= number_format((float) $p['total_outstanding'], 2) ?></td>
                    <td class="px-3 py-2 text-center">
                        <?php if ((int) $p['not_arm_length'] > 0): ?>
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs bg-rose-100 text-rose-800">
                                <?= (int) $p['not_arm_length'] ?> non-arm's-length
                            </span>
                        <?php endif; ?>
                    </td>
                    <td class="px-3 py-2 text-right">
                        <?php if ($canEdit && !engagement_locked($engagementId)): ?>
                            <form method="post" class="inline" onsubmit="return confirm('Delete this party and all its transactions?');">
                                <?= csrf_field() ?>
                                <input type="hidden" name="_action" value="party_delete">
                                <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                                <button class="text-rose-600 hover:underline text-xs">Delete</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<!-- Add transaction -->
<?php if ($canEdit && !engagement_locked($engagementId) && !empty($parties)): ?>
<details class="bg-white rounded-lg border border-slate-200 mb-5">
    <summary class="cursor-pointer px-4 py-3 font-medium text-slate-900">+ Log transaction</summary>
    <form method="post" class="p-4 grid grid-cols-1 md:grid-cols-3 gap-3 border-t border-slate-100">
        <?= csrf_field() ?>
        <input type="hidden" name="_action" value="txn_create">
        <label class="block md:col-span-1">
            <span class="text-sm font-medium text-slate-700">Party *</span>
            <select name="related_party_id" required class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm">
                <?php foreach ($parties as $p): ?>
                    <option value="<?= (int) $p['id'] ?>"><?= e($p['party_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="block">
            <span class="text-sm font-medium text-slate-700">Transaction type *</span>
            <select name="txn_type" required class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm">
                <?php foreach (rp_txn_types() as $k => $v): ?>
                    <option value="<?= e($k) ?>"><?= e($v) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="block">
            <span class="text-sm font-medium text-slate-700">Arm's length</span>
            <select name="arm_length" class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm">
                <option value="tbd">TBD</option>
                <option value="yes">Yes</option>
                <option value="no">No — flag</option>
                <option value="na">N/A</option>
            </select>
        </label>
        <label class="block">
            <span class="text-sm font-medium text-slate-700">Amount (MYR)</span>
            <input type="number" step="0.01" name="txn_amount" value="0"
                   class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm">
        </label>
        <label class="block">
            <span class="text-sm font-medium text-slate-700">Balance outstanding</span>
            <input type="number" step="0.01" name="balance_outstanding" value="0"
                   class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm">
        </label>
        <label class="block">
            <span class="text-sm font-medium text-slate-700">Period</span>
            <input name="period_label" placeholder="e.g. FY2024"
                   class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm">
        </label>
        <label class="block md:col-span-3">
            <span class="text-sm font-medium text-slate-700">Notes</span>
            <textarea name="notes" rows="2" class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"></textarea>
        </label>
        <div class="md:col-span-3">
            <button class="rounded bg-brand-600 hover:bg-brand-700 text-white px-3 py-1.5 text-sm">Add transaction</button>
        </div>
    </form>
</details>
<?php endif; ?>

<!-- Transactions list -->
<div class="bg-white rounded-lg border border-slate-200 overflow-hidden">
    <div class="px-4 py-3 border-b border-slate-100 font-medium text-slate-900">Transactions</div>
    <?php if (empty($transactions)): ?>
        <div class="p-5 text-sm text-slate-500">No transactions logged yet.</div>
    <?php else: ?>
        <table class="min-w-full text-sm">
            <thead class="bg-slate-50 text-xs uppercase text-slate-500">
                <tr>
                    <th class="px-3 py-2 text-left">Party</th>
                    <th class="px-3 py-2 text-left">Type</th>
                    <th class="px-3 py-2 text-right">Amount</th>
                    <th class="px-3 py-2 text-right">Outstanding</th>
                    <th class="px-3 py-2 text-left">Arm's length</th>
                    <th class="px-3 py-2 text-left">Period</th>
                    <th class="px-3 py-2"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
            <?php foreach ($transactions as $t): ?>
                <tr>
                    <td class="px-3 py-2 font-medium text-slate-900"><?= e($t['party_name']) ?></td>
                    <td class="px-3 py-2 text-slate-600">
                        <?= e(rp_txn_types()[$t['txn_type']] ?? $t['txn_type']) ?>
                    </td>
                    <td class="px-3 py-2 text-right tabular-nums"><?= number_format((float) $t['txn_amount'], 2) ?></td>
                    <td class="px-3 py-2 text-right tabular-nums"><?= number_format((float) $t['balance_outstanding'], 2) ?></td>
                    <td class="px-3 py-2">
                        <?php $cls = match ($t['arm_length']) {
                            'yes' => 'bg-emerald-100 text-emerald-800',
                            'no'  => 'bg-rose-100 text-rose-800',
                            'na'  => 'bg-slate-100 text-slate-600',
                            default => 'bg-amber-100 text-amber-800',
                        }; ?>
                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs <?= $cls ?>">
                            <?= e(strtoupper($t['arm_length'])) ?>
                        </span>
                    </td>
                    <td class="px-3 py-2 text-slate-600"><?= e($t['period_label'] ?? '—') ?></td>
                    <td class="px-3 py-2 text-right">
                        <?php if ($canEdit && !engagement_locked($engagementId)): ?>
                            <form method="post" class="inline">
                                <?= csrf_field() ?>
                                <input type="hidden" name="_action" value="txn_delete">
                                <input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
                                <button class="text-rose-600 hover:underline text-xs">Delete</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<?php
require __DIR__ . '/../includes/footer.php';
