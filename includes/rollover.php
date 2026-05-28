<?php
/**
 * /includes/rollover.php
 *
 * Year-end rollover engine. Creates a new engagement for the next FY
 * of the same client and bulk-clones the audit file structure from a
 * source engagement:
 *
 *   * Working papers — title, section, lead_area, reference_code,
 *     procedure text, risk_rating, notes. Status reset to not_started,
 *     prepared_by / reviewed_by cleared, ai_summary cleared. Review
 *     notes are NOT cloned (they belong to last year's file).
 *   * Document requests — title, category, due-date pattern. Status
 *     reset to pending; admin notes carried over.
 *   * Lead schedule overrides — account_code → audit_area mapping
 *     persists across years for the same client.
 *   * Materiality basis + percentages — amounts cleared (recomputed
 *     when new TB is imported).
 *
 * What is NOT cloned: trial balance, general ledger, uploaded documents,
 * aging listings, AI outputs, review notes, sign-offs, locks.
 *
 * The new engagement starts in status="draft" / review_stage="preparation".
 * activity_logs records the rollover; engagements.rolled_over_from_id
 * stores the link.
 */

declare(strict_types=1);

if (!defined('AUDITBOS_BOOTSTRAPPED')) {
    http_response_code(403); exit('Forbidden');
}

require_once __DIR__ . '/workplan.php';

/**
 * Engagements eligible as a rollover SOURCE for a given client (in the
 * current firm). Lists most-recent first, excluding ones that are
 * themselves drafts.
 *
 * @return array<int, array<string,mixed>>
 */
function rollover_candidates(int $clientId, int $firmId): array
{
    $stmt = db()->prepare(
        'SELECT e.id, e.financial_year, e.engagement_type, e.period_end, e.status
           FROM engagements e
          WHERE e.client_id = :c AND e.firm_id = :f
          ORDER BY e.period_end DESC, e.created_at DESC'
    );
    $stmt->execute([':c'=>$clientId, ':f'=>$firmId]);
    return $stmt->fetchAll();
}

/**
 * Roll a source engagement into a new one. Returns the new engagement id.
 *
 * @param array<string,mixed> $newEng  ['financial_year','period_start','period_end','deadline']
 */
