<?php
/**
 * /ai/prompts.php
 *
 * Prompt builders. Each function name in the AI registry has a builder
 * here that:
 *   1. Pulls relevant engagement data from the database (TB, GL, docs,
 *      working papers, review notes, etc.)
 *   2. Formats it as a structured user message that Claude can reason about
 *
 * Keeping data-fetch + formatting per function means the API call itself
 * stays generic — ai_call_provider() only needs the system prompt (from
 * the registry) plus the user message returned here.
 *
 * Loaded by /ai/ai_service.php — not a standalone entrypoint.
 */

declare(strict_types=1);

if (!defined('AUDITBOS_BOOTSTRAPPED')) {
    http_response_code(403); exit('Forbidden');
}

/**
 * Top-level dispatcher. Returns the user message to send.
 *
 * @param array<string,mixed> $payload Caller-supplied extras (e.g. entity_id).
 */
function ai_build_user_message(string $functionName, ?int $engagementId, array $payload): string
{
    switch ($functionName) {
        case 'ai_analyze_trial_balance':
        case 'ai_detect_variance':
            return ai_payload_tb_variance($engagementId);
        case 'ai_summarize_engagement_status':
        case 'ai_partner_review_assistant':
            return ai_payload_engagement_status($engagementId);
        case 'ai_generate_audit_queries':
            return ai_payload_audit_queries($engagementId);
        case 'ai_generate_management_letter':
            return ai_payload_management_letter($engagementId);
        case 'ai_generate_client_reminder':
            return ai_payload_client_reminder($engagementId);
        case 'ai_review_working_paper':
            return ai_payload_review_wp((int)($payload['working_paper_id'] ?? 0));
        case 'ai_analyze_gl_exceptions':
            return ai_payload_gl_exceptions($engagementId);
        case 'ai_going_concern_assessment':
            return ai_payload_going_concern($engagementId);
        case 'ai_generate_audit_report':
            return ai_payload_audit_report($engagementId, $payload);
        default:
            return "(No structured data available — function: {$functionName})";
    }
}

// ---------------------------------------------------------------------
// Common: engagement header block included on every prompt
// ---------------------------------------------------------------------
function ai_engagement_header(int $engagementId): string
{
    $stmt = db()->prepare(
        'SELECT e.financial_year, e.engagement_type, e.period_start, e.period_end,
                e.deadline, e.status, e.engagement_code,
                c.company_name, c.industry, c.financial_year_end,
                c.registration_no, c.business_type
           FROM engagements e
           JOIN clients c ON c.id = e.client_id
          WHERE e.id = :id'
    );
    $stmt->execute([':id' => $engagementId]);
    $row = $stmt->fetch();
    if (!$row) {
        return "[Engagement not found.]";
    }
    $lines = [
        "ENGAGEMENT",
        "  Client:           {$row['company_name']}"
            . ($row['registration_no'] ? "  (Reg: {$row['registration_no']})" : ''),
        "  Industry:         " . ($row['industry'] ?? 'n/a'),
        "  Business type:    " . ($row['business_type'] ?? 'n/a'),
        "  Financial year:   {$row['financial_year']}",
        "  Period:           " . ($row['period_start'] ?? '?') . " to " . ($row['period_end'] ?? '?'),
        "  FYE:              " . ($row['financial_year_end'] ?? 'n/a'),
        "  Engagement type:  " . ucfirst($row['engagement_type']),
        "  Engagement code:  " . ($row['engagement_code'] ?? 'n/a'),
        "  Deadline:         " . ($row['deadline'] ?? 'n/a'),
        "  Status:           " . str_replace('_', ' ', $row['status']),
    ];
    return implode("\n", $lines);
}

