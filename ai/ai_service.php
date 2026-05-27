<?php
/**
 * /ai/ai_service.php
 *
 * Server-side AI service layer.
 *
 *   ai_run(fn, payload, engagementId)
 *     ├── build user message from engagement data  (ai/prompts.php)
 *     ├── insert pending ai_logs row
 *     ├── ai_call_provider() → POST /v1/messages
 *     ├── update ai_logs with tokens + latency
 *     ├── ai_debit_credits()                       (credit_wallet + tx)
 *     └── insert curated row in ai_outputs         (status="draft")
 *
 * Real Anthropic Claude calls go through cURL (no Composer dependency to
 * keep the project Hostinger-friendly). When AI_API_KEY is empty the
 * provider returns a deterministic stub so every screen still works
 * without keys.
 */

declare(strict_types=1);

if (!defined('AUDITBOS_BOOTSTRAPPED')) {
    http_response_code(403); exit('Forbidden');
}

require_once __DIR__ . '/../config/app_config.php';
require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/prompts.php';

// ---------------------------------------------------------------------
// Pricing. Anthropic publishes prices in USD per 1M tokens; firm wallets
// are denominated in MYR (per credit_wallet.currency default). We
// compute the raw USD cost from token usage, convert to MYR, then apply
// AI_CREDIT_MARKUP. Override the FX rate per environment via
// db_config.local.php to track the actual rate the firm pays.
// ---------------------------------------------------------------------
if (!defined('AI_PRICE_INPUT_PER_1M'))  define('AI_PRICE_INPUT_PER_1M',   5.00);   // USD
if (!defined('AI_PRICE_OUTPUT_PER_1M')) define('AI_PRICE_OUTPUT_PER_1M', 25.00);   // USD
if (!defined('AI_USD_TO_MYR'))          define('AI_USD_TO_MYR',           4.70);   // FX rate
if (!defined('AI_CREDIT_MARKUP'))       define('AI_CREDIT_MARKUP',        1.00);   // billable = MYR cost × markup
if (!defined('AI_MAX_TOKENS'))          define('AI_MAX_TOKENS',           8192);
if (!defined('AI_HTTP_TIMEOUT'))        define('AI_HTTP_TIMEOUT',         180);
if (!defined('AI_EFFORT'))              define('AI_EFFORT',               'high');

// ---------------------------------------------------------------------
// Public entrypoint used by callers.
// ---------------------------------------------------------------------

/**
 * Execute a registered AI function for the current user.
 *
 * @param string             $functionName   One of the ai_* keys below.
 * @param array<string,mixed> $payload       Caller-supplied extras
 *                                            (e.g. working_paper_id).
 * @param int|null            $engagementId  Engagement context (nullable).
 *
 * @return array{ok:bool, log_id:int, output:string, output_type:string, title:string}
 */
