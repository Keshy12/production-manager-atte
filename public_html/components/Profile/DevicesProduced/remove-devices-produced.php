<?php 
use Atte\DB\MsaDB;

$MsaDB = MsaDB::getInstance();

$deviceType = $_POST["type"];
$deviceId = $_POST["device_id"] ?? "";
$userId = $_SESSION["userid"];


$query = "DELETE FROM used__{$deviceType} WHERE `user_id` = {$userId} AND `{$deviceType}_id` = {$deviceId} ";

$result = [];
try {
    $MsaDB -> query($query);
}
catch(\Exception $e) {
    $queryResult = '
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        Usuwanie urządzenia nie powiodło się. Kod błędu: '.$e -> getMessage().'
        <button type="button" class="close" data-dismiss="alert" aria-label="Close">
            <span aria-hidden="true">&times;</span>
        </button>
    </div>
    ';
    $result = [$queryResult, false];
}
finally {
    $queryResult = '
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        Usunięto z powodzeniem.
        <button type="button" class="close" data-dismiss="alert" aria-label="Close">
            <span aria-hidden="true">&times;</span>
        </button>
    </div>
    ';
    $result = [$queryResult, true];
}
echo json_encode($result, JSON_FORCE_OBJECT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);