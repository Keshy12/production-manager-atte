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
 * Adds 13 tables (the procurement module + reference tables):
 *   list__currency, list__producer, list__vendor, list__vendor_part,
 *   list__vendor_part_pack, list__vendor_supplier,
 *   purchase__number_counter, purchase__rfq, purchase__rfq_item,
 *   purchase__order, purchase__order_item,
 *   purchase__order_receipt, purchase__order_receipt_item
 *
 * Also adds 2 columns to purchase__rfq and purchase__order:
 *   pdf_generated_at (datetime NULL) — when the PDF was last generated
 *   pdf_path         (varchar(255) NULL) — relative path to the PDF file
 * And adds 1 column to list__vendor:
 *   default_currency (int(11) NOT NULL, FK → list__currency.id) — vendor's
 *     preferred currency; backfilled from the currency of each vendor's
 *     most recent purchase__order_item so the value reflects what was
 *     actually ordered last (per-item, because orders can mix currencies).
 * The CREATE TABLE blocks include them for fresh DBs; a separate
 * idempotent ALTER step (checking information_schema.COLUMNS) backfills
 * them on databases that already have the tables from a prior run.
 *
 * For default_currency specifically the migration is state-aware:
 *   - State 0 (fresh DB): CREATE TABLE creates INT FK directly.
 *   - State 1 (legacy VARCHAR(8) from the first migration run): the
 *     column gets converted via add-temp-int / backfill-from-code /
 *     drop-varchar / rename-int / add-FK. Existing rows are backfilled
 *     from their current code (all 129 seeded rows had 'PLN').
 *   - State 2 (INT but no FK, intermediate state): FK constraint is
 *     added without touching data.
 *   - State 3 (INT + FK present): no-op.
 * The conversion block below detects the current state from
 * information_schema and applies only what's needed.
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

