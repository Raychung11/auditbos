<?php
/**
 * /includes/workflow.php
 *
 * Engagement approval chain + archive locking.
 *
 *   Preparation → Senior review → Manager review → Partner review →
 *   Signed off → Locked (read-only)
 *
 * The review_stage column on engagements tracks position in the chain.
 * locked_at / locked_by record the archive lock. engagement_signoffs is
 * an append-only audit log of every transition.
 *
 * Lock enforcement: write paths call assert_engagement_open() at the top
 * of their POST handlers. A locked engagement rejects all mutations.
 */

declare(strict_types=1);

if (!defined('AUDITBOS_BOOTSTRAPPED')) {
    http_response_code(403); exit('Forbidden');
}

/**
 * Ordered stages with labels.
 *
 * @return array<string, array{label:string, order:int}>
 */
function workflow_stages(): array
{
    return [
        'preparation'    => ['label' => 'Preparation',    'order' => 0],
        'senior_review'  => ['label' => 'Senior review',  'order' => 1],
        'manager_review' => ['label' => 'Manager review', 'order' => 2],
        'partner_review' => ['label' => 'Partner review', 'order' => 3],
        'signed_off'     => ['label' => 'Signed off',      'order' => 4],
    ];
}

/**
 * Which role may approve / move the file forward FROM a given stage.
 * (firm_admin can always act — partner authority.)
 *
 * @return array<int, string>
 */
function workflow_approvers(string $fromStage): array
{
    switch ($fromStage) {
        case 'preparation':                                  // submit for review
            return ['firm_admin','audit_manager','senior_auditor','junior_auditor','reviewer'];
        case 'senior_review':
            return ['firm_admin','audit_manager','senior_auditor','reviewer'];
        case 'manager_review':
            return ['firm_admin','audit_manager'];
        case 'partner_review':                               // partner sign-off
            return ['firm_admin'];
        default:
            return [];
    }
}

/**
 * The next stage forward from $stage, or null if already signed off.
 */
function workflow_next_stage(string $stage): ?string
{
    $order = ['preparation','senior_review','manager_review','partner_review','signed_off'];
    $i = array_search($stage, $order, true);
    if ($i === false || $i >= count($order) - 1) {
        return null;
    }
    return $order[$i + 1];
}

/**
 * Verb for the forward action at a stage ("Submit for review", "Approve",
 * "Sign off").
 */
function workflow_forward_label(string $stage): string
{
    return match ($stage) {
        'preparation'    => 'Submit for review',
        'senior_review'  => 'Senior approve',
        'manager_review' => 'Manager approve',
        'partner_review' => 'Partner sign-off',
        default          => 'Advance',
    };
}

/**
 * Is the engagement locked (read-only archive)?
 */
function engagement_locked(int $engagementId): bool
{
    static $cache = [];
    if (array_key_exists($engagementId, $cache)) {
        return $cache[$engagementId];
    }
    $s = db()->prepare('SELECT locked_at FROM engagements WHERE id = :id');
    $s->execute([':id' => $engagementId]);
    $row = $s->fetch();
    return $cache[$engagementId] = ($row && $row['locked_at'] !== null);
}

/**
 * Guard for write paths: if the engagement is locked, flash + redirect to
 * the workspace and stop. Call at the top of every mutating POST handler.
 */
function assert_engagement_open(int $engagementId): void
{
    if ($engagementId > 0 && engagement_locked($engagementId)) {
        flash('error', 'This engagement is locked (signed off & archived). Unlock it to make changes.');
        redirect('/firm/engagement_view.php?id=' . $engagementId);
    }
}

/**
 * Record a workflow transition in engagement_signoffs.
 */
function workflow_log(int $engagementId, string $action, ?string $from, ?string $to, ?string $notes): void
{
    db()->prepare(
        'INSERT INTO engagement_signoffs (engagement_id, from_stage, to_stage, action, user_id, notes)
         VALUES (:e, :f, :t, :a, :u, :n)'
    )->execute([
        ':e'=>$engagementId, ':f'=>$from, ':t'=>$to, ':a'=>$action,
        ':u'=>current_user_id(), ':n'=>$notes,
    ]);
    log_activity('engagement.' . $action, 'engagement', $engagementId,
        trim(($from ?? '') . ' → ' . ($to ?? '')));
}
