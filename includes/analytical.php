<?php
/**
 * /includes/analytical.php
 *
 * Analytical review helpers: derive the headline financial metrics from
 * the trial balance (via the FS builder), compute going-concern ratios
 * (current/prior), and support the materiality calculation.
 */

declare(strict_types=1);

if (!defined('AUDITBOS_BOOTSTRAPPED')) {
    http_response_code(403); exit('Forbidden');
}

require_once __DIR__ . '/fs_builder.php';

/**
 * Extract the headline figures needed for ratios + materiality from the
 * SOFP / SOCI builders. All values are positive in their natural
 * presentation (assets +, liabilities +, equity +, revenue +).
 *
 * @return array{has_tb:bool, cur:array<string,float>, pri:array<string,float>}
 */
function analytical_metrics(int $engagementId): array
{
    $sofp = fs_build_sofp($engagementId);
    $soci = fs_build_soci($engagementId);

    $hasTb = !empty($sofp['sections']) || !empty($soci['lines']);

    // SOFP section subtotals by heading.
    $section = static function (array $sofp, string $heading, string $field): float {
        foreach ($sofp['sections'] as $s) {
            if ($s['heading'] === $heading) return (float) $s[$field];
        }
        return 0.0;
    };
    // A group inside any section, matched by label substring.
    $group = static function (array $sofp, string $needle, string $field): float {
        foreach ($sofp['sections'] as $s) {
            foreach ($s['groups'] as $g) {
                if (stripos($g['label'], $needle) !== false) return (float) $g[$field];
            }
        }
        return 0.0;
    };
    // A SOCI line by label.
    $line = static function (array $soci, string $label, string $field): float {
        foreach ($soci['lines'] as $l) {
            if ($l['label'] === $label) return (float) $l[$field];
        }
        return 0.0;
    };

    $build = static function (string $f) use ($sofp, $soci, $section, $group, $line): array {
        $curAssets = $section($sofp, 'Current assets', 'subtotal_' . ($f === 'cur' ? 'cur' : 'pri'));
        $field = $f === 'cur' ? 'cur' : 'pri';
        $subField = 'subtotal_' . $field;

        $currentAssets   = $section($sofp, 'Current assets', $subField);
        $currentLiab     = $section($sofp, 'Current liabilities', $subField);
        $ncLiab          = $section($sofp, 'Non-current liabilities', $subField);
        $equity          = $section($sofp, 'Equity', $subField);
        $inventory       = $group($sofp, 'Inventories', $field);
        $borrowingsCurr  = $group($sofp, 'Borrowings — current', $field);
        $borrowingsNc    = $group($sofp, 'Borrowings — non-current', $field);

        $totalAssets     = (float) $sofp['total_assets_' . $field];
        $totalEquityLiab = (float) $sofp['total_equity_liab_' . $field];
        $totalLiab       = $totalEquityLiab - $equity;

        return [
            'total_assets'        => $totalAssets,
            'total_liabilities'   => $totalLiab,
            'current_assets'      => $currentAssets,
            'current_liabilities' => $currentLiab,
            'non_current_liab'    => $ncLiab,
            'inventory'           => $inventory,
            'equity'              => $equity,
            'borrowings'          => $borrowingsCurr + $borrowingsNc,
            'revenue'             => $line($soci, 'Revenue', $field),
            'operating_profit'    => $line($soci, 'Operating profit', $field),
            'finance_costs'       => $line($soci, 'Finance costs', $field),
            'pbt'                 => $line($soci, 'Profit before tax', $field),
            'profit'              => (float) $soci['profit_' . $field],
            'net_assets'          => $totalAssets - $totalLiab,
        ];
    };

    return ['has_tb' => $hasTb, 'cur' => $build('cur'), 'pri' => $build('pri')];
}

/**
 * Compute going-concern / financial-health ratios for current + prior.
 *
 * @return array<int, array{
 *   key:string, label:string, cur:?float, pri:?float, unit:string,
 *   concern:bool, hint:string
 * }>
 */
