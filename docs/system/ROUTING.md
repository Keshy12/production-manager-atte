# ATTE Production Manager — Routing Reference

---

## 1. Architecture Overview

### Request Lifecycle

```
HTTP Request
    │
    ▼
index.php                         ← single entry point
    │
    ├─ $request = strtok(..., '?')   strip query string
    │
    ├─ str_replace('/atte_ms_new/', '', ...)   remove prefix
    │
    ▼
switch ($request)                 ← match against 33 cases
                                                (see §4 for the full list)
    │
    ├─ includeWithVariables($headerDir, ['title' => ...])
    │       │
    │       ▼
    │   public_html/assets/layout/header.php   (HTML <head>, nav)
    │
    ├─ require $componentsDir . '/<path>/<file>.php'
    │
    └─ default: 404
```

**Key constants** (defined in `config/config.php`):

| Constant | Value |
|---|---|
| `ROOT_DIRECTORY` | `$_SERVER['DOCUMENT_ROOT'] . '/atte_ms_new'` (or realpath fallback) |
| `BASEURL` | loaded from `.env` via `Dotenv` |

**`includeWithVariables()`** — buffers the included file and returns/renders it. Accepts an associative array of variables (e.g. `title`) that are extracted into the included file's scope (`config/config.php:16`).

---

## 2. Header Inclusion Pattern

Every page (except `login`, `/`, and the test route) follows this pattern:

```php
includeWithVariables($headerDir, array('title' => 'Page Title (PL)'));
require $componentsDir . '/<path>/<view-file>.php';
```

`$headerDir` is always `'public_html/assets/layout/header.php'`.

| Route | Header `skip` param |
|---|---|
| `/` (home) | `skip => true` (header rendered but nav items hidden for unauthenticated) |
| `login` | `skip => true` (standalone, no nav) |
| `test` | **no header included** (commented out at `index.php:108`) |

---

## 3. 404 Handling

The `default` case (`index.php:126–130`) catches every unmatched route:

```php
default:
    includeWithVariables($headerDir, array('title' => 'Nie znaleziono takiej witryny.', 'skip' => true));
    http_response_code(404);
    require $componentsDir . '/error/404.php';
    break;
```

---

## 4. Route Table

| Route | Title (PL) | Component File | Notes |
|---|---|---|---|
| `/` | Strona Główna | `public_html/components/index/active-commissions-view.php` | Auth-gated: only rendered if `$_SESSION["userid"]` is set. Shows "active commissions" panel. Otherwise renders header only with empty content. |
| `login` | Logowanie | `public_html/components/login/login-view.php` | **Redirects** if already logged in (`index.php:119–120`) — shows "Jesteś już zalogowany" message inline. |
| `logout` | — | `public_html/components/login/logout.php` | Destroys session and redirects. No header. |
| `production/tht` | Produkcja THT | `public_html/components/production/production-view.php` | Sets `$_GET['type'] = 'tht'` (`index.php:16`). Auth-gated (session check inside component). |
| `production/smd` | Produkcja SMD | `public_html/components/production/production-view.php` | Sets `$_GET['type'] = 'smd'` (`index.php:21`). Shares same component as THT. Auth-gated. |
| `transfer` | Transfer | `public_html/components/transfer/transfer-view.php` | Auth-gated. |

| `archive` | Archiwum | `public_html/components/archive/archive-view.php` | Auth-gated. |
| `warehouse` | Magazyn | `public_html/components/warehouse/warehouse-view.php` | Auth-gated. |
| `commissions` | Zlecenia | `public_html/components/commissions/commissions-view.php` | Auth-gated. |
| `notification` | Powiadomienie | `public_html/components/notification/notification-view.php` | Auth-gated. |
| `profile` | Mój Profil | `public_html/components/profile/profile-view.php` | Auth-gated. |
| `profile/warehouse` | Mój Magazyn | `public_html/components/profile/warehouse/warehouse-view.php` | Auth-gated. |
| `profile/devices-produced` | Produkowane Urządzenia | `public_html/components/profile/devicesproduced/devices-produced-view.php` | Auth-gated. |
| `admin/bom/upload` | Wczytywanie BOM | `public_html/components/admin/bom/upload/upload-bom-view.php` | Admin route. Auth check inside component. |
| `admin/bom/edit` | Edycja BOM | `public_html/components/admin/bom/edit/edit-bom-view.php` | Admin route. Auth check inside component. |
| `admin/bom/dictionary` | Edycja słownika ValuePackage | `public_html/components/admin/bom/dictionary/edit-dictionary-view.php` | Admin route. Auth check inside component. |
| `admin/profiles/edit` | Edycja Profili | `public_html/components/admin/profiles/edit/edit-profile-view.php` | Admin route. Auth check inside component. |
| `admin/components/edit` | Edycja Komponentów | `public_html/components/admin/components/edit/edit-component-view.php` | Admin route. Auth check inside component. |
| `admin/components/detect-new-parts` | Aktualizuj Parts | `public_html/components/admin/components/detectnewparts/detect-parts-view.php` | Admin route. Auth check inside component. |
| `admin/components/from-orders` | — | **Redirect only** | `header('Location: http://' . BASEURL . '/admin/synchronization/sheets#import-orders')` (`index.php:85–86`). Exits immediately. |
| `admin/components/update-prices` | — | **Redirect only** | `header('Location: http://' . BASEURL . '/admin/synchronization/sheets#update-prices')` (`index.php:88–90`). Exits immediately. |
| `admin/magazines/edit` | Edycja Magazynów | `public_html/components/admin/magazines/edit/edit-magazines-view.php` | Admin route. Auth check inside component. |
| `admin/purchase/vendors` | Dostawcy | `public_html/components/Admin/Purchase/Vendors/vendors-view.php` | Admin route. Procurement module (P1). `Admin/` capitalized. |
| `admin/purchase/producers` | Producenci | `public_html/components/Admin/Purchase/Producers/producers-view.php` | Admin route. Procurement module (P1). |
| `admin/purchase/vendor-parts` | Artykuły u dostawców | `public_html/components/Admin/Purchase/VendorParts/vendor-parts-view.php` | Admin route. Procurement module (P1). |
| `admin/purchase/cart` | Koszyk zakupowy | `public_html/components/purchases/cart/cart-view.php` | Admin route. Procurement UX: cart-style entry point for creating new RFQ/PO documents. Lives under `components/purchases/`; AJAX endpoints are called by real path via `COMPONENTS_PATH`. |
| `admin/purchase/receipts` | Przyjęcia | `public_html/components/purchases/receipts/receipts-view.php` | Admin route. Procurement module (P4): goods-receipt list with per-receipt line-item detail modal. Under `components/purchases/`. |
| `admin/purchase/documents` | Zapytania i zamówienia | `public_html/components/purchases/documents/documents-view.php` | Admin route. **Placeholder** — future combined RFQ+PO table with filtration. Linked from top-nav "Zamówienia komponentów". |
| `admin/synchronization/flowpin` | Flowpin - Status Aktualizacji | `public_html/components/Admin/Synchronization/flowpin/flowpin-status-view.php` | **Note:** `Admin/` directory is capitalized. |
| `admin/synchronization/sheets` | Flowpin - Arkusze | `public_html/components/Admin/Synchronization/sheets/flowpin-sheets-view.php` | **Note:** `Admin/` directory is capitalized. |
| `test` | — | `public_html/components/tests/test1.php` | Dev/test only. **No header included** (`index.php:108–110`). |
| `flowpin/test` | Flowpin Warehouse State | `public_html/components/tests/warehouse_state_view.php` | Dev/test only. **⚠ File not found** in `public_html/components/tests/` — likely broken path or missing file. |

