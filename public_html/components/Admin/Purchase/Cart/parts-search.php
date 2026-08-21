<?php
/**
 * AJAX: LIKE-search over list__parts (our global part catalog).
 *
 * Used by the Koszyk inline "Dodaj nowy artykuł u dostawcy" modal to
 * pick an existing Part when creating a new VendorPart record.
 *
 * GET params:
 *   q (string, optional)
 *
 * Response: [{id, name, description, jm_id, jm_name, label}, …]
 */
use Atte\DB\MsaDB;

if(!isset($_SESSION['isAdmin']) || $_SESSION['isAdmin'] !== true) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Brak uprawnień.']);
    exit;
}

$q = trim((string)($_GET['q'] ?? ''));
$like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';

$MsaDB = MsaDB::getInstance();
$sql = "
    SELECT p.id, p.name, p.description, p.JM, u.name AS jm_name
      FROM `list__parts` p
      JOIN `part__unit` u ON u.id = p.JM
     WHERE p.isActive = 1
       AND (p.name LIKE ? OR p.description LIKE ?)
     ORDER BY p.name ASC
     LIMIT 20
";
$stmt = $MsaDB->db->prepare($sql);
$stmt->execute([$like, $like]);
$rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

$out = [];
foreach ($rows as $r) {
    $label = $r['name'];
    if (!empty($r['description']) && $r['description'] !== $r['name']) {
        $label .= '  —  ' . $r['description'];
    }
    $out[] = [
        'id'          => (int)$r['id'],
        'name'        => $r['name'],
        'description' => $r['description'],
        'jm_id'       => (int)$r['JM'],
        'jm_name'     => $r['jm_name'],
        'label'       => $label,
    ];
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode($out);
