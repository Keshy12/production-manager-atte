<?php
/**
 * One-shot CLI: import procurement history (RFQs, POs, Goods Receipts) from
 * three Google Sheets into the MSA database.
 *
 * Reads three sheets from three spreadsheets:
 *   1. "Lista RFQ"              — `RFQ_list` (A:T)            → purchase__rfq + items
 *   2. "Otwarte Zamowienia PO"  — `purchase_order_list` (A:W) → purchase__order + items (header + positions)
 *   3. "Lista przyjętych zamówień" — `to_warehouse` (A:Y)      → purchase__order_receipt + items (per PO + GRN date)
 *
 * Re-run safe: documents are matched by their sheet number (rfq_number /
 * po_number / receipt document_number) and skipped if already in DB.
 *
 * No stock writes: this importer never touches inventory__parts and never
 * creates transfer groups. Receipt items only record what was received;
 * downstream stock movement is left to other workflows.
 *
 * Usage:
 *   php src/cron/import-procurement-history-from-gsheet.php                  # LIVE
 *   php src/cron/import-procurement-history-from-gsheet.php --dry-run        # zero writes
 *   php src/cron/import-procurement-history-from-gsheet.php --step=rfq       # one step only
 *   php src/cron/import-procurement-history-from-gsheet.php --user=1 --magazine=27
 *
 * CLI bootstrap notes:
 *   - Loads config.php only. Deliberately does NOT load
 *     config-google-sheets.php because that file eagerly instantiates
 *     Hybridauth\Provider\Google, which calls session_start() and breaks
 *     under CLI after stdout output.
 *   - Hits the Sheets API directly via \Google_Client and implements its
 *     own 401-retry with token refresh (mirroring seed-component-procurement-from-gsheet.php).
 */

use Atte\DB\MsaDB;
use Atte\Utils\Locker;

require_once __DIR__ . '/../../config/config.php';

set_time_limit(0);

// ── Single-instance lock ─────────────────────────────────────────────
$locker = new Locker('procurement_history_import.lock');
if ($locker->isLocked()) {
    fwrite(STDERR, "Process is already running.\n");
    exit(1);
}
$locker->lock();
register_shutdown_function(function () use ($locker) {
    if ($locker->isLocked()) { $locker->unlock(); }
});

// ── Flags ────────────────────────────────────────────────────────────
$dryRun = in_array('--dry-run', $argv ?? [], true);
$step   = 'all';
foreach ($argv ?? [] as $a) {
    if (str_starts_with($a, '--step=')) {
        $step = substr($a, 7);
    }
}
if (!in_array($step, ['all', 'rfq', 'po', 'grn'], true)) {
    fwrite(STDERR, "Unknown --step='{$step}'. Use one of: all, rfq, po, grn.\n");
    exit(1);
}

$cliUserId    = null;
$cliMagazineId = null;
foreach ($argv ?? [] as $a) {
    if (str_starts_with($a, '--user='))     { $cliUserId     = (int)substr($a, 7); }
    if (str_starts_with($a, '--magazine=')) { $cliMagazineId = (int)substr($a, 11); }
}

// ── Spreadsheet IDs ──────────────────────────────────────────────────
$SHEET_RFQ = [
    'label'        => 'Lista RFQ',
    'spreadsheetId'=> '19eiRJEs6yvy7r6ZpB74HjS-VwkCVJ5oHE9voCjUOO5U',
    'sheet'        => 'RFQ_list',
    'range'        => 'A1:T50000',
    'expect'       => ['RFQ ID','Creation date','ID Vendor','Vendor Name','ID Order Variants','Vendor PartNo','Producer','Producer PartNo','PartName','PartNo','Qty to Order','Vendor JM','Our PRIVATE Comment','Vendor Full Pack Qty','Leave a comment for a vendor','Add price if you know the price','Add currency','RFQ position','Expected Delivery Date',"Vendor's Offer number"],
];
$SHEET_PO = [
    'label'        => 'Otwarte Zamowienia PO',
    'spreadsheetId'=> '1ELkiaSIUjDfY1wSHG5Pl5Y1MQ1-gJU9Sory1T7RRDwA',
    'sheet'        => 'purchase_order_list',
    'range'        => 'A1:W50000',
    'expect'       => ['PO ID','PO position','PO creation date','Vendor ID','Vendor Name','RFQ ID','Creation date','ID order variants','Vendor PartNo','Producer','Producer PartNo','PartName','PartNo','Qty','Vendor JM','Private Comments','Full Pack QTY','Comment','Price','Currency','Expected Delivery Date',"Vendor's offer number",'Confirmation Date'],
];
// Sheet C has an empty header cell at index 13 (PartNo is unlabeled, but
// the data column sits between PartName[12] and Qty[14]).
$SHEET_GRN = [
    'label'        => 'Lista przyjetych zamowien',
    'spreadsheetId'=> '1F-uzgUWxYfUYaFNPhfcMyGpd8qrkMyXVBb6V_TlYnKA',
    'sheet'        => 'to_warehouse',
    'range'        => 'A1:Y50000',
    // Indices: 0..12 fixed text, 13 must be empty, 14..24 fixed text
    'fixed_prefix' => ['GRN ID','PO ID','PO position','PO creation date','Vendor ID','Vendor Name','RFQ ID','Creation date','ID order variants','Vendor PartNo','Producer','Producer PartNo','PartName'],
    'empty_index'  => 13,
    'fixed_suffix' => ['Qty','Vendor JM','Private Comments','Full Pack QTY','Comment','Price','Currency','Expected Delivery Date',"Vendor's offer number",'Confirmation Date','GRN date'],
];

// ── Google credentials from .env (config.php already loaded it) ─────
if (!defined('GOOGLE_CLIENT_ID')) {
    define('GOOGLE_CLIENT_ID', $_ENV['GOOGLE_CLIENT_ID'] ?? '');
}
if (!defined('GOOGLE_CLIENT_SECRET')) {
    define('GOOGLE_CLIENT_SECRET', $_ENV['GOOGLE_CLIENT_SECRET'] ?? '');
}

// ── Logger: stdout + daily file ──────────────────────────────────────
$logDir  = ROOT_DIRECTORY . '/public_html/var/logs';
if (!is_dir($logDir)) { @mkdir($logDir, 0755, true); }
$logFile = $logDir . '/procurement-history-import-' . date('Y-m-d') . '.log';

function logLine(string $msg) {
    global $logFile, $dryRun;
    $prefix = $dryRun ? '[DRY-RUN] ' : '';
    $line = date('Y-m-d H:i:s') . ' ' . $prefix . $msg;
    echo $line . PHP_EOL;
    @file_put_contents($logFile, $line . PHP_EOL, FILE_APPEND);
}

// ── DB helpers (BaseDB::query() doesn't take params) ─────────────────
function dbFetchAll(MsaDB $db, string $sql, array $params = []): array {
    $stmt = $db->db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(\PDO::FETCH_ASSOC);
}
function dbFetchOne(MsaDB $db, string $sql, array $params = []): ?array {
    $stmt = $db->db->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch(\PDO::FETCH_ASSOC);
    return $row === false ? null : $row;
}
function dbExecute(MsaDB $db, string $sql, array $params = []): int {
    $stmt = $db->db->prepare($sql);
    $stmt->execute($params);
    return $stmt->rowCount();
}

// ── Parsing helpers ──────────────────────────────────────────────────

/**
 * Try parsing one of these formats in order:
 *   - "yyyy-mm-dd HH:MM:SS"     (sheet A Creation date)
 *   - "yyyy-mm-dd HH:MM:SS.uuu" (rare)
 *   - "m/d/yyyy"                (sheet A Expected Delivery Date, sheet B PO creation date, sheet C most dates)
 *   - "m/d/yyyy HH:MM:SS"       (rare)
 *   - "yyyy/mm/dd"              (sheet B Confirmation Date)
 *   - "yyyy/mm/dd HH:MM:SS"
 *
 * Returns ['dt' => DateTime, 'date' => 'Y-m-d'] on success, or null.
 * Anything else (free text, dd.mm.yyyy, etc.) → null and caller decides
 * what to do (per task: "append original text to comment as `Termin: …`").
 */
function parseSheetDate(?string $raw): ?array {
    if ($raw === null) return null;
    $s = trim($raw);
    if ($s === '') return null;

    $formats = [
        'Y-m-d H:i:s.u',
        'Y-m-d H:i:s',
        'Y-m-d',
        'n/j/Y H:i:s',
        'n/j/Y',
        'j/n/Y H:i:s', // ambiguous but harmless if input is well-formed
        'j/n/Y',
        'Y/m/d H:i:s',
        'Y/m/d',
    ];
    foreach ($formats as $fmt) {
        $d = \DateTime::createFromFormat('!' . $fmt, $s);
        if ($d !== false) {
            // Validate the parsed date actually matches (createFromFormat
            // is lenient — "2020-13-40" would parse). Skip impossible dates.
            $errors = \DateTime::getLastErrors();
            if (is_array($errors) && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0)) {
                continue;
            }
            return ['dt' => $d, 'date' => $d->format('Y-m-d')];
        }
    }
    return null;
}

/**
 * Parse a numeric cell. Per task:
 *   - trim, drop spaces
 *   - If both ',' and '.' present: LAST one is decimal separator, the
 *     other is thousands.
 *   - If only ',': for Qty columns treat `^\d{1,3}(,\d{3})+$` as
 *     thousands; otherwise comma is decimal.
 *   - If only '.': standard float.
 *   - Returns null for unparseable strings (caller decides how to log).
 */
