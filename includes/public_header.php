<?php
/**
 * /includes/public_header.php
 *
 * Lightweight header for marketing / public-facing pages
 * (/index.php and any future blog, pricing, about pages). Distinct
 * from /includes/header.php which is the app shell — public pages
 * have no sidebar, no flash messages, no auth context.
 *
 * Pages set $pageTitle and optionally $pageDescription before
 * requiring this file.
 */

declare(strict_types=1);

if (!defined('AUDITBOS_BOOTSTRAPPED')) {
    http_response_code(403); exit('Forbidden');
}

$pageTitle = $pageTitle ?? APP_NAME;
$pageDescription = $pageDescription ?? 'AI Audit BOS gathers documents, imports accounting data, drafts working papers, and helps your team sign off faster. Built for audit firms in Malaysia and Southeast Asia.';
$isLoggedIn = is_logged_in();
?>
<!doctype html>
<html lang="en" class="scroll-smooth">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle) ?> · <?= e(APP_NAME) ?></title>
    <meta name="description" content="<?= e($pageDescription) ?>">

    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        brand: {
                            50:'#eef2ff',100:'#e0e7ff',200:'#c7d2fe',
                            500:'#4f46e5',600:'#4338ca',700:'#3730a3',900:'#1e1b4b',
                        }
                    },
                    fontFamily: {
                        sans: ['"Inter"', 'system-ui', 'sans-serif']
                    }
                }
            }
        };
    </script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">

    <style>
        /* Subtle floating animation on the hero blob */
        @keyframes float-slow { 0%,100% { transform: translateY(0); } 50% { transform: translateY(-12px); } }
        .anim-float { animation: float-slow 8s ease-in-out infinite; }
        /* Fade-up reveal — toggled by IntersectionObserver */
        .reveal { opacity: 0; transform: translateY(16px); transition: opacity .6s ease-out, transform .6s ease-out; }
        .reveal.is-visible { opacity: 1; transform: translateY(0); }
        /* Tab transitions */
        .role-pane[hidden] { display: none; }
        details > summary { list-style: none; cursor: pointer; }
        details > summary::-webkit-details-marker { display: none; }
        details[open] .faq-icon { transform: rotate(45deg); }
        .faq-icon { transition: transform .2s ease-out; }
    </style>
</head>
<body class="bg-white text-slate-900 antialiased font-sans">

<header class="absolute top-0 inset-x-0 z-30">
    <nav class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-4 flex items-center justify-between">
        <a href="/" class="flex items-center gap-2 text-white">
            <span class="inline-flex w-9 h-9 rounded bg-white/15 backdrop-blur items-center justify-center font-bold">A</span>
            <span class="font-semibold text-lg leading-none">
                <?= e(APP_NAME) ?>
                <span class="block text-[11px] font-normal opacity-70">AI Audit OS</span>
            </span>
        </a>
        <div class="flex items-center gap-2">
            <a href="#why"          class="hidden md:inline text-sm text-white/80 hover:text-white px-3 py-2">Why us</a>
            <a href="#demo"         class="hidden md:inline text-sm text-white/80 hover:text-white px-3 py-2">See it</a>
            <a href="#how-it-works" class="hidden md:inline text-sm text-white/80 hover:text-white px-3 py-2">How it works</a>
            <a href="#faq"          class="hidden md:inline text-sm text-white/80 hover:text-white px-3 py-2">FAQ</a>
            <?php if ($isLoggedIn): ?>
                <a href="/dashboard.php"
                   class="rounded bg-white text-brand-700 hover:bg-brand-50 text-sm font-semibold px-4 py-2 transition">
                    Open dashboard
                </a>
            <?php else: ?>
                <a href="/auth/login.php"
                   class="rounded border border-white/30 text-white hover:bg-white/10 text-sm font-medium px-4 py-2 transition">
                    Sign in
                </a>
            <?php endif; ?>
        </div>
    </nav>
</header>
