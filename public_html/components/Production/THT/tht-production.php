<?php
use Atte\Utils\{UserRepository, BomRepository};

$MsaDB = Atte\DB\MsaDB::getInstance();
$MsaDB->db->beginTransaction();

$userId = $_POST["user_id"];
$deviceId = $_POST["device_id"];
$version = $_POST["version"];
$quantity = $_POST["qty"];
$comment = !empty($_POST["comment"]) ? $_POST["comment"] : 'Produkcja przez Formularz Produkcja THT';
$productionDate = !empty($_POST["prod_date"]) ? "'".$_POST["prod_date"]."'" : NULL;

try {
    $userRepository = new UserRepository($MsaDB);
    $user = $userRepository->getUserById($userId);
    $firstInsertedId = handleProduction($MsaDB, $user, $deviceId, $version, $quantity, $comment, $productionDate);
} catch (Exception $e) {
    echo $e->getMessage();
}
echo json_encode($firstInsertedId);
$MsaDB->db->commit();


function handleProduction($MsaDB, $user, $deviceId, $version, $quantity, $comment, $productionDate) {
    $userInfo = $user->getUserInfo();
    $userId = $userInfo['user_id'];
    $sub_magazine_id = $userInfo["sub_magazine_id"];
    $deviceType = "tht";
    $bomRepository = new BomRepository($MsaDB);
    $bomValues = [
        $deviceType."_id" => $deviceId,
        "version" => $version
    ];
    $bomsFound = $bomRepository->getBomByValues($deviceType, $bomValues);
    if(count($bomsFound) > 1) throw new \Exception("Multiple BOM records found for the provided values. Unable to proceed with the production.");
    $bom = $bomsFound[0];
    $bomId = $bom->id;
    $bomComponents = $bom->getComponents(1);
    $firstInsertedId = "";

    $getRelevant = function ($var) use($bomId, $deviceType) {
        return ($var->deviceType == $deviceType
            && $var->commissionValues['deviceBomId'] == $bomId
            && $var->commissionValues['state_id'] == 1);
    };

    $commissions = $user->getActiveCommissions();
    $commissions = array_filter($commissions, $getRelevant);
    foreach($commissions as $commission) {
        $row = $commission->commissionValues;
        if($quantity == 0) break;
        $commission_id = $row["id"];
        $quantity_needed = $row["quantity"] - $row["quantity_produced"];
        $quantity -= $quantity_needed;
        $state_id = 2;
        if($quantity < 0) {
            $state_id = 1;
            $quantity_needed += $quantity;
            $quantity += $quantity*(-1);
        }
        foreach($bomComponents as $component) {
            $type = $component["type"];
            $component_id = $component["componentId"];
            $component_quantity = ($component["quantity"]*$quantity_needed)*-1;
            $MsaDB->insert("inventory__".$type, [$type."_id", "commission_id", "user_id", "sub_magazine_id", "quantity", "input_type_id", "comment"], [$component_id, $commission_id, $userId, $sub_magazine_id, $component_quantity, '6', 'Zejście z magazynu do produkcji']);
        }
        $quantity_produced = $row["quantity_produced"] + $quantity_needed;
        $insertedId = $MsaDB->insert("inventory__tht", ["tht_id", "tht_bom_id", "commission_id", "user_id", "sub_magazine_id", "quantity", "input_type_id", "comment", "production_date"], [$deviceId, $bomId, $commission_id, $userId, $sub_magazine_id, $quantity_needed, '4', $comment, $productionDate]);
        $firstInsertedId = empty($firstInsertedId) ? $insertedId : $firstInsertedId;
        $MsaDB->update("commission__list", ["quantity_produced" => $quantity_produced, "state_id" => $state_id], "id", $commission_id);
    }

    if($quantity != 0) {
        foreach($bomComponents as $component) {
            $type = $component["type"];
            $component_id = $component["componentId"];
            $component_quantity = ($component["quantity"]*$quantity)*-1;
            $MsaDB->insert("inventory__".$type, [$type."_id", "user_id", "sub_magazine_id", "quantity", "input_type_id", "comment"], [$component_id, $userId, $sub_magazine_id, $component_quantity, '6', 'Zejście z magazynu do produkcji']);
        }
        $insertedId = $MsaDB->insert("inventory__tht", ["tht_id", "tht_bom_id", "user_id", "sub_magazine_id", "quantity", "input_type_id", "comment", "production_date"], [$deviceId, $bomId, $userId, $sub_magazine_id, $quantity, '4', $comment, $productionDate]);
        $firstInsertedId = empty($firstInsertedId) ? $insertedId : $firstInsertedId;
    }

    return $firstInsertedId;
}
