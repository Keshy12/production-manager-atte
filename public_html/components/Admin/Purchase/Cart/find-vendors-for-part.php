<?php
/**
 * AJAX: for a chosen list__parts.id, returns every active
 * list__vendor_part for that part joined with vendor / producer /
 * unit info + the last-known unit_price from purchase__order_item
 * history + the count of active RFQs and POs per VendorPart.
 *
 * Used by the Koszyk page to render the "Dostępni dostawcy" table
 * after the admin picks a part.
 *
 * GET params:
 *   parts_id (int, required)
 *
 * Response: [{id, vendor_id, vendor_name, vendor_part_no,
 *            producer_part_no, producer_name, vendor_jm_id,
 *            unit_name, full_pack_quantity, last_unit_price,
 *            active_rfqs, active_pos}, …]
 */
use Atte\DB\MsaDB;

if(!isset($_SESSION['isAdmin']) || $_SESSION['isAdmin'] !== true) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Brak uprawnień.']);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

$partsId = (int)($_GET['parts_id'] ?? 0);
if ($partsId <= 0) {
    echo json_encode([]);
    exit;
}

$MsaDB = MsaDB::getInstance();

// Single query: vendors + last price + active doc counts via sub-selects.
// Sub-selects are scoped per vendor_part.id so per-row counts are correct.
$sql = "
SELECT vp.id,
       vp.vendor_id,
       vp.vendor_part_no,
       vp.producer_part_no,
       vp.vendor_jm_id,
       vp.full_pack_quantity,
       v.name  AS vendor_name,
       pr.name AS producer_name,
       p.name  AS part_name,
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
  JOIN `list__vendor`   v  ON vp.vendor_id    = v.id
  JOIN `list__producer` pr ON vp.producer_id  = pr.id
  JOIN `list__parts`    p  ON vp.parts_id     = p.id
  JOIN `part__unit`     u  ON vp.vendor_jm_id = u.id
 WHERE vp.parts_id   = ?
   AND vp.is_active  = 1
 ORDER BY v.name ASC
";
$stmt = $MsaDB->db->prepare($sql);
$stmt->execute([$partsId]);
$rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

$out = [];
foreach ($rows as $r) {
    $out[] = [
        'id'                  => (int)$r['id'],
        'vendor_id'           => (int)$r['vendor_id'],
        'vendor_name'         => $r['vendor_name'],
        'vendor_part_no'      => $r['vendor_part_no'],
        'producer_part_no'    => $r['producer_part_no'],
        'producer_name'       => $r['producer_name'],
        'part_name'           => $r['part_name'],
        'vendor_jm_id'        => (int)$r['vendor_jm_id'],
        'unit_name'           => $r['unit_name'],
        'full_pack_quantity'  => (float)$r['full_pack_quantity'],
        'last_unit_price'     => $r['last_unit_price'] !== null ? (float)$r['last_unit_price'] : null,
        'active_rfqs'         => (int)$r['active_rfqs'],
        'active_pos'          => (int)$r['active_pos'],
    ];
}

echo json_encode($out);
