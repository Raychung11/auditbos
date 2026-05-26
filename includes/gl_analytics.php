<?php
/**
 * /includes/gl_analytics.php
 *
 * Read-only analytical procedures over the imported general ledger.
 * Surfaces exceptions an auditor should investigate:
 *   - Duplicate payments (same account + amount + date)
 *   - Round-number postings (manual-journal / estimate indicator)
 *   - Weekend postings (unusual for routine business activity)
 *   - Large-amount outliers
 *   - Benford's Law first-digit deviation (classic fraud screen)
 *   - Account concentration (top accounts by value + volume)
 *
 * Everything operates on general_ledgers for one engagement. Limits are
 * inlined as cast ints (PDO native prepares reject bound LIMIT params).
 */

declare(strict_types=1);

if (!defined('AUDITBOS_BOOTSTRAPPED')) {
    http_response_code(403); exit('Forbidden');
}

/**
 * High-level GL stats for an engagement.
 *
 * @return array{rows:int, total_debit:float, total_credit:float,
 *   accounts:int, date_min:?string, date_max:?string}
 */
function gl_summary(int $engagementId): array
{
    $stmt = db()->prepare(
        'SELECT COUNT(*) AS rows_n,
                COALESCE(SUM(debit),0)  AS total_debit,
                COALESCE(SUM(credit),0) AS total_credit,
                COUNT(DISTINCT account_code) AS accounts,
                MIN(transaction_date) AS date_min,
                MAX(transaction_date) AS date_max
           FROM general_ledgers
          WHERE engagement_id = :e'
    );
    $stmt->execute([':e' => $engagementId]);
    $r = $stmt->fetch() ?: [];
    return [
        'rows'         => (int) ($r['rows_n'] ?? 0),
        'total_debit'  => (float) ($r['total_debit'] ?? 0),
        'total_credit' => (float) ($r['total_credit'] ?? 0),
        'accounts'     => (int) ($r['accounts'] ?? 0),
        'date_min'     => $r['date_min'] ?? null,
        'date_max'     => $r['date_max'] ?? null,
    ];
}

/**
 * Potential duplicate payments: the same account + same debit amount
 * posted on the same date more than once.
 *
 * @return array<int, array<string,mixed>>
 */
function gl_duplicates(int $engagementId, int $limit = 50): array
{
    $stmt = db()->prepare(
        'SELECT account_code, account_name, transaction_date, debit,
                COUNT(*) AS occurrences,
                GROUP_CONCAT(COALESCE(reference_no, "—") ORDER BY id SEPARATOR " · ") AS refs,
                GROUP_CONCAT(COALESCE(description, "") ORDER BY id SEPARATOR " | ") AS descriptions
           FROM general_ledgers
          WHERE engagement_id = :e AND debit > 0
          GROUP BY account_code, debit, transaction_date
         HAVING occurrences > 1
          ORDER BY debit DESC, occurrences DESC
          LIMIT ' . (int) $limit
    );
    $stmt->execute([':e' => $engagementId]);
    return $stmt->fetchAll();
}

/**
 * Round-number postings at or above a threshold (default 1,000) where
 * the amount is an exact multiple of 1,000.
 *
 * @return array<int, array<string,mixed>>
 */
function gl_round_numbers(int $engagementId, float $threshold = 1000.0, int $limit = 50): array
{
    $stmt = db()->prepare(
        'SELECT id, transaction_date, account_code, account_name, reference_no,
                description, debit, credit,
                GREATEST(debit, credit) AS amount
           FROM general_ledgers
          WHERE engagement_id = :e
            AND GREATEST(debit, credit) >= :thr
            AND MOD(GREATEST(debit, credit), 1000) = 0
          ORDER BY amount DESC
          LIMIT ' . (int) $limit
    );
    $stmt->execute([':e' => $engagementId, ':thr' => $threshold]);
    return $stmt->fetchAll();
}

/**
 * Weekend postings (Saturday / Sunday). DAYOFWEEK: 1 = Sun, 7 = Sat.
 *
 * @return array<int, array<string,mixed>>
 */
function gl_weekend_postings(int $engagementId, int $limit = 50): array
{
    $stmt = db()->prepare(
        'SELECT id, transaction_date, DAYNAME(transaction_date) AS day_name,
                account_code, account_name, reference_no, description, debit, credit
           FROM general_ledgers
          WHERE engagement_id = :e AND DAYOFWEEK(transaction_date) IN (1, 7)
          ORDER BY transaction_date DESC
          LIMIT ' . (int) $limit
    );
    $stmt->execute([':e' => $engagementId]);
    return $stmt->fetchAll();
}

