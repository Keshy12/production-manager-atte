<?php
/**
 * One-shot CLI: apply docs/procurement/sql/P6-schema.sql against the live
 * `atte_ms` database using the MsaDB connection.
 *
 * Usage:
 *   php src/cron/apply-p6-schema.php
 *
 * Unlike the P2–P5 runners (which only use `;`-terminated ALTER
 * statements), P6 uses a MySQL stored procedure wrapped with
 * `DELIMITER $$` / `DELIMITER ;` directives. The DELIMITER directive
 * is a mysql-client feature, not SQL — it's not understood by PDO.
 * The runner handles it by:
 *
 *   1. Stripping comment lines and the two `DELIMITER` lines,
 *   2. Extracting the procedure definition (from `DROP PROCEDURE`
 *      through `END $$`) as one logical CREATE-PROCEDURE statement,
 *      sent via PDO::exec(),
 *   3. Splitting the remainder on `;` and running the `CALL` +
 *      `DROP PROCEDURE` statements normally.
 *
 * Idempotent — the procedure itself short-circuits if a table is
 * already migrated (its `information_schema.COLUMNS` cursor skips
 * tables that don't have `is_active`).
 */
use Atte\DB\MsaDB;

require_once __DIR__ . '/../../config/config.php';

$sqlFile = ROOT_DIRECTORY . '/docs/procurement/sql/P6-schema.sql';
if (!is_file($sqlFile)) {
    fwrite(STDERR, "Schema file not found: $sqlFile\n");
    exit(1);
}

$raw = file_get_contents($sqlFile);

// Strip /* ... */ block comments and -- line comments so the simple
// semicolon-based split doesn't trip on commented-out statements.
$raw = preg_replace('#/\*.*?\*/#s', '', $raw);
$raw = preg_replace('/^\s*--.*$/m', '', $raw);

// Strip the DELIMITER directives — they're mysql-client-side, not SQL.
$raw = preg_replace('/^\s*DELIMITER\s+.*$/mi', '', $raw);

// Normalize the `$$` delimiter markers that wrap statements inside
// the procedure body. `$$` is a mysql-client feature; PDO sends
// plain SQL and chokes on it. Replace with `;` (the real PDO
// statement terminator). The trailing `END $$` becomes `END ;`,
// the DROP-procedure `$$` becomes `;` — both valid.
$raw = str_replace('$$', ';', $raw);

// The CREATE PROCEDURE block spans from `DROP PROCEDURE` through the
// matching `END ;` (post-replacement). We don't have a real SQL parser,
// so we slice on the `END ;` marker. Everything before the procedure
// becomes the "prefix" (currently empty for P6) and everything after
// becomes the "suffix" (the CALL + DROP PROCEDURE).
$marker = 'END ;';
$endPos = strpos($raw, $marker);
if ($endPos === false) {
    fwrite(STDERR, "Could not find `END ;` marker in P6-schema.sql — file format changed?\n");
    exit(1);
}
$procStmt = substr($raw, 0, $endPos + strlen($marker));
$suffix   = substr($raw, $endPos + strlen($marker));

$MsaDB = MsaDB::getInstance();
$applied = 0;
$skipped = 0;

// Run the procedure definition as a single multi-statement block.
// (DROP PROCEDURE + CREATE PROCEDURE is a single logical statement
// from MySQL's point of view; PDO::exec() handles it fine.)
try {
    $MsaDB->db->exec($procStmt);
    $applied++;
    echo "✓ procedure defined: rename_is_active_to_isActive\n";
} catch (\PDOException $e) {
    fwrite(STDERR, "✗ FAILED to define procedure: " . $e->getMessage() . "\n");
    exit(2);
}

// Split the suffix on `;` and run the remaining statements.
$suffixStmts = array_filter(
    array_map('trim', explode(';', $suffix)),
    fn($s) => $s !== ''
);
foreach ($suffixStmts as $stmt) {
    if (!preg_match('/\S/', $stmt)) continue;
    try {
        $MsaDB->db->exec($stmt);
        $applied++;
        echo "✓ applied (" . substr($stmt, 0, 80) . "...)\n";
    } catch (\PDOException $e) {
        // Procedural `CALL` and `DROP PROCEDURE` may error if the
        // objects are already gone (idempotent re-runs). Treat as
        // skipped rather than fatal.
        if (str_contains($e->getMessage(), 'does not exist')
            || str_contains($e->getMessage(), 'already exists')) {
            $skipped++;
            echo "= already done (skipped)\n";
            continue;
        }
        fwrite(STDERR, "✗ FAILED: " . substr($stmt, 0, 80) . "...\n");
        fwrite(STDERR, "  " . $e->getMessage() . "\n");
        exit(2);
    }
}

echo "\nDone. Applied: $applied, Skipped: $skipped.\n";

// Verify the four procurement tables now have isActive and not is_active.
$rows = $MsaDB->query(
    "SELECT TABLE_NAME, COLUMN_NAME
       FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME IN (
            'list__vendor', 'list__vendor_supplier',
            'list__producer', 'list__vendor_part'
        )
        AND COLUMN_NAME IN ('is_active', 'isActive')
      ORDER BY TABLE_NAME, COLUMN_NAME"
);
$byTable = [];
if ($rows) {
    foreach ($rows as $r) {
        $byTable[$r['TABLE_NAME']][] = $r['COLUMN_NAME'];
    }
}
echo "\nProcurement master tables — active column:\n";
$tables = ['list__vendor', 'list__vendor_supplier', 'list__producer', 'list__vendor_part'];
$ok = true;
foreach ($tables as $t) {
    $cols = $byTable[$t] ?? [];
    if (in_array('isActive', $cols, true) && !in_array('is_active', $cols, true)) {
        echo "  - $t: isActive ✓\n";
    } else {
        $ok = false;
        echo "  - $t: " . (empty($cols) ? 'MISSING' : implode(', ', $cols)) . " ✗\n";
    }
}
echo $ok
    ? "\nAll four tables migrated. Cart should load now.\n"
    : "\nMigration incomplete — run again or check the procedure above.\n";
