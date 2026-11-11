<?php
/**
 * AJAX (POST): post (zatwierdź) a draft delivery — writes stock, creates
 * per-PO receipts and updates PO states in ONE transaction.
 *
 * POST: delivery_id (required)
 *   po_choices[po_id] — opcjonalnie: wybór operatora z okna podglądu
 *   ('received' = zamknij zamówienie, 'auto' = zostaw otwarte). Brak = 'auto'.
 * Response: {success:true, result:{delivery_id, grn_number, pos:[...]},
 *            state:<getDeliveryState() of the posted delivery>}
 */
require_once __DIR__ . '/delivery-common.php';

$userId = delivery_guard_user('POST');

$deliveryId = delivery_int($_POST['delivery_id'] ?? 0);
if ($deliveryId <= 0) {
    delivery_json(['success' => false, 'error' => 'Nieprawidłowe ID dostawy.']);
}

// Wybory operatora dla zamówień (z okna podglądu): po_id → 'auto' | 'received'.
$rawChoices = $_POST['po_choices'] ?? [];
$poChoices = [];
if (is_array($rawChoices)) {
    foreach ($rawChoices as $poId => $state) {
        $poId = (int)$poId;
        $state = (string)$state;
        if ($poId > 0 && in_array($state, ['auto', 'received'], true)) {
            $poChoices[$poId] = $state;
        }
    }
}

delivery_run(function ($MsaDB, $handler) use ($deliveryId, $userId, $poChoices) {
    $result = $handler->postDelivery($deliveryId, $userId, $poChoices);   // owns its transaction
    return [
        'success' => true,
        'result'  => $result,
        'state'   => $handler->getDeliveryState($deliveryId),
    ];
});
