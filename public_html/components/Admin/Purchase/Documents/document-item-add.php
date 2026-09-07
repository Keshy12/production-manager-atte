<?php
/**
 * AJAX: append one item to an existing RFQ or PO.
 *
 * Replaces rfq-item-add.php with a type-aware version. The legacy
 * RFQ-only endpoint continues to work for the transition period; both
 * are wired in this file. Phase 5 deletes the legacy ones.
 *
 * The document must be in a state that accepts edits — see
 * PurchaseActionHandler::allowedEditStates(). Terminal states
 * (cancelled, converted for RFQ; cancelled, partially_received,
 * received for PO) reject new items.
 *
 * The vendor_part_id must belong to the document's vendor. The server
 * re-reads the header + the vendor_part and enforces this so the client
 * can't smuggle a vendor-part from another vendor in.
 *
 * quantity_unit_id is auto-derived from list__vendor_part.vendor_jm_id
 * — the user never picks a unit; the catalog row's default JM is what
 * the purchase row carries. Matches cart-create-document behavior.
 *
 * Form-encoded payload (PHP $_POST):
 *   type             = 'rfq' | 'po'  (required)
 *   doc_id           = int            (required, > 0) — rfq_id or po_id
 *   vendor_part_id   = int            (required, > 0)
 *   quantity         = float          (required, > 0)
 *   unit_price       = float          (optional, blank → NULL)
 *   currency         = string         (default PLN)
 *   picked_pack_size = float          (optional, blank → NULL, > 0 when present)
 *   comment          = string         (optional, blank → NULL)
 *
 * Response:
 *   {success: true, item_id: int, message: 'Pozycja dodana.'}   on save
 *   {success: false, error: '...'}                             on validation/server error
 */
use Atte\DB\MsaDB;
use Atte\Utils\Purchase\Order\PurchaseActionHandler;

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

$type   = (string)($_POST['type'] ?? '');
$docId  = (int)($_POST['doc_id'] ?? 0);
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

// ── Validation ───────────────────────────────────────────────────────
if (!in_array($type, ['rfq', 'po'], true)) {
    echo json_encode(['success' => false, 'error' => 'Nieprawidłowy typ dokumentu.']);
    exit;
}
if ($docId <= 0) { echo json_encode(['success' => false, 'error' => 'Nieprawidłowy identyfikator dokumentu.']); exit; }
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

// ── Server-side state guard + vendor-scope check ─────────────────────
// Don't trust the client. Look up the parent doc, confirm it exists
// and is in an editable state, and capture its vendor_id for the
// VP-membership check below.
$docTable = $type === 'po' ? 'purchase__order' : 'purchase__rfq';
$stateStmt = $MsaDB->db->prepare("SELECT vendor_id, state FROM `{$docTable}` WHERE id = ?");
$stateStmt->execute([$docId]);
$docRow = $stateStmt->fetch(\PDO::FETCH_ASSOC);
if (!$docRow) {
    echo json_encode(['success' => false, 'error' => 'Dokument nie istnieje.']);
    exit;
}

$allowedStates = PurchaseActionHandler::allowedEditStates($type);
if (!in_array($docRow['state'], $allowedStates, true)) {
    $docLabel = $type === 'po' ? 'zamówienia' : 'zapytania';
    echo json_encode([
        'success' => false,
        'error'   => "Nie można dodawać pozycji do {$docLabel} w stanie: {$docRow['state']}.",
    ]);
    exit;
}
$docVendorId = (int)$docRow['vendor_id'];

// ── Vendor-part membership check ────────────────────────────────────
// Same VP must belong to the doc's vendor — prevents smuggling a
// competitor's VP into the line items.
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
if ((int)$vp['vendor_id'] !== $docVendorId) {
    echo json_encode(['success' => false, 'error' => 'Wybrany artykuł nie należy do dostawcy tego dokumentu.']);
    exit;
}
$unitId = (int)$vp['vendor_jm_id'];
if ($unitId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Artykuł nie ma przypisanej jednostki miary.']);
    exit;
}

// ── Insert via the right repo ───────────────────────────────────────
$handler = new PurchaseActionHandler($MsaDB);
try {
    $itemData = [
        'vendor_part_id'   => $vpId,
        'quantity'         => $qty,
        'quantity_unit_id' => $unitId,
        'unit_price'       => $unitPrice,
        'currency'         => $currency,
        'comment'          => $cmt,
        'picked_pack_size' => $pickedPackSize,
    ];
    $newId = $handler->addItem($type, $docId, $itemData);
} catch (\Throwable $e) {
    error_log('document-item-add: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Błąd zapisu: ' . $e->getMessage()]);
    exit;
}

echo json_encode([
    'success' => true,
    'item_id' => (int)$newId,
    'message' => 'Pozycja dodana.',
]);
exit;
