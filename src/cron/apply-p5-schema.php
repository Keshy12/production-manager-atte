<?php
/**
 * One-shot CLI: apply docs/procurement/sql/P5-schema.sql against the live
 * `atte_ms` database using the MsaDB connection.
 *
 * Usage:
 *   php src/cron/apply-p5-schema.php
 *
 * Idempotent — the column-existence check skips the ALTER if the
 * column is already present.
 */

use Atte\DB\MsaDB;

require_once __DIR__ . '/../../config/config.php';

$sqlFile = ROOT_DIRECTORY . '/docs/procurement/sql/P5-schema.sql';
if (!is_file($sqlFile)) {
    fwrite(STDERR, "Schema file not found: $sqlFile\n");
    exit(1);
}

$raw = file_get_contents($sqlFile);

// Strip /* ... */ block comments and -- line comments so the simple
// semicolon-based split doesn't trip on commented-out statements.
$raw = preg_replace('#/\*.*?\*/#s', '', $raw);
$raw = preg_replace('/^\s*--.*$/m', '', $raw);

// Split into statements on `;` boundaries. Trim each; drop empties.
$statements = array_filter(
    array_map('trim', explode(';', $raw)),
    fn($s) => $s !== ''
);

$MsaDB = MsaDB::getInstance();
$applied = 0;
$skipped = 0;

foreach ($statements as $stmt) {
    // Skip pure SQL comments and SET user-var lines (we still run the
    // remaining statements; SET alone doesn't change schema).
    if (preg_match('/^(SET|SELECT)\b/i', $stmt)) {
        // Run them silently — they only print informational output.
        try {
            $MsaDB->db->query($stmt);
            $skipped++;
        } catch (\PDOException $e) {
            // ignore
        }
        continue;
    }
    try {
        $MsaDB->db->exec($stmt);
        $applied++;
        echo "✓ applied (" . substr($stmt, 0, 80) . "...)\n";
    } catch (\PDOException $e) {
        if ($e->getCode() === '42S21' || str_contains($e->getMessage(), 'Duplicate column') || str_contains($e->getMessage(), 'already exists')) {
            $skipped++;
            echo "= already exists (skipped)\n";
            continue;
        }
        fwrite(STDERR, "✗ FAILED: " . substr($stmt, 0, 80) . "...\n");
        fwrite(STDERR, "  " . $e->getMessage() . "\n");
        exit(2);
    }
}

echo "\nDone. Applied: $applied, Skipped: $skipped.\n";

// Verify the column exists
$col = $MsaDB->query(
    "SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE
       FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME   = 'list__vendor_part'
        AND COLUMN_NAME  = 'producer_part_no'"
);
echo "\nColumn 'producer_part_no' on list__vendor_part:\n";
if ($col && count($col) > 0) {
    $r = $col[0];
    echo "  - present: {$r['COLUMN_TYPE']} NULL allowed\n";
} else {
    echo "  - MISSING — schema migration didn't apply\n";
}
