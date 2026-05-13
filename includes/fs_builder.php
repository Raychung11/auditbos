<?php
/**
 * /includes/fs_builder.php
 *
 * Generates basic financial statements (SOFP + SOCI) directly from
 * the imported trial balance. Classifies accounts using a hybrid of
 * the chart_of_accounts.account_type enum and a Malaysian-CoA-style
 * account-code heuristic (1xxx assets, 2xxx liabilities, 3xxx equity,
 * 4xxx revenue, 5xxx cost of sales, 6xxx operating expenses, 7xxx
 * finance costs, 8xxx tax).
 *
 * Output structure is render-agnostic so the same data feeds the HTML
 * page, CSV export, and (later) a PDF generator.
 *
 * In v1 the classification is purely heuristic — no per-account
 * mapping UI yet. Firms with non-standard chart-of-accounts can either
 * rename accounts to conform, or wait for the mapping UI to land in
 * a follow-up.
 */

declare(strict_types=1);

if (!defined('AUDITBOS_BOOTSTRAPPED')) {
    http_response_code(403); exit('Forbidden');
}

/**
 * Map an account (code + type + name) to a financial-statement section.
 *
 * @return array{
 *   statement: 'sofp'|'soci'|'unmapped',
 *   section:   string,          // group key inside the statement
 *   label:     string,          // human-readable section heading
 *   sign:      int              // +1 = display balance as-is, -1 = flip
 * }
 */
