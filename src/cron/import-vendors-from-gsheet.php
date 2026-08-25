<?php
/**
 * One-time Google Sheets → MSA importer for Phase 1 procurement master data.
 *
 * Reads three sheets from the same spreadsheet that `update-part-prices.php`
 * uses, and populates:
 *   - list__vendor
 *   - list__vendor_supplier
 *   - list__producer (created on demand)
 *   - list__vendor_part (including the optional producer_part_no column)
 *
 * Run from CLI:
 *   php src/cron/import-vendors-from-gsheet.php
 *   php src/cron/import-vendors-from-gsheet.php --dry-run
 *   php src/cron/import-vendors-from-gsheet.php --update-existing
 *   php src/cron/import-vendors-from-gsheet.php --dry-run --update-existing
 *
 * Behaviour:
 *   - Idempotent: re-runs are safe. Existing rows are skipped (matched by
 *     business key: vendor name, vendor+supplier name, vendor+vendor_part_no,
 *     producer name).
 *   - Producer is created on first sight.
 *   - Vendor JM unit is created in `part__unit` on first sight.
 *   - Producer PartNo (column 5 of order_variants) is imported when
 *     present and non-empty; the column stays NULL when missing.
 *   - PartNo not found in `list__parts.name` => row skipped + logged.
 *   - Empty cells in vendor 'Notes' leave `comment` as NULL.
 *   - Lead time column is interpreted as DAYS (per spec).
 *   - --update-existing: when set, backfills producer_part_no on
 *     existing VendorPart rows whose value is currently NULL. Rows that
 *     already have a value are skipped (we don't blindly overwrite).
 *     Use this once to populate historical rows from the spreadsheet
 *     after the producer_part_no column was added in P5.
 *
 * CLI bootstrap notes:
 *   - config.php is required explicitly because Apache's .htaccess prepend
 *     only runs under HTTP. It defines ROOT_DIRECTORY, loads autoload, and
 *     loads .env.
 *   - We deliberately do NOT load config-google-sheets.php, because that file
 *     eagerly instantiates Hybridauth\Provider\Google, which calls
 *     session_start() and breaks under CLI after stdout output.
 *   - Instead we hit the Google Sheets API directly via \Google_Client and
 *     implement our own 401-retry with token refresh.
 */

// CLI bootstrap.
require_once __DIR__ . '/../../config/config.php';

use Atte\DB\MsaDB;

set_time_limit(0);

$dryRun = in_array('--dry-run', $argv ?? [], true);
$updateExisting = in_array('--update-existing', $argv ?? [], true);

$spreadsheetId = '1OowYceg8hWtuCmnqPiqCyg5N3rVaAngEvmnGRhjeOew';

// .env was already loaded by config.php; ensure credentials are available as
// constants so we don't depend on config-google-sheets.php.
if (!defined('GOOGLE_CLIENT_ID')) {
    define('GOOGLE_CLIENT_ID', $_ENV['GOOGLE_CLIENT_ID'] ?? '');
}
if (!defined('GOOGLE_CLIENT_SECRET')) {
    define('GOOGLE_CLIENT_SECRET', $_ENV['GOOGLE_CLIENT_SECRET'] ?? '');
}

$logDir  = ROOT_DIRECTORY . '/public_html/var/logs';
if (!is_dir($logDir)) { @mkdir($logDir, 0755, true); }
$logFile = $logDir . '/vendor-import-' . date('Y-m-d') . '.log';

function logLine(string $msg) {
    global $logFile, $dryRun;
    $prefix = $dryRun ? '[DRY-RUN] ' : '';
    $line = date('Y-m-d H:i:s') . ' ' . $prefix . $msg;
    echo $line . PHP_EOL;
    @file_put_contents($logFile, $line . PHP_EOL, FILE_APPEND);
}

logLine('=== Start vendor import ===');
logLine('Mode: ' . ($dryRun ? 'DRY-RUN (no writes)' : 'LIVE'));

$MsaDB = MsaDB::getInstance();

// ---------------------------------------------------------------
// DB helpers (BaseDB::query() doesn't take params — go via PDO directly)
// ---------------------------------------------------------------

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

