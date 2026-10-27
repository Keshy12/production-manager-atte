<?php
/**
 * One-shot CLI: apply hand-picked full pack quantities / units / comments to
 * the order variants listed in manual-pack-qty.csv (produced by
 * rebuild-vendor-parts-from-gsheet.php).
 *
 * For every ID in $FIXES:
 *   - packs:   list__vendor_part_pack is replaced with the given values
 *              ('-' = no full pack defined → all pack rows removed);
 *   - jm:      vendor_jm_id is set to that unit ('-' = unit unchanged).
 *              'm' → 'mb', 'l' → 'litr'; 'g' is created if missing;
 *   - comment: appended to the existing comment with ' | '
 *              (skipped if the comment already contains it — safe to re-run).
 *
 * CSV rows whose ID is NOT in $FIXES are left untouched and written to
 * manual-pack-qty-remaining.csv next to the source CSV.
 *
 * NOTE: rebuild-vendor-parts-from-gsheet.php --execute re-creates every
 * variant from the sheet and would undo these fixes.
 *
 * Usage:
 *   php src/cron/fix-manual-pack-qty.php                      # DRY RUN (default)
 *   php src/cron/fix-manual-pack-qty.php --execute
 *   php src/cron/fix-manual-pack-qty.php --csv=path/to/manual-pack-qty.csv [--execute]
 *     (default CSV: newest non-dry-run public_html/var/logs/rebuild-vendor-parts/<ts>/manual-pack-qty.csv)
 */

declare(strict_types=1);

use Atte\DB\MsaDB;

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("This script may only be run from the command line.\n");
}

require_once __DIR__ . '/../../config/config.php';

// id => [full pack qty ('-' or 'a/b/c'), JM ('-' = keep), comment to append (or null)]
$FIXES = [
    '00366' => ['30',               'm',   null],
    '00374' => ['100',              'mb',  null],
    '00376' => ['76.25',            'mb',  null],
    '00385' => ['100',              'mb',  null],
    '00386' => ['100',              'mb',  null],
    '00396' => ['100',              'mb',  null],
    '00412' => ['100',              'mb',  null],
    '00508' => ['100',              'mb',  null],
    '00546' => ['6.2525',           'm2',  null],
    '00547' => ['6.2525',           'm2',  null],
    '00548' => ['6.2525',           'm2',  null],
    '00549' => ['6.2525',           'm2',  null],
    '00550' => ['2.5625',           'm2',  null],
    '00579' => ['50',               'm',   null],
    '00607' => ['30',               'm',   null],
    '00661' => ['100',              'mb',  null],
    '00693' => ['12',               'szt', null],
    '00837' => ['5',                'l',   null],
    '00898' => ['100',              'mb',  null],
    '00899' => ['100',              'mb',  null],
    '00970' => ['200',              'm',   null],
    '00985' => ['750',              'g',   null],
    '01255' => ['0.35',             'kg',  null],
    '01494' => ['0.75',             'kg',  null],
    '01502' => ['0.25',             'kg',  null],
    '01659' => ['150',              'szt', 'MPQ 1500'],
    '01660' => ['250',              'szt', 'MPQ 2500'],
    '01661' => ['150',              'szt', 'MPQ 1500'],
    '01826' => ['10',               'szt', '1kpl=10szt'],
    '01834' => ['3000',             'szt', 'MPQ 6000'],
    '01877' => ['-',                '-',   'Fltst+'],
    '01880' => ['-',                'szt', 'min 240'],
    '02010' => ['6.1915',           'm2',  null],
    '02011' => ['10000',            'szt', null],
    '02075' => ['500',              'szt', 'MPQ 5000'],
    '02076' => ['300',              'szt', 'MPQ 3000'],
    '02077' => ['250',              'szt', 'MPQ 2500'],
    '02128' => ['2000',             'szt', 'MPQ 16k'],
    '02168' => ['-',                'kg',  null],
    '02181' => ['6.2525',           'm2',  null],
    '02204' => ['2000/5000',        'szt', null],
    '02232' => ['-',                'szt', 'MPQ 5000'],
    '02233' => ['-',                'szt', 'MPQ 5000'],
    '02491' => ['5/10',             'm',   null],
    '02492' => ['-',                'm',   null],
    '02527' => ['-',                'szt', 'MPQ 1600'],
    '02532' => ['500',              'szt', 'MPQ 5000'],
    '02616' => ['1/5',              'l',   null],
    '02757' => ['500/1500/9000',    'szt', null],
    '02836' => ['-',                'szt', 'MPQ 500'],
    '02848' => ['2',                'szt', null],
    '02898' => ['-',                'szt', 'full pack qty 60?'],
    '02905' => ['2000',             'szt', 'SPQ 6000'],
    '02946' => ['1500',             'szt', 'MPQ 6000'],
    '02948' => ['-',                'szt', 'full pack qty 60?'],
    '02982' => ['10000/600000',     'szt', null],
    '02998' => ['3000/15000/30000', 'szt', null],
    '03128' => ['-',                'szt', 'MPQ 600'],
    '03137' => ['-',                'szt', 'MPQ 1800'],
    '03138' => ['-',                'szt', 'MPQ 1800'],
    '03139' => ['-',                'szt', 'MPQ 600'],
    '03218' => ['500',              'g',   null],
    '03285' => ['1/5',              'kg',  null],
    '03532' => ['30.5',             'mb',  null],
    '03640' => ['12',               'szt', null],
    '03642' => ['76.25',            'mb',  null],
    '03731' => ['50/250/2000',      'szt', null],
    '03742' => ['-',                'szt', 'full pack qty: ???2172'],
    '03972' => ['-',                'szt', 'MOQ400?'],
    '04096' => ['-',                'mb',  null],
];
$UNIT_ALIASES  = ['m' => 'mb', 'l' => 'litr'];
$UNITS_TO_CREATE = ['g'];

