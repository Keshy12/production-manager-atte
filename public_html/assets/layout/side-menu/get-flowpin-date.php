<?php

use Atte\DB\MsaDB;

$MsaDB = MsaDB::getInstance();

$flowpinUpdate = $MsaDB->query("SELECT last_timestamp FROM ref__timestamp WHERE id = '4'")[0][0];
$GSLastWarehouseUpload = $MsaDB->query("SELECT last_timestamp FROM ref__timestamp WHERE id = '5'")[0][0];

$result = ["flowpin" => $flowpinUpdate, "gs" => $GSLastWarehouseUpload];

echo json_encode($result);