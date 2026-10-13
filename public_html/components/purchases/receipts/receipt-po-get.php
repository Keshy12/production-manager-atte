<?php
/**
 * AJAX: load a single PO for the goods-receipt form (header + lines).
 *
 * GET param: po_id (int, required).
 *
 * Guard: PO must exist AND be in state 'confirmed'|'partially_received'
 * |'in_progress'. Otherwise return
 *   {success: false, error: 'Zamówienie nie jest w stanie
 *    pozwalającym na przyjęcie towaru.'}
 * with HTTP 200 (no 4xx — the JS side owns the user-visible alert).
 *
 * 'in_progress' is the logistics state set by receipt-accept.php when
 * the operator clicks "Akceptuję" without items yet. Including it in
 * the guard means the operator can re-open the form, see their
 * accepted header metadata prefilled, and continue with items.
 *
 * Response:
 *   {success: true,
 *    po: {poId, poNumber, vendorPoNumber, vendorName, state,
 *         expectedDeliveryDate, comment},
 *    items: [{poItemId, vendorPartId, vendorPartNo, producerPartNo,
 *             partName, partDescription, vendorPartComment, producerName,
 *             unitId, unitName,
 *             quantityOrdered, quantityReceived, quantityRemaining,
 *             maxAllowed, pickedPackSize, packQuantities, comment}, ...],
 *    defaultSubMagazineId: int|null,
 *    lastReceipt: {lastReceiptId, documentNumber, vendorDocumentNumber,
 *                  receivedAt, comment, lastSubMagazineId}|null}
 *
 * - All PO items are returned, including fully-received lines (frontend
 *   greys them out — the spec wants this so the operator can see the
 *   delivery complete in one glance).
 * - `lastReceipt` describes the MOST RECENT receipt (real or draft)
 *   already booked against this PO, or null when there is none. The
 *   receive form uses it to pre-fill and auto-collapse step 1
 *   ("Dokument przyjęcia") for a follow-up partial delivery or for
 *   resuming an in_progress PO. `vendorDocumentNumber` is parsed back
 *   out of the stored comment, where receipt-create.php line ~154 (and
 *   acceptReceiptMetadata in PurchaseActionHandler) prepends it as
 *   "Nr dok. dostawcy: <nr>\n<reszta komentarza>"; `comment` is the
 *   remainder with that prefix line stripped (null when nothing left).
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
 *   it to pre-select the receive-into magazine on every line. Note:
 *   drafts have no items, so the most recent magazine the operator
 *   selected is NOT persisted on the draft; the form falls back to
 *   this default when reopening an in_progress PO.
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
    if (!in_array($hdr['state'], ['confirmed','partially_received','in_progress'], true)) {
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
                vp.comment          AS vendorPartComment,
                lp.name             AS partName,
                lp.description      AS partDescription,
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
            // Opis urządzenia z list__parts — zawsze pod nazwą części w
            // formularzu przyjęcia, jeśli niepusty.
            'partDescription'   => $r['partDescription'],
            // Uwagi do artykułu u dostawcy z list__vendor_part.comment
            // ("Uwagi do urządzenia") — wizualnie odróżnialne od uwag
            // do pozycji PO (item.comment, ten już renderowany).
            'vendorPartComment' => $r['vendorPartComment'],
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

    // 6. Last receipt already booked against this PO (null when none).
    //
    // Two sources of "last receipt metadata" for prefill:
    //   - DRAFT (purchase__order_receipt with zero items): created when
    //     the operator clicked "Akceptuję" via receipt-accept.php. For
    //     an in_progress PO this is the only thing in the table — it
    //     carries the metadata they last accepted, so re-opening the
    //     form repaints their work.
    //   - REAL receipt (at least one item): created when items were
    //     finally booked via receipt-create.php. For partially_received
    //     this is the most recent save.
    //
    // Preference order: drafts win over real receipts. Rationale: the
    // draft represents "the operator's currently-accepted metadata" —
    // if they typed something, hit Akceptuję, then walked away, the
    // draft is what they intended. A real receipt is a *historical*
    // save; reusing it for prefill would silently override anything the
    // operator typed after that save. (For partially_received this
    // almost never matters — there's normally only one receipt, and
    // by the time you re-open the form the operator's intent IS that
    // receipt — but the rule stays simple and corner-case-safe.)
    //
    // Two queries: first try a draft, fall back to any receipt. Each is
    // indexed (FK on po_id, the LEFT JOIN is the same shape as the
    // GET handler in receipt-get.php uses).
    //
    // ORDER BY id DESC: drafts share a received_at only when the
    // operator hits Akceptuję twice within the same second, which is
    // unusual but possible — id breaks the tie towards the newest.
    $lastReceipt = null;
    $rcpStmt = $MsaDB->db->prepare(
        "SELECT r.id              AS lastReceiptId,
                r.document_number AS documentNumber,
                r.received_at     AS receivedAt,
                r.comment         AS comment
           FROM `purchase__order_receipt` r
           LEFT JOIN `purchase__order_receipt_item` ri ON ri.receipt_id = r.id
          WHERE r.po_id = ? AND ri.id IS NULL
          ORDER BY r.id DESC
          LIMIT 1"
    );
    $rcpStmt->execute([$poId]);
    $rcpRow = $rcpStmt->fetch(\PDO::FETCH_ASSOC);
    if ($rcpRow === false) {
        // Fallback: any receipt (real, may have items).
        $rcpStmt = $MsaDB->db->prepare(
            "SELECT r.id              AS lastReceiptId,
                    r.document_number AS documentNumber,
                    r.received_at     AS receivedAt,
                    r.comment         AS comment
               FROM `purchase__order_receipt` r
              WHERE r.po_id = ?
              ORDER BY r.received_at DESC, r.id DESC
              LIMIT 1"
        );
        $rcpStmt->execute([$poId]);
        $rcpRow = $rcpStmt->fetch(\PDO::FETCH_ASSOC);
    }

    if ($rcpRow !== false) {
        // Split "Nr dok. dostawcy: <nr>\n<reszta>" back into its two parts.
        // No prefix → vendorDocumentNumber stays null and the whole comment
        // is passed through untouched.
        $vendorDocNumber = null;
        $rawComment      = $rcpRow['comment'];
        $restComment     = $rawComment;
        $prefix          = 'Nr dok. dostawcy: ';

        if ($rawComment !== null && strncmp($rawComment, $prefix, strlen($prefix)) === 0) {
            $afterPrefix = substr($rawComment, strlen($prefix));
            $nlPos       = strpos($afterPrefix, "\n");
            if ($nlPos === false) {
                // Delivery-note number only — no comment body was supplied.
                $vendorDocNumber = rtrim($afterPrefix, "\r");
                $restComment     = null;
            } else {
                $vendorDocNumber = rtrim(substr($afterPrefix, 0, $nlPos), "\r");
                $restComment     = substr($afterPrefix, $nlPos + 1);
            }
            if ($vendorDocNumber === '')                          $vendorDocNumber = null;
            if ($restComment !== null && trim($restComment) === '') $restComment = null;
        }

        // Most-used receive-into magazine of that receipt — pre-selects the
        // header picker so a follow-up delivery lands where the first one did.
        $magRow = $MsaDB->db->prepare(
            "SELECT `sub_magazine_id`
               FROM `purchase__order_receipt_item`
              WHERE `receipt_id` = ?
              GROUP BY `sub_magazine_id`
              ORDER BY COUNT(*) DESC, `sub_magazine_id` ASC
              LIMIT 1"
        );
        $magRow->execute([(int)$rcpRow['lastReceiptId']]);
        $lastSubMagazineId = $magRow->fetchColumn();

        $lastReceipt = [
            'lastReceiptId'        => (int)$rcpRow['lastReceiptId'],
            'documentNumber'       => $rcpRow['documentNumber'],
            'vendorDocumentNumber' => $vendorDocNumber,
            'receivedAt'           => $rcpRow['receivedAt'],
            'comment'              => $restComment,
            'lastSubMagazineId'    => $lastSubMagazineId === false ? null : (int)$lastSubMagazineId,
        ];
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
        'lastReceipt' => $lastReceipt,
    ]);
} catch (\Throwable $e) {
    error_log('receipt-po-get error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Błąd ładowania zamówienia.']);
}
exit;
