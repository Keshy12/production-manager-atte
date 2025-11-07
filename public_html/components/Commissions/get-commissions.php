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
$groupTogether = $_POST["groupTogether"] == "true";

$page = isset($_POST["page"]) ? (int)$_POST["page"] : 1;
$page = max(1, $page);
$offset = ($page - 1) * 10;

if($transferFrom !== false) $statements[] = "warehouse_from_id = ".$transferFrom;
if($transferTo !== false) $statements[] = "warehouse_to_id = ".$transferTo;
$commissionRepository = new CommissionRepository($MsaDB);
$bomRepository = new BomRepository($MsaDB);

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
        $statements[] = "device_type = '".$type."' AND bom_id = ".$bom_id;
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
        $stateStatement[] = "state = '".$state."'";
    }
    $statements[] = "(".implode(" OR ", $stateStatement).")";
}

if($priority_id) {
    $priorityStatement = array();
    foreach($priority_id as $priority) {
        $priorityStatement[] = "priority = '".$priority."'";
    }
    $statements[] = "(".implode(" OR ", $priorityStatement).")";
}

if(!$showCancelled) {
    $statements[] = "is_cancelled = 0";
}

$add = implode(" AND ", $statements);

$allIdsQuery = $MsaDB -> query(
    "SELECT DISTINCT cl.id 
     FROM `commission__list` cl 
     JOIN commission__receivers cr on cl.id = cr.commission_id 
     WHERE $add 
     ORDER BY cl.created_at DESC",
    PDO::FETCH_COLUMN
);

$nextPageAvailable = false;
if(isset($resultQuery[10]))
{
    unset($resultQuery[10]);
    $nextPageAvailable = true;
}

$groupedCommissionsMap = [];
$potentialGroupsMap = [];

foreach($allIdsQuery as $id) {
    $commission = $commissionRepository->getCommissionById($id);
    $type = $commission->deviceType;
    $row = $commission->commissionValues;
    $bomId = $row["bom_id"];
    $receivers = implode(',', $commission->getReceivers());

    $groupKey = $type.'_'.$bomId.'_'.$row["warehouse_from_id"].'_'.$row["warehouse_to_id"].'_'.$receivers.'_'.$row["priority"];

    if(!isset($potentialGroupsMap[$groupKey])) {
        $potentialGroupsMap[$groupKey] = 1;
    } else {
        $potentialGroupsMap[$groupKey]++;
    }
}

if($groupTogether) {
    $groupedCommissions = [];
    foreach($allIdsQuery as $id) {
        $commission = $commissionRepository->getCommissionById($id);
        $type = $commission->deviceType;
        $row = $commission->commissionValues;
        $bomId = $row["bom_id"];
        $receivers = implode(',', $commission->getReceivers());

        $groupKey = $type.'_'.$bomId.'_'.$row["warehouse_from_id"].'_'.$row["warehouse_to_id"].'_'.$receivers.'_'.$row["priority"];

        if(!isset($groupedCommissionsMap[$groupKey])) {
            $groupedCommissionsMap[$groupKey] = [
                'commission' => $commission,
                'ids' => [$id],
                'totalQuantity' => (int)$row["qty"],
                'totalProduced' => (int)$row["qty_produced"],
                'totalReturned' => (int)$row["qty_returned"]
            ];
        } else {
            $groupedCommissionsMap[$groupKey]['ids'][] = $id;
            $groupedCommissionsMap[$groupKey]['totalQuantity'] += (int)$row["qty"];
            $groupedCommissionsMap[$groupKey]['totalProduced'] += (int)$row["qty_produced"];
            $groupedCommissionsMap[$groupKey]['totalReturned'] += (int)$row["qty_returned"];
        }
    }
    $totalCount = count($groupedCommissionsMap);
    $groupedKeys = array_keys($groupedCommissionsMap);

    $paginatedKeys = array_slice($groupedKeys, $offset, 10);
    $nextPageAvailable = count($groupedKeys) > ($offset + 10);

    $commissions = [];
    foreach($paginatedKeys as $key) {
        $commissions[] = $groupedCommissionsMap[$key];
    }
} else {
    $totalCount = count($allIdsQuery);

    $paginatedIds = array_slice($allIdsQuery, $offset, 10);
    $nextPageAvailable = count($allIdsQuery) > ($offset + 10);

    $commissions = [];
    foreach($paginatedIds as $id) {
        $commission = $commissionRepository->getCommissionById($id);
        $commissions[] = [
            'commission' => $commission,
            'ids' => [$id],
            'totalQuantity' => (int)$commission->commissionValues["qty"],
            'totalProduced' => (int)$commission->commissionValues["qty_produced"],
            'totalReturned' => (int)$commission->commissionValues["qty_returned"]
        ];
    }
}