function parseSheetNumber(?string $raw, bool $isQty = false): ?float {
    if ($raw === null) return null;
    $s = trim($raw);
    if ($s === '') return null;
    $s = str_replace(' ', '', $s);

    // Quick reject: no digits at all
    if (!preg_match('/\d/', $s)) return null;

    $hasDot = str_contains($s, '.');
    $hasCom = str_contains($s, ',');

    if ($hasDot && $hasCom) {
        // The LAST one is the decimal separator.
        $lastDot = strrpos($s, '.');
        $lastCom = strrpos($s, ',');
        if ($lastCom > $lastDot) {
            // comma is decimal, dot is thousands
            $s = str_replace('.', '', $s);
            $s = str_replace(',', '.', $s);
        } else {
            // dot is decimal, comma is thousands
            $s = str_replace(',', '', $s);
        }
    } elseif ($hasCom) {
        // Only comma present
        if ($isQty && preg_match('/^\d{1,3}(,\d{3})+$/', $s)) {
            // thousands grouping (e.g. "1,000")
            $s = str_replace(',', '', $s);
        } else {
            // comma is decimal
            $s = str_replace(',', '.', $s);
        }
    }
    // else: only dot or no separator — keep as-is

    if (!is_numeric($s)) return null;
    $v = (float)$s;
    return is_finite($v) ? $v : null;
}

/**
 * Parse a currency code cell: upper-trim, fix `UDS`→`USD`, must exist in
 * the supplied code→id map. Returns the id, or null if the value is
 * unrecognised.
 */
function parseSheetCurrency(?string $raw, array $currencyCodeToId): ?int {
    if ($raw === null) return null;
    $s = strtoupper(trim($raw));
    if ($s === '') return null;
    if ($s === 'UDS') $s = 'USD'; // known typo seen in sheet A
    return $currencyCodeToId[$s] ?? null;
}

function isRowBlank(array $row): bool {
    foreach ($row as $v) {
        if (trim((string)$v) !== '') return false;
    }
    return true;
}

// ── CSV skip report ─────────────────────────────────────────────────
$skipCsvPath = $logDir . '/procurement-history-skipped-' . date('Y-m-d') . '.csv';
$skipCsvFh   = null;
function writeSkipRow(string $sheet, int $rowNumber, string $docId, string $position, string $vendorId, string $vendorPartNo, string $partNo, string $reason): void {
    global $skipCsvFh, $skipCsvPath, $dryRun;
    if ($dryRun) return; // never write CSV in dry-run
    if ($skipCsvFh === null) {
        $new = !file_exists($skipCsvPath);
        $skipCsvFh = fopen($skipCsvPath, 'a');
        if ($new) {
            fputcsv($skipCsvFh, ['sheet','row','doc_id','position','vendor_id','vendor_part_no','part_no','reason']);
        }
    }
    fputcsv($skipCsvFh, [$sheet, $rowNumber, $docId, $position, $vendorId, $vendorPartNo, $partNo, $reason]);
}

// ── Stats ────────────────────────────────────────────────────────────
$stats = [
    'rfq' => [
        'docs_read'              => 0,
        'docs_imported'          => 0,
        'skipped_existing'       => 0,
        'skipped_unknown_vendor' => 0,
        'skipped_no_lines'       => 0,
        'skipped_invalid_id'     => 0,
        'failed'                 => 0,
        'lines_imported'         => 0,
        'lines_skipped'          => 0,
        'duplicates_dropped'     => 0,
    ],
    'po' => [
        'docs_read'              => 0,
        'docs_imported'          => 0,
        'skipped_existing'       => 0,
        'skipped_unknown_vendor' => 0,
        'skipped_no_lines'       => 0,
        'skipped_invalid_id'     => 0,
        'failed'                 => 0,
        'lines_imported'         => 0,
        'lines_skipped'          => 0,
        'duplicates_dropped'     => 0,
    ],
    'grn' => [
        'docs_read'              => 0,
        'docs_imported'          => 0,
        'skipped_existing'       => 0,
        'skipped_unknown_po'     => 0,
        'skipped_no_items'       => 0,
        'failed'                 => 0,
        'receipts_imported'      => 0,
        'items_imported'         => 0,
        'items_skipped_no_po_line'=> 0,
        'duplicates_dropped'     => 0,
    ],
    'currency_changes_planned' => [],
    'currency_changes_done'    => [],
    'counter_updates_planned'  => [],
    'counter_updates_done'     => 0,
];

// ── Google Sheets CLI-safe client ────────────────────────────────────
function loadStoredToken(MsaDB $db): ?array {
    $rows = $db->query("SELECT provider_value FROM google_oauth WHERE provider = 'google'");
    if (!$rows || count($rows) === 0) return null;
    $decoded = json_decode($rows[0]['provider_value'], true);
    return is_array($decoded) ? $decoded : null;
}
function refreshAccessToken(\Google_Client $client, MsaDB $db): array {
    $token = loadStoredToken($db);
    $refresh = $token['refresh_token'] ?? null;
    if (!$refresh) throw new \RuntimeException('Brak refresh_token w bazie. Uruchom ponownie OAuth flow.');
    $newToken = $client->fetchAccessTokenWithRefreshToken($refresh);
    if (!is_array($newToken)) throw new \RuntimeException('Refresh response is not an array: ' . var_export($newToken, true));
    if (isset($newToken['error'])) throw new \RuntimeException('Refresh failed: ' . ($newToken['error_description'] ?? $newToken['error']));
    if (empty($newToken['access_token'])) throw new \RuntimeException('Refresh response missing access_token: ' . json_encode($newToken));
    $client->setAccessToken($newToken);
    $newToken['refresh_token'] = $refresh;
    $db->update('google_oauth', ['provider_value' => json_encode($newToken)], 'provider', 'google');
    return $newToken;
}
function buildGoogleClient(MsaDB $db): \Google_Client {
    $token = loadStoredToken($db);
    if (!$token || empty($token['access_token'])) {
        throw new \RuntimeException('Brak tokena Google OAuth w bazie. Uruchom OAuth flow przez /admin/synchronization/sheets.');
    }
    $client = new \Google_Client();
    $client->setClientId(GOOGLE_CLIENT_ID);
    $client->setClientSecret(GOOGLE_CLIENT_SECRET);
    $client->setAccessToken([
        'access_token' => $token['access_token'],
        'expires_in'   => $token['expires_in'] ?? 3600,
    ]);
    return $client;
}
function readSheetCli(\Google_Client $client, MsaDB $db, string $spreadsheetId, string $sheetName, string $range): array {
    $service = new \Google_Service_Sheets($client);
    try {
        $resp = $service->spreadsheets_values->get($spreadsheetId, $sheetName . '!' . $range);
        return $resp->getValues() ?? [];
    } catch (\Exception $e) {
        if ((int)$e->getCode() === 401) {
            logLine('Access token expired, refreshing...');
            refreshAccessToken($client, $db);
            $service = new \Google_Service_Sheets($client);
            $resp = $service->spreadsheets_values->get($spreadsheetId, $sheetName . '!' . $range);
            return $resp->getValues() ?? [];
        }
        throw $e;
    }
}

// ── Sheet fetch + header validation ─────────────────────────────────

/**
 * Read a sheet and find the header row. For sheets A and B the header is
 * at row 1 and matches the expected text exactly. For sheet C the header
 * at index 13 is intentionally empty (PartNo is unlabeled).
 */
function fetchSheet(\Google_Client $client, MsaDB $db, array $cfg): array {
    $raw = readSheetCli($client, $db, $cfg['spreadsheetId'], $cfg['sheet'], $cfg['range']);
    if (!$raw || count($raw) < 1) {
        throw new \RuntimeException("Sheet '{$cfg['sheet']}' is empty.");
    }
    // Find header row by scanning for the first cell of the expected label.
    // Sheets A/B/C all have a unique non-blank value in cell 0 at the header row.
    $expectedFirst = null;
    if ($cfg['sheet'] === 'RFQ_list')            $expectedFirst = 'RFQ ID';
    elseif ($cfg['sheet'] === 'purchase_order_list') $expectedFirst = 'PO ID';
    elseif ($cfg['sheet'] === 'to_warehouse')    $expectedFirst = 'GRN ID';

    $headerIdx = -1;
    for ($i = 0; $i < min(count($raw), 30); $i++) {
        if (!empty($raw[$i]) && trim((string)$raw[$i][0]) === $expectedFirst) {
            $headerIdx = $i;
            break;
        }
    }
    if ($headerIdx < 0) {
        throw new \RuntimeException("Sheet '{$cfg['sheet']}' has no header row containing '$expectedFirst' in column A (first 30 rows scanned).");
    }

    $actual = array_map(fn($v) => trim((string)$v), $raw[$headerIdx]);

    if (isset($cfg['expect'])) {
        // Exact-match header check (sheets A and B).
        if (count($actual) < count($cfg['expect'])) {
            throw new \RuntimeException("Sheet '{$cfg['sheet']}' has only " . count($actual) . " columns; expected at least " . count($cfg['expect']) . ".");
        }
        $diffs = [];
        foreach ($cfg['expect'] as $i => $exp) {
            $act = $actual[$i] ?? '<MISSING>';
            if ($act !== $exp) $diffs[] = "col $i: actual='$act' expected='$exp'";
        }
        if ($diffs) {
            throw new \RuntimeException("Sheet '{$cfg['sheet']}' header mismatch:\n  " . implode("\n  ", $diffs));
        }
    } else {
        // Split header check (sheet C with empty col 13).
        $prefix = $cfg['fixed_prefix'];
        $suffix = $cfg['fixed_suffix'];
        $empty  = $cfg['empty_index'];
        $diffs  = [];
        foreach ($prefix as $i => $exp) {
            $act = $actual[$i] ?? '<MISSING>';
            if ($act !== $exp) $diffs[] = "col $i: actual='$act' expected='$exp'";
        }
        if (($actual[$empty] ?? '') !== '') {
            $diffs[] = "col $empty: actual='" . ($actual[$empty] ?? '') . "' expected='' (PartNo column is unlabeled in this sheet)";
        }
        foreach ($suffix as $j => $exp) {
            $i = $empty + 1 + $j;
            $act = $actual[$i] ?? '<MISSING>';
            if ($act !== $exp) $diffs[] = "col $i: actual='$act' expected='$exp'";
        }
        if ($diffs) {
            throw new \RuntimeException("Sheet '{$cfg['sheet']}' header mismatch:\n  " . implode("\n  ", $diffs));
        }
    }
    logLine("Sheet '{$cfg['sheet']}': header validated at row " . ($headerIdx + 1) . " (" . count($actual) . " columns).");

    // Build data rows (skip blanks).
    $dataRows = [];
    foreach ($raw as $i => $r) {
        if ($i <= $headerIdx) continue;
        if (isRowBlank($r ?? [])) continue;
        $dataRows[$i + 1] = $r; // key = 1-based spreadsheet row number
    }
    logLine("Sheet '{$cfg['sheet']}': " . count($dataRows) . " non-blank data rows.");
    return [$dataRows, $actual];
}