$execute = in_array('--execute', $argv ?? [], true);
$csvPath = null;
foreach ($argv ?? [] as $a) {
    if (str_starts_with($a, '--csv=')) { $csvPath = substr($a, 6); }
}

function out(string $msg): void {
    global $execute;
    echo date('H:i:s') . ' ' . ($execute ? '' : '[DRY-RUN] ') . $msg . PHP_EOL;
}
function fail(string $msg): void { out("ERROR: $msg"); exit(1); }

// ── Locate + read CSV ────────────────────────────────────────────────
if ($csvPath === null) {
    $dirs = array_filter(glob(ROOT_DIRECTORY . '/public_html/var/logs/rebuild-vendor-parts/*', GLOB_ONLYDIR) ?: [],
        fn($d) => !str_ends_with($d, '_dry-run') && is_file("$d/manual-pack-qty.csv"));
    sort($dirs);
    if (!$dirs) { fail('No manual-pack-qty.csv found; pass --csv=path.'); }
    $csvPath = end($dirs) . '/manual-pack-qty.csv';
}
if (!is_file($csvPath)) { fail("CSV not found: $csvPath"); }
out("Mode: " . ($execute ? 'EXECUTE' : 'DRY RUN (pass --execute to apply)'));
out("CSV: $csvPath");

$fh = fopen($csvPath, 'r');
$bom = fread($fh, 3);
if ($bom !== "\xEF\xBB\xBF") { rewind($fh); }
$header = fgetcsv($fh, 0, ';');
$csvRows = [];
while (($r = fgetcsv($fh, 0, ';')) !== false) {
    if ($r === [null] || trim((string)$r[0]) === '') continue;
    $csvRows[] = $r;
}
fclose($fh);
$csvIds = array_column($csvRows, 0);

$notInCsv = array_diff(array_keys($FIXES), $csvIds);
if ($notInCsv) { fail('IDs in $FIXES but not in the CSV: ' . implode(', ', $notInCsv)); }

// ── Parse fixes ──────────────────────────────────────────────────────
$db  = MsaDB::getInstance();
$pdo = $db->db;

$units = [];
foreach ($pdo->query("SELECT id, name FROM part__unit") as $u) { $units[mb_strtolower($u['name'])] = (int)$u['id']; }

$plan = [];
foreach ($FIXES as $idStr => [$packStr, $jm, $comment]) {
    $packs = [];
    if ($packStr !== '-') {
        foreach (explode('/', $packStr) as $p) {
            if (!is_numeric($p) || (float)$p <= 0) { fail("$idStr: invalid pack value '$p'"); }
            $packs[] = $p;
        }
    }
    $unitName = null;
    if ($jm !== '-') {
        $unitName = $UNIT_ALIASES[$jm] ?? $jm;
        if (!isset($units[mb_strtolower($unitName)]) && !in_array($unitName, $UNITS_TO_CREATE, true)) {
            fail("$idStr: unit '$unitName' does not exist");
        }
    }
    $plan[(int)$idStr] = ['idStr' => $idStr, 'packs' => $packs, 'unit' => $unitName, 'comment' => $comment];
}

