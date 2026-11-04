<?php
/**
 * AJAX (POST): open PO lines of a vendor (PO states confirmed /
 * in_progress / partially_received, remaining > 0), in DeliveryMatcher
 * candidate order.
 *
 * POST: vendor_id (int, required)
 * Response:
 *   {success:true, items:[{po_item_id, po_id, po_number, po_state,
 *     vendor_part_id, vendor_part_no, producer_part_no, part_name,
 *     quantity, quantity_received, remaining, unit_price, currency,
 *     due_date}]}
 */
require_once __DIR__ . '/delivery-common.php';

delivery_guard('POST');

$vendorId = delivery_int($_POST['vendor_id'] ?? 0);
if ($vendorId <= 0) {
    delivery_json(['success' => false, 'error' => 'Nieprawidłowy dostawca.']);
}

delivery_run(function ($MsaDB) use ($vendorId) {
    return ['success' => true, 'items' => delivery_open_items($MsaDB->db, $vendorId)];
});