// ── Cache builders ───────────────────────────────────────────────────
function loadCaches(MsaDB $db): array {
    $vendorById = [];
    foreach (dbFetchAll($db, "SELECT id, name, default_currency FROM list__vendor") as $v) {
        $vendorById[(int)$v['id']] = ['id'=>(int)$v['id'], 'name'=>$v['name'], 'default_currency'=>(int)$v['default_currency']];
    }
    $currencyByCode = [];
    $currencyById   = [];
    foreach (dbFetchAll($db, "SELECT id, code FROM list__currency WHERE isActive = 1") as $c) {
        $currencyByCode[strtoupper($c['code'])] = (int)$c['id'];
        $currencyById[(int)$c['id']] = $c['code'];
    }
    // (vendor_id, TRIM(vendor_part_no)) → vendor_part row
    $vpByPair    = [];
    $vpByPartno  = []; // fallback: TRIM(vendor_part_no) (lower-case) → list of vendor_part rows
    foreach (dbFetchAll($db, "SELECT id, vendor_id, producer_id, parts_id, vendor_part_no, vendor_jm_id FROM list__vendor_part WHERE isActive = 1") as $vp) {
        $keyExact = (int)$vp['vendor_id'] . '|' . trim((string)$vp['vendor_part_no']);
        $vpByPair[$keyExact] = $vp;
        $keyCi = (int)$vp['vendor_id'] . '|' . strtolower(trim((string)$vp['vendor_part_no']));
        $vpByPartno[$keyCi][] = $vp;
    }
    return [$vendorById, $currencyByCode, $currencyById, $vpByPair, $vpByPartno];
}

/**
 * Resolve a vendor_part row by (vendor_id, vendor_part_no). Per task:
 * "try exact, then case-insensitive trimmed". Producer is NOT used for
 * matching at all. Returns null if no match.
 */
function resolveVendorPart(int $vendorId, string $vendorPartNo, array $vpByPair, array $vpByPartno): ?array {
    $vpn = trim($vendorPartNo);
    if ($vpn === '') return null;
    $keyExact = $vendorId . '|' . $vpn;
    if (isset($vpByPair[$keyExact])) return $vpByPair[$keyExact];
    $keyCi = $vendorId . '|' . strtolower($vpn);
    if (isset($vpByPartno[$keyCi]) && count($vpByPartno[$keyCi]) === 1) {
        return $vpByPartno[$keyCi][0];
    }
    return null;
}

/**
 * Counter table shape check. The NEW shape (post-migration) is:
 *   - PK (type, vendor_id)
 *   - enum('rfq','po','grn') on `type`
 *   - column `vendor_id`
 * The OLD shape (pre-migration) is:
 *   - PK (year, type)
 *   - enum('rfq','po','pz') on `type`
 * Returns true if NEW shape, false if OLD shape.
 */
function counterTableIsNewShape(MsaDB $db): bool {
    $cols = [];
    foreach (dbFetchAll($db, "SHOW COLUMNS FROM purchase__number_counter") as $c) {
        $cols[$c['Field']] = $c;
    }
    if (!isset($cols['vendor_id'])) return false;
    if (!isset($cols['type'])) return false;
    $typeType = $cols['type']['Type'] ?? '';
    return str_contains($typeType, "'grn'");
}

// ── Doc-ID format check helpers ──────────────────────────────────────
function rfqIdValid(string $s): bool { return (bool)preg_match('/^RFQ\/\d{5}\/\d{5}$/', $s); }
function poIdValid(string $s): bool  { return (bool)preg_match('/^PO\/\d{5}\/\d{5}$/',  $s); }
function grnIdValid(string $s): bool { return (bool)preg_match('/^GRN\/\d{6}$/',         $s); }

/**
 * Extract vendor_id and per-vendor seq from a sheet document number.
 * Returns [vendorId, seq] or [0, 0] if the format doesn't match.
 */
function splitPoOrRfqId(string $num): array {
    if (preg_match('/^(?:RFQ|PO)\/(\d{5})\/(\d{5})$/', $num, $m)) {
        return [(int)$m[1], (int)$m[2]];
    }
    return [0, 0];
}
function splitGrnId(string $num): array {
    if (preg_match('/^GRN\/(\d{6})$/', $num, $m)) {
        return [0, (int)$m[1]];
    }
    return [0, 0];
}

// ── Comment join helper ──────────────────────────────────────────────
function joinCommentParts(array $parts): ?string {
    $clean = [];
    foreach ($parts as $p) {
        if ($p === null) continue;
        $t = trim((string)$p);
        if ($t === '') continue;
        $clean[] = $t;
    }
    return $clean ? implode("\n", $clean) : null;
}

