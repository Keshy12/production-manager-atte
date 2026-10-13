<?php
/**
 * One-shot CLI: apply the procurement-module + email-draft schema
 * (the delta between atte_ms_old_struct.sql and atte_ms_new_struct.sql)
 * to the MSA database.
 *
 * - Schema-only: does NOT migrate data. The delta is purely additive —
 *   every existing table is byte-for-byte identical between old and new.
 * - Idempotent: each table is probed via information_schema.TABLES
 *   before being created; on already-present tables the column shape
 *   and FK set are verified against information_schema. Mismatch fails
 *   loudly — re-running on a clean migration is silent.
 * - FK-safe: tables are created in strict dependency order so the
 *   referent of every FK exists by the time the child table is built.
 *
 * Adds 15 tables (the procurement module + reference tables + email_draft*):
 *   L0: email_draft, list__currency, list__producer, purchase__number_counter
 *   L1: email_draft_default, list__vendor
 *   L2: list__vendor_part, list__vendor_supplier, purchase__rfq
 *   L3: list__vendor_part_pack, purchase__rfq_item, purchase__order
 *   L4: purchase__order_item, purchase__order_receipt
 *   L5: purchase__order_receipt_item
 *
 * 27 foreign keys total on the new tables.
 *
 * Seeds (all "insert if missing, never overwrite"):
 *   - list__currency             : 13 active ISO codes (PLN first)
 *   - ref__transfer_group_types  : 'purchase_receipt' /
 *                                  'Przyjęcie z zamówienia #{po_id}'
 *   - email_draft                : 'Domyślny' (RFQ) +
 *                                  'Domyślny — zamówienie' (PO)
 *   - email_draft_default        : (rfq → 'Domyślny') +
 *                                  (po  → 'Domyślny — zamówienie')
 *   - inventory__input_type      : new row 'Przyjęcie z zamówienia PO'
 *                                  (existing id=1 row
 *                                  'Aktualizacja z Google Sheets' stays
 *                                  intact for historical stock rows)
 *
 * Intentional deviation from the dump: list__vendor.default_currency is
 * created as `int(11) NOT NULL DEFAULT <PLN id>` (the dump declares
 * `int(11) NOT NULL` with no default — but
 * seed-component-procurement-from-gsheet.php:288 inserts vendors
 * without default_currency, so the default is required for the
 * import to succeed). If list__vendor already exists without the
 * default, this script issues an ALTER ... MODIFY to add it.
 *
 * Pre-flight (refuses to run if any check fails):
 *   - server is MariaDB ≥ 10.2.2 or MySQL ≥ 5.7.7
 *   - innodb_default_row_format is not COMPACT/REDUNDANT
 *     (utf8mb4 + InnoDB requires DYNAMIC or COMPRESSED for the
 *     3072-byte index limit — see MySQL/MariaDB refman)
 *   - 6 required legacy tables exist: list__parts, part__unit, user,
 *     magazine__list, inventory__input_type, ref__transfer_group_types
 *   - trigger count snapshot is captured and re-verified post-run
 *     (must stay at 8 — the inventory audit triggers are unchanged
 *     between the two schema dumps)
 *
 * If a CREATE fails partway through, the script reports failures
 * but the tables created earlier remain (DDL cannot be rolled back).
 * Re-running resumes from the first missing/incomplete table.
 *
 * Usage:
 *   php src/cron/migrate-production-to-purchase-schema.php [--dry-run] [--yes] [--database=<name>]
 *
 *   --dry-run         Print planned actions, change nothing
 *   --yes             Skip the "Type YES to continue" interactive confirm
 *   --database=<name> Override the target database (default: MSAURL dbname
 *                     in .env). Used by the validation harness to point the
 *                     script at a scratch DB; production runs omit it.
 */

use Atte\DB\MsaDB;

// ── Parse CLI flags BEFORE loading config so we can override MSAURL ────
$cliArgs    = $argv ?? [];
$dryRun     = in_array('--dry-run', $cliArgs, true);
$autoYes    = in_array('--yes',     $cliArgs, true);
$dbOverride = null;
foreach ($cliArgs as $a) {
    if (str_starts_with($a, '--database=')) {
        $dbOverride = substr($a, 11);
    }
}
if ($dbOverride !== null && !preg_match('/^[A-Za-z0-9_]+$/', $dbOverride)) {
    fwrite(STDERR, "✗ Invalid --database='{$dbOverride}' (allowed: [A-Za-z0-9_]+)\n");
    exit(1);
}

// ── Bootstrap (config + DB) ──────────────────────────────────────────
require_once __DIR__ . '/../../config/config.php';

// Apply --database override by rewriting the DSN in $_ENV BEFORE the
// MsaDB singleton reads it. MsaDB's constructor reads $_ENV['MSAURL']
// directly, so mutating it here is sufficient and safe.
if ($dbOverride !== null) {
    $dsn = $_ENV['MSAURL'] ?? '';
    $newDsn = preg_replace('/(dbname=)[^;]+/', '$1' . $dbOverride, $dsn);
    if ($newDsn === null || $newDsn === $dsn) {
        fwrite(STDERR, "✗ Could not rewrite dbname in MSAURL='{$dsn}'\n");
        exit(1);
    }
    $_ENV['MSAURL'] = $newDsn;
    echo "[override] MSA DSN rewritten → dbname={$dbOverride}\n\n";
}

$MsaDB = MsaDB::getInstance();
$db    = $MsaDB->db;
$db->exec("SET NAMES utf8mb4");

$dbName = (string) $db->query('SELECT DATABASE()')->fetchColumn();
$dbHost = (string) $db->query("SELECT SUBSTRING_INDEX(USER(), '@', -1)")->fetchColumn();
echo "=== migrate-production-to-purchase-schema ===\n";
echo "Target DB : {$dbName}\n";
echo "Host      : {$dbHost}\n";
echo "Mode      : " . ($dryRun ? 'DRY-RUN (no writes)' : 'LIVE') . "\n\n";

// ── Pre-flight ───────────────────────────────────────────────────────
echo "── Pre-flight ──\n";

// 1. Server version
$versionStr = (string) $db->query('SELECT VERSION()')->fetchColumn();
$isMaria    = stripos($versionStr, 'MariaDB') !== false;
$versionNum = $versionStr;
if (preg_match('/(\d+\.\d+\.\d+)/', $versionStr, $m)) {
    $versionNum = $m[1];
}
$ok = $isMaria ? version_compare($versionNum, '10.2.2', '>=')
               : version_compare($versionNum, '5.7.7',  '>=');
