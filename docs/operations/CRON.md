# ATTE Production Manager — Cron Jobs Reference

---

## Lock Pattern

All cron jobs use `src/classes/Utils/class-locker.php` (91 lines) to prevent concurrent execution.

| Class | Namespace | Lock file path |
|---|---|---|
| `Locker` | `Atte\Utils` | `public_html/var/locks/<lock-name>.lock` |

**Mechanism** (lines 27–50 of `class-locker.php`):
1. Creates the lock file if it does not exist (line 30–34).
2. Opens a file handle (line 39).
3. Calls `flock()` — **blocking** (`LOCK_EX`) by default, or **non-blocking** (`LOCK_EX | LOCK_NB`) when `lock(false)` is passed.
4. `isLocked()` (lines 73–89) acquires a non-blocking lock to test; if it succeeds it immediately releases — a classic check-then-act race-condition guard.
5. `unlock()` (lines 60–67) releases the lock and closes the file handle. The file is **not deleted** after release to avoid inode race conditions.
6. The destructor also calls `unlock()` (line 19), so a killed process releases the lock when PHP exits — but a hard `kill -9` leaves the lock file dangling until the next run.

> **Ops note:** If a cron job crashes mid-flight and the lock is not released, the next run will see `isLocked() == true` and exit early with `"Process is already running."`. In that case, manually remove the stale lock file from `public_html/var/locks/` before re-running.

---

## Logs

With the exception of `flowpin-sku-update.php` (which maintains its own daily-log directory), all cron jobs write to:

```
public_html/var/logs/
```

This path is not hard-coded in the scripts — it is resolved via `ROOT_DIRECTORY` / `__DIR__` relative paths. Confirm the directory exists and is writable by the web-server / CLI user.

| Job | Log location |
|---|---|
| `flowpin-sku-update.php` | `src/cron/logs/<YYYY-MM-DD>/flowpin-sku-update-<YYYY-MM-DD>-<HH>h.log` |
| All others | `public_html/var/logs/` (or stdout via `echo`) |

---

## Job 1 — `warehouse-data-gs-upload.php`

**File:** `src/cron/warehouse-data-gs-upload.php` (121 lines)

### Purpose
Aggregates live inventory quantities across **all** magazines (SKU, THT, SMD, parts) from the MSA database and writes the result to a Google Sheet tab named `ds_php_mag`. The sheet serves as a real-time stock overview for operations staff.

### Trigger / Schedule
External cron; see deployment docs. No schedule is defined in the file.

### Inputs
| Source | Table / Query |
|---|---|
| MSA DB | `magazine__list` — active sub-magazines (`isActive = 1`) |
| MSA DB | `list__sku`, `list__tht`, `list__smd`, `list__parts` — device/component name lookups |
| MSA DB | `inventory__sku`, `inventory__tht`, `inventory__smd`, `inventory__parts` — quantity data, joined with `magazine__list` to get `type_id` |
| Timestamp | `ref__timestamp` row `id = 5` — updated on success |

### Outputs
| Target | Details |
|---|---|
| Google Sheet | Spreadsheet `1rV1rbLXDdsOxT49sgJNm1Aicldg8ZvBdQo9yaX314QI`, tab `ds_php_mag` — full grid overwrite |
| MSA DB | `ref__timestamp` — `last_timestamp` updated to current time on success |

### Locks
- **Lock file:** `public_html/var/locks/gs_upload.lock` (line 7)
- Uses `Locker::isLocked()` pre-check (lines 9–11) and `Locker::lock()` / `unlock()` in `try … finally` (lines 14, 120)

### Key Classes / Functions Used
| Class | Namespace | Role |
|---|---|---|
| `GoogleSheets` | `Atte\Api` | `writeToSheet()` — writes data to Google Sheets API |
| `MsaDB` | `Atte\DB` | Singleton DB access; `readIdName()`, `query()`, `update()` |
| `Locker` | `Atte\Utils` | Prevents concurrent runs |

