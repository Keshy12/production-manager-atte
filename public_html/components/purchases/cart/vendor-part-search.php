<?php
/**
 * AJAX: global LIKE-search over active VendorParts (any vendor).
 *
 * GET params:
 *   q (string) — partial match on vendor_part_no / producer_part_no /
 *                part name / vendor name (min. 2 chars after trim)
 *
 * Response: [{id, vendor_id, parts_id, vendor_part_no, producer_part_no,
 *             vendor_jm_id, full_pack_quantity, vendor_name, part_name,
 *             producer_name, unit_name}, …]
 */
use Atte\DB\MsaDB;

if(!isset($_SESSION['isAdmin']) || $_SESSION['isAdmin'] !== true) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Brak uprawnień.']);
    exit;
}

$q = trim((string)($_GET['q'] ?? ''));

if (strlen($q) < 2) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([]);
    exit;
}

$like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';

$MsaDB = MsaDB::getInstance();
$sql = "
    SELECT vp.id,
           vp.vendor_id,
           vp.parts_id,
           vp.vendor_part_no,
           vp.producer_part_no,
           vp.vendor_jm_id,
           vp.full_pack_quantity,
           v.name  AS vendor_name,
           p.name  AS part_name,
           pr.name AS producer_name,
           u.name  AS unit_name
      FROM `list__vendor_part` vp
      JOIN `list__vendor` v ON v.id = vp.vendor_id
      JOIN `list__parts`  p ON p.id = vp.parts_id
      LEFT JOIN `list__producer` pr ON pr.id = vp.producer_id
      JOIN `part__unit`   u ON u.id = vp.vendor_jm_id
      WHERE vp.isActive = 1
        AND v.isActive  = 1
       AND (vp.vendor_part_no LIKE ?
        OR vp.producer_part_no LIKE ?
        OR p.name LIKE ?
        OR v.name LIKE ?)
     ORDER BY vp.vendor_part_no ASC
     LIMIT 25
";
$stmt = $MsaDB->db->prepare($sql);
$stmt->execute([$like, $like, $like, $like]);
$rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

$out = [];
foreach ($rows as $r) {
    $out[] = [
        'id'                 => (int)$r['id'],
        'vendor_id'          => (int)$r['vendor_id'],
        'parts_id'           => (int)$r['parts_id'],
        'vendor_part_no'     => $r['vendor_part_no'] ?? '',
        'producer_part_no'   => $r['producer_part_no'] ?? null,
        'vendor_jm_id'       => (int)$r['vendor_jm_id'],
        'full_pack_quantity' => (float)$r['full_pack_quantity'],
        'vendor_name'        => $r['vendor_name'] ?? '',
        'part_name'          => $r['part_name'] ?? '',
        'producer_name'      => $r['producer_name'] ?? null,
        'unit_name'          => $r['unit_name'] ?? '',
    ];
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode($out);
