<?php
/**
 * /includes/workplan.php
 *
 * The 27-step audit SOP workplan. Every engagement is auto-seeded with
 * the same ordered list of leads/modules; staff assign owners + reviewers
 * per step and statuses flow:
 *
 *     not_started → in_progress → prepared → reviewed → cleared
 *                                                       (or not_applicable)
 *
 * A step can carry an automation_hook — a feature key that, when the
 * underlying feature reaches a "done" state for the engagement, will
 * auto-advance the step. Examples:
 *
 *     tb_import           → step 2 cleared once a TB row exists
 *     materiality_set     → step 4 cleared once engagement_materiality has rows
 *     lead_<area>         → step cleared once that lead area has at least one
 *                            audit_working_papers row in status=cleared
 *     ai_going_concern    → step cleared once an accepted ai_outputs row exists
 *     audit_report_draft  → step cleared once a report has been drafted
 *     file_locked         → step cleared once engagements.locked_at is set
 *
 * workplan_sync_status() reads these hooks against current engagement
 * state and updates statuses idempotently. It's safe to call every time
 * the workspace renders — read-only checks on already-cleared steps.
 */

declare(strict_types=1);

if (!defined('AUDITBOS_BOOTSTRAPPED')) {
    http_response_code(403); exit('Forbidden');
}

/**
 * Master template of 27 audit leads/modules. Kept in PHP so adding/editing
 * a step does not require a schema migration.
 *
 * @return array<int, array{
 *   step_no:int, code:string, phase:string, title:string,
 *   procedures:string, automation_hook:?string, depends_on:?string,
 *   default_role:string
 * }>
 */
