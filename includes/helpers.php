<?php
/**
 * /includes/helpers.php
 *
 * Pure, cheap utility functions used everywhere.
 * Loaded by /includes/auth_guard.php — do not include directly from pages.
 */

declare(strict_types=1);

if (!defined('AUDITBOS_BOOTSTRAPPED')) {
    http_response_code(403);
    exit('Forbidden');
}

/**
 * Does a table exist in the current database? Cached per request.
 * Used to fail gracefully when a migration hasn't been applied yet.
 */
function table_exists(string $name): bool
{
    static $cache = [];
    if (array_key_exists($name, $cache)) {
        return $cache[$name];
    }
    try {
        $stmt = db()->prepare('SHOW TABLES LIKE :n');
        $stmt->execute([':n' => $name]);
        return $cache[$name] = (bool) $stmt->fetchColumn();
    } catch (Throwable $e) {
        return $cache[$name] = false;
    }
}

/**
 * HTML-escape a value for safe inline output.
 */
function e($value): string
{
    if ($value === null) {
        return '';
    }
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Redirect helper. Always exits afterwards.
 */
function redirect(string $path): void
{
    // Strip any control chars to avoid header-injection.
    $path = preg_replace('/[\r\n\0]+/', '', $path) ?? '/';
    header('Location: ' . $path, true, 302);
    exit;
}

/**
 * Flash a one-shot message into the session, surfaced by the next page.
 *
 * @param string $type  success|error|warning|info
 */
function flash(string $type, string $message): void
{
    if (!isset($_SESSION['_flash']) || !is_array($_SESSION['_flash'])) {
        $_SESSION['_flash'] = [];
    }
    $_SESSION['_flash'][] = ['type' => $type, 'message' => $message];
}

/**
 * Pull and clear flash messages.
 *
 * @return array<int, array{type:string, message:string}>
 */
function take_flashes(): array
{
    $messages = $_SESSION['_flash'] ?? [];
    unset($_SESSION['_flash']);
    return is_array($messages) ? $messages : [];
}

/**
 * Format money for display (defaults to MYR).
 */
function money(?float $amount, string $currency = 'MYR'): string
{
    if ($amount === null) {
        return '-';
    }
    return $currency . ' ' . number_format($amount, 2);
}

/**
 * Format a datetime string for the UI.
 */
function datefmt(?string $datetime, string $format = 'd M Y'): string
{
    if (!$datetime) {
        return '-';
    }
    $ts = strtotime($datetime);
    return $ts ? date($format, $ts) : '-';
}

/**
 * Generate a cryptographically-random filename (preserves extension).
 */
function random_filename(string $originalName): string
{
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $ext = preg_replace('/[^a-z0-9]/', '', $ext) ?: 'bin';
    return bin2hex(random_bytes(16)) . '.' . $ext;
}

/**
 * Compute a human-readable file size.
 */
function human_filesize(int $bytes): string
{
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = 0;
    $size = (float) $bytes;
    while ($size >= 1024 && $i < count($units) - 1) {
        $size /= 1024;
        $i++;
    }
    return sprintf('%.1f %s', $size, $units[$i]);
}

/**
 * Build an absolute URL from a path. Honours APP_BASE_URL if configured,
 * otherwise reconstructs from request headers.
 */
function absolute_url(string $path): string
{
    $path = '/' . ltrim($path, '/');
    if (defined('APP_BASE_URL') && APP_BASE_URL !== '') {
        return rtrim(APP_BASE_URL, '/') . $path;
    }
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return "{$scheme}://{$host}{$path}";
}

/**
 * Generate a cryptographically random token (URL-safe).
 */
function random_token(int $bytes = 32): string
{
    return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
}

/**
 * Send an email if MAIL_ENABLED, otherwise return the message body so the
 * caller can surface a manual-relay link in the UI.
 *
 * @return array{sent:bool, error?:string}
 */
function send_email(string $to, string $subject, string $body): array
{
    if (!MAIL_ENABLED) {
        return ['sent' => false, 'error' => 'mail_disabled'];
    }
    $headers = [
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'From: ' . sprintf('%s <%s>', MAIL_FROM_NAME, MAIL_FROM),
    ];
    $ok = @mail($to, $subject, $body, implode("\r\n", $headers));
    return $ok ? ['sent' => true] : ['sent' => false, 'error' => 'mail_failed'];
}

/**
 * Record an entry in the activity_logs table. Never throws.
 */
function log_activity(
    string $action,
    ?string $entityType = null,
    $entityId = null,
    ?string $description = null
): void {
    try {
        // When a super admin is impersonating a firm user, tag the
        // description so the audit trail records who actually performed
        // the action. user_id stays as the impersonated user — actions
        // belong to the firm context, not to the platform admin.
        if (function_exists('is_impersonating') && is_impersonating()) {
            $real = real_user();
            $tag = '[impersonated by ' . ($real['name'] ?? 'super_admin')
                 . ' #' . ($real['id'] ?? '?') . '] ';
            $description = $tag . ($description ?? '');
        }

        $stmt = db()->prepare(
            'INSERT INTO activity_logs
                (firm_id, user_id, action, entity_type, entity_id, description, ip_address, user_agent)
             VALUES (:firm_id, :user_id, :action, :etype, :eid, :desc, :ip, :ua)'
        );
        $stmt->execute([
            ':firm_id' => current_firm_id(),
            ':user_id' => current_user_id(),
            ':action'  => $action,
            ':etype'   => $entityType,
            ':eid'     => $entityId,
            ':desc'    => $description,
            ':ip'      => $_SERVER['REMOTE_ADDR'] ?? null,
            ':ua'      => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
        ]);
    } catch (Throwable $e) {
        error_log('[AuditBOS] activity log failed: ' . $e->getMessage());
    }
}

/**
 * Status badge class lookup for Tailwind chip rendering.
 */
function badge_classes(string $status): string
{
    $map = [
        // engagements
        'draft'                => 'bg-slate-100 text-slate-700',
        'pending_documents'    => 'bg-amber-100 text-amber-800',
        'in_progress'          => 'bg-blue-100 text-blue-800',
        'under_review'         => 'bg-indigo-100 text-indigo-800',
        'partner_review'       => 'bg-purple-100 text-purple-800',
        'completed'            => 'bg-emerald-100 text-emerald-800',
        'billed'               => 'bg-teal-100 text-teal-800',
        'archived'             => 'bg-slate-100 text-slate-500',
        // documents
        'pending'              => 'bg-amber-100 text-amber-800',
        'received'             => 'bg-emerald-100 text-emerald-800',
        'rejected'             => 'bg-rose-100 text-rose-800',
        'needs_clarification'  => 'bg-orange-100 text-orange-800',
        'waived'               => 'bg-slate-100 text-slate-600',
        'uploaded'             => 'bg-blue-100 text-blue-800',
        'accepted'             => 'bg-emerald-100 text-emerald-800',
        // working papers
        'not_started'          => 'bg-slate-100 text-slate-700',
        'prepared'             => 'bg-blue-100 text-blue-800',
        'pending_review'       => 'bg-amber-100 text-amber-800',
        'review_note_raised'   => 'bg-rose-100 text-rose-800',
        'cleared'              => 'bg-emerald-100 text-emerald-800',
        // risk
        'low'                  => 'bg-emerald-100 text-emerald-800',
        'medium'               => 'bg-amber-100 text-amber-800',
        'high'                 => 'bg-orange-100 text-orange-800',
        'critical'             => 'bg-rose-100 text-rose-800',
        // generic
        'active'               => 'bg-emerald-100 text-emerald-800',
        'inactive'             => 'bg-slate-100 text-slate-600',
        'suspended'            => 'bg-rose-100 text-rose-800',
    ];
    return $map[$status] ?? 'bg-slate-100 text-slate-700';
}

/**
 * Render a status badge.
 */
function badge(string $status): string
{
    $label = ucwords(str_replace('_', ' ', $status));
    return '<span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium '
        . badge_classes($status) . '">' . e($label) . '</span>';
}

/**
 * Minimal, defensive Markdown → HTML renderer for AI output.
 *
 * Handles: headings, bold/italic, inline code, fenced code blocks,
 * bullet lists (- or *) including nested via two-space indent, ordered
 * lists (1.), block quotes (>), and horizontal rules (---).
 *
 * Anything not matched is rendered as escaped plain text inside <p>.
 *
 * Why we don't use a library: AI outputs are tiny, we want zero deps,
 * and we want predictable, safe HTML — all inline content is escaped
 * before formatting markers are applied to inner HTML segments.
 */
function md_to_html(string $markdown): string
{
    if ($markdown === '') {
        return '';
    }
    $lines = preg_split("/\r\n|\r|\n/", $markdown);
    $html  = [];
    $inFence = false;
    $fenceBuf = [];
    $listStack = []; // entries are 'ul' or 'ol'
    $inBlockquote = false;
    $paraBuf = [];

    $closeLists = function () use (&$listStack, &$html) {
        while (!empty($listStack)) {
            $html[] = '</' . array_pop($listStack) . '>';
        }
    };
    $closeParagraph = function () use (&$paraBuf, &$html) {
        if (!empty($paraBuf)) {
            $html[] = '<p>' . md_inline(implode(' ', $paraBuf)) . '</p>';
            $paraBuf = [];
        }
    };
    $closeBlockquote = function () use (&$inBlockquote, &$html) {
        if ($inBlockquote) {
            $html[] = '</blockquote>';
            $inBlockquote = false;
        }
    };

    foreach ($lines as $raw) {
        $line = rtrim($raw, " \t");

        // Code fence (```)
        if (preg_match('/^```/', $line)) {
            if ($inFence) {
                $html[] = '<pre class="bg-slate-100 rounded p-3 text-xs font-mono overflow-x-auto whitespace-pre">'
                    . e(implode("\n", $fenceBuf)) . '</pre>';
                $fenceBuf = [];
                $inFence = false;
            } else {
                $closeParagraph();
                $closeLists();
                $closeBlockquote();
                $inFence = true;
            }
            continue;
        }
        if ($inFence) {
            $fenceBuf[] = $raw;
            continue;
        }

        // Blank line — closes current block (but not fence handled above)
        if ($line === '') {
            $closeParagraph();
            $closeLists();
            $closeBlockquote();
            continue;
        }

        // Horizontal rule
        if (preg_match('/^[-*_]{3,}\s*$/', $line)) {
            $closeParagraph(); $closeLists(); $closeBlockquote();
            $html[] = '<hr class="my-3 border-slate-200">';
            continue;
        }

        // Headings (# .. ######)
        if (preg_match('/^(#{1,6})\s+(.*)$/', $line, $m)) {
            $closeParagraph(); $closeLists(); $closeBlockquote();
            $level = strlen($m[1]);
            $size  = ['text-xl','text-lg','text-base','text-sm','text-sm','text-sm'][$level-1];
            $html[] = "<h{$level} class=\"font-semibold {$size} mt-3 mb-1\">"
                    . md_inline($m[2]) . "</h{$level}>";
            continue;
        }

        // Block quote
        if (preg_match('/^>\s?(.*)$/', $line, $m)) {
            $closeParagraph(); $closeLists();
            if (!$inBlockquote) {
                $html[] = '<blockquote class="border-l-4 border-slate-300 pl-3 my-2 text-slate-700">';
                $inBlockquote = true;
            }
            $html[] = '<p>' . md_inline($m[1]) . '</p>';
            continue;
        } else {
            $closeBlockquote();
        }

        // List items — supports two-space indent nesting
        if (preg_match('/^(\s*)([-*]|\d+\.)\s+(.*)$/', $line, $m)) {
            $closeParagraph();
            $depth   = (int) floor(strlen($m[1]) / 2);
            $kind    = preg_match('/^\d+\./', $m[2]) ? 'ol' : 'ul';
            $content = $m[3];

            // Close deeper lists than current depth
            while (count($listStack) > $depth + 1) {
                $html[] = '</' . array_pop($listStack) . '>';
            }
            // Open new list if depth increased
            while (count($listStack) <= $depth) {
                $cls = $kind === 'ol' ? 'list-decimal' : 'list-disc';
                $html[] = "<{$kind} class=\"{$cls} pl-5 my-1 space-y-0.5\">";
                $listStack[] = $kind;
            }
            $html[] = '<li>' . md_inline($content) . '</li>';
            continue;
        } else {
            // Non-list content closes any open lists.
            if (!empty($listStack)) { $closeLists(); }
        }

        // Default: accumulate paragraph
        $paraBuf[] = $line;
    }

    if ($inFence) {
        $html[] = '<pre class="bg-slate-100 rounded p-3 text-xs font-mono overflow-x-auto whitespace-pre">'
            . e(implode("\n", $fenceBuf)) . '</pre>';
    }
    $closeParagraph();
    $closeLists();
    $closeBlockquote();
    return implode("\n", $html);
}

/**
 * Apply inline markdown (bold/italic/code) to an already-trusted string.
 * Escapes HTML first, then re-injects safe inline tags via regex over
 * the escaped text — so user input cannot inject HTML.
 */
function md_inline(string $text): string
{
    $t = e($text);
    // `code` (inline)
    $t = preg_replace('/`([^`]+)`/', '<code class="px-1 py-0.5 bg-slate-100 rounded text-[0.85em]">$1</code>', $t) ?? $t;
    // **bold**
    $t = preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $t) ?? $t;
    // *italic* or _italic_ (avoid clobbering ** already handled)
    $t = preg_replace('/(?<![\*\w])\*([^*\n]+)\*(?!\w)/', '<em>$1</em>', $t) ?? $t;
    $t = preg_replace('/(?<![_\w])_([^_\n]+)_(?!\w)/', '<em>$1</em>', $t) ?? $t;
    return $t;
}
