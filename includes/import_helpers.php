<?php
/**
 * /includes/import_helpers.php
 *
 * Shared helpers for the accounting-data import module:
 *   - CSV parsing (BOM-safe, delimiter auto-detect)
 *   - Header normalisation
 *   - Mapping persistence (import_mappings)
 *   - Safe upload + storage of the source file under /uploads_private
 *
 * The whole module is CSV-only for v1. Users save-as CSV from Excel.
 * .xlsx native parsing will land in a follow-up (PhpSpreadsheet).
 */

declare(strict_types=1);

if (!defined('AUDITBOS_BOOTSTRAPPED')) {
    http_response_code(403); exit('Forbidden');
}

/**
 * Field definitions per import type. Each field:
 *   - key:      DB column name we write to
 *   - label:    UI label
 *   - required: must be mapped before import
 *   - hints:    common header keywords used for auto-mapping
 *
 * @return array<string, array<int, array{key:string,label:string,required:bool,hints:array<int,string>}>>
 */
function import_field_specs(): array
{
    return [
        'trial_balance' => [
            ['key' => 'account_code', 'label' => 'Account code',  'required' => true,  'hints' => ['account code','acc code','code','account no','account number','acct','gl code']],
            ['key' => 'account_name', 'label' => 'Account name',  'required' => true,  'hints' => ['account name','account','description','desc','particulars','name']],
            ['key' => 'debit',        'label' => 'Debit',         'required' => false, 'hints' => ['debit','dr','db']],
            ['key' => 'credit',       'label' => 'Credit',        'required' => false, 'hints' => ['credit','cr']],
            ['key' => 'balance',      'label' => 'Balance',       'required' => false, 'hints' => ['balance','closing','net','amount']],
        ],
        'general_ledger' => [
            ['key' => 'transaction_date', 'label' => 'Transaction date', 'required' => true,  'hints' => ['date','transaction date','trans date','tran date','posting date']],
            ['key' => 'account_code',     'label' => 'Account code',     'required' => true,  'hints' => ['account code','acc code','code','gl code','account no']],
            ['key' => 'account_name',     'label' => 'Account name',     'required' => false, 'hints' => ['account name','account','particulars','name']],
            ['key' => 'reference_no',     'label' => 'Reference no.',    'required' => false, 'hints' => ['reference','ref','ref no','document','doc no','voucher']],
            ['key' => 'description',      'label' => 'Description',      'required' => false, 'hints' => ['description','desc','narration','memo','remarks']],
            ['key' => 'debit',            'label' => 'Debit',            'required' => false, 'hints' => ['debit','dr','db']],
            ['key' => 'credit',           'label' => 'Credit',           'required' => false, 'hints' => ['credit','cr']],
        ],
    ];
}

/**
 * Read a CSV upload into a header + rows structure. Handles UTF-8 BOM
 * and tries comma / semicolon / tab as delimiters.
 *
 * @return array{headers: array<int,string>, rows: array<int, array<int,string>>}
 */
function parse_csv_file(string $absPath, int $maxRows = 50000): array
{
    if (!is_file($absPath) || !is_readable($absPath)) {
        throw new RuntimeException('Cannot read uploaded CSV.');
    }

    $fh = fopen($absPath, 'rb');
    if (!$fh) {
        throw new RuntimeException('Failed to open CSV.');
    }

    // Sniff first line to pick a delimiter.
    $firstLine = fgets($fh) ?: '';
    // Strip UTF-8 BOM if present.
    if (substr($firstLine, 0, 3) === "\xEF\xBB\xBF") {
        $firstLine = substr($firstLine, 3);
    }

    $candidates = [',' => 0, ';' => 0, "\t" => 0, '|' => 0];
    foreach ($candidates as $d => $_) {
        $candidates[$d] = substr_count($firstLine, $d);
    }
    arsort($candidates);
    $delim = (string) array_key_first($candidates);
    if ($candidates[$delim] === 0) {
        $delim = ',';
    }

    // Parse the first line as headers using the chosen delimiter.
    $headers = str_getcsv(rtrim($firstLine, "\r\n"), $delim);
    $headers = array_map(static fn($h) => trim((string) $h), $headers);

    $rows = [];
    $count = 0;
    while (($line = fgets($fh)) !== false) {
        if ($line === '' || trim($line) === '') {
            continue;
        }
        $row = str_getcsv(rtrim($line, "\r\n"), $delim);
        $rows[] = array_map(static fn($v) => trim((string) $v), $row);
        $count++;
        if ($count >= $maxRows) {
            break;
        }
    }
    fclose($fh);

    return ['headers' => $headers, 'rows' => $rows];
}

/**
 * Best-guess mapping: for every target field, pick the CSV column whose
 * header contains the strongest hint match. Returns target_key => column_index.
 *
 * @param array<int, string> $headers
 * @return array<string, int>
 */
