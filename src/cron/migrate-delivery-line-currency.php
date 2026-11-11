<?php
/**
 * One-shot CLI: add the `currency` column to `purchase__delivery_line`
 * (per-line currency of a reception).
 *
 * Why:
 *   The supplier document (invoice / WZ) has ONE currency in its header,
 *   but a single invoice may mix currencies (e.g. most lines in PLN and one
 *   line in USD). The line currency decides:
 *     - which PO lines the line may be allocated to (DeliveryMatcher skips
 *       PO lines in another currency),
 *     - the "currency differs" blocker before posting,
 *     - whether the invoice price may be written into the PO item
 *       (PurchaseActionHandler::postDelivery).
 *
 * What:
 *   ALTER TABLE `purchase__delivery_line`
 *     ADD COLUMN `currency` varchar(3) DEFAULT NULL AFTER `unit_price`;
 *
 *   NULL = the line inherits the document currency (all existing rows keep
 *   that meaning — no backfill, no row data touched).
 *
 * - Idempotent: the column is probed via information_schema.COLUMNS before
 *   the ALTER runs. On a database that already has the column the script
 *   prints "nothing to do" and exits 0 — re-running is safe.
 * - Non-destructive: the script never creates the table. If
 *   purchase__delivery_line does not exist, run
 *   src/cron/migrate-purchase-delivery-schema.php first (its CREATE TABLE
 *   block already declares the `currency` column).
 *
 * Pre-flight:
 *   Refuses to run unless the connected database is named exactly
 *   `atte_ms` — the same guard the other procurement migrations use.
 *
 * Usage:
 *   php src/cron/migrate-delivery-line-currency.php            # applies
 *   php src/cron/migrate-delivery-line-currency.php --dry-run  # prints only
 *
 * Exit codes:
 *   0 — the column exists (either already was, or the ALTER succeeded)
 *   1 — wrong database, missing table, or the ALTER failed
 */

use Atte\DB\MsaDB;

require_once __DIR__ . '/../../config/config.php';

$dryRun = in_array('--dry-run', $argv ?? [], true);

// ── Pre-flight: refuse to run against the wrong database ──────────────
$MsaDB  = MsaDB::getInstance();
$db     = $MsaDB->db;
$dbName = $db->query('SELECT DATABASE()')->fetchColumn();
if ($dbName !== 'atte_ms') {
    fwrite(STDERR, "✗ Connected to '{$dbName}', not 'atte_ms'. Aborting — refusing to migrate the wrong database.\n");
    exit(1);
}
echo "Connected to: {$dbName}\n";
if ($dryRun) {
    echo "Mode: DRY RUN (no changes)\n";
}
echo "\n";

$table = 'purchase__delivery_line';
$col   = 'currency';

try {
    // ── Does the table exist? ────────────────────────────────────────
    $tbl = $db->prepare(
        "SELECT COUNT(*)
           FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME   = ?"
    );
    $tbl->execute([$table]);
    if ((int)$tbl->fetchColumn() === 0) {
        fwrite(STDERR,
            "✗ Table `{$table}` not found in '{$dbName}'.\n"
          . "  This migration only alters an existing table — it never creates\n"
          . "  it. Run src/cron/migrate-purchase-delivery-schema.php first.\n"
        );
        exit(1);
    }

    // ── Does the column exist already? ───────────────────────────────
    $probe = $db->prepare(
        "SELECT COLUMN_TYPE
           FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME   = ?
            AND COLUMN_NAME  = ?"
    );
    $probe->execute([$table, $col]);
    $row = $probe->fetch(\PDO::FETCH_ASSOC);

    if ($row !== false) {
        echo "= {$table}.{$col}: already present ({$row['COLUMN_TYPE']}) — nothing to do.\n";
        echo "\nSummary: no change needed; {$table}.{$col} already exists.\n";
        exit(0);
    }

    $ddl = "ALTER TABLE `{$table}` ADD COLUMN `{$col}` varchar(3) DEFAULT NULL AFTER `unit_price`";

    if ($dryRun) {
        echo "→ would run: {$ddl}\n";
        echo "\nSummary (dry run): nothing was changed.\n";
        exit(0);
    }

    // ── Add the column ───────────────────────────────────────────────
    $db->exec($ddl);
    echo "✓ {$table}.{$col}: added (varchar(3) DEFAULT NULL)\n";

    // ── Verify ───────────────────────────────────────────────────────
    $probe->execute([$table, $col]);
    $after = $probe->fetch(\PDO::FETCH_ASSOC);
    if ($after === false) {
        fwrite(STDERR, "✗ Verification failed — {$table}.{$col} is missing after the ALTER.\n");
        exit(1);
    }

    echo "\n";
    echo "Summary:\n";
    echo "  - {$table}.{$col} is now `{$after['COLUMN_TYPE']}`.\n";
    echo "  - Metadata-only change — no row data was touched.\n";
    echo "  - No backfill: existing rows keep currency = NULL, which means\n";
    echo "    „inherit the document's currency\" (same behaviour as before).\n";
    exit(0);

} catch (\PDOException $e) {
    fwrite(STDERR, "✗ {$table}.{$col}: " . $e->getMessage() . "\n");
    exit(1);
}
