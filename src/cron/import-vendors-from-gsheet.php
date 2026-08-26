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
 *   - list__vendor_part_pack (one row per pack size; "100/1000/5000" splits)
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
 *   - Producer PartNo (column M of `ref_order_variants`) is imported when
 *     present and non-empty; the column stays NULL when missing.
 *   - Pack quantities: column Q of `ref_order_variants` is split on `/`
 *     (e.g. "100/1000/5000" → three rows in `list__vendor_part_pack`).
 *     Empty cell = no pack rows. Tokens are trimmed, `,` treated as
 *     decimal point (Polish locale), duplicates collapsed.
 *   - Inactive flag: column S of `ref_order_variants` is treated as
 *     boolean (TRUE = inactive). On re-import the sheet is the source of
 *     truth: existing rows are flipped to isActive=1 if S is empty/false
 *     or to isActive=0 if S is true. New rows are inserted with the
 *     matching isActive value (the sheet decides whether a row lands in
 *     the DB active or inactive — there is no automatic skipping).
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
 *   - Sheet reads go through Atte\Api\GoogleSheets. That class used to
 *     eagerly load config-google-sheets.php (which instantiates Hybridauth
 *     and calls session_start() — breaks CLI after stdout output). The
 *     require was removed; GoogleOAuth::regenerateToken() defines
 *     GOOGLE_CLIENT_ID/SECRET from $_ENV lazily, only when a 401 forces
 *     a refresh. No Hybridauth, no session_start, safe to use after
 *     logLine() output.
 *   - 401 → refresh → retry is handled inside GoogleSheets::readSheet()
 *     by rebuilding the Google_Client (which gives a fresh empty
 *     MemoryCacheItemPool, dodging the bearer-token cache leak).
 */

// CLI bootstrap.
require_once __DIR__ . '/../../config/config.php';

use Atte\DB\MsaDB;
use Atte\Utils\Locker;
use Atte\Api\GoogleSheets;
use Atte\Utils\Purchase\PackListParser;

set_time_limit(0);

// Single-instance guard. Matches the pattern used by the other 6
// cron scripts (bom-flat-sku-gs-upload.php, warehouse-data-gs-upload.php,
// etc.) — cheap insurance against double-triggers even though
// per-row dedup would also catch a duplicate write.
$locker = new Locker('vendor_import_gsheet.lock');
if ($locker->isLocked()) {
    fwrite(STDERR, "Process is already running.\n");
    exit(1);
}
$locker->lock();
register_shutdown_function(function () use ($locker) {
    if ($locker->isLocked()) { $locker->unlock(); }
});

$dryRun = in_array('--dry-run', $argv ?? [], true);
$updateExisting = in_array('--update-existing', $argv ?? [], true);

$spreadsheetId = '1OowYceg8hWtuCmnqPiqCyg5N3rVaAngEvmnGRhjeOew';

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
// Google Sheets client (CLI-safe; uses Atte\Api\GoogleSheets which
// no longer loads config-google-sheets.php — see Api class docblocks).
// 401 → refresh → retry is handled inside GoogleSheets::readSheet()
// by rebuilding the Google_Client, which avoids the bearer-token
// cache leak that hit the previous hand-rolled flow.
// ---------------------------------------------------------------
$sheets = new GoogleSheets();

/**
 * CLI-flavoured wrapper around GoogleSheets::readSheet() that funnels
 * success / failure through logLine() and hard-exits on hard failure
 * (matches the previous behaviour of readSheetCli).
 */
function readSheet(string $label, string $spreadsheetId, string $sheetName, string $range, GoogleSheets $sheets): array {
    $values = $sheets->readSheet($spreadsheetId, $sheetName, $range);
    if ($values === false) {
        logLine("Arkusz $label pusty lub nieczytelny.");
        exit(1);
    }
    return $values;
}