Anonymous function `processInventory($type, $list__magazine)` (lines 34–82) does the per-type aggregation.

### Side Effects
- **DB writes:** `ref__timestamp.last_timestamp` updated on success (line 105).
- **DB reads:** All `inventory__*` tables, `magazine__list`, `list__*` tables.
- **Google Sheets API:** Full tab overwrite via `writeToSheet()`.
- **Transaction:** `beginTransaction()` / `commit()` on `$MsaDB->db`; `rollback()` on any exception (lines 20, 111, 115).

### Failure Handling
- Outer `try … finally` (lines 13–121): lock is **always** released in `finally`.
- Inner `try … catch` (lines 22–118): on exception → `rollback()` and echo message.
- `writeToSheet()` failure throws `Exception("Failed to write data to Google Sheets")` (line 107).

---

## Job 2 — `warehouse-comparison-gs-upload.php`

**File:** `src/cron/warehouse-comparison-gs-upload.php` (179 lines)

### Purpose
Produces a **side-by-side stock comparison** between the local MSA warehouse and the external FlowPin ERP. Outputs columns: summed quantities (ATTE + Zagranica), main-magazine totals, per-magazine breakdown, MJ sub-magazine (mag 11), and difference calculations. Written to Google Sheet tab `ds_php_sku_quantity`.

Intended for ops to reconcile FlowPin orders vs. MSA inventory levels.

### Trigger / Schedule
External cron; see deployment docs. No schedule is defined in the file.

### Inputs
| Source | Table / Query |
|---|---|
| MSA DB | `magazine__list` — main magazines (`type_id = 1, isActive = 1`) |
| MSA DB | `list__sku`, `list__tht`, `list__smd` — name lookups |
| MSA DB | `inventory__sku`, `inventory__tht`, `inventory__smd` — quantity data joined with `magazine__list` |
| FlowPin DB | `ProductTypes`, `Products` — FlowPin product symbols and warehouse quantities |

### Outputs
| Target | Details |
|---|---|
| Google Sheet | Spreadsheet `1dVUCdqrqaMKBEN_ol75SjiFODKelDKX0fqRaO94_ydM`, tab `ds_php_sku_quantity`, range `A2` (second row, preserving headers) |

### Locks
- **Lock file:** `public_html/var/locks/warehouse_comparison_gs_upload.lock` (line 12)
- Same pre-check / try-finally pattern as Job 1.

### Key Classes / Functions Used
| Class | Namespace | Role |
|---|---|---|
| `GoogleSheets` | `Atte\Api` | `writeToSheet()` |
| `MsaDB` | `Atte\DB` | MSA database access |
| `FlowpinDB` | `Atte\DB` | FlowPin ERP database access |
| `Locker` | `Atte\Utils` | Concurrent-run guard |

### Side Effects
- **DB reads:** Both MSA and FlowPin databases.
- **Google Sheets API:** Partial overwrite starting at row 2.
- **No DB writes** — read-only job.

### Failure Handling
- `try … catch … finally` (lines 18–179): lock always released in `finally`.
- On Google Sheets write failure → `Exception("Failed to write data to Google Sheets")` (line 172), caught by outer catch → echo message.

---

## Job 3 — `update-part-prices.php`

**File:** `src/cron/update-part-prices.php` (74 lines)

### Purpose
Reads a price list from a Google Sheet (`ceny_tmp` tab), compares each `BuyPrice_PLN` against the current `list__parts.price` in the MSA DB, and updates any part whose price has changed. After updating a price, calls `PriceCalculator::propagatePriceChange()` to recalculate and update all BOMs that reference the changed part.

### Trigger / Schedule
External cron; see deployment docs. No schedule is defined in the file.

### Inputs
| Source | Details |
|---|---|
| Google Sheet | Spreadsheet `1OowYceg8hWtuCmnqPiqCyg5N3rVaAngEvmnGRhjeOew`, tab `ceny_tmp`, columns A:G |
| MSA DB | `list__parts` — current `id`, `name`, `price` for all parts |

