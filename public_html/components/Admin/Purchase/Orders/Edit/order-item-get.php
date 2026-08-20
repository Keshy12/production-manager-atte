<?php
use Atte\DB\MsaDB;
use Atte\Utils\Purchase\Order\PurchaseOrderItemRepository;

header('Content-Type: application/json; charset=utf-8');

if(!isset($_SESSION['isAdmin']) || $_SESSION['isAdmin'] !== true) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Brak uprawnień']);
    exit();
}

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Nieprawidłowe ID pozycji']);
    exit;
}

try {
    $MsaDB = MsaDB::getInstance();
    $repo  = new PurchaseOrderItemRepository($MsaDB);
    $item  = $repo->getById($id);
    if ($item === null) {
        echo json_encode(['success' => false, 'error' => 'Nie znaleziono pozycji']);
    } else {
        echo json_encode([
            'success' => true,
            'item' => [
                'id'              => $item->id,
                'poId'            => $item->poId,
                'vendorPartId'    => $item->vendorPartId,
                'quantity'        => $item->quantity,
                'quantityUnitId'  => $item->quantityUnitId,
                'unitPrice'       => $item->unitPrice,
                'currency'        => $item->currency,
                'comment'         => $item->comment,
                'quantityReceived'=> $item->quantityReceived,
                'vendorName'      => $item->vendorName,
                'producerName'    => $item->producerName,
                'partName'        => $item->partName,
                'unitName'        => $item->unitName,
                'vendorPartNo'    => $item->vendorPartNo,
            ],
        ]);
    }
} catch (\Throwable $e) {
    error_log('order-item-get error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Wystąpił błąd podczas ładowania pozycji']);
}
exit;
