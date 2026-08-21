-- ============================================================
-- Procurement Module — Phase 5 schema (add producer_part_no column)
-- Apply to the `atte_ms` database.
--
-- Adds a nullable `producer_part_no` VARCHAR(255) column to
-- `list__vendor_part`. This is the producer's own part number for
-- the same component (often different from our internal part name
-- AND from the vendor's reference). Was deferred in plan §9.2;
-- added now to support showing it in the Koszyk cart items table.
--
-- Idempotent: skip the ALTER if the column already exists.
-- ============================================================

SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'list__vendor_part'
       AND COLUMN_NAME  = 'producer_part_no'
);

SET @ddl := IF(@col_exists = 0,
    'ALTER TABLE `list__vendor_part` ADD COLUMN `producer_part_no` VARCHAR(255) DEFAULT NULL AFTER `vendor_part_no`',
    'SELECT ''producer_part_no already present, skipping'' AS info'
);

PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ============================================================
-- Sanity check
-- ============================================================
-- SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT
--   FROM information_schema.COLUMNS
--  WHERE TABLE_SCHEMA = DATABASE()
--    AND TABLE_NAME   = 'list__vendor_part'
--    AND COLUMN_NAME  = 'producer_part_no';
-- expected: producer_part_no | varchar(255) | YES | NULL
