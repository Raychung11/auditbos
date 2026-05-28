<?php
/**
 * /ai/document_classify.php
 *
 * Service function for AI-assisted document classification.
 *
 *   ai_classify_document(int $documentId): array
 *
 * Loads an uploaded engagement_document, sends the file to Claude as
 * a document (PDF) or image content block alongside the engagement's
 * pending document_requests, asks Claude to pick the best matching
 * category and extract a short summary + key figures.
 *
 * Writes the structured result to ai_outputs and also surfaces a
 * one-line gist into engagement_documents.review_notes. Credits are
 * debited from credit_wallet via ai_debit_credits() — same accounting
 * as the regular ai_run() flow.
 */

declare(strict_types=1);

if (!defined('AUDITBOS_BOOTSTRAPPED')) {
    http_response_code(403); exit('Forbidden');
}

require_once __DIR__ . '/ai_service.php';   // pulls in pricing, AI_* constants, ai_debit_credits

/**
 * Run classification on one uploaded document.
 *
 * @return array{
 *   ok:bool, log_id:?int,
 *   output:string,
 *   suggested_request_id:?int, suggested_request_title:?string,
 *   confidence:?string, summary:?string,
 *   key_figures: array<int, array{label:string, value:string}>
 * }
 */
function ai_classify_document(int $documentId): array
{
    $pdo    = db();
    $firmId = function_exists('current_firm_id') ? current_firm_id() : null;
    $userId = function_exists('current_user_id') ? current_user_id() : null;

    // 1. Load doc + scope-check against the current firm.
    $stmt = $pdo->prepare(
        'SELECT ed.*, e.firm_id AS eng_firm_id, e.id AS engagement_id, c.company_name
           FROM engagement_documents ed
           JOIN engagements e ON e.id = ed.engagement_id
           JOIN clients c     ON c.id = e.client_id
          WHERE ed.id = :id'
    );
    $stmt->execute([':id' => $documentId]);
    $doc = $stmt->fetch();
    if (!$doc || ($firmId && (int) $doc['eng_firm_id'] !== $firmId)) {
        return ai_classify_fail(null, 'Document not found or out of scope.');
    }

    // 2. Validate the file: type + size + presence.
    $mime = (string) ($doc['mime_type'] ?? '');
    $supported = ['application/pdf', 'image/png', 'image/jpeg', 'image/gif', 'image/webp'];
    if (!in_array($mime, $supported, true)) {
        return ai_classify_fail(null,
            'Unsupported file type for classification (' . $mime . '). Supported: PDF, PNG, JPEG, GIF, WEBP.');
    }

    $maxBytes = 5 * 1024 * 1024;
    if ((int) $doc['file_size'] > $maxBytes) {
        return ai_classify_fail(null,
            'File too large for inline classification (' . human_filesize((int) $doc['file_size']) . '). Limit: 5 MB.');
    }

    $absPath = realpath(UPLOADS_PRIVATE . '/' . $doc['relative_path']);
    $rootReal = realpath(UPLOADS_PRIVATE);
    if (!$absPath || !$rootReal || !str_starts_with($absPath, $rootReal . DIRECTORY_SEPARATOR)) {
        return ai_classify_fail(null, 'File path resolution failed.');
    }
    if (!is_file($absPath) || !is_readable($absPath)) {
        return ai_classify_fail(null, 'File missing on disk.');
    }
    $bytes = file_get_contents($absPath);
    if ($bytes === false) {
        return ai_classify_fail(null, 'Could not read file.');
    }
    $b64 = base64_encode($bytes);

    // 3. Pull the engagement's open document_requests so Claude has a list to pick from.
    $reqStmt = $pdo->prepare(
        'SELECT dr.id, dr.title, dr.description, dc.name AS category_name
           FROM document_requests dr
           LEFT JOIN document_categories dc ON dc.id = dr.category_id
          WHERE dr.engagement_id = :e AND dr.status IN ("pending","needs_clarification","rejected")
          ORDER BY dc.sort_order, dr.id'
    );
    $reqStmt->execute([':e' => (int) $doc['engagement_id']]);
    $openRequests = $reqStmt->fetchAll();

    // 4. Build the prompt. Keep it tight — classification doesn't need a wall of text.
    $systemPrompt = 'You are an audit assistant. You receive a single client-uploaded document plus a list of '
        . 'open document requests for the engagement. Identify which request the document fulfils (if any), '
        . 'summarise the document in one sentence, and extract up to five quantitative key figures '
        . '(headline numbers, totals, dates) that an auditor would care about. Return strict JSON only — '
        . 'no preamble, no markdown fences. Use the schema described in the user message.';

    $reqList = '';
    if (!empty($openRequests)) {
        foreach ($openRequests as $r) {
            $reqList .= "  - id={$r['id']}  title=\"{$r['title']}\""
                . ($r['category_name'] ? " (category: {$r['category_name']})" : '') . "\n";
        }
    } else {
        $reqList = "  (none open)\n";
    }

    $userText = "Engagement: {$doc['company_name']}\n"
        . "Document uploaded: {$doc['original_filename']}\n\n"
        . "OPEN DOCUMENT REQUESTS for this engagement:\n{$reqList}\n"
        . "Classify the attached document and respond with this exact JSON shape:\n"
        . "{\n"
        . "  \"suggested_request_id\": <integer or null>,\n"
        . "  \"suggested_request_title\": <string or null>,\n"
        . "  \"confidence\": <\"high\"|\"medium\"|\"low\">,\n"
        . "  \"summary\": <one short sentence>,\n"
        . "  \"key_figures\": [\n"
        . "    { \"label\": <string>, \"value\": <string> }\n"
        . "  ]\n"
        . "}\n"
        . "If no open request matches, set suggested_request_id to null and explain in summary. "
        . "Return at most 5 key_figures. Strict JSON, no comments, no trailing commas.";

    // 5. Insert pending ai_logs row first so we always have an audit trail, even on failure.
    $pdo->prepare(
        'INSERT INTO ai_logs
            (firm_id, engagement_id, user_id, function_name, provider, model,
             prompt, input_payload, status)
         VALUES (:f, :e, :u, "ai_classify_document", :pr, :m, :p, :ip, "pending")'
    )->execute([
        ':f'  => $firmId,
        ':e'  => (int) $doc['engagement_id'],
        ':u'  => $userId,
        ':pr' => AI_PROVIDER,
        ':m'  => AI_MODEL,
        ':p'  => $systemPrompt,
        ':ip' => $userText,
    ]);
    $logId = (int) $pdo->lastInsertId();

    // 6. If AI isn't enabled, return a stub so the UI flow can be tested end-to-end.
    if (!AI_ENABLED) {
        $stubBody = ai_classify_stub_response($openRequests, $doc);
        $pdo->prepare(
            'UPDATE ai_logs SET output = :o, status = "success", credits_used = 0
              WHERE id = :id'
        )->execute([':o' => $stubBody, ':id' => $logId]);
        return ai_classify_persist($logId, $documentId, (int) $doc['engagement_id'],
            $firmId, $userId, $stubBody);
    }

    // 7. Real Claude call. Different content shape than ai_call_provider() (which is text-only).
    $contentBlocks = [];
    if (strpos($mime, 'image/') === 0) {
        $contentBlocks[] = [
            'type' => 'image',
            'source' => [
                'type'       => 'base64',
                'media_type' => $mime,
                'data'       => $b64,
            ],
        ];
    } else {
        // PDF
        $contentBlocks[] = [
            'type' => 'document',
            'source' => [
                'type'       => 'base64',
                'media_type' => $mime,
                'data'       => $b64,
            ],
        ];
    }
    $contentBlocks[] = ['type' => 'text', 'text' => $userText];

    $body = [
        'model'         => AI_MODEL,
        'max_tokens'    => 1024,
        'system'        => $systemPrompt,
        'messages'      => [
            ['role' => 'user', 'content' => $contentBlocks],
        ],
        'thinking'      => ['type' => 'adaptive'],
        'output_config' => ['effort' => 'medium'],   // classification doesn't need 'high'
    ];

    $startedAt = microtime(true);
    try {
        [$rawOutput, $usage] = ai_classify_call_anthropic($body);
    } catch (Throwable $e) {
        error_log('[AuditBOS] doc classify failed: ' . $e->getMessage());
        $pdo->prepare(
            'UPDATE ai_logs SET status = "failed", error_message = :m WHERE id = :id'
        )->execute([':m' => $e->getMessage(), ':id' => $logId]);
        return ai_classify_fail($logId, 'AI classification failed: ' . $e->getMessage());
    }
    $latencyMs = (int) ((microtime(true) - $startedAt) * 1000);

    $costUsd = (($usage['input_tokens'] ?? 0)  * AI_PRICE_INPUT_PER_1M  / 1_000_000)
             + (($usage['output_tokens'] ?? 0) * AI_PRICE_OUTPUT_PER_1M / 1_000_000);
    $credits = round($costUsd * AI_USD_TO_MYR * AI_CREDIT_MARKUP, 4);   // MYR

    $pdo->prepare(
        'UPDATE ai_logs SET output = :o, input_tokens = :it, output_tokens = :ot,
                            total_tokens = :tt, credits_used = :c, latency_ms = :lm,
                            status = "success"
                       WHERE id = :id'
    )->execute([
        ':o'  => $rawOutput,
        ':it' => $usage['input_tokens']  ?? null,
        ':ot' => $usage['output_tokens'] ?? null,
        ':tt' => ($usage['input_tokens'] ?? 0) + ($usage['output_tokens'] ?? 0),
        ':c'  => $credits,
        ':lm' => $latencyMs,
        ':id' => $logId,
    ]);

    if ($firmId && $credits > 0) {
        ai_debit_credits($firmId, $credits, 'ai_classify_document', $logId);
    }

    return ai_classify_persist($logId, $documentId, (int) $doc['engagement_id'],
        $firmId, $userId, $rawOutput);
}

