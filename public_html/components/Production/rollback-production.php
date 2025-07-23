<?php
use Atte\Utils\ProductionManager;
use Atte\Utils\BomRepository;
use Atte\Utils\CommissionRepository;

$MsaDB = Atte\DB\MsaDB::getInstance();
$userRepository = new Atte\Utils\UserRepository($MsaDB);
$bomRepository = new BomRepository($MsaDB);
$commissionRepository = new CommissionRepository($MsaDB);
$user = $userRepository->getUserById($_SESSION["userid"]);
$userInfo = $user->getUserInfo();

$deviceType = $_POST["deviceType"] ?? "";
$deviceId = $_POST["deviceId"] ?? "";
$rollbackIds = $_POST["rollbackIds"] ?? ""; // Comma-separated list of IDs to rollback
$subMagazineId = $userInfo["sub_magazine_id"];

$MsaDB->db->beginTransaction();
try {
    if (empty($rollbackIds)) {
        throw new Exception("Brak wpisów do cofnięcia");
    }

    // Parse the comma-separated list of IDs
    $idsToRollback = array_map('intval', explode(',', $rollbackIds));
    $idsString = implode(',', $idsToRollback);

    // Build different queries based on device type
    if ($deviceType === 'smd') {
        $selectQuery = "
            SELECT i.id, i.quantity, i.user_id, i.timestamp, i.comment, i.commission_id, i.smd_bom_id
            FROM `inventory__smd` i 
            JOIN list__smd l on i.smd_id = l.id 
            WHERE i.id IN ({$idsString})
            AND l.id = '$deviceId' 
            AND i.sub_magazine_id = '{$subMagazineId}' 
            AND input_type_id = 4 
            ORDER BY i.`id` DESC
        ";
    } else {
        $selectQuery = "
            SELECT i.id, i.quantity, i.user_id, i.timestamp, i.comment, i.commission_id, i.tht_bom_id
            FROM `inventory__tht` i 
            JOIN list__tht l on i.tht_id = l.id 
            WHERE i.id IN ({$idsString})
            AND l.id = '$deviceId' 
            AND i.sub_magazine_id = '{$subMagazineId}' 
            AND input_type_id = 4 
            ORDER BY i.`id` DESC
        ";
    }

    // Get the specific entries to rollback
    $entriesToRollback = $MsaDB->query($selectQuery);

    if (empty($entriesToRollback)) {
        throw new Exception("Nie znaleziono wpisów do cofnięcia");
    }

    $rollbackResultIds = [];
    $totalRollbackQuantity = 0;
    $allAlerts = [];

    // Process each entry to rollback
    foreach ($entriesToRollback as $entry) {
        $entryId = $entry[0];
        $quantity = $entry[1];
        $userId = $entry[2];
        $commissionId = $entry[5] ?? null; // commission_id might be null
        $bomId = $entry[6]; // This is smd_bom_id or tht_bom_id

        $rollbackQuantity = -$quantity; // Reverse the quantity
        $rollbackComment = "ROLLBACK: " . $entry[4] . " (ID: " . $entryId . ")";
        $totalRollbackQuantity += abs($quantity);

        // Get BOM details using BomRepository
        $bom = $bomRepository->getBomById($deviceType, $bomId);
        $version = $bom->version;
        $laminateId = $deviceType === 'smd' ? $bom->laminateId : null;

        // The quantity from the database entry represents the original operation
        // To rollback, we need to do the opposite operation
        // So we just pass the original quantity as-is, rollback method will handle the reversal
        $rollbackComment = "ROLLBACK: " . $entry[4] . " (ID: " . $entryId . ")";

        // Create rollback entry using ProductionManager's rollback method
        $productionManager = new ProductionManager($MsaDB);

        if ($deviceType === 'smd') {
            list($rollbackFirstId, $rollbackLastId, $alerts, $commissionAlerts) = $productionManager->rollback(
                $userId,
                $deviceId,
                $version,
                $quantity, // Pass original quantity, rollback method will reverse it
                $rollbackComment,
                NULL, // current timestamp
                'smd',
                $laminateId,
                $commissionId
            );
        } else {
            list($rollbackFirstId, $rollbackLastId, $alerts, $commissionAlerts) = $productionManager->rollback(
                $userId,
                $deviceId,
                $version,
                $quantity, // Pass original quantity, rollback method will reverse it
                $rollbackComment,
                NULL, // current timestamp
                'tht',
                null,
                $commissionId
            );
        }

        $rollbackResultIds[] = $rollbackFirstId;
        if ($rollbackLastId !== $rollbackFirstId) {
            $rollbackResultIds[] = $rollbackLastId;
        }

        // Merge alerts
        $allAlerts = array_merge($allAlerts, $alerts, $commissionAlerts);

        // Note: Commission quantity updates are handled automatically by ProductionManager->produce()
        // when processing negative quantities (rollbacks)
    }

    // Create ID range for highlighting rollback entries
    if (!empty($rollbackResultIds)) {
        $firstRollbackId = min($rollbackResultIds);
        $lastRollbackId = max($rollbackResultIds);
        $idRange = ($firstRollbackId === $lastRollbackId) ? $firstRollbackId : $firstRollbackId . '-' . $lastRollbackId;
    } else {
        $idRange = '';
    }

    echo json_encode([
        'success' => true,
        'rollbackIdRange' => $idRange,
        'rollbackIds' => $rollbackResultIds,
        'alerts' => $allAlerts,
        'message' => "Pomyślnie cofnięto produkcję ({$totalRollbackQuantity} szt., " . count($entriesToRollback) . " wpisów)"
    ]);

    $MsaDB->db->commit();

} catch (Exception $e) {
    $MsaDB->db->rollback();
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}