// ---------------------------------------------------------------
// 1) VENDORS — sheet `dane_dostawcy`
//    Cols: A=ID, B=Name, J=Notes, K=LT
// ---------------------------------------------------------------
logLine('--- Vendors (dane_dostawcy) ---');
$rawVendorValues = readSheet('dane_dostawcy', $spreadsheetId, 'dane_dostawcy', 'A:L', $sheets);
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
$rawContactValues = readSheet('dane_dostawcy_kontakty', $spreadsheetId, 'dane_dostawcy_kontakty', 'A:J', $sheets);
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
// 3) VENDOR PARTS — sheet `ref_order_variants`
//    Range read: H:S (the variant data block lives between H and S)
//    1-indexed col: H  I       J          K        L         M               N            O             P         Q                    R                  S
//                   ID PartNo  PartName   JM Our   Producer  Producer PartNo Vendor Name  Vendor PartNo  Vendor JM Vendor Full Pack Qty Our PRIVATE Comment INACTIVE
//    Local index:   0  1       2          3        4         5               6            7             8         9                    10                 11
// ---------------------------------------------------------------
logLine('--- Vendor parts (ref_order_variants) ---');
$rawVariantValues = readSheet('ref_order_variants', $spreadsheetId, 'ref_order_variants', 'H:S', $sheets);
if (!$rawVariantValues || count($rawVariantValues) < 2) {
    logLine('Arkusz ref_order_variants pusty lub nieczytelny.');
    exit(1);
}

$partStats = ['total' => 0, 'inserted' => 0, 'inserted_inactive' => 0,
              'skipped_existing' => 0,
              'skipped_no_part' => 0, 'skipped_no_vendor' => 0,
              'skipped_no_producer' => 0, 'skipped_no_unit' => 0,
              'marked_inactive' => 0, 'marked_active' => 0,
              'packs_inserted' => 0,
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
    // Column S = INACTIVE flag. filter_var() with FILTER_VALIDATE_BOOLEAN
    // accepts: true/1/on/yes → true (inactive); false/0/off/no/empty → false.
    $isInactive     = filter_var(trim((string)($r[11] ?? '')), FILTER_VALIDATE_BOOLEAN);

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

    $packList = PackListParser::parse($fullPackRaw);
    $packLog  = $packList ? ' packs=' . implode('/', $packList) : '';

    $existing = dbFetchOne($MsaDB,
        "SELECT id, producer_part_no AS producerPartNo, isActive
           FROM `list__vendor_part`
          WHERE vendor_id = ? AND vendor_part_no = ?",
        [$vendorId, $vendorPartNo]
    );

    // The sheet decides isActive. For existing rows we flip them when
    // needed; for new rows we insert with the sheet's value (no longer
    // skip on S=true — see commit "insert inactive rows from sheet").
    $desiredActive = $isInactive ? 0 : 1;

    if ($existing) {
        if ((int)$existing['isActive'] !== $desiredActive) {
            if (!$dryRun) {
                $MsaDB->update('list__vendor_part', ['isActive' => $desiredActive], 'id', $existing['id']);
            }
            if ($desiredActive === 0) {
                $partStats['marked_inactive']++;
                logLine("  [vp] marked INACTIVE (S=true) vendor=$vendorNm part=$partNo vendorPartNo=$vendorPartNo");
            } else {
                $partStats['marked_active']++;
                logLine("  [vp] marked ACTIVE (S=false/empty) vendor=$vendorNm part=$partNo vendorPartNo=$vendorPartNo");
            }
        }
        // Backfill producer_part_no (preserved old behaviour, opt-in).
        if ($updateExisting && $existing['producerPartNo'] === null && $producerPartNo) {
            if (!$dryRun) {
                $MsaDB->update(
                    'list__vendor_part',
                    ['producer_part_no' => $producerPartNo],
                    'id',
                    $existing['id']
                );
            }
            $partStats['backfilled']++;
            logLine("  [vp] backfilled producer_part_no=$producerPartNo on vendor=$vendorNm part=$partNo vendorPartNo=$vendorPartNo");
        } elseif ($updateExisting && $producerPartNo && $existing['producerPartNo'] !== null) {
            $partStats['skipped_already_set']++;
        }
        // Refresh packs: DELETE then INSERT. Idempotent re-imports converge.
        if (!$dryRun) {
            $MsaDB->db->prepare("DELETE FROM `list__vendor_part_pack` WHERE vendor_part_id = ?")
                     ->execute([$existing['id']]);
            insertPackRows($MsaDB, $existing['id'], $packList);
            $partStats['packs_inserted'] += count($packList);
        }
        logLine("  [vp] refreshed vendor=$vendorNm part=$partNo vendorPartNo=$vendorPartNo$packLog");
        continue;
    }

    // New row: insert header (no full_pack_quantity column) + pack rows.
    // isActive is decided by the sheet (S column): 0 if S=true, 1 otherwise.
    $data = [
        'vendor_id'        => $vendorId,
        'producer_id'      => $producerId > 0 ? $producerId : 0,
        'parts_id'         => $partsId,
        'vendor_part_no'   => $vendorPartNo,
        'producer_part_no' => $producerPartNo,
        'vendor_jm_id'     => $unitId > 0 ? $unitId : 0,
        'comment'          => $comment,
        'isActive'         => $desiredActive,
    ];

    $activeTag = $desiredActive === 0 ? ' INACTIVE' : '';
    if ($dryRun) {
        $partStats['inserted']++;
        if ($desiredActive === 0) $partStats['inserted_inactive']++;
        $pPartLog = $producerPartNo ? " producerPartNo=$producerPartNo" : '';
        logLine("  [vp] would create$activeTag vendor=$vendorNm producer=$producerNm part=$partNo vendorPartNo=$vendorPartNo$pPartLog unit=$vendorJM$packLog");
        continue;
    }
    $newId = dbInsertAssoc($MsaDB, 'list__vendor_part', $data);
    insertPackRows($MsaDB, $newId, $packList);
    $partStats['inserted']++;
    if ($desiredActive === 0) $partStats['inserted_inactive']++;
    $partStats['packs_inserted'] += count($packList);
    $pPartLog = $producerPartNo ? " producerPartNo=$producerPartNo" : '';
    logLine("  [vp] created vendor=$vendorNm producer=$producerId part=$partNo vendorPartNo=$vendorPartNo$pPartLog$packLog");
}

