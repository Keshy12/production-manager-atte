# ATTE Production Manager — Codebase Map

**Purpose:** Structural overview for new developers. Find anything in under a minute.
**Path:** `C:\xampp\htdocs\atte_ms_NEW`

---

## 1. Top-Level Structure

```
atte_ms_NEW/
├── config/                  # Application configuration
├── docs/                    # Documentation (this folder)
├── public_html/              # Webroot — all HTTP-accessible files
│   ├── assets/              # Static assets (img, js, layout)
│   ├── components/          # 20 module directories (124 PHP files)
│   └── var/                 # Runtime storage (locks, logs)
├── src/
│   ├── classes/             # PHP class library (Api, DB, Utils)
│   └── cron/                # Scheduled job scripts (6 files)
├── index.php                 # Single routing entry point (~27 routes)
├── composer.json             # Composer dependencies
└── .env                      # Environment variables (not committed)
```

---

## 2. `public_html/components/` — Module Directories

All 20 modules listed with full path, purpose, and PHP file count. The `Admin/` and `Profile/` parents each contain sub-modules that are first-class modules in their own right (no nesting).

| Module Path | Purpose                                              | Files |
|-------------|------------------------------------------------------|-------|
| `Admin/BOM/` | BOM upload, edit, dictionary                         | 22 |
| `Admin/Components/` | Component CRUD, detect-new-parts workflow            | 10 |
| `Admin/Magazines/` | Magazine (warehouse) CRUD                            | 3 |
| `Admin/Profiles/` | User profile management                              | 6 |
| `Admin/Synchronization/` | FlowPin status + Google Sheets sync                  | 18 |
| `Archive/` | Historical transfer data, transfer group resolution  | 9 |
| `Commissions/` | Zlecenia (commission) CRUD, grouping, card views     | 9 |
| `Error/` | 404 error page                                       | 1 |
| `GoogleSheets/` | OAuth callback + upload status endpoint              | 2 |
| `Index/` | Dashboard — active commissions, return production    | 6 |
| `Login/` | Authentication (login-view, logout)                  | 3 |
| `Notification/` | User notifications, resolve-affected-queries         | 3 |
| `Production/` | THT/SMD production entry, rollback, last-production  | 6 |
| `Profile/` | Profile landing page                                 | 2 |
| `Profile/DevicesProduced/` | User's devices-produced view                         | 4 |
| `Profile/Warehouse/` | User's own warehouse view                            | 5 |
| `Transfer/` | Transfer workflow, component selection, confirmation | 7 |
| `Verification/` | Production verification (get/set values)             | 3 |
| `Warehouse/` | Admin/manager stock view, correct-warehouse          | 4 |
| `tests/` | Test harness                                         | 1 |

---

## 3. `src/classes/` — Class Library

### Api/ (2 files)
- `class-googleoauth.php` — Google OAuth 2.0 authentication adapter
- `class-googlesheets.php` — Google Sheets API read/write operations

### DB/ (4 files)
- `class-basedb.php` — BaseDB class with common query methods
- `class-flowpindb.php` — Flowpin ERP database queries
- `class-ibiznesdb.php` — iBiznes external system DB queries
- `class-msadb.php` — MSA (internal) database queries

### Utils/ — Domain Classes (17 files across 8 domains)

| Domain | Files | Purpose |
|--------|-------|---------|
| **Bom** | 3 | `class-bom.php`, `class-bomrepository.php`, `class-pricecalculator.php` |
| **Commission** | 2 | `class-commission.php`, `class-commissionrepository.php` |
| **ComponentRenderer** | 2 | `class-paginationrenderer.php`, `class-selectrenderer.php` |
| **Magazine** | 3 | `class-magazine.php`, `class-magazinerepository.php`, `class-magazineactionhandler.php` |
| **Notification** | 2 | `class-notification.php`, `class-notificationrepository.php` |
| **Production** | 2 | `class-productionmanager.php`, `class-skuproductionprocessor.php` |
| **TransferGroup** | 1 | `class-transfergroupmanager.php` |
| **User** | 2 | `class-user.php`, `class-userrepository.php` |

**Root Utils:**
- `class-locker.php` — File-based distributed lock mechanism

---

## 4. `src/cron/` — Scheduled Jobs (6 files)

