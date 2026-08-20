<?php
use Atte\DB\MsaDB;
use Atte\Utils\Purchase\Order\PurchaseActionHandler;
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
    echo json_encode(['success' => false, 'error' => 'Nieprawidłowe ID zapytania']);
    exit;
}

try {
    $MsaDB   = MsaDB::getInstance();
    $handler = new PurchaseActionHandler($MsaDB);
    $userId  = (int)($_SESSION['user_id'] ?? 0);
    $poId    = $handler->createPoFromRfq($id, $userId);

    // Fetch the freshly-created PO so we can return its number.
    $po        = (new PurchaseOrderRepository($MsaDB))->getById($poId);
    $poNumber  = $po ? $po->poNumber : null;

    echo json_encode([
        'success'   => true,
        'po_id'     => $poId,
        'po_number' => $poNumber,
        'message'   => 'Skonwertowano na zamówienie ' . ($poNumber ?? ('#' . $poId)),
    ]);
} catch (\LogicException $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (\InvalidArgumentException $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (\Throwable $e) {
    error_log('rfq-convert-to-po error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Wystąpił błąd podczas konwersji zapytania']);
}
exit;