function ai_run(string $functionName, array $payload = [], ?int $engagementId = null): array
{
    $pdo    = db();
    $firmId = function_exists('current_firm_id') ? current_firm_id() : null;
    $userId = function_exists('current_user_id') ? current_user_id() : null;

    $registry = ai_registry();
    if (!isset($registry[$functionName])) {
        return ai_record_failure(null, $functionName, $firmId, $userId, $engagementId,
            'Unknown AI function');
    }

    $config = $registry[$functionName];
    $userMessage = ai_build_user_message($functionName, $engagementId, $payload);

    // Insert pending log row first so we can update it after.
    $pdo->prepare(
        'INSERT INTO ai_logs
            (firm_id, engagement_id, user_id, function_name, provider, model,
             prompt, input_payload, status)
         VALUES (:f, :e, :u, :fn, :pr, :m, :p, :ip, "pending")'
    )->execute([
        ':f'  => $firmId,
        ':e'  => $engagementId,
        ':u'  => $userId,
        ':fn' => $functionName,
        ':pr' => AI_PROVIDER,
        ':m'  => AI_MODEL,
        ':p'  => $config['prompt'] ?? null,
        ':ip' => $userMessage,
    ]);
    $logId = (int) $pdo->lastInsertId();

    $startedAt = microtime(true);
    try {
        $result = ai_call_provider($functionName, $config, $userMessage);
    } catch (Throwable $e) {
        error_log('[AuditBOS] AI call failed: ' . $e->getMessage());
        return ai_record_failure($logId, $functionName, $firmId, $userId, $engagementId,
            $e->getMessage());
    }
    $latencyMs = (int) ((microtime(true) - $startedAt) * 1000);

    // Update log row with success + tokens + cost.
    $pdo->prepare(
        'UPDATE ai_logs SET output = :o, input_tokens = :it, output_tokens = :ot,
                            total_tokens = :tt, credits_used = :c, latency_ms = :lm,
                            status = "success"
                       WHERE id = :id'
    )->execute([
        ':o'  => $result['output'],
        ':it' => $result['input_tokens']  ?? null,
        ':ot' => $result['output_tokens'] ?? null,
        ':tt' => $result['total_tokens']  ?? null,
        ':c'  => $result['credits']       ?? 0.0,
        ':lm' => $latencyMs,
        ':id' => $logId,
    ]);

    // Debit credits + record a wallet transaction (best-effort).
    if ($firmId && ($result['credits'] ?? 0.0) > 0) {
        ai_debit_credits($firmId, (float) ($result['credits']), $functionName, $logId);
    }

    // Surface the output as a curated row.
    $title = $config['title'] ?? ucwords(str_replace('_', ' ', $functionName));
    $entityId = $payload['entity_id']
        ?? $payload['working_paper_id']
        ?? $engagementId;
    $pdo->prepare(
        'INSERT INTO ai_outputs (ai_log_id, firm_id, engagement_id, entity_type, entity_id,
                                 output_type, title, content, status, created_by)
         VALUES (:l, :f, :e, :et, :ei, :ot, :t, :c, "draft", :u)'
    )->execute([
        ':l'  => $logId,
        ':f'  => $firmId,
        ':e'  => $engagementId,
        ':et' => $config['entity_type'] ?? 'engagement',
        ':ei' => $entityId,
        ':ot' => $config['output_type'] ?? $functionName,
        ':t'  => $title,
        ':c'  => $result['output'],
        ':u'  => $userId,
    ]);

    return [
        'ok'          => true,
        'log_id'      => $logId,
        'output'      => $result['output'],
        'output_type' => $config['output_type'] ?? $functionName,
        'title'       => $title,
        'tokens'      => [
            'input'  => $result['input_tokens']  ?? null,
            'output' => $result['output_tokens'] ?? null,
            'total'  => $result['total_tokens']  ?? null,
        ],
        'credits'     => $result['credits'] ?? 0.0,
        'latency_ms'  => $latencyMs,
    ];
}

