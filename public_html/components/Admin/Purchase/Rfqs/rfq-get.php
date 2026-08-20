<?php
use Atte\DB\MsaDB;
use Atte\Utils\Purchase\Order\RFQRepository;

header('Content-Type: application/json; charset=utf-8');

if(!isset($_SESSION['isAdmin']) || $_SESSION['isAdmin'] !== true) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Brak uprawnień']);
    exit();
}

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Nieprawidłowe ID zapytania']);
    exit;
}

try {
    $MsaDB = MsaDB::getInstance();
    $repo  = new RFQRepository($MsaDB);
    $rfq   = $repo->getById($id);
    if ($rfq === null) {
        echo json_encode(['success' => false, 'error' => 'Nie znaleziono zapytania']);
    } else {
        echo json_encode([
            'success' => true,
            'rfq' => [
                'id'                => $rfq->id,
                'vendorId'          => $rfq->vendorId,
                'state'             => $rfq->state,
                'rfqNumber'         => $rfq->rfqNumber,
                'expectedReplyDate' => $rfq->expectedReplyDate,
                'sentAt'            => $rfq->sentAt,
                'comment'           => $rfq->comment,
                'createdAt'         => $rfq->createdAt,
                'vendorName'        => $rfq->vendorName,
            ],
        ]);
    }
} catch (\Throwable $e) {
    error_log('rfq-get error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Wystąpił błąd podczas ładowania zapytania']);
}
exit;