echo ($ok ? '✓' : '✗') . " Server version: {$versionStr} "
   . ($ok ? '(≥ minimum)' : '(REQUIRED: MariaDB≥10.2.2 or MySQL≥5.7.7)') . "\n";
if (!$ok) { fwrite(STDERR, "✗ Aborting — server too old.\n"); exit(1); }

// 2. innodb_default_row_format
$rowFormat = strtoupper((string) $db->query("SELECT @@innodb_default_row_format")->fetchColumn());
$rowFormatOk = !in_array($rowFormat, ['COMPACT', 'REDUNDANT'], true);
echo ($rowFormatOk ? '✓' : '✗') . " innodb_default_row_format: {$rowFormat} "
   . ($rowFormatOk ? '(DYNAMIC/COMPRESSED ok)' : '(REQUIRED: not COMPACT/REDUNDANT — utf8mb4 indexes need >767 byte keys)') . "\n";
if (!$rowFormatOk) { fwrite(STDERR, "✗ Aborting — row format incompatible with utf8mb4 indexes.\n"); exit(1); }

// 3. Required existing tables
$requiredExisting = [
    'list__parts', 'part__unit', 'user',
    'magazine__list', 'inventory__input_type', 'ref__transfer_group_types',
];
$placeholders = implode(',', array_fill(0, count($requiredExisting), '?'));
$stmt = $db->prepare(
    "SELECT TABLE_NAME FROM information_schema.TABLES
      WHERE TABLE_SCHEMA = ? AND TABLE_NAME IN ($placeholders)"
);
$stmt->execute(array_merge([$dbName], $requiredExisting));
$present = array_column($stmt->fetchAll(\PDO::FETCH_ASSOC), 'TABLE_NAME');
$missing = array_diff($requiredExisting, $present);
echo (empty($missing) ? '✓' : '✗') . " Required existing tables: "
   . (empty($missing) ? "all 6 present" : "MISSING " . implode(', ', $missing)) . "\n";
if (!empty($missing)) { fwrite(STDERR, "✗ Aborting — required legacy tables missing.\n"); exit(1); }

// 4. Trigger count snapshot
$triggerStmt = $db->prepare(
    "SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = ?"
);
$triggerStmt->execute([$dbName]);
$triggerCountBefore = (int) $triggerStmt->fetchColumn();
echo "✓ Triggers before: {$triggerCountBefore} (must equal after)\n";

echo "\n";

