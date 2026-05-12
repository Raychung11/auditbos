<?php
/**
 * /ai/ai_service.php
 *
 * Server-side AI service layer.
 *
 * Why this exists:
 *   - The spec demands a "reusable AI service" rather than scattering
 *     vendor calls across UI files.
 *   - Every AI request gets logged to `ai_logs` (prompt, output, tokens,
 *     credits) BEFORE we expose anything to the UI.
 *   - Curated outputs land in `ai_outputs` so partners can review/accept
 *     drafts (variance analysis, audit queries, management letter, etc).
 *
 * The actual model call is delegated to ai_call_provider(); for now it
 * returns a deterministic stub so the app is usable without an API key.
 * Wire a real provider by setting AI_API_KEY in db_config.local.php and
 * replacing the stub body — every caller (and the audit log) keeps working.
 */

declare(strict_types=1);

if (!defined('AUDITBOS_BOOTSTRAPPED')) {
    http_response_code(403); exit('Forbidden');
}

require_once __DIR__ . '/../config/app_config.php';
require_once __DIR__ . '/../config/db_config.php';

// ---------------------------------------------------------------------
// Public entrypoint used by callers.
// ---------------------------------------------------------------------

/**
 * Execute a registered AI function for the current user.
 *
 * @param string             $functionName   One of the ai_* keys below.
 * @param array<string,mixed> $payload       Input data for the function.
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
        ':ip' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);
    $logId = (int) $pdo->lastInsertId();

    $startedAt = microtime(true);
    try {
        $result = ai_call_provider($functionName, $config, $payload);
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
        ':c'  => $result['credits']       ?? AI_CREDITS_PER_CALL,
        ':lm' => $latencyMs,
        ':id' => $logId,
    ]);

    // Debit credits + record a wallet transaction (best-effort).
    if ($firmId && ($result['credits'] ?? AI_CREDITS_PER_CALL) > 0) {
        ai_debit_credits($firmId, (float) ($result['credits'] ?? AI_CREDITS_PER_CALL),
            $functionName, $logId);
    }

    // Surface the output as a curated row.
    $title = $config['title'] ?? ucwords(str_replace('_', ' ', $functionName));
    $pdo->prepare(
        'INSERT INTO ai_outputs (ai_log_id, firm_id, engagement_id, entity_type, entity_id,
                                 output_type, title, content, status, created_by)
         VALUES (:l, :f, :e, :et, :ei, :ot, :t, :c, "draft", :u)'
    )->execute([
        ':l'  => $logId,
        ':f'  => $firmId,
        ':e'  => $engagementId,
        ':et' => $config['entity_type'] ?? 'engagement',
        ':ei' => $payload['entity_id'] ?? $engagementId,
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
    ];
}

// ---------------------------------------------------------------------
// Registry of AI functions exposed by the platform.
// Each entry: ['prompt' => system-style, 'output_type', 'entity_type', 'title']
// ---------------------------------------------------------------------
function ai_registry(): array
{
    return [
        'ai_analyze_trial_balance' => [
            'prompt'      => 'You are an experienced audit senior. Analyse the trial balance vs prior year. Flag unusual movements, negative balances, weak gross margins, unusual expenses, and potential going-concern indicators.',
            'output_type' => 'variance_analysis',
            'entity_type' => 'trial_balance',
            'title'       => 'Trial balance variance analysis',
        ],
        'ai_generate_audit_queries' => [
            'prompt'      => 'You are an audit manager. Draft polite, professional audit queries for the client based on the supplied data, one per item, each with a clear ask and supporting context.',
            'output_type' => 'audit_query',
            'entity_type' => 'engagement',
            'title'       => 'Draft audit queries',
        ],
        'ai_review_working_paper' => [
            'prompt'      => 'You are an audit partner reviewing a working paper. Identify gaps in audit evidence, weak conclusions, missing procedures, and propose reviewer notes.',
            'output_type' => 'wp_review',
            'entity_type' => 'audit_working_paper',
            'title'       => 'AI working paper review',
        ],
        'ai_generate_management_letter' => [
            'prompt'      => 'You are an audit partner. Draft a management letter section in the format: Observation / Risk / Recommendation / Management response (placeholder).',
            'output_type' => 'management_letter',
            'entity_type' => 'engagement',
            'title'       => 'Management letter draft',
        ],
        'ai_generate_client_reminder' => [
            'prompt'      => 'Write a friendly, concise reminder message to a client listing the outstanding documents and a clear deadline. Tone: professional but warm. Provide both an email version and a short WhatsApp version.',
            'output_type' => 'client_reminder',
            'entity_type' => 'engagement',
            'title'       => 'Client reminder draft',
        ],
        'ai_detect_variance' => [
            'prompt'      => 'You are an audit analyst. Compare two periods of figures and surface the most material variances with brief explanations.',
            'output_type' => 'variance_detect',
            'entity_type' => 'engagement',
            'title'       => 'Variance detection',
        ],
        'ai_summarize_engagement_status' => [
            'prompt'      => 'You are an audit manager. Summarise the engagement status: high-risk areas, missing evidence, unresolved review notes, major variances, pending client documents, and completion percentage.',
            'output_type' => 'engagement_summary',
            'entity_type' => 'engagement',
            'title'       => 'Engagement status summary',
        ],
        'ai_partner_review_assistant' => [
            'prompt'      => 'You are an audit partner. Produce a partner review pack: high-risk areas to challenge, key audit conclusions, areas needing further evidence, and recommended sign-off conditions.',
            'output_type' => 'partner_review',
            'entity_type' => 'engagement',
            'title'       => 'Partner review pack',
        ],
    ];
}

// ---------------------------------------------------------------------
// Provider call. STUBBED for now — wire a real model here later.
// ---------------------------------------------------------------------

/**
 * @return array{output:string, input_tokens?:int, output_tokens?:int, total_tokens?:int, credits?:float}
 */
