<?php
use Atte\DB\MsaDB;
use Atte\Utils\CommissionRepository;

$MsaDB = MsaDB::getInstance();
$MsaDB -> db -> beginTransaction();
$wasSuccessful = true;
$errorMessage = "";

try {
    $commissionRepository = new CommissionRepository($MsaDB);
    $commission = $commissionRepository -> getCommissionById($_POST["id"]);
    
    $commission -> updatePriority($_POST["priority"]);
    
    $commission -> updateReceivers($_POST["receivers"]);

    $MsaDB -> db -> commit();
}
catch (\Throwable $e) {
    $MsaDB -> db -> rollBack();
    $wasSuccessful = false;
    $errorMessage = "ERROR! Error message:".$e -> getMessage();
}

echo json_encode([$wasSuccessful, $errorMessage]
                , JSON_FORCE_OBJECT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);