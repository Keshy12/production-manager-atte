<?php
use Atte\DB\MsaDB;

header('Content-Type: application/json; charset=utf-8');

if(!isset($_SESSION['isAdmin']) || $_SESSION['isAdmin'] !== true) {
    http_response_code(403);
    echo json_encode([]);
    exit();
}

$q = trim($_GET['q'] ?? '');
$q = '%' . str_replace(['%', '_'], ['\%', '\_'], $q) . '%';

try {
    $MsaDB = MsaDB::getInstance();
    $stmt = $MsaDB->db->prepare(
        "SELECT id, name, description FROM `list__parts`
         WHERE name LIKE ?
         ORDER BY name ASC
         LIMIT 20"
    );
    $stmt->execute([$q]);
    $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'id'    => (int)$r['id'],
            'name'  => $r['name'],
            'label' => $r['description'] ? ($r['name'] . ' — ' . $r['description']) : $r['name'],
        ];
    }
    echo json_encode($out);
} catch (\Throwable $e) {
    error_log('vp-search-parts error: ' . $e->getMessage());
    echo json_encode([]);
}
exit;
