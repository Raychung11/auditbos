<?php
/**
 * /includes/sidebar.php
 *
 * Role-aware navigation. Items are filtered based on current_role().
 * Active state is computed from REQUEST_URI prefix match.
 */

declare(strict_types=1);

if (!defined('AUDITBOS_BOOTSTRAPPED')) {
    http_response_code(403);
    exit('Forbidden');
}

$role = current_role() ?? 'guest';
$path = $_SERVER['REQUEST_URI'] ?? '';

/**
 * Each item: [label, href, roles[], icon_svg_path].
 * Use very small inline SVGs — no external icon dependencies.
 */
$nav = [
    [
        'label' => 'Dashboard',
        'href'  => '/dashboard.php',
        'roles' => ['super_admin','firm_admin','audit_manager','senior_auditor','junior_auditor','reviewer','client_user'],
        'icon'  => 'M3 12l9-9 9 9M5 10v10h14V10',
    ],
    [
        'label' => 'Firms',
        'href'  => '/admin/firms.php',
        'roles' => ['super_admin'],
        'icon'  => 'M3 21h18M5 21V7l7-4 7 4v14M9 9h.01M9 13h.01M9 17h.01M13 9h.01M13 13h.01M13 17h.01',
    ],
    [
        'label' => 'Wallets',
        'href'  => '/admin/wallets.php',
        'roles' => ['super_admin'],
        'icon'  => 'M3 8l4-4h10l4 4M3 8v10a2 2 0 002 2h14a2 2 0 002-2V8M3 8h18M16 13a2 2 0 100 4 2 2 0 000-4z',
    ],
    [
        'label' => 'Transactions',
        'href'  => '/admin/transactions.php',
        'roles' => ['super_admin'],
        'icon'  => 'M3 10h18M7 15h4m-7 4h16a2 2 0 002-2V7a2 2 0 00-2-2H4a2 2 0 00-2 2v10a2 2 0 002 2z',
    ],
    [
        'label' => 'Activity Log',
        'href'  => '/admin/activity.php',
        'roles' => ['super_admin'],
        'icon'  => 'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M12 11h4m-4 4h4m-6-4h.01M10 15h.01',
    ],
    [
        'label' => 'Staff',
        'href'  => '/firm/staff.php',
        'roles' => ['firm_admin'],
        'icon'  => 'M17 20h5v-2a4 4 0 00-3-3.87M9 20H4v-2a4 4 0 013-3.87M16 3.13a4 4 0 010 7.75M8 3.13a4 4 0 000 7.75M12 12a4 4 0 100-8 4 4 0 000 8z',
    ],
    [
        'label' => 'Staff KPI',
        'href'  => '/firm/kpi.php',
        'roles' => ['firm_admin','audit_manager','reviewer'],
        'icon'  => 'M3 21h2l1-4h4l1 4h2M14 21h2l1-7h4l1 7h2M5 13l3-8 3 6M14 14l3-9 3 4',
    ],
    [
        'label' => 'Clients',
        'href'  => '/firm/clients.php',
        'roles' => ['firm_admin','audit_manager','senior_auditor','junior_auditor','reviewer'],
        'icon'  => 'M3 7h18M3 12h18M3 17h18',
    ],
    [
        'label' => 'Engagements',
        'href'  => '/firm/engagements.php',
        'roles' => ['firm_admin','audit_manager','senior_auditor','junior_auditor','reviewer'],
        'icon'  => 'M9 12h6m-6 4h6M5 21h14a2 2 0 002-2V7l-5-5H7a2 2 0 00-2 2v17z',
    ],
    [
        'label' => 'Documents',
        'href'  => '/documents/index.php',
        'roles' => ['firm_admin','audit_manager','senior_auditor','junior_auditor','reviewer','client_user'],
        'icon'  => 'M9 17v-6a2 2 0 012-2h2a2 2 0 012 2v6M5 21h14a2 2 0 002-2V7l-5-5H7a2 2 0 00-2 2v17z',
    ],
    [
        'label' => 'Reminders',
        'href'  => '/firm/reminders.php',
        'roles' => ['firm_admin','audit_manager','senior_auditor'],
        'icon'  => 'M15 17h5l-1.4-1.4A2 2 0 0118 14.2V11a6 6 0 00-4-5.7V5a2 2 0 10-4 0v.3A6 6 0 006 11v3.2a2 2 0 01-.6 1.4L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9',
    ],
    [
        'label' => 'Working Papers',
        'href'  => '/audit/working_papers.php',
        'roles' => ['firm_admin','audit_manager','senior_auditor','junior_auditor','reviewer'],
        'icon'  => 'M12 4v16m8-8H4',
    ],
    [
        'label' => 'Lead Schedules',
        'href'  => '/audit/lead_schedules.php',
        'roles' => ['firm_admin','audit_manager','senior_auditor','junior_auditor','reviewer'],
        'icon'  => 'M3 10h18M3 14h18m-9-8v12M4 4h16a1 1 0 011 1v14a1 1 0 01-1 1H4a1 1 0 01-1-1V5a1 1 0 011-1z',
    ],
    [
        'label' => 'GL Analytics',
        'href'  => '/audit/gl_analytics.php',
        'roles' => ['firm_admin','audit_manager','senior_auditor','junior_auditor','reviewer'],
        'icon'  => 'M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z',
    ],
    [
        'label' => 'Materiality & Ratios',
        'href'  => '/audit/analytical_review.php',
        'roles' => ['firm_admin','audit_manager','senior_auditor','junior_auditor','reviewer'],
        'icon'  => 'M3 6l3 1m0 0l-3 9a5.002 5.002 0 006.001 0M6 7l3 9M6 7l6-2m6 2l3-1m-3 1l-3 9a5.002 5.002 0 006.001 0M18 7l3 9M18 7l-6-2m0-2v2m0 16V5m0 16H9m3 0h3',
    ],
    [
        'label' => 'Import Data',
        'href'  => '/import/index.php',
        'roles' => ['firm_admin','audit_manager','senior_auditor','junior_auditor','reviewer'],
        'icon'  => 'M4 16v2a2 2 0 002 2h12a2 2 0 002-2v-2M7 10l5-5m0 0l5 5m-5-5v12',
    ],
    [
        'label' => 'Financial Statements',
        'href'  => '/reports/financial_statements.php',
        'roles' => ['firm_admin','audit_manager','senior_auditor','junior_auditor','reviewer'],
        'icon'  => 'M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z',
    ],
    [
        'label' => 'AI Assistant',
        'href'  => '/ai/index.php',
        'roles' => ['firm_admin','audit_manager','senior_auditor','reviewer'],
        'icon'  => 'M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z',
    ],
    [
        'label' => 'AI Outputs',
        'href'  => '/ai/outputs.php',
        'roles' => ['firm_admin','audit_manager','senior_auditor','reviewer'],
        'icon'  => 'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2',
    ],
    [
        'label' => 'Wallet',
        'href'  => '/firm/wallet.php',
        'roles' => ['firm_admin','audit_manager'],
        'icon'  => 'M3 8l4-4h10l4 4M3 8v10a2 2 0 002 2h14a2 2 0 002-2V8M3 8h18M16 13a2 2 0 100 4 2 2 0 000-4z',
    ],
];

