<?php
/**
 * /includes/tax_computation.php
 *
 * Malaysian corporate tax computation: accounting profit → add-backs +
 * deductions + capital allowances → chargeable income → tax expense.
 * Default rate 24% (Malaysia standard); SME rates (17% on first RM150K)
 * are not auto-applied — preparers tweak the rate per engagement.
 */

declare(strict_types=1);

if (!defined('AUDITBOS_BOOTSTRAPPED')) {
    http_response_code(403); exit('Forbidden');
}

/**
 * Adjustment categories with display labels and the sign each applies
 * to chargeable income (add_back +, deduction/capital_allowance −, other 0).
 *
 * @return array<string, array{label:string, sign:int}>
 */
function tax_adjustment_categories(): array
{
    return [
        'add_back'          => ['label' => 'Add-backs (non-deductible)',      'sign' => 1],
        'deduction'         => ['label' => 'Deductions / exempt income',     'sign' => -1],
        'capital_allowance' => ['label' => 'Capital allowances',              'sign' => -1],
        'other'             => ['label' => 'Other adjustments',               'sign' => 0],
    ];
}

/**
 * Common Malaysian add-back templates (suggested when seeding a new
 * tax computation). The amounts are 0 — the preparer fills them in.
 *
 * @return array<int, array{category:string, line_item:string, mfrs_reference:string}>
 */
function tax_default_lines(): array
{
    return [
        ['category'=>'add_back',   'line_item'=>'Depreciation of PPE',          'mfrs_reference'=>'ITA s.39'],
        ['category'=>'add_back',   'line_item'=>'Amortisation of intangibles', 'mfrs_reference'=>'ITA s.39'],
        ['category'=>'add_back',   'line_item'=>'Donations (non-approved)',    'mfrs_reference'=>'ITA s.34(6)(h)'],
        ['category'=>'add_back',   'line_item'=>'Entertainment (50% portion)', 'mfrs_reference'=>'ITA s.39(1)(l)'],
        ['category'=>'add_back',   'line_item'=>'Penalties / fines',           'mfrs_reference'=>'ITA s.39'],
        ['category'=>'add_back',   'line_item'=>'Provision for doubtful debts', 'mfrs_reference'=>'ITA s.34(2)'],
        ['category'=>'deduction',  'line_item'=>'Approved donations',          'mfrs_reference'=>'ITA s.44(6)'],
        ['category'=>'deduction',  'line_item'=>'Reinvestment allowance',      'mfrs_reference'=>'ITA Sch 7A'],
        ['category'=>'capital_allowance','line_item'=>'Capital allowance — plant & machinery', 'mfrs_reference'=>'ITA Sch 3'],
        ['category'=>'capital_allowance','line_item'=>'Industrial building allowance',          'mfrs_reference'=>'ITA Sch 3'],
    ];
}

/**
 * Load the tax computation row + all adjustments, plus pre-totalled
 * sub-buckets. Returns null if no computation yet.
 *
 * @return array{comp:array<string,mixed>, adjustments:array<int,array<string,mixed>>,
 *               totals:array<string,float>, chargeable_income:float,
 *               tax_expense:float, effective_rate:?float}|null
 */
function tax_load(int $engagementId): ?array
{
    if (!table_exists('tax_computations')) {
        return null;
    }
    $pdo = db();
    $s = $pdo->prepare('SELECT * FROM tax_computations WHERE engagement_id = :e');
    $s->execute([':e' => $engagementId]);
    $comp = $s->fetch();
    if (!$comp) {
        return null;
    }
    $a = $pdo->prepare(
        'SELECT * FROM tax_adjustments
          WHERE engagement_id = :e
          ORDER BY category, sort_order, id'
    );
    $a->execute([':e' => $engagementId]);
    $adj = $a->fetchAll();

    $totals = ['add_back'=>0.0, 'deduction'=>0.0, 'capital_allowance'=>0.0, 'other'=>0.0];
    foreach ($adj as $row) {
        $cat = $row['category'];
        if (!isset($totals[$cat])) { $totals[$cat] = 0.0; }
        $totals[$cat] += (float) $row['amount'];
    }

    $ap = (float) $comp['accounting_profit'];
    $chargeable = $ap
        + $totals['add_back']
        - $totals['deduction']
        - $totals['capital_allowance'];
    $rate     = (float) $comp['tax_rate_pct'];
    $tax      = max(0.0, $chargeable * $rate / 100.0);
    $effRate  = $ap != 0.0 ? ($tax / $ap) * 100.0 : null;

    return [
        'comp'              => $comp,
        'adjustments'       => $adj,
        'totals'            => $totals,
        'chargeable_income' => $chargeable,
        'tax_expense'       => $tax,
        'effective_rate'    => $effRate,
    ];
}

/**
 * Insert the default Malaysian template lines for a fresh computation.
 * Idempotent: only inserts if no adjustments exist yet.
 */
function tax_seed_default_lines(int $engagementId): int
{
    if (!table_exists('tax_adjustments')) {
        return 0;
    }
    $pdo = db();
    $check = $pdo->prepare('SELECT COUNT(*) FROM tax_adjustments WHERE engagement_id = :e');
    $check->execute([':e' => $engagementId]);
    if ((int) $check->fetchColumn() > 0) {
        return 0;
    }
    $ins = $pdo->prepare(
        'INSERT INTO tax_adjustments (engagement_id, sort_order, category, line_item, mfrs_reference)
         VALUES (:e, :so, :c, :li, :ref)'
    );
    $n = 0;
    foreach (tax_default_lines() as $i => $row) {
        $ins->execute([
            ':e'   => $engagementId,
            ':so'  => $i * 10,
            ':c'   => $row['category'],
            ':li'  => $row['line_item'],
            ':ref' => $row['mfrs_reference'],
        ]);
        $n++;
    }
    return $n;
}