// ---------------------------------------------------------------------
// TB variance analysis
// ---------------------------------------------------------------------
function ai_payload_tb_variance(?int $engagementId): string
{
    if (!$engagementId) {
        return "No engagement context provided.";
    }
    $header = ai_engagement_header($engagementId);

    $stmt = db()->prepare(
        'SELECT
            COALESCE(cur.account_code, pri.account_code) AS account_code,
            COALESCE(cur.account_name, pri.account_name) AS account_name,
            cur.balance AS cur_balance,
            pri.balance AS pri_balance
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
         UNION
         SELECT pri.account_code, pri.account_name, cur.balance, pri.balance
           FROM (
                SELECT account_code, account_name, balance
                  FROM trial_balances
                 WHERE engagement_id = :e3 AND period = "prior"
              ) pri
           LEFT JOIN (
                SELECT account_code, account_name, balance
                  FROM trial_balances
                 WHERE engagement_id = :e4 AND period = "current"
              ) cur ON cur.account_code = pri.account_code
          WHERE cur.account_code IS NULL
          ORDER BY account_code'
    );
    $stmt->execute([':e1'=>$engagementId, ':e2'=>$engagementId, ':e3'=>$engagementId, ':e4'=>$engagementId]);
    $rows = $stmt->fetchAll();

    if (empty($rows)) {
        return $header . "\n\nNo trial balance data is loaded. Please ask the user to import a TB first.";
    }

    $lines = ["", "TRIAL BALANCE (current vs prior, ordered by account_code):", ""];
    $lines[] = sprintf("%-12s  %-50s  %15s  %15s  %15s  %8s",
        'Code', 'Account', 'Current', 'Prior', 'Δ', 'Δ %');
    $lines[] = str_repeat('-', 122);

    $totalCur = 0.0; $totalPri = 0.0;
    foreach ($rows as $r) {
        $cur = (float)($r['cur_balance'] ?? 0);
        $pri = (float)($r['pri_balance'] ?? 0);
        $diff = $cur - $pri;
        $pct  = $pri != 0 ? ($diff / abs($pri)) * 100 : null;
        $pctStr = $pct === null ? 'new' : sprintf('%.1f%%', $pct);
        $lines[] = sprintf("%-12s  %-50s  %15s  %15s  %15s  %8s",
            substr((string)$r['account_code'], 0, 12),
            substr((string)($r['account_name'] ?? ''), 0, 50),
            number_format($cur, 2),
            number_format($pri, 2),
            number_format($diff, 2),
            $pctStr
        );
        $totalCur += $cur;
        $totalPri += $pri;
    }
    $lines[] = str_repeat('-', 122);
    $lines[] = sprintf("%-12s  %-50s  %15s  %15s  %15s  %8s",
        '', 'Totals',
        number_format($totalCur, 2),
        number_format($totalPri, 2),
        number_format($totalCur - $totalPri, 2),
        ''
    );

    return $header . "\n" . implode("\n", $lines) . "\n\n"
        . "Please run a variance analysis focused on:\n"
        . "  • Material movements (anything beyond the user's tolerance — flag everything > 10% as a starting point)\n"
        . "  • Negative balances on accounts that should never go negative\n"
        . "  • Unusual gross margin shifts or expense ratios\n"
        . "  • Accounts that are new this year or have disappeared\n"
        . "  • Potential going-concern or related-party indicators\n\n"
        . "Format the output as a concise audit memorandum with a short summary up top, then a bulleted list of findings grouped by risk level (High / Medium / Low). For each finding, cite the account code + name + amounts.";
}

