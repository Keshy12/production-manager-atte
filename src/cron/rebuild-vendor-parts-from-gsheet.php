<?php
/**
 * One-shot CLI: re-create ALL order variants (`list__vendor_part` +
 * `list__vendor_part_pack`) from the `ref_order_variants` Google Sheet.
 *
 * Fixes the defects of seed-component-procurement-from-gsheet.php Step 3:
 *   - reads `ref_order_variants!H:S` (includes INACTIVE) instead of the
 *     active-only FILTER projection `order_variants`;
 *   - `list__vendor_part.id` == "ID Order Variants" exactly (e.g. "00021" → 21);
 *   - `isActive` is taken from the INACTIVE column (TRUE → 0, FALSE/empty → 1);
 *   - "Vendor Full Pack Qty" with several `/`-separated numbers
 *     (e.g. "100/1000/5000") creates one pack row per value;
 *   - any other non-numeric pack text (e.g. "1szt/SCEL") is NOT guessed —
 *     the variant is imported without packs and listed in a CSV for manual
 *     pack selection.
 *
 * Sheet columns (H:S, header validated by name):
 *   H ID Order Variants | I PartNo | J PartName | K JM Our | L Producer |
 *   M Producer PartNo | N Vendor Name | O Vendor PartNo | P Vendor JM |
 *   Q Vendor Full Pack Qty | R Our PRIVATE Comment | S INACTIVE
 *
 * DESTRUCTIVE: deletes every row in list__vendor_part_pack and
 * list__vendor_part and inserts them again. Purchase documents reference
 * vendor parts (FK RESTRICT), so the script refuses to run --execute while
 * purchase__order_item / purchase__rfq_item contain rows. Order:
 *   1. php src/cron/wipe-purchase-data.php
 *   2. php src/cron/rebuild-vendor-parts-from-gsheet.php            (dry run, read the CSVs)
 *   3. php src/cron/rebuild-vendor-parts-from-gsheet.php --execute
 *   4. php src/cron/import-procurement-history-from-gsheet.php       (optional)
 *
 * Rules:
 *   - Duplicate "ID Order Variants": first row (sheet order) wins, others → skipped.csv.
 *   - Duplicate (Vendor Name, Vendor PartNo) — DB key uq_vendor_part_no:
 *     the active row wins; if both have the same status, the lowest ID wins.
 *     Losers → skipped.csv (their IDs stay as gaps).
 *   - Vendor and PartNo must already exist (list__vendor / list__parts);
 *     Producer and Vendor JM are created on demand.
 *   - Pack value with a decimal separator followed by exactly 3 digits
 *     ("10,000", "1.500") is ambiguous (thousands vs decimal) → manual CSV.
 *   - Everything runs in one transaction; any error rolls back the lot.
 *
 * Output (gitignored): public_html/var/logs/rebuild-vendor-parts/<timestamp>/
 *   run.log, skipped.csv, manual-pack-qty.csv   (CSV: UTF-8 BOM, ';' separated)
 *
 * Usage:
 *   php src/cron/rebuild-vendor-parts-from-gsheet.php             # DRY RUN (default, no writes)
 *   php src/cron/rebuild-vendor-parts-from-gsheet.php --execute   # wipe + re-create
 */

declare(strict_types=1);

use Atte\DB\MsaDB;
use Atte\Utils\Locker;

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("This script may only be run from the command line.\n");
}

require_once __DIR__ . '/../../config/config.php';

set_time_limit(0);

const SPREADSHEET_ID = '1OowYceg8hWtuCmnqPiqCyg5N3rVaAngEvmnGRhjeOew';
const SHEET_NAME     = 'ref_order_variants';
const SHEET_RANGE    = 'H:S';
const EXPECTED_HEADER = [
    'ID Order Variants', 'PartNo', 'PartName', 'JM Our', 'Producer', 'Producer PartNo',
    'Vendor Name', 'Vendor PartNo', 'Vendor JM', 'Vendor Full Pack Qty',
    'Our PRIVATE Comment', 'INACTIVE',
];

$execute = in_array('--execute', $argv ?? [], true);
$dryRun  = !$execute;

if (!defined('GOOGLE_CLIENT_ID'))     { define('GOOGLE_CLIENT_ID', $_ENV['GOOGLE_CLIENT_ID'] ?? ''); }
if (!defined('GOOGLE_CLIENT_SECRET')) { define('GOOGLE_CLIENT_SECRET', $_ENV['GOOGLE_CLIENT_SECRET'] ?? ''); }

