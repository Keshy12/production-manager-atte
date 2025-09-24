<?php
use Atte\DB\MsaDB;
use Atte\Utils\CommissionRepository;

header('Content-Type: application/json');

$MsaDB = MsaDB::getInstance();
$response = ['success' => false, 'message' => '', 'data' => null];

try {
    $commissionId = $_POST['commissionId'];
    $type = $_POST['type']; // 'single' or 'group'

    $commissionRepository = new CommissionRepository($MsaDB);
    $commission = $commissionRepository->getCommissionById($commissionId);

    $commissionGroupId = $commission->commissionValues['commission_group_id'];

    if (!$commissionGroupId) {
        throw new Exception("Commission is not part of a group");
    }

    $commissionGroup = $commissionRepository->getCommissionGroupById($commissionGroupId);
    $transferSummary = $commissionGroup->getTransferSummaryByComponent();

    // Prepare current commission transfer details
    $currentCommissionTransfers = [];

    foreach ($transferSummary as $componentKey => $summary) {
        // Check if this component was transferred for current commission
        $componentTransfers = getComponentTransfersForCommission($MsaDB, $commissionId, $summary['type'], $summary['componentId']);

        if (!empty($componentTransfers)) {
            $sources = [];
            $destination = null;
            $totalQuantity = 0;

            foreach ($componentTransfers as $transfer) {
                if ($transfer['quantity'] < 0) {
                    // Source warehouse
                    $sources[] = [
                        'magazineId' => $transfer['sub_magazine_id'],
                        'magazineName' => $transfer['magazine_name'],
                        'quantity' => abs($transfer['quantity'])
                    ];
                } else {
                    // Destination warehouse
                    $destination = [
                        'magazineId' => $transfer['sub_magazine_id'],
                        'magazineName' => $transfer['magazine_name']
                    ];
                    $totalQuantity = $transfer['quantity'];
                }
            }

            // Get produced quantity for this commission
            $producedQuantity = getProducedQuantity($MsaDB, $commissionId, $summary['type'], $summary['componentId']);

            $currentCommissionTransfers[$componentKey] = [
                'component' => [
                    'name' => $summary['componentName'],
                    'description' => $summary['componentDescription']
                ],
                'sources' => $sources,
                'destination' => $destination,
                'totalQuantity' => $totalQuantity,
                'producedQuantity' => $producedQuantity
            ];
        }
    }

    $data = [
        'currentCommission' => [
            'id' => $commissionId,
            'deviceName' => getCommissionDeviceName($commission),
            'transfers' => $currentCommissionTransfers
        ]
    ];

    // If canceling single commission, also get group transfers
    if ($type === 'single') {
        $groupTransfers = [];

        foreach ($commissionGroup->commissions as $groupCommission) {
            if ($groupCommission->commissionValues['id'] != $commissionId) {
                $groupCommissionTransfers = getCommissionTransfers($MsaDB, $groupCommission->commissionValues['id']);
                $groupTransfers = array_merge($groupTransfers, $groupCommissionTransfers);
            }
        }

        $data['groupTransfers'] = $groupTransfers;
    }

    $response['success'] = true;
    $response['data'] = $data;

} catch (Exception $e) {
    $response['message'] = $e->getMessage();
}

echo json_encode($response);

function getComponentTransfersForCommission($MsaDB, $commissionId, $type, $componentId) {
    return $MsaDB->query("
        SELECT i.*, m.sub_magazine_name as magazine_name
        FROM inventory__$type i
        LEFT JOIN magazine__list m ON i.sub_magazine_id = m.sub_magazine_id
        WHERE i.commission_id = $commissionId AND i.{$type}_id = $componentId
        ORDER BY i.quantity DESC
    ");
}

function getProducedQuantity($MsaDB, $commissionId, $type, $componentId) {
    $deviceType = $type;

    // Get production entries for this commission and component
    $productions = $MsaDB->query("
        SELECT quantity FROM inventory__$deviceType 
        WHERE commission_id = $commissionId 
        AND {$deviceType}_id IN (
            SELECT {$deviceType}_id FROM bom__{$deviceType} 
            WHERE id = (
                SELECT bom_{$deviceType}_id FROM commission__list 
                WHERE id = $commissionId
            )
        )
        AND input_type_id = 1 -- Production entries
    ");

    $totalProduced = 0;
    foreach ($productions as $production) {
        $totalProduced += $production['quantity'];
    }

    return $totalProduced;
}

function getCommissionDeviceName($commission) {
    $deviceType = $commission->deviceType;
    $bomId = $commission->commissionValues["bom_{$deviceType}_id"];

    // Get device name based on type
    $MsaDB = MsaDB::getInstance();

    switch ($deviceType) {
        case 'sku':
            $result = $MsaDB->query("
                SELECT s.name 
                FROM bom__sku bs 
                LEFT JOIN list__sku s ON bs.sku_id = s.id 
                WHERE bs.id = $bomId
            ");
            break;
        case 'smd':
            $result = $MsaDB->query("
                SELECT s.name 
                FROM bom__smd bs 
                LEFT JOIN list__smd s ON bs.smd_id = s.id 
                WHERE bs.id = $bomId
            ");
            break;
        case 'tht':
            $result = $MsaDB->query("
                SELECT t.name 
                FROM bom__tht bt 
                LEFT JOIN list__tht t ON bt.tht_id = t.id 
                WHERE bt.id = $bomId
            ");
            break;
    }

    return $result[0]['name'] ?? 'Unknown Device';
}

function getCommissionTransfers($MsaDB, $commissionId) {
    $transfers = [];

    foreach (['sku', 'smd', 'tht', 'parts'] as $type) {
        $typeTransfers = $MsaDB->query("
            SELECT i.quantity, i.commission_id,
                   mf.sub_magazine_name as magazine_from,
                   mt.sub_magazine_name as magazine_to,
                   l.name as component_name,
                   l.description as component_description
            FROM inventory__$type i
            LEFT JOIN magazine__list mf ON i.sub_magazine_id = mf.sub_magazine_id AND i.quantity < 0
            LEFT JOIN magazine__list mt ON i.sub_magazine_id = mt.sub_magazine_id AND i.quantity > 0
            LEFT JOIN list__$type l ON i.{$type}_id = l.id
            WHERE i.commission_id = $commissionId
        ");

        foreach ($typeTransfers as $transfer) {
            if ($transfer['quantity'] > 0) { // Only positive entries (destinations)
                $transfers[] = [
                    'commissionId' => $transfer['commission_id'],
                    'componentName' => $transfer['component_name'],
                    'componentDescription' => $transfer['component_description'],
                    'quantity' => $transfer['quantity'],
                    'magazineFrom' => $transfer['magazine_from'],
                    'magazineTo' => $transfer['magazine_to']
                ];
            }
        }
    }

    return $transfers;
}
?>