// ---------------------------------------------------------------
// Lookup / create helpers
// ---------------------------------------------------------------

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

// ---------------------------------------------------------------
// Google Sheets client (CLI-safe wrapper around \Google_Client)
// ---------------------------------------------------------------

/**
 * Read values from a sheet range. Auto-refreshes the access token on HTTP 401
 * (mirrors GoogleSheets::readSheet but works in CLI).
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

// ---------------------------------------------------------------
// 1) VENDORS — sheet `dane_dostawcy`
//    Cols: A=ID, B=Name, J=Notes, K=LT
// ---------------------------------------------------------------
logLine('--- Vendors (dane_dostawcy) ---');
$rawVendorValues = readSheetCli($spreadsheetId, 'dane_dostawcy', 'A:L', $MsaDB);
if (!$rawVendorValues || count($rawVendorValues) < 2) {
    logLine('Arkusz dane_dostawcy pusty lub nieczytelny.');
    exit(1);
}

$sheetVendorIdToDbId = [];
$vendorStats = ['total' => 0, 'inserted' => 0, 'skipped_existing' => 0, 'invalid' => 0];

for ($i = 1; $i < count($rawVendorValues); $i++) {
    $r = $rawVendorValues[$i];
    if (count(array_filter($r, fn($v) => trim((string)$v) !== '')) === 0) continue;
    $vendorStats['total']++;

    $sheetId = (int)($r[0] ?? 0);
    $name    = trim((string)($r[1] ?? ''));
    $notes   = trim((string)($r[9] ?? '')) ?: null;
    $ltRaw   = trim((string)($r[10] ?? ''));
    $lt      = is_numeric($ltRaw) ? (int)$ltRaw : null;

    if ($sheetId <= 0 || $name === '') {
        $vendorStats['invalid']++;
        logLine("  [vendor] SKIP invalid row (id=$sheetId, name='$name')");
        continue;
    }

    $existing = dbFetchOne($MsaDB, "SELECT id FROM `list__vendor` WHERE name = ?", [$name]);
    if ($existing) {
        $dbId = (int)$existing['id'];
        $vendorStats['skipped_existing']++;
        logLine("  [vendor] exists '$name' -> id=$dbId");
    } else {
        if ($dryRun) {
            $dbId = -1;
            logLine("  [vendor] would create '$name' (LT=" . ($lt ?? 'NULL') . ")");
        } else {
            $dbId = dbInsertAssoc($MsaDB, 'list__vendor', [
                'name'           => $name,
                'comment'        => $notes,
                'lead_time_days' => $lt,
            ]);
            logLine("  [vendor] created '$name' (LT=" . ($lt ?? 'NULL') . ") -> id=$dbId");
        }
        $vendorStats['inserted']++;
    }
    $sheetVendorIdToDbId[$sheetId] = $dbId;
}
logLine("Vendors summary: total={$vendorStats['total']} inserted={$vendorStats['inserted']} skipped_existing={$vendorStats['skipped_existing']} invalid={$vendorStats['invalid']}");

// ---------------------------------------------------------------
// 2) VENDOR CONTACTS — sheet `dane_dostawcy_kontakty`
//    Cols: A=Vendor ID, B=Vendor Name, C-F=person 1, G-J=person 2
// ---------------------------------------------------------------
logLine('--- Vendor contacts (dane_dostawcy_kontakty) ---');
$rawContactValues = readSheetCli($spreadsheetId, 'dane_dostawcy_kontakty', 'A:J', $MsaDB);
if (!$rawContactValues || count($rawContactValues) < 2) {
    logLine('Arkusz dane_dostawcy_kontakty pusty lub nieczytelny.');
    exit(1);
}

$supplierStats = ['rows' => 0, 'contacts_attempted' => 0, 'inserted' => 0,
                  'skipped_existing' => 0, 'skipped_no_vendor' => 0];

for ($i = 1; $i < count($rawContactValues); $i++) {
    $r = $rawContactValues[$i];
    if (count(array_filter($r, fn($v) => trim((string)$v) !== '')) === 0) continue;
    $supplierStats['rows']++;

    $sheetVendorId = (int)($r[0] ?? 0);
    $dbVendorId    = $sheetVendorIdToDbId[$sheetVendorId] ?? null;
    if (!$dbVendorId || $dbVendorId < 0) {
        $supplierStats['skipped_no_vendor']++;
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
        $supplierStats['contacts_attempted']++;

        $existing = dbFetchOne($MsaDB,
            "SELECT id FROM `list__vendor_supplier` WHERE vendor_id = ? AND name = ?",
            [$dbVendorId, $personName]
        );
        if ($existing) {
            $supplierStats['skipped_existing']++;
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
            $supplierStats['inserted']++;
            logLine("  [supplier] would create '$personName' for vendorId=$dbVendorId");
            continue;
        }
        dbInsertAssoc($MsaDB, 'list__vendor_supplier', $data);
        $supplierStats['inserted']++;
        logLine("  [supplier] created '$personName' for vendorId=$dbVendorId");
    }
}
logLine("Suppliers summary: rows={$supplierStats['rows']} contacts_attempted={$supplierStats['contacts_attempted']} inserted={$supplierStats['inserted']} skipped_existing={$supplierStats['skipped_existing']} skipped_no_vendor={$supplierStats['skipped_no_vendor']}");

// ---------------------------------------------------------------
// 3) VENDOR PARTS — sheet `order_variants`
//    Header (1-indexed col): ID, PartNo, PartName, JM Our, Producer,
//                            Producer PartNo, Vendor Name, Vendor PartNo,
//                            Vendor JM, Vendor Full Pack Qty,
//                            Our PRIVATE Comment
//    Index in 0-based row:  0     1        2        3       4
//                            5          6            7          8        9            10
// ---------------------------------------------------------------
logLine('--- Vendor parts (order_variants) ---');
$rawVariantValues = readSheetCli($spreadsheetId, 'order_variants', 'A:K', $MsaDB);
if (!$rawVariantValues || count($rawVariantValues) < 2) {
    logLine('Arkusz order_variants pusty lub nieczytelny.');
    exit(1);
}

$partStats = ['total' => 0, 'inserted' => 0, 'skipped_existing' => 0,
              'skipped_no_part' => 0, 'skipped_no_vendor' => 0,
              'skipped_no_producer' => 0, 'skipped_no_unit' => 0,
              'backfilled' => 0, 'skipped_already_set' => 0];

for ($i = 1; $i < count($rawVariantValues); $i++) {
    $r = $rawVariantValues[$i];
    if (count(array_filter($r, fn($v) => trim((string)$v) !== '')) === 0) continue;
    $partStats['total']++;

    $partNo         = trim((string)($r[1] ?? ''));
    $producerNm     = trim((string)($r[4] ?? ''));
    $producerPartNo = trim((string)($r[5] ?? '')) ?: null;
    $vendorNm       = trim((string)($r[6] ?? ''));
    $vendorPartNo   = trim((string)($r[7] ?? ''));
    $vendorJM       = trim((string)($r[8] ?? ''));
    $fullPackRaw    = trim((string)($r[9] ?? ''));
    $comment        = trim((string)($r[10] ?? '')) ?: null;

    if ($partNo === '' || $vendorNm === '' || $vendorPartNo === '' || $producerNm === '') {
        $partStats['skipped_no_part']++;
        logLine("  [vp] SKIP missing required fields (partNo='$partNo', vendor='$vendorNm', vendorPartNo='$vendorPartNo', producer='$producerNm')");
        continue;
    }

    $vendorRow = dbFetchOne($MsaDB, "SELECT id FROM `list__vendor` WHERE name = ?", [$vendorNm]);
    if (!$vendorRow) {
        $partStats['skipped_no_vendor']++;
        logLine("  [vp] SKIP vendor '$vendorNm' not found (partNo='$partNo')");
        continue;
    }
    $vendorId = (int)$vendorRow['id'];

    $producerId = getOrCreateProducer($MsaDB, $producerNm, $dryRun);
    if (!$producerId) {
        $partStats['skipped_no_producer']++;
        continue;
    }

    $partsId = getPartIdByName($MsaDB, $partNo);
    if (!$partsId) {
        $partStats['skipped_no_part']++;
        logLine("  [vp] SKIP PartNo '$partNo' not in list__parts (vendor='$vendorNm')");
        continue;
    }

    if ($vendorJM === '') {
        $partStats['skipped_no_unit']++;
        logLine("  [vp] SKIP Vendor JM empty (partNo='$partNo', vendor='$vendorNm')");
        continue;
    }
    $unitId = getOrCreateUnit($MsaDB, $vendorJM, $dryRun);
    if (!$unitId) {
        $partStats['skipped_no_unit']++;
        continue;
    }

    $existing = dbFetchOne($MsaDB,
        "SELECT id, producer_part_no AS producerPartNo
           FROM `list__vendor_part`
          WHERE vendor_id = ? AND vendor_part_no = ?",
        [$vendorId, $vendorPartNo]
    );
    if ($existing) {
        if ($updateExisting && $existing['producerPartNo'] === null && $producerPartNo) {
            // Backfill: existing row has NULL producer_part_no, sheet has a value.
            // Safe to update — won't overwrite any manually-entered data.
            $MsaDB->update(
                'list__vendor_part',
                ['producer_part_no' => $producerPartNo],
                'id',
                $existing['id']
            );
            $partStats['backfilled']++;
            logLine("  [vp] backfilled producer_part_no=$producerPartNo on vendor=$vendorNm part=$partNo vendorPartNo=$vendorPartNo");
        } else {
            if ($updateExisting && $producerPartNo && $existing['producerPartNo'] !== null) {
                $partStats['skipped_already_set']++;
                logLine("  [vp] skipped (already set: " . $existing['producerPartNo'] . ") for vendor=$vendorNm part=$partNo vendorPartNo=$vendorPartNo");
            } else {
                $partStats['skipped_existing']++;
                logLine("  [vp] skipped (existing) vendor=$vendorNm producer=$producerNm part=$partNo vendorPartNo=$vendorPartNo");
            }
        }
        continue;
    }

    $packNorm = str_replace([' ', ','], ['', '.'], $fullPackRaw);
    if (!is_numeric($packNorm) || (float)$packNorm <= 0) $packNorm = 1;

    $data = [
        'vendor_id'          => $vendorId,
        'producer_id'        => $producerId > 0 ? $producerId : 0,
        'parts_id'           => $partsId,
        'vendor_part_no'     => $vendorPartNo,
        'producer_part_no'   => $producerPartNo,
        'vendor_jm_id'       => $unitId > 0 ? $unitId : 0,
        'full_pack_quantity' => (float)$packNorm,
        'comment'            => $comment,
    ];

    if ($dryRun) {
        $partStats['inserted']++;
        $pPartLog = $producerPartNo ? " producerPartNo=$producerPartNo" : '';
        logLine("  [vp] would create vendor=$vendorNm producer=$producerNm part=$partNo vendorPartNo=$vendorPartNo$pPartLog unit=$vendorJM pack=$packNorm");
        continue;
    }
    dbInsertAssoc($MsaDB, 'list__vendor_part', $data);
    $partStats['inserted']++;
    $pPartLog = $producerPartNo ? " producerPartNo=$producerPartNo" : '';
    logLine("  [vp] created vendor=$vendorNm producer=$producerId part=$partNo vendorPartNo=$vendorPartNo$pPartLog");
}
logLine("VendorParts summary: total={$partStats['total']} inserted={$partStats['inserted']} skipped_existing={$partStats['skipped_existing']} skipped_no_part={$partStats['skipped_no_part']} skipped_no_vendor={$partStats['skipped_no_vendor']} skipped_no_producer={$partStats['skipped_no_producer']} skipped_no_unit={$partStats['skipped_no_unit']} backfilled={$partStats['backfilled']} skipped_already_set={$partStats['skipped_already_set']}");

logLine('=== Vendor import complete ===');
logLine("Log file: $logFile");
