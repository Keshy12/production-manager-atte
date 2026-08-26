-- ============================================================
-- Procurement Module — Phase 2 schema (RFQ lifecycle + numbering)
-- Apply to the `atte_ms` database.
-- Wraps CREATE statements in a transaction so a failure rolls back cleanly.
-- ============================================================

START TRANSACTION;

-- ------------------------------------------------------------
-- Document-number counter (used by PO too — schema shared)
-- NB: ENUM value is 'po', NOT 'order' — 'order' is a SQL reserved word.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `purchase__number_counter` (
  `year`       SMALLINT NOT NULL,
  `type`       ENUM('rfq','po') NOT NULL,
  `last_value` INT NOT NULL DEFAULT 0,
  PRIMARY KEY (`year`,`type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ------------------------------------------------------------
-- RFQ (Request For Quote) header
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `purchase__rfq` (
  `id`           INT NOT NULL AUTO_INCREMENT,
  `vendor_id`    INT NOT NULL,
  `state`        ENUM('draft','sent','responded','cancelled','converted') NOT NULL DEFAULT 'draft',
  `rfq_number`   VARCHAR(64),                            -- e.g. RFQ/2026/0003
  `expected_reply_date` DATE,
  `sent_at`      DATETIME,
  `created_by`   INT NOT NULL,                           -- → user.user_id
  `comment`      TEXT,
  `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_rfq_vendor` (`vendor_id`),
  KEY `idx_rfq_state`  (`state`),
  CONSTRAINT `fk_rfq_vendor` FOREIGN KEY (`vendor_id`)  REFERENCES `list__vendor`(`id`),
  CONSTRAINT `fk_rfq_user`   FOREIGN KEY (`created_by`) REFERENCES `user`(`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ------------------------------------------------------------
-- RFQ line items
-- NB: unit_price is nullable — admin sets it as a target on the RFQ edit
-- page; not required at creation time.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `purchase__rfq_item` (
  `id`                INT NOT NULL AUTO_INCREMENT,
  `rfq_id`            INT NOT NULL,
  `vendor_part_id`    INT NOT NULL,
  `quantity`          DECIMAL(30,10) NOT NULL,
  `quantity_unit_id`  INT NOT NULL,
  `unit_price`        DECIMAL(30,10),
  `currency`          VARCHAR(8) NOT NULL DEFAULT 'PLN',
  `picked_pack_size`  DECIMAL(30,10) NULL,    -- P7: operator-chosen pack size from the cart UI; NULL = "not picked"
  `comment`           TEXT,
  PRIMARY KEY (`id`),
  KEY `idx_rfq_item_rfq` (`rfq_id`),
  CONSTRAINT `fk_rfq_item_rfq`  FOREIGN KEY (`rfq_id`)
    REFERENCES `purchase__rfq`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_rfq_item_vp`   FOREIGN KEY (`vendor_part_id`)
    REFERENCES `list__vendor_part`(`id`),
  CONSTRAINT `fk_rfq_item_unit` FOREIGN KEY (`quantity_unit_id`)
    REFERENCES `part__unit`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

COMMIT;

-- ============================================================
-- Sanity checks (run after the CREATE block)
-- ============================================================
-- SELECT COUNT(*) FROM information_schema.tables
--   WHERE table_schema = DATABASE()
--     AND table_name IN ('purchase__rfq','purchase__rfq_item','purchase__number_counter');
-- expected: 3

-- SELECT CONSTRAINT_NAME, REFERENCED_TABLE_NAME
--   FROM information_schema.KEY_COLUMN_USAGE
--   WHERE TABLE_SCHEMA = DATABASE()
--     AND TABLE_NAME IN ('purchase__rfq','purchase__rfq_item')
--     AND REFERENCED_TABLE_NAME IS NOT NULL;
-- expected: 5 rows total (2 on rfq → vendor+user, 3 on rfq_item → rfq+vendor_part+unit)

-- SELECT TABLE_NAME, COLUMN_NAME, COLUMN_DEFAULT
--   FROM information_schema.COLUMNS
--   WHERE TABLE_SCHEMA = DATABASE()
--     AND TABLE_NAME = 'purchase__rfq_item'
--     AND COLUMN_NAME = 'currency';
-- expected: 'PLN'

-- INSERT a sentinel counter row so allocateDocumentNumber() doesn't race
-- on the first call of a new year. (Optional but avoids a 'row missing' edge
-- case. PurchaseActionHandler::allocateDocumentNumber() handles this with an
-- INSERT...ON DUPLICATE KEY UPDATE anyway, so this is purely cosmetic.)
-- INSERT IGNORE INTO `purchase__number_counter` (`year`, `type`, `last_value`)
--   VALUES (YEAR(CURDATE()), 'rfq', 0),
--          (YEAR(CURDATE()), 'po',  0);
