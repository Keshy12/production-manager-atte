<?php
/**
 * AJAX (POST): full state of a delivery (draft: live matcher; posted /
 * cancelled: stored data).
 *
 * POST: delivery_id (required)
 * Response: {success:true, state:<getDeliveryState()>}
 */
require_once __DIR__ . '/delivery-common.php';

delivery_guard('POST');

$deliveryId = delivery_int($_POST['delivery_id'] ?? 0);
if ($deliveryId <= 0) {
    delivery_json(['success' => false, 'error' => 'Nieprawidłowe ID dostawy.']);
}

delivery_run(function ($MsaDB, $handler) use ($deliveryId) {
    return ['success' => true, 'state' => $handler->getDeliveryState($deliveryId)];
});
