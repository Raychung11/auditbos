<?php
/**
 * /index.php
 *
 * Public marketing landing page. Authenticated users are bounced to
 * their dashboard. Copy is in plain English; interactivity provided
 * by IntersectionObserver-driven reveals, an animated stat counter,
 * a role-tab switcher, and HTML <details> FAQ accordions — all
 * vanilla JS, no extra dependencies.
 */

declare(strict_types=1);

define('AUDITBOS_PUBLIC', true);
require_once __DIR__ . '/includes/auth_guard.php';

if (is_logged_in()) {
    redirect('/dashboard.php');
}

$pageTitle = 'Less spreadsheets. Less chasing. More auditing.';
$pageDescription = 'AI Audit BOS gathers documents from your clients, imports their accounting data, drafts working papers, and helps your team sign off faster. Built for audit firms in Malaysia and Southeast Asia.';
require __DIR__ . '/includes/public_header.php';
?>

<!-- ============================================================ -->
<!-- HERO                                                          -->
<!-- ============================================================ -->
<section class="relative overflow-hidden bg-gradient-to-br from-brand-900 via-brand-700 to-brand-500 text-white">
    <div aria-hidden="true" class="absolute inset-0 opacity-25"
         style="background-image:radial-gradient(circle at 18% 28%, rgba(255,255,255,.30), transparent 50%),radial-gradient(circle at 82% 72%, rgba(255,255,255,.20), transparent 45%);"></div>
    <div aria-hidden="true" class="anim-float absolute -right-24 -top-24 w-96 h-96 rounded-full bg-brand-200/20 blur-3xl"></div>

    <div class="relative max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 pt-32 pb-20 lg:pt-40 lg:pb-28">
        <div class="grid grid-cols-1 lg:grid-cols-12 gap-10 items-center">
            <div class="lg:col-span-7">
                <span class="inline-flex items-center gap-2 rounded-full bg-white/10 backdrop-blur border border-white/15 px-3 py-1 text-xs font-medium mb-6">
                    <span class="w-2 h-2 rounded-full bg-emerald-400"></span>
                    Made for audit firms · Built with AI inside
                </span>
                <h1 class="text-4xl sm:text-5xl lg:text-6xl font-extrabold leading-[1.05] tracking-tight mb-5">
                    Less spreadsheets.<br>
                    Less chasing.<br>
                    <span class="text-brand-200">More auditing.</span>
                </h1>
                <p class="text-lg sm:text-xl text-brand-100/90 leading-relaxed mb-8 max-w-xl">
                    <strong class="text-white"><?= e(APP_NAME) ?></strong> gathers documents from your clients,
                    imports their accounting data, drafts working papers, and helps your team
                    sign off faster — all in one place. Built for audit firms
                    in Malaysia and Southeast Asia.
                </p>
                <div class="flex flex-wrap items-center gap-3">
                    <a href="/auth/login.php"
                       class="rounded-md bg-white text-brand-700 hover:bg-brand-50 font-semibold px-5 py-3 text-sm transition">
                        Sign in to your firm &rarr;
                    </a>
                    <a href="#demo"
                       class="rounded-md border border-white/30 text-white hover:bg-white/10 font-medium px-5 py-3 text-sm transition">
                        Take a look first
                    </a>
                </div>
                <p class="text-xs text-brand-100/70 mt-5">
                    No software to install. Works in any browser. Deploys on Hostinger.
                </p>
            </div>

            <!-- Hero illustration: a stylised dashboard preview -->
            <div class="lg:col-span-5">
                <div class="bg-white/10 backdrop-blur-md border border-white/20 rounded-xl shadow-2xl p-4 rotate-1 hover:rotate-0 transition-transform duration-500">
                    <div class="flex items-center gap-1.5 mb-3">
                        <span class="w-2.5 h-2.5 rounded-full bg-rose-400"></span>
                        <span class="w-2.5 h-2.5 rounded-full bg-amber-400"></span>
                        <span class="w-2.5 h-2.5 rounded-full bg-emerald-400"></span>
                        <span class="ml-2 text-[11px] text-white/60">auditbos.app/dashboard</span>
                    </div>
                    <div class="bg-white rounded-lg p-4 text-slate-800">
                        <div class="text-xs text-slate-500 mb-3">Welcome back, Ray · Partner</div>
                        <div class="grid grid-cols-2 gap-2 mb-3">
                            <div class="bg-emerald-50 rounded p-2">
                                <div class="text-[10px] text-slate-500">Active jobs</div>
                                <div class="text-2xl font-bold text-emerald-700">12</div>
                            </div>
                            <div class="bg-rose-50 rounded p-2">
                                <div class="text-[10px] text-slate-500">Overdue</div>
                                <div class="text-2xl font-bold text-rose-700">2</div>
                            </div>
                            <div class="bg-amber-50 rounded p-2">
                                <div class="text-[10px] text-slate-500">Docs pending</div>
                                <div class="text-2xl font-bold text-amber-700">7</div>
                            </div>
                            <div class="bg-brand-50 rounded p-2">
                                <div class="text-[10px] text-slate-500">AI alerts</div>
                                <div class="text-2xl font-bold text-brand-700">3</div>
                            </div>
                        </div>
                        <div class="space-y-1.5">
                            <div class="flex items-center justify-between text-xs">
                                <span class="font-medium">Acme Tech · FY2024</span>
                                <span class="text-emerald-600">In progress</span>
                            </div>
                            <div class="flex items-center justify-between text-xs text-slate-600">
                                <span>Sunset Mfg · FY2024</span>
                                <span class="text-amber-600">Pending docs</span>
                            </div>
                            <div class="flex items-center justify-between text-xs text-slate-600">
                                <span>Bayu Retail · FY2024</span>
                                <span class="text-slate-500">Draft</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Animated stat strip -->
    <div class="relative bg-white/5 backdrop-blur border-t border-white/10">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-7 grid grid-cols-2 md:grid-cols-4 gap-6 text-center">
            <?php
            $stats = [
                ['10',  'Audit modules in one place'],
                ['8',   'AI helpers built in'],
                ['7',   'Tailored dashboards'],
                ['100', 'Percent native PHP — no Composer'],
            ];
            foreach ($stats as [$n, $label]):
            ?>
                <div>
                    <div class="text-4xl font-extrabold text-white tabular-nums"
                         data-counter="<?= e($n) ?>">0</div>
                    <div class="text-xs text-brand-100/80 uppercase tracking-wide mt-1"><?= e($label) ?></div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- ============================================================ -->