**Total routes: 33** (25 page cases + 2 redirect-only + 2 dev/test + 4 misc legacy; see §4 for the full table).

---

## 5. Auth Gating

### Session-based auth

The primary session key is `$_SESSION["userid"]`. Auth checks are handled **inside individual components**, not centrally in `index.php`, for all routes **except**:

| Route | Auth behavior in `index.php` |
|---|---|
| `/` | Only renders `active-commissions-view.php` **if** `$_SESSION["userid"]` is set (`index.php:11`). |
| `login` | If `$_SESSION["userid"]` **is already set**, displays inline message instead of the login form (`index.php:119–120`). |
| `logout` | No session check here; the logout component destroys the session unconditionally. |

### Components that handle their own auth

All production/warehouse/transfer/admin routes rely on components to redirect or show an error if the user is not authenticated. See each component's implementation for the specific auth guard pattern.

### Routes with no auth check in `index.php`

The following routes have **no session check** in `index.php` — auth is delegated to the component:

- `production/tht`, `production/smd`
- `transfer`

- `archive`
- `warehouse`
- `commissions`
- `notification`
- `profile`, `profile/warehouse`, `profile/devices-produced`
- `admin/*` (all admin sub-routes)

---

## 6. Redirect-Only Routes

Two routes issue an HTTP redirect and `exit` immediately — no component is rendered:

| Route | Destination | `index.php` line |
|---|---|---|
| `admin/components/from-orders` | `http://{BASEURL}/admin/synchronization/sheets#import-orders` | `85–86` |
| `admin/components/update-prices` | `http://{BASEURL}/admin/synchronization/sheets#update-prices` | `88–90` |

Both redirect to the **Flowpin Sheets** admin panel with a URL anchor.

---

## 7. Test / Dev Routes

| Route | Title | Component | Notes |
|---|---|---|---|
| `test` | — (no header) | `public_html/components/tests/test1.php` | Raw test page, no layout. |
| `flowpin/test` | Flowpin Warehouse State | `public_html/components/tests/warehouse_state_view.php` | ⚠ References a file that does not appear to exist. |

**Do not enable these routes in production.**

---

## 8. Logged-Out Home Behaviour

When an **unauthenticated** user visits `/`:

1. `includeWithVariables($headerDir, ['title' => 'Strona Główna', 'skip' => true])` renders the header (with `skip=true` suppressing nav items).
2. The `if(isset($_SESSION["userid"]))` check on `index.php:11` is `false`, so `active-commissions-view.php` is **not loaded**.
3. The page renders with an **empty content area** — no visible error, just a blank main section.

Authenticated users see the active-commissions panel immediately after the header.

---

## 9. Adding a New Route

To add a new route (e.g. `products`):

1. **Add a case** in `index.php` inside the `switch ($request)` block (`index.php:7`).
2. Use the standard pattern:

```php
case 'products':
    includeWithVariables($headerDir, array('title' => 'Produkty'));
    require $componentsDir . '/products/products-view.php';
    break;
```

3. **Create the component** at `public_html/components/products/products-view.php`.
4. **Add auth gating** inside the component if the route should be protected.
5. **Add session check** in `index.php` for routes that must be inaccessible when logged in (e.g. a `register` route that should redirect if `$_SESSION["userid"]` is already set — follow the `login` pattern at `index.php:117–121`).

---

*End of document.*

---

**Next up:** [DATABASE.md](../data/DATABASE.md) — MySQL schema reference; all tables and relationships.
