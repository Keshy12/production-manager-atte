<?php
/**
 * One-shot CLI: add `expected_delivery_date` to `purchase__order_item`.
 *
 * Why:
 *   Data dostawy była trzymana wyłącznie na nagłówku zamówienia
 *   (`purchase__order.expected_delivery_date` — „Planowana dostawa"
 *   dla całego zamówienia). Operator potwierdzający odpowiedź dostawcy
 *   (`/admin/purchase/documents/respond?id=N&type=po`) potrzebuje
 *   ustalić termin dostawy **per pozycja** — różne części tego samego
 *   zamówienia potrafią mieć różne terminy (dostawca magazynowy vs.
 *   producent na zlecenie).
 *
 *   Kolumna na pozycji jest nullable i domyślnie pusta:
 *     NULL = „brak własnego terminu dla tej pozycji" → UI pokazuje
 *     wtedy domyślną datę z nagłówka zamówienia (lead time dostawcy),
 *     ale baza nic nie zapamiętuje.
 *
 * What:
 *   ALTER TABLE `purchase__order_item`
 *     ADD COLUMN `expected_delivery_date` date DEFAULT NULL;
 *
 *   To zmiana METADATA-ONLY (dodanie kolumny). Nie rusza danych
 *   istniejących wierszy — `NULL` zostaje `NULL`.
 *
 *   Opcjonalny backfill (--backfill):
 *     kopiuje `purchase__order.expected_delivery_date` do wierszy
 *     pozycji, które nie mają własnej daty. Domyślnie WYŁĄCZONY —
 *     bez niego baza pozostaje „niepamiętająca" per pozycję, a UI
 *     samo pokazuje domyślną datę z nagłówka. Uruchomienie z
 *     `--backfill` jest jednorazowym, nieodwracalnym zapisem danych:
 *     kopiuje datę nagłówka do wszystkich pozycji tego zamówienia
 *     (także do już potwierdzonych / odebranych).
 *
 * - Idempotent: obecność kolumny jest sprawdzana przez
 *   information_schema.COLUMNS przed ALTERem. Na bazie, która już ma
 *   kolumnę, skrypt wypisuje „nothing to do" i kończy z kodem 0.
 * - Non-destructive: skrypt nigdy nie tworzy tabeli. Jeśli
 *   purchase__order_item nie istnieje, uruchom najpierw
 *   src/cron/migrate-to-procurement-schema.php (albo
 *   src/cron/migrate-production-to-purchase-schema.php).
 * - Idempotentny backfill: `WHERE i.expected_delivery_date IS NULL`
 *   — drugie uruchomienie nie nadpisuje już zapisanych dat.
 *
 * Pre-flight:
 *   Odmawia uruchomienia, chyba że podłączona baza nazywa się dokładnie
 *   `atte_ms` — ten sam strażnik co w pozostałych migracjach zakupowych.
 *
 * Usage:
 *   php src/cron/migrate-po-item-expected-delivery-date.php
 *   php src/cron/migrate-po-item-expected-delivery-date.php --backfill
 *
 * Exit codes:
 *   0 — kolumna istnieje (już była albo ALTER się powiódł)
 *   1 — zła baza, brak tabeli/kolumny albo nieudany ALTER
 */

use Atte\DB\MsaDB;

require_once __DIR__ . '/../../config/config.php';

$backfill = in_array('--backfill', $argv ?? [], true);

// ── Pre-flight: refuse to run against the wrong database ──────────────
$MsaDB  = MsaDB::getInstance();
$db     = $MsaDB->db;
$dbName = $db->query('SELECT DATABASE()')->fetchColumn();
if ($dbName !== 'atte_ms') {
    fwrite(STDERR, "✗ Connected to '{$dbName}', not 'atte_ms'. Aborting — refusing to migrate the wrong database.\n");
    exit(1);
}
echo "Connected to: {$dbName}\n";
echo 'Mode: ' . ($backfill ? 'ADD COLUMN + BACKFILL from PO header' : 'ADD COLUMN only (no data touched)') . "\n\n";