<!-- WHY                                                            -->
<!-- ============================================================ -->
<section id="why" class="bg-slate-50 py-20">
    <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="text-center mb-12 reveal">
            <h2 class="text-3xl sm:text-4xl font-bold text-slate-900 mb-3">
                Sound familiar?
            </h2>
            <p class="text-lg text-slate-600 max-w-3xl mx-auto">
                Most audit firms run their work across five or six different tools at once.
                Things slip. Time disappears. Junior staff spend half their day chasing files.
            </p>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-10">
            <!-- Before -->
            <div class="bg-white border border-rose-200 rounded-lg p-6 reveal">
                <div class="text-xs uppercase tracking-wider text-rose-600 font-semibold mb-2">Before</div>
                <h3 class="font-bold text-slate-900 mb-4">The audit firm shuffle</h3>
                <ul class="space-y-2 text-sm text-slate-700">
                    <?php foreach ([
                        'Excel for the trial balance',
                        'WhatsApp for chasing clients',
                        'Google Drive for files',
                        'Another tool for working papers',
                        'Email threads for everything else',
                        'Partners flying blind on staff workload',
                    ] as $i):
                    ?>
                        <li class="flex items-start gap-2">
                            <span class="text-rose-500 mt-0.5">✕</span>
                            <span><?= e($i) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>

            <!-- After -->
            <div class="bg-white border-2 border-emerald-300 rounded-lg p-6 shadow-md reveal">
                <div class="text-xs uppercase tracking-wider text-emerald-600 font-semibold mb-2">With AI Audit BOS</div>
                <h3 class="font-bold text-slate-900 mb-4">One platform, everything inside</h3>
                <ul class="space-y-2 text-sm text-slate-700">
                    <?php foreach ([
                        'One tidy checklist of what each client owes you',
                        'Branded portal where clients upload — you stop chasing',
                        'Drop in any spreadsheet, AI flags what looks weird',
                        'Working papers + review notes in one thread',
                        'Partner sees every job, every risk, on one screen',
                        'AI drafts the queries, summaries and reminders for you',
                    ] as $i):
                    ?>
                        <li class="flex items-start gap-2">
                            <span class="text-emerald-500 mt-0.5">✓</span>
                            <span><?= e($i) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>

        <div class="text-center reveal">
            <p class="text-base text-slate-500 italic max-w-2xl mx-auto">
                "Traditional audit software helps auditors complete files.
                <strong class="text-brand-700"><?= e(APP_NAME) ?> helps audit firms run the whole show.</strong>"
            </p>
        </div>
    </div>
