<?php 
use Atte\DB\MsaDB;

$MsaDB = MsaDB::getInstance();

$MsaDB -> db -> beginTransaction();

$deviceType = $_POST["type"];
$deviceIds = $_POST["device_id"] ?? "";
$userId = $_POST["userid"] ?? $_SESSION["userid"];

$deviceIds = is_array($deviceIds) ? $deviceIds : [$deviceIds];
$result = [];

$queryResult = '
<div class="alert alert-success alert-dismissible fade show" role="alert">
    Usunięto z powodzeniem.
    <button type="button" class="close" data-dismiss="alert" aria-label="Close">
        <span aria-hidden="true">&times;</span>
    </button>
</div>
';
$wasSuccessful = true;

foreach($deviceIds as $deviceId)
{
    $query = "DELETE FROM used__{$deviceType} WHERE `user_id` = {$userId} AND `{$deviceType}_id` = {$deviceId} ";
    try {
        $MsaDB -> query($query);
    }
    catch(\Exception $e) {
        $queryResult = '
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            Usuwanie urządzeń nie powiodło się. Kod błędu: '.$e -> getMessage().'
            <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                <span aria-hidden="true">&times;</span>
            </button>
        </div>
        ';
        $wasSuccessful = false;
    }
}

if($wasSuccessful) $MsaDB -> db -> commit();
else $MsaDB -> db -> rollBack();

$result = [$queryResult, $wasSuccessful];
echo json_encode($result, JSON_FORCE_OBJECT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);