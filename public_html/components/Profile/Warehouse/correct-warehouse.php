<?php
$MsaDB = Atte\DB\MsaDB::getInstance();

$userId = $_SESSION["userid"];
$userRepository = new Atte\Utils\UserRepository($MsaDB);
$user = $userRepository -> getUserById($userId);
$userInfo = $user -> getUserInfo();

$subMagazineId = $userInfo["sub_magazine_id"];


$type = $_POST["type"];
$deviceId = $_POST["device_id"];
$quantityDifference = $_POST["difference"];
//input_type = korekta
$inputTypeId = 3;
$comment = "Korekta magazynu przez magazyn użytkownika";
$insertColumns = ["{$type}_id", "user_id", "sub_magazine_id", "quantity", "input_type_id", "comment"];

$insertValues = [$deviceId, $userId, $subMagazineId, $quantityDifference, $inputTypeId, $comment];

if($quantityDifference != 0) $MsaDB -> insert("inventory__{$type}", $insertColumns, $insertValues);