function workplan_master_template(): array
{
    return [
        // ----- PLANNING ------------------------------------------------
        ['step_no'=>1, 'code'=>'client_acceptance', 'phase'=>'planning',
         'title'=>'Client Acceptance & Appointment',
         'procedures'=>"- Confirm independence (no fee-dependency / familiarity issues)\n- Review prior auditor's clearance letter (if continuing audit)\n- Sign engagement letter; record fee, partner, manager\n- Update KYC: directors, shareholders, beneficial owners",
         'automation_hook'=>null, 'depends_on'=>null, 'default_role'=>'audit_manager'],

        ['step_no'=>2, 'code'=>'tb_import', 'phase'=>'planning',
         'title'=>'Trial Balance Import',
         'procedures'=>"- Import current and prior-year TB (CSV/Excel)\n- Map account_code → financial statement section\n- Tie TB total = 0 (debits = credits)\n- Investigate any unmapped accounts",
         'automation_hook'=>'tb_import', 'depends_on'=>'1', 'default_role'=>'junior_auditor'],

        ['step_no'=>3, 'code'=>'documents_intake', 'phase'=>'planning',
         'title'=>'Supporting Documents Intake',
         'procedures'=>"- Send document checklist via client portal\n- Receive & classify uploads (bank statements, invoices, etc.)\n- Run AI document classifier on incoming files\n- Mark items received / needs_clarification / rejected",
         'automation_hook'=>'documents_received', 'depends_on'=>'1', 'default_role'=>'junior_auditor'],

        ['step_no'=>4, 'code'=>'materiality', 'phase'=>'planning',
         'title'=>'Materiality',
         'procedures'=>"- Select benchmark (revenue, PBT, total assets, equity)\n- Set planning materiality % and performance materiality %\n- Set clearly trivial threshold (CTT)\n- Document rationale; partner approval",
         'automation_hook'=>'materiality_set', 'depends_on'=>'2', 'default_role'=>'audit_manager'],

        // ----- FIELDWORK · BALANCE SHEET --------------------------------
        ['step_no'=>5, 'code'=>'cash_bank', 'phase'=>'fieldwork',
         'title'=>'Cash & Bank Lead',
         'procedures'=>"- Obtain & test bank reconciliation\n- Send bank confirmations (auditor-direct)\n- Test high-value payments (sample over performance materiality)\n- Run GL unusual-payment / weekend-posting analytics",
         'automation_hook'=>'lead_cash', 'depends_on'=>'4', 'default_role'=>'senior_auditor'],

        ['step_no'=>6, 'code'=>'trade_recv', 'phase'=>'fieldwork',
         'title'=>'Trade Receivables Lead',
         'procedures'=>"- Aged debtors listing — tie to GL\n- Debtor confirmations (positive for large, negative for small)\n- Subsequent collection test (post-YE receipts)\n- Match Revenue cycle → Cash cycle",
         'automation_hook'=>'lead_receivables', 'depends_on'=>'4', 'default_role'=>'senior_auditor'],

        ['step_no'=>7, 'code'=>'other_recv', 'phase'=>'fieldwork',
         'title'=>'Other Receivables Lead',
         'procedures'=>"- Aging of staff advances, deposits, prepayments\n- Subsequent recovery / utilisation check\n- Prepayment allocation reasonableness\n- Flag long-overdue balances for impairment",
         'automation_hook'=>'lead_receivables', 'depends_on'=>'4', 'default_role'=>'junior_auditor'],

        ['step_no'=>8, 'code'=>'inventory', 'phase'=>'fieldwork',
         'title'=>'Inventory Lead',
         'procedures'=>"- Stock count attendance / roll-back roll-forward\n- Aging & slow-moving / obsolete review\n- NRV test for high-value SKUs\n- Cut-off testing (last GRN / last DO before YE)",
         'automation_hook'=>'lead_inventory', 'depends_on'=>'4', 'default_role'=>'senior_auditor'],

        ['step_no'=>9, 'code'=>'ppe', 'phase'=>'fieldwork',
         'title'=>'PPE Lead',
         'procedures'=>"- Asset register roll-forward (Open + Add − Disp = Close)\n- Recompute depreciation by class\n- Vouch additions to invoice; disposals to authorisation\n- Impairment indicators review",
         'automation_hook'=>'lead_ppe', 'depends_on'=>'4', 'default_role'=>'senior_auditor'],

        ['step_no'=>10, 'code'=>'finance_lease', 'phase'=>'fieldwork',
         'title'=>'Finance Lease Lead',
         'procedures'=>"- Lease schedule recalculation (interest + principal split)\n- Classification review (finance vs operating, MFRS 16)\n- Tie current / non-current liability portions\n- Disclosure check (maturity analysis)",
         'automation_hook'=>'lead_borrowings', 'depends_on'=>'4', 'default_role'=>'senior_auditor'],

        ['step_no'=>11, 'code'=>'lease_liability', 'phase'=>'fieldwork',
         'title'=>'Lease Liability (MFRS 16)',
         'procedures'=>"- Right-of-use asset roll-forward\n- Lease liability recalc — discount rate reasonableness\n- Remeasurement on rent/term modifications\n- Short-term and low-value lease exemption check",
         'automation_hook'=>'lead_borrowings', 'depends_on'=>'4', 'default_role'=>'senior_auditor'],

        ['step_no'=>12, 'code'=>'borrowings', 'phase'=>'fieldwork',
         'title'=>'Borrowings Lead',
         'procedures'=>"- Bank loan confirmation\n- Interest expense recalculation\n- Current / non-current split (repayment schedule)\n- Covenant compliance review",
         'automation_hook'=>'lead_borrowings', 'depends_on'=>'4', 'default_role'=>'senior_auditor'],

        ['step_no'=>13, 'code'=>'trade_pay', 'phase'=>'fieldwork',
         'title'=>'Trade Payables Lead',
         'procedures'=>"- Aged creditors listing — tie to GL\n- Supplier statement reconciliation (search for unrecorded liabilities)\n- Post-YE payment review (cut-off)\n- Sample supplier confirmations",
         'automation_hook'=>'lead_payables', 'depends_on'=>'4', 'default_role'=>'senior_auditor'],

        ['step_no'=>14, 'code'=>'other_pay', 'phase'=>'fieldwork',
         'title'=>'Other Payables Lead',
         'procedures'=>"- Accruals reasonableness (recompute or vouch to invoice)\n- Subsequent payment review\n- Cut-off — pre vs post-YE expense classification\n- GST/SST payable reconciliation",
         'automation_hook'=>'lead_payables', 'depends_on'=>'4', 'default_role'=>'junior_auditor'],

        ['step_no'=>15, 'code'=>'provision', 'phase'=>'fieldwork',
         'title'=>'Provision Lead',
         'procedures'=>"- Movement analysis (Open + Add − Util = Close)\n- Reasonableness of estimate (basis, assumptions)\n- Subsequent payment / utilisation evidence\n- Legal letters / contract review for unrecorded provisions",
         'automation_hook'=>'lead_payables', 'depends_on'=>'4', 'default_role'=>'senior_auditor'],

        // ----- FIELDWORK · P&L ------------------------------------------
        ['step_no'=>16, 'code'=>'revenue', 'phase'=>'fieldwork',
         'title'=>'Revenue Lead',
         'procedures'=>"- Revenue cut-off testing (last invoices / DOs before YE)\n- Three-way match: sales order → DO → invoice\n- Trend & ratio analysis vs prior year and budget\n- Unusual journal / credit-note detection",
         'automation_hook'=>'lead_revenue', 'depends_on'=>'4', 'default_role'=>'senior_auditor'],

        ['step_no'=>17, 'code'=>'cost_sales', 'phase'=>'fieldwork',
         'title'=>'Cost of Sales Lead',
         'procedures'=>"- Gross profit margin analysis (vs prior year, vs industry)\n- Reconciliation: Opening inv + Purchases − Closing inv = COS\n- Major supplier trend review\n- Cut-off — purchase invoice matched to correct period",
         'automation_hook'=>'lead_cost_sales', 'depends_on'=>'4', 'default_role'=>'senior_auditor'],

        ['step_no'=>18, 'code'=>'expenses', 'phase'=>'fieldwork',
         'title'=>'Expenses Lead',
         'procedures'=>"- Select high-value expense items for vouching\n- Unusual vendor / duplicate payment detection (GL analytics)\n- Period-on-period variance review (flag > materiality)\n- Personal-expense / related-party reasonableness check",
         'automation_hook'=>'lead_opex', 'depends_on'=>'4', 'default_role'=>'junior_auditor'],

        ['step_no'=>19, 'code'=>'payroll', 'phase'=>'fieldwork',
         'title'=>'Payroll Lead',
         'procedures'=>"- Payroll register → bank payment reconciliation\n- EPF / SOCSO / EIS amount tie-up to statutory submissions\n- Headcount reasonableness; ghost-employee testing\n- Director remuneration disclosure check",
         'automation_hook'=>'lead_payroll', 'depends_on'=>'4', 'default_role'=>'senior_auditor'],

        ['step_no'=>20, 'code'=>'related_party', 'phase'=>'fieldwork',
         'title'=>'Related Party Lead',
         'procedures'=>"- Intercompany / director account reconciliation\n- Sales / purchases with related parties — arm's length review\n- Director's current account movement scrutiny\n- MFRS 124 disclosure completeness check",
         'automation_hook'=>'related_party_done', 'depends_on'=>'4', 'default_role'=>'senior_auditor'],

        ['step_no'=>21, 'code'=>'tax', 'phase'=>'fieldwork',
         'title'=>'Tax Lead',
         'procedures'=>"- Tax computation: book profit → taxable income (add-backs)\n- Deferred tax computation (temporary differences)\n- SST / e-invoice compliance risk tagging\n- Tax instalment vs estimated tax payable reconciliation",
         'automation_hook'=>'tax_computation_done', 'depends_on'=>'4', 'default_role'=>'senior_auditor'],

        // ----- COMPLETION -----------------------------------------------
        ['step_no'=>22, 'code'=>'subsequent_events', 'phase'=>'completion',
         'title'=>'Subsequent Events Lead',
         'procedures'=>"- Post-YE GL scan (large transactions / reversals)\n- Subsequent management accounts review\n- Board / shareholder minutes after YE\n- Significant write-offs or refunds detection",
         'automation_hook'=>'subsequent_events', 'depends_on'=>'5,6,7,8,9,13,16', 'default_role'=>'audit_manager'],

        ['step_no'=>23, 'code'=>'going_concern', 'phase'=>'completion',
         'title'=>'Going Concern Lead',
         'procedures'=>"- Liquidity & solvency ratios\n- Debt servicing capability (cash flow vs interest)\n- Altman Z-score / similar distress indicators\n- Management's GC assessment & 12-month cash forecast",
         'automation_hook'=>'ai_going_concern', 'depends_on'=>'4,12,16,17', 'default_role'=>'audit_manager'],

        ['step_no'=>24, 'code'=>'misstatement_sum', 'phase'=>'completion',
         'title'=>'SUM / Misstatement Lead',
         'procedures'=>"- Accumulate uncorrected misstatements from all leads\n- Compare aggregate vs performance materiality\n- Evaluate qualitative misstatements\n- Management representation on uncorrected items",
         'automation_hook'=>'misstatement_done', 'depends_on'=>'5,6,7,8,9,10,11,12,13,14,15,16,17,18,19,20,21', 'default_role'=>'audit_manager'],

        ['step_no'=>25, 'code'=>'completion', 'phase'=>'completion',
         'title'=>'Audit Completion Lead',
         'procedures'=>"- File completion checklist (all leads cleared)\n- Review notes — all cleared / carry-forward documented\n- Manager review → Partner review sign-off chain\n- Management representation letter received",
         'automation_hook'=>'completion_done', 'depends_on'=>'22,23,24', 'default_role'=>'audit_manager'],

        // ----- REPORTING ------------------------------------------------
        ['step_no'=>26, 'code'=>'audit_report', 'phase'=>'reporting',
         'title'=>'Audit Report Generation',
         'procedures'=>"- Select opinion (unqualified / qualified / adverse / disclaimer)\n- Draft KAMs and emphasis-of-matter paragraphs (if any)\n- Tie financial statements: SOFP balances, SOCI totals\n- Partner final review & signature",
         'automation_hook'=>'audit_report_draft', 'depends_on'=>'25', 'default_role'=>'firm_admin'],

        ['step_no'=>27, 'code'=>'file_locking', 'phase'=>'reporting',
         'title'=>'Audit File Locking',
         'procedures'=>"- Confirm all leads cleared, all sign-offs recorded\n- Lock engagement (read-only archive)\n- Generate file index for retention\n- Archive supporting documents in /uploads_private",
         'automation_hook'=>'file_locked', 'depends_on'=>'26', 'default_role'=>'firm_admin'],
    ];
}

