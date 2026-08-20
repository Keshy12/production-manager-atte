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

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Nieprawidłowe ID zamówienia']);
    exit;
}

try {
    $MsaDB   = MsaDB::getInstance();
    $repo    = new PurchaseOrderRepository($MsaDB);
    $itemRepo = new PurchaseOrderItemRepository($MsaDB);
    $po      = $repo->getById($id);
    if ($po === null) {
        echo json_encode(['success' => false, 'error' => 'Nie znaleziono zamówienia']);
        exit;
    }
    if ($po->state !== 'draft') {
        echo json_encode(['success' => false, 'error' => 'Zamówienie nie jest w stanie szkicu']);
        exit;
    }
    if ($itemRepo->countByPo($id) === 0) {
        echo json_encode(['success' => false, 'error' => 'Nie można wysłać zamówienia bez pozycji']);
        exit;
    }

    $MsaDB->db->beginTransaction();
    try {
        $repo->setState($id, 'sent');
        $repo->setSentAt($id, date('Y-m-d H:i:s'));
        $MsaDB->db->commit();
        echo json_encode(['success' => true, 'po_number' => $po->poNumber, 'message' => 'Zamówienie wysłane']);
    } catch (\Throwable $e) {
        if ($MsaDB->db->inTransaction()) { $MsaDB->db->rollBack(); }
        throw $e;
    }
} catch (\LogicException $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (\Throwable $e) {
    error_log('order-send error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Wystąpił błąd podczas wysyłania zamówienia']);
}
exit;
