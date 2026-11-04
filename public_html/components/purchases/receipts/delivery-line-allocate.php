<?php
/**
 * AJAX (POST): manual split of a delivery line over open PO items.
 *
 * POST: line_id (required);
 *   allocations[i][po_item_id], allocations[i][quantity] (jQuery
 *   form-encoded nested array; ',' accepted in quantity).
 *   Empty / missing allocations (or rows with blank/0 quantity) clear
 *   the manual split of that line → back to automatic matching.
 * Response: {success:true, state:<getDeliveryState()>}
 */
require_once __DIR__ . '/delivery-common.php';

delivery_guard_user('POST');

$lineId = delivery_int($_POST['line_id'] ?? 0);
if ($lineId <= 0) {
    delivery_json(['success' => false, 'error' => 'Nieprawidłowe ID pozycji.']);
}

$allocations = [];
if (isset($_POST['allocations']) && is_array($_POST['allocations'])) {
    foreach ($_POST['allocations'] as $row) {
        if (!is_array($row)) continue;
        $qty = delivery_str($row['quantity'] ?? null);
        if ($qty === null) continue;
        $num = str_replace([' ', ','], ['', '.'], $qty);
        if (is_numeric($num) && (float)$num == 0.0) continue;   // blank / 0 → no allocation
        $allocations[] = [
            'po_item_id' => delivery_int($row['po_item_id'] ?? 0),
            'quantity'   => $qty,
        ];
    }
}

delivery_run(function ($MsaDB, $handler) use ($lineId, $allocations) {
    return ['success' => true, 'state' => $handler->setLineAllocation($lineId, $allocations)];
});
