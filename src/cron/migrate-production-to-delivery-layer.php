<?php
/**
 * One-shot CLI orchestrator: bring a PRODUCTION (pre-P10) database up to the
 * current development schema and data model — the whole "retire the legacy
 * reception mechanism" migration in ONE command, in the exact order that was
 * rehearsed on the dev copy of production.
 *
 * What it runs (each step is an existing, idempotent, individually tested
 * script — this file only sequences them, backs up first and verifies after):
 *
 *   S1  migrate-po-item-price-nullable.php
 *         purchase__order_item.unit_price NOT NULL -> NULL (metadata only,
 *         rows with price 0 keep 0).
 *   S2  migrate-po-item-expected-delivery-date.php
 *         ADD purchase__order_item.expected_delivery_date (NO --backfill).
 *   S3  migrate-purchase-delivery-schema.php
 *         purchase__delivery / _line / _allocation, the two bridge columns
 *         (receipt.delivery_id, receipt_item.delivery_allocation_id), FKs,
 *         indexes, ref__transfer_group_types 'purchase_delivery', and the
 *         legacy cleanup (empty "draft" receipts deleted, 'in_progress' POs
 *         re-derived).
 *   S4  migrate-delivery-line-currency.php
 *         ADD purchase__delivery_line.currency.
 *   S5  fix-order-item-quantity-received.php
 *         Repairs the 2x double-counted purchase__order_item.quantity_received
 *         (value := SUM(purchase__order_receipt_item.quantity_received)).
 *   S6  migrate-legacy-receipts-to-deliveries.php
 *         One synthetic purchase__delivery (document_type='invoice') per
 *         (vendor_id, document_number) of the legacy receipts + lines +
 *         allocations, and back-links the receipts.
 *
 *   then a read-only VERIFICATION block (integrity checks, row-count
 *   invariants, PO states untouched).
 *
 * It deliberately does NOT: re-run the Google Sheets importer, wipe anything,
 * touch purchase__order.state (apart from S3's in_progress cleanup), touch
 * inventory__* or purchase__number_counter.
 *
 * Modes
 *   (default) DRY-RUN   writes NOTHING. Prints the current state, what each
 *                       step would do (S3/S5 run their own --dry-run), and a
 *                       size estimate of the receipt migration. Steps whose
 *                       dry-run needs schema that does not exist yet are
 *                       reported as "WOULD APPLY after S3".
 *   --execute           backup -> confirmation prompt -> S1..S6 -> verify.
 *                       Stops at the first failing step (earlier steps stay
 *                       applied; every step is idempotent, so after fixing
 *                       the cause simply run the script again).
 *   --verify-only       only the read-only verification block.
 *
 * Options
 *   --yes               skip the interactive "type MIGRATE" confirmation
 *                       (required when STDIN is not a terminal).
 *   --skip-backup       do NOT take the mysqldump backup (NOT recommended).
 *   --backup-dir=PATH   where the backup goes (default: <system temp>/atte_ms_backups).
 *                       Do NOT put it under public_html/ — it is web-reachable.
 *   --mysqldump=PATH    mysqldump binary (default: PATH lookup, then XAMPP).
 *   --php=PATH          PHP CLI binary used for the child scripts
 *                       (default: the one running this script).
 *   --verbose           stream every line of the child scripts' output.
 *   --help
 *
 * Usage
 *   php src/cron/migrate-production-to-delivery-layer.php                # dry-run (default)
 *   php src/cron/migrate-production-to-delivery-layer.php --execute      # apply (asks to confirm)
 *   php src/cron/migrate-production-to-delivery-layer.php --verify-only
 *
 * Before --execute on production
 *   1. Freeze writes (nobody posts receipts / creates POs during the run).
 *   2. Deploy the new code, then `composer dump-autoload` (classmap autoload —
 *      the new Delivery* classes will not load without it).
 *   3. Run the dry-run and read it.
 *
 * Rollback: restore the backup file this script prints
 *   (mysql -u <user> -p atte_ms < <backup.sql>).
 *
 * Exit codes: 0 ok · 1 a step or a verification check failed / aborted · 2 usage error.
 *
 * Does NOT require config-google-sheets.php (it starts a session; unsafe in CLI).
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("This script may only be run from the command line.\n");
}

require_once __DIR__ . '/../../config/config.php';
set_time_limit(0);

use Atte\DB\MsaDB;
use Atte\Utils\Locker;

// ── Logger: stdout + daily file ──────────────────────────────────────────
$logDir = ROOT_DIRECTORY . '/public_html/var/logs';
if (!is_dir($logDir)) { @mkdir($logDir, 0755, true); }
$logFile = $logDir . '/migrate-production-to-delivery-layer-' . date('Y-m-d') . '.log';

function logLine(string $msg = ''): void {
    global $logFile;
    $text = $msg === '' ? '' : date('Y-m-d H:i:s') . ' ' . $msg;
    echo $text . PHP_EOL;
    @file_put_contents($logFile, $text . PHP_EOL, FILE_APPEND);
}

function fmtNum($n): string { return number_format((float)$n, 0, '.', ','); }

// ── Arguments ────────────────────────────────────────────────────────────
$execute    = false;
$verifyOnly = false;
$assumeYes  = false;
$skipBackup = false;
$verbose    = false;
$backupDir  = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'atte_ms_backups';
$mysqldump  = null;
$phpBin     = PHP_BINARY;
$unknown    = [];

foreach (array_slice($_SERVER['argv'] ?? [], 1) as $arg) {
    if ($arg === '--execute')              { $execute = true; }
    elseif ($arg === '--dry-run')          { /* default */ }
    elseif ($arg === '--verify-only')      { $verifyOnly = true; }
    elseif ($arg === '--yes')              { $assumeYes = true; }
    elseif ($arg === '--skip-backup')      { $skipBackup = true; }
    elseif ($arg === '--verbose')          { $verbose = true; }
    elseif (str_starts_with($arg, '--backup-dir=')) { $backupDir = rtrim(substr($arg, 13), "/\\"); }
    elseif (str_starts_with($arg, '--mysqldump='))  { $mysqldump = substr($arg, 12); }
    elseif (str_starts_with($arg, '--php='))        { $phpBin = substr($arg, 6); }
    elseif ($arg === '--help' || $arg === '-h') {
        $doc = file_get_contents(__FILE__);
        if (preg_match('#/\*\*(.*?)\*/#s', $doc, $m)) {
            echo preg_replace('/^\s*\* ?/m', '', trim($m[1])) . PHP_EOL;
        }
        exit(0);
    } else { $unknown[] = $arg; }
}
if ($unknown) {
    fwrite(STDERR, 'Unknown argument(s): ' . implode(', ', $unknown) . " — see --help.\n");
    exit(2);
}
if ($execute && $verifyOnly) {
    fwrite(STDERR, "--execute and --verify-only are mutually exclusive.\n");
    exit(2);
}

