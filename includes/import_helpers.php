<?php
/**
 * /includes/import_helpers.php
 *
 * Shared helpers for the accounting-data import module:
 *   - CSV parsing (BOM-safe, delimiter auto-detect)
 *   - XLSX parsing (ZipArchive + SimpleXML, no Composer needed)
 *   - Header normalisation
 *   - Mapping persistence (import_mappings)
 *   - Safe upload + storage of the source file under /uploads_private
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
 * Parse an XLSX file natively (ZipArchive + SimpleXML — no Composer).
 *
 * Reads the first worksheet. Resolves shared strings, picks up inline
 * strings, returns numeric values as their canonical numeric string,
 * and returns ISO-format dates for cells that Excel marked as dates.
 *
 * Limitations (deliberate, v1):
 *   - Only the first sheet is read.
 *   - Formulas: returns the cached `<v>` value if present, otherwise empty.
 *   - Styles: only the bundled "default" Excel date formats are detected
 *     (codes 14–22, 27–36, 45–47, 50–58) plus any custom numFmt whose
 *     format-code contains a `d`, `m`, or `y` placeholder.
 *
 * @return array{headers: array<int,string>, rows: array<int, array<int,string>>}
 */
function parse_xlsx_file(string $absPath, int $maxRows = 50000): array
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('PHP ZipArchive extension is required to read .xlsx files.');
    }
    if (!is_file($absPath) || !is_readable($absPath)) {
        throw new RuntimeException('Cannot read uploaded XLSX.');
    }

    $zip = new ZipArchive();
    if ($zip->open($absPath) !== true) {
        throw new RuntimeException('Uploaded file is not a valid .xlsx (zip) archive.');
    }

    // Suppress libxml entity-expansion warnings (XXE protection).
    $prev = libxml_use_internal_errors(true);
    // PHP 8+ defaults are safe; keep this explicit for clarity.
    if (function_exists('libxml_disable_entity_loader')) {
        // No-op on PHP 8.x, retained for older environments.
        @libxml_disable_entity_loader(true);
    }

    try {
        // 1. Shared strings (string cells reference these by index).
        $shared = [];
        if (($sst = $zip->getFromName('xl/sharedStrings.xml')) !== false && $sst !== '') {
            $sx = simplexml_load_string($sst);
            if ($sx) {
                foreach ($sx->si as $si) {
                    $text = '';
                    // Plain string: <si><t>foo</t></si>
                    if (isset($si->t)) {
                        $text = (string) $si->t;
                    } else {
                        // Rich text: <si><r><t>part1</t></r><r><t>part2</t></r></si>
                        foreach ($si->r as $r) {
                            $text .= (string) $r->t;
                        }
                    }
                    $shared[] = $text;
                }
            }
        }

        // 2. Style index → "is this a date?" — covers built-in formats
        //    and any custom numFmt whose code looks date-like.
        $dateStyleIdx = xlsx_date_style_indexes($zip);

        // 3. Workbook → first sheet's filename inside the zip.
        $sheetXml = null;
        if (($wb = $zip->getFromName('xl/workbook.xml')) !== false) {
            $wbx = simplexml_load_string($wb);
            if ($wbx && isset($wbx->sheets->sheet[0])) {
                $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');
                if ($rels !== false) {
                    $rx = simplexml_load_string($rels);
                    if ($rx) {
                        $sheet = $wbx->sheets->sheet[0];
                        $rid   = (string) $sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')->id;
                        foreach ($rx->Relationship as $rel) {
                            if ((string) $rel['Id'] === $rid) {
                                $target = (string) $rel['Target'];
                                // Target is relative to xl/ — strip a leading slash if present
                                $target = ltrim($target, '/');
                                $sheetXml = strpos($target, 'xl/') === 0 ? $target : 'xl/' . $target;
                                break;
                            }
                        }
                    }
                }
            }
        }
        if ($sheetXml === null) {
            $sheetXml = 'xl/worksheets/sheet1.xml';
        }

        // 4. Parse the sheet. Use XMLReader to keep memory low for big sheets.
        $sheetBlob = $zip->getFromName($sheetXml);
        if ($sheetBlob === false) {
            throw new RuntimeException('XLSX sheet stream not found: ' . $sheetXml);
        }
        $reader = new XMLReader();
        $reader->XML($sheetBlob, 'UTF-8', LIBXML_NONET | LIBXML_COMPACT);

        $allRows = [];
        $currentRow = null;
        $currentRowIdx = 0;
        while ($reader->read()) {
            if ($reader->nodeType === XMLReader::ELEMENT && $reader->name === 'row') {
                $currentRow = [];
            }
            if ($reader->nodeType === XMLReader::ELEMENT && $reader->name === 'c' && $currentRow !== null) {
                $ref   = $reader->getAttribute('r')   ?? '';   // e.g. "B4"
                $type  = $reader->getAttribute('t')   ?? 'n';  // n|s|str|inlineStr|b|e|d
                $style = (int) ($reader->getAttribute('s') ?? -1);
                $colIdx = xlsx_col_index($ref);

                // Drill into <v> / <is><t> child elements.
                $rawValue = '';
                if (!$reader->isEmptyElement) {
                    $inner = new XMLReader();
                    $inner->XML($reader->readOuterXml(), 'UTF-8', LIBXML_NONET | LIBXML_COMPACT);
                    while ($inner->read()) {
                        if ($inner->nodeType === XMLReader::ELEMENT) {
                            if ($inner->name === 'v') {
                                $rawValue = (string) $inner->readString();
                            } elseif ($inner->name === 't' && $type === 'inlineStr') {
                                $rawValue = (string) $inner->readString();
                            }
                        }
                    }
                    $inner->close();
                }

                $value = xlsx_resolve_cell($rawValue, $type, $style, $shared, $dateStyleIdx);
                if ($colIdx !== null) {
                    $currentRow[$colIdx] = $value;
                }
            }
            if ($reader->nodeType === XMLReader::END_ELEMENT && $reader->name === 'row' && $currentRow !== null) {
                // Compact sparse row (Excel skips empty cells).
                if (!empty($currentRow)) {
                    ksort($currentRow);
                    $maxCol = max(array_keys($currentRow));
                    $compact = [];
                    for ($i = 0; $i <= $maxCol; $i++) {
                        $compact[] = $currentRow[$i] ?? '';
                    }
                    $allRows[] = $compact;
                    $currentRowIdx++;
                    if ($currentRowIdx > $maxRows + 1) {
                        break;
                    }
                }
                $currentRow = null;
            }
        }
        $reader->close();
        $zip->close();
    } finally {
        libxml_use_internal_errors($prev);
    }

    if (empty($allRows)) {
        return ['headers' => [], 'rows' => []];
    }

    // First non-empty row is headers; the rest are data rows.
    $headers = array_shift($allRows);
    $headers = array_map(static fn($h) => trim((string) $h), $headers);

    // Skip blank trailing rows.
    $rows = [];
    foreach ($allRows as $r) {
        $r = array_map(static fn($v) => trim((string) $v), $r);
        if (count(array_filter($r, static fn($v) => $v !== '')) === 0) {
            continue;
        }
        $rows[] = $r;
    }
    return ['headers' => $headers, 'rows' => $rows];
}

