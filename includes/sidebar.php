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
        'label' => 'Staff',
        'href'  => '/firm/staff.php',
        'roles' => ['firm_admin'],
        'icon'  => 'M17 20h5v-2a4 4 0 00-3-3.87M9 20H4v-2a4 4 0 013-3.87M16 3.13a4 4 0 010 7.75M8 3.13a4 4 0 000 7.75M12 12a4 4 0 100-8 4 4 0 000 8z',
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
        'label' => 'Working Papers',
        'href'  => '/audit/working_papers.php',
        'roles' => ['firm_admin','audit_manager','senior_auditor','junior_auditor','reviewer'],
        'icon'  => 'M12 4v16m8-8H4',
    ],
    [
        'label' => 'Import Data',
        'href'  => '/import/index.php',
        'roles' => ['firm_admin','audit_manager','senior_auditor','junior_auditor','reviewer'],
        'icon'  => 'M4 16v2a2 2 0 002 2h12a2 2 0 002-2v-2M7 10l5-5m0 0l5 5m-5-5v12',
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
