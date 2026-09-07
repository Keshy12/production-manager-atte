<?php
/**
 * AJAX: LIKE-search over VendorParts scoped to ONE vendor (the RFQ's
 * vendor). Used by the "Dodaj pozycję" picker on
 * /admin/purchase/documents/edit?type=rfq so the admin can only pick
 * parts that belong to this RFQ's vendor.
 *
 * POST params:
 *   vendor_id   (int)    — required, > 0
 *   q           (string) — partial match on vendor_part_no /
 *                          producer_part_no / part name / description
 *                          (min 2 chars after trim)
 *   exclude_ids (array)  — optional, vendor_part_ids to exclude
 *                          (already-on-this-RFQ list)
 *
 * Response: [{id, vendor_id, parts_id, vendor_part_no, producer_part_no,
 *             part_name, description, vendor_jm_id, unit_name,
 *             pack_quantities, min_pack}, …]
 *
 * Only isActive=1 vendor parts + vendors are returned.
 */
use Atte\DB\MsaDB;

header('Content-Type: application/json; charset=utf-8');

if(!isset($_SESSION['isAdmin']) || $_SESSION['isAdmin'] !== true) {
    http_response_code(403);
    echo json_encode(['error' => 'Brak uprawnień.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Metoda nieobsługiwana']);
    exit;
}

$vendorId = (int)($_POST['vendor_id'] ?? 0);
$q        = trim((string)($_POST['q'] ?? ''));

if ($vendorId <= 0) {
    echo json_encode(['error' => 'Brak identyfikatora dostawcy.']);
    exit;
}
if (strlen($q) < 2) {
    echo json_encode([]);
    exit;
}

// Optional exclusion list — vendor parts already on the RFQ.
$excludeIds = [];
if (isset($_POST['exclude_ids']) && is_array($_POST['exclude_ids'])) {
    foreach ($_POST['exclude_ids'] as $raw) {
        $id = (int)$raw;
        if ($id > 0) { $excludeIds[] = $id; }
    }
}

$like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';

$MsaDB = MsaDB::getInstance();
$sql = "
    SELECT vp.id,
           vp.vendor_id,
           vp.parts_id,
           vp.vendor_part_no,
           vp.producer_part_no,
           lp.name        AS part_name,
           lp.description AS part_description,
           vp.vendor_jm_id,
           u.name         AS unit_name
      FROM `list__vendor_part` vp
      JOIN `list__vendor` v  ON v.id  = vp.vendor_id
      JOIN `list__parts`  lp ON lp.id = vp.parts_id
      JOIN `part__unit`   u  ON u.id  = vp.vendor_jm_id
     WHERE vp.isActive = 1
       AND v.isActive  = 1
       AND vp.vendor_id = ?
       AND (vp.vendor_part_no   LIKE ?
         OR vp.producer_part_no LIKE ?
         OR lp.name             LIKE ?
         OR lp.description      LIKE ?)
     ORDER BY vp.vendor_part_no ASC
     LIMIT 50
";
$stmt = $MsaDB->db->prepare($sql);
$stmt->execute([$vendorId, $like, $like, $like, $like]);
$rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

// Filter excluded ids in PHP (small list).
if (!empty($excludeIds)) {
    $rows = array_values(array_filter(
        $rows,
        fn($r) => !in_array((int)$r['id'], $excludeIds, true)
    ));
}

// Pack sizes keyed by vendor_part_id — one extra query.
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
        'id'               => (int)$r['id'],
        'vendor_id'        => (int)$r['vendor_id'],
        'parts_id'         => (int)$r['parts_id'],
        'vendor_part_no'   => $r['vendor_part_no'] ?? '',
        'producer_part_no' => $r['producer_part_no'] ?? null,
        'part_name'        => $r['part_name'] ?? '',
        'description'      => $r['part_description'] ?? '',
        'vendor_jm_id'     => (int)$r['vendor_jm_id'],
        'unit_name'        => $r['unit_name'] ?? '',
        'pack_quantities'  => $pack['packs'],
        'min_pack'         => $pack['minPack'],
    ];
}

echo json_encode($out);