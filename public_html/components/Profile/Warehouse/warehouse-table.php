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
$itemsPerPage = $limit - 1;
$offset = ($page - 1) * $itemsPerPage;

$list__device = $MsaDB -> readIdName('list__'.$deviceType);
$list__device_desc = $MsaDB -> readIdName('list__'.$deviceType, 'id', 'description');

$selected = array_map('intval', $components);

// Get items for this page
if (!empty($selected)) {
    $selectedForThisPage = array_slice($selected, $offset, $itemsPerPage);
    $conditions = implode(" OR ", array_map(function ($deviceId) use ($deviceType) {
        return "{$deviceType}_id = " . intval($deviceId);
    }, $selectedForThisPage));
    $nextPageAvailable = count($selected) > ($page * $itemsPerPage);
} else {
    $conditions = '1';
    $nextPageAvailable = false;
}

$query = "SELECT {$deviceType}_id, 
                SUM(quantity) as quantity
            FROM `inventory__{$deviceType}` 
            WHERE sub_magazine_id = '{$subMagazineId}' 
            AND ({$conditions}) 
            GROUP BY {$deviceType}_id" .
    (empty($selected) ? " LIMIT {$limit} OFFSET {$offset}" : "");

$queryResult = $MsaDB -> query($query, PDO::FETCH_BOTH);

// Handle pagination for non-selected items
if (empty($selected)) {
    $nextPageAvailable = isset($queryResult[$limit-1]);
    if($nextPageAvailable) unset($queryResult[$limit-1]);
}

$result = [];

foreach($queryResult as $row) {
    list($deviceId, $quantity) = $row;

    $result[$deviceId] = [
        'deviceType' => $deviceType,
        'componentName' => $list__device[$deviceId],
        'componentDescription' => $list__device_desc[$deviceId],
        'sumQuantity' => $quantity+0
    ];
}

// Add missing selected items with 0 quantity
if (!empty($selected)) {
    $selectedForThisPage = array_slice($selected, $offset, $itemsPerPage);

    foreach ($selectedForThisPage as $deviceId) {
        if (!isset($result[$deviceId])) {
            $result[$deviceId] = [
                'deviceType' => $deviceType,
                'componentName' => $list__device[$deviceId] ?? '',
                'componentDescription' => $list__device_desc[$deviceId] ?? '',
                'sumQuantity' => 0,
            ];
        }
    }
}

echo json_encode([$result, $nextPageAvailable], JSON_FORCE_OBJECT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);