<?php
/**
 * One-shot CLI: repair `purchase__order_item.quantity_received`.
 *
 * ── What is broken ─────────────────────────────────────────────────────────
 * The Google Sheets procurement-history importer double-counted the received
 * quantity on purchase-order lines. On the live database 8291 of 8515 PO
 * lines carry a wrong `quantity_received` counter — 8284 of them are exactly
 * 2x the true received sum (the double-count), 7 are off by a different
 * amount, and the remaining 224 are already correct. The result: 8282 lines
 * claim more received than ordered (excess ≈ 82.9 M units, absolute delta
 * ≈ 83.0 M). Any UI or report that compares "ordered" vs "received"
 * therefore lies.
 *
 * ── Root cause ─────────────────────────────────────────────────────────────
 * src/cron/import-procurement-history-from-gsheet.php:1256-1259 runs
 *
 *     UPDATE purchase__order_item SET quantity_received = quantity_received + ?
 *
 * on top of a counter that the PO-import step of the very same sheet run had
 * already pre-set from that sheet — so the sheet value was added twice.
 *
 * ── The fix ────────────────────────────────────────────────────────────────
 * The truth lives in the receipt lines:
 *
 *     SELECT COALESCE(SUM(i.quantity_received), 0)
 *       FROM purchase__order_receipt_item i
 *      WHERE i.po_item_id = purchase__order_item.id
 *
 * That invariant holds for BOTH producers of receipts:
 *   - PurchaseActionHandler::postDelivery()
 *       (src/classes/Utils/Purchase/Order/class-purchaseactionhandler.php)
 *     bumps `quantity_received` at lines 2908-2909 AND inserts exactly one
 *     `purchase__order_receipt_item` row per allocation at lines 2902-2906 —
 *     a strict 1:1 relation.
 *   - the Google Sheets importer inserts the same receipt lines, but wrongly
 *     bumped the counter a second time (see root cause above).
 *
 * So this script simply RECOMPUTES the counter from the receipt lines. It is
 * idempotent: running it twice changes nothing the second time, because after
 * the first run every counter already equals the receipt sum.
 *
 * ── What is deliberately NOT touched ────────────────────────────────────────
 *   - `purchase__order.state` is left ALONE ON PURPOSE. The stored PO state
 *     is a business fact (an order the buyer closed stays closed) and the
 *     "Do przyjęcia" queue filters on it. Recomputing the lines would flip
 *     2624 `received` POs back to `partially_received` and flood that queue
 *     with already-delivered orders. Closing a PO stays a human decision.
 *   - `purchase__order_receipt`, `purchase__order_receipt_item`,
 *     `purchase__delivery*`, `inventory__*` and `purchase__number_counter`
 *     are read-only here. The ONLY column this script may write is
 *     `purchase__order_item.quantity_received`.
 *
 * ── Safety model ───────────────────────────────────────────────────────────
 *   - CLI only (HTTP access returns 403).
 *   - Single-instance lock (Atte\Utils\Locker).
 *   - Refuses to run unless the connection points at the `atte_ms` database.
 *   - DRY-RUN by default: prints the full impact analysis and writes nothing
 *     to the database. `--execute` performs the repair inside a single
 *     transaction.
 *
 * ── Usage ──────────────────────────────────────────────────────────────────
 *   php src/cron/fix-order-item-quantity-received.php            # dry-run (default)
 *   php src/cron/fix-order-item-quantity-received.php --execute  # apply
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
// instantiates Hybridauth\Provider\Google, which calls session_start() and
// breaks under CLI after stdout output (see also:
// src/cron/wipe-purchase-data.php:68-73).
require_once __DIR__ . '/../../config/config.php';

set_time_limit(0);

// ── Logger: stdout + daily file ──────────────────────────────────────
$logDir  = ROOT_DIRECTORY . '/public_html/var/logs';
if (!is_dir($logDir)) { @mkdir($logDir, 0755, true); }
$logFile = $logDir . '/fix-order-item-quantity-received-' . date('Y-m-d') . '.log';

function logLine(string $msg): void {
    global $logFile;
    $line = date('Y-m-d H:i:s') . ' ' . $msg;
    echo $line . PHP_EOL;
    @file_put_contents($logFile, $line . PHP_EOL, FILE_APPEND);
}

/**
 * Write a multi-line block (tables) verbatim to stdout AND to the log file.
 * No timestamp prefix, so column alignment survives.
 */
