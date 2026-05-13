<?php
/**
 * /index.php
 *
 * Public marketing landing page. Authenticated users are bounced to
 * their dashboard so the index URL works as both the marketing site
 * and the canonical app entry point.
 *
 * Uses /includes/public_header.php and /includes/public_footer.php —
 * NOT the authenticated app shell.
 */

declare(strict_types=1);

define('AUDITBOS_PUBLIC', true);
require_once __DIR__ . '/includes/auth_guard.php';

// Skip the marketing page for users who are already signed in.
if (is_logged_in()) {
    redirect('/dashboard.php');
}

$pageTitle = 'Run your audit firm intelligently';
$pageDescription = APP_NAME . ' is the AI-powered Business Operating System for audit firms. Automate workflow, collect client documents, import accounting data, generate working papers, and run partner review — in one platform.';
require __DIR__ . '/includes/public_header.php';
?>

<!-- ============================================================ -->
<!-- HERO                                                          -->
<!-- ============================================================ -->
<section class="relative overflow-hidden bg-gradient-to-br from-brand-900 via-brand-700 to-brand-500 text-white">
    <div aria-hidden="true" class="absolute inset-0 opacity-20"
         style="background-image:radial-gradient(circle at 20% 30%, rgba(255,255,255,.25), transparent 50%),radial-gradient(circle at 80% 70%, rgba(255,255,255,.18), transparent 45%);"></div>

    <div class="relative max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 pt-32 pb-24 lg:pt-40 lg:pb-32">
        <div class="max-w-3xl">
            <span class="inline-flex items-center gap-2 rounded-full bg-white/10 backdrop-blur border border-white/15 px-3 py-1 text-xs font-medium mb-6">
                <span class="w-2 h-2 rounded-full bg-emerald-400"></span>
                AI-powered audit operating system
            </span>
            <h1 class="text-4xl sm:text-5xl lg:text-6xl font-extrabold leading-tight tracking-tight mb-6">
                Run your audit firm intelligently.
            </h1>
            <p class="text-lg sm:text-xl text-brand-100/90 leading-relaxed mb-8 max-w-2xl">
                <strong class="text-white"><?= e(APP_NAME) ?></strong> replaces the patchwork of
                Excel sheets, shared drives, WhatsApp threads and audit-software file managers
                with a single operating system — built around how audit firms actually work,
                with AI woven through every step.
            </p>
            <div class="flex flex-wrap items-center gap-3">
                <a href="/auth/login.php"
                   class="rounded-md bg-white text-brand-700 hover:bg-brand-50 font-semibold px-5 py-3 text-sm transition">
                    Sign in to your firm
                </a>
                <a href="#features"
                   class="rounded-md border border-white/30 text-white hover:bg-white/10 font-medium px-5 py-3 text-sm transition">
                    See what's inside
                </a>
            </div>
            <p class="text-xs text-brand-100/70 mt-6">
                No credit card. No installation. Hostinger-friendly native PHP &mdash; deploys in minutes.
            </p>
        </div>
    </div>

    <!-- Stat strip -->
    <div class="relative bg-white/5 backdrop-blur border-t border-white/10">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6 grid grid-cols-2 md:grid-cols-4 gap-6 text-center">
            <div>
                <div class="text-3xl font-bold text-white">10+</div>
                <div class="text-xs text-brand-100/80 uppercase tracking-wide mt-1">Audit modules</div>
            </div>
            <div>
                <div class="text-3xl font-bold text-white">8</div>
                <div class="text-xs text-brand-100/80 uppercase tracking-wide mt-1">AI functions</div>
            </div>
            <div>
                <div class="text-3xl font-bold text-white">7</div>
                <div class="text-xs text-brand-100/80 uppercase tracking-wide mt-1">Role-based dashboards</div>
            </div>
            <div>
                <div class="text-3xl font-bold text-white">Native</div>
                <div class="text-xs text-brand-100/80 uppercase tracking-wide mt-1">PHP — zero dependencies</div>
            </div>
        </div>
    </div>
</section>