/**
 * Phase labels used by the UI grouping.
 *
 * @return array<string, string>
 */
function workplan_phases(): array
{
    return [
        'planning'   => 'Planning',
        'fieldwork'  => 'Fieldwork',
        'completion' => 'Completion',
        'reporting'  => 'Reporting',
    ];
}

/**
 * Status labels + chip classes.
 *
 * @return array<string, array{label:string, classes:string}>
 */
function workplan_statuses(): array
{
    return [
        'not_started'    => ['label' => 'Not started',    'classes' => 'bg-slate-100 text-slate-700'],
        'in_progress'    => ['label' => 'In progress',    'classes' => 'bg-blue-100 text-blue-800'],
        'prepared'       => ['label' => 'Prepared',       'classes' => 'bg-indigo-100 text-indigo-800'],
        'reviewed'       => ['label' => 'Reviewed',       'classes' => 'bg-amber-100 text-amber-800'],
        'cleared'        => ['label' => 'Cleared',        'classes' => 'bg-emerald-100 text-emerald-800'],
        'not_applicable' => ['label' => 'N/A',            'classes' => 'bg-slate-100 text-slate-500'],
    ];
}

function workplan_status_badge(string $status): string
{
    $s = workplan_statuses()[$status] ?? ['label' => $status, 'classes' => 'bg-slate-100 text-slate-700'];
    return '<span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium '
        . $s['classes'] . '">' . e($s['label']) . '</span>';
}

/**
 * Seed the workplan for an engagement from the master template. Idempotent —
 * skips steps that already exist (INSERT IGNORE on the unique key).
 *
 * Returns the number of steps inserted.
 */
function workplan_seed_engagement(int $engagementId): int
{
    if (!table_exists('engagement_workplan')) {
        return 0;
    }
    $pdo = db();
    $ins = $pdo->prepare(
        'INSERT IGNORE INTO engagement_workplan
            (engagement_id, step_no, code, phase, title, procedures_md, automation_hook, depends_on)
         VALUES (:e, :n, :c, :ph, :t, :p, :a, :d)'
    );
    $inserted = 0;
    foreach (workplan_master_template() as $tpl) {
        $ins->execute([
            ':e'  => $engagementId,
            ':n'  => $tpl['step_no'],
            ':c'  => $tpl['code'],
            ':ph' => $tpl['phase'],
            ':t'  => $tpl['title'],
            ':p'  => $tpl['procedures'],
            ':a'  => $tpl['automation_hook'],
            ':d'  => $tpl['depends_on'],
        ]);
        $inserted += $ins->rowCount();
    }
    return $inserted;
}

/**
 * Load the workplan for an engagement. Auto-seeds if empty.
 *
 * @return array<int, array<string, mixed>>
 */
function workplan_load(int $engagementId): array
{
    if (!table_exists('engagement_workplan')) {
        return [];
    }
    $pdo = db();
    $sql = 'SELECT w.*,
                   ou.name AS owner_name,
                   ru.name AS reviewer_name
              FROM engagement_workplan w
              LEFT JOIN users ou ON ou.id = w.owner_user_id
              LEFT JOIN users ru ON ru.id = w.reviewer_user_id
             WHERE w.engagement_id = :e
             ORDER BY w.step_no';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':e' => $engagementId]);
    $rows = $stmt->fetchAll();

    if (empty($rows)) {
        workplan_seed_engagement($engagementId);
        $stmt->execute([':e' => $engagementId]);
        $rows = $stmt->fetchAll();
    }
    return $rows;
}

/**
 * Summary counts by status + by phase for the engagement workspace card.
 *
 * @return array{
 *   total:int, cleared:int, in_progress:int, not_started:int,
 *   by_phase: array<string, array{total:int, cleared:int}>,
 *   pct:int
 * }
 */
