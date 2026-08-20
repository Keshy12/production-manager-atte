<?php
use Atte\DB\MsaDB;

header('Content-Type: application/json; charset=utf-8');

if(!isset($_SESSION['isAdmin']) || $_SESSION['isAdmin'] !== true) {
    http_response_code(403);
    echo json_encode([]);
    exit();
}

$vendorId = (int)($_GET['vendor_id'] ?? 0);
if ($vendorId <= 0) {
    echo json_encode([]);
    exit;
}

$q = trim($_GET['q'] ?? '');
$q = '%' . str_replace(['%', '_'], ['\%', '\_'], $q) . '%';

try {
    $MsaDB = MsaDB::getInstance();
    $stmt = $MsaDB->db->prepare(
        "SELECT vp.id,
                vp.vendor_part_no   AS vendor_part_no,
                vp.vendor_jm_id     AS quantity_unit_id,
                lp.name             AS part_name,
                p.name              AS producer_name
         FROM `list__vendor_part` vp
         LEFT JOIN `list__parts` lp   ON vp.parts_id     = lp.id
         LEFT JOIN `list__producer` p ON vp.producer_id  = p.id
         WHERE vp.vendor_id = ?
           AND vp.is_active = 1
           AND vp.vendor_part_no LIKE ?
         ORDER BY vp.vendor_part_no ASC
         LIMIT 20"
    );
    $stmt->execute([$vendorId, $q]);
    $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
    $out = [];
    foreach ($rows as $r) {
        $label = ($r['vendor_part_no'] ?? '')
               . ' — ' . ($r['part_name'] ?? '')
               . ' (' . ($r['producer_name'] ?? '') . ')';
        $out[] = [
            'id'                => (int)$r['id'],
            'vendor_part_no'    => $r['vendor_part_no'],
            'quantity_unit_id'  => (int)$r['quantity_unit_id'],
            'part_name'         => $r['part_name'],
            'producer_name'     => $r['producer_name'],
            'label'             => $label,
        ];
    }
    echo json_encode($out);
} catch (\Throwable $e) {
    error_log('search-vendor-parts error: ' . $e->getMessage());
    echo json_encode([]);
}
exit;