$statsQuery = $MsaDB -> query(
    "SELECT 
        COUNT(DISTINCT CASE WHEN cl.state = 'active' THEN cl.id END) as active_count,
        COUNT(DISTINCT CASE WHEN cl.state = 'completed' THEN cl.id END) as completed_count,
        COUNT(DISTINCT CASE WHEN cl.state = 'returned' THEN cl.id END) as returned_count
    FROM `commission__list` cl 
    JOIN commission__receivers cr on cl.id = cr.commission_id 
    WHERE $add",
    PDO::FETCH_ASSOC
);

$stats = $statsQuery[0] ?? ['active_count' => 0, 'completed_count' => 0, 'returned_count' => 0];
$stats['grouped_count'] = $groupTogether ? $totalCount : 0;

$result = [];
foreach($commissions as $commissionData) {
    $commission = $commissionData['commission'];
    $type = $commission->deviceType;
    $row = $commission->commissionValues;

    $isGrouped = count($commissionData['ids']) > 1;

    $stateMap = [
        'active' => 1,
        'completed' => 2,
        'returned' => 3,
        'cancelled' => 4
    ];
    if($isGrouped) {
        $states = [];
        foreach($commissionData['ids'] as $groupedId) {
            $groupedCommission = $commissionRepository->getCommissionById($groupedId);
            $states[] = $stateMap[$groupedCommission->commissionValues["state"]] ?? 1;
        }
        $state_numeric = min($states);
        $state = array_search($state_numeric, $stateMap);
    } else {
        $state = $row["state"];
        $state_numeric = $stateMap[$state] ?? 1;
    }
    $state_numeric = $stateMap[$state] ?? 1;

    $priorityMap = [
        'none' => 0,
        'standard' => 1,
        'urgent' => 2,
        'critical' => 3
    ];
    $priority = $row["priority"];
    $priority_numeric = $priorityMap[$priority] ?? 0;

    $colors = ["none", "green", "yellow", "red"];
    $bomId = $row["bom_id"];
    $deviceBom = $bomRepository -> getBomById($type, $bomId);
    $deviceId = $deviceBom -> deviceId;
    $device_version = $deviceBom -> version;
    $device_laminate_id = $deviceBom -> laminateId;
    $device_laminate = !is_null($deviceBom -> laminateId) ? "Laminat: <b>".$list__laminate[$device_laminate_id]."</b>" : "" ;
    $device_laminate_and_version = $device_laminate." Wersja: <b>".$device_version."</b>";
    $isCancelled = $row["is_cancelled"];
    $receivers = $commission -> getReceivers();
    $classes = ['list-group-item-primary', '', 'alert-secondary', 'list-group-item-dark border-secondary', 'list-group-item-danger'];
    $class = $classes[$state_numeric];
    if($isCancelled == 1 ) $class = 'list-group-item-danger';
    $receiversName = array();
    foreach($receivers as $receiver) {
        $receiversName[] = $users[$receiver];
    }

    $groupKey = $type.'_'.$bomId.'_'.$row["warehouse_from_id"].'_'.$row["warehouse_to_id"].'_'.implode(',', $receivers).'_'.$row["priority"];
    $canBeGrouped = !$groupTogether && isset($potentialGroupsMap[$groupKey]) && $potentialGroupsMap[$groupKey] > 1;

    $result[] = [
        "class" => $class,
        "class2" => ($state_numeric != 3) ? 'text-muted' : '',
        "class3" => ($state_numeric != 1 || $isCancelled == 1) ? 'table-light' : '',
        "isHidden" => $isCancelled == 1 ? "hidden" : "",
        "showGroupBadge" => $isGrouped ? "" : "hidden",
        "showPotentialGroupBadge" => $canBeGrouped ? "" : "hidden",
        "potentialGroupCount" => $canBeGrouped ? $potentialGroupsMap[$groupKey] : 0,
        "color" => $colors[$priority_numeric],
        "warehouseFromId" => $row["warehouse_from_id"],
        "warehouseFrom" => $magazines[$row["warehouse_from_id"]],
        "warehouseToId" => $row["warehouse_to_id"],
        "warehouseTo" => $magazines[$row["warehouse_to_id"]],
        "isCancelled" => $isCancelled,
        "id" => $row["id"],
        "receivers" => implode(", ", $receivers),
        "priority" => $priority,
        "deviceDescription" => ${"list__".$type."_desc"}[$deviceId],
        "deviceName" => ${"list__".$type}[$deviceId],
        "deviceLaminateAndVersion" => $device_laminate_and_version,
        "state" => $state,
        "stateId" => $state_numeric,
        "receiversName" => implode(", ", $receiversName),
        "quantity" => $commissionData['totalQuantity'],
        "quantityProduced" => $commissionData['totalProduced'],
        "quantityReturned" => $commissionData['totalReturned'],
        "timestampCreated" => $row["created_at"],
        "groupedCount" => count($commissionData['ids']),
        "groupedIds" => implode(',', $commissionData['ids']),
        "cancelledClass" => $isCancelled == 1 ? "commission-cancelled" : "",
        "cancelledStyle" => $isCancelled == 1 ? "opacity: 0.7; filter: grayscale(50%);" : ""
    ];
}

echo json_encode([$result, $nextPageAvailable, $totalCount, $stats]);