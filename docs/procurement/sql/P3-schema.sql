-- ============================================================
-- Procurement Module — Phase 3 schema (Purchase Order lifecycle)
-- Apply to the `atte_ms` database.
-- Wraps CREATE statements in a transaction so a failure rolls back cleanly.
--
-- Prerequisite: P2 schema must already be applied (purchase__rfq must exist
-- for fk_po_rfq ON DELETE SET NULL to resolve).
-- ============================================================

START TRANSACTION;

-- ------------------------------------------------------------
-- Purchase Order header
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `purchase__order` (
  `id`                    INT NOT NULL AUTO_INCREMENT,
  `vendor_id`             INT NOT NULL,
  `state`                 ENUM('draft','sent','confirmed','partially_received','received','cancelled') NOT NULL DEFAULT 'draft',
  `po_number`             VARCHAR(64),
  `vendor_po_number`      VARCHAR(64),
  `converted_from_rfq_id` INT DEFAULT NULL,
  `expected_delivery_date` DATE,
  `sent_at`               DATETIME,
  `confirmed_at`          DATETIME,
  `created_by`            INT NOT NULL,
  `comment`               TEXT,
  `created_at`            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_po_vendor` (`vendor_id`),
  KEY `idx_po_state`  (`state`),
  CONSTRAINT `fk_po_vendor` FOREIGN KEY (`vendor_id`)
    REFERENCES `list__vendor`(`id`),
  CONSTRAINT `fk_po_user`   FOREIGN KEY (`created_by`)
    REFERENCES `user`(`user_id`),
  CONSTRAINT `fk_po_rfq`    FOREIGN KEY (`converted_from_rfq_id`)
    REFERENCES `purchase__rfq`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ------------------------------------------------------------
-- Purchase Order line items
-- NB: quantity_received is the running total of physically received units,
-- written by PurchaseActionHandler::createReceipt() in P4. Defaults to 0.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `purchase__order_item` (
  `id`                  INT NOT NULL AUTO_INCREMENT,
  `po_id`               INT NOT NULL,
  `vendor_part_id`      INT NOT NULL,
  `quantity`            DECIMAL(30,10) NOT NULL,
  `quantity_unit_id`    INT NOT NULL,
  `unit_price`          DECIMAL(30,10) NOT NULL DEFAULT 0,
  `currency`            VARCHAR(8) NOT NULL DEFAULT 'PLN',
  `quantity_received`   DECIMAL(30,10) NOT NULL DEFAULT 0,
  `comment`             TEXT,
  PRIMARY KEY (`id`),
  KEY `idx_poi_po` (`po_id`),
  CONSTRAINT `fk_poi_po`   FOREIGN KEY (`po_id`)
    REFERENCES `purchase__order`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_poi_vp`   FOREIGN KEY (`vendor_part_id`)
    REFERENCES `list__vendor_part`(`id`),
  CONSTRAINT `fk_poi_unit` FOREIGN KEY (`quantity_unit_id`)
    REFERENCES `part__unit`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

COMMIT;

-- ============================================================
-- Sanity checks (run after the CREATE block)
-- ============================================================
-- SELECT COUNT(*) FROM information_schema.tables
--   WHERE table_schema = DATABASE()
--     AND table_name IN ('purchase__order','purchase__order_item');
-- expected: 2

-- SELECT CONSTRAINT_NAME, REFERENCED_TABLE_NAME
--   FROM information_schema.KEY_COLUMN_USAGE
--   WHERE TABLE_SCHEMA = DATABASE()
--     AND TABLE_NAME IN ('purchase__order','purchase__order_item')
--     AND REFERENCED_TABLE_NAME IS NOT NULL;
-- expected: 5 rows total (3 on order → vendor+user+rfq, 2 on order_item → order+vendor_part+unit)
-- Note: purchase__order_item has only 2 listed FKs above plus the
--   composite fk_poi_po points at purchase__order (counts as one).
