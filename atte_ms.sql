    -- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Sty 29, 2026 at 07:07 PM
-- Wersja serwera: 10.4.32-MariaDB
-- Wersja PHP: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `atte_ms_old2`
--

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `bom__flat`
--

CREATE TABLE `bom__flat` (
  `id` int(11) NOT NULL,
  `bom_smd_id` int(11) DEFAULT NULL,
  `bom_tht_id` int(11) DEFAULT NULL,
  `bom_sku_id` int(11) DEFAULT NULL,
  `tht_id` int(11) DEFAULT NULL,
  `parts_id` int(11) DEFAULT NULL,
  `smd_id` int(11) DEFAULT NULL,
  `sku_id` int(11) DEFAULT NULL,
  `quantity` decimal(30,10) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `bom__sku`
--

CREATE TABLE `bom__sku` (
  `id` int(11) NOT NULL,
  `sku_id` int(11) NOT NULL,
  `version` text DEFAULT NULL,
  `isActive` tinyint(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `bom__smd`
--

CREATE TABLE `bom__smd` (
  `id` int(11) NOT NULL,
  `smd_id` int(11) NOT NULL,
  `laminate_id` int(11) NOT NULL,
  `version` text NOT NULL,
  `isActive` tinyint(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `bom__tht`
--

CREATE TABLE `bom__tht` (
  `id` int(11) NOT NULL,
  `tht_id` int(11) NOT NULL,
  `version` text DEFAULT NULL,
  `isActive` tinyint(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `commission__list`
--

CREATE TABLE `commission__list` (
  `id` int(11) NOT NULL,
  `created_by` int(11) NOT NULL,
  `warehouse_from_id` int(11) NOT NULL,
  `warehouse_to_id` int(11) NOT NULL,
  `device_type` enum('sku','tht','smd') NOT NULL,
  `bom_id` int(11) NOT NULL,
  `qty` int(11) NOT NULL,
  `qty_produced` int(11) DEFAULT 0,
  `qty_returned` int(11) DEFAULT 0,
  `state` enum('active','completed','returned','cancelled') DEFAULT 'active',
  `priority` enum('none','standard','urgent','critical') DEFAULT 'none',
  `is_cancelled` tinyint(1) DEFAULT 0,
  `cancelled_at` datetime DEFAULT NULL,
  `cancelled_by` int(11) DEFAULT NULL,
  `transfer_group_id` int(11) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `commission__receivers`
--

CREATE TABLE `commission__receivers` (
  `commission_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `google_oauth`
--

CREATE TABLE `google_oauth` (
  `id` int(11) NOT NULL,
  `provider` varchar(255) NOT NULL,
  `provider_value` text NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `group__contractors`
--

CREATE TABLE `group__contractors` (
  `id` int(11) NOT NULL,
  `group_id` int(11) NOT NULL,
  `contractor_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_polish_ci;

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `group__list`
--

CREATE TABLE `group__list` (
  `id` int(11) NOT NULL,
  `name` text NOT NULL,
  `description` text NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_polish_ci;

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `inventory__input_type`
--

CREATE TABLE `inventory__input_type` (
  `id` int(11) NOT NULL,
  `name` text NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `inventory__parts`
--

CREATE TABLE `inventory__parts` (
  `id` int(11) NOT NULL,
  `parts_id` int(11) NOT NULL,
  `commission_id` int(11) DEFAULT NULL,
  `sub_magazine_id` int(11) NOT NULL,
  `qty` decimal(30,10) NOT NULL,
  `transfer_group_id` int(11) NOT NULL,
  `is_cancelled` tinyint(1) NOT NULL DEFAULT 0,
  `cancelled_at` datetime DEFAULT NULL,
  `cancelled_by` int(11) DEFAULT NULL,
  `timestamp` datetime NOT NULL DEFAULT current_timestamp(),
  `production_date` date DEFAULT NULL,
  `input_type_id` int(11) NOT NULL,
  `comment` text NOT NULL,
  `isVerified` tinyint(1) NOT NULL DEFAULT 1,
  `verifiedBy` int(11) DEFAULT NULL,
  `flowpin_update_session_id` int(11) DEFAULT NULL COMMENT 'FK to ref__flowpin_update_progress.id',
  `flowpin_event_id` bigint(20) DEFAULT NULL COMMENT 'FlowPin EventID (non-unique, one event can create multiple rows)'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Wyzwalacze `inventory__parts`
--
DELIMITER $$
CREATE TRIGGER `after_inventory_parts_insert` AFTER INSERT ON `inventory__parts` FOR EACH ROW BEGIN
    DECLARE total_qty DECIMAL(30,10);
    
    SELECT SUM(qty) INTO total_qty
    FROM inventory__parts
    WHERE parts_id = NEW.parts_id AND sub_magazine_id = NEW.sub_magazine_id;

    IF total_qty < 0 THEN
        INSERT INTO lowstock__parts (parts_id, sub_magazine_id, total_quantity)
        VALUES (NEW.parts_id, NEW.sub_magazine_id, total_qty)
        ON DUPLICATE KEY UPDATE total_quantity = VALUES(total_quantity);
    ELSE
        DELETE FROM lowstock__parts
        WHERE parts_id = NEW.parts_id AND sub_magazine_id = NEW.sub_magazine_id;
    END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `after_inventory_parts_update` AFTER UPDATE ON `inventory__parts` FOR EACH ROW BEGIN
    DECLARE total_qty DECIMAL(30,10);
    
    SELECT SUM(qty) INTO total_qty
    FROM inventory__parts
    WHERE parts_id = NEW.parts_id AND sub_magazine_id = NEW.sub_magazine_id;

    IF total_qty < 0 THEN
        INSERT INTO lowstock__parts (parts_id, sub_magazine_id, total_quantity)
        VALUES (NEW.parts_id, NEW.sub_magazine_id, total_qty)
        ON DUPLICATE KEY UPDATE total_quantity = VALUES(total_quantity);
    ELSE
        DELETE FROM lowstock__parts
        WHERE parts_id = NEW.parts_id AND sub_magazine_id = NEW.sub_magazine_id;
    END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `inventory__sku`
--

CREATE TABLE `inventory__sku` (
  `id` int(11) NOT NULL,
  `sku_id` int(11) NOT NULL,
  `sku_bom_id` int(11) DEFAULT NULL,
  `commission_id` int(11) DEFAULT NULL,
  `sub_magazine_id` int(11) NOT NULL,
  `qty` decimal(30,10) NOT NULL,
  `transfer_group_id` int(11) NOT NULL,
  `is_cancelled` tinyint(1) NOT NULL DEFAULT 0,
  `cancelled_at` datetime DEFAULT NULL,
  `cancelled_by` int(11) DEFAULT NULL,
  `timestamp` datetime NOT NULL DEFAULT current_timestamp(),
  `production_date` date DEFAULT NULL,
  `input_type_id` int(11) NOT NULL,
  `comment` text NOT NULL,
  `isVerified` tinyint(1) NOT NULL DEFAULT 1,
  `verifiedBy` int(11) DEFAULT NULL,
  `flowpin_update_session_id` int(11) DEFAULT NULL COMMENT 'FK to ref__flowpin_update_progress.id',
  `flowpin_event_id` bigint(20) DEFAULT NULL COMMENT 'FlowPin EventID (non-unique, one event can create multiple rows)'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Wyzwalacze `inventory__sku`
--
DELIMITER $$
CREATE TRIGGER `after_inventory_sku_insert` AFTER INSERT ON `inventory__sku` FOR EACH ROW BEGIN
    DECLARE total_qty DECIMAL(30,10);
    
    SELECT SUM(qty) INTO total_qty
    FROM inventory__sku
    WHERE sku_id = NEW.sku_id AND sub_magazine_id = NEW.sub_magazine_id;

    IF total_qty < 0 THEN
        INSERT INTO lowstock__sku (sku_id, sub_magazine_id, total_quantity)
        VALUES (NEW.sku_id, NEW.sub_magazine_id, total_qty)
        ON DUPLICATE KEY UPDATE total_quantity = VALUES(total_quantity);
    ELSE
        DELETE FROM lowstock__sku
        WHERE sku_id = NEW.sku_id AND sub_magazine_id = NEW.sub_magazine_id;
    END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `after_inventory_sku_update` AFTER UPDATE ON `inventory__sku` FOR EACH ROW BEGIN
    DECLARE total_qty DECIMAL(30,10);
    
    SELECT SUM(qty) INTO total_qty
    FROM inventory__sku
    WHERE sku_id = NEW.sku_id AND sub_magazine_id = NEW.sub_magazine_id;

    IF total_qty < 0 THEN
        INSERT INTO lowstock__sku (sku_id, sub_magazine_id, total_quantity)
        VALUES (NEW.sku_id, NEW.sub_magazine_id, total_qty)
        ON DUPLICATE KEY UPDATE total_quantity = VALUES(total_quantity);
    ELSE
        DELETE FROM lowstock__sku
        WHERE sku_id = NEW.sku_id AND sub_magazine_id = NEW.sub_magazine_id;
    END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `inventory__smd`
--

CREATE TABLE `inventory__smd` (
  `id` int(11) NOT NULL,
  `smd_id` int(11) NOT NULL,
  `smd_bom_id` int(11) DEFAULT NULL,
  `commission_id` int(11) DEFAULT NULL,
  `sub_magazine_id` int(11) NOT NULL,
  `qty` decimal(30,10) NOT NULL,
  `transfer_group_id` int(11) NOT NULL,
  `is_cancelled` tinyint(1) NOT NULL DEFAULT 0,
  `cancelled_at` datetime DEFAULT NULL,
  `cancelled_by` int(11) DEFAULT NULL,
  `timestamp` datetime NOT NULL DEFAULT current_timestamp(),
  `production_date` date DEFAULT NULL,
  `input_type_id` int(11) NOT NULL,
  `comment` text NOT NULL,
  `isVerified` tinyint(1) DEFAULT 1,
  `verifiedBy` int(11) DEFAULT NULL,
  `flowpin_update_session_id` int(11) DEFAULT NULL COMMENT 'FK to ref__flowpin_update_progress.id',
  `flowpin_event_id` bigint(20) DEFAULT NULL COMMENT 'FlowPin EventID (non-unique, one event can create multiple rows)'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Wyzwalacze `inventory__smd`
--
DELIMITER $$
CREATE TRIGGER `after_inventory_smd_insert` AFTER INSERT ON `inventory__smd` FOR EACH ROW BEGIN
    DECLARE total_qty DECIMAL(30,10);
    
    SELECT SUM(qty) INTO total_qty
    FROM inventory__smd
    WHERE smd_id = NEW.smd_id AND sub_magazine_id = NEW.sub_magazine_id;

    IF total_qty < 0 THEN
        INSERT INTO lowstock__smd (smd_id, sub_magazine_id, total_quantity)
        VALUES (NEW.smd_id, NEW.sub_magazine_id, total_qty)
        ON DUPLICATE KEY UPDATE total_quantity = VALUES(total_quantity);
    ELSE
        DELETE FROM lowstock__smd
        WHERE smd_id = NEW.smd_id AND sub_magazine_id = NEW.sub_magazine_id;
    END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `after_inventory_smd_update` AFTER UPDATE ON `inventory__smd` FOR EACH ROW BEGIN
    DECLARE total_qty DECIMAL(30,10);
    
    SELECT SUM(qty) INTO total_qty
    FROM inventory__smd
    WHERE smd_id = NEW.smd_id AND sub_magazine_id = NEW.sub_magazine_id;

    IF total_qty < 0 THEN
        INSERT INTO lowstock__smd (smd_id, sub_magazine_id, total_quantity)
        VALUES (NEW.smd_id, NEW.sub_magazine_id, total_qty)
        ON DUPLICATE KEY UPDATE total_quantity = VALUES(total_quantity);
    ELSE
        DELETE FROM lowstock__smd
        WHERE smd_id = NEW.smd_id AND sub_magazine_id = NEW.sub_magazine_id;
    END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `inventory__tht`
--

CREATE TABLE `inventory__tht` (
  `id` int(11) NOT NULL,
  `tht_id` int(11) NOT NULL,
  `tht_bom_id` int(11) DEFAULT NULL,
  `commission_id` int(11) DEFAULT NULL,
  `sub_magazine_id` int(11) NOT NULL,
  `qty` decimal(30,10) NOT NULL,
  `transfer_group_id` int(11) NOT NULL,
  `is_cancelled` tinyint(1) NOT NULL DEFAULT 0,
  `cancelled_at` datetime DEFAULT NULL,
  `cancelled_by` int(11) DEFAULT NULL,
  `timestamp` datetime NOT NULL DEFAULT current_timestamp(),
  `production_date` date DEFAULT NULL,
  `input_type_id` int(11) NOT NULL,
  `comment` text NOT NULL,
  `isVerified` tinyint(1) DEFAULT 1,
  `verifiedBy` int(11) DEFAULT NULL,
  `flowpin_update_session_id` int(11) DEFAULT NULL COMMENT 'FK to ref__flowpin_update_progress.id',
  `flowpin_event_id` bigint(20) DEFAULT NULL COMMENT 'FlowPin EventID (non-unique, one event can create multiple rows)'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Wyzwalacze `inventory__tht`
--
DELIMITER $$
CREATE TRIGGER `after_inventory_tht_insert` AFTER INSERT ON `inventory__tht` FOR EACH ROW BEGIN
    DECLARE total_qty DECIMAL(30,10);
    
    SELECT SUM(qty) INTO total_qty
    FROM inventory__tht
    WHERE tht_id = NEW.tht_id AND sub_magazine_id = NEW.sub_magazine_id;

    IF total_qty < 0 THEN
        INSERT INTO lowstock__tht (tht_id, sub_magazine_id, total_quantity)
        VALUES (NEW.tht_id, NEW.sub_magazine_id, total_qty)
        ON DUPLICATE KEY UPDATE total_quantity = VALUES(total_quantity);
    ELSE
        DELETE FROM lowstock__tht
        WHERE tht_id = NEW.tht_id AND sub_magazine_id = NEW.sub_magazine_id;
    END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `after_inventory_tht_update` AFTER UPDATE ON `inventory__tht` FOR EACH ROW BEGIN
    DECLARE total_qty DECIMAL(30,10);
    
    SELECT SUM(qty) INTO total_qty
    FROM inventory__tht
    WHERE tht_id = NEW.tht_id AND sub_magazine_id = NEW.sub_magazine_id;

    IF total_qty < 0 THEN
        INSERT INTO lowstock__tht (tht_id, sub_magazine_id, total_quantity)
        VALUES (NEW.tht_id, NEW.sub_magazine_id, total_qty)
        ON DUPLICATE KEY UPDATE total_quantity = VALUES(total_quantity);
    ELSE
        DELETE FROM lowstock__tht
        WHERE tht_id = NEW.tht_id AND sub_magazine_id = NEW.sub_magazine_id;
    END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `inventory__transfer_groups`
--

CREATE TABLE `inventory__transfer_groups` (
  `id` int(11) NOT NULL,
  `created_by` int(11) NOT NULL,
  `type_id` int(11) DEFAULT NULL,
  `params` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`params`)),
  `is_cancelled` tinyint(1) NOT NULL DEFAULT 0,
  `cancelled_at` datetime DEFAULT NULL,
  `cancelled_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `flowpin_update_session_id` int(11) DEFAULT NULL COMMENT 'FK to ref__flowpin_update_progress.id'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `list__laminate`
--

CREATE TABLE `list__laminate` (
  `id` int(11) NOT NULL,
  `name` text NOT NULL,
  `description` text NOT NULL,
  `isActive` tinyint(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `list__parts`
--

CREATE TABLE `list__parts` (
  `id` int(11) NOT NULL,
  `name` text NOT NULL,
  `description` text NOT NULL,
  `PartGroup` int(11) NOT NULL,
  `PartType` int(11) DEFAULT NULL,
  `JM` int(11) NOT NULL,
  `isActive` tinyint(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `list__sku`
--

CREATE TABLE `list__sku` (
  `id` int(11) NOT NULL,
  `name` text NOT NULL,
  `description` text DEFAULT NULL,
  `isActive` tinyint(1) NOT NULL,
  `isAutoProduced` tinyint(1) NOT NULL DEFAULT 0,
  `autoProduceVersion` varchar(1) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `list__smd`
--

CREATE TABLE `list__smd` (
  `id` int(11) NOT NULL,
  `name` text NOT NULL,
  `description` text NOT NULL,
  `isActive` tinyint(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `list__tht`
--

CREATE TABLE `list__tht` (
  `id` int(11) NOT NULL,
  `name` text NOT NULL,
  `description` text NOT NULL,
  `circle_checked` tinyint(1) NOT NULL DEFAULT 0,
  `triangle_checked` tinyint(1) NOT NULL DEFAULT 0,
  `square_checked` tinyint(1) NOT NULL DEFAULT 0,
  `isActive` tinyint(1) NOT NULL DEFAULT 1,
  `isAutoProduced` tinyint(1) NOT NULL DEFAULT 0,
  `autoProduceVersion` varchar(1) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `lowstock__parts`
--

CREATE TABLE `lowstock__parts` (
  `parts_id` int(11) NOT NULL,
  `sub_magazine_id` int(11) NOT NULL,
  `total_quantity` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf32 COLLATE=utf32_polish_ci;

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `lowstock__sku`
--

CREATE TABLE `lowstock__sku` (
  `sku_id` int(11) NOT NULL,
  `sub_magazine_id` int(11) NOT NULL,
  `total_quantity` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf32 COLLATE=utf32_polish_ci;

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `lowstock__smd`
--

CREATE TABLE `lowstock__smd` (
  `smd_id` int(11) NOT NULL,
  `sub_magazine_id` int(11) NOT NULL,
  `total_quantity` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf32 COLLATE=utf32_polish_ci;

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `lowstock__tht`
--

CREATE TABLE `lowstock__tht` (
  `tht_id` int(11) NOT NULL,
  `sub_magazine_id` int(11) NOT NULL,
  `total_quantity` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf32 COLLATE=utf32_polish_ci;

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `magazine__list`
--

CREATE TABLE `magazine__list` (
  `sub_magazine_id` int(11) NOT NULL,
  `sub_magazine_name` text NOT NULL,
  `type_id` int(11) NOT NULL,
  `isActive` tinyint(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `magazine__type`
--

CREATE TABLE `magazine__type` (
  `id` int(11) NOT NULL,
  `name` text NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_polish_ci;

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `notification__action_needed`
--

CREATE TABLE `notification__action_needed` (
  `id` int(11) NOT NULL,
  `description` text NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `notification__flowpin_query_type`
--

CREATE TABLE `notification__flowpin_query_type` (
  `id` int(11) NOT NULL,
  `description` text NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `notification__list`
--

CREATE TABLE `notification__list` (
  `id` int(11) NOT NULL,
  `timestamp` datetime NOT NULL DEFAULT current_timestamp(),
  `action_needed_id` int(11) NOT NULL,
  `value_for_action` text DEFAULT NULL,
  `isResolved` tinyint(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `notification__queries_affected`
--

CREATE TABLE `notification__queries_affected` (
  `id` int(11) NOT NULL,
  `notification_id` int(11) NOT NULL,
  `values_to_resolve` text NOT NULL,
  `exception_values_serialized` text NOT NULL,
  `flowpin_query_type_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `notification__receivers`
--

CREATE TABLE `notification__receivers` (
  `notification_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `isRead` tinyint(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `part__group`
--

CREATE TABLE `part__group` (
  `id` int(11) NOT NULL,
  `name` text NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `part__type`
--

CREATE TABLE `part__type` (
  `id` int(11) NOT NULL,
  `name` text NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `part__unit`
--

CREATE TABLE `part__unit` (
  `id` int(11) NOT NULL,
  `name` text NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `ref__flowpin_checkpoints`
--

CREATE TABLE `ref__flowpin_checkpoints` (
  `operation_type` varchar(50) NOT NULL COMMENT 'sold_sku, returned_sku, etc.',
  `checkpoint_event_id` bigint(20) NOT NULL COMMENT 'Last processed EventID for this operation'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `ref__flowpin_update_progress`
--

CREATE TABLE `ref__flowpin_update_progress` (
  `id` int(11) NOT NULL,
  `session_id` varchar(50) NOT NULL COMMENT 'Unique identifier for this update run',
  `total_records` int(11) NOT NULL DEFAULT 0,
  `processed_records` int(11) NOT NULL DEFAULT 0,
  `current_operation_type` varchar(50) DEFAULT NULL COMMENT 'production_sku, sold_sku, returned_sku, moved_sku',
  `current_event_id` bigint(20) DEFAULT NULL,
  `status` enum('pending','running','completed','error') NOT NULL DEFAULT 'pending',
  `started_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `starting_event_id` bigint(20) DEFAULT NULL COMMENT 'First EventId processed in session',
  `finishing_event_id` bigint(20) DEFAULT NULL COMMENT 'Last EventId processed in session',
  `created_transfer_count` int(11) DEFAULT 0 COMMENT 'Total transfers created in this session',
  `created_group_count` int(11) DEFAULT 0 COMMENT 'Total transfer groups created in this session'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `ref__package_exclude`
--

CREATE TABLE `ref__package_exclude` (
  `id` int(11) NOT NULL,
  `name` text NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `ref__timestamp`
--

CREATE TABLE `ref__timestamp` (
  `id` int(11) NOT NULL,
  `last_timestamp` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `name` text NOT NULL,
  `params` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_polish_ci;

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `ref__transfer_group_types`
--

CREATE TABLE `ref__transfer_group_types` (
  `id` int(11) NOT NULL,
  `slug` varchar(64) NOT NULL,
  `template` text NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `ref__valuepackage`
--

CREATE TABLE `ref__valuepackage` (
  `id` int(11) NOT NULL,
  `ValuePackage` text NOT NULL,
  `parts_id` int(11) DEFAULT NULL,
  `tht_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `used__sku`
--

CREATE TABLE `used__sku` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `sku_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `used__smd`
--

CREATE TABLE `used__smd` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `smd_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `used__tht`
--

CREATE TABLE `used__tht` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `tht_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `user`
--

CREATE TABLE `user` (
  `user_id` int(11) NOT NULL,
  `login` text NOT NULL,
  `password` text NOT NULL,
  `name` text NOT NULL,
  `surname` text NOT NULL,
  `email` text NOT NULL,
  `isAdmin` tinyint(1) NOT NULL,
  `isActive` tinyint(1) NOT NULL DEFAULT 1,
  `sub_magazine_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Indeksy dla zrzutów tabel
--

--
-- Indeksy dla tabeli `bom__flat`
--
ALTER TABLE `bom__flat`
  ADD PRIMARY KEY (`id`),
  ADD KEY `parts_id` (`parts_id`),
  ADD KEY `smd_id` (`smd_id`),
  ADD KEY `tht_id` (`tht_id`),
  ADD KEY `bom_smd_id` (`bom_smd_id`),
  ADD KEY `bom_tht_id` (`bom_tht_id`),
  ADD KEY `bom_sku_id` (`bom_sku_id`),
  ADD KEY `sku_id` (`sku_id`);

--
-- Indeksy dla tabeli `bom__sku`
--
ALTER TABLE `bom__sku`
  ADD PRIMARY KEY (`id`),
  ADD KEY `tht_id` (`sku_id`);

--
-- Indeksy dla tabeli `bom__smd`
--
ALTER TABLE `bom__smd`
  ADD PRIMARY KEY (`id`),
  ADD KEY `smd_id` (`smd_id`),
  ADD KEY `laminate_id` (`laminate_id`);

--
-- Indeksy dla tabeli `bom__tht`
--
ALTER TABLE `bom__tht`
  ADD PRIMARY KEY (`id`),
  ADD KEY `tht_id` (`tht_id`);

--
-- Indeksy dla tabeli `commission__list`
--
ALTER TABLE `commission__list`
  ADD PRIMARY KEY (`id`),
  ADD KEY `transfer_group_id` (`transfer_group_id`),
  ADD KEY `created_by` (`created_by`),
  ADD KEY `cancelled_by` (`cancelled_by`),
  ADD KEY `warehouse_from_id` (`warehouse_from_id`),
  ADD KEY `warehouse_to_id` (`warehouse_to_id`);

--
-- Indeksy dla tabeli `commission__receivers`
--
ALTER TABLE `commission__receivers`
  ADD PRIMARY KEY (`commission_id`,`user_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indeksy dla tabeli `google_oauth`
--
ALTER TABLE `google_oauth`
  ADD PRIMARY KEY (`id`);

--
-- Indeksy dla tabeli `group__contractors`
--
ALTER TABLE `group__contractors`
  ADD PRIMARY KEY (`id`),
  ADD KEY `contractor_id` (`contractor_id`),
  ADD KEY `group_id` (`group_id`);

--
-- Indeksy dla tabeli `group__list`
--
ALTER TABLE `group__list`
  ADD PRIMARY KEY (`id`);

--
-- Indeksy dla tabeli `inventory__input_type`
--
ALTER TABLE `inventory__input_type`
  ADD PRIMARY KEY (`id`);

--
-- Indeksy dla tabeli `inventory__parts`
--
ALTER TABLE `inventory__parts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `mag_parts_archive_all.parts_id=bom_parts_list.id` (`parts_id`),
  ADD KEY `mag_parts_archive_all.sub_magazine_id=sub_magazine_list.id` (`sub_magazine_id`),
  ADD KEY `mag_parts_archive_all.input_type_id=input_type.id` (`input_type_id`),
  ADD KEY `verifiedBy` (`verifiedBy`),
  ADD KEY `transfer_group_id` (`transfer_group_id`),
  ADD KEY `cancelled_by` (`cancelled_by`),
  ADD KEY `idx_flowpin_update_session` (`flowpin_update_session_id`),
  ADD KEY `idx_flowpin_event` (`flowpin_event_id`),
  ADD KEY `idx_transfer_cancelled_timestamp` (`transfer_group_id`,`is_cancelled`,`timestamp`),
  ADD KEY `idx_cancelled_timestamp` (`is_cancelled`,`timestamp`);

--
-- Indeksy dla tabeli `inventory__sku`
--
ALTER TABLE `inventory__sku`
  ADD PRIMARY KEY (`id`),
  ADD KEY `commision_id` (`commission_id`),
  ADD KEY `sku_id` (`sku_id`),
  ADD KEY `input_type_id` (`input_type_id`),
  ADD KEY `sub_magazine_id` (`sub_magazine_id`),
  ADD KEY `verifiedBy` (`verifiedBy`),
  ADD KEY `sku_bom_id` (`sku_bom_id`),
  ADD KEY `transfer_group_id` (`transfer_group_id`),
  ADD KEY `cancelled_by` (`cancelled_by`),
  ADD KEY `idx_flowpin_update_session` (`flowpin_update_session_id`),
  ADD KEY `idx_flowpin_event` (`flowpin_event_id`),
  ADD KEY `idx_transfer_cancelled_timestamp` (`transfer_group_id`,`is_cancelled`,`timestamp`),
  ADD KEY `idx_cancelled_timestamp` (`is_cancelled`,`timestamp`);

--
-- Indeksy dla tabeli `inventory__smd`
--
ALTER TABLE `inventory__smd`
  ADD PRIMARY KEY (`id`),
  ADD KEY `mag_smd_archive_all.sub_magazine_id = sub_magazine_list.id` (`sub_magazine_id`),
  ADD KEY `mag_smd_archive_all.input_type_id=input_type.id` (`input_type_id`),
  ADD KEY `mag_smd_archive_all.smd_id=smd_list.id` (`smd_id`),
  ADD KEY `verifiedBy` (`verifiedBy`),
  ADD KEY `smd_bom_id` (`smd_bom_id`),
  ADD KEY `transfer_group_id` (`transfer_group_id`),
  ADD KEY `cancelled_by` (`cancelled_by`),
  ADD KEY `idx_flowpin_update_session` (`flowpin_update_session_id`),
  ADD KEY `idx_flowpin_event` (`flowpin_event_id`),
  ADD KEY `idx_transfer_cancelled_timestamp` (`transfer_group_id`,`is_cancelled`,`timestamp`),
  ADD KEY `idx_cancelled_timestamp` (`is_cancelled`,`timestamp`);

--
-- Indeksy dla tabeli `inventory__tht`
--
ALTER TABLE `inventory__tht`
  ADD PRIMARY KEY (`id`),
  ADD KEY `mag_tht_archive_all.tht_id=tht_list.id` (`tht_id`),
  ADD KEY `mag_tht_archive_all.sub_magazine_id=sub_magazine_list.id` (`sub_magazine_id`),
  ADD KEY `mag_tht_archive_all.input_type_id=input_type.id` (`input_type_id`),
  ADD KEY `verifiedBy` (`verifiedBy`),
  ADD KEY `tht_bom_id` (`tht_bom_id`),
  ADD KEY `transfer_group_id` (`transfer_group_id`),
  ADD KEY `cancelled_by` (`cancelled_by`),
  ADD KEY `idx_flowpin_update_session` (`flowpin_update_session_id`),
  ADD KEY `idx_flowpin_event` (`flowpin_event_id`),
  ADD KEY `idx_transfer_cancelled_timestamp` (`transfer_group_id`,`is_cancelled`,`timestamp`),
  ADD KEY `idx_cancelled_timestamp` (`is_cancelled`,`timestamp`);

--
-- Indeksy dla tabeli `inventory__transfer_groups`
--
ALTER TABLE `inventory__transfer_groups`
  ADD PRIMARY KEY (`id`),
  ADD KEY `created_by` (`created_by`),
  ADD KEY `cancelled_by` (`cancelled_by`),
  ADD KEY `idx_flowpin_update_session` (`flowpin_update_session_id`),
  ADD KEY `idx_cancelled_created` (`is_cancelled`,`created_at`);

--
-- Indeksy dla tabeli `list__laminate`
--
ALTER TABLE `list__laminate`
  ADD PRIMARY KEY (`id`);

--
-- Indeksy dla tabeli `list__parts`
--
ALTER TABLE `list__parts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `restrict_part_group` (`PartGroup`),
  ADD KEY `restrict_part_type` (`PartType`),
  ADD KEY `restrict_part_unit` (`JM`);

--
-- Indeksy dla tabeli `list__sku`
--
ALTER TABLE `list__sku`
  ADD PRIMARY KEY (`id`);

--
-- Indeksy dla tabeli `list__smd`
--
ALTER TABLE `list__smd`
  ADD PRIMARY KEY (`id`);

--
-- Indeksy dla tabeli `list__tht`
--
ALTER TABLE `list__tht`
  ADD PRIMARY KEY (`id`);

--
-- Indeksy dla tabeli `lowstock__parts`
--
ALTER TABLE `lowstock__parts`
  ADD PRIMARY KEY (`parts_id`,`sub_magazine_id`);

--
-- Indeksy dla tabeli `lowstock__sku`
--
ALTER TABLE `lowstock__sku`
  ADD PRIMARY KEY (`sku_id`,`sub_magazine_id`);

--
-- Indeksy dla tabeli `lowstock__smd`
--
ALTER TABLE `lowstock__smd`
  ADD PRIMARY KEY (`smd_id`,`sub_magazine_id`);

--
-- Indeksy dla tabeli `lowstock__tht`
--
ALTER TABLE `lowstock__tht`
  ADD PRIMARY KEY (`tht_id`,`sub_magazine_id`);

--
-- Indeksy dla tabeli `magazine__list`
--
ALTER TABLE `magazine__list`
  ADD PRIMARY KEY (`sub_magazine_id`),
  ADD KEY `type_id` (`type_id`);

--
-- Indeksy dla tabeli `magazine__type`
--
ALTER TABLE `magazine__type`
  ADD PRIMARY KEY (`id`);

--
-- Indeksy dla tabeli `notification__action_needed`
--
ALTER TABLE `notification__action_needed`
  ADD PRIMARY KEY (`id`);

--
-- Indeksy dla tabeli `notification__flowpin_query_type`
--
ALTER TABLE `notification__flowpin_query_type`
  ADD PRIMARY KEY (`id`);

--
-- Indeksy dla tabeli `notification__list`
--
ALTER TABLE `notification__list`
  ADD PRIMARY KEY (`id`),
  ADD KEY `action_needed_id` (`action_needed_id`);

--
-- Indeksy dla tabeli `notification__queries_affected`
--
ALTER TABLE `notification__queries_affected`
  ADD PRIMARY KEY (`id`),
  ADD KEY `flowpin_query_type_id` (`flowpin_query_type_id`),
  ADD KEY `notification_id` (`notification_id`);

--
-- Indeksy dla tabeli `notification__receivers`
--
ALTER TABLE `notification__receivers`
  ADD PRIMARY KEY (`notification_id`,`user_id`);

--
-- Indeksy dla tabeli `part__group`
--
ALTER TABLE `part__group`
  ADD PRIMARY KEY (`id`);

--
-- Indeksy dla tabeli `part__type`
--
ALTER TABLE `part__type`
  ADD PRIMARY KEY (`id`);

--
-- Indeksy dla tabeli `part__unit`
--
ALTER TABLE `part__unit`
  ADD PRIMARY KEY (`id`);

--
-- Indeksy dla tabeli `ref__flowpin_checkpoints`
--
ALTER TABLE `ref__flowpin_checkpoints`
  ADD PRIMARY KEY (`operation_type`);

--
-- Indeksy dla tabeli `ref__flowpin_update_progress`
--
ALTER TABLE `ref__flowpin_update_progress`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `session_id` (`session_id`);

--
-- Indeksy dla tabeli `ref__package_exclude`
--
ALTER TABLE `ref__package_exclude`
  ADD PRIMARY KEY (`id`);

--
-- Indeksy dla tabeli `ref__timestamp`
--
ALTER TABLE `ref__timestamp`
  ADD PRIMARY KEY (`id`);

--
-- Indeksy dla tabeli `ref__transfer_group_types`
--
ALTER TABLE `ref__transfer_group_types`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `slug` (`slug`);

--
-- Indeksy dla tabeli `ref__valuepackage`
--
ALTER TABLE `ref__valuepackage`
  ADD PRIMARY KEY (`id`),
  ADD KEY `tht_id` (`tht_id`),
  ADD KEY `parts_id` (`parts_id`);

--
-- Indeksy dla tabeli `used__sku`
--
ALTER TABLE `used__sku`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_tht_used.user_id=user.user_id` (`user_id`),
  ADD KEY `user_tht_used.tht_id=bom_tht_list.id` (`sku_id`);

--
-- Indeksy dla tabeli `used__smd`
--
ALTER TABLE `used__smd`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_tht_used.user_id=user.user_id` (`user_id`),
  ADD KEY `user_tht_used.tht_id=bom_tht_list.id` (`smd_id`);

--
-- Indeksy dla tabeli `used__tht`
--
ALTER TABLE `used__tht`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `tht_id` (`tht_id`);

--
-- Indeksy dla tabeli `user`
--
ALTER TABLE `user`
  ADD PRIMARY KEY (`user_id`),
  ADD KEY `restrict` (`sub_magazine_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `bom__flat`
--
ALTER TABLE `bom__flat`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `bom__sku`
--
ALTER TABLE `bom__sku`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `bom__smd`
--
ALTER TABLE `bom__smd`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `bom__tht`
--
ALTER TABLE `bom__tht`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `commission__list`
--
ALTER TABLE `commission__list`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `google_oauth`
--
ALTER TABLE `google_oauth`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `group__contractors`
--
ALTER TABLE `group__contractors`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `group__list`
--
ALTER TABLE `group__list`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `inventory__input_type`
--
ALTER TABLE `inventory__input_type`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `inventory__parts`
--
ALTER TABLE `inventory__parts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `inventory__sku`
--
ALTER TABLE `inventory__sku`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `inventory__smd`
--
ALTER TABLE `inventory__smd`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `inventory__tht`
--
ALTER TABLE `inventory__tht`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `inventory__transfer_groups`
--
ALTER TABLE `inventory__transfer_groups`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `list__laminate`
--
ALTER TABLE `list__laminate`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `list__parts`
--
ALTER TABLE `list__parts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `list__sku`
--
ALTER TABLE `list__sku`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `list__smd`
--
ALTER TABLE `list__smd`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `list__tht`
--
ALTER TABLE `list__tht`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `magazine__list`
--
ALTER TABLE `magazine__list`
  MODIFY `sub_magazine_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `magazine__type`
--
ALTER TABLE `magazine__type`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `notification__action_needed`
--
ALTER TABLE `notification__action_needed`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `notification__flowpin_query_type`
--
ALTER TABLE `notification__flowpin_query_type`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `notification__list`
--
ALTER TABLE `notification__list`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `notification__queries_affected`
--
ALTER TABLE `notification__queries_affected`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `part__group`
--
ALTER TABLE `part__group`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `part__type`
--
ALTER TABLE `part__type`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `part__unit`
--
ALTER TABLE `part__unit`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `ref__flowpin_update_progress`
--
ALTER TABLE `ref__flowpin_update_progress`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `ref__package_exclude`
--
ALTER TABLE `ref__package_exclude`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `ref__timestamp`
--
ALTER TABLE `ref__timestamp`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `ref__transfer_group_types`
--
ALTER TABLE `ref__transfer_group_types`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `ref__valuepackage`
--
ALTER TABLE `ref__valuepackage`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `used__sku`
--
ALTER TABLE `used__sku`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `used__smd`
--
ALTER TABLE `used__smd`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `used__tht`
--
ALTER TABLE `used__tht`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `user`
--
ALTER TABLE `user`
  MODIFY `user_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `bom__flat`
--
ALTER TABLE `bom__flat`
  ADD CONSTRAINT `bom__flat_ibfk_1` FOREIGN KEY (`parts_id`) REFERENCES `list__parts` (`id`),
  ADD CONSTRAINT `bom__flat_ibfk_2` FOREIGN KEY (`smd_id`) REFERENCES `list__smd` (`id`),
  ADD CONSTRAINT `bom__flat_ibfk_3` FOREIGN KEY (`tht_id`) REFERENCES `list__tht` (`id`),
  ADD CONSTRAINT `bom__flat_ibfk_4` FOREIGN KEY (`bom_smd_id`) REFERENCES `bom__smd` (`id`),
  ADD CONSTRAINT `bom__flat_ibfk_5` FOREIGN KEY (`bom_tht_id`) REFERENCES `bom__tht` (`id`),
  ADD CONSTRAINT `bom__flat_ibfk_6` FOREIGN KEY (`bom_sku_id`) REFERENCES `bom__sku` (`id`),
  ADD CONSTRAINT `bom__flat_ibfk_7` FOREIGN KEY (`sku_id`) REFERENCES `list__sku` (`id`);

--
-- Constraints for table `bom__sku`
--
ALTER TABLE `bom__sku`
  ADD CONSTRAINT `bom__sku_ibfk_1` FOREIGN KEY (`sku_id`) REFERENCES `list__sku` (`id`);

--
-- Constraints for table `bom__smd`
--
ALTER TABLE `bom__smd`
  ADD CONSTRAINT `bom__smd_ibfk_1` FOREIGN KEY (`smd_id`) REFERENCES `list__smd` (`id`),
  ADD CONSTRAINT `bom__smd_ibfk_2` FOREIGN KEY (`laminate_id`) REFERENCES `list__laminate` (`id`);

--
-- Constraints for table `bom__tht`
--
ALTER TABLE `bom__tht`
  ADD CONSTRAINT `bom__tht_ibfk_1` FOREIGN KEY (`tht_id`) REFERENCES `list__tht` (`id`);

--
-- Constraints for table `commission__list`
--
ALTER TABLE `commission__list`
  ADD CONSTRAINT `commission__list_ibfk_1` FOREIGN KEY (`transfer_group_id`) REFERENCES `inventory__transfer_groups` (`id`),
  ADD CONSTRAINT `commission__list_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `user` (`user_id`),
  ADD CONSTRAINT `commission__list_ibfk_3` FOREIGN KEY (`cancelled_by`) REFERENCES `user` (`user_id`),
  ADD CONSTRAINT `commission__list_ibfk_4` FOREIGN KEY (`warehouse_from_id`) REFERENCES `magazine__list` (`sub_magazine_id`),
  ADD CONSTRAINT `commission__list_ibfk_5` FOREIGN KEY (`warehouse_to_id`) REFERENCES `magazine__list` (`sub_magazine_id`);

--
-- Constraints for table `commission__receivers`
--
ALTER TABLE `commission__receivers`
  ADD CONSTRAINT `commission__receivers_ibfk_1` FOREIGN KEY (`commission_id`) REFERENCES `commission__list` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `commission__receivers_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `user` (`user_id`) ON DELETE CASCADE;

--
-- Constraints for table `group__contractors`
--
ALTER TABLE `group__contractors`
  ADD CONSTRAINT `group__contractors_ibfk_1` FOREIGN KEY (`contractor_id`) REFERENCES `user` (`user_id`),
  ADD CONSTRAINT `group__contractors_ibfk_2` FOREIGN KEY (`group_id`) REFERENCES `group__list` (`id`);

--
-- Constraints for table `inventory__parts`
--
ALTER TABLE `inventory__parts`
  ADD CONSTRAINT `fk_inv_parts_cancelled_by` FOREIGN KEY (`cancelled_by`) REFERENCES `user` (`user_id`),
  ADD CONSTRAINT `fk_inv_parts_flowpin_session` FOREIGN KEY (`flowpin_update_session_id`) REFERENCES `ref__flowpin_update_progress` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_inv_parts_transfer_group` FOREIGN KEY (`transfer_group_id`) REFERENCES `inventory__transfer_groups` (`id`),
  ADD CONSTRAINT `inventory__parts_ibfk_1` FOREIGN KEY (`verifiedBy`) REFERENCES `user` (`user_id`),
  ADD CONSTRAINT `mag_parts_archive_all.input_type_id=input_type.id` FOREIGN KEY (`input_type_id`) REFERENCES `inventory__input_type` (`id`),
  ADD CONSTRAINT `mag_parts_archive_all.parts_id=bom_parts_list.id` FOREIGN KEY (`parts_id`) REFERENCES `list__parts` (`id`),
  ADD CONSTRAINT `mag_parts_archive_all.sub_magazine_id=sub_magazine_list.id` FOREIGN KEY (`sub_magazine_id`) REFERENCES `magazine__list` (`sub_magazine_id`);

--
-- Constraints for table `inventory__sku`
--
ALTER TABLE `inventory__sku`
  ADD CONSTRAINT `fk_inv_sku_cancelled_by` FOREIGN KEY (`cancelled_by`) REFERENCES `user` (`user_id`),
  ADD CONSTRAINT `fk_inv_sku_flowpin_session` FOREIGN KEY (`flowpin_update_session_id`) REFERENCES `ref__flowpin_update_progress` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_inv_sku_transfer_group` FOREIGN KEY (`transfer_group_id`) REFERENCES `inventory__transfer_groups` (`id`),
  ADD CONSTRAINT `inventory__sku_ibfk_1` FOREIGN KEY (`commission_id`) REFERENCES `commission__list` (`id`),
  ADD CONSTRAINT `inventory__sku_ibfk_11` FOREIGN KEY (`verifiedBy`) REFERENCES `user` (`user_id`),
  ADD CONSTRAINT `inventory__sku_ibfk_12` FOREIGN KEY (`verifiedBy`) REFERENCES `user` (`user_id`),
  ADD CONSTRAINT `inventory__sku_ibfk_13` FOREIGN KEY (`sku_bom_id`) REFERENCES `bom__sku` (`id`),
  ADD CONSTRAINT `inventory__sku_ibfk_2` FOREIGN KEY (`sku_id`) REFERENCES `list__sku` (`id`),
  ADD CONSTRAINT `inventory__sku_ibfk_3` FOREIGN KEY (`input_type_id`) REFERENCES `inventory__input_type` (`id`),
  ADD CONSTRAINT `inventory__sku_ibfk_4` FOREIGN KEY (`sub_magazine_id`) REFERENCES `magazine__list` (`sub_magazine_id`),
  ADD CONSTRAINT `inventory__sku_ibfk_6` FOREIGN KEY (`commission_id`) REFERENCES `commission__list` (`id`),
  ADD CONSTRAINT `inventory__sku_ibfk_7` FOREIGN KEY (`sku_id`) REFERENCES `list__sku` (`id`),
  ADD CONSTRAINT `inventory__sku_ibfk_8` FOREIGN KEY (`input_type_id`) REFERENCES `inventory__input_type` (`id`),
  ADD CONSTRAINT `inventory__sku_ibfk_9` FOREIGN KEY (`sub_magazine_id`) REFERENCES `magazine__list` (`sub_magazine_id`);

--
-- Constraints for table `inventory__smd`
--
ALTER TABLE `inventory__smd`
  ADD CONSTRAINT `fk_inv_smd_cancelled_by` FOREIGN KEY (`cancelled_by`) REFERENCES `user` (`user_id`),
  ADD CONSTRAINT `fk_inv_smd_flowpin_session` FOREIGN KEY (`flowpin_update_session_id`) REFERENCES `ref__flowpin_update_progress` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_inv_smd_transfer_group` FOREIGN KEY (`transfer_group_id`) REFERENCES `inventory__transfer_groups` (`id`),
  ADD CONSTRAINT `inventory__smd_ibfk_1` FOREIGN KEY (`verifiedBy`) REFERENCES `user` (`user_id`),
  ADD CONSTRAINT `inventory__smd_ibfk_2` FOREIGN KEY (`smd_bom_id`) REFERENCES `bom__smd` (`id`),
  ADD CONSTRAINT `mag_smd_archive_all.input_type_id=input_type.id` FOREIGN KEY (`input_type_id`) REFERENCES `inventory__input_type` (`id`),
  ADD CONSTRAINT `mag_smd_archive_all.smd_id=smd_list.id` FOREIGN KEY (`smd_id`) REFERENCES `list__smd` (`id`),
  ADD CONSTRAINT `mag_smd_archive_all.sub_magazine_id = sub_magazine_list.id` FOREIGN KEY (`sub_magazine_id`) REFERENCES `magazine__list` (`sub_magazine_id`);

--
-- Constraints for table `inventory__tht`
--
ALTER TABLE `inventory__tht`
  ADD CONSTRAINT `fk_inv_tht_cancelled_by` FOREIGN KEY (`cancelled_by`) REFERENCES `user` (`user_id`),
  ADD CONSTRAINT `fk_inv_tht_flowpin_session` FOREIGN KEY (`flowpin_update_session_id`) REFERENCES `ref__flowpin_update_progress` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_inv_tht_transfer_group` FOREIGN KEY (`transfer_group_id`) REFERENCES `inventory__transfer_groups` (`id`),
  ADD CONSTRAINT `inventory__tht_ibfk_1` FOREIGN KEY (`verifiedBy`) REFERENCES `user` (`user_id`),
  ADD CONSTRAINT `inventory__tht_ibfk_2` FOREIGN KEY (`verifiedBy`) REFERENCES `user` (`user_id`),
  ADD CONSTRAINT `inventory__tht_ibfk_3` FOREIGN KEY (`tht_bom_id`) REFERENCES `bom__tht` (`id`),
  ADD CONSTRAINT `mag_tht_archive_all.input_type_id=input_type.id` FOREIGN KEY (`input_type_id`) REFERENCES `inventory__input_type` (`id`),
  ADD CONSTRAINT `mag_tht_archive_all.sub_magazine_id=sub_magazine_list.id` FOREIGN KEY (`sub_magazine_id`) REFERENCES `magazine__list` (`sub_magazine_id`),
  ADD CONSTRAINT `mag_tht_archive_all.tht_id=tht_list.id` FOREIGN KEY (`tht_id`) REFERENCES `list__tht` (`id`);

--
-- Constraints for table `inventory__transfer_groups`
--
ALTER TABLE `inventory__transfer_groups`
  ADD CONSTRAINT `fk_transfer_group_flowpin_session` FOREIGN KEY (`flowpin_update_session_id`) REFERENCES `ref__flowpin_update_progress` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_transfer_groups_cancelled_by` FOREIGN KEY (`cancelled_by`) REFERENCES `user` (`user_id`),
  ADD CONSTRAINT `fk_transfer_groups_created_by` FOREIGN KEY (`created_by`) REFERENCES `user` (`user_id`);

--
-- Constraints for table `list__parts`
--
ALTER TABLE `list__parts`
  ADD CONSTRAINT `restrict_part_group` FOREIGN KEY (`PartGroup`) REFERENCES `part__group` (`id`),
  ADD CONSTRAINT `restrict_part_type` FOREIGN KEY (`PartType`) REFERENCES `part__type` (`id`),
  ADD CONSTRAINT `restrict_part_unit` FOREIGN KEY (`JM`) REFERENCES `part__unit` (`id`);

--
-- Constraints for table `magazine__list`
--
ALTER TABLE `magazine__list`
  ADD CONSTRAINT `magazine__list_ibfk_1` FOREIGN KEY (`type_id`) REFERENCES `magazine__type` (`id`);

--
-- Constraints for table `notification__list`
--
ALTER TABLE `notification__list`
  ADD CONSTRAINT `notification__list_ibfk_1` FOREIGN KEY (`action_needed_id`) REFERENCES `notification__action_needed` (`id`);

--
-- Constraints for table `notification__queries_affected`
--
ALTER TABLE `notification__queries_affected`
  ADD CONSTRAINT `notification__queries_affected_ibfk_1` FOREIGN KEY (`flowpin_query_type_id`) REFERENCES `notification__flowpin_query_type` (`id`),
  ADD CONSTRAINT `notification__queries_affected_ibfk_2` FOREIGN KEY (`notification_id`) REFERENCES `notification__list` (`id`);

--
-- Constraints for table `ref__valuepackage`
--
ALTER TABLE `ref__valuepackage`
  ADD CONSTRAINT `ref__valuepackage_ibfk_1` FOREIGN KEY (`tht_id`) REFERENCES `list__tht` (`id`),
  ADD CONSTRAINT `ref__valuepackage_ibfk_2` FOREIGN KEY (`parts_id`) REFERENCES `list__parts` (`id`);

--
-- Constraints for table `used__sku`
--
ALTER TABLE `used__sku`
  ADD CONSTRAINT `user_sku_used.sku_id=bom_sku_list.id` FOREIGN KEY (`sku_id`) REFERENCES `list__sku` (`id`),
  ADD CONSTRAINT `user_sku_used.user_id=user.user_id` FOREIGN KEY (`user_id`) REFERENCES `user` (`user_id`);

--
-- Constraints for table `used__smd`
--
ALTER TABLE `used__smd`
  ADD CONSTRAINT `used__smd_ibfk_1` FOREIGN KEY (`smd_id`) REFERENCES `list__smd` (`id`),
  ADD CONSTRAINT `used__smd_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `user` (`user_id`);

--
-- Constraints for table `used__tht`
--
ALTER TABLE `used__tht`
  ADD CONSTRAINT `used__tht_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `user` (`user_id`),
  ADD CONSTRAINT `used__tht_ibfk_2` FOREIGN KEY (`tht_id`) REFERENCES `list__tht` (`id`);

--
-- Constraints for table `user`
--
ALTER TABLE `user`
  ADD CONSTRAINT `restrict` FOREIGN KEY (`sub_magazine_id`) REFERENCES `magazine__list` (`sub_magazine_id`) ON DELETE NO ACTION ON UPDATE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
