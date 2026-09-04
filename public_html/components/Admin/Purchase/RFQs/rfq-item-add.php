<?php
/**
 * AJAX: append one item to an existing RFQ.
 *
 * The RFQ must be in draft / sent / responded state — terminal states
 * (cancelled, converted) reject new items.
 *
 * The vendor_part_id must belong to the RFQ's vendor. The server
 * re-reads the header + the vendor_part and enforces this so the client
 * can't smuggle a vendor-part from another vendor into the RFQ.
 *
 * Form-encoded payload (PHP $_POST):
 *   rfq_id           = int   (required, > 0)
 *   vendor_part_id   = int   (required, > 0)
 *   quantity         = float (required, > 0)
 *   unit_price       = float (optional, blank → NULL)
 *   currency         = string (default 'PLN')
 *   picked_pack_size = float (optional, blank → NULL, > 0 when present)
 *   comment          = string (optional, blank → NULL)
 *
 * Response:
 *   {success: true, rfq_item_id: int, message: 'Pozycja dodana.'}   on save
 *   {success: false, error: '...'}                                 on validation/server error
 *
 * quantity_unit_id is auto-derived from list__vendor_part.vendor_jm_id
 * — the user never picks a unit; the catalog row's default JM is what
 * the purchase row carries. Matches cart-create-document behavior.
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

$rfqId  = (int)($_POST['rfq_id'] ?? 0);
$vpId   = (int)($_POST['vendor_part_id'] ?? 0);
$qtyRaw = str_replace(',', '.', (string)($_POST['quantity'] ?? '0'));
$qty    = (float)$qtyRaw;

$priceRaw = $_POST['unit_price'] ?? '';
$priceRawStr = is_string($priceRaw) ? trim($priceRaw) : (string)$priceRaw;
$unitPrice = ($priceRawStr === '' || $priceRawStr === null) ? null
    : (float)str_replace(',', '.', $priceRawStr);

$currency = trim((string)($_POST['currency'] ?? 'PLN')) ?: 'PLN';

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

if ($rfqId <= 0) { echo json_encode(['success' => false, 'error' => 'Nieprawidłowy identyfikator zapytania.']); exit; }
if ($vpId <= 0)  { echo json_encode(['success' => false, 'error' => 'Wybierz część z listy.']); exit; }
if ($qty <= 0)   { echo json_encode(['success' => false, 'error' => 'Ilość musi być > 0.']); exit; }
if ($unitPrice !== null && $unitPrice < 0) {
    echo json_encode(['success' => false, 'error' => 'Cena nie może być ujemna.']);
    exit;
}
if ($pickedPackSize !== null && $pickedPackSize <= 0) {
    echo json_encode(['success' => false, 'error' => 'Wybrane opakowanie musi być > 0.']);
    exit;
}

$MsaDB = MsaDB::getInstance();

// Server-side state guard + vendor-scope check. Don't trust the client.
$stateStmt = $MsaDB->db->prepare("SELECT vendor_id, state FROM `purchase__rfq` WHERE id = ?");
$stateStmt->execute([$rfqId]);
$rfqRow = $stateStmt->fetch(\PDO::FETCH_ASSOC);
if (!$rfqRow) {
    echo json_encode(['success' => false, 'error' => 'Zapytanie nie istnieje.']);
    exit;
}
$allowedStates = ['draft', 'sent', 'responded'];
if (!in_array($rfqRow['state'], $allowedStates, true)) {
    echo json_encode(['success' => false, 'error' => 'Nie można dodawać pozycji do zapytania w stanie: ' . $rfqRow['state'] . '.']);
    exit;
}
$rfqVendorId = (int)$rfqRow['vendor_id'];

$vpStmt = $MsaDB->db->prepare(
    "SELECT id, vendor_id, vendor_jm_id, isActive
       FROM `list__vendor_part`
      WHERE id = ?"
);
$vpStmt->execute([$vpId]);
$vp = $vpStmt->fetch(\PDO::FETCH_ASSOC);
if (!$vp || (int)$vp['isActive'] !== 1) {
    echo json_encode(['success' => false, 'error' => 'Wybrany artykuł nie jest aktywny.']);
    exit;
}
if ((int)$vp['vendor_id'] !== $rfqVendorId) {
    echo json_encode(['success' => false, 'error' => 'Wybrany artykuł nie należy do dostawcy tego zapytania.']);
    exit;
}
$unitId = (int)$vp['vendor_jm_id'];
if ($unitId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Artykuł nie ma przypisanej jednostki miary.']);
    exit;
}

$rfiRepo = new RFQItemRepository($MsaDB);

try {
    $newId = $rfiRepo->create($rfqId, $vpId, $qty, $unitId, $unitPrice, $currency, $cmt, $pickedPackSize);
} catch (\Throwable $e) {
    error_log('rfq-item-add: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Błąd zapisu: ' . $e->getMessage()]);
    exit;
}

echo json_encode([
    'success'      => true,
    'rfq_item_id'  => (int)$newId,
    'message'      => 'Pozycja dodana.',
]);
exit;