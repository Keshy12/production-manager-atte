<?php

declare(strict_types=1);

use Atte\Utils\ProductionManager;

$MsaDB = Atte\DB\MsaDB::getInstance();

$userId = $_POST["user_id"];
$deviceId = $_POST["device_id"];
$deviceType = $_POST["device_type"];
$version = $_POST["version"];
$quantity = $_POST["qty"];
$comment = !empty($_POST["comment"]) ? $_POST["comment"] : "";
$productionDate = !empty($_POST["prod_date"]) ? "'" . $_POST["prod_date"] . "'" : NULL;
$laminateId = ($deviceType === 'smd' && !empty($_POST["laminate"])) ? $_POST["laminate"] : null;

$MsaDB->db->beginTransaction();
try {
    $productionManager = new ProductionManager($MsaDB);
    list($transferGroupId, $negativeQuantityItemsAlerts, $commissionAlerts) = $productionManager->produce(
        $userId,
        $deviceId,
        $version,
        $quantity,
        $comment,
        $productionDate,
        $deviceType,
        $laminateId
    );

    $allAlerts = array_merge($negativeQuantityItemsAlerts, $commissionAlerts);

    echo json_encode([$transferGroupId, $allAlerts]);
    $MsaDB->db->commit();
} catch (Exception $e) {
    echo $e->getMessage();
    $MsaDB->db->rollback();
}