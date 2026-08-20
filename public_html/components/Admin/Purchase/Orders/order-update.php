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

$id                   = (int)($_POST['id'] ?? 0);
$vendorId             = (int)($_POST['vendor_id'] ?? 0);
$expectedDeliveryDate = $_POST['expected_delivery_date'] ?? null;
$comment              = $_POST['comment'] ?? null;
if ($expectedDeliveryDate === '') $expectedDeliveryDate = null;
if ($comment !== null) { $comment = trim($comment); if ($comment === '') $comment = null; }

if ($id <= 0 || $vendorId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Nieprawidłowe dane formularza']);
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
    if ($po->state !== 'draft') {
        echo json_encode(['success' => false, 'error' => 'Zamówienie można edytować tylko w stanie szkicu']);
        exit;
    }
    $repo->update($id, $vendorId, $expectedDeliveryDate, $comment);
    echo json_encode(['success' => true, 'message' => 'Zamówienie zaktualizowane pomyślnie']);
} catch (\InvalidArgumentException $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (\Throwable $e) {
    error_log('order-update error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Wystąpił błąd podczas aktualizacji zamówienia']);
}
exit;
