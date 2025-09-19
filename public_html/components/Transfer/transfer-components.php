<?php
use Atte\DB\MsaDB;
use Atte\Utils\BomRepository;
use Atte\Utils\CommissionRepository;

$MsaDB = MsaDB::getInstance();
$MsaDB->db->beginTransaction();

$components = isset($_POST["components"]) ? array_filter($_POST["components"]) : "";
$commissions = isset($_POST["commissions"]) ? array_filter($_POST["commissions"]) : "";
$existingCommissions = isset($_POST["existingCommissions"]) ? array_filter($_POST["existingCommissions"]) : [];
$componentSources = isset($_POST["componentSources"]) ? $_POST["componentSources"] : [];
$existingCommissionsIds = array_column($existingCommissions, 0, 3);

$magazineNamesCache = [];
$allMagazines = $MsaDB->query("SELECT sub_magazine_id, sub_magazine_name FROM magazine__list");
foreach($allMagazines as $magazine) {
    $magazineNamesCache[$magazine['sub_magazine_id']] = $magazine['sub_magazine_name'];
}

$userid = $_SESSION["userid"];
$now = date("Y/m/d H:i:s", time());
$transferFrom = $_POST["transferFrom"];
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

// Tworzenie grupy zleceń
$groupId = null;
if (!empty($commissions)) {
    $groupId = $commissionRepository->createCommissionGroup(
        $userid,
        $transferFrom,
        $transferTo,
        "Multi-source transfer group"
    );
}

$commissionKeyToId = [];
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

        $isExpanded = false;
        $initialQty = 0;

        if (isset($existingCommissionsIds[$index])) {
            // Extend existing commission
            $existingId = $existingCommissionsIds[$index];
            $commissionObj = $commissionRepository->getCommissionById($existingId);
            $initialQty = $commissionObj->commissionValues['quantity']; // Store initial quantity
            $commissionObj->addToQuantity($qty);
            $newQty = $commissionObj->commissionValues['quantity'];
            $commission_id = $existingId;
            $isExpanded = true;
        } else {
            // Create new commission with group_id
            $commission_id = $MsaDB->insert("commission__list",
                ["user_id", "magazine_from", "magazine_to", "bom_" . $type . "_id", "quantity",
                    "timestamp_created", "state_id", "priority", "commission_group_id"],
                [$userid, $transferFrom, $transferTo, $bomId, $qty, $now, '1', $priorityId, $groupId]
            );
            $newQty = $qty;
            $initialQty = 0;
        }

        $commissionKeyToId[$index] = $commission_id;

        $bom->getNameAndDescription();
        $commissionResult[] = [
            "priorityColor" => $commission["priorityColor"],
            "receivers" => $commission["receivers"],
            "deviceName" => $bom->name,
            "deviceDescription" => $bom->description,
            "laminate" => $bom->laminateName ?? "",
            "version" => $bom->version ?? "",
            "quantity" => $newQty,
            "isExpanded" => $isExpanded,
            "initialQuantity" => $initialQty,
            "addedQuantity" => $qty
        ];

        // Add receivers only for new commissions
        if (!$isExpanded) {
            $receivers = $commission['receiversIds'];
            foreach ($receivers as $user) {
                $MsaDB->insert("commission__receivers", ["commission_id", "user_id"], [$commission_id, $user]);
            }
        }
    }
}

$componentsResult = [];
$input_type_id = 2;
$comment = "Transfer komponentów";

if (!empty($components)) {
    foreach ($components as $componentIndex => $component) {
        $type = $component['type'];
        $deviceId = $component['componentId'];
        $totalQty = $component['transferQty'];

        // Determine the commission_id based on commissionKey
        $commission_id = null;
        if (isset($component['commissionKey']) && $component['commissionKey'] !== null && $component['commissionKey'] !== '') {
            $commissionKey = $component['commissionKey'];
            if (isset($commissionKeyToId[$commissionKey])) {
                $commission_id = $commissionKeyToId[$commissionKey];
            } else {
                throw new \Exception("Commission key $commissionKey not found in mapping. Component index: $componentIndex");
            }
        }

        // Get sources for this component (from transferSources JS object)
        $sources = $componentSources[$componentIndex] ?? [
            ['warehouseId' => $transferFrom, 'quantity' => $totalQty]
        ];

        $transferredSources = [];
        $isNonDefaultTransfer = false;

        foreach($sources as $source) {
            $sourceWarehouse = $source['warehouseId'];
            $sourceQty = $source['quantity'];

            if($sourceQty <= 0) continue;

            // Check if this source is different from default
            if($sourceWarehouse != $transferFrom) {
                $isNonDefaultTransfer = true;
            }

            // Remove from source warehouse (negative quantity)
            $MsaDB->insert("inventory__".$type,
                [$type."_id", "commission_id", "user_id", "sub_magazine_id", "quantity", "timestamp", "input_type_id", "comment"],
                [$deviceId, $commission_id, $userid, $sourceWarehouse, $sourceQty * -1, $now, $input_type_id, $comment . " - Grupa: $groupId (źródło)"]);

            $transferredSources[] = [
                'warehouseName' => getMagazineNameCached($sourceWarehouse, $magazineNamesCache),
                'quantity' => $sourceQty
            ];
        }

        // Add to destination warehouse (positive quantity)
        $MsaDB->insert("inventory__".$type,
            [$type."_id", "commission_id", "user_id", "sub_magazine_id", "quantity", "timestamp", "input_type_id", "comment"],
            [$deviceId, $commission_id, $userid, $transferTo, $totalQty, $now, $input_type_id, $comment . " - Grupa: $groupId (cel)"]);

        $componentsResult[] = [
            "deviceName" => $component['componentName'],
            "deviceDescription" => $component['componentDescription'],
            "transferQty" => $totalQty,
            "sources" => $transferredSources,
            "showSources" => $isNonDefaultTransfer || count($transferredSources) > 1, // Flag to show sources
            "isDefaultTransfer" => !$isNonDefaultTransfer && count($transferredSources) === 1
        ];
    }
}

function getMagazineNameCached($magazineId, $cache) {
    return $cache[$magazineId] ?? 'Unknown';
}
    echo json_encode([$commissionResult, $componentsResult], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);

$MsaDB->db->commit();