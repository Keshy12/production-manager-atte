<?php
/**
 * AJAX: delete one row of /admin/purchase/documents/edit.
 *
 * Replaces rfq-item-delete.php with a type-aware version. The
 * endpoint validates the item exists in the table implied by `type`
 * before deleting — a hand-crafted POST with the wrong type is
 * rejected (no silent delete from the wrong table).
 *
 * PO-only floor check: when type=po and the item has receipts
 * recorded against it, deletion is blocked. The user must first
 * remove the receipts before the line can go away. RFQ items never
 * carry receipts.
 *
 * Form-encoded payload (PHP $_POST):
 *   type = 'rfq' | 'po'  (required)
 *   id   = int           (required, > 0)
 *
 * Response:
 *   {success: true, message: 'Pozycja usunięta.'}     on delete
 *   {success: false, error: '...'}                    on validation/server error
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

$type = (string)($_POST['type'] ?? '');
$id   = (int)($_POST['id'] ?? 0);

if (!in_array($type, ['rfq', 'po'], true)) {
    echo json_encode(['success' => false, 'error' => 'Nieprawidłowy typ dokumentu.']);
    exit;
}
if ($id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Nieprawidłowy identyfikator pozycji.']);
    exit;
}

$MsaDB = MsaDB::getInstance();
$handler = new PurchaseActionHandler($MsaDB);
$itemRepo = $handler->itemRepository($type);

// ── Confirm the item exists in the implied table ────────────────────
// repo->delete() returns bool but doesn't tell us whether the row was
// missing vs present-but-undeletable (FK cascade, etc.). The check
// below gives a precise error in either case.
$itemTable = $type === 'po' ? 'purchase__order_item' : 'purchase__rfq_item';
$existsStmt = $MsaDB->db->prepare("SELECT 1 FROM `{$itemTable}` WHERE id = ?");
$existsStmt->execute([$id]);
if (!$existsStmt->fetchColumn()) {
    echo json_encode(['success' => false, 'error' => 'Pozycja nie istnieje.']);
    exit;
}

// ── PO-only receipt-exists check ────────────────────────────────────
// FK CASCADE on po_item_id in purchase__order_receipt_item would
// silently delete the receipt rows if we let this through. The
// receipt flow lives under /purchases/receipts/; force the user
// through that path to clear receipts first.
if ($type === 'po') {
    $receiptCountStmt = $MsaDB->db->prepare(
        "SELECT COUNT(*) FROM `purchase__order_receipt_item` WHERE po_item_id = ?"
    );
    $receiptCountStmt->execute([$id]);
    if ((int)$receiptCountStmt->fetchColumn() > 0) {
        echo json_encode([
            'success' => false,
            'error'   => 'Nie można usunąć pozycji, do której istnieją przyjęcia. Najpierw usuń przyjęcia.',
        ]);
        exit;
    }
}

try {
    $ok = $itemRepo->delete($id);
    if (!$ok) {
        throw new \RuntimeException('Usunięcie nie powiodło się.');
    }
} catch (\Throwable $e) {
    error_log('document-item-delete: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Błąd usuwania: ' . $e->getMessage()]);
    exit;
}

echo json_encode(['success' => true, 'message' => 'Pozycja usunięta.']);
exit;
