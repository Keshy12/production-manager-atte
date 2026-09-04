<?php
/**
 * One-shot CLI: apply the procurement-module schema (the delta between
 * atte_ms_struct_old.sql and atte_ms_struct_new.sql) to the live
 * atte_ms database.
 *
 * - Schema-only: does NOT migrate data. The delta is purely additive —
 *   every existing table is byte-for-byte identical between old and new.
 * - Idempotent: each table is checked via information_schema.TABLES
 *   before being created; running it twice is a no-op.
 * - FK-safe: tables are created in strict dependency order; foreign keys
 *   are inline in each CREATE TABLE so the referent is guaranteed to
 *   exist by the time the child table is built.
 *
 * Usage:
 *   php src/cron/migrate-to-procurement-schema.php
 *
 * Adds 12 tables (the procurement module):
 *   list__producer, list__vendor, list__vendor_part,
 *   list__vendor_part_pack, list__vendor_supplier,
 *   purchase__number_counter, purchase__rfq, purchase__rfq_item,
 *   purchase__order, purchase__order_item,
 *   purchase__order_receipt, purchase__order_receipt_item
 *
 * Also adds 2 columns to purchase__rfq and purchase__order:
 *   pdf_generated_at (datetime NULL) — when the PDF was last generated
 *   pdf_path         (varchar(255) NULL) — relative path to the PDF file
 * The CREATE TABLE blocks include them for fresh DBs; a separate
 * idempotent ALTER step (checking information_schema.COLUMNS) backfills
 * them on databases that already have the tables from a prior run.
 *
 * Also seeds one row in ref__transfer_group_types (slug='purchase_receipt')
 * required by the goods-receipt flow. Does NOT seed purchase__number_counter
 * — allocateDocumentNumber() handles the missing-row case with
 * INSERT...ON DUPLICATE KEY UPDATE.
 *
 * If a CREATE fails partway through, the script stops reporting failures
 * but the tables created earlier remain. Re-running the script resumes
 * from the first missing table — DDL cannot be rolled back, so each
 * CREATE TABLE is its own implicit transaction. To roll back manually,
 * drop the new tables in reverse FK order (see docs section "Rollback").
 *
 * Safe to re-run.
 */

use Atte\DB\MsaDB;

require_once __DIR__ . '/../../config/config.php';

// ── Pre-flight: refuse to run against the wrong database ──────────────
$MsaDB  = MsaDB::getInstance();
$db     = $MsaDB->db;
$dbName = $db->query('SELECT DATABASE()')->fetchColumn();
if ($dbName !== 'atte_ms') {
    fwrite(STDERR, "✗ Connected to '{$dbName}', not 'atte_ms'. Aborting — refusing to migrate the wrong database.\n");
    exit(1);
}
echo "Connected to: {$dbName}\n\n";

