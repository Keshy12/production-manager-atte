<?php
/**
 * AJAX: inline-edit one row of /admin/purchase/rfqs/edit.
 *
 * Only qty and unit_price are meant to change from the UI. The other
 * fields (vendor_part_id, quantity_unit_id, currency, picked_pack_size,
 * comment) are echoed back from the row unchanged — but we re-validate
 * them so a hand-crafted POST can't smuggle in garbage.
 *
 * Form-encoded payload (PHP $_POST):
 *   id                  = int   (required, > 0)
 *   quantity            = float (required, > 0)
 *   unit_price          = float (optional, blank → NULL)
 *   vendor_part_id      = int   (required, > 0)
 *   quantity_unit_id    = int   (required, > 0)
 *   currency            = string (default PLN)
 *   picked_pack_size    = float (optional, blank → NULL, > 0 when present)
 *   comment             = string (optional, blank → NULL)
 *
 * Response:
 *   {success: true, quantity, unit_price, line_total}   on save
 *   {success: false, error: '...'}                     on validation/server error
 *
 * Wraps in a transaction so qty/price/comment land together; on throw
 * rolls back so the row never ends up half-edited.
 */
use Atte\DB\MsaDB;
use Atte\Utils\Purchase\Order\RFQItemRepository;

header('Content-Type: application/json; charset=utf-8');

if(!isset($_SESSION['isAdmin']) || $_SESSION['isAdmin'] !== true) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Brak uprawnień.']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Metoda nieobsługiwana']);
    exit();
}

$id         = (int)($_POST['id'] ?? 0);
$vpId       = (int)($_POST['vendor_part_id'] ?? 0);
$unitId     = (int)($_POST['quantity_unit_id'] ?? 0);
$qtyRaw     = str_replace(',', '.', (string)($_POST['quantity'] ?? '0'));
$qty        = (float)$qtyRaw;
$priceRaw   = $_POST['unit_price'] ?? '';
$priceRawStr = is_string($priceRaw) ? trim($priceRaw) : (string)$priceRaw;
$unitPrice  = ($priceRawStr === '' || $priceRawStr === null) ? null
    : (float)str_replace(',', '.', $priceRawStr);
$currency   = trim((string)($_POST['currency'] ?? 'PLN')) ?: 'PLN';

$packRaw = $_POST['picked_pack_size'] ?? null;
if ($packRaw !== null) {
    $packRawStr = is_string($packRaw) ? trim($packRaw) : (string)$packRaw;
    $pickedPackSize = ($packRawStr === '' || $packRawStr === null) ? null
        : (float)str_replace(',', '.', $packRawStr);
} else {
    $pickedPackSize = null;
}

$cmt = $_POST['comment'] ?? null;
if ($cmt !== null) { $cmt = trim((string)$cmt); if ($cmt === '') { $cmt = null; } }

if ($id <= 0)        { echo json_encode(['success' => false, 'error' => 'Nieprawidłowy identyfikator pozycji.']); exit; }
if ($vpId <= 0)      { echo json_encode(['success' => false, 'error' => 'Brak artykułu dostawcy.']); exit; }
if ($unitId <= 0)    { echo json_encode(['success' => false, 'error' => 'Brak jednostki miary.']); exit; }
if ($qty <= 0)       { echo json_encode(['success' => false, 'error' => 'Ilość musi być > 0.']); exit; }
if ($unitPrice !== null && $unitPrice < 0) { echo json_encode(['success' => false, 'error' => 'Cena nie może być ujemna.']); exit; }
if ($pickedPackSize !== null && $pickedPackSize <= 0) {
    echo json_encode(['success' => false, 'error' => 'Wybrane opakowanie musi być > 0.']);
    exit;
}

$MsaDB = MsaDB::getInstance();
$rfiRepo = new RFQItemRepository($MsaDB);

try {
    $MsaDB->db->beginTransaction();
    $ok = $rfiRepo->update($id, $vpId, $qty, $unitId, $unitPrice, $currency, $cmt, $pickedPackSize);
    if (!$ok) {
        throw new \RuntimeException('Update nie powiódł się (wiersz mógł zostać usunięty).');
    }
    $MsaDB->db->commit();
} catch (\Throwable $e) {
    if ($MsaDB->db->inTransaction()) { $MsaDB->db->rollBack(); }
    error_log('rfq-item-update: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Błąd zapisu: ' . $e->getMessage()]);
    exit;
}

$lineTotal = $unitPrice === null ? null : ((float)$unitPrice * $qty);

echo json_encode([
    'success'    => true,
    'quantity'   => $qty,
    'unit_price' => $unitPrice,
    'line_total' => $lineTotal,
    'currency'   => $currency,
    'message'    => 'Zapisano.',
]);
exit;