function fs_classify_account(string $code, ?string $type, string $name): array
{
    $code = trim($code);
    $name = strtolower(trim($name));
    $type = strtolower((string) $type);
    $firstDigit = $code !== '' ? (int) substr($code, 0, 1) : 0;
    $codeInt    = is_numeric($code) ? (int) $code : 0;

    // -------------- ASSETS (debit-natural; sign = +1) --------------
    if ($firstDigit === 1 || $type === 'asset') {
        // Non-current asset bands
        if (($codeInt >= 1500 && $codeInt <= 1599)
            || str_contains($name, 'property')
            || str_contains($name, 'plant')
            || str_contains($name, 'equipment')) {
            return _fs_pack('sofp', 'ppe', 'Property, plant and equipment', +1);
        }
        if (($codeInt >= 1510 && $codeInt <= 1519)
            || str_contains($name, 'accumulated depreciation')) {
            return _fs_pack('sofp', 'ppe', 'Property, plant and equipment', -1);
        }
        if (($codeInt >= 1600 && $codeInt <= 1699)
            || str_contains($name, 'intangible')) {
            return _fs_pack('sofp', 'intangibles', 'Intangible assets', +1);
        }
        if (($codeInt >= 1700 && $codeInt <= 1799)
            || str_contains($name, 'deferred tax asset')) {
            return _fs_pack('sofp', 'deferred_tax_asset', 'Deferred tax assets', +1);
        }
        // Current assets
        if (str_contains($name, 'ecl') || str_contains($name, 'allowance')
            || str_contains($name, 'provision for doubtful')) {
            // ECL/allowance is a credit on an asset line — net it inside receivables.
            return _fs_pack('sofp', 'receivables', 'Trade and other receivables', -1);
        }
        if (($codeInt >= 1200 && $codeInt <= 1299)
            || str_contains($name, 'receivable')
            || str_contains($name, 'debtor')) {
            return _fs_pack('sofp', 'receivables', 'Trade and other receivables', +1);
        }
        if (($codeInt >= 1300 && $codeInt <= 1399)
            || str_contains($name, 'inventor')
            || str_contains($name, 'stock')) {
            return _fs_pack('sofp', 'inventories', 'Inventories', +1);
        }
        if (($codeInt >= 1000 && $codeInt <= 1199)
            || str_contains($name, 'cash')
            || str_contains($name, 'bank')) {
            return _fs_pack('sofp', 'cash', 'Cash and cash equivalents', +1);
        }
        return _fs_pack('sofp', 'other_assets', 'Other assets', +1);
    }

    // -------------- LIABILITIES (credit-natural; sign = -1) --------------
    if ($firstDigit === 2 || $type === 'liability') {
        if (($codeInt >= 2500 && $codeInt <= 2599)
            || str_contains($name, 'borrowing')
            || str_contains($name, 'loan')) {
            // Bank borrowings — classify long-term if name says "long term" else current.
            if (str_contains($name, 'long term') || str_contains($name, 'non-current')) {
                return _fs_pack('sofp', 'borrowings_nc', 'Borrowings — non-current', -1);
            }
            return _fs_pack('sofp', 'borrowings_curr', 'Borrowings — current', -1);
        }
        if (($codeInt >= 2300 && $codeInt <= 2399)
            || str_contains($name, 'tax payable')) {
            return _fs_pack('sofp', 'tax_payable', 'Current tax payable', -1);
        }
        if (($codeInt >= 2400 && $codeInt <= 2499)
            || str_contains($name, 'deferred tax liab')) {
            return _fs_pack('sofp', 'deferred_tax_liab', 'Deferred tax liabilities', -1);
        }
        if (($codeInt >= 2100 && $codeInt <= 2199)
            || str_contains($name, 'accrual')
            || str_contains($name, 'accrued')
            || str_contains($name, 'bonus accrual')) {
            return _fs_pack('sofp', 'accruals', 'Accruals and other payables', -1);
        }
        if (($codeInt >= 2000 && $codeInt <= 2099)
            || str_contains($name, 'payable')
            || str_contains($name, 'creditor')) {
            return _fs_pack('sofp', 'payables', 'Trade and other payables', -1);
        }
        return _fs_pack('sofp', 'other_liabilities', 'Other liabilities', -1);
    }

    // -------------- EQUITY (credit-natural; sign = -1) --------------
    if ($firstDigit === 3 || $type === 'equity') {
        if (($codeInt >= 3000 && $codeInt <= 3099)
            || str_contains($name, 'share capital')
            || str_contains($name, 'paid-up')) {
            return _fs_pack('sofp', 'share_capital', 'Share capital', -1);
        }
        if (($codeInt >= 3200 && $codeInt <= 3299)
            || str_contains($name, 'reserve')) {
            return _fs_pack('sofp', 'reserves', 'Reserves', -1);
        }
        return _fs_pack('sofp', 'retained_earnings', 'Retained earnings', -1);
    }

    // -------------- INCOME (credit-natural; sign = -1) --------------
    if ($firstDigit === 4 || $type === 'income') {
        if (str_contains($name, 'other income')
            || ($codeInt >= 4100 && $codeInt <= 4999)) {
            return _fs_pack('soci', 'other_income', 'Other income', -1);
        }
        return _fs_pack('soci', 'revenue', 'Revenue', -1);
    }

    // -------------- EXPENSES (debit-natural; sign = +1) --------------
    if ($firstDigit === 5
        || str_contains($name, 'cost of sales')
        || str_contains($name, 'cost of goods')) {
        return _fs_pack('soci', 'cost_of_sales', 'Cost of sales', +1);
    }
    if ($firstDigit === 6 || $type === 'expense') {
        if (str_contains($name, 'depreciation') || str_contains($name, 'amortis')) {
            return _fs_pack('soci', 'opex_depreciation', 'Depreciation and amortisation', +1);
        }
        if (str_contains($name, 'staff') || str_contains($name, 'salar')
            || str_contains($name, 'payroll')) {
            return _fs_pack('soci', 'opex_staff', 'Staff costs', +1);
        }
        return _fs_pack('soci', 'opex_other', 'Other operating expenses', +1);
    }
    if ($firstDigit === 7 || str_contains($name, 'finance cost')
        || str_contains($name, 'interest expense')) {
        return _fs_pack('soci', 'finance_costs', 'Finance costs', +1);
    }
    if ($firstDigit === 8 || str_contains($name, 'income tax')
        || str_contains($name, 'tax expense')) {
        return _fs_pack('soci', 'tax_expense', 'Income tax expense', +1);
    }

    return _fs_pack('unmapped', 'unmapped', 'Unclassified', +1);
}

function _fs_pack(string $statement, string $section, string $label, int $sign): array
{
    return compact('statement', 'section', 'label', 'sign');
}

/**
 * Load both periods of TB rows for an engagement.
 *
 * @return array<int, array{
 *   account_code:string, account_name:string, account_type:?string,
 *   cur_balance:float, pri_balance:float
 * }>
 */