// ---------------------------------------------------------------------
// Engagement status summary / partner review
// ---------------------------------------------------------------------
function ai_payload_engagement_status(?int $engagementId): string
{
    if (!$engagementId) return "No engagement context provided.";
    $pdo = db();
    $header = ai_engagement_header($engagementId);

    // Docs
    $docs = $pdo->prepare(
        'SELECT dr.title, dr.status, dc.name AS category_name,
                (SELECT COUNT(*) FROM engagement_documents ed
                   WHERE ed.document_request_id = dr.id AND ed.status IN ("uploaded","accepted")) AS files
           FROM document_requests dr
           LEFT JOIN document_categories dc ON dc.id = dr.category_id
          WHERE dr.engagement_id = :e
          ORDER BY dr.status = "pending" DESC, dc.sort_order, dr.id'
    );
    $docs->execute([':e' => $engagementId]);
    $docRows = $docs->fetchAll();

    // WPs
    $wps = $pdo->prepare(
        'SELECT wp.reference_code, wp.title, wp.status, wp.risk_rating,
                asec.name AS section_name,
                (SELECT COUNT(*) FROM audit_review_notes arn
                  WHERE arn.working_paper_id = wp.id AND arn.status IN ("open","responded","reopened")) AS open_notes
           FROM audit_working_papers wp
           LEFT JOIN audit_sections asec ON asec.id = wp.section_id
          WHERE wp.engagement_id = :e
          ORDER BY asec.sort_order, wp.id'
    );
    $wps->execute([':e' => $engagementId]);
    $wpRows = $wps->fetchAll();

    // Data summary
    $data = $pdo->prepare(
        'SELECT
            (SELECT COUNT(*) FROM trial_balances WHERE engagement_id = :e1 AND period = "current") AS tb_cur,
            (SELECT COUNT(*) FROM trial_balances WHERE engagement_id = :e2 AND period = "prior")   AS tb_pri,
            (SELECT COUNT(*) FROM general_ledgers WHERE engagement_id = :e3)                       AS gl,
            (SELECT COUNT(*) FROM engagement_documents WHERE engagement_id = :e4
              AND status IN ("uploaded","accepted"))                                                AS docs'
    );
    $data->execute([':e1'=>$engagementId, ':e2'=>$engagementId, ':e3'=>$engagementId, ':e4'=>$engagementId]);
    $d = $data->fetch() ?: ['tb_cur'=>0,'tb_pri'=>0,'gl'=>0,'docs'=>0];

    $out  = $header . "\n\nDATA LOADED:\n";
    $out .= "  Trial balance (current): {$d['tb_cur']} accounts\n";
    $out .= "  Trial balance (prior):   {$d['tb_pri']} accounts\n";
    $out .= "  GL transactions:         {$d['gl']}\n";
    $out .= "  Documents uploaded:      {$d['docs']}\n";

    $out .= "\nDOCUMENT CHECKLIST (" . count($docRows) . " items):\n";
    $byStatus = [];
    foreach ($docRows as $dr) {
        $byStatus[$dr['status']][] = $dr;
    }
    foreach (['pending','needs_clarification','rejected','received','waived'] as $st) {
        if (empty($byStatus[$st])) continue;
        $out .= "  [" . strtoupper(str_replace('_',' ',$st)) . "] " . count($byStatus[$st]) . " items:\n";
        foreach (array_slice($byStatus[$st], 0, 20) as $dr) {
            $out .= "    - {$dr['title']}" . ($dr['category_name'] ? " ({$dr['category_name']})" : '') . "\n";
        }
        if (count($byStatus[$st]) > 20) {
            $out .= "    ... and " . (count($byStatus[$st]) - 20) . " more\n";
        }
    }

    $out .= "\nWORKING PAPERS (" . count($wpRows) . " total):\n";
    $wpByStatus = [];
    foreach ($wpRows as $w) { $wpByStatus[$w['status']][] = $w; }
    foreach (['not_started','prepared','pending_review','review_note_raised','cleared','completed'] as $st) {
        if (empty($wpByStatus[$st])) continue;
        $out .= "  [" . strtoupper(str_replace('_',' ',$st)) . "] " . count($wpByStatus[$st]) . " WPs:\n";
        foreach ($wpByStatus[$st] as $wp) {
            $risk = $wp['risk_rating'] ? " [risk={$wp['risk_rating']}]" : '';
            $notes = $wp['open_notes'] > 0 ? " ({$wp['open_notes']} open notes)" : '';
            $ref = $wp['reference_code'] ? "{$wp['reference_code']} " : '';
            $out .= "    - {$ref}{$wp['title']}{$risk}{$notes}\n";
        }
    }

    $out .= "\nPlease produce a concise engagement status summary covering:\n"
         . "  1. Overall completion (rough %)\n"
         . "  2. High-risk areas needing partner attention\n"
         . "  3. Outstanding client documents blocking progress\n"
         . "  4. Unresolved review notes\n"
         . "  5. Recommended next actions, prioritised\n"
         . "Format as a short briefing (5–10 bullets). Highlight anything blocking sign-off.";
    return $out;
}