function workplan_summary(int $engagementId): array
{
    $rows = workplan_load($engagementId);
    $sum = [
        'total' => 0, 'cleared' => 0, 'in_progress' => 0, 'not_started' => 0,
        'by_phase' => [], 'pct' => 0,
    ];
    foreach (workplan_phases() as $key => $_) {
        $sum['by_phase'][$key] = ['total' => 0, 'cleared' => 0];
    }
    foreach ($rows as $r) {
        $sum['total']++;
        $phase = $r['phase'];
        if (!isset($sum['by_phase'][$phase])) {
            $sum['by_phase'][$phase] = ['total' => 0, 'cleared' => 0];
        }
        $sum['by_phase'][$phase]['total']++;
        if ($r['status'] === 'cleared' || $r['status'] === 'not_applicable') {
            $sum['cleared']++;
            $sum['by_phase'][$phase]['cleared']++;
        } elseif ($r['status'] === 'in_progress' || $r['status'] === 'prepared' || $r['status'] === 'reviewed') {
            $sum['in_progress']++;
        } else {
            $sum['not_started']++;
        }
    }
    $sum['pct'] = $sum['total'] > 0
        ? (int) round(($sum['cleared'] / $sum['total']) * 100)
        : 0;
    return $sum;
}

/**
 * Inspect engagement state for each automation_hook and auto-advance step
 * status where the underlying feature has reached its "done" condition.
 *
 * Idempotent. Only ever advances forward (cleared sticks), never reverses.
 * Safe to call from any page render.
 *
 * @return int  number of steps advanced this call
 */
function workplan_sync_status(int $engagementId): int
{
    if (!table_exists('engagement_workplan')) {
        return 0;
    }
    $pdo = db();

    // Resolve all hook conditions in one batch — single SELECT per hook,
    // not per step, so the cost is bounded regardless of step count.
    $hookState = workplan_hook_state($engagementId);

    $upd = $pdo->prepare(
        'UPDATE engagement_workplan
            SET status = "cleared", cleared_at = COALESCE(cleared_at, NOW())
          WHERE engagement_id = :e AND step_no = :n
            AND status NOT IN ("cleared","not_applicable")'
    );

    $rows = $pdo->prepare(
        'SELECT step_no, automation_hook, status
           FROM engagement_workplan
          WHERE engagement_id = :e AND automation_hook IS NOT NULL'
    );
    $rows->execute([':e' => $engagementId]);

    $advanced = 0;
    foreach ($rows->fetchAll() as $r) {
        if ($r['status'] === 'cleared' || $r['status'] === 'not_applicable') {
            continue;
        }
        $hook = $r['automation_hook'];
        if (!empty($hookState[$hook])) {
            $upd->execute([':e' => $engagementId, ':n' => (int) $r['step_no']]);
            $advanced += $upd->rowCount();
        }
    }
    return $advanced;
}

/**
 * Compute hook → done? boolean map for a single engagement. Each hook
 * runs at most one focused SELECT; results are not cached because the
 * caller invokes this once per page render.
 *
 * @return array<string, bool>
 */
function workplan_hook_state(int $engagementId): array
{
    $pdo = db();
    $state = [];

    $exists = function (string $sql, array $params) use ($pdo): bool {
        $s = $pdo->prepare($sql);
        $s->execute($params);
        return (bool) $s->fetchColumn();
    };

    // TB imported (any TB row for the engagement)
    $state['tb_import'] = table_exists('trial_balances')
        && $exists('SELECT 1 FROM trial_balances WHERE engagement_id = :e LIMIT 1',
                   [':e' => $engagementId]);

    // Documents intake — at least one document_request not pending
    $state['documents_received'] = table_exists('document_requests')
        && $exists(
            'SELECT 1 FROM document_requests
              WHERE engagement_id = :e AND status IN ("received","waived") LIMIT 1',
            [':e' => $engagementId]);

    // Materiality set
    $state['materiality_set'] = table_exists('engagement_materiality')
        && $exists('SELECT 1 FROM engagement_materiality WHERE engagement_id = :e LIMIT 1',
                   [':e' => $engagementId]);

    // Per-lead-area hooks — clear when at least one WP for that area is cleared.
    $leadAreas = ['cash','receivables','inventory','ppe','payables','borrowings',
                  'tax','equity','revenue','cost_sales','payroll','opex','finance'];
    if (table_exists('audit_working_papers')) {
        $wpStmt = $pdo->prepare(
            'SELECT lead_area, COUNT(*) AS n
               FROM audit_working_papers
              WHERE engagement_id = :e AND status = "cleared" AND lead_area IS NOT NULL
              GROUP BY lead_area'
        );
        $wpStmt->execute([':e' => $engagementId]);
        $clearedByArea = [];
        foreach ($wpStmt->fetchAll() as $row) {
            $clearedByArea[$row['lead_area']] = (int) $row['n'];
        }
        foreach ($leadAreas as $area) {
            $state['lead_' . $area] = !empty($clearedByArea[$area]);
        }
    } else {
        foreach ($leadAreas as $area) {
            $state['lead_' . $area] = false;
        }
    }

    // Subsequent events — any variance_detect output accepted (the AI
    // function is reused for post-YE scans).
    $state['subsequent_events'] = table_exists('ai_outputs')
        && $exists(
            'SELECT 1 FROM ai_outputs
              WHERE engagement_id = :e AND output_type = "variance_detect"
                AND status IN ("accepted","published") LIMIT 1',
            [':e' => $engagementId]);

    // Going concern — AI output accepted
    $state['ai_going_concern'] = table_exists('ai_outputs')
        && $exists(
            'SELECT 1 FROM ai_outputs
              WHERE engagement_id = :e AND output_type = "going_concern"
                AND status IN ("accepted","published") LIMIT 1',
            [':e' => $engagementId]);

    // Related party register has at least one party logged.
    $state['related_party_done'] = table_exists('related_parties')
        && $exists('SELECT 1 FROM related_parties WHERE engagement_id = :e LIMIT 1',
                   [':e' => $engagementId]);

    // Tax computation row exists OR a tax-area WP is cleared.
    $state['tax_computation_done'] = (
        table_exists('tax_computations')
        && $exists('SELECT 1 FROM tax_computations WHERE engagement_id = :e LIMIT 1',
                   [':e' => $engagementId])
    ) || !empty($state['lead_tax']);

    // SUM cleared: at least one misstatement raised + uncorrected aggregate
    // within performance materiality (or PM not set yet, in which case the
    // step stays open).
    $state['misstatement_done'] = false;
    if (table_exists('misstatements')) {
        require_once __DIR__ . '/misstatements.php';
        $m = mis_summary($engagementId);
        $state['misstatement_done'] = ($m['uncorrected']['count'] + $m['corrected']['count']) > 0
            && $m['pm'] !== null
            && !$m['breach_pm'];
    }

    // Audit completion checklist — every item ticked.
    $state['completion_done'] = false;
    if (table_exists('completion_checklist')) {
        require_once __DIR__ . '/completion.php';
        $state['completion_done'] = completion_is_complete($engagementId);
    }

    // Audit completion (legacy hook for the old workflow-based gate) —
    // engagement at partner_review or beyond
    $st = $pdo->prepare('SELECT review_stage, locked_at FROM engagements WHERE id = :e');
    $st->execute([':e' => $engagementId]);
    $eng = $st->fetch();
    if ($eng) {
        $state['completion_checklist'] = in_array(
            $eng['review_stage'],
            ['partner_review','signed_off'],
            true
        );
        // Audit report drafted — at least one accepted ai_outputs of report type
        $state['audit_report_draft'] = table_exists('ai_outputs')
            && $exists(
                'SELECT 1 FROM ai_outputs
                  WHERE engagement_id = :e AND output_type = "audit_report"
                    AND status IN ("accepted","published") LIMIT 1',
                [':e' => $engagementId]);
        // File locked
        $state['file_locked'] = !empty($eng['locked_at']);
    } else {
        $state['completion_checklist'] = false;
        $state['audit_report_draft'] = false;
        $state['file_locked'] = false;
    }

    return $state;
}

