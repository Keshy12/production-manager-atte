<?php
use Atte\DB\MsaDB;

$MsaDB = MsaDB::getInstance();
$commissionId = $_POST['commissionId'];

$response = ['success' => false, 'data' => []];

try {
    // Get all transfer groups for this commission
    $transferGroups = $MsaDB->query("
        SELECT DISTINCT 
            tg.id as transfer_group_id,
            tg.created_at,
            tg.created_by,
            tgt.template as transfer_template,
            tg.params as transfer_params,
            tg.is_cancelled
        FROM inventory__transfer_groups tg
        LEFT JOIN ref__transfer_group_types tgt ON tg.type_id = tgt.id

        INNER JOIN (
            SELECT DISTINCT transfer_group_id FROM inventory__parts WHERE commission_id = $commissionId
            UNION
            SELECT DISTINCT transfer_group_id FROM inventory__sku WHERE commission_id = $commissionId
            UNION
            SELECT DISTINCT transfer_group_id FROM inventory__smd WHERE commission_id = $commissionId
            UNION
            SELECT DISTINCT transfer_group_id FROM inventory__tht WHERE commission_id = $commissionId
        ) transfers ON tg.id = transfers.transfer_group_id
        WHERE tg.is_cancelled = 0
        ORDER BY tg.created_at DESC
    ");

    $groups = [];
    foreach ($transferGroups as $transfer) {
        $transferGroupId = $transfer['transfer_group_id'];

        // Get ALL commissions in this transfer group
        $allCommissionsInGroup = $MsaDB->query("
            SELECT DISTINCT 
                cl.id as commission_id,
                cl.warehouse_from_id,
                cl.warehouse_to_id,
                cl.device_type,
                cl.bom_id,
                cl.qty,
                cl.qty_produced,
                cl.qty_returned,
                cl.state,
                cl.priority,
                cl.is_cancelled,
                cl.created_at
            FROM commission__list cl
            INNER JOIN (
                SELECT DISTINCT commission_id FROM inventory__parts WHERE transfer_group_id = $transferGroupId AND commission_id IS NOT NULL
                UNION
                SELECT DISTINCT commission_id FROM inventory__sku WHERE transfer_group_id = $transferGroupId AND commission_id IS NOT NULL
                UNION
                SELECT DISTINCT commission_id FROM inventory__smd WHERE transfer_group_id = $transferGroupId AND commission_id IS NOT NULL
                UNION
                SELECT DISTINCT commission_id FROM inventory__tht WHERE transfer_group_id = $transferGroupId AND commission_id IS NOT NULL
            ) transfers ON cl.id = transfers.commission_id
            WHERE cl.is_cancelled = 0
            ORDER BY cl.created_at ASC
        ");

        // Check if there are manual components (no commission_id) in this group
        $hasManualComponents = $MsaDB->query("
            SELECT COUNT(*) as count
            FROM (
                SELECT id FROM inventory__parts WHERE transfer_group_id = $transferGroupId AND commission_id IS NULL AND is_cancelled = 0
                UNION ALL
                SELECT id FROM inventory__sku WHERE transfer_group_id = $transferGroupId AND commission_id IS NULL AND is_cancelled = 0
                UNION ALL
                SELECT id FROM inventory__smd WHERE transfer_group_id = $transferGroupId AND commission_id IS NULL AND is_cancelled = 0
                UNION ALL
                SELECT id FROM inventory__tht WHERE transfer_group_id = $transferGroupId AND commission_id IS NULL AND is_cancelled = 0
            ) as manual_transfers
        ")[0]['count'] > 0;

        // Get commission details for each commission
        $commissionsWithDetails = [];

        foreach ($allCommissionsInGroup as $commissionData) {
            $commissionIdInGroup = $commissionData['commission_id'];
            $deviceType = $commissionData['device_type'];
            $bomId = $commissionData['bom_id'];

            // Check if this commission appears in other transfer groups (indicating extensions)
            $allGroupsForCommission = $MsaDB->query("
                SELECT DISTINCT transfer_group_id
                FROM (
                    SELECT transfer_group_id FROM inventory__parts WHERE commission_id = $commissionIdInGroup AND is_cancelled = 0
                    UNION
                    SELECT transfer_group_id FROM inventory__sku WHERE commission_id = $commissionIdInGroup AND is_cancelled = 0
                    UNION
                    SELECT transfer_group_id FROM inventory__smd WHERE commission_id = $commissionIdInGroup AND is_cancelled = 0
                    UNION
                    SELECT transfer_group_id FROM inventory__tht WHERE commission_id = $commissionIdInGroup AND is_cancelled = 0
                ) as all_transfers
            ");

            $isExtension = count($allGroupsForCommission) > 1;

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

            // Get transfers specific to this commission in this transfer group
            $commissionTransfers = getCommissionTransfers($MsaDB, $transferGroupId, $commissionData['commission_id']);

            $commissionsWithDetails[] = [
                'commissionId' => $commissionData['commission_id'],
                'transferGroupId' => $transferGroupId,
                'isCurrentCommission' => $commissionData['commission_id'] == $commissionId,
                'isExtension' => $isExtension,
                'deviceName' => $deviceInfo['name'],
                'deviceDescription' => $deviceInfo['description'] ?? '',
                'version' => $deviceInfo['version'] ?? '',
                'laminate' => $deviceInfo['laminate_name'] ?? '',
                'qty' => $commissionData['qty'],
                'qtyProduced' => $commissionData['qty_produced'],
                'qtyReturned' => $commissionData['qty_returned'],
                'state' => $commissionData['state'],
                'priority' => $commissionData['priority'],
                'isCancelled' => $commissionData['is_cancelled'],
                'createdAt' => $commissionData['created_at'],
                'receivers' => $receiverNames,
                'deviceType' => $deviceType,
                'transfers' => $commissionTransfers,
                'warehouseFromId' => $commissionData['warehouse_from_id'],
                'warehouseToId' => $commissionData['warehouse_to_id']
            ];
        }

        // Add manual components as a separate "commission" if they exist
        if ($hasManualComponents) {
            $manualTransfers = getManualComponentTransfers($MsaDB, $transferGroupId);

            $commissionsWithDetails[] = [
                'commissionId' => null,
                'transferGroupId' => $transferGroupId,
                'isCurrentCommission' => false,
                'isManualComponents' => true,
                'deviceName' => 'Komponenty dodane ręcznie',
                'deviceDescription' => 'Komponenty transferowane bez powiązania ze zleceniem',
                'version' => '',
                'laminate' => '',
                'qty' => 0,
                'qtyProduced' => 0,
                'qtyReturned' => 0,
                'state' => 'active',
                'priority' => 'none',
                'isCancelled' => 0,
                'createdAt' => $transfer['created_at'],
                'receivers' => [],
                'deviceType' => '',
                'transfers' => $manualTransfers,
                'warehouseFromId' => null,
                'warehouseToId' => null
            ];
        }

        $groups[] = [
            'id' => $transferGroupId,
            'timestamp' => $transfer['created_at'],
            'notes' => \Atte\Utils\TransferGroupManager::formatNote($transfer['transfer_template'] ?? '', $transfer['transfer_params'] ?? '[]'),
            'createdBy' => $transfer['created_by'],

            'hasOtherCommissions' => count($allCommissionsInGroup) > 1 || $hasManualComponents,
            'allCommissions' => $commissionsWithDetails
        ];
    }

    // After all groups are built, calculate proper badge logic
    // First, collect all commission IDs and their group appearances in current context
    $allVisibleCommissions = [];
    foreach ($groups as $group) {
        foreach ($group['allCommissions'] as $commission) {
            if (!isset($commission['commissionId']) || ($commission['isManualComponents'] ?? false)) continue;

            $commissionIdInContext = $commission['commissionId'];
            if (!isset($allVisibleCommissions[$commissionIdInContext])) {
                $allVisibleCommissions[$commissionIdInContext] = [];
            }
            $allVisibleCommissions[$commissionIdInContext][] = $group['id'];
        }
    }

    // Now update badge logic for each group
    foreach ($groups as &$group) {
        foreach ($group['allCommissions'] as &$commission) {
            if (!isset($commission['commissionId']) || ($commission['isManualComponents'] ?? false)) {
                $commission['extensionBadge'] = 'none';
                continue;
            }

            $commissionIdInContext = $commission['commissionId'];

            // Get ALL transfer groups for this commission (total)
            $allTransfersForCommission = $MsaDB->query("
                SELECT DISTINCT transfer_group_id
                FROM (
                    SELECT transfer_group_id FROM inventory__parts WHERE commission_id = $commissionIdInContext AND is_cancelled = 0
                    UNION
                    SELECT transfer_group_id FROM inventory__sku WHERE commission_id = $commissionIdInContext AND is_cancelled = 0
                    UNION
                    SELECT transfer_group_id FROM inventory__smd WHERE commission_id = $commissionIdInContext AND is_cancelled = 0
                    UNION
                    SELECT transfer_group_id FROM inventory__tht WHERE commission_id = $commissionIdInContext AND is_cancelled = 0
                ) as all_transfers
            ");

            $totalTransferGroups = count($allTransfersForCommission);
            $visibleTransferGroups = count(array_unique($allVisibleCommissions[$commissionIdInContext]));

            // Set badge logic
            if ($totalTransferGroups > 1) {
                if ($visibleTransferGroups >= $totalTransferGroups) {
                    $commission['extensionBadge'] = 'requires_all';
                } else {
                    $commission['extensionBadge'] = 'partial_only';
                }
            } else {
                $commission['extensionBadge'] = 'none';
            }
        }
    }

    $response['success'] = true;
    $response['data'] = $groups;

} catch (Exception $e) {
    $response['message'] = $e->getMessage();
}

echo json_encode($response);

/**
 * Get commission transfers for a specific commission in a transfer group
 */
function getCommissionTransfers($MsaDB, $transferGroupId, $commissionId) {
    $transfers = [];

    foreach (['sku', 'smd', 'tht', 'parts'] as $type) {
        $typeTransfers = $MsaDB->query("
            SELECT 
                i.qty, 
                i.sub_magazine_id,
                l.name as component_name,
                l.description as component_description,
                m.sub_magazine_name as magazine_name
            FROM inventory__$type i
            JOIN list__$type l ON i.{$type}_id = l.id
            JOIN magazine__list m ON i.sub_magazine_id = m.sub_magazine_id
            WHERE i.transfer_group_id = $transferGroupId 
            AND i.commission_id = $commissionId
            AND i.is_cancelled = 0
        ");

        // Group by component
        $componentGroups = [];
        foreach ($typeTransfers as $t) {
            $key = $t['component_name'];
            if (!isset($componentGroups[$key])) {
                $componentGroups[$key] = [
                    'sources' => [],
                    'sourceIds' => [],
                    'destination' => '',
                    'destinationId' => null,
                    'quantity' => 0,
                    'description' => $t['component_description']
                ];
            }

            if ($t['qty'] < 0) {
                // This is a source (negative quantity means taken FROM this warehouse)
                $componentGroups[$key]['sources'][] = $t['magazine_name'] . ' (' . abs($t['qty']) . ')';
                $componentGroups[$key]['sourceIds'][] = [
                    'id' => (int)$t['sub_magazine_id'],
                    'name' => $t['magazine_name'],
                    'quantity' => abs($t['qty'])
                ];
            } else {
                // This is a destination (positive quantity means added TO this warehouse)
                $componentGroups[$key]['destination'] = $t['magazine_name'];
                $componentGroups[$key]['destinationId'] = (int)$t['sub_magazine_id'];
                $componentGroups[$key]['quantity'] += $t['qty'];
            }
        }

        foreach ($componentGroups as $name => $data) {
            $transfers[] = [
                'componentName' => $name,
                'componentDescription' => $data['description'],
                'quantity' => $data['quantity'],
                'sources' => $data['sources'],
                'sourceIds' => $data['sourceIds'],
                'destination' => $data['destination'],
                'destinationId' => $data['destinationId']
            ];
        }
    }

    return $transfers;
}

/**
 * Get manual component transfers (no commission_id) in a transfer group
 */
function getManualComponentTransfers($MsaDB, $transferGroupId) {
    $transfers = [];

    foreach (['sku', 'smd', 'tht', 'parts'] as $type) {
        $typeTransfers = $MsaDB->query("
            SELECT 
                i.qty, 
                i.sub_magazine_id,
                l.name as component_name,
                l.description as component_description,
                m.sub_magazine_name as magazine_name
            FROM inventory__$type i
            JOIN list__$type l ON i.{$type}_id = l.id
            JOIN magazine__list m ON i.sub_magazine_id = m.sub_magazine_id
            WHERE i.transfer_group_id = $transferGroupId 
            AND i.commission_id IS NULL
            AND i.is_cancelled = 0
        ");

        // Group by component
        $componentGroups = [];
        foreach ($typeTransfers as $t) {
            $key = $t['component_name'];
            if (!isset($componentGroups[$key])) {
                $componentGroups[$key] = [
                    'sources' => [],
                    'sourceIds' => [],
                    'destination' => '',
                    'destinationId' => null,
                    'quantity' => 0,
                    'description' => $t['component_description']
                ];
            }

            if ($t['qty'] < 0) {
                $componentGroups[$key]['sources'][] = $t['magazine_name'] . ' (' . abs($t['qty']) . ')';
                $componentGroups[$key]['sourceIds'][] = [
                    'id' => (int)$t['sub_magazine_id'],
                    'name' => $t['magazine_name'],
                    'quantity' => abs($t['qty'])
                ];
            } else {
                $componentGroups[$key]['destination'] = $t['magazine_name'];
                $componentGroups[$key]['destinationId'] = (int)$t['sub_magazine_id'];
                $componentGroups[$key]['quantity'] += $t['qty'];
            }
        }

        foreach ($componentGroups as $name => $data) {
            $transfers[] = [
                'componentName' => $name,
                'componentDescription' => $data['description'],
                'quantity' => $data['quantity'],
                'sources' => $data['sources'],
                'sourceIds' => $data['sourceIds'],
                'destination' => $data['destination'],
                'destinationId' => $data['destinationId']
            ];
        }
    }

    return $transfers;
}