function logBlock(string $text): void {
    global $logFile;
    $text = rtrim($text, "\n");
    echo $text . PHP_EOL;
    @file_put_contents($logFile, $text . PHP_EOL, FILE_APPEND);
}

logLine("=== fix-order-item-quantity-received.php START ===");

// ── Single-instance lock (Atte\Utils\Locker → public_html/var/locks/) ─
$locker = new Locker('fix_order_item_quantity_received.lock');
if ($locker->isLocked()) {
    logLine("ABORT: another fix-order-item-quantity-received run is already in progress.");
    exit(1);
}
$locker->lock();
register_shutdown_function(static function () use ($locker): void {
    $locker->unlock();
});
logLine("Lock acquired: fix_order_item_quantity_received.lock");

// ── PDO handle — same MsaDB::getInstance() pattern as the other cron
//    scripts (wipe-purchase-data.php, warehouse-data-gs-upload.php,
//    migrate-to-procurement-schema.php). BaseDB reads MSA{URL,USERNAME,
//    PASSWORD} from $_ENV (loaded by config.php's Dotenv call).
$MsaDB = MsaDB::getInstance();
$db    = $MsaDB->db;

// ── Pre-flight: refuse to run against the wrong database ──────────────
$dbName = (string) $db->query('SELECT DATABASE()')->fetchColumn();
if ($dbName !== 'atte_ms') {
    fwrite(STDERR, "✗ Connected to '{$dbName}', not 'atte_ms'. Aborting — refusing to repair the wrong database.\n");
    logLine("ABORT: connected to '{$dbName}', not 'atte_ms'.");
    exit(1);
}
logLine("Connected to: {$dbName}");

// ── Mode: dry-run by default, --execute applies the repair ────────────
$execute     = false;
$unknownArgs = [];
foreach (array_slice($_SERVER['argv'] ?? [], 1) as $arg) {
    switch ($arg) {
        case '--execute':
            $execute = true;
            break;
        case '--dry-run':
            // same as the default — accepted explicitly for convenience
            break;
        case '--help':
        case '-h':
            logLine('Usage:');
            logLine('  php src/cron/fix-order-item-quantity-received.php            # dry-run (default)');
            logLine('  php src/cron/fix-order-item-quantity-received.php --execute  # apply');
            exit(0);
        default:
            $unknownArgs[] = $arg;
    }
}
if (!empty($unknownArgs)) {
    $msg = 'Unknown argument(s): ' . implode(', ', $unknownArgs)
         . ' — use --dry-run (default) or --execute.';
    fwrite(STDERR, "✗ {$msg}\n");
    logLine("ABORT: {$msg}");
    exit(2);
}
$mode = $execute ? 'EXECUTE (will write to the database)' : 'DRY-RUN (no database writes)';
logLine("Mode: {$mode}");

// ── Helpers ───────────────────────────────────────────────────────────
function dbFetchAll(\PDO $db, string $sql, array $params = []): array {
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(\PDO::FETCH_ASSOC);
}

function dbFetchColumn(\PDO $db, string $sql, array $params = []): string {
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return (string) $stmt->fetchColumn();
}

/** Format a DECIMAL string for humans: thousands separator, trimmed zeros. */
function fmtNum($value, int $decimals = 4): string {
    if ($value === null || $value === '') {
        return '0';
    }
    $out = number_format((float) $value, $decimals, '.', ',');
    if (strpos($out, '.') !== false) {
        $out = rtrim(rtrim($out, '0'), '.');
        if ($out === '' || $out === '-') {
            $out = '0';
        }
    }
    return $out;
}

