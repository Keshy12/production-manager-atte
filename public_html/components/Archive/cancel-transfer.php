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
        // Get transfer IDs from POST
        $transferIdsJson = $_POST['transfer_ids'] ?? '[]';
        $transferIds = json_decode($transferIdsJson, true);

        if (empty($transferIds) || !is_array($transferIds)) {
            echo json_encode([
                'success' => false,
                'message' => 'No transfer IDs provided'
            ]);
            return;
        }

        // Get device type from POST
        $deviceType = $_POST['device_type'] ?? '';
        $allowedTypes = ['sku', 'tht', 'smd', 'parts'];

        if (!in_array($deviceType, $allowedTypes)) {
            echo json_encode([
                'success' => false,
                'message' => 'Invalid or missing device type'
            ]);
            return;
        }

        // Get user ID from session
        $userId = $_SESSION['userid'] ?? 1;
        $now = date('Y-m-d H:i:s');

        // Begin transaction
        $MsaDB->db->beginTransaction();

        $cancelledCount = 0;
        $errors = [];
        $transfersToCancel = [];
        $cancelledTransferGroups = [];

        $tableName = "inventory__{$deviceType}";

        // First pass: collect all transfer data
        foreach ($transferIds as $transferId) {
            $transferId = (int)$transferId;

            // Query the specific device type table
            $check = $MsaDB->query("
                SELECT * FROM {$tableName}
                WHERE id = $transferId
                LIMIT 1
            ");

            if (empty($check)) {
                $errors[] = "Transfer ID $transferId not found in {$tableName}";
                continue;
            }

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
        $cancellationGroupId = $MsaDB->insert(
            'inventory__transfer_groups',
            ['created_by', 'notes', 'created_at'],
            [$userId, $notes, $now]
        );

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

        // Commit transaction
        $MsaDB->db->commit();

        // Prepare response message
        $message = "Pomyślnie anulowano $cancelledCount transferów";

        if (!empty($errors)) {
            $message .= ". Błędy: " . implode(', ', $errors);
        }

        echo json_encode([
            'success' => true,
            'message' => $message,
            'cancelled_count' => $cancelledCount,
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