### Outputs
| Target | Details |
|---|---|
| MSA DB | `list__parts.price` — updated for any part where `|new - old| > 0.000001` |
| MSA DB | BOM recalculation via `PriceCalculator::propagatePriceChange()` for each changed part |

### Locks
- **No lock file.** This job does **not** use `Locker`. If concurrent runs are possible, add a lock.

### Key Classes / Functions Used
| Class | Namespace | Role |
|---|---|---|
| `GoogleSheets` | `Atte\Api` | `readSheet()` — reads price data from Google Sheets |
| `MsaDB` | `Atte\DB` | Singleton DB; `query()`, `update()` |
| `PriceCalculator` | `Atte\Utils\Bom` | `propagatePriceChange()` — recalculates BOM costs downstream |

### Side Effects
- **DB writes:** `list__parts.price` per changed part; downstream BOM cost recalculations.
- **Google Sheets API:** Read-only (no write).
- **stdout:** Polish-language progress messages printed to console.

### Failure Handling
- No `try/catch` at the top level. Early exit with `exit(1)` on:
  - Sheet read failure (`!$values || count($values) < 2`) — line 22
  - Missing required columns (`PartNo`, `BuyPrice_PLN`) — line 33
- Per-row errors are **not** caught individually — a price update or BOM propagation failure will cause the entire script to terminate.

---

## Job 4 — `flowpin-sku-update.php`

**File:** `src/cron/flowpin-sku-update.php` (1025 lines)

### Purpose
The most complex job. It **synchronises SKU inventory movements** from the FlowPin ERP into the MSA database. It processes four categories of events fetched from FlowPin's `ProductQuantityHistoryView`:

1. **Sold** — finalised orders (outbound, reduces MSA stock)
2. **Returned** — customer returns (inbound, increases MSA stock)
3. **Moved** — inter-warehouse transfers (in/out pair)
4. **Produced / Inter** — production output and intermediate transfers

Each event results in an `inventory__sku` row (or a pair of rows for moves) with a `transfer_group_id` linking related operations. The job maintains **crash-recovery checkpoints** per operation type in `ref__flowpin_checkpoints`, and a **progress session** in `ref__flowpin_update_progress` that is updated after every single record.

### Trigger / Schedule
External cron; see deployment docs. No schedule is defined in the file.

### Inputs
| Source | Table / View / Record |
|---|---|
| FlowPin DB | `[report].[ProductQuantityHistoryView]` — sold, returned, moved, produced events |
| MSA DB | `ref__flowpin_checkpoints` — per-operation-type `checkpoint_event_id` |
| MSA DB | `ref__timestamp id=4` — main `params` (last processed EventId) |
| MSA DB | `list__sku` — SKU name lookups |
| MSA DB | `list__parts`, `bom__*` — BOM validation per record |
| MSA DB | `ref__flowpin_update_progress` — session tracking |
| MSA DB | `inventory__transfer_groups` — transfer group creation |

### Outputs
| Target | Details |
|---|---|
| MSA DB | `inventory__sku` — one row per sold/returned/moved/produced event |
| MSA DB | `inventory__tht`, `inventory__smd`, `inventory__parts` — production components (via `SkuProductionProcessor`) |
| MSA DB | `inventory__transfer_groups` — groups linking related inventory movements |
| MSA DB | `ref__flowpin_checkpoints` — updated after each operation type |
| MSA DB | `ref__flowpin_update_progress` — session row updated after every record |
| MSA DB | `ref__timestamp id=4` — `params` updated to highest processed EventId on success |
| Google Sheets | Inventory changes summary written to `src/cron/logs/<date>/flowpin-inventory-changes-<date>-<HH>h.log` (local file, not Sheets) |

### Locks
- **Lock file:** `public_html/var/locks/flowpin.lock` (line 410)
- Uses `Locker::lock(FALSE)` — **non-blocking** (line 411). If lock cannot be acquired, logs a warning and exits with code `1`.
- **Crash-recovery cleanup** (lines 420–450): on startup, any session still marked `running` in `ref__flowpin_update_progress` is marked `error` — this is safe because the lock guarantees no two runs overlap.

