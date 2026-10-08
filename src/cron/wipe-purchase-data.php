<?php
/**
 * One-shot CLI: wipe ALL procurement data — every RFQ, PO, goods receipt,
 * the stock rows those receipts created, and the numbering counters.
 *
 * IRREVERSIBLE. This script has no `--force` / `--dry-run` flag — running
 * it deletes immediately. Do not invoke unless you intend to reset the
 * procurement module to a clean state.
 *
 * What gets deleted, in this FK-safe order:
 *
 *   1. `purchase__order_receipt_item`
 *   2. `purchase__order_receipt`
 *   3. `purchase__order_item`
 *   4. `purchase__order`
 *   5. `purchase__rfq_item`
 *   6. `purchase__rfq`
 *   7. `inventory__parts`   WHERE `transfer_group_id` IN (captured set)
 *   8. `inventory__transfer_groups`  WHERE type='purchase_receipt'
 *   9. `purchase__number_counter`   (all rows — numbering restarts at 00001)
 *
 * The spec lists transfer_groups as #7 and inventory__parts as #8, but
 * the FK `inventory__parts.transfer_group_id → inventory__transfer_groups.id`
 * has no ON DELETE clause → defaults to RESTRICT, so the engine refuses
 * to delete a transfer_group while inventory rows still reference it.
 * Execution order is therefore #7/#8 swapped below with a comment so a
 * future reader does not "restore" the numbered order and watch the FK
 * trip.
 *
 * Why DELETE (not TRUNCATE):
 *   - TRUNCATE causes an implicit COMMIT and would defeat the
 *     transaction that wraps every step in this script.
 *   - `inventory__parts` has `after_inventory_parts_insert` /
 *     `after_inventory_parts_update` triggers that maintain the
 *     `lowstock__parts` shadow aggregate; a plain DELETE keeps those
 *     aggregates consistent. Do NOT "optimise" this into a TRUNCATE.
 *
 * What is deliberately left alone:
 *   - `list__vendor`, `list__vendor_part`, `list__vendor_part_pack`,
 *     `list__vendor_supplier`, `list__producer`, `list__currency`,
 *     `list__parts`, etc. — vendor / master catalog data.
 *   - `inventory__transfer_groups` rows whose type is NOT
 *     'purchase_receipt' (sync sessions, commission transfers, …).
 *   - `inventory__parts` rows that are not attributable to a receipt
 *     (i.e. `transfer_group_id` not in the captured set).
 *   - `commission__list` rows.
 *
 * Usage:
 *   php src/cron/wipe-purchase-data.php
 *
 * Idempotent: each table is checked via information_schema.TABLES first
 * and silently skipped if missing — same defensive pattern as
 * src/cron/migrate-to-procurement-schema.php. Pre-flight counts are
 * printed before any row is touched.
 */

declare(strict_types=1);

use Atte\DB\MsaDB;
use Atte\Utils\Locker;

// ── CLI-only guard ────────────────────────────────────────────────────
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("This script may only be run from the command line.\n");
}

// ── Bootstrap .env + composer autoload ───────────────────────────────
// Deliberately does NOT load config-google-sheets.php — that file eagerly
// instantiates Hybridauth\Provider\Google, which calls session_start()
// and breaks under CLI after stdout output (see also:
// src/cron/seed-component-procurement-from-gsheet.php docblock).
require_once __DIR__ . '/../../config/config.php';

// ── Logger: stdout + daily file ──────────────────────────────────────
$logDir  = ROOT_DIRECTORY . '/public_html/var/logs';
if (!is_dir($logDir)) { @mkdir($logDir, 0755, true); }
$logFile = $logDir . '/wipe-purchase-data-' . date('Y-m-d') . '.log';

function logLine(string $msg): void {
    global $logFile;
    $line = date('Y-m-d H:i:s') . ' ' . $msg;
    echo $line . PHP_EOL;
    @file_put_contents($logFile, $line . PHP_EOL, FILE_APPEND);
}

logLine("=== wipe-purchase-data.php START ===");

// ── Single-instance lock (Atte\Utils\Locker → public_html/var/locks/) ─
$locker = new Locker('wipe_purchase_data.lock');
if ($locker->isLocked()) {
    logLine("ABORT: another wipe-purchase-data run is already in progress.");
    exit(1);
}
$locker->lock();
logLine("Lock acquired: wipe_purchase_data.lock");

