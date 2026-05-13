<?php
/**
 * /dashboard.php
 *
 * Role-aware landing page. Each role sees a different mix of KPI cards
 * + a "next actions" list driven by their actual job queue.
 */

declare(strict_types=1);
require_once __DIR__ . '/includes/auth_guard.php';

$role    = current_role();
$firmId  = current_firm_id();
$userId  = current_user_id();
$pageTitle = 'Dashboard';

// ---------------------------------------------------------------------
// Build per-role KPI cards.
// Each card: ['label' => ..., 'value' => ..., 'href' => ..., 'tone' => ...]
// ---------------------------------------------------------------------
$cards = [];

if ($role === 'super_admin') {
    $totalFirms = (int) db()->query('SELECT COUNT(*) FROM firms')->fetchColumn();
    $activeFirms = (int) db()->query("SELECT COUNT(*) FROM firms WHERE status='active'")->fetchColumn();
    $totalUsers = (int) db()->query('SELECT COUNT(*) FROM users')->fetchColumn();
    $aiCalls = (int) db()->query('SELECT COUNT(*) FROM ai_logs')->fetchColumn();

    $cards = [
        ['label' => 'Total Firms',   'value' => $totalFirms,   'href' => '/admin/firms.php', 'tone' => 'brand'],
        ['label' => 'Active Firms',  'value' => $activeFirms,  'href' => '/admin/firms.php', 'tone' => 'emerald'],
        ['label' => 'Platform Users','value' => $totalUsers,   'href' => '#',                'tone' => 'blue'],
        ['label' => 'AI Calls (all)','value' => $aiCalls,      'href' => '#',                'tone' => 'purple'],
    ];

} elseif ($role === 'client_user') {
    $clientId = current_user()['client_id'] ?? null;
    $pending = $received = $engagements = 0;
    if ($clientId) {
        // One prepared statement per metric: PDO native prepares don't
        // allow reusing the same named placeholder across subqueries.
        $countStmt = function (string $sql) use ($clientId): int {
            $s = db()->prepare($sql);
            $s->execute([':cid' => $clientId]);
            return (int) $s->fetchColumn();
        };
        $pending = $countStmt(
            'SELECT COUNT(*) FROM document_requests dr
               JOIN engagements e ON e.id = dr.engagement_id
              WHERE e.client_id = :cid AND dr.status = "pending"'
        );
        $received = $countStmt(
            'SELECT COUNT(*) FROM document_requests dr
               JOIN engagements e ON e.id = dr.engagement_id
              WHERE e.client_id = :cid AND dr.status = "received"'
        );
        $engagements = $countStmt(
            'SELECT COUNT(*) FROM engagements
              WHERE client_id = :cid
                AND status NOT IN ("completed","billed","archived")'
        );
    }
    $cards = [
        ['label' => 'Documents Pending',  'value' => $pending,     'href' => '/documents/index.php', 'tone' => 'amber'],
        ['label' => 'Documents Submitted','value' => $received,    'href' => '/documents/index.php', 'tone' => 'emerald'],
        ['label' => 'Active Engagements', 'value' => $engagements, 'href' => '#',                    'tone' => 'brand'],
    ];

} else {
    // Firm admin / managers / auditors / reviewers — one prepared
    // statement per metric: PDO with EMULATE_PREPARES=false rejects the
    // same named placeholder repeated across subqueries.
    $countStmt = function (string $sql) use ($firmId): int {
        $s = db()->prepare($sql);
        $s->execute([':fid' => $firmId]);
        return (int) $s->fetchColumn();
    };

    $cards = [
        ['label' => 'Active Engagements', 'tone' => 'brand', 'href' => '/firm/engagements.php',
         'value' => $countStmt(
            'SELECT COUNT(*) FROM engagements
              WHERE firm_id = :fid AND status NOT IN ("completed","billed","archived")'
         )],
        ['label' => 'Partner Review', 'tone' => 'purple', 'href' => '/firm/engagements.php?status=partner_review',
         'value' => $countStmt(
            'SELECT COUNT(*) FROM engagements WHERE firm_id = :fid AND status = "partner_review"'
         )],
        ['label' => 'Overdue Jobs', 'tone' => 'rose', 'href' => '/firm/engagements.php',
         'value' => $countStmt(
            'SELECT COUNT(*) FROM engagements
              WHERE firm_id = :fid
                AND deadline IS NOT NULL AND deadline < CURDATE()
                AND status NOT IN ("completed","billed","archived")'
         )],
        ['label' => 'Pending Documents', 'tone' => 'amber', 'href' => '/documents/index.php',
         'value' => $countStmt(
            'SELECT COUNT(*) FROM document_requests dr
               JOIN engagements e ON e.id = dr.engagement_id
              WHERE e.firm_id = :fid AND dr.status = "pending"'
         )],
        ['label' => 'WPs Pending Review', 'tone' => 'blue', 'href' => '/audit/working_papers.php?status=pending_review',
         'value' => $countStmt(
            'SELECT COUNT(*) FROM audit_working_papers wp
               JOIN engagements e ON e.id = wp.engagement_id
              WHERE e.firm_id = :fid AND wp.status = "pending_review"'
         )],
        ['label' => 'Open Review Notes', 'tone' => 'orange', 'href' => '/audit/working_papers.php',
         'value' => $countStmt(
            'SELECT COUNT(*) FROM audit_review_notes arn
               JOIN audit_working_papers wp ON wp.id = arn.working_paper_id
               JOIN engagements e ON e.id = wp.engagement_id
              WHERE e.firm_id = :fid AND arn.status = "open"'
         )],
    ];
}