// ---------------------------------------------------------------------
// Audit queries — draft outgoing questions for the client
// ---------------------------------------------------------------------
function ai_payload_audit_queries(?int $engagementId): string
{
    if (!$engagementId) return "No engagement context provided.";
    $pdo = db();
    $header = ai_engagement_header($engagementId);

    $missing = $pdo->prepare(
        'SELECT dr.title, dr.description, dc.name AS category_name, dr.due_date,
                dr.admin_notes
           FROM document_requests dr
           LEFT JOIN document_categories dc ON dc.id = dr.category_id
          WHERE dr.engagement_id = :e AND dr.status IN ("pending","needs_clarification","rejected")
          ORDER BY dr.due_date IS NULL, dr.due_date ASC'
    );
    $missing->execute([':e' => $engagementId]);
    $missingRows = $missing->fetchAll();

    // Pull top-10 largest variances for context
    $variances = $pdo->prepare(
        'SELECT cur.account_code, cur.account_name,
                cur.balance AS cur_bal, pri.balance AS pri_bal
           FROM trial_balances cur
           JOIN trial_balances pri
             ON pri.engagement_id = cur.engagement_id
            AND pri.account_code = cur.account_code
            AND pri.period = "prior"
          WHERE cur.engagement_id = :e AND cur.period = "current"
            AND pri.balance <> 0
            AND ABS((cur.balance - pri.balance) / pri.balance) > 0.20
          ORDER BY ABS(cur.balance - pri.balance) DESC
          LIMIT 10'
    );
    $variances->execute([':e' => $engagementId]);
    $varRows = $variances->fetchAll();

    $out = $header . "\n\nMISSING / UNRESOLVED DOCUMENT REQUESTS:\n";
    if (empty($missingRows)) {
        $out .= "  (None — all documents received.)\n";
    } else {
        foreach ($missingRows as $dr) {
            $due = $dr['due_date'] ? " [due {$dr['due_date']}]" : '';
            $out .= "  - {$dr['title']}{$due}\n";
            if (!empty($dr['admin_notes'])) {
                $out .= "      note: {$dr['admin_notes']}\n";
            }
        }
    }

    $out .= "\nLARGE YEAR-ON-YEAR VARIANCES (> 20%):\n";
    if (empty($varRows)) {
        $out .= "  (No material variances detected.)\n";
    } else {
        foreach ($varRows as $v) {
            $cur = number_format((float) $v['cur_bal'], 2);
            $pri = number_format((float) $v['pri_bal'], 2);
            $diff = (float) $v['cur_bal'] - (float) $v['pri_bal'];
            $out .= "  - {$v['account_code']} {$v['account_name']}: {$cur} (prior {$pri}, Δ "
                  . number_format($diff, 2) . ")\n";
        }
    }

    $out .= "\nDraft polite, professional audit query messages the client can act on. "
         . "Group queries logically (Documents, Variances, Going concern, Other). "
         . "Each query should be a self-contained paragraph the client can reply to without context. "
         . "Use plain-text email-ready format — no markdown headers, just clear paragraph breaks.";
    return $out;
}

