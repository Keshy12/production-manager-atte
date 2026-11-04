<?php
/**
 * AJAX (POST): update the header of a draft delivery.
 *
 * POST: delivery_id (required); any of document_type, vendor_document_number,
 *   document_date (Y-m-d), currency, default_sub_magazine_id,
 *   received_at (Y-m-d → 'Y-m-d 00:00:00'), comment.
 *   Only fields that are sent are changed.
 * Response: {success:true, state:<getDeliveryState()>}
 */
require_once __DIR__ . '/delivery-common.php';

delivery_guard_user('POST');

$deliveryId = delivery_int($_POST['delivery_id'] ?? 0);
if ($deliveryId <= 0) {
    delivery_json(['success' => false, 'error' => 'Nieprawidłowe ID dostawy.']);
}
$header = delivery_header_from($_POST, true);

delivery_run(function ($MsaDB, $handler) use ($deliveryId, $header) {
    $state = $handler->updateDeliveryHeader($deliveryId, $header);
    return ['success' => true, 'state' => $state];
});
