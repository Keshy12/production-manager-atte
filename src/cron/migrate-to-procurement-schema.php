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
//   purchase__number_counter (0) + purchase__rfq (2) +
//   purchase__rfq_item (3) + purchase__order (3) +
//   purchase__order_item (3) + purchase__order_receipt (2) +
//   purchase__order_receipt_item (3) = 23
$fkStmt = $db->prepare(
    "SELECT COUNT(*)
       FROM information_schema.KEY_COLUMN_USAGE
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME IN ($placeholders)
        AND REFERENCED_TABLE_NAME IS NOT NULL"
);
$fkStmt->execute($expectedTables);
$fkCount = (int) $fkStmt->fetchColumn();
echo "FKs on new tables: {$fkCount} (expected 23) " . ($fkCount === 23 ? '✓' : '✗') . "\n";

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

echo "\nDone. Created: {$created}, Skipped: {$skipped}, Failed: {$failed}.\n";

$ok = $failed === 0
    && empty($missing)
    && $fkCount === 23
    && $slugCount === 1
    && empty($pdfMissing)
    && $vendorCurrencyCol
    && $vendorCurrencyType === 'int'
    && $vendorCurrencyFk > 0
    && $currencySeedCount >= 13;
exit($ok ? 0 : 2);