function fs_load_tb(int $engagementId): array
{
    $pdo = db();
    $stmt = $pdo->prepare(
        'SELECT
            COALESCE(cur.account_code, pri.account_code) AS account_code,
            COALESCE(cur.account_name, pri.account_name) AS account_name,
            coa.account_type,
            COALESCE(cur.balance, 0) AS cur_balance,
            COALESCE(pri.balance, 0) AS pri_balance
           FROM (
                SELECT account_code, account_name, balance
                  FROM trial_balances
                 WHERE engagement_id = :e1 AND period = "current"
            ) cur
           LEFT JOIN (
                SELECT account_code, account_name, balance
                  FROM trial_balances
                 WHERE engagement_id = :e2 AND period = "prior"
            ) pri ON pri.account_code = cur.account_code
           LEFT JOIN chart_of_accounts coa
                  ON coa.engagement_id = :e3 AND coa.account_code = cur.account_code
         UNION
         SELECT
            pri.account_code, pri.account_name, coa.account_type,
            COALESCE(cur.balance, 0), COALESCE(pri.balance, 0)
           FROM (
                SELECT account_code, account_name, balance
                  FROM trial_balances
                 WHERE engagement_id = :e4 AND period = "prior"
            ) pri
           LEFT JOIN (
                SELECT account_code, account_name, balance
                  FROM trial_balances
                 WHERE engagement_id = :e5 AND period = "current"
            ) cur ON cur.account_code = pri.account_code
           LEFT JOIN chart_of_accounts coa
                  ON coa.engagement_id = :e6 AND coa.account_code = pri.account_code
          WHERE cur.account_code IS NULL
          ORDER BY account_code'
    );
    $stmt->execute([
        ':e1'=>$engagementId, ':e2'=>$engagementId, ':e3'=>$engagementId,
        ':e4'=>$engagementId, ':e5'=>$engagementId, ':e6'=>$engagementId,
    ]);
    return $stmt->fetchAll();
}

/**
 * Build the SOFP structure for an engagement.
 *
 * @return array{
 *   sections: array<int, array{
 *     heading:string,
 *     groups: array<int, array{
 *       label:string,
 *       cur:float, pri:float,
 *       accounts: array<int, array{code:string,name:string,cur:float,pri:float}>
 *     }>,
 *     subtotal_label:string, subtotal_cur:float, subtotal_pri:float
 *   }>,
 *   total_assets_cur:float,           total_assets_pri:float,
 *   total_equity_liab_cur:float,      total_equity_liab_pri:float,
 *   balance_diff_cur:float,           balance_diff_pri:float,
 *   unmapped: array<int, array{code:string,name:string,cur:float,pri:float}>
 * }
 */
