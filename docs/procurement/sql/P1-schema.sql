-- ============================================================
-- Procurement Module — Phase 1 schema (master data only)
-- Apply to the `atte_ms` database.
-- Bracketing each CREATE in a transaction so a failure rolls back cleanly.
-- ============================================================

START TRANSACTION;

-- ------------------------------------------------------------
-- 4.1.1 Vendor
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `list__vendor` (
  `id`              INT NOT NULL AUTO_INCREMENT,
  `name`            VARCHAR(255) NOT NULL,
  `address`         TEXT,
  `additional_data` TEXT,
  `lead_time_days`  INT DEFAULT NULL,
  `is_active`       TINYINT(1) NOT NULL DEFAULT 1,
  `comment`         TEXT,
  `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                                  ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_active` (`is_active`)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- 4.1.2 Vendor contact person (1-to-many to list__vendor)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `list__vendor_supplier` (
  `id`         INT NOT NULL AUTO_INCREMENT,
  `vendor_id`  INT NOT NULL,
  `name`       VARCHAR(255) NOT NULL,
  `job_title`  VARCHAR(255) DEFAULT NULL,
  `phone`      VARCHAR(64)  DEFAULT NULL,
  `email`      VARCHAR(255) DEFAULT NULL,
  `is_active`  TINYINT(1) NOT NULL DEFAULT 1,
  `comment`    TEXT,
  PRIMARY KEY (`id`),
  KEY `vendor_id` (`vendor_id`),
  CONSTRAINT `fk_vs_vendor`
    FOREIGN KEY (`vendor_id`) REFERENCES `list__vendor`(`id`)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- 4.1.3 Producer (manufacturer)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `list__producer` (
  `id`        INT NOT NULL AUTO_INCREMENT,
  `name`      VARCHAR(255) NOT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `comment`   TEXT,
  PRIMARY KEY (`id`),
  KEY `idx_active` (`is_active`)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- 4.1.4 Vendor Part (the "OrderVariant")
-- Uniqueness: vendor's own part no is unique per vendor.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `list__vendor_part` (
  `id`                  INT NOT NULL AUTO_INCREMENT,
  `vendor_id`           INT NOT NULL,
  `producer_id`         INT NOT NULL,
  `parts_id`            INT NOT NULL,
  `vendor_part_no`      VARCHAR(255) NOT NULL,
  `vendor_jm_id`        INT NOT NULL,
  `full_pack_quantity`  DECIMAL(30,10) NOT NULL DEFAULT 1,
  `is_active`           TINYINT(1) NOT NULL DEFAULT 1,
  `comment`             TEXT,
  `created_at`          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                                    ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_vendor_part_no` (`vendor_id`,`vendor_part_no`),
  KEY `idx_vp_vendor`    (`vendor_id`),
  KEY `idx_vp_producer`  (`producer_id`),
  KEY `idx_vp_parts`     (`parts_id`),
  CONSTRAINT `fk_vp_vendor`   FOREIGN KEY (`vendor_id`)
    REFERENCES `list__vendor`(`id`)
    ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_vp_producer` FOREIGN KEY (`producer_id`)
    REFERENCES `list__producer`(`id`)
    ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_vp_parts`    FOREIGN KEY (`parts_id`)
    REFERENCES `list__parts`(`id`)
    ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_vp_unit`     FOREIGN KEY (`vendor_jm_id`)
    REFERENCES `part__unit`(`id`)
    ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB;

COMMIT;

-- ============================================================
-- Sanity checks (run these after the CREATE block, expect 1 row each)
-- ============================================================
-- SELECT COUNT(*) FROM information_schema.tables
--   WHERE table_schema = DATABASE()
--     AND table_name IN ('list__vendor','list__vendor_supplier',
--                        'list__producer','list__vendor_part');
-- expected: 4

-- SELECT
--   CONSTRAINT_NAME, TABLE_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
-- FROM information_schema.KEY_COLUMN_USAGE
-- WHERE TABLE_SCHEMA = DATABASE()
--   AND TABLE_NAME = 'list__vendor_part'
--   AND REFERENCED_TABLE_NAME IS NOT NULL;
-- expected: 4 rows (vendor, producer, parts, unit)
