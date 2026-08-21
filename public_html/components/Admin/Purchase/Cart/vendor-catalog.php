<?php
/**
 * AJAX: returns all active VendorParts for a single vendor
 * (vendor_id), with last-known unit_price + active RFQ/PO counts.
 *
 * Powers the vendor-first entry point in the Koszyk page —
 * admin picks a vendor, sees the vendor's full catalog, and
 * adds items directly to the shared cart.
 *
 * GET params:
 *   vendor_id (int, required)
 *
 * Response: [{id, vendor_id, vendor_part_no, producer_part_no,
 *            part_id, part_name, producer_name, unit_name,
 *            full_pack_quantity, last_unit_price,
 *            active_rfqs, active_pos}, …]
 */
use Atte\DB\MsaDB;

if(!isset($_SESSION['isAdmin']) || $_SESSION['isAdmin'] !== true) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Brak uprawnień.']);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

$vendorId = (int)($_GET['vendor_id'] ?? 0);
if ($vendorId <= 0) {
    echo json_encode([]);
    exit;
}

$MsaDB = MsaDB::getInstance();

$sql = "
SELECT vp.id,
       vp.vendor_id,
       vp.vendor_part_no,
       vp.producer_part_no,
       vp.parts_id,
       vp.vendor_jm_id,
       vp.full_pack_quantity,
       p.name  AS part_name,
       pr.name AS producer_name,
       u.name  AS unit_name,
       (
           SELECT poi.unit_price
             FROM purchase__order_item poi
             JOIN purchase__order po ON po.id = poi.po_id
            WHERE poi.vendor_part_id = vp.id
              AND po.state NOT IN ('cancelled')
              AND poi.unit_price > 0
            ORDER BY poi.id DESC
            LIMIT 1
       ) AS last_unit_price,
       (
           SELECT COUNT(*)
             FROM purchase__rfq_item pri
             JOIN purchase__rfq pr ON pr.id = pri.rfq_id
            WHERE pri.vendor_part_id = vp.id
              AND pr.state IN ('draft','sent','responded')
       ) AS active_rfqs,
       (
           SELECT COUNT(*)
             FROM purchase__order_item poi
             JOIN purchase__order po ON po.id = poi.po_id
            WHERE poi.vendor_part_id = vp.id
              AND po.state IN ('draft','sent','confirmed','partially_received')
       ) AS active_pos
  FROM `list__vendor_part` vp
  JOIN `list__parts`    p  ON vp.parts_id     = p.id
  JOIN `list__producer` pr ON vp.producer_id  = pr.id
  JOIN `part__unit`     u  ON vp.vendor_jm_id = u.id
 WHERE vp.vendor_id  = ?
   AND vp.is_active  = 1
 ORDER BY p.name ASC
";
$stmt = $MsaDB->db->prepare($sql);
$stmt->execute([$vendorId]);
$rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

$out = [];
foreach ($rows as $r) {
    $out[] = [
        'id'                  => (int)$r['id'],
        'vendor_id'           => (int)$r['vendor_id'],
        'vendor_part_no'      => $r['vendor_part_no'],
        'producer_part_no'    => $r['producer_part_no'],
        'parts_id'            => (int)$r['parts_id'],
        'part_name'           => $r['part_name'],
        'producer_name'       => $r['producer_name'],
        'vendor_jm_id'        => (int)$r['vendor_jm_id'],
        'unit_name'           => $r['unit_name'],
        'full_pack_quantity'  => (float)$r['full_pack_quantity'],
        'last_unit_price'     => $r['last_unit_price'] !== null ? (float)$r['last_unit_price'] : null,
        'active_rfqs'         => (int)$r['active_rfqs'],
        'active_pos'          => (int)$r['active_pos'],
    ];
}

echo json_encode($out);
