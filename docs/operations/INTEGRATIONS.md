# ATTE Production Manager — Integration Reference

This document describes the two external integrations in the ATTE Production Manager codebase: **Google Sheets / OAuth** and **FlowPin**.

---

## 1. Google Sheets / OAuth

### Overview

ATTE uses Google Sheets as a data export target for BOM (Bill of Materials), warehouse inventory, and comparative analysis. The integration uses OAuth 2.0 with a refresh-token flow to maintain a persistent connection to the Google Sheets API v4.

### OAuth Flow

The OAuth handshake is managed by [Hybridauth](https://hybridauth.github.io/) (a PHP OAuth library) and two custom classes:

| Class | File | Responsibility |
|---|---|---|
| `Hybridauth\Provider\Google` | `config/config-google-sheets.php` | Handles the authorization redirect and token exchange (web flow only — `session_start()` makes this file unsafe to load from CLI after stdout output) |
| `Atte\Api\GoogleOAuth` | `src/classes/Api/class-googleoauth.php` | Stores/retrieves tokens in the MSA database (`google_oauth` table) and triggers token refresh. CLI-safe: `regenerateToken()` defines `GOOGLE_CLIENT_ID/SECRET` from `$_ENV` lazily, only when a refresh is needed |
| `Atte\Api\GoogleSheets` | `src/classes/Api/class-googlesheets.php` | Provides high-level methods (`readSheet`, `writeToSheet`, `appendToSheet`) wrapping the Google Sheets API v4. CLI-safe: the class no longer requires `config-google-sheets.php`. On HTTP 401 it refreshes the token via `GoogleOAuth` and rebuilds the `Google_Client` (avoids the bearer-token cache leak in `ScopedAccessTokenMiddleware`'s `MemoryCacheItemPool`) |

**Authorization callback** — after the user approves access, Google redirects to:

```
/components/GoogleSheets/callback.php
```

This script (`callback.php`) calls `$adapter->authenticate()`, obtains the access/refresh token bundle, and persists it via `GoogleOAuth::update_access_token()`.

**Token refresh** — `GoogleOAuth::regenerateToken()` (called automatically by `GoogleSheets` on HTTP 401):

1. Reads the stored refresh token from the `google_oauth` table.
2. POSTs to `https://accounts.google.com/o/oauth2/token` with `grant_type=refresh_token`.
3. Saves the new token bundle back to the database, preserving the original refresh token.

### GoogleSheets Class — Key Methods

| Method | Signature | Description |
|---|---|---|
| `readSheet` | `(spreadsheetId, sheetName, range)` | Reads values from a sheet range (e.g. `"A1:D10"`). Returns `array\|false`. |
| `writeToSheet` | `(spreadsheetId, sheetName, range, values)` | Clears the target range then writes, overwriting existing data. Returns updated cell count or `false`. |
| `appendToSheet` | `(spreadsheetId, sheetName, range, values)` | Appends rows after the last populated row (no override). Returns updated cell count or `false`. |

All three methods auto-refresh the OAuth token on 401 responses by calling `$OAuth->regenerateToken()` and retrying once.

### Config File Structure

**`config/config-google-sheets.php`**

```php
$config = [
    'callback' => 'http://{BASEURL}/components/GoogleSheets/callback.php',
    'keys'     => ['id' => GOOGLE_CLIENT_ID, 'secret' => GOOGLE_CLIENT_SECRET],
    'scope'    => 'https://www.googleapis.com/auth/spreadsheets',
    'authorize_url_parameters' => [
            'approval_prompt' => 'force',   // force re-acquire refresh token when needed
            'access_type'     => 'offline'  // request refresh token
    ]
];
$adapter = new Hybridauth\Provider\Google($config);
```

The `Hybridauth\Provider\Google` instance (`$adapter`) is used only in `callback.php` for the initial OAuth handshake. All subsequent API calls go through `GoogleSheets`.

### UI Routes

| Route | File | Purpose |
|---|---|---|
| `admin/synchronization/sheets` | `public_html/components/Admin/Synchronization/sheets/flowpin-sheets-view.php` | Main admin panel — contains three tabs: **Integracja z Google Sheets** (export), **Aktualizacja Cen** (price sync from Sheets), **Import Zamówień** (order import from Sheets) |

The **Integracja z Google Sheets** tab exposes four upload buttons:

| Button ID | Cron endpoint |
|---|---|
| `#sendWarehousesToGS` | `src/cron/warehouse-data-gs-upload.php` |
| `#sendBomFlatToGS` | `src/cron/bom-flat-tht-gs-upload.php` |
| `#sendWarehouseComparisonToGS` | `src/cron/warehouse-comparison-gs-upload.php` |
| `#sendBomFlatSkuToGS` | `src/cron/bom-flat-sku-gs-upload.php` |

Upload status is polled via `public_html/components/GoogleSheets/get-upload-status.php`, which uses `Atte\Utils\Locker('gs_upload.lock')` to detect whether a cron job is currently running.

### Cron Jobs (Google Sheets Upload)

| Cron script | Purpose |
|---|---|
| `src/cron/warehouse-data-gs-upload.php` | Exports current warehouse inventory states to Google Sheets |
| `src/cron/bom-flat-tht-gs-upload.php` | Exports flat THT BOM data |
| `src/cron/bom-flat-sku-gs-upload.php` | Exports flat SKU BOM data |
| `src/cron/warehouse-comparison-gs-upload.php` | Exports a comparison analysis between FlowPin warehouse data and MSA data |

All four scripts instantiate `Atte\Api\GoogleSheets` and call `writeToSheet()` (or `appendToSheet()`). They are triggered by admin UI buttons via AJAX POST requests and are protected by a file-based lock (`gs_upload.lock`).

### Required Environment Variables

| Variable | Purpose |
|---|---|
| `GOOGLE_CLIENT_ID` | OAuth 2.0 client ID (Google Cloud Console credential) |
| `GOOGLE_CLIENT_SECRET` | OAuth 2.0 client secret |
| `BASEURL` | Application base URL (used to construct the OAuth callback URL) |

---

## 2. FlowPin

### Overview

FlowPin is an external production management system that tracks manufacturing events — production output, sales, returns, and inter-warehouse transfers. ATTE synchronises FlowPin data into its own MSA database on a scheduled basis, creating inventory transfers and transfer groups for each operation.

The sync is **event-driven with crash recovery**: each operation type (production, sold, returned, moved) maintains its own checkpoint (`ref__flowpin_checkpoints`), and the cron job tracks progress in `ref__flowpin_update_progress` so a interrupted run can be resumed without duplicating records.

### FlowpinDB Class

**File:** `src/classes/DB/class-flowpindb.php`

```php
class FlowpinDB extends BaseDB  // extends Atte\DB\BaseDB
```

Establishes a connection to the FlowPin SQL Server database using credentials from env vars.

| Method | Query |
|---|---|
| `getWarehouses()` | `SELECT Id, Name, Description FROM [dbo].[Warehouses] WHERE CompanyId = 1` |
| `getUsers()` | `SELECT anu.* FROM [dbo].[AspNetUsers] anu JOIN [dbo].[User2Company] u2c ON anu.Id = u2c.User_Id WHERE Company_Id = 1` |

### Routes

| Route | File | Purpose |
|---|---|---|
| `admin/synchronization/flowpin` | `public_html/components/Admin/Synchronization/flowpin/flowpin-status-view.php` | Admin sync panel — two tabs: **Aktualizacja** (run sync with pre-flight analysis) and **Sesje** (historical session browser) |
| `flowpin/test` | `public_html/components/tests/warehouse_state_view.php` | Debug/diagnostic view for FlowPin warehouse state |

The **Aktualizacja** tab provides a two-step workflow:

1. **Step 1 — Analiza (Analysis):** Runs a pre-flight check to surface user/magazine/SKU problems before committing to a sync.
2. **Step 2 — Uruchom aktualizację (Run update):** Executes the sync, with real-time progress bars tracking records processed, current operation type, and last EventId.

Stale/crashed sessions are detected by the absence of the `flowpin.lock` file lock and are marked as `error` in `ref__flowpin_update_progress`.

### Sync Sessions Concept

Session history is managed in `public_html/components/Admin/Synchronization/flowpin/sessions/`:

| File | Purpose |
|---|---|
| `flowpin-sessions-view.php` | Renders the session history table with date-range and status filters |
| `flowpin-sessions-view.js` | Client-side logic for loading and displaying sessions |
| `get-sessions.php` | Backend endpoint returning paginated session records from `ref__flowpin_update_progress` |
| `get-session-transfers.php` | Backend endpoint returning transfer records linked to a specific session |
| `get-filter-options.php` | Returns available filter values (date range, status) |
| `cancel-session.php` | Marks a running session as `error` and releases its lock |
| `modals.php` | UI modals for session detail views |
| `reset-session.php` | Allows an admin to manually reset a stale session (within `update/` subdirectory) |

Each session record in `ref__flowpin_update_progress` tracks:

- `session_id` — unique session identifier
- `status` — `running`, `completed`, or `error`
- `total_records`, `processed_records` — progress counters
- `starting_event_id`, `finishing_event_id` — FlowPin EventId range processed
- `created_transfer_count`, `created_group_count` — inventory records created
- `current_operation_type` — current phase: `Pobieranie danych z FlowPin...`, `sold_sku`, `returned_sku`, `moved_sku`, `production_sku`, or `completed`
- `started_at`, `updated_at`

### Cron Job

**File:** `src/cron/flowpin-sku-update.php`

This is the main sync engine. It:

1. Acquires `flowpin.lock` (prevents concurrent runs).
2. Creates a progress session in `ref__flowpin_update_progress`.
3. Fetches four datasets from FlowPin (all with individual checkpoint support):
   - **Sold SKUs** — `sold_sku` checkpoint
   - **Returned SKUs** — `returned_sku` checkpoint
   - **Moved SKUs** — `moved_sku` checkpoint
   - **Produced SKUs** — `production_sku` checkpoint
4. For each record: validates the user, ensures the SKU exists (auto-creates if missing), resolves the active BOM, creates or reuses a transfer group, and inserts an inventory transfer (`inventory__sku` table).
5. Updates checkpoints and progress after every record.
6. Writes an inventory-change summary to a log file and optionally to Google Sheets.
7. On completion, updates `ref__timestamp.id=4` with the highest processed EventId (the global checkpoint).

Key supporting classes used within the cron:

- `Atte\Utils\Locker` — file-based process lock
- `Atte\Utils\TransferGroupManager` — creates/links transfer groups
- `Atte\Utils\BomRepository` — resolves active BOM for a SKU
- `Atte\Utils\Production\SkuProductionProcessor` — handles production record logic
- `Atte\Api\GoogleSheets` — writes inventory-change summaries to Sheets

### Required Environment Variables

| Variable | Purpose |
|---|---|
| `FLOWPINURL` | SQL Server connection string for FlowPin database (e.g. `sqlsrv:Server=192.168.1.5;Database=flowpin_solution;TrustServerCertificate=true;`) |
| `FLOWPINUSERNAME` | Database username |
| `FLOWPINPASSWORD` | Database password |

---

## Architecture Summary

```
                    ┌─────────────────────────────────────────────┐
                    │              Admin Browser                  │
                    └──────────┬──────────────────────┬───────────┘
                               │                      │
              /admin/synchronization/flowpin   /admin/synchronization/sheets
                               │                      │
        ┌──────────────────────┴───┐    ┌─────────────┴───────────┐
        │   flowpin-status-view.php│    │  flowpin-sheets-view.php│
        │   (pre-flight + run sync)│    │  (upload buttons → AJAX)│
        └──────────────┬───────────┘    └───────────┬─────────────┘
                       │                            │
        ┌──────────────▼──────────────┐   ┌─────────▼──────────────┐
        │ src/cron/flowpin-sku-update │   │ src/cron/*-gs-upload   │
        │      (FlowPin → MSA)        │   │   (MSA → Google Sheets)│
        └──────────────┬──────────────┘   └─────────┬──────────────┘
                       │                            │
        ┌──────────────▼──────────────┐   ┌─────────▼──────────────┐
        │      FlowpinDB class        │   │    GoogleSheets class  │
        │  (SQL Server, FlowPin DB)   │   │   (Google Sheets API)  │
        └─────────────────────────────┘   └────────────────────────┘
                        │
         ┌──────────────▼──────────────────────────────────────────────┐
         │              GoogleOAuth class                               │
         │   (token storage in MSA `google_oauth` table + refresh)      │
         └──────────────────────────────────────────────────────────────┘
```

---

**Next up:** [CRON.md](./CRON.md) — Scheduled jobs; what runs automatically and when.