// ── Step: RFQ ────────────────────────────────────────────────────────
function importRfqs(
    MsaDB $db, array $rows, int $userId, array $vendorById,
    array $vpByPair, array $vpByPartno, array &$stats,
    array &$rfqNumberToDbId, bool $dryRun
): void {
    // Bucket rows by RFQ ID; preserve sheet row number for the skip report.
    $byRfq = [];
    foreach ($rows as $rowNum => $r) {
        $id = trim((string)($r[0] ?? ''));
        if ($id === '') continue;
        $byRfq[$id][] = ['row' => $rowNum, 'r' => $r];
    }
    $stats['rfq']['docs_read'] = count($byRfq);
    logLine("RFQ: {$stats['rfq']['docs_read']} distinct RFQ IDs to process.");

    // Re-run safety: bulk-fetch existing rfq_numbers in one query.
    $existingNumbers = [];
    foreach (dbFetchAll($db, "SELECT rfq_number FROM purchase__rfq WHERE rfq_number IS NOT NULL") as $row) {
        $existingNumbers[$row['rfq_number']] = true;
    }
    logLine("RFQ: " . count($existingNumbers) . " already in DB.");

    foreach ($byRfq as $rfqNumber => $entries) {
        if (!rfqIdValid($rfqNumber)) {
            $stats['rfq']['skipped_invalid_id']++;
            logLine("  RFQ $rfqNumber: SKIP invalid id format");
            continue;
        }
        if (isset($existingNumbers[$rfqNumber])) {
            $stats['rfq']['skipped_existing']++;
            continue;
        }

        // Dedupe within document by (rfq_number, position) — keep first.
        $seenPos = [];
        $deduped = [];
        foreach ($entries as $e) {
            $pos = trim((string)($e['r'][17] ?? ''));
            $key = $rfqNumber . '|' . $pos;
            if (isset($seenPos[$key])) {
                $stats['rfq']['duplicates_dropped']++;
                writeSkipRow('A', $e['row'], $rfqNumber, $pos, '', '', '', 'duplicate (rfq, position) within document');
                continue;
            }
            $seenPos[$key] = true;
            $deduped[] = $e;
        }
        $entries = $deduped;
        if (!$entries) continue;

        // Header fields from the first row.
        $first    = $entries[0]['r'];
        $vidRaw   = trim((string)($first[2] ?? ''));
        if (!is_numeric($vidRaw) || !isset($vendorById[(int)$vidRaw])) {
            $stats['rfq']['skipped_unknown_vendor']++;
            logLine("  RFQ $rfqNumber: SKIP unknown vendor_id=$vidRaw");
            writeSkipRow('A', $entries[0]['row'], $rfqNumber, '', $vidRaw, '', '', 'unknown vendor_id');
            continue;
        }
        $vendorId  = (int)$vidRaw;
        $createdAt = parseSheetDate($first[1] ?? null);
        if (!$createdAt) {
            // Couldn't parse Creation date — abort this doc with a clear log.
            $stats['rfq']['failed']++;
            logLine("  RFQ $rfqNumber: FAIL unparseable Creation date '" . trim((string)($first[1] ?? '')) . "'");
            continue;
        }

        // First non-empty vendor_reference across the doc.
        $vendorReference = null;
        foreach ($entries as $e) {
            $vr = trim((string)($e['r'][19] ?? ''));
            if ($vr !== '') { $vendorReference = mb_substr($vr, 0, 128); break; }
        }

        // Has-priced-line + line-level processing.
        $hasPrice  = false;
        $lineInsert = [];
        foreach ($entries as $e) {
            $r = $e['r'];
            $rowNum = $e['row'];
            $vpnRaw = trim((string)($r[5] ?? ''));
            $partNo = trim((string)($r[9]  ?? ''));
            $pos    = trim((string)($r[17] ?? ''));

            $qty = parseSheetNumber($r[10] ?? null, /*isQty*/ true);
            if ($qty === null || $qty <= 0) {
                $stats['rfq']['lines_skipped']++;
                writeSkipRow('A', $rowNum, $rfqNumber, $pos, (string)$vendorId, $vpnRaw, $partNo, 'unparseable or zero qty');
                continue;
            }

            $vp = resolveVendorPart($vendorId, $vpnRaw, $vpByPair, $vpByPartno);
            if ($vp === null) {
                $stats['rfq']['lines_skipped']++;
                writeSkipRow('A', $rowNum, $rfqNumber, $pos, (string)$vendorId, $vpnRaw, $partNo, 'vendor_part not found in list__vendor_part');
                continue;
            }

            $priceRaw = trim((string)($r[15] ?? ''));
            $price    = parseSheetNumber($priceRaw);
            $currency = parseSheetCurrency($r[16] ?? null, $GLOBALS['__currencyCodeToId']);
            $priceOrNull = ($price !== null && $price > 0) ? $price : null;
            if ($priceOrNull !== null) $hasPrice = true;

            // Comment composition per task.
            $parts = [];
            $priv = trim((string)($r[12] ?? ''));
            if ($priv !== '') $parts[] = $priv;
            $vendorCmt = trim((string)($r[14] ?? ''));
            if ($vendorCmt !== '') $parts[] = "Dla dostawcy: $vendorCmt";
            $offerLine = trim((string)($r[19] ?? ''));
            if ($offerLine !== '' && $offerLine !== ($vendorReference ?? '')) {
                $parts[] = "Oferta: $offerLine";
            }
            $dateRaw = trim((string)($r[18] ?? ''));
            $dateParsed = parseSheetDate($dateRaw);
            if (!$dateParsed && $dateRaw !== '') {
                $parts[] = "Termin: $dateRaw";
            }
            $comment = joinCommentParts($parts);

            $lineInsert[] = [
                'row'              => $rowNum,
                'pos'              => $pos,
                'vendor_part_id'   => (int)$vp['id'],
                'quantity'         => $qty,
                'quantity_unit_id' => (int)$vp['vendor_jm_id'],
                'unit_price'       => $priceOrNull,
                'currency'         => $currency ? ($GLOBALS['__currencyById'][$currency] ?? 'PLN') : 'PLN',
                'comment'          => $comment,
            ];
        }

        if (!$lineInsert) {
            $stats['rfq']['skipped_no_lines']++;
            logLine("  RFQ $rfqNumber: SKIP no resolvable lines");
            continue;
        }

        // State + responded_* fields.
        if ($hasPrice) {
            $state        = 'responded';
            $respondedAt  = $createdAt['date'] . ' 00:00:00';
            $respondedBy  = $userId;
        } else {
            $state        = 'sent';
            $respondedAt  = null;
            $respondedBy  = null;
        }

        // Dry-run: log plan, do not write.
        if ($dryRun) {
            $stats['rfq']['docs_imported']++;
            $stats['rfq']['lines_imported'] += count($lineInsert);
            // Mark as planned so downstream steps (counters, PO→RFQ link)
            // can see it. Use a fake negative id (the rfq_number as a unique
            // string key isn't useful for FKs, but for "is this planned?" the
            // presence of the entry in $rfqNumberToDbId is enough).
            $rfqNumberToDbId[$rfqNumber] = -1 - count($rfqNumberToDbId);
            logLine("  RFQ $rfqNumber: PLAN state=$state lines=" . count($lineInsert));
            continue;
        }

        // Real run: transaction.
        $db->db->beginTransaction();
        try {
            $sql = "INSERT INTO purchase__rfq
                    (vendor_id, state, rfq_number, vendor_reference, sent_at,
                     responded_at, responded_by, created_by, comment, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, NULL, ?)";
            $stmt = $db->db->prepare($sql);
            $stmt->execute([
                $vendorId, $state, $rfqNumber, $vendorReference, $createdAt['date'] . ' 00:00:00',
                $respondedAt, $respondedBy, $userId, $createdAt['date'] . ' 00:00:00',
            ]);
            $rfqId = (int)$db->db->lastInsertId();
            $rfqNumberToDbId[$rfqNumber] = $rfqId;

            $itemStmt = $db->db->prepare(
                "INSERT INTO purchase__rfq_item
                 (rfq_id, vendor_part_id, quantity, quantity_unit_id, unit_price, currency, comment, picked_pack_size)
                 VALUES (?, ?, ?, ?, ?, ?, ?, NULL)"
            );
            foreach ($lineInsert as $li) {
                $itemStmt->execute([
                    $rfqId, $li['vendor_part_id'], $li['quantity'], $li['quantity_unit_id'],
                    $li['unit_price'], $li['currency'], $li['comment'],
                ]);
            }
            $db->db->commit();
            $stats['rfq']['docs_imported']++;
            $stats['rfq']['lines_imported'] += count($lineInsert);
            logLine("  RFQ $rfqNumber: imported state=$state lines=" . count($lineInsert));
        } catch (\Throwable $e) {
            if ($db->db->inTransaction()) $db->db->rollBack();
            $stats['rfq']['failed']++;
            logLine("  RFQ $rfqNumber: FAIL " . $e->getMessage());
        }
    }
}

// ── Step: PO ─────────────────────────────────────────────────────────
/**
 * Build PO documents from sheet B (authoritative) and merge in any
 * positions that appear ONLY in sheet C (qty = SUM(C qty)).
 *
 * Returns a map: poNumber => [
 *   'vendor_id', 'created_at', 'confirmed_at', 'rfq_id_in_sheet',
 *   'expected_delivery_date', 'offer_number',
 *   'lines' => [
 *      [pos, qty_ordered, qty_received_from_C, unit_price, currency, comment, rowNum]
 *   ]
 * ]
 */
function buildPoDocuments(array $rowsB, array $rowsC, array $vendorById): array {
    // Sheet B → grouped by PO ID
    $byPo = [];
    foreach ($rowsB as $rowNum => $r) {
        $poId = trim((string)($r[0] ?? ''));
        if ($poId === '') continue;
        $vidRaw = trim((string)($r[3] ?? ''));
        if (!is_numeric($vidRaw) || !isset($vendorById[(int)$vidRaw])) {
            // Unknown vendor — record caller will log & skip.
            $byPo[$poId]['_unknown_vendor'] = $vidRaw;
            $byPo[$poId]['_unknown_row']    = $rowNum;
            continue;
        }
        $byPo[$poId]['vendor_id']  = (int)$vidRaw;
        $byPo[$poId]['created_at_raw'] = parseSheetDate($r[2] ?? null); // PO creation date
        $byPo[$poId]['rfq_id_in_sheet'] = trim((string)($r[5] ?? ''));
        $byPo[$poId]['offer_number']    = trim((string)($r[21] ?? ''));
        $byPo[$poId]['confirmed_at_raw'] = parseSheetDate($r[22] ?? null); // Confirmation Date

        // expected_delivery_date = earliest among lines
        if (!isset($byPo[$poId]['_edd'])) $byPo[$poId]['_edd'] = null;
        $eddRaw = trim((string)($r[20] ?? ''));
        $edd = parseSheetDate($eddRaw);
        if ($edd) {
            $cur = $byPo[$poId]['_edd'];
            if (!$cur || $edd['date'] < $cur) $byPo[$poId]['_edd'] = $edd['date'];
        }

        // Lines keyed by (PO, position). Sheet B is authoritative.
        $pos = trim((string)($r[1] ?? ''));
        $key = $poId . '|' . $pos;
        if (isset($byPo[$poId]['lines'][$key])) {
            // Duplicate within sheet B — caller drops.
            $byPo[$poId]['_dup_keys'][$key] = ($byPo[$poId]['_dup_keys'][$key] ?? 1) + 1;
            continue;
        }
        $qty = parseSheetNumber($r[13] ?? null, /*isQty*/ true);
        $price = parseSheetNumber($r[18] ?? null);
        $byPo[$poId]['lines'][$key] = [
            'row'              => $rowNum,
            'po_id'            => $poId,
            'position'         => $pos,
            'qty_ordered'      => $qty,
            'qty_received_sum' => 0, // filled later from sheet C
            'unit_price'       => $price,
            'currency_raw'     => trim((string)($r[19] ?? '')),
            'private_comments' => trim((string)($r[15] ?? '')),
            'comment'          => trim((string)($r[17] ?? '')),
            'vendor_part_no'   => trim((string)($r[8] ?? '')),
            'part_no'          => trim((string)($r[12] ?? '')),
            'expected_delivery_date_raw' => $eddRaw,
            'source'           => 'B',
        ];
    }

    // Sheet C → only positions not already in B are added.
    // Sheet C column map (no PartNo header):
    //   0 GRN ID, 1 PO ID, 2 PO position, 3 PO creation date, 4 Vendor ID,
    //   5 Vendor Name, 6 RFQ ID, 7 Creation date, 8 ID order variants,
    //   9 Vendor PartNo, 10 Producer, 11 Producer PartNo, 12 PartName,
    //   13 (PartNo - unlabeled), 14 Qty, 15 Vendor JM, 16 Private Comments,
    //   17 Full Pack QTY, 18 Comment, 19 Price, 20 Currency, 21 Expected Delivery Date,
    //   22 Vendor's offer number, 23 Confirmation Date, 24 GRN date
    foreach ($rowsC as $rowNum => $r) {
        $poId = trim((string)($r[1] ?? ''));
        if ($poId === '') continue;
        $pos = trim((string)($r[2] ?? ''));
        $key = $poId . '|' . $pos;
        $qty = parseSheetNumber($r[14] ?? null, /*isQty*/ true);

        if (!isset($byPo[$poId])) {
            // PO appears only in sheet C — bootstrap header info from C.
            $vidRaw = trim((string)($r[4] ?? ''));
            if (!is_numeric($vidRaw) || !isset($vendorById[(int)$vidRaw])) {
                $byPo[$poId]['_unknown_vendor'] = $vidRaw;
                $byPo[$poId]['_unknown_row']    = $rowNum;
                continue;
            }
            $byPo[$poId]['vendor_id']  = (int)$vidRaw;
            $byPo[$poId]['created_at_raw'] = parseSheetDate($r[3] ?? null); // PO creation date from C
            $byPo[$poId]['rfq_id_in_sheet'] = trim((string)($r[6] ?? ''));
            $byPo[$poId]['offer_number']    = trim((string)($r[22] ?? ''));
            $byPo[$poId]['confirmed_at_raw'] = parseSheetDate($r[23] ?? null);
            $byPo[$poId]['lines']           = [];
            $byPo[$poId]['_edd']             = null;
        }

        if (isset($byPo[$poId]['lines'][$key])) {
            // Position already in B — just accumulate received qty.
            if ($qty !== null && $qty > 0) {
                $byPo[$poId]['lines'][$key]['qty_received_sum'] += $qty;
            }
            continue;
        }

        // Position only in C — qty_ordered = SUM(C qty) over rows for this (PO, position).
        // We can't compute the SUM up front; we use the FIRST row's qty as initial
        // qty_ordered, then add subsequent rows to BOTH qty_ordered and qty_received_sum.
        $price = parseSheetNumber($r[19] ?? null);
        if (!isset($byPo[$poId]['lines'][$key])) {
            $byPo[$poId]['lines'][$key] = [
                'row'              => $rowNum,
                'po_id'            => $poId,
                'position'         => $pos,
                'qty_ordered'      => $qty,
                'qty_received_sum' => $qty,
                'unit_price'       => $price,
                'currency_raw'     => trim((string)($r[20] ?? '')),
                'private_comments' => trim((string)($r[16] ?? '')),
                'comment'          => trim((string)($r[18] ?? '')),
                'vendor_part_no'   => trim((string)($r[9] ?? '')),
                'part_no'          => trim((string)($r[13] ?? '')),
                'expected_delivery_date_raw' => trim((string)($r[21] ?? '')),
                'source'           => 'C',
            ];
        }
    }

    // Now: for sheet-C-only positions, fold additional rows into both ordered and received.
    foreach ($byPo as $poId => &$po) {
        if (empty($po['lines'])) continue;
        foreach ($po['lines'] as $key => &$line) {
            if ($line['source'] !== 'C') continue;
            // sum qty across rows in sheet C for this (PO, position)
            $sum = 0.0;
            foreach ($rowsC as $r) {
                $pid = trim((string)($r[1] ?? ''));
                $ppos = trim((string)($r[2] ?? ''));
                if ($pid === $poId && $ppos === $line['position']) {
                    $q = parseSheetNumber($r[14] ?? null, true);
                    if ($q !== null && $q > 0) $sum += $q;
                }
            }
            $line['qty_ordered']      = $sum > 0 ? $sum : $line['qty_ordered'];
            $line['qty_received_sum'] = $sum;
        }
        unset($line);
    }
    unset($po);

    return $byPo;
}

