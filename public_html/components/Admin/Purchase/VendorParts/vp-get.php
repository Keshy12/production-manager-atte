<?php
use Atte\DB\MsaDB;
use Atte\Utils\Purchase\Master\VendorPartRepository;

header('Content-Type: application/json; charset=utf-8');

if(!isset($_SESSION['isAdmin']) || $_SESSION['isAdmin'] !== true) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Brak uprawnień']);
    exit();
}

$id = (int)($_GET['id'] ?? 0);
if($id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Nieprawidłowe ID artykułu']);
    exit;
}

try {
    $MsaDB = MsaDB::getInstance();
    $repo  = new VendorPartRepository($MsaDB);
    $vp    = $repo->getById($id);
    if($vp === null) {
        echo json_encode(['success' => false, 'error' => 'Nie znaleziono artykułu']);
    } else {
        echo json_encode([
            'success' => true,
            'vendorPart' => [
                'id'               => $vp->id,
                'vendorId'         => $vp->vendorId,
                'producerId'       => $vp->producerId,
                'partsId'          => $vp->partsId,
                'vendorPartNo'     => $vp->vendorPartNo,
                'vendorJmId'       => $vp->vendorJmId,
                'fullPackQuantity' => $vp->fullPackQuantity,
                'comment'          => $vp->comment,
                'isActive'         => $vp->isActive,
                'vendorName'       => $vp->vendorName,
                'producerName'     => $vp->producerName,
                'partName'         => $vp->partName,
                'unitName'         => $vp->unitName,
            ],
        ]);
    }
} catch (\Throwable $e) {
    error_log('vp-get error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Wystąpił błąd podczas ładowania danych']);
}
exit;