### Key Classes / Functions Used
| Class | Namespace | Role |
|---|---|---|
| `MsaDB` | `Atte\DB` | MSA database singleton |
| `FlowpinDB` | `Atte\DB` | FlowPin ERP database |
| `NotificationRepository` | `Atte\Utils` | Creates admin notifications on per-record errors |
| `UserRepository` | `Atte\Utils` | Resolves `ByUserEmail` → user record |
| `TransferGroupManager` | `Atte\Utils` | Creates/links transfer groups |
| `BomRepository` | `Atte\Utils` | BOM lookup for production processing |
| `SkuProductionProcessor` | `Atte\Utils\Production` | Handles BOM-explosion for production events |
| `GoogleSheets` | `Atte\Api` | (imported but not used in this file) |
| `Locker` | `Atte\Utils` | Non-blocking concurrent-run guard |

### Side Effects
- **DB writes:** Every event creates 1–N `inventory__sku` rows and a `transfer_group`; production events additionally write to `inventory__tht/smd/parts`.
- **DB reads:** Large join queries on both MSA and FlowPin databases per record.
- **Notifications:** `NotificationRepository::createNotificationFromException()` called for every record that fails validation — surfaces in the admin panel.
- **Checkpoints:** Updated **after every individual record** (not batched) for fine-grained crash recovery.
- **Progress session:** Updated after every record; tracks `session_id`, `total_records`, `processed_records`, `starting_event_id`, `finishing_event_id`, `created_transfer_count`, `created_group_count`, `status`.
- **Log files:** Three local files per hour in `src/cron/logs/<YYYY-MM-DD>/`:
  - `flowpin-sku-update-<date>-<HH>h.log` — main log
  - `flowpin-sku-update-errors-<date>-<HH>h.log` — errors/warnings only
  - `flowpin-inventory-changes-<date>-<HH>h.log` — per-SKU change summary
- **Shutdown handler** (lines 325–408): if the process is killed or crashes, the shutdown function marks the session `error` with counts of transfers/groups already created before releasing the lock.

### Failure Handling
- **Per-record transactions:** Each record is wrapped in `beginTransaction() / commit()` (or `rollback()` on error) — one bad record does not kill the whole run.
- **Per-record errors:** Increment `$errorCount`, log, create admin notification, and continue.
- **Critical errors** (caught in top-level `catch (\Throwable $exception)`, lines 970–1017): transaction rollback, session marked `error`, counts preserved.
- **`$scriptCompletedNormally` flag:** Set to `true` at line 968 only when the main `try` block completes without throwing. The shutdown handler (lines 325–408) uses this to distinguish a clean exit from a crash — if the flag is `false` and the session is still `running`, it is marked `error`.
- **Zero records:** Early exit at line 584–594 — marks session `completed` with `processed_records = 0`.

---

## Job 5 — `bom-flat-tht-gs-upload.php`

**File:** `src/cron/bom-flat-tht-gs-upload.php` (162 lines)

### Purpose
Flattens the Bill of Materials for every **active THT device** to its base `parts` and writes the result to Google Sheet tab `ds_php_bom_flat_tht`. The output lists each THT device with its version, then each component part, its description, quantity (accounting for nested sub-assemblies), source sub-assembly, laminate/version info, and a warning flag if the device lacks a default BOM assignment.

### Trigger / Schedule
External cron; see deployment docs. No schedule is defined in the file.

### Inputs
| Source | Table / Query |
|---|---|
| MSA DB | `list__parts` — `id`, `name`, `description` |
| MSA DB | `list__smd` — `id`, `name`, `description`, `default_bom_id` |
| MSA DB | `list__tht` — `id`, `name`, `description`, `default_bom_id` |
| MSA DB | `list__sku` — `id`, `name`, `description` |
| MSA DB | `list__laminate` — laminate name lookup |
| MSA DB | `bom__tht`, `bom__smd`, `bom__sku` — BOM version/layer info |
| MSA DB | `bom__flat` — BOM component rows (`parts_id`, `smd_id`, `tht_id`, `sku_id`, `quantity`) |

