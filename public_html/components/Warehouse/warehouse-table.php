<?php
$MsaDB = Atte\DB\MsaDB::getInstance();

$limit = 10;

$deviceType = $_POST['type'];
$components = $_POST['components'] ?? [];
$page = $_POST['page'] ?? 1;

$list__device = $MsaDB -> readIdName('list__'.$deviceType);
$list__device_desc = $MsaDB -> readIdName('list__'.$deviceType, 'id', 'description');

$components = empty($components)
    ? array_slice(array_keys($list__device), (($page-1)*$limit), $limit+1)
    : $components;

$nextPageAvailable = isset($components[$limit]);
if($nextPageAvailable) unset($components[$limit]);

$list__warehouse = $MsaDB -> readIdName('magazine__list', 'sub_magazine_id', 'sub_magazine_name');
$list__warehouse_type_id = $MsaDB -> readIdName('magazine__list', 'sub_magazine_id', 'type_id');
// Get magazine active status
$list__warehouse_active = $MsaDB -> readIdName('magazine__list', 'sub_magazine_id', 'isActive');

$prepareArray = function ($deviceId) use ($list__device, $list__device_desc, $list__warehouse, $list__warehouse_type_id, $list__warehouse_active) {
    $return = [];
    $return['componentName'] = $list__device[$deviceId];
    $return['componentDescription'] = $list__device_desc[$deviceId];
    foreach($list__warehouse as $warehouseId => $warehouseName) {
        $warehouseType = $list__warehouse_type_id[$warehouseId];
        $isActive = $list__warehouse_active[$warehouseId] ?? 1;

        if(!isset($return[$warehouseType]))
        {
            $return[$warehouseType] = [];
            $return[$warehouseType]['typeQuantitySum'] = 0;
        }
        if(!isset($return[$warehouseType][$warehouseId])) {
            $return[$warehouseType][$warehouseId] = [];
            $return[$warehouseType][$warehouseId]['name'] = $warehouseName;
            $return[$warehouseType][$warehouseId]['quantity'] = 0;
            $return[$warehouseType][$warehouseId]['isActive'] = $isActive;
        }
    }

    return $return;
};

$result = [];
foreach($components as $componentId) {
    $result[$componentId] = $prepareArray($componentId);
}

$components = array_map(function ($deviceId) use ($deviceType) {
    return "i.{$deviceType}_id = " . intval($deviceId);
}, $components);
$conditions = empty($components) ? '1' : implode(" OR ", $components);

$sql = "SELECT device_id, 
               warehouse_id, 
               type_id, 
               isActive,
               SUM(quantity) as quantity 
        FROM ( SELECT i.{$deviceType}_id as device_id, 
                      i.quantity as quantity, 
                      i.sub_magazine_id as warehouse_id, 
                      ml.type_id,
                      ml.isActive
                      FROM `inventory__{$deviceType}` i 
                      JOIN magazine__list ml ON i.sub_magazine_id = ml.sub_magazine_id 
                      WHERE {$conditions}
                      ORDER BY {$deviceType}_id ASC ) 
               AS tmp_table 
        GROUP BY device_id, warehouse_id";

$queryResult = $MsaDB -> query($sql, PDO::FETCH_BOTH);

foreach($queryResult as $row) {
    list($deviceId, $warehouseId, $warehouseTypeId, $isActive, $quantity) = $row;

    // Only add to type sum if magazine is active
    if ($isActive) {
        $result[$deviceId][$warehouseTypeId]['typeQuantitySum'] += $quantity;
    }
    $result[$deviceId][$warehouseTypeId][$warehouseId]['quantity'] += $quantity;
    $result[$deviceId][$warehouseTypeId][$warehouseId]['isActive'] = $isActive;
}

echo json_encode([$result, $nextPageAvailable], JSON_FORCE_OBJECT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);