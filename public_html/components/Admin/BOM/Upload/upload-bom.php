<?php
use Atte\DB\MsaDB;

$MsaDB = MsaDB::getInstance();
$MsaDB -> db -> beginTransaction();

$thtData = json_decode($_POST['thtData'], true);
$smdData = json_decode($_POST['smdData'], true);

$wasSuccessful = true;
$resultMessage = "Dodawanie powiodlo się.";
try{
    if($thtData['bomId'] == null) {
        $thtData['bomId'] = insertBomTHTItem($MsaDB, $thtData['deviceId'], $thtData['deviceVersion']);
    }
    
    if($smdData['laminateId'] === false) {
        $smdData["laminateId"] = insertLaminate($MsaDB, $smdData['laminateName']);
    }
    
    if($smdData['bomId'] == null) {
        $smdData['bomId'] = insertBomSMDItem($MsaDB, $smdData['deviceId'], $smdData['laminateId'], $smdData['deviceVersion']);
    }
    
    insertBomTHT($MsaDB, $thtData);
    insertBomSMD($MsaDB, $smdData);  
    $MsaDB -> db -> commit(); 
} 
catch (\Throwable $e) {
    $wasSuccessful = false;
    $resultMessage = "Dodawanie BOMu nie powiodło się. Kod błędu: ".$e->getMessage();
}

echo json_encode([$resultMessage, $wasSuccessful]
                        , JSON_FORCE_OBJECT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);

function insertBomSMD($MsaDB, $smdData) 
{
    $bomSmdId = $smdData['bomId'];
    $MsaDB -> query("DELETE FROM bom__flat WHERE `bom_smd_id` = {$bomSmdId}");
    $sql = "INSERT INTO bom__flat (bom_smd_id, tht_id, parts_id, quantity) 
                    VALUES ({$bomSmdId}, :tht_id, :parts_id, :quantity)";
    $stmt = $MsaDB -> db -> prepare($sql);
    foreach($smdData['bomFlat'] as $bomFlatItem)
    {
        $thtId = null;
        $partsId = null;
        ${$bomFlatItem['type']."Id"} = $bomFlatItem['componentId'];
        $quantity = $bomFlatItem['quantity'];
        $stmt->execute([
            ':tht_id' => $thtId,
            ':parts_id' => $partsId,
            ':quantity' => $quantity
        ]);
    }
}

function insertBomTHT($MsaDB, $thtData)
{
    $bomThtId = $thtData['bomId'];
    $MsaDB -> query("DELETE FROM bom__flat WHERE `bom_tht_id` = {$bomThtId}");
    $sql = "INSERT INTO bom__flat (bom_tht_id, tht_id, smd_id, parts_id, quantity) 
                    VALUES ({$bomThtId}, :tht_id, :smd_id, :parts_id, :quantity)";
    $stmt = $MsaDB -> db -> prepare($sql);
    foreach($thtData['bomFlat'] as $bomFlatItem)
    {
        $thtId = null;
        $smdId = null;
        $partsId = null;
        ${$bomFlatItem['type']."Id"} = $bomFlatItem['componentId'];
        $quantity = $bomFlatItem['quantity'];
        $stmt->execute([
            ':tht_id' => $thtId,
            ':smd_id' => $smdId,
            ':parts_id' => $partsId,
            ':quantity' => $quantity
        ]);
    }
}

function insertBomTHTItem($MsaDB, $thtId, $thtVersion)
{
    $bomId = $MsaDB -> insert('bom__tht', 
                            ['tht_id', 'version'], 
                            [$thtId, $thtVersion]);
    return $bomId;
}

function insertBomSMDItem($MsaDB, $smdId, $laminateId, $smdVersion)
{
    $bomId = $MsaDB -> insert('bom__smd', 
                            ['smd_id', 'laminate_id', 'version'], 
                            [$smdId, $laminateId, $smdVersion]);
    return $bomId;
}

function insertLaminate($MsaDB, $laminateName)
{
    $defaultDescription = "Przykładowy opis laminatu";
    $laminateId = $MsaDB -> insert('list__laminate',
                                ["name", "description"],
                                [$laminateName, $defaultDescription]);
    return $laminateId;
}
