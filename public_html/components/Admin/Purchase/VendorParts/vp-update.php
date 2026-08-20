<?php
use Atte\DB\MsaDB;
use Atte\Utils\Purchase\Master\VendorPartRepository;

header('Content-Type: application/json; charset=utf-8');

if(!isset($_SESSION['isAdmin']) || $_SESSION['isAdmin'] !== true) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Brak uprawnień']);
    exit();
}

if($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Metoda nieobsługiwana']);
    exit();
}

$id               = (int)($_POST['id'] ?? 0);
$vendorId         = (int)($_POST['vendor_id'] ?? 0);
$producerId       = (int)($_POST['producer_id'] ?? 0);
$partsId          = (int)($_POST['parts_id'] ?? 0);
$vendorPartNo     = trim($_POST['vendor_part_no'] ?? '');
$vendorJmId       = (int)($_POST['vendor_jm_id'] ?? 0);
$fullPackQuantity = str_replace(',', '.', (string)($_POST['full_pack_quantity'] ?? '1'));
$comment          = $_POST['comment'] ?? null;
if($comment !== null) { $comment = trim($comment); if($comment === '') { $comment = null; } }

if($id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Nieprawidłowe ID artykułu']);
    exit;
}

try {
    $MsaDB = MsaDB::getInstance();
    $repo  = new VendorPartRepository($MsaDB);

    // Uniqueness check: vendor_id + vendor_part_no must be unique (excluding self).
    if($repo->existsForVendorAndPartNo($vendorId, $vendorPartNo)) {
        $existing = $MsaDB->db->prepare('SELECT id FROM `list__vendor_part` WHERE vendor_id = ? AND vendor_part_no = ? LIMIT 1');
        $existing->execute([$vendorId, $vendorPartNo]);
        $existingRow = $existing->fetch(\PDO::FETCH_ASSOC);
        if($existingRow && (int)$existingRow['id'] !== $id) {
            echo json_encode(['success' => false, 'error' => 'Vendor part no już istnieje dla tego dostawcy']);
            exit;
        }
    }

    $repo->update($id, $vendorId, $producerId, $partsId, $vendorPartNo, $vendorJmId, (float)$fullPackQuantity, $comment);
    echo json_encode(['success' => true, 'message' => 'Artykuł zaktualizowany pomyślnie']);
} catch (\InvalidArgumentException $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (\Throwable $e) {
    error_log('vp-update error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Wystąpił błąd podczas aktualizacji artykułu']);
}
exit;