function analytical_ratios(int $engagementId): array
{
    $m = analytical_metrics($engagementId);
    if (!$m['has_tb']) return [];

    $c = $m['cur']; $p = $m['pri'];
    $safe = static fn(float $num, float $den): ?float => $den != 0.0 ? $num / $den : null;

    $rows = [];

    // Current ratio
    $rows[] = [
        'key' => 'current_ratio', 'label' => 'Current ratio', 'unit' => 'x',
        'cur' => $safe($c['current_assets'], $c['current_liabilities']),
        'pri' => $safe($p['current_assets'], $p['current_liabilities']),
        'concern_below' => 1.0,
        'hint' => 'Current assets ÷ current liabilities. Below 1.0 signals possible short-term liquidity pressure.',
    ];
    // Quick ratio
    $rows[] = [
        'key' => 'quick_ratio', 'label' => 'Quick ratio', 'unit' => 'x',
        'cur' => $safe($c['current_assets'] - $c['inventory'], $c['current_liabilities']),
        'pri' => $safe($p['current_assets'] - $p['inventory'], $p['current_liabilities']),
        'concern_below' => 1.0,
        'hint' => '(Current assets − inventory) ÷ current liabilities. Below 1.0 indicates reliance on selling stock to meet obligations.',
    ];
    // Gearing
    $rows[] = [
        'key' => 'gearing', 'label' => 'Gearing (debt / equity)', 'unit' => 'x',
        'cur' => $safe($c['borrowings'], $c['equity']),
        'pri' => $safe($p['borrowings'], $p['equity']),
        'concern_above' => 1.0,
        'hint' => 'Borrowings ÷ equity. High gearing increases refinancing and solvency risk.',
    ];
    // Interest cover
    $rows[] = [
        'key' => 'interest_cover', 'label' => 'Interest cover', 'unit' => 'x',
        'cur' => $safe($c['operating_profit'], $c['finance_costs']),
        'pri' => $safe($p['operating_profit'], $p['finance_costs']),
        'concern_below' => 1.5,
        'hint' => 'Operating profit ÷ finance costs. Below ~1.5 means earnings barely cover interest.',
    ];
    // Net margin
    $rows[] = [
        'key' => 'net_margin', 'label' => 'Net profit margin', 'unit' => '%',
        'cur' => $c['revenue'] != 0 ? ($c['profit'] / $c['revenue']) * 100 : null,
        'pri' => $p['revenue'] != 0 ? ($p['profit'] / $p['revenue']) * 100 : null,
        'concern_below' => 0.0,
        'hint' => 'Profit ÷ revenue. Negative means the company is loss-making.',
    ];
    // Return on assets
    $rows[] = [
        'key' => 'roa', 'label' => 'Return on assets', 'unit' => '%',
        'cur' => $c['total_assets'] != 0 ? ($c['profit'] / $c['total_assets']) * 100 : null,
        'pri' => $p['total_assets'] != 0 ? ($p['profit'] / $p['total_assets']) * 100 : null,
        'hint' => 'Profit ÷ total assets. How efficiently assets generate profit.',
    ];
    // Net asset position
    $rows[] = [
        'key' => 'net_assets', 'label' => 'Net assets (equity)', 'unit' => 'amount',
        'cur' => $c['net_assets'], 'pri' => $p['net_assets'],
        'concern_below' => 0.0,
        'hint' => 'Total assets − total liabilities. A net liability position is a strong going-concern indicator.',
    ];

    // Compute the concern flag per row.
    foreach ($rows as &$r) {
        $concern = false;
        $v = $r['cur'];
        if ($v !== null) {
            if (isset($r['concern_below']) && $v < $r['concern_below']) $concern = true;
            if (isset($r['concern_above']) && $v > $r['concern_above']) $concern = true;
        }
        $r['concern'] = $concern;
    }
    unset($r);

    return $rows;
}

/**
 * Basis options + the metric key they read + a default percentage.
 *
 * @return array<string, array{label:string, metric:string, default_pct:float}>
 */
function materiality_bases(): array
{
    return [
        'pbt'            => ['label' => 'Profit before tax',  'metric' => 'pbt',          'default_pct' => 5.0],
        'revenue'        => ['label' => 'Revenue',            'metric' => 'revenue',      'default_pct' => 1.0],
        'total_assets'   => ['label' => 'Total assets',       'metric' => 'total_assets', 'default_pct' => 1.0],
        'net_assets'     => ['label' => 'Net assets / equity', 'metric' => 'net_assets',  'default_pct' => 2.0],
        'total_expenses' => ['label' => 'Total expenses',     'metric' => 'total_expenses','default_pct' => 1.0],
    ];
}

/**
 * Current-year amount for a materiality basis (absolute value).
 */
function materiality_basis_amount(int $engagementId, string $basis): float
{
    $m = analytical_metrics($engagementId);
    $c = $m['cur'];
    $val = match ($basis) {
        'revenue'        => $c['revenue'],
        'total_assets'   => $c['total_assets'],
        'net_assets'     => $c['net_assets'],
        'pbt'            => $c['pbt'],
        // Total expenses = revenue + other income − profit (approx), use revenue−PBT magnitude.
        'total_expenses' => abs($c['revenue'] - $c['pbt']),
        default          => $c['pbt'],
    };
    return abs($val);
}

/**
 * Load the saved materiality row for an engagement, or null.
 */
function materiality_load(int $engagementId): ?array
{
    $stmt = db()->prepare('SELECT * FROM engagement_materiality WHERE engagement_id = :e');
    $stmt->execute([':e' => $engagementId]);
    $row = $stmt->fetch();
    return $row ?: null;
}
