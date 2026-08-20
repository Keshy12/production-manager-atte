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

$vendorId             = (int)($_POST['vendor_id'] ?? 0);
$expectedDeliveryDate = $_POST['expected_delivery_date'] ?? null;
$comment              = $_POST['comment'] ?? null;
if ($expectedDeliveryDate === '') $expectedDeliveryDate = null;
if ($comment !== null) { $comment = trim($comment); if ($comment === '') $comment = null; }

if ($vendorId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Wybierz dostawcę']);
    exit;
}

try {
    $MsaDB   = MsaDB::getInstance();
    $handler = new PurchaseActionHandler($MsaDB);
    $userId  = (int)($_SESSION['user_id'] ?? 0);
    $newId   = $handler->createDocument('po', $vendorId, $userId);

    // Follow-up so the freshly-created draft carries the user-supplied date/comment.
    if ($expectedDeliveryDate !== null || $comment !== null) {
        $repo = new PurchaseOrderRepository($MsaDB);
        $repo->update($newId, $vendorId, $expectedDeliveryDate, $comment);
    }

    $po = (new PurchaseOrderRepository($MsaDB))->getById($newId);
    $poNumber = $po ? $po->poNumber : null;

    echo json_encode([
        'success'   => true,
        'id'        => $newId,
        'po_number' => $poNumber,
        'message'   => 'Zamówienie dodane pomyślnie',
    ]);
} catch (\InvalidArgumentException $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (\LogicException $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (\Throwable $e) {
    error_log('order-add error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Wystąpił błąd podczas dodawania zamówienia']);
}
exit;
