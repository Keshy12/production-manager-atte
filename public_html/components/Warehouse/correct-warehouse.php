<?php
$MsaDB = Atte\DB\MsaDB::getInstance();

$userid = $_SESSION["userid"];
$result = $_POST["result"] ?? [];
$type = $_POST["type"];
$device_id = $_POST["device_id"];
//input_type = korekta
$input_type_id = 3;
$comment = "Korekta magazynu przez stronę Magazyn (admin)";

foreach ($result as $magazine) {
    $magazine_id = $magazine[0];
    $difference = $magazine[1];
    $sql = "INSERT INTO `inventory__" . $type . "` (`" . $type . "_id`, `user_id`, `sub_magazine_id`, `quantity`, `input_type_id`, `comment`) 
    VALUES ('$device_id', '$userid', '$magazine_id', '$difference', '$input_type_id', '$comment')";
    $MsaDB -> query($sql);
}