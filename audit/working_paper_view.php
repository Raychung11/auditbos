<?php
/**
 * /audit/working_paper_view.php
 *
 * Single working paper: read-only summary + review notes thread.
 * Reviewers/partners can raise notes; preparers can respond; either
 * can clear (cleared_by recorded).
 */

declare(strict_types=1);
require_once __DIR__ . '/../includes/auth_guard.php';
require_once __DIR__ . '/../includes/workplan.php';
require_role(['firm_admin','audit_manager','senior_auditor','junior_auditor','reviewer']);
require_once __DIR__ . '/../includes/workflow.php';

$pdo    = db();
$firmId = current_firm_id();
$id     = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($id <= 0) { flash('error', 'Invalid working paper.'); redirect('/audit/working_papers.php'); }

$canReview = role_allows(['firm_admin','audit_manager','senior_auditor','reviewer']);

// ---------------------------------------------------------------------
// POST handlers (raise note / respond / clear)
// ---------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['_action'] ?? '';

    // Block all mutations when the parent engagement is locked.
    $engLookup = $pdo->prepare(
        'SELECT e.id FROM audit_working_papers wp
           JOIN engagements e ON e.id = wp.engagement_id
          WHERE wp.id = :id AND e.firm_id = :fid'
    );
    $engLookup->execute([':id'=>$id, ':fid'=>$firmId]);
    $engForLock = (int) ($engLookup->fetchColumn() ?: 0);
    if ($engForLock > 0) {
        assert_engagement_open($engForLock);
    }

    if ($action === 'raise_note' && $canReview) {
        $note = trim((string)($_POST['note'] ?? ''));
        $severity = $_POST['severity'] ?? 'minor';
        if (!in_array($severity, ['info','minor','major','critical'], true)) {
            $severity = 'minor';
        }
        if ($note !== '') {
            $pdo->prepare(
                'INSERT INTO audit_review_notes (working_paper_id, raised_by, note, severity, status)
                 VALUES (:wp, :u, :n, :sev, "open")'
            )->execute([
                ':wp'=>$id, ':u'=>current_user_id(), ':n'=>$note, ':sev'=>$severity,
            ]);
            // Bump WP status if it was prepared.
            $pdo->prepare(
                'UPDATE audit_working_papers
                    SET status = CASE WHEN status IN ("prepared","pending_review")
                                      THEN "review_note_raised" ELSE status END
                  WHERE id = :id'
            )->execute([':id' => $id]);
            log_activity('wp.note_raise', 'audit_working_paper', $id, $severity);
            flash('success', 'Review note raised.');
        } else {
            flash('error', 'Note cannot be empty.');
        }
    } elseif ($action === 'respond_note') {
        $noteId = (int)($_POST['note_id'] ?? 0);
        $response = trim((string)($_POST['response'] ?? ''));
        if ($noteId > 0 && $response !== '') {
            // Verify note belongs to a WP in this firm.
            $check = $pdo->prepare(
                'SELECT arn.id FROM audit_review_notes arn
                   JOIN audit_working_papers wp ON wp.id = arn.working_paper_id
                   JOIN engagements e ON e.id = wp.engagement_id
                  WHERE arn.id = :id AND e.firm_id = :fid'
            );
            $check->execute([':id'=>$noteId, ':fid'=>$firmId]);
            if ($check->fetch()) {
                $pdo->prepare(
                    'UPDATE audit_review_notes
                        SET response = :r, status = "responded"
                      WHERE id = :id'
                )->execute([':r'=>$response, ':id'=>$noteId]);
                log_activity('wp.note_respond', 'audit_review_note', $noteId);
                flash('success', 'Response submitted.');
            }
        }
    } elseif ($action === 'clear_note' && $canReview) {
        $noteId = (int)($_POST['note_id'] ?? 0);
        $check = $pdo->prepare(
            'SELECT arn.id FROM audit_review_notes arn
               JOIN audit_working_papers wp ON wp.id = arn.working_paper_id
               JOIN engagements e ON e.id = wp.engagement_id
              WHERE arn.id = :id AND e.firm_id = :fid'
        );
        $check->execute([':id'=>$noteId, ':fid'=>$firmId]);
        if ($check->fetch()) {
            $pdo->prepare(
                'UPDATE audit_review_notes
                    SET status = "cleared", cleared_by = :u, cleared_at = NOW()
                  WHERE id = :id'
            )->execute([':u'=>current_user_id(), ':id'=>$noteId]);
            // If no more open notes, restore WP status.
            $open = $pdo->prepare(
                'SELECT COUNT(*) FROM audit_review_notes
                  WHERE working_paper_id = :wp AND status IN ("open","responded","reopened")'
            );
            $open->execute([':wp' => $id]);
            if ((int) $open->fetchColumn() === 0) {
                $pdo->prepare(
                    'UPDATE audit_working_papers
                        SET status = CASE WHEN status = "review_note_raised"
                                          THEN "pending_review" ELSE status END
                      WHERE id = :id'
                )->execute([':id' => $id]);
            }
            log_activity('wp.note_clear', 'audit_review_note', $noteId);
            flash('success', 'Note cleared.');
        }
    }
    redirect('/audit/working_paper_view.php?id=' . $id);
}