/** Display width of a string (UTF-8 aware when mbstring is available). */
function dispWidth(string $text): int {
    return function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
}

/**
 * Render a fixed-width table. Column widths are derived from the actual
 * content (header + cells), so nothing ever runs together.
 *
 * @param string[] $headers
 * @param array[]  $rows     rows of cell strings
 * @param string[] $align    'l' (default) or 'r' per column
 * @param array|null $totalRow optional trailing TOTAL row (rendered after a rule)
 */
function renderTable(array $headers, array $rows, array $align = [], ?array $totalRow = null): string {
    $colCount = count($headers);
    $widths   = [];
    for ($c = 0; $c < $colCount; $c++) {
        $w = dispWidth((string) $headers[$c]);
        foreach ($rows as $r) {
            $w = max($w, dispWidth((string) ($r[$c] ?? '')));
        }
        if ($totalRow !== null) {
            $w = max($w, dispWidth((string) ($totalRow[$c] ?? '')));
        }
        $widths[$c] = $w;
    }

    $render = static function (array $cells) use ($widths, $colCount, $align): string {
        $out = '';
        for ($c = 0; $c < $colCount; $c++) {
            $cell = (string) ($cells[$c] ?? '');
            $pad  = str_repeat(' ', max(0, $widths[$c] - dispWidth($cell)));
            $out .= (($align[$c] ?? 'l') === 'r') ? $pad . $cell : $cell . $pad;
            $out .= '  ';
        }
        return rtrim($out, ' ');
    };

    $headerLine = $render($headers);
    $out        = $headerLine . PHP_EOL . str_repeat('-', dispWidth($headerLine)) . PHP_EOL;
    foreach ($rows as $r) {
        $out .= $render($r) . PHP_EOL;
    }
    if ($totalRow !== null) {
        $out .= str_repeat('-', dispWidth($headerLine)) . PHP_EOL;
        $out .= $render($totalRow) . PHP_EOL;
    }
    return $out;
}

/**
 * The one and only definition of "the truth": the sum of the receipt lines
 * that belong to a purchase-order line. Used by every read below and by the
 * repair UPDATE.
 */
const RECOMPUTED_SUM_SQL = 'COALESCE((SELECT SUM(i.quantity_received)
                                         FROM purchase__order_receipt_item i
                                        WHERE i.po_item_id = oi.id), 0)';

// ── Analysis (read-only — safe in both modes) ─────────────────────────
logLine('--- Analysis ---');

$summary = dbFetchAll(
    $db,
    "SELECT
        COUNT(*)                                                                   AS total_items,
        SUM(CASE WHEN oi.quantity_received <> " . RECOMPUTED_SUM_SQL . " THEN 1 ELSE 0 END) AS items_to_change,
        SUM(CASE WHEN oi.quantity_received <  " . RECOMPUTED_SUM_SQL . " THEN 1 ELSE 0 END) AS items_go_up,
        SUM(CASE WHEN oi.quantity_received >  " . RECOMPUTED_SUM_SQL . " THEN 1 ELSE 0 END) AS items_go_down,
        COALESCE(SUM(ABS(oi.quantity_received - " . RECOMPUTED_SUM_SQL . ")), 0)        AS abs_delta
       FROM purchase__order_item oi"
)[0];

$totalItems    = (int) $summary['total_items'];
$itemsToChange = (int) $summary['items_to_change'];
$itemsGoUp     = (int) $summary['items_go_up'];
$itemsGoDown   = (int) $summary['items_go_down'];
$absDelta      = (string) $summary['abs_delta'];

$zeroSumButCounter = (int) dbFetchColumn(
    $db,
    'SELECT COUNT(*)
       FROM purchase__order_item oi
      WHERE oi.quantity_received > 0 AND ' . RECOMPUTED_SUM_SQL . ' = 0'
);

$recomputedOverOrdered = (int) dbFetchColumn(
    $db,
    'SELECT COUNT(*)
       FROM purchase__order_item oi
      WHERE ' . RECOMPUTED_SUM_SQL . ' > oi.quantity'
);

