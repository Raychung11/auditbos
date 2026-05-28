<?php
/**
 * /includes/misstatements.php
 *
 * Schedule of Uncorrected Misstatements (SUM, ISA 450). Aggregates the
 * misstatements raised during fieldwork and compares the total impact
 * on PBT, assets and liabilities against performance materiality.
 */

declare(strict_types=1);

if (!defined('AUDITBOS_BOOTSTRAPPED')) {
    http_response_code(403); exit('Forbidden');
}

function mis_categories(): array
{
    return [
        'factual'      => 'Factual',
        'judgemental'  => 'Judgemental',
        'projected'    => 'Projected (sample-extrapolated)',
    ];
}

function mis_statuses(): array
{
    return [
        'uncorrected' => 'Uncorrected',
        'corrected'   => 'Corrected by client',
        'waived'      => 'Waived (immaterial)',
    ];
}

/**
 * Load all misstatements for an engagement, joined with the WP they
 * were raised from (if any).
 *
 * @return array<int, array<string,mixed>>
 */
function mis_load(int $engagementId): array
{
    if (!table_exists('misstatements')) {
        return [];
    }
    $s = db()->prepare(
        'SELECT m.*, wp.title AS wp_title, wp.reference_code AS wp_ref
           FROM misstatements m
           LEFT JOIN audit_working_papers wp ON wp.id = m.working_paper_id
          WHERE m.engagement_id = :e
          ORDER BY m.status, m.category, m.id'
    );
    $s->execute([':e' => $engagementId]);
    return $s->fetchAll();
}

/**
 * Aggregate uncorrected (and total) misstatement impact, plus the
 * materiality benchmarks for comparison.
 *
 * @return array{
 *   uncorrected:array{pbt:float, assets:float, liabilities:float, count:int},
 *   corrected:array{pbt:float, assets:float, liabilities:float, count:int},
 *   pm:?float, ctt:?float,
 *   breach_pm:bool, breach_ctt:bool
 * }
 */
function mis_summary(int $engagementId): array
{
    $rows = mis_load($engagementId);
    $sum = [
        'uncorrected' => ['pbt'=>0.0,'assets'=>0.0,'liabilities'=>0.0,'count'=>0],
        'corrected'   => ['pbt'=>0.0,'assets'=>0.0,'liabilities'=>0.0,'count'=>0],
    ];
    foreach ($rows as $r) {
        $k = $r['status'] === 'corrected' ? 'corrected' : 'uncorrected';
        if ($r['status'] === 'waived') { continue; }
        $sum[$k]['pbt']         += (float) $r['amount_pbt'];
        $sum[$k]['assets']      += (float) $r['amount_assets'];
        $sum[$k]['liabilities'] += (float) $r['amount_liabilities'];
        $sum[$k]['count']++;
    }

    // Pull materiality thresholds (performance + CTT) if set.
    $pm = $ctt = null;
    if (table_exists('engagement_materiality')) {
        $ms = db()->prepare(
            'SELECT performance_amount, ctt_amount
               FROM engagement_materiality WHERE engagement_id = :e'
        );
        $ms->execute([':e' => $engagementId]);
        if ($m = $ms->fetch()) {
            $pm  = (float) $m['performance_amount'] ?: null;
            $ctt = (float) $m['ctt_amount'] ?: null;
        }
    }

    $uncorrectedAbs = abs($sum['uncorrected']['pbt']);
    return $sum + [
        'pm'         => $pm,
        'ctt'        => $ctt,
        'breach_pm'  => $pm  !== null && $uncorrectedAbs > $pm,
        'breach_ctt' => $ctt !== null && $uncorrectedAbs > $ctt,
    ];
}
