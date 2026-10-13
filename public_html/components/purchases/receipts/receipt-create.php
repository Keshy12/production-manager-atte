<?php
/**
 * AJAX: create a goods-receipt (GRN) against a confirmed / in_progress /
 * partially_received PO.
 *
 * Powers the goods-receiving UI on /purchase/receipts. Mirrors the
 * endpoint shape spelled out in AGENTS.md (admin gate, method gate,
 * jQuery form-encoded $_POST, validation-as-JSON-200, transaction).
 *
 * 'in_progress' is the logistics state set by receipt-accept.php when
 * the operator clicks "Akceptuję" (header metadata only, no items). The
 * natural next step from in_progress is to book items — that's this
 * endpoint, with the same state-transition output (partially_received
 * or received) as a confirmed-PO save.
 *
 * POST (form-encoded):
 *   po_id                    int    required
 *   comment                  string optional — receipt header comment
 *   vendor_document_number   string optional — prepended to the receipt
 *                                  comment on its own line, prefixed
 *                                  "Nr dok. dostawcy: ..."
 *   received_at              string optional, 'Y-m-d H:i:s' or 'Y-m-d'.
 *                                  Date-only is normalised to
 *                                  'Y-m-d 00:00:00' before being
 *                                  forwarded to the handler. The
 *                                  handler does the actual
 *                                  parse + future-tolerance check.
 *   items[i][po_item_id]         int    required
 *   items[i][quantity_received]  decimal string (',' accepted)
 *                                          required, > 0
 *   items[i][sub_magazine_id]    int    required
 *   items[i][comment]            string optional
 *
 * Behaviour:
 *   - Items with blank or 0 quantity_received are skipped (defensive —
 *     frontend only sends filled lines but a stray empty <input> still
 *     surfaces as '' in $_POST). If NO items survive the filter we
 *     short-circuit with a Polish error and never touch the DB.
 *   - The whole call is wrapped in beginTransaction / commit / rollBack.
 *     PurchaseActionHandler::createReceipt joins the open transaction
 *     (its $ownedTransaction logic — see line ~514 in
 *     class-purchaseactionhandler.php) so the GRN number allocation,
 *     header insert, item inserts, inventory ledger writes, and PO
 *     state transition commit atomically.
 *   - The GRN number is auto-allocated by the handler inside the
 *     transaction (we pass null for $documentNumber). After commit we
 *     re-read it from the freshly-written receipt row.
 *
 * Response on success:
 *   {success: true, receipt_id: int, document_number: string,
 *    po_state: 'partially_received'|'received'|other,
 *    po_state_label: 'Częściowo odebrane'|'Odebrane'|other,
 *    short_items: [
 *      {poItemId: int, vendorPartNo: string, partName: string,
 *       unitName: string, ordered: float, received: float,
 *       remaining: float, progressPct: float},
 *      ...
 *    ],
 *    has_short: bool,            // true iff short_items is non-empty
 *    lines_total: int,           // count of PO items
 *    lines_received: int}        // count of PO items fully received after save
 *
 *   - `short_items` lists PO lines that are still genuinely short after
 *     this save (ordered - received_after > 1e-6). Fully received and
 *     over-received lines are excluded. `progressPct` =
 *     round(min(100, received/ordered*100), 1) — or 0 when ordered = 0.
 *     Reads go through PurchaseOrderItemRepository::getByPo(), which
 *     already joins vendor_part / parts / unit (same joins as
 *     receipt-po-get.php lines 118-141) so we don't repeat the SQL.
 *     Floats come back as JSON numbers (not strings).
 *   - `lines_total` / `lines_received` let the UI draw an aggregate
 *     progress bar without re-fetching the PO.
 *
 * Exception handling:
 *   - \LogicException / \InvalidArgumentException from the handler
 *     carry user-actionable messages (validation failures, state
 *     guards, over-delivery caps). They are re-thrown as JSON
 *     {success:false, error: <message>} with HTTP 200 so the JS side
 *     can render via setAlert.
 *   - Any other \Throwable → generic Polish message + error_log of the
 *     real exception. We never leak raw exception text to the client.
 */
