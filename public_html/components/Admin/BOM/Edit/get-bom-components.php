<?php
use Atte\DB\MsaDB;
use Atte\Utils\BomRepository;

$MsaDB = MsaDB::getInstance();
$bomRepository = new BomRepository($MsaDB); 

$list__sku = $MsaDB -> readIdName('list__sku');
$list__sku_desc = $MsaDB -> readIdName('list__sku', 'id', 'description');
$list__tht = $MsaDB -> readIdName('list__tht');
$list__tht_desc = $MsaDB -> readIdName('list__tht', 'id', 'description');
$list__smd = $MsaDB -> readIdName('list__smd');
$list__smd_desc = $MsaDB -> readIdName('list__smd', 'id', 'description');
$list__parts = $MsaDB -> readIdName('list__parts');
$list__parts_desc = $MsaDB -> readIdName('list__parts', 'id', 'description');

$bomType = $_POST['bomType'];

$bomValues = [
    $bomType.'_id' => $_POST['bomValues'][0]
];

if($bomType == 'tht') {
    $bomValues['version'] = $_POST['bomValues'][1];
    $bomValues['version'] = $bomValues['version'] == 'n/d' ? null : $bomValues['version'];
}

if($bomType == 'smd') {
    $bomValues['laminate_id'] = $_POST['bomValues'][1];
    $bomValues['version'] = $_POST['bomValues'][2];
}

$bomsFound = $bomRepository->getBomByValues($bomType, $bomValues);

if(count($bomsFound) < 1) throw new \Exception("BOM not found");
if(count($bomsFound) > 1) throw new \Exception("Multiple BOMs found");

$bom = $bomsFound[0];
$bomComponents = $bom -> getComponents(1);

$generateComponentInfo = function(&$row) use (
        $list__sku, $list__sku_desc,
        $list__tht, $list__tht_desc, 
        $list__smd, $list__smd_desc, 
        $list__parts, $list__parts_desc) {
    $componentType = $row['type'];
    $componentId = $row['componentId'];

    $row['componentName'] = ${'list__'.$componentType}[$componentId];
    $row['componentDescription'] = ${'list__'.$componentType.'_desc'}[$componentId];
    return $row;
};

array_walk($bomComponents, $generateComponentInfo);

echo json_encode($bomComponents
                , JSON_FORCE_OBJECT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);