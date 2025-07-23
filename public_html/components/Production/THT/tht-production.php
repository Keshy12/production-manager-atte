<?php
use Atte\Utils\ProductionManager;

$MsaDB = Atte\DB\MsaDB::getInstance();

$userId         = $_POST["user_id"];
$deviceId       = $_POST["device_id"];
$version        = $_POST["version"];
$quantity       = $_POST["qty"];
$comment        = !empty($_POST["comment"]) ? $_POST["comment"] : 'Produkcja przez Formularz Produkcja THT';
$productionDate = !empty($_POST["prod_date"]) ? "'".$_POST["prod_date"]."'" : NULL;

$MsaDB->db->beginTransaction();
try {
    $productionManager = new ProductionManager($MsaDB);
    list($firstInsertedId, $lastInsertedId, $negativeQuantityItemsAlerts, $commissionAlerts) = $productionManager->produce($userId, $deviceId, $version, $quantity, $comment, $productionDate, 'tht');

    $allAlerts = array_merge($negativeQuantityItemsAlerts, $commissionAlerts);

    echo json_encode([$firstInsertedId, $lastInsertedId, $allAlerts]);
    $MsaDB->db->commit();
} catch (Exception $e) {
    echo $e->getMessage();
    $MsaDB->db->rollback();
}