/**
 * Convert "B4" → 1 (zero-indexed column). Returns null for malformed refs.
 */
function xlsx_col_index(string $cellRef): ?int
{
    if (!preg_match('/^([A-Z]+)\d+$/', $cellRef, $m)) {
        return null;
    }
    $letters = $m[1];
    $col = 0;
    for ($i = 0, $n = strlen($letters); $i < $n; $i++) {
        $col = $col * 26 + (ord($letters[$i]) - ord('A') + 1);
    }
    return $col - 1;
}

/**
 * Resolve a cell's raw value to a display string.
 *
 * @param array<int,string> $shared
 * @param array<int,bool>   $dateStyleIdx
 */
function xlsx_resolve_cell(string $raw, string $type, int $style,
    array $shared, array $dateStyleIdx): string
{
    if ($raw === '') {
        return '';
    }
    switch ($type) {
        case 's':                                    // shared string
            $idx = (int) $raw;
            return $shared[$idx] ?? '';
        case 'str':                                  // inline cached formula result
        case 'inlineStr':
            return $raw;
        case 'b':                                    // boolean
            return $raw === '1' ? 'TRUE' : 'FALSE';
        case 'e':                                    // error
            return '';
        case 'd':                                    // ISO date stored as-is
            return $raw;
        case 'n':                                    // number
        default:
            // Heuristic: numeric cell styled as a date → convert.
            if (isset($dateStyleIdx[$style]) && is_numeric($raw)) {
                return xlsx_serial_to_iso((float) $raw);
            }
            return $raw;
    }
}

