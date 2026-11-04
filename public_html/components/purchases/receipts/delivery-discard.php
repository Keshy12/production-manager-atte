<?php
/**
 * AJAX (POST): discard (delete) a draft delivery. Nothing was booked.
 *
 * POST: delivery_id (required)
 * Response: {success:true}
 */
require_once __DIR__ . '/delivery-common.php';

delivery_guard_user('POST');

$deliveryId = delivery_int($_POST['delivery_id'] ?? 0);
if ($deliveryId <= 0) {
    delivery_json(['success' => false, 'error' => 'Nieprawidłowe ID dostawy.']);
}

delivery_run(function ($MsaDB, $handler) use ($deliveryId) {
    $handler->discardDeliveryDraft($deliveryId);
    return ['success' => true];
});
