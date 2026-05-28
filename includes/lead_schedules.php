<?php
/**
 * /includes/lead_schedules.php
 *
 * Lead schedules: group the imported trial balance into audit areas
 * (Cash, Receivables, Payables, Inventory, PPE, Revenue, Payroll, ...)
 * so each area can be tied to a working paper and worked top-down.
 *
 * Classification reuses fs_classify_account() (in fs_builder.php) and
 * maps its financial-statement sections onto audit areas. Auditors can
 * override the area for any account via lead_schedule_overrides.
 */

declare(strict_types=1);

if (!defined('AUDITBOS_BOOTSTRAPPED')) {
    http_response_code(403); exit('Forbidden');
}

require_once __DIR__ . '/fs_builder.php';

/**
 * Ordered audit areas. Each: [label, matched audit_sections.code or null].
 * The section code is used to pre-fill the working paper when an auditor
 * creates one for the lead.
 *
 * @return array<string, array{label:string, section:?string}>
 */
function lead_areas(): array
{
    return [
        'cash'        => ['label' => 'Cash and bank',                 'section' => 'B-100'],
        'receivables' => ['label' => 'Trade and other receivables',  'section' => 'B-200'],
        'inventory'   => ['label' => 'Inventories',                  'section' => 'B-400'],
        'ppe'         => ['label' => 'Property, plant & equipment',  'section' => 'B-500'],
        'payables'    => ['label' => 'Trade and other payables',     'section' => 'B-300'],
        'borrowings'  => ['label' => 'Borrowings',                   'section' => null],
        'tax'         => ['label' => 'Taxation',                     'section' => 'B-700'],
        'equity'      => ['label' => 'Equity',                       'section' => null],
        'revenue'     => ['label' => 'Revenue',                      'section' => 'B-200'],
        'cost_sales'  => ['label' => 'Cost of sales',                'section' => 'B-300'],
        'payroll'     => ['label' => 'Payroll / staff costs',        'section' => 'B-600'],
        'opex'        => ['label' => 'Operating expenses',           'section' => null],
        'finance'     => ['label' => 'Finance costs',                'section' => null],
        'other'       => ['label' => 'Other / unclassified',         'section' => null],
    ];
}

/**
 * Map an FS section key (from fs_classify_account) to an audit area.
 */
function lead_area_from_fs_section(string $fsSection): string
{
    static $map = [
        'ppe'                => 'ppe',
        'intangibles'        => 'ppe',
        'deferred_tax_asset' => 'tax',
        'tax_payable'        => 'tax',
        'deferred_tax_liab'  => 'tax',
        'tax_expense'        => 'tax',
        'receivables'        => 'receivables',
        'inventories'        => 'inventory',
        'cash'               => 'cash',
        'share_capital'      => 'equity',
        'reserves'           => 'equity',
        'retained_earnings'  => 'equity',
        'borrowings_nc'      => 'borrowings',
        'borrowings_curr'    => 'borrowings',
        'payables'           => 'payables',
        'accruals'           => 'payables',
        'other_liabilities'  => 'payables',
        'revenue'            => 'revenue',
        'other_income'       => 'revenue',
        'cost_of_sales'      => 'cost_sales',
        'opex_staff'         => 'payroll',
        'opex_depreciation'  => 'opex',
        'opex_other'         => 'opex',
        'finance_costs'      => 'finance',
        'other_assets'       => 'other',
        'unmapped'           => 'other',
    ];
    return $map[$fsSection] ?? 'other';
}

/**
 * Heuristic audit area for an account, before overrides are applied.
 */
function lead_classify_account(string $code, ?string $type, string $name): string
{
    $c = fs_classify_account($code, $type, $name);
    return lead_area_from_fs_section($c['section']);
}

/**
 * Build lead schedules for an engagement.
 *
 * @return array{
 *   leads: array<int, array{
 *     area:string, label:string, section:?string,
 *     cur:float, pri:float, diff:float, pct:?float,
 *     account_count:int,
 *     accounts: array<int, array{code:string,name:string,cur:float,pri:float,overridden:bool}>,
 *     wp: array{id:int,reference_code:?string,status:string,risk:?string}|null
 *   }>,
 *   total_cur:float, total_pri:float,
 *   has_tb:bool
 * }
 */
function build_lead_schedules(int $engagementId): array
{
    $pdo = db();
    $tb  = fs_load_tb($engagementId);

    // Overrides: account_code => audit_area
    $ovr = [];
    $os = $pdo->prepare(
        'SELECT account_code, audit_area FROM lead_schedule_overrides WHERE engagement_id = :e'
    );
    $os->execute([':e' => $engagementId]);
    foreach ($os->fetchAll() as $row) {
        $ovr[$row['account_code']] = $row['audit_area'];
    }

    // Working papers already tagged to a lead area, keyed by area.
    $wpByArea = [];
    $ws = $pdo->prepare(
        'SELECT id, lead_area, reference_code, status, risk_rating
           FROM audit_working_papers
          WHERE engagement_id = :e AND lead_area IS NOT NULL'
    );
    $ws->execute([':e' => $engagementId]);
    foreach ($ws->fetchAll() as $w) {
        // First WP per area wins for the summary link.
        if (!isset($wpByArea[$w['lead_area']])) {
            $wpByArea[$w['lead_area']] = [
                'id'             => (int) $w['id'],
                'reference_code' => $w['reference_code'],
                'status'         => $w['status'],
                'risk'           => $w['risk_rating'],
            ];
        }
    }

    $areas = lead_areas();
    $buckets = [];
    foreach ($areas as $key => $_) {
        $buckets[$key] = ['cur' => 0.0, 'pri' => 0.0, 'accounts' => []];
    }

    foreach ($tb as $row) {
        $code = (string) $row['account_code'];
        $area = $ovr[$code] ?? lead_classify_account($code, $row['account_type'], $row['account_name']);
        if (!isset($buckets[$area])) {
            $area = 'other';
        }
        $cur = (float) $row['cur_balance'];
        $pri = (float) $row['pri_balance'];
        $buckets[$area]['cur'] += $cur;
        $buckets[$area]['pri'] += $pri;
        $buckets[$area]['accounts'][] = [
            'code'       => $code,
            'name'       => (string) $row['account_name'],
            'cur'        => $cur,
            'pri'        => $pri,
            'overridden' => isset($ovr[$code]),
        ];
    }

    $leads = [];
    $totalCur = $totalPri = 0.0;
    foreach ($areas as $key => $meta) {
        $b = $buckets[$key];
        if (empty($b['accounts'])) {
            continue;
        }
        $diff = $b['cur'] - $b['pri'];
        $pct  = $b['pri'] != 0.0 ? ($diff / abs($b['pri'])) * 100 : null;
        $leads[] = [
            'area'          => $key,
            'label'         => $meta['label'],
            'section'       => $meta['section'],
            'cur'           => $b['cur'],
            'pri'           => $b['pri'],
            'diff'          => $diff,
            'pct'           => $pct,
            'account_count' => count($b['accounts']),
            'accounts'      => $b['accounts'],
            'wp'            => $wpByArea[$key] ?? null,
        ];
        $totalCur += $b['cur'];
        $totalPri += $b['pri'];
    }

    return [
        'leads'     => $leads,
        'total_cur' => $totalCur,
        'total_pri' => $totalPri,
        'has_tb'    => !empty($tb),
    ];
}
