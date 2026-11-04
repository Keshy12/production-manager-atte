<?php
/**
 * AJAX (POST): create an empty delivery draft (invoice / WZ) for a vendor.
 *
 * POST: vendor_id (required); optional document_type ('invoice'|'wz'),
 *   vendor_document_number, document_date (Y-m-d), currency,
 *   default_sub_magazine_id, comment.
 * Response: {success:true, delivery_id:int, state:<getDeliveryState()>}
 */
require_once __DIR__ . '/delivery-common.php';

$userId = delivery_guard_user('POST');

$vendorId = delivery_int($_POST['vendor_id'] ?? 0);
if ($vendorId <= 0) {
    delivery_json(['success' => false, 'error' => 'Nieprawidłowy dostawca.']);
}
$header = delivery_header_from($_POST, false);

delivery_run(function ($MsaDB, $handler) use ($vendorId, $userId, $header) {
    $deliveryId = $handler->createDeliveryDraft($vendorId, $userId, $header);
    return [
        'success'     => true,
        'delivery_id' => $deliveryId,
        'state'       => $handler->getDeliveryState($deliveryId),
    ];
});
