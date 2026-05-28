<?php
/**
 * /firm/reminders.php
 *
 * Reminders hub: chase clients for outstanding documents and nudge
 * staff on overdue work. Sends email when configured, always offers a
 * WhatsApp deep-link + copyable text for manual relay.
 */

declare(strict_types=1);
require_once __DIR__ . '/../includes/auth_guard.php';
require_role(['firm_admin','audit_manager','senior_auditor']);
require_once __DIR__ . '/../includes/reminders.php';

$pdo    = db();
$firmId = current_firm_id();

// The reminders feature needs migration 005. Fail gracefully if it
// hasn't been applied rather than throwing a blank 500.
if (!table_exists('reminders')) {
    $pageTitle = 'Reminders';
    require __DIR__ . '/../includes/header.php';
    ?>
    <div class="bg-white rounded-lg border border-amber-200 p-6 max-w-2xl">
        <h3 class="font-semibold text-slate-900 mb-2">Reminders not set up yet</h3>
        <p class="text-sm text-slate-600 mb-3">
            This module needs a database migration that hasn't been applied to this
            environment. Run <code>sql/005_reminders.sql</code> against your database, then reload.
        </p>
        <pre class="bg-slate-900 text-slate-100 rounded p-3 text-xs overflow-x-auto">mysql -u &lt;user&gt; -p &lt;database&gt; &lt; sql/005_reminders.sql</pre>
    </div>
    <?php
    require __DIR__ . '/../includes/footer.php';
    exit;
}

$result = null;       // populated after a send so we can show the WhatsApp link
$resultEng = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['_action'] ?? '';

    if ($action === 'send_client_reminder') {
        $engId = (int)($_POST['engagement_id'] ?? 0);
        // Re-fetch the engagement row with contact details, firm-scoped.
        $stmt = $pdo->prepare(
            'SELECT e.id AS engagement_id, e.financial_year,
                    c.id AS client_id, c.company_name, c.contact_person,
                    c.email AS client_email, c.phone AS client_phone
               FROM engagements e
               JOIN clients c ON c.id = e.client_id
              WHERE e.id = :id AND e.firm_id = :fid'
        );
        $stmt->execute([':id'=>$engId, ':fid'=>$firmId]);
        $eng = $stmt->fetch();
        if ($eng) {
            $result = send_client_reminder($eng, $firmId, 'user');
            $resultEng = $eng;
            flash($result['ok'] ? 'success' : 'error', $result['message']);
        } else {
            flash('error', 'Engagement not found.');
        }
        // Fall through to render (so we can show the WhatsApp link), no redirect.
    } else {
        redirect('/firm/reminders.php');
    }
}

$clientRows = reminders_client_outstanding($firmId);
$staffRows  = reminders_staff_nudges($firmId);

// Recent reminders log
$recent = $pdo->prepare(
    'SELECT r.*, e.financial_year, c.company_name, u.name AS by_name
       FROM reminders r
       LEFT JOIN engagements e ON e.id = r.engagement_id
       LEFT JOIN clients c     ON c.id = r.client_id
       LEFT JOIN users u       ON u.id = r.created_by
      WHERE r.firm_id = :f
      ORDER BY r.created_at DESC
      LIMIT 20'
);
$recent->execute([':f' => $firmId]);
$recentRows = $recent->fetchAll();

$pageTitle = 'Reminders';
require __DIR__ . '/../includes/header.php';
?>

<div class="mb-5">
    <p class="text-sm text-slate-600">
        Chase outstanding client documents and nudge staff on overdue work.
        <?php if (!MAIL_ENABLED): ?>
            <span class="text-amber-700">Email isn't configured — reminders give you a WhatsApp link and copyable text instead.</span>
        <?php endif; ?>
    </p>
</div>

<?php if ($result && $result['ok']): ?>
    <div class="mb-6 bg-white rounded-lg border border-brand-200 p-5">
        <div class="flex items-center justify-between mb-2">
            <h3 class="font-semibold text-slate-900">
                Reminder for <?= e($resultEng['company_name']) ?> (<?= e($resultEng['financial_year']) ?>)
            </h3>
            <?php if ($result['whatsapp_url']): ?>
                <a href="<?= e($result['whatsapp_url']) ?>" target="_blank" rel="noopener"
                   class="rounded bg-emerald-600 hover:bg-emerald-700 text-white px-3 py-1.5 text-sm font-medium">
                    Open in WhatsApp
                </a>
            <?php endif; ?>
        </div>
        <?php if (!empty($result['text'])): ?>
            <label class="text-xs text-slate-500">Email text (copy &amp; paste if needed)</label>
            <textarea readonly rows="8"
                      class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-xs font-mono"
                      onclick="this.select()"><?= e($result['text']['subject']) ?>

<?= e($result['text']['email']) ?></textarea>
        <?php endif; ?>
    </div>
<?php endif; ?>

