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
    $pdo = db();
    $scalar = static fn(string $sql) => (int) $pdo->query($sql)->fetchColumn();

    // Headline KPIs
    $totalFirms      = $scalar('SELECT COUNT(*) FROM firms');
    $activeSubs      = $scalar("SELECT COUNT(*) FROM firms WHERE subscription_status = 'active'");
    $activeEng       = $scalar('SELECT COUNT(*) FROM engagements
                                WHERE status NOT IN ("completed","billed","archived")');
    $overdueEng      = $scalar('SELECT COUNT(*) FROM engagements
                                WHERE deadline IS NOT NULL AND deadline < CURDATE()
                                  AND status NOT IN ("completed","billed","archived")');
    $totalUsers      = $scalar("SELECT COUNT(*) FROM users WHERE status = 'active'");
    $aiCalls30d      = $scalar('SELECT COUNT(*) FROM ai_logs
                                WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)');

    $creditsInRow    = $pdo->query("SELECT COALESCE(SUM(amount),0) FROM credit_transactions
                                     WHERE direction='credit'
                                       AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)")->fetchColumn();
    $creditsOutRow   = $pdo->query("SELECT COALESCE(SUM(amount),0) FROM credit_transactions
                                     WHERE direction='debit'
                                       AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)")->fetchColumn();
    $creditsIn30d    = (float) $creditsInRow;
    $creditsOut30d   = (float) $creditsOutRow;

    $cards = [
        ['label' => 'Total Firms',          'value' => $totalFirms,                    'href' => '/admin/firms.php',        'tone' => 'brand'],
        ['label' => 'Active Subscriptions', 'value' => $activeSubs,                    'href' => '/admin/firms.php',        'tone' => 'emerald'],
        ['label' => 'Active Engagements',   'value' => number_format($activeEng),      'href' => '#',                       'tone' => 'blue'],
        ['label' => 'Overdue Engagements',  'value' => $overdueEng,                    'href' => '#',                       'tone' => 'rose'],
        ['label' => 'Platform Users',       'value' => number_format($totalUsers),     'href' => '#',                       'tone' => 'brand'],
        ['label' => 'AI Calls (30d)',       'value' => number_format($aiCalls30d),     'href' => '/admin/activity.php',     'tone' => 'purple'],
        ['label' => 'Topped Up (30d)',      'value' => money($creditsIn30d),           'href' => '/admin/transactions.php?direction=credit', 'tone' => 'emerald'],
        ['label' => 'Credits Used (30d)',   'value' => money($creditsOut30d),          'href' => '/admin/transactions.php?direction=debit',  'tone' => 'amber'],
    ];

    // Recent platform activity (last 15)
    $recentActivity = $pdo->query(
        'SELECT al.created_at, al.action, al.description, al.entity_type, al.entity_id,
                f.name AS firm_name, u.name AS user_name, u.role AS user_role
           FROM activity_logs al
           LEFT JOIN firms f ON f.id = al.firm_id
           LEFT JOIN users u ON u.id = al.user_id
          ORDER BY al.created_at DESC
          LIMIT 15'
    )->fetchAll();

    // Low-balance firms (< MYR 25 — roughly ~$5 at the default FX rate)
    $lowBalance = $pdo->query(
        'SELECT f.id, f.name, cw.balance, cw.currency,
                (SELECT COUNT(*) FROM ai_logs WHERE firm_id = f.id
                  AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)) AS calls_30d
           FROM firms f
           JOIN credit_wallet cw ON cw.firm_id = f.id
          WHERE f.status = "active"
            AND cw.balance < 25.00
          ORDER BY cw.balance ASC
          LIMIT 8'
    )->fetchAll();

    // AI usage by function (30d) with total credits
    $aiBreakdown = $pdo->query(
        'SELECT function_name,
                COUNT(*) AS calls,
                SUM(CASE WHEN status = "failed" THEN 1 ELSE 0 END) AS failed,
                COALESCE(SUM(credits_used), 0) AS cost,
                COALESCE(SUM(total_tokens), 0) AS tokens
           FROM ai_logs
          WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
          GROUP BY function_name
          ORDER BY calls DESC
          LIMIT 8'
    )->fetchAll();

    // Subscription status breakdown
    $subBreakdown = $pdo->query(
        'SELECT subscription_status, COUNT(*) AS n
           FROM firms
          GROUP BY subscription_status
          ORDER BY FIELD(subscription_status, "active","trial","suspended","cancelled")'
    )->fetchAll();

    // Firms by most recent activity (last 8)
    $firmsByActivity = $pdo->query(
        'SELECT f.id, f.name, f.subscription_status, f.status, f.credit_balance,
                (SELECT MAX(created_at) FROM activity_logs WHERE firm_id = f.id) AS last_activity,
                (SELECT COUNT(*) FROM users WHERE firm_id = f.id AND status = "active") AS users,
                (SELECT COUNT(*) FROM engagements WHERE firm_id = f.id
                  AND status NOT IN ("completed","billed","archived")) AS active_eng
           FROM firms f
          ORDER BY last_activity DESC, f.created_at DESC
          LIMIT 8'
    )->fetchAll();

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

<?php if ($role === 'super_admin'): ?>
    <!-- Super-admin operational panels -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8">
        <!-- Recent platform activity (2/3 width) -->
        <div class="lg:col-span-2 bg-white rounded-lg border border-slate-200">
            <div class="px-5 py-3 border-b border-slate-200 flex items-center justify-between">
                <h3 class="font-semibold text-slate-900">Recent platform activity</h3>
                <a href="/admin/activity.php" class="text-sm text-brand-600 hover:underline">Full log</a>
            </div>
            <?php if (empty($recentActivity)): ?>
                <div class="p-8 text-center text-sm text-slate-500">No activity yet.</div>
            <?php else: ?>
                <ul class="divide-y divide-slate-200">
                    <?php foreach ($recentActivity as $r): ?>
                        <li class="px-5 py-2.5 flex items-baseline justify-between gap-3">
                            <div class="min-w-0">
                                <div class="text-sm">
                                    <span class="font-medium"><?= e($r['user_name'] ?? 'System') ?></span>
                                    <?php if ($r['user_role']): ?>
                                        <span class="text-xs text-slate-500">· <?= e(ucwords(str_replace('_',' ',$r['user_role']))) ?></span>
                                    <?php endif; ?>
                                    <span class="text-slate-500">·</span>
                                    <code class="text-xs text-slate-700"><?= e($r['action']) ?></code>
                                </div>
                                <div class="text-xs text-slate-500 truncate">
                                    <?= e($r['firm_name'] ?? 'No firm') ?>
                                    <?php if (!empty($r['description'])): ?>
                                        · <?= e($r['description']) ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <time class="text-xs text-slate-500 whitespace-nowrap"
                                  datetime="<?= e($r['created_at']) ?>">
                                <?= e(datefmt($r['created_at'], 'd M H:i')) ?>
                            </time>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>

        <!-- Low-balance firms (1/3 width) -->
        <div class="bg-white rounded-lg border border-slate-200">
            <div class="px-5 py-3 border-b border-slate-200 flex items-center justify-between">
                <h3 class="font-semibold text-slate-900">Wallets running low</h3>
                <a href="/admin/wallets.php" class="text-xs text-brand-600 hover:underline">All wallets</a>
            </div>
            <?php if (empty($lowBalance)): ?>
                <div class="p-6 text-center text-sm text-slate-500">All wallets above MYR 25.</div>
            <?php else: ?>
                <ul class="divide-y divide-slate-200">
                    <?php foreach ($lowBalance as $lb): ?>
                        <li class="px-5 py-3">
                            <div class="flex items-center justify-between gap-2">
                                <div class="min-w-0">
                                    <div class="text-sm font-medium truncate"><?= e($lb['name']) ?></div>
                                    <div class="text-xs text-slate-500">
                                        <?= (int) $lb['calls_30d'] ?> AI calls / 30d
                                    </div>
                                </div>
                                <div class="text-right shrink-0">
                                    <div class="text-sm font-semibold text-rose-700 tabular-nums">
                                        <?= e(money((float) $lb['balance'], $lb['currency'] ?? 'MYR')) ?>
                                    </div>
                                    <a href="/admin/firms_topup.php?firm_id=<?= (int) $lb['id'] ?>"
                                       class="text-xs text-brand-600 hover:underline">Top up</a>
                                </div>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
        <!-- AI usage by function -->
        <div class="bg-white rounded-lg border border-slate-200">
            <div class="px-5 py-3 border-b border-slate-200">
                <h3 class="font-semibold text-slate-900">AI usage (30 days)</h3>
                <p class="text-xs text-slate-500">By function · top 8</p>
            </div>
            <?php if (empty($aiBreakdown)): ?>
                <div class="p-6 text-center text-sm text-slate-500">No AI calls in the last 30 days.</div>
            <?php else:
                $maxCalls = max(array_map(static fn($r) => (int) $r['calls'], $aiBreakdown)) ?: 1;
            ?>
                <ul class="px-5 py-3 space-y-3">
                    <?php foreach ($aiBreakdown as $a):
                        $pct = ((int) $a['calls'] / $maxCalls) * 100;
                    ?>
                        <li>
                            <div class="flex items-baseline justify-between text-xs">
                                <code class="text-slate-700"><?= e($a['function_name']) ?></code>
                                <span class="text-slate-500 tabular-nums">
                                    <?= (int) $a['calls'] ?> calls
                                    <?php if ((int) $a['failed'] > 0): ?>
                                        · <span class="text-rose-700"><?= (int) $a['failed'] ?> failed</span>
                                    <?php endif; ?>
                                    · MYR <?= number_format((float) $a['cost'], 4) ?>
                                </span>
                            </div>
                            <div class="w-full bg-slate-100 rounded h-2 mt-1">
                                <div class="bg-purple-500 h-2 rounded" style="width: <?= number_format($pct, 1) ?>%"></div>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>

        <!-- Subscription mix -->
        <div class="bg-white rounded-lg border border-slate-200">
            <div class="px-5 py-3 border-b border-slate-200">
                <h3 class="font-semibold text-slate-900">Subscription mix</h3>
                <p class="text-xs text-slate-500">All firms on the platform</p>
            </div>
            <?php if (empty($subBreakdown)): ?>
                <div class="p-6 text-center text-sm text-slate-500">No firms yet.</div>
            <?php else:
                $totalForMix = array_sum(array_column($subBreakdown, 'n')) ?: 1;
                $tones = [
                    'active'    => 'bg-emerald-500',
                    'trial'     => 'bg-blue-500',
                    'suspended' => 'bg-amber-500',
                    'cancelled' => 'bg-rose-500',
                ];
            ?>
                <div class="px-5 py-3">
                    <div class="flex h-3 w-full overflow-hidden rounded">
                        <?php foreach ($subBreakdown as $s):
                            $pct = ((int) $s['n'] / $totalForMix) * 100;
                            $cls = $tones[$s['subscription_status']] ?? 'bg-slate-400';
                        ?>
                            <div class="<?= $cls ?>" style="width: <?= number_format($pct, 2) ?>%"
                                 title="<?= e($s['subscription_status']) ?>: <?= (int) $s['n'] ?>"></div>
                        <?php endforeach; ?>
                    </div>
                    <ul class="mt-3 space-y-1">
                        <?php foreach ($subBreakdown as $s):
                            $pct = ((int) $s['n'] / $totalForMix) * 100;
                            $cls = $tones[$s['subscription_status']] ?? 'bg-slate-400';
                        ?>
                            <li class="flex items-center justify-between text-sm">
                                <span class="flex items-center gap-2">
                                    <span class="inline-block w-2 h-2 rounded-full <?= $cls ?>"></span>
                                    <?= e(ucfirst($s['subscription_status'])) ?>
                                </span>
                                <span class="text-slate-500 tabular-nums">
                                    <?= (int) $s['n'] ?>
                                    <span class="text-xs text-slate-400">· <?= number_format($pct, 0) ?>%</span>
                                </span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Firms by most recent activity -->
    <div class="bg-white rounded-lg border border-slate-200 overflow-hidden mb-8">
        <div class="px-5 py-3 border-b border-slate-200 flex items-center justify-between">
            <h3 class="font-semibold text-slate-900">Recently active firms</h3>
            <a href="/admin/firms.php" class="text-sm text-brand-600 hover:underline">All firms</a>
        </div>
        <?php if (empty($firmsByActivity)): ?>
            <div class="p-8 text-center text-sm text-slate-500">No firms yet.</div>
        <?php else: ?>
            <table class="table-app">
                <thead>
                    <tr>
                        <th>Firm</th>
                        <th>Subscription</th>
                        <th class="text-right">Active engagements</th>
                        <th class="text-right">Users</th>
                        <th class="text-right">Balance</th>
                        <th>Last activity</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($firmsByActivity as $f): ?>
                        <tr>
                            <td class="font-medium"><?= e($f['name']) ?></td>
                            <td><?= badge($f['subscription_status']) ?></td>
                            <td class="text-right tabular-nums"><?= (int) $f['active_eng'] ?></td>
                            <td class="text-right tabular-nums"><?= (int) $f['users'] ?></td>
                            <td class="text-right tabular-nums">
                                <?= e(money((float) $f['credit_balance'])) ?>
                            </td>
                            <td class="text-xs text-slate-500">
                                <?= $f['last_activity'] ? e(datefmt($f['last_activity'], 'd M H:i')) : '—' ?>
                            </td>
                            <td class="text-right">
                                <a href="/admin/firms.php?action=edit&id=<?= (int) $f['id'] ?>"
                                   class="text-sm text-brand-600 hover:underline">Open</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php
// Partner-level panels (high-risk WPs, AI alerts, top performers)
// Loaded only for partner / firm-admin / audit-manager / reviewer.
$showPartnerPanels = $firmId && in_array($role, ['firm_admin','audit_manager','reviewer'], true);
if ($showPartnerPanels) {
    require_once __DIR__ . '/includes/kpi.php';
    $highRiskWps = kpi_high_risk_wps($firmId, 8);
    $aiAlerts    = kpi_ai_alerts($firmId, 5);
    $kpiRows     = kpi_for_firm($firmId);
    $topPerformers = array_slice(array_filter($kpiRows,
        static fn($r) => ($r['productivity_score'] ?? 0) > 0), 0, 5);
}
?>

<?php if (!empty($showPartnerPanels)): ?>
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8">
        <!-- High-risk working papers -->
        <div class="lg:col-span-2 bg-white rounded-lg border border-slate-200 overflow-hidden">
            <div class="px-5 py-3 border-b border-slate-200 flex items-center justify-between">
                <h3 class="font-semibold text-slate-900">High-risk areas</h3>
                <a href="/audit/working_papers.php" class="text-sm text-brand-600 hover:underline">All working papers</a>
            </div>
            <?php if (empty($highRiskWps)): ?>
                <div class="p-8 text-center text-sm text-slate-500">No high-risk working papers right now.</div>
            <?php else: ?>
                <table class="table-app">
                    <thead>
                        <tr>
                            <th>Engagement</th>
                            <th>Working paper</th>
                            <th>Risk</th>
                            <th>Status</th>
                            <th class="text-right">Open notes</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($highRiskWps as $w): ?>
                        <tr>
                            <td>
                                <div class="text-sm font-medium"><?= e($w['company_name']) ?></div>
                                <div class="text-xs text-slate-500"><?= e($w['financial_year']) ?></div>
                            </td>
                            <td>
                                <div class="text-xs text-slate-500"><?= e($w['section_name'] ?? '') ?></div>
                                <div class="text-sm">
                                    <?php if ($w['reference_code']): ?>
                                        <span class="font-mono text-xs text-slate-500"><?= e($w['reference_code']) ?></span>
                                    <?php endif; ?>
                                    <?= e($w['title']) ?>
                                </div>
                            </td>
                            <td><?= $w['risk_rating'] ? badge($w['risk_rating']) : '<span class="text-xs text-slate-400">—</span>' ?></td>
                            <td><?= badge($w['status']) ?></td>
                            <td class="text-right tabular-nums <?= $w['open_notes'] > 0 ? 'text-rose-700 font-medium' : '' ?>">
                                <?= (int) $w['open_notes'] ?>
                            </td>
                            <td class="text-right">
                                <a href="/audit/working_paper_view.php?id=<?= (int) $w['id'] ?>"
                                   class="text-sm text-brand-600 hover:underline">Open</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <!-- AI alerts + top performers -->
        <div class="space-y-6">
            <div class="bg-white rounded-lg border border-slate-200">
                <div class="px-5 py-3 border-b border-slate-200 flex items-center justify-between">
                    <h3 class="font-semibold text-slate-900">AI alerts</h3>
                    <a href="/ai/outputs.php?status=draft" class="text-xs text-brand-600 hover:underline">All drafts</a>
                </div>
                <?php if (empty($aiAlerts)): ?>
                    <div class="p-5 text-center text-sm text-slate-500">No AI drafts pending review.</div>
                <?php else: ?>
                    <ul class="divide-y divide-slate-200">
                    <?php foreach ($aiAlerts as $a): ?>
                        <li class="px-5 py-3">
                            <a href="/ai/output_view.php?id=<?= (int) $a['id'] ?>"
                               class="text-sm font-medium text-slate-800 hover:text-brand-700 hover:underline block">
                                <?= e($a['title'] ?? $a['output_type']) ?>
                            </a>
                            <div class="text-xs text-slate-500">
                                <?= e($a['company_name'] ?? 'No engagement') ?>
                                · <?= e(datefmt($a['created_at'], 'd M H:i')) ?>
                            </div>
                        </li>
                    <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>

            <div class="bg-white rounded-lg border border-slate-200">
                <div class="px-5 py-3 border-b border-slate-200 flex items-center justify-between">
                    <h3 class="font-semibold text-slate-900">Top performers</h3>
                    <a href="/firm/kpi.php" class="text-xs text-brand-600 hover:underline">Full KPI</a>
                </div>
                <?php if (empty($topPerformers)): ?>
                    <div class="p-5 text-center text-sm text-slate-500">No staff activity yet.</div>
                <?php else: ?>
                    <ul class="divide-y divide-slate-200">
                    <?php foreach ($topPerformers as $p):
                        $score = (float) $p['productivity_score'];
                    ?>
                        <li class="px-5 py-3 flex items-center justify-between">
                            <div>
                                <div class="text-sm font-medium"><?= e($p['name']) ?></div>
                                <div class="text-xs text-slate-500">
                                    <?= e(ucwords(str_replace('_',' ',$p['role']))) ?>
                                    · <?= (int) $p['wp_prepared'] ?> WPs
                                    · <?= (int) $p['notes_cleared'] ?> cleared
                                </div>
                            </div>
                            <span class="text-sm font-semibold tabular-nums text-brand-700">
                                <?= number_format($score, 0) ?>
                            </span>
                        </li>
                    <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>
    </div>
<?php endif; ?>

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
