<?php

use Atte\DB\MsaDB;

$MsaDB = MsaDB::getInstance();
$id = $_POST["id"];

$queriesAffectedCount = $MsaDB -> query("SELECT COUNT(1) FROM `notification__queries_affected` WHERE notification_id = $id");

echo $queriesAffectedCount[0][0];