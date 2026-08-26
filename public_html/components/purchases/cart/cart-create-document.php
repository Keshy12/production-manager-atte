<?php
/**
 * AJAX: create a draft PO or RFQ from cart items for ONE vendor.
 *
 * Mirrors the vp-add.php POST style (application/x-www-form-urlencoded,
 * JSON response, admin-gated). The browser then window.open()s the
 * returned edit_url in a new tab and removes the used items from the
 * localStorage cart on success.
 *
 * Accepts form-encoded payload:
 *   type       = 'rfq' | 'po'           (required)
 *   vendor_id  = int                    (required)
 *   comment    = string                 (optional, header comment)
 *   items[0][vendor_part_id]            = int  (required, > 0)
 *   items[0][quantity]                  = number (required, > 0)
 *   items[0][quantity_unit_id]          = int  (required, > 0)
 *   items[0][unit_price]                = number (optional, blank allowed)
 *   items[0][currency]                  = string (default PLN)
 *   items[0][comment]                   = string (optional)
 *
 * Response shape:
 *   {
 *     success:  true,
 *     doc_type: 'rfq'|'po',
 *     doc_id:   int,
 *     edit_url: '/admin/purchase/orders/edit?id=N' or '/admin/purchase/rfqs/edit?id=N',
 *     used_vendor_part_ids: [int, ...]   // mirror of input item vendor_part_ids, for the cart to remove
 *     message: string
 *   }
 *
 * The whole create (header + N items) is wrapped in a single transaction;
 * any throw rolls everything back so the user never sees a half-created doc.
 */
use Atte\DB\MsaDB;
use Atte\Utils\Purchase\Order\PurchaseActionHandler;
use Atte\Utils\Purchase\Order\PurchaseOrderItemRepository;
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

$type     = $_POST['type'] ?? '';
$vendorId = (int)($_POST['vendor_id'] ?? 0);
$comment  = $_POST['comment'] ?? null;
if ($comment !== null) { $comment = trim($comment); if ($comment === '') { $comment = null; } }

if (!in_array($type, ['rfq', 'po'], true)) {
    echo json_encode(['success' => false, 'error' => 'Nieprawidłowy typ dokumentu.']);
    exit;
}
if ($vendorId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Nieprawidłowy dostawca.']);
    exit;
}
if (!isset($_POST['items']) || !is_array($_POST['items']) || count($_POST['items']) === 0) {
    echo json_encode(['success' => false, 'error' => 'Brak pozycji do dodania.']);
    exit;
}

$userId = (int)($_SESSION['user_id'] ?? 0);
if ($userId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Brak zalogowanego użytkownika.']);
    exit;
}

$items = [];
$usedVpIds = [];
foreach ($_POST['items'] as $row) {
    $vpId   = (int)($row['vendor_part_id'] ?? 0);
    $qty    = str_replace(',', '.', (string)($row['quantity'] ?? '0'));
    $qty    = (float)$qty;
    $unitId = (int)($row['quantity_unit_id'] ?? 0);
    $priceRaw = $row['unit_price'] ?? '';
    $priceRawStr = is_string($priceRaw) ? trim($priceRaw) : (string)$priceRaw;
    $unitPrice = ($priceRawStr === '' || $priceRawStr === null) ? null
        : (float)str_replace(',', '.', $priceRawStr);
    $currency = trim((string)($row['currency'] ?? 'PLN')) ?: 'PLN';
    $rowCmt = $row['comment'] ?? null;
    if ($rowCmt !== null) { $rowCmt = trim((string)$rowCmt); if ($rowCmt === '') { $rowCmt = null; } }

    if ($vpId <= 0)  { echo json_encode(['success' => false, 'error' => 'Pozycja z nieprawidłowym artykułem.']); exit; }
    if ($qty <= 0)   { echo json_encode(['success' => false, 'error' => 'Ilość musi być > 0.']); exit; }
    if ($unitId <= 0){ echo json_encode(['success' => false, 'error' => 'Brak jednostki miary.']); exit; }

    $items[] = [
        'vendor_part_id'  => $vpId,
        'quantity'        => $qty,
        'quantity_unit_id'=> $unitId,
        'unit_price'      => $unitPrice,
        'currency'        => $currency,
        'comment'         => $rowCmt,
    ];
    $usedVpIds[] = $vpId;
}

$MsaDB = MsaDB::getInstance();
$handler = new PurchaseActionHandler($MsaDB);

try {
    // createDocument() commits internally (header only).
    $docId = $handler->createDocument($type, $vendorId, $userId);
} catch (\Throwable $e) {
    error_log('cart-create-document createDocument: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Błąd tworzenia dokumentu: ' . $e->getMessage()]);
    exit;
}

// Persist header comment (createDocument doesn't take one). Then bulk-insert items.
try {
    $MsaDB->db->beginTransaction();
    if ($comment !== null) {
        if ($type === 'po') {
            $stmt = $MsaDB->db->prepare("UPDATE `purchase__order` SET `comment` = ? WHERE `id` = ?");
        } else {
            $stmt = $MsaDB->db->prepare("UPDATE `purchase__rfq` SET `comment` = ? WHERE `id` = ?");
        }
        $stmt->execute([$comment, $docId]);
    }

    if ($type === 'po') {
        $itemRepo = new PurchaseOrderItemRepository($MsaDB);
        foreach ($items as $it) {
            $itemRepo->create(
                $docId,
                $it['vendor_part_id'],
                $it['quantity'],
                $it['quantity_unit_id'],
                $it['unit_price'] ?? 0,
                $it['currency'],
                $it['comment']
            );
        }
    } else { // 'rfq'
        $itemRepo = new RFQItemRepository($MsaDB);
        foreach ($items as $it) {
            $itemRepo->create(
                $docId,
                $it['vendor_part_id'],
                $it['quantity'],
                $it['quantity_unit_id'],
                $it['unit_price'],
                $it['currency'],
                $it['comment']
            );
        }
    }
    $MsaDB->db->commit();
} catch (\Throwable $e) {
    if ($MsaDB->db->inTransaction()) { $MsaDB->db->rollBack(); }
    // Best-effort cleanup of the header we created
    try {
        if ($type === 'po') {
            $MsaDB->db->prepare("DELETE FROM `purchase__order` WHERE id = ?")->execute([$docId]);
        } else {
            $MsaDB->db->prepare("DELETE FROM `purchase__rfq` WHERE id = ?")->execute([$docId]);
        }
    } catch (\Throwable $cleanupErr) { /* swallow */ }
    error_log('cart-create-document items insert: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Błąd zapisu pozycji: ' . $e->getMessage()]);
    exit;
}

$editUrl = 'http://' . BASEURL . '/' . (($type === 'po')
    ? 'admin/purchase/orders/edit'
    : 'admin/purchase/rfqs/edit') . '?id=' . $docId;

echo json_encode([
    'success'              => true,
    'doc_type'             => $type,
    'doc_id'               => (int)$docId,
    'edit_url'             => $editUrl,
    'used_vendor_part_ids' => $usedVpIds,
    'message'              => ($type === 'po' ? 'Zamówienie ' : 'Zapytanie ') . '#' . $docId . ' utworzone jako szkic.',
]);
exit;
