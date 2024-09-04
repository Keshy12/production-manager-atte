<?php
use Atte\DB\MsaDB;

$MsaDB = MsaDB::getInstance();
$MsaDB -> db -> beginTransaction();

$wasSuccessful = true;

$bomType = $_POST['bomType'];
$bomId = $_POST['bomId'];
$isActive = $_POST['isActive'] == 'true';

$valuesToInsert = [
    "isActive" => $isActive,
];

if(empty($bomType) || empty($bomId)) {
    $wasSuccessful = false;
}

$querySuccessful = $MsaDB -> update("bom__".$bomType, $valuesToInsert, 'id', $bomId);

if($wasSuccessful && $querySuccessful) {
    $MsaDB -> db -> commit();
}
else {
    $MsaDB -> db -> rollBack();
    $wasSuccessful = false;
}

echo json_encode($wasSuccessful);