function fs_build_sofp(int $engagementId): array
{
    $tb = fs_load_tb($engagementId);

    // Section definitions in display order.
    $sectionsConfig = [
        'non_current_assets' => [
            'heading' => 'Non-current assets',
            'groups'  => ['ppe', 'intangibles', 'deferred_tax_asset', 'other_assets'],
            'subtotal_label' => 'Total non-current assets',
        ],
        'current_assets' => [
            'heading' => 'Current assets',
            'groups'  => ['inventories', 'receivables', 'cash'],
            'subtotal_label' => 'Total current assets',
        ],
        'equity' => [
            'heading' => 'Equity',
            'groups'  => ['share_capital', 'reserves', 'retained_earnings'],
            'subtotal_label' => 'Total equity',
        ],
        'non_current_liab' => [
            'heading' => 'Non-current liabilities',
            'groups'  => ['borrowings_nc', 'deferred_tax_liab', 'other_liabilities'],
            'subtotal_label' => 'Total non-current liabilities',
        ],
        'current_liab' => [
            'heading' => 'Current liabilities',
            'groups'  => ['payables', 'accruals', 'tax_payable', 'borrowings_curr'],
            'subtotal_label' => 'Total current liabilities',
        ],
    ];

    // Which section does each group belong to?
    $groupToSection = [];
    foreach ($sectionsConfig as $skey => $cfg) {
        foreach ($cfg['groups'] as $g) { $groupToSection[$g] = $skey; }
    }

    $byGroup = [];                                   // groupKey -> ['label', cur, pri, accounts[]]
    $unmapped = [];
    $totalAssetsCur = $totalAssetsPri = 0.0;
    $totalEquityCur = $totalEquityPri = 0.0;
    $totalLiabCur   = $totalLiabPri   = 0.0;

    foreach ($tb as $row) {
        $c = fs_classify_account($row['account_code'], $row['account_type'], $row['account_name']);
        if ($c['statement'] !== 'sofp') {
            continue;
        }
        $cur = (float) $row['cur_balance'] * $c['sign'];
        $pri = (float) $row['pri_balance'] * $c['sign'];

        if ($c['section'] === 'unmapped') {
            $unmapped[] = ['code'=>$row['account_code'], 'name'=>$row['account_name'],
                           'cur'=>$cur, 'pri'=>$pri];
            continue;
        }
        if (!isset($byGroup[$c['section']])) {
            $byGroup[$c['section']] = [
                'label' => $c['label'], 'cur'=>0.0, 'pri'=>0.0, 'accounts'=>[]
            ];
        }
        $byGroup[$c['section']]['cur']      += $cur;
        $byGroup[$c['section']]['pri']      += $pri;
        $byGroup[$c['section']]['accounts'][] = [
            'code'=>$row['account_code'], 'name'=>$row['account_name'],
            'cur'=>$cur, 'pri'=>$pri,
        ];

        // Roll up to header totals.
        $sectionKey = $groupToSection[$c['section']] ?? null;
        if ($sectionKey === 'non_current_assets' || $sectionKey === 'current_assets') {
            $totalAssetsCur += $cur; $totalAssetsPri += $pri;
        } elseif ($sectionKey === 'equity') {
            $totalEquityCur += $cur; $totalEquityPri += $pri;
        } elseif ($sectionKey === 'non_current_liab' || $sectionKey === 'current_liab') {
            $totalLiabCur += $cur; $totalLiabPri += $pri;
        }
    }

    // Assemble sections in defined order.
    $sections = [];
    foreach ($sectionsConfig as $cfg) {
        $groups = [];
        $sumC = $sumP = 0.0;
        foreach ($cfg['groups'] as $g) {
            if (!isset($byGroup[$g])) continue;
            $groups[] = $byGroup[$g];
            $sumC += $byGroup[$g]['cur'];
            $sumP += $byGroup[$g]['pri'];
        }
        if (empty($groups)) continue;
        $sections[] = [
            'heading'        => $cfg['heading'],
            'groups'         => $groups,
            'subtotal_label' => $cfg['subtotal_label'],
            'subtotal_cur'   => $sumC,
            'subtotal_pri'   => $sumP,
        ];
    }

    return [
        'sections'             => $sections,
        'total_assets_cur'     => $totalAssetsCur,
        'total_assets_pri'     => $totalAssetsPri,
        'total_equity_liab_cur'=> $totalEquityCur + $totalLiabCur,
        'total_equity_liab_pri'=> $totalEquityPri + $totalLiabPri,
        'balance_diff_cur'     => $totalAssetsCur - ($totalEquityCur + $totalLiabCur),
        'balance_diff_pri'     => $totalAssetsPri - ($totalEquityPri + $totalLiabPri),
        'unmapped'             => $unmapped,
    ];
}

/**
 * Build the SOCI structure for an engagement.
 *
 * Returns a flat sequence of line items including computed subtotals
 * (gross profit, operating profit, profit before tax, profit for the year).
 *
 * @return array{
 *   lines: array<int, array{
 *     label:string,
 *     is_total:bool,
 *     cur:float, pri:float,
 *     accounts: array<int, array{code:string,name:string,cur:float,pri:float}>
 *   }>,
 *   profit_cur:float, profit_pri:float,
 *   unmapped: array<int, array{code:string,name:string,cur:float,pri:float}>
 * }
 */
