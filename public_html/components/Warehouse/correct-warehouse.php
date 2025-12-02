<?php
$MsaDB = Atte\DB\MsaDB::getInstance();

$userId = $_SESSION["userid"];
$result = $_POST["result"] ?? [];
$type = $_POST["type"];
$deviceId = $_POST["device_id"];
//input_type = korekta
$inputTypeId = 3;
$comment = "Korekta magazynu przez stronę Magazyn (admin)";

// Build column list based on device type
// All inventory tables have: device_id, sub_magazine_id, qty, input_type_id, comment, verifiedBy
$insertColumns = ["{$type}_id", "sub_magazine_id", "qty", "input_type_id", "comment", "verifiedBy"];

// Add device-specific BOM column if needed (sku, smd, tht have bom_id columns, parts doesn't)
if ($type !== 'parts') {
    // For sku, smd, tht - they can have optional bom_id which is NULL for corrections
    $bomColumn = $type . "_bom_id";
}

foreach ($result as $magazine) {
    $subMagazineId = $magazine[0];
    $quantityDifference = $magazine[1];
    $insertValues = [$deviceId, $subMagazineId, $quantityDifference, $inputTypeId, $comment, $userId];
    $MsaDB -> insert("inventory__{$type}", $insertColumns, $insertValues);
}