$stillMismatched = (int) dbFetchColumn(
    $db,
    'SELECT COUNT(*)
       FROM purchase__order_item oi
      WHERE oi.quantity_received <> ' . RECOMPUTED_SUM_SQL
);

// POs in state 'partially_received' whose EVERY line would be fully received
// after the repair → those would be closable. (The state itself is NOT
// changed by this script — see the docblock.)
$wouldBeClosable = (int) dbFetchColumn(
    $db,
    "SELECT COUNT(*)
       FROM (
            SELECT o.id
              FROM purchase__order o
              JOIN purchase__order_item oi ON oi.po_id = o.id
             WHERE o.state = 'partially_received'
             GROUP BY o.id
            HAVING SUM(CASE WHEN " . RECOMPUTED_SUM_SQL . " >= oi.quantity THEN 0 ELSE 1 END) = 0
       ) AS closable"
);

$partiallyReceivedPos = (int) dbFetchColumn(
    $db,
    "SELECT COUNT(*) FROM purchase__order WHERE state = 'partially_received'"
);

// ── Dry-run / pre-write summary ───────────────────────────────────────
logLine('');
logLine("MODE: {$mode}");
logLine('');
logLine('=== Impact summary ===');
logLine("  purchase__order_item rows (total)            : " . fmtNum($totalItems));
logLine("  rows that WOULD change                       : " . fmtNum($itemsToChange));
logLine("  rows whose counter would go UP               : " . fmtNum($itemsGoUp));
logLine("  rows whose counter would go DOWN             : " . fmtNum($itemsGoDown));
logLine("  sum of absolute deltas                       : " . fmtNum($absDelta));
logLine("  rows with recomputed sum = 0 but counter > 0 : " . fmtNum($zeroSumButCounter));
logLine("  rows where recomputed sum > ordered qty      : " . fmtNum($recomputedOverOrdered));
logLine("  rows still mismatching (pre-write)           : " . fmtNum($stillMismatched));

// ── Breakdown by PO state ─────────────────────────────────────────────
$stateRows = dbFetchAll(
    $db,
    "SELECT o.state                                        AS state,
            COUNT(DISTINCT o.id)                           AS pos_affected,
            COUNT(*)                                       AS items_affected
       FROM purchase__order o
       JOIN purchase__order_item oi ON oi.po_id = o.id
      WHERE oi.quantity_received <> " . RECOMPUTED_SUM_SQL . "
      GROUP BY o.state
      ORDER BY o.state"
);
$stateTotals = [];
foreach (dbFetchAll($db, 'SELECT state, COUNT(*) AS n FROM purchase__order GROUP BY state') as $r) {
    $stateTotals[(string) $r['state']] = (int) $r['n'];
}

$stateTableRows = [];
foreach ($stateRows as $r) {
    $state = (string) $r['state'];
    $stateTableRows[] = [
        $state,
        fmtNum($stateTotals[$state] ?? 0),
        fmtNum((int) $r['pos_affected']),
        fmtNum((int) $r['items_affected']),
    ];
}
$affectedPos = 0;
foreach ($stateRows as $r) {
    $affectedPos += (int) $r['pos_affected'];
}
$stateTable = renderTable(
    ['po state', 'pos total', 'pos affected', 'items affected'],
    $stateTableRows,
    ['l', 'r', 'r', 'r'],
    ['TOTAL', fmtNum(array_sum($stateTotals)), fmtNum($affectedPos), fmtNum($itemsToChange)]
);

logLine('');
logLine('=== Affected purchase orders by state ===');
logBlock($stateTable);

// ── Top 20 largest divergences ────────────────────────────────────────
$topRows = dbFetchAll(
    $db,
    "SELECT oi.id                AS po_item_id,
            oi.po_id             AS po_id,
            o.po_number          AS po_number,
            oi.quantity          AS ordered_qty,
            oi.quantity_received AS current_counter,
            " . RECOMPUTED_SUM_SQL . " AS recomputed_sum,
            (oi.quantity_received - " . RECOMPUTED_SUM_SQL . ") AS delta
       FROM purchase__order_item oi
       LEFT JOIN purchase__order o ON o.id = oi.po_id
      WHERE oi.quantity_received <> " . RECOMPUTED_SUM_SQL . "
      ORDER BY ABS(oi.quantity_received - " . RECOMPUTED_SUM_SQL . ") DESC, oi.id ASC
      LIMIT 20"
);

