<?php
use Atte\DB\MsaDB;
use Atte\Utils\CommissionRepository;

header('Content-Type: application/json');

$MsaDB = MsaDB::getInstance();
$MsaDB->db->beginTransaction();

$response = ['success' => false, 'message' => ''];

try {
    $commissionId = $_POST['commissionId'];
    $cancellationType = $_POST['cancellationType']; // 'single' or 'group'
    $rollbackEnabled = $_POST['rollbackEnabled'] === 'true';
    $rollbackDistribution = json_decode($_POST['rollbackDistribution'], true);

    $commissionRepository = new CommissionRepository($MsaDB);
    $commission = $commissionRepository->getCommissionById($commissionId);

    $commissionGroupId = $commission->commissionValues['commission_group_id'];

    if ($cancellationType === 'group') {
        // Cancel entire group
        if (!$commissionGroupId) {
            throw new Exception("Commission is not part of a group");
        }

        $commissionGroup = $commissionRepository->getCommissionGroupById($commissionGroupId);

        // Cancel all commissions in the group
        foreach ($commissionGroup->commissions as $groupCommission) {
            $groupCommission->cancel();
        }

        // Perform rollback for entire group if enabled
        if ($rollbackEnabled && $rollbackDistribution) {
            performGroupRollback($MsaDB, $commissionGroup, $rollbackDistribution);
        }

        $response['message'] = 'Grupa zleceń została anulowana pomyślnie';

    } else {
        // Cancel single commission
        $commission->cancel();

        // Perform rollback for single commission if enabled
        if ($rollbackEnabled && $rollbackDistribution) {
            performSingleCommissionRollback($MsaDB, $commission, $rollbackDistribution);
        }

        $response['message'] = 'Zlecenie zostało anulowane pomyślnie';
    }

    $MsaDB->db->commit();
    $response['success'] = true;

} catch (Exception $e) {
    $MsaDB->db->rollBack();
    $response['message'] = $e->getMessage();
}

echo json_encode($response);

function performSingleCommissionRollback($MsaDB, $commission, $rollbackDistribution) {
    $commissionId = $commission->commissionValues['id'];
    $deviceType = $commission->deviceType;
    $userId = $_SESSION['user_id'] ?? 1;

    foreach ($rollbackDistribution as $componentKey => $sources) {
        // Parse component key (e.g., "smd_123" -> type: "smd", id: "123")
        $parts = explode('_', $componentKey);
        $type = $parts[0];
        $componentId = $parts[1];

        // Calculate total rollback quantity
        $totalRollbackQuantity = array_sum(array_column($sources, 'quantity'));

        if ($totalRollbackQuantity <= 0) continue;

        // Remove from destination magazine
        $destinationMagazineId = getDestinationMagazineId($MsaDB, $commissionId, $type, $componentId);

        $MsaDB->insert("inventory__$type", [
            $type . '_id',
            'commission_id',
            'user_id',
            'sub_magazine_id',
            'quantity',
            'input_type_id',
            'comment'
        ], [
            $componentId,
            $commissionId,
            $userId,
            $destinationMagazineId,
            -$totalRollbackQuantity,
            2, // Transfer type
            "Rollback - anulacja zlecenia #$commissionId"
        ]);

        // Add back to source magazines according to distribution
        foreach ($sources as $source) {
            if ($source['quantity'] > 0) {
                $MsaDB->insert("inventory__$type", [
                    $type . '_id',
                    'commission_id',
                    'user_id',
                    'sub_magazine_id',
                    'quantity',
                    'input_type_id',
                    'comment'
                ], [
                    $componentId,
                    $commissionId,
                    $userId,
                    $source['sourceId'],
                    $source['quantity'],
                    2, // Transfer type
                    "Rollback - anulacja zlecenia #$commissionId (powrót do źródła)"
                ]);
            }
        }
    }
}

function performGroupRollback($MsaDB, $commissionGroup, $rollbackDistribution) {
    $userId = $_SESSION['user_id'] ?? 1;
    $groupId = $commissionGroup->groupValues['id'];

    foreach ($rollbackDistribution as $componentKey => $sources) {
        // Parse component key
        $parts = explode('_', $componentKey);
        $type = $parts[0];
        $componentId = $parts[1];

        // Calculate total rollback quantity
        $totalRollbackQuantity = array_sum(array_column($sources, 'quantity'));

        if ($totalRollbackQuantity <= 0) continue;

        // Get all transfers for this component in the group
        $transfers = $MsaDB->query("
            SELECT i.*, c.id as commission_id
            FROM inventory__$type i
            LEFT JOIN commission__list c ON i.commission_id = c.id
            WHERE c.commission_group_id = $groupId 
            AND i.{$type}_id = $componentId
            AND i.quantity > 0
            ORDER BY i.quantity DESC
        ");

        $remainingToRollback = $totalRollbackQuantity;

        // Rollback from destination magazines
        foreach ($transfers as $transfer) {
            if ($remainingToRollback <= 0) break;

            $rollbackFromThis = min($remainingToRollback, $transfer['quantity']);

            // Remove from destination
            $MsaDB->insert("inventory__$type", [
                $type . '_id',
                'commission_id',
                'user_id',
                'sub_magazine_id',
                'quantity',
                'input_type_id',
                'comment'
            ], [
                $componentId,
                $transfer['commission_id'],
                $userId,
                $transfer['sub_magazine_id'],
                -$rollbackFromThis,
                2,
                "Rollback - anulacja grupy zleceń #$groupId"
            ]);

            $remainingToRollback -= $rollbackFromThis;
        }

        // Add back to source magazines according to distribution
        foreach ($sources as $source) {
            if ($source['quantity'] > 0) {
                // We need to distribute across commissions that used this source
                $sourceTransfers = $MsaDB->query("
                    SELECT i.commission_id, ABS(i.quantity) as quantity
                    FROM inventory__$type i
                    LEFT JOIN commission__list c ON i.commission_id = c.id
                    WHERE c.commission_group_id = $groupId 
                    AND i.{$type}_id = $componentId
                    AND i.sub_magazine_id = {$source['sourceId']}
                    AND i.quantity < 0
                ");

                $sourceTotal = array_sum(array_column($sourceTransfers, 'quantity'));

                foreach ($sourceTransfers as $sourceTransfer) {
                    if ($sourceTotal > 0) {
                        $proportion = $sourceTransfer['quantity'] / $sourceTotal;
                        $returnQuantity = round($source['quantity'] * $proportion);

                        if ($returnQuantity > 0) {
                            $MsaDB->insert("inventory__$type", [
                                $type . '_id',
                                'commission_id',
                                'user_id',
                                'sub_magazine_id',
                                'quantity',
                                'input_type_id',
                                'comment'
                            ], [
                                $componentId,
                                $sourceTransfer['commission_id'],
                                $userId,
                                $source['sourceId'],
                                $returnQuantity,
                                2,
                                "Rollback - anulacja grupy zleceń #$groupId (powrót do źródła)"
                            ]);
                        }
                    }
                }
            }
        }
    }
}

function getDestinationMagazineId($MsaDB, $commissionId, $type, $componentId) {
    $result = $MsaDB->query("
        SELECT sub_magazine_id 
        FROM inventory__$type 
        WHERE commission_id = $commissionId 
        AND {$type}_id = $componentId 
        AND quantity > 0 
        LIMIT 1
    ");

    return $result[0]['sub_magazine_id'] ?? null;
}
?>