</section>

<!-- ============================================================ -->
<!-- INTERACTIVE DEMO — ROLE TABS                                  -->
<!-- ============================================================ -->
<section id="demo" class="bg-white py-20">
    <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="text-center mb-10 reveal">
            <span class="text-xs font-semibold text-brand-600 uppercase tracking-wider">See what each person sees</span>
            <h2 class="text-3xl sm:text-4xl font-bold text-slate-900 mt-2">
                Click a role &mdash; the screen changes
            </h2>
            <p class="text-slate-600 mt-3 max-w-2xl mx-auto">
                Each person on your team only sees the parts that matter to them. No noise.
            </p>
        </div>

        <div class="reveal" data-role-tabs>
            <div class="flex flex-wrap gap-2 justify-center mb-6" role="tablist">
                <?php
                $tabs = [
                    'partner'  => ['Partner',         'partner'],
                    'manager'  => ['Audit Manager',   'manager'],
                    'senior'   => ['Senior Auditor',  'senior'],
                    'junior'   => ['Junior Auditor',  'junior'],
                    'client'   => ['Your Client',     'client'],
                ];
                $first = true;
                foreach ($tabs as $key => [$label, $_]):
                ?>
                    <button type="button" role="tab"
                            data-role-tab="<?= $key ?>"
                            aria-selected="<?= $first ? 'true' : 'false' ?>"
                            class="role-tab rounded-full px-5 py-2 text-sm font-medium transition
                                   <?= $first ? 'bg-brand-600 text-white' : 'bg-slate-100 text-slate-700 hover:bg-slate-200' ?>">
                        <?= e($label) ?>
                    </button>
                <?php $first = false; endforeach; ?>
            </div>

            <?php
            $panes = [
                'partner' => [
                    'headline' => 'Run the firm like a control tower',
                    'body'     => 'You sign in and see every job in flight, who is working on what, what is overdue, and which jobs need your attention next. The AI flags risky areas before you have to ask.',
                    'sees'     => [
                        'High-risk audit areas across every active job',
                        'Staff productivity scores and workload bars',
                        'AI drafts waiting for your review',
                        'Top 5 performers this week',
                        'Engagements approaching deadline',
                    ],
                ],
                'manager' => [
                    'headline' => 'Move jobs forward without chasing',
                    'body'     => 'You see your portfolio in one view. Documents the client still owes. Working papers your team has prepared. Review notes waiting for a response. The AI summarises status so you can update the partner in seconds.',
                    'sees'     => [
                        'Document checklist progress per client',
                        'Working papers ready for review',
                        'Review notes raised, responded, cleared',
                        'AI-drafted client queries you can send out',
                        'Engagement timeline showing what happened today',
                    ],
                ],
                'senior' => [
                    'headline' => 'Spend your day auditing — not chasing',
                    'body'     => 'You see only the working papers assigned to you. The trial balance is already imported. AI suggests procedures and conclusions. You focus on the judgement; the AI handles the formatting.',
                    'sees'     => [
                        'Your working papers in priority order',
                        'Trial balance with variance flags already highlighted',
                        'AI-drafted review of your WP before partner sees it',
                        'One-click reply to reviewer notes',
                        'Engagement context without flipping between tools',
                    ],
                ],
                'junior' => [
                    'headline' => 'Know exactly what to do next',
                    'body'     => 'No more "what should I be working on?". Your queue is clear. AI helps you write the first draft so you can learn faster.',
                    'sees'     => [
                        'A focused queue of tasks just for you',
                        'AI variance analysis to spot what to dig into',
                        'Document upload tool with auto-classification',
                        'Review notes from your senior, clearly assigned',
                    ],
                ],
                'client' => [
                    'headline' => 'Send your auditor what they need — without WhatsApp ping-pong',
                    'body'     => 'You get a branded portal showing exactly which documents are needed and which you have already sent. Upload once. See the status. No more "did you get my email?".',
                    'sees'     => [
                        'A tidy checklist of what is still needed',
                        'Drag-and-drop upload, with file size and status shown',
                        'A confirmation when the firm marks your file received',
                        'No login complications — one link sets your password',
                    ],
                ],
            ];
            $first = true;
            foreach ($panes as $key => $p):
            ?>
                <div class="role-pane bg-gradient-to-br from-slate-50 to-white border border-slate-200 rounded-xl p-6 sm:p-10"
                     data-role-pane="<?= $key ?>"
                     <?= $first ? '' : 'hidden' ?>>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-8 items-start">
                        <div>
                            <h3 class="text-2xl font-bold text-slate-900 mb-3"><?= e($p['headline']) ?></h3>
                            <p class="text-slate-600 leading-relaxed"><?= e($p['body']) ?></p>
                        </div>
                        <div class="bg-white border border-slate-200 rounded-lg p-5">
                            <div class="text-xs uppercase tracking-wider font-semibold text-brand-700 mb-3">What you see</div>
                            <ul class="space-y-2">
                                <?php foreach ($p['sees'] as $line): ?>
                                    <li class="flex items-start gap-2 text-sm text-slate-700">
                                        <svg class="w-4 h-4 text-emerald-500 shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5"
                                                  d="M5 13l4 4L19 7"/>
                                        </svg>
                                        <span><?= e($line) ?></span>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    </div>
                </div>
            <?php $first = false; endforeach; ?>
        </div>
    </div>