use Atte\DB\MsaDB;
use Atte\Utils\Purchase\Order\OrderReceiptRepository;
use Atte\Utils\Purchase\Order\PurchaseActionHandler;
use Atte\Utils\Purchase\Order\PurchaseOrderItemRepository;
use Atte\Utils\Purchase\Order\PurchaseOrderRepository;

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
$comment         = $_POST['comment'] ?? null;
$vendorDocNumber = $_POST['vendor_document_number'] ?? null;
$receivedAt      = $_POST['received_at'] ?? null;

if ($comment !== null) {
    $comment = trim((string)$comment);
    if ($comment === '') $comment = null;
}
if ($vendorDocNumber !== null) {
    $vendorDocNumber = trim((string)$vendorDocNumber);
    if ($vendorDocNumber === '') $vendorDocNumber = null;
}
if ($receivedAt !== null) {
    $receivedAt = trim((string)$receivedAt);
    if ($receivedAt === '') $receivedAt = null;
}
// Normalise 'Y-m-d' → 'Y-m-d 00:00:00'. The handler validates strictly
// against 'Y-m-d H:i:s', so the endpoint owns this conversion. If the
// string already contains a time component we leave it alone and let
// the handler's stricter validator complain if it's malformed.
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
if (!isset($_POST['items']) || !is_array($_POST['items'])) {
    echo json_encode(['success' => false, 'error' => 'Brak pozycji przyjęcia.']);
    exit;
}

// Defensive: skip rows with blank / 0 / non-numeric quantity_received.
// The UI sends only filled lines, but a stray empty <input> in the form
// would still surface as '' in $_POST — silently dropping those keeps
// the handler's invariants intact (createReceipt throws on qty <= 0).
$items = [];
foreach ($_POST['items'] as $row) {
    if (!is_array($row)) continue;
    $raw = $row['quantity_received'] ?? null;
    if ($raw === null || $raw === '' || (is_string($raw) && in_array(trim($raw), ['', '0', '0.0', '0.00'], true))) {
        continue;
    }
    $qty = str_replace(',', '.', (string)$raw);
    if (!is_numeric($qty) || (float)$qty <= 0) continue;

    $poItemId = (int)($row['po_item_id'] ?? 0);
    $subMag   = (int)($row['sub_magazine_id'] ?? 0);
    if ($poItemId <= 0 || $subMag <= 0) continue;

    $rowComment = $row['comment'] ?? null;
    if ($rowComment !== null) {
        $rowComment = trim((string)$rowComment);
        if ($rowComment === '') $rowComment = null;
    }

    $items[] = [
        'po_item_id'        => $poItemId,
        'quantity_received' => (float)$qty,
        'sub_magazine_id'   => $subMag,
        'comment'           => $rowComment,
    ];
}
if (count($items) === 0) {
    echo json_encode(['success' => false, 'error' => 'Nie wprowadzono żadnej przyjmowanej ilości.']);
    exit;
}

// Prepend the vendor's delivery-note number to the receipt comment on its
// own line (when supplied). Lands in purchase__order_receipt.comment
// (line-level comments stay separate on purchase__order_receipt_item).
if ($vendorDocNumber !== null) {
    $prefix = 'Nr dok. dostawcy: ' . $vendorDocNumber;
    $comment = $comment === null ? $prefix : ($prefix . "\n" . $comment);
}

$MsaDB   = MsaDB::getInstance();
$handler = new PurchaseActionHandler($MsaDB);

// Polish labels for the post-write PO state — the JS side renders the
// state label inside the modal so the operator sees the result.
$poStateLabels = [
    'partially_received' => 'Częściowo odebrane',
    'received'           => 'Odebrane',
];

