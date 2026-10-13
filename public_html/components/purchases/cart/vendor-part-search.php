<?php
/**
 * AJAX: global LIKE-search over active VendorParts (any vendor).
 *
 * POST params:
 *   q (string) — partial match on vendor_part_no / producer_part_no /
 *                part name / vendor name (min. 2 chars after trim)
 *
 * Response: [{id, vendor_id, parts_id, vendor_part_no, producer_part_no,
 *             vendor_jm_id, full_pack_quantity, pack_quantities,
 *             vendor_name, part_name, producer_name, unit_name}, …]
 *
 * `full_pack_quantity` is the smallest pack size (back-compat with the
 * cart JS that displays "opak. N"). `pack_quantities` is the full list
 * ascending — UI surfaces come later.
 *
 * Note: POST-only because the .htaccess catch-all only routes POSTs
 * to existing component files (the index.php rewrite bypass). GETs
 * to .php files under /components/ are dropped by the POST rule.
 */
use Atte\DB\MsaDB;

header('Content-Type: application/json; charset=utf-8');

if(!isset($_SESSION['isAdmin']) || $_SESSION['isAdmin'] !== true) {
    echo json_encode(['error' => 'Brak uprawnień.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Metoda nieobsługiwana']);
    exit;
}

$q = trim((string)($_POST['q'] ?? ''));

if (strlen($q) < 2) {
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

// Pack sizes keyed by vendor_part_id — one extra query instead of a
// correlated subquery so the LIKE filter stays simple.
$vpIds = array_map(fn($r) => (int)$r['id'], $rows);
$packsByVpId = [];
if ($vpIds) {
    $placeholders = implode(',', array_fill(0, count($vpIds), '?'));
    $packStmt = $MsaDB->db->prepare(
        "SELECT vendor_part_id,
                MIN(full_pack_quantity) AS min_pack,
                GROUP_CONCAT(full_pack_quantity ORDER BY full_pack_quantity ASC) AS packs_csv
           FROM `list__vendor_part_pack`
          WHERE vendor_part_id IN ($placeholders)
          GROUP BY vendor_part_id"
    );
    $packStmt->execute($vpIds);
    foreach ($packStmt->fetchAll(\PDO::FETCH_ASSOC) as $pr) {
        $packsByVpId[(int)$pr['vendor_part_id']] = [
            'packs'   => $pr['packs_csv'] === null ? [] : array_map('floatval', explode(',', $pr['packs_csv'])),
            'minPack' => $pr['min_pack'] === null ? null : (float)$pr['min_pack'],
        ];
    }
}

$out = [];
foreach ($rows as $r) {
    $pack = $packsByVpId[(int)$r['id']] ?? ['packs' => [], 'minPack' => null];
    $out[] = [
        'id'                 => (int)$r['id'],
        'vendor_id'          => (int)$r['vendor_id'],
        'parts_id'           => (int)$r['parts_id'],
        'vendor_part_no'     => $r['vendor_part_no'] ?? '',
        'producer_part_no'   => $r['producer_part_no'] ?? null,
        'vendor_jm_id'       => (int)$r['vendor_jm_id'],
        'full_pack_quantity' => $pack['minPack'],
        'pack_quantities'    => $pack['packs'],
        'vendor_name'        => $r['vendor_name'] ?? '',
        'part_name'          => $r['part_name'] ?? '',
        'producer_name'      => $r['producer_name'] ?? null,
        'unit_name'          => $r['unit_name'] ?? '',
    ];
}

echo json_encode($out);