### Outputs
| Target | Details |
|---|---|
| Google Sheet | Spreadsheet `1dVUCdqrqaMKBEN_ol75SjiFODKelDKX0fqRaO94_ydM`, tab `ds_php_bom_flat_tht` — full grid overwrite |

### Locks
- **Lock file:** `public_html/var/locks/bom_flat_tht_gs_upload.lock` (line 7)
- Standard pre-check / try-finally pattern (lines 9–11, 14, 161).

### Key Classes / Functions Used
| Class | Namespace | Role |
|---|---|---|
| `GoogleSheets` | `Atte\Api` | `writeToSheet()` |
| `MsaDB` | `Atte\DB` | All DB queries |
| `Locker` | `Atte\Utils` | Concurrent-run guard |

Anonymous function `$flattenBom` (lines 40–94) — recursive closure that walks the BOM tree.

### Side Effects
- **DB reads only** — no writes to MSA tables.
- **Google Sheets API:** Full tab overwrite.
- **Transaction:** `beginTransaction() / commit()` (lines 21, 151); `rollback()` on exception (line 154).

### Failure Handling
- `try … catch … finally` with `Locker` in `finally`.
- `writeToSheet()` failure → `Exception` (line 148) → outer catch echoes message.
- Any DB exception during the inner transaction → `rollback()` and re-throw.

---

## Job 6 — `bom-flat-sku-gs-upload.php`

**File:** `src/cron/bom-flat-sku-gs-upload.php` (158 lines)

### Purpose
Flattens the Bill of Materials for every **active SKU** (excluding those ending in `_INTER`) to its base `parts` and writes the result to Google Sheet tab `ds_php_bom_flat_sku`. Unlike the THT job, this one tracks the **full source path** (up to 3 levels deep) — showing which sub-assemblies contributed each part and at what quantity — plus a "missing default BOM" warning per source node.

### Trigger / Schedule
External cron; see deployment docs. No schedule is defined in the file.

### Inputs
| Source | Table / Query |
|---|---|
| MSA DB | `list__parts`, `list__smd`, `list__tht`, `list__sku` — name/description lookups |
| MSA DB | `bom__sku`, `bom__smd`, `bom__tht` — BOM existence check |
| MSA DB | `bom__flat` — component rows |

### Outputs
| Target | Details |
|---|---|
| Google Sheet | Spreadsheet `1dVUCdqrqaMKBEN_ol75SjiFODKelDKX0fqRaO94_ydM`, tab `ds_php_bom_flat_sku` — full grid overwrite |

### Locks
- **Lock file:** `public_html/var/locks/bom_flat_sku_gs_upload.lock` (line 7)
- Standard pre-check / try-finally pattern (lines 9–11, 14, 157).

### Key Classes / Functions Used
| Class | Namespace | Role |
|---|---|---|
| `GoogleSheets` | `Atte\Api` | `writeToSheet()` |
| `MsaDB` | `Atte\DB` | All DB queries |
| `Locker` | `Atte\Utils` | Concurrent-run guard |

Anonymous function `$flattenBom` (lines 40–93) — recursive closure with path tracking.

### Side Effects
- **DB reads only** — no writes.
- **Google Sheets API:** Full tab overwrite.
- **Transaction:** `beginTransaction() / commit()` (lines 21, 147); `rollback()` on exception (line 150).

### Failure Handling
- Same pattern as Job 5.
- `writeToSheet()` failure → `Exception` (line 144) → caught by outer catch.

---

## Job 7 — `import-procurement-history-from-gsheet.php`

**File:** `src/cron/import-procurement-history-from-gsheet.php`

### Purpose
One-shot CLI importer that seeds the procurement documents (`purchase__rfq`, `purchase__order`, `purchase__order_receipt` + items) from three Google Sheets into the MSA database. It is a **migration tool**, not a recurring job — meant to be run once (and re-run safely) when history needs to be brought in.

