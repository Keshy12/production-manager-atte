-- ============================================================
-- Procurement Module — Phase 7 schema (picked_pack_size on line items)
-- Apply to the `atte_ms` database.
--
-- Adds the `picked_pack_size DECIMAL(30,10) NULL` column to the two
-- purchase line-item tables so the cart UI can persist the operator-
-- chosen pack size per line. NULL means "no pick recorded yet" — the
-- UI falls back to the smallest pack from list__vendor_part_pack in
-- that case.
--
-- Prerequisite: P2 + P3 schema must already be applied
-- (purchase__rfq_item + purchase__order_item must exist).
--
-- The PHP layer (`src/cron/apply-p7-schema.php`) implements this same
-- rename in pure PHP — see that file for the idempotent runner. The
-- SQL file remains runnable via `mysql -u root -p atte_ms < P7-schema.sql`.
--
-- Idempotent: skip the ALTER if the target column already exists.
-- The runner is graceful if a table is missing (P2/P3 not applied yet) —
-- it logs "table missing, skipped" and continues.
-- ============================================================

DELIMITER $$
DROP PROCEDURE IF EXISTS add_picked_pack_size $$
CREATE PROCEDURE add_picked_pack_size()
BEGIN
    DECLARE done TINYINT DEFAULT 0;
    DECLARE table_name VARCHAR(64);
    DECLARE needs_rename TINYINT DEFAULT 0;

    DECLARE cur CURSOR FOR
        SELECT TABLE_NAME FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME IN (
               'purchase__order_item',
               'purchase__rfq_item'
           );
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET needs_rename = 1;

    OPEN cur;
    read_loop: LOOP
        FETCH cur INTO table_name;
        IF needs_rename THEN
            LEAVE read_loop;
        END IF;
        -- Only add the column if it doesn't already exist (idempotent).
        IF NOT EXISTS (
            SELECT 1 FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME   = table_name
               AND COLUMN_NAME  = 'picked_pack_size'
        ) THEN
            SET @ddl := CONCAT(
                'ALTER TABLE `', table_name,
                '` ADD COLUMN `picked_pack_size` DECIMAL(30,10) NULL'
            );
            PREPARE stmt FROM @ddl;
            EXECUTE stmt;
            DEALLOCATE PREPARE stmt;
        END IF;
    END LOOP read_loop;
    CLOSE cur;
END $$
DELIMITER ;

CALL add_picked_pack_size();
DROP PROCEDURE add_picked_pack_size;

-- ============================================================
-- Sanity check
-- ============================================================
-- SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE
--   FROM information_schema.COLUMNS
--  WHERE TABLE_SCHEMA = DATABASE()
--    AND TABLE_NAME   IN ('purchase__order_item', 'purchase__rfq_item')
--    AND COLUMN_NAME  = 'picked_pack_size';
-- expected: two rows; COLUMN_TYPE = 'decimal(30,10)'; IS_NULLABLE = 'YES'.