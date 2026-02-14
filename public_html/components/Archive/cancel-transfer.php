<?php
use Atte\DB\MsaDB;

header('Content-Type: application/json');

$MsaDB = MsaDB::getInstance();

$action = $_POST['action'] ?? '';

if ($action === 'cancel_transfers') {
    cancelTransfers($MsaDB);
} else {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid action'
    ]);
}

function cancelTransfers($MsaDB) {
    try {
        // Get transfer IDs grouped by device type from POST
        $transferIdsByTypeJson = $_POST['transfer_ids_by_type'] ?? '{}';
        $transferIdsByType = json_decode($transferIdsByTypeJson, true);

        if (empty($transferIdsByType) || !is_array($transferIdsByType)) {
            echo json_encode([
                'success' => false,
                'message' => 'No transfer IDs provided'
            ]);
            return;
        }

        // Get transfer groups to cancel (if complete group cancellation)
        $cancelGroupsJson = $_POST['cancel_groups'] ?? '[]';
        $cancelGroups = json_decode($cancelGroupsJson, true) ?: [];

        // Get user ID from session
        $userId = $_SESSION['userid'] ?? 1;
        $now = $MsaDB->query("SELECT NOW() as now", \PDO::FETCH_ASSOC)[0]['now'];


        // Begin transaction
        $MsaDB->db->beginTransaction();

        $cancelledCount = 0;
        $errors = [];
        $transfersToCancel = [];
        $cancelledTransferGroups = [];

        $allowedTypes = ['sku', 'tht', 'smd', 'parts'];

        // First pass: collect all transfer data organized by device type
        foreach ($transferIdsByType as $deviceType => $transferIds) {
            // Validate device type
            if (!in_array($deviceType, $allowedTypes)) {
                $errors[] = "Invalid device type: $deviceType";
                continue;
            }

            // Sanitize transfer IDs
            $transferIds = array_map('intval', $transferIds);
            $tableName = "inventory__{$deviceType}";

            // Query transfers for this device type
            foreach ($transferIds as $transferId) {
                $transferId = (int)$transferId;

                // Query the specific device type table
                $check = $MsaDB->query("
                    SELECT * FROM {$tableName}
                    WHERE id = $transferId
                    LIMIT 1
                ");

                if (!empty($check)) {
                    $transfer = $check[0];

                    if ($transfer['is_cancelled'] == 1) {
                        $errors[] = "Transfer ID $transferId is already cancelled";
                        continue;
                    }

                    // Store transfer data for creating compensating entries
                    $transfersToCancel[] = [
                        'id' => $transferId,
                        'table' => $tableName,
                        'type' => $deviceType,
                        'data' => $transfer
                    ];

                    // Track cancelled transfer groups
                    if (!empty($transfer['transfer_group_id'])) {
                        $cancelledTransferGroups[$transfer['transfer_group_id']] = true;
                    }
                } else {
                    $errors[] = "Transfer ID $transferId not found in {$deviceType} inventory";
                }
            }
        }

        if (empty($transfersToCancel)) {
            echo json_encode([
                'success' => false,
                'message' => 'No valid transfers to cancel',
                'errors' => $errors
            ]);
            $MsaDB->db->rollBack();
            return;
        }

        // Build notes for the new cancellation transfer group
        $cancelledIds = array_map(function($t) { return $t['id']; }, $transfersToCancel);
        $cancelledGroupsList = array_keys($cancelledTransferGroups);

        $notes = "Anulowanie transferów: " . implode(', ', $cancelledIds);
        if (!empty($cancelledGroupsList)) {
            $notes .= " | Anulowane grupy: " . implode(', ', $cancelledGroupsList);
        }

        // Create new transfer group for compensating entries
        $transferGroupManager = new \Atte\Utils\TransferGroupManager($MsaDB);
        $cancellationGroupId = $transferGroupManager->createTransferGroup($userId, 'migration', ['note' => $notes]);


        // Second pass: mark original transfers as cancelled and create compensating entries
        foreach ($transfersToCancel as $transferInfo) {
            $transferId = $transferInfo['id'];
            $tableName = $transferInfo['table'];
            $deviceType = $transferInfo['type'];
            $transfer = $transferInfo['data'];

            // Mark original transfer as cancelled
            $result = $MsaDB->update($tableName, [
                'is_cancelled' => 1,
                'cancelled_at' => $now,
                'cancelled_by' => $userId
            ], 'id', $transferId);

            if (!$result) {
                $errors[] = "Failed to cancel transfer ID $transferId";
                continue;
            }

            // Create compensating entry with opposite quantity
            $compensatingQty = -1 * floatval($transfer['qty']);

            $insertFields = [
                $deviceType . '_id',
                'sub_magazine_id',
                'qty',
                'timestamp',
                'input_type_id',
                'comment',
                'transfer_group_id',
                'is_cancelled',
                'cancelled_at',
                'cancelled_by'
            ];

            $insertValues = [
                $transfer[$deviceType . '_id'],
                $transfer['sub_magazine_id'],
                $compensatingQty,
                $now,
                9, // Input type for cancellation (adjust if needed)
                "Anulowanie transferu ID: {$transferId}",
                $cancellationGroupId,
                1,
                $now,
                $userId
            ];

            // Add commission_id if exists
            if (!empty($transfer['commission_id'])) {
                $insertFields[] = 'commission_id';
                $insertValues[] = $transfer['commission_id'];
            }

            // Add BOM ID if exists (e.g., tht_bom_id, smd_bom_id, etc.)
            $bomIdField = $deviceType . '_bom_id';
            if (!empty($transfer[$bomIdField])) {
                $insertFields[] = $bomIdField;
                $insertValues[] = $transfer[$bomIdField];
            }

            $MsaDB->insert($tableName, $insertFields, $insertValues);
            $cancelledCount++;
        }

        // Mark the cancellation transfer group as cancelled
        $MsaDB->update(
            'inventory__transfer_groups',
            [
                'is_cancelled' => 1,
                'cancelled_at' => $now,
                'cancelled_by' => $userId
            ],
            'id',
            $cancellationGroupId
        );

        // Mark original transfer groups as cancelled if complete group cancellation
        $cancelledGroupIds = [];
        if (!empty($cancelGroups)) {
            foreach ($cancelGroups as $groupId) {
                $groupId = (int)$groupId;
                $MsaDB->update(
                    'inventory__transfer_groups',
                    [
                        'is_cancelled' => 1,
                        'cancelled_at' => $now,
                        'cancelled_by' => $userId
                    ],
                    'id',
                    $groupId
                );
                $cancelledGroupIds[] = $groupId;
            }
        }

        // Commit transaction
        $MsaDB->db->commit();

        // Prepare response message
        $message = "Pomyślnie anulowano $cancelledCount transferów";

        if (!empty($cancelledGroupIds)) {
            $groupCount = count($cancelledGroupIds);
            $message .= " i $groupCount " . ($groupCount === 1 ? 'grupę transferów' : 'grup transferów');
        }

        if (!empty($errors)) {
            $message .= ". Błędy: " . implode(', ', $errors);
        }

        echo json_encode([
            'success' => true,
            'message' => $message,
            'cancelled_count' => $cancelledCount,
            'cancelled_groups' => $cancelledGroupIds,
            'cancellation_group_id' => $cancellationGroupId,
            'errors' => $errors
        ]);

    } catch (Exception $e) {
        // Rollback on error
        if ($MsaDB->db->inTransaction()) {
            $MsaDB->db->rollBack();
        }

        echo json_encode([
            'success' => false,
            'message' => 'Error during cancellation: ' . $e->getMessage()
        ]);
    }
}
