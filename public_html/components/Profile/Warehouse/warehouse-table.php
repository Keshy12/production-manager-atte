<?php
$MsaDB = Atte\DB\MsaDB::getInstance();

$userRepository = new Atte\Utils\UserRepository($MsaDB);
$user = $userRepository -> getUserById($_SESSION["userid"]);
$userInfo = $user -> getUserInfo();

$subMagazineId = $userInfo["sub_magazine_id"];


$deviceType = $_POST['type'];
$components = $_POST['components'] ?? [];

$limit = 11;
$page = $_POST['page'] ?? 1;
$offset = ($page-1)*($limit-1);

$list__device = $MsaDB -> readIdName('list__'.$deviceType);
$list__device_desc = $MsaDB -> readIdName('list__'.$deviceType, 'id', 'description');

$components = empty($components) ? [] : $components;

$components = array_map(function ($deviceId) use ($deviceType) {
    return "{$deviceType}_id = " . intval($deviceId);
}, $components);
$conditions = empty($components) ? '1' : implode(" OR ", $components);

$query = "SELECT {$deviceType}_id, 
                SUM(quantity) as quantity
            FROM `inventory__{$deviceType}` 
            WHERE sub_magazine_id = '{$subMagazineId}' 
            AND ({$conditions}) 
            GROUP BY {$deviceType}_id
            LIMIT {$limit} OFFSET {$offset}";

$queryResult = $MsaDB -> query($query, PDO::FETCH_BOTH);

$nextPageAvailable = isset($queryResult[$limit-1]);
if($nextPageAvailable) unset($queryResult[$limit-1]);

$result = [];

foreach($queryResult as $row) {
    list($deviceId, $quantity) = $row;

    $result[$deviceId]['deviceType'] = $deviceType;
    $result[$deviceId]['componentName'] = $list__device[$deviceId];
    $result[$deviceId]['componentDescription'] = $list__device_desc[$deviceId];
    $result[$deviceId]['sumQuantity'] = $quantity+0;
}

echo json_encode([$result, $nextPageAvailable], JSON_FORCE_OBJECT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
