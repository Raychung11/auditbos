<?php
/**
 * /admin/seed_demo.php?firm_id=<id>
 *
 * Super-admin one-click demo data: three clients, two engagements, a
 * full document checklist + working papers + trial balance + sample GL
 * for the flagship engagement. Lets the user explore every screen
 * without typing fixture data themselves.
 *
 * Idempotent guard: refuses to seed if the firm already has clients
 * — avoids accidentally double-loading data on a live tenant.
 */

declare(strict_types=1);
require_once __DIR__ . '/../includes/auth_guard.php';
require_role(['super_admin']);

$pdo    = db();
$firmId = isset($_GET['firm_id']) ? (int) $_GET['firm_id'] : (int) ($_POST['firm_id'] ?? 0);
if ($firmId <= 0) {
    flash('error', 'Pick a firm first.');
    redirect('/admin/firms.php');
}

$firm = $pdo->prepare('SELECT id, name FROM firms WHERE id = :id');
$firm->execute([':id' => $firmId]);
$firm = $firm->fetch();
if (!$firm) {
    flash('error', 'Firm not found.');
    redirect('/admin/firms.php');
}

// Existing-data check
$counts = $pdo->prepare(
    'SELECT
        (SELECT COUNT(*) FROM clients     WHERE firm_id = :f1) AS clients,
        (SELECT COUNT(*) FROM engagements WHERE firm_id = :f2) AS engagements,
        (SELECT COUNT(*) FROM users       WHERE firm_id = :f3 AND role <> "client_user") AS staff'
);
$counts->execute([':f1'=>$firmId, ':f2'=>$firmId, ':f3'=>$firmId]);
$c = $counts->fetch();

$summary = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['_action'] ?? 'seed';

    if ($action === 'reset_demo_passwords') {
        // Recovery action: reset every demo user attached to this firm so
        // the documented password works again — clears lockouts too.
        $pwHash = password_hash('DemoPass!2026', PASSWORD_DEFAULT);
        $pdo->prepare(
            'UPDATE users
                SET password_hash = :h,
                    status = "active",
                    failed_login_count = 0,
                    locked_until = NULL,
                    password_reset_token = NULL,
                    password_reset_expires_at = NULL
              WHERE firm_id = :f
                AND email LIKE "demo.%@example.com"'
        )->execute([':h' => $pwHash, ':f' => $firmId]);
        log_activity('demo.reset_passwords', 'firm', $firmId);
        flash('success', 'Demo passwords reset to DemoPass!2026 and lockouts cleared.');
        redirect('/admin/seed_demo.php?firm_id=' . $firmId);
    }

    if ($c['clients'] > 0 && empty($_POST['force'])) {
        flash('error', 'This firm already has clients. Tick "force" to seed anyway.');
        redirect('/admin/seed_demo.php?firm_id=' . $firmId);
    }

    try {
        $pdo->beginTransaction();
        $summary = run_seed($pdo, $firmId, current_user_id());
        $pdo->commit();
        log_activity('demo.seed', 'firm', $firmId,
            "clients={$summary['clients']} engagements={$summary['engagements']}");
        flash('success', 'Demo data seeded — explore via the sidebar.');
    } catch (Throwable $ex) {
        $pdo->rollBack();
        error_log('[AuditBOS] seed failed: ' . $ex->getMessage());
        flash('error', 'Seed failed: ' . $ex->getMessage());
        redirect('/admin/seed_demo.php?firm_id=' . $firmId);
    }
}