logLine('=== migrate-production-to-delivery-layer.php START ===');

// ── Lock ─────────────────────────────────────────────────────────────────
$locker = new Locker('migrate_production_to_delivery_layer.lock');
if ($locker->isLocked()) {
    logLine('ABORT: another run of this migration is already in progress.');
    exit(1);
}
$locker->lock();
register_shutdown_function(static function () use ($locker): void { $locker->unlock(); });

// ── DB + pre-flight ──────────────────────────────────────────────────────
$MsaDB = MsaDB::getInstance();
$db    = $MsaDB->db;
$dbName = (string)$db->query('SELECT DATABASE()')->fetchColumn();
if ($dbName !== 'atte_ms') {
    logLine("ABORT: connected to '{$dbName}', not 'atte_ms' — refusing to migrate the wrong database.");
    exit(1);
}
logLine("Connected to: {$dbName}");
$mode = $verifyOnly ? 'VERIFY-ONLY' : ($execute ? 'EXECUTE (will write to the database)' : 'DRY-RUN (no database writes)');
logLine("Mode: {$mode}");

// ── DB probes ────────────────────────────────────────────────────────────
function scalar(string $sql) {
    global $db;
    return $db->query($sql)->fetchColumn();
}
function tableExists(string $t): bool {
    global $db;
    $s = $db->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?");
    $s->execute([$t]);
    return (int)$s->fetchColumn() > 0;
}
function columnInfo(string $t, string $c): ?array {
    global $db;
    $s = $db->prepare("SELECT is_nullable FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?");
    $s->execute([$t, $c]);
    $r = $s->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
}

foreach (['purchase__order', 'purchase__order_item', 'purchase__order_receipt', 'purchase__order_receipt_item', 'ref__transfer_group_types'] as $req) {
    if (!tableExists($req)) {
        logLine("ABORT: required table `{$req}` is missing — this is not a procurement-schema database.");
        exit(1);
    }
}

