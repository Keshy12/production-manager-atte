<?php
use Atte\DB\MsaDB;
use Atte\Utils\BomRepository;

$MsaDB = MsaDB::getInstance();

// $userInfo defined in script that includes this file
$userSubmagazineId = $userInfo["sub_magazine_id"];

function getAvailableComponents($componentType, $userSubmagazineId) {
    $MsaDB = MsaDB::getInstance();
    return $MsaDB->query("
        SELECT {$componentType}_id
        FROM `inventory__{$componentType}`
        WHERE sub_magazine_id = $userSubmagazineId
        GROUP BY {$componentType}_id
        HAVING SUM(qty) != 0
    ", PDO::FETCH_COLUMN);
}

function getUsedComponents($user, $componentType) {
    $MsaDB = MsaDB::getInstance();
    $bomRepo = new BomRepository($MsaDB);
    $usedDevices = $user -> getDevicesUsed($componentType);
    if(empty($usedDevices)) return false;
    $sql = "SELECT id 
        FROM `bom__{$componentType}` 
        WHERE {$componentType}_id IN (".implode(",", $usedDevices).")";
    $allBomIds = $MsaDB->query($sql, PDO::FETCH_COLUMN);
    foreach($allBomIds as $bomId) {
        $bom = $bomRepo->getBomById($componentType, $bomId);
        $usedComponents = $bom -> getComponents(1);
        $usedComponentsIds = [
            "sku" => [],
            "tht" => [],
            "smd" => [],
            "parts" => []
        ];
        foreach($usedComponents as $component) {
            $usedComponentsIds[$component['type']][] = $component["componentId"];
        }
    }
    return $usedComponentsIds;
}

$available = [
    "sku" => getAvailableComponents("sku", $userSubmagazineId),
    "tht" => getAvailableComponents("tht", $userSubmagazineId),
    "smd" => getAvailableComponents("smd", $userSubmagazineId),
    "parts" => getAvailableComponents("parts", $userSubmagazineId)
];

$possiblyUsed = ["sku", "tht", "smd"];

foreach($possiblyUsed as $componentType) {
    $used = $user -> getDevicesUsed($componentType);
    $available[$componentType] = array_merge($available[$componentType], $used);
}
foreach($possiblyUsed as $componentType) {
    $used = getUsedComponents($user, $componentType);
    if ($used === false) continue;
    foreach($used as $type => $items) {
        $available[$type] = array_merge($available[$type], $items);
    }
}