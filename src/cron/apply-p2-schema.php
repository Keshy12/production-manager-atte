<?php
/**
 * One-shot CLI: apply docs/procurement/sql/P2-schema.sql against the live
 * `atte_ms` database using the MsaDB connection (no interactive password
 * needed, unlike the `mysql -u root -p` invocation).
 *
 * Usage:
 *   php src/cron/apply-p2-schema.php
 *
 * Idempotent — every CREATE TABLE uses IF NOT EXISTS. Safe to re-run.
 */

use Atte\DB\MsaDB;

require_once __DIR__ . '/../../config/config.php';

$sqlFile = ROOT_DIRECTORY . '/docs/procurement/sql/P2-schema.sql';
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
    // Skip pure transaction-control lines.
    if (preg_match('/^(START|COMMIT|ROLLBACK)\s+TRANSACTION\s*$/i', $stmt)) {
        $skipped++;
        continue;
    }
    try {
        $MsaDB->db->exec($stmt);
        $applied++;
        echo "✓ applied (" . substr($stmt, 0, 80) . "...)\n";
    } catch (\PDOException $e) {
        // MySQL "already exists" error (code 42S01) → safe to ignore.
        if ($e->getCode() === '42S01' || str_contains($e->getMessage(), 'already exists')) {
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

// Quick sanity check.
$rows = $MsaDB->query(
    "SELECT TABLE_NAME FROM information_schema.tables
       WHERE table_schema = DATABASE()
         AND table_name IN ('purchase__rfq','purchase__rfq_item','purchase__number_counter')
       ORDER BY TABLE_NAME"
);
echo "\nTables now present:\n";
foreach ($rows as $r) {
    echo "  - {$r['TABLE_NAME']}\n";
}
