<?php
use Atte\DB\MsaDB;
use Atte\Utils\BomRepository;
use Atte\Utils\MagazineRepository;

$MsaDB = MsaDB::getInstance();

$magazineRepository = new MagazineRepository($MsaDB);
$bomRepository = new BomRepository($MsaDB);

$commissions = array_filter($_POST['commissions'] ?? []);
$transferFrom = $_POST['transferFrom'];
$transferTo = $_POST['transferTo'];

$magazineTo = $magazineRepository -> getMagazineById((int)$transferTo);
$magazineToCommissions = $magazineTo->getActiveCommissions();

$existingCommissions = [];

// Group components by commission
$componentsByCommission = [];
foreach($commissions as $key => $commission)
{
    $deviceType = $commission['deviceType'];
    $deviceId   = $commission['deviceId'];
    $bomValues = [
        $deviceType.'_id' => $deviceId,
        'version' => $commission['version'] == 'n/d' ? null : $commission['version'],
        'isActive' => 1
    ];

    if(!empty($commission['laminateId'])) $bomValues['laminate_id'] = $commission['laminateId'];

    $bomsFound = $bomRepository -> getBomByValues($deviceType, $bomValues);

    if(count($bomsFound) < 1) throw new \Exception("BOM not found");
    if(count($bomsFound) > 1) throw new \Exception("Multiple BOMs found");

    $bom = $bomsFound[0];
    $bomId = $bom -> id; // This is the ID from bom__smd, bom__tht, etc.

    // --- REFACTORED DUPLICATE CHECK ---
    foreach ($magazineToCommissions as $activeCommission) {
        $activeCommission->getReceivers(); // Ensure receivers are loaded
        $activeCommValues = $activeCommission->commissionValues; // Get the raw data

        // Check using the correct column names from commission__list table
        if ($activeCommission->deviceType === $deviceType
            && isset($activeCommValues["bom_id"]) && $activeCommValues["bom_id"] == $bomId
            && isset($activeCommValues['is_cancelled']) && $activeCommValues['is_cancelled'] == 0
            && isset($activeCommValues['warehouse_from_id']) && $activeCommValues['warehouse_from_id'] == $transferFrom
            && $commission['receiversIds'] == $activeCommission->getReceivers()
        ) {
            // Enhanced duplicate info with version and laminate
            $duplicateInfo = [
                $activeCommValues["id"],
                $commission['deviceName'],
                $activeCommValues["created_at"], // Use correct 'created_at' column
                $key
            ];

            // Add version info
            $version = $commission['version'] !== 'n/d' ? $commission['version'] : '';
            if (!empty($version)) {
                $duplicateInfo[1] .= " (wersja: {$version})";
            }

            // Add laminate info for SMD
            if ($deviceType === 'smd' && !empty($commission['laminate'])) {
                $duplicateInfo[1] .= " (laminat: {$commission['laminate']})";
            }

            $existingCommissions[] = $duplicateInfo;
            break; // Found a duplicate, no need to check other active commissions
        }
    }
    // --- END REFACTORED DUPLICATE CHECK ---

    // Group components by commission
    $componentsByCommission[$key] = [
        'commissionInfo' => [
            'deviceName' => $commission['deviceName'],
            'receivers' => $commission['receivers'],
            'priorityColor' => $commission['priorityColor']
        ],
        'components' => []
    ];

    foreach($bom->getComponents($commission['quantity']) as $bomComponent)
    {
        $bomType = $bomComponent['type'];
        $bomComponentId = $bomComponent['componentId'];
        $bomComponentQty = $bomComponent['quantity'];

        $componentsByCommission[$key]['components'][] = [
            'type' => $bomType,
            'componentId' => $bomComponentId,
            'neededForCommissionQty' => $bomComponentQty,
            'commissionKey' => $key,
        ];
    }
}

echo json_encode([$componentsByCommission, $existingCommissions]
    ,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);