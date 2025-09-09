<?php
use Atte\DB\MsaDB;
use Atte\Utils\CommissionRepository;
use Atte\Utils\BomRepository;

$MsaDB = MsaDB::getInstance();

try {
    $commissionId = $_POST["id"];
    $rollbackType = $_POST["rollbackType"];

    $commissionRepository = new CommissionRepository($MsaDB);
    $bomRepository = new BomRepository($MsaDB);

    $commission = $commissionRepository->getCommissionById($commissionId);
    $deviceType = $commission->deviceType;
    $commissionValues = $commission->commissionValues;

    // Get magazines
    $magazines = $MsaDB->readIdName("magazine__list", "sub_magazine_id", "sub_magazine_name");

    // Get BOM components for remaining quantity calculation
    $bomId = $commissionValues["bom_{$deviceType}_id"];
    $deviceBom = $bomRepository->getBomById($deviceType, $bomId);
    $bomComponents = $deviceBom->getComponents(1);

    // Get name mappings for all component types
    $partsNames = $MsaDB->readIdName("list__parts");
    $skuNames = $MsaDB->readIdName("list__sku");
    $thtNames = $MsaDB->readIdName("list__tht");
    $smdNames = $MsaDB->readIdName("list__smd");

    // Get the exact same transferred items as displayed in the modal
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

        // Calculate remaining quantities for each item
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

            $item['remainingQuantity'] = max(0, $originalQuantity - $usedQuantity);

            // Only include items that have remaining quantity for remaining/delete operations
            if ($rollbackType === 'remaining' || $rollbackType === 'delete') {
                if ($item['remainingQuantity'] > 0) {
                    $transferredItems[] = $item;
                }
            }
        }
    }

    // Generate summary based on rollback type
    $summary = "";
    if ($rollbackType === 'remaining') {
        $totalRemaining = array_sum(array_column($transferredItems, 'remainingQuantity'));
        $itemsList = [];
        foreach ($transferredItems as $item) {
            $typeLabels = ['parts' => 'Części', 'sku' => 'SKU', 'tht' => 'THT', 'smd' => 'SMD'];
            $itemsList[] = $typeLabels[$item['itemType']] . ": " . $item['itemName'] . " (" . $item['remainingQuantity'] . " szt.)";
        }

        $summary = "
            <strong>Zostanie cofniętych:</strong> {$totalRemaining} szt. łącznie<br>
            <strong>Z magazynu:</strong> {$magazines[$commissionValues['magazine_to']]}<br>
            <strong>Do magazynu:</strong> {$magazines[$commissionValues['magazine_from']]}<br>
            <small class='text-muted'>Przedmioty do cofnięcia:</small><br>
            <small>" . implode("<br>", $itemsList) . "</small>
        ";
    } else if ($rollbackType === 'delete') {
        $totalToDelete = array_sum(array_column($transferredItems, 'remainingQuantity'));
        $itemsList = [];
        foreach ($transferredItems as $item) {
            $typeLabels = ['parts' => 'Części', 'sku' => 'SKU', 'tht' => 'THT', 'smd' => 'SMD'];
            $itemsList[] = $typeLabels[$item['itemType']] . ": " . $item['itemName'] . " (" . $item['remainingQuantity'] . " szt.)";
        }

        $summary = "
            <strong>Zostanie usuniętych:</strong> {$totalToDelete} szt. łącznie<br>
            <strong>Z magazynu:</strong> {$magazines[$commissionValues['magazine_to']]}<br>
            <small class='text-muted'>Przedmioty do usunięcia:</small><br>
            <small>" . implode("<br>", $itemsList) . "</small>
        ";
    }

    echo json_encode([
        'success' => true,
        'summary' => $summary
    ]);

} catch (\Throwable $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>