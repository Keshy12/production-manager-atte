<?php
use Atte\DB\MsaDB;

header('Content-Type: application/json');

$MsaDB = MsaDB::getInstance();

try {
    $entryIds = $_POST['entry_ids'] ?? '';
    $deviceType = $_POST['device_type'] ?? '';

    if (empty($entryIds) || !in_array($deviceType, ['sku', 'tht', 'smd', 'parts'])) {
        echo json_encode(['success' => false, 'message' => 'Invalid parameters']);
        exit;
    }

    $tableName = "inventory__{$deviceType}";
    $deviceIdField = "{$deviceType}_id";

    $query = "
        SELECT
            i.*,
            i.{$deviceIdField} as device_id,
            l.name as device_name,
            '$deviceType' as device_type,
            u.name as user_name,
            u.surname as user_surname,
            it.name as input_type_name,
            sm.sub_magazine_name as sub_magazine_name
        FROM {$tableName} i
        LEFT JOIN list__{$deviceType} l ON i.{$deviceIdField} = l.id
        LEFT JOIN inventory__transfer_groups tg ON i.transfer_group_id = tg.id
        LEFT JOIN user u ON tg.created_by = u.user_id
        LEFT JOIN inventory__input_type it ON i.input_type_id = it.id
        LEFT JOIN magazine__list sm ON i.sub_magazine_id = sm.sub_magazine_id
        WHERE i.id IN ($entryIds)
        ORDER BY i.id ASC
    ";

    $transfers = $MsaDB->query($query);

    echo json_encode([
        'success' => true,
        'transfers' => $transfers,
        'count' => count($transfers)
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage()
    ]);
}
