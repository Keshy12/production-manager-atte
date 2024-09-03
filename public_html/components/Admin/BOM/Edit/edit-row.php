<?php
use Atte\DB\MsaDB;

$MsaDB = MsaDB::getInstance();

$rowId = $_POST['rowId'];

$valuesToInsert = [
    "sku_id" => null,
    "tht_id" => null,
    "smd_id" => null,
    "parts_id" => null,
    "quantity" => $_POST["quantity"]
];

$valuesToInsert[$_POST['componentType'].'_id'] = $_POST['componentId'];

$wasSuccessful = $MsaDB -> update("bom__flat", $valuesToInsert, 'id', $rowId);

echo json_encode($wasSuccessful);
