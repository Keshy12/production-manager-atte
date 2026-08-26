<?php
use Atte\DB\MsaDB;
use Atte\Utils\Purchase\Master\VendorPartRepository;

header('Content-Type: application/json; charset=utf-8');

if(!isset($_SESSION['isAdmin']) || $_SESSION['isAdmin'] !== true) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Brak uprawnień.']);
    exit();
}

if($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Metoda nieobsługiwana']);
    exit();
}

$vendorId         = (int)($_POST['vendor_id'] ?? 0);
$producerId       = (int)($_POST['producer_id'] ?? 0);
$partsId          = (int)($_POST['parts_id'] ?? 0);
$vendorPartNo     = trim($_POST['vendor_part_no'] ?? '');
$producerPartNo   = trim($_POST['producer_part_no'] ?? '');
if ($producerPartNo === '') { $producerPartNo = null; }
$vendorJmId       = (int)($_POST['vendor_jm_id'] ?? 0);
$fullPackQuantity = str_replace(',', '.', (string)($_POST['full_pack_quantity'] ?? '1'));
$comment          = $_POST['comment'] ?? null;
if($comment !== null) { $comment = trim($comment); if($comment === '') { $comment = null; } }

try {
    $MsaDB = MsaDB::getInstance();
    $repo  = new VendorPartRepository($MsaDB);

    if($repo->existsForVendorAndPartNo($vendorId, $vendorPartNo)) {
        echo json_encode(['success' => false, 'error' => 'Vendor part no już istnieje dla tego dostawcy']);
        exit;
    }

    $id = $repo->create(
        $vendorId,
        $producerId,
        $partsId,
        $vendorPartNo,
        $vendorJmId,
        (float)$fullPackQuantity,
        $comment,
        $producerPartNo
    );
    echo json_encode(['success' => true, 'id' => $id, 'message' => 'Artykuł dodany pomyślnie']);
} catch (\InvalidArgumentException $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (\Throwable $e) {
    error_log('vp-add error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Wystąpił błąd podczas dodawania artykułu']);
}
exit;
