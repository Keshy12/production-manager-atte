<?php
use Atte\DB\MsaDB;

$MsaDB = MsaDB::getInstance();

$userId = $_POST["user_id"];
$userInfo = [
    "login" => $_POST["login"],
    "name" => $_POST["name"],
    "surname" => $_POST["surname"],
    "email" => $_POST["email"],
    "sub_magazine_id" => $_POST["sub_magazine_id"]
];


$resultMessage = "Zedytowano dane pomyślnie";
$wasSuccessful = true;
try
{
    $MsaDB -> update("user", $userInfo, "user_id", $userId);
}
catch(\Throwable $e)
{
    $resultMessage = "Wystąpił błąd. Treść błędu: ".$e->getMessage();
    $wasSuccessful = false;
}

echo json_encode([$resultMessage, $wasSuccessful], JSON_FORCE_OBJECT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);

