<?php
use Atte\DB\MsaDB;
use Atte\Utils\Locker;
use Atte\Utils\TransferGroupManager;

$locker = new Locker("import-orders.lock");
$isLocked = !($locker -> lock(FALSE));
$oldLastCell = (int)$_POST['oldLastCellFound'];
$newLastCell = (int)$_POST['newLastCellFound'];

if(!$isLocked) {
    $orders = json_decode($_POST['orders'], true);
    try {
        $importSummary = importOrders($orders, $oldLastCell, $newLastCell);
        // Return success with summary data
        echo json_encode(['success' => true, 'summary' => $importSummary]);
    } catch (\Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
} else {
    echo json_encode(['success' => false, 'error' => 'Import jest już w toku. Proszę czekać.']);
}


function importOrders($orders, $oldLastCell, $newLastCell) {
    $MsaDB = MsaDB::getInstance();
    $MsaDB->db->beginTransaction();

    try {
        // Existing validation checks
        $lastReadCell = (int)$MsaDB->query("SELECT * FROM `ref__timestamp` WHERE `id` = 3")[0]['params'];
        if(($oldLastCell+1) !== $lastReadCell) {
            $MsaDB->db->rollBack();
            throw new \Exception("Dane się zmieniły, proszę odświeżyć stronę.");
        }

        if($lastReadCell + count($orders) !== $newLastCell) {
            $MsaDB->db->rollBack();
            throw new \Exception("Nieprawidłowa ilość zamówień, coś poszło nie tak.");
        }

        // Initialize TransferGroupManager
        $transferGroupManager = new TransferGroupManager($MsaDB);
        $userId = $_SESSION['userid'] ?? 8;

        // Group orders by PO_ID
        $ordersByPO = [];
        foreach ($orders as $order) {
            $poId = $order['PO_ID'];
            if (!isset($ordersByPO[$poId])) {
                $ordersByPO[$poId] = [];
            }
            $ordersByPO[$poId][] = $order;
        }

        // Track import summary for response
        $importSummary = [
            'totalOrders' => count($orders),
            'uniqueParts' => count(array_unique(array_column($orders, 'PartName'))),
            'transferGroupsCreated' => [],
            'ordersByPO' => []
        ];

        // Process each PO_ID group
        foreach ($ordersByPO as $poId => $poOrders) {
            // Collect unique GRN_IDs for this PO
            $grnIds = array_values(array_unique(array_column($poOrders, 'GRN_ID')));
            $grnIdsString = implode(', ', $grnIds);

            // Create transfer group for this PO
            $transferGroupId = $transferGroupManager->createTransferGroup($userId, 'order_import', [
                'po_id' => $poId,
                'grn_ids' => $grnIdsString
            ]);


            if (!$transferGroupId || $transferGroupId <= 0) {
                throw new \Exception("Nie udało się utworzyć grupy transferów dla PO-{$poId}");
            }

            // Track transfer group created
            $importSummary['transferGroupsCreated'][] = [
                'poId' => $poId,
                'transferGroupId' => $transferGroupId,
                'grnIds' => $grnIds // Now guaranteed to be sequential array
            ];

            // Prepare order summary for this PO
            $poSummary = [
                'poId' => $poId,
                'transferGroupId' => $transferGroupId,
                'grnIds' => $grnIds, // Now guaranteed to be sequential array
                'parts' => []
            ];

            // Insert all orders from this PO with same transfer_group_id
            foreach ($poOrders as $order) {
                // FIX: Use "qty" not "quantity", remove "user_id" column
                $MsaDB->insert(
                    "inventory__parts",
                    ["parts_id", "sub_magazine_id", "qty", "input_type_id", "comment", "transfer_group_id"],
                    [$order['PartId'], '27', $order['Qty'], '7', $order['GRN_ID'], $transferGroupId]
                );

                // Track part in summary
                $poSummary['parts'][] = [
                    'partName' => $order['PartName'],
                    'qty' => $order['Qty'],
                    'vendorJM' => $order['Vendor_JM'] ?? ''
                ];
            }

            $importSummary['ordersByPO'][] = $poSummary;
        }

        // Update timestamp and commit
        $MsaDB->update("ref__timestamp", ["params" => $newLastCell], "id", 3);
        $MsaDB->db->commit();

        // Return import summary for success modal
        return $importSummary;

    } catch (\Exception $e) {
        if($MsaDB -> isInTransaction()) $MsaDB->db->rollBack();
        throw $e;
    }
}