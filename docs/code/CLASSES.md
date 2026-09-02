# ATTE Production Manager — Class Reference

---

## Architecture Overview

The codebase follows a layered pattern across all domains:

| Pattern | Purpose | Examples |
|---|---|---|
| **Domain entity** | Pure data + business logic. Holds state, exposes behaviour. | `Magazine`, `Commission`, `User` |
| **Repository** | DB access wrapper. CRUD operations against a specific table. | `MagazineRepository`, `CommissionRepository` |
| **Action handler** | Bridges HTTP requests to domain logic. Orchestrates multi-step operations. | `MagazineActionHandler` |
| **Renderer** | UI helpers. Emit HTML directly for pagination controls and select dropdowns. | `PaginationRenderer`, `SelectRenderer` |
| **Manager / Processor** | Orchestrators for complex flows. Coordinate multiple repositories, handle transactions, group related changes. | `ProductionManager`, `SkuProductionProcessor`, `TransferGroupManager` |

> **Note:** All classes live in the `Atte\Utils` namespace (or `Atte\Utils\{SubNs}`) and accept a `MsaDB` (or `BaseDB`) instance via the constructor.

---

## Bom

Bill of Materials — describes what components are needed to produce one unit of a device (SMD, THT, or SKU).

### Bom
**File:** `src/classes/Utils/Bom/class-bom.php`

**Purpose:** Represents a single BOM revision for a device, providing component listing and price calculation helpers.

**Constructor / dependencies:**
```php
public function __construct(MsaDB $MsaDB)
```

**Public methods:**
- `getComponents($quantity)` — Returns the BOM component rows (type, id, qty, price, auto-produce flag) multiplied by `$quantity`, with price-missing validation. `file:25`
- `getNameAndDescription()` — Populates `$this->name` and `$this->description` from the device list table; also loads laminate name for SMD devices. `file:109`
- `checkNestedBomForMissingPrices($type, $componentId, &$missingComponentNames)` — Recursively checks nested THT/SMD BOMs for components with zero or null prices (private, used by validator). `file:130`
- `validateComponentPrices($components)` — Wraps each component with `hasMissingPrice`, `hasNestedMissingPrices`, and `nestedMissingComponents` flags. `file:197`

**Used by:** `ProductionManager::produce()`, `SkuProductionProcessor::processProduction()`, `Magazine::getComponentsReserved()`, `Notification::resolveSKUProduction()`

---

### BomRepository
**File:** `src/classes/Utils/Bom/class-bomrepository.php`

**Purpose:** Data-access layer for BOM records — fetch, search, and create BOM rows.

**Constructor / dependencies:**
```php
public function __construct(MsaDB $MsaDB)
```

**Public methods:**
- `getBomById($deviceType, $id)` — Fetches a BOM by `id` and device type (`sku`, `tht`, `smd`); returns an instantiated `Bom` object. `file:14`
- `getBomByValues($deviceType, $values)` — Searches BOMs using an array of column-value pairs (e.g. `['sku_id' => 5, 'version' => null]`); returns `Bom[]`. `file:43`
- `createBom($deviceType, array $data)` — Inserts a new BOM row and returns the populated `Bom` object; validates required fields per device type. `file:77`

**Used by:** `MagazineActionHandler` (via `BomRepository`), `ProductionManager`, `SkuProductionProcessor`, `Notification` (via `BomRepository`)

---

### PriceCalculator
**File:** `src/classes/Utils/Bom/class-pricecalculator.php`  
**Namespace:** `Atte\Utils\Bom`

**Purpose:** Recalculates BOM material + processing costs and propagates price changes up the BOM hierarchy.

**Constructor / dependencies:**
```php
public function __construct(MsaDB $MsaDB)
```

**Public methods:**
- `updateBomPrice(int $bomId, string $deviceType)` — Sums component costs + adds processing overhead (SMD: `qty × 0.06 PLN`; THT: `out_tht_qty × 1.00 PLN`); updates `bom__$deviceType.price`. Returns the new total. `file:16`
- `propagatePriceChange(int $childId, string $childType)` — When a part or sub-BOM price changes, finds all parent BOMs that reference it and recalculates them. `file:99`
- `updateBomPriceAndPropagate(int $bomId, string $type)` — Convenience wrapper that calls `updateBomPrice` then propagates upward if the BOM is the default for its device. `file:127`

**Used by:** BOM management controllers / price-update routes (triggered on component or BOM price edits)

---

## Commission

Production orders assigned to a warehouse, with quantity tracking and receiver-user assignment.

### Commission
**File:** `src/classes/Utils/Commission/class-commission.php`

**Purpose:** Represents a single commission order; exposes state transitions and receiver management.

**Constructor / dependencies:**
```php
public function __construct(BaseDB $MsaDB)
```