function auto_map_columns(array $headers, string $importType): array
{
    $specs = import_field_specs()[$importType] ?? [];
    $lowerHeaders = array_map(static fn($h) => strtolower(trim((string) $h)), $headers);

    $map = [];
    foreach ($specs as $spec) {
        $bestIdx = null;
        $bestScore = 0;
        foreach ($lowerHeaders as $idx => $h) {
            if ($h === '') {
                continue;
            }
            // Direct equality wins.
            if (in_array($h, $spec['hints'], true) || $h === strtolower($spec['key'])) {
                $bestIdx = $idx;
                $bestScore = 100;
                break;
            }
            foreach ($spec['hints'] as $hint) {
                if (strpos($h, $hint) !== false) {
                    $score = strlen($hint);
                    if ($score > $bestScore) {
                        $bestScore = $score;
                        $bestIdx = $idx;
                    }
                }
            }
        }
        if ($bestIdx !== null) {
            $map[$spec['key']] = $bestIdx;
        }
    }
    return $map;
}

/**
 * Normalise an amount-ish string to a decimal. Handles:
 *   - 1,234.56  ->  1234.56
 *   - (123.45)  -> -123.45  (accounting negative)
 *   - "—" / "-" / ""        -> 0
 */
function parse_amount(?string $raw): float
{
    if ($raw === null) {
        return 0.0;
    }
    $s = trim($raw);
    if ($s === '' || $s === '-' || $s === '—') {
        return 0.0;
    }
    $negative = false;
    if (preg_match('/^\((.*)\)$/', $s, $m)) {
        $negative = true;
        $s = $m[1];
    }
    if (substr($s, 0, 1) === '-') {
        $negative = !$negative;
        $s = substr($s, 1);
    }
    $s = preg_replace('/[^0-9.,]/', '', $s) ?? '';
    // If both comma and dot exist, assume comma is thousands sep.
    if (strpos($s, ',') !== false && strpos($s, '.') !== false) {
        $s = str_replace(',', '', $s);
    } elseif (strpos($s, ',') !== false && strpos($s, '.') === false) {
        // Could be European decimal — only convert if there's exactly one comma
        // followed by 1–2 digits.
        if (preg_match('/^\d+(,\d{1,2})$/', $s)) {
            $s = str_replace(',', '.', $s);
        } else {
            $s = str_replace(',', '', $s);
        }
    }
    $val = (float) $s;
    return $negative ? -$val : $val;
}

/**
 * Parse a date-ish string into Y-m-d, or return null if it can't.
 * Tolerates: 2024-12-31, 31/12/2024, 31-Dec-2024, 12/31/2024.
 */
function parse_date_value(?string $raw): ?string
{
    if (!$raw) return null;
    $s = trim($raw);
    if ($s === '') return null;

    // Already ISO?
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) {
        return $s;
    }

    // DD/MM/YYYY or DD-MM-YYYY (assume DD first for MY/SEA)
    if (preg_match('#^(\d{1,2})[/-](\d{1,2})[/-](\d{2,4})$#', $s, $m)) {
        $d = (int) $m[1]; $mo = (int) $m[2]; $y = (int) $m[3];
        if ($y < 100) $y += 2000;
        if ($d > 12 && $mo <= 12) {
            return sprintf('%04d-%02d-%02d', $y, $mo, $d);
        }
        // If month > 12, swap (US-format files).
        if ($mo > 12 && $d <= 12) {
            return sprintf('%04d-%02d-%02d', $y, $d, $mo);
        }
        return sprintf('%04d-%02d-%02d', $y, $mo, $d);
    }

    // Fallback: strtotime
    $ts = strtotime($s);
    return $ts ? date('Y-m-d', $ts) : null;
}

/**
 * Persist (or update) a saved column mapping for reuse on next upload.
 *
 * @param array<string, int> $mapping
 */
function save_mapping(int $firmId, string $importType, string $source,
    string $label, array $mapping, ?int $userId): void
{
    $json = json_encode($mapping, JSON_UNESCAPED_UNICODE);
    db()->prepare(
        'INSERT INTO import_mappings (firm_id, import_type, source, label, mapping_json, created_by)
         VALUES (:f, :t, :s, :l, :j, :u)
         ON DUPLICATE KEY UPDATE mapping_json = VALUES(mapping_json), status = "active"'
    )->execute([
        ':f'=>$firmId, ':t'=>$importType, ':s'=>$source,
        ':l'=>$label, ':j'=>$json, ':u'=>$userId,
    ]);
}

/**
 * Move the uploaded CSV into private storage under
 * /uploads_private/{firm}/imports/.
 *
 * @return array{abs:string, rel:string, stored:string}
 */
function stash_import_upload(array $file, int $firmId): array
{
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Upload failed (PHP error ' . $file['error'] . ').');
    }
    if ($file['size'] <= 0 || $file['size'] > UPLOAD_MAX_BYTES) {
        throw new RuntimeException('File too large. Max: ' . human_filesize(UPLOAD_MAX_BYTES));
    }
    $orig = (string) $file['name'];
    $ext  = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
    if ($ext !== 'csv' && $ext !== 'txt') {
        throw new RuntimeException('Only .csv files are accepted in v1. Save the Excel sheet as CSV.');
    }
    $dir = UPLOADS_PRIVATE . '/' . $firmId . '/imports';
    if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
        throw new RuntimeException('Server storage unavailable.');
    }
    $stored = random_filename($orig);
    $abs    = $dir . '/' . $stored;
    if (!move_uploaded_file($file['tmp_name'], $abs)) {
        throw new RuntimeException('Could not save uploaded file.');
    }
    @chmod($abs, 0640);
    return [
        'abs'    => $abs,
        'rel'    => $firmId . '/imports/' . $stored,
        'stored' => $stored,
    ];
}
