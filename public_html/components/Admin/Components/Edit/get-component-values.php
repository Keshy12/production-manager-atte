<?php
use Atte\DB\MsaDB;

$MsaDB = MsaDB::getInstance();

$deviceType = $_POST["deviceType"];
$deviceId = $_POST["deviceId"];

$result = $MsaDB -> query("SELECT * FROM list__{$deviceType} WHERE id = {$deviceId}", PDO::FETCH_ASSOC);

if(!isset($result[0])) throw new \Exception("There is no device with this ID.");

echo json_encode($result[0], JSON_FORCE_OBJECT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);