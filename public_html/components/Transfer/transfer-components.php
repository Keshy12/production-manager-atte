<?php
use Atte\DB\MsaDB;
use Atte\Utils\BomRepository;
use Atte\Utils\CommissionRepository;

$MsaDB = MsaDB::getInstance();
$MsaDB->db->beginTransaction();

// Get and filter components and commissions
$components = isset($_POST["components"]) ? array_filter($_POST["components"]) : "";
$commissions = isset($_POST["commissions"]) ? array_filter($_POST["commissions"]) : "";
$existingCommissions = isset($_POST["existingCommissions"]) ? array_filter($_POST["existingCommissions"]) : [];
$existingCommissionsIds = array_column($existingCommissions, 0, 3);

$userid = $_SESSION["userid"];
$now = date("Y/m/d H:i:s", time());

// Warehouse that components are transferred from
$transferFrom = $_POST["transferFrom"];
// Warehouse that is being transferred components
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

$commissionRepository = new CommissionRepository($MsaDB);
$bomRepository = new BomRepository($MsaDB);

$commissionResult = [];
if (!empty($commissions)) {
    $comment = "Przekazanie materiałów do zlecenia";
    $input_type_id = 8;
    foreach ($commissions as $index => $commission) {
        $type = $commission['deviceType'];
        $priorityId = $commission['priorityId'];
        $qty = $commission['quantity'];
        $version = $commission['version'];
        $laminateId = $commission['laminateId'] ?? null;
        $bomValues = getBomValues($type, $commission, $version, $laminateId);
        $bomsFound = $bomRepository->getBomByValues($type, $bomValues);
        if(count($bomsFound) > 1) throw new \Exception("Multiple BOM records found for the provided values. 
                                                        Unable to proceed with the production.");
        $bom = $bomsFound[0];
        $bomId = $bom->id;

        if (isset($existingCommissionsIds[$index])) {
            $existingId = $existingCommissionsIds[$index];
            $commissionObj = $commissionRepository->getCommissionById($existingId);
            $commissionObj->addToQuantity($qty);
            $newQty = $commissionObj->commissionValues['quantity'];
            $commission_id = $existingId;
        } else {
            $commission_id = $MsaDB->insert("commission__list",
                ["user_id", "magazine_from", "magazine_to", "bom_" . $type . "_id", "quantity", "timestamp_created", "state_id", "priority"],
                [$userid, $transferFrom, $transferTo, $bomId, $qty, $now, '1', $priorityId]
            );
            $newQty = $qty;
        }

        $bom->getNameAndDescription();
        $commissionResult[] = [
            "priorityColor" => $commission["priorityColor"],
            "receivers" => $commission["receivers"],
            "deviceName" => $bom->name,
            "deviceDescription" => $bom->description,
            "laminate" => $bom->laminateName ?? "",
            "version" => $bom->version ?? "",
            "quantity" => $newQty
        ];

        if (!isset($existingCommissionsIds[$index])) {
            $receivers = $commission['receiversIds'];
            foreach ($receivers as $user) {
                $MsaDB->insert("commission__receivers", ["commission_id", "user_id"], [$commission_id, $user]);
            }
        }
    }
}

$componentsResult = [];
$input_type_id = 2;
$comment = "Przekazanie materiałów";
if (!empty($components)) {
    foreach ($components as $component) {
        $type = $component['type'];
        $deviceId = $component['componentId'];
        $qty = $component['transferQty'];
        $commission_id = $commission_id ?? null;
        $MsaDB->insert("inventory__".$type, [$type."_id", "commission_id", "user_id", "sub_magazine_id", "quantity", "timestamp", "input_type_id", "comment"], [$deviceId, $commission_id, $userid, $transferFrom, $qty*-1, $now, $input_type_id, $comment]);
        $MsaDB->insert("inventory__".$type, [$type."_id", "commission_id", "user_id", "sub_magazine_id", "quantity", "timestamp", "input_type_id", "comment"], [$deviceId, $commission_id, $userid, $transferTo, $qty, $now, $input_type_id, $comment]);
        $componentsResult[] = [
            "deviceName" => $component['componentName'],
            "deviceDescription" => $component['componentDescription'],
            "transferQty" => $qty
        ];
    }
}

echo json_encode([$commissionResult, $componentsResult], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);

$MsaDB->db->commit();