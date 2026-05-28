<?php
/**
 * /includes/completion.php
 *
 * Audit completion checklist — the formal gate before partner sign-off.
 * Mix of auto-driven items (computed from engagement state) and manual
 * sign-off items (preparer ticks + notes).
 *
 * Items mirror the partner's standard pre-issuance review checklist:
 * all WPs cleared, all review notes resolved, SUM below materiality,
 * subsequent events done, going concern documented, MFRS-124 disclosure
 * complete, tax computation prepared, completion meeting held, etc.
 */

declare(strict_types=1);

if (!defined('AUDITBOS_BOOTSTRAPPED')) {
    http_response_code(403); exit('Forbidden');
}

/**
 * Master template of completion items. Each item:
 *   - key:    stable identifier
 *   - label:  short title
 *   - desc:   one-line explanation
 *   - auto:   bool — if true, status is driven by computed state, not
 *             a manual tick. The compute() callback decides.
 *
 * @return array<int, array{key:string, label:string, desc:string, auto:bool}>
 */
function completion_items_template(): array
{
    return [
        ['key'=>'all_wps_cleared',          'auto'=>true,  'label'=>'All working papers cleared',         'desc'=>'No WP in not_started / prepared / pending_review / review_note_raised.'],
        ['key'=>'all_review_notes_cleared', 'auto'=>true,  'label'=>'All review notes resolved',          'desc'=>'No open or reopened review notes remain.'],
        ['key'=>'misstatement_below_pm',    'auto'=>true,  'label'=>'Misstatements below performance materiality', 'desc'=>'Aggregate uncorrected PBT impact does not exceed PM.'],
        ['key'=>'subsequent_events_done',   'auto'=>true,  'label'=>'Subsequent events review complete', 'desc'=>'Step 22 cleared in the workplan.'],
        ['key'=>'going_concern_done',       'auto'=>true,  'label'=>'Going concern documented',           'desc'=>'Step 23 cleared in the workplan.'],
        ['key'=>'tax_computation_done',     'auto'=>true,  'label'=>'Tax computation prepared',           'desc'=>'tax_computations row exists for the engagement.'],
        ['key'=>'related_party_done',       'auto'=>true,  'label'=>'Related party register complete',    'desc'=>'At least one related party logged (or N/A).'],
        ['key'=>'mgmt_rep_received',        'auto'=>false, 'label'=>'Management representation letter received', 'desc'=>'Signed by directors, dated on/around report date.'],
        ['key'=>'completion_meeting_held',  'auto'=>false, 'label'=>'Completion meeting held with client', 'desc'=>'Draft FS and KAMs walked through; minutes filed.'],
        ['key'=>'final_review_done',        'auto'=>false, 'label'=>'Final manager / partner review done', 'desc'=>'Manager and partner reviewed the file end-to-end.'],
    ];
}

/**
 * Compute live status for each auto-driven item. Returns key → bool.
 *
 * @return array<string, bool>
 */
