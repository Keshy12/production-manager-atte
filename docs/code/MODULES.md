# ATTE Production Manager — Module Reference

---

## 1. Admin/ — Master Administration Module (59 PHP files)

**Purpose:** Central administration hub for BOM management, component CRUD, magazine editing, user profile management, and Flowpin/Google Sheets synchronization. Largest module in the codebase.

### Admin Sub-Modules

#### Admin/BOM/ — Bill of Materials Management (22 files)

**Purpose:** Manages BOM (Bill of Materials) for SKU, THT, and SMD devices. Handles CSV upload, dictionary mapping, and BOM row editing.

**Key files:**
- `upload-bom-view.php` — CSV upload interface with side-by-side comparison (MSA DB vs CSV file)
- `edit-bom-view.php` — BOM editor with SKU/THT/SMD type selection, warehouse selector, price calculation
- `edit-dictionary-view.php` — ValuePackage dictionary editor (maps CSV values to components)

**Routes:** `/admin/bom/upload`, `/admin/bom/edit`, `/admin/bom/dictionary`

**Key Utils classes:**
- `Atte\Utils\ComponentRenderer\SelectRenderer` — Renders dropdown selects for BOM components
- `Atte\Utils\UserRepository` — Gets current user's warehouse assignment
- `Atte\DB\MsaDB` — MSA database access

**Database tables:**
- `bom__smd` — SMD BOM definitions
- `bom__tht` — THT BOM definitions
- `bom__sku` — SKU BOM definitions
- `ref__valuepackage` — ValuePackage dictionary (maps CSV names to components)
- `ref__package_exclude` — Package exclusion dictionary
- `magazine__list` — Warehouse list for BOM assignment
- `list__laminate` — Laminate types
- `inventory__smd`, `inventory__tht`, `inventory__sku` — Component inventory

**Notable behaviors:**
- BOM upload (`upload-bom-view.php`) posts to `upload-csv.php` which validates device name/version against the database
- The `edit-bom-view.php` calculates total BOM price using price data from Flowpin
- `$_GET['type']` parameter ('sku', 'tht', 'smd') determines which BOM type is being edited
- Dictionary editor supports auto-pick via URL parameters (`?dictionary=ref__ValuePackage&value=XYZ`)

---

#### Admin/Components/ — Component CRUD (10 files)

**Purpose:** Create, edit, clone, and detect new components. Supports SKU, THT, SMD, Laminates, and generic Parts.

**Key files:**
- `detect-parts-view.php` — Detects new parts from Flowpin sync that don't exist in MSA database
- `edit-component-view.php` — Full component editor with clone functionality, device images, and auto-produce flags

**Routes:** `/admin/components/edit`, `/admin/components/detect-new-parts`

**Key Utils classes:**
- `Atte\Utils\ComponentRenderer\SelectRenderer` — Renders device selects
- `Atte\DB\MsaDB` — MSA database

**Database tables:**
- `list__sku` — SKU device list
- `list__tht` — THT device list
- `list__smd` — SMD device list
- `list__laminate` — Laminate list
- `part__group` — Part group categories
- `part__type` — Part type classifications
- `part__unit` — Part unit of measure

**Notable behaviors:**
- Detect new parts view highlights new parts in green, changed parts in yellow
- Clicking a yellow "cell-edited" field shows the difference
- Component editor allows cloning an existing device as template for new one
- THT components have marking options (circle, triangle, square) shown as visual images
- `autoProduce` checkbox enables automatic production when component is out of stock

---

#### Admin/Magazines/ — Warehouse Management (3 files)

**Purpose:** CRUD operations for magazines (warehouses). Only type_id=1 (main) magazines cannot be disabled.

**Key files:**
- `edit-magazines-view.php` — Magazine list with add/edit/disable functionality

**Routes:** `/admin/magazines/edit`

**Key Utils classes:**
- `Atte\Utils\MagazineRepository` — Magazine data access
- `Atte\Utils\UserRepository` — User-magazine assignments
- `Atte\DB\MsaDB` — MSA database

**Database tables:**
- `magazine__list` — Warehouse/sub-magazine definitions
- `user` — User accounts with `sub_magazine_id` foreign key

**Notable behaviors:**
- Admin-only access enforced by `$_SESSION['isAdmin']` check
- Cannot disable type_id=1 magazines (main ATTE warehouse)
- Shows assigned users for each magazine
- Disabled magazines shown with gray background

---

#### Admin/Profiles/ — User Profile Management (6 files)

**Purpose:** Create/edit user profiles, assign warehouses, set admin privileges, manage produced devices (THT/SMD) per user.

**Key files:**
- `edit-profile-view.php` — User profile editor with device assignments
- `device-produced-template.php` — Template for device-produced multi-select

**Routes:** `/admin/profiles/edit`

**Key Utils classes:**
- `Atte\Utils\ComponentRenderer\SelectRenderer` — Renders user/device selects
- `Atte\Utils\UserRepository` — User data access
- `Atte\DB\MsaDB` — MSA database

**Database tables:**
- `user` — User accounts (login, password, name, surname, email, isAdmin, isActive)
- `magazine__list` — Warehouse assignments
- `used__tht` — User-THT device assignments
- `used__smd` — User-SMD device assignments

