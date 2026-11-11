<?php
/**
 * AJAX (POST): list of deliveries for the Robocze / Historia tabs.
 *
 * POST: state = draft | posted | all (default draft)
 *   draft  → drafts, newest update first
 *   posted → posted AND cancelled (history); `state` field tells them apart
 *   all    → everything
 *   (max DELIVERY_LIST_MAX rows)
 * Response: {success:true, total:<int>, deliveries:[{id, vendor_id,
 *   vendor_name, state, document_type, vendor_document_number,
 *   document_date, grn_number, received_at, line_count, po_numbers:[...],
 *   created_by_name, created_at, posted_at}]}
 */
require_once __DIR__ . '/delivery-common.php';

use Atte\Utils\Purchase\Order\DeliveryRepository;

delivery_guard('POST');

/**
 * Limit wierszy w jednej odpowiedzi. Po podłączeniu starszych przyjęć
 * (purchase__order_receipt → purchase__delivery) w tabeli dostaw jest
 * ~3 800 zatwierdzonych pozycji, a filtr zakładki historii działa po
 * stronie klienta — endpoint musi zwrócić całą listę, bo inaczej
 * wyszukiwanie milcząco pomijałoby większość dostaw.
 */
const DELIVERY_LIST_MAX = DeliveryRepository::UI_LIST_MAX;

$state = (string)($_POST['state'] ?? 'draft');
if (!in_array($state, ['draft', 'posted', 'all'], true)) {
    delivery_json(['success' => false, 'error' => 'Nieprawidłowy filtr stanu.']);
}

delivery_run(function ($MsaDB) use ($state) {
    $repo        = new DeliveryRepository($MsaDB);
    $total       = $repo->countForUi($state);
    $deliveries  = $repo->listForUi($state, DELIVERY_LIST_MAX);

    return ['success' => true, 'total' => $total, 'deliveries' => $deliveries];
});