// ---------------------------------------------------------------------
// Load WP + engagement + notes
// ---------------------------------------------------------------------
$stmt = $pdo->prepare(
    'SELECT wp.*, e.financial_year, e.engagement_type, e.id AS engagement_id,
            c.company_name, asec.name AS section_name, asec.code AS section_code,
            pu.name AS prepared_by_name, ru.name AS reviewed_by_name
       FROM audit_working_papers wp
       JOIN engagements e ON e.id = wp.engagement_id
       JOIN clients c     ON c.id = e.client_id
       LEFT JOIN audit_sections asec ON asec.id = wp.section_id
       LEFT JOIN users pu ON pu.id = wp.prepared_by
       LEFT JOIN users ru ON ru.id = wp.reviewed_by
      WHERE wp.id = :id AND e.firm_id = :fid'
);
$stmt->execute([':id'=>$id, ':fid'=>$firmId]);
$wp = $stmt->fetch();
if (!$wp) { flash('error', 'Not found.'); redirect('/audit/working_papers.php'); }

$notesStmt = $pdo->prepare(
    'SELECT arn.*, ru.name AS raised_by_name, cu.name AS cleared_by_name
       FROM audit_review_notes arn
       LEFT JOIN users ru ON ru.id = arn.raised_by
       LEFT JOIN users cu ON cu.id = arn.cleared_by
      WHERE arn.working_paper_id = :wp
      ORDER BY arn.created_at DESC'
);
$notesStmt->execute([':wp' => $id]);
$notes = $notesStmt->fetchAll();

$pageTitle = $wp['title'];
require __DIR__ . '/../includes/header.php';
?>

<a href="/firm/engagement_view.php?id=<?= (int) $wp['engagement_id'] ?>"
   class="text-sm text-brand-600 hover:underline">&larr; Back to engagement</a>

<?php if (!empty($wp['lead_area'])):
    $sopStep = workplan_step_for_context('wp_lead_area', (string) $wp['lead_area'], (int) $wp['engagement_id']);
    echo workplan_breadcrumb_html((int) $wp['engagement_id'], $sopStep);
endif; ?>

