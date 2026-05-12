<?php
/**
 * /includes/header.php
 *
 * Top of every authenticated page: <head>, top bar, flash messages.
 * The matching closer is /includes/footer.php.
 *
 * Pages may set $pageTitle before requiring this file.
 */

declare(strict_types=1);

if (!defined('AUDITBOS_BOOTSTRAPPED')) {
    http_response_code(403);
    exit('Forbidden');
}

$pageTitle = $pageTitle ?? 'Dashboard';
$user      = current_user();
$role      = current_role() ?? 'guest';
$flashes   = take_flashes();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle) ?> · <?= e(APP_NAME) ?></title>
    <meta name="robots" content="noindex,nofollow">

    <!-- Tailwind via CDN keeps Hostinger deployment friction at zero.
         Swap for compiled CSS once asset pipeline is added. -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        brand: {
                            50:  '#eef2ff',
                            100: '#e0e7ff',
                            500: '#4f46e5',
                            600: '#4338ca',
                            700: '#3730a3',
                            900: '#1e1b4b',
                        }
                    }
                }
            }
        };
    </script>
    <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body class="bg-slate-50 text-slate-800 antialiased">

<div class="min-h-screen flex">
    <?php require __DIR__ . '/sidebar.php'; ?>

    <div class="flex-1 flex flex-col min-w-0">
        <!-- Top bar -->
        <header class="bg-white border-b border-slate-200 px-6 py-3 flex items-center justify-between">
            <div class="flex items-center gap-3">
                <button id="sidebar-toggle"
                        class="md:hidden p-2 rounded hover:bg-slate-100"
                        aria-label="Toggle sidebar">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                              d="M4 6h16M4 12h16M4 18h16"/>
                    </svg>
                </button>
                <h1 class="text-lg font-semibold text-slate-900"><?= e($pageTitle) ?></h1>
            </div>
            <div class="flex items-center gap-4">
                <span class="hidden sm:inline text-sm text-slate-500">
                    <?= e(ucwords(str_replace('_', ' ', $role))) ?>
                </span>
                <div class="flex items-center gap-2">
                    <div class="w-8 h-8 rounded-full bg-brand-500 text-white flex items-center justify-center text-sm font-semibold">
                        <?= e(strtoupper(substr((string)($user['name'] ?? '?'), 0, 1))) ?>
                    </div>
                    <span class="hidden sm:inline text-sm font-medium"><?= e($user['name'] ?? '') ?></span>
                </div>
                <a href="/auth/logout.php"
                   class="text-sm text-slate-600 hover:text-rose-600 transition">Sign out</a>
            </div>
        </header>

        <!-- Flash messages -->
        <?php if (!empty($flashes)): ?>
            <div class="px-6 pt-4 space-y-2">
                <?php foreach ($flashes as $flash):
                    $type = $flash['type'] ?? 'info';
                    $colors = [
                        'success' => 'bg-emerald-50 border-emerald-200 text-emerald-800',
                        'error'   => 'bg-rose-50 border-rose-200 text-rose-800',
                        'warning' => 'bg-amber-50 border-amber-200 text-amber-800',
                        'info'    => 'bg-blue-50 border-blue-200 text-blue-800',
                    ];
                    $cls = $colors[$type] ?? $colors['info'];
                ?>
                    <div class="rounded border <?= $cls ?> px-4 py-2 text-sm">
                        <?= e($flash['message']) ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <main class="flex-1 px-6 py-6">
