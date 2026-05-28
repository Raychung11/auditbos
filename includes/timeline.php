<?php
/**
 * /includes/timeline.php
 *
 * Engagement-scoped activity feed. activity_logs is a flat global table
 * keyed by (entity_type, entity_id). For an engagement view we need to
 * pull rows that *touch* the engagement directly OR via related
 * entities (document requests, working papers, review notes, documents,
 * import batches) — and aggregate them into one chronologically sorted
 * feed.
 *
 * Approach: one query per entity-type, joining back to engagement_id,
 * then merge + sort in PHP. Keeps each query indexable on the
 * (entity_type, entity_id) composite key already in activity_logs.
 */

declare(strict_types=1);

if (!defined('AUDITBOS_BOOTSTRAPPED')) {
    http_response_code(403); exit('Forbidden');
}

/**
 * @return array<int, array{
 *   id:int, created_at:string, action:string, description:?string,
 *   entity_type:string, entity_id:?int,
 *   user_id:?int, user_name:?string, user_role:?string
 * }>
 */
function engagement_timeline(int $engagementId, int $limit = 60): array
{
    $pdo = db();

    $cols = 'al.id, al.created_at, al.action, al.description,
             al.entity_type, al.entity_id, al.user_id,
             u.name AS user_name, u.role AS user_role';

    $queries = [
        // 1. Direct: action on the engagement itself, or AI runs that targeted it.
        "SELECT {$cols} FROM activity_logs al
            LEFT JOIN users u ON u.id = al.user_id
           WHERE al.entity_type = 'engagement' AND al.entity_id = :eid",

        // 2. Document requests for this engagement.
        "SELECT {$cols} FROM activity_logs al
            LEFT JOIN users u ON u.id = al.user_id
            JOIN document_requests dr ON dr.id = al.entity_id
           WHERE al.entity_type = 'document_request' AND dr.engagement_id = :eid",

        // 3. Uploaded documents.
        "SELECT {$cols} FROM activity_logs al
            LEFT JOIN users u ON u.id = al.user_id
            JOIN engagement_documents ed ON ed.id = al.entity_id
           WHERE al.entity_type = 'engagement_document' AND ed.engagement_id = :eid",

        // 4. Working papers.
        "SELECT {$cols} FROM activity_logs al
            LEFT JOIN users u ON u.id = al.user_id
            JOIN audit_working_papers wp ON wp.id = al.entity_id
           WHERE al.entity_type = 'audit_working_paper' AND wp.engagement_id = :eid",

        // 5. Review notes (entity_id is note id; need extra hop through WP).
        "SELECT {$cols} FROM activity_logs al
            LEFT JOIN users u ON u.id = al.user_id
            JOIN audit_review_notes arn ON arn.id = al.entity_id
            JOIN audit_working_papers wp ON wp.id = arn.working_paper_id
           WHERE al.entity_type = 'audit_review_note' AND wp.engagement_id = :eid",

        // 6. Import batches.
        "SELECT {$cols} FROM activity_logs al
            LEFT JOIN users u ON u.id = al.user_id
            JOIN import_batches ib ON ib.id = al.entity_id
           WHERE al.entity_type = 'import_batch' AND ib.engagement_id = :eid",

        // 7. AI outputs.
        "SELECT {$cols} FROM activity_logs al
            LEFT JOIN users u ON u.id = al.user_id
            JOIN ai_outputs ao ON ao.id = al.entity_id
           WHERE al.entity_type = 'ai_output' AND ao.engagement_id = :eid",
    ];

    $combined = [];
    foreach ($queries as $sql) {
        $stmt = $pdo->prepare($sql . ' ORDER BY al.created_at DESC LIMIT ' . (int) $limit);
        $stmt->execute([':eid' => $engagementId]);
        foreach ($stmt->fetchAll() as $row) {
            $combined[(int) $row['id']] = $row;
        }
    }

    // Sort newest first, cap at $limit.
    usort($combined, static fn($a, $b) => strcmp($b['created_at'], $a['created_at']));
    return array_slice($combined, 0, $limit);
}

/**
 * Map an action key to a human-readable label and a tone (used for
 * the marker colour in the timeline view).
 *
 * @return array{label:string, tone:string}
 */
function timeline_action_meta(string $action): array
{
    static $map = null;
    if ($map === null) {
        $raw = [
            'engagement.create'          => ['Engagement created',           'brand'],
            'engagement.update'          => ['Engagement updated',           'slate'],
            'engagement.status'          => ['Status changed',               'blue'],
            'engagement.seed_checklist'  => ['Document checklist seeded',    'amber'],
            'docreq.create'              => ['Document request added',       'amber'],
            'docreq.update'              => ['Document request edited',      'slate'],
            'docreq.delete'              => ['Document request deleted',     'rose'],
            'docreq.status'              => ['Request status changed',       'amber'],
            'document.upload'            => ['Document uploaded',            'emerald'],
            'document.download'          => ['Document downloaded',          'slate'],
            'wp.create'                  => ['Working paper created',        'brand'],
            'wp.update'                  => ['Working paper updated',        'slate'],
            'wp.note_raise'              => ['Review note raised',           'rose'],
            'wp.note_respond'            => ['Review note responded',        'blue'],
            'wp.note_clear'              => ['Review note cleared',          'emerald'],
            'ai.run'                     => ['AI assistant ran',             'purple'],
            'ai_classify.run'            => ['AI document classified',       'purple'],
            'import.tb.upload'           => ['TB import uploaded',           'amber'],
            'import.tb.commit'           => ['TB import committed',          'emerald'],
            'import.gl.upload'           => ['GL import uploaded',           'amber'],
            'import.gl.commit'           => ['GL import committed',          'emerald'],
        ];
        $map = [];
        foreach ($raw as $k => $pair) {
            $map[$k] = ['label' => $pair[0], 'tone' => $pair[1]];
        }
    }
    return $map[$action] ?? ['label' => ucwords(str_replace(['.','_'], ' ', $action)), 'tone' => 'slate'];
}

/**
 * Tailwind classes for the timeline marker dot, keyed by tone.
 */
function timeline_dot_classes(string $tone): string
{
    return match ($tone) {
        'brand'   => 'bg-brand-500',
        'emerald' => 'bg-emerald-500',
        'blue'    => 'bg-blue-500',
        'amber'   => 'bg-amber-500',
        'rose'    => 'bg-rose-500',
        'purple'  => 'bg-purple-500',
        default   => 'bg-slate-400',
    };
}
