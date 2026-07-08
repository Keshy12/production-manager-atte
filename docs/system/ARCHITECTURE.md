# ATTE Production Manager — Architecture

---

## 1. System Purpose

ATTE Production Manager is a **manufacturing inventory and production tracking system** for two assembly lines:

- **SMD (Surface-Mount Device)** — pick-and-place assembly
- **THT (Through-Hole Technology)** — manual assembly

It manages the full lifecycle from component stock through production commissions to finished device tracking. A typical workflow: check BOM availability → record production → system deducts components → track completion.

**Core domains:**
- **Inventory** — multi-type component tracking (Parts, SMD, THT, SKU) across warehouse magazines
- **Production** — production recording, rollback, quantity tracking
- **Commissions** — production orders (zlecenia) with state management
- **BOM** — bill of materials for device definitions
- **Transfers** — inter-magazine inventory movements

> **Note:** This is a Polish-language product. User-facing identifiers, comments, and variable names are in Polish. Some class names are English. Treat Polish as the canonical language for business logic.

---

## 2. Tech Stack at a Glance

| Layer | Technology |
|-------|------------|
| **Backend** | PHP 8.0+ (vanilla, no framework) |
| **Frontend** | jQuery 3.6, Bootstrap 4.3.1, Bootstrap Icons |
| **Database** | MySQL 5.7+ (3 separate DBs — see §7) |
| **External APIs** | Google Sheets API (via `google/apiclient`), Google OAuth (Hybridauth) |
| **Configuration** | `vlucas/phpdotenv` for `.env` |
| **Autoload** | Composer classmap (`src/classes`) |

No framework. No ORM. No router library. Pure PHP with a single switch-statement router.

---

## 3. Layered Architecture

```
┌─────────────────────────────────────────────────────────────────┐
│                     PRESENTATION LAYER                          │
│  public_html/components/*/          Page renderers            │
│  public_html/assets/layout/          Header, side-menu          │
│  jQuery + Bootstrap 4               Client-side interactivity  │
└────────────────────────┬────────────────────────────────────────┘
                         │ includeWithVariables($filePath, $variables)
┌────────────────────────▼────────────────────────────────────────┐
│                       ROUTING LAYER                             │
│  index.php                    Single switch router (~27 routes)│
│  config/config.php             includeWithVariables(), asset()  │
└────────────────────────┬────────────────────────────────────────┘
                         │ require component view
┌────────────────────────▼────────────────────────────────────────┐
│                     DOMAIN LAYER                                │
│  src/classes/Utils/<Domain>/   Business logic                   │
│  Magazine/, Commission/, Bom/, Production/, User/, etc.        │
│  class-*.php                   Single-responsibility classes    │
└────────────────────────┬────────────────────────────────────────┘
                         │ new XRepository(), new XManager()
┌────────────────────────▼────────────────────────────────────────┐
│                     PERSISTENCE LAYER                           │
│  src/classes/DB/              Database abstraction              │
│  class-basedb.php             BaseDB (common query methods)    │
│  class-msadb.php              MSA (production) DB              │
│  class-flowpindb.php          Flowpin ERP DB                   │
│  class-ibiznesdb.php          iBiznes external DB              │
└────────────────────────┬────────────────────────────────────────┘
                         │
┌────────────────────────▼────────────────────────────────────────┐
│                   EXTERNAL SYSTEMS                              │
│  src/classes/Api/              Google OAuth + Sheets API        │
│  src/cron/*                    Background sync jobs            │
│  FLOWPIN ERP                   Production data source         │
└─────────────────────────────────────────────────────────────────┘
```

**Key file locations:**

| Layer | Path |
|-------|------|
| Presentation | `public_html/components/<area>/<feature>-view.php` |
| Routing | `index.php` |
| Domain | `src/classes/Utils/<Domain>/class-*.php` |
| Persistence | `src/classes/DB/class-*.php` |
| External APIs | `src/classes/Api/class-*.php` |
| Scheduled jobs | `src/cron/*.php` |

---

## 4. Request Lifecycle

Every HTTP request follows this path:

```
URL request
    │
    ▼
index.php                              # Entry point
    │ strtok(REQUEST_URI) → route      # Parse URL path
    │
    ▼
switch ($request)                     # Route matching
    │
    ▼
includeWithVariables($header, [...])  # Render layout header
    │
    ▼
require public_html/components/       # Load page view
    │   <area>/<feature>-view.php     # View may use $_GET params
    │
    ▼
new CommissionRepository()            # Instantiate domain class
    │
    ▼
MsaDb::query()                        # Database access
    │
    ▼
echo / render                         # Output HTML
    │
    ▼
</body></html> (closed in index.php)  # Footer rendered
```

**Example: `/production/tht`**

1. `index.php` receives `production/tht`
2. Switch matches `case 'production/tht'`
3. Sets `$_GET['type'] = 'tht'`
4. Calls `includeWithVariables($headerDir, ['title' => 'Produkcja THT'])`
5. Requires `public_html/components/production/production-view.php`
6. View uses `$_GET['type']` to render THT form
7. Form POSTs to same URL; view handles submission via domain classes

**Key helper:** `includeWithVariables($filePath, $variables, $print)` in `config/config.php` — extracts `$variables` into local scope, buffers the include, returns output. Used everywhere for template rendering.

---

## 5. Auth Model

**Session-based, role-based.**

```php
// Login sets session
$_SESSION["userid"] = $userId;        // Always set on login

// Check in views/components
if (!isset($_SESSION["userid"])) {
    header('Location: /login');
    exit;
}

// Admin check (rough pattern)
if (!$_SESSION["isAdmin"]) {
    // redirect or die
}
```

**Password hashing:** SHA-256 in `public_html/components/Login/login.php:7` (`hash('sha256', $_POST["userPassword"])`).  
**Session start:** In `config/config.php` — skipped in CLI mode (`php_sapi_name() !== 'cli'`).

There is no route guard middleware. Each view/component is responsible for checking `$_SESSION["userid"]`.

---

## 6. State Management

**Server-side sessions only. No SPA. No JSON API. Full page renders.**

- `$_SESSION` — user ID, role, flash data
- `$_GET` — page parameters (e.g., `?type=tht`, `?id=123`)
- `$_POST` — form submissions handled in-view
- No Vue/React/Angular. All interactivity via jQuery + AJAX

Every state-changing action (create, update, delete) POSTs to the same view or a dedicated handler, then re-renders or redirects. No separate REST controllers.

---

## 7. Database Design

**3 separate database connections:**

| DB | System | Purpose |
|----|--------|---------|
| `atte_ms` | MSA (internal) | Primary application DB — inventory, commissions, production |
| `flowpin_solution` | Flowpin ERP | External production data (SKU, prices) |
| `ibiznes_atte` | iBiznes | External business data |

**Schema patterns:**
- `inventory__*` — stock levels per type (SMD, THT, Parts, SKU)
- `list__*` — master data (components, devices, materials)
- `bom__*` — bill of materials definitions
- `commission__*` — production order management
- `user` — authentication + roles

> Full schema: see [DATABASE.md](../data/DATABASE.md).

**DB classes** (`src/classes/DB/`):
- `BaseDB` — base class with `query()`, `insert()`, `insertBulk()`, `update()`, `deleteById()`, `isInTransaction()` using PDO
- `MsaDb`, `FlowpinDb`, `IBiznesDb` — extend BaseDB with connection-specific logic

---

## 8. External Systems

### Google Sheets
- **Purpose:** Data export for reporting and manual review
- **Auth:** OAuth 2.0 via Hybridauth (`hybridauth/hybridauth` v3)
- **Config:** `config/config-google-sheets.php` — client ID/secret from `.env`
- **Callback:** `public_html/components/GoogleSheets/callback.php`
- **Upload jobs:** `src/cron/*gs-upload*.php` — scheduled export to Sheets

### Flowpin ERP
- **Purpose:** Production data import (SKU data, prices, component info)
- **Connection:** internal SQL Server (see `.env`)
- **DB class:** `src/classes/DB/class-flowpindb.php`
- **Cron jobs:** `src/cron/flowpin-sku-update.php`, `src/cron/update-part-prices.php`

> Full integration details: see [INTEGRATIONS.md](../operations/INTEGRATIONS.md).

---

## 9. Background Jobs

**6 cron scripts** in `src/cron/`:

| File | Purpose | Target |
|------|---------|--------|
| `bom-flat-sku-gs-upload.php` | Upload flat SKU BOM | Google Sheets |
| `bom-flat-tht-gs-upload.php` | Upload flat THT BOM | Google Sheets |
| `flowpin-sku-update.php` | Sync SKU from Flowpin | Internal DB |
| `update-part-prices.php` | Refresh prices from Flowpin | Internal DB |
| `warehouse-comparison-gs-upload.php` | Warehouse diff | Google Sheets |
| `warehouse-data-gs-upload.php` | Stock levels | Google Sheets |

**Lock pattern:** Each job writes a `.lock` file to `public_html/var/locks/` before running. `src/classes/Utils/class-locker.php` provides `lock()` / `unlock()`. If a lock exists, the job skips (prevents concurrent runs).

**Logs:** Written to `public_html/var/logs/` (directory exists, files created at runtime).

> Full cron documentation: see [CRON.md](../operations/CRON.md).

---

## 10. Where to Add New Features

### New page/route
1. Add case to `index.php` switch statement:
   ```php
   case 'area/feature':
       includeWithVariables($headerDir, array('title' => 'Feature Title'));
       require $componentsDir . '/area/feature/feature-view.php';
       break;
   ```
2. Create view file: `public_html/components/area/feature/feature-view.php`
3. (Optional) Add subdir in `public_html/assets/layout/` for custom JS/CSS

### New domain logic
1. Add class in `src/classes/Utils/<Domain>/`:
   - `class-mymanager.php` — orchestrates operations
   - `class-myrepository.php` — data access patterns
   - Keep single responsibility — one class per concern
2. Autoload picks up via Composer classmap (`composer.json` `"autoload": {"classmap": ["src/classes"]}`)

### New DB access
1. Extend `BaseDB` in `src/classes/DB/class-mydb.php`
2. Or add methods to existing DB class (e.g., `MsaDb`) if tightly related
3. Use prepared statements: `BaseDB::query("SELECT * FROM table WHERE id = ?", [$id])`

### New scheduled job
1. Create `src/cron/my-job.php`
2. Use the locker pattern:
   ```php
   $locker = new Locker('my_job');
   if (!$locker->lock()) { exit('Locked'); }
   // ... work ...
   $locker->unlock();
   ```
3. Register the cron externally (e.g., Linux crontab or Windows Task Scheduler):
   ```
   */15 * * * * php /path/to/src/cron/my-job.php
   ```

---

## 11. Anti-Patterns & Known Quirks

### Vanilla PHP (no framework)
- No autoloading PSR-4 namespaces (Composer classmap only — changes require `composer dump-autoload`)
- No routing library — the switch in `index.php` is the router
- No ORM — raw SQL with prepared statements via `BaseDB`
- No template engine — PHP itself is the template language

### Single switch router
- `index.php` handles all HTTP routing. No `.htaccess` rewrite rules redirect to it (it sits at webroot).
- Route parameter extraction is manual (`strtok(REQUEST_URI, '?')`)
- No route guards — each view checks `$_SESSION["userid"]`

### Manual template rendering
- `includeWithVariables($filePath, $variables)` extracts variables into local scope — no isolated scope
- Views are PHP files that `require` or `echo` HTML directly
- Layout is split: header in `public_html/assets/layout/header.php`, footer is `</body></html>` in `index.php`

### Mixed Polish/English identifiers
- Business logic (variable names, comments, view labels) is in **Polish**
- Class names and technical identifiers are in **English**
- The product is Polish — treat Polish identifiers as the authoritative business vocabulary
- Some older files may use English for business terms that later got Polish equivalents

### No separate API layer
- Forms submit to the same view that renders them (POST → same URL → re-render)
- AJAX endpoints are often just `require` statements inside component views that `echo` JSON and `exit`
- Google Sheets OAuth callback (`callback.php`) is an exception — it handles OAuth redirect

### Multiple databases, one connection pattern
- Each DB class (`MsaDb`, `FlowpinDb`, `IBiznesDb`) manages its own PDO connection
- No connection pooling or shared connection manager
- Flowpin uses SQL Server (not MySQL) — separate driver

---

*Last updated: 2026-07-08*  
*Related docs: [CODEBASE_MAP.md](./CODEBASE_MAP.md), [DATABASE.md](../data/DATABASE.md), [CRON.md](../operations/CRON.md), [INTEGRATIONS.md](../operations/INTEGRATIONS.md)*

---

**Next up:** [ROUTING.md](./ROUTING.md) — How HTTP requests are routed; adding new pages.