<div class="flex flex-wrap items-start justify-between gap-3 mt-1 mb-5">
    <div>
        <div class="text-xs text-slate-500">
            <?= e($wp['company_name']) ?> · <?= e($wp['financial_year']) ?>
            <?php if ($wp['section_code']): ?>· <?= e($wp['section_code']) ?> <?= e($wp['section_name']) ?><?php endif; ?>
        </div>
        <h2 class="text-xl font-semibold text-slate-900 mt-0.5">
            <?php if ($wp['reference_code']): ?>
                <span class="font-mono text-slate-500"><?= e($wp['reference_code']) ?></span>
            <?php endif; ?>
            <?= e($wp['title']) ?>
        </h2>
        <div class="flex items-center gap-2 mt-2">
            <?= badge($wp['status']) ?>
            <?php if ($wp['risk_rating']): ?><?= badge($wp['risk_rating']) ?><?php endif; ?>
        </div>
    </div>
    <div class="flex items-center gap-2">
        <?php if (defined('AI_ENABLED') && AI_ENABLED): ?>
            <a href="/ai/run.php?fn=ai_review_working_paper&engagement_id=<?= (int) $wp['engagement_id'] ?>&working_paper_id=<?= (int) $wp['id'] ?>"
               class="rounded bg-brand-600 hover:bg-brand-700 text-white px-3 py-1.5 text-sm">
                AI review
            </a>
        <?php endif; ?>
        <a href="/audit/working_papers.php?action=edit&id=<?= (int) $wp['id'] ?>"
           class="rounded border border-slate-300 px-3 py-1.5 text-sm hover:bg-slate-50">Edit</a>
    </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <div class="lg:col-span-2 space-y-5">
        <div class="bg-white rounded-lg border border-slate-200 p-5">
            <h3 class="font-semibold text-slate-900 mb-2">Audit procedure</h3>
            <p class="text-sm text-slate-700 whitespace-pre-wrap"><?= e($wp['procedure'] ?? '—') ?></p>
        </div>
        <div class="bg-white rounded-lg border border-slate-200 p-5">
            <h3 class="font-semibold text-slate-900 mb-2">Conclusion</h3>
            <p class="text-sm text-slate-700 whitespace-pre-wrap"><?= e($wp['conclusion'] ?? '—') ?></p>
        </div>
        <?php if (!empty($wp['notes'])): ?>
            <div class="bg-white rounded-lg border border-slate-200 p-5">
                <h3 class="font-semibold text-slate-900 mb-2">Notes</h3>
                <p class="text-sm text-slate-700 whitespace-pre-wrap"><?= e($wp['notes']) ?></p>
            </div>
        <?php endif; ?>
        <?php if (!empty($wp['ai_summary'])): ?>
            <div class="bg-brand-50 border border-brand-100 rounded-lg p-5">
                <h3 class="font-semibold text-brand-900 mb-2">AI summary</h3>
                <p class="text-sm text-brand-900 whitespace-pre-wrap"><?= e($wp['ai_summary']) ?></p>
            </div>
        <?php endif; ?>
    </div>

    <!-- Review notes thread -->
    <div class="space-y-5">
        <?php if ($canReview): ?>
            <div class="bg-white rounded-lg border border-slate-200 p-4">
                <h3 class="font-semibold text-slate-900 mb-2">Raise review note</h3>
                <form method="post" class="space-y-2">
                    <?= csrf_field() ?>
                    <input type="hidden" name="_action" value="raise_note">
                    <textarea name="note" required rows="3"
                              placeholder="What needs to be addressed?"
                              class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm"></textarea>
                    <div class="flex items-center justify-between">
                        <select name="severity" class="rounded border border-slate-300 text-xs px-2 py-1">
                            <option value="info">Info</option>
                            <option value="minor" selected>Minor</option>
                            <option value="major">Major</option>
                            <option value="critical">Critical</option>
                        </select>
                        <button class="rounded bg-brand-600 hover:bg-brand-700 text-white px-3 py-1.5 text-sm">
                            Raise
                        </button>
                    </div>
                </form>
            </div>
        <?php endif; ?>

        <div class="bg-white rounded-lg border border-slate-200">
            <div class="px-4 py-3 border-b border-slate-200 flex items-center justify-between">
                <h3 class="font-semibold text-slate-900">Review notes</h3>
                <span class="text-xs text-slate-500">
                    <?= count(array_filter($notes, fn($n) => $n['status'] !== 'cleared')) ?> open
                </span>
            </div>
            <?php if (empty($notes)): ?>
                <div class="p-6 text-center text-sm text-slate-500">No notes yet.</div>
            <?php else: ?>
                <ul class="divide-y divide-slate-200">
                    <?php foreach ($notes as $n): ?>
                        <li class="px-4 py-3">
                            <div class="flex items-start justify-between gap-2 mb-1">
                                <div class="text-xs text-slate-500">
                                    <?= e($n['raised_by_name'] ?? 'Unknown') ?>
                                    · <?= e(datefmt($n['created_at'], 'd M Y H:i')) ?>
                                </div>
                                <div class="flex items-center gap-1">
                                    <?= badge($n['severity']) ?>
                                    <?= badge($n['status']) ?>
                                </div>
                            </div>
                            <p class="text-sm text-slate-800 whitespace-pre-wrap"><?= e($n['note']) ?></p>

                            <?php if (!empty($n['response'])): ?>
                                <div class="mt-2 pl-3 border-l-2 border-brand-200">
                                    <div class="text-xs text-slate-500 mb-0.5">Response</div>
                                    <p class="text-sm text-slate-700 whitespace-pre-wrap"><?= e($n['response']) ?></p>
                                </div>
                            <?php elseif ($n['status'] !== 'cleared'): ?>
                                <form method="post" class="mt-2 space-y-1">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="_action" value="respond_note">
                                    <input type="hidden" name="note_id" value="<?= (int) $n['id'] ?>">
                                    <textarea name="response" required rows="2"
                                              placeholder="Reply…"
                                              class="w-full rounded border border-slate-300 px-2 py-1 text-xs"></textarea>
                                    <button class="text-xs rounded bg-slate-700 text-white px-2 py-1">Reply</button>
                                </form>
                            <?php endif; ?>

                            <?php if ($n['status'] !== 'cleared' && $canReview): ?>
                                <form method="post" class="mt-2">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="_action" value="clear_note">
                                    <input type="hidden" name="note_id" value="<?= (int) $n['id'] ?>">
                                    <button class="text-xs rounded bg-emerald-600 hover:bg-emerald-700 text-white px-2 py-1">
                                        Mark cleared
                                    </button>
                                </form>
                            <?php elseif ($n['status'] === 'cleared'): ?>
                                <div class="mt-2 text-xs text-emerald-700">
                                    Cleared by <?= e($n['cleared_by_name'] ?? '') ?>
                                    · <?= e(datefmt($n['cleared_at'], 'd M Y H:i')) ?>
                                </div>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
