<?php
use Atte\DB\MsaDB;

header('Content-Type: application/json');

$MsaDB = MsaDB::getInstance();

try {
    $groupIds = $_POST['group_ids'] ?? [];
    $deviceKeys = $_POST['device_keys'] ?? []; // Format: "groupId:deviceId:deviceType"
    $manualIdsByType = $_POST['manual_ids_by_type'] ?? []; // Format: ["sku" => [1,2,3], ...]
    if (is_string($manualIdsByType)) {
        $manualIdsByType = json_decode($manualIdsByType, true) ?? [];
    }
    $showCancelled = isset($_POST['show_cancelled']) && $_POST['show_cancelled'] === '1';
    $deviceTypeFilter = $_POST['device_type_filter'] ?? 'all';
    $forceAll = isset($_POST['force_all']) && $_POST['force_all'] === '1';

    $results = [];
    $deviceTypes = ['sku', 'tht', 'smd', 'parts'];
    $cancelledFilter = $showCancelled ? "" : "AND i.is_cancelled = 0";
    
    $hasHiddenTypes = false;
    $typesWithData = [];

    // First, identify which types actually have data for these groups/keys
    foreach ($deviceTypes as $type) {
        $whereParts = [];
        if (!empty($groupIds)) {
            $gIdsStr = implode(',', array_map('intval', $groupIds));
            $whereParts[] = "i.transfer_group_id IN ($gIdsStr)";
        }
        if (!empty($deviceKeys)) {
            $tuples = [];
            foreach ($deviceKeys as $key) {
                $parts = explode(':', $key);
                if (count($parts) === 3 && $parts[2] === $type) {
                    $tuples[] = "(" . (int)$parts[0] . ", " . (int)$parts[1] . ")";
                }
            }
            if (!empty($tuples)) {
                $deviceIdField = "{$type}_id";
                $whereParts[] = "(i.transfer_group_id, i.$deviceIdField) IN (" . implode(',', $tuples) . ")";
            }
        }
        if (!empty($manualIdsByType[$type])) {
            $mIds = array_map('intval', $manualIdsByType[$type]);
            $whereParts[] = "i.id IN (" . implode(',', $mIds) . ")";
        }

        if (empty($whereParts)) continue;

        $whereClause = "(" . implode(" OR ", $whereParts) . ")";
        
        // Check if we should skip this type due to filter
        if (!$forceAll && $deviceTypeFilter !== 'all' && $type !== $deviceTypeFilter) {
            // Check if there's actually data here that we're hiding
            $checkQuery = "SELECT 1 FROM `inventory__{$type}` i WHERE $whereClause $cancelledFilter LIMIT 1";
            $hasData = $MsaDB->query($checkQuery, PDO::FETCH_ASSOC);
            if (!empty($hasData)) {
                $hasHiddenTypes = true;
            }
            continue;
        }

        $query = "
            SELECT 
                i.id, i.qty, i.timestamp, i.is_cancelled, i.transfer_group_id,
                i.{$type}_id as device_id, l.name as device_name,
                m.sub_magazine_name, u.name as user_name, u.surname as user_surname,
                it.name as input_type_name, '$type' as device_type
            FROM `inventory__{$type}` i
            LEFT JOIN list__{$type} l ON i.{$type}_id = l.id
            LEFT JOIN magazine__list m ON i.sub_magazine_id = m.sub_magazine_id
            LEFT JOIN inventory__transfer_groups tg ON i.transfer_group_id = tg.id
            LEFT JOIN user u ON tg.created_by = u.user_id
            LEFT JOIN inventory__input_type it ON i.input_type_id = it.id
            WHERE $whereClause $cancelledFilter
        ";
        
        $rows = $MsaDB->query($query, PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $results[] = $row;
        }
    }

    echo json_encode([
        'success' => true,
        'entries' => $results,
        'has_hidden_types' => $hasHiddenTypes,
        'filter_used' => $forceAll ? 'all' : $deviceTypeFilter
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