function importPos(
    MsaDB $db, array $poDocs, int $userId, array $vendorById,
    array $vpByPair, array $vpByPartno, array $currencyById,
    array &$stats, array &$rfqNumberToDbId,
    array &$poItemPosMap, int $dryRun
): void {
    $stats['po']['docs_read'] = count($poDocs);
    logLine("PO: {$stats['po']['docs_read']} distinct PO IDs to process.");

    $existingNumbers = [];
    foreach (dbFetchAll($db, "SELECT po_number FROM purchase__order WHERE po_number IS NOT NULL") as $row) {
        $existingNumbers[$row['po_number']] = true;
    }
    logLine("PO: " . count($existingNumbers) . " already in DB.");

    foreach ($poDocs as $poNumber => $po) {
        if (!poIdValid($poNumber)) {
            $stats['po']['skipped_invalid_id']++;
            logLine("  PO $poNumber: SKIP invalid id format");
            continue;
        }
        if (isset($po['_unknown_vendor'])) {
            $stats['po']['skipped_unknown_vendor']++;
            logLine("  PO $poNumber: SKIP unknown vendor_id={$po['_unknown_vendor']}");
            writeSkipRow('B', $po['_unknown_row'] ?? 0, $poNumber, '', (string)($po['_unknown_vendor'] ?? ''), '', '', 'unknown vendor_id');
            continue;
        }
        if (isset($existingNumbers[$poNumber])) {
            $stats['po']['skipped_existing']++;
            continue;
        }
        if (empty($po['lines'])) {
            $stats['po']['skipped_no_lines']++;
            logLine("  PO $poNumber: SKIP no lines");
            continue;
        }
        if (!$po['created_at_raw']) {
            $stats['po']['failed']++;
            logLine("  PO $poNumber: FAIL unparseable PO creation date");
            continue;
        }
        $vendorId  = (int)$po['vendor_id'];
        $createdAt = $po['created_at_raw'];
        $confirmed = $po['confirmed_at_raw'] ?? null;

        $rfqDbId = null;
        if ($po['rfq_id_in_sheet'] !== '') {
            $rfqDbId = $rfqNumberToDbId[$po['rfq_id_in_sheet']] ?? null;
        }

        $lineInsert = [];
        $receivedTotal = 0.0;
        $orderedTotal  = 0.0;
        $hasPrice = false;
        $lineCount = 0;
        foreach ($po['lines'] as $key => $line) {
            $lineCount++;
            $qtyOrd = $line['qty_ordered'];
            if ($qtyOrd === null || $qtyOrd <= 0) {
                $stats['po']['lines_skipped']++;
                writeSkipRow($line['source'] === 'C' ? 'C' : 'B', $line['row'], $poNumber, $line['position'], (string)$vendorId, $line['vendor_part_no'], $line['part_no'], 'unparseable or zero ordered qty');
                continue;
            }
            $vp = resolveVendorPart($vendorId, $line['vendor_part_no'], $vpByPair, $vpByPartno);
            if ($vp === null) {
                $stats['po']['lines_skipped']++;
                writeSkipRow($line['source'] === 'C' ? 'C' : 'B', $line['row'], $poNumber, $line['position'], (string)$vendorId, $line['vendor_part_no'], $line['part_no'], 'vendor_part not found in list__vendor_part');
                continue;
            }
            $price = $line['unit_price'];
            // purchase__order_item.unit_price is now nullable (NULL = "price
            // not set yet"), but this historical import deliberately still
            // stores 0.0 for an unparseable sheet price: it treats a missing
            // price as zero, consistent with the rows already in the table.
            // The PO price gate accepts both 0 and NULL as "price missing".
            $priceStore = ($price !== null && $price > 0) ? $price : 0.0;
            if ($priceStore > 0) $hasPrice = true;
            $cur = parseSheetCurrency($line['currency_raw'], $GLOBALS['__currencyCodeToId']);
            $currency = $cur ? ($currencyById[$cur] ?? 'PLN') : 'PLN';

            $commentParts = [];
            if ($line['private_comments'] !== '') $commentParts[] = $line['private_comments'];
            if ($line['comment'] !== '') $commentParts[] = $line['comment'];
            $comment = joinCommentParts($commentParts);

            $lineInsert[] = [
                'row'              => $line['row'],
                'position'         => $line['position'],
                'vendor_part_id'   => (int)$vp['id'],
                'quantity'         => $qtyOrd,
                'quantity_received'=> $line['qty_received_sum'] > 0 ? $line['qty_received_sum'] : 0.0,
                'quantity_unit_id' => (int)$vp['vendor_jm_id'],
                'unit_price'       => $priceStore,
                'currency'         => $currency,
                'comment'          => $comment,
                'source'           => $line['source'],
            ];
            $orderedTotal  += $qtyOrd;
            $receivedTotal += $line['qty_received_sum'] > 0 ? $line['qty_received_sum'] : 0.0;
        }
        if (!$lineInsert) {
            $stats['po']['skipped_no_lines']++;
            logLine("  PO $poNumber: SKIP no resolvable lines");
            continue;
        }

        // PO state.
        if ($receivedTotal > 0 && $receivedTotal >= $orderedTotal) $state = 'received';
        elseif ($receivedTotal > 0)                                  $state = 'partially_received';
        elseif ($confirmed)                                          $state = 'confirmed';
        else                                                         $state = 'sent';

        // PO header comment.
        $poCommentParts = [];
        if ($po['offer_number'] !== '') $poCommentParts[] = "Oferta dostawcy: " . $po['offer_number'];
        $poComment = joinCommentParts($poCommentParts);

        if ($dryRun) {
            $stats['po']['docs_imported']++;
            $stats['po']['lines_imported'] += count($lineInsert);
            // Mark (PO, position) → fake id so counters + GRN dry-run see it.
            foreach ($lineInsert as $li) {
                $poItemPosMap[$poNumber . '|' . $li['position']] = -1 - count($poItemPosMap);
            }
            logLine("  PO $poNumber: PLAN state=$state lines=" . count($lineInsert) . " ordered=$orderedTotal received=$receivedTotal");
            continue;
        }

        $db->db->beginTransaction();
        try {
            $sql = "INSERT INTO purchase__order
                    (vendor_id, state, po_number, vendor_po_number, converted_from_rfq_id,
                     expected_delivery_date, sent_at, confirmed_at, created_by, comment, created_at)
                    VALUES (?, ?, ?, NULL, ?, ?, ?, ?, ?, ?, ?)";
            $stmt = $db->db->prepare($sql);
            $stmt->execute([
                $vendorId, $state, $poNumber, $rfqDbId,
                $po['_edd'] ?? null, $createdAt['date'] . ' 00:00:00',
                $confirmed ? $confirmed['date'] . ' 00:00:00' : null,
                $userId, $poComment, $createdAt['date'] . ' 00:00:00',
            ]);
            $poDbId = (int)$db->db->lastInsertId();

            $itemStmt = $db->db->prepare(
                "INSERT INTO purchase__order_item
                 (po_id, vendor_part_id, quantity, quantity_unit_id, unit_price, currency, quantity_received, comment, picked_pack_size)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, NULL)"
            );
            foreach ($lineInsert as $li) {
                $itemStmt->execute([
                    $poDbId, $li['vendor_part_id'], $li['quantity'], $li['quantity_unit_id'],
                    $li['unit_price'], $li['currency'], $li['quantity_received'], $li['comment'],
                ]);
                $poItemId = (int)$db->db->lastInsertId();
                $poItemPosMap[$poNumber . '|' . $li['position']] = $poItemId;
            }
            // Mirror PurchaseActionHandler::convertRfqToPo(): the source RFQ
            // becomes 'converted' once a PO references it. Keep updated_at.
            if ($rfqDbId !== null) {
                $db->db->prepare(
                    "UPDATE purchase__rfq SET state = 'converted', updated_at = updated_at
                     WHERE id = ? AND state <> 'converted'"
                )->execute([$rfqDbId]);
            }
            $db->db->commit();
            $stats['po']['docs_imported']++;
            $stats['po']['lines_imported'] += count($lineInsert);
            logLine("  PO $poNumber: imported state=$state lines=" . count($lineInsert));
        } catch (\Throwable $e) {
            if ($db->db->inTransaction()) $db->db->rollBack();
            $stats['po']['failed']++;
            logLine("  PO $poNumber: FAIL " . $e->getMessage());
        }
    }
}

