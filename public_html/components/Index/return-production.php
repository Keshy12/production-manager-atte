<?php

use Atte\Utils\{CommissionRepository, MagazineRepository};

$MsaDB = Atte\DB\MsaDB::getInstance();
$MsaDB -> db -> beginTransaction();

$commissionRepository = new CommissionRepository($MsaDB);
$magazineRepository = new MagazineRepository($MsaDB);

$userId = $_SESSION['userid'];
$commissionId = $_POST["commission_id"];
$currentCommission = $commissionRepository -> getCommissionById($commissionId);
$deviceType = $_POST["type"];
$deviceId = $_POST["device_id"];
$quantityBeingReturned = $_POST["quantity"];
if(!$quantityBeingReturned || $quantityBeingReturned <= 0){
    return;
}
$inputTypeId = 5;
$comment = "Finalizacja produkcji, dostarczenie produktu";

$values = $currentCommission -> commissionValues;

$magazineFrom = $values["magazine_from"];
$classMagazineFrom = $magazineRepository -> getMagazineById($magazineFrom);
$magazineTo = $values["magazine_to"];
$bomId = $values["bom_".$deviceType."_id"];
$quantity = $values["quantity"];
$quantityProduced = $values["quantity_produced"];
$quantityReturned = $values["quantity_returned"] + $quantityBeingReturned;
if($quantityReturned > $quantityProduced) {
    throw new \Exception('Cannot make returned quantity higher than produced quantity.');
}

$MsaDB -> insert("inventory__".$deviceType, [$deviceType."_id", "commission_id", "user_id", "sub_magazine_id", "quantity", "input_type_id", "comment"], 
[$deviceId, $commissionId, $userId, $magazineTo, $quantityBeingReturned*-1, $inputTypeId, $comment]);

if($MsaDB -> update("commission__list", ["quantity_returned" => $quantityReturned], "id", $commissionId)){
    $currentCommission -> commissionValues['quantity_returned'] = $quantityReturned;
};


/**
 * If commission that is being returned to wasn't made from
 * a main company magazine, check if any commissions on given
 * submagazines for this device are active. If so, increment
 * the quantity_produced value accordingly.
 */
if($classMagazineFrom -> typeId == 2 && $magazineFrom != $magazineTo) {
    /**
     * Filters all commissions to only get relevant to production
     * type == POST             - commissions for given type
     * bom_{type}_id == bomId   - commissions for produced type
     * state_id == 1            - commissions that still need production
     */
    $getRelevant = function ($var) use($bomId, $deviceType) {
        return ($var -> deviceType == $deviceType
                && $var -> commissionValues['deviceBomId'] == $bomId 
                && $var -> commissionValues['state_id'] == 1);
    };

    $activeCommissionsMagazineFrom = $classMagazineFrom -> getActiveCommissions();
    $activeCommissionsMagazineFrom = array_filter($activeCommissionsMagazineFrom, $getRelevant);
    foreach($activeCommissionsMagazineFrom as $commission) {
        if($quantityBeingReturned == 0) break;
        $commissionValues = $commission -> commissionValues;
        $commission_id = $commissionValues["id"];
        $quantity_needed = $commissionValues["quantity"] - $commissionValues["quantity_produced"];
        $quantityBeingReturned -= $quantity_needed;
        $state_id = 2;
        if($quantityBeingReturned < 0) {
            $state_id = 1;
            $quantity_needed += $quantityBeingReturned;
            $quantityBeingReturned += $quantityBeingReturned*(-1);
        }
        $quantity_produced = $commissionValues["quantity_produced"] + $quantity_needed;
        $MsaDB -> insert("inventory__".$deviceType, [$deviceType."_id", "commission_id", "user_id", "sub_magazine_id", "quantity", "input_type_id", "comment", "isVerified"], 
        [$deviceId, $commissionId, $userId, $magazineFrom, $quantity_needed, $inputTypeId, 'Automatyczna inkrementacja zlecenia nr '.$commission_id.', dostarczenie zlecenia do magazynu subkontraktora', '0']);
        $MsaDB -> update("commission__list", ["quantity_produced" => $quantity_produced], "id", $commission_id);
        $commission -> updateStateIdAuto();
    }
}

if($quantityBeingReturned != 0) {
    $MsaDB -> insert("inventory__".$deviceType, [$deviceType."_id", "commission_id", "user_id", "sub_magazine_id", "quantity", "input_type_id", "comment", "isVerified"], 
    [$deviceId, $commissionId, $userId, $magazineFrom, $quantityBeingReturned, $inputTypeId, $comment, 0]);                    
} 


$MsaDB -> db -> commit();
$currentCommission -> updateStateIdAuto();

echo json_encode($quantityReturned);