**Public methods:**
- `updatePriority($priority)` — Persists a new priority rank for the commission. `file:17`
- `cancel()` — Marks the commission cancelled (`is_cancelled = 1`, `state = 'cancelled'`); throws if already cancelled. `file:24`
- `updateState($state)` — Directly sets the commission state string. `file:35`
- `updateStateAuto()` — Derives state from quantities: `active` → `completed` (when `qty == qty_produced`) → `returned` (when `qty == qty_returned`). `file:42`
- `getReceivers()` — Returns an array of `user_id` values for users assigned to receive this commission. `file:54`
- `updateReceivers($receivers)` — Replaces the receiver list (delete + re-insert). `file:64`
- `addToQuantity($amount, $fieldName = 'qty')` — Increments a quantity field and re-evaluates state auto. `file:74`

**Used by:** `ProductionManager::produce()`, `SkuProductionProcessor::processProduction()`, `TransferGroupManager::updateCommissionAfterRollback()`

---

### CommissionRepository
**File:** `src/classes/Utils/Commission/class-commissionrepository.php`

**Purpose:** Data-access layer for commission records — fetch by ID or bulk by IDs.

**Constructor / dependencies:**
```php
public function __construct(MsaDB $MsaDB)
```

**Public methods:**
- `getCommissionById($id)` — Fetches a single commission row and returns a hydrated `Commission` entity. Throws if not found. `file:19`
- `getCommissionsByIds(array $ids)` — Bulk fetch; returns `[$id => Commission, ...]`. `file:44`
- `getReceiversForCommissions(array $ids)` — Returns `[commissionId => [userId, ...], ...]` for the given commission IDs. `file:74`

**Used by:** `Magazine::getActiveCommissions()`, `User::getActiveCommissions()`, `TransferGroupManager::updateCommissionAfterRollback()`

---

## ComponentRenderer

UI helpers that emit Bootstrap-flavoured HTML for pagination controls and `<select>` dropdowns.

### PaginationRenderer
**File:** `src/classes/Utils/ComponentRenderer/class-paginationrenderer.php`

**Purpose:** Renders paginated navigation buttons (first/prev/next/last) with optional page-select dropdown and item count display.

**Constructor / dependencies:**
```php
public function __construct(
    int $currentPage,       // 1-indexed
    int $totalItems,
    int $itemsPerPage = 20,
    array $options = []    // baseUrl, useAjax, maxVisiblePages, showFirstLast, …
)
```

**Public methods:**
- `render()` — Renders the full pagination block (info + buttons + page-select). `file:58`
- `renderPaginationButtons()` — Renders just the button row (first/prev/page-numbers/next/last). `file:89`
- `renderPageSelect()` — Renders a "go to page" `<select>` dropdown. `file:192`
- `renderItemsInfo()` — Renders "Showing X–Y of Z elements" line. `file:222`
- `getCurrentPage()`, `getTotalPages()`, `hasNextPage()`, `hasPreviousPage()` — State accessors. `file:309–337`
- `getOffset()`, `getLimit()` — Return 0-indexed offset and limit for DB queries. `file:345–355`

**Used by:** List/table controllers that need paginated results (magazine inventory, commission lists, etc.)

---

### SelectRenderer
**File:** `src/classes/Utils/ComponentRenderer/class-selectrenderer.php`

**Purpose:** Provides data-fetching helpers and renders `<option>` HTML for device lists (SKU, THT, SMD, parts) and user selects.

**Constructor / dependencies:**
```php
public function __construct(MsaDB $MsaDB)
```

**Public methods:**
- `getSKUBOMValuesForSelect($isLeftJoin = false)` — Returns `[id => [name, description, versions[], bomIds[]], ...]`. `file:13`
- `getTHTBOMValuesForSelect($isLeftJoin = false)` — Same shape + circle/triangle/square marking flags. `file:44`
- `getSMDBOMValuesForSelect()` — Nested: `[id => [name, desc, laminate_id => [laminateName, versions[], bomIds[]]]]`. `file:86`
- `renderPartsSelect(...)`, `renderSMDSelect(...)`, `renderTHTSelect(...)`, `renderSKUSelect(...)` — Render `<option>` tags for plain item lists. `file:122–177`
- `renderSMDBOMSelect(...)`, `renderTHTBOMSelect(...)`, `renderSKUBOMSelect(...)` — Render BOM-aware options with JSON-encoded version/bom-id data attributes. `file:179–233`
- `renderUserSelect()` — Renders `<option>` for all active users (name + surname). `file:235`
- `renderArraySelect(array $array)`, `renderArraySelectWithSubtext(array $array, array $subText)` — Generic array-to-options helpers. `file:251–274`

**Used by:** Production forms, BOM edit forms, magazine user-assignment forms

---

## Magazine

Warehouse / sub-magazine management — tracks inventory quantities, active commissions, and reserved components.

### Magazine
**File:** `src/classes/Utils/Magazine/class-magazine.php`

**Purpose:** Represents a single magazine (warehouse); provides inventory qty queries and active-commission listing.

