<?php
/**
 * One-shot CLI: mark every RFQ that has a PO pointing at it
 * (`purchase__order.converted_from_rfq_id`) as state='converted'.
 *
 * Why: the procurement history importer linked imported POs to their
 * RFQs but left the RFQ in the state derived from the RFQ sheet
 * (e.g. 'responded'). PurchaseActionHandler::convertRfqToPo() always sets
 * 'converted', so this backfills the same state for historical data.
 * The importer now does this itself; this script fixes already-imported data.
 *
 * Safe to re-run (only touches RFQs not yet 'converted'). Keeps updated_at.
 * Cancelled RFQs are converted too — a PO exists, so the RFQ was used.
 *
 * Usage:
 *   php src/cron/fix-rfq-converted-state.php --dry-run
 *   php src/cron/fix-rfq-converted-state.php
 */

use Atte\DB\MsaDB;

require_once __DIR__ . '/../../config/config.php';

$dryRun = in_array('--dry-run', $argv ?? [], true);
$MsaDB  = MsaDB::getInstance();
$pdo    = $MsaDB->db;

$rows = $pdo->query(
    "SELECT r.id, r.rfq_number, r.state,
            GROUP_CONCAT(o.po_number ORDER BY o.id SEPARATOR ', ') AS pos
       FROM purchase__rfq r
       JOIN purchase__order o ON o.converted_from_rfq_id = r.id
      WHERE r.state <> 'converted'
      GROUP BY r.id, r.rfq_number, r.state
      ORDER BY r.id"
)->fetchAll(\PDO::FETCH_ASSOC);

$byState = [];
foreach ($rows as $r) {
    $byState[$r['state']] = ($byState[$r['state']] ?? 0) + 1;
    echo sprintf("  RFQ #%d %s (%s) <- %s\n", $r['id'], $r['rfq_number'] ?? '-', $r['state'], $r['pos']);
}

echo "\nRFQs to mark converted: " . count($rows) . "\n";
foreach ($byState as $s => $n) echo "  from '$s': $n\n";

if ($dryRun) {
    echo "\nDRY RUN — nothing changed.\n";
    exit(0);
}
if (!$rows) {
    echo "Nothing to do.\n";
    exit(0);
}

$pdo->beginTransaction();
try {
    $affected = $pdo->exec(
        "UPDATE purchase__rfq r
            JOIN (SELECT DISTINCT converted_from_rfq_id AS rid
                    FROM purchase__order
                   WHERE converted_from_rfq_id IS NOT NULL) o ON o.rid = r.id
            SET r.state = 'converted', r.updated_at = r.updated_at
          WHERE r.state <> 'converted'"
    );
    $pdo->commit();
    echo "\nUpdated: $affected RFQ(s) -> converted.\n";
} catch (\Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR, "FAILED, rolled back: " . $e->getMessage() . "\n");
    exit(1);
}
