<?php 
use Atte\DB\MsaDB;

$MsaDB = MsaDB::getInstance();

$userId = $_POST["user_selected"];
$userPassword = $MsaDB->query("SELECT password FROM user WHERE user_id = $userId", PDO::FETCH_COLUMN)[0];
$oldPassword = hash('sha256',$_POST['old-password']);
$newPassword = hash('sha256',$_POST['new-password']);
$confirmNewPassword = hash('sha256',$_POST['confirm-new-password']);

$result = "";
$changeSuccessfull = false;
if($userPassword != $oldPassword) $result = "Dane nieprawidłowe. Spróbuj ponownie.";
else if($confirmNewPassword != $newPassword) $result = "Podane nowe hasła nie są takie same.";

if(empty($result)) 
{
    $MsaDB -> update('user', ['password' => $newPassword], "user_id", $userId);
    $result = "Hasło zmienione z powodzeniem.";
    $changeSuccessfull = true;
}

echo json_encode([$changeSuccessfull, $result]);



