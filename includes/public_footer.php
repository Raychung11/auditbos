<?php
/**
 * /includes/public_footer.php
 *
 * Marketing-page footer. Distinct from /includes/footer.php which
 * closes the app shell's <main> wrapper.
 */

declare(strict_types=1);

if (!defined('AUDITBOS_BOOTSTRAPPED')) {
    http_response_code(403); exit('Forbidden');
}
?>
<footer class="bg-slate-900 text-slate-300">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-12">
        <div class="grid grid-cols-1 md:grid-cols-4 gap-8 mb-10">
            <div class="md:col-span-2">
                <div class="flex items-center gap-2 mb-3">
                    <span class="inline-flex w-9 h-9 rounded bg-brand-500 items-center justify-center font-bold text-white">A</span>
                    <span class="text-white font-semibold text-lg"><?= e(APP_NAME) ?></span>
                </div>
                <p class="text-sm text-slate-400 max-w-md">
                    AI-powered Business Operating System for audit firms.
                    Workflow automation, client documents, accounting data import,
                    AI assistant, working papers and partner review — in one platform.
                </p>
            </div>
            <div>
                <h4 class="text-white font-semibold text-sm mb-3">Platform</h4>
                <ul class="space-y-2 text-sm">
                    <li><a href="#features" class="hover:text-white">Features</a></li>
                    <li><a href="#how-it-works" class="hover:text-white">How it works</a></li>
                    <li><a href="#roles" class="hover:text-white">Who it's for</a></li>
                    <li><a href="#security" class="hover:text-white">Security</a></li>
                </ul>
            </div>
            <div>
                <h4 class="text-white font-semibold text-sm mb-3">Access</h4>
                <ul class="space-y-2 text-sm">
                    <li><a href="/auth/login.php" class="hover:text-white">Sign in</a></li>
                    <li><a href="/auth/forgot_password.php" class="hover:text-white">Forgot password</a></li>
                </ul>
            </div>
        </div>
        <div class="pt-8 border-t border-slate-700 flex flex-col md:flex-row md:items-center md:justify-between gap-4 text-xs text-slate-500">
            <div>&copy; <?= date('Y') ?> <?= e(APP_NAME) ?>. All rights reserved.</div>
            <div>v<?= e(APP_VERSION) ?> · Built for audit firms in Malaysia &amp; Southeast Asia.</div>
        </div>
    </div>
</footer>
</body>
</html>
