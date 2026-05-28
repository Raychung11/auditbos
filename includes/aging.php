<?php
/**
 * /includes/aging.php
 *
 * Helpers for the debtor / creditor aging module: column-mapping field
 * specs (so the importer can auto-detect bucket columns) plus summary
 * and exposure analysis over imported aging_items.
 */

declare(strict_types=1);

if (!defined('AUDITBOS_BOOTSTRAPPED')) {
    http_response_code(403); exit('Forbidden');
}

/**
 * Mappable fields for an aging listing. Most accounting systems export
 * pre-bucketed columns; only party + total are required.
 *
 * @return array<int, array{key:string,label:string,required:bool,hints:array<int,string>}>
 */
function aging_field_specs(): array
{
    return [
        ['key'=>'party_name',    'label'=>'Party / customer name', 'required'=>true,  'hints'=>['name','customer','supplier','debtor','creditor','party','account name','company']],
        ['key'=>'party_code',    'label'=>'Party code',            'required'=>false, 'hints'=>['code','account code','acc code','account no','id']],
        ['key'=>'total',         'label'=>'Total / balance',       'required'=>false, 'hints'=>['total','balance','outstanding','amount','grand total']],
        ['key'=>'current_amt',   'label'=>'Current / not due',     'required'=>false, 'hints'=>['current','not due','not yet due','0-30','0 - 30']],
        ['key'=>'days_1_30',     'label'=>'1–30 days',             'required'=>false, 'hints'=>['1-30','1 - 30','30 days','30','1-30 days']],
        ['key'=>'days_31_60',    'label'=>'31–60 days',            'required'=>false, 'hints'=>['31-60','31 - 60','60 days','60','31-60 days']],
        ['key'=>'days_61_90',    'label'=>'61–90 days',            'required'=>false, 'hints'=>['61-90','61 - 90','90 days','90','61-90 days']],
        ['key'=>'days_91_120',   'label'=>'91–120 days',           'required'=>false, 'hints'=>['91-120','91 - 120','120 days','120','91-120 days']],
        ['key'=>'days_over_120', 'label'=>'Over 120 days',         'required'=>false, 'hints'=>['over 120','120+','>120','more than 120','older','over120']],
    ];
}

/**
 * Auto-map aging columns from headers using the hint lists.
 *
 * @param array<int,string> $headers
 * @return array<string,int>
 */
function aging_auto_map(array $headers): array
{
    $lower = array_map(static fn($h) => strtolower(trim((string)$h)), $headers);
    $map = [];
    foreach (aging_field_specs() as $spec) {
        $best = null; $bestScore = 0;
        foreach ($lower as $idx => $h) {
            if ($h === '') continue;
            if (in_array($h, $spec['hints'], true)) { $best = $idx; break; }
            foreach ($spec['hints'] as $hint) {
                if (strpos($h, $hint) !== false && strlen($hint) > $bestScore) {
                    $bestScore = strlen($hint); $best = $idx;
                }
            }
        }
        if ($best !== null) $map[$spec['key']] = $best;
    }
    return $map;
}

/**
 * Aging summary for one type (debtor/creditor): bucket totals, count,
 * % overdue (anything beyond "current").
 *
 * @return array{
 *   count:int, total:float,
 *   buckets: array{current:float, d1_30:float, d31_60:float, d61_90:float, d91_120:float, over_120:float},
 *   overdue:float, overdue_pct:?float, over_90:float, over_90_pct:?float,
 *   has_data:bool
 * }
 */
function aging_summary(int $engagementId, string $type): array
{
    $stmt = db()->prepare(
        'SELECT COUNT(*) AS n,
                COALESCE(SUM(total),0)         AS total,
                COALESCE(SUM(current_amt),0)   AS c,
                COALESCE(SUM(days_1_30),0)     AS d1,
                COALESCE(SUM(days_31_60),0)    AS d2,
                COALESCE(SUM(days_61_90),0)    AS d3,
                COALESCE(SUM(days_91_120),0)   AS d4,
                COALESCE(SUM(days_over_120),0) AS d5
           FROM aging_items
          WHERE engagement_id = :e AND aging_type = :t'
    );
    $stmt->execute([':e'=>$engagementId, ':t'=>$type]);
    $r = $stmt->fetch() ?: [];

    $count = (int) ($r['n'] ?? 0);
    $total = (float) ($r['total'] ?? 0);
    $b = [
        'current'  => (float) ($r['c']  ?? 0),
        'd1_30'    => (float) ($r['d1'] ?? 0),
        'd31_60'   => (float) ($r['d2'] ?? 0),
        'd61_90'   => (float) ($r['d3'] ?? 0),
        'd91_120'  => (float) ($r['d4'] ?? 0),
        'over_120' => (float) ($r['d5'] ?? 0),
    ];
    // If total wasn't imported, derive it from the buckets.
    $bucketSum = array_sum($b);
    if ($total == 0.0 && $bucketSum != 0.0) {
        $total = $bucketSum;
    }
    $overdue = $b['d1_30'] + $b['d31_60'] + $b['d61_90'] + $b['d91_120'] + $b['over_120'];
    $over90  = $b['d91_120'] + $b['over_120'];

    return [
        'count'        => $count,
        'total'        => $total,
        'buckets'      => $b,
        'overdue'      => $overdue,
        'overdue_pct'  => $total != 0.0 ? ($overdue / $total) * 100 : null,
        'over_90'      => $over90,
        'over_90_pct'  => $total != 0.0 ? ($over90 / $total) * 100 : null,
        'has_data'     => $count > 0,
    ];
}

/**
 * Top exposures: largest total balances, with the over-90 portion.
 *
 * @return array<int, array<string,mixed>>
 */
function aging_top(int $engagementId, string $type, int $limit = 25): array
{
    $stmt = db()->prepare(
        'SELECT party_name, party_code, total, current_amt,
                days_1_30, days_31_60, days_61_90, days_91_120, days_over_120,
                (days_91_120 + days_over_120) AS over_90
           FROM aging_items
          WHERE engagement_id = :e AND aging_type = :t
          ORDER BY total DESC
          LIMIT ' . (int) $limit
    );
    $stmt->execute([':e'=>$engagementId, ':t'=>$type]);
    return $stmt->fetchAll();
}

/**
 * Parties with the most-overdue balances (over 90 days), for ECL focus.
 *
 * @return array<int, array<string,mixed>>
 */
function aging_most_overdue(int $engagementId, string $type, int $limit = 15): array
{
    $stmt = db()->prepare(
        'SELECT party_name, total, (days_91_120 + days_over_120) AS over_90, days_over_120
           FROM aging_items
          WHERE engagement_id = :e AND aging_type = :t
            AND (days_91_120 + days_over_120) > 0
          ORDER BY over_90 DESC
          LIMIT ' . (int) $limit
    );
    $stmt->execute([':e'=>$engagementId, ':t'=>$type]);
    return $stmt->fetchAll();
}
