<?php 
use Atte\DB\MsaDB;

$MsaDB = MsaDB::getInstance();

$deviceType = $_POST["type"];
$deviceIds = $_POST["device_id"] ?? [];
$userId = $_POST["userid"] ?? $_SESSION["userid"];

$list__device = $MsaDB -> readIdName("list__{$deviceType}");
$list__device_desc = $MsaDB -> readIdName("list__{$deviceType}", "id", "description");

$insertValues = [];
$insertedDevices = [];
foreach($deviceIds as $deviceId)
{
    $insertedDevices[] = "
    <div class='{$deviceType}-{$deviceId} mt-3'>
        <b class='name'>{$list__device[$deviceId]}</b>
        <button type='button' data-type='{$deviceType}' data-id='{$deviceId}' class='close removeDevice' aria-label='Close'>
            <span aria-hidden='true'>×</span>
        </button>
        <br>
        <small class='description'>{$list__device_desc[$deviceId]}</small>
        <br>
    </div>";
    $insertValues[] = "('{$userId}', '{$deviceId}')";
}
$insertValues = implode(",", $insertValues);

$query = "INSERT INTO `used__{$deviceType}` (`user_id`, `{$deviceType}_id`) VALUES {$insertValues}";

$caught = false;
$result = [];
try {
    $MsaDB -> query($query);
}
catch(\Exception $e) {
    $queryResult = '
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        Dodawanie urządzeń nie powiodło się. Kod błędu: '.$e -> getMessage().'
        <button type="button" class="close" data-dismiss="alert" aria-label="Close">
            <span aria-hidden="true">&times;</span>
        </button>
    </div>
    ';
    $result = [$queryResult];
    $caught = true;
}
if(!$caught) {
    $queryResult = '
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        Dodano z powodzeniem.
        <button type="button" class="close" data-dismiss="alert" aria-label="Close">
            <span aria-hidden="true">&times;</span>
        </button>
    </div>
    ';
    $result = [$queryResult, $insertedDevices];
}
echo json_encode($result, JSON_FORCE_OBJECT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);