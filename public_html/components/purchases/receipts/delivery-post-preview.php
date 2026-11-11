<?php
/**
 * AJAX (POST): PODGLĄD zatwierdzenia dostawy — bez zapisu.
 *
 * Zwraca stan, w jakim byłyby zamówienia po zatwierdzeniu tej dostawy
 * (zamówiono / przyjęto po dostawie / braki / stan). Używane przez okno
 * „Przyjęcie do zatwierdzenia": operator najpierw ogląda skutek i decyduje
 * o stanach zamówień, a DOPIERO potem wysyła delivery-post.php, który
 * księguje towar i nadaje numer GRN.
 *
 * POST: delivery_id (required)
 * Response: {success:true, preview:{delivery_id:int, pos:[…]}}
 *
 * Bloki są zwracane jako błąd (HTTP 200, success:false) — dokładnie tak,
 * jak przy delivery-post.php.
 */
require_once __DIR__ . '/delivery-common.php';

$userId = delivery_guard_user('POST');

$deliveryId = delivery_int($_POST['delivery_id'] ?? 0);
if ($deliveryId <= 0) {
    delivery_json(['success' => false, 'error' => 'Nieprawidłowe ID dostawy.']);
}

delivery_run(function ($MsaDB, $handler) use ($deliveryId) {
    return [
        'success' => true,
        'preview' => $handler->previewDeliveryPost($deliveryId),
    ];
});