// ---------------------------------------------------------------------
// Management letter — Observation / Risk / Recommendation per finding
// ---------------------------------------------------------------------
function ai_payload_management_letter(?int $engagementId): string
{
    if (!$engagementId) return "No engagement context provided.";
    $pdo = db();
    $header = ai_engagement_header($engagementId);

    $wps = $pdo->prepare(
        'SELECT wp.reference_code, wp.title, wp.`procedure` AS procedure, wp.conclusion,
                wp.risk_rating, asec.name AS section_name,
                (SELECT GROUP_CONCAT(CONCAT("[", arn.severity, "] ", arn.note) SEPARATOR " | ")
                   FROM audit_review_notes arn
                  WHERE arn.working_paper_id = wp.id
                    AND arn.status IN ("open","responded","reopened","cleared")
                    AND arn.severity IN ("major","critical")) AS major_notes
           FROM audit_working_papers wp
           LEFT JOIN audit_sections asec ON asec.id = wp.section_id
          WHERE wp.engagement_id = :e
            AND (wp.risk_rating IN ("high","critical")
                 OR wp.status = "review_note_raised"
                 OR EXISTS (SELECT 1 FROM audit_review_notes arn2
                             WHERE arn2.working_paper_id = wp.id
                               AND arn2.severity IN ("major","critical")))
          ORDER BY asec.sort_order, wp.id'
    );
    $wps->execute([':e' => $engagementId]);
    $rows = $wps->fetchAll();

    $out = $header . "\n\nHIGH-RISK / NOTE-RAISED WORKING PAPERS:\n";
    if (empty($rows)) {
        $out .= "  (No high-risk findings flagged yet — draft a generic management letter shell from typical control areas.)\n";
    } else {
        foreach ($rows as $wp) {
            $out .= "\n• {$wp['reference_code']} {$wp['title']}"
                  . ($wp['section_name'] ? " ({$wp['section_name']})" : '')
                  . ($wp['risk_rating'] ? " [risk={$wp['risk_rating']}]" : '') . "\n";
            if (!empty($wp['conclusion'])) {
                $out .= "  Conclusion: " . substr($wp['conclusion'], 0, 400) . "\n";
            }
            if (!empty($wp['major_notes'])) {
                $out .= "  Major notes: " . substr($wp['major_notes'], 0, 600) . "\n";
            }
        }
    }

    $out .= "\nDraft a management letter section per finding using the standard structure:\n"
         . "  • Observation — what we found\n"
         . "  • Risk — what could go wrong if unaddressed\n"
         . "  • Recommendation — specific, actionable improvement\n"
         . "  • Management response — leave as placeholder \"[Pending management response]\"\n\n"
         . "Use professional auditor tone. Be specific to the findings supplied above.";
    return $out;
}

// ---------------------------------------------------------------------
// Client reminder — friendly nudge for outstanding docs
// ---------------------------------------------------------------------
function ai_payload_client_reminder(?int $engagementId): string
{
    if (!$engagementId) return "No engagement context provided.";
    $pdo = db();
    $header = ai_engagement_header($engagementId);

    $missing = $pdo->prepare(
        'SELECT dr.title, dr.description, dc.name AS category_name, dr.due_date
           FROM document_requests dr
           LEFT JOIN document_categories dc ON dc.id = dr.category_id
          WHERE dr.engagement_id = :e AND dr.status = "pending"
          ORDER BY dr.due_date IS NULL, dr.due_date ASC'
    );
    $missing->execute([':e' => $engagementId]);
    $rows = $missing->fetchAll();

    $out = $header . "\n\nOUTSTANDING DOCUMENTS (pending):\n";
    if (empty($rows)) {
        $out .= "  (None — write a thank-you for prompt submission instead.)\n";
    } else {
        foreach ($rows as $dr) {
            $due = $dr['due_date'] ? " (due {$dr['due_date']})" : '';
            $out .= "  - {$dr['title']}" . ($dr['category_name'] ? " — {$dr['category_name']}" : '') . "{$due}\n";
        }
    }

    $out .= "\nDraft two versions of a reminder to the client:\n"
         . "  1. EMAIL version (formal but warm, ~150 words; full salutation + signature placeholder)\n"
         . "  2. WHATSAPP version (3–5 short lines, friendly, no formal salutation; use line breaks for readability)\n\n"
         . "Both should list the outstanding items clearly and propose a deadline (suggest one week unless an earlier due date is shown).";
    return $out;
}

