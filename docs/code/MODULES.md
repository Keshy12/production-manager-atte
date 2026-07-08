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

## 12. Verification/ — Quality Control (3 files)

**Purpose:** Verify incoming/outgoing transfers. Quality control check before finalizing.

**Key files:**
- `verification-view.php` — Card-based verification interface
- `verify.php` — AJAX endpoint for confirming verification
- `get-verification-values.php` — AJAX endpoint for fetching pending verifications

**Routes:** `/verification`

**Key Utils classes:** None (pure AJAX endpoints)

**Database tables:**
- `inventory__sku`, `inventory__tht`, `inventory__smd`, `inventory__parts` — Transfer records awaiting verification
- `inventory__input_type` — Operation type

**Notable behaviors:**
- Filter by device type (SKU, THT, SMD, Parts)
- Shows comment field for each transfer
- "Korekta?" checkbox allows quantity correction
- Verified transfers update the system; corrections require comment

---

## 13. Warehouse/ — Stock Views (4 files)

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

## 14. tests/ — Development Test Pages (1 file)

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
| `/verification` | Verification/ |
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