**Notable behaviors:**
- Profile editor can create new SUB MAG (type_id=2) warehouses
- Password verification required for admin operations
- THT/SMD device assignments restrict which devices a user can produce
- Creating new profile auto-generates warehouse name as "SUB MAG XX: Name"

---

#### Admin/Synchronization/ — External System Sync (18 files)

**Purpose:** Bidirectional sync with Flowpin ERP and Google Sheets. Handles data import/export and price updates.

**Key files:**

**flowpin/ sub-module (11 files):**
- `flowpin-status-view.php` — Main sync dashboard with two tabs: Update and Sessions
- `flowpin-sku-analyze.php` — Pre-sync analysis of data before Flowpin update
- `sessions/flowpin-sessions-view.php` — Historical session browser

**sheets/ sub-module (7 files):**
- `flowpin-sheets-view.php` — Google Sheets integration hub with 3 tabs: Integration, Update Prices, Import Orders
- `from-orders-view.php` — Import orders from Google Sheets
- `update-prices-view.php` — Sync component prices from Sheets

**Routes:** `/admin/synchronization/flowpin`, `/admin/synchronization/sheets`

**Key Utils classes:**
- `Atte\Utils\Locker` — File-based distributed lock (prevents concurrent sync)
- `Atte\DB\MsaDB` — MSA database
- `Atte\Api\GoogleSheets` — Google Sheets API wrapper
- `Atte\Api\GoogleOAuth` — OAuth token management

**Database tables:**
- `ref__timestamp` — Tracks last sync timestamps
- `ref__flowpin_update_progress` — Session status tracking
- `inventory__sku`, `inventory__tht`, `inventory__smd`, `inventory__parts` — Inventory with `flowpin_update_session_id`
- `inventory__transfer_groups` — Transfer groups created during sync

**Notable behaviors:**
- Lock file mechanism (`flowpin.lock`) prevents concurrent Flowpin sync
- Stale sessions (lock removed but status='running') are auto-detected and marked as error
- Pre-sync analysis identifies issues with users, devices, and warehouses before actual sync
- Sheets sync exports: warehouse data, BOM_FLAT, BOM_FLAT_SKU, warehouse comparison
- Import orders from Sheets creates commissions and transfers

#### Admin/Purchase/ — Procurement Module — Master Data (P1) (33 files)

**Purpose:** Manage vendors (dostawcy), their contact persons, producers (producenci), and the per-(vendor × producer × part) catalog row. This is Phase 1 of the procurement module — RFQ/PO/receipt flows come in later phases (see `docs/procurement/PLAN.md`).

**Key files (Vendors):**
- `Vendors/vendors-view.php` — Vendor list, add form, and detail modal (showing linked suppliers + VendorParts)
- `Vendors/modals.php` — edit/delete/detail modals
- `Vendors/vendor-{add,update,toggle-active,get,detail}.php` — AJAX endpoints
- `Vendors/supplier-{add,update,toggle-active}.php` — AJAX for inline supplier management in the detail modal
- `Vendors/vendor-part-{add,toggle-active}.php` — AJAX for inline VendorPart management in the detail modal
- `Vendors/table-row-template.php`, `Vendors/vendors-view.js`

**Key files (Producers):**
- `Producers/producers-view.php`, `Producers/modals.php`
- `Producers/producer-{add,update,toggle-active,get}.php`
- `Producers/table-row-template.php`, `Producers/producers-view.js`

**Key files (VendorParts):**
- `VendorParts/vendor-parts-view.php` — list with 4 searchable FK dropdowns (vendor/producer/part/unit), `VendorParts/modals.php`
- `VendorParts/vp-{add,update,toggle-active,get}.php`
- `VendorParts/vp-search-{vendors,producers,parts,units}.php` — AJAX search endpoints for the FK dropdowns
- `VendorParts/table-row-template.php`, `VendorParts/vendor-parts-view.js`

**Key Utils classes (foundation, `Atte\Utils\Purchase\Master`):**
- `Vendor` + `VendorRepository` — CRUD + `countVendorParts`, `countSuppliers`
- `VendorSupplier` + `VendorSupplierRepository` — contact persons per vendor
- `Producer` + `ProducerRepository` — manufacturer catalogue
- `VendorPart` + `VendorPartRepository` — joined queries against `list__vendor`, `list__producer`, `list__parts`, `part__unit`; `existsForVendorAndPartNo()` for uniqueness

**Database tables:**
- `list__vendor` — vendors (name, address, lead time, is_active)
- `list__vendor_supplier` — vendor contact persons (1-to-many → vendor, cascading)
- `list__producer` — manufacturers
- `list__vendor_part` — catalog row for (vendor × producer × part) triple; unique on `(vendor_id, vendor_part_no)`

**One-time import helper:**
- `src/cron/import-vendors-from-gsheet.php` — CLI importer that pulls vendor / contact / VendorPart data from the Google Sheets spreadsheet shared with `update-part-prices.php`. Idempotent, supports `--dry-run`. CLI bootstrap pattern (`require_once config.php`, bypass `config-google-sheets.php` because Hybridauth breaks under CLI) documented in the script header.

