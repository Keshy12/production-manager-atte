<?php
/**
 * One-shot CLI: synthesize the missing "delivery" layer for the LEGACY
 * goods-receipt rows and back-link them, so every reception shows up in the
 * new "Dostawy wg faktur / WZ" list.
 *
 * ── Background ─────────────────────────────────────────────────────────────
 * The procurement module has TWO reception layers that share the same tables:
 *
 *   NEW  "delivery" layer
 *        purchase__delivery            — one vendor document (faktura / WZ)
 *        purchase__delivery_line       — one invoice line
 *        purchase__delivery_allocation — line → PO-item split
 *
 *   LEGACY "receipt" projection
 *        purchase__order_receipt       — one row per PO touched
 *        purchase__order_receipt_item  — one row per PO line received
 *
 * The NEW flow writes BOTH: a receipt is the legacy-shaped projection of a
 * delivery, and it carries two bridge columns back to the new tables
 * (`purchase__order_receipt.delivery_id` and
 * `purchase__order_receipt_item.delivery_allocation_id`).
 *
 * A receipt whose `delivery_id IS NULL` therefore has NO delivery — it was
 * created before the delivery layer existed (or by the Google Sheets
 * importer). Those rows are invisible to the new "Dostawy" list. This script
 * rebuilds the missing delivery / line / allocation rows for them and fills
 * the bridge columns in.
 *
 * On the live database at the time of writing: 3772 receipts, 3 of which
 * already have a `delivery_id` (the new flow) → 3769 legacy receipts and
 * 8936 legacy receipt items to migrate.
 *
 * ── GROUPING ───────────────────────────────────────────────────────────────
 * One delivery per `(vendor_id, document_number)` of the legacy receipts.
 * All receipts in a group MUST share the same vendor (the vendor of their
 * purchase order) — that is asserted, and a group that violates it is
 * skipped and reported. In practice every legacy GRN number is unique
 * (3772 receipts → 3771 distinct document numbers, and the only duplicated
 * one already has a `delivery_id`), so this yields ~3769 deliveries.
 *
 * The grouping is implemented GENERICALLY: two legacy receipts that share a
 * GRN merge into ONE delivery with two receipts attached to it.
 *
 * ── Exact column mapping ──────────────────────────────────────────────────
 * `purchase__delivery` (one row per group):
 *
 *   vendor_id               → purchase__order.vendor_id of the group's
 *                             receipts (asserted identical inside the group)
 *   state                   → 'posted'
 *   document_type           → 'invoice' (overridable with --document-type=)
 *   vendor_document_number  → the legacy `document_number` (the GRN)
 *   document_date           → DATE(received_at) of the group's earliest
 *                             receipt
 *   grn_number              → the legacy `document_number` (the GRN)
 *   currency                → list__vendor.default_currency (INT FK) →
 *                             list__currency.code (PLN / EUR / USD …),
 *                             fallback 'PLN' when NULL or unknown
 *   default_sub_magazine_id → the sub-magazine shared by the group's items
 *                             (most frequent one; NULL when the group has no
 *                             items)
 *   received_at             → the group's earliest
 *                             purchase__order_receipt.received_at
 *   transfer_group_id       → NULL  (see below)
 *   created_by, posted_by   → the group's earliest receipt `received_by`
 *                             (FK → user.user_id)
 *   created_at, posted_at   → the group's earliest receipt `received_at`
 *   comment                 → the group's earliest receipt `comment`
 *
 * `purchase__delivery_line` (one row per purchase__order_receipt_item):
 *
 *   delivery_id             → the new delivery id
 *   line_no                 → 1..n per delivery, in receipt-item id order
 *   vendor_part_id          → purchase__order_item.vendor_part_id of the
 *                             item's po_item_id
 *   quantity                → the item's `quantity_received`
 *   unit_price              → purchase__order_item.unit_price (NULL stays
 *                             NULL)
 *   currency                → purchase__order_item.currency
 *   sub_magazine_id         → the item's `sub_magazine_id`
 *   inventory_parts_id      → NULL  (see below)
 *   comment                 → the item's `comment`
 *
 * `purchase__delivery_allocation` (one row per purchase__order_receipt_item):
 *
 *   delivery_line_id        → the new line id
 *   po_item_id              → the item's `po_item_id`
 *   quantity                → the item's `quantity_received`
 *   is_manual               → 0
 *
 * BACK-LINK (the actual "retirement" of the legacy rows):
 *
 *   UPDATE purchase__order_receipt
 *      SET delivery_id = <new delivery id>
 *   UPDATE purchase__order_receipt_item
 *      SET delivery_allocation_id = <new allocation id>
 *
 * ── Why vendor_document_number holds the legacy GRN ───────────────────────
 * The legacy tables have NO invoice number. `purchase__order_receipt`
 * stores exactly one document identifier — `document_number` — and in the
 * legacy flow that value is the GRN (goods-receipt note, e.g. "GRN/009759").
 * There is no other document number anywhere in the legacy data, so the GRN
 * is the closest truthful value for both `vendor_document_number` and
 * `grn_number`. A migrated delivery therefore reads as "vendor document
 * number = GRN/xxxxxx" in the UI, which is the only document reference the
 * legacy reception ever had.
 *
 * ── Why transfer_group_id is NULL ─────────────────────────────────────────
 * `purchase__delivery.transfer_group_id` points at
 * `inventory__transfer_groups` — the stock-transfer session a reception
 * created. Legacy receipts never created one (the whole database contains
 * exactly ONE row of type 'purchase_receipt'), so there is nothing to point
 * at and the column stays NULL.
 *
 * CONSEQUENCE: a migrated delivery cannot be cancelled / reversed by the UI,
 * because the cancellation path works on the transfer group. That is
 * accepted on purpose: the legacy receipts are historical facts and the
 * stock rows they created are long since part of the inventory.
 *
 * ── Why inventory_parts_id is NULL ────────────────────────────────────────
 * `purchase__delivery_line.inventory_parts_id` points at the physical stock
 * row the line put into a magazine. The legacy flow did not create
 * `inventory__parts` rows for these receipts (the whole database contains
 * exactly ONE such row, belonging to the new flow), so there is no stock row
 * to reference and the column stays NULL. The reception is still fully
 * represented — the quantity, the magazine and the PO link are all there.
 *
 * ── The receipt tables are NOT dropped ────────────────────────────────────
 * `purchase__order_receipt` / `purchase__order_receipt_item` stay exactly
 * where they are. They are NOT legacy junk to be thrown away: they are the
 * LIVE PROJECTION of the new flow as well (PurchaseActionHandler::postDelivery()
 * writes one receipt row per allocation), and the entire "Historia przyjęć"
 * screen, the PO close logic and the `quantity_received` recomputation all
 * read them. After this migration both layers describe the same receptions —
 * the receipt rows just gain the two bridge columns that point at the
 * delivery they belong to.
 *
 * ── purchase__number_counter is untouched ─────────────────────────────────
 * The GRN counter (`purchase__number_counter`, type = 'grn') is SHARED
 * between the legacy and the new flow — it is one sequence for the whole
 * module. This script does not read it, does not write it and does not
 * advance it. The migrated deliveries keep the GRN numbers the legacy flow
 * already handed out; no new numbers are consumed, so the next real GRN
 * issued by the new flow continues the same sequence with no gap and no
 * collision.
 *
 * ── What is deliberately NOT touched ──────────────────────────────────────
 *   - `purchase__order_item` — the `quantity_received` counter repair is a
 *     SEPARATE script (src/cron/fix-order-item-quantity-received.php).
 *   - `purchase__order.state` — untouched (closing a PO stays a human
 *     decision).
 *   - `purchase__order_receipt` / `_item` — only the two bridge columns
 *     (`delivery_id`, `delivery_allocation_id`) are written.
 *   - `inventory__*` — read-only (nothing to create: see inventory_parts_id).
 *   - `purchase__number_counter` — see above.
 *
 * ── Safety model ──────────────────────────────────────────────────────────
 *   - CLI only (HTTP access returns 403).
 *   - Single-instance lock (Atte\Utils\Locker).
 *   - Refuses to run unless the connection points at the `atte_ms` database.
 *   - DRY-RUN by default: prints the full impact analysis and writes
 *     NOTHING to the database. `--execute` performs the migration.
 *   - SCOPE GUARD: only receipts with `delivery_id IS NULL` are touched, so
 *     the script is IDEMPOTENT — a second run finds nothing and writes
 *     nothing.
 *   - Chunked transactions (500 deliveries per commit) so the transaction
 *     never grows unbounded; each chunk is atomic, so an interrupted run is
 *     resumable: whatever was committed stays committed and the next run
 *     picks up only what is still un-linked.
 *
 * ── Usage ─────────────────────────────────────────────────────────────────
 *   php src/cron/migrate-legacy-receipts-to-deliveries.php            # dry-run (default)
 *   php src/cron/migrate-legacy-receipts-to-deliveries.php --execute  # apply
 *
 *   php src/cron/migrate-legacy-receipts-to-deliveries.php --execute --document-type=wz
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
$logFile = $logDir . '/migrate-legacy-receipts-to-deliveries-' . date('Y-m-d') . '.log';

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

logLine('=== migrate-legacy-receipts-to-deliveries.php START ===');

// ── Single-instance lock (Atte\Utils\Locker → public_html/var/locks/) ─
$locker = new Locker('migrate_legacy_receipts_to_deliveries.lock');
if ($locker->isLocked()) {
    logLine('ABORT: another migrate-legacy-receipts-to-deliveries run is already in progress.');
    exit(1);
}
$locker->lock();
register_shutdown_function(static function () use ($locker): void {
    $locker->unlock();
});
logLine('Lock acquired: migrate_legacy_receipts_to_deliveries.lock');

// ── PDO handle — same MsaDB::getInstance() pattern as the other cron
//    scripts (wipe-purchase-data.php, warehouse-data-gs-upload.php).
//    BaseDB reads MSA{URL,USERNAME,PASSWORD} from $_ENV (loaded by
//    config.php's Dotenv call).
$MsaDB = MsaDB::getInstance();
$db    = $MsaDB->db;

// ── Pre-flight: refuse to run against the wrong database ──────────────
$dbName = (string) $db->query('SELECT DATABASE()')->fetchColumn();
if ($dbName !== 'atte_ms') {
    fwrite(STDERR, "✗ Connected to '{$dbName}', not 'atte_ms'. Aborting — refusing to migrate the wrong database.\n");
    logLine("ABORT: connected to '{$dbName}', not 'atte_ms'.");
    exit(1);
}
logLine("Connected to: {$dbName}");

// ── Mode: dry-run by default, --execute applies the migration ─────────
$execute        = false;
$documentType   = 'invoice';
$unknownArgs    = [];
$argv           = array_slice($_SERVER['argv'] ?? [], 1);
for ($i = 0; $i < count($argv); $i++) {
    $arg = $argv[$i];
    if ($arg === '--execute') {
        $execute = true;
    } elseif ($arg === '--dry-run') {
        // same as the default — accepted explicitly for convenience
    } elseif (str_starts_with($arg, '--document-type=')) {
        $documentType = trim(substr($arg, strlen('--document-type=')));
    } elseif ($arg === '--document-type') {
        $documentType = trim((string) ($argv[$i + 1] ?? ''));
        $i++;
    } elseif ($arg === '--help' || $arg === '-h') {
        logLine('Usage:');
        logLine('  php src/cron/migrate-legacy-receipts-to-deliveries.php            # dry-run (default)');
        logLine('  php src/cron/migrate-legacy-receipts-to-deliveries.php --execute  # apply');
        logLine('');
        logLine('Options:');
        logLine('  --document-type=invoice|wz   document_type of the created deliveries (default: invoice)');
        exit(0);
    } else {
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
if (!in_array($documentType, ['invoice', 'wz'], true)) {
    $msg = "Invalid --document-type='{$documentType}' — allowed: invoice, wz.";
    fwrite(STDERR, "✗ {$msg}\n");
    logLine("ABORT: {$msg}");
    exit(2);
}
$mode = $execute ? 'EXECUTE (will write to the database)' : 'DRY-RUN (no database writes)';
logLine("Mode: {$mode}");
logLine("document_type: {$documentType}");

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
 * @param array|null $totalRow optional trailing TOTAL row
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

// ═══════════════════════════════════════════════════════════════════════
// ── Load the legacy data (read-only) ───────────────────────────────────
// ═══════════════════════════════════════════════════════════════════════
logLine('');
logLine('--- Reading legacy receptions (delivery_id IS NULL) ---');

$legacyReceipts = dbFetchAll(
    $db,
    "SELECT r.id                AS receipt_id,
            r.po_id,
            r.document_number,
            r.received_by,
            r.received_at,
            r.comment           AS receipt_comment,
            o.vendor_id,
            v.name              AS vendor_name,
            c.code              AS vendor_currency
       FROM purchase__order_receipt r
       JOIN purchase__order o        ON o.id = r.po_id
       JOIN list__vendor v           ON v.id = o.vendor_id
       LEFT JOIN list__currency c    ON c.id = v.default_currency
      WHERE r.delivery_id IS NULL
      ORDER BY r.document_number, r.received_at, r.id"
);

$legacyItems = dbFetchAll(
    $db,
    "SELECT ri.id                 AS receipt_item_id,
            ri.receipt_id,
            ri.po_item_id,
            ri.quantity_received,
            ri.sub_magazine_id,
            ri.comment            AS item_comment,
            oi.vendor_part_id,
            oi.unit_price,
            oi.currency           AS item_currency,
            po.po_number
       FROM purchase__order_receipt_item ri
       JOIN purchase__order_receipt r ON r.id = ri.receipt_id
       LEFT JOIN purchase__order_item oi ON oi.id = ri.po_item_id
       LEFT JOIN purchase__order po      ON po.id = oi.po_id
      WHERE r.delivery_id IS NULL
      ORDER BY ri.id"
);

$totalLegacyReceipts = count($legacyReceipts);
$totalLegacyItems    = count($legacyItems);
logLine("Legacy receipts (delivery_id IS NULL): {$totalLegacyReceipts}");
logLine("Legacy receipt items (delivery_id IS NULL): {$totalLegacyItems}");

// ── Index the items by receipt id ─────────────────────────────────────
$itemsByReceipt = [];
foreach ($legacyItems as $it) {
    $itemsByReceipt[(int) $it['receipt_id']][] = $it;
}

// ── Build the groups (one delivery per document_number) ───────────────
$groups         = [];
$groupKeyByRcpt = [];
$nullDocReceipts = 0;

foreach ($legacyReceipts as $r) {
    $rid = (int) $r['receipt_id'];
    $doc = $r['document_number'];

    if ($doc === null || trim((string) $doc) === '') {
        // A receipt without any document number cannot be merged with
        // anything — it becomes a group of its own.
        $key = 'NO-DOC:' . $rid;
        $nullDocReceipts++;
    } else {
        $key = 'GRN:' . trim((string) $doc);
    }

    if (!isset($groups[$key])) {
        $groups[$key] = [
            'key'          => $key,
            'document_number' => $doc,
            'receipts'     => [],
            'items'        => [],
            'vendor_ids'   => [],
        ];
    }
    $groups[$key]['receipts'][] = $r;
    $groupKeyByRcpt[$rid] = $key;

    foreach ($itemsByReceipt[$rid] ?? [] as $it) {
        $groups[$key]['items'][] = $it;
    }
}

// ── Per-group roll-up ─────────────────────────────────────────────────
$skippedGroups    = [];
$migratableGroups = [];
$orphanItemCount  = 0;
$orphanVendorPart = 0;
$currencyOverflow = 0;
$lineCurrencyWidth = (int) dbFetchColumn(
    $db,
    "SELECT CHARACTER_MAXIMUM_LENGTH
       FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME   = 'purchase__delivery_line'
        AND COLUMN_NAME  = 'currency'"
);
if ($lineCurrencyWidth <= 0) { $lineCurrencyWidth = 8; }

foreach ($groups as $key => &$g) {
    // ── vendor consistency (assert) ───────────────────────────────────
    $vendorIds = [];
    foreach ($g['receipts'] as $r) {
        $vendorIds[(int) $r['vendor_id']] = true;
    }
    if (count($vendorIds) !== 1) {
        $g['skip_reason'] = 'mixed vendors: ' . implode('/', array_keys($vendorIds));
        $skippedGroups[]  = $g;
        continue;
    }
    $g['vendor_id'] = (int) array_key_first($vendorIds);

    // ── earliest receipt drives the delivery header ───────────────────
    usort($g['receipts'], static function ($a, $b) {
        $cmp = strcmp((string) $a['received_at'], (string) $b['received_at']);
        return $cmp !== 0 ? $cmp : ((int) $a['receipt_id'] <=> (int) $b['receipt_id']);
    });
    $earliest = $g['receipts'][0];

    $g['earliest_receipt_id']   = (int) $earliest['receipt_id'];
    $g['earliest_received_at']  = (string) $earliest['received_at'];
    $g['earliest_received_by']  = (int) $earliest['received_by'];
    $g['earliest_comment']      = $earliest['receipt_comment'];
    $g['vendor_name']           = (string) $earliest['vendor_name'];
    $g['currency']              = ($earliest['vendor_currency'] !== null && $earliest['vendor_currency'] !== '')
        ? (string) $earliest['vendor_currency']
        : 'PLN';

    // ── lines: receipt-item id order ──────────────────────────────────
    usort($g['items'], static fn($a, $b) => ((int) $a['receipt_item_id']) <=> ((int) $b['receipt_item_id']));

    $g['lines']  = [];
    $lineNo      = 0;
    $magCount    = [];
    foreach ($g['items'] as $it) {
        if ($it['vendor_part_id'] === null || $it['po_item_id'] === null) {
            // Cannot create an allocation without a valid purchase__order_item
            // (FK) nor a line without a vendor part (FK) → the item is
            // reported and skipped.
            $orphanItemCount++;
            continue;
        }
        if ($it['item_currency'] !== null && strlen((string) $it['item_currency']) > $lineCurrencyWidth) {
            $currencyOverflow++;
        }
        $lineNo++;
        $g['lines'][] = [
            'line_no'          => $lineNo,
            'receipt_item_id'  => (int) $it['receipt_item_id'],
            'po_item_id'       => (int) $it['po_item_id'],
            'vendor_part_id'   => (int) $it['vendor_part_id'],
            'quantity'         => (string) $it['quantity_received'],
            'unit_price'       => $it['unit_price'],
            'currency'         => $it['item_currency'],
            'sub_magazine_id'  => (int) $it['sub_magazine_id'],
            'comment'          => $it['item_comment'],
            'po_number'        => $it['po_number'],
        ];
        $magCount[(int) $it['sub_magazine_id']] = ($magCount[(int) $it['sub_magazine_id']] ?? 0) + 1;
    }

    // ── default_sub_magazine_id: most frequent magazine of the group ──
    $g['default_sub_magazine_id'] = null;
    if (!empty($magCount)) {
        arsort($magCount);
        $g['default_sub_magazine_id'] = (int) array_key_first($magCount);
    }

    $migratableGroups[] = $g;
}
unset($g);

// ── Roll-up numbers ───────────────────────────────────────────────────
$receiptsToMigrate   = 0;
$deliveriesToCreate  = 0;
$linesToCreate       = 0;
$allocationsToCreate = 0;
$itemsToBackLink     = 0;
$mixedVendorReceipts = 0;

foreach ($migratableGroups as $g) {
    $receiptsToMigrate   += count($g['receipts']);
    $deliveriesToCreate++;
    $linesToCreate       += count($g['lines']);
    $allocationsToCreate += count($g['lines']);
    $itemsToBackLink     += count($g['lines']);
}
foreach ($skippedGroups as $g) {
    $mixedVendorReceipts += count($g['receipts']);
}

// ═══════════════════════════════════════════════════════════════════════
// ── Dry-run / pre-write report ─────────────────────────────────────────
// ═══════════════════════════════════════════════════════════════════════
logLine('');
logLine('=== Impact summary ===');
logLine("  MODE                                          : {$mode}");
logLine("  document_type                                 : {$documentType}");
logLine("  receipts to migrate (delivery_id IS NULL)     : {$receiptsToMigrate}");
logLine("  deliveries to create                          : {$deliveriesToCreate}");
logLine("  delivery lines to create                      : {$linesToCreate}");
logLine("  delivery allocations to create                : {$allocationsToCreate}");
logLine("  receipts to back-link (delivery_id)           : {$receiptsToMigrate}");
logLine("  receipt items to back-link (delivery_alloc_id): {$itemsToBackLink}");
logLine("  groups skipped (mixed vendor inside a group)  : " . count($skippedGroups));
logLine("  receipts inside skipped groups                : {$mixedVendorReceipts}");
logLine("  receipts without a document_number            : {$nullDocReceipts}");
logLine("  items skipped (missing purchase__order_item)  : {$orphanItemCount}");
logLine("  items whose currency exceeds the line column  : {$currencyOverflow}"
    . " (delivery_line.currency is varchar({$lineCurrencyWidth}); the value is truncated to fit)");
logLine("  receipts with a missing purchase order        : 0 (FK-enforced)");

// ── Group-size breakdown ──────────────────────────────────────────────
$groupSizes = [];
foreach ($migratableGroups as $g) {
    $n = count($g['receipts']);
    $groupSizes[$n] = ($groupSizes[$n] ?? 0) + 1;
}
ksort($groupSizes);
$sizeRows = [];
foreach ($groupSizes as $n => $count) {
    $sizeRows[] = [(string) $n, (string) $count];
}
$sizeTable = renderTable(
    ['receipts in group', 'groups'],
    $sizeRows,
    ['r', 'r'],
    ['TOTAL', (string) count($migratableGroups)]
);
logLine('');
logLine('=== Group shape (receipts per delivery) ===');
logBlock($sizeTable);

// ── Vendor split of the created deliveries ────────────────────────────
$vendorRows = [];
foreach ($migratableGroups as $g) {
    $k = $g['vendor_id'] . '|' . $g['vendor_name'];
    $vendorRows[$k] = ($vendorRows[$k] ?? 0) + 1;
}
arsort($vendorRows);
$vendorTableRows = [];
foreach (array_slice($vendorRows, 0, 15, true) as $k => $n) {
    [$vid, $vname] = explode('|', $k, 2);
    $vendorTableRows[] = [$vid, $vname, (string) $n];
}
logLine('');
logLine('=== Top 15 vendors among the created deliveries ===');
logBlock(renderTable(['vendor_id', 'vendor', 'deliveries'], $vendorTableRows, ['r', 'l', 'r']));

// ── Currency split ────────────────────────────────────────────────────
$currencyRows = [];
foreach ($migratableGroups as $g) {
    $currencyRows[$g['currency']] = ($currencyRows[$g['currency']] ?? 0) + 1;
}
ksort($currencyRows);
$currencyTableRows = [];
foreach ($currencyRows as $code => $n) {
    $currencyTableRows[] = [(string) $code, (string) $n];
}
logLine('');
logLine('=== Currency of the created deliveries (vendor default currency) ===');
logBlock(renderTable(['currency', 'deliveries'], $currencyTableRows, ['l', 'r']));

// ── Sample of 10 fully-mapped rows ────────────────────────────────────
$nextDeliveryId = (int) dbFetchColumn(
    $db,
    "SELECT AUTO_INCREMENT FROM information_schema.TABLES
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'purchase__delivery'"
);
if ($nextDeliveryId <= 0) { $nextDeliveryId = 1; }

$sampleRows = [];
$sampleIdx  = 0;
foreach ($migratableGroups as $gi => $g) {
    if ($sampleIdx >= 10) { break; }
    $line = $g['lines'][0] ?? null;
    if ($line === null) { continue; }
    $sampleRows[] = [
        (string) $g['earliest_receipt_id'],
        (string) ($g['document_number'] ?? '—'),
        (string) ($nextDeliveryId + $gi),
        (string) $line['line_no'],
        fmtNum($line['quantity']),
        (string) ($line['po_number'] ?? '—'),
        $g['vendor_name'],
    ];
    $sampleIdx++;
}
logLine('');
logLine('=== Sample of 10 fully-mapped rows (old GRN → new delivery) ===');
logLine('  NOTE: the delivery id is PREDICTED (next AUTO_INCREMENT + group index) — it only');
logLine('        becomes real when the script runs with --execute.');
logBlock(renderTable(
    ['receipt_id', 'legacy GRN', 'new delivery id*', 'line_no', 'qty', 'po_number', 'vendor'],
    $sampleRows,
    ['r', 'l', 'r', 'r', 'r', 'l', 'l']
));

// ── Mixed-vendor groups (must be 0) ───────────────────────────────────
logLine('');
logLine('=== Vendor consistency inside groups (must be 0) ===');
logLine("  receipts whose vendor_id differs inside a group : {$mixedVendorReceipts}");
if (!empty($skippedGroups)) {
    $skippedRows = [];
    foreach ($skippedGroups as $g) {
        $skippedRows[] = [
            (string) ($g['document_number'] ?? '—'),
            (string) count($g['receipts']),
            (string) $g['skip_reason'],
        ];
    }
    logLine('  SKIPPED groups (they will NOT be migrated):');
    logBlock(renderTable(['document_number', 'receipts', 'reason'], $skippedRows, ['l', 'r', 'l']));
}

// ═══════════════════════════════════════════════════════════════════════
// ── Verification SELECTs ───────────────────────────────────────────────
// ═══════════════════════════════════════════════════════════════════════
$verifySql = [
    'receipts still without delivery_id (must be 0)' => '
        SELECT COUNT(*) FROM purchase__order_receipt
         WHERE delivery_id IS NULL',
    'receipt items still without delivery_allocation_id (must be 0)' => '
        SELECT COUNT(*) FROM purchase__order_receipt_item
         WHERE delivery_allocation_id IS NULL',
    'orphan allocations (po_item_id without purchase__order_item)' => '
        SELECT COUNT(*)
          FROM purchase__delivery_allocation a
          LEFT JOIN purchase__order_item oi ON oi.id = a.po_item_id
         WHERE oi.id IS NULL',
    'orphan lines (vendor_part_id missing in list__vendor_part)' => '
        SELECT COUNT(*)
          FROM purchase__delivery_line l
          LEFT JOIN list__vendor_part vp ON vp.id = l.vendor_part_id
         WHERE vp.id IS NULL',
    'receipts pointing at a missing delivery' => '
        SELECT COUNT(*)
          FROM purchase__order_receipt r
          LEFT JOIN purchase__delivery d ON d.id = r.delivery_id
         WHERE r.delivery_id IS NOT NULL
           AND d.id IS NULL',
    'receipts whose delivery vendor differs from the PO vendor' => '
        SELECT COUNT(*)
          FROM purchase__order_receipt r
          JOIN purchase__delivery d ON d.id = r.delivery_id
          JOIN purchase__order o    ON o.id = r.po_id
         WHERE d.vendor_id <> o.vendor_id',
];

// ── Execute path ──────────────────────────────────────────────────────
if ($execute) {
    logLine('');
    logLine('--- MIGRATION START ---');

    if (empty($migratableGroups)) {
        logLine('Nothing to migrate — all legacy receipts are already linked.');
    }

    $chunkSize = 500;
    $chunks    = array_chunk($migratableGroups, $chunkSize);
    $chunkNo   = 0;

    $insDelivery = $db->prepare(
        "INSERT INTO purchase__delivery
            (vendor_id, state, document_type, vendor_document_number, document_date,
             grn_number, currency, default_sub_magazine_id, received_at, transfer_group_id,
             created_by, posted_by, created_at, posted_at, comment)
         VALUES
            (?, 'posted', ?, ?, ?, ?, ?, ?, ?, NULL, ?, ?, ?, ?, ?)"
    );
    $insLine = $db->prepare(
        "INSERT INTO purchase__delivery_line
            (delivery_id, line_no, vendor_part_id, quantity, unit_price, currency,
             sub_magazine_id, inventory_parts_id, comment)
         VALUES (?, ?, ?, ?, ?, ?, ?, NULL, ?)"
    );
    $insAllocation = $db->prepare(
        "INSERT INTO purchase__delivery_allocation
            (delivery_line_id, po_item_id, quantity, is_manual)
         VALUES (?, ?, ?, 0)"
    );
    $updReceipt = $db->prepare(
        "UPDATE purchase__order_receipt
            SET delivery_id = ?
          WHERE id = ? AND delivery_id IS NULL"
    );
    $updItem = $db->prepare(
        "UPDATE purchase__order_receipt_item
            SET delivery_allocation_id = ?
          WHERE id = ? AND delivery_allocation_id IS NULL"
    );

    $doneDeliveries  = 0;
    $doneLines       = 0;
    $doneAllocations = 0;
    $doneReceipts    = 0;
    $doneItems       = 0;

    foreach ($chunks as $chunk) {
        $chunkNo++;

        // Re-read the set of still-unlinked receipts — a resumed run must
        // never re-touch an already linked receipt.
        $pendingSet = [];
        foreach (dbFetchAll($db, 'SELECT id FROM purchase__order_receipt WHERE delivery_id IS NULL') as $row) {
            $pendingSet[(int) $row['id']] = true;
        }

        // Same for the receipt items (a different id space).
        $pendingItemSet = [];
        foreach (dbFetchAll($db, 'SELECT id FROM purchase__order_receipt_item WHERE delivery_allocation_id IS NULL') as $row) {
            $pendingItemSet[(int) $row['id']] = true;
        }

        $db->beginTransaction();
        try {
            foreach ($chunk as $g) {
                // Resume safety: a receipt that already carries a delivery_id
                // is left alone. If only PART of a group was linked by an
                // earlier interrupted run, the still-pending receipts of that
                // group get their own (new) delivery — the already-linked ones
                // keep the delivery they have.
                $pendingReceipts = [];
                $alreadyLinked    = 0;
                foreach ($g['receipts'] as $r) {
                    if (isset($pendingSet[(int) $r['receipt_id']])) {
                        $pendingReceipts[] = $r;
                    } else {
                        $alreadyLinked++;
                    }
                }
                if (empty($pendingReceipts)) {
                    logLine("  chunk {$chunkNo}: group {$g['key']} skipped — all its receipts are already linked.");
                    continue;
                }
                if ($alreadyLinked > 0) {
                    logLine("  chunk {$chunkNo}: group {$g['key']} — {$alreadyLinked} receipt(s) already linked, migrating the remaining " . count($pendingReceipts) . '.');
                }

                $docNumber = ($g['document_number'] === null || trim((string) $g['document_number']) === '')
                    ? null
                    : (string) $g['document_number'];

                $insDelivery->execute([
                    $g['vendor_id'],
                    $documentType,
                    $docNumber,
                    substr((string) $g['earliest_received_at'], 0, 10), // DATE(received_at)
                    $docNumber,
                    $g['currency'],
                    $g['default_sub_magazine_id'],
                    $g['earliest_received_at'],
                    $g['earliest_received_by'],
                    $g['earliest_received_by'],
                    $g['earliest_received_at'],
                    $g['earliest_received_at'],
                    $g['earliest_comment'],
                ]);
                $deliveryId = (int) $db->lastInsertId();
                $doneDeliveries++;

                $insertedLineNo = 0;
                foreach ($g['lines'] as $line) {
                    // Skip a line whose receipt item is already linked.
                    if (!isset($pendingItemSet[$line['receipt_item_id']])) {
                        continue;
                    }
                    $insertedLineNo++;
                    $lineCurrency = $line['currency'];
                    if ($lineCurrency !== null && strlen((string) $lineCurrency) > $lineCurrencyWidth) {
                        $lineCurrency = substr((string) $lineCurrency, 0, $lineCurrencyWidth);
                    }
                    $insLine->execute([
                        $deliveryId,
                        $insertedLineNo,
                        $line['vendor_part_id'],
                        $line['quantity'],
                        $line['unit_price'],
                        $lineCurrency,
                        $line['sub_magazine_id'],
                        $line['comment'],
                    ]);
                    $lineId = (int) $db->lastInsertId();
                    $doneLines++;

                    $insAllocation->execute([
                        $lineId,
                        $line['po_item_id'],
                        $line['quantity'],
                    ]);
                    $allocationId = (int) $db->lastInsertId();
                    $doneAllocations++;

                    $updItem->execute([$allocationId, $line['receipt_item_id']]);
                    if ($updItem->rowCount() > 0) {
                        $doneItems++;
                    }
                }

                foreach ($pendingReceipts as $r) {
                    $updReceipt->execute([$deliveryId, (int) $r['receipt_id']]);
                    if ($updReceipt->rowCount() > 0) {
                        $doneReceipts++;
                    }
                }
            }
            $db->commit();
            logLine("  chunk {$chunkNo}/" . count($chunks) . " committed — deliveries {$doneDeliveries}, lines {$doneLines}, receipts {$doneReceipts}, items {$doneItems}");
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $err = 'ROLLBACK of chunk ' . $chunkNo . ': ' . $e->getMessage();
            logLine('--- ' . $err . ' ---');
            fwrite(STDERR, "✗ {$err}\n");
            exit(1);
        }
    }
    logLine('--- MIGRATION DONE ---');
    logLine("  deliveries created : {$doneDeliveries}");
    logLine("  lines created      : {$doneLines}");
    logLine("  allocations created: {$doneAllocations}");
    logLine("  receipts linked    : {$doneReceipts}");
    logLine("  items linked       : {$doneItems}");
} else {
    logLine('');
    logLine('DRY-RUN: nothing was written to the database. Re-run with --execute to apply the migration.');
}

// ── Verification block ────────────────────────────────────────────────
logLine('');
logLine('=== Verification SELECTs ===');
$verifyOk = true;
foreach ($verifySql as $label => $sql) {
    $value = (int) dbFetchColumn($db, $sql);
    $isNullCheck = str_contains($label, 'must be 0');
    // In dry-run the two "must be 0" checks legitimately report the CURRENT
    // (pre-migration) state — only an executed run can fail them.
    if ($execute && $isNullCheck && $value !== 0) {
        $verifyOk = false;
    }
    logLine("  {$label}: {$value}" . ($execute ? '' : '   (dry-run — the value above is the CURRENT state)'));
    logLine("      SQL: " . trim(preg_replace('/\s+/', ' ', $sql)));
}

// ── Projected post-state (what the numbers become after --execute) ───
if (!$execute) {
    logLine('');
    logLine('=== Projected state after --execute ===');
    $projNullReceipts = max(0, $totalLegacyReceipts - $receiptsToMigrate - $mixedVendorReceipts);
    $projNullItems    = max(0, $totalLegacyItems - $itemsToBackLink);
    logLine("  receipts with delivery_id IS NULL        : {$totalLegacyReceipts} → {$projNullReceipts}");
    logLine("  items with delivery_allocation_id IS NULL: {$totalLegacyItems} → {$projNullItems}");
    logLine("  orphan allocations / lines               : 0 → 0");
    logLine('  (both must be 0 for the migration to be complete — the 3 receipts that');
    logLine('   already had a delivery_id are not counted in the legacy totals above)');
}

if ($execute) {
    logLine('');
    $nullReceipts = (int) dbFetchColumn($db, 'SELECT COUNT(*) FROM purchase__order_receipt WHERE delivery_id IS NULL');
    $nullItems    = (int) dbFetchColumn($db, 'SELECT COUNT(*) FROM purchase__order_receipt_item WHERE delivery_allocation_id IS NULL');
    logLine('=== Post-migration check ===');
    logLine("  receipts with delivery_id IS NULL        : {$nullReceipts}" . ($nullReceipts === 0 ? '  ✔ OK' : '  ✗ NOT OK'));
    logLine("  items with delivery_allocation_id IS NULL : {$nullItems}" . ($nullItems === 0 ? '  ✔ OK' : '  ✗ NOT OK'));
    if ($nullReceipts !== 0 || $nullItems !== 0) {
        $verifyOk = false;
    }
}

logLine('');
logLine('=== migrate-legacy-receipts-to-deliveries.php DONE ===');
if (!$verifyOk) {
    logLine('WARNING: at least one verification check did not reach the expected value.');
}

$locker->unlock();
logLine('Lock released: migrate_legacy_receipts_to_deliveries.lock');