// ---------------------------------------------------------------------
// Registry of AI functions exposed by the platform.
// Each entry: ['prompt' => system prompt, 'output_type', 'entity_type', 'title']
// ---------------------------------------------------------------------
function ai_registry(): array
{
    return [
        'ai_analyze_trial_balance' => [
            'prompt'      => 'You are an experienced audit senior performing analytical procedures on a client trial balance. Your job is to surface unusual movements, negative balances on accounts that should never be negative, weak gross margins, unusual expense ratios, accounts that are new or missing, related-party indicators, and going-concern flags. Be specific, cite account codes, and give a brief reason for each finding. Output a professional audit memorandum.',
            'output_type' => 'variance_analysis',
            'entity_type' => 'trial_balance',
            'title'       => 'Trial balance variance analysis',
        ],
        'ai_generate_audit_queries' => [
            'prompt'      => 'You are an audit manager drafting client queries. Write polite, professional questions the client can answer one at a time. Group queries (Documents / Variances / Going concern / Other), keep each query self-contained, and avoid audit jargon the client may not know.',
            'output_type' => 'audit_query',
            'entity_type' => 'engagement',
            'title'       => 'Draft audit queries',
        ],
        'ai_review_working_paper' => [
            'prompt'      => 'You are an audit partner reviewing a working paper. Identify gaps in audit evidence, weak conclusions, missing procedures, and propose reviewer notes. Be specific and proportionate — do not raise nits, but do not let weak conclusions slide. Output: short summary paragraph, then a list of [severity] notes.',
            'output_type' => 'wp_review',
            'entity_type' => 'audit_working_paper',
            'title'       => 'AI working paper review',
        ],
        'ai_generate_management_letter' => [
            'prompt'      => 'You are an audit partner drafting a management letter. For each finding, output a section structured as Observation / Risk / Recommendation / Management response (placeholder). Tone: professional, actionable, specific to the engagement.',
            'output_type' => 'management_letter',
            'entity_type' => 'engagement',
            'title'       => 'Management letter draft',
        ],
        'ai_generate_client_reminder' => [
            'prompt'      => 'You draft client communications for an audit firm. Produce two versions of a reminder for outstanding documents: a formal email and a short WhatsApp version. Tone: professional but warm. Always include a specific deadline.',
            'output_type' => 'client_reminder',
            'entity_type' => 'engagement',
            'title'       => 'Client reminder draft',
        ],
        'ai_detect_variance' => [
            'prompt'      => 'You are an audit analyst. Surface the most material variances year-on-year with brief, accurate explanations.',
            'output_type' => 'variance_detect',
            'entity_type' => 'engagement',
            'title'       => 'Variance detection',
        ],
        'ai_summarize_engagement_status' => [
            'prompt'      => 'You are an audit manager preparing a status briefing. Summarise overall completion, high-risk areas, blockers, unresolved review notes, and recommended next actions. Be concise — partners read these in 30 seconds.',
            'output_type' => 'engagement_summary',
            'entity_type' => 'engagement',
            'title'       => 'Engagement status summary',
        ],
        'ai_partner_review_assistant' => [
            'prompt'      => 'You are an audit partner preparing for sign-off review. Produce a partner review pack: high-risk areas to challenge, key audit conclusions, areas needing further evidence, and recommended sign-off conditions or matters for further consideration.',
            'output_type' => 'partner_review',
            'entity_type' => 'engagement',
            'title'       => 'Partner review pack',
        ],
        'ai_analyze_gl_exceptions' => [
            'prompt'      => 'You are a forensic-minded audit senior reviewing general-ledger analytics. You receive the output of automated exception tests (potential duplicate payments, round-number postings, weekend postings, largest transactions, account concentration, and a Benford first-digit analysis). Identify which exceptions warrant investigation and why, propose specific follow-up procedures, and call out any patterns that could indicate error or fraud. Be proportionate — do not cry wolf on immaterial items. Output a short memo grouped by risk level (High / Medium / Low).',
            'output_type' => 'gl_exceptions',
            'entity_type' => 'engagement',
            'title'       => 'GL exception analysis',
        ],
        'ai_going_concern_assessment' => [
            'prompt'      => 'You are an audit manager performing a going-concern assessment. You receive the client\'s key financial-health ratios (current vs prior) and the materiality assessment. Evaluate whether there are events or conditions that may cast significant doubt on the entity\'s ability to continue as a going concern. Reference the specific ratios. Conclude with one of: (a) no material uncertainty, (b) material uncertainty exists — disclosure needed, (c) going-concern basis inappropriate. List the audit procedures you would perform and any management representations to obtain.',
            'output_type' => 'going_concern',
            'entity_type' => 'engagement',
            'title'       => 'Going-concern assessment',
        ],
        'ai_generate_audit_report' => [
            'prompt'      => 'You are an audit partner drafting the Independent Auditor\'s Report for a Malaysian private company audit. Follow ISA 700 (Revised) as adopted in Malaysia, the requirements of the Companies Act 2016, and the MIA By-Laws. Produce a complete, properly-structured report with these sections as applicable: title ("Independent Auditor\'s Report to the Members of <company>"); "Report on the Audit of the Financial Statements" containing Opinion, Basis for Opinion, Material Uncertainty Related to Going Concern (only if flagged), Key Audit Matters (only if requested), Information Other than the Financial Statements, Responsibilities of the Directors for the Financial Statements, and Auditor\'s Responsibilities for the Audit of the Financial Statements; then "Report on Other Legal and Regulatory Requirements" (Companies Act 2016); and a signature block. Word the Opinion and Basis paragraphs correctly for the opinion type supplied (unmodified / qualified / adverse / disclaimer). Use placeholders like [AF: 0000], [Membership No.], [Place] where firm-specific details are unknown. This is a DRAFT for partner review — note that at the top.',
            'output_type' => 'audit_report',
            'entity_type' => 'engagement',
            'title'       => 'Independent auditor\'s report (draft)',
        ],
        'ai_analyze_aging' => [
            'prompt'      => 'You are an audit senior reviewing an aging analysis. For a DEBTOR (receivables) aging, focus on recoverability and expected credit losses (ECL) — flag balances that likely need provision, assess whether the provision appears adequate, and propose recovery/confirmation procedures. For a CREDITOR (payables) aging, focus on long-outstanding or disputed balances, completeness of liabilities, and possible unrecorded liabilities. Be specific about the parties and amounts. Output a short memo with findings grouped by risk and recommended procedures.',
            'output_type' => 'aging_review',
            'entity_type' => 'engagement',
            'title'       => 'Aging analysis review',
        ],
    ];
}

