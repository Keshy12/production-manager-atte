# ATTE Production Manager — Database Reference

---

## 1. Database Topology

The application uses **four separate database connections**, all accessed via PDO through a shared `BaseDB` superclass.

| Class | DB Name | Purpose |
|---|---|---|
| `BaseDB` | *(abstract)* | BaseDB is abstract; `MsaDB` extends it for `atte_ms` — all core tables: inventory, BOMs, commissions, users, magazines. |
| `FlowpinDB` | External FlowPin MS SQL | External production/sales tracking system. Provides warehouse data and user accounts synced from FlowPin. |
| `IbiznesDB` | External iBiznes | iBiznes ERP/external integration (structure not defined in this schema). |
| `MsaDB` | MSA database | Master System Adapter — wraps `atte_ms` with repository helpers (`readIdName`, etc.) and is the primary DB used by domain repositories. |

All three subclasses (`FlowpinDB`, `IbiznesDB`, `MsaDB`) extend `BaseDB`, which provides `query()`, `insert()`, `insertBulk()`, `update()`, `deleteById()` via PDO prepared statements. FlowpinDB, IbiznesDB, and MsaDB each use the **Singleton pattern** (`getInstance()`).

---

## 2. Connection Config

Credentials are loaded from `.env` via `vlucas/dotenv` into `$_ENV` at runtime.

```php
// BaseDB (class-basedb.php) — uses $this->dbUrl / $this->dbUsername / $this->dbPassword
// set by each subclass constructor before calling parent::__construct()

// FlowpinDB — env vars:
FLOWPINURL, FLOWPINUSERNAME, FLOWPINPASSWORD

// IbiznesDB — env vars:
IBIZNESURL, IBIZNESUSERNAME, IBIZNESPASSWORD

// MsaDB — env vars:
MSAURL, MSAUSERNAME, MSAPASSWORD
```

`BaseDB` constructor uses `Dotenv\Dotenv::createImmutable(ROOT_DIRECTORY)` to load the `.env` file. Connection is established via PDO:

```php
$conn = new PDO($this->dbUrl, $this->dbUsername, $this->dbPassword);
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
```

---

## 3. Naming Conventions

Table names follow a `prefix__suffix` double-underscore convention (except `user`).

| Prefix | Purpose | Examples |
|---|---|---|
| `inventory__*` | Material/item stock movement ledger | `inventory__parts`, `inventory__smd`, `inventory__transfer_groups` |
| `list__*` | Master data / reference lists | `list__parts`, `list__smd`, `list__tht`, `list__sku`, `list__laminate` |
| `bom__*` | Bill of Materials | `bom__smd`, `bom__tht`, `bom__sku`, `bom__flat` |
| `commission__*` | Production orders | `commission__list`, `commission__receivers` |
| `user` | Authentication & users | `user` |
| `magazine__*` | Warehouses / storage locations | `magazine__list`, `magazine__type` |
| `lowstock__*` | Auto-populated low-stock alerts | `lowstock__parts`, `lowstock__smd`, `lowstock__tht`, `lowstock__sku` |
| `notification__*` | System notifications | `notification__list`, `notification__receivers` |
| `ref__*` | Reference / meta tables | `ref__flowpin_update_progress`, `ref__transfer_group_types` |
| `part__*` | Part classification | `part__group`, `part__type`, `part__unit` |
| `purchase__*` | Procurement (RFQ / PO / receipt lifecycle) | `purchase__rfq`, `purchase__rfq_item`, `purchase__order`, `purchase__order_item`, `purchase__order_receipt`, `purchase__order_receipt_item`, `purchase__number_counter` |
| `used__*` | Per-user device usage tracking | `used__smd`, `used__tht`, `used__sku` |
| `group__*` | User groups / contractors | `group__list`, `group__contractors` |
| `google_oauth` | OAuth tokens | `google_oauth` |

---

## 4. Schema Reference

### 4a. `inventory__*` — Stock Movement Ledger

Each `inventory__*` table is a **transfer/transaction ledger row** for a specific device type. All four share the same column shape, plus a `*_bom_id` FK on SKU/SMD/THT variants. Rows are created when stock moves into or out of a sub-magazine.

