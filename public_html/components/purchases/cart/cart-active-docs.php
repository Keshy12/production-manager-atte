<?php
/**
 * AJAX: returns active (non-terminal) RFQs and POs for an array
 * of VendorPart ids, grouped by VendorPart. Used by the Koszyk
 * cart items table to render per-row warning badges so the
 * admin sees that the same VendorPart is already being ordered
 * before they queue another RFQ/PO.
 *
 * Accepts either:
 *   - JSON body: [12, 45, 78]
 *   - Form-encoded: vp_ids[]=12&vp_ids[]=45
 *
 * Response shape:
 *   {
 *     "<vendor_part_id>": [
 *         {doc_type:"rfq"|"po", number, state, quantity, unit_price, expected_date},
 *         …
 *     ],
 *     …
 *   }
 */
use Atte\DB\MsaDB;

if(!isset($_SESSION['isAdmin']) || $_SESSION['isAdmin'] !== true) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Brak uprawnień.']);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

// Accept JSON body or form-encoded payload
$vpIds = [];
$raw = file_get_contents('php://input');
if ($raw !== '' && $raw[0] === '[') {
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        $vpIds = $decoded;
    }
} else {
    parse_str($raw, $parsed);
    if (isset($parsed['vp_ids']) && is_array($parsed['vp_ids'])) {
        $vpIds = $parsed['vp_ids'];
    }
}

$vpIds = array_values(array_filter(array_map('intval', $vpIds), fn($id) => $id > 0));
if (empty($vpIds)) {
    echo json_encode(new \stdClass());  // empty object so the client gets `{}` not `[]`
    exit;
}

$placeholders = implode(',', array_fill(0, count($vpIds), '?'));
$MsaDB = MsaDB::getInstance();

// Active RFQs (draft / sent / responded — non-terminal)
$rfqSql = "
SELECT pri.vendor_part_id,
       pr.rfq_number  AS number,
       pr.state       AS state,
       pri.quantity   AS quantity,
       pri.unit_price AS unit_price,
       pri.picked_pack_size AS pickedPackSize,
       pr.expected_reply_date AS expected_date
  FROM `purchase__rfq_item` pri
  JOIN `purchase__rfq` pr ON pr.id = pri.rfq_id
 WHERE pri.vendor_part_id IN ($placeholders)
   AND pr.state IN ('draft','sent','responded')
 ORDER BY pr.id DESC
 ";
$rfqStmt = $MsaDB->db->prepare($rfqSql);
$rfqStmt->execute($vpIds);
$rfqRows = $rfqStmt->fetchAll(\PDO::FETCH_ASSOC);

// Active POs (draft / sent / confirmed / partially_received — non-terminal)
$poSql = "
SELECT poi.vendor_part_id,
       po.po_number  AS number,
       po.state      AS state,
       poi.quantity  AS quantity,
       poi.unit_price AS unit_price,
       poi.picked_pack_size AS pickedPackSize,
       po.expected_delivery_date AS expected_date
  FROM `purchase__order_item` poi
  JOIN `purchase__order` po ON po.id = poi.po_id
 WHERE poi.vendor_part_id IN ($placeholders)
   AND po.state IN ('draft','sent','confirmed','partially_received')
 ORDER BY po.id DESC
 ";
$poStmt = $MsaDB->db->prepare($poSql);
$poStmt->execute($vpIds);
$poRows = $poStmt->fetchAll(\PDO::FETCH_ASSOC);

$out = [];
foreach ($rfqRows as $r) {
    $vid = (int)$r['vendor_part_id'];
    $out[$vid][] = [
        'doc_type'        => 'rfq',
        'number'          => $r['number'],
        'state'           => $r['state'],
        'quantity'        => (float)$r['quantity'],
        'unit_price'      => $r['unit_price'] !== null ? (float)$r['unit_price'] : null,
        'picked_pack_size'=> $r['pickedPackSize'] === null ? null : (float)$r['pickedPackSize'],
        'expected_date'   => $r['expected_date'],
    ];
}
foreach ($poRows as $r) {
    $vid = (int)$r['vendor_part_id'];
    $out[$vid][] = [
        'doc_type'        => 'po',
        'number'          => $r['number'],
        'state'           => $r['state'],
        'quantity'        => (float)$r['quantity'],
        'unit_price'      => $r['unit_price'] !== null ? (float)$r['unit_price'] : null,
        'picked_pack_size'=> $r['pickedPackSize'] === null ? null : (float)$r['pickedPackSize'],
        'expected_date'   => $r['expected_date'],
    ];
}

echo json_encode($out);