function ai_call_provider(string $functionName, array $config, array $payload): array
{
    if (!AI_ENABLED) {
        // Deterministic stub so the UI is usable without keys.
        $sample = "[AI " . $functionName . " — stub output]\n\n"
            . "This is a placeholder output. To enable real AI generation:\n"
            . "  1. Set AI_API_KEY in /config/db_config.local.php\n"
            . "  2. Edit ai_call_provider() in /ai/ai_service.php to call your provider.\n\n"
            . "Input payload received:\n"
            . substr(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}', 0, 800);
        return ['output' => $sample, 'credits' => 0.0];
    }

    // TODO: replace this branch with a real provider call.
    // Example (Anthropic) — sketch only, kept out of execution to avoid
    // an outbound dependency in this initial commit:
    //
    //   $ch = curl_init('https://api.anthropic.com/v1/messages');
    //   curl_setopt_array($ch, [
    //       CURLOPT_HTTPHEADER => [
    //           'x-api-key: ' . AI_API_KEY,
    //           'anthropic-version: 2023-06-01',
    //           'content-type: application/json',
    //       ],
    //       CURLOPT_POST       => true,
    //       CURLOPT_POSTFIELDS => json_encode([...]),
    //       CURLOPT_RETURNTRANSFER => true,
    //       CURLOPT_TIMEOUT    => 60,
    //   ]);
    //   ...parse output & token usage...
    //
    // Until then we still return a structured response so callers don't
    // break when AI_ENABLED is true but no integration is wired.

    return [
        'output' => "[AI provider not yet wired] Function: {$functionName}",
        'credits' => 0.0,
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
        // Ensure wallet exists
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

        // Mirror balance on firms row for cheap dashboard reads.
        $pdo->prepare('UPDATE firms SET credit_balance = :b WHERE id = :id')
            ->execute([':b'=>$newBalance, ':id'=>$firmId]);

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('[AuditBOS] credit debit failed: ' . $e->getMessage());
    }
}
