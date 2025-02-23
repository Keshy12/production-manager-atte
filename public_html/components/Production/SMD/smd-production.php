<?php
use Atte\Utils\ProductionManager;

$MsaDB = Atte\DB\MsaDB::getInstance();

$userId         = $_POST["user_id"];
$deviceId       = $_POST["device_id"];
$version        = $_POST["version"];
$laminateId     = $_POST["laminate"];
$quantity       = $_POST["qty"];
$comment        = !empty($_POST["comment"]) ? $_POST["comment"] : 'Produkcja przez Formularz Produkcja SMD';
$productionDate = !empty($_POST["prod_date"]) ? "'".$_POST["prod_date"]."'" : NULL;

$MsaDB->db->beginTransaction();
try {
    $productionManager = new ProductionManager($MsaDB);
    list($firstInsertedId, $negativeQuantityItemsAlerts) = $productionManager->produce($userId, $deviceId, $version, $quantity, $comment, $productionDate, 'smd', $laminateId);
    echo json_encode([$firstInsertedId, $negativeQuantityItemsAlerts]);
    $MsaDB->db->commit();
} catch (Exception $e) {
    echo $e->getMessage();
    $MsaDB->db->rollback();
}
