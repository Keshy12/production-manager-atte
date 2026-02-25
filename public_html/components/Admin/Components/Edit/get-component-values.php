<?php
use Atte\DB\MsaDB;

$MsaDB = MsaDB::getInstance();

$deviceType = $_POST["deviceType"];
$deviceId = $_POST["deviceId"];

if ($deviceType === 'parts') {
    $result = $MsaDB -> query("SELECT * FROM list__{$deviceType} WHERE id = {$deviceId}", PDO::FETCH_ASSOC);
} elseif ($deviceType === 'sku') {
    // SKUs fetch price from their only active BOM
    $result = $MsaDB -> query("
        SELECT l.*, b.price 
        FROM list__sku l
        LEFT JOIN bom__sku b ON l.id = b.sku_id AND b.isActive = 1
        WHERE l.id = {$deviceId}
    ", PDO::FETCH_ASSOC);
} else {
    // THT/SMD fetch price from their default BOM
    $result = $MsaDB -> query("
        SELECT l.*, b.price 
        FROM list__{$deviceType} l
        LEFT JOIN bom__{$deviceType} b ON l.default_bom_id = b.id
        WHERE l.id = {$deviceId}
    ", PDO::FETCH_ASSOC);
}


if(!isset($result[0])) throw new \Exception("There is no device with this ID.");

echo json_encode($result[0], JSON_FORCE_OBJECT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);