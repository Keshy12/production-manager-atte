<?php
/**
 * One-shot CLI: rename the `is_active` column to `isActive` on the four
 * procurement master tables so they match the rest of the `list__*` /
 * `magazine__*` convention (camelCase).
 *
 * Usage:
 *   php src/cron/apply-p6-schema.php
 *
 * Idempotent — each table is checked via information_schema.COLUMNS
 * first; the ALTER is skipped if the column is already named isActive.
 * Safe to re-run.
 *
 * Notes for future maintainers:
 *   - The original docs/procurement/sql/P6-schema.sql defines a stored
 *     procedure with a `DELIMITER $$` block. That works via the
 *     `mysql` CLI but PDO chokes on the `$$` marker and on cursors
 *     over information_schema. So this PHP runner implements the
 *     same rename in pure PHP — see P6-schema.sql for the SQL
 *     reference of the identical operation. The SQL file remains
 *     runnable via `mysql -u root -p atte_ms < P6-schema.sql`.
 */
use Atte\DB\MsaDB;

require_once __DIR__ . '/../../config/config.php';

$tables = [
    'list__vendor',
    'list__vendor_supplier',
    'list__producer',
    'list__vendor_part',
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
    // Idempotency: skip if the column is already named isActive.
    $row = $db->prepare(
        "SELECT COLUMN_NAME
           FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME   = ?
            AND COLUMN_NAME  IN ('is_active', 'isActive')"
    );
    $row->execute([$table]);
    $col = $row->fetch(\PDO::FETCH_ASSOC);

    if (!$col) {
        echo "= {$table}: column not present (table missing?)\n";
        $skipped++;
        continue;
    }

    if ($col['COLUMN_NAME'] === 'isActive') {
        echo "= {$table}: already isActive (skipped)\n";
        $skipped++;
        continue;
    }

    // Run the rename. CHANGE COLUMN preserves type + nullability +
    // default (matches the existing definition exactly).
    try {
        $db->exec(
            "ALTER TABLE `{$table}`
               CHANGE COLUMN `is_active` `isActive` TINYINT(1) NOT NULL DEFAULT 1"
        );
        echo "✓ {$table}: is_active → isActive\n";
        $migrated++;
    } catch (\PDOException $e) {
        fwrite(STDERR, "✗ {$table}: " . $e->getMessage() . "\n");
        $failed++;
    }
}

echo "\nDone. Migrated: $migrated, Skipped: $skipped, Failed: $failed.\n";

// Verification block: print the final active column for each of the
// four tables. Should show four × isActive, zero × is_active.
echo "\nProcurement master tables — active column:\n";
$rows = $MsaDB->query(
    "SELECT TABLE_NAME, COLUMN_NAME
       FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME   IN ('list__vendor', 'list__vendor_supplier', 'list__producer', 'list__vendor_part')
        AND COLUMN_NAME  IN ('is_active', 'isActive')
      ORDER BY TABLE_NAME, COLUMN_NAME"
);
$byTable = [];
if ($rows) {
    foreach ($rows as $r) {
        $byTable[$r['TABLE_NAME']][] = $r['COLUMN_NAME'];
    }
}
$ok = true;
foreach ($tables as $t) {
    $cols = $byTable[$t] ?? [];
    if (in_array('isActive', $cols, true) && !in_array('is_active', $cols, true)) {
        echo "  - {$t}: isActive ✓\n";
    } else {
        $ok = false;
        echo "  - {$t}: " . (empty($cols) ? 'MISSING' : implode(', ', $cols)) . " ✗\n";
    }
}
echo $ok
    ? "\nAll four tables migrated. Cart should load now.\n"
    : "\nMigration incomplete — re-run or check the errors above.\n";