// ---------------------------------------------------------------------
// Seeder
// ---------------------------------------------------------------------
function run_seed(PDO $pdo, int $firmId, ?int $userId): array
{
    // 1. Staff users (placeholder password = 'DemoPass!2026', user can rotate via /admin/firm_users.php)
    $pwHash = password_hash('DemoPass!2026', PASSWORD_DEFAULT);
    $staff = [
        ['firm_admin',     'Demo Firm Admin',     'demo.admin@example.com'],
        ['audit_manager',  'Demo Manager',        'demo.manager@example.com'],
        ['senior_auditor', 'Demo Senior Auditor', 'demo.senior@example.com'],
        ['junior_auditor', 'Demo Junior Auditor', 'demo.junior@example.com'],
        ['reviewer',       'Demo Reviewer',       'demo.reviewer@example.com'],
    ];
    $staffIds = [];
    // Refresh password / status / firm_id on every seed so the documented
    // demo password always works, even after lockouts or previous tenant runs.
    $ins = $pdo->prepare(
        'INSERT INTO users (firm_id, role, name, email, password_hash, status, created_by,
                            failed_login_count, locked_until,
                            password_reset_token, password_reset_expires_at)
         VALUES (:f, :r, :n, :e, :h, "active", :cb, 0, NULL, NULL, NULL)
         ON DUPLICATE KEY UPDATE
            firm_id              = VALUES(firm_id),
            role                 = VALUES(role),
            name                 = VALUES(name),
            password_hash        = VALUES(password_hash),
            status               = "active",
            failed_login_count   = 0,
            locked_until         = NULL,
            password_reset_token = NULL,
            password_reset_expires_at = NULL'
    );
    foreach ($staff as [$role, $name, $email]) {
        $ins->execute([':f'=>$firmId, ':r'=>$role, ':n'=>$name, ':e'=>$email,
            ':h'=>$pwHash, ':cb'=>$userId]);
        $sel = $pdo->prepare('SELECT id FROM users WHERE email = :e');
        $sel->execute([':e' => $email]);
        $staffIds[$role] = (int) $sel->fetchColumn();
    }

    // 2. Three clients
    $clients = [
        ['Acme Tech Sdn. Bhd.',            '202101000001', 'Sdn. Bhd.', 'Technology',         '12-31',
         'Tan Wei Ming, Lim Sook Yi', 'Tan Holdings Sdn. Bhd. (60%), Lim Family Trust (40%)',
         'Tan Wei Ming', 'finance@acmetech.example', '+60 3-2161 0001',
         'Level 18, Menara Acme, Jalan Sultan Ismail, 50250 Kuala Lumpur', 'active'],

        ['Sunset Manufacturing Sdn. Bhd.', '201801000123', 'Sdn. Bhd.', 'Manufacturing',      '06-30',
         'Wong Kah Mun, Sara Devi',   'Sunset Group Bhd. (100%)',
         'Wong Kah Mun', 'accounts@sunset-mfg.example', '+60 4-643 9100',
         'Lot 47, Bayan Lepas Industrial Park, 11900 Pulau Pinang', 'active'],

        ['Bayu Retail Sdn. Bhd.',          '202401000456', 'Sdn. Bhd.', 'Retail (consumer)',  '12-31',
         'Hafizah Abdul Rahman',      'Hafizah Abdul Rahman (100%)',
         'Hafizah Abdul Rahman', 'hafizah@bayuretail.example', '+60 7-336 5500',
         '12 Jalan Molek, Taman Molek, 81100 Johor Bahru', 'onboarding'],
    ];

    $cIns = $pdo->prepare(
        'INSERT INTO clients
            (firm_id, company_name, registration_no, business_type, industry,
             financial_year_end, directors, shareholders, contact_person, email,
             phone, address, audit_status, assigned_manager_id, status, created_by)
         VALUES (:f, :n, :rn, :bt, :i, :fye, :d, :sh, :cp, :e, :p, :a, :as, :m, "active", :cb)'
    );
    $clientIds = [];
    foreach ($clients as $row) {
        $cIns->execute([
            ':f'=>$firmId, ':n'=>$row[0], ':rn'=>$row[1], ':bt'=>$row[2], ':i'=>$row[3],
            ':fye'=>$row[4], ':d'=>$row[5], ':sh'=>$row[6], ':cp'=>$row[7], ':e'=>$row[8],
            ':p'=>$row[9], ':a'=>$row[10], ':as'=>$row[11],
            ':m'=>$staffIds['audit_manager'], ':cb'=>$userId,
        ]);
        $clientIds[$row[0]] = (int) $pdo->lastInsertId();
    }

    // 3. Engagements
    $engIns = $pdo->prepare(
        'INSERT INTO engagements
            (firm_id, client_id, engagement_code, financial_year, period_start, period_end,
             engagement_type, fee_amount, start_date, deadline, partner_id, manager_id,
             status, created_by)
         VALUES (:f, :c, :code, :fy, :ps, :pe, :t, :fee, :sd, :dl, :pi, :mi, :s, :cb)'
    );

    // Flagship: Acme Tech FY2024 — fully populated
    $engIns->execute([
        ':f'=>$firmId, ':c'=>$clientIds['Acme Tech Sdn. Bhd.'],
        ':code'=>'AUD-2024-001', ':fy'=>'FY2024',
        ':ps'=>'2024-01-01', ':pe'=>'2024-12-31',
        ':t'=>'audit', ':fee'=>45000,
        ':sd'=>date('Y-m-d', strtotime('-21 days')),
        ':dl'=>date('Y-m-d', strtotime('+45 days')),
        ':pi'=>$staffIds['firm_admin'],
        ':mi'=>$staffIds['audit_manager'],
        ':s'=>'in_progress', ':cb'=>$userId,
    ]);
    $eng1 = (int) $pdo->lastInsertId();

    // Sunset Manufacturing FY2024 — pending documents
    $engIns->execute([
        ':f'=>$firmId, ':c'=>$clientIds['Sunset Manufacturing Sdn. Bhd.'],
        ':code'=>'AUD-2024-002', ':fy'=>'FY2024',
        ':ps'=>'2023-07-01', ':pe'=>'2024-06-30',
        ':t'=>'audit', ':fee'=>32000,
        ':sd'=>date('Y-m-d', strtotime('-7 days')),
        ':dl'=>date('Y-m-d', strtotime('+30 days')),
        ':pi'=>$staffIds['firm_admin'],
        ':mi'=>$staffIds['audit_manager'],
        ':s'=>'pending_documents', ':cb'=>$userId,
    ]);
    $eng2 = (int) $pdo->lastInsertId();

    // Engagement team
    $teamIns = $pdo->prepare(
        'INSERT INTO engagement_team (engagement_id, user_id, team_role)
         VALUES (:e, :u, :r)'
    );
    foreach ([
        [$eng1, $staffIds['firm_admin'],     'partner'],
        [$eng1, $staffIds['audit_manager'],  'manager'],
        [$eng1, $staffIds['senior_auditor'], 'senior'],
        [$eng1, $staffIds['junior_auditor'], 'junior'],
        [$eng1, $staffIds['reviewer'],       'reviewer'],
        [$eng2, $staffIds['firm_admin'],     'partner'],
        [$eng2, $staffIds['audit_manager'],  'manager'],
        [$eng2, $staffIds['senior_auditor'], 'senior'],
    ] as $row) {
        $teamIns->execute([':e'=>$row[0], ':u'=>$row[1], ':r'=>$row[2]]);
    }

    // 4. Document requests — seed the default checklist for both engagements
    $cats = $pdo->prepare(
        'SELECT id, name FROM document_categories
          WHERE (firm_id IS NULL OR firm_id = :f) AND status = "active" AND is_default_required = 1
          ORDER BY sort_order'
    );
    $cats->execute([':f' => $firmId]);
    $categories = $cats->fetchAll();

    $reqIns = $pdo->prepare(
        'INSERT INTO document_requests (engagement_id, category_id, title, is_required, status, requested_by, due_date)
         VALUES (:e, :c, :t, 1, :s, :u, :dd)'
    );
    foreach ($categories as $cat) {
        // Acme: most received, two pending (TB + Bank), one needs clarification
        $st = match ($cat['name']) {
            'Bank Statements'               => 'pending',
            'Trial Balance'                 => 'pending',
            'Inventory Listing'             => 'needs_clarification',
            'Payroll Records'               => 'pending',
            default                         => 'received',
        };
        $reqIns->execute([
            ':e'=>$eng1, ':c'=>$cat['id'], ':t'=>$cat['name'],
            ':s'=>$st, ':u'=>$staffIds['audit_manager'],
            ':dd'=>date('Y-m-d', strtotime('+14 days')),
        ]);
        // Sunset: all pending
        $reqIns->execute([
            ':e'=>$eng2, ':c'=>$cat['id'], ':t'=>$cat['name'],
            ':s'=>'pending', ':u'=>$staffIds['audit_manager'],
            ':dd'=>date('Y-m-d', strtotime('+21 days')),
        ]);
    }

    // Custom request — illustrates the doc_requests.php CRUD
    $pdo->prepare(
        'INSERT INTO document_requests (engagement_id, category_id, title, description,
                                        is_required, status, requested_by, due_date, admin_notes)
         VALUES (:e, NULL, :t, :d, 1, "pending", :u, :dd, :an)'
    )->execute([
        ':e'=>$eng1,
        ':t'=>'Lease schedule for Menara Acme HQ',
        ':d'=>'Five-year operating lease commencing 2024-04-01. Need rent escalation clauses and option-to-renew terms.',
        ':u'=>$staffIds['senior_auditor'],
        ':dd'=>date('Y-m-d', strtotime('+10 days')),
        ':an'=>'Lessor is a related party — RPT disclosure needed.',
    ]);

    // 5. Audit sections — build a code => id map for the WP inserts below.
    $secs = [];
    foreach ($pdo->query(
        'SELECT id, code FROM audit_sections WHERE status = "active" ORDER BY sort_order'
    ) as $row) {
        $secs[$row['code']] = (int) $row['id'];
    }

    // 6. Working papers on Acme engagement
    $wpIns = $pdo->prepare(
        'INSERT INTO audit_working_papers
            (engagement_id, section_id, reference_code, title, `procedure`, conclusion,
             notes, prepared_by, prepared_at, status, risk_rating)
         VALUES (:e, :s, :rc, :t, :p, :c, :n, :pb, NOW(), :st, :r)'
    );
    $wps = [
        ['A-100', 'A-100', 'Planning memorandum',
         "Document the audit strategy, materiality (planning materiality = RM 250,000, performance materiality = RM 187,500), and key risk areas identified during the planning meeting on " . date('d M Y', strtotime('-21 days')) . ".",
         "Strategy approved by the engagement partner. Key risk areas confirmed: revenue recognition (high), related-party transactions (medium), going concern (low). Materiality thresholds applied to all substantive testing.",
         null, 'completed', 'medium'],

        ['A-200', 'A-200', 'Risk assessment & internal controls',
         "Walk through the revenue, payroll and procurement cycles; document key controls; perform control-environment understanding interviews.",
         "Substantive approach selected for revenue (control testing not relied upon). Walk-throughs evidenced acceptable design; minor weakness noted in payroll segregation (see B-600 review note).",
         null, 'completed', 'high'],

        ['B-100', 'B-100', 'Cash and bank confirmation',
         "Obtain bank confirmations for all five accounts as at year-end. Reconcile to general ledger. Test reconciling items > RM 5,000.",
         "Three of five confirmations received and tie out. Outstanding: HSBC (USD account), CIMB Niaga (IDR). Following up — see open review note.",
         "USD account holds USD 412k — translation exposure noted in P&L (FX gain RM 28k).",
         'pending_review', 'low'],

        ['B-200', 'B-200', 'Trade receivables — confirmations & subsequent receipts',
         "Send positive confirmations to top-20 customers (covering 78% of YE AR balance). Trace subsequent receipts for non-respondents through to bank in Jan 2025.",
         "Confirmations: 14 of 20 received, all agree. Subsequent receipts cover 92% of remaining balance. ECL provision adequate at RM 142,000.",
         null, 'cleared', 'medium'],

        ['B-400', 'B-400', 'Inventory — physical count attendance',
         "Attend the year-end stock take on 31 Dec 2024 at the warehouse. Test counts on a sample basis (40 SKUs).",
         "[Pending — count attendance memo to be uploaded by junior. SKU sample selected.]",
         "Note: warehouse layout changed mid-year — verify cut-off procedures cover both old and new bins.",
         'review_note_raised', 'high'],

        ['B-500', 'B-500', 'Fixed asset additions',
         "Inspect ten largest additions (>RM 50k each) by reference to supplier invoice + delivery note + asset register entry.",
         "All ten additions vouched without exception. Depreciation rates consistent with prior year (5-yr SL for IT, 10-yr SL for furniture). One asset (server rack) capitalised but not yet placed in service at YE — note in management letter.",
         null, 'completed', 'low'],

        ['B-600', 'B-600', 'Payroll — analytical procedures',
         "Recompute monthly headcount × average wage; compare to GL postings. Investigate variances > 5%.",
         "Headcount × average wage approach yields RM 14.2m total payroll cost; GL shows RM 14.45m. RM 250k unexplained — see open review note on bonus accrual.",
         null, 'review_note_raised', 'medium'],

        ['B-700', 'B-700', 'Taxation — current year tax provision',
         "Recompute current year tax provision per MFRS 112. Reconcile movement in deferred tax.",
         "Provision agrees to computation within RM 4,000 (immaterial). Deferred tax asset on losses brought forward fully recognised — recoverability supported by 3-yr forecast.",
         null, 'prepared', 'medium'],

        ['C-100', 'C-100', 'Related-party transactions',
         "Obtain RPT schedule from management. Vouch top-10 transactions to supporting documentation.",
         "Schedule lists 14 transactions totalling RM 3.1m, primarily management fees to parent (Tan Holdings). Reviewed — all at arm's length per board minutes.",
         "Lessor of HQ premises is the founder's family trust — see custom doc request for lease schedule.",
         'pending_review', 'high'],

        ['C-200', 'C-200', 'Going concern assessment',
         "Review cashflow forecast for 12 months from sign-off date. Assess key assumptions and headroom.",
         "Forecast shows positive cash position throughout. Two banking covenants comfortably met. No going-concern flag.",
         null, 'completed', 'low'],
    ];
    $wpIds = [];
    foreach ($wps as $w) {
        $wpIns->execute([
            ':e'=>$eng1, ':s'=>$secs[$w[1]] ?? null, ':rc'=>$w[0], ':t'=>$w[2],
            ':p'=>$w[3], ':c'=>$w[4], ':n'=>$w[5],
            ':pb'=>$staffIds['senior_auditor'],
            ':st'=>$w[6], ':r'=>$w[7],
        ]);
        $wpIds[$w[0]] = (int) $pdo->lastInsertId();
    }

    // 7. Review notes — open, responded, cleared
    $noteIns = $pdo->prepare(
        'INSERT INTO audit_review_notes (working_paper_id, raised_by, assigned_to, note, response, severity, status, cleared_by, cleared_at)
         VALUES (:wp, :rb, :at, :n, :rp, :sev, :st, :cb, :ca)'
    );
    $noteIns->execute([
        ':wp'=>$wpIds['B-100'], ':rb'=>$staffIds['audit_manager'], ':at'=>$staffIds['senior_auditor'],
        ':n'=>'Follow up on the two outstanding bank confirmations (HSBC USD, CIMB Niaga IDR). Confirm whether responses received post-date this review note.',
        ':rp'=>'Chased both banks on ' . date('d M', strtotime('-3 days')) . '. HSBC committed to respond by end of week.',
        ':sev'=>'major', ':st'=>'responded', ':cb'=>null, ':ca'=>null,
    ]);
    $noteIns->execute([
        ':wp'=>$wpIds['B-400'], ':rb'=>$staffIds['reviewer'], ':at'=>$staffIds['junior_auditor'],
        ':n'=>'Stock count attendance memo not uploaded. Please attach within 48 hours.',
        ':rp'=>null,
        ':sev'=>'critical', ':st'=>'open', ':cb'=>null, ':ca'=>null,
    ]);
    $noteIns->execute([
        ':wp'=>$wpIds['B-600'], ':rb'=>$staffIds['audit_manager'], ':at'=>$staffIds['senior_auditor'],
        ':n'=>'RM 250k variance on payroll analytical — needs to be tied to the bonus accrual. Pull the December bonus journal and reconcile.',
        ':rp'=>null,
        ':sev'=>'major', ':st'=>'open', ':cb'=>null, ':ca'=>null,
    ]);
    $noteIns->execute([
        ':wp'=>$wpIds['B-200'], ':rb'=>$staffIds['audit_manager'], ':at'=>$staffIds['senior_auditor'],
        ':n'=>'ECL provision: re-perform the stage-2 / stage-3 split using the new aging buckets management adopted in Q4.',
        ':rp'=>'Re-performed. Stage 2 = RM 88k, Stage 3 = RM 54k. Total provision now RM 142k (vs RM 138k draft). Adjusting entry posted.',
        ':sev'=>'minor', ':st'=>'cleared',
        ':cb'=>$staffIds['audit_manager'], ':ca'=>date('Y-m-d H:i:s', strtotime('-2 days')),
    ]);

    // 8. Trial balance — current + prior period on Acme engagement
    $tb = [
        // [code, name, type, cur_debit, cur_credit, pri_debit, pri_credit]
        ['1000', 'Cash at bank — MYR',          'asset',     580_000,      0,       420_000,       0],
        ['1010', 'Cash at bank — USD (RM eq.)', 'asset',     412_000,      0,       380_000,       0],
        ['1200', 'Trade receivables',           'asset',   2_140_000,      0,     1_780_000,       0],
        ['1210', 'ECL provision',               'asset',         0,    142_000,         0,    138_000],
        ['1300', 'Inventories',                 'asset',   3_280_000,      0,     2_950_000,       0],
        ['1500', 'Property, plant & equipment', 'asset',   8_700_000,      0,     8_950_000,       0],
        ['1510', 'Accumulated depreciation',    'asset',         0,  3_120_000,         0,  2_640_000],
        ['1700', 'Deferred tax asset',          'asset',     188_000,      0,       212_000,       0],
        ['2000', 'Trade payables',              'liability',     0,  1_460_000,         0,  1_180_000],
        ['2100', 'Accrued expenses',            'liability',     0,    320_000,         0,    295_000],
        ['2110', 'Bonus accrual',               'liability',     0,    250_000,         0,           0],
        ['2300', 'Current tax payable',         'liability',     0,    540_000,         0,    480_000],
        ['2500', 'Bank borrowings — long term', 'liability',     0,  1_800_000,         0,  2_100_000],
        ['3000', 'Share capital',               'equity',        0,  1_000_000,         0,  1_000_000],
        ['3100', 'Retained earnings',           'equity',        0,  6_528_000,         0,  5_859_000],
        ['4000', 'Revenue',                     'income',        0, 18_400_000,         0, 15_800_000],
        ['4100', 'Other income',                'income',        0,    285_000,         0,    195_000],
        ['5000', 'Cost of sales',               'expense',13_750_000,       0,    11_900_000,        0],
        ['6000', 'Staff costs',                 'expense', 2_900_000,       0,     2_650_000,        0],
        ['6100', 'Depreciation',                'expense',   480_000,       0,       460_000,        0],
        ['6200', 'Rental — head office',        'expense',   360_000,       0,       180_000,        0],
        ['6300', 'Marketing',                   'expense',   215_000,       0,        92_000,        0],
        ['6400', 'Professional fees',           'expense',    98_000,       0,        76_000,        0],
        ['6500', 'Other operating expenses',    'expense',   312_000,       0,       298_000,        0],
        ['7000', 'Finance costs',               'expense',   124_000,       0,       148_000,        0],
        ['8000', 'Income tax expense',          'expense',   562_000,       0,       456_000,        0],
    ];

    $coaIns = $pdo->prepare(
        'INSERT INTO chart_of_accounts (engagement_id, account_code, account_name, account_type)
         VALUES (:e, :c, :n, :t)
         ON DUPLICATE KEY UPDATE account_name = VALUES(account_name), account_type = VALUES(account_type)'
    );
    $tbIns = $pdo->prepare(
        'INSERT INTO trial_balances
            (engagement_id, account_code, account_name, period, debit, credit, balance)
         VALUES (:e, :c, :n, :p, :d, :cr, :b)'
    );
    foreach ($tb as $row) {
        [$code, $name, $type, $cd, $cc, $pd, $pc] = $row;
        $coaIns->execute([':e'=>$eng1, ':c'=>$code, ':n'=>$name, ':t'=>$type]);
        $tbIns->execute([
            ':e'=>$eng1, ':c'=>$code, ':n'=>$name, ':p'=>'current',
            ':d'=>$cd, ':cr'=>$cc, ':b'=>$cd - $cc,
        ]);
        $tbIns->execute([
            ':e'=>$eng1, ':c'=>$code, ':n'=>$name, ':p'=>'prior',
            ':d'=>$pd, ':cr'=>$pc, ':b'=>$pd - $pc,
        ]);
    }

    // 9. Sample GL transactions — 40 entries across the year to make the
    //    GL list look real (not a full reconciled set; enough for "wow").
    $glIns = $pdo->prepare(
        'INSERT INTO general_ledgers
            (engagement_id, transaction_date, account_code, account_name,
             reference_no, description, debit, credit, source)
         VALUES (:e, :d, :c, :n, :ref, :ds, :dr, :cr, "excel")'
    );
    $glRows = [
        ['2024-01-15', '4000', 'Revenue',          'INV-2024-0142', 'Sales to Sunrise Plc — Q1 contract',                0,    320_000],
        ['2024-01-15', '1200', 'Trade receivables','INV-2024-0142', 'Sales to Sunrise Plc — Q1 contract',          320_000,          0],
        ['2024-02-28', '5000', 'Cost of sales',    'GR-2024-0399',  'Goods despatched — Sunrise Plc Q1',           212_000,          0],
        ['2024-02-28', '1300', 'Inventories',      'GR-2024-0399',  'Goods despatched — Sunrise Plc Q1',                 0,    212_000],
        ['2024-03-31', '6000', 'Staff costs',      'PAY-2024-Q1',   'Payroll Q1',                                  710_000,          0],
        ['2024-03-31', '1000', 'Cash at bank — MYR','PAY-2024-Q1', 'Payroll Q1 — disbursement',                          0,    710_000],
        ['2024-04-01', '6200', 'Rental — head office', 'LEASE-001', 'Q2 rental — Menara Acme (Tan Family Trust)',  90_000,          0],
        ['2024-04-01', '1000', 'Cash at bank — MYR',  'LEASE-001', 'Q2 rental — Menara Acme',                            0,     90_000],
        ['2024-05-12', '1500', 'PPE',              'PO-2024-0218', 'Dell PowerEdge server rack — placed in service Dec', 168_000,    0],
        ['2024-05-12', '2000', 'Trade payables',   'PO-2024-0218', 'Dell PowerEdge server rack',                         0,    168_000],
        ['2024-06-30', '4100', 'Other income',     'JV-2024-061',  'FX gain — USD revaluation',                          0,     28_000],
        ['2024-06-30', '1010', 'Cash at bank — USD','JV-2024-061', 'FX gain — USD revaluation',                     28_000,          0],
        ['2024-07-15', '6300', 'Marketing',        'MKT-Q2',       'Q2 digital marketing campaign',                 62_000,          0],
        ['2024-07-15', '1000', 'Cash at bank — MYR','MKT-Q2',      'Q2 digital marketing campaign',                       0,     62_000],
        ['2024-09-30', '6000', 'Staff costs',      'PAY-2024-Q3',  'Payroll Q3',                                   725_000,          0],
        ['2024-09-30', '1000', 'Cash at bank — MYR','PAY-2024-Q3', 'Payroll Q3 — disbursement',                          0,    725_000],
        ['2024-10-21', '6400', 'Professional fees','JV-2024-217',  'Audit fees — prior year final',                 28_000,          0],
        ['2024-10-21', '2000', 'Trade payables',   'JV-2024-217',  'Audit fees — prior year final',                      0,     28_000],
        ['2024-11-15', '5000', 'Cost of sales',    'IM-2024-0992', 'Inventory write-down — slow-moving SKUs',       95_000,          0],
        ['2024-11-15', '1300', 'Inventories',      'IM-2024-0992', 'Inventory write-down — slow-moving SKUs',            0,     95_000],
        ['2024-12-15', '6200', 'Rental — head office', 'LEASE-004','Q4 rental — Menara Acme (RPT)',                90_000,          0],
        ['2024-12-15', '1000', 'Cash at bank — MYR',  'LEASE-004','Q4 rental — Menara Acme',                              0,     90_000],
        ['2024-12-20', '2110', 'Bonus accrual',    'JV-2024-380',  'Discretionary bonus accrual — Dec',                  0,    250_000],
        ['2024-12-20', '6000', 'Staff costs',      'JV-2024-380',  'Discretionary bonus accrual — Dec',            250_000,          0],
        ['2024-12-31', '6100', 'Depreciation',     'JV-2024-401',  'Annual depreciation — PPE',                    480_000,          0],
        ['2024-12-31', '1510', 'Accum. depreciation','JV-2024-401','Annual depreciation — PPE',                          0,    480_000],
        ['2024-12-31', '1210', 'ECL provision',    'JV-2024-402',  'ECL adjustment — Stage 2/3 split',                   0,      4_000],
        ['2024-12-31', '5000', 'Cost of sales',    'JV-2024-402',  'ECL adjustment — net',                            4_000,          0],
        ['2024-12-31', '8000', 'Income tax exp.',  'JV-2024-410',  'Current tax provision',                        540_000,          0],
        ['2024-12-31', '2300', 'Current tax pay.', 'JV-2024-410',  'Current tax provision',                              0,    540_000],
    ];
    foreach ($glRows as $g) {
        $glIns->execute([
            ':e'=>$eng1, ':d'=>$g[0], ':c'=>$g[1], ':n'=>$g[2],
            ':ref'=>$g[3], ':ds'=>$g[4], ':dr'=>$g[5], ':cr'=>$g[6],
        ]);
    }

    return [
        'clients'     => count($clientIds),
        'engagements' => 2,
        'staff'       => count($staffIds),
        'doc_requests'=> count($categories) * 2 + 1,
        'working_papers' => count($wpIds),
        'tb_rows'     => count($tb) * 2,
        'gl_rows'     => count($glRows),
        'flagship_engagement_id' => $eng1,
    ];
}