// ── PDO handle — same MsaDB::getInstance() pattern as the other cron
//    scripts (warehouse-data-gs-upload.php, bom-flat-tht-gs-upload.php,
//    migrate-to-procurement-schema.php). The base class BaseDB then
//    reads MSA{URL,USERNAME,PASSWORD} from $_ENV (loaded by config.php's
//    Dotenv call) inside its constructor.
$MsaDB = MsaDB::getInstance();
$db    = $MsaDB->db;

// ── Pre-flight: refuse to run against the wrong database ──────────────
$dbName = (string) $db->query('SELECT DATABASE()')->fetchColumn();
if ($dbName !== 'atte_ms') {
    fwrite(STDERR, "✗ Connected to '{$dbName}', not 'atte_ms'. Aborting — refusing to wipe the wrong database.\n");
    logLine("ABORT: connected to '{$dbName}', not 'atte_ms'.");
    exit(1);
}
logLine("Connected to: {$dbName}");

// ── Helpers (style mirrors src/cron/seed-component-procurement-from-gsheet.php) ──
function dbFetchAll(\PDO $db, string $sql, array $params = []): array {
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(\PDO::FETCH_ASSOC);
}
function dbFetchColumn(\PDO $db, string $sql, array $params = []): int {
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return (int) $stmt->fetchColumn();
}
function dbExecute(\PDO $db, string $sql, array $params = []): int {
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->rowCount();
}

$tableExistsStmt = $db->prepare(
    "SELECT 1
       FROM information_schema.TABLES
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = ?"
);
function tableExists(\PDOStatement $stmt, string $name): bool {
    $stmt->execute([$name]);
    return (bool) $stmt->fetchColumn();
}

// ── Capture phase: ids of transfer groups whose type is 'purchase_receipt'.
//    We capture BEFORE any DELETE so we can filter inventory__parts on
//    transfer_group_id IN (captured_set). PurchaseActionHandler::createReceipt
//    opens these groups (src/classes/Utils/TransferGroup/...) and is the
//    only producer in the codebase — see src/classes/Utils/Purchase/Order/
//    class-purchaseactionhandler.php around lines 1045 / 1094-1125.
$purchaseReceiptGroupIds = [];
if (tableExists($tableExistsStmt, 'inventory__transfer_groups')
    && tableExists($tableExistsStmt, 'ref__transfer_group_types')) {
    $purchaseReceiptGroupIds = array_map(
        'intval',
        array_column(
            dbFetchAll(
                $db,
                "SELECT tg.id
                   FROM `inventory__transfer_groups` tg
                   JOIN `ref__transfer_group_types` tgt ON tg.type_id = tgt.id
                  WHERE tgt.slug = ?",
                ['purchase_receipt']
            ),
            'id'
        )
    );
}
logLine(sprintf(
    "Captured %d transfer-group(s) of type 'purchase_receipt'.",
    count($purchaseReceiptGroupIds)
));

// ── Preflight: refuse to proceed if any OTHER table still references
//    one of the captured transfer groups. The only legitimate referencer
//    is `inventory__parts` (step 7 below). `commission__list`,
//    `inventory__sku`, `inventory__smd`, `inventory__tht` should not point
//    at a purchase_receipt group — if any of them do, the data model is
//    in a state this wiper doesn't know how to clean, so bail.
$referencerCheck = [
    'commission__list'    => "SELECT COUNT(*) FROM `%s` WHERE `transfer_group_id` IN (%s)",
    'inventory__sku'      => "SELECT COUNT(*) FROM `%s` WHERE `transfer_group_id` IN (%s)",
    'inventory__smd'      => "SELECT COUNT(*) FROM `%s` WHERE `transfer_group_id` IN (%s)",
    'inventory__tht'      => "SELECT COUNT(*) FROM `%s` WHERE `transfer_group_id` IN (%s)",
];
$unexpectedRefs = [];
if (!empty($purchaseReceiptGroupIds)) {
    $inPh = implode(',', array_fill(0, count($purchaseReceiptGroupIds), '?'));
    foreach (array_keys($referencerCheck) as $tbl) {
        if (!tableExists($tableExistsStmt, $tbl)) { continue; }
        $sql = sprintf($referencerCheck[$tbl], $tbl, $inPh);
        $n   = dbFetchColumn($db, $sql, $purchaseReceiptGroupIds);
        if ($n > 0) { $unexpectedRefs[$tbl] = $n; }
    }
}
if (!empty($unexpectedRefs)) {
    $msg = "ABORT: the following tables still reference a 'purchase_receipt' "
         . "transfer group: " . implode(', ', array_map(
             fn($t, $n) => "{$t}={$n}",
             array_keys($unexpectedRefs),
             $unexpectedRefs
         ))
         . ". This wiper does not touch those tables — clean them up manually first.";
    logLine($msg);
    fwrite(STDERR, "✗ {$msg}\n");
    exit(1);
}

