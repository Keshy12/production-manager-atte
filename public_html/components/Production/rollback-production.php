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
            $entries = $MsaDB->query("
                SELECT id, {$deviceType}_id, qty, comment, transfer_group_id, sub_magazine_id,
                       {$deviceType}_bom_id, commission_id, input_type_id
                FROM inventory__{$deviceType}
                WHERE transfer_group_id = {$groupId} AND is_cancelled = 0
            ");

            foreach ($entries as $entry) {
                $entryId = $entry['id'];
                $processedEntries[$entryId] = true;

                $deviceItemId = $entry["{$deviceType}_id"];
                $qty = $entry['qty'];
                $comment = $entry['comment'];
                $transferGroupId = $entry['transfer_group_id'];
                $subMagazineId = $entry['sub_magazine_id'];
                $bomId = $entry["{$deviceType}_bom_id"];
                $commissionId = $entry['commission_id'];
                $inputTypeId = $entry['input_type_id'];

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
                $rollbackComment = "ROLLBACK: {$comment} (ID: {$entryId})";

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
                    $transferGroupId,
                    1,
                    date('Y-m-d H:i:s'),
                    $userId,
                    date('Y-m-d H:i:s'),
                    $inputTypeId,
                    $rollbackComment,
                    1
                ];

                $MsaDB->insert("inventory__{$deviceType}", $columns, $values);

                if ($commissionId) {
                    $commission = $commissionRepository->getCommissionById($commissionId);
                    $commission->addToQuantity(-$qty, 'qty_produced');
                }

                $totalCancelled++;
            }

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
            $cancellationNote = "Anulacja pojedynczego wpisu produkcji #$entryId";
            $newTransferGroupId = $transferGroupManager->createTransferGroup($userId, $cancellationNote);

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

            if ($commissionId) {
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