</section>

<!-- ============================================================ -->
<!-- HOW IT WORKS                                                  -->
<!-- ============================================================ -->
<section id="how-it-works" class="bg-slate-900 text-slate-100 py-20">
    <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="text-center mb-14 reveal">
            <span class="text-xs font-semibold text-brand-200 uppercase tracking-wider">How it works</span>
            <h2 class="text-3xl sm:text-4xl font-bold text-white mt-2">From the first hello to sign-off</h2>
            <p class="text-slate-400 mt-3 max-w-2xl mx-auto">A normal audit, end to end. Roughly what it looks like with us.</p>
        </div>

        <ol class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
            <?php
            $steps = [
                ['1', 'Set up your firm',
                 'Add your staff. Add a client. Open the first job. Pick a partner and a deadline. About 10 minutes.'],
                ['2', 'Invite the client',
                 'Send a link. They land in a portal with a clear list of what to upload. The platform marks each item received as files come in.'],
                ['3', 'Drop in the numbers',
                 'Upload the trial balance and the general ledger — CSV or Excel. Works with SQL Account, AutoCount, UBS, Bukku, Million and Financio exports.'],
                ['4', 'Audit and sign off',
                 'Prepare working papers. AI drafts variance analysis, audit queries, management letters. Partner reviews on one screen. Print the financial statements.'],
            ];
            foreach ($steps as [$num, $title, $body]):
            ?>
                <li class="reveal relative bg-slate-800 border border-slate-700 rounded-lg p-6 hover:border-brand-500 transition-colors">
                    <div class="absolute -top-4 left-6 inline-flex items-center justify-center w-10 h-10 rounded-full bg-brand-500 text-white font-bold">
                        <?= e($num) ?>
                    </div>
                    <h3 class="font-bold text-white mt-4 mb-2"><?= e($title) ?></h3>
                    <p class="text-sm text-slate-400 leading-relaxed"><?= e($body) ?></p>
                </li>
            <?php endforeach; ?>
        </ol>
    </div>
</section>