$table = 'purchase__order_item';
$col   = 'expected_delivery_date';

try {
    // ── Czy tabela w ogóle istnieje? ─────────────────────────────────
    $tableStmt = $db->prepare(
        "SELECT COUNT(*) FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?"
    );
    $tableStmt->execute([$table]);
    if ((int)$tableStmt->fetchColumn() === 0) {
        fwrite(STDERR,
            "✗ Table `{$table}` not found in '{$dbName}'.\n"
          . "  This migration only alters an existing table — it never creates it.\n"
          . "  Run src/cron/migrate-to-procurement-schema.php (or\n"
          . "  src/cron/migrate-production-to-purchase-schema.php) first.\n"
        );
        exit(1);
    }

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

    // ── Already there → nothing to do (idempotent) ───────────────────
    if ($row !== false) {
        echo "= {$table}.{$col}: already present ({$row['COLUMN_TYPE']}, nullable={$row['IS_NULLABLE']}) — no ALTER needed.\n";
        $changed = false;
    } else {
        // ── Add the column (metadata-only, no row data touched) ───────
        $db->exec("ALTER TABLE `{$table}` ADD COLUMN `{$col}` date DEFAULT NULL");
        echo "✓ {$table}.{$col}: added (date DEFAULT NULL)\n";
        $changed = true;

        // ── Verify ────────────────────────────────────────────────────
        $probe->execute([$table, $col]);
        $after = $probe->fetch(\PDO::FETCH_ASSOC);
        if ($after === false) {
            fwrite(STDERR, "✗ Verification failed — {$table}.{$col} is missing after ALTER.\n");
            exit(1);
        }
        if (strcasecmp((string)$after['COLUMN_TYPE'], 'date') !== 0
            || strcasecmp((string)$after['IS_NULLABLE'], 'YES') !== 0) {
            fwrite(STDERR, "✗ Verification failed — {$table}.{$col} is '{$after['COLUMN_TYPE']}' nullable={$after['IS_NULLABLE']}, expected 'date' nullable=YES.\n");
            exit(1);
        }
        echo "✓ Verified: {$table}.{$col} is `date` NULL.\n";
    }

    // ── Optional backfill: header date → lines without their own ─────
    $backfilled = 0;
    if ($backfill) {
        $db->beginTransaction();
        try {
            $stmt = $db->prepare(
                "UPDATE `purchase__order_item` i
                   JOIN `purchase__order` o ON o.id = i.po_id
                    SET i.expected_delivery_date = o.expected_delivery_date
                  WHERE i.expected_delivery_date IS NULL
                    AND o.expected_delivery_date IS NOT NULL"
            );
            $stmt->execute();
            $backfilled = $stmt->rowCount();
            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
        echo "✓ Backfill: {$backfilled} pozycji dostało datę z nagłówka zamówienia.\n";
    }

    // ── Summary ───────────────────────────────────────────────────────
    $nullCount = (int)$db->query(
        "SELECT COUNT(*) FROM `{$table}` WHERE `{$col}` IS NULL"
    )->fetchColumn();
    $setCount = (int)$db->query(
        "SELECT COUNT(*) FROM `{$table}` WHERE `{$col}` IS NOT NULL"
    )->fetchColumn();

    echo "\n";
    echo "Summary:\n";
    echo "  - {$table}.{$col} exists as `date DEFAULT NULL`."
        . ($changed ? ' (added by this run)' : ' (was already there)') . "\n";
    echo "  - Rows with a date:    {$setCount}\n";
    echo "  - Rows without a date: {$nullCount} (NULL = brak własnego terminu;\n";
    echo "    UI pokazuje wtedy domyślną datę z nagłówka zamówienia)\n";
    if (!$backfill) {
        echo "  - No backfill was run. To copy the PO header date onto every\n";
        echo "    line, re-run with --backfill (idempotent — only fills NULLs).\n";
    }
    exit(0);

} catch (\PDOException $e) {
    fwrite(STDERR, "✗ {$table}.{$col}: " . $e->getMessage() . "\n");
    exit(1);
}
