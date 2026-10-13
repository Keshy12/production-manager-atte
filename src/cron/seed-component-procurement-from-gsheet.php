<?php
/**
 * One-shot CLI: seed component-procurement master data from Google Sheets
 * into the MSA database.
 *
 * Replaces the older src/cron/import-vendors-from-gsheet.php (kept as .bak
 * for reference). Consolidates three Google Sheets reads into one clean
 * step-based runner.
 *
 * Reads three sheets from one spreadsheet:
 *   1. dane_dostawcy          (A:L) → list__vendor
 *   2. dane_dostawcy_kontakty (A:J) → list__vendor_supplier
 *   3. order_variants         (A:K) → list__producer (created on demand),
 *                                          list__vendor_part,
 *                                          list__vendor_part_pack,
 *                                          part__unit (created on demand)
 *
 * Spreadsheet: 1OowYceg8hWtuCmnqPiqCyg5N3rVaAngEvmnGRhjeOew
 *
 * Idempotent — safe to re-run. Existing rows are matched by business key
 * (vendor name, vendor+supplier name, vendor+vendor_part_no, producer name,
 * vendor_part+pack_quantity).
 *
 * Usage:
 *   php src/cron/seed-component-procurement-from-gsheet.php                  # all steps, LIVE
 *   php src/cron/seed-component-procurement-from-gsheet.php --dry-run        # all steps, no writes
 *   php src/cron/seed-component-procurement-from-gsheet.php --update-existing # backfill producer_part_no
 *   php src/cron/seed-component-procurement-from-gsheet.php --step=variants  # one step only
 *
 * CLI bootstrap notes:
 *   - Loads config.php only. Deliberately does NOT load config-google-sheets.php
 *     because that file eagerly instantiates Hybridauth\Provider\Google,
 *     which calls session_start() and breaks under CLI after stdout output.
 *   - Hits the Sheets API directly via \Google_Client and implements its
 *     own 401-retry with token refresh.
 */

use Atte\DB\MsaDB;
use Atte\Utils\Locker;

require_once __DIR__ . '/../../config/config.php';

set_time_limit(0);

// ── Single-instance lock ─────────────────────────────────────────────
$locker = new Locker('procurement_seed_gsheet.lock');
if ($locker->isLocked()) {
    fwrite(STDERR, "Process is already running.\n");
    exit(1);
}
$locker->lock();
register_shutdown_function(function () use ($locker) {
    if ($locker->isLocked()) { $locker->unlock(); }
});

// ── Flags ────────────────────────────────────────────────────────────
$dryRun         = in_array('--dry-run', $argv ?? [], true);
$updateExisting = in_array('--update-existing', $argv ?? [], true);
$step           = 'all';
foreach ($argv ?? [] as $a) {
    if (str_starts_with($a, '--step=')) {
        $step = substr($a, 7);
    }
}
if (!in_array($step, ['all', 'vendor', 'contacts', 'variants'], true)) {
    fwrite(STDERR, "Unknown --step='{$step}'. Use one of: all, vendor, contacts, variants.\n");
    exit(1);
}

$spreadsheetId = '1OowYceg8hWtuCmnqPiqCyg5N3rVaAngEvmnGRhjeOew';

// .env is already loaded by config.php. Promote Google credentials to
// constants so we don't depend on config-google-sheets.php being loaded.
if (!defined('GOOGLE_CLIENT_ID')) {
    define('GOOGLE_CLIENT_ID', $_ENV['GOOGLE_CLIENT_ID'] ?? '');
}
if (!defined('GOOGLE_CLIENT_SECRET')) {
    define('GOOGLE_CLIENT_SECRET', $_ENV['GOOGLE_CLIENT_SECRET'] ?? '');
}

// ── Logger: stdout + daily file ──────────────────────────────────────
$logDir  = ROOT_DIRECTORY . '/public_html/var/logs';
if (!is_dir($logDir)) { @mkdir($logDir, 0755, true); }
$logFile = $logDir . '/seed-component-procurement-' . date('Y-m-d') . '.log';

