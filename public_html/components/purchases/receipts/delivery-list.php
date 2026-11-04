<?php
/**
 * AJAX (POST): list of deliveries for the Robocze / Historia tabs.
 *
 * POST: state = draft | posted | all (default draft)
 *   draft  → drafts, newest update first
 *   posted → posted AND cancelled (history); `state` field tells them apart
 *   all    → everything
 *   (max 200 rows)
 * Response: {success:true, deliveries:[{id, vendor_id, vendor_name, state,
 *   document_type, vendor_document_number, document_date, grn_number,
 *   received_at, line_count, po_numbers:[...], created_by_name,
 *   created_at, posted_at}]}
 */
require_once __DIR__ . '/delivery-common.php';

use Atte\Utils\Purchase\Order\DeliveryRepository;

delivery_guard('POST');

$state = (string)($_POST['state'] ?? 'draft');
if (!in_array($state, ['draft', 'posted', 'all'], true)) {
    delivery_json(['success' => false, 'error' => 'Nieprawidłowy filtr stanu.']);
}

delivery_run(function ($MsaDB) use ($state) {
    $deliveries = (new DeliveryRepository($MsaDB))->listForUi($state, 200);
    return ['success' => true, 'deliveries' => $deliveries];
});
