# AGENTS.md — ATTE Production Manager

Compact guide for agents working in this repo. Read `docs/INDEX.md` for the
full deep-dive (architecture, routing, database, modules, classes, cron,
integrations, stack).

---

## What this project is

A manufacturing inventory and production-tracking system for ATTE Power Sp.
z o.o. — vanilla PHP 8 + jQuery 3 + Bootstrap 4 frontend, MySQL/MariaDB
backend. Tracks component stock (Parts / SMD / THT / SKU) across multiple
magazines, records SMD and THT production runs against BOMs, and integrates
with Google Sheets (export) and FlowPin (external MSSQL import).

UI strings are mostly **Polish** — do not "fix" them into English unless
asked. See `audits/POLISH_LANGUAGE.md` for the audit and severity list.

---

## Layout

```
index.php                       ← single entry point, switch-based router
config/
  config.php                    ← autoloads vendor, loads .env, defines
                                  ROOT_DIRECTORY & BASEURL, starts session,
                                  helpers: includeWithVariables(), asset()
  config-google-sheets.php      ← HybridAuth Google adapter (lazy-instantiated)
public_html/                    ← web root (Apache doc-root in prod via .htaccess)
  assets/{img,js,layout}/       ← static assets, header.php layout
  components/<Module>/          ← one folder per UI module (Production, Admin,
                                  Commissions, Warehouse, Transfer, ...);
                                  Admin/ is **capitalized** — case matters on Linux
  var/{locks,logs}/             ← cron locks & logs (gitignored)
src/
  classes/                      ← PSR-4-ish: Atte\{DB,Api,Utils\...}
    DB/                         ← BaseDB + MsaDB, IbiznesDB, FlowpinDB
    Api/                        ← GoogleSheets, GoogleOAuth
    Utils/                      ← User, Notification, Magazine, TransferGroup,
                                  Commission, Production, Bom, ComponentRenderer
                                  + class-locker.php
  cron/                         ← 6 cron scripts; logs to ../logs/ (gitignored)
vendor/                         ← composer deps (gitignored)
docs/                           ← authoritative documentation (see map below)
.htaccess                       ← rewrite to index.php; **hard-codes
                                  php_value auto_prepend_file** for XAMPP path
.env                            ← gitignored; see Secrets below
atte_ms_struct.sql              ← schema dump (note: README mistakenly calls it
                                  atte_ms.sql)
```

---

## Setup (local XAMPP / Windows dev)

1. Place the project at `C:\xampp\htdocs\atte_ms_new` — `.htaccess` hard-codes
   this path in `php_value auto_prepend_file`. Renaming the directory means
   editing `.htaccess`.
2. `composer install` (deps: google/apiclient, hybridauth/hybridauth,
   vlucas/phpdotenv, twbs/bootstrap-icons; ext-pdo, ext-curl required;
   **ext-sqlsrv** required for FlowPin cron).
3. Copy `.env.example` → `.env` (if present) or create one — see Secrets.
4. Import schema: `mysql -u root -p atte_ms < atte_ms_struct.sql` (the README
   says `atte_ms.sql` — wrong filename, do not fix in README unless asked).
5. Apache doc-root must point at `public_html/`, but `.htaccess` rewrites
   assume the URL prefix `/atte_ms_new/`. Either keep that prefix or rewrite
   both `.htaccess` lines and `BASEURL` in `.env` together.
6. PHP must run as an Apache module (mod_php) for `php_value` to work — PHP-FPM
   ignores it.

There is **no automated test suite**, **no CI workflow**, **no linter or
static-analysis config**, and `composer test` references `phpunit` which is
not installed. Don't promise CI/test runs.

---

## Secrets & environment (`.env`)

The `.env` in this repo is committed by accident historically — verify with
`git ls-files .env` and rotate **all** credentials before any push, especially:

- `DBPASSWORD` (MySQL root)
- `MSA*`, `IBIZNES*` (remote MySQL connections)
- `FLOWPIN*` (MSSQL to 192.168.1.5)
- `GOOGLE_CLIENT_ID` / `GOOGLE_CLIENT_SECRET`

`BASEURL` must match the public path. Routing logic in `index.php` strips
`/atte_ms_new/` from `$_SERVER['REQUEST_URI']` — change both together.

---

## Routing & how to add a page

- All HTTP requests land in `index.php`. A 25-case `switch ($request)` decides
  which component to require.
- Admin routes (`/admin/*`) **must** call `requireAdmin()` *before*
  `includeWithVariables($headerDir, ...)`, otherwise `header()` redirects
  raise "headers already sent" warnings.
- POST requests to anything other than `/test` are dropped by `.htaccess`
  unless served via an AJAX endpoint under `components/` (the POST rule
  applies only at the router level — components still receive POST).