// ── 15 new tables, in strict FK order ────────────────────────────────
// DDL is copied verbatim from atte_ms_new_struct.sql. AUTO_INCREMENT,
// PRIMARY KEY / UNIQUE / KEY, and FOREIGN KEY constraints are inlined
// in each CREATE TABLE so the table comes into existence fully formed
// (no separate ALTER TABLE pass). list__vendor.default_currency's
// DEFAULT is a placeholder (`{PLN_ID}`) substituted below after the
// list__currency seed runs.
$tables = [

    // ── L0 ────────────────────────────────────────────────────────────
    'email_draft' => <<<'SQL'
CREATE TABLE `email_draft` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `subject` varchar(255) NOT NULL,
  `body` mediumtext NOT NULL,
  `applies_to` enum('rfq','po','both') NOT NULL DEFAULT 'both',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_active_name` (`is_active`,`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
SQL,

    'list__currency' => <<<'SQL'
CREATE TABLE `list__currency` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `code` varchar(8) NOT NULL,
  `name` varchar(64) NOT NULL,
  `isActive` tinyint(1) NOT NULL DEFAULT 1,
  `comment` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_currency_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
SQL,

    'list__producer' => <<<'SQL'
CREATE TABLE `list__producer` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `isActive` tinyint(1) NOT NULL DEFAULT 1,
  `comment` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_active` (`isActive`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
SQL,

    'purchase__number_counter' => <<<'SQL'
CREATE TABLE `purchase__number_counter` (
  `type` enum('rfq','po','grn') NOT NULL,
  `vendor_id` int(11) NOT NULL DEFAULT 0,
  `last_value` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`type`,`vendor_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
SQL,

    // ── L1 — list__vendor's DDL is built below after PLN id is known ─
    'email_draft_default' => <<<'SQL'
CREATE TABLE `email_draft_default` (
  `doc_type` enum('rfq','po') NOT NULL,
  `draft_id` int(11) NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`doc_type`),
  KEY `idx_draft_id` (`draft_id`),
  CONSTRAINT `fk_ed_default_draft` FOREIGN KEY (`draft_id`) REFERENCES `email_draft` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
SQL,

    // ── L2 ────────────────────────────────────────────────────────────
    'list__vendor_part' => <<<'SQL'
CREATE TABLE `list__vendor_part` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `vendor_id` int(11) NOT NULL,
  `producer_id` int(11) NOT NULL,
  `parts_id` int(11) NOT NULL,
  `vendor_part_no` varchar(255) NOT NULL,
  `producer_part_no` varchar(255) DEFAULT NULL,
  `vendor_jm_id` int(11) NOT NULL,
  `isActive` tinyint(1) NOT NULL DEFAULT 1,
  `comment` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_vendor_part_no` (`vendor_id`,`vendor_part_no`),
  KEY `idx_vp_vendor` (`vendor_id`),
  KEY `idx_vp_producer` (`producer_id`),
  KEY `idx_vp_parts` (`parts_id`),
  KEY `fk_vp_unit` (`vendor_jm_id`),
  CONSTRAINT `fk_vp_parts` FOREIGN KEY (`parts_id`) REFERENCES `list__parts` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_vp_producer` FOREIGN KEY (`producer_id`) REFERENCES `list__producer` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_vp_unit` FOREIGN KEY (`vendor_jm_id`) REFERENCES `part__unit` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_vp_vendor` FOREIGN KEY (`vendor_id`) REFERENCES `list__vendor` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
SQL,

    'list__vendor_supplier' => <<<'SQL'
CREATE TABLE `list__vendor_supplier` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `vendor_id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `job_title` varchar(255) DEFAULT NULL,
  `phone` varchar(64) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `isActive` tinyint(1) NOT NULL DEFAULT 1,
  `is_primary` tinyint(1) NOT NULL DEFAULT 0,
  `comment` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `vendor_id` (`vendor_id`),
  KEY `idx_vs_primary` (`vendor_id`,`is_primary`),
  CONSTRAINT `fk_vs_vendor` FOREIGN KEY (`vendor_id`) REFERENCES `list__vendor` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
SQL,

    'purchase__rfq' => <<<'SQL'
CREATE TABLE `purchase__rfq` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `vendor_id` int(11) NOT NULL,
  `state` enum('draft','sent','responded','cancelled','converted') NOT NULL DEFAULT 'draft',
  `rfq_number` varchar(64) DEFAULT NULL,
  `vendor_reference` varchar(128) DEFAULT NULL,
  `expected_reply_date` date DEFAULT NULL,
  `sent_at` datetime DEFAULT NULL,
  `responded_at` datetime DEFAULT NULL,
  `responded_by` int(11) DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `comment` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `pdf_generated_at` datetime DEFAULT NULL,
  `pdf_path` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_rfq_vendor` (`vendor_id`),
  KEY `idx_rfq_state` (`state`),
  KEY `fk_rfq_user` (`created_by`),
  KEY `idx_rfq_responder` (`responded_by`),
  CONSTRAINT `fk_rfq_responder` FOREIGN KEY (`responded_by`) REFERENCES `user` (`user_id`),
  CONSTRAINT `fk_rfq_user` FOREIGN KEY (`created_by`) REFERENCES `user` (`user_id`),
  CONSTRAINT `fk_rfq_vendor` FOREIGN KEY (`vendor_id`) REFERENCES `list__vendor` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
SQL,

    // ── L3 ────────────────────────────────────────────────────────────
    'list__vendor_part_pack' => <<<'SQL'
CREATE TABLE `list__vendor_part_pack` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `vendor_part_id` int(11) NOT NULL,
  `full_pack_quantity` decimal(30,10) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_vp_pack` (`vendor_part_id`,`full_pack_quantity`),
  KEY `idx_vpp_vp` (`vendor_part_id`),
  CONSTRAINT `fk_vpp_vendor_part` FOREIGN KEY (`vendor_part_id`) REFERENCES `list__vendor_part` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
SQL,

    'purchase__rfq_item' => <<<'SQL'
CREATE TABLE `purchase__rfq_item` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `rfq_id` int(11) NOT NULL,
  `vendor_part_id` int(11) NOT NULL,
  `quantity` decimal(30,10) NOT NULL,
  `quantity_unit_id` int(11) NOT NULL,
  `unit_price` decimal(30,10) DEFAULT NULL,
  `currency` varchar(8) NOT NULL DEFAULT 'PLN',
  `comment` text DEFAULT NULL,
  `picked_pack_size` decimal(30,10) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_rfq_item_rfq` (`rfq_id`),
  KEY `fk_rfq_item_vp` (`vendor_part_id`),
  KEY `fk_rfq_item_unit` (`quantity_unit_id`),
  CONSTRAINT `fk_rfq_item_rfq` FOREIGN KEY (`rfq_id`) REFERENCES `purchase__rfq` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_rfq_item_unit` FOREIGN KEY (`quantity_unit_id`) REFERENCES `part__unit` (`id`),
  CONSTRAINT `fk_rfq_item_vp` FOREIGN KEY (`vendor_part_id`) REFERENCES `list__vendor_part` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
SQL,

    'purchase__order' => <<<'SQL'
CREATE TABLE `purchase__order` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `vendor_id` int(11) NOT NULL,
  `state` enum('draft','sent','confirmed','partially_received','received','cancelled','in_progress') NOT NULL DEFAULT 'draft',
  `po_number` varchar(64) DEFAULT NULL,
  `vendor_po_number` varchar(64) DEFAULT NULL,
  `converted_from_rfq_id` int(11) DEFAULT NULL,
  `expected_delivery_date` date DEFAULT NULL,
  `sent_at` datetime DEFAULT NULL,
  `confirmed_at` datetime DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `comment` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `pdf_generated_at` datetime DEFAULT NULL,
  `pdf_path` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_po_vendor` (`vendor_id`),
  KEY `idx_po_state` (`state`),
  KEY `fk_po_user` (`created_by`),
  KEY `fk_po_rfq` (`converted_from_rfq_id`),
  CONSTRAINT `fk_po_rfq` FOREIGN KEY (`converted_from_rfq_id`) REFERENCES `purchase__rfq` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_po_user` FOREIGN KEY (`created_by`) REFERENCES `user` (`user_id`),
  CONSTRAINT `fk_po_vendor` FOREIGN KEY (`vendor_id`) REFERENCES `list__vendor` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
SQL,

    // ── L4 ────────────────────────────────────────────────────────────
    'purchase__order_item' => <<<'SQL'
CREATE TABLE `purchase__order_item` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `po_id` int(11) NOT NULL,
  `vendor_part_id` int(11) NOT NULL,
  `quantity` decimal(30,10) NOT NULL,
  `quantity_unit_id` int(11) NOT NULL,
  `unit_price` decimal(30,10) NOT NULL DEFAULT 0.0000000000,
  `currency` varchar(8) NOT NULL DEFAULT 'PLN',
  `quantity_received` decimal(30,10) NOT NULL DEFAULT 0.0000000000,
  `comment` text DEFAULT NULL,
  `picked_pack_size` decimal(30,10) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_poi_po` (`po_id`),
  KEY `fk_poi_vp` (`vendor_part_id`),
  KEY `fk_poi_unit` (`quantity_unit_id`),
  CONSTRAINT `fk_poi_po` FOREIGN KEY (`po_id`) REFERENCES `purchase__order` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_poi_unit` FOREIGN KEY (`quantity_unit_id`) REFERENCES `part__unit` (`id`),
  CONSTRAINT `fk_poi_vp` FOREIGN KEY (`vendor_part_id`) REFERENCES `list__vendor_part` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
SQL,

    'purchase__order_receipt' => <<<'SQL'
CREATE TABLE `purchase__order_receipt` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `po_id` int(11) NOT NULL,
  `document_number` varchar(64) DEFAULT NULL,
  `received_by` int(11) NOT NULL,
  `received_at` datetime NOT NULL DEFAULT current_timestamp(),
  `comment` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_receipt_po` (`po_id`),
  KEY `fk_receipt_user` (`received_by`),
  CONSTRAINT `fk_receipt_po` FOREIGN KEY (`po_id`) REFERENCES `purchase__order` (`id`),
  CONSTRAINT `fk_receipt_user` FOREIGN KEY (`received_by`) REFERENCES `user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
SQL,

    // ── L5 ────────────────────────────────────────────────────────────
    'purchase__order_receipt_item' => <<<'SQL'
CREATE TABLE `purchase__order_receipt_item` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `receipt_id` int(11) NOT NULL,
  `po_item_id` int(11) NOT NULL,
  `quantity_received` decimal(30,10) NOT NULL,
  `sub_magazine_id` int(11) NOT NULL,
  `comment` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_receipt_item_receipt` (`receipt_id`),
  KEY `idx_receipt_item_po_item` (`po_item_id`),
  KEY `fk_receipt_item_mag` (`sub_magazine_id`),
  CONSTRAINT `fk_receipt_item_mag` FOREIGN KEY (`sub_magazine_id`) REFERENCES `magazine__list` (`sub_magazine_id`),
  CONSTRAINT `fk_receipt_item_po_item` FOREIGN KEY (`po_item_id`) REFERENCES `purchase__order_item` (`id`),
  CONSTRAINT `fk_receipt_item_receipt` FOREIGN KEY (`receipt_id`) REFERENCES `purchase__order_receipt` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
SQL,
];