| Table | Key Columns | Purpose |
|---|---|---|
| `inventory__parts` | `id`, `parts_id`, `sub_magazine_id`, `qty` (decimal), `commission_id`, `transfer_group_id`, `input_type_id`, `is_cancelled`, `cancelled_at`, `cancelled_by`, `timestamp`, `production_date`, `comment`, `isVerified`, `verifiedBy`, `flowpin_update_session_id`, `flowpin_event_id` | Ledger entry for base component stock. Links to `list__parts`. |
| `inventory__sku` | `id`, `sku_id`, `sku_bom_id`, `sub_magazine_id`, `qty`, `commission_id`, `transfer_group_id`, `input_type_id`, `is_cancelled`, `cancelled_at`, `cancelled_by`, `timestamp`, `production_date`, `isVerified`, `verifiedBy` | Ledger entry for SKU (finished product) stock. |
| `inventory__smd` | `id`, `smd_id`, `smd_bom_id`, `sub_magazine_id`, `qty`, `commission_id`, `transfer_group_id`, `input_type_id`, `is_cancelled`, `cancelled_at`, `cancelled_by`, `timestamp`, `production_date`, `isVerified`, `verifiedBy` | Ledger entry for SMD device stock. |
| `inventory__tht` | `id`, `tht_id`, `tht_bom_id`, `sub_magazine_id`, `qty`, `commission_id`, `transfer_group_id`, `input_type_id`, `is_cancelled`, `cancelled_at`, `cancelled_by`, `timestamp`, `production_date`, `isVerified`, `verifiedBy` | Ledger entry for THT device stock. |
| `inventory__input_type` | `id`, `name` | Defines the type of stock movement (e.g. "production in", "transfer", "return"). |

> **Audit-only columns:** `isVerified` and `verifiedBy` appear on all four `inventory__*` tables above. The `/verification` admin QC workflow was retired; these columns remain in the schema as inert historical records. Producers may still write `isVerified = 0` for new rows, but no UI transitions them to `1`. See `MODULES.md` for the workflow change history.
| `inventory__transfer_groups` | `id`, `created_by`, `type_id`, `params`, `is_cancelled`, `cancelled_at`, `cancelled_by`, `created_at`, `flowpin_update_session_id` | Groups multiple `inventory__*` rows into a single atomic transfer operation. |

