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

// Change: Group components by commission instead of flattening them
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
    $bomId = $bom -> id;
    foreach ($magazineToCommissions as $activeCommission) {
        $activeCommission->getReceivers();
        if ($activeCommission->deviceType === $deviceType
            && $activeCommission->commissionValues["bom_{$deviceType}_id"] == $bomId
            && $activeCommission->commissionValues['isCancelled'] == 0
            && $activeCommission->commissionValues['magazine_from'] == $transferFrom
            && $commission['receiversIds'] == $activeCommission->getReceivers()
        ) {
            $existingCommissions[] = [$activeCommission->commissionValues["id"], $commission['deviceName'], $activeCommission->commissionValues["timestamp_created"], $key];
            break;
        }
    }

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