// Expected column shape per table — used by the verify-existing step.
// Format: [name, COLUMN_TYPE (case-insensitive match), IS_NULLABLE ('YES'/'NO')].
// Only columns that THIS script expects to be present. Extra columns
// (e.g. legacy `email_subject_template` from a half-applied earlier
// migration) trigger a hard failure — operator must reconcile.
$expectedColumns = [
    'email_draft' => [
        ['id',         'int(11)',          'NO'],
        ['name',       'varchar(255)',     'NO'],
        ['subject',    'varchar(255)',     'NO'],
        ['body',       'mediumtext',       'NO'],
        ['applies_to', "enum('rfq','po','both')", 'NO'],
        ['is_active',  'tinyint(1)',       'NO'],
        ['created_at', 'datetime',         'NO'],
        ['updated_at', 'datetime',         'NO'],
    ],
    'email_draft_default' => [
        ['doc_type',   "enum('rfq','po')", 'NO'],
        ['draft_id',   'int(11)',          'NO'],
        ['updated_at', 'datetime',         'NO'],
    ],
    'list__currency' => [
        ['id',       'int(11)',      'NO'],
        ['code',     'varchar(8)',   'NO'],
        ['name',     'varchar(64)',  'NO'],
        ['isActive', 'tinyint(1)',   'NO'],
        ['comment',  'text',         'YES'],
    ],
    'list__producer' => [
        ['id',       'int(11)',      'NO'],
        ['name',     'varchar(255)', 'NO'],
        ['isActive', 'tinyint(1)',   'NO'],
        ['comment',  'text',         'YES'],
    ],
    'list__vendor' => [
        ['id',                          'int(11)',          'NO'],
        ['name',                        'varchar(255)',     'NO'],
        ['address',                     'text',             'YES'],
        ['additional_data',             'text',             'YES'],
        ['lead_time_days',              'int(11)',          'YES'],
        ['default_currency',            'int(11)',          'NO'],
        ['isActive',                    'tinyint(1)',       'NO'],
        ['comment',                     'text',             'YES'],
        ['preferred_email_draft_rfq_id','int(11)',          'YES'],
        ['preferred_email_draft_po_id', 'int(11)',          'YES'],
        ['created_at',                  'datetime',         'NO'],
        ['updated_at',                  'datetime',         'NO'],
    ],
    'list__vendor_part' => [
        ['id',              'int(11)',        'NO'],
        ['vendor_id',       'int(11)',        'NO'],
        ['producer_id',     'int(11)',        'NO'],
        ['parts_id',        'int(11)',        'NO'],
        ['vendor_part_no',  'varchar(255)',   'NO'],
        ['producer_part_no','varchar(255)',   'YES'],
        ['vendor_jm_id',    'int(11)',        'NO'],
        ['isActive',        'tinyint(1)',     'NO'],
        ['comment',         'text',           'YES'],
        ['created_at',      'datetime',       'NO'],
        ['updated_at',      'datetime',       'NO'],
    ],
    'list__vendor_part_pack' => [
        ['id',                'int(11)',        'NO'],
        ['vendor_part_id',    'int(11)',        'NO'],
        ['full_pack_quantity','decimal(30,10)', 'NO'],
        ['created_at',        'datetime',       'NO'],
    ],
    'list__vendor_supplier' => [
        ['id',         'int(11)',      'NO'],
        ['vendor_id',  'int(11)',      'NO'],
        ['name',       'varchar(255)', 'NO'],
        ['job_title',  'varchar(255)', 'YES'],
        ['phone',      'varchar(64)',  'YES'],
        ['email',      'varchar(255)', 'YES'],
        ['isActive',   'tinyint(1)',   'NO'],
        ['is_primary', 'tinyint(1)',   'NO'],
        ['comment',    'text',         'YES'],
    ],
    'purchase__number_counter' => [
        ['type',       "enum('rfq','po','grn')", 'NO'],
        ['vendor_id',  'int(11)',      'NO'],
        ['last_value', 'int(11)',      'NO'],
    ],
    'purchase__order' => [
        ['id',                      'int(11)',          'NO'],
        ['vendor_id',               'int(11)',          'NO'],
        ['state',                   "enum('draft','sent','confirmed','partially_received','received','cancelled','in_progress')", 'NO'],
        ['po_number',               'varchar(64)',      'YES'],
        ['vendor_po_number',        'varchar(64)',      'YES'],
        ['converted_from_rfq_id',   'int(11)',          'YES'],
        ['expected_delivery_date',  'date',             'YES'],
        ['sent_at',                 'datetime',         'YES'],
        ['confirmed_at',            'datetime',         'YES'],
        ['created_by',              'int(11)',          'NO'],
        ['comment',                 'text',             'YES'],
        ['created_at',              'datetime',         'NO'],
        ['updated_at',              'datetime',         'NO'],
        ['pdf_generated_at',        'datetime',         'YES'],
        ['pdf_path',                'varchar(255)',     'YES'],
    ],
    'purchase__order_item' => [
        ['id',                'int(11)',         'NO'],
        ['po_id',             'int(11)',         'NO'],
        ['vendor_part_id',    'int(11)',         'NO'],
        ['quantity',          'decimal(30,10)',  'NO'],
        ['quantity_unit_id',  'int(11)',         'NO'],
        ['unit_price',        'decimal(30,10)',  'NO'],
        ['currency',          'varchar(8)',      'NO'],
        ['quantity_received', 'decimal(30,10)',  'NO'],
        ['comment',           'text',            'YES'],
        ['picked_pack_size',  'decimal(30,10)',  'YES'],
    ],
    'purchase__order_receipt' => [
        ['id',             'int(11)',      'NO'],
        ['po_id',          'int(11)',      'NO'],
        ['document_number','varchar(64)',  'YES'],
        ['received_by',    'int(11)',      'NO'],
        ['received_at',    'datetime',     'NO'],
        ['comment',        'text',         'YES'],
    ],
    'purchase__order_receipt_item' => [
        ['id',                'int(11)',        'NO'],
        ['receipt_id',        'int(11)',        'NO'],
        ['po_item_id',        'int(11)',        'NO'],
        ['quantity_received', 'decimal(30,10)', 'NO'],
        ['sub_magazine_id',   'int(11)',        'NO'],
        ['comment',           'text',           'YES'],
    ],
    'purchase__rfq' => [
        ['id',                  'int(11)',      'NO'],
        ['vendor_id',           'int(11)',      'NO'],
        ['state',               "enum('draft','sent','responded','cancelled','converted')", 'NO'],
        ['rfq_number',          'varchar(64)',  'YES'],
        ['vendor_reference',    'varchar(128)', 'YES'],
        ['expected_reply_date', 'date',         'YES'],
        ['sent_at',             'datetime',     'YES'],
        ['responded_at',        'datetime',     'YES'],
        ['responded_by',        'int(11)',      'YES'],
        ['created_by',          'int(11)',      'NO'],
        ['comment',             'text',         'YES'],
        ['created_at',          'datetime',     'NO'],
        ['updated_at',          'datetime',     'NO'],
        ['pdf_generated_at',    'datetime',     'YES'],
        ['pdf_path',            'varchar(255)', 'YES'],
    ],
    'purchase__rfq_item' => [
        ['id',               'int(11)',         'NO'],
        ['rfq_id',           'int(11)',         'NO'],
        ['vendor_part_id',   'int(11)',         'NO'],
        ['quantity',         'decimal(30,10)',  'NO'],
        ['quantity_unit_id', 'int(11)',         'NO'],
        ['unit_price',       'decimal(30,10)',  'YES'],
        ['currency',         'varchar(8)',      'NO'],
        ['comment',          'text',            'YES'],
        ['picked_pack_size', 'decimal(30,10)',  'YES'],
    ],
];