// ── The 12 tables, in strict FK order ─────────────────────────────────
// DDL is copied verbatim from atte_ms_struct_new.sql. AUTO_INCREMENT,
// indexes, and foreign keys are inlined — no separate ALTER TABLE blocks.
$tables = [
    'list__producer' => <<<'SQL'
CREATE TABLE `list__producer` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `isActive` tinyint(1) NOT NULL DEFAULT 1,
  `comment` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_active` (`isActive`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
SQL,

    'list__vendor' => <<<'SQL'
CREATE TABLE `list__vendor` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `address` text DEFAULT NULL,
  `additional_data` text DEFAULT NULL,
  `lead_time_days` int(11) DEFAULT NULL,
  `isActive` tinyint(1) NOT NULL DEFAULT 1,
  `comment` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_active` (`isActive`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
SQL,

    'list__vendor_part' => <<<'SQL'
CREATE TABLE `list__vendor_part` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `vendor_id` int(11) NOT NULL,
  `producer_id` int(11) NOT NULL,
  `parts_id` int(11) NOT NULL,
  `vendor_part_no` varchar(255) NOT NULL,
  `producer_part_no` varchar(255) DEFAULT NULL,
  `vendor_jm_id` int(11) NOT NULL,
  `isActive` tinyint(1) NOT NULL DEFAULT 1,
  `comment` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_vendor_part_no` (`vendor_id`,`vendor_part_no`),
  KEY `idx_vp_vendor` (`vendor_id`),
  KEY `idx_vp_producer` (`producer_id`),
  KEY `idx_vp_parts` (`parts_id`),
  KEY `fk_vp_unit` (`vendor_jm_id`),
  CONSTRAINT `fk_vp_parts`    FOREIGN KEY (`parts_id`)     REFERENCES `list__parts`   (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_vp_producer` FOREIGN KEY (`producer_id`)  REFERENCES `list__producer`(`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_vp_unit`     FOREIGN KEY (`vendor_jm_id`) REFERENCES `part__unit`    (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_vp_vendor`   FOREIGN KEY (`vendor_id`)    REFERENCES `list__vendor`  (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
SQL,

    'list__vendor_part_pack' => <<<'SQL'
CREATE TABLE `list__vendor_part_pack` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `vendor_part_id` int(11) NOT NULL,
  `full_pack_quantity` decimal(30,10) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_vp_pack` (`vendor_part_id`,`full_pack_quantity`),
  KEY `idx_vpp_vp` (`vendor_part_id`),
  CONSTRAINT `fk_vpp_vendor_part` FOREIGN KEY (`vendor_part_id`) REFERENCES `list__vendor_part`(`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
SQL,

    'list__vendor_supplier' => <<<'SQL'
CREATE TABLE `list__vendor_supplier` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `vendor_id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `job_title` varchar(255) DEFAULT NULL,
  `phone` varchar(64) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `isActive` tinyint(1) NOT NULL DEFAULT 1,
  `comment` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `vendor_id` (`vendor_id`),
  CONSTRAINT `fk_vs_vendor` FOREIGN KEY (`vendor_id`) REFERENCES `list__vendor`(`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
SQL,

    'purchase__number_counter' => <<<'SQL'
CREATE TABLE `purchase__number_counter` (
  `year` smallint(6) NOT NULL,
  `type` enum('rfq','po') NOT NULL,
  `last_value` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`year`,`type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
SQL,

    'purchase__rfq' => <<<'SQL'
CREATE TABLE `purchase__rfq` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `vendor_id` int(11) NOT NULL,
  `state` enum('draft','sent','responded','cancelled','converted') NOT NULL DEFAULT 'draft',
  `rfq_number` varchar(64) DEFAULT NULL,
  `expected_reply_date` date DEFAULT NULL,
  `sent_at` datetime DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `comment` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `pdf_generated_at` datetime DEFAULT NULL,
  `pdf_path` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_rfq_vendor` (`vendor_id`),
  KEY `idx_rfq_state` (`state`),
  KEY `fk_rfq_user` (`created_by`),
  CONSTRAINT `fk_rfq_user`   FOREIGN KEY (`created_by`) REFERENCES `user`         (`user_id`),
  CONSTRAINT `fk_rfq_vendor` FOREIGN KEY (`vendor_id`)  REFERENCES `list__vendor` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
SQL,

    'purchase__rfq_item' => <<<'SQL'
CREATE TABLE `purchase__rfq_item` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `rfq_id` int(11) NOT NULL,
  `vendor_part_id` int(11) NOT NULL,
  `quantity` decimal(30,10) NOT NULL,
  `quantity_unit_id` int(11) NOT NULL,
  `unit_price` decimal(30,10) DEFAULT NULL,
  `currency` varchar(8) NOT NULL DEFAULT 'PLN',
  `comment` text DEFAULT NULL,
  `picked_pack_size` decimal(30,10) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_rfq_item_rfq` (`rfq_id`),
  KEY `fk_rfq_item_vp` (`vendor_part_id`),
  KEY `fk_rfq_item_unit` (`quantity_unit_id`),
  CONSTRAINT `fk_rfq_item_rfq`  FOREIGN KEY (`rfq_id`)           REFERENCES `purchase__rfq`      (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_rfq_item_unit` FOREIGN KEY (`quantity_unit_id`) REFERENCES `part__unit`         (`id`),
  CONSTRAINT `fk_rfq_item_vp`   FOREIGN KEY (`vendor_part_id`)   REFERENCES `list__vendor_part` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
SQL,

    'purchase__order' => <<<'SQL'
CREATE TABLE `purchase__order` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `vendor_id` int(11) NOT NULL,
  `state` enum('draft','sent','confirmed','partially_received','received','cancelled') NOT NULL DEFAULT 'draft',
  `po_number` varchar(64) DEFAULT NULL,
  `vendor_po_number` varchar(64) DEFAULT NULL,
  `converted_from_rfq_id` int(11) DEFAULT NULL,
  `expected_delivery_date` date DEFAULT NULL,
  `sent_at` datetime DEFAULT NULL,
  `confirmed_at` datetime DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `comment` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `pdf_generated_at` datetime DEFAULT NULL,
  `pdf_path` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_po_vendor` (`vendor_id`),
  KEY `idx_po_state` (`state`),
  KEY `fk_po_user` (`created_by`),
  KEY `fk_po_rfq` (`converted_from_rfq_id`),
  CONSTRAINT `fk_po_rfq`    FOREIGN KEY (`converted_from_rfq_id`) REFERENCES `purchase__rfq` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_po_user`   FOREIGN KEY (`created_by`)            REFERENCES `user`          (`user_id`),
  CONSTRAINT `fk_po_vendor` FOREIGN KEY (`vendor_id`)             REFERENCES `list__vendor`  (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
SQL,

    'purchase__order_item' => <<<'SQL'
CREATE TABLE `purchase__order_item` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `po_id` int(11) NOT NULL,
  `vendor_part_id` int(11) NOT NULL,
  `quantity` decimal(30,10) NOT NULL,
  `quantity_unit_id` int(11) NOT NULL,
  `unit_price` decimal(30,10) NOT NULL DEFAULT 0.0000000000,
  `currency` varchar(8) NOT NULL DEFAULT 'PLN',
  `quantity_received` decimal(30,10) NOT NULL DEFAULT 0.0000000000,
  `comment` text DEFAULT NULL,
  `picked_pack_size` decimal(30,10) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_poi_po` (`po_id`),
  KEY `fk_poi_vp` (`vendor_part_id`),
  KEY `fk_poi_unit` (`quantity_unit_id`),
  CONSTRAINT `fk_poi_po`   FOREIGN KEY (`po_id`)            REFERENCES `purchase__order`    (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_poi_unit` FOREIGN KEY (`quantity_unit_id`) REFERENCES `part__unit`         (`id`),
  CONSTRAINT `fk_poi_vp`   FOREIGN KEY (`vendor_part_id`)  REFERENCES `list__vendor_part` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
SQL,

    'purchase__order_receipt' => <<<'SQL'
CREATE TABLE `purchase__order_receipt` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `po_id` int(11) NOT NULL,
  `document_number` varchar(64) DEFAULT NULL,
  `received_by` int(11) NOT NULL,
  `received_at` datetime NOT NULL DEFAULT current_timestamp(),
  `comment` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_receipt_po` (`po_id`),
  KEY `fk_receipt_user` (`received_by`),
  CONSTRAINT `fk_receipt_po`   FOREIGN KEY (`po_id`)       REFERENCES `purchase__order`(`id`),
  CONSTRAINT `fk_receipt_user` FOREIGN KEY (`received_by`) REFERENCES `user`           (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
SQL,

    'purchase__order_receipt_item' => <<<'SQL'
CREATE TABLE `purchase__order_receipt_item` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `receipt_id` int(11) NOT NULL,
  `po_item_id` int(11) NOT NULL,
  `quantity_received` decimal(30,10) NOT NULL,
  `sub_magazine_id` int(11) NOT NULL,
  `comment` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_receipt_item_receipt` (`receipt_id`),
  KEY `idx_receipt_item_po_item` (`po_item_id`),
  KEY `fk_receipt_item_mag` (`sub_magazine_id`),
  CONSTRAINT `fk_receipt_item_mag`     FOREIGN KEY (`sub_magazine_id`) REFERENCES `magazine__list`          (`sub_magazine_id`),
  CONSTRAINT `fk_receipt_item_po_item` FOREIGN KEY (`po_item_id`)     REFERENCES `purchase__order_item`    (`id`),
  CONSTRAINT `fk_receipt_item_receipt` FOREIGN KEY (`receipt_id`)     REFERENCES `purchase__order_receipt`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
SQL,
];

// ── Run each CREATE TABLE, skipping if already present ────────────────
$created = $skipped = $failed = 0;
foreach ($tables as $name => $ddl) {
    $exists = $db->prepare(
        "SELECT 1
           FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = ?"
    );
    $exists->execute([$name]);
    if ($exists->fetchColumn()) {
        echo "= {$name}: already exists (skipped)\n";
        $skipped++;
        continue;
    }
    try {
        $db->exec($ddl);
        echo "✓ {$name}: created\n";
        $created++;
    } catch (\PDOException $e) {
        fwrite(STDERR, "✗ {$name}: " . $e->getMessage() . "\n");
        $failed++;
    }
}

// ── Backfill pdf_generated_at + pdf_path on already-migrated databases ─
// Fresh DBs already received these columns via the CREATE TABLE blocks.
// Databases that ran this script before this addition need an idempotent
// ALTER so re-runs pick up the new fields without manual migration.
$columnAdds = [
    'purchase__rfq' => [
        'pdf_generated_at' => "ADD COLUMN `pdf_generated_at` DATETIME DEFAULT NULL AFTER `updated_at`",
        'pdf_path'         => "ADD COLUMN `pdf_path` VARCHAR(255) DEFAULT NULL AFTER `pdf_generated_at`",
    ],
    'purchase__order' => [
        'pdf_generated_at' => "ADD COLUMN `pdf_generated_at` DATETIME DEFAULT NULL AFTER `updated_at`",
        'pdf_path'         => "ADD COLUMN `pdf_path` VARCHAR(255) DEFAULT NULL AFTER `pdf_generated_at`",
    ],
];

$colExistsStmt = $db->prepare(
    "SELECT 1
       FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = ?
        AND COLUMN_NAME = ?"
);
foreach ($columnAdds as $table => $cols) {
    foreach ($cols as $colName => $addDDL) {
        $colExistsStmt->execute([$table, $colName]);
        if ($colExistsStmt->fetchColumn()) {
            echo "= {$table}.{$colName}: already exists (skipped)\n";
            $skipped++;
            continue;
        }
        try {
            $db->exec("ALTER TABLE `{$table}` {$addDDL}");
            echo "✓ {$table}.{$colName}: added\n";
            $created++;
        } catch (\PDOException $e) {
            fwrite(STDERR, "✗ {$table}.{$colName}: " . $e->getMessage() . "\n");
            $failed++;
        }
    }
}

echo "\n";

// ── Required seed: transfer-group type used by the goods-receipt flow ─
// PurchaseActionHandler::createReceipt() looks up this slug to label
// the transfer group it opens. INSERT IGNORE so re-runs are safe.
try {
    $db->prepare(
        "INSERT IGNORE INTO `ref__transfer_group_types` (`slug`, `template`)
         VALUES ('purchase_receipt', :template)"
    )->execute([':template' => 'Przyjęcie z zamówienia #{po_id}']);
    echo "\n✓ ref__transfer_group_types: 'purchase_receipt' seed ensured\n";
} catch (\PDOException $e) {
    fwrite(STDERR, "\n✗ ref__transfer_group_types seed failed: " . $e->getMessage() . "\n");
    $failed++;
}

// ── Post-flight verification ──────────────────────────────────────────
echo "\n--- Verification ---\n";

$expectedTables = array_keys($tables);
$placeholders   = implode(',', array_fill(0, count($expectedTables), '?'));

$presentStmt = $db->prepare(
    "SELECT TABLE_NAME
       FROM information_schema.TABLES
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME IN ($placeholders)"
);
$presentStmt->execute($expectedTables);
$presentSet = array_column($presentStmt->fetchAll(\PDO::FETCH_ASSOC), 'TABLE_NAME');

$missing = array_diff($expectedTables, $presentSet);
echo ($missing
    ? "Tables: MISSING " . implode(', ', $missing) . " ✗\n"
    : "Tables: all 12 present ✓\n");

// FK count on the 12 new tables. Sum of FKs:
//   list__producer (0) + list__vendor (0) + list__vendor_part (4) +
//   list__vendor_part_pack (1) + list__vendor_supplier (1) +
//   purchase__number_counter (0) + purchase__rfq (2) +
//   purchase__rfq_item (3) + purchase__order (3) +
//   purchase__order_item (3) + purchase__order_receipt (2) +
//   purchase__order_receipt_item (3) = 22
$fkStmt = $db->prepare(
    "SELECT COUNT(*)
       FROM information_schema.KEY_COLUMN_USAGE
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME IN ($placeholders)
        AND REFERENCED_TABLE_NAME IS NOT NULL"
);
$fkStmt->execute($expectedTables);
$fkCount = (int) $fkStmt->fetchColumn();
echo "FKs on new tables: {$fkCount} (expected 22) " . ($fkCount === 22 ? '✓' : '✗') . "\n";

$slugCount = (int) $db->query(
    "SELECT COUNT(*)
       FROM `ref__transfer_group_types`
      WHERE `slug` = 'purchase_receipt'"
)->fetchColumn();
echo "Seed slug 'purchase_receipt': " . ($slugCount === 1 ? 'present ✓' : "MISSING (got {$slugCount}) ✗") . "\n";

// pdf_generated_at + pdf_path on both purchase tables. Fresh DBs get them
// via CREATE TABLE; already-migrated DBs get them via the ALTER block above.
$expectedPdfColumns = [
    'purchase__rfq'   => ['pdf_generated_at', 'pdf_path'],
    'purchase__order' => ['pdf_generated_at', 'pdf_path'],
];
$pdfMissing = [];
foreach ($expectedPdfColumns as $tbl => $cols) {
    foreach ($cols as $col) {
        $colExistsStmt->execute([$tbl, $col]);
        if (!$colExistsStmt->fetchColumn()) {
            $pdfMissing[] = "{$tbl}.{$col}";
        }
    }
}
echo "PDF columns: " . ($pdfMissing
    ? "MISSING " . implode(', ', $pdfMissing) . " ✗"
    : "all 4 present ✓") . "\n";

echo "\nDone. Created: {$created}, Skipped: {$skipped}, Failed: {$failed}.\n";

$ok = $failed === 0
    && empty($missing)
    && $fkCount === 22
    && $slugCount === 1
    && empty($pdfMissing);
exit($ok ? 0 : 2);