$pageTitle = 'Seed demo data · ' . $firm['name'];
require __DIR__ . '/../includes/header.php';
?>

<a href="/admin/firms.php?action=edit&id=<?= (int) $firmId ?>"
   class="text-sm text-brand-600 hover:underline">&larr; Back to firm</a>

<h2 class="text-xl font-semibold text-slate-900 mt-1 mb-1">
    Seed demo data — <?= e($firm['name']) ?>
</h2>
<p class="text-sm text-slate-500 mb-5">
    One-click test fixture. Adds three clients, two engagements (one fully populated),
    five demo staff users, a document checklist, ten working papers with review notes,
    a 26-account trial balance (current + prior periods), and 30 sample GL transactions.
</p>

<?php if ($summary): ?>
    <div class="bg-emerald-50 border border-emerald-200 rounded-lg p-5 mb-5">
        <h3 class="font-semibold text-emerald-900 mb-2">Seeded successfully</h3>
        <ul class="text-sm text-emerald-900 space-y-0.5">
            <li>· <?= (int) $summary['clients'] ?> clients</li>
            <li>· <?= (int) $summary['engagements'] ?> engagements</li>
            <li>· <?= (int) $summary['staff'] ?> staff users (password <code>DemoPass!2026</code>)</li>
            <li>· <?= (int) $summary['doc_requests'] ?> document requests</li>
            <li>· <?= (int) $summary['working_papers'] ?> working papers + review notes</li>
            <li>· <?= (int) $summary['tb_rows'] ?> trial balance rows (current + prior)</li>
            <li>· <?= (int) $summary['gl_rows'] ?> general ledger transactions</li>
        </ul>
        <div class="mt-3 flex gap-2">
            <a href="/firm/engagement_view.php?id=<?= (int) $summary['flagship_engagement_id'] ?>"
               class="rounded bg-brand-600 hover:bg-brand-700 text-white px-3 py-1.5 text-sm">
                Open flagship engagement
            </a>
            <a href="/import/trial_balance_view.php?engagement_id=<?= (int) $summary['flagship_engagement_id'] ?>"
               class="rounded border border-slate-300 px-3 py-1.5 text-sm hover:bg-slate-50">
                View trial balance &amp; variance
            </a>
        </div>
    </div>
