<?php
/**
 * One-shot CLI: schema for the invoice-driven goods reception
 * (docs/procurement/RECEIPT_INVOICE_FLOW_PLAN.md, Phase 1).
 *
 * Adds:
 *   - purchase__delivery             (one row per vendor document: invoice / WZ)
 *   - purchase__delivery_line        (one row per invoice line, as typed)
 *   - purchase__delivery_allocation  (line -> PO item split, 0..n per line)
 *   - purchase__order_receipt.delivery_id              INT NULL  FK -> purchase__delivery(id)              ON DELETE SET NULL
 *   - purchase__order_receipt_item.delivery_allocation_id INT NULL FK -> purchase__delivery_allocation(id) ON DELETE SET NULL
 *   - ref__transfer_group_types seed: slug 'purchase_delivery'
 *     (template placeholders {grn} and {vendor} are filled from the
 *      transfer-group params by TransferGroupManager::formatNote(), so the
 *      code that creates the group must pass ['grn' => ..., 'vendor' => ...]).
 *
 * Legacy cleanup (plan §4.5), run in ONE transaction, counts printed:
 *   (a) delete "draft" receipts = purchase__order_receipt rows with zero
 *       purchase__order_receipt_item children (leftovers of the retired
 *       acceptReceiptMetadata() step);
 *   (b) purchase orders in state 'in_progress' (the retired value is KEPT in
 *       the ENUM):
 *         - at least one receipt item  -> 'received' when
 *           SUM(item.quantity_received) >= SUM(item.quantity), otherwise
 *           'partially_received';
 *         - no receipt item            -> 'confirmed'.
 *
 * Idempotent: tables, columns, indexes, FKs and the seed row are each probed
 * via information_schema / SELECT before being created. A second run changes
 * nothing (cleanup finds no rows to touch).
 *
 * Usage:
 *   php src/cron/migrate-purchase-delivery-schema.php [--dry-run]
 *
 *   --dry-run   print what would change, write nothing.
 *
 * Does NOT require config-google-sheets.php (it starts a session; unsafe in CLI).
 */

use Atte\DB\MsaDB;

require_once __DIR__ . '/../../config/config.php';

$dryRun = in_array('--dry-run', $argv ?? [], true);

// ── Pre-flight: refuse to run against the wrong database ──────────────
$MsaDB  = MsaDB::getInstance();
$db     = $MsaDB->db;
$dbName = $db->query('SELECT DATABASE()')->fetchColumn();
if ($dbName !== 'atte_ms') {
    fwrite(STDERR, "✗ Connected to '{$dbName}', not 'atte_ms'. Aborting — refusing to migrate the wrong database.\n");
    exit(1);
}
echo "Connected to: {$dbName}" . ($dryRun ? "  [DRY RUN — nothing will be written]" : '') . "\n\n";

$created = $skipped = $failed = 0;

// ── Probe helpers (information_schema) ────────────────────────────────
function tableExists(PDO $db, string $table): bool
{
    $st = $db->prepare(
        "SELECT 1 FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?"
    );
    $st->execute([$table]);
    return (bool) $st->fetchColumn();
}

function columnExists(PDO $db, string $table, string $column): bool
{
    $st = $db->prepare(
        "SELECT 1 FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?"
    );
    $st->execute([$table, $column]);
    return (bool) $st->fetchColumn();
}

function indexExists(PDO $db, string $table, string $index): bool
{
    $st = $db->prepare(
        "SELECT 1 FROM information_schema.STATISTICS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?
          LIMIT 1"
    );
    $st->execute([$table, $index]);
    return (bool) $st->fetchColumn();
}

function fkExists(PDO $db, string $table, string $constraint): bool
{
    $st = $db->prepare(
        "SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
            AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = 'FOREIGN KEY'"
    );
    $st->execute([$table, $constraint]);
    return (bool) $st->fetchColumn();
}

/**
 * Run (or, in dry-run, only announce) one DDL/DML step.
 * $label is printed after the status mark.
 */
