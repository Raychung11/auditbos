<?php
/**
 * /documents/upload.php
 *
 * Handles file uploads from /documents/index.php. Strict validation:
 *   - CSRF token
 *   - Engagement must be visible to current user
 *   - Extension + MIME allow-list
 *   - File-size cap
 *   - Stored under /uploads_private/{firm_id}/{engagement_id}/ with random name
 *   - If linked to a document_request, marks that request as "received"
 */

declare(strict_types=1);
require_once __DIR__ . '/../includes/auth_guard.php';
require_role(['firm_admin','audit_manager','senior_auditor','junior_auditor','reviewer','client_user']);
require_once __DIR__ . '/../includes/workflow.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/documents/index.php');
}
csrf_check();

$pdo  = db();
$role = current_role();
$user = current_user();

$engagementId = (int)($_POST['engagement_id'] ?? 0);
$requestId    = (int)($_POST['document_request_id'] ?? 0) ?: null;

if ($engagementId <= 0 || empty($_FILES['file']) || $_FILES['file']['error'] === UPLOAD_ERR_NO_FILE) {
    flash('error', 'No file selected.');
    redirect('/documents/index.php?engagement_id=' . $engagementId);
}

// No uploads once the engagement is locked.
if (engagement_locked($engagementId)) {
    flash('error', 'This engagement is locked. No further uploads allowed.');
    redirect('/documents/index.php?engagement_id=' . $engagementId);
}

// ---------------------------------------------------------------------
// Scope-check engagement.
// ---------------------------------------------------------------------
if ($role === 'client_user') {
    if (empty($user['client_id'])) {
        flash('error', 'No client linked.');
        redirect('/documents/index.php');
    }
    $stmt = $pdo->prepare(
        'SELECT e.id, e.firm_id FROM engagements e
          WHERE e.id = :id AND e.client_id = :cid'
    );
    $stmt->execute([':id'=>$engagementId, ':cid'=>$user['client_id']]);
} else {
    $stmt = $pdo->prepare(
        'SELECT e.id, e.firm_id FROM engagements e
          WHERE e.id = :id AND e.firm_id = :fid'
    );
    $stmt->execute([':id'=>$engagementId, ':fid'=>current_firm_id()]);
}
$eng = $stmt->fetch();
if (!$eng) {
    flash('error', 'Engagement not found.');
    redirect('/documents/index.php');
}
$firmIdForFile = (int) $eng['firm_id'];

// ---------------------------------------------------------------------
// PHP upload-error handling
// ---------------------------------------------------------------------
$file = $_FILES['file'];
if ($file['error'] !== UPLOAD_ERR_OK) {
    $map = [
        UPLOAD_ERR_INI_SIZE   => 'File exceeds server upload size limit.',
        UPLOAD_ERR_FORM_SIZE  => 'File exceeds form size limit.',
        UPLOAD_ERR_PARTIAL    => 'File upload was incomplete.',
        UPLOAD_ERR_NO_TMP_DIR => 'Server temp directory missing.',
        UPLOAD_ERR_CANT_WRITE => 'Could not write file.',
        UPLOAD_ERR_EXTENSION  => 'Upload blocked by server extension.',
    ];
    flash('error', $map[$file['error']] ?? 'File upload failed.');
    redirect('/documents/index.php?engagement_id=' . $engagementId);
}

if ($file['size'] <= 0 || $file['size'] > UPLOAD_MAX_BYTES) {
    flash('error', 'File too large. Maximum size: ' . human_filesize(UPLOAD_MAX_BYTES) . '.');
    redirect('/documents/index.php?engagement_id=' . $engagementId);
}

// ---------------------------------------------------------------------
// Extension + MIME validation
// ---------------------------------------------------------------------
$originalName = (string) $file['name'];
$ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
if (!in_array($ext, UPLOAD_ALLOWED_EXT, true)) {
    flash('error', 'File type not allowed.');
    redirect('/documents/index.php?engagement_id=' . $engagementId);
}

$finfo = new finfo(FILEINFO_MIME_TYPE);
$detectedMime = (string) $finfo->file($file['tmp_name']);
if (!in_array($detectedMime, UPLOAD_ALLOWED_MIME, true)) {
    flash('error', 'File MIME type not allowed: ' . e($detectedMime));
    redirect('/documents/index.php?engagement_id=' . $engagementId);
}

// ---------------------------------------------------------------------
// Move to private storage. Layout: /uploads_private/{firm}/{eng}/random.ext
// ---------------------------------------------------------------------
$targetDir = UPLOADS_PRIVATE . '/' . $firmIdForFile . '/' . $engagementId;
if (!is_dir($targetDir) && !mkdir($targetDir, 0750, true) && !is_dir($targetDir)) {
    error_log('[AuditBOS] mkdir failed: ' . $targetDir);
    flash('error', 'Server storage unavailable.');
    redirect('/documents/index.php?engagement_id=' . $engagementId);
}

$storedName = random_filename($originalName);
$absPath    = $targetDir . '/' . $storedName;
$relPath    = $firmIdForFile . '/' . $engagementId . '/' . $storedName;

if (!move_uploaded_file($file['tmp_name'], $absPath)) {
    error_log('[AuditBOS] move_uploaded_file failed: ' . $absPath);
    flash('error', 'Could not save file.');
    redirect('/documents/index.php?engagement_id=' . $engagementId);
}
@chmod($absPath, 0640);
$hash = hash_file('sha256', $absPath) ?: null;

// ---------------------------------------------------------------------
// Insert DB record + mark request received (in a transaction)
// ---------------------------------------------------------------------
try {
    $pdo->beginTransaction();
    $pdo->prepare(
        'INSERT INTO engagement_documents
            (engagement_id, document_request_id, category_id, title, original_filename,
             stored_filename, relative_path, mime_type, file_size, file_hash_sha256,
             status, uploaded_by)
         VALUES
            (:eid, :rid, :cid, :title, :ofn, :sfn, :rel, :mime, :sz, :h, "uploaded", :uid)'
    )->execute([
        ':eid'   => $engagementId,
        ':rid'   => $requestId,
        ':cid'   => null,
        ':title' => $originalName,
        ':ofn'   => $originalName,
        ':sfn'   => $storedName,
        ':rel'   => $relPath,
        ':mime'  => $detectedMime,
        ':sz'    => (int) $file['size'],
        ':h'     => $hash,
        ':uid'   => current_user_id(),
    ]);
    $docId = (int) $pdo->lastInsertId();

    // If linked to a request, also update its status.
    if ($requestId) {
        $pdo->prepare(
            'UPDATE document_requests SET status = "received"
              WHERE id = :id AND engagement_id = :eid AND status IN ("pending","needs_clarification","rejected")'
        )->execute([':id' => $requestId, ':eid' => $engagementId]);
    }

    $pdo->commit();
    log_activity('document.upload', 'engagement_document', $docId, $originalName);
    flash('success', "Uploaded: " . $originalName);
} catch (Throwable $e) {
    $pdo->rollBack();
    @unlink($absPath);
    error_log('[AuditBOS] upload save failed: ' . $e->getMessage());
    flash('error', 'Could not save upload.');
}

redirect('/documents/index.php?engagement_id=' . $engagementId);
