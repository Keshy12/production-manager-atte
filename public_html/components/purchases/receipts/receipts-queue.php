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
 *      orderedQty, receivedQty, remainingQty, daysOverdue,
 *      openLines, lateLines, dueTodayLines, dueWeekLines, undatedLines,
 *      rowDatedLines, earliestOpenDue, latestOpenDue, nextDueDate,
 *      openDueDistinct,
 *      openItems: [{itemId, vendorPartNo, producerPartNo, partName, unit,
 *                   quantity, quantityReceived, remainingQty, dueDate,
 *                   daysLate (>0 late, 0 today, <0 until due, null no date),
 *                   hasRowDate}, ...]   — all open rows, due ASC,
 *      lateItems: same shape, subset with daysLate > 0}, ...
 *   ]}
 *
 * All due-date fields use the per-row date with header fallback:
 * COALESCE(poi.expected_delivery_date, po.expected_delivery_date), and
 * count only open (not fully received) rows. daysOverdue is measured
 * from earliestOpenDue (>0 late, 0 today, <0 days until due).
 *
 * Order: earliestOpenDue ASC with NULLs last, then po.id DESC.
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
    //
    // 'in_progress' is included in the state filter so a PO that the
    // operator has accepted (Akceptuję clicked → receipt header
    // metadata persisted) but hasn't booked items for yet still shows
    // up in the queue. canReceive below mirrors the same predicate so
    // the action column is consistent with the row visibility.
    //
    // Terminy per pozycja: odpowiedź dostawcy (respond / e-mail) zapisuje
    // termin na każdym wierszu (`purchase__order_item.expected_delivery_date`).
    // Termin efektywny wiersza = COALESCE(termin wiersza, termin nagłówka PO)
    // — ta sama reguła co DeliveryMatcher::openItems(). „Po terminie" liczymy
    // wyłącznie dla OTWARTYCH wierszy (nieprzyjętych w całości), więc PO
    // z jedną spóźnioną pozycją nie jest w całości „spóźnione", a w pełni
    // przyjęta pozycja z minionym terminem nie jest spóźniona wcale.
    $due  = "COALESCE(poi.expected_delivery_date, po.expected_delivery_date)";
    $open = "(poi.id IS NOT NULL AND poi.quantity_received + 1e-6 < poi.quantity)";
    $sql = "
        SELECT
            po.id                       AS poId,
            po.po_number                AS poNumber,
            po.vendor_po_number         AS vendorPoNumber,
            po.state,
            po.expected_delivery_date   AS expectedDeliveryDate,
            po.vendor_id                AS vendorId,
            v.name                      AS vendorName,
            COUNT(poi.id)                                                            AS lineCount,
            SUM(CASE WHEN poi.quantity_received + 1e-6 >= poi.quantity THEN 1 ELSE 0 END) AS linesFullyReceived,
            COALESCE(SUM(poi.quantity), 0)                                            AS orderedQty,
            COALESCE(SUM(poi.quantity_received), 0)                                  AS receivedQty,
            SUM(CASE WHEN {$open} AND {$due} < CURDATE() THEN 1 ELSE 0 END)           AS lateLines,
            SUM(CASE WHEN {$open} AND {$due} = CURDATE() THEN 1 ELSE 0 END)           AS dueTodayLines,
            SUM(CASE WHEN {$open} AND {$due} > CURDATE()
                      AND {$due} <= DATE_ADD(CURDATE(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) AS dueWeekLines,
            SUM(CASE WHEN {$open} AND {$due} IS NULL THEN 1 ELSE 0 END)               AS undatedLines,
            SUM(CASE WHEN poi.expected_delivery_date IS NOT NULL THEN 1 ELSE 0 END)   AS rowDatedLines,
            MIN(CASE WHEN {$open} THEN {$due} END)                                    AS earliestOpenDue,
            MAX(CASE WHEN {$open} THEN {$due} END)                                    AS latestOpenDue,
            MIN(CASE WHEN {$open} AND {$due} >= CURDATE() THEN {$due} END)            AS nextDueDate,
            COUNT(DISTINCT CASE WHEN {$open} THEN {$due} END)                         AS openDueDistinct,
            DATEDIFF(CURDATE(), MIN(CASE WHEN {$open} THEN {$due} END))               AS daysOverdue
        FROM `purchase__order` po
        JOIN `list__vendor` v ON po.vendor_id = v.id
        LEFT JOIN `purchase__order_item` poi ON poi.po_id = po.id
        WHERE po.state IN ('sent','confirmed','partially_received','in_progress')
        GROUP BY po.id
        ORDER BY (MIN(CASE WHEN {$open} THEN {$due} END) IS NULL) ASC,
                 MIN(CASE WHEN {$open} THEN {$due} END) ASC,
                 po.id DESC
    ";
    $stmt = $MsaDB->db->prepare($sql);
    $stmt->execute();
    $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

    // Wszystkie OTWARTE pozycje (nieprzyjęte w całości) PO z kolejki —
    // widok „pozycja po pozycji" w karcie dostawcy. lateItems (podzbiór
    // z daysLate > 0) zostaje dla zgodności wstecz.
    // Jednostka = jednostka pozycji PO (i.quantity_unit_id), jak w
    // delivery_open_items().
    $itemsSql = "
        SELECT
            poi.po_id                                   AS poId,
            poi.id                                      AS itemId,
            vp.vendor_part_no                           AS vendorPartNo,
            vp.producer_part_no                         AS producerPartNo,
            lp.name                                     AS partName,
            u.name                                      AS unit,
            poi.quantity                                AS quantity,
            poi.quantity_received                       AS quantityReceived,
            {$due}                                      AS dueDate,
            DATEDIFF(CURDATE(), {$due})                 AS daysLate,
            (poi.quantity - poi.quantity_received)      AS remainingQty,
            (poi.expected_delivery_date IS NOT NULL)    AS hasRowDate
        FROM `purchase__order_item` poi
        JOIN `purchase__order` po        ON poi.po_id = po.id
        LEFT JOIN `list__vendor_part` vp ON poi.vendor_part_id = vp.id
        LEFT JOIN `list__parts` lp       ON vp.parts_id = lp.id
        LEFT JOIN `part__unit` u         ON poi.quantity_unit_id = u.id
        WHERE po.state IN ('sent','confirmed','partially_received','in_progress')
          AND {$open}
        ORDER BY ({$due} IS NULL) ASC, {$due} ASC, poi.po_id ASC, poi.id ASC
    ";
    $itemsStmt = $MsaDB->db->prepare($itemsSql);
    $itemsStmt->execute();
    $itemsByPo = [];
    $lateByPo  = [];
    foreach ($itemsStmt->fetchAll(\PDO::FETCH_ASSOC) as $li) {
        $poKey    = (int)$li['poId'];
        $daysLate = $li['daysLate'] !== null ? (int)$li['daysLate'] : null;
        $item = [
            'itemId'           => (int)$li['itemId'],
            'vendorPartNo'     => $li['vendorPartNo'],
            'producerPartNo'   => $li['producerPartNo'],
            'partName'         => $li['partName'],
            'unit'             => $li['unit'],
            'quantity'         => (float)$li['quantity'],
            'quantityReceived' => (float)$li['quantityReceived'],
            'remainingQty'     => max(0.0, (float)$li['remainingQty']),
            'dueDate'          => $li['dueDate'],
            // >0 = dni po terminie, 0 = dziś, <0 = dni do terminu, null = brak terminu
            'daysLate'         => $daysLate,
            'hasRowDate'       => (bool)$li['hasRowDate'],
        ];
        $itemsByPo[$poKey][] = $item;
        if ($daysLate !== null && $daysLate > 0) {
            $lateByPo[$poKey][] = $item;
        }
    }

    $pos = [];
    foreach ($rows as $r) {
        $ordered  = (float)$r['orderedQty'];
        $received = (float)$r['receivedQty'];
        $state    = (string)$r['state'];
        $pos[] = [
            'poId'                 => (int)$r['poId'],
            'poNumber'             => $r['poNumber'],
            'vendorPoNumber'       => $r['vendorPoNumber'],
            'vendorId'             => (int)$r['vendorId'],
            'vendorName'           => $r['vendorName'],
            'state'                => $state,
            // Akcja „Przyjmij towar" dostępna dla confirmed, partially_received
            // i in_progress (handler acceptReceiptMetadata/createReceipt
            // blokują stan sent). sent = czekamy na potwierdzenie dostawcy.
            // 'in_progress' = operator zaakceptował nagłówek GRN (Akceptuję),
            // ale nie wprowadził jeszcze pozycji — naturalny kolejny krok
            // to Zapisz z ilościami.
            'canReceive'           => in_array($state, ['confirmed','partially_received','in_progress'], true),
            'expectedDeliveryDate' => $r['expectedDeliveryDate'],
            'lineCount'            => (int)$r['lineCount'],
            'linesFullyReceived'   => (int)$r['linesFullyReceived'],
            'orderedQty'           => $ordered,
            'receivedQty'          => $received,
            // PHP-side max(): do NOT recompute stock anywhere else
            // (triggers own stock totals), this is just UI arithmetic.
            'remainingQty'         => max(0.0, $ordered - $received),
            // daysOverdue liczony od NAJWCZEŚNIEJSZEGO terminu otwartej
            // pozycji (per wiersz, fallback na nagłówek): >0 = tyle dni
            // spóźnienia najstarszej zaległej pozycji, <=0 = dni do
            // najbliższego terminu. null = brak otwartych pozycji z terminem.
            'daysOverdue'          => $r['daysOverdue'] !== null ? (int)$r['daysOverdue'] : null,
            'openLines'            => max(0, (int)$r['lineCount'] - (int)$r['linesFullyReceived']),
            'lateLines'            => (int)$r['lateLines'],
            'dueTodayLines'        => (int)$r['dueTodayLines'],
            'dueWeekLines'         => (int)$r['dueWeekLines'],
            'undatedLines'         => (int)$r['undatedLines'],
            'rowDatedLines'        => (int)$r['rowDatedLines'],
            'earliestOpenDue'      => $r['earliestOpenDue'],
            'latestOpenDue'        => $r['latestOpenDue'],
            'nextDueDate'          => $r['nextDueDate'],
            'openDueDistinct'      => (int)$r['openDueDistinct'],
            'lateItems'            => $lateByPo[(int)$r['poId']] ?? [],
            'openItems'            => $itemsByPo[(int)$r['poId']] ?? [],
        ];
    }

    echo json_encode(['success' => true, 'pos' => $pos]);
} catch (\Throwable $e) {
    error_log('receipts-queue error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Błąd ładowania listy zamówień oczekujących na przyjęcie.']);
}
exit;
