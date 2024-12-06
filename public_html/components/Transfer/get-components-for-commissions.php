<?php
use Atte\DB\MsaDB;
use Atte\Utils\BomRepository;

$MsaDB = MsaDB::getInstance();

$bomRepository = new BomRepository($MsaDB);

$commissions = array_filter($_POST['commissions'] ?? []);
$transferFrom = $_POST['transferFrom'];
$transferTo = $_POST['transferTo'];

$components = [];
foreach($commissions as $commission)
{
    $deviceType = $commission['deviceType'];
    $bomValues = [
        $deviceType.'_id' => $commission['deviceId'],
        'version' => $commission['version'] == 'n/d' ? null : $commission['version'],
        'isActive' => 1
    ];
    if(!empty($commission['laminateId'])) $bomValues['laminate_id'] = $commission['laminateId'];
    
    $bomsFound = $bomRepository -> getBomByValues($deviceType, $bomValues);
    
    if(count($bomsFound) < 1) throw new \Exception("BOM not found");
    if(count($bomsFound) > 1) throw new \Exception("Multiple BOMs found");
    
    $bom = $bomsFound[0];
    foreach($bom->getComponents($commission['quantity']) as $bomComponent)
    {
        $bomType = $bomComponent['type'];
        $bomComponentId = $bomComponent['componentId'];
        $bomComponentQty = $bomComponent['quantity'];

        $components[] = [
            'type' => $bomType,
            'componentId' => $bomComponentId,
            'neededForCommissionQty' => $bomComponentQty,
        ];
    }
}

echo json_encode($components
                ,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);