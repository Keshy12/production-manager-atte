# Database Migration & System Validation Guide

This document outlines the validation of the current system against the new database schema (`atte_ms.sql`) and provides a step-by-step guide to migrate the old database (`atte_ms_old.sql`) to the new structure.

## 1. System Validation Report

### Compatibility Status: **Compatible (Fixed)**

The codebase has been updated to reflect the new database structure. 

### Key Improvements Made

1.  **Manual Inventory Tracking**:
    *   **Fixed**: The `Atte\Utils\MagazineActionHandler` class has been updated to correctly create an `inventory__transfer_groups` record for manual transfers and magazine clearings. This ensures that user accountability is preserved in the new schema.
2.  **Column Alignment**: Verified that all inventory-related queries now use the `qty` column instead of `quantity`.
3.  **Flowpin Update System**: The system is fully aligned with the new schema, utilizing `transfer_groups` and update sessions for batch operations.

---

## 2. Migration Guide

Follow these steps to migrate your data from the old structure (`atte_ms_old.sql`) to the new one.

### Prerequisites
*   **Backup**: Ensure you have a secure backup of your current `atte_ms_old.sql` database.
*   **Environment**: These scripts assume a MySQL/MariaDB environment.

### Migration Script

Execute the following SQL commands in order. This script preserves data integrity by migrating legacy `user_id` associations into new `transfer_group_id` records.