/**
 * Read xl/styles.xml and return [styleIndex => true] for styles whose
 * numFmt is a date format (built-in or custom-with-date-tokens).
 *
 * @return array<int, true>
 */
function xlsx_date_style_indexes(ZipArchive $zip): array
{
    $blob = $zip->getFromName('xl/styles.xml');
    if ($blob === false || $blob === '') {
        return [];
    }
    $sx = simplexml_load_string($blob);
    if (!$sx) {
        return [];
    }

    // Excel built-in date formats (numFmtId values).
    $builtIn = [14,15,16,17,18,19,20,21,22,
                27,28,29,30,31,32,33,34,35,36,
                45,46,47,
                50,51,52,53,54,55,56,57,58];

    // Pick up any custom numFmts with d/m/y in their formatCode.
    $custom = [];
    if (isset($sx->numFmts) && isset($sx->numFmts->numFmt)) {
        foreach ($sx->numFmts->numFmt as $nf) {
            $code = (string) $nf['formatCode'];
            // Strip quoted literals so "12" doesn't trick us.
            $stripped = preg_replace('/"[^"]*"/', '', $code) ?? $code;
            if (preg_match('/[dmyDMY]/', $stripped)
                && !preg_match('/^(General|@|0|#|\$)/', trim($code))) {
                $custom[(int) $nf['numFmtId']] = true;
            }
        }
    }

    $dateNumFmtIds = array_flip($builtIn) + $custom;

    // cellXfs is an ordered list — its index is the cell's `s` attribute.
    $map = [];
    if (isset($sx->cellXfs) && isset($sx->cellXfs->xf)) {
        $i = 0;
        foreach ($sx->cellXfs->xf as $xf) {
            $nfId = (int) $xf['numFmtId'];
            $apply = (string) $xf['applyNumberFormat'];
            // applyNumberFormat=1 OR a built-in date numFmtId is treated as a date.
            if (isset($dateNumFmtIds[$nfId])) {
                $map[$i] = true;
            }
            $i++;
        }
    }
    return $map;
}

/**
 * Convert an Excel serial date (days since 1900-01-01, with the 1900
 * leap-year bug) to an ISO date string.
 */
function xlsx_serial_to_iso(float $serial): string
{
    if ($serial < 1) {
        return '';
    }
    // Excel treats 1900-02-29 as a real date (it isn't). Adjust for that.
    $ts = ($serial - 25569) * 86400;
    if ($serial < 60) {
        $ts = ($serial - 25568) * 86400;
    }
    return gmdate('Y-m-d', (int) $ts);
}

/**
 * Top-level dispatch: pick parser based on extension. Same return shape
 * as parse_csv_file() / parse_xlsx_file().
 */
function parse_spreadsheet_file(string $absPath, string $originalName, int $maxRows = 50000): array
{
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if ($ext === 'xlsx') {
        return parse_xlsx_file($absPath, $maxRows);
    }
    return parse_csv_file($absPath, $maxRows);
}

/**
 * Best-guess mapping: for every target field, pick the column whose
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

    // Excel serial date (days since 1900-01-01). Range 25569 (1970) – 80000
    // (year 2119) covers any plausible audit period without false-positives
    // on small integers like quantities.
    if (preg_match('/^\d+(\.\d+)?$/', $s)) {
        $serial = (float) $s;
        if ($serial >= 25569 && $serial < 80000) {
            return xlsx_serial_to_iso($serial);
        }
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
    if (!in_array($ext, ['csv','txt','xlsx'], true)) {
        throw new RuntimeException('Only .csv and .xlsx files are accepted.');
    }
    if ($ext === 'xlsx' && !class_exists('ZipArchive')) {
        throw new RuntimeException('PHP ZipArchive extension is required to read .xlsx files. Save as CSV or contact your hosting admin.');
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