/** Everything the report / verification needs, in one array. */
function snapshot(): array {
    $s = [];
    $s['has_delivery']   = tableExists('purchase__delivery');
    $s['has_dline']      = tableExists('purchase__delivery_line');
    $s['has_dalloc']     = tableExists('purchase__delivery_allocation');
    $s['has_receipt_fk'] = columnInfo('purchase__order_receipt', 'delivery_id') !== null;
    $s['has_item_fk']    = columnInfo('purchase__order_receipt_item', 'delivery_allocation_id') !== null;
    $price               = columnInfo('purchase__order_item', 'unit_price');
    $s['price_nullable'] = $price !== null && $price['is_nullable'] === 'YES';
    $s['has_exp_date']   = columnInfo('purchase__order_item', 'expected_delivery_date') !== null;
    $s['has_dl_currency'] = $s['has_dline'] && columnInfo('purchase__delivery_line', 'currency') !== null;
    $s['has_seed']       = (int)scalar("SELECT COUNT(*) FROM ref__transfer_group_types WHERE slug = 'purchase_delivery'") > 0;

    $s['receipts']       = (int)scalar("SELECT COUNT(*) FROM purchase__order_receipt");
    $s['receipt_items']  = (int)scalar("SELECT COUNT(*) FROM purchase__order_receipt_item");
    $s['orders']         = (int)scalar("SELECT COUNT(*) FROM purchase__order");
    $s['order_items']    = (int)scalar("SELECT COUNT(*) FROM purchase__order_item");
    $s['rfqs']           = tableExists('purchase__rfq') ? (int)scalar("SELECT COUNT(*) FROM purchase__rfq") : 0;
    $s['empty_receipts'] = (int)scalar("SELECT COUNT(*) FROM purchase__order_receipt r WHERE NOT EXISTS (SELECT 1 FROM purchase__order_receipt_item i WHERE i.receipt_id = r.id)");
    $s['in_progress']    = (int)scalar("SELECT COUNT(*) FROM purchase__order WHERE state = 'in_progress'");
    $s['po_states']      = [];
    foreach (scalar_rows("SELECT state, COUNT(*) n FROM purchase__order GROUP BY state ORDER BY state") as $r) {
        $s['po_states'][$r['state']] = (int)$r['n'];
    }
    $s['counter_mismatch'] = (int)scalar(
        "SELECT COUNT(*) FROM purchase__order_item oi
          WHERE ABS(oi.quantity_received - COALESCE((SELECT SUM(i.quantity_received)
                  FROM purchase__order_receipt_item i WHERE i.po_item_id = oi.id), 0)) > 0.000000001"
    );
    $s['received_sum'] = (float)scalar("SELECT COALESCE(SUM(quantity_received), 0) FROM purchase__order_item");

    $legacyWhere = $s['has_receipt_fk'] ? 'AND r.delivery_id IS NULL' : '';
    $s['legacy_receipts'] = (int)scalar(
        "SELECT COUNT(*) FROM purchase__order_receipt r
          WHERE EXISTS (SELECT 1 FROM purchase__order_receipt_item i WHERE i.receipt_id = r.id) {$legacyWhere}"
    );
    $s['legacy_items'] = (int)scalar(
        "SELECT COUNT(*) FROM purchase__order_receipt_item i JOIN purchase__order_receipt r ON r.id = i.receipt_id
          WHERE 1 = 1 {$legacyWhere}"
    );
    $s['legacy_groups'] = (int)scalar(
        "SELECT COUNT(*) FROM (
            SELECT po.vendor_id, r.document_number
              FROM purchase__order_receipt r JOIN purchase__order po ON po.id = r.po_id
             WHERE EXISTS (SELECT 1 FROM purchase__order_receipt_item i WHERE i.receipt_id = r.id) {$legacyWhere}
             GROUP BY po.vendor_id, r.document_number) g"
    );
    $s['deliveries'] = $s['has_delivery'] ? (int)scalar("SELECT COUNT(*) FROM purchase__delivery") : 0;
    return $s;
}
function scalar_rows(string $sql): array {
    global $db;
    return $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}

function yn(bool $b): string { return $b ? 'yes' : 'NO '; }