/**
 * Update a single step. Mutating actions are restricted to firm staff (the
 * caller is expected to gate by role already). Returns true on success.
 *
 * @param array{status?:string, owner_user_id?:?int, reviewer_user_id?:?int,
 *              due_date?:?string, notes?:?string} $fields
 */
function workplan_update_step(int $engagementId, int $stepNo, array $fields): bool
{
    if (!table_exists('engagement_workplan')) {
        return false;
    }
    $sets   = [];
    $params = [':e' => $engagementId, ':n' => $stepNo];

    if (array_key_exists('status', $fields)) {
        $valid = array_keys(workplan_statuses());
        if (!in_array($fields['status'], $valid, true)) {
            return false;
        }
        $sets[]            = 'status = :st';
        $params[':st']     = $fields['status'];
        // Timestamp the lifecycle moments.
        if ($fields['status'] === 'in_progress') {
            $sets[] = 'started_at = COALESCE(started_at, NOW())';
        } elseif ($fields['status'] === 'prepared') {
            $sets[] = 'started_at = COALESCE(started_at, NOW())';
            $sets[] = 'prepared_at = NOW()';
        } elseif ($fields['status'] === 'reviewed') {
            $sets[] = 'reviewed_at = NOW()';
        } elseif ($fields['status'] === 'cleared') {
            $sets[] = 'cleared_at = NOW()';
        }
    }
    if (array_key_exists('owner_user_id', $fields)) {
        $sets[]                  = 'owner_user_id = :own';
        $params[':own']          = $fields['owner_user_id'] ?: null;
    }
    if (array_key_exists('reviewer_user_id', $fields)) {
        $sets[]                  = 'reviewer_user_id = :rev';
        $params[':rev']          = $fields['reviewer_user_id'] ?: null;
    }
    if (array_key_exists('due_date', $fields)) {
        $sets[]                  = 'due_date = :dd';
        $params[':dd']           = $fields['due_date'] ?: null;
    }
    if (array_key_exists('notes', $fields)) {
        $sets[]                  = 'notes = :nt';
        $params[':nt']           = $fields['notes'];
    }
    if (empty($sets)) {
        return false;
    }
    $sql = 'UPDATE engagement_workplan SET ' . implode(', ', $sets)
         . ' WHERE engagement_id = :e AND step_no = :n';
    return db()->prepare($sql)->execute($params);
}

/**
 * Per-step deep links into the existing modules that actually do the work.
 * Keeps the workplan view a control panel — staff click straight through
 * to TB import, lead schedules, AI panel, etc., without losing the SOP.
 *
 * @return array<int, array{label:string, href:string}>
 */