/**
 * INSERT IGNORE one row per pack into list__vendor_part_pack. The
 * UNIQUE (vendor_part_id, full_pack_quantity) constraint makes this
 * safe to call from concurrent imports; duplicates collapse silently.
 * Caller is responsible for clearing stale pack rows when needed.
 */
function insertPackRows(MsaDB $db, int $vendorPartId, array $packList): void {
    if ($packList === []) return;
    $stmt = $db->db->prepare(
        "INSERT IGNORE INTO `list__vendor_part_pack` (`vendor_part_id`, `full_pack_quantity`) VALUES (?, ?)"
    );
    foreach ($packList as $qty) {
        $stmt->execute([$vendorPartId, $qty]);
    }
}

logLine("VendorParts summary: total={$partStats['total']} inserted={$partStats['inserted']} inserted_inactive={$partStats['inserted_inactive']} skipped_existing={$partStats['skipped_existing']} skipped_no_part={$partStats['skipped_no_part']} skipped_no_vendor={$partStats['skipped_no_vendor']} skipped_no_producer={$partStats['skipped_no_producer']} skipped_no_unit={$partStats['skipped_no_unit']} marked_inactive={$partStats['marked_inactive']} marked_active={$partStats['marked_active']} packs_inserted={$partStats['packs_inserted']} backfilled={$partStats['backfilled']} skipped_already_set={$partStats['skipped_already_set']}");

logLine('=== Vendor import complete ===');
logLine("Log file: $logFile");
