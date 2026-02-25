<?php
use Atte\DB\MsaDB;
use Atte\Utils\Bom\PriceCalculator;

$MsaDB = MsaDB::getInstance();

$MsaDB -> db -> beginTransaction();

$wasSuccessful = true;

$rowId = $_POST['rowId'];

$quantity = $_POST["quantity"];

$valuesToInsert = [
    "sku_id" => null,
    "tht_id" => null,
    "smd_id" => null,
    "parts_id" => null,
    "quantity" => $quantity
];

if(empty($_POST['componentType']) || empty($_POST['componentId']) || empty($quantity) || $quantity < 0) {
    $wasSuccessful = false;
}

$valuesToInsert[$_POST['componentType'].'_id'] = $_POST['componentId'];

$querySuccessful = $MsaDB -> update("bom__flat", $valuesToInsert, 'id', $rowId);

if($wasSuccessful && $querySuccessful) {
    // Get BOM info before commit (or after, but we need the row info)
    $bomInfo = $MsaDB->query("SELECT bom_smd_id, bom_tht_id, bom_sku_id FROM bom__flat WHERE id = $rowId");
    $MsaDB -> db -> commit();
    
    // Determine bomId and type
    $bomId = null;
    $bomType = null;
    if ($bomInfo[0]['bom_smd_id']) { $bomId = $bomInfo[0]['bom_smd_id']; $bomType = 'smd'; }
    elseif ($bomInfo[0]['bom_tht_id']) { $bomId = $bomInfo[0]['bom_tht_id']; $bomType = 'tht'; }
    elseif ($bomInfo[0]['bom_sku_id']) { $bomId = $bomInfo[0]['bom_sku_id']; $bomType = 'sku'; }
    
    if ($bomId) {
        $PriceCalculator = new PriceCalculator($MsaDB);
        try {
            $PriceCalculator->updateBomPriceAndPropagate((int)$bomId, $bomType);
        } catch (\Throwable $e) {}
    }
}

else {
    $MsaDB -> db -> rollBack();
    $wasSuccessful = false;
}


echo json_encode($wasSuccessful);