<!-- ============================================================ -->
<!-- FEATURES — hover-reveal cards                                 -->
<!-- ============================================================ -->
<section id="features" class="bg-white py-20">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="text-center mb-14 reveal">
            <span class="text-xs font-semibold text-brand-600 uppercase tracking-wider">What's inside</span>
            <h2 class="text-3xl sm:text-4xl font-bold text-slate-900 mt-2">Every part of an audit, in one place</h2>
            <p class="text-slate-600 mt-3 max-w-2xl mx-auto">Hover over a card to see what it does for your team.</p>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-5">
            <?php
            $features = [
                ['One screen per job',
                 'See everything about an audit in one tab — team, deadline, document list, working papers, AI helpers.',
                 'Open Acme Tech FY2024 and you see who is on it, what is missing, where the team is stuck, and what the AI is flagging — without opening another browser tab.',
                 'M9 12h6m-6 4h6M5 21h14a2 2 0 002-2V7l-5-5H7a2 2 0 00-2 2v17z'],
                ['Clients upload, you stop chasing',
                 'A branded portal where clients send you their files. They tick the list off, you see it update.',
                 'Send one link. Your client lands on a clean checklist. When they upload, your engagement status updates by itself.',
                 'M9 17v-6a2 2 0 012-2h2a2 2 0 012 2v6M5 21h14a2 2 0 002-2V7l-5-5H7a2 2 0 00-2 2v17z'],
                ['Drop in any spreadsheet',
                 'Trial balance, general ledger — Excel or CSV. From SQL Account, AutoCount, UBS, Bukku, Million, Financio.',
                 'First upload, you pick which column is what. Next time, the platform remembers — for that client and that accounting software.',
                 'M4 16v2a2 2 0 002 2h12a2 2 0 002-2v-2M7 10l5-5m0 0l5 5m-5-5v12'],
                ['AI that knows audit',
                 'Variance analysis, audit queries, management letter, working-paper review, client reminders, document classification.',
                 'Click a button on a trial balance — get a partner-grade variance memo grouped by risk level. Every AI output is a draft. A human always reviews before it leaves the firm.',
                 'M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z'],
                ['Working papers that hold the thread',
                 'Preparer prepares. Reviewer raises notes. Preparer responds. Reviewer clears. All in one screen, with status updating automatically.',
                 'No more emailing review notes around. The status of every WP is visible to the team — and to the partner.',
                 'M12 4v16m8-8H4'],
                ['Press a button, get the FS',
                 'Statement of Financial Position and Profit & Loss are generated from the trial balance.',
                 'Print to PDF, export to CSV. Account-level detail one click away.',
                 'M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z'],
                ['See who is doing what',
                 'Live productivity for every staff member. Working papers prepared. Notes cleared. Average turnaround time. Who is overloaded.',
                 'No more guessing if Sarah has bandwidth. Look at the KPI page.',
                 'M3 21h2l1-4h4l1 4h2M14 21h2l1-7h4l1 7h2M5 13l3-8 3 6'],
                ['Pay only for the AI you use',
                 'Each firm has a credit wallet. Every AI call shows you the cost in MYR. Top up when needed.',
                 'No flat fees. No per-seat surprises. If your team has a quiet month, the AI bill is small.',
                 'M3 8l4-4h10l4 4M3 8v10a2 2 0 002 2h14a2 2 0 002-2V8M3 8h18M16 13a2 2 0 100 4 2 2 0 000-4z'],
                ['Nothing falls through the cracks',
                 'Every action is logged — status changes, uploads, AI calls, sign-ins. Engagement-scoped timeline.',
                 'When the partner asks "what happened on this job last week?", you have a clear answer in 10 seconds.',
                 'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M12 11h4m-4 4h4m-6-4h.01M10 15h.01'],
            ];
            foreach ($features as [$title, $blurb, $hover, $icon]):
            ?>
                <div class="reveal group relative bg-slate-50 border border-slate-200 rounded-lg p-6 overflow-hidden hover:shadow-lg hover:border-brand-300 transition cursor-default">
                    <div class="inline-flex items-center justify-center w-10 h-10 rounded bg-brand-100 text-brand-700 mb-4 group-hover:bg-brand-600 group-hover:text-white transition-colors">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8"
                                  d="<?= e($icon) ?>"/>
                        </svg>
                    </div>
                    <h3 class="font-semibold text-slate-900 mb-2"><?= e($title) ?></h3>
                    <p class="text-sm text-slate-600 leading-relaxed group-hover:opacity-0 transition-opacity duration-200">
                        <?= e($blurb) ?>
                    </p>
                    <p class="absolute inset-x-6 bottom-6 text-sm text-slate-700 leading-relaxed opacity-0 group-hover:opacity-100 transition-opacity duration-200 pointer-events-none">
                        <?= e($hover) ?>
                    </p>
                </div>
            <?php endforeach; ?>
        </div>
        <p class="text-center text-xs text-slate-500 mt-6">Hover any card to see how it changes a normal audit day.</p>
    </div>