// ---------------------------------------------------------------------
// GL exception analysis — feeds the automated analytics into Claude.
// ---------------------------------------------------------------------
function ai_payload_gl_exceptions(?int $engagementId): string
{
    if (!$engagementId) return "No engagement context provided.";
    require_once __DIR__ . '/../includes/gl_analytics.php';
    $header = ai_engagement_header($engagementId);
    $a = gl_run_all($engagementId);
    $s = $a['summary'];

    if ($s['rows'] === 0) {
        return $header . "\n\nNo general ledger has been imported for this engagement.";
    }

    $out = $header . "\n\nGENERAL LEDGER SUMMARY:\n"
        . "  Transactions: {$s['rows']} · Accounts: {$s['accounts']}\n"
        . "  Total debits: " . number_format($s['total_debit'], 2)
        . " · Total credits: " . number_format($s['total_credit'], 2) . "\n"
        . "  Period: " . ($s['date_min'] ?? '?') . " to " . ($s['date_max'] ?? '?') . "\n";

    $out .= "\nPOTENTIAL DUPLICATE PAYMENTS (same account+amount+date, posted >1x): "
        . count($a['duplicates']) . "\n";
    foreach (array_slice($a['duplicates'], 0, 15) as $d) {
        $out .= "  - {$d['transaction_date']} {$d['account_code']} {$d['account_name']} "
            . "x {$d['occurrences']} @ " . number_format((float) $d['debit'], 2)
            . " (refs: {$d['refs']})\n";
    }

    $out .= "\nROUND-NUMBER POSTINGS (multiples of 1,000): " . count($a['round']) . "\n";
    foreach (array_slice($a['round'], 0, 12) as $r) {
        $out .= "  - {$r['transaction_date']} {$r['account_code']} "
            . number_format((float) $r['amount'], 2)
            . ($r['reference_no'] ? " ref {$r['reference_no']}" : '') . "\n";
    }

    $out .= "\nWEEKEND POSTINGS: " . count($a['weekend']) . "\n";
    foreach (array_slice($a['weekend'], 0, 12) as $w) {
        $out .= "  - {$w['transaction_date']} ({$w['day_name']}) {$w['account_code']} "
            . "Dr " . number_format((float) $w['debit'], 2)
            . " Cr " . number_format((float) $w['credit'], 2) . "\n";
    }

    $out .= "\nLARGEST TRANSACTIONS:\n";
    foreach (array_slice($a['outliers'], 0, 10) as $o) {
        $out .= "  - {$o['transaction_date']} {$o['account_code']} {$o['account_name']} "
            . number_format((float) $o['amount'], 2) . "\n";
    }

    $out .= "\nACCOUNT CONCENTRATION (top by value):\n";
    foreach (array_slice($a['top'], 0, 10) as $t) {
        $out .= "  - {$t['account_code']} {$t['account_name']}: "
            . "{$t['txns']} txns, total " . number_format((float) $t['total_value'], 2) . "\n";
    }

    $b = $a['benford'];
    $out .= "\nBENFORD FIRST-DIGIT ANALYSIS (sample {$b['sample']}, max deviation "
        . number_format($b['max_deviation'], 1) . "%):\n";
    foreach ($b['rows'] as $row) {
        $out .= "  digit {$row['digit']}: observed " . number_format($row['observed_pct'], 1)
            . "% vs expected " . number_format($row['expected_pct'], 1)
            . "% (" . ($row['deviation'] >= 0 ? '+' : '') . number_format($row['deviation'], 1) . "%)\n";
    }

    $out .= "\nReview these exceptions. Tell the team which to investigate first and why, "
        . "the specific follow-up procedure for each material item, and whether any pattern "
        . "suggests error or fraud. Be proportionate. Group findings High / Medium / Low.";
    return $out;
}