// ── Lock ─────────────────────────────────────────────────────────────
$locker = new Locker('rebuild_vendor_parts.lock');
if ($locker->isLocked()) {
    fwrite(STDERR, "Process is already running.\n");
    exit(1);
}
$locker->lock();
register_shutdown_function(function () use ($locker) {
    if ($locker->isLocked()) { $locker->unlock(); }
});

// ── Output dir + logger ──────────────────────────────────────────────
$outDir = ROOT_DIRECTORY . '/public_html/var/logs/rebuild-vendor-parts/' . date('Y-m-d_His') . ($dryRun ? '_dry-run' : '');
if (!is_dir($outDir) && !mkdir($outDir, 0755, true)) {
    fwrite(STDERR, "Cannot create output dir: $outDir\n");
    exit(1);
}
$logFile = $outDir . '/run.log';

function logLine(string $msg): void {
    global $logFile, $dryRun;
    $line = date('Y-m-d H:i:s') . ' ' . ($dryRun ? '[DRY-RUN] ' : '') . $msg;
    echo $line . PHP_EOL;
    @file_put_contents($logFile, $line . PHP_EOL, FILE_APPEND);
}

function fail(string $msg): void {
    logLine('ERROR: ' . $msg);
    exit(1);
}

function writeCsv(string $path, array $header, array $rows): void {
    $fh = fopen($path, 'w');
    if ($fh === false) { fail("Cannot write $path"); }
    fwrite($fh, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel shows Polish characters
    fputcsv($fh, $header, ';');
    foreach ($rows as $r) { fputcsv($fh, $r, ';'); }
    fclose($fh);
}

// ── Google Sheets (CLI-safe, same auth path as the seeder) ───────────
function loadStoredToken(MsaDB $db): ?array {
    $rows = $db->query("SELECT provider_value FROM google_oauth WHERE provider = 'google'");
    if (!$rows) return null;
    $decoded = json_decode($rows[0]['provider_value'], true);
    return is_array($decoded) ? $decoded : null;
}

function refreshAccessToken(\Google_Client $client, MsaDB $db): void {
    $token   = loadStoredToken($db);
    $refresh = $token['refresh_token'] ?? null;
    if (!$refresh) { throw new \RuntimeException('Brak refresh_token w bazie. Uruchom ponownie OAuth flow.'); }
    $new = $client->fetchAccessTokenWithRefreshToken($refresh);
    if (!is_array($new) || isset($new['error']) || empty($new['access_token'])) {
        throw new \RuntimeException('Token refresh failed: ' . json_encode($new));
    }
    $client->setAccessToken($new);
    $new['refresh_token'] = $refresh;
    $db->update('google_oauth', ['provider_value' => json_encode($new)], 'provider', 'google');
    logLine('Google token refreshed.');
}

function readSheet(MsaDB $db): array {
    $token = loadStoredToken($db);
    if (!$token || empty($token['access_token'])) {
        throw new \RuntimeException('Brak tokena Google OAuth w bazie. Uruchom OAuth flow przez /admin/synchronization/sheets.');
    }
    $client = new \Google_Client();
    $client->setClientId(GOOGLE_CLIENT_ID);
    $client->setClientSecret(GOOGLE_CLIENT_SECRET);
    $client->setAccessToken(['access_token' => $token['access_token'], 'expires_in' => $token['expires_in'] ?? 3600]);

    $range = SHEET_NAME . '!' . SHEET_RANGE;
    try {
        return (new \Google_Service_Sheets($client))->spreadsheets_values->get(SPREADSHEET_ID, $range)->getValues() ?? [];
    } catch (\Exception $e) {
        if ((int)$e->getCode() !== 401) { throw $e; }
        refreshAccessToken($client, $db);
        return (new \Google_Service_Sheets($client))->spreadsheets_values->get(SPREADSHEET_ID, $range)->getValues() ?? [];
    }
}

// ── Pack parsing ─────────────────────────────────────────────────────
/**
 * @return array{kind: 'empty'|'ok'|'manual', values: string[]}
 *   values = normalised decimal strings, deduped, ascending.
 */
function classifyPack(string $raw): array {
    $raw = trim($raw);
    if ($raw === '') { return ['kind' => 'empty', 'values' => []]; }

    $values = [];
    foreach (explode('/', $raw) as $tok) {
        $tok = str_replace([' ', "\u{00A0}"], '', $tok);
        if (!preg_match('/^\d+(?:[.,]\d+)?$/', $tok))  { return ['kind' => 'manual', 'values' => []]; }
        if (preg_match('/^\d{1,3}[.,]\d{3}$/', $tok)) { return ['kind' => 'manual', 'values' => []]; } // "10,000" ambiguous
        $num = str_replace(',', '.', $tok);
        if ((float)$num <= 0) { return ['kind' => 'manual', 'values' => []]; }
        $values[(string)(float)$num] = (float)$num;
    }
    asort($values);
    return ['kind' => 'ok', 'values' => array_map(fn($v) => rtrim(rtrim(sprintf('%.10F', $v), '0'), '.'), array_values($values))];
}

// ── Main ─────────────────────────────────────────────────────────────
logLine('=== Rebuild vendor parts from ' . SHEET_NAME . '!' . SHEET_RANGE . ' ===');
logLine('Mode: ' . ($dryRun ? 'DRY RUN (no writes; pass --execute to apply)' : 'EXECUTE'));
logLine("Output: $outDir");

$db  = MsaDB::getInstance();
$pdo = $db->db;

// Pre-flight: purchase documents must be wiped first.
$poItems  = (int)$pdo->query("SELECT COUNT(*) FROM purchase__order_item")->fetchColumn();
$rfqItems = (int)$pdo->query("SELECT COUNT(*) FROM purchase__rfq_item")->fetchColumn();
if ($poItems > 0 || $rfqItems > 0) {
    $msg = "purchase__order_item=$poItems, purchase__rfq_item=$rfqItems still reference vendor parts. "
         . "Run `php src/cron/wipe-purchase-data.php` first.";
    if ($execute) { fail($msg); }
    logLine("WARNING: $msg (--execute would abort)");
}

try {
    $raw = readSheet($db);
} catch (\Throwable $e) {
    fail('Sheet read failed: ' . $e->getMessage());
}
if (count($raw) < 2) { fail('Sheet ' . SHEET_NAME . ' is empty.'); }

// Header check by name.
$header = array_map(fn($v) => trim((string)$v), $raw[0]);
foreach (EXPECTED_HEADER as $i => $expected) {
    if (($header[$i] ?? '') !== $expected) {
        fail("Header mismatch in column " . chr(ord('H') + $i) . ": expected '$expected', got '" . ($header[$i] ?? '') . "'.");
    }
}
logLine('Header validated (' . count(EXPECTED_HEADER) . ' columns).');

// Lookups (collation is case/accent-insensitive; we mirror it with lower-case keys).
$key = fn(string $s): string => mb_strtolower(trim($s));
$vendors = []; foreach ($db->query("SELECT id, name FROM list__vendor") as $r)   { $vendors[$key($r['name'])] = (int)$r['id']; }
$parts   = []; foreach ($db->query("SELECT id, name FROM list__parts") as $r)    { $parts[$key($r['name'])]   = (int)$r['id']; }
$producers = []; foreach ($db->query("SELECT id, name FROM list__producer") as $r) { $producers[$key($r['name'])] = (int)$r['id']; }
$units   = []; foreach ($db->query("SELECT id, name FROM part__unit") as $r)     { $units[$key($r['name'])]   = (int)$r['id']; }

$skipped    = []; // [sheetRow, id, partNo, vendor, vendorPartNo, isActive, reason]
$manualPack = []; // [id, partNo, partName, vendor, vendorPartNo, isActive, rawPack]
$candidates = []; // id => row data
$stats = ['rows' => 0, 'blank' => 0];

// ── Pass 1: parse + validate each row ────────────────────────────────
for ($i = 1, $n = count($raw); $i < $n; $i++) {
    $r = array_map(fn($v) => trim((string)$v), $raw[$i]);
    $sheetRow = $i + 1;
    if (count(array_filter($r, fn($v) => $v !== '')) === 0) { $stats['blank']++; continue; }
    $stats['rows']++;

    [$idRaw, $partNo, $partName, , $producerNm, $producerPartNo, $vendorNm, $vendorPartNo, $vendorJm, $packRaw, $comment, $inactiveRaw]
        = array_pad($r, 12, '');

    $skip = function (string $reason, string $active = '') use (&$skipped, $sheetRow, $idRaw, $partNo, $vendorNm, $vendorPartNo) {
        $skipped[] = [$sheetRow, $idRaw, $partNo, $vendorNm, $vendorPartNo, $active, $reason];
    };

    $inactiveUp = strtoupper($inactiveRaw);
    if ($inactiveUp === 'TRUE')                          { $isActive = 0; }
    elseif ($inactiveUp === 'FALSE' || $inactiveUp === '') { $isActive = 1; }
    else { $skip("INACTIVE has unexpected value '$inactiveRaw'"); continue; }

    if (!ctype_digit($idRaw) || (int)$idRaw <= 0) { $skip("Invalid ID Order Variants '$idRaw'", (string)$isActive); continue; }
    $id = (int)$idRaw;
    if (isset($candidates[$id])) { $skip("Duplicate ID (first occurrence at sheet row {$candidates[$id]['sheetRow']} kept)", (string)$isActive); continue; }

    $missing = [];
    if ($partNo === '')       $missing[] = 'PartNo';
    if ($producerNm === '')   $missing[] = 'Producer';
    if ($vendorNm === '')     $missing[] = 'Vendor Name';
    if ($vendorPartNo === '') $missing[] = 'Vendor PartNo';
    if ($vendorJm === '')     $missing[] = 'Vendor JM';
    if ($missing) { $skip('Missing: ' . implode(', ', $missing), (string)$isActive); continue; }

    $vendorId = $vendors[$key($vendorNm)] ?? null;
    if (!$vendorId) { $skip("Vendor '$vendorNm' not in list__vendor", (string)$isActive); continue; }
    $partsId = $parts[$key($partNo)] ?? null;
    if (!$partsId) { $skip("PartNo '$partNo' not in list__parts", (string)$isActive); continue; }

    $candidates[$id] = [
        'id' => $id, 'sheetRow' => $sheetRow, 'isActive' => $isActive,
        'vendorId' => $vendorId, 'vendorNm' => $vendorNm, 'partsId' => $partsId,
        'partNo' => $partNo, 'partName' => $partName,
        'producerNm' => $producerNm, 'producerPartNo' => $producerPartNo !== '' ? $producerPartNo : null,
        'vendorPartNo' => $vendorPartNo, 'vendorJm' => $vendorJm,
        'packRaw' => $packRaw, 'comment' => $comment !== '' ? $comment : null,
    ];
}

// ── Pass 2: resolve duplicate (vendor, vendor_part_no) keys ──────────
$byKey = [];
foreach ($candidates as $c) { $byKey[$c['vendorId'] . '|' . $key($c['vendorPartNo'])][] = $c['id']; }
foreach ($byKey as $ids) {
    if (count($ids) < 2) continue;
    // active first, then lowest ID
    usort($ids, fn($a, $b) => [$candidates[$b]['isActive'], $a] <=> [$candidates[$a]['isActive'], $b]);
    $winner = array_shift($ids);
    foreach ($ids as $loserId) {
        $c = $candidates[$loserId];
        $skipped[] = [$c['sheetRow'], sprintf('%05d', $c['id']), $c['partNo'], $c['vendorNm'], $c['vendorPartNo'], (string)$c['isActive'],
            sprintf('Duplicate Vendor+Vendor PartNo — ID %05d (isActive=%d) kept', $winner, $candidates[$winner]['isActive'])];
        unset($candidates[$loserId]);
    }
}
ksort($candidates);

// ── Pass 3: packs ────────────────────────────────────────────────────
$packStats = ['empty' => 0, 'single' => 0, 'multi' => 0, 'manual' => 0, 'rows' => 0];
foreach ($candidates as $id => $c) {
    $p = classifyPack($c['packRaw']);
    $candidates[$id]['packs'] = $p['values'];
    if ($p['kind'] === 'empty') { $packStats['empty']++; continue; }
    if ($p['kind'] === 'manual') {
        $packStats['manual']++;
        $manualPack[] = [sprintf('%05d', $id), $c['partNo'], $c['partName'], $c['vendorNm'], $c['vendorPartNo'], (string)$c['isActive'], $c['packRaw']];
        continue;
    }
    $packStats[count($p['values']) > 1 ? 'multi' : 'single']++;
    $packStats['rows'] += count($p['values']);
}

$active   = count(array_filter($candidates, fn($c) => $c['isActive'] === 1));
$newProducers = array_unique(array_filter(array_map(fn($c) => isset($producers[$key($c['producerNm'])]) ? null : $c['producerNm'], $candidates)));
$newUnits     = array_unique(array_filter(array_map(fn($c) => isset($units[$key($c['vendorJm'])]) ? null : $c['vendorJm'], $candidates)));

logLine("Sheet rows: {$stats['rows']} (blank: {$stats['blank']})");
logLine('To import: ' . count($candidates) . " (active: $active, inactive: " . (count($candidates) - $active) . ')');
logLine('Skipped: ' . count($skipped) . ' → skipped.csv');
logLine("Packs: single={$packStats['single']} multi={$packStats['multi']} empty={$packStats['empty']} manual={$packStats['manual']} → {$packStats['rows']} pack rows");
logLine('New producers: ' . count($newProducers) . ($newProducers ? ' (' . implode(', ', $newProducers) . ')' : ''));
logLine('New units: ' . count($newUnits) . ($newUnits ? ' (' . implode(', ', $newUnits) . ')' : ''));

writeCsv("$outDir/skipped.csv",
    ['sheet_row', 'id_order_variant', 'part_no', 'vendor', 'vendor_part_no', 'is_active', 'reason'], $skipped);
writeCsv("$outDir/manual-pack-qty.csv",
    ['id_order_variant', 'part_no', 'part_name', 'vendor', 'vendor_part_no', 'is_active', 'vendor_full_pack_qty_raw'], $manualPack);
logLine('CSV written: skipped.csv (' . count($skipped) . '), manual-pack-qty.csv (' . count($manualPack) . ')');

if ($dryRun) {
    $current = (int)$pdo->query("SELECT COUNT(*) FROM list__vendor_part")->fetchColumn();
    logLine("Would delete $current vendor parts and insert " . count($candidates) . '.');
    logLine('=== Dry run finished — re-run with --execute to apply ===');
    exit(0);
}

// ── Write (single transaction) ───────────────────────────────────────
try {
    $pdo->beginTransaction();

    $getOrCreate = function (string $table, array &$cache, string $name) use ($pdo, $key): int {
        $k = $key($name);
        if (!isset($cache[$k])) {
            $pdo->prepare("INSERT INTO `$table` (`name`) VALUES (?)")->execute([trim($name)]);
            $cache[$k] = (int)$pdo->lastInsertId();
            logLine("  created $table '$name' -> id={$cache[$k]}");
        }
        return $cache[$k];
    };

    $delPacks = $pdo->exec("DELETE FROM `list__vendor_part_pack`");
    $delVp    = $pdo->exec("DELETE FROM `list__vendor_part`");
    logLine("Deleted $delVp vendor parts, $delPacks pack rows.");

    $insVp = $pdo->prepare(
        "INSERT INTO `list__vendor_part`
            (`id`, `vendor_id`, `producer_id`, `parts_id`, `vendor_part_no`, `producer_part_no`, `vendor_jm_id`, `isActive`, `comment`)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    $insPack = $pdo->prepare("INSERT INTO `list__vendor_part_pack` (`vendor_part_id`, `full_pack_quantity`) VALUES (?, ?)");

    $vpCount = 0; $packCount = 0;
    foreach ($candidates as $c) {
        $insVp->execute([
            $c['id'], $c['vendorId'],
            $getOrCreate('list__producer', $producers, $c['producerNm']),
            $c['partsId'], $c['vendorPartNo'], $c['producerPartNo'],
            $getOrCreate('part__unit', $units, $c['vendorJm']),
            $c['isActive'], $c['comment'],
        ]);
        $vpCount++;
        foreach ($c['packs'] as $qty) { $insPack->execute([$c['id'], $qty]); $packCount++; }
    }

    $pdo->commit();
    logLine("Inserted $vpCount vendor parts, $packCount pack rows. Committed.");
} catch (\Throwable $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    fail('Rolled back, nothing changed: ' . $e->getMessage());
}

// ── Verification ─────────────────────────────────────────────────────
$v = $pdo->query(
    "SELECT COUNT(*) total, SUM(isActive = 1) active, SUM(isActive = 0) inactive, MIN(id) min_id, MAX(id) max_id,
            (SELECT COUNT(*) FROM list__vendor_part_pack) packs,
            (SELECT COUNT(*) FROM (SELECT vendor_part_id FROM list__vendor_part_pack GROUP BY vendor_part_id HAVING COUNT(*) > 1) m) multi
       FROM list__vendor_part"
)->fetch(\PDO::FETCH_ASSOC);
logLine("Verify: total={$v['total']} active={$v['active']} inactive={$v['inactive']} id={$v['min_id']}..{$v['max_id']} packs={$v['packs']} multi-pack VPs={$v['multi']}");

$mismatch = 0;
$chk = $pdo->prepare("SELECT vendor_part_no, isActive FROM list__vendor_part WHERE id = ?");
foreach ($candidates as $c) {
    $chk->execute([$c['id']]);
    $row = $chk->fetch(\PDO::FETCH_ASSOC);
    if (!$row || $row['vendor_part_no'] !== $c['vendorPartNo'] || (int)$row['isActive'] !== $c['isActive']) { $mismatch++; }
}
logLine($mismatch === 0 ? 'Verify: every ID matches its sheet row.' : "Verify: $mismatch rows DO NOT match the sheet!");
logLine('=== Done ===');
exit($mismatch === 0 ? 0 : 1);