</section>

<!-- ============================================================ -->
<!-- FAQ ACCORDION                                                 -->
<!-- ============================================================ -->
<section id="faq" class="bg-slate-50 py-20">
    <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="text-center mb-10 reveal">
            <span class="text-xs font-semibold text-brand-600 uppercase tracking-wider">Common questions</span>
            <h2 class="text-3xl sm:text-4xl font-bold text-slate-900 mt-2">Things you're probably wondering</h2>
        </div>

        <div class="space-y-3">
            <?php
            $faqs = [
                ['Do I have to install anything?',
                 'No. You and your team open it in a browser. Your clients too. Nothing to install. Nothing to update.'],
                ['What if my client uses SQL Account / AutoCount / UBS / Bukku / Million / Financio?',
                 'We accept the Excel or CSV exports from all of them out of the box. First import you pick which column is the account code, debit, credit. Second import — we remember. No special integration needed.'],
                ['Is the AI accurate enough to trust?',
                 'AI output is a draft, not a replacement for judgement. Every AI suggestion sits in a queue waiting for a human auditor to accept, reject or edit. The partner always sees what the AI said. Nothing leaves the firm without a human reviewing it.'],
                ['Where is my data stored?',
                 'On your hosting account. Not ours. Your client files are kept outside the public web, served only through authenticated download links. Each firm and each client has a strict private space — nobody sees anyone else\'s data.'],
                ['Can clients see other clients\' data?',
                 'No. Every page is firm-scoped. A client portal user only sees the engagement for their own company. We enforce this on every database query.'],
                ['What does it cost?',
                 'You pay only for the AI calls you actually make — billed in MYR. There is no per-seat fee and no flat subscription until you turn on AI. Your firm has a credit wallet you top up; every AI call shows you the cost up front.'],
                ['Can I try it before committing?',
                 'Ask your platform admin to seed a demo firm with three sample clients, sample audits, sample working papers and a sample trial balance. You can click around with realistic data before adding your own.'],
                ['Does it work in Bahasa / Chinese?',
                 'The platform is in English today. Account names, descriptions, AI drafts and document titles can all be in Bahasa Malaysia or Chinese — the database is full-Unicode (utf8mb4).'],
            ];
            foreach ($faqs as [$q, $a]):
            ?>
                <details class="reveal bg-white border border-slate-200 rounded-lg p-5 hover:border-brand-200 transition">
                    <summary class="flex items-center justify-between gap-3">
                        <span class="font-semibold text-slate-900"><?= e($q) ?></span>
                        <span class="faq-icon inline-flex items-center justify-center w-7 h-7 rounded-full bg-brand-100 text-brand-700 shrink-0">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 4v16m8-8H4"/>
                            </svg>
                        </span>
                    </summary>
                    <p class="text-sm text-slate-600 leading-relaxed mt-3 pr-10"><?= e($a) ?></p>
                </details>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- ============================================================ -->
<!-- SECURITY                                                      -->
<!-- ============================================================ -->
<section id="security" class="bg-white py-20">
    <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="text-center mb-12 reveal">
            <span class="text-xs font-semibold text-brand-600 uppercase tracking-wider">Built right</span>
            <h2 class="text-3xl sm:text-4xl font-bold text-slate-900 mt-2">Quietly serious about your data</h2>
            <p class="text-slate-600 mt-3 max-w-2xl mx-auto">No buzzwords. Just the boring engineering that keeps client files safe.</p>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
            <?php
            $sec = [
                ['Private by default',
                 'Each firm has its own private workspace. Every page checks who you are and what your firm is, on every click.'],
                ['Strong passwords',
                 'We hash passwords with bcrypt. Wrong password too many times and the account locks itself for 15 minutes.'],
                ['Files outside the web',
                 'Client uploads are stored where the public web can\'t reach. We serve them only through authenticated download links.'],
                ['Every action logged',
                 'A full activity trail tells you who did what, when. Super admins can impersonate to help a firm — and that fact is tagged in the log.'],
                ['AI costs always visible',
                 'Every Claude call records the prompt, the output, the tokens and the MYR cost. Nothing hidden.'],
                ['Boring tech stack',
                 'Native PHP 8 and MySQL. No Composer. No framework lock-in. Deploys to Hostinger shared hosting in minutes.'],
            ];
            foreach ($sec as [$t, $b]):
            ?>
                <div class="reveal flex items-start gap-4 bg-slate-50 border border-slate-200 rounded-lg p-5">
                    <div class="inline-flex items-center justify-center w-9 h-9 rounded-full bg-emerald-100 text-emerald-700 shrink-0">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                  d="M5 13l4 4L19 7"/>
                        </svg>
                    </div>
                    <div>
                        <h3 class="font-semibold text-slate-900"><?= e($t) ?></h3>
                        <p class="text-sm text-slate-600 mt-1"><?= e($b) ?></p>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- ============================================================ -->
