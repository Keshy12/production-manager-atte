<?php
use Atte\DB\MsaDB;
use Atte\Utils\Purchase\Order\PurchaseOrderRepository;
use Atte\Utils\Purchase\Order\PurchaseOrderItemRepository;

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

$id   = (int)($_POST['id'] ?? 0);
$poId = (int)($_POST['po_id'] ?? 0);

if ($id <= 0 || $poId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Nieprawidłowe dane formularza']);
    exit;
}

try {
    $MsaDB    = MsaDB::getInstance();
    $poRepo   = new PurchaseOrderRepository($MsaDB);
    $itemRepo = new PurchaseOrderItemRepository($MsaDB);

    $po = $poRepo->getById($poId);
    if ($po === null || $po->state !== 'draft') {
        echo json_encode(['success' => false, 'error' => 'Zamówienie nie jest w stanie szkicu']);
        exit;
    }

    $existing = $itemRepo->getById($id);
    if ($existing === null || $existing->poId !== $poId) {
        echo json_encode(['success' => false, 'error' => 'Pozycja nie należy do tego zamówienia']);
        exit;
    }

    $itemRepo->delete($id);
    echo json_encode(['success' => true, 'message' => 'Pozycja usunięta']);
} catch (\Throwable $e) {
    error_log('order-item-delete error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Wystąpił błąd podczas usuwania pozycji']);
}
exit;