**Notable behaviors:**
- Soft-delete via `is_active` flag — never hard-delete referenced rows
- Vendor detail modal pre-computes supplier + VendorPart counts to avoid N+1
- `vendor_part_no` uniqueness check excludes the row being edited (so editing doesn't false-positive against itself)
- VendorJM is auto-created in `part__unit` on first sight (auto-create flow lives in the import script, not the UI yet — that's P2)
- `Admin/` directory is capitalized; case-sensitive on Linux

#### Admin/Purchase/Rfqs/ — Procurement Module — RFQ Lifecycle (P2) (17 files)

**Purpose:** Create, edit, and send Requests For Quote (zapytania ofertowe) to vendors. State machine: `draft → sent → responded | cancelled | converted`. Conversion to PO ships in P3.

**Key files (List page):**
- `rfqs-view.php` — list of RFQs with state-coloured badges, "Nowe zapytanie" form (vendor selectpicker + date + comment), client-side row filter
- `modals.php` — send/cancel confirm modals
- `rfq-{add,get,send,cancel}.php` — AJAX endpoints; `rfq-add` calls `PurchaseActionHandler::createDocument('rfq', …)` which allocates a `RFQ/YYYY/NNNN` number via `SELECT … FOR UPDATE`
- `table-row-template.php`, `rfqs-view.js`

**Key files (Edit page, `Edit/`):**
- `edit-rfq-view.php` — header card with vendor + number + state + expected_reply_date + sent_at, "Dodaj pozycję" form (hidden when not draft), line-items table with inline edit/delete
- `modals.php` — edit/delete-item modals + reused cancel/send modals
- `rfq-item-{add,update,delete,get}.php` — AJAX endpoints; `quantity_unit_id` is auto-derived from the chosen VendorPart's `vendor_jm_id`
- `search-vendor-parts.php` — LIKE-search over `list__vendor_part` filtered to the current RFQ's vendor
- `edit-rfq-view.js`

**Key Utils classes (foundation, `Atte\Utils\Purchase\Order`):**
- `RFQ` + `RFQRepository` — header CRUD; `getById` / `getAll` join `list__vendor.name`; `setState`, `setSentAt`, `setRfqNumber`, `delete`
- `RFQItem` + `RFQItemRepository` — line items; joined queries pull vendor / producer / part / unit names for the edit-page table
- `PurchaseActionHandler` — orchestrator with `createDocument()`, `allocateDocumentNumber()` (`SELECT … FOR UPDATE` against `purchase__number_counter`), `computeLastKnownPrice()` (returns null gracefully until `purchase__order_item` ships in P3), `sendRfq()` (state guard + zero-items guard), `cancelRfq()`. `createPoFromRfq()` is a stub throwing "Implemented in P3".

**Database tables:**
- `purchase__number_counter` — per-year, per-type auto-number generator (PK `(year, type)`, ENUM 'rfq'/'po')
- `purchase__rfq` — RFQ header with state machine
- `purchase__rfq_item` — line items; cascades on RFQ delete

**Notable behaviors:**
- Document-number allocation uses `INSERT … ON DUPLICATE KEY UPDATE … + SELECT … FOR UPDATE` to avoid race conditions between two admins creating RFQs at the same time
- `allocateDocumentNumber` is a "best-effort" transaction participant — joins an outer transaction if one is already open, otherwise opens its own
- `computeLastKnownPrice()` returns null until P3 ships (the `purchase__order_item` table doesn't exist yet); gracefully swallowed via try/catch
- Send-guard refuses to send a draft RFQ with zero line items
- Cancel-guard refuses to cancel an RFQ in `converted` state (terminal)

#### Admin/Purchase/Orders/ — Procurement Module — PO Lifecycle (P3) (18 files)

**Purpose:** Create, edit, and manage Purchase Orders (zamówienia). Two creation paths: **direct** ("Nowe zamówienie" form on the orders list page) or **via RFQ** ("Konwertuj na zamówienie" button on the RFQ edit page). State machine: `draft → sent → confirmed → partially_received → received`, plus terminal `cancelled`.

**Key files (List page):**
- `orders-view.php` — list of POs with state-coloured badges, "Nowe zamówienie" form (vendor selectpicker + date + comment), table columns: ID / Numer / Vendor / #Pozycji / Wartość (sum from `PurchaseOrderItemRepository::sumByPo`) / Stan / Data utworzenia / Akcje
- `modals.php` — send / confirm / cancel modals; confirm modal has a `vendor_po_number` input
- `order-{add,get,update,send,confirm,cancel}.php` — AJAX endpoints
- `table-row-template.php`, `orders-view.js`

**Key files (Edit page, `Edit/`):**
- `edit-order-view.php` — header card (vendor, PO number, state badge, vendor PO number, expected_delivery_date, sent_at, confirmed_at, comment) + breadcrumb "← Powiązane zapytanie: {rfq_number}" linking back to the RFQ when `converted_from_rfq_id` is set; Send / Confirm / Cancel buttons per state; line-items table with vendor_part_no, part, producer, JM, qty, unit_price, **Wartość** (qty × unit_price), currency
- `modals.php` — edit/delete-item modals + reused send/confirm/cancel
- `order-item-{add,update,delete,get}.php` — AJAX; item operations only allowed in `draft` state
- `search-vendor-parts.php` — LIKE-search over `list__vendor_part` filtered to the current PO's vendor (mirrors the RFQ edit version)
- `edit-order-view.js`

**Key Utils classes (added in P3, `Atte\Utils\Purchase\Order`):**
- `PurchaseOrder` + `PurchaseOrderRepository` — header CRUD with joined `list__vendor.name` and `purchase__rfq.rfq_number`; `getByRfq()` for the reverse-of-conversion lookup; date normalisation helper
- `PurchaseOrderItem` + `PurchaseOrderItemRepository` — line items with 5-table join; `sumByPo()` (COALESCE for the list page's Wartość column) and `sumQuantityReceivedByPo()` (P4-ready)
- `PurchaseActionHandler` activations:
  - `createDocument('po', $vendorId, $userId)` — allocates `PO/YYYY/NNNN`, creates an empty PO draft, commits
  - `createPoFromRfq($rfqId, $userId)` — loads RFQ, copies items to PO items (carrying `unit_price`), transitions RFQ to `'converted'`, returns the new PO id
  - `computeLastKnownPrice($vendorPartId, ?$currency)` — now queries `purchase__order_item` history (filtering out zero prices); PDOException wrapper remains for resilience

**Database tables (added in P3):**
- `purchase__order` — PO header with state machine and `converted_from_rfq_id` FK
- `purchase__order_item` — PO line items; `quantity_received` stays 0 in P3, written by P4

**Notable behaviors:**
- `createDocument('po')` and `order-add.php` follow the same strict-signature / follow-up-update pattern as P2's RFQ path — date/comment are applied via a separate `update(...)` call rather than atomic inside `createDocument`
- `order-send.php` enforces a non-empty PO before transitioning to `'sent'` (mirrors `sendRfq`)

> **Note (v1.6, 2026-08-22):** the `Admin/Purchase/Rfqs/`, `Admin/Purchase/Orders/`, `Admin/Purchase/Orders/Edit/`, and `Admin/Purchase/Orders/Receive/` subdirectories were **removed** in the file-system restructure. The RFQ/PO creation still happens — but the entry point is now the **Koszyk** (`purchases/cart/`) which groups queued items per vendor and calls `PurchaseActionHandler::createDocument('rfq')` or `createDocument('po')` directly on submit. The per-document edit pages (RFQ edit, PO edit, Receive form) are currently unreachable from the navbar but their data layer (`purchase__rfq*`, `purchase__order*`, `PurchaseActionHandler`) is intact and used by the cart flow. The combined RFQ+PO list with filtration is the future placeholder at `purchases/documents/`. Receive still works via the "Przyjmij towar" button reachable from a PO's edit page; the standalone `/admin/purchase/receipts` page exists for browsing receipts only.
> The file/URL table below documents what **currently exists** in the repo as of v1.6.

#### `purchases/` — Procurement User-Facing UX Surfaces (v1.6) (12 files)

The new lowercase-plural top-level folder under `public_html/components/` holds the pages an admin actually clicks through. Pairs with the master-data CRUD under `Admin/Purchase/`. Endpoints here are called by **real component path** via `COMPONENTS_PATH` (e.g. `COMPONENTS_PATH + '/purchases/cart/cart-action.php'`) — same convention as `Warehouse/`, `Production/`, `Commissions/`. The `index.php` pre-switch translator handles only the legacy single-location admin AJAX fallback for `Admin/`.

**Key files (`purchases/cart/`, 7 files):**
- `cart-view.php` — the Koszyk. Three cascading pickers: Dostawca → Część → Numer u dostawcy (scoped by current pick). "Opak." + "Ilość" + "Cena/Szt." + "Waluta" + "Dodaj" row, per-vendor cart group with per-vendor "Wybierz dostawcę" link (green confirmation pulse) and per-vendor "Usuń" clear button, inline private-comment editor (pen icon → immediate save to `list__vendor_part.comment`), 7-day localStorage persistence.
- `cart-view.js` — picker cascade, scoped variant dropdown grouped by producer, two-way Opak.↔Ilość sync with yellow uneven-pack warning, per-vendor clear/vendor-select buttons, vendor comment save, AJAX submit to `cart-action.php`.
- `cart-action.php` — handles RFQ vs PO submit: validates cart, calls `PurchaseActionHandler::createDocument('rfq' | 'po', $vendorId, $userId)`, then `$itemRepo->create(...)` for each cart line (per-item quantity + unit_price + currency).
- `cart-active-docs.php` — per-VendorPart active-documents lookup (RFQ/PO with `state IN ('draft','sent','responded' | 'confirmed','partially_received')`), drives the yellow warning strip in the cart row.
- `vendor-part-search.php` — LIKE-search over `list__vendor_part` joined with vendor + part + unit; backs the search modal.
- `vendor-part-comment.php` — immediate save of the private comment (`list__vendor_part.comment`).

**Key files (`purchases/receipts/`, 5 files):**
- `receipts-view.php` — list of receipts (one row per delivery note), state badges, "Nowe przyjęcie" form.
- `receipts-view.js` — table filter / sort.
- `receipt-get.php` — per-receipt detail (AJAX; populates the "Szczegóły przyjęcia" modal).
- `modals.php` — receive-goods form modal, lines, sub-magazine picker, document-number (PZ/WZ) field.
- `table-row-template.php` — jQuery row template for the AJAX list.

**Key files (`purchases/documents/`, 1 file):**
- `documents-view.php` — **placeholder** for the future combined RFQ+PO list with filtration (filters: typ dokumentu / dostawca / status / numer / data). The "Shipped v1.6" plan documents the combined table as the next deliverable; until then it just renders an admin-gated "w przygotowaniu" notice with a link back to the Koszyk flow.

**Notable behaviors (purchases/ in general):**
- 7-day localStorage cart persistence so a refresh doesn't lose queued items (intentionally short-lived; not a shared cart).
- AJAX endpoints use `COMPONETS_PATH + '/purchases/...'` (real paths), NOT the virtual `/admin/purchase/*.php` URLs — matches the pattern in `Warehouse/`, `Commissions/`, `Production/`.
- `assets/layout/purchases.css` — extracted from the cart's original inline `<style>` (B19 audit cleanup) for the picker-specific rules (chevron rotation, clear-picker-link, select-vendor-link, flashSelected animation, packages-uneven warning).
- `order-confirm.php` requires `vendor_po_number` (non-empty, trimmed) as part of the confirm step
- RFQ→PO conversion requires `window.confirm()` in JS (not a styled modal — keeps the destructive irreversible action explicit)
- `computeLastKnownPrice` filters out zero prices so "free" items don't pollute the price hint

**Cross-cutting change in P3:** the existing RFQ edit page header (`Rfqs/Edit/edit-rfq-view.php`) gained a "Konwertuj na zamówienie" button visible when `state ∈ {draft, sent, responded}`. When the RFQ is already converted, a breadcrumb link to the linked PO edit page appears in the header instead. Wired up by `Rfqs/Edit/edit-rfq-view.js` via the new `Rfqs/rfq-convert-to-po.php` endpoint.

---

#### Admin/Purchase/Receipts/ — Procurement Module — Receiving (P4) (5 files)

**Purpose:** Goods-receiving log. Lists every receipt ever recorded (PZ / WZ documents against POs), with a click-through detail modal showing the per-receipt line items (vendor_part_no, part, producer, JM, ilość). This is the audit trail of what physically arrived against what was ordered.

**Key files:**
- `receipts-view.php` — list of receipts (ID / Numer dokumentu / linked PO number / Dostawca / Pozycje count / Ilość łącznie / Przyjął / Data / Akcje). Loads via `OrderReceiptRepository::getAll(true)` which filters out receipts for cancelled POs.
- `receipts-view.js` — row click / "Zobacz" button opens the info modal.
- `modals.php` — info modal (read-only) showing per-receipt line items.
- `receipt-get.php` — GET `id`. Returns `{success, receipt, items}` JSON for the modal.
- `table-row-template.php` — static `<script type="text/template">` row template.

#### Admin/Purchase/Orders/Receive/ — Procurement Module — Per-PO Receive (P4) (4 files)

**Purpose:** Per-PO receiving form. Triggered by the "Przyjmij towar" button on the PO edit page header. Admin picks a sub-magazine and document number (PZ/WZ), enters `quantity_received` per line (with live "remaining / max 110%" hints), and submits. The handler runs the inventory ledger writes in one transaction.

**Key files:**
- `receive-view.php` — form page with state guard (only `confirmed`/`partially_received` POs are receivable), sub-magazine picker (server-rendered from `MagazineRepository::getAllMagazines(true)`), per-PO-item input table.
- `receive-action.php` — POST endpoint. Mirrors the handler's lenient 110% over-delivery check for clean field-level errors, then calls `PurchaseActionHandler::createReceipt(...)`. Catches `InvalidArgumentException`, `LogicException`, `RuntimeException` separately for granular error reporting.
- `modals.php` — placeholder (no extra modal needed; the form submits directly).
- `receive-view.js` — live client-side validation (adds `is-invalid` class when an input exceeds 110% of remaining). Form submit → AJAX POST → on success, navigate to the receipts list page.

**Cross-cutting change in P4:** the existing PO edit page header (`Orders/Edit/edit-order-view.php`) gained a "Przyjmij towar" button visible when `state ∈ {confirmed, partially_received}`. When the PO is `received` or `cancelled`, the button is replaced by a disabled "Towar już przyjęty" / "Zamówienie anulowane" label.

**PurchaseActionHandler::createReceipt() activation** — the previously-stubbed method is now the most logic-dense in the handler. In one transaction it:
- Validates PO state and per-line ownership + quantity + magazine + lenient 110% over-delivery cap.
- Resolves `input_type_id` from `inventory__input_type` (LIKE 'purchase%' OR fallback to id=1).
- Opens a `TransferGroupManager::createTransferGroup(..., 'purchase_receipt', ['po_id' => ...])`.
- Inserts the receipt header + per-line items.
- Bumps `purchase__order_item.quantity_received` per line.
- Inserts positive `inventory__parts` rows (`commission_id=null`, `is_cancelled=0`, `isVerified=0`).
- Transitions the PO to `partially_received` or `received` based on the running total vs ordered.

#### Admin/Purchase/Cart/ — Procurement UX — Koszyk (4 files, v1.4)

**Purpose:** Single entry point for creating new RFQ or PO documents. Replaces the inline "Nowe zapytanie" / "Nowe zamówienie" forms on the RFQ and PO list pages. User picks a vendor, adds items (with quantity + optional unit price + currency), then chooses "Utwórz zapytanie" or "Utwórz zamówienie".

**Key files:**
- `cart-view.php` — main page: vendor selectpicker + document type selector + date + comment + "Dodaj artykuł" form + items table + final action buttons
- `cart-action.php` — POST handler that wraps document + item creation in a single transaction. Validates input, calls `PurchaseActionHandler::createDocument('rfq'|'po', vendorId, userId)`, creates the matching line items, redirects to the new document's edit page
- `search-vendor-parts.php` — AJAX LIKE-search over `list__vendor_part` filtered to the selected vendor (mirrors the P2/P3 versions in RFQ/PO edit directories)
- `cart-view.js` — client-side cart state (in-memory; lost on refresh per current spec — DB-backed cart is deferred)

**Notable behaviors:**
- The vendor-part picker is loaded via AJAX once the vendor is selected, then refilled on each keystroke (length ≥ 2)
- Changing the vendor clears the cart (items reference a specific vendor)
- Document-type toggle updates the date field label between "Oczekiwana data odpowiedzi" (RFQ) and "Oczekiwana data dostawy" (PO)
- Currency defaults to PLN; EUR / USD selectable
- The cart is **session-scoped, in-memory only** — refresh discards it. DB-backed persistence is planned for a future phase.
- After successful create, the user is redirected straight to the new document's edit page (`/admin/purchase/rfqs/edit?id=…` or `/admin/purchase/orders/edit?id=…`)

---

## 2. Archive/ — Historical Transfer Records (9 files)

**Purpose:** View and manage historical transfer data. Supports filtering by device type, warehouse, user, operation type, Flowpin session, and date range. Allows cancelling of transferred items.

**Key files:**
- `archive-view.php` — Main archive view with multi-level filtering
- `archive-get-transfers-by-ids.php` — AJAX endpoint for fetching transfers by IDs
- `archive-resolve-selections.php` — AJAX endpoint for resolving group selections

**Routes:** `/archive`

**Key Utils classes:**
- `Atte\Utils\ComponentRenderer\SelectRenderer` — Renders filter dropdowns
- `Atte\DB\MsaDB` — MSA database

**Database tables:**
- `inventory__sku`, `inventory__tht`, `inventory__smd`, `inventory__parts` — Transfer records
- `inventory__input_type` — Operation type definitions
- `magazine__list` — Warehouse filter
- `user` — User filter
- `ref__flowpin_update_progress` — Session filter

**Notable behaviors:**
- Default view shows last 30 days of data (optimization)
- Grouped view shows hierarchical structure: Group → Device → Detail
- Cancelled transfers shown in red with disabled checkboxes
- Can filter by Flowpin update session to see transfers from specific sync
- "Anuluj zaznaczone" button cancels selected transfers in bulk

---

## 3. Commissions/ — Production Order Management (9 files)

**Purpose:** Zlecenia (commission/production order) CRUD. Create, view, filter, group, and cancel production orders. Orders define what devices should be produced and shipped.

**Key files:**
- `commissions-view.php` — Main commissions view with card-based layout
- `edit-commission.php` — AJAX endpoint for editing commissions
- `commissions-card-template.php` — Card template for commission display
- `get-commission-groups.php` — AJAX endpoint for grouped commission data

**Routes:** `/commissions`

**Key Utils classes:**
- `Atte\Utils\ComponentRenderer\SelectRenderer` — Device dropdowns
- `Atte\Utils\ComponentRenderer\PaginationRenderer` — Pagination
- `Atte\DB\MsaDB` — MSA database

**Database tables:**
- `commission__list` — Commission orders (id, created_by, warehouse_from_id, warehouse_to_id, device_type, bom_id, qty, qty_produced, qty_returned, priority, state, is_cancelled, cancelled_at, cancelled_by, transfer_group_id, created_at, updated_at)
- `magazine__list` — Warehouse references
- `user` — Assigned user filter

**Notable behaviors:**
- State machine: active → completed/returned/cancelled
- Priority levels: critical, urgent, standard, none
- Group identical commissions option to collapse duplicates
- Filters: warehouse (from/to), device type, user, state, priority, date range
- Statistics bar shows total/active/completed/returned counts

---

## 4. Error/ — Error Page (1 file)

**Purpose:** Catch-all for 404 Not Found errors.

**Key files:**
- `404.php` — Simple "404 Page Not Found" display

**Routes:** All unmatched routes (default case in switch)

**Notable behaviors:**
- No Utils classes, no database access
- Just displays a centered 404 error message
- HTTP response code set to 404 before including

---

## 5. GoogleSheets/ — Google Sheets Integration (2 files)

**Purpose:** OAuth callback handler for Google Sheets API and upload status endpoint.

**Key files:**
- `callback.php` — OAuth 2.0 callback, stores access token in database
- `get-upload-status.php` — AJAX endpoint for checking upload status

**Routes:** Used internally by Google OAuth flow

**Key Utils classes:**
- `Atte\Api\GoogleOAuth` — OAuth token storage

**Database tables:**
- `ref__google_access_token` — Stores OAuth access token (JSON)

**Notable behaviors:**
- callback.php uses hybridauth library adapter
- Tokens stored via `GoogleOAuth::update_access_token()`
- Config loaded from `config/config-google-sheets.php`

---

## 6. Index/ — Dashboard Homepage (6 files)

**Purpose:** Main landing page after login. Shows active commissions and allows quick production entry.

**Key files:**
- `active-commissions-view.php` — Dashboard with commission cards
- `return-production.php` — View for returning production (sending back to warehouse)
- `commission-card-template.php` — Card template for commission display

**Routes:** `/` (root)

**Key Utils classes:** None in PHP (client-side JS only)

**Database tables:**
- `commission__list` — Active commissions query

**Notable behaviors:**
- Requires `$_SESSION["userid"]` to be set (login check)
- Shows grouped commissions by default (toggleable)
- Commission cards link to production entry
- Return production view handles sending finished goods back

---

## 7. Login/ — Authentication (3 files)

**Purpose:** User authentication with login/logout functionality.

**Key files:**
- `login-view.php` — Login form with username/password fields
- `login.php` — POST handler, validates credentials, sets session
- `logout.php` — Destroys session, redirects to login

**Routes:** `/login`, `/logout`

**Key Utils classes:**
- `Atte\DB\MsaDB` — User lookup

**Database tables:**
- `user` — User accounts with hashed passwords

**Notable behaviors:**
- Failed login shows error via `$_SESSION['info']`
- Successful login sets `$_SESSION["userid"]` and `$_SESSION["isAdmin"]`
- Logout destroys entire session

---

## 8. Notification/ — User Alerts & Resolution (3 files)

**Purpose:** Display and resolve system notifications (action needed alerts created during Flowpin sync).

**Key files:**
- `notification-view.php` — Single notification detail with resolution button
- `resolve-notification.php` — AJAX endpoint for attempting notification resolution
- `count-affected-queries.php` — AJAX endpoint for affected query count

**Routes:** `/notification`

**Key Utils classes:**
- `Atte\Utils\NotificationRepository` — Notification data access
- `Atte\DB\MsaDB` — MSA database
- `Atte\DB\FlowpinDB` — Flowpin ERP (for fetching missing SKU data)

**Database tables:**
- `notification__list` — Notification records
- `notification__action_needed` — Action type definitions
- `notification__queries_affected` — Queries affected by this notification
- `list__sku` — SKU list (may be fetched from Flowpin if missing)

**Notable behaviors:**
- Notifications created during Flowpin sync when issues found
- Resolution attempts may fetch missing data from Flowpin ERP
- Progress bar shows resolution completion percentage
- actionNeededId=1 is BOM notification (SKU not found)

---

## 9. Production/ — Production Entry (6 files)

**Purpose:** SMD and THT production entry. Records production quantities and consumes components from warehouse.

**Key files:**
- `production-view.php` — Main production entry form (handles both SMD and THT via `$_GET['type']`)
- `production-handler.php` — POST handler, creates transfer records
- `rollback-production.php` — Rollback/cancel production with confirmation modal

**Routes:** `/production/tht`, `/production/smd`

**Key Utils classes:**
- `Atte\Utils\UserRepository` — Current user info
- `Atte\Utils\BomRepository` — BOM data for devices
- `Atte\Utils\ComponentRenderer\SelectRenderer` — Device selects
- `Atte\DB\MsaDB` — MSA database

**Database tables:**
- `inventory__smd`, `inventory__tht` — Production transfer records
- `bom__smd`, `bom__tht` — BOM definitions for device selection
- `user` — Current user reference
- `magazine__list` — User's warehouse

**Notable behaviors:**
- Single `production-view.php` handles both types via `$_GET['type']` parameter
- SMD requires laminate + version selection; THT only requires version
- Negative quantity triggers correction modal (requires comment)
- Production redirects preserve `device_id` via POST/GET
- Rollback shows grouped view of all transfers to undo
- Last production display at bottom of page

---

## 10. Profile/ — User Profile Management (11 files)

**Purpose:** Personal user profile view, password change, warehouse view, and devices-produced management.

**Key files:**
- `profile-view.php` — Main profile with password change form
- `warehouse-view.php` — User's warehouse stock view
- `devices-produced-view.php` — Devices user can produce

**Routes:** `/profile`, `/profile/warehouse`, `/profile/devices-produced`

**Key Utils classes:**
- `Atte\Utils\UserRepository` — User data access
- `Atte\Utils\ComponentRenderer\SelectRenderer` — Device selects
- `Atte\DB\MsaDB` — MSA database

**Database tables:**
- `user` — User info (name, surname, email, login)
- `magazine__list` — User's assigned warehouse
- `used__tht`, `used__smd` — User's producible devices

**Notable behaviors:**
- Password change requires old password verification
- Warehouse view shows user's assigned warehouse stock
- Devices-produced shows which THT/SMD devices the user is allowed to produce

---

## 11. Transfer/ — Inter-Magazine Transfers (7 files)

**Purpose:** Create and execute transfers of components between magazines (warehouses). Can optionally create commissions.

**Key files:**
- `transfer-view.php` — Main transfer workflow UI
- `transfer-components.php` — AJAX endpoint for component transfer
- `transfer-confirmation-template.php` — Transfer confirmation modal template
- `get-components-for-commissions.php` — AJAX for commission component lookup

**Routes:** `/transfer`

**Key Utils classes:**
- `Atte\Utils\UserRepository` — Current user and admin check
- `Atte\Utils\MagazineRepository` — Warehouse data
- `Atte\Utils\ComponentRenderer\SelectRenderer` — Component selects
- `Atte\DB\MsaDB` — MSA database

**Database tables:**
- `magazine__list` — Source and destination warehouses
- `commission__list` — Optional commissions created during transfer
- `inventory__sku`, `inventory__tht`, `inventory__smd`, `inventory__parts` — Transfer records
- `inventory__input_type` — Transfer operation type

**Notable behaviors:**
- Admin can transfer from any warehouse; regular users limited to their assigned warehouse
- Transfer table shows: component name, source stock, destination stock, needed for commissions, transfer qty
- "Różnica wszystkie" button auto-fills transfer as difference between warehouses
- Can optionally create commissions during transfer workflow
- Checkbox "Uwzględnij aktywne zlecenia" subtracts committed quantities from available stock

---

## 12. Warehouse/ — Stock Views (4 files)

**Purpose:** View current stock levels across all warehouses. Supports correction of stock quantities.

**Key files:**
- `warehouse-view.php` — Main warehouse stock table
- `correct-warehouse.php` — AJAX endpoint for stock correction
- `warehouse-table-item-template.php` — Table row template

**Routes:** `/warehouse`

**Key Utils classes:**
- `Atte\Utils\ComponentRenderer\SelectRenderer` — Component selects
- `Atte\DB\MsaDB` — MSA database

**Database tables:**
- `list__sku`, `list__tht`, `list__smd`, `list__parts` — Device/component definitions
- `inventory__sku`, `inventory__tht`, `inventory__smd`, `inventory__parts` — Stock quantities
- `magazine__list` — Warehouse definitions (main vs external)

**Notable behaviors:**
- Shows stock split by: main warehouses, external warehouses, total
- Correct warehouse modal allows manual stock adjustment
- Pagination support for large component lists
- Default view shows Parts type

---

## 13. tests/ — Development Test Pages (1 file)

**Purpose:** Developer test harness for debugging and testing.

**Key files:**
- `test1.php` — Basic test page calling `phpinfo()`

**Routes:** `/test`, `/flowpin/test`

**Key Utils classes:**
- Various depending on test needs

**Notable behaviors:**
- `/test` is a basic phpinfo() dump
- `/flowpin/test` includes `warehouse_state_view.php` (not listed in main file count)
- Not for production use

---

## Quick Reference: Routes → Modules

| Route | Module |
|-------|--------|
| `/` | Index/ |
| `/production/tht` | Production/ (type=tht) |
| `/production/smd` | Production/ (type=smd) |
| `/transfer` | Transfer/ |

| `/archive` | Archive/ |
| `/warehouse` | Warehouse/ |
| `/commissions` | Commissions/ |
| `/notification` | Notification/ |
| `/profile` | Profile/ |
| `/profile/warehouse` | Profile/ |
| `/profile/devices-produced` | Profile/ |
| `/admin/bom/upload` | Admin/BOM/Upload/ |
| `/admin/bom/edit` | Admin/BOM/Edit/ |
| `/admin/bom/dictionary` | Admin/BOM/Dictionary/ |
| `/admin/profiles/edit` | Admin/Profiles/Edit/ |
| `/admin/components/edit` | Admin/Components/Edit/ |
| `/admin/components/detect-new-parts` | Admin/Components/DetectNewParts/ |
| `/admin/magazines/edit` | Admin/Magazines/Edit/ |
| `/admin/synchronization/flowpin` | Admin/Synchronization/flowpin/ |
| `/admin/synchronization/sheets` | Admin/Synchronization/sheets/ |
| `/login` | Login/ |
| `/logout` | Login/ |
| `/*` | Error/ |

---

## Quick Reference: Utils Classes by Domain

| Domain | Classes |
|--------|---------|
| BOM | `BomRepository`, `PriceCalculator` (`Atte\Utils\Bom`) |
| Commission | `CommissionRepository` |
| ComponentRenderer | `SelectRenderer`, `PaginationRenderer` |
| Magazine | `MagazineRepository`, `MagazineActionHandler` |
| Notification | `NotificationRepository` |
| Production | `ProductionManager`, `SkuProductionProcessor` (`Atte\Utils\Production`) |
| TransferGroup | `TransferGroupManager` |
| User | `UserRepository` |

---

## Quick Reference: Database Classes

| Class | Purpose |
|-------|---------|
| `MsaDB` | Primary MSA application database (most modules use this) |
| `FlowpinDB` | Flowpin ERP external database |
| `IbiznesDB` | iBiznes external system database |
| `BaseDB` | Base class with common query methods |

---

*Generated for ATTE Production Manager. For schema details, see [DATABASE.md](../data/DATABASE.md).*

---

**Next up:** [CLASSES.md](./CLASSES.md) — Domain class reference; core business logic classes.