<!-- CTA                                                           -->
<!-- ============================================================ -->
<section class="bg-gradient-to-br from-brand-700 to-brand-500 text-white py-16">
    <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 text-center reveal">
        <h2 class="text-3xl sm:text-4xl font-bold mb-3">Stop juggling. Start auditing.</h2>
        <p class="text-lg text-brand-100/90 mb-8 max-w-2xl mx-auto">
            Sign in if you already have credentials. New firm? Talk to your platform admin to get a wallet and a first user.
        </p>
        <div class="flex flex-wrap items-center justify-center gap-3">
            <a href="/auth/login.php"
               class="rounded-md bg-white text-brand-700 hover:bg-brand-50 font-semibold px-6 py-3 text-base transition">
                Sign in
            </a>
            <a href="#demo"
               class="rounded-md border border-white/30 text-white hover:bg-white/10 font-medium px-6 py-3 text-base transition">
                Look around again
            </a>
        </div>
    </div>
</section>

<script>
(function () {
    // 1. Stat counter — animate when the strip scrolls into view.
    var counters = document.querySelectorAll('[data-counter]');
    var animate = function (el) {
        var target = parseInt(el.getAttribute('data-counter'), 10) || 0;
        var dur = 1200;
        var start = performance.now();
        var tick = function (t) {
            var p = Math.min(1, (t - start) / dur);
            var eased = 1 - Math.pow(1 - p, 3);
            el.textContent = Math.round(target * eased).toString();
            if (p < 1) requestAnimationFrame(tick);
        };
        requestAnimationFrame(tick);
    };
    if ('IntersectionObserver' in window) {
        var statObs = new IntersectionObserver(function (entries) {
            entries.forEach(function (en) {
                if (en.isIntersecting) {
                    animate(en.target);
                    statObs.unobserve(en.target);
                }
            });
        }, { threshold: 0.4 });
        counters.forEach(function (c) { statObs.observe(c); });
    } else {
        counters.forEach(function (c) { c.textContent = c.getAttribute('data-counter'); });
    }

    // 2. Reveal-on-scroll for elements with .reveal
    var revealEls = document.querySelectorAll('.reveal');
    if ('IntersectionObserver' in window) {
        var revealObs = new IntersectionObserver(function (entries) {
            entries.forEach(function (en) {
                if (en.isIntersecting) {
                    en.target.classList.add('is-visible');
                    revealObs.unobserve(en.target);
                }
            });
        }, { threshold: 0.15, rootMargin: '0px 0px -10% 0px' });
        revealEls.forEach(function (el) { revealObs.observe(el); });
    } else {
        revealEls.forEach(function (el) { el.classList.add('is-visible'); });
    }

    // 3. Role tab switcher
    var container = document.querySelector('[data-role-tabs]');
    if (container) {
        var tabs  = container.querySelectorAll('[data-role-tab]');
        var panes = container.querySelectorAll('[data-role-pane]');
        tabs.forEach(function (btn) {
            btn.addEventListener('click', function () {
                var key = btn.getAttribute('data-role-tab');
                tabs.forEach(function (b) {
                    var active = b === btn;
                    b.setAttribute('aria-selected', active ? 'true' : 'false');
                    b.classList.toggle('bg-brand-600', active);
                    b.classList.toggle('text-white', active);
                    b.classList.toggle('bg-slate-100', !active);
                    b.classList.toggle('text-slate-700', !active);
                });
                panes.forEach(function (p) {
                    var match = p.getAttribute('data-role-pane') === key;
                    p.hidden = !match;
                });
            });
        });
    }
})();
</script>

<?php require __DIR__ . '/includes/public_footer.php'; ?>