// Expected FK constraint names per table (from information_schema.TABLE_CONSTRAINTS).
$expectedFks = [
    'email_draft'                => [],
    'email_draft_default'        => ['fk_ed_default_draft'],
    'list__currency'             => [],
    'list__producer'             => [],
    'list__vendor'               => ['fk_vendor_default_currency', 'fk_vendor_rfq_draft', 'fk_vendor_po_draft'],
    'list__vendor_part'          => ['fk_vp_parts', 'fk_vp_producer', 'fk_vp_unit', 'fk_vp_vendor'],
    'list__vendor_part_pack'     => ['fk_vpp_vendor_part'],
    'list__vendor_supplier'      => ['fk_vs_vendor'],
    'purchase__number_counter'   => [],
    'purchase__order'            => ['fk_po_rfq', 'fk_po_user', 'fk_po_vendor'],
    'purchase__order_item'       => ['fk_poi_po', 'fk_poi_unit', 'fk_poi_vp'],
    'purchase__order_receipt'    => ['fk_receipt_po', 'fk_receipt_user'],
    'purchase__order_receipt_item' => ['fk_receipt_item_mag', 'fk_receipt_item_po_item', 'fk_receipt_item_receipt'],
    'purchase__rfq'              => ['fk_rfq_responder', 'fk_rfq_user', 'fk_rfq_vendor'],
    'purchase__rfq_item'         => ['fk_rfq_item_rfq', 'fk_rfq_item_unit', 'fk_rfq_item_vp'],
];

// ── Helpers ──────────────────────────────────────────────────────────
$created = $skipped = $failed = 0;

function fetchActualColumns(\PDO $db, string $dbName, string $table): array {
    $stmt = $db->prepare(
        "SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT
           FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?
          ORDER BY ORDINAL_POSITION"
    );
    $stmt->execute([$dbName, $table]);
    $out = [];
    foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
        $out[$row['COLUMN_NAME']] = $row;
    }
    return $out;
}

function fetchActualFks(\PDO $db, string $dbName, string $table): array {
    $stmt = $db->prepare(
        "SELECT CONSTRAINT_NAME
           FROM information_schema.TABLE_CONSTRAINTS
          WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND CONSTRAINT_TYPE = 'FOREIGN KEY'"
    );
    $stmt->execute([$dbName, $table]);
    return array_column($stmt->fetchAll(\PDO::FETCH_ASSOC), 'CONSTRAINT_NAME');
}

function checkTableShape(
    \PDO $db, string $dbName, string $table,
    array $expectedCols, array $expectedFks
): array {
    $errors = [];
    $actualCols = fetchActualColumns($db, $dbName, $table);
    $actualFks  = fetchActualFks($db, $dbName, $table);

    // Each expected column must exist with matching type and nullability.
    foreach ($expectedCols as [$name, $type, $nullable]) {
        if (!isset($actualCols[$name])) {
            $errors[] = "missing column `{$name}`";
            continue;
        }
        if (strcasecmp($actualCols[$name]['COLUMN_TYPE'], $type) !== 0) {
            $errors[] = "column `{$name}` type mismatch: expected '{$type}', got '{$actualCols[$name]['COLUMN_TYPE']}'";
        }
        if (strcasecmp($actualCols[$name]['IS_NULLABLE'], $nullable) !== 0) {
            $errors[] = "column `{$name}` nullable mismatch: expected '{$nullable}', got '{$actualCols[$name]['IS_NULLABLE']}'";
        }
    }
    // No extra columns — strict; a half-applied earlier migration
    // (e.g. `email_subject_template`) would land here.
    foreach ($actualCols as $name => $info) {
        $found = false;
        foreach ($expectedCols as $ec) { if ($ec[0] === $name) { $found = true; break; } }
        if (!$found) { $errors[] = "unexpected column `{$name}`"; }
    }

    // FK set equality (order-independent).
    $expFks = array_map('strval', $expectedFks);
    $actFks = array_map('strval', $actualFks);
    sort($expFks); sort($actFks);
    foreach (array_diff($expFks, $actFks) as $fk) { $errors[] = "missing FK `{$fk}`"; }
    foreach (array_diff($actFks, $expFks) as $fk) { $errors[] = "unexpected FK `{$fk}`"; }

    return $errors;
}

// ── Interactive confirm (skipped in --dry-run and with --yes) ────────
if (!$dryRun && !$autoYes) {
    echo "This will CREATE 15 new tables in '{$dbName}' and seed 5 reference tables.\n";
    echo "Type YES to continue: ";
    $answer = trim((string) fgets(STDIN));
    if ($answer !== 'YES') {
        fwrite(STDERR, "✗ Aborted by user.\n");
        exit(1);
    }
    echo "\n";
}

