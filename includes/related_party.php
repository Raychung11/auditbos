<?php
/**
 * /includes/related_party.php
 *
 * Related-party register + transaction list (MFRS 124 disclosure-driven).
 * Pure data access — pages handle UI and POST routing.
 */

declare(strict_types=1);

if (!defined('AUDITBOS_BOOTSTRAPPED')) {
    http_response_code(403); exit('Forbidden');
}

/**
 * Relationship-type labels used in UI and disclosures.
 *
 * @return array<string, string>
 */
function rp_relationship_types(): array
{
    return [
        'director'          => 'Director',
        'holding_company'   => 'Holding company',
        'subsidiary'        => 'Subsidiary',
        'fellow_subsidiary' => 'Fellow subsidiary',
        'associate'         => 'Associate',
        'joint_venture'     => 'Joint venture',
        'key_management'    => 'Key management personnel',
        'close_family'      => 'Close family member',
        'other'             => 'Other related party',
    ];
}

function rp_txn_types(): array
{
    return [
        'sale'          => 'Sale of goods/services',
        'purchase'      => 'Purchase of goods/services',
        'loan_given'    => 'Loan given (advance)',
        'loan_received' => 'Loan received',
        'advance'       => 'Advance',
        'salary'        => 'Salary / remuneration',
        'dividend'      => 'Dividend',
        'interest'      => 'Interest',
        'rental'        => 'Rental',
        'other'         => 'Other',
    ];
}

/**
 * Parties for an engagement, with aggregated transaction totals.
 *
 * @return array<int, array<string,mixed>>
 */
function rp_parties(int $engagementId): array
{
    if (!table_exists('related_parties')) {
        return [];
    }
    $s = db()->prepare(
        'SELECT rp.*,
                COUNT(t.id)               AS txn_count,
                COALESCE(SUM(t.txn_amount),0)         AS total_txn,
                COALESCE(SUM(t.balance_outstanding),0) AS total_outstanding,
                SUM(CASE WHEN t.arm_length = "no" THEN 1 ELSE 0 END) AS not_arm_length
           FROM related_parties rp
           LEFT JOIN related_party_transactions t ON t.related_party_id = rp.id
          WHERE rp.engagement_id = :e
          GROUP BY rp.id
          ORDER BY rp.party_name'
    );
    $s->execute([':e' => $engagementId]);
    return $s->fetchAll();
}

function rp_transactions(int $engagementId): array
{
    if (!table_exists('related_party_transactions')) {
        return [];
    }
    $s = db()->prepare(
        'SELECT t.*, rp.party_name, rp.relationship_type
           FROM related_party_transactions t
           JOIN related_parties rp ON rp.id = t.related_party_id
          WHERE t.engagement_id = :e
          ORDER BY rp.party_name, t.created_at DESC'
    );
    $s->execute([':e' => $engagementId]);
    return $s->fetchAll();
}