**Constructor / dependencies:**
```php
public function __construct(MsaDB $MsaDB)
```

**Public methods:**
- `getWarehouseQty($deviceType, $deviceId)` — Returns the summed quantity of a device in this magazine's inventory. `file:20`
- `getActiveCommissions()` — Returns all non-cancelled, non-returned commissions assigned to this warehouse (ordered by priority then creation date). `file:33`
- `getComponentsReserved()` — Returns, per component type (`sku`/`tht`/`smd`/`parts`), the total quantity reserved across all active commissions. Used to calculate available stock. `file:55`

**Used by:** `MagazineActionHandler`, inventory display controllers

---

### MagazineActionHandler
**File:** `src/classes/Utils/Magazine/class-magazineactionhandler.php`

**Purpose:** Bridges HTTP requests for magazine operations — naming conventions, inventory transfers, user/inventory action dispatching.

**Constructor / dependencies:**
```php
public function __construct(
    MsaDB $MsaDB,
    MagazineRepository $magazineRepository,
    UserRepository $userRepository,
    ?TransferGroupManager $transferGroupManager = null   // instantiated lazily if null
)
```

**Public methods:**
- `getNextSubMagNumber()` — Returns the next sequence number for `SUB MAG N:` naming. `file:23`
- `formatMagazineName(string $name, int $typeId, bool $isEdit, string $originalName)` — Applies `SUB MAG N:` prefix for type-2 magazines. `file:40`
- `getMagazineInventory(int $magazineId)` — Returns aggregated inventory rows for all four device types (parts/smd/tht/sku). `file:60`
- `transferInventoryToMagazine(int $fromMagazineId, int $toMagazineId)` — Moves all inventory from one magazine to another via paired INSERT (positive/negative) statements wrapped in a `TransferGroup`. `file:102`
- `clearMagazineInventory(int $magazineId)` — Writes negative inventory entries to zero out a magazine (used on deactivation), wrapped in a `TransferGroup`. `file:206`
- `handleUserActions(array $users, string $userAction)` — Dispatches `unassign` or `disable` actions on a user list. `file:255`
- `handleInventoryActions(int $magazineId, string $inventoryAction, ?int $targetMagazineId)` — Dispatches `transfer` or `clear` inventory actions. `file:281`
- `getAvailableMagazinesExcluding(int $excludeMagazineId)` — Returns all active magazines except the excluded one (for transfer-target dropdowns). `file:302`
- `formatUsersArray(array $users)` — Maps user objects to `[user_id, name, surname, email]` arrays. `file:320`

**Used by:** Magazine CRUD routes, transfer routes, deactivation handlers

---

### MagazineRepository
**File:** `src/classes/Utils/Magazine/class-magazinerepository.php`

**Purpose:** Data-access layer for magazine records — CRUD for magazines and types, user assignment queries.

**Constructor / dependencies:**
```php
public function __construct(MsaDB $MsaDB)
```

**Public methods:**
- `getMagazineById($id)` — Returns a hydrated `Magazine` entity. `file:19`
- `getAllMagazines($onlyIsActive = true)` — Returns all magazines joined with type names. `file:34`
- `getMagazineTypes()` — Returns `[id => name]` map for `magazine__type`. `file:49`
- `createMagazine(string $name, int $typeId)` — Inserts a new magazine; returns its ID. `file:60`
- `updateMagazine(int $id, string $name, int $typeId)` — Updates name and type. `file:76`
- `createMagazineType(string $name)` — Inserts a new type; returns its ID. `file:94`
- `isAssignedToUsers(int $id)` — Returns `true` if any user references this magazine. `file:108`
- `getUsersAssignedToMagazine(int $magazineId)` — Returns `User[]` for all active users in the magazine. `file:120`
- `assignUserToMagazine(int $userId, $magazineId)` — Sets or clears (`null`) a user's `sub_magazine_id`. `file:135`
- `toggleMagazineStatus(int $id, bool $isActive)` — Activates or deactivates a magazine. `file:151`

**Used by:** `MagazineActionHandler`, magazine CRUD controllers

---

## Notification

Error and event notification system — stores failed FlowPin sync events and provides resolution workflows for production, sold, returnal, and transfer operations.

### Notification
**File:** `src/classes/Utils/Notification/class-notification.php`

**Purpose:** Represents a single notification; provides resolution logic for FlowPin event types (production, sold, returnal, transfer).

**Constructor / dependencies:**
```php
public function __construct(MsaDB $MsaDB, array $notificationValues)
```

**Public methods:**
- `getValuesToResolve()` — Returns rows from `notification__queries_affected` for this notification. `file:22`
- `addValuesToResolve($query, $exceptionValues, $flowpinQueryTypeId)` — Persists a new affected-query row. `file:29`
- `tryToResolveNotification($userId)` — Attempts to re-process all affected queries; marks resolved when all succeed. Returns `bool`. `file:38`
- `groupValuesToResolveByFlowpinTypeId($valuesToResolve)` — Groups affected queries by FlowPin type (1=production, 2=sold, 3=returnal, 4=transfer). `file:60`
- `returnDropdownItem()` — Renders a Bootstrap alert dropdown item HTML with link, timestamp, and message. `file:329`