$topTableRows = [];
foreach ($topRows as $r) {
    $topTableRows[] = [
        (string) $r['po_item_id'],
        (string) $r['po_id'],
        (string) ($r['po_number'] ?? '—'),
        fmtNum($r['ordered_qty']),
        fmtNum($r['current_counter']),
        fmtNum($r['recomputed_sum']),
        fmtNum($r['delta']),
    ];
}
$topTable = renderTable(
    ['po_item.id', 'po_id', 'po_number', 'ordered', 'counter now', 'recomputed', 'delta'],
    $topTableRows,
    ['r', 'r', 'l', 'r', 'r', 'r', 'r']
);

logLine('');
logLine('=== 20 largest divergences (counter now vs. recomputed sum) ===');
logBlock($topTable);

// ── Operational goal: closable partially_received POs ─────────────────
logLine('');
logLine('=== partially_received POs ===');
logLine("  POs in state 'partially_received'                        : " . fmtNum($partiallyReceivedPos));
logLine("  POs that would be FULLY received after the fix (closable): " . fmtNum($wouldBeClosable));
logLine('  NOTE: purchase__order.state is NOT modified by this script — closing a PO stays a human decision.');

// ── Execute path ──────────────────────────────────────────────────────
if ($execute) {
    logLine('');
    logLine('--- Transaction begins ---');
    $db->beginTransaction();
    try {
        // The repair: one single, idempotent UPDATE. The correlated subquery
        // reads ONLY purchase__order_receipt_item; the ONLY column written is
        // purchase__order_item.quantity_received.
        $affected = $db->exec(
            "UPDATE purchase__order_item oi
                SET oi.quantity_received = COALESCE((
                      SELECT SUM(i.quantity_received) FROM purchase__order_receipt_item i
                      WHERE i.po_item_id = oi.id
                  ), 0)
              WHERE oi.quantity_received <> COALESCE((
                      SELECT SUM(i.quantity_received) FROM purchase__order_receipt_item i
                      WHERE i.po_item_id = oi.id
                  ), 0)"
        );
        $db->commit();
        logLine('--- Transaction COMMIT ---');
        logLine(sprintf('Rows updated: %d', (int) $affected));
    } catch (\Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $err = 'ROLLBACK: ' . $e->getMessage();
        logLine('--- Transaction ' . $err . ' ---');
        fwrite(STDERR, "✗ {$err}\n");
        exit(1);
    }
} else {
    logLine('');
    logLine('DRY-RUN: nothing was written to the database. Re-run with --execute to apply the repair.');
}

// ── Verification block ────────────────────────────────────────────────
$verifyMismatched = (int) dbFetchColumn(
    $db,
    'SELECT COUNT(*)
       FROM purchase__order_item oi
      WHERE oi.quantity_received <> ' . RECOMPUTED_SUM_SQL
);

logLine('');
logLine('=== Verification ===');
logLine("  items still mismatching after the fix        : " . fmtNum($verifyMismatched)
        . ($execute ? ($verifyMismatched === 0 ? '  ✔ OK' : '  ✗ NOT OK') : '  (expected in dry-run)'));
logLine("  purchase__order.state values                : untouched by this script");

// Per-line detail of the partially_received POs so a human can see the
// remaining shortfall.
$partialLines = dbFetchAll(
    $db,
    "SELECT o.id                AS po_id,
            o.po_number          AS po_number,
            oi.id                AS po_item_id,
            oi.quantity          AS ordered_qty,
            " . RECOMPUTED_SUM_SQL . " AS recomputed_sum
       FROM purchase__order o
       JOIN purchase__order_item oi ON oi.po_id = o.id
      WHERE o.state = 'partially_received'
      ORDER BY o.id ASC, oi.id ASC"
);

