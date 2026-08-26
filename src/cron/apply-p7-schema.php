<?php
/**
 * One-shot CLI: add the `picked_pack_size` denormalised column to the two
 * purchase line-item tables.
 *
 * Usage:
 *   php src/cron/apply-p7-schema.php
 *
 * Adds DECIMAL(30,10) NULL column `picked_pack_size` to:
 *   - purchase__order_item
 *   - purchase__rfq_item
 *
 * Idempotent — each table is checked via information_schema.COLUMNS first;
 * the ALTER is skipped if the column already exists. Safe to re-run.
 *
 * Notes for future maintainers:
 *   - Mirrors apply-p6-schema.php's runner pattern. The original
 *     docs/procurement/sql/P1-schema.sql defines the base DDL; this script
 *     adds columns to tables that were created in later phases (P2/P3) and
 *     therefore live in P2/P3 SQL files. See those files for the table
 *     shape; this runner only adds the column.
 *   - The two target tables may not yet exist on the live DB if P2/P3
 *     were never applied (e.g. fresh checkout). The script handles
 *     "table missing" gracefully — prints a clear message, increments
 *     the skipped counter, and moves on. It does NOT abort.
 *   - Verification block at the end lists the final column state on each
 *     table that exists.
 */
use Atte\DB\MsaDB;

require_once __DIR__ . '/../../config/config.php';

$tables = [
    'purchase__order_item',
    'purchase__rfq_item',
];

$MsaDB = MsaDB::getInstance();
$db     = $MsaDB->db;

// Verify we're attached to the expected database. If the PDO
// connection defaults to a different schema (some XAMPP installs
// do this), the renames would silently land elsewhere.
$dbName = $db->query('SELECT DATABASE() AS d')->fetch(\PDO::FETCH_ASSOC)['d'] ?? null;
if ($dbName !== 'atte_ms') {
    fwrite(STDERR, "✗ MsaDB is connected to '{$dbName}', not 'atte_ms'. Aborting — refusing to migrate the wrong database.\n");
    exit(1);
}
echo "Connected to: {$dbName}\n\n";

$migrated = 0;
$skipped  = 0;
$failed   = 0;

foreach ($tables as $table) {
    // First: does the table even exist? P2/P3 may not have been applied yet.
    $tableCheck = $db->prepare(
        "SELECT COUNT(*) AS c
           FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME   = ?"
    );
    $tableCheck->execute([$table]);
    $tableExists = ((int)$tableCheck->fetch(\PDO::FETCH_ASSOC)['c']) > 0;

    if (!$tableExists) {
        echo "= {$table}: table missing, skipped (apply P2/P3 first)\n";
        $skipped++;
        continue;
    }

    // Idempotency: skip if the column already exists.
    $col = $db->prepare(
        "SELECT COLUMN_NAME
           FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME   = ?
            AND COLUMN_NAME  = 'picked_pack_size'"
    );
    $col->execute([$table]);
    $found = $col->fetch(\PDO::FETCH_ASSOC);

    if ($found) {
        echo "= {$table}: picked_pack_size already present (skipped)\n";
        $skipped++;
        continue;
    }

    // Run the ALTER. DECIMAL(30,10) NULL matches the existing DECIMAL(30,10)
    // columns on these tables (quantity, unit_price, etc.) so the
    // chosen pack size round-trips through PDO/MySQL without surprise
    // precision loss.
    try {
        $db->exec(
            "ALTER TABLE `{$table}` ADD COLUMN `picked_pack_size` DECIMAL(30,10) NULL"
        );
        echo "✓ {$table}: added picked_pack_size DECIMAL(30,10) NULL\n";
        $migrated++;
    } catch (\PDOException $e) {
        fwrite(STDERR, "✗ {$table}: " . $e->getMessage() . "\n");
        $failed++;
    }
}

echo "\nDone. Migrated: $migrated, Skipped: $skipped, Failed: $failed.\n";

// Verification block: print the final column state on each of the two
// tables (if the table exists). Should show picked_pack_size = 1 column
// per existing table, NULL = "yes, no rows yet" or "all NULL".
echo "\nPurchase line-item tables — picked_pack_size column:\n";
foreach ($tables as $t) {
    $stmt = $db->prepare(
        "SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT
           FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME   = ?
            AND COLUMN_NAME  = 'picked_pack_size'"
    );
    $stmt->execute([$t]);
    $row = $stmt->fetch(\PDO::FETCH_ASSOC);
    if ($row === false) {
        echo "  - {$t}: MISSING ✗ (table may not exist)\n";
        continue;
    }
    echo "  - {$t}: {$row['COLUMN_NAME']} {$row['COLUMN_TYPE']} NULL={$row['IS_NULLABLE']} default=" . ($row['COLUMN_DEFAULT'] ?? 'NULL') . " ✓\n";
}

if ($failed === 0 && $migrated + $skipped === count($tables)) {
    echo "\nAll reachable tables migrated. Cart can read picked_pack_size on draft RFQ/PO lines now.\n";
} else {
    echo "\nMigration incomplete — re-run or check the errors above.\n";
}