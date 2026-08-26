# Plan — Procurement Module (Zakupy / Dostawcy)

**Status:** Shipped v1.6 — `feature/component-procurement` carries the
working end-to-end procurement flow (P1–P4 from §10): vendor /
producer / VendorPart master data, RFQ + PO lifecycle, goods
receipts wired into the inventory ledger. The Koszyk (cart) UX
on `/admin/purchase/cart` is the entry point — it queues items per
vendor and either creates an RFQ or a PO at submit.

**Branch:** `feature/component-procurement`
**Owner:** TBD
**Last cleanup:** 2026-08-25 — v1.6 reflects the cart-style entry
point (not the original plan's "Reorder" screen), the 2026-08-22
file-system restructure (cart/Receipts/Documents moved under
`components/purchases/`; Rfqs/Orders list + Edit + Receive pages
removed — combined RFQ+PO table is the future placeholder at
`/admin/purchase/documents`), the P6 `is_active` → `isActive`
column rename, and the audit-driven refactor (drop unused
`$MsaDB` from 9 entities; unify duplicate `vp-add` endpoints;
race-safe `vp-update`; Locker on the Sheets importer; inline styles
extracted to `purchases.css`).

The next time you change something here, also update:
- `docs/data/DATABASE.md` for schema,
- `docs/code/MODULES.md` for file map,
- `docs/code/CLASSES.md` for the new `Atte\Utils\Purchase\…` classes,
- `docs/operations/CRON.md` for `import-vendors-from-gsheet.php`,
- `docs/system/ROUTING.md` for route changes,
- `docs/system/ARCHITECTURE.md` for the Master/Order/ActionHandler split.

The §10 phase breakdown at the bottom is **historical** — see the
files above for the current state.

---

## 1. Scope

A self-contained **admin-only** module that lets us:

- Maintain a catalogue of **vendors** (dostawcy), their **contact persons**,
  and **producers** (manufacturers) of the components we buy.
- Catalogue every (Vendor × Producer × Part) combination we have ever bought,
  with the vendor's own name, unit, and pack size for that part — the
  *Vendor Part* (the user's `OrderVariant`). Only `parts` (raw components)
  are procured — SMD/THT/SKU are produced in-house from parts.
- Draft **Requests For Quote (RFQ)** — preliminary inquiries sent to a vendor
  for one or more components.
- Skip the RFQ and go straight to a **Purchase Order (PO)** when the admin
  already knows the price and wants to commit directly.
- Convert accepted RFQs into POs with negotiated prices and quantities.
- **Receive goods** against a PO, creating the proper `inventory__parts`
  ledger entries so stock appears in the warehouse.
- Provide a generic **"create order" workflow** so an admin can pick parts
  and assemble an order/RFQ for a single vendor in one session.

Out of scope for the first cut: supplier portal, automated price scraping,
XML/EDI order exchange, payment tracking, three-way invoice matching,
depletion forecasting.

---

## 2. Naming

The project uses Polish for UI strings and business vocabulary, English for
class/table/identifier names (see `AGENTS.md` and `audits/POLISH_LANGUAGE.md`).
The plan uses this split throughout; final names are decided in §9.

| Concept | English (technical) | Polish (business / UI) |
|---|---|---|
| Vendor (we buy from) | `vendor` | Dostawca |
| Vendor contact person | `vendor_supplier` | Osoba kontaktowa |
| Producer (manufacturer) | `producer` | Producent |
| Vendor Part (OrderVariant) | `vendor_part` | Artykuł u dostawcy |
| Request For Quote | `rfq` | Zapytanie ofertowe |
| Purchase Order | `order` | Zamówienie (zakupowe) |
| Goods receipt | `order_receipt` | Przyjęcie towaru |

`MySQL` keyword `ORDER` will need backticks wherever it appears as a column
or table reference; the codebase already does this in places.

---

## 3. Domain Model

> **Updated** after §9 decisions: producer is **required**, only `parts` are
> procured (no SMD/THT/SKU), admin-only permissions, admin picks RFQ vs PO
> at creation, generic part picker on the reorder screen.

```
                                    ┌────────────────────┐
                                    │  list__producer    │
                                    │  ──────────────    │
                                    │  id                │
                                    │  name              │
                                    └────────┬───────────┘
                                             │ 1
                                             │
                                             │ * required
┌──────────────────┐ 1    *  ┌───────────────────────┐  *    1 ┌──────────────────┐
│  list__vendor    │──────────│   list__vendor_part   │──────────│  list__parts     │
│  ──────────────  │          │   (Vendor Part)       │          │  ─────────────   │
│  id              │          │  ─────────────────    │          │  id, name, JM,   │
│  name, address…  │          │  vendor_id            │          │  PartGroup, …    │
│  lead_time_days  │          │  producer_id  NOT NULL │          └──────────────────┘
│  is_active       │          │  parts_id             │
└────────┬─────────┘          │  vendor_part_no       │
         │ 1                  │  vendor_jm_id         │
         │                    │  is_active, comment   │
         │                    │           │           │
         │ *                  │           │ 1         │
         │                    │           ▼ *         │
         │                    │ ┌────────────────────┐
         │                    │ │ list__vendor_part_pack
         │                    │ │ ───────────────────│
         │                    │ │ vendor_part_id     │
         │                    │ │ full_pack_quantity │
         │                    │ └────────────────────┘
         │                    └───────────────────────┘
┌────────▼───────────┐                  ▲ ▲
│ list__vendor_suppl.│                  │ │
│ ─────────────────  │            ┌─────┘ │
│ vendor_id (FK)     │            │       │
│ name, job_title    │            │       │
│ phone, email       │            │       │
└────────────────────┘            │       │
                                  │       │
            ┌─────────────────────┘       │
            │                             │
   ┌────────▼─────────┐         ┌─────────▼────────┐
   │   purchase__rfq  │  1   *  │ purchase__rfq_item│
   │   ─────────────  │─────────│ ──────────────── │
   │   vendor_id      │         │ rfq_id            │
   │   state          │         │ vendor_part_id    │
   │   rfq_number     │         │ quantity + unit   │
   │   created_by     │         │ unit_price (opt.) │
   └────────┬─────────┘         └───────────────────┘
            │ 0..1
            │ "converted_from_rfq_id"
            ▼
   ┌────────────────────┐ 1   *  ┌────────────────────────┐
   │  purchase__order   │────────│ purchase__order_item   │
   │  ────────────────  │        │ ───────────────────── │
   │  vendor_id         │        │ po_id                  │
   │   state             │        │ vendor_part_id         │
   │   po_number         │        │ quantity + unit        │
   │   vendor_po_number  │        │ unit_price             │
   │   expected_delivery │        │ quantity_received      │
   └────────┬───────────┘        └────────────────────────┘
            │ 1
            │ *
            ▼
   ┌─────────────────────────┐ 1   * ┌─────────────────────────────┐
   │ purchase__order_receipt │───────│ purchase__order_receipt_item │
   │ ──────────────────────  │       │ ─────────────────────────── │
   │ po_id                   │       │ receipt_id                   │
   │ received_by, received_at │       │ po_item_id                   │
   │ document_number (PZ/WZ) │       │ quantity_received            │
   │                         │       │ → creates inventory__parts   │
   └─────────────────────────┘       └─────────────────────────────┘
```

Key invariants:

- A `purchase__order` (and `purchase__rfq`) belongs to **exactly one**
  `vendor`.
- A `purchase__*_item` always references a `list__vendor_part`, which is the
  unique catalog row for that (vendor, producer, **parts**) triple.
- `list__vendor_part.parts_id` FKs directly to `list__parts.id`. There is
  **no polymorphic part_type** — only `parts` are procured. (SMD/THT/SKU
  are produced in-house from parts.)
- `list__vendor_part.producer_id` is `NOT NULL` per the §9 decision — every
  VendorPart must record who manufactures it.
- On receiving goods, the application writes one or more `inventory__parts`
  ledger rows (positive `qty`, with a `transfer_group_id` of new type
  `purchase_receipt`) so existing stock views and low-stock triggers work
  unchanged.
- Price history lives in `purchase__order_item.unit_price`; we never store a
  cached price on `list__vendor_part`. The "last known price" shown in the
  UI is a `MAX(updated_at)` / latest non-null aggregate query.

---

## 4. Database Schema (proposed DDL sketch)

To be refined in §9 once open questions are answered. Column types follow
the existing convention (`decimal(30,10)` for quantities, `tinyint(1)` for
booleans, ISO dates, FK indexes named after the constraint).

### 4.1 Master data

```sql
-- 4.1.1 Vendor
CREATE TABLE `list__vendor` (
  `id`              INT NOT NULL AUTO_INCREMENT,
  `name`            VARCHAR(255) NOT NULL,
  `address`         TEXT,
  `additional_data` TEXT,                -- NIP, REGON, notes, bank account, website, …
  `lead_time_days`  INT DEFAULT NULL,    -- typical delivery lead time
  `is_active`       TINYINT(1) NOT NULL DEFAULT 1,
  `comment`         TEXT,
  `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                                  ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_active` (`is_active`)
) ENGINE=InnoDB;

-- 4.1.2 Vendor contact person (1-to-many)
CREATE TABLE `list__vendor_supplier` (
  `id`         INT NOT NULL AUTO_INCREMENT,
  `vendor_id`  INT NOT NULL,
  `name`       VARCHAR(255) NOT NULL,
  `job_title`  VARCHAR(255),
  `phone`      VARCHAR(64),
  `email`      VARCHAR(255),
  `is_active`  TINYINT(1) NOT NULL DEFAULT 1,
  `comment`    TEXT,
  PRIMARY KEY (`id`),
  KEY `vendor_id` (`vendor_id`),
  CONSTRAINT `fk_vs_vendor`
    FOREIGN KEY (`vendor_id`) REFERENCES `list__vendor`(`id`)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB;

-- 4.1.3 Producer (manufacturer)
-- Just name + flags. The producer's own PartNo for each part is tracked
-- via the producer_part_no column on list__vendor_part (see §9.2 deferred
-- item — currently we don't store it, see below).
CREATE TABLE `list__producer` (
  `id`        INT NOT NULL AUTO_INCREMENT,
  `name`      VARCHAR(255) NOT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `comment`   TEXT,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB;

-- 4.1.4 Vendor Part (the "OrderVariant")
-- Only 'parts' are procured; SMD/THT/SKU are produced in-house.
-- Pack sizes live in a child table (4.1.4b) — a vendor can offer
-- the same variant in multiple pack sizes (e.g. "100/1000/5000").
CREATE TABLE `list__vendor_part` (
  `id`                  INT NOT NULL AUTO_INCREMENT,
  `vendor_id`           INT NOT NULL,
  `producer_id`         INT NOT NULL,               -- required (decided §9)
  `parts_id`            INT NOT NULL,               -- FK → list__parts
  `vendor_part_no`      VARCHAR(255) NOT NULL,      -- vendor's own name for the part
  `vendor_jm_id`        INT NOT NULL,               -- vendor's unit of measure (→ part__unit)
  `is_active`           TINYINT(1) NOT NULL DEFAULT 1,
  `comment`             TEXT,
  `created_at`          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                                    ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_vendor_part` (`vendor_id`,`vendor_part_no`), -- vendor's part no is unique per vendor
  KEY `vendor_id` (`vendor_id`),
  KEY `producer_id` (`producer_id`),
  KEY `parts_id` (`parts_id`),
  CONSTRAINT `fk_vp_vendor`   FOREIGN KEY (`vendor_id`)     REFERENCES `list__vendor`(`id`),
  CONSTRAINT `fk_vp_producer` FOREIGN KEY (`producer_id`)   REFERENCES `list__producer`(`id`),
  CONSTRAINT `fk_vp_parts`    FOREIGN KEY (`parts_id`)      REFERENCES `list__parts`(`id`),
  CONSTRAINT `fk_vp_unit`     FOREIGN KEY (`vendor_jm_id`)  REFERENCES `part__unit`(`id`)
) ENGINE=InnoDB;
```

```sql
-- 4.1.4b Vendor Part pack size (1-to-many to list__vendor_part).
-- One row per pack size. Empty sheet cell = no rows for that variant.
CREATE TABLE `list__vendor_part_pack` (
  `id`                INT NOT NULL AUTO_INCREMENT,
  `vendor_part_id`    INT NOT NULL,
  `full_pack_quantity` DECIMAL(30,10) NOT NULL,
  `created_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_vp_pack` (`vendor_part_id`,`full_pack_quantity`),
  KEY `idx_vpp_vp` (`vendor_part_id`),
  CONSTRAINT `fk_vpp_vendor_part` FOREIGN KEY (`vendor_part_id`)
    REFERENCES `list__vendor_part`(`id`)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB;
```

### 4.2 RFQ

```sql
CREATE TABLE `purchase__rfq` (
  `id`           INT NOT NULL AUTO_INCREMENT,
  `vendor_id`    INT NOT NULL,
  `state`        ENUM('draft','sent','responded','cancelled','converted') NOT NULL DEFAULT 'draft',
  `rfq_number`   VARCHAR(64),                -- optional vendor-issued number
  `our_ref`      VARCHAR(64),                -- our internal reference, auto or manual
  `expected_reply_date` DATE,
  `sent_at`      DATETIME,
  `created_by`   INT NOT NULL,               -- → user.user_id
  `comment`      TEXT,
  `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `vendor_id` (`vendor_id`),
  KEY `state` (`state`),
  CONSTRAINT `fk_rfq_vendor` FOREIGN KEY (`vendor_id`) REFERENCES `list__vendor`(`id`),
  CONSTRAINT `fk_rfq_user`   FOREIGN KEY (`created_by`) REFERENCES `user`(`user_id`)
) ENGINE=InnoDB;

CREATE TABLE `purchase__rfq_item` (
  `id`                  INT NOT NULL AUTO_INCREMENT,
  `rfq_id`              INT NOT NULL,
  `vendor_part_id`      INT NOT NULL,
  `quantity`            DECIMAL(30,10) NOT NULL,
  `quantity_unit_id`    INT NOT NULL,        -- defaults to vendor_jm_id but can differ
  `unit_price`          DECIMAL(30,10),      -- admin's target/expected price; nullable until set
  `currency`            VARCHAR(8) NOT NULL DEFAULT 'PLN',
  `comment`             TEXT,
  PRIMARY KEY (`id`),
  KEY `rfq_id` (`rfq_id`),
  CONSTRAINT `fk_rfq_item_rfq`  FOREIGN KEY (`rfq_id`)          REFERENCES `purchase__rfq`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_rfq_item_vp`   FOREIGN KEY (`vendor_part_id`)  REFERENCES `list__vendor_part`(`id`),
  CONSTRAINT `fk_rfq_item_unit` FOREIGN KEY (`quantity_unit_id`) REFERENCES `part__unit`(`id`)
) ENGINE=InnoDB;
```

### 4.3 Purchase Order

> Columns named `po_number`, `vendor_po_number`, `po_id` (not `order_*`) to
> keep the SQL keyword `ORDER` out of every identifier.

```sql
CREATE TABLE `purchase__order` (
  `id`                    INT NOT NULL AUTO_INCREMENT,
  `vendor_id`             INT NOT NULL,
  `state`                 ENUM('draft','sent','confirmed','partially_received','received','cancelled') NOT NULL DEFAULT 'draft',
  `po_number`             VARCHAR(64),                  -- our internal number, auto (PO/YYYY/NNNN)
  `vendor_po_number`      VARCHAR(64),                  -- vendor's confirmation number
  `converted_from_rfq_id` INT DEFAULT NULL,
  `expected_delivery_date` DATE,
  `sent_at`               DATETIME,
  `confirmed_at`          DATETIME,
  `created_by`            INT NOT NULL,
  `comment`               TEXT,
  `created_at`            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `vendor_id` (`vendor_id`),
  KEY `state` (`state`),
  CONSTRAINT `fk_po_vendor` FOREIGN KEY (`vendor_id`) REFERENCES `list__vendor`(`id`),
  CONSTRAINT `fk_po_user`   FOREIGN KEY (`created_by`) REFERENCES `user`(`user_id`),
  CONSTRAINT `fk_po_rfq`    FOREIGN KEY (`converted_from_rfq_id`) REFERENCES `purchase__rfq`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE `purchase__order_item` (
  `id`                  INT NOT NULL AUTO_INCREMENT,
  `po_id`               INT NOT NULL,
  `vendor_part_id`      INT NOT NULL,
  `quantity`            DECIMAL(30,10) NOT NULL,
  `quantity_unit_id`    INT NOT NULL,
  `unit_price`          DECIMAL(30,10) NOT NULL DEFAULT 0,   -- negotiated price per unit
  `currency`            VARCHAR(8) NOT NULL DEFAULT 'PLN',
  `quantity_received`   DECIMAL(30,10) NOT NULL DEFAULT 0,
  `comment`             TEXT,
  PRIMARY KEY (`id`),
  KEY `po_id` (`po_id`),
  CONSTRAINT `fk_poi_po`   FOREIGN KEY (`po_id`)           REFERENCES `purchase__order`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_poi_vp`   FOREIGN KEY (`vendor_part_id`)  REFERENCES `list__vendor_part`(`id`),
  CONSTRAINT `fk_poi_unit` FOREIGN KEY (`quantity_unit_id`) REFERENCES `part__unit`(`id`)
) ENGINE=InnoDB;
```

### 4.4 Receipts

> Columns named `po_id` / `po_item_id` (not `order_id` / `order_item_id`)
> to keep the SQL keyword `order` out of every identifier.

```sql
CREATE TABLE `purchase__order_receipt` (
  `id`              INT NOT NULL AUTO_INCREMENT,
  `po_id`           INT NOT NULL,
  `document_number` VARCHAR(64),                     -- PZ / WZ number from vendor
  `received_by`     INT NOT NULL,
  `received_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `comment`         TEXT,
  PRIMARY KEY (`id`),
  KEY `po_id` (`po_id`),
  CONSTRAINT `fk_rec_po`   FOREIGN KEY (`po_id`)       REFERENCES `purchase__order`(`id`),
  CONSTRAINT `fk_rec_user` FOREIGN KEY (`received_by`) REFERENCES `user`(`user_id`)
) ENGINE=InnoDB;

CREATE TABLE `purchase__order_receipt_item` (
  `id`                INT NOT NULL AUTO_INCREMENT,
  `receipt_id`        INT NOT NULL,
  `po_item_id`        INT NOT NULL,
  `quantity_received` DECIMAL(30,10) NOT NULL,
  `sub_magazine_id`   INT NOT NULL,                  -- which warehouse receives the goods
  `comment`           TEXT,
  PRIMARY KEY (`id`),
  KEY `receipt_id` (`receipt_id`),
  CONSTRAINT `fk_reci_receipt` FOREIGN KEY (`receipt_id`)    REFERENCES `purchase__order_receipt`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_reci_poi`     FOREIGN KEY (`po_item_id`)     REFERENCES `purchase__order_item`(`id`),
  CONSTRAINT `fk_reci_mag`     FOREIGN KEY (`sub_magazine_id`) REFERENCES `magazine__list`(`sub_magazine_id`)
) ENGINE=InnoDB;
```

### 4.5 Transfer-group type

Add a new row to `ref__transfer_group_types` so receipts participate in the
existing transfer-group machinery (used by archive, rollback, notifications):

```sql
INSERT INTO `ref__transfer_group_types` (`slug`, `template`)
VALUES ('purchase_receipt', 'Przyjęcie z zamówienia #{po_id}');
```

On each receipt the application creates one `inventory__transfer_groups`
row with this slug and N `inventory__parts` rows (positive `qty`) inside it,
pointing to the appropriate sub-magazine. The existing low-stock triggers
then keep the `lowstock__parts` table accurate without further work.

### 4.6 Document-number counter

Auto-generated numbers use a small per-year counter (admin can still
override before sending):

```sql
CREATE TABLE `purchase__number_counter` (
  `year`       SMALLINT NOT NULL,
  `type`       ENUM('rfq','po') NOT NULL,  -- 'po' not 'order'; 'order' is a SQL reserved word
  `last_value` INT NOT NULL DEFAULT 0,
  PRIMARY KEY (`year`,`type`)
) ENGINE=InnoDB;
```

Generated number formats:

| Type | Format | Example |
|---|---|---|
| PO (Zamówienie) | `PO/YYYY/NNNN` | `PO/2026/0007` |
| RFQ (Zapytanie ofertowe) | `RFQ/YYYY/NNNN` | `RFQ/2026/0003` |

> **Note on the `type` ENUM:** the values are `'rfq'` and `'po'` — the bare
> SQL word `order` is reserved and avoided even inside an ENUM literal.
> The `purchase__order` table name itself is fine because the
> `purchase__` prefix qualifies it.

Allocation must happen inside a `SELECT ... FOR UPDATE` transaction so two
admins never grab the same number. The handler is in
`PurchaseActionHandler::allocateDocumentNumber($type, $year)`.

---

## 5. PHP Class Structure

All new domain classes go under `src/classes/Utils/Purchase/`, split into
three subfolders by concern (master data / transactional docs / shared).
The existing app convention is one folder per domain; the split here
mirrors the conceptual difference between reference data and transaction
state, since the procurement domain is larger than the others.

```
src/classes/Utils/Purchase/
├── Master/
│   ├── class-vendor.php
│   ├── class-vendorrepository.php
│   ├── class-vendorsupplier.php
│   ├── class-vendorsupplierrepository.php
│   ├── class-producer.php
│   ├── class-producerrepository.php
│   ├── class-vendorpart.php
│   └── class-vendorpartrepository.php
├── Order/
│   ├── class-rfq.php
│   ├── class-rfqrepository.php
│   ├── class-purchaseorder.php
│   ├── class-purchaseorderrepository.php
│   ├── class-orderreceipt.php
│   ├── class-orderreceiptrepository.php
│   └── class-purchaseactionhandler.php    ← orchestrator, lives here because
│                                            it spans RFQ + PO + receipt ops
└── Shared/
    └── (reserved for cross-cutting helpers if needed in later phases)
```

PHP namespaces follow the directory:

| Folder | Namespace |
|---|---|
| `Purchase/Master/` | `Atte\Utils\Purchase\Master` |
| `Purchase/Order/`  | `Atte\Utils\Purchase\Order`  |
| `Purchase/Shared/` | `Atte\Utils\Purchase\Shared` |

The `PurchaseActionHandler` (in `Order/`) orchestrates multi-step operations:

- `createDocument($type, $vendorId, $userId)` — creates an RFQ or a PO in
  `state='draft'`. `$type` is `'rfq'` or `'po'` (picked by the admin at
  creation). Auto-allocates the next number via
  `allocateDocumentNumber()` (admin can override before sending).
- `createOrFindVendorPart($vendorId, $producerId, $partsId, $userData)` —
  used by the inline-create flow when the (vendor, producer, part) combo
  has no `list__vendor_part` yet. Returns the existing row, or creates one
  with admin-supplied `vendor_part_no`, `vendor_jm_id`, `full_pack_quantity`,
  `currency`. See §7.2.
- `createPoFromRfQ($rfqId, $userId)` — converts RFQ + items into a PO,
  copies `vendor_part_id`, `quantity`, `quantity_unit_id`, copies
  `unit_price` from the RFQ item as the starting point for negotiation.
  Producer is implicit via `vendor_part_id` (no separate `producer_id` on
  the line, per §9.2).
- `createReceipt($poId, $items, $userId)` — validates
  `quantity_received ≤ quantity × 1.10` (lenient per §9.2), opens a
  `TransferGroupManager::createTransferGroup(..., 'purchase_receipt')`,
  inserts `inventory__parts` rows with positive `qty`, updates
  `purchase__order_item.quantity_received`, and (if everything received)
  transitions the PO to `'received'` (or `'partially_received'`).
- `allocateDocumentNumber($type, $year)` — `SELECT ... FOR UPDATE` on
  `purchase__number_counter`, returns `PO/YYYY/NNNN` or `RFQ/YYYY/NNNN`.
  `$type` is `'rfq'` or `'po'` (never `'order'`).
- `computeLastKnownPrice($vendorPartId, $currency = null)` — single-purpose
  helper for the "last known price" hint in the RFQ/PO draft UI. Runs a
  query against `purchase__order_item` history for the given `vendor_part_id`
  (optionally filtered by `currency`) and returns the most recent non-null
  `unit_price`. **Never persisted** — always computed at read time.
- `searchPartsForReorder($query, $limit)` — read-only generic part picker
  on the reorder screen, backed by a search on `list__parts` (no forecast
  logic for v1 — forecast deferred).

Repositories expose standard CRUD plus the few specialised queries above.
All write paths go through prepared statements (see `BomRepository` for
the safest pattern in this codebase).

After adding the files: `composer dump-autoload`. Required because
`composer.json` uses `"autoload": {"classmap": ["src/classes"]}` — without
dumping, `use Atte\Utils\Purchase\Master\VendorRepository;` will fail with
"class not found". One-shot per merge, not per request.

---

## 6. UI / Routing

**As built (v1.6).** Procurement routes live in two filesystem
locations by intent:

- **Master data CRUD** under `public_html/components/Admin/Purchase/`
  (capital `Admin/` follows the existing app convention for admin-only
  reference data; see `AGENTS.md` and `docs/code/CODEBASE_MAP.md`):
  `Vendors/`, `Producers/`, `VendorParts/`.
- **User-facing UX surfaces** under
  `public_html/components/purchases/` (lowercase, plural — the
  "shipped, user-visible flows" convention):
  `cart/`, `receipts/`, `documents/`.

Nav layout in `public_html/assets/layout/header.php` (top level,
next to "Magazyn"):

- **Admin → Dostawy** dropdown: Dostawcy, Producenci, Artykuły u
  dostawców (the three master-data CRUD pages).
- **Zamówienia komponentów** dropdown: Koszyk, Przyjęcia, Zapytania
  i zamówienia (the latter is a placeholder for the future combined
  RFQ+PO list with filtration).

`index.php` switch (each route calls `requireAdmin()` first):

| Route | Title (PL) | Component |
|---|---|---|
| `admin/purchase/vendors` | Dostawcy | `components/Admin/Purchase/Vendors/vendors-view.php` |
| `admin/purchase/producers` | Producenci | `components/Admin/Purchase/Producers/producers-view.php` |
| `admin/purchase/vendor-parts` | Artykuły u dostawców | `components/Admin/Purchase/VendorParts/vendor-parts-view.php` |
| `admin/purchase/cart` | Koszyk zakupowy | `components/purchases/cart/cart-view.php` |
| `admin/purchase/receipts` | Przyjęcia | `components/purchases/receipts/receipts-view.php` |
| `admin/purchase/documents` | Zapytania i zamówienia | `components/purchases/documents/documents-view.php` |

AJAX endpoints in the same folders are called by **real component
path** via `COMPONENTS_PATH` (`header.js` defines
`COMPONENTS_PATH = '/atte_ms_new/public_html/components'`) — e.g. the
Koszyk calls `COMPONENTS_PATH + '/purchases/cart/cart-action.php'`.
This matches the convention used by warehouse / commissions / production
modules. The index.php pre-switch translator handles only the legacy
`/admin/<rest>.php` virtual URL form for any non-moved admin AJAX
endpoints (single fallback location under `components/Admin/`).

The original v1.5 plan of separate **RFQ list / RFQ edit / Order list /
Order edit / Receive / Reorder** pages was **not** built. Instead the
Koszyk entry point queues items per vendor and the cart's
"Utwórz zapytanie / Utwórz zamówienie" buttons (grouped per vendor)
jump straight to the document creation flow. The separate list pages
were removed during the 2026-08-22 restructure; the combined RFQ+PO
list with filtration is the future `/admin/purchase/documents`
placeholder. Receive goods lives in `/admin/purchase/receipts` (the
per-PO receive form is invoked by a button inside the PO edit page,
which itself is reachable from the placeholder's per-doc links once
that list exists).

For per-folder file conventions, see the relevant `docs/code/MODULES.md`
entries for the `Admin/Purchase/Vendors`, `Admin/Purchase/Producers`,
`Admin/Purchase/VendorParts`, `purchases/cart`, `purchases/receipts`,
and `purchases/documents` sections.

---

## 7. Process Flow (admin-facing)

### 7.1 Maintain vendors / producers / vendor parts
Standard CRUD. Each list page is filterable, with create/edit/disable
buttons. Vendor detail page shows linked suppliers and vendor parts inline.

### 7.2 Create RFQ or PO (preliminary order)

1. Admin opens **`/admin/purchase/reorder`** (or starts from a vendor page).
2. The "Reorder" screen shows a **generic part picker** (search over
   `list__parts` by name / PartGroup) — no forecast, no auto-suggestion
   for v1.
3. Admin picks a part → modal asks for:
   - Vendor
   - Producer
   - **Document type** (`Zapytanie ofertowe` / `Zamówienie`) — the
     explicit RFQ-vs-PO choice at creation
   - Quantity (in vendor JM) + number of packs (multiples of
     `full_pack_quantity`)
   - Currency (defaults to PLN)
4. The handler first looks up `list__vendor_part` by
   `(vendor_id, producer_id, parts_id)`. If found → use it. If **not**
   found → inline create the VendorPart: the modal reveals extra fields
   (`vendor_part_no`, `vendor_jm_id`, `full_pack_quantity`, optional
   comment) and the new row is persisted in the same transaction as the
   line item. This is the "inline VendorPart creation" flow (§9.2).
5. The draft RFQ/PO is created with this one item line. The PO/RFQ
   document number is allocated via `allocateDocumentNumber()`.
6. Admin can add more parts (any producer, any vendor part) — each
   addition writes one `purchase__*_item` row against the same draft.
7. For each line, the UI shows:
   - Vendor's part no
   - Vendor's unit
   - Vendor's full pack (informational)
   - Currency
   - **Last known unit price** = the most recent non-null
     `purchase__order_item.unit_price` for the same `vendor_part_id` in
     the same currency, computed on the fly by
     `PurchaseActionHandler::computeLastKnownPrice()`. Shown greyed out
     with a tooltip "orientacyjnie — cena może się różnić". The value is
     not stored anywhere — it is queried at render time.
8. Draft can be saved (`state = 'draft'`) or sent (transitions to `'sent'`,
   stamps `sent_at`).

### 7.3 Quote → PO (RFQ path only)
When the vendor replies to an RFQ:
1. Admin opens the RFQ → "Konwertuj na zamówienie".
2. `PurchaseActionHandler::createPoFromRfQ()` creates a `purchase__order`
   with the same `vendor_id`, copies items, sets
   `converted_from_rfq_id`, leaves `unit_price` blank.
3. Admin fills in negotiated `unit_price` per line and the vendor's
   confirmation number, then sends (`state = 'sent' → 'confirmed'`).

### 7.4 Receive goods
1. Admin opens PO → "Przyjmij towar".
2. Picks the receiving sub-magazine (defaults to the user's own).
3. For each line, enters `quantity_received`. Validation is **lenient** per
   §9.2: up to `quantity × 1.10` per line is accepted (over-delivery
   tolerated up to 10%). If the admin enters more, the form rejects the
   excess with an inline warning. The form also flags any line whose unit
   in the delivery differs from the PO line's `quantity_unit_id`.
4. Submission wraps everything in one `TransferGroup` of type
   `purchase_receipt`, inserts `inventory__parts` rows with positive `qty`,
   updates `purchase__order_item.quantity_received`.
5. PO state transitions to `'partially_received'` or `'received'`.
6. The goods are now visible in `/warehouse` and in low-stock alerts.

---

## 8. Integration Points & Reuse

- **Existing inventory ledger (`inventory__*`)** — receipts create positive
  rows. Low-stock triggers already wired → no DB changes there.
- **`ref__transfer_group_types`** — add the `purchase_receipt` slug so
  Archive shows receipts with a proper description.
- **`magazine__list`** — receipts need a destination sub-magazine; reuse
  the existing list (already loaded in many views).
- **`part__unit`** — vendor JM and order-line units both reference this
  table. No change needed; we just add new rows when a vendor uses a
  unit we don't yet track.
- **`user`** — created_by / received_by reference `user.user_id`. No
  change.
- **`Notification` system** — optional later enhancement: surface RFQs
  approaching `expected_reply_date` and POs past `expected_delivery_date`
  as admin notifications. Not in v1.
- **Cron** — no new cron jobs in v1. If we later add a "stale RFQ" or
  "PO past ETA" reminder job, follow the `Locker` pattern from
  `docs/operations/CRON.md`.

---

## 9. Open Questions

### 9.1 Resolved (decisions recorded)

| # | Topic | Decision |
|---|---|---|
| 1 | Naming convention | Polish UI + English identifiers (`Dostawca` / `list__vendor`) |
| 1 | Namespace | `Atte\Utils\Purchase\` |
| 1 | Table prefix for transactional data | `purchase__*` (e.g. `purchase__order`, backticks around `order`) |
| 2 | Producer mandatory on VendorPart | **Required** (`producer_id NOT NULL`) |
| 3 | Procurable part types | **`parts` only**. Drop SMD/THT/SKU. `list__vendor_part.parts_id` FKs straight to `list__parts.id` |
| 4 | Reorder-screen part selection | Generic part picker (no forecast for v1). Forecast deferred |
| 5 | RFQ vs PO at creation | Admin picks at creation time (modal with both options) |
| 6 | Currency | Keep per-line `currency` column (PLN default) — some vendors invoice in EUR |
| 7 | Order numbers | Auto-generate (`PO/YYYY/NNNN`, `ZO/YYYY/NNNN`) with admin override before sending |
| 8 | Receipt over-delivery | Lenient: up to 110% of ordered per line; mismatch in unit flagged but allowed |
| 9 | Inline VendorPart creation | Inline create from the reorder modal (admin supplies `vendor_part_no`, JM, full-pack, currency) |
| 10 | Producer on order line | Fixed by VendorPart catalog. No `producer_id` on `purchase__*_item` |
| 11 | Permissions | Admin-only across the whole module, including receiving |
| 12 | Soft-delete | Confirm `is_active` flag pattern. Never hard-delete referenced rows |

### 9.2 Deferred UX polish (P5+, not blocking P1–P4)

1. **Multi-line batch add** — One-by-one is fine for v1; revisit when we
   see actual usage patterns. **Still open (v1.6).**
2. **Dedicated price history view** — Inline "last price" hint is enough
   for v1; a per-VendorPart timeline screen can be added later.
   **Still open (v1.6).**
3. **Preferred vendor per part** — YAGNI for v1; the part picker already
   shows all available vendors for a given part. **Still open (v1.6).**
4. **Depletion forecast on reorder screen** — Deferred; current picker is
   a generic search over `list__parts`. **Still open (v1.6).**
5. **Producer's own PartNo per part** — Distinct from our internal part
   name and from the vendor's reference. **Shipped in P5** — column
   `list__vendor_part.producer_part_no` added (see
   `docs/procurement/sql/P5-schema.sql`); surfaced in the Koszyk
   picker option subtext and the cart table sub-line.

Additional polish captured during v1.6 cleanup (not in the original
§9.2 list):

- Soft-delete of referenced rows is correctly prevented by
  `ON DELETE RESTRICT` (P2–P4) — the `is_active` (now `isActive`,
  see `docs/procurement/sql/P6-schema.sql`) flag is the deactivation
  channel.
- **Inline create-vendor-part flow** was built but the Koszyk page
  evolved away from the original "Reorder" screen into a vendor-first
  cascading picker instead. The `createOrFindVendorPart()` helper is
  still available in `PurchaseActionHandler` for future callers.
- **Cart persistence** (so an admin can refresh without losing queued
  items): 7-day localStorage (intentionally short-lived — not a shared
  cart; for shared / multi-device carts, persist server-side later).
- **Per-vendor clear** + **per-vendor "select vendor" link** with a
  green confirmation pulse on the picker.
- **Inline edit of the variant's private comment** (pen icon on the
  picker row → immediate save to `list__vendor_part.comment` via
  `vendor-part-comment.php`).
- **In-cart variant creation** — `+ Artykuł` button toggles the picker
  card into add mode (Producent / JM / Numer u dostawcy / Pełne
  opakowanie + optional Numer u producenta + Komentarz). "Dodaj i
  utwórz" POSTs `vp-add.php`, then on success adds the just-created
  line to the cart in one click; the new variant lands in
  `VENDOR_PARTS_INDEX` in memory so it's reachable in the picker
  immediately. No page reload (Q3), no separate modal. Producent and
  JM are required pickers from existing entities; creating new
  Producer/Unit itself stays under `/admin/purchase/`.
- **Per-row quick-edit modal** (pencil icon in the cart table →
  modal with Opak. / Ilość / Cena/Szt. / Waluta). Opak.↔Ilość
  two-way sync mirrors the picker row; packages is derived from the
  item's stored `full_pack_quantity` (never persisted per line).
  Merge rule: same vendor-part merges only when price AND currency
  also match — different price = separate line. Cart persists via
  localStorage (7-day expiry, cleared on submit).

---

## 10. Implementation Phases (historical — shipped)

The v1.5 plan delivered P1–P4 in the order proposed below. P1–P4 are
**shipped** in `feature/component-procurement` as of 2026-08-22; the
Koszyk UX on `/admin/purchase/cart` (not the original "Reorder"
screen) is the de-facto entry point. The detailed per-phase scope
notes below are preserved as the **historical record of how the
module was built**. For the current state, see:

- File map: `docs/code/MODULES.md` — procurement subdirs.
- Class map: `docs/code/CLASSES.md` — `Atte\Utils\Purchase\…` namespace.
- Schema: `docs/data/DATABASE.md` — `list__vendor*`, `list__producer`,
  `purchase__*` tables.
- Routes: `docs/system/ROUTING.md` — 6 procurement routes.
- Cron: `docs/operations/CRON.md` — `import-vendors-from-gsheet.php`.
- Architecture: `docs/system/ARCHITECTURE.md` — Master / Order /
  ActionHandler split.

| Phase | Scope (as built) | Status |
|---|---|---|
| **P1 — Schema + master data** | Tables `list__vendor`, `list__vendor_supplier`, `list__producer`, `list__vendor_part` (with `is_active` later renamed to `isActive` in P6); vendor / supplier / producer / VendorPart CRUD; header.php nav entry; vendor-detail page with linked suppliers + VendorParts. | **Shipped** |
| **P2 — RFQ lifecycle** | Tables `purchase__rfq`, `purchase__rfq_item`, `purchase__number_counter`; RFQ creation via Koszyk "Utwórz zapytanie" (no standalone list page — the combined table is the future placeholder); `state` machine; `createDocument` + `allocateDocumentNumber` (FOR UPDATE) in `PurchaseActionHandler`. | **Shipped** (no standalone list page) |
| **P3 — PO lifecycle + RFQ→PO conversion** | Tables `purchase__order`, `purchase__order_item`; PO creation via Koszyk "Utwórz zamówienie"; `createPoFromRfQ`; `vendor_po_number` capture; `unit_price` capture per line. | **Shipped** (no standalone list page) |
| **P4 — Receiving + inventory integration** | Tables `purchase__order_receipt`, `purchase__order_receipt_item`; `ref__transfer_group_types` row `slug='purchase_receipt'`; `TransferGroupManager` + positive `inventory__parts` writes; lenient 110% over-delivery per line; state transitions to `partially_received` / `received`. | **Shipped** (receive form reachable from PO edit; no standalone list — `/admin/purchase/receipts` exists for browsing) |
| **P5 — Reorder screen + polish** | **Replaced** by the Koszyk UX (cascading vendor / part / variant pickers, 7-day localStorage cart, per-vendor clear + vendor-select link, packages-input with uneven-pack warning, inline private-comment edit). Producer's own PartNo (`producer_part_no` column) added. Inline styles extracted to `purchases.css`. | **Shipped (different shape)** |

Original v1.5 P5 per-phase detail follows as historical reference.
The "Reorder" screen note is no longer applicable; the corresponding
route (`/admin/purchase/reorder`) does **not** exist in v1.6.

### P1 detail — Schema + master data (shipped)

Tables to add in this phase: `list__vendor`, `list__vendor_supplier`,
`list__producer`, `list__vendor_part`. No transactional data yet.

PHP files to add (all under `Utils/Purchase/Master/`):
`class-vendor.php`, `class-vendorrepository.php`,
`class-vendorsupplier.php`, `class-vendorsupplierrepository.php`,
`class-producer.php`, `class-producerrepository.php`,
`class-vendorpart.php`, `class-vendorpartrepository.php`.

Routes to add: `/admin/purchase/vendors`, `/admin/purchase/producers`,
`/admin/purchase/vendor-parts`.

UI: list/edit pages for each entity, plus a vendor-detail page that
shows its suppliers and VendorParts inline.

### P2 detail — RFQ lifecycle

Tables: `purchase__rfq`, `purchase__rfq_item`, `purchase__number_counter`.

New classes (under `Utils/Purchase/Order/`):
`class-rfq.php`, `class-rfqrepository.php`,
plus a skeleton `class-purchaseactionhandler.php` with
`createDocument($type, $vendorId, $userId)`,
`allocateDocumentNumber($type, $year)` and
`computeLastKnownPrice(...)`.

New routes: `/admin/purchase/rfqs`, `/admin/purchase/rfqs/edit`.

UI: RFQ list, RFQ edit (add/remove items, save draft, send).

### P3 detail — PO lifecycle + RFQ→PO

Tables: `purchase__order`, `purchase__order_item`.

New classes (under `Utils/Purchase/Order/`):
`class-purchaseorder.php`, `class-purchaseorderrepository.php`,
add `createPoFromRfQ($rfqId, $userId)` to `PurchaseActionHandler`.

New routes: `/admin/purchase/orders`, `/admin/purchase/orders/edit`.

UI: PO list, PO edit, "Konwertuj z zapytania" button on RFQ detail.

### P4 detail — Receiving + inventory integration

Tables: `purchase__order_receipt`, `purchase__order_receipt_item`.
`ref__transfer_group_types` row: `slug='purchase_receipt'`.

New classes (under `Utils/Purchase/Order/`):
`class-orderreceipt.php`, `class-orderreceiptrepository.php`,
add `createReceipt(...)` to `PurchaseActionHandler`. Reuses
`TransferGroupManager` and writes positive `qty` to `inventory__parts`.

New route: `/admin/purchase/orders/receive` (and AJAX endpoint).

UI: receive-goods form per PO, document-number (PZ/WZ) field, sub-magazine
picker, per-line quantity entry with validation against remaining.

### P5 detail — Reorder screen + polish

Route: `/admin/purchase/reorder` — generic part picker (no forecast yet),
modal flow that drives into RFQ or PO creation. Polish UI pass, copy
review, end-to-end smoke test.

---

## 11. Out of Band

Things explicitly **not** included in this plan and should remain unbuilt
until requested:

- Supplier-facing portal / vendor self-service
- Automated price scraping from vendor websites
- EDI / XML order exchange
- Invoice matching, three-way reconciliation
- Approval workflows / multi-step sign-off
- Multi-currency reporting and FX conversion
- Integration with accounting (iBiznes) beyond exporting data