```sql
CREATE TABLE `inventory__parts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `parts_id` int(11) NOT NULL,
  `commission_id` int(11) DEFAULT NULL,
  `sub_magazine_id` int(11) NOT NULL,
  `qty` decimal(30,10) NOT NULL,           -- positive = stock in, negative = stock out
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
  `flowpin_update_session_id` int(11) DEFAULT NULL,
  `flowpin_event_id` bigint(20) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `parts_id` (`parts_id`),
  KEY `sub_magazine_id` (`sub_magazine_id`),
  KEY `transfer_group_id` (`transfer_group_id`),
  KEY `commission_id` (`commission_id`)
) ENGINE=InnoDB;
```

**Note:** The `qty` column uses `decimal(30,10)` to allow fractional quantities (e.g. for raw materials). Positive values represent stock additions; negative values represent stock deductions.

---

### 4b. `list__*` — Master Data (Device/Component Catalogs)

| Table | Key Columns | Purpose |
|---|---|---|
| `list__parts` | `id`, `name`, `description`, `PartGroup`, `PartType`, `JM` (unit), `isActive`, `price` | Master list of base components (raw materials / individual parts). |
| `list__sku` | `id`, `name`, `description`, `isActive`, `isAutoProduced`, `autoProduceVersion` | SKU (Stock Keeping Unit) — finished or tracked product. |
| `list__smd` | `id`, `name`, `description`, `isActive`, `default_bom_id` | SMD (Surface Mount Device) — electronic components. |
| `list__tht` | `id`, `name`, `description`, `circle_checked`, `triangle_checked`, `square_checked`, `isActive`, `isAutoProduced`, `autoProduceVersion`, `default_bom_id` | THT (Through-Hole Technology) — electronic components with lead-form factor flags. |
| `list__laminate` | `id`, `name`, `description`, `isActive` | Laminate materials used in SMD BOMs. |
| `list__vendor` | `id`, `name`, `address`, `additional_data`, `lead_time_days`, `is_active`, `comment`, `created_at`, `updated_at` | Vendor (dostawca) — supplier we buy components from. Procurement module. |
| `list__vendor_supplier` | `id`, `vendor_id` (FK), `name`, `job_title`, `phone`, `email`, `is_active`, `comment` | Vendor contact person (1-to-many → `list__vendor`). Procurement module. |
| `list__producer` | `id`, `name`, `is_active`, `comment` | Component manufacturer (producent). Procurement module. |
| `list__vendor_part` | `id`, `vendor_id` (FK), `producer_id` (FK), `parts_id` (FK→`list__parts`), `vendor_part_no`, `vendor_jm_id` (FK→`part__unit`), `is_active`, `comment`, `created_at`, `updated_at` | Vendor Part — catalog row for the (vendor × producer × part) triple. Unique on `(vendor_id, vendor_part_no)`. Pack sizes live in `list__vendor_part_pack`. Procurement module. |
| `list__vendor_part_pack` | `id`, `vendor_part_id` (FK→`list__vendor_part` ON DELETE CASCADE), `full_pack_quantity` DECIMAL(30,10), `created_at` | One row per pack size offered for a Vendor Part (e.g. `100`, `1000`, `5000` for the same variant). Unique on `(vendor_part_id, full_pack_quantity)`. Empty sheet cell = no rows. Procurement module. |

Supporting classification tables:
| Table | Key Columns | Purpose |
|---|---|---|
| `part__group` | `id`, `name` | Groups for `list__parts` (PartGroup FK). |
| `part__type` | `id`, `name` | Types for `list__parts` (PartType FK). |
| `part__unit` | `id`, `name` | Units of measure for `list__parts` (JM FK). |
| `ref__valuepackage` | `id`, `ValuePackage`, `parts_id`, `tht_id` | Value package definitions linking parts to THT. |
| `ref__package_exclude` | `id`, `name` | Package exclusion rules. |
| `ref__transfer_group_types` | `id`, `slug`, `template` | Transfer group type definitions. Unique constraint on `slug`. |

```sql
CREATE TABLE `list__parts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` text NOT NULL,
  `description` text NOT NULL,
  `PartGroup` int(11) NOT NULL,
  `PartType` int(11) DEFAULT NULL,
  `JM` int(11) NOT NULL,
  `isActive` tinyint(1) NOT NULL DEFAULT 1,
  `price` decimal(30,10) NOT NULL DEFAULT 0.0000000000,
  PRIMARY KEY (`id`),
  KEY `restrict_part_group` (`PartGroup`),
  KEY `restrict_part_type` (`PartType`),
  KEY `restrict_part_unit` (`JM`)
) ENGINE=InnoDB;
```

---

### 4c. `bom__*` — Bill of Materials

Each BOM table holds **versioned BOM definitions** for one device type. Multiple rows per device indicate different versions (v1, v2, etc.). The `isActive` flag marks the currently-used version.

| Table | Key Columns | Purpose |
|---|---|---|
| `bom__smd` | `id`, `smd_id`, `laminate_id`, `version`, `isActive`, `price` | BOM version for an SMD device. FK to `list__smd` and `list__laminate`. |
| `bom__tht` | `id`, `tht_id`, `version`, `isActive`, `out_tht_quantity`, `price` | BOM version for a THT device. FK to `list__tht`. `out_tht_quantity` tracks production output per BOM. |
| `bom__sku` | `id`, `sku_id`, `version`, `isActive`, `price` | BOM version for a SKU. FK to `list__sku`. |
| `bom__flat` | `id`, `bom_smd_id`, `bom_tht_id`, `bom_sku_id`, `smd_id`, `tht_id`, `sku_id`, `parts_id`, `quantity` | Flattened BOM — a single row per line item across all BOM types. Joins all device types + parts. |

```sql
CREATE TABLE `bom__tht` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `tht_id` int(11) NOT NULL,
  `version` text DEFAULT NULL,
  `isActive` tinyint(1) NOT NULL DEFAULT 1,
  `out_tht_quantity` decimal(30,10) NOT NULL DEFAULT 0.0000000000,
  `price` decimal(30,10) NOT NULL DEFAULT 0.0000000000,
  PRIMARY KEY (`id`),
  KEY `tht_id` (`tht_id`)
) ENGINE=InnoDB;

