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
    $type = $device[0] ?? false;
    $device_id = $device[1] ?? false;
    $laminate_id = $device[2] ?? false;
    $version = $device[3] ?? false;
    if($version == 'n/d') $version = "NULL";
    if($type && $device_id && $version) {
        if($type == "smd" && $laminate_id) { 
            $bom_id = $bomRepository -> getBomByValues($type, $device_id, $laminate_id, $version);
        }
        else {
            $bom_id = $bomRepository -> getBomByValues($type, $device_id, $version);
        }
    }
}
if($bom_id) {
    $statements[] = "bom_".$type."_id = ".$bom_id;
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
    $color = $colors[$row["priority"]];
    $id = $row["id"];
    $magazineFrom = $magazines[$row["magazine_from"]];
    $magazineTo = $magazines[$row["magazine_to"]];
    $bomId = $row["bom_".$type."_id"];
    $quantity = $row["quantity"];
    $quantity_produced = $row["quantity_produced"];
    $quantity_returned = $row["quantity_returned"];
    $timestamp_created = $row["timestamp_created"];
    $state_id = $row["state_id"];
    $deviceBom = $bomRepository -> getBomById($type, $bomId);
    $device_id = $deviceBom -> deviceId;
    $device_version = $deviceBom -> version;
    $device_laminate_id = $deviceBom -> laminateId;
    $device_laminate = !is_null($deviceBom -> laminateId) ? "Laminat: <b>".$list__laminate[$device_laminate_id]."</b>" : "" ;
    $device_laminate_and_version = $device_laminate." Wersja: <b>".$device_version."</b>";
    $device_name = ${"list__".$type}[$device_id];
    $device_description = ${"list__".$type."_desc"}[$device_id];
    $isCancelled = $row["isCancelled"];
    $receivers = $commission -> getReceivers();
    $receiversName = array();
    $classes = ['list-group-item-primary', '', 'list-group-item-secondary', 'bg-secondary'];
    $class = $classes[$state_id];
    if($isCancelled == 1 ) $class = 'list-group-item-danger';
    foreach($receivers as $receiver) {
        $receiversName[] = $users[$receiver];
    }
    $push = [
        "class" => $class,
        "class2" => ($state_id!=3) ? 'text-muted' : '',
        "class3" => ($state_id!=1||$isCancelled==1)?'table-light':'',
        "isHidden" => $isCancelled == 1 ? "hidden" : "",
        "color" => $color,
        "magazineFrom" => $magazineFrom,
        "magazineTo" => $magazineTo,
        "isCancelled" => $isCancelled,
        "id" => $id,
        "receivers" => implode(", ", $receivers),
        "priority" => $row['priority'],
        "deviceDescription" => $device_description,
        "deviceName" => $device_name,
        "deviceLaminateAndVersion" => $device_laminate_and_version,
        "stateId" => $state_id,
        "receiversName" => implode(", ", $receiversName),
        "quantity" => $quantity,
        "quantityProduced" => $quantity_produced,
        "quantityReturned" => $quantity_returned,
        "timestampCreated" => $timestamp_created
    ];
    $result[] = $push;
}
echo json_encode([$result, $nextPageAvailable]);