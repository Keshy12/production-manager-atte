<?php
/**
 * AJAX (POST): post (zatwierdź) a draft delivery — writes stock, creates
 * per-PO receipts and updates PO states in ONE transaction.
 *
 * POST: delivery_id (required)
 * Response: {success:true, result:{delivery_id, grn_number, pos:[...]},
 *            state:<getDeliveryState() of the posted delivery>}
 */
require_once __DIR__ . '/delivery-common.php';

$userId = delivery_guard_user('POST');

$deliveryId = delivery_int($_POST['delivery_id'] ?? 0);
if ($deliveryId <= 0) {
    delivery_json(['success' => false, 'error' => 'Nieprawidłowe ID dostawy.']);
}

delivery_run(function ($MsaDB, $handler) use ($deliveryId, $userId) {
    $result = $handler->postDelivery($deliveryId, $userId);   // owns its transaction
    return [
        'success' => true,
        'result'  => $result,
        'state'   => $handler->getDeliveryState($deliveryId),
    ];
});
