# ATTE Production Manager — Technology Stack

---

## 1. Runtime

| Requirement | Value | Notes |
|-------------|-------|-------|
| **PHP Version** | PHP 8.0+ | Minimum supported version |
| **Required Extensions** | `ext-pdo`, `ext-curl`, `ext-json`, `ext-mbstring` | PDO for database, cURL for HTTP requests, JSON for API responses, mbstring for Unicode string handling |

**Files referencing runtime requirements:**
- `composer.json` — declares `ext-pdo`, `ext-curl` as required
- `README.md` — documents PHP 8.0+ requirement and all four extensions

---

## 2. Database

| Property | Value |
|----------|-------|
| **Engine** | MySQL 5.7+ with InnoDB |
| **Character Set** | UTF-8 (implied by general application design) |
| **Key Features Used** | Database triggers for automatic stock calculations, prepared statements for SQL injection protection |

**Key database tables (per `README.md`):**
- `inventory__*` — Inventory tracking for different component types
- `list__*` — Master data for components, devices, and materials
- `bom__*` — Bill of materials definitions
- `commission__*` — Production order management
- `user` — User management and permissions

---

## 3. Web Server

| Property | Value |
|----------|-------|
| **Supported** | Apache or Nginx |
| **Configuration** | `.htaccess` exists at project root |

**`.htaccess` key rules** (source: `.htaccess`; abridged — see `.htaccess` for full rules including POST handling, 403 protection):
```apache
DirectoryIndex index.php
RewriteEngine On
php_value auto_prepend_file C:/xampp/htdocs/atte_ms_new/config/config.php
RewriteCond %{REQUEST_METHOD} ^POST$
RewriteRule ^ - [L]
RewriteCond %{REQUEST_FILENAME} !-f
RewriteRule ^(.*)ďĽ‚ index.php
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^(.*)$ /atte_ms_new/index.php/$1 [L,QSA]
RewriteRule \.(css|js|png|jpg|ico|svg|xml|webmanifest|webp)$ - [L]
RewriteRule !^(index.php) [F]
```

> **Note:** The `auto_prepend_file` directive loads `config/config.php` for every PHP request, ensuring the application bootstrap runs universally.

---

## 4. Frontend Stack

| Library | Version | Purpose |
|---------|---------|---------|
| **jQuery** | 3.6 | DOM manipulation, AJAX, event handling |
| **Bootstrap** | 4.3.1 | Responsive UI framework, grid system, components |
| **Bootstrap Icons** | 1.10+ (from composer) | SVG icon library |

**Source:** `README.md` — Technology Stack section

**Frontend assets location:** `public_html/assets/` contains:
- `layout/` — Header, side-menu layout components
- `js/` — Application JavaScript modules (e.g., `flowpin-manager.js`)
- `img/` — Images, icons, production device photos

---

## 5. PHP Libraries (Composer Dependencies)

### Primary Dependencies

| Package | Version | Purpose in This Project | File Using It |
|---------|---------|------------------------|---------------|
| `google/apiclient` | ^2.10 | Google Sheets API integration for data synchronization and upload | `config/config-google-sheets.php`, `src/cron/*-gs-upload.php` |
| `hybridauth/hybridauth` | ~3.0 | OAuth authentication (social login, Google OAuth) | User authentication flows |
| `monolog/monolog` | ^2.9\|\|^3.0 | PSR-3 logging | Transitive dep via `google/apiclient`; not used by application code directly |
| `vlucas/phpdotenv` | ^5.4 | Load environment variables from `.env` file | `config/config.php` line 7-8 |
| `graham-campbell/result-type` | ^1.1.3 | Result type for error handling | Dependency of `phpdotenv` |
| `symfony/polyfill-php80` | ^1.24 | PHP 8.0+ polyfills (e.g., `Stringable`, `fdiv`) | Dependency of `phpdotenv` |
| `twbs/bootstrap-icons` | ^1.10 | SVG icon library (frontend asset) | Included as Composer asset |

**Source:** `composer.json` (direct dependencies), `composer.lock` (resolved tree)

### Transitive Dependencies (Automatically Installed)

| Package | Purpose |
|---------|---------|
| `google/apiclient-services` | Google API service definitions (Sheets API) |
| `google/auth` | Google OAuth2 authentication library |
| `guzzlehttp/guzzle` | PSR-7 HTTP client (used by Google API Client) |
| `guzzlehttp/psr7` | PSR-7 HTTP message implementations |
| `guzzlehttp/promises` | Async promise implementation for Guzzle |
| `firebase/php-jwt` | JWT encoding/decoding (used by Google Auth) |
| `phpseclib/phpseclib` | SSH/SFTP cryptography (used by Google Auth) |
| `psr/log` | PSR-3 logging interface |
| `psr/cache` | PSR-6 caching interface |
| `psr/http-message` | PSR-7 HTTP message interfaces |
| `psr/http-client` | PSR-18 HTTP client interface |
| `symfony/polyfill-mbstring` | mbstring polyfill for environments without it |
| `symfony/polyfill-ctype` | ctype polyfill |
| `symfony/deprecation-contracts` | Deprecation handling |
| `ralouphie/getallheaders` | `getallheaders()` polyfill |
| `paragonie/constant_time_encoding` | Timing-safe string encoding |
| `paragonie/random_compat` | `random_bytes()` polyfill for PHP 5.x |
| `phpoption/phpoption` | Option type (dependency of `graham-campbell/result-type`) |

