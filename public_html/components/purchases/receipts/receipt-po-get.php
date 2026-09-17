<?php
/**
 * AJAX: load a single PO for the goods-receipt form (header + lines).
 *
 * GET param: po_id (int, required).
 *
 * Guard: PO must exist AND be in state 'confirmed'|'partially_received'.
 * Otherwise return
 *   {success: false, error: 'Zamówienie nie jest w stanie
 *    pozwalającym na przyjęcie towaru.'}
 * with HTTP 200 (no 4xx — the JS side owns the user-visible alert).
 *
 * Response:
 *   {success: true,
 *    po: {poId, poNumber, vendorPoNumber, vendorName, state,
 *         expectedDeliveryDate, comment},
 *    items: [{poItemId, vendorPartId, vendorPartNo, producerPartNo,
 *             partName, producerName, unitId, unitName,
 *             quantityOrdered, quantityReceived, quantityRemaining,
 *             maxAllowed, pickedPackSize, packQuantities, comment}, ...],
 *    defaultSubMagazineId: int|null}
 *
 * - All PO items are returned, including fully-received lines (frontend
 *   greys them out — the spec wants this so the operator can see the
 *   delivery complete in one glance).
 * - `maxAllowed` = round(quantityRemaining * 1.10, 4) — mirrors the
 *   handler's cap at PurchaseActionHandler::createReceipt line ~485.
 * - `packQuantities` is the full ascending list of pack sizes for the
 *   vendor part, fetched in ONE GROUP_CONCAT query for all line items
 *   (same pattern as public_html/components/purchases/cart/
 *   vendor-part-search.php lines 78-95).
 * - `defaultSubMagazineId` is the FIXED default receive-into magazine
 *   (see DEFAULT_RECEIPT_SUB_MAGAZINE_ID below) when that row exists
 *   and is active. Otherwise the lowest active sub_magazine_id, or
 *   null when there are no active magazines at all. The frontend uses
 *   it to pre-select the receive-into magazine on every line.
 */

/**
 * Designated goods-receiving warehouse. Hard-coded by physical layout
 * (this is the dock where deliveries arrive) rather than read off the
 * operator's `user.sub_magazine_id` — the receiving location is the
 * same for every operator. Change here to relocate the default.
 */
const DEFAULT_RECEIPT_SUB_MAGAZINE_ID = 27;

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

$poId = (int)($_POST['po_id'] ?? 0);
if ($poId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Nieprawidłowe ID zamówienia.']);
    exit;
}