function completion_auto_state(int $engagementId): array
{
    $pdo = db();
    $state = [];

    // 1. All WPs cleared.
    if (table_exists('audit_working_papers')) {
        $s = $pdo->prepare(
            'SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN status IN ("cleared","completed") THEN 1 ELSE 0 END) AS done
              FROM audit_working_papers WHERE engagement_id = :e'
        );
        $s->execute([':e' => $engagementId]);
        $r = $s->fetch();
        $state['all_wps_cleared'] = ((int) $r['total']) > 0
            && (int) $r['total'] === (int) $r['done'];
    } else {
        $state['all_wps_cleared'] = false;
    }

    // 2. All review notes resolved.
    if (table_exists('audit_review_notes')) {
        $s = $pdo->prepare(
            'SELECT COUNT(*) FROM audit_review_notes rn
               JOIN audit_working_papers wp ON wp.id = rn.working_paper_id
              WHERE wp.engagement_id = :e AND rn.status IN ("open","reopened")'
        );
        $s->execute([':e' => $engagementId]);
        $state['all_review_notes_cleared'] = ((int) $s->fetchColumn()) === 0;
    } else {
        $state['all_review_notes_cleared'] = true;
    }

    // 3. Misstatements below PM.
    if (table_exists('misstatements')) {
        require_once __DIR__ . '/misstatements.php';
        $mis = mis_summary($engagementId);
        $state['misstatement_below_pm'] = !$mis['breach_pm']
            && ($mis['pm'] !== null);
    } else {
        $state['misstatement_below_pm'] = false;
    }

    // 4 & 5. Subsequent events / Going concern — read workplan step status.
    if (table_exists('engagement_workplan')) {
        $s = $pdo->prepare(
            'SELECT step_no, status FROM engagement_workplan
              WHERE engagement_id = :e AND step_no IN (22, 23)'
        );
        $s->execute([':e' => $engagementId]);
        $stepMap = [];
        foreach ($s->fetchAll() as $row) {
            $stepMap[(int) $row['step_no']] = $row['status'];
        }
        $state['subsequent_events_done'] = ($stepMap[22] ?? null) === 'cleared';
        $state['going_concern_done']     = ($stepMap[23] ?? null) === 'cleared';
    } else {
        $state['subsequent_events_done'] = false;
        $state['going_concern_done']     = false;
    }

    // 6. Tax computation prepared.
    if (table_exists('tax_computations')) {
        $s = $pdo->prepare('SELECT COUNT(*) FROM tax_computations WHERE engagement_id = :e');
        $s->execute([':e' => $engagementId]);
        $state['tax_computation_done'] = ((int) $s->fetchColumn()) > 0;
    } else {
        $state['tax_computation_done'] = false;
    }

    // 7. Related party register populated (any row counts).
    if (table_exists('related_parties')) {
        $s = $pdo->prepare('SELECT COUNT(*) FROM related_parties WHERE engagement_id = :e');
        $s->execute([':e' => $engagementId]);
        $state['related_party_done'] = ((int) $s->fetchColumn()) > 0;
    } else {
        $state['related_party_done'] = false;
    }

    return $state;
}

/**
 * Merge the manual sign-off rows with the live auto state into a single
 * list ready to render.
 *
 * @return array<int, array{key:string, label:string, desc:string, auto:bool,
 *                          is_done:bool, signed_off_by_name:?string,
 *                          signed_off_at:?string, notes:?string}>
 */
function completion_checklist(int $engagementId): array
{
    $template = completion_items_template();
    $auto     = completion_auto_state($engagementId);

    $manual = [];
    if (table_exists('completion_checklist')) {
        $s = db()->prepare(
            'SELECT cc.*, u.name AS signed_off_by_name
               FROM completion_checklist cc
               LEFT JOIN users u ON u.id = cc.signed_off_by
              WHERE cc.engagement_id = :e'
        );
        $s->execute([':e' => $engagementId]);
        foreach ($s->fetchAll() as $row) {
            $manual[$row['item_key']] = $row;
        }
    }

    $out = [];
    foreach ($template as $item) {
        $key = $item['key'];
        if ($item['auto']) {
            $isDone = !empty($auto[$key]);
            $out[] = $item + [
                'is_done'            => $isDone,
                'signed_off_by_name' => null,
                'signed_off_at'      => null,
                'notes'              => null,
            ];
        } else {
            $m = $manual[$key] ?? null;
            $out[] = $item + [
                'is_done'            => $m ? (bool) $m['is_done'] : false,
                'signed_off_by_name' => $m['signed_off_by_name'] ?? null,
                'signed_off_at'      => $m['signed_off_at'] ?? null,
                'notes'              => $m['notes'] ?? null,
            ];
        }
    }
    return $out;
}

/**
 * Upsert a manual completion item — single endpoint for "tick + sign off".
 */
function completion_set_item(int $engagementId, string $itemKey, bool $isDone, ?string $notes, ?int $userId): bool
{
    if (!table_exists('completion_checklist')) {
        return false;
    }
    return db()->prepare(
        'INSERT INTO completion_checklist
            (engagement_id, item_key, is_done, notes, signed_off_by, signed_off_at)
         VALUES (:e, :k, :d, :n, :u, :ts)
         ON DUPLICATE KEY UPDATE
            is_done = VALUES(is_done),
            notes = VALUES(notes),
            signed_off_by = VALUES(signed_off_by),
            signed_off_at = VALUES(signed_off_at)'
    )->execute([
        ':e' => $engagementId,
        ':k' => $itemKey,
        ':d' => $isDone ? 1 : 0,
        ':n' => $notes,
        ':u' => $isDone ? $userId : null,
        ':ts'=> $isDone ? date('Y-m-d H:i:s') : null,
    ]);
}

/**
 * True when every item (auto + manual) on the checklist is done.
 */
function completion_is_complete(int $engagementId): bool
{
    foreach (completion_checklist($engagementId) as $item) {
        if (!$item['is_done']) {
            return false;
        }
    }
    return true;
}
