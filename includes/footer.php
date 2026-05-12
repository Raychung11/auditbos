<?php
/**
 * /includes/footer.php
 *
 * Closes the <main> opened in header.php and emits the page footer.
 */

declare(strict_types=1);

if (!defined('AUDITBOS_BOOTSTRAPPED')) {
    http_response_code(403);
    exit('Forbidden');
}
?>
        </main>

        <footer class="bg-white border-t border-slate-200 px-6 py-3 text-xs text-slate-500 flex justify-between">
            <span>&copy; <?= date('Y') ?> <?= e(APP_NAME) ?> · v<?= e(APP_VERSION) ?></span>
            <span>Powered by AI Audit BOS</span>
        </footer>
    </div>
</div>

<script>
(function () {
    var btn = document.getElementById('sidebar-toggle');
    var sb  = document.getElementById('app-sidebar');
    if (btn && sb) {
        btn.addEventListener('click', function () {
            sb.classList.toggle('hidden');
            sb.classList.toggle('md:flex');
        });
    }
})();
</script>
</body>
</html>