```sql
SET FOREIGN_KEY_CHECKS=0;
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;

-- ==========================================
-- 1. Create New Tracking Tables
-- ==========================================

-- Create inventory__transfer_groups
CREATE TABLE IF NOT EXISTS `inventory__transfer_groups` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `created_by` int(11) NOT NULL,
  `notes` text DEFAULT NULL,
  `is_cancelled` tinyint(1) NOT NULL DEFAULT 0,
  `cancelled_at` datetime DEFAULT NULL,
  `cancelled_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `flowpin_update_session_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ==========================================
-- 2. Create Global Transfer Groups (Grouping Logic)
-- ==========================================
-- We group rows that were created by the SAME user at the EXACT same second.
-- This ensures that a single "Transfer" (which usually creates a +/- pair) remains one group.

INSERT INTO inventory__transfer_groups (created_by, created_at, notes)
SELECT DISTINCT user_id, timestamp, 'Legacy Migration'
FROM (
    SELECT user_id, timestamp FROM inventory__parts WHERE user_id IS NOT NULL
    UNION
    SELECT user_id, timestamp FROM inventory__sku WHERE user_id IS NOT NULL
    UNION
    SELECT user_id, timestamp FROM inventory__smd WHERE user_id IS NOT NULL
    UNION
    SELECT user_id, timestamp FROM inventory__tht WHERE user_id IS NOT NULL
) AS combined_history;

-- ==========================================
-- 3. Migrate Inventory Data
-- ==========================================

-- Parts
ALTER TABLE inventory__parts 
  CHANGE quantity qty decimal(30,10) NOT NULL,
  ADD COLUMN transfer_group_id int(11) DEFAULT NULL,
  ADD COLUMN is_cancelled tinyint(1) NOT NULL DEFAULT 0,
  ADD COLUMN cancelled_at datetime DEFAULT NULL,
  ADD COLUMN cancelled_by int(11) DEFAULT NULL,
  ADD COLUMN flowpin_update_session_id int(11) DEFAULT NULL,
  ADD COLUMN flowpin_event_id bigint(20) DEFAULT NULL;

UPDATE inventory__parts t 
JOIN inventory__transfer_groups g ON g.created_by = t.user_id AND g.created_at = t.timestamp 
SET t.transfer_group_id = g.id;

ALTER TABLE inventory__parts DROP COLUMN user_id;

-- SKU
ALTER TABLE inventory__sku 
  CHANGE quantity qty decimal(30,10) NOT NULL,
  ADD COLUMN transfer_group_id int(11) DEFAULT NULL,
  ADD COLUMN is_cancelled tinyint(1) NOT NULL DEFAULT 0,
  ADD COLUMN cancelled_at datetime DEFAULT NULL,
  ADD COLUMN cancelled_by int(11) DEFAULT NULL,
  ADD COLUMN flowpin_update_session_id int(11) DEFAULT NULL,
  ADD COLUMN flowpin_event_id bigint(20) DEFAULT NULL;

UPDATE inventory__sku t 
JOIN inventory__transfer_groups g ON g.created_by = t.user_id AND g.created_at = t.timestamp 
SET t.transfer_group_id = g.id;

ALTER TABLE inventory__sku DROP COLUMN user_id;

-- SMD
ALTER TABLE inventory__smd 
  CHANGE quantity qty decimal(30,10) NOT NULL,
  ADD COLUMN transfer_group_id int(11) DEFAULT NULL,
  ADD COLUMN is_cancelled tinyint(1) NOT NULL DEFAULT 0,
  ADD COLUMN cancelled_at datetime DEFAULT NULL,
  ADD COLUMN cancelled_by int(11) DEFAULT NULL,
  ADD COLUMN flowpin_update_session_id int(11) DEFAULT NULL,
  ADD COLUMN flowpin_event_id bigint(20) DEFAULT NULL;

UPDATE inventory__smd t 
JOIN inventory__transfer_groups g ON g.created_by = t.user_id AND g.created_at = t.timestamp 
SET t.transfer_group_id = g.id;

ALTER TABLE inventory__smd DROP COLUMN user_id;

-- THT
ALTER TABLE inventory__tht 
  CHANGE quantity qty decimal(30,10) NOT NULL,
  ADD COLUMN transfer_group_id int(11) DEFAULT NULL,
  ADD COLUMN is_cancelled tinyint(1) NOT NULL DEFAULT 0,
  ADD COLUMN cancelled_at datetime DEFAULT NULL,
  ADD COLUMN cancelled_by int(11) DEFAULT NULL,
  ADD COLUMN flowpin_update_session_id int(11) DEFAULT NULL,
  ADD COLUMN flowpin_event_id bigint(20) DEFAULT NULL;

UPDATE inventory__tht t 
JOIN inventory__transfer_groups g ON g.created_by = t.user_id AND g.created_at = t.timestamp 
SET t.transfer_group_id = g.id;

ALTER TABLE inventory__tht DROP COLUMN user_id;

-- ==========================================
-- 4. Migrate Commission List
-- ==========================================

ALTER TABLE `commission__list`
  ADD COLUMN `created_by` int(11) DEFAULT NULL,
  ADD COLUMN `warehouse_from_id` int(11) DEFAULT NULL,
  ADD COLUMN `warehouse_to_id` int(11) DEFAULT NULL,
  ADD COLUMN `device_type` enum('sku','tht','smd') NOT NULL DEFAULT 'sku',
  ADD COLUMN `bom_id` int(11) NOT NULL DEFAULT 0,
  ADD COLUMN `qty` int(11) NOT NULL DEFAULT 0,
  ADD COLUMN `qty_produced` int(11) DEFAULT 0,
  ADD COLUMN `qty_returned` int(11) DEFAULT 0,
  ADD COLUMN `state` enum('active','completed','returned','cancelled') DEFAULT 'active',
  ADD COLUMN `priority_new` enum('none','standard','urgent','critical') DEFAULT 'none',
  ADD COLUMN `is_cancelled` tinyint(1) DEFAULT 0,
  ADD COLUMN `cancelled_at` datetime DEFAULT NULL,
  ADD COLUMN `cancelled_by` int(11) DEFAULT NULL,
  ADD COLUMN `transfer_group_id` int(11) DEFAULT NULL,
  ADD COLUMN `created_at` datetime DEFAULT current_timestamp(),
  ADD COLUMN `updated_at` datetime DEFAULT current_timestamp();

UPDATE `commission__list` SET
  `created_by` = `user_id`,
  `warehouse_from_id` = `magazine_from`,
  `warehouse_to_id` = `magazine_to`,
  `qty` = `quantity`,
  `qty_produced` = `quantity_produced`,
  `qty_returned` = `quantity_returned`,
  `created_at` = `timestamp_created`,
  `updated_at` = COALESCE(`timestamp_finished`, `timestamp_created`),
  `is_cancelled` = `isCancelled`,
  `state` = CASE WHEN `state_id` = 1 THEN 'active' ELSE 'completed' END,
  `priority_new` = CASE `priority` WHEN 1 THEN 'standard' WHEN 2 THEN 'urgent' WHEN 3 THEN 'critical' ELSE 'none' END;

UPDATE `commission__list` SET `device_type` = 'sku', `bom_id` = `bom_sku_id` WHERE `bom_sku_id` IS NOT NULL;
UPDATE `commission__list` SET `device_type` = 'smd', `bom_id` = `bom_smd_id` WHERE `bom_smd_id` IS NOT NULL;
UPDATE `commission__list` SET `device_type` = 'tht', `bom_id` = `bom_tht_id` WHERE `bom_tht_id` IS NOT NULL;

ALTER TABLE `commission__list` 
  DROP COLUMN `user_id`, DROP COLUMN `magazine_from`, DROP COLUMN `magazine_to`, 
  DROP COLUMN `bom_tht_id`, DROP COLUMN `bom_smd_id`, DROP COLUMN `bom_sku_id`, 
  DROP COLUMN `quantity`, DROP COLUMN `quantity_produced`, DROP COLUMN `quantity_returned`, 
  DROP COLUMN `timestamp_created`, DROP COLUMN `timestamp_finished`, DROP COLUMN `state_id`, 
  DROP COLUMN `priority`, DROP COLUMN `isCancelled`;

ALTER TABLE `commission__list` CHANGE `priority_new` `priority` enum('none','standard','urgent','critical') DEFAULT 'none';

-- ==========================================
-- 5. Final Cleanup
-- ==========================================

DROP TABLE IF EXISTS `commission__priority`;
DROP TABLE IF EXISTS `commission__state`;

COMMIT;
SET FOREIGN_KEY_CHECKS=1;
```