function logLine(string $msg) {
    global $logFile, $dryRun;
    $prefix = $dryRun ? '[DRY-RUN] ' : '';
    $line = date('Y-m-d H:i:s') . ' ' . $prefix . $msg;
    echo $line . PHP_EOL;
    @file_put_contents($logFile, $line . PHP_EOL, FILE_APPEND);
}

// ── DB helpers (BaseDB::query() doesn't take params — go via PDO) ───
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

function dbInsertAssoc(MsaDB $db, string $table, array $data): int {
    if (empty($data)) {
        throw new \InvalidArgumentException("Empty data for insert into $table");
    }
    $columns = array_keys($data);
    $placeholders = array_fill(0, count($columns), '?');
    $sql = "INSERT INTO `$table` (`" . implode('`,`', $columns) . "`) VALUES (" . implode(',', $placeholders) . ")";
    $stmt = $db->db->prepare($sql);
    $stmt->execute(array_values($data));
    return (int)$db->db->lastInsertId();
}

// ── Lookup / create helpers ──────────────────────────────────────────
function getOrCreateProducer(MsaDB $db, string $name, bool $dryRun): ?int {
    $name = trim($name);
    if ($name === '') return null;
    $row = dbFetchOne($db, "SELECT id FROM `list__producer` WHERE name = ?", [$name]);
    if ($row) return (int)$row['id'];
    if ($dryRun) {
        logLine("  [producer] would create: '$name'");
        return -1;
    }
    $id = dbInsertAssoc($db, 'list__producer', ['name' => $name]);
    logLine("  [producer] created '$name' -> id=$id");
    return $id;
}

function getOrCreateUnit(MsaDB $db, string $name, bool $dryRun): ?int {
    $name = trim($name);
    if ($name === '') return null;
    $row = dbFetchOne($db, "SELECT id FROM `part__unit` WHERE name = ?", [$name]);
    if ($row) return (int)$row['id'];
    if ($dryRun) {
        logLine("  [unit] would create: '$name'");
        return -1;
    }
    $id = dbInsertAssoc($db, 'part__unit', ['name' => $name]);
    logLine("  [unit] created '$name' -> id=$id");
    return $id;
}

function getPartIdByName(MsaDB $db, string $partNo): ?int {
    $partNo = trim($partNo);
    if ($partNo === '') return null;
    $row = dbFetchOne($db, "SELECT id FROM `list__parts` WHERE name = ?", [$partNo]);
    return $row ? (int)$row['id'] : null;
}

function getVendorIdByName(MsaDB $db, string $name): ?int {
    $name = trim($name);
    if ($name === '') return null;
    $row = dbFetchOne($db, "SELECT id FROM `list__vendor` WHERE name = ?", [$name]);
    return $row ? (int)$row['id'] : null;
}

// ── Google Sheets CLI-safe client ────────────────────────────────────
/**
 * Read values from a sheet range. Auto-refreshes the access token on HTTP 401.
 *
 * @return array|false 2D array of rows, or false on hard failure.
 */
