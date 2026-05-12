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
 * Record an entry in the activity_logs table. Never throws.
 */
function log_activity(
    string $action,
    ?string $entityType = null,
    $entityId = null,
    ?string $description = null
): void {
    try {
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
