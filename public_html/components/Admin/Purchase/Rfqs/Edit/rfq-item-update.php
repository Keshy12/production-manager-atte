<?php
use Atte\DB\MsaDB;
use Atte\Utils\Purchase\Master\VendorPartRepository;
use Atte\Utils\Purchase\Order\RFQItemRepository;

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

$id            = (int)($_POST['id'] ?? 0);
$rfqId         = (int)($_POST['rfq_id'] ?? 0);
$vendorPartId  = (int)($_POST['vendor_part_id'] ?? 0);
$quantityRaw   = (string)($_POST['quantity'] ?? '');
$unitPriceRaw  = $_POST['unit_price'] ?? null;
$currency      = trim((string)($_POST['currency'] ?? 'PLN'));
$comment       = $_POST['comment'] ?? null;

if ($currency === '') $currency = 'PLN';
if ($comment !== null) { $comment = trim($comment); if ($comment === '') $comment = null; }

if ($unitPriceRaw !== null && $unitPriceRaw !== '') {
    $unitPriceRaw = str_replace(',', '.', (string)$unitPriceRaw);
    if (!is_numeric($unitPriceRaw) || (float)$unitPriceRaw < 0) {
        echo json_encode(['success' => false, 'error' => 'Nieprawidłowa cena jednostkowa']);
        exit;
    }
    $unitPrice = (float)$unitPriceRaw;
} else {
    $unitPrice = null;
}

if ($id <= 0 || $rfqId <= 0 || $vendorPartId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Nieprawidłowe dane formularza']);
    exit;
}

$quantityNorm = str_replace(',', '.', $quantityRaw);
if (!is_numeric($quantityNorm) || (float)$quantityNorm <= 0) {
    echo json_encode(['success' => false, 'error' => 'Ilość musi być większa od zera']);
    exit;
}
$quantity = (float)$quantityNorm;

try {
    $MsaDB   = MsaDB::getInstance();
    $itemRepo = new RFQItemRepository($MsaDB);
    $existing = $itemRepo->getById($id);
    if ($existing === null || $existing->rfqId !== $rfqId) {
        echo json_encode(['success' => false, 'error' => 'Pozycja nie należy do tego zapytania']);
        exit;
    }

    $vpRepo = new VendorPartRepository($MsaDB);
    $vp     = $vpRepo->getById($vendorPartId);
    if ($vp === null) {
        echo json_encode(['success' => false, 'error' => 'Artykuł u dostawcy nie istnieje']);
        exit;
    }

    $itemRepo->update($id, $vendorPartId, $quantity, $vp->vendorJmId, $unitPrice, $currency, $comment);
    echo json_encode(['success' => true, 'message' => 'Pozycja zaktualizowana pomyślnie']);
} catch (\InvalidArgumentException $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (\Throwable $e) {
    error_log('rfq-item-update error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Wystąpił błąd podczas aktualizacji pozycji']);
}
exit;