**Private resolution methods (called by `retryQueries`):**
- `resolveSKUProduction(...)` — Re-executes production events via `SkuProductionProcessor`. `file:99`
- `resolveSKUSold(...)` — Inserts SKU sold records into `inventory__sku` with input type 9. `file:154`
- `resolveSKUReturnal(...)` — Inserts SKU return records with input type 10. `file:206`
- `resolveSKUTransfer(...)` — Handles inter-warehouse SKU transfers based on warehouse-out/in flags. `file:259`
- `resolveNotification()` — Sets `isResolved = 1`. `file:323`

**Used by:** Notification controllers, `SkuProductionProcessor` error paths, cron/background resolution jobs

---

### NotificationRepository
**File:** `src/classes/Utils/Notification/class-notificationrepository.php`

**Purpose:** Data-access layer for notification records — fetch, create from exceptions, create from FlowPin errors.

**Constructor / dependencies:**
```php
public function __construct(MsaDB $MsaDB)
```

**Public methods:**
- `getNotificationById($id)` — Returns a hydrated `Notification` entity. `file:14`
- `getUnresolvedNotifications()` — Returns all unresolved `Notification` objects. `file:25`
- `createNotification($actionNeeded, $row, $valueForAction, $exceptionValues, $flowpinQueryTypeId)` — Inserts a new notification and its first affected-query row. `file:37`
- `createNotificationFromException(\Throwable $exception, mixed $row, int|null $flowpinQueryTypeId)` — Serialises an exception into a notification; routes to PDO or custom-exception handler. `file:82`

**Private helpers:**
- `simplifyException(\Throwable $exception)` — Strips `args` from stack frames to keep stored traces compact. `file:58`
- `createNotificationFromPDOException(...)` — Classifies PDO errors (e.g. `23000` → `action_needed = 1`). `file:92`
- `createNotificationFromCustomException(...)` — Classifies custom exceptions by code (1=BOM, 2=User, 3=Returnal, 4=Transfer, 5=SubMagazine). `file:110`
- `getValueForAction($row, $key)` — Extracts a value from nested row arrays for notification grouping. `file:151`

**Used by:** `SkuProductionProcessor`, `Notification::resolveSKU*` methods, exception handlers across the application

---

## Procurement