// ── Phase 1: Create L0 tables (no new-table deps) ───────────────────
echo "── Phase 1 / 4: Create tables (L0) ──\n";
$l0 = ['email_draft', 'list__currency', 'list__producer', 'purchase__number_counter'];
foreach ($l0 as $name) {
    $checkStmt = $db->prepare(
        "SELECT 1 FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?"
    );
    $checkStmt->execute([$dbName, $name]);
    if ($checkStmt->fetchColumn()) {
        // Verify shape
        $errs = checkTableShape($db, $dbName, $name, $expectedColumns[$name], $expectedFks[$name]);
        if (!empty($errs)) {
            fwrite(STDERR, "✗ {$name}: already exists but shape mismatch — " . implode('; ', $errs) . "\n");
            $failed++;
            continue;
        }
        echo "= {$name}: already exists (shape verified)\n";
        $skipped++;
        continue;
    }
    if ($dryRun) {
        echo "· {$name}: would CREATE\n";
        $skipped++;
        continue;
    }
    try {
        $db->exec($tables[$name]);
        echo "✓ {$name}: created\n";
        $created++;
    } catch (\PDOException $e) {
        fwrite(STDERR, "✗ {$name}: " . $e->getMessage() . "\n");
        $failed++;
    }
}
echo "\n";

// ── Phase 2: Seed list__currency, then build list__vendor DDL ────────
echo "── Phase 2 / 4: Seed list__currency & resolve PLN id ──\n";