// ── Apply ────────────────────────────────────────────────────────────
$sel     = $pdo->prepare("SELECT vp.id, vp.comment, vp.vendor_jm_id, u.name unit,
                                 (SELECT GROUP_CONCAT(0 + full_pack_quantity ORDER BY full_pack_quantity SEPARATOR '/')
                                    FROM list__vendor_part_pack WHERE vendor_part_id = vp.id) packs
                            FROM list__vendor_part vp JOIN part__unit u ON u.id = vp.vendor_jm_id WHERE vp.id = ?");
$delPack = $pdo->prepare("DELETE FROM list__vendor_part_pack WHERE vendor_part_id = ?");
$insPack = $pdo->prepare("INSERT INTO list__vendor_part_pack (vendor_part_id, full_pack_quantity) VALUES (?, ?)");
$updUnit = $pdo->prepare("UPDATE list__vendor_part SET vendor_jm_id = ? WHERE id = ?");
$updCmt  = $pdo->prepare("UPDATE list__vendor_part SET comment = ? WHERE id = ?");

$stats = ['variants' => 0, 'pack_rows' => 0, 'unit_changes' => 0, 'comments' => 0];

try {
    $pdo->beginTransaction();

    foreach ($UNITS_TO_CREATE as $name) {
        if (!isset($units[mb_strtolower($name)])) {
            $pdo->prepare("INSERT INTO part__unit (name) VALUES (?)")->execute([$name]);
            $units[mb_strtolower($name)] = (int)$pdo->lastInsertId();
            out("Created unit '$name' -> id={$units[mb_strtolower($name)]}");
        }
    }

    foreach ($plan as $id => $p) {
        $sel->execute([$id]);
        $vp = $sel->fetch(\PDO::FETCH_ASSOC);
        if (!$vp) { throw new \RuntimeException("{$p['idStr']}: vendor part not found in list__vendor_part"); }
        $changes = [];

        // packs
        $delPack->execute([$id]);
        foreach ($p['packs'] as $qty) { $insPack->execute([$id, $qty]); $stats['pack_rows']++; }
        $newPacks = $p['packs'] ? implode('/', $p['packs']) : '-';
        $changes[] = 'packs ' . ($vp['packs'] ?? '-') . " → $newPacks";

        // unit
        if ($p['unit'] !== null) {
            $unitId = $units[mb_strtolower($p['unit'])];
            if ($unitId !== (int)$vp['vendor_jm_id']) {
                $updUnit->execute([$unitId, $id]);
                $stats['unit_changes']++;
                $changes[] = "JM {$vp['unit']} → {$p['unit']}";
            }
        }

        // comment
        if ($p['comment'] !== null) {
            $old = trim((string)$vp['comment']);
            if (!str_contains($old, $p['comment'])) {
                $new = $old === '' ? $p['comment'] : "$old | {$p['comment']}";
                $updCmt->execute([$new, $id]);
                $stats['comments']++;
                $changes[] = 'comment "' . $new . '"';
            }
        }

        $stats['variants']++;
        out("  {$p['idStr']}: " . implode('; ', $changes));
    }

    if ($execute) {
        $pdo->commit();
        out('Committed.');
    } else {
        $pdo->rollBack();
        out('Dry run — rolled back, nothing changed.');
    }
} catch (\Throwable $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    fail('Rolled back, nothing changed: ' . $e->getMessage());
}

out("Variants: {$stats['variants']}, pack rows: {$stats['pack_rows']}, unit changes: {$stats['unit_changes']}, comments: {$stats['comments']}");

// ── Remaining rows CSV ───────────────────────────────────────────────
$remaining = array_filter($csvRows, fn($r) => !isset($FIXES[$r[0]]));
$remPath = dirname($csvPath) . '/manual-pack-qty-remaining.csv';
$fh = fopen($remPath, 'w');
fwrite($fh, "\xEF\xBB\xBF");
fputcsv($fh, $header, ';');
foreach ($remaining as $r) { fputcsv($fh, $r, ';'); }
fclose($fh);
out('Remaining (not fixed): ' . count($remaining) . " rows → $remPath");
