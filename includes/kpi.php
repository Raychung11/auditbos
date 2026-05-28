<?php
/**
 * /includes/kpi.php
 *
 * Computes staff KPIs on-the-fly from live data (engagements, working
 * papers, review notes). No nightly aggregation job — the firm size
 * this platform serves makes synchronous computation cheap, and live
 * numbers are more useful to partners than yesterday's snapshot.
 *
 * If the platform grows past ~100 staff per firm, swap to a cron job
 * that materialises into the staff_kpi table; the public API here
 * (kpi_for_user / kpi_for_firm) stays the same.
 */

declare(strict_types=1);

if (!defined('AUDITBOS_BOOTSTRAPPED')) {
    http_response_code(403); exit('Forbidden');
}

/**
 * Compute KPIs for a single user within a firm.
 *
 * @return array{
 *   user_id:int,
 *   jobs_assigned:int,
 *   jobs_completed:int,
 *   wp_prepared:int,
 *   wp_pending_review:int,
 *   notes_received:int,
 *   notes_cleared:int,
 *   overdue_tasks:int,
 *   avg_completion_days:?float,
 *   productivity_score:?float,
 *   last_login_at:?string
 * }
 */
function kpi_for_user(int $userId, int $firmId): array
{
    $pdo = db();

    // Single-placeholder helpers so we don't trip native-prepare reuse.
    $one = function (string $sql, array $params = []) use ($pdo) {
        $s = $pdo->prepare($sql);
        $s->execute($params);
        $v = $s->fetchColumn();
        return $v === false ? null : $v;
    };

    $jobsAssigned = (int) $one(
        'SELECT COUNT(DISTINCT e.id)
           FROM engagements e
          WHERE e.firm_id = :f
            AND (e.partner_id = :u OR e.manager_id = :u2
                 OR EXISTS (SELECT 1 FROM engagement_team t
                             WHERE t.engagement_id = e.id AND t.user_id = :u3))
            AND e.status NOT IN ("archived")',
        [':f'=>$firmId, ':u'=>$userId, ':u2'=>$userId, ':u3'=>$userId]
    );

    $jobsCompleted = (int) $one(
        'SELECT COUNT(DISTINCT e.id)
           FROM engagements e
          WHERE e.firm_id = :f
            AND (e.partner_id = :u OR e.manager_id = :u2
                 OR EXISTS (SELECT 1 FROM engagement_team t
                             WHERE t.engagement_id = e.id AND t.user_id = :u3))
            AND e.status IN ("completed","billed")',
        [':f'=>$firmId, ':u'=>$userId, ':u2'=>$userId, ':u3'=>$userId]
    );

    $wpPrepared = (int) $one(
        'SELECT COUNT(*) FROM audit_working_papers wp
           JOIN engagements e ON e.id = wp.engagement_id
          WHERE e.firm_id = :f AND wp.prepared_by = :u
            AND wp.status NOT IN ("not_started")',
        [':f'=>$firmId, ':u'=>$userId]
    );

    $wpPendingReview = (int) $one(
        'SELECT COUNT(*) FROM audit_working_papers wp
           JOIN engagements e ON e.id = wp.engagement_id
          WHERE e.firm_id = :f AND wp.prepared_by = :u
            AND wp.status IN ("prepared","pending_review","review_note_raised")',
        [':f'=>$firmId, ':u'=>$userId]
    );

    $notesReceived = (int) $one(
        'SELECT COUNT(*) FROM audit_review_notes arn
           JOIN audit_working_papers wp ON wp.id = arn.working_paper_id
           JOIN engagements e ON e.id = wp.engagement_id
          WHERE e.firm_id = :f
            AND (arn.assigned_to = :u OR wp.prepared_by = :u2)',
        [':f'=>$firmId, ':u'=>$userId, ':u2'=>$userId]
    );

    $notesCleared = (int) $one(
        'SELECT COUNT(*) FROM audit_review_notes arn
           JOIN audit_working_papers wp ON wp.id = arn.working_paper_id
           JOIN engagements e ON e.id = wp.engagement_id
          WHERE e.firm_id = :f AND arn.cleared_by = :u',
        [':f'=>$firmId, ':u'=>$userId]
    );

    $overdueTasks = (int) $one(
        'SELECT COUNT(DISTINCT e.id)
           FROM engagements e
          WHERE e.firm_id = :f
            AND (e.partner_id = :u OR e.manager_id = :u2)
            AND e.deadline IS NOT NULL AND e.deadline < CURDATE()
            AND e.status NOT IN ("completed","billed","archived")',
        [':f'=>$firmId, ':u'=>$userId, ':u2'=>$userId]
    );

    $avgDays = $one(
        'SELECT AVG(DATEDIFF(reviewed_at, prepared_at))
           FROM audit_working_papers wp
           JOIN engagements e ON e.id = wp.engagement_id
          WHERE e.firm_id = :f AND wp.prepared_by = :u
            AND wp.prepared_at IS NOT NULL AND wp.reviewed_at IS NOT NULL',
        [':f'=>$firmId, ':u'=>$userId]
    );
    $avgCompletionDays = $avgDays !== null ? (float) $avgDays : null;

    // Productivity score: simple weighted heuristic so each row is comparable.
    // - WP prepared:    +2
    // - Notes cleared:  +3
    // - Jobs completed: +5
    // - Overdue jobs:   −5
    $productivity = ($wpPrepared * 2)
                  + ($notesCleared * 3)
                  + ($jobsCompleted * 5)
                  - ($overdueTasks * 5);

    $lastLogin = $one(
        'SELECT last_login_at FROM users WHERE id = :u', [':u'=>$userId]
    );

    return [
        'user_id'             => $userId,
        'jobs_assigned'       => $jobsAssigned,
        'jobs_completed'      => $jobsCompleted,
        'wp_prepared'         => $wpPrepared,
        'wp_pending_review'   => $wpPendingReview,
        'notes_received'      => $notesReceived,
        'notes_cleared'       => $notesCleared,
        'overdue_tasks'       => $overdueTasks,
        'avg_completion_days' => $avgCompletionDays,
        'productivity_score'  => (float) $productivity,
        'last_login_at'       => $lastLogin ? (string) $lastLogin : null,
    ];
}