CREATE TABLE `bom__flat` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `bom_smd_id` int(11) DEFAULT NULL,
  `bom_tht_id` int(11) DEFAULT NULL,
  `bom_sku_id` int(11) DEFAULT NULL,
  `tht_id` int(11) DEFAULT NULL,
  `parts_id` int(11) DEFAULT NULL,
  `smd_id` int(11) DEFAULT NULL,
  `sku_id` int(11) DEFAULT NULL,
  `quantity` decimal(30,10) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `parts_id` (`parts_id`),
  KEY `smd_id` (`smd_id`),
  KEY `tht_id` (`tht_id`),
  KEY `bom_smd_id` (`bom_smd_id`),
  KEY `bom_tht_id` (`bom_tht_id`),
  KEY `bom_sku_id` (`bom_sku_id`)
) ENGINE=InnoDB;
```

---

### 4d. `commission__*` — Production Orders

| Table | Key Columns | Purpose |
|---|---|---|
| `commission__list` | `id`, `created_by`, `warehouse_from_id`, `warehouse_to_id`, `device_type` (enum: sku/tht/smd), `bom_id`, `qty`, `qty_produced`, `qty_returned`, `state` (enum: active/completed/returned/cancelled), `priority` (enum: none/standard/urgent/critical), `is_cancelled`, `cancelled_at`, `cancelled_by`, `transfer_group_id`, `created_at`, `updated_at` | Production order. `device_type` determines whether `bom_id` references `bom__sku`, `bom__tht`, or `bom__smd`. |
| `commission__receivers` | `commission_id`, `user_id` | Many-to-many: users assigned to receive notifications for a commission. PK is composite (`commission_id`, `user_id`). |

```sql
CREATE TABLE `commission__list` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
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
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `transfer_group_id` (`transfer_group_id`),
  KEY `created_by` (`created_by`),
  KEY `idx_bom_id` (`bom_id`),
  KEY `idx_state_cancelled` (`state`,`is_cancelled`)
) ENGINE=InnoDB;
```

---

### 4e. `user` — Authentication & Users

| Table | Key Columns | Purpose |
|---|---|---|
| `user` | `user_id`, `login`, `password` (hashed), `name`, `surname`, `email`, `isAdmin`, `isActive`, `sub_magazine_id` | Application user. `sub_magazine_id` FK to `magazine__list`. |

```sql
CREATE TABLE `user` (
  `user_id` int(11) NOT NULL AUTO_INCREMENT,
  `login` text NOT NULL,
  `password` text NOT NULL,
  `name` text NOT NULL,
  `surname` text NOT NULL,
  `email` text NOT NULL,
  `isAdmin` tinyint(1) NOT NULL,
  `isActive` tinyint(1) NOT NULL DEFAULT 1,
  `sub_magazine_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`user_id`),
  KEY `restrict` (`sub_magazine_id`)
) ENGINE=InnoDB;
```

---

### 4f. `magazine__*` — Warehouses / Storage Locations

| Table | Key Columns | Purpose |
|---|---|---|
| `magazine__list` | `sub_magazine_id`, `sub_magazine_name`, `type_id`, `isActive` | Physical or logical storage location (warehouse / sub-magazine). PK is `sub_magazine_id` (not `id`). |
| `magazine__type` | `id`, `name` | Type classification for magazines (e.g. "raw material warehouse", "finished goods"). |

---

### 4g. `lowstock__*` — Automatic Low-Stock Alerts

| Table | Key Columns | Purpose |
|---|---|---|
| `lowstock__parts` | `parts_id`, `sub_magazine_id`, `total_quantity` (int(11)) | **Auto-populated by trigger.** Rows exist only when total quantity for a `(parts_id, sub_magazine_id)` pair goes **below zero**. |
| `lowstock__sku` | `sku_id`, `sub_magazine_id`, `total_quantity` (int(11)) | Same pattern for SKUs. |
| `lowstock__smd` | `smd_id`, `sub_magazine_id`, `total_quantity` (int(11)) | Same pattern for SMDs. |
| `lowstock__tht` | `tht_id`, `sub_magazine_id`, `total_quantity` (int(11)) | Same pattern for THTs. |

All four have composite PK `(device_id, sub_magazine_id)` (device_id = `parts_id`/`sku_id`/`smd_id`/`tht_id` respectively). These tables are **populated exclusively by triggers** — no direct inserts.

---

### 4h. `notification__*` — System Notifications

| Table | Key Columns | Purpose |
|---|---|---|
| `notification__action_needed` | `id`, `description` | Describes the required action for a notification (e.g. "approve transfer", "resolve discrepancy"). |
| `notification__flowpin_query_type` | `id`, `description` | Classifies the type of FlowPin query that triggered a notification. |
| `notification__list` | `id`, `timestamp`, `action_needed_id`, `value_for_action`, `isResolved` | Core notification record. `action_needed_id` FK to `notification__action_needed`. |
| `notification__queries_affected` | `id`, `notification_id`, `values_to_resolve`, `exception_values_serialized`, `flowpin_query_type_id` | Captures the query values and exception details tied to a notification. |
| `notification__receivers` | `notification_id`, `user_id`, `isRead` | Many-to-many: users who should receive a given notification. PK is composite (`notification_id`, `user_id`). |

---

### 4i. `ref__*` — Reference / Meta Tables

| Table | Key Columns | Purpose |
|---|---|---|
| `ref__flowpin_checkpoints` | `operation_type` (PK), `checkpoint_event_id` | Tracks the last processed `EventID` per FlowPin operation type (e.g. `sold_sku`, `returned_sku`). PK is `operation_type` (no `id` column). |
| `ref__flowpin_update_progress` | `id`, `session_id` (UNIQUE), `total_records`, `processed_records`, `current_operation_type`, `current_event_id`, `status` (enum: pending/running/completed/error), `started_at`, `updated_at`, `starting_event_id`, `finishing_event_id`, `created_transfer_count`, `created_group_count` | Tracks the progress and state of each FlowPin sync session. |
| `ref__package_exclude` | `id`, `name` | Package exclusion rules for BOM/value-package logic. |
| `ref__timestamp` | `id`, `last_timestamp`, `name`, `params` | Generic timestamp bookmark table for external system sync state. |

### 4j. `purchase__*` — Procurement (RFQ / PO / Receipts)

Phase 2 introduces RFQ (Request For Quote) lifecycle tables. PO + receipt tables come in later phases.

> **Column-naming note (P6, 2026-08-25):** the four procurement master-data
> tables (`list__vendor`, `list__vendor_supplier`, `list__producer`,
> `list__vendor_part`) had their `is_active` column renamed to
> `isActive` to match the rest of the `list__*` family (camelCase).
> The migration is idempotent — see
> `docs/procurement/sql/P6-schema.sql`. The `purchase__*` tables use
> their own created_at / updated_at columns and were not affected.

| Table | Key Columns | Purpose |
|---|---|---|
| `purchase__number_counter` | `year` (SMALLINT), `type` ENUM('rfq','po'), `last_value` (INT) | Per-year, per-type document-number generator. Composite PK `(year, type)`. Used by `PurchaseActionHandler::allocateDocumentNumber()`. |
| `purchase__rfq` | `id`, `vendor_id` (FK→`list__vendor`), `state` ENUM('draft','sent','responded','cancelled','converted'), `rfq_number` VARCHAR(64), `expected_reply_date` DATE, `sent_at` DATETIME, `created_by` (FK→`user.user_id`), `comment` TEXT, `created_at`, `updated_at` | RFQ header. State machine: `draft → sent → responded \| cancelled \| converted`. |
| `purchase__rfq_item` | `id`, `rfq_id` (FK→`purchase__rfq` ON DELETE CASCADE), `vendor_part_id` (FK→`list__vendor_part`), `quantity` DECIMAL(30,10), `quantity_unit_id` (FK→`part__unit`), `unit_price` DECIMAL(30,10) NULL, `currency` VARCHAR(8) DEFAULT 'PLN', `comment` TEXT | RFQ line items. `unit_price` is the admin's target/expected price; nullable. |
| `purchase__order` | `id`, `vendor_id` (FK→`list__vendor`), `state` ENUM('draft','sent','confirmed','partially_received','received','cancelled'), `po_number` VARCHAR(64), `vendor_po_number` VARCHAR(64), `converted_from_rfq_id` (FK→`purchase__rfq` ON DELETE SET NULL), `expected_delivery_date` DATE, `sent_at` DATETIME, `confirmed_at` DATETIME, `created_by` (FK→`user.user_id`), `comment` TEXT, `created_at`, `updated_at` | PO header. State machine: `draft → sent → confirmed → partially_received → received`, plus terminal `cancelled`. `converted_from_rfq_id` is NULL for direct POs and set when the PO was created via RFQ conversion. |
| `purchase__order_item` | `id`, `po_id` (FK→`purchase__order` ON DELETE CASCADE), `vendor_part_id` (FK→`list__vendor_part`), `quantity` DECIMAL(30,10), `quantity_unit_id` (FK→`part__unit`), `unit_price` DECIMAL(30,10) DEFAULT 0, `currency` VARCHAR(8) DEFAULT 'PLN', `quantity_received` DECIMAL(30,10) DEFAULT 0, `comment` TEXT | PO line items. `quantity_received` is the running total written by `PurchaseActionHandler::createReceipt()` (P4). |
| `purchase__order_receipt` | `id`, `po_id` (FK→`purchase__order`), `document_number` VARCHAR(64), `received_by` (FK→`user.user_id`), `received_at` DATETIME, `comment` TEXT | Goods-receipt header (one row per delivery note / WZ-PZ). Created by `PurchaseActionHandler::createReceipt()`. |
| `purchase__order_receipt_item` | `id`, `receipt_id` (FK→`purchase__order_receipt` ON DELETE CASCADE), `po_item_id` (FK→`purchase__order_item`), `quantity_received` DECIMAL(30,10), `sub_magazine_id` (FK→`magazine__list.sub_magazine_id`), `comment` TEXT | Goods-receipt line items. `quantity_received` here is the per-receipt delta; the running total lives on `purchase__order_item.quantity_received`. |

---

## 5. Foreign Keys

Key relationships (not exhaustive — see `information_schema.REFERENTIAL_CONSTRAINTS` for the full list):

- **`inventory__parts.parts_id`** → `list__parts.id`
- **`inventory__parts.sub_magazine_id`** → `magazine__list.sub_magazine_id`
- **`inventory__parts.transfer_group_id`** → `inventory__transfer_groups.id`
- **`inventory__parts.input_type_id`** → `inventory__input_type.id`
- **`inventory__parts.cancelled_by`** → `user.user_id`
- **`inventory__parts.verifiedBy`** → `user.user_id`
- **`inventory__parts.flowpin_update_session_id`** → `ref__flowpin_update_progress.id` (ON DELETE SET NULL)
- **`inventory__sku.sku_id`** → `list__sku.id`
- **`inventory__sku.sku_bom_id`** → `bom__sku.id`
- **`inventory__smd.smd_id`** → `list__smd.id`
- **`inventory__smd.smd_bom_id`** → `bom__smd.id`
- **`inventory__tht.tht_id`** → `list__tht.id`
- **`inventory__tht.tht_bom_id`** → `bom__tht.id`
- **`commission__list.created_by`** → `user.user_id`
- **`commission__list.warehouse_from_id`** → `magazine__list.sub_magazine_id`
- **`commission__list.warehouse_to_id`** → `magazine__list.sub_magazine_id`
- **`commission__list.transfer_group_id`** → `inventory__transfer_groups.id`
- **`commission__list.cancelled_by`** → `user.user_id`
- **`commission__receivers.commission_id`** → `commission__list.id` (ON DELETE CASCADE)
- **`commission__receivers.user_id`** → `user.user_id` (ON DELETE CASCADE)
- **`bom__smd.smd_id`** → `list__smd.id`
- **`bom__smd.laminate_id`** → `list__laminate.id`
- **`bom__tht.tht_id`** → `list__tht.id`
- **`bom__sku.sku_id`** → `list__sku.id`
- **`bom__flat`** → links `bom__smd`, `bom__tht`, `bom__sku`, `list__smd`, `list__tht`, `list__sku`, `list__parts`
- **`list__parts.PartGroup`** → `part__group.id`
- **`list__parts.PartType`** → `part__type.id`
- **`list__parts.JM`** → `part__unit.id`
- **`list__smd.default_bom_id`** → `bom__smd.id`
- **`list__tht.default_bom_id`** → `bom__tht.id`
- **`user.sub_magazine_id`** → `magazine__list.sub_magazine_id` (ON DELETE NO ACTION, ON UPDATE CASCADE)
- **`magazine__list.type_id`** → `magazine__type.id`
- **`used__sku.sku_id`** → `list__sku.id`
- **`used__sku.user_id`** → `user.user_id`
- **`used__smd.smd_id`** → `list__smd.id`
- **`used__smd.user_id`** → `user.user_id`
- **`used__tht.tht_id`** → `list__tht.id`
- **`used__tht.user_id`** → `user.user_id`
- **`group__contractors.group_id`** → `group__list.id`
- **`group__contractors.contractor_id`** → `user.user_id`
- **`notification__list.action_needed_id`** → `notification__action_needed.id`
- **`notification__receivers.notification_id`** → `notification__list.id`
- **`notification__receivers.user_id`** → `user.user_id`
- **`ref__valuepackage.parts_id`** → `list__parts.id`
- **`ref__valuepackage.tht_id`** → `list__tht.id`

---

## 6. Triggers

Triggers automatically maintain the `lowstock__*` shadow tables whenever an `inventory__*` row is inserted or updated. There are **8 triggers total** — two per inventory table (after insert, after update).

**Pattern:** After every INSERT or UPDATE on `inventory__*`, the trigger sums `qty` for the same `(device_id, sub_magazine_id)` pair. If the total is negative, a row is inserted/updated in the corresponding `lowstock__*` table; otherwise the row is deleted.

```sql
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
END$$
DELIMITER ;
```

The same trigger body pattern repeats for:
- `after_inventory_parts_update` → `lowstock__parts`
- `after_inventory_sku_insert` / `after_inventory_sku_update` → `lowstock__sku`
- `after_inventory_smd_insert` / `after_inventory_smd_update` → `lowstock__smd`
- `after_inventory_tht_insert` / `after_inventory_tht_update` → `lowstock__tht`

---

## 7. Indexes

Notable indexes and constraints:

| Table | Index Type | Columns |
|---|---|---|
| `commission__receivers` | **PRIMARY KEY** (composite) | `(commission_id, user_id)` |
| `lowstock__parts` | **PRIMARY KEY** (composite) | `(parts_id, sub_magazine_id)` |
| `lowstock__sku` | **PRIMARY KEY** (composite) | `(sku_id, sub_magazine_id)` |
| `lowstock__smd` | **PRIMARY KEY** (composite) | `(smd_id, sub_magazine_id)` |
| `lowstock__tht` | **PRIMARY KEY** (composite) | `(tht_id, sub_magazine_id)` |
| `notification__receivers` | **PRIMARY KEY** (composite) | `(notification_id, user_id)` |
| `ref__flowpin_update_progress` | **UNIQUE KEY** | `session_id` |
| `ref__transfer_group_types` | **UNIQUE KEY** | `slug` |
| `commission__list` | **Composite index** | `idx_state_cancelled` on `(state, is_cancelled)` |
| `commission__list` | **Composite index** | `idx_bom_id` on `(bom_id)` |
| `inventory__parts` | Many composite indexes for transfer-group scoped queries: `idx_tg_submag`, `idx_tg_input`, `idx_tg_device`, `idx_tg_summary`, `idx_tg_cancelled_ts`, etc. |
| `inventory__sku` | Same composite index pattern as `inventory__parts` |
| `inventory__smd` | Same composite index pattern |
| `inventory__tht` | Same composite index pattern |
| `list__parts` | `idx_is_active` on `(isActive)` |
| `list__smd` | `idx_is_active` on `(isActive)` |
| `list__tht` | `idx_is_active` on `(isActive)` |
| `list__sku` | `idx_is_active` on `(isActive)` |

The inventory tables have particularly dense indexing to support fast lookups by transfer group, sub-magazine, device ID, input type, and timestamp — all commonly filtered in the production/warehouse UI.

---

## 8. Repository Pattern

Domain objects access the database through **Repository classes** wrapping `MsaDB` / `BaseDB`:

### `MagazineRepository` (`src/classes/Utils/Magazine/class-magazinerepository.php`)
Wraps `MsaDB`. Provides:
- `getMagazineById(id)` → `Magazine` object
- `getAllMagazines(onlyIsActive)` → array with type join to `magazine__type`
- `getMagazineTypes()` → uses `MsaDB::readIdName('magazine__type')`
- `createMagazine(name, typeId)` / `updateMagazine(id, name, typeId)` → uses `MsaDB::insert/update`
- `createMagazineType(name)`
- `isAssignedToUsers(id)` — checks `user.sub_magazine_id` count
- `getUsersAssignedToMagazine(magazineId)` → `User[]`
- `assignUserToMagazine(userId, magazineId)` / `toggleMagazineStatus(id, isActive)`

### `CommissionRepository` (`src/classes/Utils/Commission/class-commissionrepository.php`)
Wraps `MsaDB`. Provides:
- `getCommissionById(id)` → `Commission` domain object (instantiated with device type + values)
- `getCommissionsByIds(array $ids)` → `Commission[]`; uses `array_map('intval', $ids)` for safe int casting
- `getReceiversForCommissions(array $ids)` → `[$commissionId => [$userId, ...]]`

### `BomRepository` (`src/classes/Utils/Bom/class-bomrepository.php`)
Wraps `MsaDB`. Provides:
- `getBomById(deviceType, id)` → `Bom` object; dynamically builds column list based on type (adds `laminate_id` for smd, `out_tht_quantity` for tht)
- `getBomByValues(deviceType, $values)` → uses **prepared statements** with named params (`:columnName`, not `?`) — the safest query method in the codebase
- `createBom(deviceType, array $data)` → uses prepared statement with bound params

### `UserRepository` (`src/classes/Utils/User/class-userrepository.php`)
Wraps `BaseDB` (`MsaDB`). Provides:
- `getAllUsers()` → `User[]`, excludes inactive
- `getUserById(id)` / `getUserByEmail(email)` → `User` object or exception
- `disableUser(userId)` / `enableUser(userId)` → via `MsaDB::update()`

**Security note:** Several repository methods (e.g. `getUserById`, `getMagazineById`, `getBomById`) use direct string interpolation of IDs into SQL — `WHERE id = $id`. These are a known SQL injection risk. The exception is `getBomByValues` which properly uses PDO prepared statements (uses named placeholders, not `?`).

---

## 9. Recent Schema Changes

Summary of 2026-02-25 changes:

| Change | Table | Detail |
|---|---|---|
| Added `price` column | `list__parts` | `DECIMAL(30,10) DEFAULT 0` — base component cost |
| Added `out_tht_quantity` column | `bom__tht` | `DECIMAL(30,10) DEFAULT 0` — tracks production output qty per BOM |
| Added `price` column | `bom__tht`, `bom__sku`, `bom__smd` | `DECIMAL(30,10) DEFAULT 0` — BOM version-specific cost |
| Added `default_bom_id` | `list__tht`, `list__smd` | `INT(11) NULL` — points to default BOM version for hierarchical cost analysis |
| Auto-migration | `list__tht`, `list__smd` | Sets `default_bom_id` for devices that have exactly one active BOM version |

Summary of 2026-08 (procurement module) changes:

| Phase | Change | Tables | Detail |
|---|---|---|---|
| P1 | Added 4 master tables | `list__vendor`, `list__vendor_supplier`, `list__producer`, `list__vendor_part` | New `list__*` master data: vendors, their contact people, component manufacturers, and the (vendor × producer × part) catalog row. `is_active` column. Unique key `(vendor_id, vendor_part_no)` on `list__vendor_part`. |
| P2 | Added 2 RFQ tables + counter | `purchase__rfq`, `purchase__rfq_item`, `purchase__number_counter` | RFQ header + line items + per-year/per-type document-number generator. State machine `draft → sent → responded \| cancelled \| converted`. |
| P3 | Added 2 PO tables | `purchase__order`, `purchase__order_item` | PO header + line items. State machine `draft → sent → confirmed → partially_received → received` (+ `cancelled`). `converted_from_rfq_id` ON DELETE SET NULL. `vendor_po_number` capture. |
| P4 | Added 2 receipt tables + ref row | `purchase__order_receipt`, `purchase__order_receipt_item`, `ref__transfer_group_types` row `('purchase_receipt', 'Przyjęcie z zamówienia #{po_id}')` | Goods-receipt header + line items. Receipts create a `transfer_group` of type `purchase_receipt` and write positive `inventory__parts` rows. Lenient 110% over-delivery per line (P5 deferred). |
| P5 | Added 1 column | `list__vendor_part` | `producer_part_no VARCHAR(255) DEFAULT NULL` — producer's own catalog name, surfaced as option subtext in the Koszyk picker and the cart table sub-line. |
| P6 | Renamed 1 column × 4 tables | `list__vendor`, `list__vendor_supplier`, `list__producer`, `list__vendor_part` | `is_active` → `isActive` to match the rest of the `list__*` family. Idempotent migration in `docs/procurement/sql/P6-schema.sql`. Repository SQL aliases removed in the same commit. |

---

## 10. Security

### SQL Injection Protection

`BaseDB` provides `insert()`, `insertBulk()`, and `update()` methods that use **PDO prepared statements** with `?` placeholders. The `query()` method wraps `PDO::prepare()` + `execute()`.

Example of the safe pattern (from `insert`):
```php
$sql = "INSERT INTO $table (" . implode(", ", $columns) . ") VALUES (" . implode(", ", $questionMarkParam) . ")";
$query = $db->prepare($sql);
$query->execute($values);
```

**Known risks:** Several locations concatenate raw values directly into SQL strings, including:
- `class-basedb.php` — `update()` and `deleteById()` interpolate `$checkValue` / `$id` directly into the WHERE clause (lines 118, 130)
- `class-userrepository.php` — `getUserById()` and `getUserByEmail()` interpolate `$id` / `$email` directly (lines 32, 44)
- `login.php` — direct concatenation of username/password into SQL

**Recommendation:** All DB access should route through prepared statements.

### Password Hashing

The `user.password` column stores SHA-256 hashes. The README notes this is a known weakness — modern PHP should use `password_hash()` / `password_verify()`.

---

**Next up:** [MODULES.md](../code/MODULES.md) — Per-module guide; what's in each of the 19 module directories.

---

*Generated from DB class sources + schema inspection. All table definitions reflect the current schema as of the latest MySQL dump.*