// ── Step: GRN ────────────────────────────────────────────────────────
/**
 * Sheet C column map (no PartNo header at col 13):
 *   0 GRN ID, 1 PO ID, 2 PO position, 3 PO creation date, 4 Vendor ID,
 *   5 Vendor Name, 6 RFQ ID, 7 Creation date, 8 ID order variants,
 *   9 Vendor PartNo, 10 Producer, 11 Producer PartNo, 12 PartName,
 *   13 (PartNo - unlabeled), 14 Qty, 15 Vendor JM, 16 Private Comments,
 *   17 Full Pack QTY, 18 Comment, 19 Price, 20 Currency, 21 Expected Delivery Date,
 *   22 Vendor's offer number, 23 Confirmation Date, 24 GRN date
 */
function importGrns(
    MsaDB $db, array $rows, int $userId, int $magazineId, array $currencyById,
    array &$stats, array &$poItemPosMap, int $dryRun
): void {
    // Dedupe by GRN ID (keep first).
    $seenGrn = [];
    $deduped = [];
    foreach ($rows as $rowNum => $r) {
        $grnId = trim((string)($r[0] ?? ''));
        if ($grnId === '') continue;
        if (!grnIdValid($grnId)) {
            $stats['grn']['duplicates_dropped']++;
            continue;
        }
        if (isset($seenGrn[$grnId])) {
            $stats['grn']['duplicates_dropped']++;
            writeSkipRow('C', $rowNum, $grnId, trim((string)($r[2] ?? '')), '', '', '', 'duplicate GRN id within sheet');
            continue;
        }
        $seenGrn[$grnId] = true;
        $deduped[$rowNum] = $r;
    }
    $rows = $deduped;

    // Group by (PO ID, GRN date). Each group → one receipt.
    $groups = [];
    foreach ($rows as $rowNum => $r) {
        $poId = trim((string)($r[1] ?? ''));
        $grnDateRaw = trim((string)($r[24] ?? ''));
        $grnDate = parseSheetDate($grnDateRaw);
        $key = $poId . '|' . ($grnDate['date'] ?? '__NULL__');
        if (!isset($groups[$key])) {
            $groups[$key] = [
                'po_id'         => $poId,
                'grn_date'      => $grnDate,
                'grn_ids'       => [],
                'lines'         => [],
                'first_row_num' => $rowNum,
            ];
        }
        $groups[$key]['grn_ids'][] = trim((string)($r[0] ?? ''));
        $groups[$key]['lines'][]   = ['row' => $rowNum, 'r' => $r];
    }
    $stats['grn']['docs_read'] = count($groups);
    logLine("GRN: {$stats['grn']['docs_read']} receipt groups (PO + GRN date).");

    // Existing receipt document numbers (for skip-existing).
    $existingDocNumbers = [];
    foreach (dbFetchAll($db, "SELECT document_number FROM purchase__order_receipt WHERE document_number IS NOT NULL") as $row) {
        $existingDocNumbers[$row['document_number']] = true;
    }

    foreach ($groups as $key => $grp) {
        $grnIds = $grp['grn_ids'];
        $docNum = $grnIds[0]; // smallest GRN id in group
        foreach ($grnIds as $gid) {
            if (strcmp($gid, $docNum) < 0) $docNum = $gid;
        }
        if (isset($existingDocNumbers[$docNum])) {
            $stats['grn']['skipped_existing']++;
            continue;
        }
        $poId = $grp['po_id'];
        if (!poIdValid($poId)) {
            $stats['grn']['skipped_unknown_po']++;
            logLine("  GRN doc=$docNum: SKIP invalid PO id '$poId'");
            continue;
        }
        // Look up the PO. Re-run rule: only POs imported by THIS run are
        // eligible (existing POs are skipped because we cannot reliably map
        // receipt items to existing items). In dry-run the import step
        // populated $poItemPosMap with planned positions, so we can detect
        // "this PO is in our plan" even without touching the DB.
        $poDbId = null;
        if ($dryRun) {
            // Any planned position for this PO means the PO is in scope.
            $hasPlannedPos = false;
            foreach ($poItemPosMap as $k => $_id) {
                if (str_starts_with($k, $poId . '|')) { $hasPlannedPos = true; break; }
            }
            if (!$hasPlannedPos) {
                $stats['grn']['skipped_unknown_po']++;
                continue;
            }
            // Use a fake id (any negative int) — never inserted in dry-run.
            $poDbId = -1;
        } else {
            $poExistsInDb = dbFetchOne($db, "SELECT id FROM purchase__order WHERE po_number = ?", [$poId]);
            if (!$poExistsInDb) {
                $stats['grn']['skipped_unknown_po']++;
                logLine("  GRN doc=$docNum PO=$poId: SKIP PO not in purchase__order (caller should run --step=po first or this PO is in a previous-run batch)");
                continue;
            }
            $poDbId = (int)$poExistsInDb['id'];
        }

        $grnDate = $grp['grn_date'];
        if (!$grnDate) {
            // Unparseable GRN date → skip the whole receipt (need a date).
            $stats['grn']['failed']++;
            logLine("  GRN doc=$docNum: FAIL unparseable GRN date");
            continue;
        }
        $receivedAt = $grnDate['date'] . ' 00:00:00';

        $lineItems = [];
        $skipNoPoLine = 0;
        foreach ($grp['lines'] as $line) {
            $r = $line['r'];
            $rowNum = $line['row'];
            $pos = trim((string)($r[2] ?? ''));
            $poItemKey = $poId . '|' . $pos;
            $poItemId = $poItemPosMap[$poItemKey] ?? null;
            if ($poItemId === null) {
                // Either PO line didn't import, or position is unknown.
                $stats['grn']['items_skipped_no_po_line']++;
                $skipNoPoLine++;
                writeSkipRow('C', $rowNum, $poId, $pos, '', '', '', 'PO line not imported (no matching po_item)');
                continue;
            }
            $qty = parseSheetNumber($r[14] ?? null, /*isQty*/ true);
            if ($qty === null || $qty <= 0) {
                $stats['grn']['items_skipped_no_po_line']++;
                writeSkipRow('C', $rowNum, $poId, $pos, '', '', '', 'unparseable or zero receipt qty');
                $skipNoPoLine++;
                continue;
            }
            // Drop purely-numeric Private Comments per task (those are
            // pack-size numbers; not real comments).
            $privRaw = trim((string)($r[16] ?? ''));
            $privKeep = null;
            if ($privRaw !== '' && parseSheetNumber($privRaw, true) === null) {
                $privKeep = $privRaw;
            }
            $lineItems[] = [
                'row'              => $rowNum,
                'po_item_id'       => $poItemId,
                'quantity_received'=> $qty,
                'comment'          => $privKeep,
            ];
        }
        if (!$lineItems) {
            $stats['grn']['skipped_no_items']++;
            logLine("  GRN doc=$docNum: SKIP no items (skipped $skipNoPoLine line(s) with no PO line)");
            continue;
        }

        $commentText = 'Import z arkusza. GRN: ' . implode(', ', $grnIds);

        if ($dryRun) {
            $stats['grn']['receipts_imported']++;
            $stats['grn']['items_imported'] += count($lineItems);
            logLine("  GRN doc=$docNum PO=$poId date={$grnDate['date']}: PLAN items=" . count($lineItems) . " skipped_no_po_line=$skipNoPoLine");
            continue;
        }

        $db->db->beginTransaction();
        try {
            $stmt = $db->db->prepare(
                "INSERT INTO purchase__order_receipt (po_id, document_number, received_by, received_at, comment)
                 VALUES (?, ?, ?, ?, ?)"
            );
            $stmt->execute([$poDbId, $docNum, $userId, $receivedAt, $commentText]);
            $receiptId = (int)$db->db->lastInsertId();

            $itemStmt = $db->db->prepare(
                "INSERT INTO purchase__order_receipt_item (receipt_id, po_item_id, quantity_received, sub_magazine_id, comment)
                 VALUES (?, ?, ?, ?, ?)"
            );
            // Update quantity_received on po_item (already pre-set from sheet C; add this delta).
            $updStmt = $db->db->prepare(
                "UPDATE purchase__order_item SET quantity_received = quantity_received + ? WHERE id = ?"
            );
            foreach ($lineItems as $li) {
                $itemStmt->execute([$receiptId, $li['po_item_id'], $li['quantity_received'], $magazineId, $li['comment']]);
                $updStmt->execute([$li['quantity_received'], $li['po_item_id']]);
            }
            $db->db->commit();
            $stats['grn']['receipts_imported']++;
            $stats['grn']['items_imported'] += count($lineItems);
            logLine("  GRN doc=$docNum PO=$poId date={$grnDate['date']}: imported items=" . count($lineItems) . " skipped_no_po_line=$skipNoPoLine");
        } catch (\Throwable $e) {
            if ($db->db->inTransaction()) $db->db->rollBack();
            $stats['grn']['failed']++;
            logLine("  GRN doc=$docNum: FAIL " . $e->getMessage());
        }
    }
}