// Recent engagements list (skip super_admin & client_user — they have different views).
$recent = [];
if ($firmId && $role !== 'client_user') {
    $stmt = db()->prepare(
        'SELECT e.id, e.financial_year, e.engagement_type, e.status, e.deadline,
                c.company_name
           FROM engagements e
           JOIN clients c ON c.id = e.client_id
          WHERE e.firm_id = :fid
          ORDER BY e.updated_at DESC
          LIMIT 8'
    );
    $stmt->execute([':fid' => $firmId]);
    $recent = $stmt->fetchAll();
}

// Tone-to-Tailwind map
$toneMap = [
    'brand'   => 'from-brand-50 to-white text-brand-700',
    'emerald' => 'from-emerald-50 to-white text-emerald-700',
    'blue'    => 'from-blue-50 to-white text-blue-700',
    'purple'  => 'from-purple-50 to-white text-purple-700',
    'amber'   => 'from-amber-50 to-white text-amber-700',
    'rose'    => 'from-rose-50 to-white text-rose-700',
    'orange'  => 'from-orange-50 to-white text-orange-700',
];

require __DIR__ . '/includes/header.php';
?>

<div class="mb-6">
    <h2 class="text-xl font-semibold text-slate-900">
        Welcome back, <?= e(explode(' ', current_user()['name'] ?? '')[0] ?? 'there') ?>
    </h2>
    <p class="text-sm text-slate-500 mt-0.5">
        <?= e(ucwords(str_replace('_', ' ', $role))) ?> · <?= date('l, d M Y') ?>
    </p>
</div>

<!-- KPI cards -->
<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4 mb-8">
    <?php foreach ($cards as $card):
        $tone = $toneMap[$card['tone']] ?? $toneMap['brand'];
    ?>
        <a href="<?= e($card['href']) ?>"
           class="bg-gradient-to-br <?= $tone ?> rounded-lg border border-slate-200 p-4 hover:shadow-md transition block">
            <div class="text-xs uppercase tracking-wide text-slate-500"><?= e($card['label']) ?></div>
            <div class="text-3xl font-semibold mt-1"><?= e((string) $card['value']) ?></div>
        </a>
    <?php endforeach; ?>
</div>

<?php if ($role !== 'super_admin' && $role !== 'client_user'): ?>
    <!-- Recent engagements -->
    <div class="bg-white rounded-lg border border-slate-200 overflow-hidden">
        <div class="px-5 py-3 border-b border-slate-200 flex items-center justify-between">
            <h3 class="font-semibold text-slate-900">Recent Engagements</h3>
            <a href="/firm/engagements.php" class="text-sm text-brand-600 hover:underline">View all</a>
        </div>
        <?php if (empty($recent)): ?>
            <div class="p-8 text-center text-sm text-slate-500">
                No engagements yet. <a href="/firm/engagements.php" class="text-brand-600 hover:underline">Create one</a>.
            </div>
        <?php else: ?>
            <table class="table-app">
                <thead>
                    <tr>
                        <th>Client</th>
                        <th>FY</th>
                        <th>Type</th>
                        <th>Status</th>
                        <th>Deadline</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recent as $eng): ?>
                        <tr>
                            <td class="font-medium"><?= e($eng['company_name']) ?></td>
                            <td><?= e($eng['financial_year']) ?></td>
                            <td><?= e(ucfirst($eng['engagement_type'])) ?></td>
                            <td><?= badge($eng['status']) ?></td>
                            <td><?= e(datefmt($eng['deadline'])) ?></td>
                            <td class="text-right">
                                <a href="/firm/engagement_view.php?id=<?= (int) $eng['id'] ?>"
                                   class="text-sm text-brand-600 hover:underline">Open</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
