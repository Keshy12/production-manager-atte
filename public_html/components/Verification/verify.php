<?php
$MsaDB = Atte\DB\MsaDB::getInstance();
$MsaDB -> db -> beginTransaction();

$deviceType = $_POST["deviceType"];
$id = $_POST["id"];
$comment = $_POST["comment"];
$verifyQuantity = $_POST["quantity"];
$userId = $_SESSION["userid"];
$deviceId = $_POST["deviceId"];
$commissionRepository = new Atte\Utils\CommissionRepository($MsaDB);
$commissionId = $_POST["commissionId"];
$commission = empty($commissionId) 
                    ? null 
                    : $commissionRepository -> getCommissionById($commissionId);


$verifyRow = $MsaDB -> query("SELECT * FROM `inventory__".$deviceType."` WHERE id = $id", PDO::FETCH_ASSOC)[0];
$verifyRowSubmagId = $verifyRow["sub_magazine_id"];

// If no correction has been made
if($verifyQuantity == $verifyRow["qty"]) {
    $MsaDB -> update("inventory__".$deviceType, ["comment" => $comment, "verifiedBy" => $userId ,"isVerified" => 1], "id", $id);
    $MsaDB -> db -> commit();
    return;
}

$correctionQuantity = $verifyQuantity - $verifyRow["qty"];
$transferGroupManager = new \Atte\Utils\TransferGroupManager($MsaDB);
$transferGroupId = $transferGroupManager->createTransferGroup($userId, 'warehouse_correct', [
    'magazine_name' => 'Korekta weryfikacji (ID: '.$id.')'
]);

if(isset($commission)) {
    if($verifyQuantity <= 0) {
        echo "Zwrot produkcji nie może być mniejszy bądź równy 0";
        return;
    }
    $commissionValues = $commission -> commissionValues;
    $quantityProduced = $commissionValues["qty_produced"];
    $quantityReturned = $commissionValues["qty_returned"];  
    $updatedQuantity = $correctionQuantity + $quantityReturned;
    if($updatedQuantity > $quantityProduced) {
        echo "Nie możesz zwiększyć tego zwrotu. Przy podanej korekcie zwrot przekracza produkcje w zleceniu. <br>Zlecenie nr $commissionId.<br>".
        "Wyprodukowano: $quantityProduced. Zwrócono: $quantityReturned. Zwrot po wprowadzonej korekcie: $updatedQuantity";
        return;
    }
    $magazineTo = $commissionValues["warehouse_to_id"];
    $MsaDB -> insert("inventory__".$deviceType, [$deviceType."_id", "sub_magazine_id", "qty", "input_type_id", "comment", "transfer_group_id", "verifiedBy"], [$deviceId, $magazineTo, $correctionQuantity*-1, '4', "Korekta podczas weryfikacji przez administratora. Zdarzenie o id: $id", $transferGroupId, $userId]);
    $MsaDB -> update("commission__list", ["qty_returned" => $updatedQuantity], "id", $commissionId);
}
$MsaDB -> insert("inventory__".$deviceType, [$deviceType."_id", "sub_magazine_id", "qty", "input_type_id", "comment", "transfer_group_id", "verifiedBy"], [$deviceId, $verifyRowSubmagId, $correctionQuantity, '4', "Korekta podczas weryfikacji przez administratora. Zdarzenie o id: $id", $transferGroupId, $userId]);
$MsaDB -> update("inventory__".$deviceType, ["comment" => $comment." | Korekta podczas weryfikacji przez administratora", "verifiedBy" => $userId ,"isVerified" => 1], "id", $id);

$MsaDB -> db -> commit();

if(isset($commission)) $commission -> updateStateAuto();  

