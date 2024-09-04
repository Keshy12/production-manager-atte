<?php
use Atte\DB\MsaDB;

$MsaDB = MsaDB::getInstance();
$MsaDB -> db -> beginTransaction();

$wasSuccessful = true;

$rowId = $_POST['rowId'];

$quantity = $_POST["quantity"];

$valuesToInsert = [
    "sku_id" => null,
    "tht_id" => null,
    "smd_id" => null,
    "parts_id" => null,
    "quantity" => $quantity
];

if(empty($_POST['componentType']) || empty($_POST['componentId']) || empty($quantity) || $quantity < 0) {
    $wasSuccessful = false;
}

$valuesToInsert[$_POST['componentType'].'_id'] = $_POST['componentId'];

$querySuccessful = $MsaDB -> update("bom__flat", $valuesToInsert, 'id', $rowId);

if($wasSuccessful && $querySuccessful) {
    $MsaDB -> db -> commit();
}
else {
    $MsaDB -> db -> rollBack();
    $wasSuccessful = false;
}


echo json_encode($wasSuccessful);