// ── Step: Vendor default currency ────────────────────────────────────
function planAndApplyCurrencyChanges(
    MsaDB $db, array $rowsB, array $rowsC, array $vendorById, array $currencyByCode, array $currencyById,
    array $vpByPair, array $vpByPartno, int $dryRun, array &$stats
): void {
    // For each vendor, find the MOST RECENT priced line (price > 0 + currency
    // present) across all sheets.
    //   - GRN date (sheet C col 24)
    //   - PO creation date (sheet B col 2)
    //   - RFQ creation date (sheet A col 1)
    // Use the latest of these per (vendor, currency) candidate.
    // Per task: "by line date: GRN date > PO creation date > RFQ creation date".
    // The priority is the SHEET — the LATEST dated line in that sheet wins.

    $candidates = []; // vendor_id => ['date' => 'Y-m-d', 'currency' => 'CODE', 'source' => 'grn|po|rfq']

    // Sheet C (GRN): each row's col 24 is GRN date; col 20 is Currency; col 19 is Price.
    foreach ($rowsC as $r) {
        $vidRaw = trim((string)($r[4] ?? ''));
        if (!is_numeric($vidRaw)) continue;
        $vid = (int)$vidRaw;
        if (!isset($vendorById[$vid])) continue;
        $price = parseSheetNumber($r[19] ?? null);
        if ($price === null || $price <= 0) continue;
        $curRaw = trim((string)($r[20] ?? ''));
        $cur = parseSheetCurrency($curRaw, $currencyByCode);
        if (!$cur) continue;
        $date = parseSheetDate($r[24] ?? null);
        if (!$date) continue;
        $code = $currencyById[$cur] ?? null;
        if (!$code) continue;
        $curCand = &$candidates[$vid];
        if (!isset($curCand) || $date['date'] > $curCand['date']) {
            $curCand = ['date' => $date['date'], 'currency' => $code, 'currency_id' => $cur, 'source' => 'grn'];
        }
        unset($curCand);
    }
    // Sheet B (PO): col 2 = PO creation date, col 19 = Currency, col 18 = Price.
    foreach ($rowsB as $r) {
        $vidRaw = trim((string)($r[3] ?? ''));
        if (!is_numeric($vidRaw)) continue;
        $vid = (int)$vidRaw;
        if (!isset($vendorById[$vid])) continue;
        $price = parseSheetNumber($r[18] ?? null);
        if ($price === null || $price <= 0) continue;
        $curRaw = trim((string)($r[19] ?? ''));
        $cur = parseSheetCurrency($curRaw, $currencyByCode);
        if (!$cur) continue;
        $date = parseSheetDate($r[2] ?? null);
        if (!$date) continue;
        $code = $currencyById[$cur] ?? null;
        if (!$code) continue;
        // Sheet C is preferred over sheet B per task priority (GRN > PO > RFQ),
        // so only update from B if there's no GRN candidate.
        $curCand = &$candidates[$vid];
        if (!isset($curCand) || ($curCand['source'] !== 'grn' && $date['date'] > $curCand['date'])) {
            $curCand = ['date' => $date['date'], 'currency' => $code, 'currency_id' => $cur, 'source' => 'po'];
        }
        unset($curCand);
    }

    // Sheet A (RFQ): col 1 = Creation date, col 16 = Currency, col 15 = Price.
    // Not in rowsB/rowsC — we need to pass them in. Adjust signature:
    // Done via global $rowsA reference (set before calling this fn).
    // (We attach via a static-ish approach: caller passes rowsA.)
    // Below we do it inline to keep the helper's signature simple.
    // (See caller for the explicit rowsA loop.)

    // Now compare with list__vendor.default_currency.
    foreach ($candidates as $vid => $info) {
        $currentId = (int)$vendorById[$vid]['default_currency'];
        $currentCode = $currencyById[$currentId] ?? '?';
        if ($currentId === $info['currency_id']) {
            continue; // no change
        }
        $stats['currency_changes_planned'][] = [
            'vendor_id' => $vid,
            'vendor_name'=> $vendorById[$vid]['name'],
            'from'      => $currentCode,
            'to'        => $info['currency'],
            'source'    => $info['source'],
            'date'      => $info['date'],
        ];
    }
}

function planRfqCurrencyCandidates(array $rowsA, array $vendorById, array $currencyByCode, array $currencyById, array &$candidates): void {
    foreach ($rowsA as $r) {
        $vidRaw = trim((string)($r[2] ?? ''));
        if (!is_numeric($vidRaw)) continue;
        $vid = (int)$vidRaw;
        if (!isset($vendorById[$vid])) continue;
        $price = parseSheetNumber($r[15] ?? null);
        if ($price === null || $price <= 0) continue;
        $cur = parseSheetCurrency($r[16] ?? null, $currencyByCode);
        if (!$cur) continue;
        $date = parseSheetDate($r[1] ?? null);
        if (!$date) continue;
        $code = $currencyById[$cur] ?? null;
        if (!$code) continue;
        // Only used if no GRN/PO candidate exists.
        $curCand = &$candidates[$vid];
        if (!isset($curCand)) {
            $curCand = ['date' => $date['date'], 'currency' => $code, 'currency_id' => $cur, 'source' => 'rfq'];
        }
        unset($curCand);
    }
}

// ── Main ─────────────────────────────────────────────────────────────
logLine("=== Start procurement history import ===");
logLine("Mode: " . ($dryRun ? 'DRY-RUN (no writes)' : 'LIVE'));
logLine("Step: $step");

// 0. Resolve --user / --magazine.
$MsaDB = MsaDB::getInstance();

if ($cliUserId !== null) {
    $u = dbFetchOne($MsaDB, "SELECT user_id, isAdmin, isActive, sub_magazine_id FROM user WHERE user_id = ?", [$cliUserId]);
    if (!$u || (int)$u['isAdmin'] !== 1 || (int)$u['isActive'] !== 1) {
        fwrite(STDERR, "--user=$cliUserId is not an active admin user. Aborting.\n");
        exit(1);
    }
    $userId = (int)$u['user_id'];
} else {
    $u = dbFetchOne($MsaDB, "SELECT user_id, sub_magazine_id FROM user WHERE isAdmin = 1 AND isActive = 1 ORDER BY user_id LIMIT 1");
    if (!$u) {
        fwrite(STDERR, "No active admin user in DB. Aborting.\n");
        exit(1);
    }
    $userId = (int)$u['user_id'];
}
logLine("Using user_id=$userId" . ($cliUserId !== null ? " (from --user)" : " (default: lowest active admin)"));

if ($cliMagazineId !== null) {
    $m = dbFetchOne($MsaDB, "SELECT sub_magazine_id FROM magazine__list WHERE sub_magazine_id = ?", [$cliMagazineId]);
    if (!$m) {
        fwrite(STDERR, "--magazine=$cliMagazineId does not exist in magazine__list. Aborting.\n");
        exit(1);
    }
    $magazineId = $cliMagazineId;
} else {
    $magazineId = (int)($u['sub_magazine_id'] ?? 0);
    $m = dbFetchOne($MsaDB, "SELECT sub_magazine_id FROM magazine__list WHERE sub_magazine_id = ?", [$magazineId]);
    if (!$m) {
        fwrite(STDERR, "User $userId has sub_magazine_id=$magazineId which does not exist in magazine__list. Pass --magazine=<id> explicitly. Aborting.\n");
        exit(1);
    }
}
logLine("Using magazine_id=$magazineId" . ($cliMagazineId !== null ? " (from --magazine)" : " (from user.sub_magazine_id)"));

// 1. Counter table shape check.
$newShape = counterTableIsNewShape($MsaDB);
if ($newShape) {
    logLine("Counter table: NEW shape detected (PK=(type, vendor_id), enum includes 'grn'). Proceeding.");
} else {
    if ($dryRun) {
        logLine("WARNING: Counter table is OLD shape (PK=(year, type), enum='rfq','po','pz'). Counter step will be SKIPPED in this dry-run. Run `php src/cron/migrate-to-procurement-schema.php` before the real run.");
    } else {
        fwrite(STDERR, "Counter table is OLD shape. Run php src/cron/migrate-to-procurement-schema.php first.\n");
        exit(1);
    }
}