<!-- ============================================================ -->
<!-- PROBLEM                                                       -->
<!-- ============================================================ -->
<section class="bg-slate-50 py-20">
    <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 text-center">
        <h2 class="text-3xl sm:text-4xl font-bold text-slate-900 mb-4">
            Traditional audit software helps auditors complete files.
        </h2>
        <p class="text-xl text-slate-600 mb-2">
            <strong class="text-brand-700"><?= e(APP_NAME) ?> helps audit firms run their entire audit operation intelligently.</strong>
        </p>
        <p class="text-base text-slate-500 max-w-3xl mx-auto">
            Less manual follow-up. Better audit quality. Faster partner review.
            Better client communication. Real visibility into staff workload and
            engagement health — all in one place.
        </p>
    </div>
</section>

<!-- ============================================================ -->
<!-- FEATURES                                                      -->
<!-- ============================================================ -->
<section id="features" class="bg-white py-20">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="text-center mb-14">
            <span class="text-xs font-semibold text-brand-600 uppercase tracking-wider">What's inside</span>
            <h2 class="text-3xl sm:text-4xl font-bold text-slate-900 mt-2">
                Every part of an audit, in one platform
            </h2>
            <p class="text-slate-600 mt-3 max-w-2xl mx-auto">
                Designed around how audit teams actually work — not a generic project tool retro-fitted to audit.
            </p>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
            <?php
            $features = [
                ['Engagement workspace',
                 'Status, team, deadline, document checklist, working papers, AI panel, accounting data and activity timeline in one screen per engagement.',
                 'M9 12h6m-6 4h6M5 21h14a2 2 0 002-2V7l-5-5H7a2 2 0 00-2 2v17z'],
                ['Client document portal',
                 'Branded portal for clients to upload trial balances, bank statements, sales/purchase invoices, payroll and more. Auto-marks requests received when files arrive.',
                 'M9 17v-6a2 2 0 012-2h2a2 2 0 012 2v6M5 21h14a2 2 0 002-2V7l-5-5H7a2 2 0 00-2 2v17z'],
                ['Accounting data import',
                 'CSV and native XLSX import for trial balance and general ledger. Reusable column mappings per accounting source (SQL Account, AutoCount, UBS, Bukku, Million, Financio).',
                 'M4 16v2a2 2 0 002 2h12a2 2 0 002-2v-2M7 10l5-5m0 0l5 5m-5-5v12'],
                ['Working papers + review notes',
                 'Section taxonomy, risk ratings, full preparer-reviewer-partner thread. Status auto-bumps as review notes are raised, responded, cleared.',
                 'M12 4v16m8-8H4'],
                ['Financial statement export',
                 'Statement of Financial Position and Statement of Comprehensive Income generated directly from the trial balance, with print/PDF and CSV export.',
                 'M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z'],
                ['AI assistant — woven through',
                 'Variance analysis, audit query drafting, working-paper review, management letter, client reminders, document classification, engagement summary, partner review pack.',
                 'M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z'],
                ['Staff KPI + partner dashboard',
                 'Live productivity scores per staff, high-risk areas, top performers, AI alerts. Partners see the engagement health at a glance.',
                 'M3 21h2l1-4h4l1 4h2M14 21h2l1-7h4l1 7h2M5 13l3-8 3 6'],
                ['Credit wallet + billing',
                 'Per-firm wallets, manual top-ups, full transaction history, AI usage by function. Cost transparency for every Claude call.',
                 'M3 8l4-4h10l4 4M3 8v10a2 2 0 002 2h14a2 2 0 002-2V8M3 8h18M16 13a2 2 0 100 4 2 2 0 000-4z'],
                ['Activity timeline + audit trail',
                 'Every status change, document upload, review note, AI call and login is logged. Engagement-scoped timeline shows the full story.',
                 'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M12 11h4m-4 4h4m-6-4h.01M10 15h.01'],
            ];
            foreach ($features as [$title, $body, $icon]):
            ?>
                <div class="bg-slate-50 border border-slate-200 rounded-lg p-6 hover:shadow-md hover:border-brand-200 transition">
                    <div class="inline-flex items-center justify-center w-10 h-10 rounded bg-brand-100 text-brand-700 mb-4">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8"
                                  d="<?= e($icon) ?>"/>
                        </svg>
                    </div>
                    <h3 class="font-semibold text-slate-900 mb-2"><?= e($title) ?></h3>
                    <p class="text-sm text-slate-600 leading-relaxed"><?= e($body) ?></p>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- ============================================================ -->
