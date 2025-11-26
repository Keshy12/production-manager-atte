<?php
use Atte\DB\MsaDB;

header('Content-Type: application/json');

$MsaDB = MsaDB::getInstance();

try {
    // Get parameters
    $groupId = (int)($_POST['group_id'] ?? 0);
    $deviceId = (int)($_POST['device_id'] ?? 0);
    $deviceType = $_POST['device_type'] ?? '';
    $offset = (int)($_POST['offset'] ?? 0);
    $limit = (int)($_POST['limit'] ?? 10);
    $excludedLoadedIdsJson = $_POST['excluded_loaded_ids'] ?? '[]';
    $excludedLoadedIds = json_decode($excludedLoadedIdsJson, true);
    $includeCancelled = isset($_POST['include_cancelled']) && $_POST['include_cancelled'] === '1';

    // Validate parameters
    $allowedTypes = ['sku', 'tht', 'smd', 'parts'];
    if (!in_array($deviceType, $allowedTypes)) {
        echo json_encode([
            'success' => false,
            'message' => 'Invalid device type'
        ]);
        exit;
    }

    if ($groupId === 0 || $deviceId === 0) {
        echo json_encode([
            'success' => false,
            'message' => 'Invalid group_id or device_id'
        ]);
        exit;
    }

    $tableName = "inventory__{$deviceType}";
    $deviceIdField = "{$deviceType}_id";

    // Build exclusion clause
    $exclusionClause = '';
    if (!empty($excludedLoadedIds) && is_array($excludedLoadedIds)) {
        $excludedIdsStr = implode(',', array_map('intval', $excludedLoadedIds));
        $exclusionClause = " AND i.id NOT IN ($excludedIdsStr)";
    }

    // Cancelled filter - include cancelled transfers when requested (e.g., for cancellation modal)
    $cancelledFilter = $includeCancelled ? '' : 'AND i.is_cancelled = 0';

    // Get total count of unloaded rows (excluding loaded ones)
    $countQuery = "
        SELECT COUNT(*) as total
        FROM {$tableName} i
        WHERE i.transfer_group_id = $groupId
        AND i.{$deviceIdField} = $deviceId
        {$cancelledFilter}
        {$exclusionClause}
    ";
    $countResult = $MsaDB->query($countQuery);
    $totalCount = $countResult[0]['total'] ?? 0;

    // Get total quantity sum for all unloaded rows
    $sumQuery = "
        SELECT SUM(i.qty) as total_qty
        FROM {$tableName} i
        WHERE i.transfer_group_id = $groupId
        AND i.{$deviceIdField} = $deviceId
        {$cancelledFilter}
        {$exclusionClause}
    ";
    $sumResult = $MsaDB->query($sumQuery);
    $totalQty = $sumResult[0]['total_qty'] ?? 0;

    // Get paginated unloaded rows with full details
    $entriesQuery = "
        SELECT
            i.*,
            l.name as device_name,
            u.name as user_name,
            u.surname as user_surname,
            sm.sub_magazine_name as sub_magazine_name,
            it.name as input_type_name
        FROM {$tableName} i
        LEFT JOIN list__{$deviceType} l ON i.{$deviceIdField} = l.id
        LEFT JOIN inventory__transfer_groups tg ON i.transfer_group_id = tg.id
        LEFT JOIN user u ON tg.created_by = u.user_id
        LEFT JOIN magazine__list sm ON i.sub_magazine_id = sm.sub_magazine_id
        LEFT JOIN inventory__input_type it ON i.input_type_id = it.id
        WHERE i.transfer_group_id = $groupId
        AND i.{$deviceIdField} = $deviceId
        {$cancelledFilter}
        {$exclusionClause}
        ORDER BY i.id DESC
        LIMIT $limit OFFSET $offset
    ";
    $entries = $MsaDB->query($entriesQuery);

    // Get device name from first entry (now properly fetched from join)
    $deviceName = '';
    if (!empty($entries)) {
        $deviceName = $entries[0]['device_name'] ?? "Device #$deviceId";
    } else {
        $deviceName = "Device #$deviceId";
    }

    // Add device_type to each entry
    foreach ($entries as &$entry) {
        $entry['device_type'] = $deviceType;
    }

    echo json_encode([
        'success' => true,
        'entries' => $entries,
        'total_count' => $totalCount,
        'total_qty' => $totalQty,
        'device_name' => $deviceName,
        'loaded' => count($entries),
        'hasMore' => ($offset + count($entries)) < $totalCount,
        'remaining' => max(0, $totalCount - $offset - count($entries))
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error fetching unloaded rows: ' . $e->getMessage()
    ]);
}
