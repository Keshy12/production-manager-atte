<?php
/**
 * AJAX (POST): remove a line from a draft delivery.
 *
 * POST: line_id (required)
 * Response: {success:true, state:<getDeliveryState()>}
 */
require_once __DIR__ . '/delivery-common.php';

delivery_guard_user('POST');

$lineId = delivery_int($_POST['line_id'] ?? 0);
if ($lineId <= 0) {
    delivery_json(['success' => false, 'error' => 'Nieprawidłowe ID pozycji.']);
}

delivery_run(function ($MsaDB, $handler) use ($lineId) {
    return ['success' => true, 'state' => $handler->removeDeliveryLine($lineId)];
});