<!-- HOW IT WORKS                                                  -->
<!-- ============================================================ -->
<section id="how-it-works" class="bg-slate-50 py-20">
    <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="text-center mb-14">
            <span class="text-xs font-semibold text-brand-600 uppercase tracking-wider">How it works</span>
            <h2 class="text-3xl sm:text-4xl font-bold text-slate-900 mt-2">
                From kickoff to sign-off
            </h2>
            <p class="text-slate-600 mt-3 max-w-2xl mx-auto">
                A typical engagement, end to end.
            </p>
        </div>

        <ol class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
            <?php
            $steps = [
                ['01', 'Onboard',
                 'Create the firm, add staff, register the client and open an engagement with partner, manager and deadline.'],
                ['02', 'Collect',
                 'Seed the document checklist, invite the client to the portal, watch requests flip to received as files arrive. AI auto-classifies what comes in.'],
                ['03', 'Audit',
                 'Import the trial balance and GL, prepare working papers, raise review notes, run AI variance analysis and query drafting on real data.'],
                ['04', 'Sign off',
                 'Partner review pack, management letter, AI engagement summary, financial statement export — all from the working files already in the system.'],
            ];
            foreach ($steps as [$num, $title, $body]):
            ?>
                <li class="relative bg-white border border-slate-200 rounded-lg p-6">
                    <div class="text-3xl font-bold text-brand-200 mb-2"><?= e($num) ?></div>
                    <h3 class="font-semibold text-slate-900 mb-2"><?= e($title) ?></h3>
                    <p class="text-sm text-slate-600 leading-relaxed"><?= e($body) ?></p>
                </li>
            <?php endforeach; ?>
        </ol>
    </div>
</section>

<!-- ============================================================ -->
<!-- ROLES                                                         -->
<!-- ============================================================ -->
<section id="roles" class="bg-white py-20">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="text-center mb-14">
            <span class="text-xs font-semibold text-brand-600 uppercase tracking-wider">Who it's for</span>
            <h2 class="text-3xl sm:text-4xl font-bold text-slate-900 mt-2">
                One platform — seven dashboards
            </h2>
            <p class="text-slate-600 mt-3 max-w-2xl mx-auto">
                Each role sees the work that's theirs. No noise, no rummaging through unrelated tabs.
            </p>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6">
            <?php
            $roles = [
                ['Partners',          'High-risk areas, top performers, AI alerts pending review, engagement health, sign-off-ready packs.'],
                ['Firm admins',       'Staff, clients, engagements, credit wallet, AI usage and platform settings.'],
                ['Audit managers',    'Engagement portfolio, document follow-up, working-paper review, team workload.'],
                ['Senior auditors',   'Working papers to prepare, AI assistance on TB and GL data, review-note response thread.'],
                ['Junior auditors',   'Focused queue of WPs to prepare, document upload tools, AI variance helper.'],
                ['Reviewers',         'Outstanding review notes, AI-drafted reviewer suggestions, working-paper status board.'],
                ['Client users',      'Branded portal showing exactly which documents are needed, upload progress, engagement status.'],
                ['Super admins',      'Platform-wide observability — firms, wallets, AI usage, transactions, activity log, impersonate.'],
            ];
            foreach ($roles as [$role, $blurb]):
            ?>
                <div class="bg-slate-50 border border-slate-200 rounded-lg p-5">
                    <h3 class="font-semibold text-slate-900 mb-1"><?= e($role) ?></h3>
                    <p class="text-sm text-slate-600 leading-relaxed"><?= e($blurb) ?></p>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- ============================================================ -->
