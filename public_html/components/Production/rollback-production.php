<?php
use Atte\Utils\TransferGroupManager;

$MsaDB = Atte\DB\MsaDB::getInstance();
$userRepository = new Atte\Utils\UserRepository($MsaDB);
$user = $userRepository->getUserById($_SESSION["userid"]);
$userInfo = $user->getUserInfo();

$deviceType = $_POST["deviceType"] ?? "";
$deviceId = $_POST["deviceId"] ?? "";
$transferGroupIds = $_POST["transferGroupIds"] ?? ""; // Comma-separated list of transfer group IDs
$userId = $_SESSION["userid"];

$MsaDB->db->beginTransaction();
try {
    if (empty($transferGroupIds)) {
        throw new Exception("Brak grup transferów do cofnięcia");
    }

    // Parse the comma-separated list of transfer group IDs
    $groupIdsToCancel = array_map('intval', explode(',', $transferGroupIds));

    $transferGroupManager = new TransferGroupManager($MsaDB);

    $totalCancelled = 0;
    $allAlerts = [];

    foreach ($groupIdsToCancel as $groupId) {
        $result = $transferGroupManager->cancelTransferGroup($groupId, $userId);
        $totalCancelled += $result['itemsCancelled'];
        $allAlerts = array_merge($allAlerts, $result['alerts']);
    }

    // Return the first cancelled group ID for highlighting
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