/**
 * Minimal direct Anthropic call for document classification. Mirrors
 * ai_call_provider() but accepts a fully-formed request body so we can
 * include image/document content blocks.
 *
 * @return array{0: string, 1: array{input_tokens:int, output_tokens:int}}
 */
function ai_classify_call_anthropic(array $body): array
{
    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER => [
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
        throw new RuntimeException("Claude API error: "
            . ($data['error']['message'] ?? "HTTP {$httpCode}"));
    }

    $text = '';
    foreach (($data['content'] ?? []) as $block) {
        if (($block['type'] ?? '') === 'text' && isset($block['text'])) {
            $text .= $block['text'];
        }
    }
    if ($text === '') {
        $text = '{"summary":"","key_figures":[]}';
    }

    return [$text, [
        'input_tokens'  => (int) ($data['usage']['input_tokens']  ?? 0),
        'output_tokens' => (int) ($data['usage']['output_tokens'] ?? 0),
    ]];
}

/**
 * Persist the classification result into ai_outputs + update the
 * engagement_documents row's review_notes summary line. Returns the
 * parsed result for the caller.
 */
function ai_classify_persist(int $logId, int $documentId, int $engagementId,
    ?int $firmId, ?int $userId, string $rawOutput): array
{
    $parsed = ai_classify_extract_json($rawOutput);

    db()->prepare(
        'INSERT INTO ai_outputs
            (ai_log_id, firm_id, engagement_id, entity_type, entity_id,
             output_type, title, content, status, created_by)
         VALUES (:l, :f, :e, "engagement_document", :ei,
                 "document_classification", :t, :c, "draft", :u)'
    )->execute([
        ':l'  => $logId,
        ':f'  => $firmId,
        ':e'  => $engagementId,
        ':ei' => $documentId,
        ':t'  => 'Document classification',
        ':c'  => $rawOutput,
        ':u'  => $userId,
    ]);

    // Short summary into engagement_documents.review_notes so the table
    // view shows something useful without round-tripping to ai_outputs.
    $summary = trim((string) ($parsed['summary'] ?? ''));
    $confidence = strtolower((string) ($parsed['confidence'] ?? ''));
    $suggestedTitle = trim((string) ($parsed['suggested_request_title'] ?? ''));
    $noteLine = trim(sprintf('[AI %s] %s%s',
        $confidence ?: '—',
        $suggestedTitle ? "→ {$suggestedTitle}. " : '',
        $summary
    ));
    if ($noteLine !== '[AI ] ') {
        db()->prepare(
            'UPDATE engagement_documents SET review_notes = :n WHERE id = :id'
        )->execute([':n' => $noteLine, ':id' => $documentId]);
    }

    log_activity('ai_classify.run', 'engagement_document', $documentId,
        $suggestedTitle ?: 'Classification complete');

    return [
        'ok'     => true,
        'log_id' => $logId,
        'output' => $rawOutput,
        'suggested_request_id'    => isset($parsed['suggested_request_id'])
            && is_numeric($parsed['suggested_request_id'])
            ? (int) $parsed['suggested_request_id'] : null,
        'suggested_request_title' => $suggestedTitle ?: null,
        'confidence'              => $confidence ?: null,
        'summary'                 => $summary ?: null,
        'key_figures'             => is_array($parsed['key_figures'] ?? null)
            ? array_slice($parsed['key_figures'], 0, 5) : [],
    ];
}