// Seed list__currency. The 13 codes/names mirror the legacy seed in
// src/cron/migrate-to-procurement-schema.php:870-890. INSERT IGNORE on
// the UNIQUE code index makes the seed idempotent.
if ($dryRun) {
    // In dry-run the L0 tables haven't been created yet, so the SELECT
    // would 1146. Just plan the seed against the would-be-default state.
    echo "· list__currency seed: would INSERT IGNORE 13 rows (table not yet created in dry-run)\n";
    $plnId = 1; // optimistic guess for downstream dry-run planning
} else {
    $currencyCountBefore = (int) $db->query(
        "SELECT COUNT(*) FROM `list__currency`"
    )->fetchColumn();
    $db->exec("
        INSERT IGNORE INTO `list__currency` (`code`, `name`, `isActive`) VALUES
            ('PLN', 'Polski złoty',         1),
            ('EUR', 'Euro',                 1),
            ('USD', 'Dolar amerykański',    1),
            ('GBP', 'Funt szterling',       1),
            ('CHF', 'Frank szwajcarski',    1),
            ('CZK', 'Korona czeska',        1),
            ('UAH', 'Hrywna ukraińska',     1),
            ('SEK', 'Korona szwedzka',      1),
            ('NOK', 'Korona norweska',      1),
            ('DKK', 'Korona duńska',        1),
            ('HUF', 'Forint węgierski',     1),
            ('CNY', 'Juan chiński',         1),
            ('JPY', 'Jen japoński',         1)
    ");
    $currencyCountAfter = (int) $db->query("SELECT COUNT(*) FROM `list__currency`")->fetchColumn();
    $inserted = $currencyCountAfter - $currencyCountBefore;
    echo "✓ list__currency: seeded (was {$currencyCountBefore}, now {$currencyCountAfter}, +{$inserted})\n";
    $plnId = (int) $db->query("SELECT id FROM `list__currency` WHERE code = 'PLN'")->fetchColumn();
}
if ($plnId <= 0) {
    fwrite(STDERR, "✗ Could not resolve PLN id in list__currency (got {$plnId}). Aborting before L1 — list__vendor needs the default.\n");
    exit(1);
}
echo "  PLN id = {$plnId}\n\n";

// Build list__vendor DDL with the resolved PLN id in the default.
// This is the ONE intentional deviation from the new_struct dump.
$tables['list__vendor'] = <<<SQL
CREATE TABLE `list__vendor` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `address` text DEFAULT NULL,
  `additional_data` text DEFAULT NULL,
  `lead_time_days` int(11) DEFAULT NULL,
  `default_currency` int(11) NOT NULL DEFAULT {$plnId},
  `isActive` tinyint(1) NOT NULL DEFAULT 1,
  `comment` text DEFAULT NULL,
  `preferred_email_draft_rfq_id` int(11) DEFAULT NULL,
  `preferred_email_draft_po_id` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_active` (`isActive`),
  KEY `idx_vendor_default_currency` (`default_currency`),
  KEY `fk_vendor_rfq_draft` (`preferred_email_draft_rfq_id`),
  KEY `fk_vendor_po_draft` (`preferred_email_draft_po_id`),
  CONSTRAINT `fk_vendor_default_currency` FOREIGN KEY (`default_currency`) REFERENCES `list__currency` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_vendor_rfq_draft` FOREIGN KEY (`preferred_email_draft_rfq_id`) REFERENCES `email_draft` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_vendor_po_draft` FOREIGN KEY (`preferred_email_draft_po_id`) REFERENCES `email_draft` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
SQL;

// ── Phase 3: Create L1..L5 tables ───────────────────────────────────
echo "── Phase 3 / 4: Create tables (L1..L5) ──\n";
$rest = [
    // L1
    'email_draft_default', 'list__vendor',
    // L2
    'list__vendor_part', 'list__vendor_supplier', 'purchase__rfq',
    // L3
    'list__vendor_part_pack', 'purchase__rfq_item', 'purchase__order',
    // L4
    'purchase__order_item', 'purchase__order_receipt',
    // L5
    'purchase__order_receipt_item',
];
foreach ($rest as $name) {
    $checkStmt = $db->prepare(
        "SELECT 1 FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?"
    );
    $checkStmt->execute([$dbName, $name]);
    if ($checkStmt->fetchColumn()) {
        $errs = checkTableShape($db, $dbName, $name, $expectedColumns[$name], $expectedFks[$name]);

        // Special case: list__vendor.default_currency MUST have a
        // DEFAULT (any non-NULL value). The dump has no default but
        // seed-component-procurement-from-gsheet.php:288 inserts
        // without that column. If the default is missing, fix it
        // with a MODIFY.
        if ($name === 'list__vendor' && empty($errs)) {
            $cur = fetchActualColumns($db, $dbName, $name);
            if (($cur['default_currency']['COLUMN_DEFAULT'] ?? null) === null) {
                if ($dryRun) {
                    echo "· list__vendor.default_currency: would MODIFY to add DEFAULT {$plnId} (currently no default)\n";
                } else {
                    try {
                        $db->exec("ALTER TABLE `list__vendor`
                                   MODIFY `default_currency` int(11) NOT NULL DEFAULT {$plnId}");
                        echo "✓ list__vendor.default_currency: added DEFAULT {$plnId} (was missing)\n";
                        $created++;
                    } catch (\PDOException $e) {
                        fwrite(STDERR, "✗ list__vendor.default_currency MODIFY: " . $e->getMessage() . "\n");
                        $failed++;
                    }
                }
            }
        }
        if (!empty($errs)) {
            fwrite(STDERR, "✗ {$name}: already exists but shape mismatch — " . implode('; ', $errs) . "\n");
            $failed++;
            continue;
        }
        echo "= {$name}: already exists (shape verified)\n";
        $skipped++;
        continue;
    }
    if ($dryRun) {
        echo "· {$name}: would CREATE\n";
        $skipped++;
        continue;
    }
    try {
        $db->exec($tables[$name]);
        echo "✓ {$name}: created\n";
        $created++;
    } catch (\PDOException $e) {
        fwrite(STDERR, "✗ {$name}: " . $e->getMessage() . "\n");
        $failed++;
    }
}
echo "\n";

// ── Phase 4: Seeds (in a single transaction) ────────────────────────
// DDL is implicitly committed; only the data writes are transactional.
echo "── Phase 4 / 4: Seeds ──\n";

if ($dryRun) {
    echo "· ref__transfer_group_types: would INSERT IGNORE ('purchase_receipt', 'Przyjęcie z zamówienia #{po_id}')\n";
    echo "· email_draft: would INSERT 'Domyślny' (RFQ) and 'Domyślny — zamówienie' (PO) if absent\n";
    echo "· email_draft_default: would INSERT IGNORE ('rfq', id), ('po', id)\n";
    echo "· inventory__input_type: would INSERT 'Przyjęcie z zamówienia PO' if absent\n";
    echo "  (all seeds wrapped in a single transaction)\n";
    $skipped += 4;
    echo "\n";
} else {
    try {
        $db->beginTransaction();

        // 1) ref__transfer_group_types — used by goods-receipt flow.
        //    Template text copied verbatim from
        //    src/cron/migrate-to-procurement-schema.php:856.
        $db->prepare(
            "INSERT IGNORE INTO `ref__transfer_group_types` (`slug`, `template`)
             VALUES ('purchase_receipt', :template)"
        )->execute([':template' => 'Przyjęcie z zamówienia #{po_id}']);
        $slugCount = (int) $db->query(
            "SELECT COUNT(*) FROM `ref__transfer_group_types` WHERE `slug` = 'purchase_receipt'"
        )->fetchColumn();
        echo "✓ ref__transfer_group_types 'purchase_receipt': " . ($slugCount === 1 ? 'present' : "MISSING (got {$slugCount})") . "\n";

        // 2) email_draft — two templates. The 'Domyślny' body is the
        //    RFQ query text from public_html/migrations/
        //    2026_09_28_create_email_drafts.sql step 8 (subject +
        //    body). The 'Domyślny — zamówienie' body is the PO order
        //    text from public_html/migrations/
        //    2026_10_08_seed_default_draft_templates.sql step 1.
        //    Each insert is gated on the name (so a manual edit of
        //    the row is preserved on re-run).
        $rfqExists = (int) $db->query("SELECT COUNT(*) FROM `email_draft` WHERE `name` = 'Domyślny'")->fetchColumn();
        if ($rfqExists === 0) {
            $db->exec("
                INSERT INTO `email_draft`
                    (`name`, `subject`, `body`, `applies_to`, `is_active`, `created_at`, `updated_at`)
                VALUES (
                    'Domyślny',
                    'Zapytanie ofertowe {document_number}',
                    'Dotyczy: zapytanie ofertowe {document_number} — {vendor_name}\n\nSzanowni Państwo,\n\nZwracamy się z prośbą o ofertę na następujące części:\n\n{items}\n\nOczekiwany termin odpowiedzi: {expected_reply_date}.\n\nZ poważaniem,\n{created_by_name}',
                    'rfq',
                    1,
                    NOW(),
                    NOW()
                )
            ");
            echo "✓ email_draft 'Domyślny' (RFQ): inserted\n";
            $created++;
        } else {
            echo "= email_draft 'Domyślny' (RFQ): already present (skipped)\n";
            $skipped++;
        }

        $poExists = (int) $db->query("SELECT COUNT(*) FROM `email_draft` WHERE `name` = 'Domyślny — zamówienie'")->fetchColumn();
        if ($poExists === 0) {
            $db->exec("
                INSERT INTO `email_draft`
                    (`name`, `subject`, `body`, `applies_to`, `is_active`, `created_at`, `updated_at`)
                VALUES (
                    'Domyślny — zamówienie',
                    'Zamówienie {document_number}',
                    'Dotyczy: zamówienie {document_number} — {vendor_name}\n\nSzanowni Państwo,\n\nPrzesyłamy zamówienie na następujące części:\n\n{items}\n\nOczekiwany termin dostawy: {expected_delivery_date}.\n\nZ poważaniem,\n{created_by_name}',
                    'po',
                    1,
                    NOW(),
                    NOW()
                )
            ");
            echo "✓ email_draft 'Domyślny — zamówienie' (PO): inserted\n";
            $created++;
        } else {
            echo "= email_draft 'Domyślny — zamówienie' (PO): already present (skipped)\n";
            $skipped++;
        }

        // 3) email_draft_default — pointers to the seeded drafts.
        //    INSERT IGNORE on the PK (doc_type) — never overwrite an
        //    existing operator-chosen pointer.
        $db->exec("
            INSERT IGNORE INTO `email_draft_default` (`doc_type`, `draft_id`, `updated_at`)
                 SELECT 'rfq', id, NOW() FROM `email_draft` WHERE `name` = 'Domyślny' LIMIT 1
        ");
        $db->exec("
            INSERT IGNORE INTO `email_draft_default` (`doc_type`, `draft_id`, `updated_at`)
                 SELECT 'po',  id, NOW() FROM `email_draft` WHERE `name` = 'Domyślny — zamówienie' LIMIT 1
        ");
        $defaultCount = (int) $db->query("SELECT COUNT(*) FROM `email_draft_default`")->fetchColumn();
        echo "✓ email_draft_default: {$defaultCount} pointer row(s) present\n";

        // 4) inventory__input_type — add a new row for the PO receipt
        //    label. The historical id=1 row 'Aktualizacja z Google
        //    Sheets' is preserved (it's referenced by every FlowPin /
        //    Sheet-driven stock row). New purchases stamp id=N with
        //    name 'Przyjęcie z zamówienia PO' (resolved by
        //    PurchaseActionHandler via the exact-name lookup).
        $newName = 'Przyjęcie z zamówienia PO';
        $inputTypeExists = (int) $db->query(
            "SELECT COUNT(*) FROM `inventory__input_type` WHERE `name` = " . $db->quote($newName)
        )->fetchColumn();
        if ($inputTypeExists === 0) {
            $db->exec("INSERT INTO `inventory__input_type` (`name`) VALUES (" . $db->quote($newName) . ")");
            $newId = (int) $db->lastInsertId();
            echo "✓ inventory__input_type '{$newName}': inserted (id={$newId})\n";
            $created++;
        } else {
            $existingId = (int) $db->query(
                "SELECT id FROM `inventory__input_type` WHERE `name` = " . $db->quote($newName)
            )->fetchColumn();
            echo "= inventory__input_type '{$newName}': already present (id={$existingId}, skipped)\n";
            $skipped++;
        }

        $db->commit();
    } catch (\PDOException $e) {
        if ($db->inTransaction()) { $db->rollBack(); }
        fwrite(STDERR, "✗ Seed phase failed: " . $e->getMessage() . "\n");
        $failed++;
    }
    echo "\n";
}

// ── Post-verify ──────────────────────────────────────────────────────
// Skip verification on --dry-run — there's no schema to verify yet.
if ($dryRun) {
    echo "── Dry-run summary ──\n";
    echo "All actions printed above would be performed on a live run.\n";
    echo "Created+seeds (planned): " . ($created + $skipped) . " | Skipped: 0 | Failed: {$failed}\n";
    exit($failed === 0 ? 0 : 2);
}

echo "── Post-verify ──\n";

// 1. Total table count
$totalTableCount = (int) $db->query(
    "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = " . $db->quote($dbName)
)->fetchColumn();
echo ($totalTableCount === 59 ? '✓' : '✗') . " Total tables: {$totalTableCount} (expected 59)\n";

// 2. All 15 new tables present
$newTableNames = array_keys($tables);
$placeholders = implode(',', array_fill(0, count($newTableNames), '?'));
$stmt = $db->prepare(
    "SELECT TABLE_NAME FROM information_schema.TABLES
      WHERE TABLE_SCHEMA = ? AND TABLE_NAME IN ($placeholders)"
);
$stmt->execute(array_merge([$dbName], $newTableNames));
$present = array_column($stmt->fetchAll(\PDO::FETCH_ASSOC), 'TABLE_NAME');
$missingTables = array_diff($newTableNames, $present);
echo (empty($missingTables) ? '✓' : '✗') . " All 15 new tables present: "
   . (empty($missingTables) ? "yes" : "MISSING " . implode(', ', $missingTables)) . "\n";

// 3. Exactly 27 FK names on the new tables
$stmt = $db->prepare(
    "SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE
      WHERE TABLE_SCHEMA = ? AND TABLE_NAME IN ($placeholders)
        AND REFERENCED_TABLE_NAME IS NOT NULL"
);
$stmt->execute(array_merge([$dbName], $newTableNames));
$fkCount = (int) $stmt->fetchColumn();
echo ($fkCount === 27 ? '✓' : '✗') . " FKs on new tables: {$fkCount} (expected 27)\n";

// 4. Trigger count unchanged
$triggerStmt->execute([$dbName]);
$triggerCountAfter = (int) $triggerStmt->fetchColumn();
echo ($triggerCountAfter === $triggerCountBefore ? '✓' : '✗')
   . " Triggers: {$triggerCountBefore} → {$triggerCountAfter} (unchanged)\n";

// 5. Seed rows present
$slugCount = (int) $db->query(
    "SELECT COUNT(*) FROM `ref__transfer_group_types` WHERE `slug` = 'purchase_receipt'"
)->fetchColumn();
echo ($slugCount === 1 ? '✓' : '✗') . " Seed slug 'purchase_receipt': present (1 row)\n";

$currencyCount = (int) $db->query("SELECT COUNT(*) FROM `list__currency`")->fetchColumn();
echo ($currencyCount >= 13 ? '✓' : '✗') . " list__currency rows: {$currencyCount} (expected ≥ 13)\n";

$rfqDraftCount = (int) $db->query("SELECT COUNT(*) FROM `email_draft` WHERE `name` = 'Domyślny'")->fetchColumn();
$poDraftCount  = (int) $db->query("SELECT COUNT(*) FROM `email_draft` WHERE `name` = 'Domyślny — zamówienie'")->fetchColumn();
echo (($rfqDraftCount === 1 && $poDraftCount === 1) ? '✓' : '✗')
   . " email_draft seeds: RFQ={$rfqDraftCount}, PO={$poDraftCount} (expected 1+1)\n";

$defaultCount = (int) $db->query("SELECT COUNT(*) FROM `email_draft_default`")->fetchColumn();
echo ($defaultCount === 2 ? '✓' : '✗') . " email_draft_default pointers: {$defaultCount} (expected 2)\n";

$newInputTypeCount = (int) $db->query(
    "SELECT COUNT(*) FROM `inventory__input_type` WHERE `name` = " . $db->quote('Przyjęcie z zamówienia PO')
)->fetchColumn();
echo ($newInputTypeCount === 1 ? '✓' : '✗')
   . " inventory__input_type 'Przyjęcie z zamówienia PO': {$newInputTypeCount} (expected 1)\n";

$historicalInputType = (int) $db->query(
    "SELECT COUNT(*) FROM `inventory__input_type` WHERE `id` = 1"
)->fetchColumn();
echo ($historicalInputType === 1 ? '✓' : '✗')
   . " inventory__input_type id=1 (historical 'Aktualizacja z Google Sheets'): preserved (1 row)\n";

echo "\n";

// ── Exit ─────────────────────────────────────────────────────────────
$ok = $failed === 0
    && $totalTableCount === 59
    && empty($missingTables)
    && $fkCount === 27
    && $triggerCountAfter === $triggerCountBefore
    && $slugCount === 1
    && $currencyCount >= 13
    && $rfqDraftCount === 1
    && $poDraftCount === 1
    && $defaultCount === 2
    && $newInputTypeCount === 1
    && $historicalInputType === 1;

echo "── Summary ──\n";
echo "Created: {$created} | Skipped (already): {$skipped} | Failed: {$failed}\n";
echo $ok ? "ALL CHECKS PASSED ✓\n" : "FAILURES DETECTED ✗\n";
exit($ok ? 0 : 2);
