<?php
use Atte\DB\MsaDB;

header('Content-Type: application/json; charset=utf-8');

if(!isset($_SESSION['isAdmin']) || $_SESSION['isAdmin'] !== true) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Brak uprawnień']);
    exit();
}

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Nieprawidłowe ID przyjęcia']);
    exit;
}

try {
    $MsaDB = MsaDB::getInstance();

    // Receipt header.
    $hdrStmt = $MsaDB->db->prepare(
        "SELECT r.id,
                r.po_id AS poId,
                r.document_number AS documentNumber,
                r.received_by AS receivedBy,
                r.received_at AS receivedAt,
                r.comment,
                po.po_number AS poNumber,
                v.name AS vendorName,
                CONCAT(u.name, ' ', u.surname) AS receivedByName
         FROM `purchase__order_receipt` r
         LEFT JOIN `purchase__order` po ON r.po_id = po.id
         LEFT JOIN `list__vendor` v  ON po.vendor_id = v.id
         LEFT JOIN `user` u           ON r.received_by = u.user_id
         WHERE r.id = ?"
    );
    $hdrStmt->execute([$id]);
    $hdr = $hdrStmt->fetch(\PDO::FETCH_ASSOC);
    if ($hdr === false) {
        echo json_encode(['success' => false, 'error' => 'Nie znaleziono przyjęcia']);
        exit;
    }

    // Receipt items, joined for display.
    $itStmt = $MsaDB->db->prepare(
        "SELECT ri.id,
                ri.po_item_id AS poItemId,
                ri.quantity_received AS quantityReceived,
                ri.sub_magazine_id AS subMagazineId,
                ri.comment,
                vp.vendor_part_no AS vendorPartNo,
                lp.name AS partName,
                pr.name AS producerName,
                u.name AS unitName,
                m.sub_magazine_name AS magazineName,
                poi.quantity AS orderedQty,
                poi.quantity_received AS totalReceivedQty
         FROM `purchase__order_receipt_item` ri
         LEFT JOIN `purchase__order_item` poi     ON ri.po_item_id    = poi.id
         LEFT JOIN `list__vendor_part` vp          ON poi.vendor_part_id = vp.id
         LEFT JOIN `list__parts` lp                ON vp.parts_id        = lp.id
         LEFT JOIN `list__producer` pr             ON vp.producer_id     = pr.id
         LEFT JOIN `part__unit` u                  ON poi.quantity_unit_id = u.id
         LEFT JOIN `magazine__list` m              ON ri.sub_magazine_id = m.sub_magazine_id
         WHERE ri.receipt_id = ?
         ORDER BY ri.id ASC"
    );
    $itStmt->execute([$id]);
    $items = $itStmt->fetchAll(\PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'receipt' => [
            'id'              => (int)$hdr['id'],
            'poId'            => (int)$hdr['poId'],
            'poNumber'        => $hdr['poNumber'],
            'vendorName'      => $hdr['vendorName'],
            'documentNumber'  => $hdr['documentNumber'],
            'receivedBy'      => (int)$hdr['receivedBy'],
            'receivedByName'  => $hdr['receivedByName'],
            'receivedAt'      => $hdr['receivedAt'],
            'comment'         => $hdr['comment'],
        ],
        'items'   => array_map(fn($i) => [
            'id'                  => (int)$i['id'],
            'poItemId'            => (int)$i['poItemId'],
            'vendorPartNo'        => $i['vendorPartNo'],
            'partName'            => $i['partName'],
            'producerName'        => $i['producerName'],
            'unitName'            => $i['unitName'],
            'quantityReceived'    => (float)$i['quantityReceived'],
            'subMagazineId'       => (int)$i['subMagazineId'],
            'magazineName'        => $i['magazineName'],
            'orderedQty'          => (float)$i['orderedQty'],
            'totalReceivedQty'    => (float)$i['totalReceivedQty'],
            'comment'             => $i['comment'],
        ], $items),
    ]);
} catch (\Throwable $e) {
    error_log('receipt-get error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Wystąpił błąd podczas ładowania przyjęcia']);
}
exit;
