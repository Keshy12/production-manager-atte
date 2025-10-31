<?php
use Atte\DB\MsaDB;
use Atte\Utils\BomRepository;
use Atte\Utils\CommissionRepository;

$MsaDB = MsaDB::getInstance();
$MsaDB->db->beginTransaction();

try {
    $components = isset($_POST["components"]) ? array_filter($_POST["components"]) : [];
    $commissions = isset($_POST["commissions"]) ? array_filter($_POST["commissions"]) : [];
    $componentSources = isset($_POST["componentSources"]) ? $_POST["componentSources"] : [];

    $magazineNamesCache = [];
    $allMagazines = $MsaDB->query("SELECT sub_magazine_id, sub_magazine_name FROM magazine__list");
    foreach($allMagazines as $magazine) {
        $magazineNamesCache[$magazine['sub_magazine_id']] = $magazine['sub_magazine_name'];
    }

    $userid = $_SESSION["userid"];
    $now = date("Y-m-d H:i:s", time());
    $transferFrom = $_POST["transferFrom"];
    $transferTo = $_POST["transferTo"];

    function getMagazineNameCached($magazineId, $cache) {
        return $cache[$magazineId] ?? 'Unknown';
    }

    function getPriorityName($priorityId) {
        $priorities = [
            0 => 'none',
            1 => 'standard',
            2 => 'urgent',
            3 => 'critical'
        ];
        return $priorities[$priorityId] ?? 'none';
    }

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

    $groupId = null;
    if (!empty($commissions) || !empty($components)) {
        $groupId = $MsaDB->insert("inventory__transfer_groups",
            ["created_by", "notes", "created_at"],
            [$userid, "Multi-source transfer group", $now]
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

            if(count($bomsFound) < 1) {
                throw new \Exception("BOM not found for the provided values.");
            }
            if(count($bomsFound) > 1) {
                throw new \Exception("Multiple BOM records found for the provided values. Unable to proceed.");
            }

            $bom = $bomsFound[0];
            $bomId = $bom->id;

            $commission_id = $MsaDB->insert("commission__list",
                ["created_by", "warehouse_from_id", "warehouse_to_id", "device_type", "bom_id", "qty",
                    "created_at", "state", "priority", "transfer_group_id"],
                [$userid, $transferFrom, $transferTo, $type, $bomId, $qty, $now, 'active',
                    getPriorityName($priorityId), $groupId]
            );

            $commissionKeyToId[$index] = $commission_id;

            $bom->getNameAndDescription();
            $commissionResult[] = [
                "priorityColor" => $commission["priorityColor"],
                "receivers" => $commission["receivers"],
                "deviceName" => $bom->name,
                "deviceDescription" => $bom->description,
                "laminate" => $bom->laminateName ?? "",
                "version" => $bom->version ?? "",
                "quantity" => $qty
            ];

            $receivers = $commission['receiversIds'];
            foreach ($receivers as $user) {
                $MsaDB->insert("commission__receivers", ["commission_id", "user_id"], [$commission_id, $user]);
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

            $commission_id = null;

            if (isset($component['commissionKey']) && $component['commissionKey'] !== null && $component['commissionKey'] !== '') {
                $commissionKey = $component['commissionKey'];
                if (isset($commissionKeyToId[$commissionKey])) {
                    $commission_id = $commissionKeyToId[$commissionKey];
                } else {
                    throw new \Exception("Commission key $commissionKey not found in mapping. Component index: $componentIndex");
                }
            }

            $sources = $componentSources[$componentIndex] ?? [
                ['warehouseId' => $transferFrom, 'quantity' => $totalQty]
            ];

            $transferredSources = [];
            $isNonDefaultTransfer = false;

            foreach($sources as $source) {
                $sourceWarehouse = $source['warehouseId'];
                $sourceQty = $source['quantity'];

                if($sourceQty <= 0) continue;

                if($sourceWarehouse != $transferFrom) {
                    $isNonDefaultTransfer = true;
                }

                $tableName = "inventory__" . $type;

                $insertFields = [$type."_id", "sub_magazine_id", "qty", "timestamp", "input_type_id", "comment"];
                $insertValues = [$deviceId, $sourceWarehouse, $sourceQty * -1, $now, 6, $comment . " - Grupa: $groupId (źródło)"];

                if ($commission_id) {
                    $insertFields[] = "commission_id";
                    $insertValues[] = $commission_id;
                }

                $insertFields[] = "transfer_group_id";
                $insertValues[] = $groupId;

                $MsaDB->insert($tableName, $insertFields, $insertValues);

                $transferredSources[] = [
                    'warehouseName' => getMagazineNameCached($sourceWarehouse, $magazineNamesCache),
                    'quantity' => $sourceQty
                ];
            }

            $tableName = "inventory__" . $type;
            $insertFields = [$type."_id", "sub_magazine_id", "qty", "timestamp", "input_type_id", "comment"];
            $insertValues = [$deviceId, $transferTo, $totalQty, $now, 8, $comment . " - Grupa: $groupId (cel)"];

            if ($commission_id) {
                $insertFields[] = "commission_id";
                $insertValues[] = $commission_id;
            }

            $insertFields[] = "transfer_group_id";
            $insertValues[] = $groupId;

            $MsaDB->insert($tableName, $insertFields, $insertValues);

            $componentsResult[] = [
                "deviceName" => $component['componentName'],
                "deviceDescription" => $component['componentDescription'],
                "transferQty" => $totalQty,
                "sources" => $transferredSources,
                "showSources" => $isNonDefaultTransfer || count($transferredSources) > 1,
                "isDefaultTransfer" => !$isNonDefaultTransfer && count($transferredSources) === 1
            ];
        }
    }

    $MsaDB->db->commit();

    echo json_encode([
        'success' => true,
        'data' => [$commissionResult, $componentsResult]
    ], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);

} catch (\Exception $e) {
    $MsaDB->db->rollBack();

    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
}