function printState(array $s): void {
    logLine('--- Current database state ---');
    logLine('  schema (target = all "yes"):');
    logLine('    unit_price nullable (S1)                 : ' . yn($s['price_nullable']));
    logLine('    order_item.expected_delivery_date (S2)   : ' . yn($s['has_exp_date']));
    logLine('    purchase__delivery / _line / _allocation : ' . yn($s['has_delivery'] && $s['has_dline'] && $s['has_dalloc']) . ' (S3)');
    logLine('    bridge columns on receipt / receipt_item : ' . yn($s['has_receipt_fk'] && $s['has_item_fk']) . ' (S3)');
    logLine("    ref__transfer_group_types 'purchase_delivery' : " . yn($s['has_seed']) . ' (S3)');
    logLine('    purchase__delivery_line.currency (S4)    : ' . yn($s['has_dl_currency']));
    logLine('  data:');
    logLine('    purchase orders / PO items / RFQs        : ' . fmtNum($s['orders']) . ' / ' . fmtNum($s['order_items']) . ' / ' . fmtNum($s['rfqs']));
    logLine('    PO states                                : ' . implode(', ', array_map(fn($k, $v) => "{$k}={$v}", array_keys($s['po_states']), $s['po_states'])));
    logLine('    receipts / receipt items                 : ' . fmtNum($s['receipts']) . ' / ' . fmtNum($s['receipt_items']));
    logLine('    empty "draft" receipts (S3 deletes)      : ' . fmtNum($s['empty_receipts']));
    logLine("    POs in retired state 'in_progress' (S3)  : " . fmtNum($s['in_progress']));
    logLine('    PO items with wrong quantity_received (S5): ' . fmtNum($s['counter_mismatch']));
    logLine('    deliveries already present               : ' . fmtNum($s['deliveries']));
    logLine('  receipt migration still to do (S6):');
    logLine('    legacy receipts (with items) to migrate  : ' . fmtNum($s['legacy_receipts']));
    logLine('    legacy receipt items to migrate          : ' . fmtNum($s['legacy_items']));
    logLine('    deliveries that would be created (est.)  : ' . fmtNum($s['legacy_groups']) . '  [grouped by vendor + GRN number]');
}

$before = snapshot();
printState($before);
logLine();

// ── Child-script runner ──────────────────────────────────────────────────
/** Lines worth showing when not --verbose (the full output is in each script's own log). */
const KEEP_RE = '/(ABORT|ERROR|FATAL|Exception|WARNING|COMMIT|ROLLBACK|Rows updated|WOULD|still mismatching|chunk \d+\/\d+|created\s*:|to create|to migrate|back-link|DONE|nothing to do|already|MISMATCH|✔|✘|Mode:|MODE:|exists|added|dropped|deleted|re-derived|re-derive|Updated|Inserted)/iu';

function runChild(string $label, string $script, array $args, bool $verbose): int {
    global $phpBin;
    $cmd = array_merge([$phpBin, ROOT_DIRECTORY . '/src/cron/' . $script], $args);
    logLine("    \$ php src/cron/{$script}" . ($args ? ' ' . implode(' ', $args) : ''));
    $env = array_merge(getenv(), ['XDEBUG_MODE' => 'off']);
    $proc = @proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, ROOT_DIRECTORY, $env);
    if (!is_resource($proc)) {
        logLine("    ✘ could not start the child process (check --php=).");
        return 127;
    }
    $n = 0; $hidden = 0; $tail = [];
    while (($line = fgets($pipes[1])) !== false) {
        $line = rtrim($line, "\r\n");
        // drop xdebug noise
        if (stripos($line, 'Xdebug') !== false) { continue; }
        $n++;
        $show = $verbose || $n <= 6 || preg_match(KEEP_RE, $line);
        if ($show) {
            echo '    │ ' . $line . PHP_EOL;
            @file_put_contents($GLOBALS['logFile'], '    │ ' . $line . PHP_EOL, FILE_APPEND);
        } else {
            $hidden++;
            $tail[] = $line;
            if (count($tail) > 8) { array_shift($tail); }
        }
    }
    fclose($pipes[1]);
    $code = proc_close($proc);
    if ($hidden > 0 && !$verbose) {
        logLine("    │ … {$hidden} more line(s) hidden — see the script's own log in public_html/var/logs/ (or use --verbose).");
        if ($code !== 0) {
            // the last lines usually carry the reason for the failure
            foreach ($tail as $t) { logLine('    │ ' . $t); }
        }
    }
    return $code;
}

