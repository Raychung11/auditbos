<?php
/**
 * /cron/reminders.php
 *
 * Scheduled client-document reminders. Designed to be hit by Hostinger's
 * cron scheduler once a day, e.g.:
 *
 *   wget -q -O /dev/null "https://your-domain/cron/reminders.php?token=YOUR_CRON_TOKEN"
 *
 * or from the CLI:
 *
 *   CRON_TOKEN=... php cron/reminders.php
 *
 * For every active engagement with outstanding documents whose client
 * was last reminded more than REMINDER_COOLDOWN_DAYS ago (or never), it
 * sends an email reminder (only when MAIL_ENABLED and the client has an
 * email) and logs it. Locked / completed engagements are skipped.
 *
 * Auth: requires ?token=CRON_TOKEN (web) — CLI runs are always allowed.
 * Returns a small text summary.
 */

declare(strict_types=1);

define('AUDITBOS_BOOTSTRAPPED', true);
require_once __DIR__ . '/../config/app_config.php';
require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/../includes/helpers.php';

// No session in cron context — provide the accessor stubs that helpers
// and the reminder engine expect (normally defined in auth_guard.php).
if (!function_exists('current_user_id')) {
    function current_user_id(): ?int { return null; }
}
if (!function_exists('current_firm_id')) {
    function current_firm_id(): ?int { return null; }
}
if (!function_exists('current_user')) {
    function current_user(): ?array { return null; }
}

// ---------------------------------------------------------------------
// Auth gate.
// ---------------------------------------------------------------------
$isCli = PHP_SAPI === 'cli';
if (!$isCli) {
    header('Content-Type: text/plain; charset=utf-8');
    $token = (string)($_GET['token'] ?? '');
    if (CRON_TOKEN === '' || !hash_equals(CRON_TOKEN, $token)) {
        http_response_code(403);
        exit("Forbidden. Set CRON_TOKEN in db_config.local.php and pass ?token=...\n");
    }
}

require_once __DIR__ . '/../includes/reminders.php';

$pdo = db();
$cooldown = (int) REMINDER_COOLDOWN_DAYS;

// Engagements with outstanding docs not reminded within the cooldown window,
// across every firm, with the client contact details.
$stmt = $pdo->query(
    'SELECT e.id AS engagement_id, e.firm_id, e.financial_year,
            c.id AS client_id, c.company_name, c.contact_person,
            c.email AS client_email, c.phone AS client_phone,
            MAX(dr.last_reminder_at) AS last_reminder_at
       FROM engagements e
       JOIN clients c ON c.id = e.client_id
       JOIN document_requests dr ON dr.engagement_id = e.id
      WHERE e.status NOT IN ("completed","billed","archived")
        AND e.locked_at IS NULL
        AND dr.status IN ("pending","needs_clarification")
      GROUP BY e.id
      HAVING last_reminder_at IS NULL
          OR last_reminder_at < DATE_SUB(NOW(), INTERVAL ' . $cooldown . ' DAY)'
);
$engagements = $stmt->fetchAll();

$sent = 0; $skipped = 0; $failed = 0;
foreach ($engagements as $eng) {
    // Only auto-send when we can actually email; otherwise leave it for
    // the firm to action manually from the Reminders page.
    $email = trim((string)($eng['client_email'] ?? ''));
    if (!MAIL_ENABLED || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $skipped++;
        continue;
    }
    $res = send_client_reminder($eng, (int) $eng['firm_id'], 'cron');
    if ($res['ok'] && $res['channel'] === 'email') {
        $sent++;
    } else {
        $failed++;
    }
}

$summary = sprintf(
    "Reminder cron complete. Candidates: %d · emailed: %d · skipped (no email/mail off): %d · failed: %d\n",
    count($engagements), $sent, $skipped, $failed
);

if ($isCli) {
    fwrite(STDOUT, $summary);
} else {
    echo $summary;
}
