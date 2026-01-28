<?php
use Atte\Utils\TransferGroupManager;
use Atte\Utils\CommissionRepository;

$MsaDB = Atte\DB\MsaDB::getInstance();
$userRepository = new Atte\Utils\UserRepository($MsaDB);
$user = $userRepository->getUserById($_SESSION["userid"]);
$userInfo = $user->getUserInfo();

$deviceType = $_POST["deviceType"] ?? "";
$deviceId = $_POST["deviceId"] ?? "";
$transferGroupIds = $_POST["transferGroupIds"] ?? "";
$entryIds = $_POST["entryIds"] ?? "";
$userId = $_SESSION["userid"];

$MsaDB->db->beginTransaction();
try {
    if (empty($transferGroupIds) && empty($entryIds)) {
        throw new Exception("Brak grup transferów lub wpisów do cofnięcia");
    }

    $transferGroupManager = new TransferGroupManager($MsaDB);
    $commissionRepository = new CommissionRepository($MsaDB);

    $totalCancelled = 0;
    $allAlerts = [];
    $processedEntries = []; // Track processed entries to avoid duplicates

    // Process transfer groups
    if (!empty($transferGroupIds)) {
        $groupIdsToCancel = array_filter(array_map('intval', explode(',', $transferGroupIds)));

        foreach ($groupIdsToCancel as $groupId) {
            // Create NEW transfer group for this rollback
            $newTransferGroupId = $transferGroupManager->createTransferGroup($userId, 'rollback_group', ['group_id' => $groupId]);


            // Define all device types to check
            $deviceTypes = ['sku', 'smd', 'tht', 'parts'];

            // Loop through ALL device types to find all entries in this transfer group
            // Bug fix: Production creates entries in multiple device type tables.
            // E.g., producing 1 SMD device creates:
            //   - Negative entries in inventory__smd, inventory__tht, inventory__parts (consumed components)
            //   - Positive entry in inventory__smd (produced product)
            // We must loop through ALL device types to find and cancel all entries.
            foreach ($deviceTypes as $currentType) {
                // Note: inventory__parts does NOT have a bom_id column
                $hasBomId = in_array($currentType, ['sku', 'smd', 'tht']);

                $bomField = $hasBomId ? "{$currentType}_bom_id," : "";
                $entries = $MsaDB->query("
                    SELECT id, {$currentType}_id, qty, comment, transfer_group_id, sub_magazine_id,
                           {$bomField} commission_id, input_type_id
                    FROM inventory__{$currentType}
                    WHERE transfer_group_id = {$groupId} AND is_cancelled = 0
                ");

                // Skip if no entries for this device type
                if (empty($entries)) {
                    continue;
                }

                foreach ($entries as $entry) {
                    $entryId = $entry['id'];
                    $processedEntries[$entryId] = true;

                    $deviceItemId = $entry["{$currentType}_id"];
                    $qty = $entry['qty'];
                    $comment = $entry['comment'];
                    $transferGroupId = $entry['transfer_group_id'];
                    $subMagazineId = $entry['sub_magazine_id'];
                    $bomId = $hasBomId ? $entry["{$currentType}_bom_id"] : null;
                    $commissionId = $entry['commission_id'];
                    $inputTypeId = $entry['input_type_id'];

                    $MsaDB->update(
                        "inventory__{$currentType}",
                        [
                            'is_cancelled' => 1,
                            'cancelled_at' => date('Y-m-d H:i:s'),
                            'cancelled_by' => $userId
                        ],
                        'id',
                        $entryId
                    );

                    $oppositeQty = -$qty;
                    $rollbackComment = "ROLLBACK: {$comment} (ID: {$entryId})";

                    // Build columns and values arrays conditionally based on whether bom_id exists
                    $columns = ["{$currentType}_id"];
                    $values = [$deviceItemId];

                    if ($hasBomId) {
                        $columns[] = "{$currentType}_bom_id";
                        $values[] = $bomId;
                    }

                    $columns = array_merge($columns, [
                        'commission_id',
                        'sub_magazine_id',
                        'qty',
                        'transfer_group_id',
                        'is_cancelled',
                        'cancelled_at',
                        'cancelled_by',
                        'timestamp',
                        'input_type_id',
                        'comment',
                        'isVerified'
                    ]);

                    $values = array_merge($values, [
                        $commissionId,
                        $subMagazineId,
                        $oppositeQty,
                        $newTransferGroupId,
                        1,
                        date('Y-m-d H:i:s'),
                        $userId,
                        date('Y-m-d H:i:s'),
                        $inputTypeId,
                        $rollbackComment,
                        1
                    ]);

                    $MsaDB->insert("inventory__{$currentType}", $columns, $values);

                    // Only adjust commission qty_produced for positive entries (produced devices),
                    // not for negative entries (consumed components)
                    if ($commissionId && $qty > 0) {
                        $commission = $commissionRepository->getCommissionById($commissionId);
                        $commission->addToQuantity(-$qty, 'qty_produced');
                    }

                    $totalCancelled++;
                }
            } // End foreach deviceTypes

            $result = $transferGroupManager->cancelTransferGroup($groupId, $userId);
            $allAlerts = array_merge($allAlerts, $result['alerts']);
        }
    }

    // Process individual entries
    if (!empty($entryIds)) {
        $individualEntryIds = array_filter(array_map('intval', explode(',', $entryIds)));

        foreach ($individualEntryIds as $entryId) {
            // Skip if already processed as part of a group
            if (isset($processedEntries[$entryId])) {
                continue;
            }

            $entry = $MsaDB->query("
                SELECT id, {$deviceType}_id, qty, comment, transfer_group_id, sub_magazine_id,
                       {$deviceType}_bom_id, commission_id, input_type_id
                FROM inventory__{$deviceType}
                WHERE id = {$entryId} AND is_cancelled = 0
            ");

            if (empty($entry)) {
                continue; // Entry not found or already cancelled
            }

            $entry = $entry[0];
            $deviceItemId = $entry["{$deviceType}_id"];
            $qty = $entry['qty'];
            $comment = $entry['comment'];
            $originalTransferGroupId = $entry['transfer_group_id'];
            $subMagazineId = $entry['sub_magazine_id'];
            $bomId = $entry["{$deviceType}_bom_id"];
            $commissionId = $entry['commission_id'];
            $inputTypeId = $entry['input_type_id'];

            // Create a new transfer group for this individual cancellation
            $newTransferGroupId = $transferGroupManager->createTransferGroup($userId, 'rollback_entry', ['entry_id' => $entryId]);


            $MsaDB->update(
                "inventory__{$deviceType}",
                [
                    'is_cancelled' => 1,
                    'cancelled_at' => date('Y-m-d H:i:s'),
                    'cancelled_by' => $userId
                ],
                'id',
                $entryId
            );

            $oppositeQty = -$qty;
            $rollbackComment = "ROLLBACK (pojedynczy wpis): {$comment} (ID: {$entryId})";

            $columns = [
                "{$deviceType}_id",
                "{$deviceType}_bom_id",
                'commission_id',
                'sub_magazine_id',
                'qty',
                'transfer_group_id',
                'is_cancelled',
                'cancelled_at',
                'cancelled_by',
                'timestamp',
                'input_type_id',
                'comment',
                'isVerified'
            ];

            $values = [
                $deviceItemId,
                $bomId,
                $commissionId,
                $subMagazineId,
                $oppositeQty,
                $newTransferGroupId,
                1,
                date('Y-m-d H:i:s'),
                $userId,
                date('Y-m-d H:i:s'),
                $inputTypeId,
                $rollbackComment,
                1
            ];

            $MsaDB->insert("inventory__{$deviceType}", $columns, $values);

            // Only adjust commission qty_produced for positive entries (produced devices),
            // not for negative entries (consumed components)
            if ($commissionId && $qty > 0) {
                $commission = $commissionRepository->getCommissionById($commissionId);
                $commission->addToQuantity(-$qty, 'qty_produced');
            }

            $totalCancelled++;
        }
    }

    $groupCount = !empty($transferGroupIds) ? count(array_filter(array_map('intval', explode(',', $transferGroupIds)))) : 0;
    $individualCount = !empty($entryIds) ? count(array_filter(array_map('intval', explode(',', $entryIds)))) - count($processedEntries) : 0;

    $message = "Pomyślnie cofnięto produkcję";
    if ($groupCount > 0 && $individualCount > 0) {
        $message .= " ({$groupCount} grup, {$individualCount} pojedynczych wpisów, razem {$totalCancelled} wpisów)";
    } elseif ($groupCount > 0) {
        $message .= " ({$groupCount} grup, {$totalCancelled} wpisów)";
    } else {
        $message .= " ({$individualCount} wpisów)";
    }

    echo json_encode([
        'success' => true,
        'transferGroupId' => '',
        'alerts' => $allAlerts,
        'message' => $message
    ]);

    $MsaDB->db->commit();

} catch (Exception $e) {
    $MsaDB->db->rollback();
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}