// ── Step table ───────────────────────────────────────────────────────────
// 'probe_done'   : is the step already applied?
// 'dry'          : 'probe'  = the child has no dry-run flag; report from probes only
//                  'child'  = run the child with its own dry-run (needs 'dry_needs')
// 'exec_args'    : arguments for the real run
$steps = [
    ['id' => 'S1', 'title' => 'purchase__order_item.unit_price -> NULLABLE',
     'script' => 'migrate-po-item-price-nullable.php', 'dry' => 'probe',
     'done' => fn(array $s) => $s['price_nullable'], 'dry_args' => [], 'exec_args' => []],
    ['id' => 'S2', 'title' => 'ADD purchase__order_item.expected_delivery_date (no backfill)',
     'script' => 'migrate-po-item-expected-delivery-date.php', 'dry' => 'probe',
     'done' => fn(array $s) => $s['has_exp_date'], 'dry_args' => [], 'exec_args' => []],
    ['id' => 'S3', 'title' => 'delivery tables + bridge columns + seed + legacy cleanup',
     'script' => 'migrate-purchase-delivery-schema.php', 'dry' => 'child',
     'done' => fn(array $s) => false, 'dry_args' => ['--dry-run'], 'exec_args' => []],
    ['id' => 'S4', 'title' => 'ADD purchase__delivery_line.currency',
     'script' => 'migrate-delivery-line-currency.php', 'dry' => 'probe',
     'done' => fn(array $s) => $s['has_dl_currency'], 'dry_args' => ['--dry-run'], 'exec_args' => []],
    ['id' => 'S5', 'title' => 'repair 2x-counted purchase__order_item.quantity_received',
     'script' => 'fix-order-item-quantity-received.php', 'dry' => 'child',
     'done' => fn(array $s) => $s['counter_mismatch'] === 0, 'dry_args' => [], 'exec_args' => ['--execute']],
    ['id' => 'S6', 'title' => 'legacy receipts -> synthetic deliveries (document_type=invoice)',
     'script' => 'migrate-legacy-receipts-to-deliveries.php', 'dry' => 'child_needs_schema',
     'done' => fn(array $s) => $s['has_receipt_fk'] && $s['legacy_receipts'] === 0, 'dry_args' => [], 'exec_args' => ['--execute']],
];

$schemaReady = fn(array $s) => $s['has_delivery'] && $s['has_dline'] && $s['has_dalloc'] && $s['has_receipt_fk'] && $s['has_item_fk'];