function runStep(PDO $db, bool $dryRun, string $label, string $sql, int &$created, int &$failed): void
{
    if ($dryRun) {
        echo "· would apply: {$label}\n";
        $created++;
        return;
    }
    try {
        $db->exec($sql);
        echo "✓ {$label}: applied\n";
        $created++;
    } catch (\PDOException $e) {
        fwrite(STDERR, "✗ {$label}: " . $e->getMessage() . "\n");
        $failed++;
    }
}

// ── 1. New tables (strict FK order, FKs inline) ───────────────────────
$tables = [
    'purchase__delivery' => <<<'SQL'
CREATE TABLE `purchase__delivery` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `vendor_id` int(11) NOT NULL,
  `state` enum('draft','posted','cancelled') NOT NULL DEFAULT 'draft',
  `document_type` enum('invoice','wz') NOT NULL DEFAULT 'invoice',
  `vendor_document_number` varchar(100) DEFAULT NULL,
  `document_date` date DEFAULT NULL,
  `grn_number` varchar(64) DEFAULT NULL,
  `currency` varchar(8) NOT NULL DEFAULT 'PLN',
  `default_sub_magazine_id` int(11) DEFAULT NULL,
  `received_at` datetime DEFAULT NULL,
  `transfer_group_id` int(11) DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `posted_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `posted_at` datetime DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `comment` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_delivery_vendor_state` (`vendor_id`,`state`),
  KEY `fk_delivery_mag` (`default_sub_magazine_id`),
  KEY `fk_delivery_tg` (`transfer_group_id`),
  KEY `fk_delivery_created_by` (`created_by`),
  KEY `fk_delivery_posted_by` (`posted_by`),
  CONSTRAINT `fk_delivery_vendor`     FOREIGN KEY (`vendor_id`)               REFERENCES `list__vendor`              (`id`),
  CONSTRAINT `fk_delivery_mag`        FOREIGN KEY (`default_sub_magazine_id`) REFERENCES `magazine__list`            (`sub_magazine_id`),
  CONSTRAINT `fk_delivery_tg`         FOREIGN KEY (`transfer_group_id`)       REFERENCES `inventory__transfer_groups`(`id`),
  CONSTRAINT `fk_delivery_created_by` FOREIGN KEY (`created_by`)              REFERENCES `user`                      (`user_id`),
  CONSTRAINT `fk_delivery_posted_by`  FOREIGN KEY (`posted_by`)               REFERENCES `user`                      (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
SQL,

    'purchase__delivery_line' => <<<'SQL'
CREATE TABLE `purchase__delivery_line` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `delivery_id` int(11) NOT NULL,
  `line_no` int(11) NOT NULL,
  `vendor_part_id` int(11) NOT NULL,
  `quantity` decimal(30,10) NOT NULL,
  `unit_price` decimal(30,10) DEFAULT NULL,
  `sub_magazine_id` int(11) DEFAULT NULL,
  `inventory_parts_id` int(11) DEFAULT NULL,
  `comment` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_dline_delivery` (`delivery_id`,`line_no`),
  KEY `fk_dline_vp` (`vendor_part_id`),
  KEY `fk_dline_mag` (`sub_magazine_id`),
  CONSTRAINT `fk_dline_delivery` FOREIGN KEY (`delivery_id`)     REFERENCES `purchase__delivery`  (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_dline_vp`       FOREIGN KEY (`vendor_part_id`)  REFERENCES `list__vendor_part`   (`id`),
  CONSTRAINT `fk_dline_mag`      FOREIGN KEY (`sub_magazine_id`) REFERENCES `magazine__list`      (`sub_magazine_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
SQL,

    'purchase__delivery_allocation' => <<<'SQL'
CREATE TABLE `purchase__delivery_allocation` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `delivery_line_id` int(11) NOT NULL,
  `po_item_id` int(11) NOT NULL,
  `quantity` decimal(30,10) NOT NULL,
  `is_manual` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_dalloc_line` (`delivery_line_id`),
  KEY `idx_dalloc_po_item` (`po_item_id`),
  CONSTRAINT `fk_dalloc_line` FOREIGN KEY (`delivery_line_id`) REFERENCES `purchase__delivery_line`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_dalloc_poi`  FOREIGN KEY (`po_item_id`)       REFERENCES `purchase__order_item`   (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
SQL,
];

echo "── New tables ──\n";
foreach ($tables as $name => $ddl) {
    if (tableExists($db, $name)) {
        echo "= {$name}: already exists (skipped)\n";
        $skipped++;
        continue;
    }
    runStep($db, $dryRun, "CREATE TABLE {$name}", $ddl, $created, $failed);
}

// ── 2. Link columns on the existing receipt tables ────────────────────
// [table, column, ADD COLUMN ddl, index name, FK name, FK ddl]
$links = [
    [
        'table'   => 'purchase__order_receipt',
        'column'  => 'delivery_id',
        'addCol'  => 'ADD COLUMN `delivery_id` INT(11) DEFAULT NULL AFTER `po_id`',
        'index'   => 'idx_receipt_delivery',
        'addIdx'  => 'ADD KEY `idx_receipt_delivery` (`delivery_id`)',
        'fk'      => 'fk_receipt_delivery',
        'addFk'   => 'ADD CONSTRAINT `fk_receipt_delivery` FOREIGN KEY (`delivery_id`) REFERENCES `purchase__delivery`(`id`) ON DELETE SET NULL',
        'refTbl'  => 'purchase__delivery',
    ],
    [
        'table'   => 'purchase__order_receipt_item',
        'column'  => 'delivery_allocation_id',
        'addCol'  => 'ADD COLUMN `delivery_allocation_id` INT(11) DEFAULT NULL AFTER `comment`',
        'index'   => 'idx_receipt_item_alloc',
        'addIdx'  => 'ADD KEY `idx_receipt_item_alloc` (`delivery_allocation_id`)',
        'fk'      => 'fk_receipt_item_alloc',
        'addFk'   => 'ADD CONSTRAINT `fk_receipt_item_alloc` FOREIGN KEY (`delivery_allocation_id`) REFERENCES `purchase__delivery_allocation`(`id`) ON DELETE SET NULL',
        'refTbl'  => 'purchase__delivery_allocation',
    ],
];

echo "\n── Link columns on existing receipt tables ──\n";
foreach ($links as $l) {
    $t = $l['table'];
    if (columnExists($db, $t, $l['column'])) {
        echo "= {$t}.{$l['column']}: already exists (skipped)\n";
        $skipped++;
    } else {
        runStep($db, $dryRun, "{$t}.{$l['column']} ADD COLUMN", "ALTER TABLE `{$t}` {$l['addCol']}", $created, $failed);
    }

    if (indexExists($db, $t, $l['index'])) {
        echo "= {$t}.{$l['index']}: already exists (skipped)\n";
        $skipped++;
    } else {
        runStep($db, $dryRun, "{$t} ADD KEY {$l['index']}", "ALTER TABLE `{$t}` {$l['addIdx']}", $created, $failed);
    }

    if (fkExists($db, $t, $l['fk'])) {
        echo "= {$t}.{$l['fk']}: already exists (skipped)\n";
        $skipped++;
    } elseif (!$dryRun && !tableExists($db, $l['refTbl'])) {
        fwrite(STDERR, "✗ {$t}.{$l['fk']}: referenced table {$l['refTbl']} missing\n");
        $failed++;
    } else {
        runStep($db, $dryRun, "{$t} ADD CONSTRAINT {$l['fk']}", "ALTER TABLE `{$t}` {$l['addFk']}", $created, $failed);
    }
}

// ── 3. Seed: transfer-group type 'purchase_delivery' ──────────────────
echo "\n── ref__transfer_group_types seed ──\n";
$tgTemplate = 'Przyjęcie dostawy {grn} — {vendor}';
try {
    $st = $db->prepare("SELECT COUNT(*) FROM `ref__transfer_group_types` WHERE `slug` = ?");
    $st->execute(['purchase_delivery']);
    if ((int) $st->fetchColumn() > 0) {
        echo "= ref__transfer_group_types 'purchase_delivery': already present (skipped)\n";
        $skipped++;
    } elseif ($dryRun) {
        echo "· would insert ref__transfer_group_types ('purchase_delivery', '{$tgTemplate}')\n";
        $created++;
    } else {
        $db->prepare(
            "INSERT INTO `ref__transfer_group_types` (`slug`, `template`) VALUES ('purchase_delivery', :template)"
        )->execute([':template' => $tgTemplate]);
        echo "✓ ref__transfer_group_types 'purchase_delivery': inserted\n";
        $created++;
    }
} catch (\PDOException $e) {
    fwrite(STDERR, "✗ ref__transfer_group_types seed: " . $e->getMessage() . "\n");
    $failed++;
}

// ── 4. Legacy cleanup (plan §4.5) — single transaction ────────────────
echo "\n── Legacy cleanup (draft receipts, in_progress POs) ──\n";
$deletedReceipts = 0;
$poChanges       = []; // po_id => ['from' => 'in_progress', 'to' => state]
try {
    if (!$dryRun) {
        $db->beginTransaction();
    }

    // (a) draft receipts = receipts with no items
    $draftIds = $db->query(
        "SELECT r.id
           FROM `purchase__order_receipt` r
           LEFT JOIN `purchase__order_receipt_item` ri ON ri.receipt_id = r.id
          WHERE ri.id IS NULL"
    )->fetchAll(\PDO::FETCH_COLUMN);
    $draftCount = count($draftIds);
    if ($draftCount === 0) {
        echo "= draft receipts (no items): none found\n";
    } elseif ($dryRun) {
        echo "· would DELETE {$draftCount} draft receipt(s): ids " . implode(',', $draftIds) . "\n";
    } else {
        $in = implode(',', array_map('intval', $draftIds));
        $deletedReceipts = (int) $db->exec("DELETE FROM `purchase__order_receipt` WHERE id IN ({$in})");
        echo "✓ deleted {$deletedReceipts} draft receipt(s): ids {$in}\n";
    }

    // (b) POs stuck in 'in_progress'
    $pos = $db->query(
        "SELECT po.id,
                (SELECT COUNT(*)
                   FROM `purchase__order_receipt_item` ri
                   JOIN `purchase__order_receipt` r ON r.id = ri.receipt_id
                  WHERE r.po_id = po.id)                                   AS receipt_items,
                (SELECT COALESCE(SUM(poi.quantity), 0)
                   FROM `purchase__order_item` poi WHERE poi.po_id = po.id) AS qty_ordered,
                (SELECT COALESCE(SUM(poi.quantity_received), 0)
                   FROM `purchase__order_item` poi WHERE poi.po_id = po.id) AS qty_received
           FROM `purchase__order` po
          WHERE po.state = 'in_progress'"
    )->fetchAll(\PDO::FETCH_ASSOC);

    $counts = ['confirmed' => 0, 'partially_received' => 0, 'received' => 0];
    foreach ($pos as $p) {
        if ((int) $p['receipt_items'] === 0) {
            $to = 'confirmed';
        } elseif ((float) $p['qty_received'] >= (float) $p['qty_ordered'] && (float) $p['qty_ordered'] > 0) {
            $to = 'received';
        } else {
            $to = 'partially_received';
        }
        $poChanges[(int) $p['id']] = $to;
        $counts[$to]++;
    }

    if (!$pos) {
        echo "= POs in 'in_progress': none found\n";
    } else {
        $summary = "confirmed={$counts['confirmed']}, partially_received={$counts['partially_received']}, received={$counts['received']}";
        if ($dryRun) {
            echo "· would UPDATE " . count($pos) . " PO(s) from 'in_progress' ({$summary}):\n";
            foreach ($poChanges as $id => $to) {
                echo "    PO id {$id} → {$to}\n";
            }
        } else {
            $upd = $db->prepare("UPDATE `purchase__order` SET `state` = ? WHERE `id` = ? AND `state` = 'in_progress'");
            $done = 0;
            foreach ($poChanges as $id => $to) {
                $upd->execute([$to, $id]);
                $done += $upd->rowCount();
                echo "    PO id {$id} → {$to}\n";
            }
            echo "✓ updated {$done} PO(s) from 'in_progress' ({$summary})\n";
        }
    }

    if (!$dryRun) {
        $db->commit();
    }
    $created += $deletedReceipts + (int) count($poChanges);
    if ($dryRun) {
        $created += $draftCount;
    }
    if ($draftCount === 0 && !$pos) {
        $skipped++;
    }
} catch (\Throwable $e) {
    if (!$dryRun && $db->inTransaction()) {
        $db->rollBack();
    }
    fwrite(STDERR, "✗ legacy cleanup failed (rolled back): " . $e->getMessage() . "\n");
    $failed++;
}

// ── 5. Post-flight verification ───────────────────────────────────────
echo "\n--- Verification" . ($dryRun ? " (dry run — expect missing objects before the first real run)" : '') . " ---\n";
$ok = true;

foreach (array_keys($tables) as $t) {
    $has = tableExists($db, $t);
    echo "Table {$t}: " . ($has ? 'present ✓' : 'MISSING ✗') . "\n";
    $ok = $ok && $has;
}

$expectedFks = [
    'purchase__delivery'            => ['fk_delivery_vendor', 'fk_delivery_mag', 'fk_delivery_tg', 'fk_delivery_created_by', 'fk_delivery_posted_by'],
    'purchase__delivery_line'       => ['fk_dline_delivery', 'fk_dline_vp', 'fk_dline_mag'],
    'purchase__delivery_allocation' => ['fk_dalloc_line', 'fk_dalloc_poi'],
    'purchase__order_receipt'       => ['fk_receipt_delivery'],
    'purchase__order_receipt_item'  => ['fk_receipt_item_alloc'],
];
foreach ($expectedFks as $t => $fks) {
    $missingFks = array_filter($fks, fn($fk) => !fkExists($db, $t, $fk));
    echo "FKs on {$t}: " . ($missingFks ? 'MISSING ' . implode(', ', $missingFks) . ' ✗' : count($fks) . ' present ✓') . "\n";
    $ok = $ok && !$missingFks;
}

foreach ([['purchase__order_receipt', 'delivery_id'], ['purchase__order_receipt_item', 'delivery_allocation_id']] as [$t, $c]) {
    $has = columnExists($db, $t, $c);
    echo "Column {$t}.{$c}: " . ($has ? 'present ✓' : 'MISSING ✗') . "\n";
    $ok = $ok && $has;
}

$slugCount = (int) $db->query("SELECT COUNT(*) FROM `ref__transfer_group_types` WHERE `slug` = 'purchase_delivery'")->fetchColumn();
echo "Seed slug 'purchase_delivery': " . ($slugCount === 1 ? 'present ✓' : "MISSING (got {$slugCount}) ✗") . "\n";
$ok = $ok && $slugCount === 1;

$leftDrafts = (int) $db->query(
    "SELECT COUNT(*) FROM `purchase__order_receipt` r
      WHERE NOT EXISTS (SELECT 1 FROM `purchase__order_receipt_item` ri WHERE ri.receipt_id = r.id)"
)->fetchColumn();
$leftInProgress = (int) $db->query("SELECT COUNT(*) FROM `purchase__order` WHERE `state` = 'in_progress'")->fetchColumn();
echo "Draft receipts remaining: {$leftDrafts} " . ($leftDrafts === 0 ? '✓' : '✗') . "\n";
echo "POs remaining in 'in_progress': {$leftInProgress} " . ($leftInProgress === 0 ? '✓' : '✗') . "\n";
$ok = $ok && $leftDrafts === 0 && $leftInProgress === 0;

echo "\nDone" . ($dryRun ? ' (DRY RUN)' : '') . ". " . ($dryRun ? 'Would change' : 'Changed') . ": {$created}, Skipped: {$skipped}, Failed: {$failed}.\n";

if ($dryRun) {
    exit($failed === 0 ? 0 : 1);
}
exit(($failed === 0 && $ok) ? 0 : 1);
