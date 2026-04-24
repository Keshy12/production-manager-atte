<?php
use Atte\DB\MsaDB;
use Atte\Utils\BomRepository;

$MsaDB = MsaDB::getInstance();
$bomRepository = new BomRepository($MsaDB);

$wasSuccessful = true;
$errorMessage = '';
$createNewBom = isset($_POST['createNewBom']) && $_POST['createNewBom'] === 'true';

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
        } elseif($bomType == 'tht' && $createNewBom) {
            $columns = ['tht_id', 'version', 'isActive', 'out_tht_quantity'];
            $values = [$bomValues['tht_id'], $bomValues['version'], 0, 0];
            $newBomId = $MsaDB->insert('bom__tht', $columns, $values);

            $MsaDB->update('list__tht', ['default_bom_id' => $newBomId], 'id', $bomValues['tht_id']);

            $bomsFound = $bomRepository->getBomByValues($bomType, $bomValues);

            if(count($bomsFound) != 1) {
                throw new \Exception("Błąd podczas tworzenia nowego BOM dla THT");
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
$bomPrice = 0.00;
$outThtQuantity = null;
$outThtPrice = null;
$outSmdPrice = null;
$outSmdQty = null;
$outSmdPricePerItem = null;
$outThtPricePerItem = null;

$warehouseId = isset($_POST['warehouseId']) && $_POST['warehouseId'] !== '' ? (int)$_POST['warehouseId'] : null;

if($wasSuccessful) {
    $bom = $bomsFound[0];
    $bomId = $bom -> id;
    $bomIsActive = $bom -> isActive;

    if($bomType == 'tht') {
        $outThtQuantity = $bom -> out_tht_quantity;
        $outThtPricePerItem = 1;
        $outThtPrice = $outThtQuantity * $outThtPricePerItem;
    } else {
        $outThtPrice = null;
        $outThtPricePerItem = null;
    }
    $bomComponents = $bom -> getComponents(1);

    $bomPrice = 0.00;
    foreach ($bomComponents as $component) {
        $bomPrice += (float)$component['totalPrice'];
    }

    if($bomType == 'smd') {
        $outSmdQty = 0;
        foreach ($bomComponents as $component) {
            $outSmdQty += $component['quantity'];
        }
        $outSmdPricePerItem = 0.06;
        $outSmdPrice = $outSmdQty * $outSmdPricePerItem;
        $bomPrice += $outSmdPrice;
    }

    if($bomType == 'tht') {
        $bomPrice += (float)$outThtPrice;
    }

    if ($warehouseId !== null) {
        $skuIds = [];
        $smdIds = [];
        $thtIds = [];
        $partsIds = [];

        foreach ($bomComponents as $c) {
            $type = $c['type'];
            $id = (int)$c['componentId'];
            switch ($type) {
                case 'sku': $skuIds[] = $id; break;
                case 'smd': $smdIds[] = $id; break;
                case 'tht': $thtIds[] = $id; break;
                case 'parts': $partsIds[] = $id; break;
            }
        }

        $stockMap = [];

        if (!empty($skuIds)) {
            $in = implode(',', $skuIds);
            $result = $MsaDB->query("
                SELECT sku_id, SUM(qty) as qty
                FROM inventory__sku
                WHERE sku_id IN ($in) AND sub_magazine_id = $warehouseId
                GROUP BY sku_id
            ");
            foreach ($result as $row) {
                $stockMap['sku_' . $row['sku_id']] = (int)$row['qty'];
            }
        }

        if (!empty($smdIds)) {
            $in = implode(',', $smdIds);
            $result = $MsaDB->query("
                SELECT smd_id, SUM(qty) as qty
                FROM inventory__smd
                WHERE smd_id IN ($in) AND sub_magazine_id = $warehouseId
                GROUP BY smd_id
            ");
            foreach ($result as $row) {
                $stockMap['smd_' . $row['smd_id']] = (int)$row['qty'];
            }
        }

        if (!empty($thtIds)) {
            $in = implode(',', $thtIds);
            $result = $MsaDB->query("
                SELECT tht_id, SUM(qty) as qty
                FROM inventory__tht
                WHERE tht_id IN ($in) AND sub_magazine_id = $warehouseId
                GROUP BY tht_id
            ");
            foreach ($result as $row) {
                $stockMap['tht_' . $row['tht_id']] = (int)$row['qty'];
            }
        }

        if (!empty($partsIds)) {
            $in = implode(',', $partsIds);
            $result = $MsaDB->query("
                SELECT parts_id, SUM(qty) as qty
                FROM inventory__parts
                WHERE parts_id IN ($in) AND sub_magazine_id = $warehouseId
                GROUP BY parts_id
            ");
            foreach ($result as $row) {
                $stockMap['parts_' . $row['parts_id']] = (int)$row['qty'];
            }
        }

        foreach ($bomComponents as &$component) {
            $type = $component['type'];
            $id = $component['componentId'];
            $key = $type . '_' . $id;
            $component['stockQty'] = $stockMap[$key] ?? 0;
        }
        unset($component);
    } else {
        foreach ($bomComponents as &$component) {
            $component['stockQty'] = null;
        }
        unset($component);
    }

    $generateComponentInfo = function(&$row) use (
        $list__sku, $list__sku_desc,
        $list__tht, $list__tht_desc,
        $list__smd, $list__smd_desc,
        $list__parts, $list__parts_desc) {
        $componentType = $row['type'];
        $componentId = $row['componentId'];
        if(!array_key_exists($componentId, ${'list__'.$componentType})
            || !array_key_exists($componentId, ${'list__'.$componentType.'_desc'}))
        {
            throw new \Exception("ERROR Component not found, type: $componentType, id: $componentId");
        }

        $row['componentName'] = ${'list__'.$componentType}[$componentId];
        $row['componentDescription'] = ${'list__'.$componentType.'_desc'}[$componentId];
        return $row;
    };

    try {
        array_walk($bomComponents, $generateComponentInfo);
    } catch(\Exception $e) {
        $wasSuccessful = false;
        $errorMessage = $e -> getMessage();
    }
}

echo json_encode([$bomComponents, $bomId, $bomIsActive, $wasSuccessful, $errorMessage, $outThtQuantity, $outThtPrice, $outSmdPrice, $outSmdQty, $outSmdPricePerItem, $outThtPricePerItem, $bomPrice],
    JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
