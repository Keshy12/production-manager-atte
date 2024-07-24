<?php
use Atte\DB\MsaDB;

$MsaDB = MsaDB::getInstance();

$columns = ["login", "password", "name", "surname", "email", "isAdmin", "sub_magazine_id"];
$values = [
    $_POST["login"],
    hash('sha256', $_POST["password"]),
    $_POST["name"],
    $_POST["surname"],
    $_POST["email"],
    isset($_POST["isAdmin"]),
    $_POST["sub_magazine_id"]
];

$resultMessage = "Pomyślnie dodano użytkownika";
$wasSuccessful = true;
$insertedId = "";
try
{
    $insertedId = $MsaDB -> insert("user", $columns, $values);
}
catch (\Throwable $e)
{
    $resultMessage = "Wystąpił błąd przy dodawaniu. Kod błędu: ".$e->getMessage();
    $wasSuccessful = false;
}

echo json_encode([$resultMessage, $wasSuccessful, $insertedId], JSON_FORCE_OBJECT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);


 




