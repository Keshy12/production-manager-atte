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

$magazineFrom = $values["warehouse_from_id"];
$classMagazineFrom = $magazineRepository -> getMagazineById($magazineFrom);
$magazineTo = $values["warehouse_to_id"];
$bomId = $values["bom_id"];
$quantity = $values["qty"];
$quantityProduced = $values["qty_produced"];
$quantityReturned = $values["qty_returned"] + $quantityBeingReturned;
if($quantityReturned > $quantityProduced) {
    throw new \Exception('Cannot make returned quantity higher than produced quantity.');
}

// Updated column names for inventory table
$MsaDB -> insert("inventory__".$deviceType,
    [$deviceType."_id", $deviceType."_bom_id", "commission_id", "sub_magazine_id", "qty", "input_type_id", "comment"],
    [$deviceId, $bomId, $commissionId, $magazineTo, $quantityBeingReturned*-1, $inputTypeId, $comment]
);

if($MsaDB -> update("commission__list", ["qty_returned" => $quantityReturned], "id", $commissionId)){
    $currentCommission -> commissionValues['qty_returned'] = $quantityReturned;
};


/**
 * If commission that is being returned to wasn't made from
 * a main company magazine, check if any commissions on given
 * submagazines for this device are active. If so, increment
 * the qty_produced value accordingly.
 */
if($classMagazineFrom -> typeId == 2 && $magazineFrom != $magazineTo) {
    /**
     * Filters all commissions to only get relevant to production
     * type == POST             - commissions for given type
     * bom_id == bomId          - commissions for produced type
     * state == 'active'        - commissions that still need production
     */
    $getRelevant = function ($var) use($bomId, $deviceType) {
        return ($var -> deviceType == $deviceType
            && $var -> commissionValues['bom_id'] == $bomId
            && $var -> commissionValues['state'] == 'active');
    };

    $activeCommissionsMagazineFrom = $classMagazineFrom -> getActiveCommissions();
    $activeCommissionsMagazineFrom = array_filter($activeCommissionsMagazineFrom, $getRelevant);
    foreach($activeCommissionsMagazineFrom as $commission) {
        if($quantityBeingReturned == 0) break;
        $commissionValues = $commission -> commissionValues;
        $commission_id = $commissionValues["id"];
        $quantity_needed = $commissionValues["qty"] - $commissionValues["qty_produced"];
        $quantityBeingReturned -= $quantity_needed;
        $state = 'completed';
        if($quantityBeingReturned < 0) {
            $state = 'active';
            $quantity_needed += $quantityBeingReturned;
            $quantityBeingReturned += $quantityBeingReturned*(-1);
        }
        $quantity_produced = $commissionValues["qty_produced"] + $quantity_needed;
        $MsaDB -> insert("inventory__".$deviceType,
            [$deviceType."_id", $deviceType."_bom_id", "commission_id", "sub_magazine_id", "qty", "input_type_id", "comment", "isVerified"],
            [$deviceId, $bomId, $commissionId, $magazineFrom, $quantity_needed, $inputTypeId, 'Automatyczna inkrementacja zlecenia nr '.$commission_id.', dostarczenie zlecenia do magazynu subkontraktora', '0']
        );
        $MsaDB -> update("commission__list", ["qty_produced" => $quantity_produced], "id", $commission_id);
        $commission -> updateStateAuto();
    }
}

if($quantityBeingReturned != 0) {
    $MsaDB -> insert("inventory__".$deviceType,
        [$deviceType."_id", $deviceType."_bom_id", "commission_id", "sub_magazine_id", "qty", "input_type_id", "comment", "isVerified"],
        [$deviceId, $bomId, $commissionId, $magazineFrom, $quantityBeingReturned, $inputTypeId, $comment, 0]
    );
}


$MsaDB -> db -> commit();
$currentCommission -> updateStateAuto();

echo json_encode($quantityReturned);