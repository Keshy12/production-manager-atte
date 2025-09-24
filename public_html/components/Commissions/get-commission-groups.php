<?php
use Atte\DB\MsaDB;

$MsaDB = MsaDB::getInstance();
$commissionId = $_POST['commissionId'];

$response = ['success' => false, 'data' => []];

try {
    // Get all commission group transfers for this commission
    $groupTransfers = $MsaDB->query("
        SELECT cgt.*, cg.timestamp_created, cg.comment
        FROM commission__group_transfers cgt
        JOIN commission__groups cg ON cgt.commission_group_id = cg.id
        WHERE cgt.commission_id = $commissionId 
        AND cgt.is_cancelled = 0
        ORDER BY cgt.timestamp_created DESC
    ");

    $groups = [];
    foreach ($groupTransfers as $transfer) {
        $groupId = $transfer['commission_group_id'];

        // Get ALL commissions in this group with their details
        $allCommissionsInGroup = $MsaDB->query("
            SELECT 
                cgt.commission_id,
                cgt.id as transfer_id,
                cl.quantity,
                cl.quantity_produced,
                cl.quantity_returned,
                cl.state_id,
                cl.isCancelled,
                cl.timestamp_created,
                CASE 
                    WHEN cl.bom_sku_id IS NOT NULL THEN 'sku'
                    WHEN cl.bom_smd_id IS NOT NULL THEN 'smd'
                    WHEN cl.bom_tht_id IS NOT NULL THEN 'tht'
                END as device_type,
                COALESCE(cl.bom_sku_id, cl.bom_smd_id, cl.bom_tht_id) as bom_id
            FROM commission__group_transfers cgt
            JOIN commission__list cl ON cgt.commission_id = cl.id
            WHERE cgt.commission_group_id = $groupId
            AND cgt.is_cancelled = 0
            ORDER BY cgt.timestamp_created ASC
        ");

        // Check if there are manual components (no commission_id) in this group
        $hasManualComponents = $MsaDB->query("
            SELECT COUNT(*) as count
            FROM commission__group_transfers cgt
            WHERE cgt.commission_group_id = $groupId
            AND cgt.commission_id IS NULL
            AND cgt.is_cancelled = 0
        ")[0]['count'] > 0;

        // Get commission details for each commission
        $commissionsWithDetails = [];

        foreach ($allCommissionsInGroup as $commissionData) {
            $commissionId = $commissionData['commission_id'];
            $deviceType = $commissionData['device_type'];
            $bomId = $commissionData['bom_id'];

            // Check if this commission appears in other groups (indicating extensions)
            $allGroupsForCommission = $MsaDB->query("
                SELECT DISTINCT commission_group_id
                FROM commission__group_transfers 
                WHERE commission_id = $commissionId
                AND is_cancelled = 0
            ");

            $isExtension = count($allGroupsForCommission) > 1;

            $isPartialView = $isExtension && ($commissionId != $_POST['commissionId']);

            // Get device info based on type
            switch ($deviceType) {
                case 'sku':
                    $deviceInfo = $MsaDB->query("
                SELECT s.name, s.description, bs.version 
                FROM bom__sku bs 
                JOIN list__sku s ON bs.sku_id = s.id 
                WHERE bs.id = $bomId
            ")[0];
                    break;
                case 'smd':
                    $deviceInfo = $MsaDB->query("
                SELECT s.name, s.description, bs.version, l.name as laminate_name
                FROM bom__smd bs 
                JOIN list__smd s ON bs.smd_id = s.id 
                LEFT JOIN list__laminate l ON bs.laminate_id = l.id
                WHERE bs.id = $bomId
            ")[0];
                    break;
                case 'tht':
                    $deviceInfo = $MsaDB->query("
                SELECT t.name, t.description, bt.version 
                FROM bom__tht bt 
                JOIN list__tht t ON bt.tht_id = t.id 
                WHERE bt.id = $bomId
            ")[0];
                    break;
            }

            // Get receivers for this commission
            $receivers = $MsaDB->query("
                SELECT u.name, u.surname 
                FROM commission__receivers cr
                JOIN user u ON cr.user_id = u.user_id
                WHERE cr.commission_id = {$commissionData['commission_id']}
            ");

            $receiverNames = array_map(function($r) { return $r['name'] . ' ' . $r['surname']; }, $receivers);

            // Get transfers specific to this commission
            $commissionTransfers = getCommissionTransfers($MsaDB, $groupId, $commissionData['commission_id']);

            $commissionsWithDetails[] = [
                'commissionId' => $commissionData['commission_id'],
                'transferId' => $commissionData['transfer_id'],
                'isCurrentCommission' => $commissionData['commission_id'] == $commissionId,
                'isExtension' => $isExtension,
                'isPartialView' => $isPartialView,
                'deviceName' => $deviceInfo['name'],
                'deviceDescription' => $deviceInfo['description'] ?? '',
                'version' => $deviceInfo['version'] ?? '',
                'laminate' => $deviceInfo['laminate_name'] ?? '',
                'quantity' => $commissionData['quantity'],
                'quantityProduced' => $commissionData['quantity_produced'],
                'quantityReturned' => $commissionData['quantity_returned'],
                'stateId' => $commissionData['state_id'],
                'isCancelled' => $commissionData['isCancelled'],
                'timestampCreated' => $commissionData['timestamp_created'],
                'receivers' => $receiverNames,
                'deviceType' => $deviceType,
                'transfers' => $commissionTransfers
            ];
        }

        // Add manual components as a separate "commission" if they exist
        if ($hasManualComponents) {
            $manualTransfers = getManualComponentTransfers($MsaDB, $groupId);

            // Get the transfer_id for manual components
            $manualTransferId = $MsaDB->query("
                SELECT id FROM commission__group_transfers 
                WHERE commission_group_id = $groupId 
                AND commission_id IS NULL 
                AND is_cancelled = 0 
                LIMIT 1
            ")[0]['id'] ?? null;

            $commissionsWithDetails[] = [
                'commissionId' => null,
                'transferId' => $manualTransferId,
                'isCurrentCommission' => false,
                'isManualComponents' => true,
                'deviceName' => 'Komponenty dodane ręcznie',
                'deviceDescription' => 'Komponenty transferowane bez powiązania ze zleceniem',
                'version' => '',
                'laminate' => '',
                'quantity' => 0,
                'quantityProduced' => 0,
                'quantityReturned' => 0,
                'stateId' => 0,
                'isCancelled' => 0,
                'timestampCreated' => $transfer['timestamp_created'],
                'receivers' => [],
                'deviceType' => '',
                'transfers' => $manualTransfers
            ];
        }

        $groups[] = [
            'id' => $groupId,
            'transferId' => $transfer['id'],
            'timestamp' => $transfer['timestamp_created'],
            'comment' => $transfer['comment'],
            'hasOtherCommissions' => count($allCommissionsInGroup) > 1 || $hasManualComponents,
            'allCommissions' => $commissionsWithDetails
        ];
    }

    $response['success'] = true;
    $response['data'] = $groups;

} catch (Exception $e) {
    $response['message'] = $e->getMessage();
}

echo json_encode($response);

function getCommissionTransfers($MsaDB, $groupId, $commissionId) {
    $transfers = [];

    foreach (['sku', 'smd', 'tht', 'parts'] as $type) {
        $typeTransfers = $MsaDB->query("
            SELECT i.quantity, i.sub_magazine_id,
                   l.name as component_name,
                   l.description as component_description,
                   m.sub_magazine_name as magazine_name
            FROM inventory__$type i
            JOIN commission__group_transfers cgt ON i.commission_group_transfer_id = cgt.id
            JOIN list__$type l ON i.{$type}_id = l.id
            JOIN magazine__list m ON i.sub_magazine_id = m.sub_magazine_id
            WHERE cgt.commission_group_id = $groupId 
            AND i.commission_id = $commissionId
        ");

        // Group by component
        $componentGroups = [];
        foreach ($typeTransfers as $t) {
            $key = $t['component_name'];
            if (!isset($componentGroups[$key])) {
                $componentGroups[$key] = [
                    'sources' => [],
                    'destination' => '',
                    'quantity' => 0,
                    'description' => $t['component_description']
                ];
            }

            if ($t['quantity'] < 0) {
                $componentGroups[$key]['sources'][] = $t['magazine_name'] . ' (' . abs($t['quantity']) . ')';
            } else {
                $componentGroups[$key]['destination'] = $t['magazine_name'];
                $componentGroups[$key]['quantity'] += $t['quantity'];
            }
        }

        foreach ($componentGroups as $name => $data) {
            $transfers[] = [
                'componentName' => $name,
                'componentDescription' => $data['description'],
                'quantity' => $data['quantity'],
                'sources' => $data['sources'],
                'destination' => $data['destination']
            ];
        }
    }

    return $transfers;
}

function getManualComponentTransfers($MsaDB, $groupId) {
    $transfers = [];

    foreach (['sku', 'smd', 'tht', 'parts'] as $type) {
        $typeTransfers = $MsaDB->query("
            SELECT i.quantity, i.sub_magazine_id,
                   l.name as component_name,
                   l.description as component_description,
                   m.sub_magazine_name as magazine_name
            FROM inventory__$type i
            JOIN commission__group_transfers cgt ON i.commission_group_transfer_id = cgt.id
            JOIN list__$type l ON i.{$type}_id = l.id
            JOIN magazine__list m ON i.sub_magazine_id = m.sub_magazine_id
            WHERE cgt.commission_group_id = $groupId 
            AND cgt.commission_id IS NULL
        ");

        // Group by component
        $componentGroups = [];
        foreach ($typeTransfers as $t) {
            $key = $t['component_name'];
            if (!isset($componentGroups[$key])) {
                $componentGroups[$key] = [
                    'sources' => [],
                    'destination' => '',
                    'quantity' => 0,
                    'description' => $t['component_description']
                ];
            }

            if ($t['quantity'] < 0) {
                $componentGroups[$key]['sources'][] = $t['magazine_name'] . ' (' . abs($t['quantity']) . ')';
            } else {
                $componentGroups[$key]['destination'] = $t['magazine_name'];
                $componentGroups[$key]['quantity'] += $t['quantity'];
            }
        }

        foreach ($componentGroups as $name => $data) {
            $transfers[] = [
                'componentName' => $name,
                'componentDescription' => $data['description'],
                'quantity' => $data['quantity'],
                'sources' => $data['sources'],
                'destination' => $data['destination']
            ];
        }
    }

    return $transfers;
}