/**
 * Compute KPIs for every firm-staff user in one firm.
 *
 * @return array<int, array<string, mixed>> One row per user, each row
 *     contains the user metadata + KPI fields. Sorted by productivity desc.
 */
function kpi_for_firm(int $firmId): array
{
    $stmt = db()->prepare(
        "SELECT id, name, email, role, department, status
           FROM users
          WHERE firm_id = :f AND role <> 'client_user'
          ORDER BY name"
    );
    $stmt->execute([':f' => $firmId]);
    $users = $stmt->fetchAll();

    $rows = [];
    foreach ($users as $u) {
        $rows[] = $u + kpi_for_user((int) $u['id'], $firmId);
    }
    usort($rows, static function ($a, $b) {
        return ($b['productivity_score'] ?? 0) <=> ($a['productivity_score'] ?? 0);
    });
    return $rows;
}

/**
 * High-risk working papers across the firm — for partner dashboard.
 * Returns up to $limit rows joined with engagement + section labels.
 *
 * @return array<int, array<string, mixed>>
 */
function kpi_high_risk_wps(int $firmId, int $limit = 10): array
{
    $stmt = db()->prepare(
        'SELECT wp.id, wp.reference_code, wp.title, wp.status, wp.risk_rating,
                e.id AS engagement_id, e.financial_year,
                c.company_name,
                asec.name AS section_name,
                (SELECT COUNT(*) FROM audit_review_notes arn
                  WHERE arn.working_paper_id = wp.id AND arn.status IN ("open","responded","reopened")) AS open_notes
           FROM audit_working_papers wp
           JOIN engagements e ON e.id = wp.engagement_id
           JOIN clients c     ON c.id = e.client_id
           LEFT JOIN audit_sections asec ON asec.id = wp.section_id
          WHERE e.firm_id = :f
            AND (wp.risk_rating IN ("high","critical")
                 OR wp.status = "review_note_raised")
            AND e.status NOT IN ("completed","billed","archived")
          ORDER BY FIELD(wp.risk_rating, "critical","high","medium","low",NULL),
                   open_notes DESC,
                   wp.updated_at DESC
          LIMIT ' . (int) $limit
    );
    $stmt->execute([':f' => $firmId]);
    return $stmt->fetchAll();
}

/**
 * Recent AI risk alerts: variance analyses and partner reviews still
 * in "draft" status. These are the things a partner ought to look at.
 *
 * @return array<int, array<string, mixed>>
 */
function kpi_ai_alerts(int $firmId, int $limit = 5): array
{
    $stmt = db()->prepare(
        'SELECT ao.id, ao.title, ao.output_type, ao.created_at,
                e.id AS engagement_id, c.company_name
           FROM ai_outputs ao
           LEFT JOIN engagements e ON e.id = ao.engagement_id
           LEFT JOIN clients c     ON c.id = e.client_id
          WHERE ao.firm_id = :f
            AND ao.status = "draft"
            AND ao.output_type IN ("variance_analysis","partner_review","wp_review","management_letter")
          ORDER BY ao.created_at DESC
          LIMIT ' . (int) $limit
    );
    $stmt->execute([':f' => $firmId]);
    return $stmt->fetchAll();
}