Vendor / producer / RFQ / PO / receipt domain — added in v1.6 (`feature/component-procurement`). Two sub-namespaces: `Master\` for reference data and `Order\` for transactional state. Entity constructors take `array $row` only (no `MsaDB` — repositories own the DB); repositories are hand-rolled `FETCH_ASSOC` + manual `new Entity($row)` hydration (B6 audit decision 2026-08-25). `PurchaseActionHandler` is the single orchestrator for multi-step operations spanning RFQ + PO + receipt.

### Master

#### Vendor
**File:** `src/classes/Utils/Purchase/Master/class-vendor.php` — represents a supplier we buy components from.

- Constructor: `__construct(array $row)` — hydrates from a `list__vendor` row.
- Public typed properties: `id`, `name`, `address`, `additionalData`, `leadTimeDays`, `isActive`, `comment`, `createdAt`, `updatedAt`, `supplierCount`, `vendorPartCount` (last two populated by the vendor-list join in `VendorRepository::getAll()`).
- No methods — pure data; the repository handles lookups.

#### VendorRepository
**File:** `src/classes/Utils/Purchase/Master/class-vendorrepository.php` — CRUD over `list__vendor`.

- `getById(int $id): ?Vendor` — single-row lookup.
- `getAll(bool $onlyActive = false, bool $withCounts = true): array` — left-joins `list__vendor_supplier` and `list__vendor_part` for `COUNT(*)` per vendor (single query, no N+1); `withCounts=false` skips the join for callers that don't need it.
- `create(...)` / `update(...)` / `toggleActive(int $id, bool $isActive): bool` — standard CRUD with validation (`name` non-empty, `leadTimeDays` ≥ 0, etc.) that throws `\InvalidArgumentException` on bad input.
- `countSuppliers(int $vendorId): int` / `countVendorParts(int $vendorId): int` — exposed for callers that want the count without the full list.

**Used by:** `Vendors` admin view (`Admin/Purchase/Vendors/vendors-view.php`); `PurchaseActionHandler::createDocument()` (resolves vendor_id for the new RFQ/PO); `VendorSupplierRepository` (FK target); `VendorPartRepository` (FK target).

#### VendorSupplier
**File:** `src/classes/Utils/Purchase/Master/class-vendorsupplier.php` — vendor contact person (1-to-many → `list__vendor`).

- Constructor: `__construct(array $row)` from `list__vendor_supplier`.
- Public typed properties: `id`, `vendorId`, `name`, `jobTitle`, `phone`, `email`, `isActive`, `comment`.
- No methods — pure data.

#### VendorSupplierRepository
**File:** `src/classes/Utils/Purchase/Master/class-vendorsupplierrepository.php` — CRUD over `list__vendor_supplier`.

- `getById`, `getByVendor(int $vendorId, bool $onlyActive = false): array` — main lookup for the vendor-detail page.
- `create(...)` / `update(...)` / `toggleActive(...)` — standard.

**Used by:** `Vendors` admin view (vendor-detail tab).

#### Producer
**File:** `src/classes/Utils/Purchase/Master/class-producer.php` — component manufacturer.

- Constructor: `__construct(array $row)`.
- Public typed properties: `id`, `name`, `isActive`, `comment`.
- No methods — pure data.

#### ProducerRepository
**File:** `src/classes/Utils/Purchase/Master/class-producerrepository.php` — CRUD over `list__producer`.

- `getById`, `getAll(bool $onlyActive = false)`, `getByName(string $name): ?Producer` (used by the Sheets importer to dedup by business key).
- `create(...)` / `update(...)` / `toggleActive(...)` — standard.

**Used by:** `Producers` admin view; `seed-component-procurement-from-gsheet.php` (creates producers on first sight); `VendorPart` (FK target).

#### VendorPart
**File:** `src/classes/Utils/Purchase/Master/class-vendorpart.php` — the (vendor × producer × part) catalog row, known internally as the "OrderVariant".

- Constructor: `__construct(array $row)` from `list__vendor_part` (which carries the P5 `producer_part_no` column and the P6-renamed `isActive` flag).
- Public typed properties: `id`, `vendorId`, `producerId`, `partsId`, `vendorPartNo`, `producerPartNo` (nullable), `producerName` (nullable, populated by joins), `privateComment`, `vendorJmId`, `fullPackQuantity`, `isActive`, `createdAt`, `updatedAt`, plus joined `vendorName`, `partName`, `unitName`.
- No methods — pure data; the `PurchaseActionHandler::computeLastKnownPrice()` and `vendor-part-comment.php` AJAX endpoint are the only writers to its `comment` column (the private-comment inline-edit feature).

#### VendorPartRepository
**File:** `src/classes/Utils/Purchase/Master/class-vendorpartrepository.php` — CRUD over `list__vendor_part`; the most-used repo (drives the Koszyk picker's variant list, the search modal, the vendor-parts admin view).

- `getById`, `getAll(bool $onlyActive = false)`, `getByVendor(int $vendorId, bool $onlyActive = false)`, `getByProducer(int $producerId, bool $onlyActive = false)`, `getByPart(int $partsId, bool $onlyActive = false)` — the four "by-X" lookups used by the admin views.
- `create(...)` / `update(...)` / `toggleActive(...)` — standard with `name` + JM + full-pack validation. `update(...)` takes `array $packQuantities` (replacing the legacy single-`float $fullPackQuantity`); the header update + pack-set replacement are wrapped in one transaction. The header row does NOT carry a denormalised `full_pack_quantity` column — pack sizes live entirely in `list__vendor_part_pack`.
- `getPackQuantities(int $vendorPartId): float[]` — pack sizes for a single VP, sorted ASC; thin wrapper for callers that don't need the full header row.
- `existsForVendorAndPartNo(int $vendorId, string $vendorPartNo): bool` — uniqueness pre-check for the create flow; the dedicated edit page (`edit/vendor-part-edit-save.php`) also relies on the `(vendor_id, vendor_part_no)` UNIQUE index in DB as the real race guard.
- `buildSelectJoin(): string` — shared SELECT clause used by every read.

**Used by:** `VendorParts` admin view; `purchases/cart/cart-view.php` (variant options, VENDOR_PARTS_INDEX, scoped options, comment display); `purchases/cart/vendor-part-search.php` and `purchases/cart/vendor-part-comment.php` (search modal + comment save); `purchases/receipts/receipt-get.php`; `seed-component-procurement-from-gsheet.php`; `PurchaseActionHandler`.

### Order

#### RFQ
**File:** `src/classes/Utils/Purchase/Order/class-rfq.php` — Request For Quote header (state machine: `draft → sent → responded | cancelled | converted`).

- Constructor: `__construct(array $row)`.
- Public typed properties: `id`, `vendorId`, `state`, `rfqNumber`, `expectedReplyDate`, `sentAt`, `createdBy`, `comment`, `createdAt`, `updatedAt`.
- No methods — state transitions live in `PurchaseActionHandler::setSentAt()`, `setRfqNumber()`, etc. (repository-only, no entity methods).

#### RFQRepository
**File:** `src/classes/Utils/Purchase/Order/class-rfqrepository.php` — CRUD over `purchase__rfq`.

- `getById`, `getAll(bool $onlyActive = false)`, `getByVendor(int $vendorId)`, `getByState(string $state)`.
- `create(...)` / `update(...)` / `toggleActive(...)`.
- State setters: `setSentAt(int $id, ?\DateTime $at)`, `setRfqNumber(int $id, ?string $number)`.

**Used by:** `PurchaseActionHandler`.

#### RFQItem
**File:** `src/classes/Utils/Purchase/Order/class-rfqitem.php` — RFQ line item.

- Constructor: `__construct(array $row)`.
- Public typed properties: `id`, `rfqId`, `vendorPartId`, `quantity`, `quantityUnitId`, `unitPrice` (nullable), `currency`, `pickedPackSize` (nullable), `comment`.
- No methods — pure data.

#### RFQItemRepository
**File:** `src/classes/Utils/Purchase/Order/class-rfqitemrepository.php` — CRUD over `purchase__rfq_item`.

- `getById`, `getByRfq(int $rfqId): array` — main lookup for the (future) RFQ edit page.
- `create(int $rfqId, int $vendorPartId, float $quantity, int $quantityUnitId, ?float $unitPrice = null, string $currency = 'PLN', ?string $comment = null, ?float $pickedPackSize = null): int` / `update(int $id, ..., ?float $pickedPackSize = null): bool` — `pickedPackSize` (last param) is the operator-chosen pack size (nullable, must be > 0 when provided). `delete(int $id)`.
- `buildSelectJoin()` selects `i.picked_pack_size AS pickedPackSize` so hydrated `RFQItem` entities carry the picked-pack value.

**Used by:** `PurchaseActionHandler::createDocument()`, `createPoFromRfQ()`, `addItem()`.

#### PurchaseOrder
**File:** `src/classes/Utils/Purchase/Order/class-purchaseorder.php` — Purchase Order header (state machine: `draft → sent → confirmed → partially_received → received`, plus terminal `cancelled`).

- Constructor: `__construct(array $row)`.
- Public typed properties: `id`, `vendorId`, `state`, `poNumber`, `vendorPoNumber`, `convertedFromRfqId`, `expectedDeliveryDate`, `sentAt`, `confirmedAt`, `createdBy`, `comment`, `createdAt`, `updatedAt`.
- No methods — state transitions live in the repository setters.

#### PurchaseOrderRepository
**File:** `src/classes/Utils/Purchase/Order/class-purchaseorderrepository.php` — CRUD over `purchase__order`.

- `getById`, `getAll`, `getByVendor`, `getByState`, `getByRfq(int $rfqId): ?PurchaseOrder` — finds the PO created from an RFQ.
- `create(...)` / `update(...)` / `toggleActive(...)`.
- State setters: `setPoNumber`, `setVendorPoNumber`, `setConfirmedAt`, `setSentAt`.

**Used by:** `PurchaseActionHandler`.

#### PurchaseOrderItem
**File:** `src/classes/Utils/Purchase/Order/class-purchaseorderitem.php` — PO line item.

- Constructor: `__construct(array $row)`.
- Public typed properties: `id`, `poId`, `vendorPartId`, `quantity`, `quantityUnitId`, `unitPrice`, `currency`, `quantityReceived`, `pickedPackSize` (nullable), `comment`.
- No methods — pure data; `quantityReceived` is updated by `PurchaseActionHandler::createReceipt()`.

#### PurchaseOrderItemRepository
**File:** `src/classes/Utils/Purchase/Order/class-purchaseorderitemrepository.php` — CRUD over `purchase__order_item`.

- `getById`, `getByPo(int $poId): array` — main lookup.
- `create(int $poId, int $vendorPartId, float $quantity, int $quantityUnitId, float $unitPrice = 0, string $currency = 'PLN', ?string $comment = null, ?float $pickedPackSize = null): int` / `update(int $id, ..., ?float $pickedPackSize = null): bool` — `pickedPackSize` (last param) is the operator-chosen pack size (nullable, must be > 0 when provided). `delete(int $id)`.
- `buildSelectJoin()` selects `i.picked_pack_size AS pickedPackSize` so hydrated `PurchaseOrderItem` entities carry the picked-pack value.

**Used by:** `PurchaseActionHandler::createReceipt()`, `addItem()`.

#### OrderReceipt
**File:** `src/classes/Utils/Purchase/Order/class-orderreceipt.php` — Goods-receipt header (one row per delivery note / PZ-WZ).

- Constructor: `__construct(array $row)`.
- Public typed properties: `id`, `poId`, `documentNumber`, `receivedBy`, `receivedAt`, `comment`.
- No methods — pure data.

#### OrderReceiptRepository
**File:** `src/classes/Utils/Purchase/Order/class-orderreceiptrepository.php` — CRUD over `purchase__order_receipt`.

- `getById`, `getByPo(int $poId): array`.
- `create(...)` / `update(...)` / `delete(int $id)`.

**Used by:** `purchases/receipts/receipt-get.php`; `PurchaseActionHandler::createReceipt()`.

#### PurchaseActionHandler
**File:** `src/classes/Utils/Purchase/Order/class-purchaseactionhandler.php` (~460 lines) — the single orchestrator for all multi-step procurement operations. Instantiated per-request (holds no state); constructors injects the four transactional repos (`RFQRepository`, `RFQItemRepository`, `PurchaseOrderRepository`, `PurchaseOrderItemRepository`) plus the four master repos it needs to look up vendors / parts. Uses `MsaDB->db->beginTransaction()` for atomic writes and reuses the existing `TransferGroupManager` for the `inventory__parts` writes that close the receiving loop into the warehouse.

- `createDocument(string $type, int $vendorId, int $userId): int` — creates an RFQ (`type='rfq'`) or PO (`type='po'`) in `state='draft'`. Auto-allocates the next number via `allocateDocumentNumber()`; admin can override before sending.
- `addItem(string $type, int $docId, array $itemData): int` — appends one line item to an existing draft RFQ (`type='rfq'`) or PO (`type='po'`). Dispatches to `RFQItemRepository::create()` / `PurchaseOrderItemRepository::create()` with `picked_pack_size` plumbed through (must be `> 0` when provided, null otherwise). Used by the Koszyk `cart-create-document.php` endpoint.
- `allocateDocumentNumber(string $type, int $year): string` — `SELECT ... FOR UPDATE` on `purchase__number_counter`, returns `PO/YYYY/NNNN` or `RFQ/YYYY/NNNN`.
- `createPoFromRfQ(int $rfqId, int $userId): int` — converts an RFQ + items into a PO, copies `vendor_part_id`, `quantity`, `quantity_unit_id`, copies `unit_price` from the RFQ item as the starting point for negotiation.
- `createReceipt(int $poId, array $items, int $userId): int` — validates `quantity_received ≤ quantity × 1.10` per line (lenient per §9.2), opens a `TransferGroupManager::createTransferGroup(..., 'purchase_receipt')`, inserts `inventory__parts` rows with positive `qty`, updates `purchase__order_item.quantity_received`, and (if everything received) transitions the PO to `'received'` (or `'partially_received'`).
- `computeLastKnownPrice(int $vendorPartId, ?string $currency = null): ?float` — single-purpose helper for the "last known price" hint shown greyed-out in the RFQ/PO draft UI. Runs a query against `purchase__order_item` history for the given `vendor_part_id` (optionally filtered by `currency`) and returns the most recent non-null `unit_price`. **Never persisted** — always computed at read time.

**Used by:** the Koszyk cart's `createDocument` (RFQ or PO at submit); `purchases/receipts/receipt-get.php` (`createReceipt`); the RFQ→PO conversion (in the Koszyk post-submit flow before the placeholder page).

---

## Production

Production workflow orchestration for SMD/THT (via `ProductionManager`) and SKU (via `SkuProductionProcessor`).

### ProductionManager
**File:** `src/classes/Utils/Production/class-ProductionManager.php`

**Purpose:** Orchestrates SMD/THT production — validates BOM, deducts components from inventory, updates commissions, creates transfer groups, and triggers auto-production on negative stock.

**Constructor / dependencies:**
```php
public function __construct($MsaDB)   // instantiates UserRepository, BomRepository, TransferGroupManager internally
```

**Public methods:**
- `produce($userId, $deviceId, $version, $quantity, $comment, $productionDate, $deviceType, $laminateId = null)` — Main entry point; returns `[$transferGroupId, $negativeStockAlerts, $commissionAlerts]`. Handles both positive (normal) and negative (correction) quantities. `file:25`

**Private methods:**
- `handleNegativeProduction(...)` — Rolls back production by returning components to inventory and reversing commission quantities. `file:168`
- `checkLowStock($userId, $sub_magazine_id, $bomComponents)` — Scans `lowstock__*` tables for negative quantities; auto-produces if `isAutoProduced`, otherwise alerts. `file:265`
- `prepareComponents($bomComponents)` — Re-indexes BOM component array by type for low-stock queries. `file:350`

**Used by:** SMD/THT production form controllers, low-stock auto-production cron

---

### SkuProductionProcessor
**File:** `src/classes/Utils/Production/class-SkuProductionProcessor.php`

**Purpose:** Processes SKU production events from FlowPin — validates users and BOMs, generates SQL for component deduction and inventory insertion, with full error categorisation for notifications.

**Constructor / dependencies:**
```php
public function __construct(MsaDB $MsaDB, FlowpinDB $FlowpinDB)
    // instantiates NotificationRepository, UserRepository, TransferGroupManager internally
