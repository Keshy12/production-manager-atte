<?php
/**
 * One-shot CLI: add the two `is_default_*` boolean columns to the
 * existing `email_draft` table so a draft can be marked as the global
 * default for the /admin/purchase/documents/send wizard's RFQ and PO
 * sides (vendor-level preference on `list__vendor` keeps priority;
 * this is strictly the lower-priority fallback layer).
 *
 * Strictly additive — does not touch the catalogue table itself, only
 * ALTERs two columns onto it. The table is created by the earlier
 * `migrate-email-drafts.php` runner, which is a hard precondition
 * (refused at the pre-flight check below — running this script on a
 * DB that hasn't been bootstrapped would otherwise blow up with a
 * confusing "Table ... doesn't exist" error on the very first ALTER).
 *
 * The SQL itself lives in:
 *   public_html/migrations/2026_10_06_email_draft_global_default.sql
 * This runner loads that file, splits it on `;`, and executes each
 * statement individually. The naive `;` split is required because
 * MySQL rejects multi-statement strings when the driver is in
 * non-multi-statement mode (PDO::prepare + execute's default).
 *
 * Idempotent: each ADD COLUMN is gated on an INFORMATION_SCHEMA lookup
 * inside the SQL file, so re-running this script is a no-op against
 * an already-migrated DB (the prepared IF(...) block resolves to a
 * harmless `SELECT '... already ...'` when the column is present).
 *
 * Usage:
 *   php src/cron/migrate-email-draft-global-default.php
 *
 * Exits 0 on full success, 1 on any failure (wrong DB, missing
 * `email_draft` table, missing SQL file, failed verification), 2
 * when some statement failed but verification still passed.
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

// ── Pre-flight: refuse to run if `email_draft` is missing ────────────
//
// This migration ALTERs the existing `email_draft` table (adds two
// columns). The catalogue table itself is created by the older
// `migrate-email-drafts.php` runner — running this script on a DB
// that hasn't been bootstrapped yet would fail at the very first
// ALTER TABLE with a confusing "Table 'atte_ms.email_draft' doesn't
// exist" error and leave the operator guessing. Bail out early with
// a clear pointer to the prerequisite runner instead.
$tblStmt = $db->prepare(
    "SELECT 1 FROM INFORMATION_SCHEMA.TABLES
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME   = 'email_draft'"
);
$tblStmt->execute();
if (!$tblStmt->fetchColumn()) {
    $tblStmt->closeCursor();
    fwrite(STDERR,
        "✗ Brak tabeli 'email_draft'. Ta migracja dodaje tylko kolumny do istniejącej tabeli — najpierw uruchom:\n"
      . "  php src/cron/migrate-email-drafts.php\n"
    );
    exit(1);
}
$tblStmt->closeCursor();

// ── Pre-flight: detect re-run vs fresh install ───────────────────────
//
// The SQL file's IF() blocks gracefully degrade to a SELECT '...
// already present' on re-run, but executeAndDrain() drops the result
// set before it can be printed (closeCursor on a row-returning SELECT
// just releases the cursor — the row text is never echoed). Surface
// the state explicitly here so a re-run is clearly distinguishable
// from a fresh install in the console output. This is the operator's
// only at-a-glance signal that nothing will change on this run.
$stateStmt = $db->prepare(
    "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME   = 'email_draft'
        AND COLUMN_NAME IN ('is_default_rfq', 'is_default_po')"
);
$stateStmt->execute();
$existingCount = (int)$stateStmt->fetchColumn();
$stateStmt->closeCursor();

if ($existingCount === 2) {
    echo "Migration state: already present — both is_default_* columns exist on email_draft; re-run, no schema change will be made.\n\n";
} else {
    echo "Migration state: applying ({$existingCount}/2 is_default_* columns currently present).\n\n";
}

// ── Load + execute the SQL file ───────────────────────────────────────
$sqlFile = __DIR__ . '/../../public_html/migrations/2026_10_06_email_draft_global_default.sql';
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
        // errors as success — the migration's intent (column added)
        // is met. Each message below maps to a specific gate pattern
        // in the SQL file:
        //   - "Duplicate column"   → ADD COLUMN gated on presence
        //   - "Unknown column"     → DROP COLUMN gated on presence (no
        //                            DROPs in this file; kept for parity
        //                            with the 09_28 runner)
        //   - "Duplicate key name" → ADD CONSTRAINT gated on presence
        //                            (no FKs in this file; kept for
        //                            parity)
        //   - "Table ... already exists" → CREATE TABLE IF NOT EXISTS
        //                            (no CREATE TABLE in this file;
        //                            kept for parity)
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
//   1. Both new `is_default_*` columns exist on `email_draft`.
//   2. How many rows currently carry each flag — informational only.
//      The flags DEFAULT 0 on ADD COLUMN, so a fresh migration always
//      reports 0/0; anything else means an operator manually promoted
//      a draft, or this migration ran before on this DB. We do NOT
//      exit non-zero on a non-zero count — it's a status, not an error.
$expectedCols = ['is_default_rfq', 'is_default_po'];
$missing = [];

try {
    $colStmt = $db->prepare(
        "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME   = 'email_draft'
            AND COLUMN_NAME  = ?"
    );
    foreach ($expectedCols as $col) {
        $colStmt->execute([$col]);
        if (!$colStmt->fetchColumn()) {
            $missing[] = "column:email_draft.{$col}";
        }
        // Drain the cursor before the next iteration. With unbuffered
        // PDO the second $colStmt->execute() inside the loop would
        // otherwise hit HY000 2014 because the prior SELECT 1 row
        // hasn't been closed.
        $colStmt->closeCursor();
    }

    // Informational row-count per flag. Kept inside the same try/catch
    // so a blown-up verification query cannot abort before reporting
    // — same pattern as the 09_28 runner.
    foreach (['is_default_rfq', 'is_default_po'] as $flag) {
        $cntStmt = $db->prepare(
            "SELECT COUNT(*) FROM `email_draft` WHERE `{$flag}` = 1"
        );
        $cntStmt->execute();
        $count = (int)$cntStmt->fetchColumn();
        $cntStmt->closeCursor();
        if ($count === 0) {
            echo "= {$flag}: 0 rows flagged (expected on a fresh run)\n";
        } else {
            echo "! {$flag}: {$count} row(s) flagged — a global default is already set (this migration has run before, or an operator manually flagged a draft)\n";
        }
    }
} catch (\PDOException $e) {
    fwrite(STDERR, "✗ verification query failed: {$e->getMessage()}\n");
    exit(1);
}

if ($missing === []) {
    echo "Verification: 2 is_default_* columns present on email_draft ✓\n";
    exit($fail === 0 ? 0 : 2);
}

fwrite(STDERR, "Verification: MISSING " . implode(', ', $missing) . " ✗\n");
exit(1);