// ── Plan: each row = a table to wipe + the count + the delete SQL.
//    The first six tables have no filters — every row is removed. The
//    remaining three are scoped (inventory__transfer_groups / inventory__parts
//    via the captured set; counter is unfiltered but listed for clarity).
$plainTables = [
    'purchase__order_receipt_item',
    'purchase__order_receipt',
    'purchase__order_item',
    'purchase__order',
    'purchase__rfq_item',
    'purchase__rfq',
];

// ── Preview phase: count before any delete. Bails out cleanly if the DB
//    is already empty (or holds only other tables) — still proceeds, but
//    the operator sees the zero before the script commits to mutating.
logLine("--- Pre-delete counts ---");
foreach ($plainTables as $tbl) {
    if (!tableExists($tableExistsStmt, $tbl)) {
        logLine("= {$tbl}: table does not exist (skipping)");
        continue;
    }
    $n = dbFetchColumn($db, "SELECT COUNT(*) FROM `{$tbl}`");
    logLine("  {$tbl}: {$n} row(s) will be deleted");
}
if (tableExists($tableExistsStmt, 'inventory__parts')) {
    if (empty($purchaseReceiptGroupIds)) {
        logLine("  inventory__parts: 0 stock rows are attributable to a purchase_receipt (skipping)");
    } else {
        $inPh = implode(',', array_fill(0, count($purchaseReceiptGroupIds), '?'));
        $n    = dbFetchColumn(
            $db,
            "SELECT COUNT(*) FROM `inventory__parts` WHERE `transfer_group_id` IN ({$inPh})",
            $purchaseReceiptGroupIds
        );
        logLine("  inventory__parts: {$n} stock row(s) attributable to purchase_receipt will be deleted");
    }
} else {
    logLine("= inventory__parts: table does not exist (skipping)");
}
if (tableExists($tableExistsStmt, 'inventory__transfer_groups')) {
    $n = dbFetchColumn(
        $db,
        "SELECT COUNT(*) FROM `inventory__transfer_groups` tg
          JOIN `ref__transfer_group_types` tgt ON tg.type_id = tgt.id
         WHERE tgt.slug = ?",
        ['purchase_receipt']
    );
    logLine("  inventory__transfer_groups: {$n} purchase_receipt row(s) will be deleted");
} else {
    logLine("= inventory__transfer_groups: table does not exist (skipping)");
}
if (tableExists($tableExistsStmt, 'purchase__number_counter')) {
    $n = dbFetchColumn($db, "SELECT COUNT(*) FROM `purchase__number_counter`");
    logLine("  purchase__number_counter: {$n} row(s) will be deleted (numbering restarts at 00001)");
} else {
    logLine("= purchase__number_counter: table does not exist (skipping)");
}

