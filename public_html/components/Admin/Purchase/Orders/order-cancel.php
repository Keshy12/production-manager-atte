<?php
use Atte\DB\MsaDB;
use Atte\Utils\Purchase\Order\PurchaseOrderRepository;

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
    $MsaDB = MsaDB::getInstance();
    $repo  = new PurchaseOrderRepository($MsaDB);
    $po    = $repo->getById($id);
    if ($po === null) {
        echo json_encode(['success' => false, 'error' => 'Nie znaleziono zamówienia']);
        exit;
    }
    if (in_array($po->state, ['received','cancelled'], true)) {
        echo json_encode(['success' => false, 'error' => 'Zamówienie jest już w stanie terminalnym']);
        exit;
    }

    $MsaDB->db->beginTransaction();
    try {
        $repo->setState($id, 'cancelled');
        $MsaDB->db->commit();
        echo json_encode(['success' => true, 'message' => 'Zamówienie anulowane']);
    } catch (\Throwable $e) {
        if ($MsaDB->db->inTransaction()) { $MsaDB->db->rollBack(); }
        throw $e;
    }
} catch (\LogicException $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (\Throwable $e) {
    error_log('order-cancel error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Wystąpił błąd podczas anulowania zamówienia']);
}
exit;