function workplan_step_links(string $code, int $engagementId): array
{
    $eid = $engagementId;
    switch ($code) {
        case 'client_acceptance':
            return [
                ['label' => 'Edit engagement',  'href' => "/firm/engagements.php?action=edit&id={$eid}"],
                ['label' => 'Engagement team',  'href' => "/firm/engagement_view.php?id={$eid}#team"],
            ];
        case 'tb_import':
            return [
                ['label' => 'Import trial balance', 'href' => "/import/trial_balance.php?engagement_id={$eid}"],
                ['label' => 'View TB',              'href' => "/import/trial_balance_view.php?engagement_id={$eid}"],
            ];
        case 'documents_intake':
            return [
                ['label' => 'Document checklist', 'href' => "/firm/doc_requests.php?engagement_id={$eid}"],
                ['label' => 'AI classify',        'href' => "/ai/run.php?fn=ai_classify_document&engagement_id={$eid}"],
            ];
        case 'materiality':
            return [
                ['label' => 'Set materiality', 'href' => "/audit/analytical_review.php?engagement_id={$eid}"],
            ];
        case 'cash_bank':
            return [
                ['label' => 'Cash lead schedule', 'href' => "/audit/lead_schedules.php?engagement_id={$eid}&area=cash"],
                ['label' => 'GL analytics',       'href' => "/audit/gl_analytics.php?engagement_id={$eid}"],
            ];
        case 'trade_recv':
            return [
                ['label' => 'Receivables lead', 'href' => "/audit/lead_schedules.php?engagement_id={$eid}&area=receivables"],
                ['label' => 'Debtor aging',     'href' => "/audit/aging.php?engagement_id={$eid}&type=debtor"],
            ];
        case 'other_recv':
            return [
                ['label' => 'Receivables lead', 'href' => "/audit/lead_schedules.php?engagement_id={$eid}&area=receivables"],
            ];
        case 'inventory':
            return [
                ['label' => 'Inventory lead', 'href' => "/audit/lead_schedules.php?engagement_id={$eid}&area=inventory"],
            ];
        case 'ppe':
            return [
                ['label' => 'PPE lead', 'href' => "/audit/lead_schedules.php?engagement_id={$eid}&area=ppe"],
            ];
        case 'finance_lease':
        case 'lease_liability':
        case 'borrowings':
            return [
                ['label' => 'Borrowings lead', 'href' => "/audit/lead_schedules.php?engagement_id={$eid}&area=borrowings"],
            ];
        case 'trade_pay':
            return [
                ['label' => 'Payables lead',  'href' => "/audit/lead_schedules.php?engagement_id={$eid}&area=payables"],
                ['label' => 'Creditor aging', 'href' => "/audit/aging.php?engagement_id={$eid}&type=creditor"],
            ];
        case 'other_pay':
        case 'provision':
            return [
                ['label' => 'Payables lead', 'href' => "/audit/lead_schedules.php?engagement_id={$eid}&area=payables"],
            ];
        case 'revenue':
            return [
                ['label' => 'Revenue lead',     'href' => "/audit/lead_schedules.php?engagement_id={$eid}&area=revenue"],
                ['label' => 'GL cut-off scan',  'href' => "/audit/gl_analytics.php?engagement_id={$eid}"],
                ['label' => 'AI variance scan', 'href' => "/ai/run.php?fn=ai_detect_variance&engagement_id={$eid}"],
            ];
        case 'cost_sales':
            return [
                ['label' => 'Cost of sales lead', 'href' => "/audit/lead_schedules.php?engagement_id={$eid}&area=cost_sales"],
            ];
        case 'expenses':
            return [
                ['label' => 'OpEx lead',           'href' => "/audit/lead_schedules.php?engagement_id={$eid}&area=opex"],
                ['label' => 'GL duplicates/outliers','href' => "/audit/gl_analytics.php?engagement_id={$eid}"],
            ];
        case 'payroll':
            return [
                ['label' => 'Payroll lead', 'href' => "/audit/lead_schedules.php?engagement_id={$eid}&area=payroll"],
            ];
        case 'related_party':
            return [
                ['label' => 'Related party register',
                 'href' => "/audit/related_party.php?engagement_id={$eid}"],
            ];
        case 'tax':
            return [
                ['label' => 'Tax computation', 'href' => "/audit/tax_computation.php?engagement_id={$eid}"],
                ['label' => 'Tax lead',        'href' => "/audit/lead_schedules.php?engagement_id={$eid}&area=tax"],
            ];
        case 'subsequent_events':
            return [
                ['label' => 'AI variance scan', 'href' => "/ai/run.php?fn=ai_detect_variance&engagement_id={$eid}"],
            ];
        case 'going_concern':
            return [
                ['label' => 'Ratios + materiality',  'href' => "/audit/analytical_review.php?engagement_id={$eid}"],
                ['label' => 'AI going concern',      'href' => "/ai/run.php?fn=ai_going_concern_assessment&engagement_id={$eid}"],
            ];
        case 'misstatement_sum':
            return [
                ['label' => 'Misstatements (SUM)', 'href' => "/audit/misstatements.php?engagement_id={$eid}"],
                ['label' => 'Working papers',      'href' => "/audit/working_papers.php?engagement_id={$eid}"],
            ];
        case 'completion':
            return [
                ['label' => 'Completion checklist', 'href' => "/audit/completion.php?engagement_id={$eid}"],
                ['label' => 'Approval & sign-off',  'href' => "/firm/engagement_view.php?id={$eid}#workflow"],
                ['label' => 'AI status summary',
                 'href' => "/ai/run.php?fn=ai_summarize_engagement_status&engagement_id={$eid}"],
            ];
        case 'audit_report':
            return [
                ['label' => 'Auditor\'s report',  'href' => "/reports/audit_report.php?engagement_id={$eid}"],
                ['label' => 'AI report drafter',
                 'href' => "/ai/run.php?fn=ai_generate_audit_report&engagement_id={$eid}"],
            ];
        case 'file_locking':
            return [
                ['label' => 'Workflow & lock', 'href' => "/firm/engagement_view.php?id={$eid}#workflow"],
            ];
    }
    return [];
}

/**
 * Map a workplan step code → the audit area & section it produces a WP in.
 * Used by the "Start step" reverse hook to pre-create the right WP.
 *
 * @return array{lead_area:string, section_code:?string, reference:string,
 *               risk:string}|null
 */