// ---------------------------------------------------------------------
// Going-concern assessment — ratios + materiality fed to Claude.
// ---------------------------------------------------------------------
function ai_payload_going_concern(?int $engagementId): string
{
    if (!$engagementId) return "No engagement context provided.";
    require_once __DIR__ . '/../includes/analytical.php';
    $header = ai_engagement_header($engagementId);
    $ratios = analytical_ratios($engagementId);
    if (empty($ratios)) {
        return $header . "\n\nNo trial balance imported — cannot compute ratios.";
    }

    $out = $header . "\n\nFINANCIAL-HEALTH RATIOS (current vs prior):\n";
    foreach ($ratios as $r) {
        $cur = $r['cur'] === null ? 'n/a' : number_format($r['cur'], $r['unit'] === 'amount' ? 2 : 2)
            . ($r['unit'] === '%' ? '%' : ($r['unit'] === 'x' ? 'x' : ''));
        $pri = $r['pri'] === null ? 'n/a' : number_format($r['pri'], $r['unit'] === 'amount' ? 2 : 2)
            . ($r['unit'] === '%' ? '%' : ($r['unit'] === 'x' ? 'x' : ''));
        $flag = $r['concern'] ? '  [FLAG]' : '';
        $out .= "  - {$r['label']}: current {$cur} · prior {$pri}{$flag}\n";
    }

    $mat = materiality_load($engagementId);
    if ($mat) {
        $out .= "\nMATERIALITY:\n"
            . "  Basis: {$mat['basis']} = " . number_format((float) $mat['basis_amount'], 2) . "\n"
            . "  Planning materiality: " . number_format((float) $mat['planning_amount'], 2) . "\n"
            . "  Performance materiality: " . number_format((float) $mat['performance_amount'], 2) . "\n";
    }

    $out .= "\nPerform the going-concern assessment. Reference the specific ratios, "
        . "state your conclusion (no material uncertainty / material uncertainty — disclose / "
        . "going-concern basis inappropriate), list the audit procedures you would perform, "
        . "and the management representations to obtain.";
    return $out;
}

// ---------------------------------------------------------------------
// Independent auditor's report — opinion type + financials → full draft.
// ---------------------------------------------------------------------
function ai_payload_audit_report(?int $engagementId, array $payload): string
{
    if (!$engagementId) return "No engagement context provided.";
    require_once __DIR__ . '/../includes/analytical.php';
    $header = ai_engagement_header($engagementId);

    $opinionType = (string)($payload['opinion_type'] ?? 'unmodified');
    $basis       = trim((string)($payload['basis'] ?? ''));
    $includeKam  = !empty($payload['include_kam']);
    $reportDate  = trim((string)($payload['report_date'] ?? ''));
    $firmName    = trim((string)($payload['firm_name'] ?? ''));
    $place       = trim((string)($payload['place'] ?? ''));

    $metrics = analytical_metrics($engagementId);
    $c = $metrics['cur'];

    $out = $header . "\n\nKEY FINANCIAL FIGURES (current year):\n"
        . "  Revenue:       " . number_format($c['revenue'], 2) . "\n"
        . "  Profit/(loss): " . number_format($c['profit'], 2) . "\n"
        . "  Total assets:  " . number_format($c['total_assets'], 2) . "\n"
        . "  Net assets:    " . number_format($c['net_assets'], 2) . "\n";

    // Going-concern signal from the ratios.
    $ratios = analytical_ratios($engagementId);
    $gcFlag = false;
    foreach ($ratios as $r) { if (!empty($r['concern'])) { $gcFlag = true; break; } }
    $out .= "  Going-concern indicators present: " . ($gcFlag ? "YES (ratios flagged)" : "no") . "\n";

    $mat = materiality_load($engagementId);
    if ($mat) {
        $out .= "  Planning materiality: " . number_format((float) $mat['planning_amount'], 2) . "\n";
    }

    $out .= "\nREPORT PARAMETERS:\n"
        . "  Opinion type: {$opinionType}\n"
        . ($basis !== '' ? "  Basis for modification: {$basis}\n" : '')
        . "  Include Key Audit Matters section: " . ($includeKam ? 'yes' : 'no') . "\n"
        . ($reportDate !== '' ? "  Report date: {$reportDate}\n" : '')
        . ($firmName !== '' ? "  Audit firm name: {$firmName}\n" : '')
        . ($place !== '' ? "  Place of signature: {$place}\n" : '');

    $out .= "\nDraft the complete Independent Auditor's Report now. ";
    if ($opinionType !== 'unmodified') {
        $out .= "This is a {$opinionType} opinion — word the Opinion and the Basis for "
              . ($opinionType === 'disclaimer' ? 'Disclaimer of' : ($opinionType === 'adverse' ? 'Adverse' : 'Qualified'))
              . " Opinion paragraphs accordingly, referencing the basis supplied. ";
    }
    if ($gcFlag) {
        $out .= "Include a 'Material Uncertainty Related to Going Concern' section given the flagged ratios. ";
    }
    $out .= "Mark it clearly as a DRAFT for partner review.";
    return $out;
}

