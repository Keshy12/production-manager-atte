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

if($quantityDifference != 0) {
    // Fetch magazine name
    $res = $MsaDB->query("SELECT sub_magazine_name FROM magazine__list WHERE sub_magazine_id = " . (int)$subMagazineId);
    $magazineName = !empty($res) ? $res[0]['sub_magazine_name'] : 'Nieznany';

    // Create transfer group for this correction
    $transferGroupManager = new Atte\Utils\TransferGroupManager($MsaDB);
    $transferGroupId = $transferGroupManager->createTransferGroup($userId, 'warehouse_correct', [
        'magazine_name' => $magazineName
    ]);



    $insertColumns = ["{$type}_id", "sub_magazine_id", "qty", "input_type_id", "comment", "transfer_group_id"];
    $insertValues = [$deviceId, $subMagazineId, $quantityDifference, $inputTypeId, $comment, $transferGroupId];

    $MsaDB -> insert("inventory__{$type}", $insertColumns, $insertValues);
}