function workplan_step_to_wp_spec(string $code): ?array
{
    static $map = [
        'cash_bank'       => ['lead_area'=>'cash',        'section_code'=>'B-100', 'reference'=>'B-100', 'risk'=>'medium'],
        'trade_recv'      => ['lead_area'=>'receivables', 'section_code'=>'B-200', 'reference'=>'B-210', 'risk'=>'high'],
        'other_recv'      => ['lead_area'=>'receivables', 'section_code'=>'B-200', 'reference'=>'B-220', 'risk'=>'medium'],
        'inventory'       => ['lead_area'=>'inventory',   'section_code'=>'B-400', 'reference'=>'B-400', 'risk'=>'high'],
        'ppe'             => ['lead_area'=>'ppe',         'section_code'=>'B-500', 'reference'=>'B-500', 'risk'=>'medium'],
        'finance_lease'   => ['lead_area'=>'borrowings',  'section_code'=>null,    'reference'=>'C-110', 'risk'=>'medium'],
        'lease_liability' => ['lead_area'=>'borrowings',  'section_code'=>null,    'reference'=>'C-120', 'risk'=>'high'],
        'borrowings'      => ['lead_area'=>'borrowings',  'section_code'=>null,    'reference'=>'C-100', 'risk'=>'medium'],
        'trade_pay'       => ['lead_area'=>'payables',    'section_code'=>'B-300', 'reference'=>'B-310', 'risk'=>'high'],
        'other_pay'       => ['lead_area'=>'payables',    'section_code'=>'B-300', 'reference'=>'B-320', 'risk'=>'medium'],
        'provision'       => ['lead_area'=>'payables',    'section_code'=>'B-300', 'reference'=>'B-330', 'risk'=>'medium'],
        'revenue'         => ['lead_area'=>'revenue',     'section_code'=>'B-200', 'reference'=>'P-100', 'risk'=>'high'],
        'cost_sales'      => ['lead_area'=>'cost_sales',  'section_code'=>'B-300', 'reference'=>'P-200', 'risk'=>'medium'],
        'expenses'        => ['lead_area'=>'opex',        'section_code'=>null,    'reference'=>'P-300', 'risk'=>'medium'],
        'payroll'         => ['lead_area'=>'payroll',     'section_code'=>'B-600', 'reference'=>'P-400', 'risk'=>'medium'],
        'related_party'   => ['lead_area'=>'other',       'section_code'=>null,    'reference'=>'Z-100', 'risk'=>'high'],
        'tax'             => ['lead_area'=>'tax',         'section_code'=>'B-700', 'reference'=>'B-700', 'risk'=>'medium'],
        'misstatement_sum'=> ['lead_area'=>'other',       'section_code'=>null,    'reference'=>'Z-200', 'risk'=>'high'],
        'completion'      => ['lead_area'=>'other',       'section_code'=>null,    'reference'=>'A-900', 'risk'=>'medium'],
    ];
    return $map[$code] ?? null;
}

/**
 * Kick off a step: set status to in_progress and, for lead steps, create
 * the matching working paper if one doesn't already exist for that area.
 * Idempotent — re-running on a step that's already in progress is fine.
 *
 * Returns a suggested redirect path so the caller can land staff in the
 * right module after the kick-off, or null if the workplan view itself
 * is the right destination.
 */
function workplan_kickoff_step(int $engagementId, int $stepNo, ?int $userId): ?string
{
    if (!table_exists('engagement_workplan')) {
        return null;
    }
    $pdo = db();

    // Load the step.
    $s = $pdo->prepare(
        'SELECT * FROM engagement_workplan WHERE engagement_id = :e AND step_no = :n'
    );
    $s->execute([':e' => $engagementId, ':n' => $stepNo]);
    $step = $s->fetch();
    if (!$step) {
        return null;
    }

    // Move status forward (no-op if already past in_progress).
    if (in_array($step['status'], ['not_started'], true)) {
        $pdo->prepare(
            'UPDATE engagement_workplan
                SET status = "in_progress", started_at = COALESCE(started_at, NOW())
              WHERE engagement_id = :e AND step_no = :n'
        )->execute([':e' => $engagementId, ':n' => $stepNo]);
    }

    // Reverse-hook destinations for steps that aren't lead-area WPs.
    $directRoute = [
        'tb_import'         => '/import/trial_balance.php?engagement_id=' . $engagementId,
        'documents_intake'  => '/firm/doc_requests.php?engagement_id=' . $engagementId,
        'materiality'       => '/audit/analytical_review.php?engagement_id=' . $engagementId,
        'related_party'     => '/audit/related_party.php?engagement_id=' . $engagementId,
        'tax'               => '/audit/tax_computation.php?engagement_id=' . $engagementId,
        'subsequent_events' => '/ai/run.php?fn=ai_detect_variance&engagement_id=' . $engagementId,
        'going_concern'     => '/audit/analytical_review.php?engagement_id=' . $engagementId,
        'misstatement_sum'  => '/audit/misstatements.php?engagement_id=' . $engagementId,
        'completion'        => '/audit/completion.php?engagement_id=' . $engagementId,
        'audit_report'      => '/reports/audit_report.php?engagement_id=' . $engagementId,
        'file_locking'      => '/firm/engagement_view.php?id=' . $engagementId . '#workflow',
        'client_acceptance' => '/firm/engagements.php?action=edit&id=' . $engagementId,
    ];
    if (isset($directRoute[$step['code']])) {
        return $directRoute[$step['code']];
    }

    // Lead-area steps: ensure a WP exists for this lead area, create one
    // pre-filled with the step's procedures so the auditor has a starting
    // point. Reference codes follow audit-firm conventions (B-, P-, etc.).
    $spec = workplan_step_to_wp_spec($step['code']);
    if ($spec === null) {
        return null;
    }

    $check = $pdo->prepare(
        'SELECT id FROM audit_working_papers
          WHERE engagement_id = :e AND lead_area = :la
          ORDER BY id LIMIT 1'
    );
    $check->execute([':e' => $engagementId, ':la' => $spec['lead_area']]);
    $existing = (int) ($check->fetchColumn() ?: 0);

    if ($existing > 0) {
        return '/audit/working_paper_view.php?id=' . $existing;
    }

    // Resolve section_id (firm-level overrides not common for default codes
    // — global rows have firm_id IS NULL).
    $sectionId = null;
    if (!empty($spec['section_code'])) {
        $sec = $pdo->prepare(
            'SELECT id FROM audit_sections WHERE code = :c
              ORDER BY firm_id IS NULL, firm_id LIMIT 1'
        );
        $sec->execute([':c' => $spec['section_code']]);
        $sectionId = (int) ($sec->fetchColumn() ?: 0) ?: null;
    }

    $ins = $pdo->prepare(
        'INSERT INTO audit_working_papers
            (engagement_id, section_id, lead_area, reference_code, title,
             `procedure`, status, risk_rating, prepared_by)
         VALUES (:e, :s, :la, :rc, :t, :p, "not_started", :r, :u)'
    );
    $ins->execute([
        ':e'  => $engagementId,
        ':s'  => $sectionId,
        ':la' => $spec['lead_area'],
        ':rc' => $spec['reference'],
        ':t'  => $step['title'],
        ':p'  => $step['procedures_md'],
        ':r'  => $spec['risk'],
        ':u'  => $userId,
    ]);
    $newId = (int) $pdo->lastInsertId();
    log_activity('workplan.kickoff', 'engagement', $engagementId,
        sprintf('Step %d (%s) → created WP #%d', $stepNo, $step['code'], $newId));
    return '/audit/working_paper_view.php?id=' . $newId;
}

