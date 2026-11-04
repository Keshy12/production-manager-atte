<?php
/**
 * AJAX (POST): add or edit a line of a draft delivery.
 *
 * POST: vendor_part_id, quantity (required; ',' accepted);
 *   unit_price?, sub_magazine_id?, comment?;
 *   line_id? — present (> 0) → update that line, else add to delivery_id
 *   (delivery_id required when adding).
 * Response: {success:true, state:<getDeliveryState()>}
 */
require_once __DIR__ . '/delivery-common.php';

delivery_guard_user('POST');

$lineId       = delivery_int($_POST['line_id'] ?? 0);
$deliveryId   = delivery_int($_POST['delivery_id'] ?? 0);
$vendorPartId = delivery_int($_POST['vendor_part_id'] ?? 0);
$quantity     = delivery_str($_POST['quantity'] ?? null);
$unitPrice    = delivery_str($_POST['unit_price'] ?? null);
$subMagazine  = delivery_int($_POST['sub_magazine_id'] ?? 0);
$comment      = delivery_str($_POST['comment'] ?? null);

if ($lineId <= 0 && $deliveryId <= 0) {
    delivery_json(['success' => false, 'error' => 'Nieprawidłowe ID dostawy.']);
}
if ($vendorPartId <= 0) {
    delivery_json(['success' => false, 'error' => 'Nie wybrano artykułu.']);
}
if ($quantity === null) {
    delivery_json(['success' => false, 'error' => 'Ilość: wartość jest wymagana.']);
}
$subMagazineId = $subMagazine > 0 ? $subMagazine : null;

delivery_run(function ($MsaDB, $handler) use ($lineId, $deliveryId, $vendorPartId, $quantity, $unitPrice, $subMagazineId, $comment) {
    if ($lineId > 0) {
        $state = $handler->updateDeliveryLine($lineId, $vendorPartId, $quantity, $unitPrice, $subMagazineId, $comment);
    } else {
        $state = $handler->addDeliveryLine($deliveryId, $vendorPartId, $quantity, $unitPrice, $subMagazineId, $comment);
    }
    return ['success' => true, 'state' => $state];
});