try {
    $MsaDB = MsaDB::getInstance();

    // 1. Header + vendor name in one query.
    $hdrStmt = $MsaDB->db->prepare(
        "SELECT po.id              AS poId,
                po.po_number       AS poNumber,
                po.vendor_po_number AS vendorPoNumber,
                po.state,
                po.expected_delivery_date AS expectedDeliveryDate,
                po.comment,
                v.name             AS vendorName
           FROM `purchase__order` po
           LEFT JOIN `list__vendor` v ON po.vendor_id = v.id
          WHERE po.id = ?"
    );
    $hdrStmt->execute([$poId]);
    $hdr = $hdrStmt->fetch(\PDO::FETCH_ASSOC);
    if ($hdr === false) {
        echo json_encode(['success' => false, 'error' => 'Zamówienie nie zostało znalezione.']);
        exit;
    }
    if (!in_array($hdr['state'], ['confirmed','partially_received'], true)) {
        echo json_encode(['success' => false, 'error' => 'Zamówienie nie jest w stanie pozwalającym na przyjęcie towaru.']);
        exit;
    }

    // 2. All PO items + their vendor-part / producer / unit labels.
    //    Includes fully-received lines on purpose — frontend greys them.
    $itStmt = $MsaDB->db->prepare(
        "SELECT poi.id              AS poItemId,
                poi.vendor_part_id  AS vendorPartId,
                vp.vendor_part_no   AS vendorPartNo,
                vp.producer_part_no AS producerPartNo,
                lp.name             AS partName,
                pr.name             AS producerName,
                poi.quantity_unit_id AS unitId,
                u.name              AS unitName,
                poi.quantity        AS quantityOrdered,
                poi.quantity_received AS quantityReceived,
                poi.picked_pack_size  AS pickedPackSize,
                poi.comment
           FROM `purchase__order_item` poi
           JOIN `list__vendor_part` vp ON poi.vendor_part_id = vp.id
           LEFT JOIN `list__parts` lp   ON vp.parts_id    = lp.id
           LEFT JOIN `list__producer` pr ON vp.producer_id = pr.id
           JOIN `part__unit` u          ON poi.quantity_unit_id = u.id
          WHERE poi.po_id = ?
          ORDER BY poi.id ASC"
    );
    $itStmt->execute([$poId]);
    $itemRows = $itStmt->fetchAll(\PDO::FETCH_ASSOC);

    // 3. Pack sizes for all vendor_part_ids in a single GROUP_CONCAT
    //    query (one round-trip vs N round-trips). Mirrors
    //    public_html/components/purchases/cart/vendor-part-search.php
    //    lines 78-95. The placeholder list is built from the rows we
    //    already have so we don't issue a second query against MySQL
    //    just to count ids.
    $vpIds = array_values(array_unique(array_map(fn($r) => (int)$r['vendorPartId'], $itemRows)));
    $packsByVpId = [];
    if (!empty($vpIds)) {
        $placeholders = implode(',', array_fill(0, count($vpIds), '?'));
        $packStmt = $MsaDB->db->prepare(
            "SELECT vendor_part_id,
                    GROUP_CONCAT(full_pack_quantity ORDER BY full_pack_quantity ASC) AS packs_csv
               FROM `list__vendor_part_pack`
              WHERE vendor_part_id IN ($placeholders)
              GROUP BY vendor_part_id"
        );
        $packStmt->execute($vpIds);
        foreach ($packStmt->fetchAll(\PDO::FETCH_ASSOC) as $pr) {
            $packsByVpId[(int)$pr['vendor_part_id']] = $pr['packs_csv'] === null
                ? []
                : array_map('floatval', explode(',', $pr['packs_csv']));
        }
    }

    // 4. Shape items: quantityRemaining + maxAllowed per row.
    $items = [];
    foreach ($itemRows as $r) {
        $ordered   = (float)$r['quantityOrdered'];
        $received  = (float)$r['quantityReceived'];
        $remaining = max(0.0, $ordered - $received);
        $vpId      = (int)$r['vendorPartId'];
        $items[] = [
            'poItemId'          => (int)$r['poItemId'],
            'vendorPartId'      => $vpId,
            'vendorPartNo'      => $r['vendorPartNo'],
            'producerPartNo'    => $r['producerPartNo'],
            'partName'          => $r['partName'],
            'producerName'      => $r['producerName'],
            'unitId'            => (int)$r['unitId'],
            'unitName'          => $r['unitName'],
            'quantityOrdered'   => $ordered,
            'quantityReceived'  => $received,
            'quantityRemaining' => $remaining,
            // Mirrors the handler's `$remaining * 1.10 + $epsilon` cap
            // (rounded for display). The handler still applies its own
            // epsilon on submit, so this is the operator-facing preview.
            'maxAllowed'        => round($remaining * 1.10, 4),
            'pickedPackSize'    => $r['pickedPackSize'] === null ? null : (float)$r['pickedPackSize'],
            'packQuantities'    => $packsByVpId[$vpId] ?? [],
            'comment'           => $r['comment'],
        ];
    }

    // 5. Default sub-magazine = the designated goods-receiving warehouse.
    //    Prefer the hard-coded DEFAULT_RECEIPT_SUB_MAGAZINE_ID (the dock
    //    where deliveries arrive) when that row exists AND isActive = 1.
    //    If 27 is missing or inactive, fall back to the lowest active
    //    sub_magazine_id so the frontend never pre-selects a magazine
    //    the operator can't actually book stock into. null when there
    //    is no active magazine at all — frontend falls back to its
    //    own picker.
    $defaultSubMagazineId = null;
    $magStmt = $MsaDB->db->prepare(
        "SELECT 1 FROM `magazine__list`
          WHERE `sub_magazine_id` = ? AND `isActive` = 1
          LIMIT 1"
    );
    $magStmt->execute([DEFAULT_RECEIPT_SUB_MAGAZINE_ID]);
    if ($magStmt->fetchColumn() !== false) {
        // Designated warehouse exists and is active — use it.
        $defaultSubMagazineId = DEFAULT_RECEIPT_SUB_MAGAZINE_ID;
    } else {
        // Fallback: lowest active sub_magazine_id.
        $fallbackRow = $MsaDB->db->query(
            "SELECT `sub_magazine_id`
               FROM `magazine__list`
              WHERE `isActive` = 1
              ORDER BY `sub_magazine_id` ASC
              LIMIT 1"
        )->fetch(\PDO::FETCH_ASSOC);
        if ($fallbackRow !== false) {
            $defaultSubMagazineId = (int)$fallbackRow['sub_magazine_id'];
        }
    }

    echo json_encode([
        'success' => true,
        'po' => [
            'poId'                 => (int)$hdr['poId'],
            'poNumber'             => $hdr['poNumber'],
            'vendorPoNumber'       => $hdr['vendorPoNumber'],
            'vendorName'           => $hdr['vendorName'],
            'state'                => $hdr['state'],
            'expectedDeliveryDate' => $hdr['expectedDeliveryDate'],
            'comment'              => $hdr['comment'],
        ],
        'items' => $items,
        'defaultSubMagazineId' => $defaultSubMagazineId,
    ]);
} catch (\Throwable $e) {
    error_log('receipt-po-get error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Błąd ładowania zamówienia.']);
}
exit;