<?php endif; ?>

<div class="bg-white rounded-lg border border-slate-200 p-5 max-w-2xl">
    <h3 class="font-semibold text-slate-900 mb-2">Current state</h3>
    <ul class="text-sm text-slate-700 space-y-0.5 mb-4">
        <li>Clients: <strong><?= (int) $c['clients'] ?></strong></li>
        <li>Engagements: <strong><?= (int) $c['engagements'] ?></strong></li>
        <li>Staff users: <strong><?= (int) $c['staff'] ?></strong></li>
    </ul>

    <?php if ($c['clients'] > 0): ?>
        <div class="rounded border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900 mb-4">
            This firm already has data. Seeding will <em>add</em> demo records alongside what's there.
            Tick the box below to confirm.
        </div>
    <?php endif; ?>

    <form method="post" class="space-y-3">
        <?= csrf_field() ?>
        <input type="hidden" name="firm_id" value="<?= (int) $firmId ?>">
        <?php if ($c['clients'] > 0): ?>
            <label class="flex items-center gap-2 text-sm">
                <input type="checkbox" name="force" value="1" required>
                Yes, seed demo data anyway
            </label>
        <?php endif; ?>
        <button class="rounded bg-brand-600 hover:bg-brand-700 text-white px-4 py-2 text-sm font-medium">
            Seed demo data
        </button>
    </form>

    <?php if ($c['staff'] > 0): ?>
        <div class="mt-5 pt-4 border-t border-slate-200">
            <h4 class="font-medium text-slate-900 text-sm mb-1">Can't sign in as a demo user?</h4>
            <p class="text-xs text-slate-500 mb-2">
                Resets every <code>demo.*@example.com</code> account for this firm back to
                password <code>DemoPass!2026</code> and clears any lockouts.
            </p>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="firm_id" value="<?= (int) $firmId ?>">
                <input type="hidden" name="_action" value="reset_demo_passwords">
                <button class="rounded border border-amber-400 text-amber-800 bg-amber-50 hover:bg-amber-100 px-3 py-1.5 text-xs font-medium">
                    Reset demo passwords
                </button>
            </form>
        </div>
    <?php endif; ?>

    <div class="mt-4 text-xs text-slate-500">
        Demo staff passwords: <code>DemoPass!2026</code> — rotate via
        <a href="/admin/firm_users.php?firm_id=<?= (int) $firmId ?>" class="text-brand-600 hover:underline">Manage firm users</a>.
    </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
