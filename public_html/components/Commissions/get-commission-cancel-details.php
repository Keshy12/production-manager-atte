<?php
use Atte\DB\MsaDB;
use Atte\Utils\CommissionRepository;
use Atte\Utils\BomRepository;

$MsaDB = MsaDB::getInstance();

try {
    $commissionId = $_POST["id"];
    $commissionRepository = new CommissionRepository($MsaDB);
    $bomRepository = new BomRepository($MsaDB);

    $commission = $commissionRepository->getCommissionById($commissionId);
    $deviceType = $commission->deviceType;
    $commissionValues = $commission->commissionValues;

    // Get commission details
    $magazines = $MsaDB->readIdName("magazine__list", "sub_magazine_id", "sub_magazine_name");
    $users = $MsaDB->readIdName("user", "user_id", "name");
    $usersSurname = $MsaDB->readIdName("user", "user_id", "surname");

    // Get device details
    $bomId = $commissionValues["bom_{$deviceType}_id"];
    $deviceBom = $bomRepository->getBomById($deviceType, $bomId);
    $deviceBom->getNameAndDescription();

    // Get BOM components for remaining quantity calculation
    $bomComponents = $deviceBom->getComponents(1); // Get components for 1 unit

    $laminate = "";
    if ($deviceType === 'smd' && !is_null($deviceBom->laminateId)) {
        $laminate = " (Laminat: " . $deviceBom->laminateName . ")";
    }

    // Get name mappings for all component types
    $partsNames = $MsaDB->readIdName("list__parts");
    $skuNames = $MsaDB->readIdName("list__sku");
    $thtNames = $MsaDB->readIdName("list__tht");
    $smdNames = $MsaDB->readIdName("list__smd");

    // Get transferred items from ALL inventory tables
    $transferredItems = [];
    $inventoryTypes = ['parts', 'sku', 'tht', 'smd'];

    foreach ($inventoryTypes as $type) {
        $items = $MsaDB->query("
            SELECT 
                i.id,
                i.quantity,
                i.comment,
                i.user_id,
                i.{$type}_id,
                '{$type}' as itemType
            FROM inventory__{$type} i
            WHERE i.commission_id = {$commissionId} 
            AND i.input_type_id = 2
            AND i.quantity > 0
            ORDER BY i.timestamp ASC
        ");

        // Add item names and calculate remaining quantity
        foreach ($items as $item) {
            $itemId = $item["{$type}_id"];
            $itemNames = ${$type . "Names"};
            $item['itemName'] = isset($itemNames[$itemId]) ? $itemNames[$itemId] : "Unknown {$type} #{$itemId}";
            $item['quantity'] += 0;

            // Calculate remaining quantity for this item
            $originalQuantity = $item['quantity'];
            $usedQuantity = 0;

            // Find matching BOM component to calculate used quantity
            foreach ($bomComponents as $bomComponent) {
                if ($bomComponent['type'] === $type && $bomComponent['componentId'] == $itemId) {
                    $usedQuantity = $bomComponent['quantity'] * $commissionValues['quantity_produced'];
                    break;
                }
            }

            $item['remainingQuantity'] = round(max(0, $originalQuantity - $usedQuantity),5);
            $item['remainingQuantity']+=0;
            $transferredItems[] = $item;
        }
    }

    // Calculate which items are considered "returned" based on quantity_returned
    $quantityReturned = $commissionValues['quantity_returned'];
    $runningTotal = 0;

    foreach ($transferredItems as &$item) {
        $runningTotal += $item['quantity'];
        $item['isReturned'] = $runningTotal <= $quantityReturned ? 1 : 0;
    }

    $unreturnedProducts = max(0, $commissionValues['quantity_produced'] - $commissionValues['quantity_returned']);

    $result = [
        'success' => true,
        'commission' => [
            'deviceName' => $deviceBom->name,
            'deviceDescription' => $deviceBom->description,
            'version' => $deviceBom->version . $laminate,
            'magazineFrom' => $magazines[$commissionValues['magazine_from']],
            'magazineTo' => $magazines[$commissionValues['magazine_to']],
            'quantity' => $commissionValues['quantity'],
            'quantityProduced' => $commissionValues['quantity_produced'],
            'quantityReturned' => $commissionValues['quantity_returned'],
            'unreturnedProducts' => $unreturnedProducts // Add this line
        ],
        'transferredItems' => $transferredItems
    ];

    echo json_encode($result);

} catch (\Throwable $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>