try {
    $MsaDB->db->beginTransaction();
    // createReceipt joins the open transaction, allocates the GRN number
    // inside it, writes header + items + inventory ledger rows, and
    // transitions the PO state. Returns the new receipt id.
    $receiptId = $handler->createReceipt(
        $poId,
        $userId,
        $items,
        null,         // documentNumber — null triggers auto-allocation
        $comment,
        $receivedAt
    );
    $MsaDB->db->commit();

    // Re-read the auto-allocated GRN number + post-write PO state so the
    // client gets a snapshot of what was actually persisted (the
    // handler returns just the receipt id).
    $receiptRepo = new OrderReceiptRepository($MsaDB);
    $poRepo      = new PurchaseOrderRepository($MsaDB);
    $poItemRepo  = new PurchaseOrderItemRepository($MsaDB);
    $receipt     = $receiptRepo->getById($receiptId);
    $po          = $poRepo->getById($poId);

    $poState       = $po !== null ? $po->state : null;
    $poStateLabel  = $poState !== null && isset($poStateLabels[$poState])
        ? $poStateLabels[$poState]
        : ($poState ?? '');

    // Per-line post-save snapshot for the UI's "short items" hint:
    //   - lines that are STILL short after this save (ordered - received > epsilon)
    //     are returned in `short_items` with their vendor / unit labels and
    //     a progress percentage;
    //   - `lines_total` / `lines_received` cover the aggregate progress bar
    //     so the client doesn't have to re-fetch the PO just to render it.
    // Reading through PurchaseOrderItemRepository keeps the join shape
    // (vendor_part / parts / unit) identical to receipt-po-get.php —
    // we get vendorPartNo / partName / unitName from the same query the
    // GET endpoint uses (see class-purchaseorderitemrepository.php
    // buildSelectJoin() and receipt-po-get.php lines 118-141).
    $shortItems    = [];
    $linesTotal    = 0;
    $linesReceived = 0;
    $epsilon       = 1e-6;
    foreach ($poItemRepo->getByPo($poId) as $pi) {
        $ordered  = (float)$pi->quantity;
        $received = (float)$pi->quantityReceived;
        $remaining = $ordered - $received;

        $linesTotal++;
        if ($received + $epsilon >= $ordered) {
            // Fully received (or over-received) — counts as "done" for
            // the aggregate progress bar.
            $linesReceived++;
        }

        if ($ordered - $received > $epsilon) {
            // Genuinely short — include in the UI hint. progressPct
            // is capped at 100 (over-received lines never make it here,
            // but the cap is defensive) and rounded to 1 decimal.
            $progressPct = $ordered > 0
                ? round(min(100, $received / $ordered * 100), 1)
                : 0.0;
            $shortItems[] = [
                'poItemId'     => (int)$pi->id,
                'vendorPartNo' => $pi->vendorPartNo ?? '',
                'partName'     => $pi->partName ?? '',
                'unitName'     => $pi->unitName ?? '',
                'ordered'      => $ordered,
                'received'     => $received,
                'remaining'    => $remaining,
                'progressPct'  => $progressPct,
            ];
        }
    }

    echo json_encode([
        'success'         => true,
        'receipt_id'      => $receiptId,
        'document_number' => $receipt !== null ? $receipt->documentNumber : null,
        'po_state'        => $poState,
        'po_state_label'  => $poStateLabel,
        'short_items'     => $shortItems,
        'has_short'       => count($shortItems) > 0,
        'lines_total'     => $linesTotal,
        'lines_received'  => $linesReceived,
    ]);
} catch (\LogicException | \InvalidArgumentException $e) {
    // Handler-thrown validation errors carry user-actionable messages.
    if ($MsaDB->db->inTransaction()) { $MsaDB->db->rollBack(); }
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (\Throwable $e) {
    if ($MsaDB->db->inTransaction()) { $MsaDB->db->rollBack(); }
    error_log('receipt-create error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Błąd zapisu przyjęcia.']);
}
exit;