### Inputs
| Source | Details |
|---|---|
| Google Sheet — `Lista RFQ` (spreadsheet `19eiRJEs6yvy7r6ZpB74HjS-VwkCVJ5oHE9voCjUOO5U`, tab `RFQ_list`, A:T) | RFQs. Header row 1. Creation date format `yyyy-mm-dd HH:MM:SS`. |
| Google Sheet — `Otwarte Zamowienia PO` (spreadsheet `1ELkiaSIUjDfY1wSHG5Pl5Y1MQ1-gJU9Sory1T7RRDwA`, tab `purchase_order_list`, A:W) | POs. Header row 1, data lives in rows ~5855–9159. |
| Google Sheet — `Lista przyjętych zamówień` (spreadsheet `1F-uzgUWxYfUYaFNPhfcMyGpd8qrkMyXVBb6V_TlYnKA`, tab `to_warehouse`, A:Y) | GRNs. Header row 1; column 13 (PartNo) is intentionally **unlabeled** — script asserts it is empty before proceeding. |
| MSA DB | `list__vendor`, `list__vendor_part`, `list__currency`, `user`, `magazine__list` — lookups for vendor / vendor-part / currency / user / magazine validation. |

### Outputs (writes only in LIVE mode)
| Target | Details |
|---|---|
| `purchase__rfq` + `purchase__rfq_item` | One row per RFQ sheet document; one line item per resolved line. State = `converted` if any PO references the RFQ ID, else `responded` (when priced lines exist, `responded_at = created_at`), else `sent`. |
| `purchase__order` + `purchase__order_item` | One row per PO ID from the union of sheet B (authoritative) and sheet C (positions only present in sheet C → `qty_ordered = SUM(C qty)`). `state` follows `received` > `partially_received` > `confirmed` > `sent`. |
| `purchase__order_receipt` + `purchase__order_receipt_item` | One receipt per `(PO ID, GRN date)` group; `document_number` = lowest GRN ID in the group; `comment` = `"Import z arkusza. GRN: <ids>"`. |
| `list__vendor.default_currency` | For each vendor whose most recent priced line (across all sheets, GRN > PO > RFQ) is in a currency different from the current default. |
| `purchase__number_counter` | `INSERT ... ON DUPLICATE KEY UPDATE last_value = GREATEST(last_value, VALUES(last_value))` — RFQ/PO per-vendor seq, GRN `vendor_id=0` seq. |

### Re-run safety
Documents are matched by their sheet number (`rfq_number` / `po_number` / receipt `document_number`) and **skipped** if already present in the DB — fully idempotent.

A receipt is imported only when its PO was imported **by this run**. Receipts whose PO already exists in `purchase__order` from a previous run are skipped, because we cannot reliably map sheet `(PO, position)` to existing `purchase__order_item.id` without a stable ordinal column. Run `--step=all` once on a clean DB to get receipts in.

### CLI flags
- `--dry-run` — zero writes (no inserts, no counter updates, no vendor currency changes); still reads Sheets + DB.
- `--step=all|rfq|po|grn` — run a single step.
- `--user=ID` — explicit admin user for `created_by` / `received_by`; default = lowest `user_id` with `isAdmin=1 AND isActive=1`.
- `--magazine=ID` — explicit sub-magazine for receipt items; default = `user.sub_magazine_id`. Aborts with a clear message if neither resolves to a real magazine.

### Counter-table guard
Refuses to run in LIVE mode if `purchase__number_counter` is in the OLD shape (`PK = (year, type)`, `enum('rfq','po','pz')`). In `--dry-run`, prints a WARNING and skips the counter step. Run `php src/cron/migrate-to-procurement-schema.php` first.

### Locks
- **Lock file:** `public_html/var/locks/procurement_history_import.lock`.
- Standard `Locker::isLocked()` pre-check + shutdown-function unlock pattern (mirrored from `seed-component-procurement-from-gsheet.php`).