// 2. Caches.
[$vendorById, $currencyCodeToId, $currencyById, $vpByPair, $vpByPartno] = loadCaches($MsaDB);
// Expose to functions that need them without threading args everywhere.
$GLOBALS['__currencyCodeToId'] = $currencyCodeToId;
$GLOBALS['__currencyById']     = $currencyById;
logLine("Caches loaded: vendors=" . count($vendorById) . " currencies=" . count($currencyById) . " vendor_parts=" . count($vpByPair) . " (with ci aliases=" . count($vpByPartno) . ")");

// 3. Fetch sheets.
$client = buildGoogleClient($MsaDB);

logLine("Fetching sheet A (RFQ)...");
[$rowsA, $hdrA] = fetchSheet($client, $MsaDB, $SHEET_RFQ);

logLine("Fetching sheet B (PO)...");
[$rowsB, $hdrB] = fetchSheet($client, $MsaDB, $SHEET_PO);

logLine("Fetching sheet C (GRN)...");
[$rowsC, $hdrC] = fetchSheet($client, $MsaDB, $SHEET_GRN);

// 4. Run steps.
$rfqNumberToDbId = [];
$poItemPosMap    = [];

if ($step === 'all' || $step === 'rfq') {
    logLine("=== Step: RFQ ===");
    importRfqs($MsaDB, $rowsA, $userId, $vendorById, $vpByPair, $vpByPartno, $stats, $rfqNumberToDbId, $dryRun);
}

if ($step === 'all' || $step === 'po') {
    logLine("=== Step: PO ===");
    $poDocs = buildPoDocuments($rowsB, $rowsC, $vendorById);
    importPos($MsaDB, $poDocs, $userId, $vendorById, $vpByPair, $vpByPartno, $currencyById, $stats, $rfqNumberToDbId, $poItemPosMap, $dryRun ? 1 : 0);
}

if ($step === 'all' || $step === 'grn') {
    logLine("=== Step: GRN ===");
    importGrns($MsaDB, $rowsC, $userId, $magazineId, $currencyById, $stats, $poItemPosMap, $dryRun ? 1 : 0);
}

// 5. Vendor default currency — plan + apply.
logLine("=== Step: vendor default currency ===");
$candidates = [];
planAndApplyCurrencyChanges($MsaDB, $rowsB, $rowsC, $vendorById, $currencyCodeToId, $currencyById, $vpByPair, $vpByPartno, $dryRun, $stats);
planRfqCurrencyCandidates($rowsA, $vendorById, $currencyCodeToId, $currencyById, $candidates);
// We appended candidates only via the rfq-typed path; the first two loops
// wrote to $stats['currency_changes_planned'] directly via &$candidates
// reference inside planAndApplyCurrencyChanges? Actually no —
// planAndApplyCurrencyChanges works on its own internal $candidates. To
// merge in RFQ candidates before comparing, we run another pass.
foreach ($candidates as $vid => $info) {
    $currentId = (int)$vendorById[$vid]['default_currency'];
    if ($currentId === $info['currency_id']) continue;
    // Only add if not already planned by grn/po.
    $already = false;
    foreach ($stats['currency_changes_planned'] as $e) {
        if ($e['vendor_id'] === $vid) { $already = true; break; }
    }
    if ($already) continue;
    $stats['currency_changes_planned'][] = [
        'vendor_id' => $vid,
        'vendor_name'=> $vendorById[$vid]['name'],
        'from'      => $currencyById[$currentId] ?? '?',
        'to'        => $info['currency'],
        'source'    => $info['source'],
        'date'      => $info['date'],
    ];
}

// Now apply.
$planned = $stats['currency_changes_planned'];
if (!$planned) {
    logLine("Vendor currency: no changes planned.");
} else {
    logLine("Vendor currency: " . count($planned) . " planned change(s).");
    foreach (array_slice($planned, 0, 10) as $change) {
        logLine(sprintf("  vendor_id=%d (%s): %s -> %s (source=%s, line_date=%s)",
            $change['vendor_id'], $change['vendor_name'], $change['from'], $change['to'],
            $change['source'], $change['date']));
    }
    if (!$dryRun && count($planned) > 10) {
        logLine("  ... and " . (count($planned) - 10) . " more");
    }
    if (!$dryRun) {
        $db = $MsaDB;
        $db->db->beginTransaction();
        try {
            foreach ($planned as $change) {
                // Re-resolve to id (we have the code in 'to').
                $toCode = $change['to'];
                $toId = null;
                foreach ($currencyCodeToId as $code => $id) {
                    if ($code === $toCode) { $toId = $id; break; }
                }
                if ($toId === null) {
                    logLine("  vendor_id={$change['vendor_id']}: SKIP currency update (code '$toCode' not in list__currency)");
                    continue;
                }
                dbExecute($db, "UPDATE list__vendor SET default_currency = ? WHERE id = ?", [$toId, $change['vendor_id']]);
                $stats['currency_changes_done'][] = $change;
            }
            $db->db->commit();
            logLine("Vendor currency: applied " . count($stats['currency_changes_done']) . " change(s).");
        } catch (\Throwable $e) {
            if ($db->db->inTransaction()) $db->db->rollBack();
            logLine("Vendor currency: FAIL " . $e->getMessage());
        }
    }
}

// 6. Number counters (RFQ/PO/GRN). Plan first, then apply.
logLine("=== Step: number counters ===");
$counterPlanned = [];
// RFQ and PO seqs from rfqNumberToDbId (already imported this run).
foreach ($rfqNumberToDbId as $rfqNum => $_id) {
    [$vid, $seq] = splitPoOrRfqId($rfqNum);
    if ($vid > 0 && $seq > 0) {
        $counterPlanned['rfq|' . $vid] = ['type' => 'rfq', 'vendor_id' => $vid, 'last_value' => $seq];
    }
}
foreach ($poItemPosMap as $poNumPosKey => $_poItemId) {
    $poNum = explode('|', $poNumPosKey)[0];
    [$vid, $seq] = splitPoOrRfqId($poNum);
    if ($vid > 0 && $seq > 0) {
        $cur = $counterPlanned['po|' . $vid] ?? null;
        if (!$cur || $seq > $cur['last_value']) {
            $counterPlanned['po|' . $vid] = ['type' => 'po', 'vendor_id' => $vid, 'last_value' => $seq];
        }
    }
}
// GRN: vendor_id = 0, seq from imported receipt document_number
$stats['grn']['receipts_imported'] = $stats['grn']['receipts_imported'] ?? 0;
$existingDocNumbers = [];
foreach (dbFetchAll($MsaDB, "SELECT document_number FROM purchase__order_receipt WHERE document_number IS NOT NULL") as $row) {
    $existingDocNumbers[$row['document_number']] = true;
}
$maxGrnSeq = 0;
foreach (array_keys($counterPlanned) as $k) { /* placeholder */ }
foreach (dbFetchAll($MsaDB, "SELECT document_number FROM purchase__order_receipt WHERE document_number LIKE 'GRN/%'") as $row) {
    if (preg_match('/^GRN\/(\d{6})$/', $row['document_number'], $m)) {
        $seq = (int)$m[1];
        if ($seq > $maxGrnSeq) $maxGrnSeq = $seq;
    }
}
if ($maxGrnSeq > 0) {
    $counterPlanned['grn|0'] = ['type' => 'grn', 'vendor_id' => 0, 'last_value' => $maxGrnSeq];
}
logLine("Counter step: " . count($counterPlanned) . " row(s) planned.");
foreach (array_slice($counterPlanned, 0, 10, true) as $row) {
    logLine(sprintf("  planned: type=%s vendor_id=%d last_value=%d", $row['type'], $row['vendor_id'], $row['last_value']));
}
$stats['counter_updates_planned'] = array_values($counterPlanned);

if (!$dryRun && $counterPlanned) {
    if (!$newShape) {
        logLine("Counter step: SKIPPED (counter table is OLD shape; run migrate-to-procurement-schema.php first).");
    } else {
        $db = $MsaDB;
        $db->db->beginTransaction();
        try {
            $stmt = $db->db->prepare(
                "INSERT INTO purchase__number_counter (`type`, `vendor_id`, `last_value`)
                 VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE `last_value` = GREATEST(`last_value`, VALUES(`last_value`))"
            );
            foreach ($counterPlanned as $row) {
                $stmt->execute([$row['type'], $row['vendor_id'], $row['last_value']]);
                $stats['counter_updates_done']++;
            }
            $db->db->commit();
            logLine("Counter step: applied " . $stats['counter_updates_done'] . " update(s).");
        } catch (\Throwable $e) {
            if ($db->db->inTransaction()) $db->db->rollBack();
            logLine("Counter step: FAIL " . $e->getMessage());
        }
    }
}

// 7. Stats summary.
logLine("");
logLine("=== SUMMARY ===");
foreach (['rfq', 'po', 'grn'] as $s) {
    logLine(strtoupper($s) . ": " . json_encode($stats[$s], JSON_UNESCAPED_UNICODE));
}
logLine("Currency changes planned: " . count($stats['currency_changes_planned']));
logLine("Currency changes done:    " . count($stats['currency_changes_done']));
logLine("Counter updates planned:  " . count($stats['counter_updates_planned']));
logLine("Counter updates done:     " . $stats['counter_updates_done']);

logLine("Log file: $logFile");
logLine("=== Done ===");