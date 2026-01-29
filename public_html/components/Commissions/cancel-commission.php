<?php
use Atte\DB\MsaDB;
use Atte\Utils\CommissionRepository;
use Atte\Utils\BomRepository;
use Atte\Utils\TransferGroupManager;

header('Content-Type: application/json');

require_once __DIR__ . "/../../../config/config.php";

$MsaDB = MsaDB::getInstance();

$action = $_POST['action'] ?? '';

if ($action === 'submit_cancellation') {
    submitCancellation($MsaDB);
} else {
    echo json_encode(['success' => false, 'message' => 'Nieprawidłowa akcja.']);
}

/**
 * Mark all entries in a transfer group as cancelled for a specific commission
 * This includes BOTH source (negative qty) and target (positive qty) entries
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

            $group['newCancellationGroupId'] = $transferGroupManager->createTransferGroup($userId, 'rollback_commission', [
                'group_id' => $originalGroupId === 'null' ? 'BRAK' : $originalGroupId,
                'commission_id' => $commissionId
            ]);
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
