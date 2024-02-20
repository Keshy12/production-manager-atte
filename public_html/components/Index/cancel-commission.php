<?php
$MsaDB = Atte\DB\MsaDB::getInstance();
$commissionRepository = new Atte\Utils\CommissionRepository($MsaDB);
 
$id = $_POST["id"];
$commission = $commissionRepository -> getCommissionById($id);
$commission -> cancel();