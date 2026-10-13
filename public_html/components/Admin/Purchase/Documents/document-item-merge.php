<?php
/**
 * AJAX: merge a proposed quantity into an existing item on a document.
 *
 * Replaces the client-side merge that the "Dodaj pozycję" cascade
 * used to do (which was impossible because the cascade picker
 * filtered out VPs already on the document). When the user adds a
 * new line whose (vendor_part_id, currency, quantity_unit_id,
 * unit_price) tuple matches an existing row, the add endpoint
 * surfaces a modal with three options:
 *
 *   [Anuluj]            → close modal, leave form alone.
 *   [Dodaj mimo to]     → re-POST to document-item-add.php with
 *                          force_insert=1 → add row as a duplicate.
 *   [Połącz]            → THIS endpoint: atomically add the
 *                          proposed qty to the matched row.
 *
 * Merge is a single UPDATE inside a transaction; on throw we
 * roll back so the existing row never ends up half-edited.
 *
 * Form-encoded payload (PHP $_POST):
 *   type             = 'rfq' | 'po'    (required)
 *   doc_id           = int             (required, > 0) — rfq_id or po_id
 *   existing_item_id = int             (required, > 0) — row to merge into
 *   add_qty          = float           (required, > 0)
 *
 * Response:
 *   {success: true,  item: {id, new_quantity}}            on merge
 *   {success: false, error: '...'}                        on validation/server error
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
    echo json_encode(['success' => false, 'error' => 'Metoda niedozwolona.']);
    exit();
}

$type = (string)($_POST['type'] ?? '');
$docId = (int)($_POST['doc_id'] ?? 0);
$existingItemId = (int)($_POST['existing_item_id'] ?? 0);
$addQtyRaw = str_replace(',', '.', (string)($_POST['add_qty'] ?? '0'));
$addQty = (float)$addQtyRaw;

if (!in_array($type, ['rfq', 'po'], true)) {
    echo json_encode(['success' => false, 'error' => 'Nieprawidłowy typ dokumentu.']);
    exit;
}
if ($docId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Nieprawidłowy identyfikator dokumentu.']);
    exit;
}
if ($existingItemId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Nieprawidłowy identyfikator pozycji.']);
    exit;
}
if ($addQty <= 0) {
    echo json_encode(['success' => false, 'error' => 'Ilość do połączenia musi być > 0.']);
    exit;
}

$MsaDB = MsaDB::getInstance();

$itemTable = $type === 'po' ? 'purchase__order_item' : 'purchase__rfq_item';
$docTable  = $type === 'po' ? 'purchase__order'      : 'purchase__rfq';
$docFkCol  = $type === 'po' ? 'po_id'                : 'rfq_id';

// ── Verify the row exists AND belongs to the doc AND the doc is in
//    an editable state. Don't trust the client on any of those.
$itemStmt = $MsaDB->db->prepare(
    "SELECT i.{$docFkCol} AS doc_id, i.quantity,
            d.state
       FROM `{$itemTable}` i
       JOIN `{$docTable}`  d ON d.id = i.{$docFkCol}
      WHERE i.id = ?"
);
$itemStmt->execute([$existingItemId]);
$row = $itemStmt->fetch(\PDO::FETCH_ASSOC);
if (!$row) {
    echo json_encode(['success' => false, 'error' => 'Pozycja nie istnieje.']);
    exit;
}
if ((int)$row['doc_id'] !== $docId) {
    // Client supplied an item that doesn't belong to this doc — refuse
    // rather than silently merge into the wrong row.
    echo json_encode(['success' => false, 'error' => 'Pozycja nie należy do tego dokumentu.']);
    exit;
}

$allowedStates = PurchaseActionHandler::allowedEditStates($type);
if (!in_array($row['state'], $allowedStates, true)) {
    $docLabel = $type === 'po' ? 'zamówienia' : 'zapytania';
    echo json_encode([
        'success' => false,
        'error'   => "Nie można edytować pozycji {$docLabel} w stanie: {$row['state']}.",
    ]);
    exit;
}

try {
    $MsaDB->db->beginTransaction();
    $upd = $MsaDB->db->prepare(
        "UPDATE `{$itemTable}` SET quantity = quantity + ? WHERE id = ?"
    );
    $upd->execute([$addQty, $existingItemId]);
    if ($upd->rowCount() < 1) {
        // Should not happen — the row was just SELECT'd. Treat as a
        // concurrency / data-integrity failure rather than silently
        // committing an empty UPDATE.
        throw new \RuntimeException('Aktualizacja nie powiodła się (wiersz mógł zostać usunięty).');
    }
    $newQtyStmt = $MsaDB->db->prepare("SELECT quantity FROM `{$itemTable}` WHERE id = ?");
    $newQtyStmt->execute([$existingItemId]);
    $newQty = (float)$newQtyStmt->fetchColumn();
    $MsaDB->db->commit();
} catch (\Throwable $e) {
    if ($MsaDB->db->inTransaction()) { $MsaDB->db->rollBack(); }
    error_log('document-item-merge: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Nie udało się połączyć pozycji: ' . $e->getMessage()]);
    exit;
}

echo json_encode([
    'success' => true,
    'item'    => [
        'id'           => $existingItemId,
        'new_quantity' => $newQty,
    ],
    'message' => 'Pozycje połączone.',
]);
exit;
