<?php
/**
 * /includes/reminders.php
 *
 * Reminder engine — finds clients with outstanding documents and staff
 * with overdue work, builds reminder text (email + WhatsApp), sends via
 * mail() when configured, and logs everything to the `reminders` table.
 *
 * Shared by the firm Reminders page (manual "send now") and the cron
 * endpoint (/cron/reminders.php) for scheduled daily nudges.
 */

declare(strict_types=1);

if (!defined('AUDITBOS_BOOTSTRAPPED')) {
    http_response_code(403); exit('Forbidden');
}

/**
 * Engagements in a firm with outstanding (pending / needs_clarification)
 * document requests, plus client contact details and last-reminder time.
 *
 * @return array<int, array<string, mixed>>
 */
function reminders_client_outstanding(int $firmId): array
{
    $stmt = db()->prepare(
        'SELECT e.id AS engagement_id, e.financial_year, e.status,
                c.id AS client_id, c.company_name, c.contact_person,
                c.email AS client_email, c.phone AS client_phone,
                COUNT(dr.id) AS outstanding,
                MAX(dr.last_reminder_at) AS last_reminder_at
           FROM engagements e
           JOIN clients c ON c.id = e.client_id
           JOIN document_requests dr ON dr.engagement_id = e.id
          WHERE e.firm_id = :f
            AND e.status NOT IN ("completed","billed","archived")
            AND dr.status IN ("pending","needs_clarification")
          GROUP BY e.id
          ORDER BY last_reminder_at IS NOT NULL, last_reminder_at ASC, outstanding DESC'
    );
    $stmt->execute([':f' => $firmId]);
    return $stmt->fetchAll();
}

/**
 * The outstanding document titles for one engagement.
 *
 * @return array<int, string>
 */
function reminders_outstanding_titles(int $engagementId): array
{
    $stmt = db()->prepare(
        'SELECT dr.title
           FROM document_requests dr
          WHERE dr.engagement_id = :e AND dr.status IN ("pending","needs_clarification")
          ORDER BY dr.id'
    );
    $stmt->execute([':e' => $engagementId]);
    return array_column($stmt->fetchAll(), 'title');
}

/**
 * Staff who have overdue working papers or pending reviews in a firm.
 *
 * @return array<int, array<string, mixed>>
 */
function reminders_staff_nudges(int $firmId): array
{
    // Per-user: count of WPs they prepared still pending review, and
    // count of engagements they lead that are overdue.
    $stmt = db()->prepare(
        'SELECT u.id AS user_id, u.name, u.email, u.role,
                (SELECT COUNT(*) FROM audit_working_papers wp
                   JOIN engagements e ON e.id = wp.engagement_id
                  WHERE e.firm_id = :f1 AND wp.prepared_by = u.id
                    AND wp.status IN ("review_note_raised")) AS notes_to_action,
                (SELECT COUNT(*) FROM audit_working_papers wp2
                   JOIN engagements e2 ON e2.id = wp2.engagement_id
                  WHERE e2.firm_id = :f2 AND wp2.prepared_by = u.id
                    AND wp2.status = "not_started") AS wp_not_started,
                (SELECT COUNT(*) FROM engagements e3
                  WHERE e3.firm_id = :f3 AND (e3.manager_id = u.id OR e3.partner_id = u.id)
                    AND e3.deadline IS NOT NULL AND e3.deadline < CURDATE()
                    AND e3.status NOT IN ("completed","billed","archived")) AS overdue_jobs
           FROM users u
          WHERE u.firm_id = :f4 AND u.status = "active" AND u.role <> "client_user"
         HAVING notes_to_action > 0 OR wp_not_started > 0 OR overdue_jobs > 0
          ORDER BY overdue_jobs DESC, notes_to_action DESC'
    );
    $stmt->execute([':f1'=>$firmId, ':f2'=>$firmId, ':f3'=>$firmId, ':f4'=>$firmId]);
    return $stmt->fetchAll();
}

/**
 * Build reminder text for a client's outstanding documents.
 *
 * @param array<int, string> $titles
 * @return array{subject:string, email:string, whatsapp:string}
 */