logLine("--- Transaction begins ---");
$db->beginTransaction();
try {
    $stepNo  = 1;
    $deleted = ['tables' => [], 'total' => 0];

    // ── Steps 1-6: receipt / PO / RFQ cascades ────────────────────────
    foreach ($plainTables as $tbl) {
        if (!tableExists($tableExistsStmt, $tbl)) {
            logLine(sprintf("Step %d: %s — SKIPPED (table does not exist)", $stepNo, $tbl));
            $stepNo++;
            continue;
        }
        // DELETE (not TRUNCATE) — keeps the transaction consistent.
        $count = dbExecute($db, "DELETE FROM `{$tbl}`");
        logLine(sprintf("Step %d: %s — %d row(s) deleted", $stepNo, $tbl, $count));
        $deleted['tables'][$tbl] = $count;
        $deleted['total']      += $count;
        $stepNo++;
    }

    // ── Step 7 (numbered 7 in execution; spec's #8 by FK dependency):
    //    delete inventory__parts rows attributable to a purchase_receipt.
    //    Must come BEFORE inventory__transfer_groups because the FK
    //    fk_inv_parts_transfer_group (RESTRICT default) refuses the parent
    //    delete while children still point at it. inventory__parts has
    //    after_*_insert / after_*_update triggers that maintain the
    //    lowstock__parts shadow aggregate; plain DELETE keeps them
    //    consistent — DO NOT change this to TRUNCATE.
    if (!tableExists($tableExistsStmt, 'inventory__parts')) {
        logLine(sprintf("Step %d: inventory__parts — SKIPPED (table does not exist)", $stepNo));
        $stepNo++;
    } elseif (empty($purchaseReceiptGroupIds)) {
        logLine(sprintf("Step %d: inventory__parts — 0 attributable rows; nothing to delete", $stepNo));
        $deleted['tables']['inventory__parts'] = 0;
        $stepNo++;
    } else {
        $inPh  = implode(',', array_fill(0, count($purchaseReceiptGroupIds), '?'));
        $count = dbExecute(
            $db,
            "DELETE FROM `inventory__parts` WHERE `transfer_group_id` IN ({$inPh})",
            $purchaseReceiptGroupIds
        );
        logLine(sprintf("Step %d: inventory__parts — %d stock row(s) deleted", $stepNo, $count));
        $deleted['tables']['inventory__parts'] = $count;
        $deleted['total']                     += $count;
        $stepNo++;
    }

    // ── Step 8 (spec's #7): inventory__transfer_groups WHERE type='purchase_receipt'.
    //    Safe now — children are gone. Scope via ref__transfer_group_types
    //    slug = 'purchase_receipt' rather than hard-coding the type_id.
    if (!tableExists($tableExistsStmt, 'inventory__transfer_groups')
        || !tableExists($tableExistsStmt, 'ref__transfer_group_types')) {
        logLine(sprintf("Step %d: inventory__transfer_groups — SKIPPED (table or ref table does not exist)", $stepNo));
        $stepNo++;
    } else {
        $count = dbExecute(
            $db,
            "DELETE FROM `inventory__transfer_groups`
              WHERE `type_id` = (
                  SELECT id FROM `ref__transfer_group_types` WHERE slug = ?
              )",
            ['purchase_receipt']
        );
        logLine(sprintf("Step %d: inventory__transfer_groups — %d purchase_receipt row(s) deleted", $stepNo, $count));
        $deleted['tables']['inventory__transfer_groups'] = $count;
        $deleted['total']                               += $count;
        $stepNo++;
    }

    // ── Step 9: purchase__number_counter — wipe so numbering restarts at 00001.
    if (!tableExists($tableExistsStmt, 'purchase__number_counter')) {
        logLine(sprintf("Step %d: purchase__number_counter — SKIPPED (table does not exist)", $stepNo));
        $stepNo++;
    } else {
        $count = dbExecute($db, "DELETE FROM `purchase__number_counter`");
        logLine(sprintf("Step %d: purchase__number_counter — %d row(s) deleted (numbering restarts at 00001)", $stepNo, $count));
        $deleted['tables']['purchase__number_counter'] = $count;
        $deleted['total']                            += $count;
        $stepNo++;
    }

    $db->commit();
    logLine("--- Transaction COMMIT ---");
    logLine(sprintf("=== wipe-purchase-data.php DONE — total rows deleted: %d ===", $deleted['total']));

} catch (\Throwable $e) {
    if ($db->inTransaction()) { $db->rollBack(); }
    $err = "ROLLBACK: " . $e->getMessage();
    logLine("--- Transaction {$err} ---");
    fwrite(STDERR, "✗ {$err}\n");
    exit(1);
} finally {
    $locker->unlock();
    logLine("Lock released: wipe_purchase_data.lock");
}