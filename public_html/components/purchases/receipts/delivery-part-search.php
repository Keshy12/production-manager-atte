<?php
/**
 * AJAX (POST): search active vendor parts of one vendor (for adding an
 * invoice line that is not on an open PO, or picking one that is).
 *
 * POST: vendor_id (int, required), q (string, optional)
 *   q matches (LIKE) vendor_part_no, producer_part_no or part name.
 *   Empty q → only parts that are on an open PO.
 * Response:
 *   {success:true, results:[{vendor_part_id, vendor_part_no,
 *     producer_part_no, part_name, producer_name, unit, on_open_po,
 *     open_remaining, suggested_price, suggested_currency}]}
 * Parts on open POs first, then by vendor_part_no; limit 30.
 * suggested_price/currency = price of the first matcher-ordered open PO
 * item for that part (null when none).
 */
require_once __DIR__ . '/delivery-common.php';

delivery_guard('POST');

$vendorId = delivery_int($_POST['vendor_id'] ?? 0);
$q = trim((string)($_POST['q'] ?? ''));
if ($vendorId <= 0) {
    delivery_json(['success' => false, 'error' => 'Nieprawidłowy dostawca.']);
}

delivery_run(function ($MsaDB) use ($vendorId, $q) {
    $db = $MsaDB->db;

    // Open-PO info per vendor part (matcher order → first = suggestion).
    $open = [];
    foreach (delivery_open_items($db, $vendorId) as $it) {
        $vp = $it['vendor_part_id'];
        if (!isset($open[$vp])) {
            $open[$vp] = [
                'remaining' => 0.0,
                'price'     => $it['unit_price'],
                'currency'  => $it['currency'],
            ];
        }
        $open[$vp]['remaining'] += $it['remaining'];
    }

    $params = [$vendorId];
    $where = "vp.vendor_id = ? AND vp.isActive = 1";
    if ($q !== '') {
        $like = '%' . addcslashes($q, '\\%_') . '%';
        $where .= " AND (vp.vendor_part_no LIKE ? OR vp.producer_part_no LIKE ? OR lp.name LIKE ?)";
        array_push($params, $like, $like, $like);
    } else {
        if (empty($open)) {
            return ['success' => true, 'results' => []];
        }
        $ids = array_keys($open);
        $where .= " AND vp.id IN (" . implode(',', array_fill(0, count($ids), '?')) . ")";
        $params = array_merge($params, $ids);
    }

    $stmt = $db->prepare(
        "SELECT vp.id AS vendor_part_id, vp.vendor_part_no, vp.producer_part_no,
                lp.name AS part_name, pr.name AS producer_name, u.name AS unit
           FROM `list__vendor_part` vp
           LEFT JOIN `list__parts` lp    ON vp.parts_id = lp.id
           LEFT JOIN `list__producer` pr ON vp.producer_id = pr.id
           LEFT JOIN `part__unit` u      ON vp.vendor_jm_id = u.id
          WHERE {$where}
          ORDER BY vp.vendor_part_no ASC"
    );
    $stmt->execute($params);

    $onOpen = [];
    $rest = [];
    foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
        $id = (int)$r['vendor_part_id'];
        $isOpen = isset($open[$id]);
        $row = [
            'vendor_part_id'     => $id,
            'vendor_part_no'     => $r['vendor_part_no'],
            'producer_part_no'   => $r['producer_part_no'],
            'part_name'          => $r['part_name'],
            'producer_name'      => $r['producer_name'],
            'unit'               => $r['unit'],
            'on_open_po'         => $isOpen,
            'open_remaining'     => $isOpen ? round($open[$id]['remaining'], 10) : 0.0,
            'suggested_price'    => $isOpen ? $open[$id]['price'] : null,
            'suggested_currency' => $isOpen ? $open[$id]['currency'] : null,
        ];
        if ($isOpen) $onOpen[] = $row; else $rest[] = $row;
    }
    $results = array_slice(array_merge($onOpen, $rest), 0, 30);
    return ['success' => true, 'results' => $results];
});