function build_client_reminder_text(array $engagement, array $titles): array
{
    $firm    = APP_NAME;
    $company = $engagement['company_name'];
    $fy      = $engagement['financial_year'];
    $contact = $engagement['contact_person'] ?: 'team';
    $portal  = absolute_url('/documents/index.php');

    $bullets = '';
    foreach ($titles as $t) {
        $bullets .= "  • {$t}\n";
    }
    $inlineList = implode(', ', array_slice($titles, 0, 8))
        . (count($titles) > 8 ? ', and more' : '');

    $subject = "{$firm} — Outstanding documents for {$company} ({$fy})";

    $email = "Dear {$contact},\n\n"
        . "We're progressing the {$fy} audit for {$company} and still need the following "
        . "document(s) to continue:\n\n"
        . $bullets . "\n"
        . "Please upload them to your secure portal whenever convenient:\n"
        . "  {$portal}\n\n"
        . "If you've already sent any of these, apologies for the duplicate request — just let us know.\n\n"
        . "Thank you,\n"
        . "{$firm}";

    $whatsapp = "Hi {$contact}, for the {$company} {$fy} audit we still need: {$inlineList}. "
        . "Please upload via the portal: {$portal}. Thank you!";

    return ['subject' => $subject, 'email' => $email, 'whatsapp' => $whatsapp];
}

/**
 * Reduce a phone string to digits for a wa.me deep link.
 */
function whatsapp_number(string $phone): string
{
    return preg_replace('/\D+/', '', $phone) ?? '';
}

/**
 * Send (or record) a client document reminder for one engagement.
 * Sends email when MAIL_ENABLED and the client has an email; always
 * logs to `reminders` and stamps document_requests.last_reminder_at.
 *
 * @return array{ok:bool, channel:string, message:string, whatsapp_url:?string}
 */
function send_client_reminder(array $engagement, int $firmId, string $triggeredBy = 'user'): array
{
    $engagementId = (int) $engagement['engagement_id'];
    $titles = reminders_outstanding_titles($engagementId);
    if (empty($titles)) {
        return ['ok' => false, 'channel' => 'none',
                'message' => 'No outstanding documents.', 'whatsapp_url' => null];
    }

    $text = build_client_reminder_text($engagement, $titles);
    $email = trim((string)($engagement['client_email'] ?? ''));
    $phone = trim((string)($engagement['client_phone'] ?? ''));

    $channel = 'manual';
    $status  = 'manual';
    if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) && MAIL_ENABLED) {
        $res = send_email($email, $text['subject'], $text['email']);
        $channel = 'email';
        $status  = $res['sent'] ? 'sent' : 'failed';
    }

    // Log + stamp.
    db()->prepare(
        'INSERT INTO reminders
            (firm_id, engagement_id, client_id, reminder_type, channel,
             recipient, subject, body, status, triggered_by, created_by)
         VALUES (:f, :e, :c, "client_documents", :ch, :rcp, :subj, :body, :st, :tb, :u)'
    )->execute([
        ':f'=>$firmId, ':e'=>$engagementId, ':c'=>(int)$engagement['client_id'],
        ':ch'=>$channel, ':rcp'=>($email ?: $phone ?: null),
        ':subj'=>$text['subject'], ':body'=>$text['email'],
        ':st'=>$status, ':tb'=>$triggeredBy, ':u'=>current_user_id(),
    ]);

    db()->prepare(
        'UPDATE document_requests SET last_reminder_at = NOW()
          WHERE engagement_id = :e AND status IN ("pending","needs_clarification")'
    )->execute([':e' => $engagementId]);

    log_activity('reminder.client', 'engagement', $engagementId,
        $channel === 'email' ? "Emailed {$email}" : 'Reminder prepared');

    $waUrl = null;
    if ($phone !== '') {
        $num = whatsapp_number($phone);
        if ($num !== '') {
            $waUrl = 'https://wa.me/' . $num . '?text=' . rawurlencode($text['whatsapp']);
        }
    }

    $msg = match ($channel) {
        'email' => $status === 'sent'
            ? "Reminder emailed to {$email}."
            : "Email send failed — use the WhatsApp link or copy the text.",
        default => "Mail isn't configured — use the WhatsApp link or copy the text below.",
    };

    return ['ok' => true, 'channel' => $channel, 'message' => $msg,
            'whatsapp_url' => $waUrl, 'text' => $text];
}
