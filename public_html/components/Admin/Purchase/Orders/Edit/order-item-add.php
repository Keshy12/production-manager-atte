<?php
use Atte\DB\MsaDB;
use Atte\Utils\Purchase\Master\VendorPartRepository;
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

$poId          = (int)($_POST['po_id'] ?? 0);
$vendorPartId  = (int)($_POST['vendor_part_id'] ?? 0);
$quantityRaw   = (string)($_POST['quantity'] ?? '');
$unitPriceRaw  = (string)($_POST['unit_price'] ?? '0');
$currency      = trim((string)($_POST['currency'] ?? 'PLN'));
$comment       = $_POST['comment'] ?? null;

if ($currency === '') $currency = 'PLN';
if ($comment !== null) { $comment = trim($comment); if ($comment === '') $comment = null; }

if ($poId <= 0 || $vendorPartId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Nieprawidłowe dane formularza']);
    exit;
}

$quantityNorm = str_replace(',', '.', $quantityRaw);
if (!is_numeric($quantityNorm) || (float)$quantityNorm <= 0) {
    echo json_encode(['success' => false, 'error' => 'Ilość musi być większa od zera']);
    exit;
}
$quantity = (float)$quantityNorm;

$unitPriceNorm = str_replace(',', '.', $unitPriceRaw);
if (!is_numeric($unitPriceNorm) || (float)$unitPriceNorm < 0) {
    echo json_encode(['success' => false, 'error' => 'Nieprawidłowa cena jednostkowa']);
    exit;
}
$unitPrice = (float)$unitPriceNorm;

try {
    $MsaDB   = MsaDB::getInstance();
    $poRepo  = new PurchaseOrderRepository($MsaDB);
    $vpRepo  = new VendorPartRepository($MsaDB);

    $po = $poRepo->getById($poId);
    if ($po === null) {
        echo json_encode(['success' => false, 'error' => 'Nie znaleziono zamówienia']);
        exit;
    }
    if ($po->state !== 'draft') {
        echo json_encode(['success' => false, 'error' => 'Nowe pozycje można dodawać tylko w stanie szkicu']);
        exit;
    }

    $vp = $vpRepo->getById($vendorPartId);
    if ($vp === null) {
        echo json_encode(['success' => false, 'error' => 'Artykuł u dostawcy nie istnieje']);
        exit;
    }

    $repo = new PurchaseOrderItemRepository($MsaDB);
    $id   = $repo->create($poId, $vendorPartId, $quantity, $vp->vendorJmId, $unitPrice, $currency, $comment);
    echo json_encode(['success' => true, 'id' => $id, 'message' => 'Pozycja dodana pomyślnie']);
} catch (\InvalidArgumentException $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (\Throwable $e) {
    error_log('order-item-add error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Wystąpił błąd podczas dodawania pozycji']);
}
exit;
