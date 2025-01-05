<?php
use Atte\DB\MsaDB;
use Atte\Utils\BomRepository;
use Atte\Utils\CommissionRepository;

$MsaDB = MsaDB::getInstance();
$usersName = $MsaDB -> readIdName("user", "user_id", "name");
$usersSurname = $MsaDB -> readIdName("user", "user_id", "surname");
$users = [];
foreach($usersName as $key => $name){
   $users[$key] = $name." ".$usersSurname[$key];
}
$magazines = $MsaDB -> readIdName("magazine__list", "sub_magazine_id", "sub_magazine_name", "ORDER BY type_id ASC");
$list__laminate = $MsaDB -> readIdName("list__laminate");
$list__sku = $MsaDB -> readIdName("list__sku");
$list__sku_desc = $MsaDB -> readIdName("list__sku", "id", "description");
$list__tht = $MsaDB -> readIdName("list__tht");
$list__tht_desc = $MsaDB -> readIdName("list__tht", "id", "description");
$list__smd = $MsaDB -> readIdName("list__smd");
$list__smd_desc = $MsaDB -> readIdName("list__smd", "id", "description");

$statements = [1];
$transferFrom = !empty($_POST["transferFrom"]) || $_POST["transferFrom"] == '0'
                    ? $_POST["transferFrom"] 
                    : false;
$transferTo = !empty($_POST["transferTo"]) || $_POST["transferTo"] == '0'
                    ? $_POST["transferTo"] 
                    : false;
$device = array_filter($_POST["device"]);
$receivers = $_POST["receivers"] ?? false;
$state_id = $_POST["state_id"] ?? false;
$priority_id = $_POST["priority_id"] ?? false;
$showCancelled = $_POST["showCancelled"] == "true";
$page = $_POST["page"];
$offset = ($page-1)*10;
if($transferFrom !== false) $statements[] = "magazine_from = ".$transferFrom;
if($transferTo !== false) $statements[] = "magazine_to = ".$transferTo;
$commissionRepository = new CommissionRepository($MsaDB);
$bomRepository = new BomRepository($MsaDB);


/**
 * Get the bom_id from the user input
 * If not all needed values are set, bom_id = false
 */
$bom_id = false;
if(!empty($device)) {
    $type = $device[0] ?? null;
    $deviceId = $device[1] ?? null;
    $laminateId = $device[2] ?? null;
    $version = $device[3] ?? null;

    $bomValues = [
        $type."_id" => $deviceId,
        "laminate_id" => $laminateId,
        "version" => $version
    ];

    $bomValues = array_filter($bomValues, function($value) {
        return !is_null($value);
    });
    if($bomValues['version'] == 'n/d') $bomValues['version'] = NULL;
    
    $bomsFound = $bomRepository -> getBomByValues($type, $bomValues);
    foreach($bomsFound as $bom)
    {
        $bom_id = $bom -> id;
        $statements[] = "bom_".$type."_id = ".$bom_id;
    }

}

if($receivers) {
    $receiversStatement = array();
    foreach($receivers as $receiver) { 
        $receiversStatement[] = "cr.user_id = ".$receiver;
    }
    $statements[] = "(".implode(" OR ", $receiversStatement).")";
}

if($state_id) {
    $stateStatement = array();
    foreach($state_id as $state) { 
        $stateStatement[] = "state_id = ".$state;
    }
    $statements[] = "(".implode(" OR ", $stateStatement).")";
}

if($priority_id) {
    $priorityStatement = array();
    foreach($priority_id as $priority) { 
        $priorityStatement[] = "priority = ".$priority;
    }
    $statements[] = "(".implode(" OR ", $priorityStatement).")";
}

if(!$showCancelled) {
    $statements[] = "isCancelled = 0";
}

$add = implode(" AND ", $statements);
$commissions = [];
$resultQuery = $MsaDB -> query("SELECT cl.id FROM `commission__list` cl JOIN commission__receivers cr on cl.id = cr.commission_id WHERE $add GROUP BY cl.id ORDER BY cl.timestamp_created DESC LIMIT 11 OFFSET $offset;", PDO::FETCH_COLUMN);
$nextPageAvailable = false;
if(isset($resultQuery[10]))
{
    unset($resultQuery[10]);
    $nextPageAvailable = true;
}
foreach($resultQuery as $id)
{
    $commissions[] = $commissionRepository -> getCommissionById($id);
}
$result = [];
foreach($commissions as $commission) { 
    $type = $commission -> deviceType;
    $row = $commission -> commissionValues;
    $colors = ["none", "green", "yellow", "red"];
    $bomId = $row["bom_".$type."_id"];
    $state_id = $row["state_id"];
    $deviceBom = $bomRepository -> getBomById($type, $bomId);
    $deviceId = $deviceBom -> deviceId;
    $device_version = $deviceBom -> version;
    $device_laminate_id = $deviceBom -> laminateId;
    $device_laminate = !is_null($deviceBom -> laminateId) ? "Laminat: <b>".$list__laminate[$device_laminate_id]."</b>" : "" ;
    $device_laminate_and_version = $device_laminate." Wersja: <b>".$device_version."</b>";
    $isCancelled = $row["isCancelled"];
    $receivers = $commission -> getReceivers();
    $classes = ['list-group-item-primary', '', 'list-group-item-secondary', 'bg-secondary'];
    $class = $classes[$state_id];
    if($isCancelled == 1 ) $class = 'list-group-item-danger';
    $receiversName = array();
    foreach($receivers as $receiver) {
        $receiversName[] = $users[$receiver];
    }
    $result[] = [
        "class" => $class,
        "class2" => ($state_id!=3) ? 'text-muted' : '',
        "class3" => ($state_id!=1||$isCancelled==1)?'table-light':'',
        "isHidden" => $isCancelled == 1 ? "hidden" : "",
        "color" => $colors[$row["priority"]],
        "magazineFromId" => $row["magazine_from"],
        "magazineFrom" => $magazines[$row["magazine_from"]],
        "magazineToId" => $row["magazine_to"],
        "magazineTo" => $magazines[$row["magazine_to"]],
        "isCancelled" => $isCancelled,
        "id" => $row["id"],
        "receivers" => implode(", ", $receivers),
        "priority" => $row['priority'],
        "deviceDescription" => ${"list__".$type."_desc"}[$deviceId],
        "deviceName" => ${"list__".$type}[$deviceId],
        "deviceLaminateAndVersion" => $device_laminate_and_version,
        "stateId" => $state_id,
        "receiversName" => implode(", ", $receiversName),
        "quantity" => $row["quantity"],
        "quantityProduced" => $row["quantity_produced"],
        "quantityReturned" => $row["quantity_returned"],
        "timestampCreated" => $row["timestamp_created"]
    ];
}
echo json_encode([$result, $nextPageAvailable]);