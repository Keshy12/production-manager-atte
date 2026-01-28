<?php
use Atte\Utils\TransferGroupManager;

$MsaDB = Atte\DB\MsaDB::getInstance();

$userId = $_SESSION["userid"];
$result = $_POST["result"] ?? [];
$type = $_POST["type"];
$deviceId = $_POST["device_id"];
$inputTypeId = 3;
$comment = "Korekta magazynu przez stronę Magazyn (admin)";

// Start transaction
$MsaDB->db->beginTransaction();

try {
    // Validate input
    if (empty($result) || !is_array($result)) {
        throw new \Exception("Brak danych do korekty magazynu");
    }

    if (empty($type) || empty($deviceId)) {
        throw new \Exception("Nieprawidłowe parametry korekty");
    }

    // Fetch magazine names for the transfer group note
    $magazineNames = [];
    foreach ($result as $magazine) {
        $subMagazineId = (int)$magazine[0];
        $res = $MsaDB->query("SELECT sub_magazine_name FROM magazine__list WHERE sub_magazine_id = $subMagazineId");
        if (!empty($res)) {
            $magazineNames[] = $res[0]['sub_magazine_name'];
        }
    }
    $magazineNameString = implode(', ', array_unique($magazineNames));

    // Create ONE transfer group for all corrections
    $transferGroupManager = new TransferGroupManager($MsaDB);
    $transferGroupId = $transferGroupManager->createTransferGroup(
        $userId,
        'warehouse_correct',
        ['magazine_name' => $magazineNameString]
    );



    if (!$transferGroupId || $transferGroupId <= 0) {
        throw new \Exception("Nie udało się utworzyć grupy transferów");
    }

    // Build column list - now includes transfer_group_id AND verifiedBy
    $insertColumns = [
        "{$type}_id",
        "sub_magazine_id",
        "qty",
        "input_type_id",
        "comment",
        "transfer_group_id",
        "verifiedBy"
    ];

    // Add BOM column if needed (sku, smd, tht have it, parts doesn't)
    if ($type !== 'parts') {
        $bomColumn = $type . "_bom_id";
    }

    // Process each magazine correction
    foreach ($result as $magazine) {
        $subMagazineId = $magazine[0];
        $quantityDifference = $magazine[1];

        $insertValues = [
            $deviceId,
            $subMagazineId,
            $quantityDifference,
            $inputTypeId,
            $comment,
            $transferGroupId,  // Same ID for all magazines
            $userId
        ];

        $MsaDB->insert("inventory__{$type}", $insertColumns, $insertValues);
    }

    // Commit transaction
    $MsaDB->db->commit();

    // Return success
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'message' => 'Korekta magazynu została pomyślnie zapisana',
        'transferGroupId' => $transferGroupId,
        'itemsProcessed' => count($result)
    ]);

} catch (\Exception $e) {
    // Rollback on error
    $MsaDB->db->rollback();

    // Return error
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'message' => 'Błąd podczas korekty magazynu: ' . $e->getMessage()
    ]);
}