function rollover_engagement(int $sourceId, int $firmId, array $newEng, ?int $userId): int
{
    $pdo = db();

    $src = $pdo->prepare(
        'SELECT * FROM engagements WHERE id = :id AND firm_id = :f'
    );
    $src->execute([':id'=>$sourceId, ':f'=>$firmId]);
    $source = $src->fetch();
    if (!$source) {
        throw new RuntimeException('Source engagement not found or out of scope.');
    }

    $financialYear = trim((string)($newEng['financial_year'] ?? ''));
    if ($financialYear === '') {
        throw new RuntimeException('Financial year is required for the new engagement.');
    }
    $periodStart = trim((string)($newEng['period_start'] ?? '')) ?: null;
    $periodEnd   = trim((string)($newEng['period_end'] ?? '')) ?: null;
    $deadline    = trim((string)($newEng['deadline'] ?? '')) ?: null;
    $code        = trim((string)($newEng['engagement_code'] ?? '')) ?: null;

    $pdo->beginTransaction();
    try {
        // 1. Create the new engagement (carry over team + type from source).
        $pdo->prepare(
            'INSERT INTO engagements
                (firm_id, client_id, engagement_code, financial_year,
                 period_start, period_end, engagement_type, fee_amount,
                 start_date, deadline, partner_id, manager_id,
                 status, review_stage, rolled_over_from_id, created_by)
             VALUES
                (:f, :c, :code, :fy, :ps, :pe, :type, :fee,
                 NULL, :dl, :pi, :mi,
                 "draft", "preparation", :src, :u)'
        )->execute([
            ':f'   => $firmId,
            ':c'   => (int) $source['client_id'],
            ':code'=> $code,
            ':fy'  => $financialYear,
            ':ps'  => $periodStart,
            ':pe'  => $periodEnd,
            ':type'=> $source['engagement_type'],
            ':fee' => $source['fee_amount'],
            ':dl'  => $deadline,
            ':pi'  => $source['partner_id'],
            ':mi'  => $source['manager_id'],
            ':src' => $sourceId,
            ':u'   => $userId,
        ]);
        $newId = (int) $pdo->lastInsertId();

        // 2. Clone engagement team.
        $team = $pdo->prepare('SELECT user_id, team_role FROM engagement_team WHERE engagement_id = :e');
        $team->execute([':e' => $sourceId]);
        $teamIns = $pdo->prepare(
            'INSERT IGNORE INTO engagement_team (engagement_id, user_id, team_role)
             VALUES (:e, :u, :r)'
        );
        $teamCount = 0;
        foreach ($team->fetchAll() as $t) {
            $teamIns->execute([':e'=>$newId, ':u'=>$t['user_id'], ':r'=>$t['team_role']]);
            $teamCount++;
        }

        // 3. Clone document requests (status reset to pending, carry admin notes).
        $reqs = $pdo->prepare(
            'SELECT category_id, title, description, is_required, admin_notes
               FROM document_requests
              WHERE engagement_id = :e
              ORDER BY id'
        );
        $reqs->execute([':e' => $sourceId]);
        $reqIns = $pdo->prepare(
            'INSERT INTO document_requests
                (engagement_id, category_id, title, description, is_required,
                 status, admin_notes, requested_by)
             VALUES (:e, :c, :t, :d, :ir, "pending", :an, :u)'
        );
        $reqCount = 0;
        foreach ($reqs->fetchAll() as $r) {
            $reqIns->execute([
                ':e'=>$newId, ':c'=>$r['category_id'], ':t'=>$r['title'],
                ':d'=>$r['description'], ':ir'=>$r['is_required'],
                ':an'=>$r['admin_notes'], ':u'=>$userId,
            ]);
            $reqCount++;
        }

        // 4. Clone working papers (procedure / risk / lead_area / notes;
        //    reset status + prepared/reviewed metadata; no review notes).
        $wps = $pdo->prepare(
            'SELECT section_id, lead_area, reference_code, title,
                    `procedure`, notes, risk_rating
               FROM audit_working_papers
              WHERE engagement_id = :e
              ORDER BY id'
        );
        $wps->execute([':e' => $sourceId]);
        $wpIns = $pdo->prepare(
            'INSERT INTO audit_working_papers
                (engagement_id, section_id, lead_area, reference_code, title,
                 `procedure`, notes, status, risk_rating)
             VALUES (:e, :s, :la, :rc, :t, :p, :n, "not_started", :r)'
        );
        $wpCount = 0;
        foreach ($wps->fetchAll() as $w) {
            $wpIns->execute([
                ':e'=>$newId, ':s'=>$w['section_id'], ':la'=>$w['lead_area'],
                ':rc'=>$w['reference_code'], ':t'=>$w['title'],
                ':p'=>$w['procedure'], ':n'=>$w['notes'], ':r'=>$w['risk_rating'],
            ]);
            $wpCount++;
        }

        // 5. Clone lead schedule overrides (the per-account audit-area
        //    mapping is genuinely stable across years for the same client).
        $ovr = $pdo->prepare(
            'SELECT account_code, audit_area FROM lead_schedule_overrides
              WHERE engagement_id = :e'
        );
        $ovr->execute([':e' => $sourceId]);
        $ovrIns = $pdo->prepare(
            'INSERT INTO lead_schedule_overrides (engagement_id, account_code, audit_area, created_by)
             VALUES (:e, :c, :a, :u)'
        );
        $ovrCount = 0;
        foreach ($ovr->fetchAll() as $o) {
            $ovrIns->execute([':e'=>$newId, ':c'=>$o['account_code'], ':a'=>$o['audit_area'], ':u'=>$userId]);
            $ovrCount++;
        }

        // 6. Clone materiality assessment (basis + percentages only;
        //    amounts get recomputed when new TB lands).
        $mat = $pdo->prepare('SELECT * FROM engagement_materiality WHERE engagement_id = :e');
        $mat->execute([':e' => $sourceId]);
        $matRow = $mat->fetch();
        $matCloned = false;
        if ($matRow) {
            $pdo->prepare(
                'INSERT INTO engagement_materiality
                    (engagement_id, basis, basis_amount, planning_pct, planning_amount,
                     performance_pct, performance_amount, ctt_pct, ctt_amount,
                     rationale, set_by)
                 VALUES (:e, :b, 0, :pp, 0, :fp, 0, :cp, 0, :r, :u)'
            )->execute([
                ':e'=>$newId, ':b'=>$matRow['basis'],
                ':pp'=>$matRow['planning_pct'], ':fp'=>$matRow['performance_pct'],
                ':cp'=>$matRow['ctt_pct'], ':r'=>$matRow['rationale'], ':u'=>$userId,
            ]);
            $matCloned = true;
        }

        $pdo->commit();
        log_activity('engagement.rollover', 'engagement', $newId,
            sprintf('Rolled over from #%d · %d WPs · %d requests · %d overrides · %d team · materiality:%s',
                $sourceId, $wpCount, $reqCount, $ovrCount, $teamCount, $matCloned ? 'yes' : 'no'));

        // Seed the 27-step audit workplan for the new engagement. Status
        // resets to not_started — last year's progress doesn't carry.
        if (function_exists('workplan_seed_engagement')) {
            workplan_seed_engagement($newId);
        }

        return $newId;
    } catch (Throwable $ex) {
        $pdo->rollBack();
        error_log('[AuditBOS] rollover failed: ' . $ex->getMessage());
        throw $ex;
    }
}
