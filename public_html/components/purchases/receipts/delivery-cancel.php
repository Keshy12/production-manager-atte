<?php
/**
 * AJAX (POST): cancel (wycofaj) a POSTED delivery in full — reverses
 * stock rows, quantity_received and PO states.
 *
 * POST: delivery_id (required)
 * Response: {success:true, result:{delivery_id, pos:[...]},
 *            state:<getDeliveryState() of the cancelled delivery>}
 */
require_once __DIR__ . '/delivery-common.php';

$userId = delivery_guard_user('POST');

$deliveryId = delivery_int($_POST['delivery_id'] ?? 0);
if ($deliveryId <= 0) {
    delivery_json(['success' => false, 'error' => 'Nieprawidłowe ID dostawy.']);
}

delivery_run(function ($MsaDB, $handler) use ($deliveryId, $userId) {
    $result = $handler->cancelPostedDelivery($deliveryId, $userId);   // owns its transaction
    return [
        'success' => true,
        'result'  => $result,
        'state'   => $handler->getDeliveryState($deliveryId),
    ];
});
