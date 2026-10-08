<?php
/**
 * One-shot CLI: zastąpienie dwóch boolean kolumn `is_default_*` na
 * `email_draft` pojedynczą tabelą wskaźników `email_draft_default`,
 * której PRIMARY KEY na `doc_type` czyni "co najwyżej jeden domyślny
 * szablon na typ dokumentu" inwariantem strukturalnym (a nie
 * konwencją warstwy aplikacji).
 *
 * Ściśle addytywne wobec kontraktu API — jedynie zmienia kształt
 * pamięci masowej dla tej samej informacji. Tabela `email_draft`
 * musi już istnieć (jest tworzona przez `migrate-email-drafts.php`),
 * a kolumny `is_default_*` (dodane przez `migrate-email-draft-global-default.php`)
 * są opcjonalne — kroki migracji danych i DROP są bramkowane przez
 * sprawdzenie obecności, więc świeża instalacja tego pliku na DB
 * bez 10_06 nie wysypuje się i nie pozostawia w złym stanie.
 *
 * SQL znajduje się w:
 *   public_html/migrations/2026_10_07_email_draft_default_pointer.sql
 * Ten runner ładuje plik, dzieli po `;` i wykonuje każdą instrukcję
 * osobno. Naiwny split po `;` jest wymagany, bo MySQL odrzuca
 * multi-statement strings gdy driver jest w trybie non-multi-statement
 * (PDO::prepare + execute — domyślny).
 *
 * Idempotentne: każdy DDL jest bramkowany przez lookup w
 * INFORMATION_SCHEMA wewnątrz pliku SQL, więc re-run jest no-op
 * wobec już zmigrowanej DB (przygotowany blok IF(...) rozwiązuje się
 * do harmless `SELECT '... already ...'` gdy obiekt już istnieje).
 *
 * Użycie:
 *   php src/cron/migrate-email-draft-default-pointer.php
 *
 * Wyjścia 0 przy pełnym sukcesie, 1 przy każdym błędzie (zła DB,
 * brak tabeli `email_draft`, brak pliku SQL, failed verification),
 * 2 gdy jakaś instrukcja się nie powiodła, ale weryfikacja
 * przeszła pomyślnie.
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
// Ta migracja tworzy `email_draft_default` z FK do `email_draft.id`,
// oraz (potencjalnie) DRUPUJE kolumny z `email_draft`. Katalog
// `email_draft` musi istnieć — jest tworzony przez starszy runner
// `migrate-email-drafts.php`. Uruchomienie tego skryptu na DB, która
// nie została zbootstrappowana, wysypałoby się na CREATE TABLE z
// mylącym "Table 'atte_ms.email_draft' doesn't exist" na kroku FK.
// Wyjdź wcześnie z jasnym wskaźnikiem na wymagany prerequisite.
$tblStmt = $db->prepare(
    "SELECT 1 FROM INFORMATION_SCHEMA.TABLES
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME   = 'email_draft'"
);
$tblStmt->execute();
if (!$tblStmt->fetchColumn()) {
    $tblStmt->closeCursor();
    fwrite(STDERR,
        "✗ Brak tabeli 'email_draft'. Ta migracja tworzy tabelę wskaźników z FK do email_draft — najpierw uruchom:\n"
      . "  php src/cron/migrate-email-drafts.php\n"
    );
    exit(1);
}
$tblStmt->closeCursor();

// ── Pre-flight: detect re-run vs fresh install ───────────────────────
//
// SQL-owy blok CREATE TABLE IF NOT EXISTS na re-run zwraca cicho
// (nie ma komunikatu "already present" — IF NOT EXISTS tłumi całe
// wykonanie). Wykrywamy obecność tabeli wskaźników explicite i
// raportujemy operatorowi — to jedyny at-a-glance sygnał, że ten
// run jest no-opem.
$stateStmt = $db->prepare(
    "SELECT 1 FROM INFORMATION_SCHEMA.TABLES
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME   = 'email_draft_default'"
);
$stateStmt->execute();
$tblExists = (bool)$stateStmt->fetchColumn();
$stateStmt->closeCursor();

if ($tblExists) {
    echo "Migration state: already present — email_draft_default table exists; re-run, no schema change will be made.\n\n";
} else {
    echo "Migration state: applying (email_draft_default table absent).\n\n";
}

// ── Load + execute the SQL file ───────────────────────────────────────
$sqlFile = __DIR__ . '/../../public_html/migrations/2026_10_07_email_draft_default_pointer.sql';
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
        // errors as success — the migration's intent (table created,
        // FK added, data migrated, column dropped) is met. Each
        // message below maps to a specific gate pattern in the SQL
        // file:
        //   - "Duplicate column"   → ADD COLUMN gated on presence
        //                            (no ADD COLUMNs in this file;
        //                            kept for parity with the 10_06
        //                            runner)
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
//   1. `email_draft_default` table exists.
//   2. FK `fk_ed_default_draft` exists.
//   3. The two legacy `is_default_*` columns are GONE from `email_draft`.
//   4. Print current contents of the pointer table — informational;
//      expected 0 rows on a fresh install, anything else means either
//      an operator manually promoted a draft, the data-migration step
//      captured a previously-flagged legacy row, or this migration ran
//      twice. Not an error.
$expectedTables          = ['email_draft_default'];
$expectedFks             = ['fk_ed_default_draft'];
$expectedAbsentColumns   = ['is_default_rfq', 'is_default_po'];
$missing = [];

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
        $tblStmt->closeCursor();
    }

    $fkStmt = $db->prepare(
        "SELECT 1 FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
          WHERE TABLE_SCHEMA    = DATABASE()
            AND TABLE_NAME      = 'email_draft_default'
            AND CONSTRAINT_NAME  = ?
            AND CONSTRAINT_TYPE = 'FOREIGN KEY'"
    );
    foreach ($expectedFks as $fk) {
        $fkStmt->execute([$fk]);
        if (!$fkStmt->fetchColumn()) {
            $missing[] = "fk:email_draft_default.{$fk}";
        }
        $fkStmt->closeCursor();
    }

    $colStmt = $db->prepare(
        "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME   = 'email_draft'
            AND COLUMN_NAME  = ?"
    );
    foreach ($expectedAbsentColumns as $col) {
        $colStmt->execute([$col]);
        if ($colStmt->fetchColumn()) {
            // Found the column — that's wrong, it should be gone.
            $missing[] = "column:email_draft.{$col} (still present, should have been dropped)";
        }
        $colStmt->closeCursor();
    }

    // Informational: count + contents of the pointer table. Expected 0
    // rows on a fresh install with no legacy-flagged drafts; any other
    // value is informational only — not an error.
    $cntStmt = $db->prepare(
        "SELECT COUNT(*) FROM `email_draft_default`"
    );
    $cntStmt->execute();
    $ptrCount = (int)$cntStmt->fetchColumn();
    $cntStmt->closeCursor();

    if ($ptrCount === 0) {
        echo "= email_draft_default: 0 rows (expected on a fresh install)\n";
    } else {
        echo "! email_draft_default: {$ptrCount} row(s) — see below\n";
        $rowsStmt = $db->prepare(
            "SELECT doc_type, draft_id, updated_at
               FROM `email_draft_default`
              ORDER BY doc_type"
        );
        $rowsStmt->execute();
        foreach ($rowsStmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            echo "    [{$r['doc_type']}] draft_id={$r['draft_id']} updated_at={$r['updated_at']}\n";
        }
        $rowsStmt->closeCursor();
    }
} catch (\PDOException $e) {
    fwrite(STDERR, "✗ verification query failed: {$e->getMessage()}\n");
    exit(1);
}

if ($missing === []) {
    echo "Verification: email_draft_default table + FK + absent legacy columns ✓\n";
    exit($fail === 0 ? 0 : 2);
}

fwrite(STDERR, "Verification: MISSING " . implode(', ', $missing) . " ✗\n");
exit(1);