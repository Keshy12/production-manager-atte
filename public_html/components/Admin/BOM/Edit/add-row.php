<?php
use Atte\DB\MsaDB;

$MsaDB = MsaDB::getInstance();
$MsaDB -> db -> beginTransaction();

$wasSuccessful = true;

$bomType = $_POST["bomType"];
$bomId = $_POST["bomId"];
$componentType = $_POST["componentType"];
$componentId = $_POST["componentId"];
$quantity = $_POST["quantity"];

if(empty($componentType) || empty($componentId) || empty($quantity) || $quantity < 0) {
    $wasSuccessful = false;
}

$valuesToInsert = [
    'bom_'.$bomType.'_id' => $bomId,
    $componentType.'_id' => $componentId,
    'quantity' => $quantity
];

$columnsToInsert = array_keys($valuesToInsert);
$valuesToInsert = array_values($valuesToInsert);

try{
    $insertedId = $MsaDB -> insert('bom__flat', $columnsToInsert, $valuesToInsert);
}
catch (\Throwable $e)
{
    $wasSuccessful = false;
}

if($wasSuccessful) {
    $MsaDB -> db -> commit();
}
else {
    $MsaDB -> db -> rollBack();
}

echo json_encode($wasSuccessful);


        