- `includeWithVariables($file, $vars)` buffers + extracts `$vars` into the
  included file. Use it for every page header (`title`, optional `skip`).
- The default case renders `404.php` (PL message). Add a `case` here when you
  add a route.
- See `docs/system/ROUTING.md` for the full table including which routes are
  redirect-only (`admin/components/from-orders`, `admin/components/update-prices`).

---

## Public debug routes (auth-less — be careful)

- `/test` → `public_html/components/tests/test1.php` — FlowPin ↔ MSA SKU
  comparison. Has a `.backup` sibling still present in the tree.
- `/flowpin/test` → `public_html/components/tests/warehouse_state_view.php`

Both are wired in `index.php` without `requireAdmin()`. Treat as internal
debug surfaces; do not expose them beyond the local network.

---

## Cron jobs (`src/cron/`)

Six scripts. All use `Atte\Utils\Locker`
(`public_html/var/locks/<name>.lock`):

| Script | Lock file | Log location |
|---|---|---|
| `warehouse-data-gs-upload.php` | `gs_upload.lock` | `public_html/var/logs/` |
| `warehouse-comparison-gs-upload.php` | `warehouse_comparison_gs_upload.lock` | `public_html/var/logs/` |
| `bom-flat-tht-gs-upload.php` | `bom_flat_tht_gs_upload.lock` | `public_html/var/logs/` |
| `bom-flat-sku-gs-upload.php` | `bom_flat_sku_gs_upload.lock` | `public_html/var/logs/` |
| `update-part-prices.php` | (uses GS upload lock pattern) | `public_html/var/logs/` |
| `flowpin-sku-update.php` (1025 lines) | `flowpin.lock` | `src/cron/logs/<YYYY-MM-DD>/` |

- If a cron crashes (`kill -9`) the lock file dangles → next run exits with
  "Process is already running." → manually `rm public_html/var/locks/<name>.lock`.
- `flowpin-sku-update.php` is the largest and most complex job (15+ funcs);
  audit flagged it for split — keep new logic in a sibling file if you extend.
- See `docs/operations/CRON.md` for inputs, outputs, side effects per job.

---

## Conventions & gotchas

- **Class file naming:** `class-FooBar.php` (kebab-case) but the class is
  `FooBar` under namespace `Atte\{DB,Api,Utils\...}`. Autoloader is the
  Composer `classmap` over `src/classes` — after adding a new class file,
  run `composer dump-autoload` or the new class won't load.
- **Component directory case:** `public_html/components/Admin/` is
  capitalized (vs. `production/`, `warehouse/` lowercase). On Windows it
  works either way; **on Linux the case must match** `index.php` paths.
- **Helpers:** use `asset('public_html/...')` for cache-busted URLs (it
  appends `?v=<filemtime>`); use `includeWithVariables(...)` for view
  includes that need `$title`/`$skip`.
- **DB access:** all four DB classes extend `BaseDB` (PDO, ERRMOD_EXCEPTION).
  Read `src/classes/DB/class-basedb.php` before writing raw queries — there
  are helpers (`query`, `insertBulk`, `insert`, `update`).
- **Stock math:** inventory quantities are computed by **MySQL triggers**
  (see `atte_ms_struct.sql`); never recompute stock in PHP.
- **No framework:** there is no DI container, no autoloaded service layer
  for components. Most component endpoints are `.php` scripts that `require`
  classes directly — match that style.
- **Hard-coded paths:** the `auto_prepend_file` path in `.htaccess` is the
  most visible one; also watch for `__DIR__`-relative paths in
  `class-locker.php` (resolves `realpath(__FILE__, 4)` + `/public_html/var/locks/`).
- **Auth:** `$_SESSION['user_id']` + `$_SESSION['isAdmin']`. Login uses
  SHA-256 hashing — see `public_html/components/Login/login.php`.
- **JavaScript declarations:** always use `let` (or `const` for values
  that are never reassigned) — **never `var`**, in any new or edited
  JS, including inline `<script>` blocks in component PHP files.
