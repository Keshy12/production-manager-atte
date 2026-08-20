<?php
use Atte\DB\MsaDB;
use Atte\Utils\Purchase\Order\PurchaseOrderRepository;

header('Content-Type: application/json; charset=utf-8');

if(!isset($_SESSION['isAdmin']) || $_SESSION['isAdmin'] !== true) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Brak uprawnień']);
    exit();
}

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Nieprawidłowe ID zamówienia']);
    exit;
}

try {
    $MsaDB = MsaDB::getInstance();
    $repo  = new PurchaseOrderRepository($MsaDB);
    $po    = $repo->getById($id);
    if ($po === null) {
        echo json_encode(['success' => false, 'error' => 'Nie znaleziono zamówienia']);
    } else {
        echo json_encode([
            'success' => true,
            'order'   => [
                'id'                     => $po->id,
                'vendorId'               => $po->vendorId,
                'state'                  => $po->state,
                'poNumber'               => $po->poNumber,
                'vendorPoNumber'         => $po->vendorPoNumber,
                'convertedFromRfqId'     => $po->convertedFromRfqId,
                'expectedDeliveryDate'   => $po->expectedDeliveryDate,
                'sentAt'                 => $po->sentAt,
                'confirmedAt'            => $po->confirmedAt,
                'comment'                => $po->comment,
                'createdAt'              => $po->createdAt,
                'vendorName'             => $po->vendorName,
                'convertedFromRfqNumber' => $po->convertedFromRfqNumber,
            ],
        ]);
    }
} catch (\Throwable $e) {
    error_log('order-get error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Wystąpił błąd podczas ładowania zamówienia']);
}
exit;