// Build the per-line cells, then derive ONE global set of column widths so
// that every PO block lines up with the others.
$partialRows = [];
foreach ($partialLines as $r) {
    $ordered    = (float) $r['ordered_qty'];
    $recomputed = (float) $r['recomputed_sum'];
    $shortfall  = $ordered - $recomputed;
    $partialRows[] = [
        'po_id'         => (int) $r['po_id'],
        'po_number'     => (string) $r['po_number'],
        'item'          => (string) $r['po_item_id'],
        'ordered_raw'   => $ordered,
        'received_raw'  => $recomputed,
        'shortfall_raw' => $shortfall,
        'ordered'       => fmtNum($r['ordered_qty']),
        'received'      => fmtNum($r['recomputed_sum']),
        'shortfall'     => $shortfall > 0 ? fmtNum($shortfall) : '0',
        'status'        => $shortfall > 0 ? 'SHORT' : 'full',
    ];
}

$wItem    = dispWidth('po_item.id');
$wOrdered = dispWidth('ordered');
$wRecv    = dispWidth('recomputed received');
$wShort   = dispWidth('shortfall');
$wStatus  = dispWidth('status');
foreach ($partialRows as $c) {
    $wItem    = max($wItem, dispWidth($c['item']));
    $wOrdered = max($wOrdered, dispWidth($c['ordered']));
    $wRecv    = max($wRecv, dispWidth($c['received']));
    $wShort   = max($wShort, dispWidth($c['shortfall']));
    $wStatus  = max($wStatus, dispWidth($c['status']));
}

$partialByPo = [];
foreach ($partialRows as $c) {
    $partialByPo[$c['po_id']][] = $c;
}

$partialTable = '';
foreach ($partialByPo as $poId => $cells) {
    $orderedTotal = 0.0;
    $recvTotal    = 0.0;
    $shortLines   = 0;
    foreach ($cells as $c) {
        $orderedTotal += $c['ordered_raw'];
        $recvTotal    += $c['received_raw'];
        if ($c['shortfall_raw'] > 0) {
            $shortLines++;
        }
    }
    $poShortfall = $orderedTotal - $recvTotal;

    // right-aligned cell + 2-space gutter
    $cell = static function (string $value, int $width): string {
        return str_pad($value, $width, ' ', STR_PAD_LEFT) . '  ';
    };
    $rule = str_repeat('-', $wItem + $wOrdered + $wRecv + $wShort + $wStatus + 8);

    $partialTable .= PHP_EOL
        . 'PO #' . $poId . '  ' . $cells[0]['po_number']
        . '  — lines: ' . count($cells)
        . '  ordered: ' . fmtNum($orderedTotal)
        . '  recomputed received: ' . fmtNum($recvTotal)
        . '  shortfall: ' . fmtNum($poShortfall)
        . '  short lines: ' . $shortLines . PHP_EOL;
    $partialTable .= '  ' . $rule . PHP_EOL;
    $partialTable .= '  ' . $cell('po_item.id', $wItem)
                   . $cell('ordered', $wOrdered)
                   . $cell('recomputed received', $wRecv)
                   . $cell('shortfall', $wShort)
                   . 'status' . PHP_EOL;
    $partialTable .= '  ' . $rule . PHP_EOL;
    foreach ($cells as $c) {
        $partialTable .= '  ' . $cell($c['item'], $wItem)
                       . $cell($c['ordered'], $wOrdered)
                       . $cell($c['received'], $wRecv)
                       . $cell($c['shortfall'], $wShort)
                       . $c['status'] . PHP_EOL;
    }
}

logLine('');
logLine("=== partially_received POs — per-line ordered vs. recomputed received ===");
logLine("  (these lines stay 'partially_received'; this script does not change purchase__order.state)");
logBlock($partialTable);

logLine('');
logLine('=== fix-order-item-quantity-received.php DONE ===');

$locker->unlock();
logLine('Lock released: fix_order_item_quantity_received.lock');
