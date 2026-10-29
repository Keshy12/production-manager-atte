<?php
/**
 * One-shot CLI: make purchase__order_item.unit_price NULLABLE.
 *
 * Why:
 *   The PO line item price used to be `decimal(30,10) NOT NULL
 *   DEFAULT 0.0000000000`, which forced every PO line to carry a
 *   price — there was no way to express "price not set yet".
 *   purchase__rfq_item.unit_price is already `decimal(30,10) DEFAULT
 *   NULL`; this migration brings the PO item in line with it.
 *
 * What:
 *   ALTER TABLE `purchase__order_item`
 *     MODIFY COLUMN `unit_price` decimal(30,10) DEFAULT NULL;
 *
 *   This is a METADATA-ONLY change (NOT NULL → NULL). It does NOT
 *   touch row data — no INSERT, no UPDATE, no backfill. Rows whose
 *   unit_price is currently 0 keep the value 0: zero is a legal
 *   price, and the product owner explicitly decided not to rewrite
 *   existing data. NULL is only ever produced by new writes.
 *
 * - Idempotent: the column's nullability is probed via
 *   information_schema.COLUMNS before the ALTER runs. On a database
 *   where the column is already nullable the script prints
 *   "nothing to do" and exits 0 — re-running is safe.
 * - Non-destructive: the script never creates the table. If
 *   purchase__order_item does not exist yet, run
 *   src/cron/migrate-to-procurement-schema.php (or
 *   src/cron/migrate-production-to-purchase-schema.php) first — the
 *   CREATE TABLE blocks in those scripts already declare unit_price
 *   as nullable.
 *
 * Pre-flight:
 *   Refuses to run unless the connected database is named exactly
 *   `atte_ms` — the same guard the other procurement migrations use.
 *
 * Usage:
 *   php src/cron/migrate-po-item-price-nullable.php
 *
 * Exit codes:
 *   0 — column is nullable (either already was, or the ALTER succeeded)
 *   1 — wrong database, missing table/column, or the ALTER failed
 */

use Atte\DB\MsaDB;

require_once __DIR__ . '/../../config/config.php';

// ── Pre-flight: refuse to run against the wrong database ──────────────
$MsaDB  = MsaDB::getInstance();
$db     = $MsaDB->db;
$dbName = $db->query('SELECT DATABASE()')->fetchColumn();
if ($dbName !== 'atte_ms') {
    fwrite(STDERR, "✗ Connected to '{$dbName}', not 'atte_ms'. Aborting — refusing to migrate the wrong database.\n");
    exit(1);
}
echo "Connected to: {$dbName}\n\n";

$table = 'purchase__order_item';
$col   = 'unit_price';

try {
    // ── Inspect the current column shape ─────────────────────────────
    $probe = $db->prepare(
        "SELECT IS_NULLABLE, COLUMN_TYPE
           FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME   = ?
            AND COLUMN_NAME  = ?"
    );
    $probe->execute([$table, $col]);
    $row = $probe->fetch(\PDO::FETCH_ASSOC);

    if ($row === false) {
        fwrite(STDERR,
            "✗ Table `{$table}` with column `{$col}` not found in '{$dbName}'.\n"
          . "  This migration only alters an existing column — it never creates\n"
          . "  the table. Run src/cron/migrate-to-procurement-schema.php (or\n"
          . "  src/cron/migrate-production-to-purchase-schema.php) first.\n"
        );
        exit(1);
    }

    $isNullable = (string) $row['IS_NULLABLE'];
    $colType    = (string) $row['COLUMN_TYPE'];

    // ── Already nullable → nothing to do ────────────────────────────
    if (strcasecmp($isNullable, 'YES') === 0) {
        echo "= {$table}.{$col}: already nullable ({$colType}) — nothing to do.\n";
        echo "\nSummary: no change needed; purchase__order_item.unit_price is already nullable.\n";
        exit(0);
    }

    // ── Make it nullable (metadata-only) ────────────────────────────
    // No backfill: existing rows keep their current values (0 stays 0).
    $db->exec("ALTER TABLE `{$table}` MODIFY COLUMN `{$col}` decimal(30,10) DEFAULT NULL");
    echo "✓ {$table}.{$col}: NOT NULL → NULL (decimal(30,10) DEFAULT NULL)\n";

    // ── Verify ──────────────────────────────────────────────────────
    $probe->execute([$table, $col]);
    $after = $probe->fetch(\PDO::FETCH_ASSOC);
    if ($after === false || strcasecmp((string) $after['IS_NULLABLE'], 'YES') !== 0) {
        fwrite(STDERR, "✗ Verification failed — {$table}.{$col} is still not nullable.\n");
        exit(1);
    }

    echo "\n";
    echo "Summary:\n";
    echo "  - {$table}.{$col} is now `decimal(30,10) DEFAULT NULL`.\n";
    echo "  - Metadata-only change (NOT NULL → NULL) — no row data was touched.\n";
    echo "  - No backfill performed: existing rows keep their current\n";
    echo "    unit_price values (a stored 0 stays 0 — zero is a legal price).\n";
    exit(0);

} catch (\PDOException $e) {
    fwrite(STDERR, "✗ {$table}.{$col}: " . $e->getMessage() . "\n");
    exit(1);
}