```

**Public methods:**
- `processProduction(array $production, $productionDate, $transferGroupId, $sessionRecordId, $currentEventId)` — Validates all rows, generates SQL INSERT/UPDATE queries grouped by event ID; does NOT execute them. Returns `[$eventId => [query strings]]. `file:38`
- `processAndExecuteProduction(...)` — Same as above but also executes queries with per-event error handling; returns detailed result structure with `overall`, `results`, and `errorSummary`. `file:246`

**Private methods:**
- `categorizeProcessingError($row)` — Re-attempts validation steps on a failed row to classify the error as `BOM_ERROR`, `USER_ERROR`, `DATABASE_ERROR`, or `PROCESSING_ERROR`. Used to set `action_needed_id` on created notifications. `file:361`

**Used by:** `Notification::resolveSKUProduction()`, FlowPin sync controllers, session-based batch production handlers

---

## TransferGroup

Auditing and rollback wrapper for inventory movements — groups related inventory changes so they can be cancelled as a unit.

### TransferGroupManager
**File:** `src/classes/Utils/TransferGroup/class-TransferGroupManager.php`

**Purpose:** Creates and manages transfer groups that group inventory movements (production, transfers, clears, notification resolutions); supports cancellation with commission rollback.

**Constructor / dependencies:**
```php
public function __construct(MsaDB $MsaDB)
```

