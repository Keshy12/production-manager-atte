-- ============================================================
-- Procurement Module — Phase 4 schema (Receiving + inventory integration)
-- Apply to the `atte_ms` database.
-- Wraps CREATE statements in a transaction so a failure rolls back cleanly.
--
-- Prerequisite: P3 schema must already be applied (purchase__order and
-- purchase__order_item must exist for the receipt FKs to resolve).
-- ============================================================

START TRANSACTION;

-- ------------------------------------------------------------
-- Goods Receipt header (one row per delivery note / WZ-PZ)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `purchase__order_receipt` (
  `id`              INT NOT NULL AUTO_INCREMENT,
  `po_id`           INT NOT NULL,
  `document_number` VARCHAR(64),
  `received_by`     INT NOT NULL,
  `received_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `comment`         TEXT,
  PRIMARY KEY (`id`),
  KEY `idx_receipt_po` (`po_id`),
  CONSTRAINT `fk_receipt_po`   FOREIGN KEY (`po_id`)       REFERENCES `purchase__order`(`id`),
  CONSTRAINT `fk_receipt_user` FOREIGN KEY (`received_by`) REFERENCES `user`(`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ------------------------------------------------------------
-- Goods Receipt line items
-- NB: quantity_received here is the per-receipt delta; the running
-- total lives in purchase__order_item.quantity_received (P3 column,
-- written by PurchaseActionHandler::createReceipt()).
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `purchase__order_receipt_item` (
  `id`                INT NOT NULL AUTO_INCREMENT,
  `receipt_id`        INT NOT NULL,
  `po_item_id`        INT NOT NULL,
  `quantity_received` DECIMAL(30,10) NOT NULL,
  `sub_magazine_id`   INT NOT NULL,
  `comment`           TEXT,
  PRIMARY KEY (`id`),
  KEY `idx_receipt_item_receipt` (`receipt_id`),
  KEY `idx_receipt_item_po_item` (`po_item_id`),
  CONSTRAINT `fk_receipt_item_receipt` FOREIGN KEY (`receipt_id`)
    REFERENCES `purchase__order_receipt`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_receipt_item_po_item` FOREIGN KEY (`po_item_id`)
    REFERENCES `purchase__order_item`(`id`),
  CONSTRAINT `fk_receipt_item_mag` FOREIGN KEY (`sub_magazine_id`)
    REFERENCES `magazine__list`(`sub_magazine_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

COMMIT;

-- ============================================================
-- Transfer-group type for receipt-driven inventory entries
-- PurchaseActionHandler::createReceipt() uses this slug when it
-- opens a TransferGroupManager::createTransferGroup(...) call,
-- so the receipt shows up in the Archive view with a readable label.
-- ============================================================
INSERT IGNORE INTO `ref__transfer_group_types` (`slug`, `template`)
VALUES ('purchase_receipt', 'Przyjęcie z zamówienia #{po_id}');

-- ============================================================
-- Sanity checks (run after the schema + insert)
-- ============================================================
-- SELECT COUNT(*) FROM information_schema.tables
--   WHERE table_schema = DATABASE()
--     AND table_name IN ('purchase__order_receipt','purchase__order_receipt_item');
-- expected: 2

-- SELECT CONSTRAINT_NAME, REFERENCED_TABLE_NAME
--   FROM information_schema.KEY_COLUMN_USAGE
--   WHERE TABLE_SCHEMA = DATABASE()
--     AND TABLE_NAME IN ('purchase__order_receipt','purchase__order_receipt_item')
--     AND REFERENCED_TABLE_NAME IS NOT NULL;
-- expected: 5 rows total (2 on receipt -> po+user, 3 on receipt_item ->
-- receipt+po_item+magazine)

-- SELECT slug FROM ref__transfer_group_types WHERE slug = 'purchase_receipt';
-- expected: 1 row