- **AJAX endpoints (component-side):** AJAX handlers live as real
  `.php` files inside `public_html/components/<Module>/...` (e.g.
  `public_html/components/purchases/cart/cart-create-document.php`).
  The `.htaccess` POST rule rewrites the URL to itself (rather than
  forwarding to `index.php`) so the file is served directly — this is
  what makes POST work for AJAX endpoints. The shape:
  - Start with `header('Content-Type: application/json; charset=utf-8');`
    + a `$_SESSION['isAdmin'] !== true` gate (return `403` JSON, exit)
    + a `$_SERVER['REQUEST_METHOD'] !== 'POST'` gate (return `405` JSON, exit).
  - Read inputs from `$_POST` (form-encoded) or `json_decode(file_get_contents('php://input'), true)`
    (raw JSON). `$_POST` works automatically when the client uses
    jQuery's `$.ajax({ data: { ... } })` with a structured object — jQuery
    emits `items[0][vendor_part_id]=…` style form-encoding, which PHP
    parses into the nested array `$_POST['items'][0]['vendor_part_id']`.
    No JSON encoding on the client side needed.
  - Validate inputs (positive ints, ranges). On failure: respond
    `{success: false, error: '…'}` with HTTP 200 (not 4xx) — the JS
    side handles the message via `setAlert`.
  - DB work goes through the existing repositories (e.g.
    `PurchaseActionHandler::createDocument()`, `RFQItemRepository::create()`)
    and should be wrapped in a transaction (`$MsaDB->db->beginTransaction();`)
    so header+items insert as one unit. On throw, roll back; on success,
    commit and respond `{success: true, …}`.
  - Return exactly one `echo json_encode(...)` then `exit;`. No HTML,
    no views, no `includeWithVariables` — this is a pure JSON endpoint.
- **AJAX endpoints (client-side):** jQuery's `$.ajax` is the standard.
  The `COMPONENTS_PATH` constant (`/atte_ms_new/public_html/components`,
  set in `public_html/assets/layout/header.js`) is the URL prefix. Set
  `dataType: 'json'`, pass a structured `data:` object (NOT a JSON string)
  so the server can use `$_POST`. Always `.done()` + `.fail()`:
  - `.done(r => { if (!r.success) { setAlert(r.error, 'danger'); return; } … })`
  - `.fail((xhr, status) => { setAlert(xhr.responseJSON?.error ?? status, 'danger'); })`
  No `window.confirm()` for create/submit actions (annoying friction) —
  reserve it for genuinely destructive ops like clearing a vendor group.
  For "open in new tab" flows, do `$.ajax(...).done(r => window.open(r.edit_url, '_blank'))`
  AFTER receiving the response, so you can clean up local state first
  (remove used items from cart, refresh badges) before the new tab opens.
- **bootstrap-select `width` option:** for a picker that must
  fill its parent and stay responsive, set `data-width="100%"` —
  the picker becomes exactly the parent's width and the plugin
  applies its built-in `overflow: hidden; text-overflow: ellipsis`
  to the trigger button so long option text clips to that width
  instead of stretching the column. Pair with
  `data-container="#<parent-id>"` so the menu also stays inside
  the column. **If the picker sits inside a `.flex-grow-1`
  wrapper (or any flex item), add `min-width: 0` to that wrapper
  (and to `.bootstrap-select`) — otherwise the flex item's
  intrinsic min-width (= longest option's text width) expands past
  the column and breaks the `100%` cap.** This is the canonical
  bootstrap-select-native fix for the responsive "fill parent +
  handle long options" case — no pixel values, no JS workaround.
  The full docs:
  [options](https://developer.snapappointments.com/bootstrap-select/options/),
  [methods](https://developer.snapappointments.com/bootstrap-select/methods/).
  For this project: **`data-width="100%"` + a sized container div +
  `data-container="#<parent-id>"` + `min-width: 0` on the flex
  wrapper is the answer to every picker-width issue.**

---

## Documentation map (authoritative source of truth)

Start with `docs/INDEX.md`. Key docs:

- `docs/system/ARCHITECTURE.md` — system design, request lifecycle, where
  to add features.
- `docs/system/ROUTING.md` — full route table, adding routes.
- `docs/system/CODEBASE_MAP.md` — directory map.
- `docs/data/DATABASE.md` — schema, tables, triggers.
- `docs/code/MODULES.md` — per-module guide (19 modules).
- `docs/code/CLASSES.md` — domain class reference.
- `docs/operations/CRON.md` — every cron job in detail.
- `docs/operations/INTEGRATIONS.md` — Google Sheets + FlowPin.
- `docs/reference/STACK.md` — stack & extensions.

Audit reports under `audits/` (STRUCTURE, QUALITY, SECURITY, DISCREPANCIES,
POLISH_LANGUAGE, PLAN, SMALL_FIXES_PLAN) describe known tech debt. Read
`STRUCTURE.md` before proposing large refactors — it ranks the 11 files
over 500 lines.

---

## Common commands

```bash
# install / refresh deps
composer install
composer dump-autoload    # run after adding a class in src/classes/

# schema
mysql -u root -p atte_ms < atte_ms_struct.sql

# manual cron run (PHP CLI; uses .env via Dotenv)
php src/cron/warehouse-data-gs-upload.php
php src/cron/flowpin-sku-update.php

# clear a stuck lock
rm public_html/var/locks/<name>.lock
```

There is no `npm`/`yarn`/`webpack` — frontend deps are vendored Bootstrap
Icons only; all JS is hand-written under `public_html/components/<Module>/`.
