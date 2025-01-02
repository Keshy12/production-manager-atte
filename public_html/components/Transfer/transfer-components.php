<?php
use Atte\DB\MsaDB;
use Atte\Utils\BomRepository;

$MsaDB = Atte\DB\MsaDB::getInstance();
$MsaDB -> db -> beginTransaction();

//Get and filter components and commissions
$components = isset($_POST["components"]) ? array_filter($_POST["components"]) : "";
$commissions = isset($_POST["commissions"]) ? array_filter($_POST["commissions"]) : "";

$userid = $_SESSION["userid"];
$now = date("Y/m/d H:i:s", time());

//Warehouse that components are transfered from
$transferFrom = $_POST["transferFrom"];
//Warehouse that is being transfered components
$transferTo = $_POST["transferTo"];

function getBomValues($bomType, $bomId, $bomVer, $bomLamId) {
    $bomValues = [
        $bomType.'_id' => $bomId['deviceId'],
        'version' => $bomVer == 'n/d' ? null : $bomVer,
    ];

    if($bomType == 'smd') {
        $bomValues['laminate_id'] = $bomLamId;
    }

    return $bomValues;
}

// In case of existing commissions
if (!empty($commissions)) {
    $comment = "Przekazanie materiałów do zlecenia";
    //Input type: Przekazanie materiałów do produkcji
    $input_type_id = 8;
    $bomRepository = new BomRepository($MsaDB);
    foreach ($commissions as $commission) {
        if($commission[2] == 'n/d') $commission[2] = "NULL";
        $type = $commission['deviceType'];
        $priority = $commissions['priority'];
        $qty = $commissions['quantity'];
        $version = $commissions['version'];
        $laminateId = $commissions['laminateId'];
        $bomValues = getBomValues($type, $commission, $version, $laminateId);
        $bom = $bomRepository->getBomByValues($type, $bomValues);
        $bomId = $bom->id;
        $commission_id = $MsaDB -> insert("commission__list", ["user_id", "magazine_from", "magazine_to", "bom_" . $type . "_id", "quantity", "timestamp_created", "state_id", "priority"], [$userid, $transferFrom, $transferTo, $bomId, $qty, $now, '1', $priority]);
        $receivers = $commmission['receiversIds'];
        foreach ($receivers as $user) {
            $MsaDB -> insert("commission__receivers", ["commission_id", "user_id"], [$commission_id, $user]);
        }
    }
}

//Input type: Przekazanie materiałow pomiedzy magazynami
$input_type_id = 2;
$comment = "Przekazanie materiałów";
if (!empty($components)) {
    foreach ($components as $component) {
        $type = $component['type'];
        $deviceId = $component['componentId'];
        $qty = $component['transferQty'];
        $MsaDB -> insert("inventory__".$type, [$type."_id", "commission_id", "user_id", "sub_magazine_id", "quantity", "timestamp", "input_type_id", "comment"], [$deviceId, $commission_id, $userid, $transferFrom, $qty*-1, $now, $input_type_id, $comment]);
        $MsaDB -> insert("inventory__".$type, [$type."_id", "commission_id", "user_id", "sub_magazine_id", "quantity", "timestamp", "input_type_id", "comment"], [$deviceId, $commission_id, $userid, $transferTo, $qty, $now, $input_type_id, $comment]);
    }
}

$MsaDB -> db -> commit();


echo json_encode($result);