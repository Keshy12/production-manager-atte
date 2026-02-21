<?php
use Atte\DB\MsaDB;
use Atte\Utils\BomRepository;

$MsaDB = MsaDB::getInstance();
$bomRepository = new BomRepository($MsaDB);

$wasSuccessful = true;
$errorMessage = '';

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

if($bomType == 'sku') {
    $bomValues['version'] = $_POST['bomValues'][1];
    $bomValues['version'] = $bomValues['version'] == 'n/d' ? null : $bomValues['version'];
}

if($bomType == 'tht') {
    $bomValues['version'] = $_POST['bomValues'][1];
    $bomValues['version'] = $bomValues['version'] == 'n/d' ? null : $bomValues['version'];
}

if($bomType == 'smd') {
    $bomValues['laminate_id'] = $_POST['bomValues'][1];
    $bomValues['version'] = $_POST['bomValues'][2];
}

$bomsFound = $bomRepository->getBomByValues($bomType, $bomValues);

try {
    if(count($bomsFound) == 0) {
        if($bomType == 'sku') {
            $columns = ['sku_id', 'version', 'isActive'];
            $values = [$bomValues['sku_id'], $bomValues['version'], 0];
            $newBomId = $MsaDB->insert('bom__sku', $columns, $values);

            $bomsFound = $bomRepository->getBomByValues($bomType, $bomValues);

            if(count($bomsFound) != 1) {
                throw new \Exception("Błąd podczas tworzenia nowego BOM dla SKU");
            }
        } else {
            throw new \Exception("Nie znaleziono BOM");
        }
    }

    if(count($bomsFound) > 1) throw new \Exception("Znaleziono wiele BOM dla podanych parametrów");

} catch(\Exception $e) {
    $wasSuccessful = false;
    $errorMessage = $e -> getMessage();
}

$bomComponents = [];
$bomId = null;
$bomIsActive = false;
$outThtQuantity = null;
$outSmdPrice = null;
$outSmdQty = null;
$outSmdPricePerItem = null;
$outThtPricePerItem = null;

if($wasSuccessful) {
    $bom = $bomsFound[0];
    $bomId = $bom -> id;
    $bomIsActive = $bom -> isActive;
    if($bomType == 'tht') {
        $outThtQuantity = $bom -> out_tht_quantity;
        $outThtPricePerItem = 1; // 1 PLN per unit as per user example
        $outThtPrice = $outThtQuantity * $outThtPricePerItem;
    } else { // Explicitly set to null if not tht to avoid "Undefined variable" warning
        $outThtPrice = null;
        $outThtPricePerItem = null;
    }
    $bomComponents = $bom -> getComponents(1);
    if($bomType == 'smd') {
        $outSmdQty = 0;
        foreach ($bomComponents as $component) {
            $outSmdQty += $component['quantity'];
        }
        $outSmdPricePerItem = 0.06;
        $outSmdPrice = $outSmdQty * $outSmdPricePerItem;
    }

    $generateComponentInfo = function(&$row) use (
        $list__sku, $list__sku_desc,
        $list__tht, $list__tht_desc,
        $list__smd, $list__smd_desc,
        $list__parts, $list__parts_desc) {
        $componentType = $row['type'];
        $componentId = $row['componentId'];
        if(!isset(${'list__'.$componentType}[$componentId])
            || !isset(${'list__'.$componentType.'_desc'}[$componentId]))
        {
            throw new \Exception("ERROR Component not found, type: $componentType, id: $componentId");
        }

        $row['componentName'] = ${'list__'.$componentType}[$componentId];
        $row['componentDescription'] = ${'list__'.$componentType.'_desc'}[$componentId];
        $row['price'] = 'Placeholder Price'; // Placeholder for price
        return $row;
    };

    try {
        array_walk($bomComponents, $generateComponentInfo);
    } catch(\Exception $e) {
        $wasSuccessful = false;
        $errorMessage = $e -> getMessage();
    }
}

echo json_encode([$bomComponents, $bomId, $bomIsActive, $wasSuccessful, $errorMessage, $outThtQuantity, $outThtPrice, $outSmdPrice, $outSmdQty, $outSmdPricePerItem, $outThtPricePerItem]
    , JSON_FORCE_OBJECT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);