// ---------------------------------------------------------------------
// Provider call: Anthropic Claude via cURL.
// Returns array{output, input_tokens?, output_tokens?, total_tokens?, credits?}
// ---------------------------------------------------------------------
function ai_call_provider(string $functionName, array $config, string $userMessage): array
{
    if (!AI_ENABLED) {
        // Stub mode — usable without keys; uses the structured user
        // message we built so partners can see what would be sent.
        $sample = "[STUB] " . ($config['title'] ?? $functionName) . "\n\n"
            . "AI provider is not configured. The user message built for this call:\n"
            . str_repeat('-', 60) . "\n"
            . $userMessage . "\n"
            . str_repeat('-', 60) . "\n\n"
            . "Set AI_API_KEY in /config/db_config.local.php to enable real Claude calls.";
        return ['output' => $sample, 'credits' => 0.0,
                'input_tokens' => null, 'output_tokens' => null, 'total_tokens' => null];
    }

    $systemPrompt = $config['prompt'] ?? '';

    $body = [
        'model'         => AI_MODEL,
        'max_tokens'    => (int) AI_MAX_TOKENS,
        'system'        => $systemPrompt,
        'messages'      => [
            ['role' => 'user', 'content' => $userMessage],
        ],
        // Opus 4.7 supports adaptive thinking only. Effort tunes depth.
        'thinking'      => ['type' => 'adaptive'],
        'output_config' => ['effort' => AI_EFFORT],
    ];

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER     => [
            'x-api-key: ' . AI_API_KEY,
            'anthropic-version: 2023-06-01',
            'content-type: application/json',
        ],
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => (int) AI_HTTP_TIMEOUT,
        CURLOPT_CONNECTTIMEOUT => 15,
    ]);

    $raw       = curl_exec($ch);
    $httpCode  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($raw === false) {
        throw new RuntimeException("Claude API call failed (cURL): {$curlError}");
    }
    $data = json_decode((string) $raw, true);
    if (!is_array($data)) {
        throw new RuntimeException("Invalid JSON from Claude API (HTTP {$httpCode})");
    }
    if ($httpCode >= 400) {
        $errMsg = $data['error']['message']
            ?? $data['error']['type']
            ?? "HTTP {$httpCode}";
        throw new RuntimeException("Claude API error: {$errMsg}");
    }

    // Extract text from content blocks. Thinking blocks may precede text;
    // we skip them — they're for the model's reasoning, not the user.
    $outputText = '';
    foreach (($data['content'] ?? []) as $block) {
        if (($block['type'] ?? '') === 'text' && isset($block['text'])) {
            $outputText .= $block['text'];
        }
    }
    if ($outputText === '') {
        // Surface stop reason for diagnosis (refusal, max_tokens, etc.)
        $stopReason = $data['stop_reason'] ?? 'unknown';
        $outputText = "[Claude returned no text output. stop_reason={$stopReason}]";
    }

    $inputTokens  = (int) ($data['usage']['input_tokens']  ?? 0);
    $outputTokens = (int) ($data['usage']['output_tokens'] ?? 0);
    $totalTokens  = $inputTokens + $outputTokens;

    $costUsd = ($inputTokens  * AI_PRICE_INPUT_PER_1M  / 1_000_000)
             + ($outputTokens * AI_PRICE_OUTPUT_PER_1M / 1_000_000);
    $credits = round($costUsd * AI_USD_TO_MYR * AI_CREDIT_MARKUP, 4);   // MYR

    return [
        'output'        => $outputText,
        'input_tokens'  => $inputTokens,
        'output_tokens' => $outputTokens,
        'total_tokens'  => $totalTokens,
        'credits'       => $credits,
    ];
}