function readSheetCli(string $spreadsheetId, string $sheetName, string $range, MsaDB $db) {
    $client = buildGoogleClient($db);
    try {
        $service = new \Google_Service_Sheets($client);
        $response = $service->spreadsheets_values->get($spreadsheetId, $sheetName . '!' . $range);
        return $response->getValues();
    } catch (\Exception $e) {
        if ((int)$e->getCode() === 401) {
            logLine('Access token expired, refreshing...');
            refreshAccessToken($client, $db);
            try {
                $service = new \Google_Service_Sheets($client);
                $response = $service->spreadsheets_values->get($spreadsheetId, $sheetName . '!' . $range);
                return $response->getValues();
            } catch (\Exception $e2) {
                logLine('Sheet read failed after refresh: ' . $e2->getMessage());
                return false;
            }
        }
        logLine('Sheet read failed: ' . $e->getMessage());
        return false;
    }
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

function loadStoredToken(MsaDB $db): ?array {
    $rows = $db->query("SELECT provider_value FROM google_oauth WHERE provider = 'google'");
    if (!$rows || count($rows) === 0) return null;
    $decoded = json_decode($rows[0]['provider_value'], true);
    return is_array($decoded) ? $decoded : null;
}

function refreshAccessToken(\Google_Client $client, MsaDB $db): array {
    $token = loadStoredToken($db);
    $refresh = $token['refresh_token'] ?? null;
    if (!$refresh) {
        throw new \RuntimeException('Brak refresh_token w bazie. Uruchom ponownie OAuth flow.');
    }
    $newToken = $client->fetchAccessTokenWithRefreshToken($refresh);
    if (!is_array($newToken)) {
        throw new \RuntimeException('Refresh response is not an array: ' . var_export($newToken, true));
    }
    if (isset($newToken['error'])) {
        throw new \RuntimeException('Refresh failed: ' . ($newToken['error_description'] ?? $newToken['error']));
    }
    if (empty($newToken['access_token'])) {
        throw new \RuntimeException('Refresh response missing access_token: ' . json_encode($newToken));
    }
    // CRITICAL: this version of google/apiclient does NOT call setAccessToken()
    // inside fetchAccessTokenWithRefreshToken(). Without the explicit call below
    // the next request still carries the old bearer header.
    $client->setAccessToken($newToken);
    // fetchAccessTokenWithRefreshToken never returns a fresh refresh_token;
    // preserve the existing one so the DB row stays usable.
    $newToken['refresh_token'] = $refresh;
    $db->update(
        'google_oauth',
        ['provider_value' => json_encode($newToken)],
        'provider',
        'google'
    );
    logLine('Token refreshed (expires_in=' . ($newToken['expires_in'] ?? '?') . 's)');
    return $newToken;
}

// ── Step 1: VENDORS — sheet `dane_dostawcy` ──────────────────────────
//    Cols: A=ID, B=Name, J=Notes, K=LT
function stepImportVendors(MsaDB $db, string $spreadsheetId, bool $dryRun): array {
    logLine('--- Step 1/3: Vendors (dane_dostawcy) ---');
    $raw = readSheetCli($spreadsheetId, 'dane_dostawcy', 'A:L', $db);
    if (!$raw || count($raw) < 2) {
        logLine('Arkusz dane_dostawcy pusty lub nieczytelny.');
        return ['sheetIdToDbId' => [], 'inserted' => 0, 'skipped_existing' => 0, 'invalid' => 0];
    }

    $map   = [];
    $stats = ['total' => 0, 'inserted' => 0, 'skipped_existing' => 0, 'invalid' => 0];

    for ($i = 1; $i < count($raw); $i++) {
        $r = $raw[$i];
        if (count(array_filter($r, fn($v) => trim((string)$v) !== '')) === 0) continue;
        $stats['total']++;

        $sheetId = (int)($r[0] ?? 0);
        $name    = trim((string)($r[1] ?? ''));
        $notes   = trim((string)($r[9] ?? '')) ?: null;
        $ltRaw   = trim((string)($r[10] ?? ''));
        $lt      = is_numeric($ltRaw) ? (int)$ltRaw : null;

        if ($sheetId <= 0 || $name === '') {
            $stats['invalid']++;
            logLine("  [vendor] SKIP invalid row (id=$sheetId, name='$name')");
            continue;
        }

        $existing = dbFetchOne($db, "SELECT id FROM `list__vendor` WHERE name = ?", [$name]);
        if ($existing) {
            $dbId = (int)$existing['id'];
            $stats['skipped_existing']++;
            logLine("  [vendor] exists '$name' -> id=$dbId");
        } else {
            if ($dryRun) {
                $dbId = -1;
                logLine("  [vendor] would create '$name' (LT=" . ($lt ?? 'NULL') . ")");
            } else {
                $dbId = dbInsertAssoc($db, 'list__vendor', [
                    'name'           => $name,
                    'comment'        => $notes,
                    'lead_time_days' => $lt,
                ]);
                logLine("  [vendor] created '$name' (LT=" . ($lt ?? 'NULL') . ") -> id=$dbId");
            }
            $stats['inserted']++;
        }
        $map[$sheetId] = $dbId;
    }

    logLine("Vendors summary: total={$stats['total']} inserted={$stats['inserted']} skipped_existing={$stats['skipped_existing']} invalid={$stats['invalid']}");
    return [
        'sheetIdToDbId'    => $map,
        'inserted'         => $stats['inserted'],
        'skipped_existing' => $stats['skipped_existing'],
        'invalid'          => $stats['invalid'],
    ];
}

// ── Step 2: VENDOR CONTACTS — sheet `dane_dostawcy_kontakty` ────────
//    Cols: A=Vendor ID, B=Vendor Name (fallback lookup), C-F=person 1, G-J=person 2
function stepImportContacts(MsaDB $db, string $spreadsheetId, bool $dryRun, array $vendorMap): array {
    logLine('--- Step 2/3: Vendor contacts (dane_dostawcy_kontakty) ---');
    $raw = readSheetCli($spreadsheetId, 'dane_dostawcy_kontakty', 'A:J', $db);
    if (!$raw || count($raw) < 2) {
        logLine('Arkusz dane_dostawcy_kontakty pusty lub nieczytelny.');
        return ['inserted' => 0, 'skipped_existing' => 0, 'skipped_no_vendor' => 0, 'rows' => 0, 'contacts_attempted' => 0];
    }

    $stats = ['rows' => 0, 'contacts_attempted' => 0, 'inserted' => 0,
              'skipped_existing' => 0, 'skipped_no_vendor' => 0];

    for ($i = 1; $i < count($raw); $i++) {
        $r = $raw[$i];
        if (count(array_filter($r, fn($v) => trim((string)$v) !== '')) === 0) continue;
        $stats['rows']++;

        $sheetVendorId = (int)($r[0] ?? 0);

        // Prefer the in-memory map from Step 1; fall back to a name
        // lookup against list__vendor. The fallback lets the user run
        // `--step=contacts` alone, after vendors were seeded in a prior run.
        $dbVendorId = $vendorMap[$sheetVendorId] ?? null;
        if ((!$dbVendorId || $dbVendorId < 0)) {
            $name = trim((string)($r[1] ?? ''));
            if ($name !== '') {
                $dbVendorId = getVendorIdByName($db, $name);
            }
        }
        if (!$dbVendorId || $dbVendorId < 0) {
            $stats['skipped_no_vendor']++;
            logLine("  [supplier] SKIP unknown vendor sheetId=$sheetVendorId");
            continue;
        }

        // Person 1: C/D/E/F  => indexes 2,3,4,5
        // Person 2: G/H/I/J  => indexes 6,7,8,9
        $persons = [
            ['name' => $r[2] ?? '', 'email' => $r[3] ?? '', 'phone' => $r[4] ?? '', 'job_title' => $r[5] ?? ''],
            ['name' => $r[6] ?? '', 'email' => $r[7] ?? '', 'phone' => $r[8] ?? '', 'job_title' => $r[9] ?? ''],
        ];

        foreach ($persons as $person) {
            $personName = trim((string)$person['name']);
            if ($personName === '') continue;
            $stats['contacts_attempted']++;

            $existing = dbFetchOne($db,
                "SELECT id FROM `list__vendor_supplier` WHERE vendor_id = ? AND name = ?",
                [$dbVendorId, $personName]
            );
            if ($existing) {
                $stats['skipped_existing']++;
                continue;
            }

            $data = [
                'vendor_id' => $dbVendorId,
                'name'      => $personName,
                'email'     => trim((string)$person['email']) ?: null,
                'phone'     => trim((string)$person['phone']) ?: null,
                'job_title' => trim((string)$person['job_title']) ?: null,
            ];
            if ($dryRun) {
                $stats['inserted']++;
                logLine("  [supplier] would create '$personName' for vendorId=$dbVendorId");
                continue;
            }
            dbInsertAssoc($db, 'list__vendor_supplier', $data);
            $stats['inserted']++;
            logLine("  [supplier] created '$personName' for vendorId=$dbVendorId");
        }
    }

    logLine("Suppliers summary: rows={$stats['rows']} contacts_attempted={$stats['contacts_attempted']} inserted={$stats['inserted']} skipped_existing={$stats['skipped_existing']} skipped_no_vendor={$stats['skipped_no_vendor']}");
    return $stats;
}

// ── Step 3: VENDOR PARTS + PACKS — sheet `order_variants` ────────────
//    Cols (0-based index in row): 0=ID 1=PartNo 2=PartName 3=JM Our
//                                 4=Producer 5=Producer PartNo
//                                 6=Vendor Name 7=Vendor PartNo 8=Vendor JM
//                                 9=Vendor Full Pack Qty 10=Private Comment
function stepImportVariants(MsaDB $db, string $spreadsheetId, bool $dryRun, bool $updateExisting): array {
    logLine('--- Step 3/3: Vendor parts + packs (order_variants) ---');
    $raw = readSheetCli($spreadsheetId, 'order_variants', 'A:K', $db);
    if (!$raw || count($raw) < 2) {
        logLine('Arkusz order_variants pusty lub nieczytelny.');
        return [];
    }

    $stats = [
        'total'                   => 0,
        'inserted'                => 0,
        'skipped_existing'        => 0,
        'skipped_no_part'         => 0,
        'skipped_no_vendor'       => 0,
        'skipped_no_producer'     => 0,
        'skipped_no_unit'         => 0,
        'backfilled'              => 0,
        'skipped_already_set'     => 0,
        'packs_inserted'          => 0,
        'packs_skipped_existing'  => 0,
        'packs_skipped_no_value'  => 0,
    ];

    for ($i = 1; $i < count($raw); $i++) {
        $r = $raw[$i];
        if (count(array_filter($r, fn($v) => trim((string)$v) !== '')) === 0) continue;
        $stats['total']++;

        $partNo         = trim((string)($r[1] ?? ''));
        $producerNm     = trim((string)($r[4] ?? ''));
        $producerPartNo = trim((string)($r[5] ?? '')) ?: null;
        $vendorNm       = trim((string)($r[6] ?? ''));
        $vendorPartNo   = trim((string)($r[7] ?? ''));
        $vendorJM       = trim((string)($r[8] ?? ''));
        $fullPackRaw    = trim((string)($r[9] ?? ''));
        $comment        = trim((string)($r[10] ?? '')) ?: null;

        if ($partNo === '' || $vendorNm === '' || $vendorPartNo === '' || $producerNm === '') {
            $stats['skipped_no_part']++;
            logLine("  [vp] SKIP missing required fields (partNo='$partNo', vendor='$vendorNm', vendorPartNo='$vendorPartNo', producer='$producerNm')");
            continue;
        }

        $vendorId = getVendorIdByName($db, $vendorNm);
        if (!$vendorId) {
            $stats['skipped_no_vendor']++;
            logLine("  [vp] SKIP vendor '$vendorNm' not found (partNo='$partNo')");
            continue;
        }

        $producerId = getOrCreateProducer($db, $producerNm, $dryRun);
        if (!$producerId) {
            $stats['skipped_no_producer']++;
            continue;
        }

        $partsId = getPartIdByName($db, $partNo);
        if (!$partsId) {
            $stats['skipped_no_part']++;
            logLine("  [vp] SKIP PartNo '$partNo' not in list__parts (vendor='$vendorNm')");
            continue;
        }

        if ($vendorJM === '') {
            $stats['skipped_no_unit']++;
            logLine("  [vp] SKIP Vendor JM empty (partNo='$partNo', vendor='$vendorNm')");
            continue;
        }
        $unitId = getOrCreateUnit($db, $vendorJM, $dryRun);
        if (!$unitId) {
            $stats['skipped_no_unit']++;
            continue;
        }

        $existing = dbFetchOne($db,
            "SELECT id, producer_part_no AS producerPartNo
               FROM `list__vendor_part`
              WHERE vendor_id = ? AND vendor_part_no = ?",
            [$vendorId, $vendorPartNo]
        );
        if ($existing) {
            // --update-existing: backfill producer_part_no when currently NULL.
            // Doesn't touch packs — pack insert only happens on a NEW vendor_part.
            if ($updateExisting && $existing['producerPartNo'] === null && $producerPartNo) {
                $db->update(
                    'list__vendor_part',
                    ['producer_part_no' => $producerPartNo],
                    'id',
                    $existing['id']
                );
                $stats['backfilled']++;
                logLine("  [vp] backfilled producer_part_no=$producerPartNo on vendor=$vendorNm part=$partNo vendorPartNo=$vendorPartNo");
            } else {
                if ($updateExisting && $producerPartNo && $existing['producerPartNo'] !== null) {
                    $stats['skipped_already_set']++;
                    logLine("  [vp] skipped (already set: " . $existing['producerPartNo'] . ") for vendor=$vendorNm part=$partNo vendorPartNo=$vendorPartNo");
                } else {
                    $stats['skipped_existing']++;
                    logLine("  [vp] skipped (existing) vendor=$vendorNm producer=$producerNm part=$partNo vendorPartNo=$vendorPartNo");
                }
            }
            continue;
        }

        $data = [
            'vendor_id'        => $vendorId,
            'producer_id'      => $producerId > 0 ? $producerId : 0,
            'parts_id'         => $partsId,
            'vendor_part_no'   => $vendorPartNo,
            'producer_part_no' => $producerPartNo,
            'vendor_jm_id'     => $unitId > 0 ? $unitId : 0,
            'comment'          => $comment,
        ];

        if ($dryRun) {
            $stats['inserted']++;
            $packDisplay = parsePackOrNull($fullPackRaw);
            $packLog     = $packDisplay !== null ? " pack=$packDisplay" : '';
            $pPartLog    = $producerPartNo ? " producerPartNo=$producerPartNo" : '';
            logLine("  [vp] would create vendor=$vendorNm producer=$producerNm part=$partNo vendorPartNo=$vendorPartNo$pPartLog unit=$vendorJM$packLog");
            continue;
        }

        $vpId = dbInsertAssoc($db, 'list__vendor_part', $data);
        $stats['inserted']++;
        $pPartLog = $producerPartNo ? " producerPartNo=$producerPartNo" : '';
        logLine("  [vp] created vendor=$vendorNm producer=$producerId part=$partNo vendorPartNo=$vendorPartNo$pPartLog");

        // list__vendor_part_pack — fixed missing insert from import-vendors.
        // UNIQUE (vendor_part_id, full_pack_quantity) makes this idempotent
        // via INSERT IGNORE. Only insert when the sheet provided a real,
        // positive pack quantity — empty/invalid cells are skipped to avoid
        // noise rows.
        $packNorm = parsePackOrNull($fullPackRaw);
        if ($packNorm === null) {
            $stats['packs_skipped_no_value']++;
        } else {
            try {
                $db->db->prepare(
                    "INSERT IGNORE INTO `list__vendor_part_pack`
                        (`vendor_part_id`, `full_pack_quantity`)
                     VALUES (?, ?)"
                )->execute([$vpId, $packNorm]);
                $rowCount = $db->db->query("SELECT ROW_COUNT()")->fetchColumn();
                if ((int)$rowCount > 0) {
                    $stats['packs_inserted']++;
                    logLine("  [vpp] pack=$packNorm for vendorPartId=$vpId");
                } else {
                    $stats['packs_skipped_existing']++;
                    logLine("  [vpp] pack=$packNorm already exists for vendorPartId=$vpId (skipped)");
                }
            } catch (\PDOException $e) {
                logLine("  [vpp] WARN pack insert failed for vendorPartId=$vpId: " . $e->getMessage());
                $stats['packs_skipped_existing']++;
            }
        }
    }

    logLine("VendorParts summary: total={$stats['total']} inserted={$stats['inserted']} skipped_existing={$stats['skipped_existing']} skipped_no_part={$stats['skipped_no_part']} skipped_no_vendor={$stats['skipped_no_vendor']} skipped_no_producer={$stats['skipped_no_producer']} skipped_no_unit={$stats['skipped_no_unit']} backfilled={$stats['backfilled']} skipped_already_set={$stats['skipped_already_set']}");
    logLine("Packs summary: inserted={$stats['packs_inserted']} skipped_existing={$stats['packs_skipped_existing']} skipped_no_value={$stats['packs_skipped_no_value']}");
    return $stats;
}

/**
 * Parse a pack-quantity cell. Returns the normalized float, or null if
 * the cell is empty / non-numeric / non-positive (so callers can decide
 * whether to skip rather than defaulting to a synthetic "1").
 *
 * Accepts European-style "1 234,56" → 1234.56.
 */
function parsePackOrNull(string $raw): ?float {
    $raw = trim($raw);
    if ($raw === '') return null;
    $candidate = str_replace([' ', ','], ['', '.'], $raw);
    if (!is_numeric($candidate)) return null;
    $val = (float)$candidate;
    return $val > 0 ? $val : null;
}

// ── Main ────────────────────────────────────────────────────────────
logLine('=== Start component-procurement seed ===');
logLine('Mode: ' . ($dryRun ? 'DRY-RUN (no writes)' : 'LIVE'));
logLine('Step: ' . $step);

$MsaDB = MsaDB::getInstance();

$runVendor   = in_array($step, ['all', 'vendor'], true);
$runContacts = in_array($step, ['all', 'contacts'], true);
$runVariants = in_array($step, ['all', 'variants'], true);

$vendorMap = [];

if ($runVendor) {
    $res = stepImportVendors($MsaDB, $spreadsheetId, $dryRun);
    $vendorMap = $res['sheetIdToDbId'] ?? [];
}
if ($runContacts) {
    stepImportContacts($MsaDB, $spreadsheetId, $dryRun, $vendorMap);
}
if ($runVariants) {
    stepImportVariants($MsaDB, $spreadsheetId, $dryRun, $updateExisting);
}

// ── Post-flight verification ─────────────────────────────────────────
echo "\n--- Verification ---\n";

$counts = $MsaDB->query(
    "SELECT
        (SELECT COUNT(*) FROM `list__vendor`)         AS vendors,
        (SELECT COUNT(*) FROM `list__vendor_supplier`) AS suppliers,
        (SELECT COUNT(*) FROM `list__vendor_part`)    AS vendor_parts,
        (SELECT COUNT(*) FROM `list__vendor_part_pack`) AS vendor_packs,
        (SELECT COUNT(*) FROM `list__producer`)       AS producers,
        (SELECT COUNT(*) FROM `part__unit`)           AS units",
    \PDO::FETCH_ASSOC
);
$c = $counts[0] ?? [];
echo "list__vendor            : {$c['vendors']}\n";
echo "list__vendor_supplier   : {$c['suppliers']}\n";
echo "list__vendor_part       : {$c['vendor_parts']}\n";
echo "list__vendor_part_pack  : {$c['vendor_packs']}\n";
echo "list__producer          : {$c['producers']}\n";
echo "part__unit              : {$c['units']}\n";

logLine('=== Seed complete ===');
logLine("Log file: $logFile");
