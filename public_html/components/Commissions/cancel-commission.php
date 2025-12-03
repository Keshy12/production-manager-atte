<?php
use Atte\DB\MsaDB;
use Atte\Utils\CommissionRepository;
use Atte\Utils\BomRepository;
use Atte\Utils\TransferGroupManager;

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
        $filters = isset($_POST['filters']) ? json_decode($_POST['filters'], true) : [];

        $commissionRepository = new CommissionRepository($MsaDB);
        $bomRepository = new BomRepository($MsaDB);

        $mainCommission = $commissionRepository->getCommissionById($commissionId);
        $mainRow = $mainCommission->commissionValues;
        $mainType = $mainCommission->deviceType;
        $mainBomId = $mainRow['bom_id'];
        $mainReceivers = implode(',', $mainCommission->getReceivers());

        $groupKey = $mainType.'_'.$mainBomId.'_'.$mainRow["warehouse_from_id"].'_'.$mainRow["warehouse_to_id"].'_'.$mainReceivers.'_'.$mainRow["priority"];

        // Build filter conditions based on current page filters
        $statements = ["1"];

        // Apply showCancelled filter
        if (isset($filters['showCancelled']) && !$filters['showCancelled']) {
            $statements[] = "cl.is_cancelled = 0";
        }

        // Apply state filter
        if (!empty($filters['state_id'])) {
            $stateStatement = array();
            foreach($filters['state_id'] as $state) {
                $stateStatement[] = "cl.state = ".$MsaDB->db->quote($state);
            }
            $statements[] = "(".implode(" OR ", $stateStatement).")";
        }

        // Apply priority filter
        if (!empty($filters['priority_id'])) {
            $priorityStatement = array();
            foreach($filters['priority_id'] as $priority) {
                $priorityStatement[] = "cl.priority = ".$MsaDB->db->quote($priority);
            }
            $statements[] = "(".implode(" OR ", $priorityStatement).")";
        }

        $additionalConditions = implode(" AND ", $statements);

        $allCommissions = $MsaDB->query("
            SELECT DISTINCT cl.id
            FROM commission__list cl
            JOIN commission__receivers cr ON cl.id = cr.commission_id
            WHERE cl.device_type = '$mainType'
            AND cl.bom_id = $mainBomId
            AND cl.warehouse_from_id = {$mainRow['warehouse_from_id']}
            AND cl.warehouse_to_id = {$mainRow['warehouse_to_id']}
            AND cl.priority = '{$mainRow['priority']}'
            AND cl.state != 'returned'
            AND $additionalConditions
            ORDER BY cl.is_cancelled ASC, cl.created_at DESC
        ", PDO::FETCH_COLUMN);

        $commissionIds = [];
        foreach ($allCommissions as $cId) {
            $comm = $commissionRepository->getCommissionById($cId);
            $receivers = implode(',', $comm->getReceivers());

            // Check if receivers match the main commission
            if ($receivers === $mainReceivers) {
                // Apply receivers filter if present
                if (!empty($filters['receivers'])) {
                    $commReceivers = $comm->getReceivers();
                    $hasMatchingReceiver = false;
                    foreach ($filters['receivers'] as $filteredReceiver) {
                        if (in_array($filteredReceiver, $commReceivers)) {
                            $hasMatchingReceiver = true;
                            break;
                        }
                    }
                    if ($hasMatchingReceiver) {
                        $commissionIds[] = $cId;
                    }
                } else {
                    $commissionIds[] = $cId;
                }
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
        $originalTransferGroups = [];

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

            // Find the original transfer group(s) for this commission
            // Original groups are those with non-cancelled, positive quantity transfers (to destination)
            $originalGroups = $MsaDB->query("
                SELECT DISTINCT transfer_group_id
                FROM (
                    SELECT transfer_group_id FROM inventory__parts
                    WHERE commission_id = $cId AND is_cancelled = 0 AND qty > 0
                    UNION
                    SELECT transfer_group_id FROM inventory__sku
                    WHERE commission_id = $cId AND is_cancelled = 0 AND qty > 0
                    UNION
                    SELECT transfer_group_id FROM inventory__smd
                    WHERE commission_id = $cId AND is_cancelled = 0 AND qty > 0
                    UNION
                    SELECT transfer_group_id FROM inventory__tht
                    WHERE commission_id = $cId AND is_cancelled = 0 AND qty > 0
                ) as all_groups
                ORDER BY transfer_group_id ASC
                LIMIT 1
            ");

            $originalTransferGroups[$cId] = !empty($originalGroups) ? (int)$originalGroups[0]['transfer_group_id'] : null;

            $transfersByCommission[$cId] = [];

            $flatBom = $MsaDB->query("
                SELECT DISTINCT parts_id, sku_id, tht_id, smd_id, quantity
                FROM bom__flat
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
                    AND qty > 0
                    ORDER BY is_cancelled ASC, timestamp ASC
                ");

                $list__component = $MsaDB->readIdName("list__".$componentType);

                foreach ($transfers as $transfer) {
                    $qtyTransferred = $transfer['qty'];
                    $qtyPerItem = $component['quantity'];
                    $qtyUsed = $row['qty_produced'] * $qtyPerItem;
                    $qtyAvailable = max(0, $qtyTransferred - $qtyUsed);
                    $isCancelled = (bool)$transfer['is_cancelled'];

                    if ($qtyAvailable <= 0 && !$isCancelled) continue;

                    $transferGroupId = $transfer['transfer_group_id'];

                    $sources = [];
                    $transferDetails = [];  // Separate array for collapsible details (includes both source and target)

                    if ($transferGroupId) {
                        // Get source warehouses (negative qty) - for distribution
                        // GROUP BY to prevent duplicate warehouse entries
                        $sourcesQuery = $MsaDB->query("
                            SELECT sub_magazine_id, SUM(ABS(qty)) as abs_qty, MIN(qty) as original_qty
                            FROM inventory__{$componentType}
                            WHERE transfer_group_id = {$transferGroupId}
                            AND {$componentType}_id = $componentId
                            AND commission_id = $cId
                            AND qty < 0
                            AND is_cancelled = 0
                            GROUP BY sub_magazine_id
                            ORDER BY sub_magazine_id = {$mainRow['warehouse_from_id']} DESC, abs_qty DESC
                        ");

                        $remainingQty = $qtyAvailable;

                        foreach ($sourcesQuery as $src) {
                            $sourceQty = (float)$src['abs_qty'];
                            $allocatedQty = min($remainingQty, $sourceQty);
                            $remainingQty -= $allocatedQty;

                            $sourceData = [
                                'warehouseId' => (int)$src['sub_magazine_id'],
                                'warehouseName' => $magazines[$src['sub_magazine_id']],
                                'quantity' => (float)$allocatedQty,
                                'originalQty' => (float)$src['original_qty'],
                                'isMainWarehouse' => $src['sub_magazine_id'] == $mainRow['warehouse_from_id']
                            ];

                            $sources[] = $sourceData;  // Add to sources (for distribution)
                            $transferDetails[] = $sourceData;  // Also add to transferDetails
                        }

                        // Get target warehouse (positive qty) - ONLY for collapsible details display
                        $targetQuery = $MsaDB->query("
                            SELECT sub_magazine_id, qty as original_qty
                            FROM inventory__{$componentType}
                            WHERE transfer_group_id = {$transferGroupId}
                            AND {$componentType}_id = $componentId
                            AND commission_id = $cId
                            AND qty > 0
                            AND is_cancelled = 0
                            LIMIT 1
                        ");

                        if (!empty($targetQuery)) {
                            // Add ONLY to transferDetails, NOT to sources
                            $transferDetails[] = [
                                'warehouseId' => (int)$targetQuery[0]['sub_magazine_id'],
                                'warehouseName' => $magazines[$targetQuery[0]['sub_magazine_id']],
                                'quantity' => (float)$qtyAvailable,
                                'originalQty' => (float)$targetQuery[0]['original_qty'],
                                'isMainWarehouse' => false
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

                    // Check if this is a cancellation transfer group
                    // A transfer is from a cancellation if its transfer_group_id doesn't match the original
                    $isCancellationGroup = false;
                    $transferGroupNotes = '';

                    if ($transferGroupId && $originalTransferGroups[$cId]) {
                        $isCancellationGroup = ($transferGroupId != $originalTransferGroups[$cId]);

                        // Get notes for display purposes
                        if ($isCancellationGroup) {
                            $groupInfo = $MsaDB->query("
                                SELECT notes
                                FROM inventory__transfer_groups
                                WHERE id = $transferGroupId
                            ");
                            if (!empty($groupInfo)) {
                                $transferGroupNotes = $groupInfo[0]['notes'] ?? '';
                            }
                        }
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
                        'sources' => $sources,  // Only source warehouses (for distribution)
                        'transferDetails' => $transferDetails,  // Source + target warehouses (for collapsible details)
                        'destinationWarehouseId' => (int)$transfer['sub_magazine_id'],
                        'transferGroupId' => $transferGroupId ? (int)$transferGroupId : null,
                        'isCancelled' => $isCancelled,
                        'isCancellationGroup' => $isCancellationGroup,
                        'transferGroupNotes' => $transferGroupNotes
                    ];
                }
            }

            // Deduplicate transfers by transferId (in case same component appears multiple times in BOM)
            $seen = [];
            $deduplicated = [];
            foreach ($transfersByCommission[$cId] as $transfer) {
                $transferId = $transfer['transferId'];
                if (!isset($seen[$transferId])) {
                    $seen[$transferId] = true;
                    $deduplicated[] = $transfer;
                }
            }
            $transfersByCommission[$cId] = $deduplicated;
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

/**
 * Mark all entries in a transfer group as cancelled for a specific commission
 * This includes BOTH source (negative qty) and target (positive qty) entries
 *
 * @param MsaDB $MsaDB Database instance
 * @param int $transferGroupId The original transfer group ID to cancel
 * @param int $commissionId The commission ID
 * @param int $userId User performing the cancellation
 * @param string $now Current timestamp
 * @return int Number of entries marked as cancelled
 */
function markAllEntriesInGroupAsCancelled($MsaDB, $transferGroupId, $commissionId, $userId, $now) {
    $deviceTypes = ['parts', 'sku', 'smd', 'tht'];
    $totalMarked = 0;

    foreach ($deviceTypes as $deviceType) {
        $entries = $MsaDB->query("
            SELECT id FROM inventory__{$deviceType}
            WHERE transfer_group_id = {$transferGroupId}
            AND commission_id = {$commissionId}
            AND is_cancelled = 0
        ");

        foreach ($entries as $entry) {
            $MsaDB->update("inventory__{$deviceType}", [
                'is_cancelled' => 1,
                'cancelled_at' => $now,
                'cancelled_by' => $userId
            ], 'id', $entry['id']);

            $totalMarked++;
        }
    }

    return $totalMarked;
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
        $transferGroupManager = new TransferGroupManager($MsaDB);

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

        // PHASE 1: Group transfers by (commission_id, original_transfer_group_id)
        $cancellationGroups = [];

        foreach ($selectedTransfers as $transfer) {
            $transferId = $transfer['transferId'];
            $componentType = $transfer['componentType'];
            $commissionId = $transfer['commissionId'];

            $originalTransfer = $MsaDB->query("
                SELECT * FROM inventory__{$componentType}
                WHERE id = $transferId
            ")[0];

            $originalTransferGroupId = $originalTransfer['transfer_group_id'];

            if (!$originalTransferGroupId) {
                $originalTransferGroupId = 'null';
            }

            $groupKey = "commission_{$commissionId}_group_{$originalTransferGroupId}";

            if (!isset($cancellationGroups[$groupKey])) {
                $cancellationGroups[$groupKey] = [
                    'commissionId' => $commissionId,
                    'originalGroupId' => $originalTransferGroupId,
                    'transfers' => [],
                    'newCancellationGroupId' => null
                ];
            }

            $cancellationGroups[$groupKey]['transfers'][] = array_merge($transfer, [
                'originalTransferData' => $originalTransfer
            ]);
        }

        // PHASE 2: Create ONE cancellation transfer group for each original group
        foreach ($cancellationGroups as $groupKey => &$group) {
            $commissionId = $group['commissionId'];
            $originalGroupId = $group['originalGroupId'];

            if ($originalGroupId === 'null') {
                $cancellationNote = "Anulacja transferów bez grupy dla zlecenia #$commissionId";
            } else {
                $cancellationNote = "Anulacja grupy transferowej #$originalGroupId dla zlecenia #$commissionId";
            }

            $group['newCancellationGroupId'] = $transferGroupManager->createTransferGroup($userId, $cancellationNote);
        }

        // PHASE 3: Mark entries in original transfer groups as cancelled
        foreach ($cancellationGroups as $group) {
            $originalGroupId = $group['originalGroupId'];
            $commissionId = $group['commissionId'];

            if ($originalGroupId === 'null') {
                // Edge case: no group, mark specific transfers only
                foreach ($group['transfers'] as $transfer) {
                    $transferId = $transfer['transferId'];
                    $componentType = $transfer['componentType'];

                    $MsaDB->update("inventory__{$componentType}", [
                        'is_cancelled' => 1,
                        'cancelled_at' => $now,
                        'cancelled_by' => $userId
                    ], 'id', $transferId);
                }
            } else {
                // Check if entire commission is being cancelled
                if (in_array($commissionId, $selectedCommissions)) {
                    // Commission checkbox was checked: mark ALL entries in the group
                    markAllEntriesInGroupAsCancelled($MsaDB, $originalGroupId, $commissionId, $userId, $now);
                } else {
                    // Only specific transfer rows selected: mark ALL related entries for each transfer
                    foreach ($group['transfers'] as $transfer) {
                        $transferId = $transfer['transferId'];
                        $componentType = $transfer['componentType'];
                        $componentId = $transfer['componentId'];

                        // Query ALL entries (source + target) for this specific transfer
                        $entries = $MsaDB->query("
                            SELECT id FROM inventory__{$componentType}
                            WHERE transfer_group_id = {$originalGroupId}
                            AND commission_id = {$commissionId}
                            AND {$componentType}_id = {$componentId}
                            AND is_cancelled = 0
                        ");

                        // Mark ALL related entries as cancelled (both negative and positive qty)
                        foreach ($entries as $entry) {
                            $MsaDB->update("inventory__{$componentType}", [
                                'is_cancelled' => 1,
                                'cancelled_at' => $now,
                                'cancelled_by' => $userId
                            ], 'id', $entry['id']);
                        }
                    }
                }
            }
        }

        // PHASE 4: Create reversal and return entries
        foreach ($cancellationGroups as $group) {
            $newCancellationGroupId = $group['newCancellationGroupId'];

            foreach ($group['transfers'] as $transfer) {
                $componentType = $transfer['componentType'];
                $componentId = $transfer['componentId'];
                $commissionId = $transfer['commissionId'];
                $qtyToReturn = $transfer['qtyToReturn'];
                $sources = $transfer['sources'];
                $originalTransfer = $transfer['originalTransferData'];

                if ($qtyToReturn <= 0) continue;

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
                    $newCancellationGroupId,
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
                            $newCancellationGroupId,
                            1,
                            $now,
                            $userId,
                            3,
                            "Zwrot komponentów do źródła - anulacja zlecenia #$commissionId"
                        ]);
                    }
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