### Skip report
A CSV is appended to `public_html/var/logs/procurement-history-skipped-YYYY-MM-DD.csv` (LIVE only — never written in `--dry-run`) with one row per skipped line: `sheet, row, doc_id, position, vendor_id, vendor_part_no, part_no, reason`.

### Side Effects
- **DB writes (LIVE only):** All `purchase__*` header + item tables, `list__vendor.default_currency`, `purchase__number_counter`.
- **No stock writes:** Never touches `inventory__parts`, never creates transfer groups.
- **Google Sheets API:** Read-only (three `spreadsheets_values.get` calls, with 401-retry via the stored refresh token in the `google_oauth` MSA table).
- **Per-document transactions:** `beginTransaction()` / `commit()` per document; `rollback()` on error with logged reason; continues with the next document.
- **Daily log:** `public_html/var/logs/procurement-history-import-YYYY-MM-DD.log`.

### Failure Handling
- Sheet read errors: abort with a clear message (the auth path mirrors `seed-component-procurement-from-gsheet.php`; refresh token is persisted back into `google_oauth` after a 401).
- Header-layout drift (sheet A/B/C text changes): abort with a per-column diff.
- Per-document errors: rollback + log, continue with the next document.
- Counter-table in OLD shape: warn-and-skip in `--dry-run`, abort in LIVE.

---

## Quick Reference Table

| Job | Lock file | DB writes | Google Sheets | Unique behaviour |
|---|---|---|---|---|
| `warehouse-data-gs-upload` | `gs_upload.lock` | `ref__timestamp` | `ds_php_mag` (spreadsheet 1) | Aggregates all 4 inventory types |
| `warehouse-comparison-gs-upload` | `warehouse_comparison_gs_upload.lock` | — | `ds_php_sku_quantity` (spreadsheet 2) | Cross-db MSA vs. FlowPin |
| `update-part-prices` | *(none)* | `list__parts`, BOM recalc | — (read-only) | Price diff + BOM propagation |
| `flowpin-sku-update` | `flowpin.lock` (non-blocking) | `inventory__*`, checkpoints, progress | — (local logs) | Full ERP sync; per-record transactions + checkpoints |
| `bom-flat-tht-gs-upload` | `bom_flat_tht_gs_upload.lock` | — | `ds_php_bom_flat_tht` (spreadsheet 2) | Recursive BOM flatten, single-level source |
| `bom-flat-sku-gs-upload` | `bom_flat_sku_gs_upload.lock` | — | `ds_php_bom_flat_sku` (spreadsheet 2) | Recursive BOM flatten, 3-level source path |
| `import-procurement-history-from-gsheet` | `procurement_history_import.lock` | `purchase__*`, `list__vendor.default_currency`, `purchase__number_counter` | — (read-only) | One-shot historical migration; safe re-run |

**Spreadsheet IDs:**
- (1) `1rV1rbLXDdsOxT49sgJNm1Aicldg8ZvBdQo9yaX314QI` — used by `warehouse-data-gs-upload`
- (2) `1dVUCdqrqaMKBEN_ol75SjiFODKelDKX0fqRaO94_ydM` — shared by `warehouse-comparison`, `bom-flat-tht`, `bom-flat-sku`

---

## Adding a New Cron Job

1. **Use `Locker`** unless the job is provably idempotent and safe to overlap. Place `Locker::isLocked()` pre-check before `lock()`.
2. **Always release the lock** in a `finally` block or destructor.
3. **Wrap DB writes in transactions** for atomicity.
4. **Log errors** — at minimum `echo` a message; prefer a structured logger.
5. **Update `ref__timestamp`** if the job modifies any data that other jobs depend on (as `warehouse-data-gs-upload` does for row `id=5`).
6. **Place the file** in `src/cron/` and register the schedule in the deployment/cron documentation.

---

**Next up:** [STACK.md](../reference/STACK.md) — Technology stack; PHP 8.0+, MySQL 5.7+, dependencies.