/**
 * Best-effort JSON extraction from Claude's output. Handles both pure
 * JSON and JSON wrapped in markdown fences (Claude is good but mortals
 * sometimes wrap).
 */
function ai_classify_extract_json(string $raw): array
{
    $s = trim($raw);
    // Strip ```json ... ``` fences if present.
    if (preg_match('/^```(?:json)?\s*(.*?)\s*```$/s', $s, $m)) {
        $s = trim($m[1]);
    }
    $decoded = json_decode($s, true);
    if (is_array($decoded)) {
        return $decoded;
    }
    // Fall back: try to find the first {...} block.
    if (preg_match('/\{.*\}/s', $s, $m)) {
        $decoded = json_decode($m[0], true);
        if (is_array($decoded)) return $decoded;
    }
    return [];
}

/**
 * Stub output for when AI_ENABLED is false — the UI flow still works.
 */
function ai_classify_stub_response(array $openRequests, array $doc): string
{
    $first = $openRequests[0] ?? null;
    return json_encode([
        'suggested_request_id'    => $first ? (int) $first['id'] : null,
        'suggested_request_title' => $first ? $first['title'] : null,
        'confidence'              => 'low',
        'summary'                 => '[Stub mode] Set AI_API_KEY in db_config.local.php to enable real classification of ' . $doc['original_filename'],
        'key_figures'             => [],
    ], JSON_PRETTY_PRINT);
}

/**
 * Build a uniform failure return shape.
 */
function ai_classify_fail(?int $logId, string $message): array
{
    return [
        'ok'                       => false,
        'log_id'                   => $logId,
        'output'                   => $message,
        'suggested_request_id'     => null,
        'suggested_request_title'  => null,
        'confidence'               => null,
        'summary'                  => $message,
        'key_figures'              => [],
    ];
}
