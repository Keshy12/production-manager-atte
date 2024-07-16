<?php
$MsaDB = Atte\DB\MsaDB::getInstance();

$userId = $_SESSION["userid"];
$result = $_POST["result"] ?? [];
$type = $_POST["type"];
$deviceId = $_POST["device_id"];
//input_type = korekta
$inputTypeId = 3;
$comment = "Korekta magazynu przez stronę Magazyn (admin)";
$insertColumns = ["{$type}_id", "user_id", "sub_magazine_id", "quantity", "input_type_id", "comment"];

foreach ($result as $magazine) {
    $subMagazineId = $magazine[0];
    $quantityDifference = $magazine[1];
    $insertValues = [$deviceId, $userId, $subMagazineId, $quantityDifference, $inputTypeId, $comment];
    $MsaDB -> insert("inventory__{$type}", $insertColumns, $insertValues);
}