<?php
/**
 * One-shot CLI: create the global `email_draft` table + the two FK
 * columns on `list__vendor` (preferred_email_draft_rfq_id,
 * preferred_email_draft_po_id).
 *
 * Supersedes the previous `migrate-vendor-email-templates.php`
 * runner, which added inline `email_subject_template` /
 * `email_body_template` columns directly to `list__vendor`. The new
 * architecture separates the catalogue (global `email_draft`) from
 * the per-vendor preference (FK pointer).
 *
 * The SQL itself lives in:
 *   public_html/migrations/2026_09_28_create_email_drafts.sql
 * This runner loads that file, splits it on `;`, and executes each
 * statement individually. The naive `;` split is required because
 * MySQL rejects multi-statement strings when the driver is in
 * non-multi-statement mode (PDO::prepare + execute's default).
 *
 * Idempotent:
 *   - Each DROP / ADD COLUMN / ADD CONSTRAINT is gated on an
 *     INFORMATION_SCHEMA lookup inside the SQL file, so re-running is
 *     a no-op (the prepared `IF(...)` block resolves to a harmless
 *     `SELECT '... already ...'` when the column / FK is present).
 *   - Re-runs also drop the old `email_subject_template` /
 *     `email_body_template` columns (if any leftover from a previous
 *     failed migration), so the end state is the same regardless of
 *     how many times this script runs.
 *   - The seed INSERT is gated on a COUNT(*) check against
 *     `email_draft` so the example 'Domyślny' draft is added exactly
 *     once.
 *
 * Usage:
 *   php src/cron/migrate-email-drafts.php
 *
 * Exits 0 on full success, 1 on any failure (or wrong DB).
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

// ── Load + execute the SQL file ───────────────────────────────────────
$sqlFile = __DIR__ . '/../../public_html/migrations/2026_09_28_create_email_drafts.sql';
if (!is_readable($sqlFile)) {
    fwrite(STDERR, "✗ Migration file not readable: {$sqlFile}\n");
    exit(1);
}

$rawSql   = file_get_contents($sqlFile);
// Strip line comments starting with `--` so the naive `;` split does
// not produce spurious empty / comment-only statements that PDO would
// choke on.
$cleanSql = preg_replace('/^\s*--.*$/m', '', $rawSql);

$statements = array_filter(
    array_map('trim', explode(';', $cleanSql)),
    static fn($s) => $s !== ''
);

/**
 * Execute a single migration statement and release any active result
 * set. PDO::exec() — what this loop used to use — does NOT drain
 * result sets for SELECT-like payloads (e.g. `EXECUTE stmt` whose
 * prepared text is `SELECT '… already …' AS status`). On
 * unbuffered-PDO connections (the default for many builds) that
 * leaves the server-side cursor live, so the very next statement
 * (typically `DEALLOCATE PREPARE stmt` in the IF() pattern) blows
 * up with HY000 2014 and every subsequent statement in the file
 * cascades into the same failure. prepare()+execute()+closeCursor()
 * replaces that pattern with one that always returns the cursor to
 * the pool, regardless of whether the SQL returned rows.
 */
function executeAndDrain(\PDO $db, string $sql): void {
    $tmp = $db->prepare($sql);
    $tmp->execute();
    $tmp->closeCursor();
}

$ok = 0;
$fail = 0;
foreach ($statements as $stmt) {
    try {
        executeAndDrain($db, $stmt);
        $ok++;
    } catch (\PDOException $e) {
        // Treat the canonical "already present" / "doesn't exist"
        // errors as success — the migration's intent (column dropped,
        // column present, FK created, table present, seed inserted) is
        // met. Each message below maps to a specific gate in the SQL
        // file:
        //   - "Duplicate column"   → ADD COLUMN gated on presence
        //   - "Unknown column"     → DROP COLUMN gated on presence
        //   - "Duplicate key name" → ADD CONSTRAINT gated on presence
        //   - "Table ... already exists" → CREATE TABLE IF NOT EXISTS
        $msg = $e->getMessage();
        if (stripos($msg, 'Duplicate column')   !== false
            || stripos($msg, 'Unknown column')   !== false
            || stripos($msg, 'Duplicate key name') !== false
            || stripos($msg, 'already exists')   !== false) {
            echo "= already in target state (skipped)\n";
            $ok++;
            continue;
        }
        fwrite(STDERR, "✗ statement failed: {$msg}\n  SQL: " . substr($stmt, 0, 120) . "\n");
        $fail++;
    }
}

echo "\nDone. Statements OK: {$ok}, Failed: {$fail}.\n";

// ── Verification ──────────────────────────────────────────────────────
//
// Confirm the end-state we expect after a successful migration:
//   1. `email_draft` table exists.
//   2. Both new FK columns exist on `list__vendor`.
//   3. Both new FK constraints exist (catches the case where the
//      columns were added but the FK creation failed midway).
$expectedTables = ['email_draft'];
$expectedCols   = ['preferred_email_draft_rfq_id', 'preferred_email_draft_po_id'];
$expectedFks    = ['fk_vendor_rfq_draft', 'fk_vendor_po_draft'];

$missing = [];

// Wrapped in try/catch so a single verification query blowing up
// (typically HY000 2014 if a prior statement left an active result
// set) doesn't abort the whole runner with an uncaught exception.
// Without this guard, the script printed "Done. …" and then died
// mid-verification before reporting the verification result.
try {
    $tblStmt = $db->prepare(
        "SELECT 1 FROM INFORMATION_SCHEMA.TABLES
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME   = ?"
    );
    foreach ($expectedTables as $t) {
        $tblStmt->execute([$t]);
        if (!$tblStmt->fetchColumn()) {
            $missing[] = "table:{$t}";
        }
        // Drain the cursor before the next iteration. With unbuffered
        // PDO the second $tblStmt->execute() inside the loop would
        // otherwise hit HY000 2014 because the prior SELECT 1 row
        // hasn't been closed.
        $tblStmt->closeCursor();
    }

    $colStmt = $db->prepare(
        "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME   = 'list__vendor'
            AND COLUMN_NAME  = ?"
    );
    foreach ($expectedCols as $col) {
        $colStmt->execute([$col]);
        if (!$colStmt->fetchColumn()) {
            $missing[] = "column:list__vendor.{$col}";
        }
        $colStmt->closeCursor();
    }

    $fkStmt = $db->prepare(
        "SELECT 1 FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
          WHERE TABLE_SCHEMA   = DATABASE()
            AND TABLE_NAME     = 'list__vendor'
            AND CONSTRAINT_NAME = ?
            AND CONSTRAINT_TYPE = 'FOREIGN KEY'"
    );
    foreach ($expectedFks as $fk) {
        $fkStmt->execute([$fk]);
        if (!$fkStmt->fetchColumn()) {
            $missing[] = "fk:list__vendor.{$fk}";
        }
        $fkStmt->closeCursor();
    }
} catch (\PDOException $e) {
    fwrite(STDERR, "✗ verification query failed: {$e->getMessage()}\n");
    exit(1);
}

if ($missing === []) {
    echo "Verification: email_draft table + 2 FK columns + 2 FK constraints present ✓\n";
    exit($fail === 0 ? 0 : 2);
}

fwrite(STDERR, "Verification: MISSING " . implode(', ', $missing) . " ✗\n");
exit(1);