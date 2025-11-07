<?php
use Atte\DB\MsaDB;
use Atte\Utils\CommissionRepository;
use Atte\Utils\BomRepository;

header('Content-Type: application/json');

$MsaDB = MsaDB::getInstance();

$action = $_POST['action'] ?? '';

if ($action === 'get_cancellation_data') {
    getCancellationData($MsaDB);
} elseif ($action === 'submit_cancellation') {
    submitCancellation($MsaDB);
}

function getCancellationData($MsaDB) {
    try {
        $commissionId = $_POST['commissionId'];
        $isGrouped = isset($_POST['isGrouped']);
        $groupedIds = $_POST['groupedIds'] ?? '';

        $commissionRepository = new CommissionRepository($MsaDB);
        $bomRepository = new BomRepository($MsaDB);

        $mainCommission = $commissionRepository->getCommissionById($commissionId);
        $mainRow = $mainCommission->commissionValues;
        $mainType = $mainCommission->deviceType;
        $mainBomId = $mainRow['bom_id'];
        $mainReceivers = implode(',', $mainCommission->getReceivers());

        $groupKey = $mainType.'_'.$mainBomId.'_'.$mainRow["warehouse_from_id"].'_'.$mainRow["warehouse_to_id"].'_'.$mainReceivers.'_'.$mainRow["priority"];

        $allCommissions = $MsaDB->query("
            SELECT DISTINCT cl.id 
            FROM commission__list cl 
            JOIN commission__receivers cr ON cl.id = cr.commission_id 
            WHERE cl.device_type = '$mainType'
            AND cl.bom_id = $mainBomId
            AND cl.warehouse_from_id = {$mainRow['warehouse_from_id']}
            AND cl.warehouse_to_id = {$mainRow['warehouse_to_id']}
            AND cl.priority = '{$mainRow['priority']}'
            AND cl.is_cancelled = 0
            AND cl.state != 'returned'
            ORDER BY cl.created_at DESC
        ", PDO::FETCH_COLUMN);

        $commissionIds = [];
        foreach ($allCommissions as $cId) {
            $comm = $commissionRepository->getCommissionById($cId);
            $receivers = implode(',', $comm->getReceivers());
            if ($receivers === $mainReceivers) {
                $commissionIds[] = $cId;
            }
        }

        $list__laminate = $MsaDB->readIdName("list__laminate");
        $list__sku = $MsaDB->readIdName("list__sku");
        $list__tht = $MsaDB->readIdName("list__tht");
        $list__smd = $MsaDB->readIdName("list__smd");
        $list__parts = $MsaDB->readIdName("list__parts");
        $magazines = $MsaDB->readIdName("magazine__list", "sub_magazine_id", "sub_magazine_name");

        $commissionsData = [];
        $transfersByCommission = [];

        foreach ($commissionIds as $cId) {
            $commission = $commissionRepository->getCommissionById($cId);
            $row = $commission->commissionValues;
            $deviceType = $commission->deviceType;
            $bomId = $row['bom_id'];
            $deviceBom = $bomRepository->getBomById($deviceType, $bomId);

            $deviceId = $deviceBom->deviceId;
            $deviceName = ${"list__".$deviceType}[$deviceId];

            $unreturned = (int)$row['qty_produced'] - (int)$row['qty_returned'];

            $commissionsData[$cId] = [
                'id' => (int)$cId,
                'deviceName' => $deviceName,
                'deviceType' => $deviceType,
                'bomId' => (int)$bomId,
                'qty' => (int)$row['qty'],
                'qtyProduced' => (int)$row['qty_produced'],
                'qtyReturned' => (int)$row['qty_returned'],
                'qtyUnreturned' => $unreturned,
                'isCancelled' => (bool)$row['is_cancelled'],
                'state' => $row['state'],
                'createdAt' => $row['created_at']
            ];

            $transfersByCommission[$cId] = [];

            $flatBom = $MsaDB->query("
                SELECT * FROM bom__flat 
                WHERE bom_{$deviceType}_id = $bomId
            ");

            foreach ($flatBom as $component) {
                $componentType = null;
                $componentId = null;

                if ($component['parts_id']) {
                    $componentType = 'parts';
                    $componentId = $component['parts_id'];
                } elseif ($component['sku_id']) {
                    $componentType = 'sku';
                    $componentId = $component['sku_id'];
                } elseif ($component['tht_id']) {
                    $componentType = 'tht';
                    $componentId = $component['tht_id'];
                } elseif ($component['smd_id']) {
                    $componentType = 'smd';
                    $componentId = $component['smd_id'];
                }

                if (!$componentType) continue;

                $transfers = $MsaDB->query("
                    SELECT * FROM inventory__{$componentType}
                    WHERE commission_id = $cId 
                    AND {$componentType}_id = $componentId
                    AND is_cancelled = 0
                    AND qty > 0
                    ORDER BY timestamp ASC
                ");

                $list__component = $MsaDB->readIdName("list__".$componentType);

                foreach ($transfers as $transfer) {
                    $qtyTransferred = $transfer['qty'];
                    $qtyPerItem = $component['quantity'];
                    $qtyUsed = $row['qty_produced'] * $qtyPerItem;
                    $qtyAvailable = max(0, $qtyTransferred - $qtyUsed);

                    if ($qtyAvailable <= 0) continue;

                    $transferGroupId = $transfer['transfer_group_id'];

                    $sources = [];
                    if ($transferGroupId) {
                        $sourcesQuery = $MsaDB->query("
                            SELECT sub_magazine_id, ABS(qty) as qty
                            FROM inventory__{$componentType}
                            WHERE transfer_group_id = {$transferGroupId}
                            AND {$componentType}_id = $componentId
                            AND commission_id = $cId
                            AND qty < 0
                            AND is_cancelled = 0
                            ORDER BY sub_magazine_id = {$mainRow['warehouse_from_id']} DESC, qty DESC
                        ");

                        $remainingQty = $qtyAvailable;

                        foreach ($sourcesQuery as $src) {
                            $sourceQty = (float)$src['qty'];
                            $allocatedQty = min($remainingQty, $sourceQty);
                            $remainingQty -= $allocatedQty;

                            $sources[] = [
                                'warehouseId' => (int)$src['sub_magazine_id'],
                                'warehouseName' => $magazines[$src['sub_magazine_id']],
                                'quantity' => (float)$allocatedQty,
                                'originalQty' => $sourceQty,
                                'isMainWarehouse' => $src['sub_magazine_id'] == $mainRow['warehouse_from_id']
                            ];
                        }
                    }

                    if (empty($sources)) {
                        $sources[] = [
                            'warehouseId' => $mainRow['warehouse_from_id'],
                            'warehouseName' => $magazines[$mainRow['warehouse_from_id']],
                            'quantity' => $qtyAvailable,
                            'isMainWarehouse' => true
                        ];
                    }

                    $transfersByCommission[$cId][] = [
                        'transferId' => (int)$transfer['id'],
                        'commissionId' => (int)$cId,
                        'componentType' => $componentType,
                        'componentId' => (int)$componentId,
                        'componentName' => $list__component[$componentId],
                        'qtyTransferred' => (float)$qtyTransferred,
                        'qtyUsed' => (float)$qtyUsed,
                        'qtyAvailable' => (float)$qtyAvailable,
                        'qtyPerItem' => (float)$qtyPerItem,
                        'sources' => $sources,
                        'destinationWarehouseId' => (int)$transfer['sub_magazine_id'],
                        'transferGroupId' => $transferGroupId ? (int)$transferGroupId : null
                    ];
                }
            }
        }

        echo json_encode([
            'success' => true,
            'clickedCommissionId' => $commissionId,
            'isGrouped' => $isGrouped,
            'commissionsData' => $commissionsData,
            'transfersByCommission' => $transfersByCommission
        ]);

    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
}

function submitCancellation($MsaDB) {
    $MsaDB->db->beginTransaction();

    try {
        $selectedCommissions = json_decode($_POST['selectedCommissions'], true);
        $selectedTransfers = json_decode($_POST['selectedTransfers'], true);
        $returnCompletedAsReturned = json_decode($_POST['returnCompletedAsReturned'], true) ?? [];
        $userId = $_SESSION['userid'] ?? 1;
        $now = date('Y-m-d H:i:s');

        $commissionRepository = new CommissionRepository($MsaDB);

        foreach ($selectedCommissions as $commissionId) {
            if (isset($returnCompletedAsReturned[$commissionId]) && $returnCompletedAsReturned[$commissionId]) {
                $commission = $commissionRepository->getCommissionById($commissionId);
                $row = $commission->commissionValues;
                $deviceType = $commission->deviceType;
                $bomId = $row['bom_id'];
                $bomRepository = new BomRepository($MsaDB);
                $deviceBom = $bomRepository->getBomById($deviceType, $bomId);
                $deviceId = $deviceBom->deviceId;

                $remaining = $row['qty_produced'] - $row['qty_returned'];

                if ($remaining > 0) {
                    $MsaDB->update('commission__list', [
                        'qty_returned' => $row['qty_produced'],
                        'state' => 'returned'
                    ], 'id', $commissionId);

                    $inputTypeId = 5;
                    $comment = "Automatyczny zwrot pozostałych sztuk przy anulacji";

                    $MsaDB->insert("inventory__".$deviceType, [
                        $deviceType."_id",
                        $deviceType."_bom_id",
                        "commission_id",
                        "sub_magazine_id",
                        "qty",
                        "input_type_id",
                        "comment"
                    ], [
                        $deviceId,
                        $bomId,
                        $commissionId,
                        $row['warehouse_to_id'],
                        -$remaining,
                        $inputTypeId,
                        $comment
                    ]);
                }
            } else {
                $MsaDB->update('commission__list', [
                    'is_cancelled' => 1,
                    'cancelled_at' => $now,
                    'cancelled_by' => $userId,
                    'state' => 'cancelled'
                ], 'id', $commissionId);
            }
        }

        foreach ($selectedTransfers as $transfer) {
            $transferId = $transfer['transferId'];
            $componentType = $transfer['componentType'];
            $componentId = $transfer['componentId'];
            $commissionId = $transfer['commissionId'];
            $qtyToReturn = $transfer['qtyToReturn'];
            $sources = $transfer['sources'];

            if ($qtyToReturn <= 0) continue;

            $originalTransfer = $MsaDB->query("
                SELECT * FROM inventory__{$componentType}
                WHERE id = $transferId
            ")[0];

            $transferGroupId = $originalTransfer['transfer_group_id'];

            $MsaDB->update("inventory__{$componentType}", [
                'is_cancelled' => 1,
                'cancelled_at' => $now,
                'cancelled_by' => $userId
            ], 'id', $transferId);

            $MsaDB->insert("inventory__{$componentType}", [
                $componentType . '_id',
                'commission_id',
                'sub_magazine_id',
                'qty',
                'transfer_group_id',
                'is_cancelled',
                'cancelled_at',
                'cancelled_by',
                'input_type_id',
                'comment'
            ], [
                $componentId,
                $commissionId,
                $originalTransfer['sub_magazine_id'],
                -$qtyToReturn,
                $transferGroupId,
                1,
                $now,
                $userId,
                3,
                "Anulacja transferu - zlecenie #$commissionId"
            ]);

            foreach ($sources as $source) {
                if ($source['quantity'] > 0) {
                    $MsaDB->insert("inventory__{$componentType}", [
                        $componentType . '_id',
                        'commission_id',
                        'sub_magazine_id',
                        'qty',
                        'transfer_group_id',
                        'is_cancelled',
                        'cancelled_at',
                        'cancelled_by',
                        'input_type_id',
                        'comment'
                    ], [
                        $componentId,
                        $commissionId,
                        $source['warehouseId'],
                        $source['quantity'],
                        $transferGroupId,
                        1,
                        $now,
                        $userId,
                        3,
                        "Zwrot komponentów do źródła - anulacja zlecenia #$commissionId"
                    ]);
                }
            }
        }

        $MsaDB->db->commit();

        echo json_encode([
            'success' => true,
            'message' => 'Anulacja przebiegła pomyślnie'
        ]);

    } catch (Exception $e) {
        $MsaDB->db->rollBack();
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
}