// ---------------------------------------------------------------------
// Working paper review — single-WP focused
// ---------------------------------------------------------------------
function ai_payload_review_wp(int $workingPaperId): string
{
    if ($workingPaperId <= 0) return "No working paper specified.";
    $stmt = db()->prepare(
        'SELECT wp.*, asec.name AS section_name, asec.code AS section_code,
                e.financial_year, c.company_name
           FROM audit_working_papers wp
           JOIN engagements e ON e.id = wp.engagement_id
           JOIN clients c     ON c.id = e.client_id
           LEFT JOIN audit_sections asec ON asec.id = wp.section_id
          WHERE wp.id = :id'
    );
    $stmt->execute([':id' => $workingPaperId]);
    $wp = $stmt->fetch();
    if (!$wp) return "Working paper not found.";

    $notes = db()->prepare(
        'SELECT severity, status, note, response FROM audit_review_notes
          WHERE working_paper_id = :id ORDER BY created_at'
    );
    $notes->execute([':id' => $workingPaperId]);
    $noteRows = $notes->fetchAll();

    $out = "WORKING PAPER REVIEW\n";
    $out .= "  Client:      {$wp['company_name']} ({$wp['financial_year']})\n";
    $out .= "  Reference:   " . ($wp['reference_code'] ?? 'n/a') . "\n";
    $out .= "  Section:     " . ($wp['section_code'] ?? '') . ' ' . ($wp['section_name'] ?? '') . "\n";
    $out .= "  Title:       {$wp['title']}\n";
    $out .= "  Status:      " . str_replace('_',' ',$wp['status']) . "\n";
    if ($wp['risk_rating']) {
        $out .= "  Risk rating: {$wp['risk_rating']}\n";
    }

    $out .= "\nAUDIT PROCEDURE:\n" . ($wp['procedure'] ?: '(empty)') . "\n";
    $out .= "\nCONCLUSION:\n"      . ($wp['conclusion'] ?: '(empty)') . "\n";
    if (!empty($wp['notes'])) {
        $out .= "\nADDITIONAL NOTES:\n{$wp['notes']}\n";
    }
    if (!empty($noteRows)) {
        $out .= "\nEXISTING REVIEW NOTES:\n";
        foreach ($noteRows as $n) {
            $out .= "  [{$n['severity']}/{$n['status']}] {$n['note']}\n";
            if ($n['response']) $out .= "    response: {$n['response']}\n";
        }
    }

    $out .= "\nAct as an audit partner reviewing this paper. Identify:\n"
         . "  • Gaps in audit evidence or procedures missing for the assertions in scope\n"
         . "  • Weak or unsubstantiated conclusions\n"
         . "  • Risk areas not adequately addressed\n"
         . "  • Specific reviewer notes the preparer should action (be precise — quote the wording you'd raise)\n\n"
         . "Output: brief paragraph summary, then a list of new review notes in the format:\n"
         . "  [severity] note text\n"
         . "where severity is one of: info, minor, major, critical. Aim for actionable, specific, and proportionate.";
    return $out;
}
