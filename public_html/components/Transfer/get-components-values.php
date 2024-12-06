<?php
use Atte\DB\MsaDB;
use Atte\Utils\MagazineRepository;

$MsaDB = MsaDB::getInstance();

$magazineRepository = new MagazineRepository($MsaDB);

$components = $_POST['components'];
$transferFrom = $_POST['transferFrom'];
$transferTo = $_POST['transferTo'];

$magazineFrom = $magazineRepository -> getMagazineById(id: $transferFrom);
$magazineTo = $magazineRepository -> getMagazineById(id: $transferTo);
$reservedFrom = $magazineFrom -> getComponentsReserved();
$reservedTo = $magazineTo -> getComponentsReserved();

$list__sku = $MsaDB -> readIdName(table: 'list__sku');
$list__sku_desc = $MsaDB -> readIdName(table: 'list__sku', id: 'id', name: 'description');
$list__tht = $MsaDB -> readIdName(table: 'list__tht');
$list__tht_desc = $MsaDB -> readIdName(table: 'list__tht', id: 'id', name: 'description');
$list__smd = $MsaDB -> readIdName(table: 'list__smd');
$list__smd_desc = $MsaDB -> readIdName(table: 'list__smd', id: 'id', name: 'description');
$list__parts = $MsaDB -> readIdName(table: 'list__parts');
$list__parts_desc = $MsaDB -> readIdName(table: 'list__parts', id: 'id', name: 'description');


$result = array_map(function($item) use ($reservedFrom, $reservedTo,
                                                    $magazineFrom, $magazineTo,
                                                    $list__sku, $list__sku_desc,
                                                    $list__tht, $list__tht_desc, 
                                                    $list__smd, $list__smd_desc, 
                                                    $list__parts, $list__parts_desc){       
    $type = $item['type'];
    $componentId = $item['componentId'];
    $item['warehouseFromReserved'] = $reservedFrom[$type][$componentId]['quantity'] ?? 0;
    $item['warehouseFromQty'] = $magazineFrom -> getWarehouseQty($type, $componentId)+0;
    $item['warehouseToReserved'] = $reservedTo[$type][$componentId]['quantity'] ?? 0;
    $item['warehouseToQty'] = $magazineTo -> getWarehouseQty($type, $componentId)+0;
    $item['componentName'] = ${"list__".$type}[$componentId];
    $item['componentDescription'] = ${"list__".$type."_desc"}[$componentId];
    return $item;
}, $components);

echo json_encode($result
                ,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);