<!-- Client document reminders -->
<div class="bg-white rounded-lg border border-slate-200 overflow-hidden mb-8">
    <div class="px-5 py-3 border-b border-slate-200 flex items-center justify-between">
        <h3 class="font-semibold text-slate-900">Clients to chase</h3>
        <span class="text-xs text-slate-500"><?= count($clientRows) ?> engagement(s) with outstanding docs</span>
    </div>
    <?php if (empty($clientRows)): ?>
        <div class="p-8 text-center text-sm text-slate-500">
            Nothing outstanding — every active engagement has its documents in. 🎉
        </div>
    <?php else: ?>
        <table class="table-app">
            <thead>
                <tr>
                    <th>Client</th>
                    <th>FY</th>
                    <th>Contact</th>
                    <th class="text-right">Outstanding</th>
                    <th>Last reminded</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($clientRows as $r):
                $last = $r['last_reminder_at'];
                $stale = !$last || strtotime($last) < strtotime('-3 days');
            ?>
                <tr>
                    <td class="font-medium"><?= e($r['company_name']) ?></td>
                    <td><?= e($r['financial_year']) ?></td>
                    <td class="text-xs">
                        <?= e($r['contact_person'] ?? '—') ?>
                        <?php if ($r['client_email']): ?>
                            <div class="text-slate-500"><?= e($r['client_email']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td class="text-right tabular-nums"><?= (int) $r['outstanding'] ?></td>
                    <td class="text-xs <?= $stale ? 'text-amber-700' : 'text-slate-500' ?>">
                        <?= $last ? e(datefmt($last, 'd M Y H:i')) : 'never' ?>
                    </td>
                    <td class="text-right">
                        <form method="post" class="inline">
                            <?= csrf_field() ?>
                            <input type="hidden" name="_action" value="send_client_reminder">
                            <input type="hidden" name="engagement_id" value="<?= (int) $r['engagement_id'] ?>">
                            <button class="rounded bg-brand-600 hover:bg-brand-700 text-white px-3 py-1.5 text-xs font-medium whitespace-nowrap">
                                <?= $last ? 'Remind again' : 'Send reminder' ?>
                            </button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<!-- Staff nudges -->
<div class="bg-white rounded-lg border border-slate-200 overflow-hidden mb-8">
    <div class="px-5 py-3 border-b border-slate-200">
        <h3 class="font-semibold text-slate-900">Staff with work outstanding</h3>
        <p class="text-xs text-slate-500">In-app view — share with the team in your stand-up.</p>
    </div>
    <?php if (empty($staffRows)): ?>
        <div class="p-8 text-center text-sm text-slate-500">No overdue staff work right now.</div>
    <?php else: ?>
        <table class="table-app">
            <thead>
                <tr>
                    <th>Staff</th>
                    <th>Role</th>
                    <th class="text-right">Notes to action</th>
                    <th class="text-right">WPs not started</th>
                    <th class="text-right">Overdue jobs</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($staffRows as $s): ?>
                <tr>
                    <td class="font-medium"><?= e($s['name']) ?></td>
                    <td class="text-xs"><?= e(ucwords(str_replace('_',' ',$s['role']))) ?></td>
                    <td class="text-right tabular-nums <?= $s['notes_to_action'] > 0 ? 'text-rose-700 font-medium' : '' ?>">
                        <?= (int) $s['notes_to_action'] ?>
                    </td>
                    <td class="text-right tabular-nums"><?= (int) $s['wp_not_started'] ?></td>
                    <td class="text-right tabular-nums <?= $s['overdue_jobs'] > 0 ? 'text-rose-700 font-medium' : '' ?>">
                        <?= (int) $s['overdue_jobs'] ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<!-- Recent reminders -->
<div class="bg-white rounded-lg border border-slate-200 overflow-hidden">
    <div class="px-5 py-3 border-b border-slate-200">
        <h3 class="font-semibold text-slate-900">Recent reminders sent</h3>
    </div>
    <?php if (empty($recentRows)): ?>
        <div class="p-8 text-center text-sm text-slate-500">No reminders sent yet.</div>
    <?php else: ?>
        <table class="table-app">
            <thead>
                <tr><th>When</th><th>Client</th><th>Type</th><th>Channel</th><th>Status</th><th>By</th></tr>
            </thead>
            <tbody>
            <?php foreach ($recentRows as $r): ?>
                <tr>
                    <td class="text-xs"><?= e(datefmt($r['created_at'], 'd M Y H:i')) ?></td>
                    <td><?= e($r['company_name'] ?? '—') ?></td>
                    <td class="text-xs"><?= e(str_replace('_',' ', $r['reminder_type'])) ?></td>
                    <td class="text-xs"><?= e($r['channel']) ?></td>
                    <td><?= badge($r['status'] === 'sent' ? 'received' : ($r['status'] === 'failed' ? 'rejected' : 'pending')) ?></td>
                    <td class="text-xs"><?= e($r['by_name'] ?? ($r['triggered_by'] === 'cron' ? 'Cron' : '—')) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