$visible = array_filter($nav, fn($item) => in_array($role, $item['roles'], true));
?>
<aside id="app-sidebar"
       class="hidden md:flex md:flex-col w-60 bg-brand-900 text-white shrink-0">
    <div class="px-5 py-5 border-b border-brand-700/40">
        <a href="/dashboard.php" class="flex items-center gap-2">
            <div class="w-9 h-9 rounded bg-brand-500 flex items-center justify-center font-bold">A</div>
            <div>
                <div class="text-sm font-semibold leading-tight">Audit BOS</div>
                <div class="text-[11px] text-brand-100/70 leading-tight">AI Audit OS</div>
            </div>
        </a>
    </div>
    <nav class="flex-1 px-2 py-3 space-y-0.5 overflow-y-auto">
        <?php foreach ($visible as $item):
            $active = $path && str_starts_with($path, $item['href'])
                && ($item['href'] !== '/dashboard.php' || $path === '/dashboard.php');
        ?>
            <a href="<?= e($item['href']) ?>"
               class="flex items-center gap-3 px-3 py-2 rounded text-sm transition <?=
                   $active
                       ? 'bg-brand-700/60 text-white font-medium'
                       : 'text-brand-100/80 hover:bg-brand-700/40 hover:text-white'
               ?>">
                <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8"
                          d="<?= e($item['icon']) ?>"/>
                </svg>
                <span><?= e($item['label']) ?></span>
            </a>
        <?php endforeach; ?>
    </nav>
    <div class="px-4 py-3 border-t border-brand-700/40 text-[11px] text-brand-100/60">
        v<?= e(APP_VERSION) ?>
    </div>
</aside>
