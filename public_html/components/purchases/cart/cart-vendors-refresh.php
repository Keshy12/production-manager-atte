<?php
/**
 * AJAX: refresh vendor + part picker options (with cascade data).
 *
 * Used by the cart page after creating a new VendorPart on the fly
 * via "+ Artykuł" — keeps the cascade pickers (data-parts /
 * data-vendors) consistent with the DB without a full page reload.
 *
 * Same shape as the inline PHP queries that build the initial pickers
 * at page load:
 *   vendors[]: { id, name, parts_ids[] }
 *   parts[]:   { id, name, description, vendors_ids[] }
 *
 * GET only, no params. Admin-only (matches cart-view.php's gate).
 */
use Atte\DB\MsaDB;

header('Content-Type: application/json; charset=utf-8');

if(!isset($_SESSION['isAdmin']) || $_SESSION['isAdmin'] !== true) {
    http_response_code(403);
    echo json_encode(['error' => 'Brak uprawnień.']);
    exit();
}

if($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Metoda nieobsługiwana']);
    exit();
}

$MsaDB = MsaDB::getInstance();

$vendorRows = $MsaDB->query(
    "SELECT v.id, v.name, GROUP_CONCAT(vp.parts_id) AS parts_ids
       FROM `list__vendor` v
       LEFT JOIN `list__vendor_part` vp ON vp.vendor_id = v.id AND vp.isActive = 1
      WHERE v.isActive = 1
      GROUP BY v.id, v.name
      ORDER BY v.name ASC"
);

$partRows = $MsaDB->query(
    "SELECT p.id, p.name, p.description, GROUP_CONCAT(vp.vendor_id) AS vendors_ids
       FROM `list__parts` p
       LEFT JOIN `list__vendor_part` vp ON vp.parts_id = p.id AND vp.isActive = 1
      WHERE p.isActive = 1
      GROUP BY p.id, p.name, p.description
      ORDER BY p.name ASC"
);

$out = ['vendors' => [], 'parts' => []];
foreach ($vendorRows as $v) {
    $out['vendors'][] = [
        'id'       => (int)$v['id'],
        'name'     => $v['name'],
        'parts_ids'=> $v['parts_ids'] === null
            ? []
            : array_map('intval', explode(',', $v['parts_ids']))
    ];
}
foreach ($partRows as $p) {
    $out['parts'][] = [
        'id'          => (int)$p['id'],
        'name'        => $p['name'],
        'description' => $p['description'],
        'vendors_ids' => $p['vendors_ids'] === null
            ? []
            : array_map('intval', explode(',', $p['vendors_ids']))
    ];
}

echo json_encode($out);