---

## 6. Composer Scripts

| Script | Command | Purpose |
|--------|---------|---------|
| `pre-autoload-dump` | `Google\Task\Composer::cleanup` | Cleanup task run before autoload dump |
| `test` | `phpunit` | Run PHPUnit test suite |

**Source:** `composer.json` lines 10-15

```json
"scripts": {
    "pre-autoload-dump": "Google\\Task\\Composer::cleanup",
    "test": [
        "Composer\\Config::disableProcessTimeout",
        "phpunit"
    ]
}
```

> **Note:** Running tests requires PHPUnit to be installed (`composer test`).

---

## 7. Auto-loading & Entry Point

### Bootstrap Flow

1. **Every PHP request** is prepended with `config/config.php` (via `.htaccess` `auto_prepend_file`)
2. **`config/config.php`** (lines 1â€“9):
   ```php
   define('ROOT_DIRECTORY', ...);             // lines 1â€“4: ROOT_DIRECTORY via ternary/DOCUMENT_ROOT or realpath
   require_once ROOT_DIRECTORY.'/vendor/autoload.php';  // line 5: loads Composer deps
   $dotenv = Dotenv\Dotenv::createImmutable(ROOT_DIRECTORY);  // line 7
   $dotenv->load();                          // line 8: loads .env into $_ENV, $_SERVER, getenv()
   define("BASEURL", $_ENV["BASEURL"]);      // line 9
   ```

3. **Session start** (line 12): `session_start()` unless running via CLI

### Autoload Configuration

```json
"autoload": {
    "classmap": ["src/classes"]
}
```

All classes in `src/classes/` are autoloaded via the Composer classmap.

### Key Helper Functions (defined in `config/config.php`)

| Function | Purpose |
|----------|---------|
| `includeWithVariables($file, $vars, $print)` | Include PHP template with extracted variables |
| `asset($path)` | Generate versioned asset URL with cache busting |

---

## 8. CRON Jobs

External scheduler (e.g., system crontab, Windows Task Scheduler) invokes scripts in `src/cron/`.

### Available CRON Scripts

| Script | Purpose |
|--------|---------|
| `warehouse-data-gs-upload.php` | Upload warehouse data to Google Sheets |
| `warehouse-comparison-gs-upload.php` | Upload warehouse comparison data to Google Sheets |
| `update-part-prices.php` | Update part pricing data |
| `flowpin-sku-update.php` | Sync SKU data from FlowPin external system |
| `bom-flat-tht-gs-upload.php` | Flatten THT BOM and upload to Google Sheets |
| `bom-flat-sku-gs-upload.php` | Flatten SKU BOM and upload to Google Sheets |

**Source:** `src/cron/*.php`

> **Note:** These scripts are intended to be run via external scheduling. They interact with Google Sheets API and external FlowPin system for data synchronization.

---

## 9. Project Directory Structure

```
atte_ms_NEW/
├── config/                     # Application configuration
│   ├── config.php             # Main bootstrap (loads vendor/autoload.php + Dotenv)
│   â””â”€â”€ config-google-sheets.php # Google Sheets integration config
├── public_html/               # Web root (point document root here)
│   ├── assets/                # CSS, JS, images
│   ├── components/            # Application UI modules
│   â””â”€â”€ index.php              # Main entry point
├── src/
│   ├── classes/               # PHP classes (autoloaded via classmap)
│   â””â”€â”€ cron/                   # Scheduled task scripts
├── vendor/                    # Composer dependencies
├── docs/                      # Documentation
├── composer.json             # Dependency manifest
├── composer.lock             # Locked dependency versions
├── .htaccess                  # Apache routing + auto-prepend config
â””â”€â”€ .env.example               # Environment variable template
```

---

## 10. Quick Reference: Installing Dependencies

```bash
# Install all Composer dependencies
composer install

# Run tests
composer test

# Update dependencies (careful in production)
composer update
```

---

## 11. Environment Configuration

Create a `.env` file in the project root (copy from `.env.example` if available). Key variables:

| Variable | Purpose |
|----------|---------|
| `BASEURL` | Application base URL (e.g., `localhost/atte_ms_new`) |
| `DBHOST`, `DBUSERNAME`, `DBPASSWORD`, `DBNAME` | Local MySQL database credentials |
| `MSAURL`, `MSAUSERNAME`, `MSAPASSWORD` | MSA (MySQL) connection string and credentials |
| `IBIZNESURL`, `IBIZNESUSERNAME`, `IBIZNESPASSWORD` | Ibiznes external DB (Synology) credentials |
| `FLOWPINURL`, `FLOWPINUSERNAME`, `FLOWPINPASSWORD` | FlowPin external SQL Server credentials |
| `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET` | Google OAuth client credentials |

> **Note:** Naming conventions vary by integration — MSA/FLOWPIN/IBIZNES use `*URL`, `*USERNAME`, `*PASSWORD`; the local DB block uses `DBHOST`, `DBUSERNAME`, `DBPASSWORD`, `DBNAME`. See `.env` for the full list.

**Loading:** `config/config.php` uses `Dotenv\Dotenv::createImmutable()` to load `.env` into `$_ENV`, `$_SERVER`, and `getenv()`.

---

**End of reading order.** [← Back to INDEX.md](../../INDEX.md) to start over.