**Public methods:**
- `createTransferGroup($userId, $slug, $params = [])` — Creates a new group referencing a `ref__transfer_group_types` slug (e.g. `production`, `magazine_transfer`, `notification_resolve`); returns the new group ID. `file:21`
- `getFormattedNote($groupId)` — Returns a human-readable note by applying JSON params to the type's template string. `file:37`
- `static formatNote($template, $params)` — Static helper; replaces `{key}` placeholders in a template with values from `$params`. `file:56`
- `cancelTransferGroup($groupId, $userId)` — Marks all inventory items in the group as cancelled, updates linked commissions, and marks the group cancelled. Returns `['itemsCancelled' => int, 'alerts' => []]. `file:76`
- `getTransferGroupItems($groupId)` — Returns all inventory rows in the group, joined with component names and magazine names, grouped by device type. `file:168`

**Used by:** `ProductionManager`, `SkuProductionProcessor`, `MagazineActionHandler`, `Notification` resolution methods

---

## User

User identity and session-level warehouse associations.

### User
**File:** `src/classes/Utils/User/class-user.php`

**Purpose:** Represents a user session — exposes user info, admin flag, active commissions, and device-usage history.

**Constructor / dependencies:**
```php
public function __construct(BaseDB $MsaDB)
```

**Public methods:**
- `isAdmin()` — Returns `$this->isAdmin` bool. `file:23`
- `getUserInfo()` — Returns a joined user+magazine row: `user.*`, `magazine.*`, plus `user_isActive` and `magazine_isActive` aliases. `file:27`
- `getActiveCommissions()` — Returns commissions where this user is a receiver and the commission is not cancelled/returned. `file:55`
- `getDevicesUsed($deviceType)` — Returns an array of device IDs the user has worked with (from `used__$deviceType`). `file:77`

**Used by:** `ProductionManager`, `SkuProductionProcessor`, `UserRepository` consumers

---

### UserRepository
**File:** `src/classes/Utils/User/class-userrepository.php`

**Purpose:** Data-access layer for user records — fetch by ID or email, enable/disable.

**Constructor / dependencies:**
```php
public function __construct(BaseDB $MsaDB)
```

**Public methods:**
- `getAllUsers()` — Returns all active `User` objects ordered by ID. `file:15`
- `getUserById($id)` — Returns a single `User` or throws. `file:29`
- `getUserByEmail($email)` — Returns a single `User` by email or throws. `file:41`
- `disableUser(int $userId)` — Sets `isActive = 0`. `file:58`
- `enableUser(int $userId)` — Sets `isActive = 1`. `file:73`

**Used by:** `MagazineActionHandler`, `ProductionManager`, `SkuProductionProcessor`, `Notification` resolution methods

---

## Utilities

### Locker
**File:** `src/classes/Utils/class-locker.php`

**Purpose:** File-based exclusive lock (flock) for critical sections — prevents concurrent execution of background jobs, sync scripts, etc.

**Constructor / dependencies:**
```php
public function __construct(string $filename)
    // Lock file path: public_html/var/locks/{$filename}
```

**Public methods:**
- `lock(bool $block = TRUE)` — Acquires an exclusive lock; if `$block = false` returns immediately if busy. Returns `bool` — always check. `file:27`
- `unlock()` — Releases the lock (also called automatically on destruct). `file:60`
- `isLocked(): bool` — Returns `true` if another process holds the lock, `false` if free. `file:73`

**Used by:** Background sync jobs, FlowPin cron controllers, any script that must not run concurrently

---

*Generated from `src/classes/Utils/` — last updated July 2026.*

---

**Next up:** [INTEGRATIONS.md](../operations/INTEGRATIONS.md) — Google Sheets sync and FlowPin integration details.
