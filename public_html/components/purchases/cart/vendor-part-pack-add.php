<?php
/**
 * AJAX: add a new pack size to a single VendorPart's list__vendor_part_pack
 * row set. Called from the Koszyk picker's inline stepper + add icon —
 * saves immediately and re-renders the stepper with the updated list.
 *
 * POST:
 *   vp_id              (int)    — list__vendor_part.id
 *   full_pack_quantity (number) — positive decimal, may use ',' as the
 *                                 decimal separator (Polish locale).
 *                                 Deduplicates against existing pack
 *                                 rows for the same vendor_part.
 *
 * Response:
 *   {success: true, pack_quantities: [100.0, 1000.0, 5000.0]}
 *     | {error: "..."}
 */
use Atte\DB\MsaDB;
use Atte\Utils\Purchase\PackListParser;

if(!isset($_SESSION['isAdmin']) || $_SESSION['isAdmin'] !== true) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Brak uprawnień.']);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

$vpId = (int)($_POST['vp_id'] ?? 0);
if ($vpId <= 0) {
    echo json_encode(['error' => 'Brak identyfikatora artykułu.']);
    exit;
}

$raw = (string)($_POST['full_pack_quantity'] ?? '');
// Reuse the importer's pack-list parser — it strips whitespace, accepts
// ',' as decimal point, drops non-positive tokens. The endpoint accepts
// a single tier here, but parsing a one-element list works identically
// and keeps the validation rules in one place.
$parsed = PackListParser::parse($raw);
if (count($parsed) === 0) {
    echo json_encode(['error' => 'Podaj wielkość opakowania > 0.']);
    exit;
}
if (count($parsed) > 1) {
    echo json_encode(['error' => 'Tutaj dodajesz jedną wielkość — użyj `/` tylko w trybie dodawania artykułu.']);
    exit;
}
$qty = (float)$parsed[0];

$MsaDB = MsaDB::getInstance();

// Idempotent INSERT. UNIQUE (vendor_part_id, full_pack_quantity) on
// list__vendor_part_pack collapses a duplicate into a no-op via
// ON DUPLICATE KEY UPDATE id = id — we still want the response to
// contain the merged list, so we don't return early on duplicate.
$MsaDB->db->prepare(
    "INSERT INTO `list__vendor_part_pack` (`vendor_part_id`, `full_pack_quantity`)
     VALUES (?, ?)
     ON DUPLICATE KEY UPDATE `full_pack_quantity` = VALUES(`full_pack_quantity`)"
)->execute([$vpId, $qty]);

// Return the merged + sorted list so the client can refresh its
// in-memory pack_quantities array in one roundtrip.
$stmt = $MsaDB->db->prepare(
    "SELECT full_pack_quantity
       FROM `list__vendor_part_pack`
      WHERE vendor_part_id = ?
      ORDER BY full_pack_quantity ASC"
);
$stmt->execute([$vpId]);
$rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
$packList = array_map(function ($r) { return (float)$r['full_pack_quantity']; }, $rows);

echo json_encode(['success' => true, 'pack_quantities' => $packList]);
