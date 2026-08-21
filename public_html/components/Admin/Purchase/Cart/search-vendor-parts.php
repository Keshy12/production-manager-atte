<?php
/**
 * AJAX: LIKE-search over list__vendor_part filtered to the given vendor.
 *
 * GET params:
 *   vendor_id (int, required)
 *   q         (string, optional) — partial match on vendor_part_no
 *
 * Response: [{id, vendor_part_no, part_name, producer_name, unit_name, label}, …]
 */
use Atte\DB\MsaDB;

if(!isset($_SESSION['isAdmin']) || $_SESSION['isAdmin'] !== true) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Brak uprawnień.']);
    exit;
}

$vendorId = (int)($_GET['vendor_id'] ?? 0);
$q        = trim((string)($_GET['q'] ?? ''));

if ($vendorId <= 0) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([]);
    exit;
}

$like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';

$MsaDB = MsaDB::getInstance();
$sql = "
    SELECT vp.id,
           vp.vendor_part_no,
           vp.producer_part_no,
           vp.vendor_jm_id,
           vp.full_pack_quantity,
           p.name       AS part_name,
           pr.name      AS producer_name,
           u.name       AS unit_name
      FROM `list__vendor_part` vp
      JOIN `list__parts`  p  ON p.id  = vp.parts_id
      JOIN `list__producer` pr ON pr.id = vp.producer_id
      JOIN `part__unit`   u  ON u.id  = vp.vendor_jm_id
     WHERE vp.vendor_id = ?
       AND vp.is_active  = 1
       AND vp.vendor_part_no LIKE ?
     ORDER BY vp.vendor_part_no ASC
     LIMIT 20
";
$stmt = $MsaDB->db->prepare($sql);
$stmt->execute([$vendorId, $like]);
$rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

$out = [];
foreach ($rows as $r) {
    // Lead with the internal part name (our catalog). Vendor's reference
    // and producer's reference are intentionally NOT shown in the
    // picker label — they live in the cart items table instead, so the
    // admin sees them at a glance when reviewing the order.
    $partName = $r['part_name'] ?? '';
    $label    = $partName !== '' ? $partName : ($r['vendor_part_no'] ?? '');
    $out[] = [
        'id'               => (int)$r['id'],
        'vendor_part_no'   => $r['vendor_part_no'] ?? '',
        'producer_part_no' => $r['producer_part_no'] ?? null,
        'vendor_jm_id'     => (int)$r['vendor_jm_id'],
        'part_name'        => $partName,
        'producer_name'    => $r['producer_name'],
        'unit_name'        => $r['unit_name'],
        'label'            => $label,
    ];
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode($out);