/**
 * Largest transactions by absolute amount.
 *
 * @return array<int, array<string,mixed>>
 */
function gl_outliers(int $engagementId, int $limit = 25): array
{
    $stmt = db()->prepare(
        'SELECT id, transaction_date, account_code, account_name, reference_no,
                description, debit, credit, GREATEST(debit, credit) AS amount
           FROM general_ledgers
          WHERE engagement_id = :e
          ORDER BY GREATEST(debit, credit) DESC
          LIMIT ' . (int) $limit
    );
    $stmt->execute([':e' => $engagementId]);
    return $stmt->fetchAll();
}

/**
 * Account concentration — top accounts by total value (Dr + Cr) and count.
 *
 * @return array<int, array<string,mixed>>
 */
function gl_top_accounts(int $engagementId, int $limit = 15): array
{
    $stmt = db()->prepare(
        'SELECT account_code, account_name,
                COUNT(*) AS txns,
                COALESCE(SUM(debit),0)  AS total_debit,
                COALESCE(SUM(credit),0) AS total_credit,
                COALESCE(SUM(debit),0) + COALESCE(SUM(credit),0) AS total_value
           FROM general_ledgers
          WHERE engagement_id = :e
          GROUP BY account_code
          ORDER BY total_value DESC
          LIMIT ' . (int) $limit
    );
    $stmt->execute([':e' => $engagementId]);
    return $stmt->fetchAll();
}

/**
 * Leading significant digit of an absolute amount (for Benford).
 */
function gl_first_digit(float $amount): int
{
    $amount = abs($amount);
    if ($amount <= 0) return 0;
    $digits = preg_replace('/[^0-9]/', '', number_format($amount, 4, '.', '')) ?? '';
    $digits = ltrim($digits, '0');
    return $digits !== '' ? (int) $digits[0] : 0;
}

/**
 * Benford's Law first-digit analysis over all non-zero amounts.
 *
 * @return array{
 *   sample:int,
 *   rows: array<int, array{digit:int, observed:int, observed_pct:float, expected_pct:float, deviation:float}>,
 *   max_deviation:float
 * }
 */
function gl_benford(int $engagementId): array
{
    // Expected Benford percentages for digits 1–9.
    $expected = [1=>30.103, 2=>17.609, 3=>12.494, 4=>9.691, 5=>7.918,
                 6=>6.695, 7=>5.799, 8=>5.115, 9=>4.576];

    $stmt = db()->prepare(
        'SELECT GREATEST(debit, credit) AS amount
           FROM general_ledgers
          WHERE engagement_id = :e AND GREATEST(debit, credit) > 0'
    );
    $stmt->execute([':e' => $engagementId]);

    $counts = array_fill(1, 9, 0);
    $sample = 0;
    foreach ($stmt as $row) {
        $d = gl_first_digit((float) $row['amount']);
        if ($d >= 1 && $d <= 9) {
            $counts[$d]++;
            $sample++;
        }
    }

    $rows = [];
    $maxDev = 0.0;
    foreach ($expected as $digit => $expPct) {
        $obs    = $counts[$digit];
        $obsPct = $sample > 0 ? ($obs / $sample) * 100 : 0.0;
        $dev    = $obsPct - $expPct;
        $maxDev = max($maxDev, abs($dev));
        $rows[] = [
            'digit'        => $digit,
            'observed'     => $obs,
            'observed_pct' => $obsPct,
            'expected_pct' => $expPct,
            'deviation'    => $dev,
        ];
    }

    return ['sample' => $sample, 'rows' => $rows, 'max_deviation' => $maxDev];
}

/**
 * Bundle every test into one structure (used by the page and the AI
 * prompt builder).
 *
 * @return array<string, mixed>
 */
function gl_run_all(int $engagementId): array
{
    return [
        'summary'    => gl_summary($engagementId),
        'duplicates' => gl_duplicates($engagementId),
        'round'      => gl_round_numbers($engagementId),
        'weekend'    => gl_weekend_postings($engagementId),
        'outliers'   => gl_outliers($engagementId),
        'top'        => gl_top_accounts($engagementId),
        'benford'    => gl_benford($engagementId),
    ];
}
