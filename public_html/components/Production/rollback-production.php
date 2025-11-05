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
$userId = $_SESSION["userid"];

$MsaDB->db->beginTransaction();
try {
    if (empty($transferGroupIds)) {
        throw new Exception("Brak grup transferów do cofnięcia");
    }

    $groupIdsToCancel = array_map('intval', explode(',', $transferGroupIds));

    $transferGroupManager = new TransferGroupManager($MsaDB);
    $commissionRepository = new CommissionRepository($MsaDB);

    $totalCancelled = 0;
    $allAlerts = [];

    foreach ($groupIdsToCancel as $groupId) {
        $entries = $MsaDB->query("
            SELECT id, {$deviceType}_id, qty, comment, transfer_group_id, sub_magazine_id, 
                   {$deviceType}_bom_id, commission_id, input_type_id
            FROM inventory__{$deviceType}
            WHERE transfer_group_id = {$groupId} AND is_cancelled = 0
        ");

        foreach ($entries as $entry) {
            $entryId = $entry['id'];
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

    $highlightGroupId = !empty($groupIdsToCancel) ? $groupIdsToCancel[0] : '';

    echo json_encode([
        'success' => true,
        'transferGroupId' => $highlightGroupId,
        'alerts' => $allAlerts,
        'message' => "Pomyślnie cofnięto produkcję (" . count($groupIdsToCancel) . " grup, " . $totalCancelled . " wpisów)"
    ]);

    $MsaDB->db->commit();

} catch (Exception $e) {
    $MsaDB->db->rollback();
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}