<!-- SECURITY / TECHNICAL CREDIBILITY                              -->
<!-- ============================================================ -->
<section id="security" class="bg-slate-900 text-slate-100 py-20">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-12 items-center">
            <div>
                <span class="text-xs font-semibold text-brand-200 uppercase tracking-wider">Built right</span>
                <h2 class="text-3xl sm:text-4xl font-bold text-white mt-2 mb-4">
                    Production-grade where it matters
                </h2>
                <p class="text-slate-300 leading-relaxed mb-6">
                    Multi-tenant by design. Every page enforces role-based access.
                    Every database query uses prepared statements. Client uploads
                    are stored outside the web root and served only through
                    authenticated download endpoints.
                </p>
                <ul class="space-y-3 text-sm">
                    <?php
                    $points = [
                        'PHP 8 native — no Composer, no framework lock-in, deploys to Hostinger shared hosting',
                        'PDO with EMULATE_PREPARES=false — real prepared statements, every query',
                        'bcrypt password hashing, per-user lockout, single-use 24h reset tokens',
                        'CSRF tokens auto-verified on every POST via the auth guard',
                        'Strict file upload validation — extension + MIME allow-list, random filenames, path-traversal containment',
                        'Full activity audit trail, including impersonation tagging for super-admin support',
                        'AI calls logged with prompt, output, tokens and MYR cost — full transparency',
                    ];
                    foreach ($points as $p):
                    ?>
                        <li class="flex items-start gap-3">
                            <svg class="w-5 h-5 text-emerald-400 shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                      d="M5 13l4 4L19 7"/>
                            </svg>
                            <span class="text-slate-300"><?= e($p) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <div class="bg-slate-800 rounded-lg border border-slate-700 p-6 overflow-hidden">
                <div class="text-xs text-slate-500 mb-3 font-mono">includes/auth_guard.php</div>
                <pre class="text-xs text-slate-300 font-mono leading-relaxed overflow-x-auto"><span class="text-purple-400">declare</span>(strict_types=<span class="text-amber-400">1</span>);
<span class="text-purple-400">require_once</span> <span class="text-emerald-400">'/config/app_config.php'</span>;
<span class="text-purple-400">require_once</span> <span class="text-emerald-400">'/config/db_config.php'</span>;

<span class="text-slate-500">// Hardened cookies, idle timeout, session regen</span>
<span class="text-purple-400">session_set_cookie_params</span>([
    <span class="text-emerald-400">'lifetime'</span>  => SESSION_LIFETIME,
    <span class="text-emerald-400">'secure'</span>    => $isHttps,
    <span class="text-emerald-400">'httponly'</span>  => <span class="text-blue-400">true</span>,
    <span class="text-emerald-400">'samesite'</span>  => <span class="text-emerald-400">'Lax'</span>,
]);

<span class="text-slate-500">// Role enforcement — every protected page</span>
<span class="text-purple-400">function</span> <span class="text-blue-300">require_role</span>(<span class="text-blue-400">array</span> $roles): <span class="text-blue-400">void</span> {
    require_login();
    <span class="text-purple-400">if</span> (!in_array(current_role(), $roles, <span class="text-blue-400">true</span>)) {
        http_response_code(<span class="text-amber-400">403</span>);
        exit;
    }
}

<span class="text-slate-500">// CSRF auto-check on every POST</span>
csrf_check();</pre>
            </div>
        </div>
    </div>
</section>

<!-- ============================================================ -->
<!-- CTA                                                           -->
<!-- ============================================================ -->
<section class="bg-gradient-to-br from-brand-700 to-brand-500 text-white py-16">
    <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 text-center">
        <h2 class="text-3xl sm:text-4xl font-bold mb-4">
            Ready to run your firm intelligently?
        </h2>
        <p class="text-lg text-brand-100/90 mb-8 max-w-2xl mx-auto">
            Sign in with your firm credentials. New firm? Ask your platform admin to provision a wallet and your first user.
        </p>
        <div class="flex flex-wrap items-center justify-center gap-3">
            <a href="/auth/login.php"
               class="rounded-md bg-white text-brand-700 hover:bg-brand-50 font-semibold px-6 py-3 text-base transition">
                Sign in
            </a>
            <a href="#features"
               class="rounded-md border border-white/30 text-white hover:bg-white/10 font-medium px-6 py-3 text-base transition">
                Back to top
            </a>
        </div>
    </div>
</section>

<?php require __DIR__ . '/includes/public_footer.php'; ?>
