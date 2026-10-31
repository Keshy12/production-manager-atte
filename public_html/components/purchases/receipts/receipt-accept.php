<?php
/**
 * AJAX: persist receipt-header metadata (vendor doc number, comment, date)
 * and transition the PO to 'in_progress'. Zero items. Idempotent — clicking
 * "Akceptuję" twice for the same PO updates the existing draft.
 *
 * Powers the first button in the goods-receiving wizard on
 * /purchase/receipts/receive. The receive-page.js side calls this BEFORE
 * collapsing the "Dokument przyjęcia" card; on success it shows the
 * summary, on failure it stays in edit mode and surfaces the error.
 *
 * Endpoint contract mirrors AGENTS.md (admin gate, method gate,
 * jQuery form-encoded $_POST, validation-as-JSON-200, transaction):
 *
 * POST (form-encoded):
 *   po_id                    int    required
 *   vendor_document_number   string optional — prepended to the receipt
 *                                  comment on its own line, prefixed
 *                                  "Nr dok. dostawcy: ..." (same convention
 *                                  as receipt-create.php lines 153-156)
 *   comment                  string optional — receipt header comment
 *   received_at              string optional, 'Y-m-d H:i:s' or 'Y-m-d'.
 *                                  Date-only is normalised to
 *                                  'Y-m-d 00:00:00' before being forwarded
 *                                  to the handler. If null/empty, the
 *                                  handler leaves the DB column default
 *                                  (CURRENT_TIMESTAMP) to fire.
 *
 * User id comes from $_SESSION['user_id'] — same as receipt-create.php.
 * The handler accepts a `receivedBy` argument explicitly, which is what
 * we hand it.
 *
 * Response on success:
 *   {success: true, receipt_id: int, po_state: 'in_progress',
 *    po_state_label: 'Częściowo przyjęte', was_updated: bool}
 *
 *   - was_updated: true if an existing draft was updated (operator hit
 *     Akceptuję again on an in_progress PO with new metadata),
 *     false if a brand-new draft row was inserted.
 *
 * Exception handling:
 *   - \LogicException / \InvalidArgumentException from the handler
 *     carry user-actionable messages (state guards, date validation).
 *     They are re-thrown as JSON {success:false, error: <message>}
 *     with HTTP 200 so the JS side can render via setAlert.
 *   - Any other \Throwable → generic Polish message + error_log of the
 *     real exception. We never leak raw exception text to the client.
 */
use Atte\DB\MsaDB;
use Atte\Utils\Purchase\Order\PurchaseActionHandler;

header('Content-Type: application/json; charset=utf-8');

if(!isset($_SESSION['isAdmin']) || $_SESSION['isAdmin'] !== true) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Brak uprawnień']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Metoda nieobsługiwana']);
    exit;
}

$poId            = (int)($_POST['po_id'] ?? 0);
$vendorDocNumber = $_POST['vendor_document_number'] ?? null;
$comment         = $_POST['comment'] ?? null;
$receivedAt      = $_POST['received_at'] ?? null;

// Mirror receipt-create.php's trim/null-coerce on the text inputs. The
// handler does the same again — duplicate work, but it lets us
// short-circuit malformed inputs before reaching the DB layer.
if ($vendorDocNumber !== null) {
    $vendorDocNumber = trim((string)$vendorDocNumber);
    if ($vendorDocNumber === '') $vendorDocNumber = null;
}
if ($comment !== null) {
    $comment = trim((string)$comment);
    if ($comment === '') $comment = null;
}
if ($receivedAt !== null) {
    $receivedAt = trim((string)$receivedAt);
    if ($receivedAt === '') $receivedAt = null;
}
// Normalise 'Y-m-d' → 'Y-m-d 00:00:00'. The handler validates strictly
// against 'Y-m-d H:i:s', so the endpoint owns this conversion. Same
// trick as receipt-create.php lines 96-98.
if ($receivedAt !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $receivedAt)) {
    $receivedAt .= ' 00:00:00';
}

$userId = (int)($_SESSION['user_id'] ?? 0);
if ($userId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Brak zalogowanego użytkownika.']);
    exit;
}
if ($poId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Nieprawidłowe ID zamówienia.']);
    exit;
}

$MsaDB   = MsaDB::getInstance();
$handler = new PurchaseActionHandler($MsaDB);

try {
    // No transaction in the endpoint — the handler owns its own
    // (acceptReceiptMetadata starts one). Reason: the only thing this
    // method commits is the receipt-row insert/update and the optional
    // PO state transition; we have no other write that needs to join
    // the transaction. Receipt-create.php follows the same pattern.
    $result = $handler->acceptReceiptMetadata(
        $poId,
        $userId,
        $vendorDocNumber,
        $comment,
        $receivedAt
    );

    echo json_encode([
        'success'         => true,
        'receipt_id'      => $result['receiptId'],
        'po_state'        => $result['state'],
        // 'po_state_label' is part of the standard response shape across
        // receipt-* endpoints — the JS renders it inside the success
        // alert so the operator sees the resulting state in Polish.
        'po_state_label'  => $result['stateLabel'],
        'was_updated'     => $result['wasUpdated'],
    ]);
} catch (\LogicException | \InvalidArgumentException $e) {
    // Handler-thrown validation errors carry user-actionable messages.
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (\Throwable $e) {
    error_log('receipt-accept error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Błąd zapisu nagłówka przyjęcia.']);
}
exit;