// ══ VERIFY-ONLY ══════════════════════════════════════════════════════════
function runVerification(array $before): bool {
    $checks = [];
    $add = function (string $name, bool $ok, string $detail, string $level = 'FAIL') use (&$checks) {
        $checks[] = [$name, $ok, $detail, $level];
    };
    $after = snapshot();
    $schemaOk = $after['has_delivery'] && $after['has_dline'] && $after['has_dalloc'] && $after['has_receipt_fk'] && $after['has_item_fk'];
    $add('schema: delivery tables + bridge columns', $schemaOk, $schemaOk ? 'present' : 'MISSING');
    $add('schema: unit_price nullable', $after['price_nullable'], yn($after['price_nullable']));
    $add('schema: order_item.expected_delivery_date', $after['has_exp_date'], yn($after['has_exp_date']));
    $add('schema: delivery_line.currency', $after['has_dl_currency'], yn($after['has_dl_currency']));
    $add("seed: ref__transfer_group_types 'purchase_delivery'", $after['has_seed'], yn($after['has_seed']));

    if ($schemaOk) {
        $v = fn(string $sql) => (int)scalar($sql);
        $n = $v("SELECT COUNT(*) FROM purchase__order_receipt WHERE delivery_id IS NULL");
        $add('receipts still without delivery_id', $n === 0, "{$n} (expected 0)");
        $n = $v("SELECT COUNT(*) FROM purchase__order_receipt_item WHERE delivery_allocation_id IS NULL");
        $add('receipt items still without delivery_allocation_id', $n === 0, "{$n} (expected 0)");
        $n = $v("SELECT COUNT(*) FROM purchase__delivery_allocation a LEFT JOIN purchase__order_item oi ON oi.id = a.po_item_id WHERE oi.id IS NULL");
        $add('orphan allocations (PO item missing)', $n === 0, "{$n} (expected 0)");
        $n = $v("SELECT COUNT(*) FROM purchase__delivery_allocation a LEFT JOIN purchase__delivery_line l ON l.id = a.delivery_line_id WHERE l.id IS NULL");
        $add('orphan allocations (line missing)', $n === 0, "{$n} (expected 0)");
        $n = $v("SELECT COUNT(*) FROM purchase__delivery_line l LEFT JOIN purchase__delivery d ON d.id = l.delivery_id WHERE d.id IS NULL");
        $add('orphan lines (delivery missing)', $n === 0, "{$n} (expected 0)");
        $n = $v("SELECT COUNT(*) FROM purchase__delivery_line l LEFT JOIN list__vendor_part vp ON vp.id = l.vendor_part_id WHERE vp.id IS NULL");
        $add('lines whose vendor_part no longer exists', $n === 0, "{$n} (expected 0)");
        $n = $v("SELECT COUNT(*) FROM purchase__order_receipt_item ri
                  JOIN purchase__delivery_allocation a ON a.id = ri.delivery_allocation_id
                 WHERE ABS(ri.quantity_received - a.quantity) > 0.000000001");
        $add('receipt items whose quantity != their allocation', $n === 0, "{$n} (expected 0)");
        $n = $v("SELECT COUNT(*) FROM purchase__delivery_line l
                  JOIN purchase__delivery d ON d.id = l.delivery_id AND d.state = 'posted'
                  LEFT JOIN (SELECT delivery_line_id, SUM(quantity) s FROM purchase__delivery_allocation GROUP BY delivery_line_id) a ON a.delivery_line_id = l.id
                 WHERE EXISTS (SELECT 1 FROM purchase__order_receipt r WHERE r.delivery_id = d.id)
                   AND ABS(l.quantity - COALESCE(a.s, 0)) > 0.000000001");
        $add('posted delivery lines whose quantity != allocation sum', $n === 0, "{$n} (expected 0)");
        $groups = $v("SELECT COUNT(*) FROM (SELECT po.vendor_id, r.document_number FROM purchase__order_receipt r
                       JOIN purchase__order po ON po.id = r.po_id GROUP BY po.vendor_id, r.document_number) g");
        $linked = $v("SELECT COUNT(DISTINCT delivery_id) FROM purchase__order_receipt");
        $add('deliveries linked == distinct (vendor, GRN) groups', $groups === $linked, "{$linked} deliveries vs {$groups} groups", 'WARN');
    }
    $add('counters != receipt-line sums', $after['counter_mismatch'] === 0, $after['counter_mismatch'] . ' (expected 0)');
    $add('empty "draft" receipts', $after['empty_receipts'] === 0, $after['empty_receipts'] . ' (expected 0)');
    $add("POs in retired state 'in_progress'", $after['in_progress'] === 0, $after['in_progress'] . ' (expected 0)');

    // Invariants vs the snapshot taken at the start of this run (only meaningful
    // when something was actually executed in this run).
    if ($before['_ran']) {
        $add('POs unchanged', $after['orders'] === $before['orders'], "{$before['orders']} -> {$after['orders']}");
        $add('PO items unchanged', $after['order_items'] === $before['order_items'], "{$before['order_items']} -> {$after['order_items']}");
        $add('receipt items unchanged', $after['receipt_items'] === $before['receipt_items'], "{$before['receipt_items']} -> {$after['receipt_items']}");
        $add('RFQs unchanged', $after['rfqs'] === $before['rfqs'], "{$before['rfqs']} -> {$after['rfqs']}");
        $expectReceipts = $before['receipts'] - $before['empty_receipts'];
        $add('receipts == before - deleted empty drafts', $after['receipts'] === $expectReceipts,
            "{$before['receipts']} - {$before['empty_receipts']} = {$expectReceipts}, now {$after['receipts']}");
        if ($before['in_progress'] === 0) {
            $same = $after['po_states'] === $before['po_states'];
            $add('PO states untouched', $same,
                $same ? 'identical' : json_encode($before['po_states']) . ' -> ' . json_encode($after['po_states']));
        } else {
            $add('PO states (in_progress cleanup ran)', true,
                json_encode($before['po_states']) . ' -> ' . json_encode($after['po_states']), 'INFO');
        }
        $add('total received qty (before -> after)', true,
            fmtNum($before['received_sum']) . ' -> ' . fmtNum($after['received_sum']), 'INFO');
    }

    logLine('--- Verification ---');
    $failed = 0; $warned = 0;
    foreach ($checks as [$name, $ok, $detail, $level]) {
        $mark = $ok ? '✔' : ($level === 'FAIL' ? '✘' : '!');
        if (!$ok && $level === 'FAIL') { $failed++; }
        if (!$ok && $level === 'WARN') { $warned++; }
        logLine(sprintf('  %s %-58s %s', $mark, $name, $detail));
    }
    logLine($failed === 0
        ? '  RESULT: all checks passed' . ($warned ? " ({$warned} warning(s))" : '')
        : "  RESULT: {$failed} check(s) FAILED");
    return $failed === 0;
}
$before['_ran'] = false;

if ($verifyOnly) {
    $ok = runVerification($before);
    exit($ok ? 0 : 1);
}

// ══ DRY-RUN ══════════════════════════════════════════════════════════════
if (!$execute) {
    logLine('--- Plan (dry-run: nothing is written) ---');
    $state = $before;
    foreach ($steps as $st) {
        logLine("  [{$st['id']}] {$st['title']}");
        if ($st['done']($state) && $st['id'] !== 'S3') {
            logLine('    already applied — nothing to do');
            continue;
        }
        if ($st['dry'] === 'probe') {
            if ($st['id'] === 'S4' && !$state['has_dline']) {
                logLine('    WOULD APPLY (after S3 creates purchase__delivery_line)');
            } else {
                logLine('    WOULD APPLY (metadata-only ALTER, no row data touched)');
            }
            continue;
        }
        if ($st['dry'] === 'child_needs_schema' && !$schemaReady($state)) {
            logLine('    WOULD RUN after S3 creates the delivery schema — estimate above:');
            logLine("      ~{$state['legacy_groups']} deliveries, ~{$state['legacy_items']} lines + allocations, {$state['legacy_receipts']} receipts back-linked");
            continue;
        }
        $code = runChild($st['id'], $st['script'], $st['dry_args'], $verbose);
        if ($code !== 0) {
            logLine("    ✘ dry-run of {$st['script']} exited with code {$code}");
            logLine('ABORT: fix the cause above before running with --execute.');
            exit(1);
        }
    }
    logLine();
    logLine('DRY-RUN complete — nothing was written.');
    logLine('Next: freeze writes, deploy + `composer dump-autoload`, then run with --execute.');
    exit(0);
}

// ══ EXECUTE ══════════════════════════════════════════════════════════════
if (!$assumeYes) {
    if (!defined('STDIN') || !stream_isatty(STDIN)) {
        logLine('ABORT: STDIN is not a terminal — pass --yes to confirm non-interactively.');
        exit(1);
    }
    logLine('This will MODIFY the database `' . $dbName . '` (schema + data).');
    logLine('Make sure writes are frozen and the new code is deployed (composer dump-autoload).');
    echo 'Type MIGRATE to continue: ';
    $answer = trim((string)fgets(STDIN));
    if ($answer !== 'MIGRATE') {
        logLine('ABORT: confirmation not given.');
        exit(1);
    }
}

// ── Backup ───────────────────────────────────────────────────────────────
$backupFile = null;
if ($skipBackup) {
    logLine('WARNING: --skip-backup given — NO backup is taken. There is no rollback.');
} else {
    logLine('--- Backup (mysqldump of the whole database) ---');
    if (!is_dir($backupDir) && !@mkdir($backupDir, 0700, true)) {
        logLine("ABORT: cannot create backup dir {$backupDir} (use --backup-dir=).");
        exit(1);
    }
    // Credentials from the same env the app uses (MSAURL = "mysql:host=…;dbname=…;port=…").
    $dsn = (string)($_ENV['MSAURL'] ?? '');
    $kv = [];
    foreach (explode(';', preg_replace('/^mysql:/i', '', $dsn)) as $part) {
        if (str_contains($part, '=')) { [$k, $v] = explode('=', $part, 2); $kv[trim($k)] = trim($v); }
    }
    $host = $kv['host'] ?? 'localhost';
    $port = $kv['port'] ?? '3306';
    $user = (string)($_ENV['MSAUSERNAME'] ?? '');
    $pass = (string)($_ENV['MSAPASSWORD'] ?? '');

    if ($mysqldump === null) {
        $candidates = ['mysqldump', 'mariadb-dump', 'C:\\xampp\\mysql\\bin\\mysqldump.exe'];
        foreach ($candidates as $c) {
            $probe = @proc_open([$c, '--version'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pp);
            if (is_resource($probe)) {
                stream_get_contents($pp[1]); stream_get_contents($pp[2]);
                fclose($pp[1]); fclose($pp[2]);
                if (proc_close($probe) === 0) { $mysqldump = $c; break; }
            }
        }
    }
    if ($mysqldump === null) {
        logLine('ABORT: mysqldump not found — pass --mysqldump=PATH, or --skip-backup if you took a backup yourself.');
        exit(1);
    }

    $backupFile = $backupDir . DIRECTORY_SEPARATOR . 'atte_ms-pre-delivery-migration-' . date('Ymd-His') . '.sql';
    $cnf = tempnam(sys_get_temp_dir(), 'atte_cnf');
    @chmod($cnf, 0600);
    $esc = fn(string $x) => str_replace(['\\', '"'], ['\\\\', '\\"'], $x);
    file_put_contents($cnf, "[client]\nuser=\"{$esc($user)}\"\npassword=\"{$esc($pass)}\"\nhost=\"{$esc($host)}\"\nport={$port}\n");
    logLine("  dumping to {$backupFile}");
    $t0 = microtime(true);
    $dumpErr = [];
    $proc = proc_open(
        [$mysqldump, "--defaults-extra-file={$cnf}", '--single-transaction', '--routines', '--triggers',
         "--result-file={$backupFile}", $dbName],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $dp
    );
    $stderr = '';
    if (is_resource($proc)) {
        stream_get_contents($dp[1]);
        $stderr = stream_get_contents($dp[2]);
        fclose($dp[1]); fclose($dp[2]);
        $dumpCode = proc_close($proc);
    } else {
        $dumpCode = 127;
    }
    @unlink($cnf);
    $size = is_file($backupFile) ? filesize($backupFile) : 0;
    $tailOk = false;
    if ($size > 0) {
        $fh = fopen($backupFile, 'rb'); fseek($fh, max(0, $size - 400)); $tailOk = str_contains((string)fread($fh, 400), 'Dump completed'); fclose($fh);
    }
    if ($dumpCode !== 0 || $size < 1024 || !$tailOk) {
        logLine("ABORT: backup failed (exit {$dumpCode}, {$size} bytes, completed-marker " . ($tailOk ? 'yes' : 'NO') . '). ' . trim($stderr));
        exit(1);
    }
    logLine(sprintf('  ✔ backup OK: %s MB in %.1fs', number_format($size / 1048576, 1), microtime(true) - $t0));
    logLine("  rollback:  mysql -u {$user} -p {$dbName} < \"{$backupFile}\"");
    logLine('  NOTE: keep this file outside the web root and delete it once the migration is accepted.');
}
logLine();

// ── Run the steps ────────────────────────────────────────────────────────
logLine('--- Executing ---');
$before['_ran'] = true;
$results = [];
foreach ($steps as $st) {
    logLine("  [{$st['id']}] {$st['title']}");
    $t0 = microtime(true);
    $code = runChild($st['id'], $st['script'], $st['exec_args'], $verbose);
    $dt = microtime(true) - $t0;
    $results[] = [$st['id'], $st['script'], $code, $dt];
    if ($code !== 0) {
        logLine(sprintf('    ✘ [%s] FAILED with exit code %d after %.1fs', $st['id'], $code, $dt));
        logLine('ABORT: stopped at the failing step. Earlier steps stay applied (all are idempotent).');
        logLine('       Fix the cause and re-run this script, or restore the backup'
            . ($backupFile ? ": {$backupFile}" : '') . '.');
        exit(1);
    }
    logLine(sprintf('    ✔ [%s] done in %.1fs', $st['id'], $dt));
}
logLine();

$ok = runVerification($before);
logLine();
logLine('--- Summary ---');
foreach ($results as [$id, $script, $code, $dt]) {
    logLine(sprintf('  %s  %-46s exit %d  %.1fs', $id, $script, $code, $dt));
}
if ($backupFile) { logLine("  backup: {$backupFile}"); }
logLine($ok ? '=== DONE: migration applied and verified ===' : '=== DONE WITH FAILED CHECKS — review above; restore the backup if needed ===');
logLine('Next: smoke-test /purchase/receipts#historia and post one test reception; then delete the backup when accepted.');
exit($ok ? 0 : 1);