function fs_build_soci(int $engagementId): array
{
    $tb = fs_load_tb($engagementId);

    $groupsConfig = [
        'revenue'           => 'Revenue',
        'cost_of_sales'     => 'Cost of sales',
        'other_income'      => 'Other income',
        'opex_staff'        => 'Staff costs',
        'opex_depreciation' => 'Depreciation and amortisation',
        'opex_other'        => 'Other operating expenses',
        'finance_costs'     => 'Finance costs',
        'tax_expense'       => 'Income tax expense',
    ];

    // For P&L display we want income lines as positive numbers and
    // expense lines as positive numbers (with negative impact captured
    // in the subtotal math). After fs_classify_account, revenue/other_income
    // are sign = -1 (we flipped the TB negative-balance into a positive).
    // Expenses are sign = +1.
    $byGroup = [];
    $unmapped = [];
    foreach ($tb as $row) {
        $c = fs_classify_account($row['account_code'], $row['account_type'], $row['account_name']);
        if ($c['statement'] !== 'soci') continue;
        $cur = (float) $row['cur_balance'] * $c['sign'];
        $pri = (float) $row['pri_balance'] * $c['sign'];
        if (!isset($groupsConfig[$c['section']])) {
            $unmapped[] = ['code'=>$row['account_code'], 'name'=>$row['account_name'],
                           'cur'=>$cur, 'pri'=>$pri];
            continue;
        }
        if (!isset($byGroup[$c['section']])) {
            $byGroup[$c['section']] = ['cur'=>0.0, 'pri'=>0.0, 'accounts'=>[]];
        }
        $byGroup[$c['section']]['cur']        += $cur;
        $byGroup[$c['section']]['pri']        += $pri;
        $byGroup[$c['section']]['accounts'][] = [
            'code'=>$row['account_code'], 'name'=>$row['account_name'],
            'cur'=>$cur, 'pri'=>$pri,
        ];
    }

    $g = static fn(string $k, string $f) => $byGroup[$k][$f] ?? 0.0;

    // Assemble in P&L display order with computed subtotals.
    $lines = [];
    foreach (['revenue','cost_of_sales'] as $k) {
        if (!isset($byGroup[$k])) continue;
        $sign = $k === 'cost_of_sales' ? -1 : 1;          // CoS reduces revenue
        $lines[] = [
            'label'    => $groupsConfig[$k],
            'is_total' => false,
            'cur'      => $sign * $byGroup[$k]['cur'],
            'pri'      => $sign * $byGroup[$k]['pri'],
            'accounts' => $byGroup[$k]['accounts'],
        ];
    }
    $grossCur = $g('revenue', 'cur') - $g('cost_of_sales', 'cur');
    $grossPri = $g('revenue', 'pri') - $g('cost_of_sales', 'pri');
    $lines[] = ['label'=>'Gross profit', 'is_total'=>true,
        'cur'=>$grossCur, 'pri'=>$grossPri, 'accounts'=>[]];

    foreach (['other_income','opex_staff','opex_depreciation','opex_other'] as $k) {
        if (!isset($byGroup[$k])) continue;
        $sign = $k === 'other_income' ? 1 : -1;
        $lines[] = [
            'label'    => $groupsConfig[$k],
            'is_total' => false,
            'cur'      => $sign * $byGroup[$k]['cur'],
            'pri'      => $sign * $byGroup[$k]['pri'],
            'accounts' => $byGroup[$k]['accounts'],
        ];
    }
    $opCur = $grossCur + $g('other_income','cur')
           - $g('opex_staff','cur') - $g('opex_depreciation','cur') - $g('opex_other','cur');
    $opPri = $grossPri + $g('other_income','pri')
           - $g('opex_staff','pri') - $g('opex_depreciation','pri') - $g('opex_other','pri');
    $lines[] = ['label'=>'Operating profit', 'is_total'=>true,
        'cur'=>$opCur, 'pri'=>$opPri, 'accounts'=>[]];

    if (isset($byGroup['finance_costs'])) {
        $lines[] = [
            'label'    => $groupsConfig['finance_costs'],
            'is_total' => false,
            'cur'      => -$byGroup['finance_costs']['cur'],
            'pri'      => -$byGroup['finance_costs']['pri'],
            'accounts' => $byGroup['finance_costs']['accounts'],
        ];
    }
    $pbtCur = $opCur - $g('finance_costs','cur');
    $pbtPri = $opPri - $g('finance_costs','pri');
    $lines[] = ['label'=>'Profit before tax', 'is_total'=>true,
        'cur'=>$pbtCur, 'pri'=>$pbtPri, 'accounts'=>[]];

    if (isset($byGroup['tax_expense'])) {
        $lines[] = [
            'label'    => $groupsConfig['tax_expense'],
            'is_total' => false,
            'cur'      => -$byGroup['tax_expense']['cur'],
            'pri'      => -$byGroup['tax_expense']['pri'],
            'accounts' => $byGroup['tax_expense']['accounts'],
        ];
    }
    $profitCur = $pbtCur - $g('tax_expense','cur');
    $profitPri = $pbtPri - $g('tax_expense','pri');
    $lines[] = ['label'=>'Profit for the year', 'is_total'=>true,
        'cur'=>$profitCur, 'pri'=>$profitPri, 'accounts'=>[]];

    return [
        'lines'      => $lines,
        'profit_cur' => $profitCur,
        'profit_pri' => $profitPri,
        'unmapped'   => $unmapped,
    ];
}