| File | Purpose |
|------|---------|
| `bom-flat-sku-gs-upload.php` | Upload flat SKU BOM to Google Sheets |
| `bom-flat-tht-gs-upload.php` | Upload flat THT BOM to Google Sheets |
| `flowpin-sku-update.php` | Sync SKU data from Flowpin ERP |
| `update-part-prices.php` | Refresh component prices from Flowpin |
| `warehouse-comparison-gs-upload.php` | Upload warehouse comparison to Sheets |
| `warehouse-data-gs-upload.php` | Upload warehouse stock data to Sheets |

All cron jobs upload to Google Sheets; locks prevent concurrent execution.

---

## 5. Routing Entry Point — `index.php`

Single switch-based router (no framework). All routes map to `public_html/components/<module>/<view>.php`.

**~27 routes** including:

| Route | Component |
|-------|-----------|
| `''` and `'/'` | Index dashboard (active commissions) |
| `/production/tht` | THT production entry |
| `/production/smd` | SMD production entry |
| `/transfer` | Transfer workflow |
| `/verification` | Production verification |
| `/archive` | Archived transfers |
| `/warehouse` | Warehouse view |
| `/commissions` | Commission management |
| `/notification` | User notifications |
| `/profile` | User profile |
| `/profile/warehouse` | User's warehouse |
| `/profile/devices-produced` | Produced devices |
| `/admin/bom/upload` | BOM CSV upload |
| `/admin/bom/edit` | BOM editor |
| `/admin/bom/dictionary` | ValuePackage dictionary |
| `/admin/profiles/edit` | Profile editor |
| `/admin/components/edit` | Component editor |
| `/admin/components/detect-new-parts` | New parts detection |
| `/admin/components/from-orders` | Redirect-only route (→ sheets) |
| `/admin/components/update-prices` | Redirect-only route (→ sheets) |
| `/admin/magazines/edit` | Magazine editor |
| `/admin/synchronization/flowpin` | Flowpin sync status |
| `/admin/synchronization/sheets` | Google Sheets sync |
| `/login` | Login page |
| `/logout` | Logout action |
| `/*` | 404 error |

> `''` and `'/'` resolve to the same Index dashboard handler.

---

## 6. Configuration Files

### `config/config.php`
- Defines `ROOT_DIRECTORY`, `BASEURL` from `.env`
- Session initialization (skipped in CLI mode)
- `includeWithVariables()` — template renderer with variable injection
- `asset()` — versioned asset URLs (appends `?v=<filemtime>`)

### `config/config-google-sheets.php`
- Google OAuth 2.0 client credentials from env vars
- Hybridauth adapter configuration for Sheets API scope
- OAuth callback URL: `/components/GoogleSheets/callback.php`

---

## 7. Assets — `public_html/assets/`

| Directory | Files | Contents |
|-----------|-------|----------|
| `img/` | 12 | Static images, icons |
| `js/` | 1 | `flowpin-manager.js` |
| `layout/` | 3 | Header (`.php`, `.js`, `.css`) + side-menu (`menu.js`, PHP endpoints) |

---

## 8. Storage/Runtime — `public_html/var/`

| Directory | Files | Purpose |
|-----------|-------|---------|
| `locks/` | 0 | Cron job lock files (`.lock`) — prevent concurrent runs |
| `logs/` | 0 | (directory exists, no log files yet) |

---

## Quick Search Guide

| Looking for... | Check here |
|----------------|------------|
| Route a URL | `index.php` switch statement |
| DB queries | `src/classes/DB/` |
| Google Sheets sync | `src/classes/Api/`, `src/cron/*gs-upload*` |
| BOM management | `public_html/components/Admin/BOM/` |
| Component data | `src/classes/Utils/Bom/`, `src/classes/Utils/ComponentRenderer/` |
| Commission logic | `src/classes/Utils/Commission/` |
| Production flow | `src/classes/Utils/Production/`, `public_html/components/Production/` |
| Transfer workflow | `public_html/components/Transfer/` |
| User/auth | `src/classes/Utils/User/`, `public_html/components/Login/` |
| Cron job code | `src/cron/` |
| Lock files | `public_html/var/locks/` |

---

**Next up:** [ARCHITECTURE.md](./ARCHITECTURE.md) — High-level system design; how the pieces fit together.
