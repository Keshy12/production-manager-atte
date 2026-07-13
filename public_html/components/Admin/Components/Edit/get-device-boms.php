<?php
use Atte\DB\MsaDB;

header('Content-Type: application/json');
$MsaDB = MsaDB::getInstance();

$deviceType = $_POST["deviceType"];
$deviceId = $_POST["deviceId"];

$boms = $MsaDB->query("SELECT id, version FROM bom__{$deviceType} WHERE {$deviceType}_id = {$deviceId} ORDER BY version ASC", PDO::FETCH_ASSOC);

echo json_encode($boms, JSON_FORCE_OBJECT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
exit;
