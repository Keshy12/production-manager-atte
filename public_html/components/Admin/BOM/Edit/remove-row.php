<?php
use Atte\DB\MsaDB;
use Atte\Utils\Bom\PriceCalculator;

$MsaDB = MsaDB::getInstance();

$rowId = $_POST['rowId'];

// Get BOM info before deleting
$bomInfo = $MsaDB->query("SELECT bom_smd_id, bom_tht_id, bom_sku_id FROM bom__flat WHERE id = $rowId");

$wasSuccessful = $MsaDB -> deleteById('bom__flat', $rowId);

if ($wasSuccessful && !empty($bomInfo)) {
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

echo json_encode($wasSuccessful);

