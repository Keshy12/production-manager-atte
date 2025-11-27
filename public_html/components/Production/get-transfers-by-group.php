<?php
use Atte\DB\MsaDB;

header('Content-Type: application/json');

$MsaDB = MsaDB::getInstance();

try {
    $transferGroupId = $_POST['transfer_group_id'] ?? 0;
    $transferGroupId = intval($transferGroupId);

    if ($transferGroupId <= 0) {
        echo json_encode([
            'success' => false,
            'message' => 'Invalid transfer group ID'
        ]);
        exit;
    }

    $allTransfers = [];
    $deviceTypes = ['sku', 'tht', 'smd', 'parts'];

    foreach ($deviceTypes as $type) {
        $tableName = "inventory__{$type}";
        $deviceIdField = "{$type}_id";

        $query = "
            SELECT
                i.*,
                i.{$deviceIdField} as device_id,
                l.name as device_name,
                '$type' as device_type,
                u.name as user_name,
                u.surname as user_surname,
                it.name as input_type_name,
                sm.sub_magazine_name as sub_magazine_name
            FROM {$tableName} i
            LEFT JOIN list__{$type} l ON i.{$deviceIdField} = l.id
            LEFT JOIN inventory__transfer_groups tg ON i.transfer_group_id = tg.id
            LEFT JOIN user u ON tg.created_by = u.user_id
            LEFT JOIN inventory__input_type it ON i.input_type_id = it.id
            LEFT JOIN magazine__list sm ON i.sub_magazine_id = sm.sub_magazine_id
            WHERE i.transfer_group_id = {$transferGroupId}
            ORDER BY i.id ASC
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