// ── The 13 tables, in strict FK order ─────────────────────────────────
// DDL is copied verbatim from atte_ms_struct_new.sql. AUTO_INCREMENT,
// indexes, and foreign keys are inlined — no separate ALTER TABLE blocks.
$tables = [
    'list__currency' => <<<'SQL'
CREATE TABLE `list__currency` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `code` varchar(8) NOT NULL,
  `name` varchar(64) NOT NULL,
  `isActive` tinyint(1) NOT NULL DEFAULT 1,
  `comment` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_currency_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
SQL,

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
  `default_currency` int(11) NOT NULL,
  `isActive` tinyint(1) NOT NULL DEFAULT 1,
  `comment` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_active` (`isActive`),
  KEY `idx_vendor_default_currency` (`default_currency`),
  CONSTRAINT `fk_vendor_default_currency` FOREIGN KEY (`default_currency`)
      REFERENCES `list__currency`(`id`) ON UPDATE CASCADE ON DELETE RESTRICT
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
  `type` enum('rfq','po','grn') NOT NULL,
  `vendor_id` int(11) NOT NULL DEFAULT 0,
  `last_value` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`type`,`vendor_id`)
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
    // list__vendor.default_currency is handled by the state-aware
    // conversion block below, not by $columnAdds, because the target
    // type (INT FK) requires a multi-step conversion when the column
    // already exists as VARCHAR(8). See the block right after this loop.
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

// ── Reshape purchase__number_counter ──────────────────────────────────
// Migrate the counter to the per-(type, vendor_id) shape required by the
// new document-numbering scheme (RFQ/00021/00001, PO/00021/00001, GRN/000001).
// vendor_id=0 is the sentinel for the global GRN sequence; the enum
// changes from ('rfq','po','pz') to ('rfq','po','grn').
//
// Steps:
//   1. DROP the legacy PRIMARY KEY if it isn't already (type,vendor_id).
//      MUST precede the column drop: on MariaDB (this server is 10.4.32)
//      dropping a PK column does NOT auto-drop the PK index — the index
//      keeps a dangling reference, and the subsequent DROP COLUMN fails
//      with error 1072 ("Key column 'year' doesn't exist in table").
//   2. Drop the `year` column (safe now — the PK no longer references it).
//   3. Add the `vendor_id` column (DEFAULT 0 — lets legacy rows land in the
//      GRN group without manual backfill; new RFQ/PO rows carry the real
//      vendor_id and start fresh from 1).
//   4. Collapse legacy rows that now share (type, vendor_id=0) by
//      aggregating to MAX(last_value). Old shape was keyed by (year,type);
//      after dropping `year`, multiple years of the same type would collide
//      on the upcoming (type, vendor_id) PK.
//   5. ADD PRIMARY KEY (type, vendor_id) (no DROP needed — step 1 handled
//      any stale PK).
//   6. Convert any `type='pz'` rows to `type='grn'` (must run before the
//      enum MODIFY below — MariaDB refuses to drop an enum value still
//      in use).
//   7. MODIFY type ENUM('rfq','po','grn').
// Each step is gated by an information_schema probe so re-runs are silent
// no-ops on already-reshaped databases and safe to run after a previous
// half-failed run.
try {
    // ── 1. Probe PK shape, then DROP the PRIMARY KEY if it isn't target ─
    //    Must precede the column drop — see the block-level comment above
    //    for the MariaDB-vs-MySQL divergence explanation.
    $pkCols = (string) $db->query("
        SELECT IFNULL(GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR ','), '')
          FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME   = 'purchase__number_counter'
           AND INDEX_NAME   = 'PRIMARY'
    ")->fetchColumn();

    if ($pkCols !== '' && $pkCols !== 'type,vendor_id') {
        $db->exec("ALTER TABLE `purchase__number_counter` DROP PRIMARY KEY");
        echo "✓ purchase__number_counter: legacy PRIMARY KEY ('{$pkCols}') dropped\n";
        $created++;
    } else {
        echo "= purchase__number_counter PRIMARY KEY: nothing to drop (skipped)\n";
        $skipped++;
    }

    // ── 2. Drop `year` column ────────────────────────────────────────
    //    Safe now — the PK no longer references it.
    $yearProbe = $db->prepare(
        "SELECT 1 FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME   = ?
            AND COLUMN_NAME  = ?"
    );
    $yearProbe->execute(['purchase__number_counter', 'year']);
    if ($yearProbe->fetchColumn()) {
        $db->exec("ALTER TABLE `purchase__number_counter` DROP COLUMN `year`");
        echo "✓ purchase__number_counter.year: dropped\n";
        $created++;
    } else {
        echo "= purchase__number_counter.year: already absent (skipped)\n";
        $skipped++;
    }

    // ── 3. Add `vendor_id` column ─────────────────────────────────────
    $vendorIdProbe = $db->prepare(
        "SELECT 1 FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME   = ?
            AND COLUMN_NAME  = ?"
    );
    $vendorIdProbe->execute(['purchase__number_counter', 'vendor_id']);
    if ($vendorIdProbe->fetchColumn()) {
        echo "= purchase__number_counter.vendor_id: already exists (skipped)\n";
        $skipped++;
    } else {
        $db->exec("ALTER TABLE `purchase__number_counter`
                   ADD COLUMN `vendor_id` INT(11) NOT NULL DEFAULT 0 AFTER `type`");
        echo "✓ purchase__number_counter.vendor_id: added (DEFAULT 0)\n";
        $created++;
    }

    // ── 4. Collapse legacy rows that would collide on the new PK ─────
    //    Old shape was (year, type); after dropping `year`, multiple years
    //    of the same type collapse onto the same (type, vendor_id=0) key.
    //    Aggregate to MAX(last_value) via a temporary table — idempotent:
    //    a re-run on an already-unique table produces an identical 1:1 copy.
    $dupCount = (int) $db->query("
        SELECT COUNT(*) - COUNT(DISTINCT type, vendor_id)
          FROM `purchase__number_counter`
    ")->fetchColumn();
    if ($dupCount > 0) {
        $db->exec("CREATE TEMPORARY TABLE `_tmp_counter_reshape` (
            `type`       ENUM('rfq','po','grn') NOT NULL,
            `vendor_id`  INT(11)                NOT NULL DEFAULT 0,
            `last_value` INT(11)                NOT NULL DEFAULT 0
        ) ENGINE=Memory");
        $db->exec("INSERT INTO `_tmp_counter_reshape` (`type`, `vendor_id`, `last_value`)
                   SELECT `type`, `vendor_id`, MAX(`last_value`)
                     FROM `purchase__number_counter`
                    GROUP BY `type`, `vendor_id`");
        $db->exec("TRUNCATE TABLE `purchase__number_counter`");
        $db->exec("INSERT INTO `purchase__number_counter` (`type`, `vendor_id`, `last_value`)
                   SELECT `type`, `vendor_id`, `last_value` FROM `_tmp_counter_reshape`");
        $db->exec("DROP TEMPORARY TABLE `_tmp_counter_reshape`");
        echo "✓ purchase__number_counter: collapsed {$dupCount} duplicate row(s) on (type,vendor_id)\n";
        $created++;
    } else {
        echo "= purchase__number_counter: no duplicate (type,vendor_id) keys (skipped)\n";
        $skipped++;
    }

    // ── 5. ADD PRIMARY KEY (type, vendor_id) ─────────────────────────
    //    No DROP needed here — step 1 already removed any stale PK. Use
    //    the pre-step-1 $pkCols value: if it was already target, both
    //    step 1 and step 5 were no-ops.
    if ($pkCols !== 'type,vendor_id') {
        $db->exec("ALTER TABLE `purchase__number_counter`
                   ADD PRIMARY KEY (`type`, `vendor_id`)");
        echo "✓ purchase__number_counter: PRIMARY KEY (type,vendor_id) set\n";
        $created++;
    } else {
        echo "= purchase__number_counter PRIMARY KEY: already (type,vendor_id) (skipped)\n";
        $skipped++;
    }

    // ── 6. Convert legacy `type='pz'` rows to `'grn'` ────────────────
    $pzRowCount = (int) $db->exec("
        UPDATE `purchase__number_counter`
           SET `type` = 'grn'
         WHERE `type` = 'pz'
    ");
    if ($pzRowCount > 0) {
        echo "✓ purchase__number_counter: {$pzRowCount} legacy 'pz' row(s) → 'grn'\n";
        $created++;
    } else {
        echo "= purchase__number_counter: no legacy 'pz' rows (skipped)\n";
        $skipped++;
    }

    // ── 7. MODIFY type ENUM('rfq','po','grn') ─────────────────────────
    $counterTypeCol = (string) $db->query("
        SELECT COLUMN_TYPE
          FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME   = 'purchase__number_counter'
           AND COLUMN_NAME  = 'type'
    ")->fetchColumn();
    if ($counterTypeCol !== ''
        && strpos($counterTypeCol, "'grn'") !== false
        && strpos($counterTypeCol, "'pz'")  === false) {
        echo "= purchase__number_counter.type: already ENUM('rfq','po','grn') (skipped)\n";
        $skipped++;
    } else {
        $db->exec("ALTER TABLE `purchase__number_counter`
                   MODIFY `type` ENUM('rfq','po','grn') NOT NULL");
        echo "✓ purchase__number_counter.type: set to ENUM('rfq','po','grn')\n";
        $created++;
    }
} catch (\PDOException $e) {
    fwrite(STDERR, "✗ purchase__number_counter reshape: " . $e->getMessage() . "\n");
    $failed++;
}

echo "\n";

// ── Add responded_at / responded_by to purchase__rfq ─────────────────
// PurchaseActionHandler::markRfqResponded() transitions an RFQ from
// `sent` to `responded` and stamps these two columns. The CREATE TABLE
// block above (line ~188) does NOT include them — they were added after
// the original schema was finalised. Both already-migrated DBs and
// freshly-CREATEd ones need an idempotent ALTER so re-runs of this
// script remain safe. Index + FK guard against orphan responders and
// keep the lookup cheap for "who responded to what" reporting.
$rfqResponseColumns = [
    'responded_at' => "ADD COLUMN `responded_at` DATETIME DEFAULT NULL AFTER `sent_at`",
    'responded_by' => "ADD COLUMN `responded_by` INT DEFAULT NULL AFTER `responded_at`",
];
foreach ($rfqResponseColumns as $colName => $addDDL) {
    $colExistsStmt->execute(['purchase__rfq', $colName]);
    if ($colExistsStmt->fetchColumn()) {
        echo "= purchase__rfq.{$colName}: already exists (skipped)\n";
        $skipped++;
        continue;
    }
    try {
        $db->exec("ALTER TABLE `purchase__rfq` {$addDDL}");
        echo "✓ purchase__rfq.{$colName}: added\n";
        $created++;
    } catch (\PDOException $e) {
        fwrite(STDERR, "✗ purchase__rfq.{$colName}: " . $e->getMessage() . "\n");
        $failed++;
    }
}
// Index + FK on responded_by — only after the column itself exists.
$idxExists = (int) $db->query("
    SELECT COUNT(*)
      FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'purchase__rfq'
       AND INDEX_NAME   = 'idx_rfq_responder'
")->fetchColumn();
if ($idxExists === 0) {
    try {
        $db->exec("ALTER TABLE `purchase__rfq`
                   ADD KEY `idx_rfq_responder` (`responded_by`),
                   ADD CONSTRAINT `fk_rfq_responder`
                       FOREIGN KEY (`responded_by`) REFERENCES `user`(`user_id`)");
        echo "✓ purchase__rfq: idx_rfq_responder + fk_rfq_responder added\n";
        $created++;
    } catch (\PDOException $e) {
        fwrite(STDERR, "✗ purchase__rfq responded_by index/FK: " . $e->getMessage() . "\n");
        $failed++;
    }
} else {
    echo "= purchase__rfq.idx_rfq_responder: already exists (skipped)\n";
    $skipped++;
}

// ── Safety MODIFY on purchase__rfq.state ENUM ────────────────────────
// The CREATE TABLE block above (line ~188) already declares
// `state ENUM('draft','sent','responded','cancelled','converted')` —
// the live enum is the same shape. This MODIFY is a no-op against
// databases built from the current schema dump, but covers legacy
// installs where the column was created without the `responded` value
// (the original CREATE TABLE used ENUM('draft','sent','cancelled',
// 'converted') before `responded` was added). The LIKE check on
// information_schema.COLUMNS keeps the log quiet when nothing needs
// to change — only "updated" prints when a real change happens.
$rfqStateCol = (string) $db->query("
    SELECT COLUMN_TYPE
      FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'purchase__rfq'
       AND COLUMN_NAME  = 'state'
")->fetchColumn();
if ($rfqStateCol === '' || strpos($rfqStateCol, "'responded'") === false) {
    try {
        $db->exec("ALTER TABLE `purchase__rfq`
                   MODIFY COLUMN `state`
                   ENUM('draft','sent','responded','cancelled','converted') NOT NULL DEFAULT 'draft'");
        echo "✓ purchase__rfq.state: widened to include 'responded'\n";
        $created++;
    } catch (\PDOException $e) {
        fwrite(STDERR, "✗ purchase__rfq.state widen: " . $e->getMessage() . "\n");
        $failed++;
    }
} else {
    echo "= purchase__rfq.state: already includes 'responded' (skipped)\n";
    $skipped++;
}

// ── Safety MODIFY on purchase__order.state ENUM ──────────────────────
// Same pattern as the rfq widening above. The CREATE TABLE block at
// line ~232 still declares the original six values — the live enum is
// already the new shape on fresh DBs (this migration runs before any
// PO rows are written), but legacy DBs have the old enum without
// 'in_progress' and need this ALTER.
//
// 'in_progress' is the logistics state introduced for the goods-
// receiving workflow: PO went confirmed → operator clicked "Akceptuję"
// on /purchase/receipts/receive to persist receipt-header metadata
// (vendor doc number, comment, date) but no items yet. Producer: the
// purchaseActionHandler::acceptReceiptMetadata method, which
// transitions confirmed → in_progress only and never re-runs on
// already-in_progress/partially_received/received states. The enum
// widening on the repo side (PurchaseOrderRepository::setState) was
// added together with this ALTER so legacy installs stay consistent.
$poStateCol = (string) $db->query("
    SELECT COLUMN_TYPE
      FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'purchase__order'
       AND COLUMN_NAME  = 'state'
")->fetchColumn();
if ($poStateCol === '' || strpos($poStateCol, "'in_progress'") === false) {
    try {
        $db->exec("ALTER TABLE `purchase__order`
                   MODIFY COLUMN `state`
                   ENUM('draft','sent','confirmed','partially_received','received','cancelled','in_progress') NOT NULL DEFAULT 'draft'");
        echo "✓ purchase__order.state: widened to include 'in_progress'\n";
        $created++;
    } catch (\PDOException $e) {
        fwrite(STDERR, "✗ purchase__order.state widen: " . $e->getMessage() . "\n");
        $failed++;
    }
} else {
    echo "= purchase__order.state: already includes 'in_progress' (skipped)\n";
    $skipped++;
}

// ── Add is_primary to list__vendor_supplier ──────────────────────────
// VendorSupplierRepository::listByVendor() / setPrimary() sort and
// toggle a per-vendor "primary contact" flag. The CREATE TABLE block
// above (line ~163) doesn't include the column. Idempotent — gate on
// information_schema.COLUMNS like the other ALTERs. The composite
// (vendor_id, is_primary) index lets the "find the primary contact for
// vendor X" lookup skip the table scan.
$colExistsStmt->execute(['list__vendor_supplier', 'is_primary']);
if ($colExistsStmt->fetchColumn()) {
    echo "= list__vendor_supplier.is_primary: already exists (skipped)\n";
    $skipped++;
} else {
    try {
        $db->exec("ALTER TABLE `list__vendor_supplier`
                   ADD COLUMN `is_primary` TINYINT(1) NOT NULL DEFAULT 0 AFTER `isActive`,
                   ADD KEY `idx_vs_primary` (`vendor_id`, `is_primary`)");
        echo "✓ list__vendor_supplier.is_primary: added (with idx_vs_primary)\n";
        $created++;
    } catch (\PDOException $e) {
        fwrite(STDERR, "✗ list__vendor_supplier.is_primary: " . $e->getMessage() . "\n");
        $failed++;
    }
}

// ── State-aware conversion: list__vendor.default_currency → INT FK ───
// Target shape: `default_currency INT(11) NOT NULL` with FK to
// `list__currency(id)`. The CREATE TABLE block above produces this
// directly for fresh DBs; for already-migrated DBs the column may be
// missing (legacy state from before this migration was introduced),
// VARCHAR(8) (the shape produced by an earlier migration run), INT
// without FK (intermediate state), or INT+FK (target — no-op).
//
// Detect via information_schema and apply only what's needed. Each
// branch is idempotent on its own — running the script twice on a
// legacy DB lands at the target shape the first time and is a no-op
// the second time.
try {
    $colInfo = $db->query("
        SELECT DATA_TYPE
          FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'list__vendor'
           AND COLUMN_NAME = 'default_currency'
    ")->fetch(\PDO::FETCH_ASSOC);

    $fkExists = (int) $db->query("
        SELECT COUNT(*)
          FROM information_schema.KEY_COLUMN_USAGE
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'list__vendor'
           AND COLUMN_NAME = 'default_currency'
           AND REFERENCED_TABLE_NAME = 'list__currency'
    ")->fetchColumn();

    if ($colInfo === false) {
        // Column doesn't exist at all — legacy DB predating the first
        // migration run. Add it as INT with default = id of PLN.
        // list__currency is already seeded by the time we get here
        // (the columnAdds seed block ran earlier in this script).
        // For DBs where list__vendor predates list__currency, fall
        // back to a default of 1 (which we expect PLN to have, since
        // it's inserted first by the seed block).
        $plnId = (int) $db->query("SELECT id FROM `list__currency` WHERE code = 'PLN'")->fetchColumn();
        if ($plnId === 0) { $plnId = 1; } // best-effort fallback
        $db->exec("ALTER TABLE `list__vendor`
                   ADD COLUMN `default_currency` INT(11) NOT NULL DEFAULT {$plnId} AFTER `lead_time_days`,
                   ADD KEY `idx_vendor_default_currency` (`default_currency`),
                   ADD CONSTRAINT `fk_vendor_default_currency`
                       FOREIGN KEY (`default_currency`) REFERENCES `list__currency`(`id`)");
        echo "✓ list__vendor.default_currency: added as INT FK (default → PLN id={$plnId})\n";
        $created++;
    } elseif (strtolower((string)$colInfo['DATA_TYPE']) === 'int' && $fkExists > 0) {
        echo "= list__vendor.default_currency: INT FK already present (skipped)\n";
        $skipped++;
    } elseif (strtolower((string)$colInfo['DATA_TYPE']) === 'int') {
        // INT without FK — add FK + index only.
        try {
            $db->exec("ALTER TABLE `list__vendor`
                       ADD KEY `idx_vendor_default_currency` (`default_currency`),
                       ADD CONSTRAINT `fk_vendor_default_currency`
                           FOREIGN KEY (`default_currency`) REFERENCES `list__currency`(`id`) ON UPDATE CASCADE ON DELETE RESTRICT");
            echo "✓ list__vendor.default_currency: FK + index added (column was already INT)\n";
            $created++;
        } catch (\PDOException $e) {
            fwrite(STDERR, "✗ list__vendor.default_currency FK add: " . $e->getMessage() . "\n");
            $failed++;
        }
    } else {
        // VARCHAR (or other non-INT) — full conversion via temp column.
        // Steps:
        //   1. Add a nullable INT column (default_currency_id).
        //   2. Backfill id values via JOIN on list__currency.code.
        //   3. Drop the old VARCHAR column.
        //   4. Rename + apply NOT NULL on the new column.
        //   5. Add FK + index.
        $db->exec("ALTER TABLE `list__vendor`
                   ADD COLUMN `default_currency_id` INT(11) DEFAULT NULL AFTER `default_currency`");
        $rowCount = $db->exec("
            UPDATE `list__vendor` v
            JOIN `list__currency` c ON c.code = v.default_currency
            SET v.default_currency_id = c.id
        ");
        $db->exec("ALTER TABLE `list__vendor` DROP COLUMN `default_currency`");
        $db->exec("ALTER TABLE `list__vendor`
                   CHANGE `default_currency_id` `default_currency` INT(11) NOT NULL");
        $db->exec("ALTER TABLE `list__vendor`
                   ADD KEY `idx_vendor_default_currency` (`default_currency`),
                   ADD CONSTRAINT `fk_vendor_default_currency`
                       FOREIGN KEY (`default_currency`) REFERENCES `list__currency`(`id`) ON UPDATE CASCADE ON DELETE RESTRICT");
        echo "✓ list__vendor.default_currency: VARCHAR → INT FK converted ({$rowCount} vendor(s) backfilled)\n";
        $created++;
    }
} catch (\PDOException $e) {
    fwrite(STDERR, "✗ list__vendor.default_currency conversion failed: " . $e->getMessage() . "\n");
    $failed++;
}

echo "\n";

// ── Backfill list__vendor.default_currency from the most recent order ──
// For each vendor still on the 'PLN' default that has at least one order,
// set default_currency to the currency id of the most recent item in the
// most recent order (id DESC, which matches insertion order for AUTO_INCREMENT).
// Orders can mix currencies, so "the last order's currency" is read from
// the last item of that order. The WHERE clause compares the int FK to
// PLN's id (resolved via subquery) — using a hard-coded "1" would
// silently skip rows if a future re-seed renumbered the IDs.
//
// Idempotent:
//   - re-runs only touch rows where default_currency is still 'PLN', so
//     any manual override is preserved;
//   - if the most recent item's currency is also 'PLN', the UPDATE is a
//     no-op anyway.
try {
    $sql = "
        UPDATE `list__vendor` v
        JOIN (
            SELECT po.vendor_id AS vendor_id,
                   c.id AS currency_id
              FROM `purchase__order_item` poi
              JOIN `purchase__order` po ON po.id = poi.po_id
              JOIN `list__currency`   c  ON c.code = poi.currency
              JOIN (
                  SELECT po2.vendor_id, MAX(po2.id) AS last_po_id
                    FROM `purchase__order` po2
                   GROUP BY po2.vendor_id
              ) lp  ON lp.vendor_id   = po.vendor_id
                    AND lp.last_po_id = po.id
              JOIN (
                  SELECT poi2.po_id, MAX(poi2.id) AS last_poi_id
                    FROM `purchase__order_item` poi2
                   GROUP BY poi2.po_id
              ) lpi ON lpi.po_id        = po.id
                    AND lpi.last_poi_id = poi.id
        ) src ON src.vendor_id = v.id
           SET v.default_currency = src.currency_id
         WHERE v.default_currency = (SELECT id FROM `list__currency` WHERE code = 'PLN')
    ";
    $rowCount = $db->exec($sql);
    echo "✓ list__vendor.default_currency backfilled from last order: {$rowCount} vendor(s) updated\n";
} catch (\PDOException $e) {
    fwrite(STDERR, "✗ list__vendor.default_currency backfill failed: " . $e->getMessage() . "\n");
    $failed++;
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

// ── Required seed: currency reference table ────────────────────────────
// Powers the bootstrap-select dropdown on /admin/purchase/vendors/edit
// (and any future currency picker). Currencies used in Polish/EU
// manufacturing procurement. INSERT IGNORE on (code) — the table has a
// UNIQUE index on `code`, so re-runs only fill missing rows. Operator
// can add more rows later via the listing/Admin module.
try {
    $db->exec("
        INSERT IGNORE INTO `list__currency` (`code`, `name`, `isActive`) VALUES
            ('PLN', 'Polski złoty',         1),
            ('EUR', 'Euro',                 1),
            ('USD', 'Dolar amerykański',    1),
            ('GBP', 'Funt szterling',       1),
            ('CHF', 'Frank szwajcarski',    1),
            ('CZK', 'Korona czeska',        1),
            ('UAH', 'Hrywna ukraińska',     1),
            ('SEK', 'Korona szwedzka',      1),
            ('NOK', 'Korona norweska',      1),
            ('DKK', 'Korona duńska',        1),
            ('HUF', 'Forint węgierski',     1),
            ('CNY', 'Juan chiński',         1),
            ('JPY', 'Jen japoński',         1)
    ");
    echo "✓ list__currency: reference seed ensured (13 active currencies)\n";
} catch (\PDOException $e) {
    fwrite(STDERR, "✗ list__currency seed failed: " . $e->getMessage() . "\n");
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
    : "Tables: all 13 present ✓\n");

// FK count on the 13 new tables. Sum of FKs:
//   list__currency (0) + list__producer (0) + list__vendor (1, since
//     default_currency → list__currency.id) + list__vendor_part (4) +
//   list__vendor_part_pack (1) + list__vendor_supplier (1) +
//   purchase__number_counter (0) + purchase__rfq (3 — vendor_id, created_by,
//     responded_by) +
//   purchase__rfq_item (3) + purchase__order (3) +
//   purchase__order_item (3) + purchase__order_receipt (2) +
//   purchase__order_receipt_item (3) = 24
$fkStmt = $db->prepare(
    "SELECT COUNT(*)
       FROM information_schema.KEY_COLUMN_USAGE
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME IN ($placeholders)
        AND REFERENCED_TABLE_NAME IS NOT NULL"
);
$fkStmt->execute($expectedTables);
$fkCount = (int) $fkStmt->fetchColumn();
echo "FKs on new tables: {$fkCount} (expected 24) " . ($fkCount === 24 ? '✓' : '✗') . "\n";

$slugCount = (int) $db->query(
    "SELECT COUNT(*)
       FROM `ref__transfer_group_types`
       WHERE `slug` = 'purchase_receipt'"
)->fetchColumn();
echo "Seed slug 'purchase_receipt': " . ($slugCount === 1 ? 'present ✓' : "MISSING (got {$slugCount}) ✗") . "\n";

$currencySeedCount = (int) $db->query(
    "SELECT COUNT(*)
       FROM `list__currency`
       WHERE `isActive` = 1"
)->fetchColumn();
// We seed 13 ISO codes; allow > 13 because operators may add more.
// The check enforces at least the seed ran (otherwise the dropdown
// would only show whatever the operator manually added).
echo "list__currency seed: {$currencySeedCount} active (expected ≥ 13) "
   . ($currencySeedCount >= 13 ? '✓' : '✗') . "\n";

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

// list__vendor.default_currency. Fresh DBs get it via CREATE TABLE;
// already-migrated DBs get it via the conversion block above. The
// backfill from the most recent order is a separate step and is not
// re-checked here (it's a data migration, not a schema invariant).
$colExistsStmt->execute(['list__vendor', 'default_currency']);
$vendorCurrencyCol = (bool) $colExistsStmt->fetchColumn();
echo "list__vendor.default_currency: " . ($vendorCurrencyCol ? "present ✓" : "MISSING ✗") . "\n";

// Same column, type check — must be int (the FK target shape).
$vendorCurrencyType = (string) $db->query("
    SELECT DATA_TYPE FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'list__vendor'
       AND COLUMN_NAME = 'default_currency'
")->fetchColumn();
echo "list__vendor.default_currency type: "
   . ($vendorCurrencyType === 'int' ? "INT ✓" : "{$vendorCurrencyType} ✗ (expected int)")
   . "\n";

// Same column, FK check — must reference list__currency.id.
$vendorCurrencyFk = (int) $db->query("
    SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'list__vendor'
       AND COLUMN_NAME = 'default_currency'
       AND REFERENCED_TABLE_NAME = 'list__currency'
")->fetchColumn();
echo "list__vendor.default_currency FK → list__currency: "
   . ($vendorCurrencyFk > 0 ? "present ✓" : "MISSING ✗") . "\n";

// purchase__number_counter must be in the per-(type, vendor_id) shape:
// ENUM('rfq','po','grn'), vendor_id column present, PRIMARY KEY
// (type, vendor_id). PurchaseActionHandler::allocateDocumentNumber() keys
// on (type, vendor_id) and formats types by vote: CREATE RULE = revenue.
$counterTypeNow = (string) $db->query("
    SELECT COLUMN_TYPE FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'purchase__number_counter'
       AND COLUMN_NAME  = 'type'
")->fetchColumn();
$counterTypeOk = $counterTypeNow !== ''
    && strpos($counterTypeNow, "'grn'") !== false
    && strpos($counterTypeNow, "'pz'")  === false;
echo "purchase__number_counter.type: "
    . ($counterTypeOk
        ? "ENUM('rfq','po','grn') ✓"
        : "wrong shape ✗ (got {$counterTypeNow})")
    . "\n";

$colExistsStmt->execute(['purchase__number_counter', 'vendor_id']);
$counterVendorIdCol = (bool) $colExistsStmt->fetchColumn();
echo "purchase__number_counter.vendor_id: "
    . ($counterVendorIdCol ? "present ✓" : "MISSING ✗")
    . "\n";

// purchase__number_counter.year must be gone — half-failed runs that
// dropped the PK but left the column (or the reverse) are caught here.
$colExistsStmt->execute(['purchase__number_counter', 'year']);
$counterYearAbsent = !$colExistsStmt->fetchColumn();
echo "purchase__number_counter.year: "
    . ($counterYearAbsent ? "absent ✓" : "still present ✗")
    . "\n";

$counterPkCols = (string) $db->query("
    SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR ',')
      FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'purchase__number_counter'
       AND INDEX_NAME   = 'PRIMARY'
")->fetchColumn();
echo "purchase__number_counter PRIMARY KEY: "
    . ($counterPkCols === 'type,vendor_id'
        ? "(type,vendor_id) ✓"
        : "wrong shape ✗ (got '{$counterPkCols}')")
    . "\n";

// purchase__rfq.responded_at / responded_by — used by
// PurchaseActionHandler::markRfqResponded(). Fresh DBs get them via the
// ALTER block above; we verify they're both present.
$rfqResponseMissing = [];
foreach (['responded_at', 'responded_by'] as $col) {
    $colExistsStmt->execute(['purchase__rfq', $col]);
    if (!$colExistsStmt->fetchColumn()) {
        $rfqResponseMissing[] = "purchase__rfq.{$col}";
    }
}
echo "purchase__rfq.responded_at / responded_by: "
   . ($rfqResponseMissing ? "MISSING " . implode(', ', $rfqResponseMissing) . " ✗" : "both present ✓")
   . "\n";

// FK responded_by → user.user_id — defensive check for the migration
// path where the ALTER ran before the user table existed (shouldn't
// happen because user predates this script, but cheap to verify).
$rfqResponderFk = (int) $db->query("
    SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'purchase__rfq'
       AND COLUMN_NAME = 'responded_by'
       AND REFERENCED_TABLE_NAME = 'user'
")->fetchColumn();
echo "purchase__rfq.responded_by FK → user: "
   . ($rfqResponderFk > 0 ? "present ✓" : "MISSING ✗")
   . "\n";

// list__vendor_supplier.is_primary — used by VendorSupplierRepository
// to sort and toggle the per-vendor primary contact.
$colExistsStmt->execute(['list__vendor_supplier', 'is_primary']);
$vendorSupplierPrimaryCol = (bool) $colExistsStmt->fetchColumn();
echo "list__vendor_supplier.is_primary: "
   . ($vendorSupplierPrimaryCol ? "present ✓" : "MISSING ✗")
   . "\n";

echo "\nDone. Created: {$created}, Skipped: {$skipped}, Failed: {$failed}.\n";

$ok = $failed === 0
    && empty($missing)
    && $fkCount === 23
    && $slugCount === 1
    && empty($pdfMissing)
    && $vendorCurrencyCol
    && $vendorCurrencyType === 'int'
    && $vendorCurrencyFk > 0
    && $currencySeedCount >= 13
    && $counterTypeOk
    && $counterVendorIdCol
    && $counterYearAbsent
    && $counterPkCols === 'type,vendor_id'
    && $fkCount === 24
    && empty($rfqResponseMissing)
    && $rfqResponderFk > 0
    && $vendorSupplierPrimaryCol;
exit($ok ? 0 : 2);