// ---------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------

function ai_record_failure(?int $logId, string $functionName, ?int $firmId,
    ?int $userId, ?int $engagementId, string $message): array
{
    $pdo = db();
    if ($logId) {
        $pdo->prepare(
            'UPDATE ai_logs SET status = "failed", error_message = :m WHERE id = :id'
        )->execute([':m' => $message, ':id' => $logId]);
    } else {
        $pdo->prepare(
            'INSERT INTO ai_logs (firm_id, engagement_id, user_id, function_name,
                                  provider, model, status, error_message)
             VALUES (:f, :e, :u, :fn, :pr, :m, "failed", :err)'
        )->execute([
            ':f'  => $firmId,
            ':e'  => $engagementId,
            ':u'  => $userId,
            ':fn' => $functionName,
            ':pr' => AI_PROVIDER,
            ':m'  => AI_MODEL,
            ':err'=> $message,
        ]);
        $logId = (int) $pdo->lastInsertId();
    }

    return [
        'ok'          => false,
        'log_id'      => $logId,
        'output'      => 'AI request failed: ' . $message,
        'output_type' => $functionName,
        'title'       => 'AI error',
    ];
}

function ai_debit_credits(int $firmId, float $amount, string $functionName, int $logId): void
{
    $pdo = db();
    try {
        $pdo->beginTransaction();
        $pdo->prepare(
            'INSERT IGNORE INTO credit_wallet (firm_id) VALUES (:f)'
        )->execute([':f' => $firmId]);

        $stmt = $pdo->prepare(
            'SELECT id, balance FROM credit_wallet WHERE firm_id = :f FOR UPDATE'
        );
        $stmt->execute([':f' => $firmId]);
        $wallet = $stmt->fetch();
        if (!$wallet) {
            $pdo->rollBack();
            return;
        }
        $newBalance = (float) $wallet['balance'] - $amount;
        $pdo->prepare(
            'UPDATE credit_wallet
                SET balance = :b, total_used = total_used + :a
              WHERE id = :id'
        )->execute([':b'=>$newBalance, ':a'=>$amount, ':id'=>$wallet['id']]);

        $pdo->prepare(
            'INSERT INTO credit_transactions
                (firm_id, direction, amount, balance_after, usage_type,
                 reference_type, reference_id, notes)
             VALUES (:f, "debit", :a, :ba, :ut, "ai_log", :rid, :n)'
        )->execute([
            ':f'=>$firmId, ':a'=>$amount, ':ba'=>$newBalance,
            ':ut'=>$functionName, ':rid'=>$logId,
            ':n'=>"AI call: {$functionName}",
        ]);

        $pdo->prepare('UPDATE firms SET credit_balance = :b WHERE id = :id')
            ->execute([':b'=>$newBalance, ':id'=>$firmId]);

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('[AuditBOS] credit debit failed: ' . $e->getMessage());
    }
}
