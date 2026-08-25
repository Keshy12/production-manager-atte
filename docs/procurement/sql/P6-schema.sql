-- ============================================================
-- Procurement Module — Phase 6 schema (align is_active → isActive)
-- Apply to the `atte_ms` database.
--
-- Renames the `is_active` column to `isActive` on the four procurement
-- master tables so they match the camelCase convention used by every
-- other `list__*` and `magazine__*` table in the app.
--
-- The PHP layer aliases `is_active AS isActive` everywhere today, so
-- the visible behaviour is unchanged after this migration. Drop the
-- aliases as a follow-up if desired.
--
-- Idempotent: skip the RENAME if the target column already exists.
-- ============================================================

DELIMITER $$
DROP PROCEDURE IF EXISTS rename_is_active_to_isActive $$
CREATE PROCEDURE rename_is_active_to_isActive()
BEGIN
    DECLARE needs_rename TINYINT DEFAULT 0;
    DECLARE table_name VARCHAR(64);

    DECLARE cur CURSOR FOR
        SELECT TABLE_NAME FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND COLUMN_NAME = 'is_active'
           AND TABLE_NAME IN (
               'list__vendor',
               'list__vendor_supplier',
               'list__producer',
               'list__vendor_part'
           );
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET needs_rename = 1;

    OPEN cur;
    read_loop: LOOP
        FETCH cur INTO table_name;
        IF needs_rename THEN
            LEAVE read_loop;
        END IF;
        SET @ddl := CONCAT(
            'ALTER TABLE `', table_name,
            '` CHANGE COLUMN `is_active` `isActive` TINYINT(1) NOT NULL DEFAULT 1'
        );
        PREPARE stmt FROM @ddl;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END LOOP read_loop;
    CLOSE cur;
END $$
DELIMITER ;

CALL rename_is_active_to_isActive();
DROP PROCEDURE rename_is_active_to_isActive;

-- ============================================================
-- Sanity check
-- ============================================================
-- SELECT TABLE_NAME, COLUMN_NAME
--   FROM information_schema.COLUMNS
--  WHERE TABLE_SCHEMA = DATABASE()
--    AND TABLE_NAME IN (
--        'list__vendor', 'list__vendor_supplier',
--        'list__producer', 'list__vendor_part'
--    )
--    AND COLUMN_NAME IN ('is_active', 'isActive');
-- expected: four rows with COLUMN_NAME = 'isActive'; zero rows with 'is_active'.
