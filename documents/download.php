<?php
/**
 * /documents/download.php
 *
 * Secure download endpoint. Files live under /uploads_private which is
 * web-blocked; this script is the only way to retrieve them and enforces:
 *   - Login
 *   - Engagement scope (firm_id for staff, client_id for client users)
 *   - Safe path containment (no traversal)
 *   - Content-Disposition uses original filename
 */

declare(strict_types=1);
require_once __DIR__ . '/../includes/auth_guard.php';
require_role(['firm_admin','audit_manager','senior_auditor','junior_auditor','reviewer','client_user']);

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($id <= 0) {
    http_response_code(404); exit('Not found');
}

$pdo  = db();
$role = current_role();
$user = current_user();

// Resolve doc + scope-check in one query.
if ($role === 'client_user') {
    $stmt = $pdo->prepare(
        'SELECT ed.*, e.firm_id, e.client_id
           FROM engagement_documents ed
           JOIN engagements e ON e.id = ed.engagement_id
          WHERE ed.id = :id AND e.client_id = :cid'
    );
    $stmt->execute([':id'=>$id, ':cid'=>$user['client_id'] ?? 0]);
} else {
    $stmt = $pdo->prepare(
        'SELECT ed.*, e.firm_id, e.client_id
           FROM engagement_documents ed
           JOIN engagements e ON e.id = ed.engagement_id
          WHERE ed.id = :id AND e.firm_id = :fid'
    );
    $stmt->execute([':id'=>$id, ':fid'=>current_firm_id()]);
}
$doc = $stmt->fetch();
if (!$doc || $doc['status'] === 'archived') {
    http_response_code(404); exit('Not found');
}

// Build the absolute path AND verify it remains inside UPLOADS_PRIVATE
// (defence against any rogue relative_path values).
$absPath = realpath(UPLOADS_PRIVATE . '/' . $doc['relative_path']);
$rootReal = realpath(UPLOADS_PRIVATE);
if (!$absPath || !$rootReal || !str_starts_with($absPath, $rootReal . DIRECTORY_SEPARATOR)) {
    error_log('[AuditBOS] download path traversal blocked: ' . $doc['relative_path']);
    http_response_code(404); exit('Not found');
}
if (!is_file($absPath)) {
    http_response_code(410); exit('File missing on disk');
}

log_activity('document.download', 'engagement_document', (int) $doc['id'], $doc['original_filename']);

// Stream the file.
header('Content-Type: ' . ($doc['mime_type'] ?: 'application/octet-stream'));
header('Content-Length: ' . filesize($absPath));
header('Content-Disposition: attachment; filename="'
    . addslashes(basename((string) $doc['original_filename'])) . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=0, must-revalidate');

readfile($absPath);
exit;
