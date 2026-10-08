<?php
/**
 * One-shot CLI: zasilenie dwóch domyślnych szablonów (osobny dla RFQ
 * i osobny dla PO) oraz ustawienie wskaźników domyślnych w
 * `email_draft_default`.
 *
 * Ściśle data + pointer seeding — nie dotyka schematu `email_draft`
 * ani struktury tabeli wskaźników. Tabele `email_draft` i
 * `email_draft_default` muszą już istnieć (pierwsza jest tworzona
 * przez `migrate-email-drafts.php`, druga przez
 * `migrate-email-draft-default-pointer.php`). Uruchomienie tego
 * skryptu bez obu prerequisites wysypałoby się na INSERT i/lub ON
 * DUPLICATE KEY UPDATE z mylącymi "Table ... doesn't exist" — wyjście
 * wcześnie z jasnym wskaźnikiem na oba wcześniejsze runy.
 *
 * SQL znajduje się w:
 *   public_html/migrations/2026_10_08_seed_default_draft_templates.sql
 * Ten runner ładuje plik, dzieli po `;` i wykonuje każdą instrukcję
 * osobno. Naiwny split po `;` jest wymagany, bo MySQL/MariaDB
 * odrzuca multi-statement strings gdy driver jest w trybie
 * non-multi-statement (PDO::prepare + execute — domyślny).
 *
 * Idempotentne: kroki 1–2 są bramkowane licznikami / aktualną
 * wartością `applies_to`, kroki 3–4 klauzulą ON DUPLICATE KEY UPDATE.
 * Ponowne uruchomienie nie zmienia stanu — krok 2 w szczególności
 * nie nadpisuje `applies_to = 'rfq'`/'po' jeśli operator świadomie
 * zmienił wartość 'both', a kroki 3–4 nie cofają wskaźnika ustawionego
 * przez operatora na inny szablon (patrz `<=>` w migracji).
 *
 * Użycie:
 *   php src/cron/migrate-email-draft-default-templates.php
 *
 * Wyjścia 0 przy pełnym sukcesie, 1 przy każdym błędzie (zła DB,
 * brak wymaganej tabeli, brak pliku SQL, failed verification), 2
 * gdy jakaś instrukcja się nie powiodła, ale weryfikacja przeszła
 * pomyślnie.
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

// ── Pre-flight: refuse to run if either required table is missing ────
//
// Ta migracja jest data + pointer seeding: INSERT-uje wiersz w
// `email_draft` (krok 1) i UPSERT-uje wiersze w
// `email_draft_default` (kroki 3–4). Obie tabele muszą istnieć.
// `email_draft` jest tworzone przez `migrate-email-drafts.php`,
// `email_draft_default` przez `migrate-email-draft-default-pointer.php`.
// Uruchomienie tego skryptu bez obu prerequisites wysypałoby się
// na INSERT i/lub ON DUPLICATE KEY UPDATE z mylącym "Table ... doesn't
// exist". Wyjdź wcześnie z jasnym wskaźnikiem na oba wcześniejsze runy.
$requiredTables = ['email_draft', 'email_draft_default'];
$tblCheck = $db->prepare(
    "SELECT 1 FROM INFORMATION_SCHEMA.TABLES
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME   = ?"
);
$missingTables = [];
foreach ($requiredTables as $t) {
    $tblCheck->execute([$t]);
    if (!$tblCheck->fetchColumn()) {
        $missingTables[] = $t;
    }
    $tblCheck->closeCursor();
}
if ($missingTables !== []) {
    fwrite(STDERR,
        "✗ Brak wymaganych tabel: " . implode(', ', $missingTables) . ". Ta migracja zasila dane i wskaźniki — najpierw uruchom:\n"
      . "  • php src/cron/migrate-email-drafts.php                  (tworzy email_draft)\n"
      . "  • php src/cron/migrate-email-draft-default-pointer.php   (tworzy email_draft_default)\n"
    );
    exit(1);
}

// ── Pre-flight: detect re-run vs fresh install ───────────────────────
//
// Kroki 1–2 są bramkowane na konkretnych stanach (`COUNT(*) = 0`
// odpowiednio dla seeda i `applies_to = 'both'` dla poprawki),
// kroki 3–4 przez `ON DUPLICATE KEY UPDATE`. Wykrywamy end-state
// explicite (oba drafts + oba wskaźniki obecne) i raportujemy —
// to jedyny at-a-glance sygnał, że ten run jest no-opem.
$stateStmt = $db->prepare(
    "SELECT
        (SELECT COUNT(*) FROM `email_draft`        WHERE `name` = 'Domyślny — zamówienie' AND `applies_to` = 'po')  AS po_seed,
        (SELECT COUNT(*) FROM `email_draft`        WHERE `name` = 'Domyślny'               AND `applies_to` = 'rfq') AS rfq_seed,
        (SELECT COUNT(*) FROM `email_draft_default` WHERE `doc_type` = 'rfq') AS rfq_ptr,
        (SELECT COUNT(*) FROM `email_draft_default` WHERE `doc_type` = 'po')  AS po_ptr"
);
$stateStmt->execute();
$state = $stateStmt->fetch(\PDO::FETCH_ASSOC);
$stateStmt->closeCursor();

$allApplied = ((int)$state['po_seed']  === 1)
            && ((int)$state['rfq_seed'] === 1)
            && ((int)$state['rfq_ptr']  === 1)
            && ((int)$state['po_ptr']   === 1);

if ($allApplied) {
    echo "Migration state: already present — both default drafts and both pointer rows exist; re-run, no data change will be made.\n\n";
} else {
    echo sprintf(
        "Migration state: applying (po_seed=%d, rfq_seed=%d, rfq_ptr=%d, po_ptr=%d).\n\n",
        (int)$state['po_seed'],
        (int)$state['rfq_seed'],
        (int)$state['rfq_ptr'],
        (int)$state['po_ptr']
    );
}

// ── Load + execute the SQL file ───────────────────────────────────────
$sqlFile = __DIR__ . '/../../public_html/migrations/2026_10_08_seed_default_draft_templates.sql';
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
        // Lenient handling — matches the rest of the runner family.
        // Canonical "already in target state" / "doesn't exist"
        // errors are treated as success; the migration's intent
        // (seed row present, applies_to already set, pointer row
        // already pointing at the right draft) is met. Each
        // message maps to a specific gate pattern in the SQL file:
        //   - "Duplicate column"   → ADD COLUMN gated on presence
        //                            (no ADD COLUMNs in this file;
        //                            kept for parity with the family)
        //   - "Unknown column"     → DROP COLUMN gated on presence
        //                            (also catches benign typos in
        //                            in-migration sanity checks —
        //                            surfaced in the report below)
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
// Potwierdza end-state tej migracji (shape danych, nie schematu):
//   1. Obie wymagane tabele (`email_draft`, `email_draft_default`)
//      nadal istnieją.
//   2. Draft 'Domyślny — zamówienie' istnieje z `applies_to = 'po'`.
//   3. Draft 'Domyślny' istnieje z `applies_to = 'rfq'`.
//   4. Dokładnie jeden wiersz wskaźnika per doc_type.
//   5. Każdy wskaźnik wskazuje na istniejący draft (LEFT JOIN —
//      orphan wykrywany przez NULL po stronie email_draft).
//   6. Wypisanie rozwiązanych wskaźników z nazwami docelowych
//      szablonów dla operatora.
//
// Kroki 4–6 używają własnych zapytań (NIE polegają na in-migration
// sanity-checku w kroku 5 pliku SQL) — dzięki temu nawet jeśli
// tamten SELECT rzuci wyjątkiem (np. przez literówkę w aliasie),
// ten runner nadal potrafi zweryfikować końcowy stan bazy.
$missing = [];

try {
    // (1) Obie tabele istnieją
    foreach ($requiredTables as $t) {
        $tblCheck->execute([$t]);
        if (!$tblCheck->fetchColumn()) {
            $missing[] = "table:{$t}";
        }
        $tblCheck->closeCursor();
    }

    // (2) Seed draft dla PO
    $poDraftStmt = $db->prepare(
        "SELECT applies_to FROM `email_draft` WHERE `name` = 'Domyślny — zamówienie'"
    );
    $poDraftStmt->execute();
    $poApplies = $poDraftStmt->fetchColumn();
    $poDraftStmt->closeCursor();
    if ($poApplies !== 'po') {
        $missing[] = "draft:'Domyślny — zamówienie' (expected applies_to='po', got " . var_export($poApplies, true) . ")";
    }

    // (3) Seed draft dla RFQ
    $rfqDraftStmt = $db->prepare(
        "SELECT applies_to FROM `email_draft` WHERE `name` = 'Domyślny'"
    );
    $rfqDraftStmt->execute();
    $rfqApplies = $rfqDraftStmt->fetchColumn();
    $rfqDraftStmt->closeCursor();
    if ($rfqApplies !== 'rfq') {
        $missing[] = "draft:'Domyślny' (expected applies_to='rfq', got " . var_export($rfqApplies, true) . ")";
    }

    // (4) Dokładnie jeden wskaźnik per doc_type
    foreach (['rfq', 'po'] as $dt) {
        $cntStmt = $db->prepare(
            "SELECT COUNT(*) FROM `email_draft_default` WHERE `doc_type` = ?"
        );
        $cntStmt->execute([$dt]);
        $cnt = (int)$cntStmt->fetchColumn();
        $cntStmt->closeCursor();
        if ($cnt !== 1) {
            $missing[] = "pointer:email_draft_default.{$dt} (expected exactly 1 row, got {$cnt})";
        }
    }

    // (5)+(6) Rozwiązane wskaźniki z JOIN-em na email_draft —
    //     orphanom wykrywamy przez NULL w kolumnach email_draft.*
    $ptrsStmt = $db->prepare(
        "SELECT dd.doc_type, dd.draft_id, ed.name, ed.applies_to, ed.is_active
           FROM `email_draft_default` dd
           LEFT JOIN `email_draft` ed ON ed.id = dd.draft_id
          ORDER BY dd.doc_type"
    );
    $ptrsStmt->execute();
    $ptrsRows = $ptrsStmt->fetchAll(\PDO::FETCH_ASSOC);
    $ptrsStmt->closeCursor();

    if (count($ptrsRows) === 0) {
        $missing[] = "pointer:email_draft_default (no rows at all)";
    }

    echo "\nResolved pointer rows:\n";
    foreach ($ptrsRows as $r) {
        if ($r['name'] === null) {
            $missing[] = "pointer:{$r['doc_type']} → draft_id={$r['draft_id']} (ORPHAN — no matching email_draft row)";
            echo "    [{$r['doc_type']}] draft_id={$r['draft_id']} (ORPHAN — draft not found)\n";
        } else {
            echo "    [{$r['doc_type']}] → draft_id={$r['draft_id']} name=\"{$r['name']}\" applies_to={$r['applies_to']} is_active={$r['is_active']}\n";
        }
    }
} catch (\PDOException $e) {
    fwrite(STDERR, "✗ verification query failed: {$e->getMessage()}\n");
    exit(1);
}

if ($missing === []) {
    echo "\nVerification: 2 default drafts + 2 pointer rows + both tables present ✓\n";
    exit($fail === 0 ? 0 : 2);
}

fwrite(STDERR, "\nVerification: MISSING " . implode(', ', $missing) . " ✗\n");
exit(1);