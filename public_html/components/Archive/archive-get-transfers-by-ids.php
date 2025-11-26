<?php
use Atte\DB\MsaDB;

header('Content-Type: application/json');

$MsaDB = MsaDB::getInstance();

try {
    // Get transfer IDs from POST
    $transferIdsJson = $_POST['transfer_ids'] ?? '[]';
    $transferIds = json_decode($transferIdsJson, true);

    // Get device type from POST
    $deviceType = $_POST['device_type'] ?? '';
    $allowedTypes = ['sku', 'tht', 'smd', 'parts', 'all'];

    if (!in_array($deviceType, $allowedTypes)) {
        echo json_encode([
            'success' => false,
            'message' => 'Invalid device type'
        ]);
        exit;
    }

    if (empty($transferIds) || !is_array($transferIds)) {
        echo json_encode([
            'success' => false,
            'message' => 'No transfer IDs provided'
        ]);
        exit;
    }

    // Sanitize IDs
    $transferIds = array_map('intval', $transferIds);
    $idsStr = implode(',', $transferIds);

    $allTransfers = [];

    // Determine which device types to query
    $deviceTypesToQuery = [];
    if ($deviceType === 'all') {
        $deviceTypesToQuery = ['sku', 'tht', 'smd', 'parts'];
    } else {
        $deviceTypesToQuery = [$deviceType];
    }

    // Query each device type table
    foreach ($deviceTypesToQuery as $type) {
        $tableName = "inventory__{$type}";
        $deviceIdField = "{$type}_id";

        $query = "
            SELECT
                i.*,
                i.{$deviceIdField} as device_id,
                l.name as device_name,
                u.name as user_name,
                u.surname as user_surname,
                sm.sub_magazine_name as sub_magazine_name,
                it.name as input_type_name,
                '$type' as device_type
            FROM {$tableName} i
            LEFT JOIN list__{$type} l ON i.{$deviceIdField} = l.id
            LEFT JOIN inventory__transfer_groups tg ON i.transfer_group_id = tg.id
            LEFT JOIN user u ON tg.created_by = u.user_id
            LEFT JOIN magazine__list sm ON i.sub_magazine_id = sm.sub_magazine_id
            LEFT JOIN inventory__input_type it ON i.input_type_id = it.id
            WHERE i.id IN ($idsStr)
            ORDER BY i.id DESC
        ";

        $transfers = $MsaDB->query($query);

        if (!empty($transfers)) {
            $allTransfers = array_merge($allTransfers, $transfers);
        }
    }

    echo json_encode([
        'success' => true,
        'transfers' => $allTransfers,
        'count' => count($allTransfers)
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error fetching transfers: ' . $e->getMessage()
    ]);
}
