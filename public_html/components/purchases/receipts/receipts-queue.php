<?php
/**
 * AJAX: list POs awaiting goods (state confirmed or partially_received).
 *
 * GET (no params).
 *
 * Powers the goods-receiving queue on /purchase/receipts. The previous
 * listing was driven from the receipts table (i.e. "POs that have at
 * least one receipt") which made the page useless for the very first
 * thing the operator does: see what is about to arrive. This endpoint
 * instead lists POs in the two receive-friendly states, with a per-PO
 * aggregate of how much is still outstanding.
 *
 * Response:
 *   {success: true, pos: [
 *     {poId, poNumber, vendorPoNumber, vendorName, state,
 *      expectedDeliveryDate, lineCount, linesFullyReceived,
 *      orderedQty, receivedQty, remainingQty, daysOverdue}, ...
 *   ]}
 *
 * Order: expected_delivery_date ASC with NULLs last, then po.id DESC.
 * `(expr IS NULL) ASC` is the standard MySQL trick to push NULLs last
 * without resorting to a UNION.
 */
use Atte\DB\MsaDB;

header('Content-Type: application/json; charset=utf-8');

if(!isset($_SESSION['isAdmin']) || $_SESSION['isAdmin'] !== true) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Brak uprawnień']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Metoda nieobsługiwana.']);
    exit();
}

try {
    $MsaDB = MsaDB::getInstance();
    // One grouped query — joins PO → vendor and aggregates over items.
    // The 1e-6 epsilon in linesFullyReceived mirrors the handler's
    // $epsilon tolerance (PurchaseActionHandler::createReceipt line ~447)
    // so what the UI counts as "fully received" matches what the handler
    // would consider fully received for state-transition purposes.
    $sql = "
        SELECT
            po.id                       AS poId,
            po.po_number                AS poNumber,
            po.vendor_po_number         AS vendorPoNumber,
            po.state,
            po.expected_delivery_date   AS expectedDeliveryDate,
            v.name                      AS vendorName,
            COUNT(poi.id)                                                            AS lineCount,
            SUM(CASE WHEN poi.quantity_received + 1e-6 >= poi.quantity THEN 1 ELSE 0 END) AS linesFullyReceived,
            COALESCE(SUM(poi.quantity), 0)                                            AS orderedQty,
            COALESCE(SUM(poi.quantity_received), 0)                                  AS receivedQty,
            DATEDIFF(CURDATE(), po.expected_delivery_date)                            AS daysOverdue
        FROM `purchase__order` po
        JOIN `list__vendor` v ON po.vendor_id = v.id
        LEFT JOIN `purchase__order_item` poi ON poi.po_id = po.id
        WHERE po.state IN ('sent','confirmed','partially_received')
        GROUP BY po.id
        ORDER BY (po.expected_delivery_date IS NULL) ASC,
                 po.expected_delivery_date ASC,
                 po.id DESC
    ";
    $stmt = $MsaDB->db->prepare($sql);
    $stmt->execute();
    $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

    $pos = [];
    foreach ($rows as $r) {
        $ordered  = (float)$r['orderedQty'];
        $received = (float)$r['receivedQty'];
        $state    = (string)$r['state'];
        $pos[] = [
            'poId'                 => (int)$r['poId'],
            'poNumber'             => $r['poNumber'],
            'vendorPoNumber'       => $r['vendorPoNumber'],
            'vendorName'           => $r['vendorName'],
            'state'                => $state,
            // Akcja „Przyjmij towar" dostępna tylko dla confirmed i
            // partially_received (handlery createReceipt / sendPo też
            // blokują stan sent). sent = czekamy na potwierdzenie.
            'canReceive'           => in_array($state, ['confirmed','partially_received'], true),
            'expectedDeliveryDate' => $r['expectedDeliveryDate'],
            'lineCount'            => (int)$r['lineCount'],
            'linesFullyReceived'   => (int)$r['linesFullyReceived'],
            'orderedQty'           => $ordered,
            'receivedQty'          => $received,
            // PHP-side max(): do NOT recompute stock anywhere else
            // (triggers own stock totals), this is just UI arithmetic.
            'remainingQty'         => max(0.0, $ordered - $received),
            // DATEDIFF returns NULL when expected_delivery_date is NULL —
            // expose null as-is so the frontend can render "—".
            'daysOverdue'          => $r['daysOverdue'] !== null ? (int)$r['daysOverdue'] : null,
        ];
    }

    echo json_encode(['success' => true, 'pos' => $pos]);
} catch (\Throwable $e) {
    error_log('receipts-queue error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Błąd ładowania listy zamówień oczekujących na przyjęcie.']);
}
exit;