/**
 * Find the workplan step that owns a given context. Used by the
 * "Part of SOP step N" breadcrumb across the existing module pages so
 * staff always know where they are in the audit programme.
 *
 * Resolution rules:
 *   ('wp_lead_area', 'cash', $eid)   → step 5 (Cash & Bank)
 *   ('module', 'materiality', $eid)  → step 4
 *   ('module', 'audit_report', $eid) → step 26
 *
 * @return array{step_no:int, title:string, status:string}|null
 */
function workplan_step_for_context(string $contextKey, string $contextValue, int $engagementId): ?array
{
    if (!table_exists('engagement_workplan')) {
        return null;
    }

    if ($contextKey === 'wp_lead_area') {
        // The first step matching this lead area is the canonical owner.
        // (For receivables we have step 6 + 7 — step 6 / Trade is the lead.)
        static $leadToStep = [
            'cash'        => 5,
            'receivables' => 6,
            'inventory'   => 8,
            'ppe'         => 9,
            'borrowings'  => 12,
            'payables'    => 13,
            'tax'         => 21,
            'revenue'     => 16,
            'cost_sales'  => 17,
            'opex'        => 18,
            'payroll'     => 19,
        ];
        $stepNo = $leadToStep[$contextValue] ?? null;
        if ($stepNo === null) {
            return null;
        }
    } elseif ($contextKey === 'module') {
        static $moduleToStep = [
            'tb_import'        => 2,
            'documents'        => 3,
            'materiality'      => 4,
            'going_concern'    => 23,
            'aging_debtor'     => 6,
            'aging_creditor'   => 13,
            'audit_report'     => 26,
            'completion'       => 25,
            'gl_analytics'     => 5,
            'subsequent'       => 22,
            'related_party'    => 20,
            'tax_computation'  => 21,
            'misstatements'    => 24,
        ];
        $stepNo = $moduleToStep[$contextValue] ?? null;
        if ($stepNo === null) {
            return null;
        }
    } else {
        return null;
    }

    $st = db()->prepare(
        'SELECT step_no, title, status FROM engagement_workplan
          WHERE engagement_id = :e AND step_no = :n'
    );
    $st->execute([':e' => $engagementId, ':n' => $stepNo]);
    $row = $st->fetch();
    return $row ?: null;
}

/**
 * Render the SOP breadcrumb banner. Call from any module page that
 * resolves a context → step via workplan_step_for_context().
 */
function workplan_breadcrumb_html(int $engagementId, ?array $step): string
{
    if (!$step) {
        return '';
    }
    return '<div class="mb-4 flex items-center justify-between gap-3 rounded-lg border border-brand-100 bg-brand-50 px-4 py-2 text-sm">
        <div class="flex items-center gap-2 text-brand-900">
            <span class="inline-flex items-center justify-center w-7 h-7 rounded-full bg-brand-600 text-white text-xs font-semibold">'
            . (int) $step['step_no'] . '</span>
            <span><strong>SOP Step ' . (int) $step['step_no'] . ':</strong> ' . e($step['title']) . '</span>
            ' . workplan_status_badge($step['status']) . '
        </div>
        <a href="/audit/workplan.php?eid=' . (int) $engagementId . '"
           class="text-xs text-brand-700 hover:underline">View workplan &rarr;</a>
    </div>';
}

/**
 * Count linked working papers (total + cleared) per lead area for an
 * engagement. Used to enrich the workplan view with "X WPs · Y cleared".
 *
 * @return array<string, array{total:int, cleared:int}>
 */
function workplan_wp_counts(int $engagementId): array
{
    $counts = [];
    if (!table_exists('audit_working_papers')) {
        return $counts;
    }
    $s = db()->prepare(
        'SELECT lead_area,
                COUNT(*) AS total,
                SUM(CASE WHEN status IN ("cleared","completed") THEN 1 ELSE 0 END) AS cleared
           FROM audit_working_papers
          WHERE engagement_id = :e AND lead_area IS NOT NULL
          GROUP BY lead_area'
    );
    $s->execute([':e' => $engagementId]);
    foreach ($s->fetchAll() as $r) {
        $counts[$r['lead_area']] = [
            'total'   => (int) $r['total'],
            'cleared' => (int) $r['cleared'],
        ];
    }
    return $counts;
}

/**
 * Count linked AI runs per step (output_type) for an engagement.
 * Returns map keyed by output_type → ['runs'=>int, 'accepted'=>int].
 *
 * @return array<string, array{runs:int, accepted:int}>
 */
function workplan_ai_counts(int $engagementId): array
{
    $counts = [];
    if (!table_exists('ai_outputs')) {
        return $counts;
    }
    $s = db()->prepare(
        'SELECT output_type,
                COUNT(*) AS runs,
                SUM(CASE WHEN status IN ("accepted","published") THEN 1 ELSE 0 END) AS accepted
           FROM ai_outputs
          WHERE engagement_id = :e
          GROUP BY output_type'
    );
    $s->execute([':e' => $engagementId]);
    foreach ($s->fetchAll() as $r) {
        $counts[$r['output_type']] = [
            'runs'     => (int) $r['runs'],
            'accepted' => (int) $r['accepted'],
        ];
    }
    return $counts;
}

/**
 * Return the firm's audit staff suitable for owner / reviewer dropdowns.
 *
 * @return array<int, array{id:int, name:string, role:string}>
 */
function workplan_staff(int $firmId): array
{
    $s = db()->prepare(
        'SELECT id, name, role
           FROM users
          WHERE firm_id = :f
            AND status = "active"
            AND role IN ("firm_admin","audit_manager","senior_auditor","junior_auditor","reviewer")
          ORDER BY FIELD(role, "firm_admin","audit_manager","senior_auditor","reviewer","junior_auditor"), name'
    );
    $s->execute([':f' => $firmId]);
    return $s->fetchAll();
}
