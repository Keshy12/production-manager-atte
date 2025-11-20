<?php
use Atte\DB\MsaDB;
use Atte\Utils\CommissionRepository;
use Atte\Utils\BomRepository;

$MsaDB = MsaDB::getInstance();

try {
    $commissionIds = $_POST['commissionIds'] ?? [];

    if (empty($commissionIds)) {
        throw new \Exception('No commission IDs provided');
    }

    $commissionRepository = new CommissionRepository($MsaDB);
    $bomRepository = new BomRepository($MsaDB);

    $result = [];

    foreach ($commissionIds as $commissionId) {
        $commission = $commissionRepository->getCommissionById($commissionId);
        $deviceType = $commission->deviceType;
        $commissionValues = $commission->commissionValues;

        $bomId = $commissionValues["bom_{$deviceType}_id"];
        $deviceBom = $bomRepository->getBomById($deviceType, $bomId);

        // Get components for 1 unit
        $bomComponents = $deviceBom->getComponents(1);

        // Get component names
        $partsNames = $MsaDB->readIdName("list__parts");
        $skuNames = $MsaDB->readIdName("list__sku");
        $thtNames = $MsaDB->readIdName("list__tht");
        $smdNames = $MsaDB->readIdName("list__smd");

        $components = [];

        foreach ($bomComponents as $bomComponent) {
            $type = $bomComponent['type'];
            $componentId = $bomComponent['componentId'];

            // Get component name
            $nameMapping = ${$type . 'Names'};
            $componentName = $nameMapping[$componentId] ?? "Unknown";

            // Calculate quantities
            $quantityPerUnit = $bomComponent['quantity'];
            $totalTransferred = $quantityPerUnit * $commissionValues['quantity'];
            $totalUsed = $quantityPerUnit * $commissionValues['quantity_produced'];
            $remaining = $totalTransferred - $totalUsed;

            $componentKey = "{$type}_{$componentId}";

            $components[$componentKey] = [
                'type' => $type,
                'componentId' => $componentId,
                'componentName' => $componentName,
                'quantityPerUnit' => $quantityPerUnit,
                'totalTransferred' => round($totalTransferred, 5) + 0,
                'totalUsed' => round($totalUsed, 5) + 0,
                'remaining' => round($remaining, 5) + 0
            ];
        }

        $result[$commissionId] = [
            'commissionId' => $commissionId,
            'quantity' => $commissionValues['quantity'],
            'quantityProduced' => $commissionValues['quantity_produced'],
            'quantityReturned' => $commissionValues['quantity_returned'],
            'components' => $components
        ];
    }

    echo json_encode([
        'success' => true,
        'data' => $result
    ]);

} catch (\Throwable $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}