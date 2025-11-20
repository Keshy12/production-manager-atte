<?php
/**
 * Get Session Transfers API
 *
 * Fetches all transfers for a specific FlowPin update session
 * Returns grouped transfers similar to Archive view
 */

use Atte\DB\MsaDB;

header('Content-Type: application/json');

$MsaDB = MsaDB::getInstance();
$sessionId = (int)($_POST['session_id'] ?? 0);

if ($sessionId <= 0) {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid session ID'
    ]);
    exit;
}

try {
    // First, get the session details
    $sessionInfo = $MsaDB->query("
        SELECT
            id,
            session_id,
            status,
            started_at,
            updated_at,
            starting_event_id,
            finishing_event_id,
            created_transfer_count,
            created_group_count
        FROM ref__flowpin_update_progress
        WHERE id = $sessionId
        LIMIT 1
    ");

    if (empty($sessionInfo)) {
        echo json_encode([
            'success' => false,
            'message' => 'Session not found'
        ]);
        exit;
    }

    $session = $sessionInfo[0];

    // Fetch all transfers from all 4 inventory types, grouped by transfer_group_id
    $inventoryTypes = [
        'sku' => 'SKU',
        'tht' => 'THT',
        'smd' => 'SMD',
        'parts' => 'Części'
    ];

    $groups = [];

    foreach ($inventoryTypes as $type => $typeName) {
        // Fetch all transfers for this session and type
        $transfers = $MsaDB->query("
            SELECT
                i.id,
                i.{$type}_id as device_id,
                i.sub_magazine_id,
                i.qty,
                i.timestamp,
                i.comment,
                i.input_type_id,
                i.is_cancelled,
                i.cancelled_at,
                i.cancelled_by,
                i.transfer_group_id,
                i.flowpin_event_id,
                tg.id as group_id,
                tg.created_at as group_created_at,
                tg.created_by as group_created_by,
                sm.sub_magazine_name as magazine_name,
                it.name as input_type_name,
                u.name as cancelled_by_firstname,
                u.surname as cancelled_by_lastname,
                gu.name as group_created_by_firstname,
                gu.surname as group_created_by_lastname,
                l.name as device_name
            FROM inventory__{$type} i
            LEFT JOIN inventory__transfer_groups tg ON i.transfer_group_id = tg.id
            LEFT JOIN magazine__list sm ON i.sub_magazine_id = sm.sub_magazine_id
            LEFT JOIN inventory__input_type it ON i.input_type_id = it.id
            LEFT JOIN user u ON i.cancelled_by = u.user_id
            LEFT JOIN user gu ON tg.created_by = gu.user_id
            LEFT JOIN list__{$type} l ON i.{$type}_id = l.id
            WHERE i.flowpin_update_session_id = $sessionId
            ORDER BY COALESCE(tg.created_at, i.timestamp) DESC, i.id DESC
        ");

        foreach ($transfers as $transfer) {
            $groupId = $transfer['transfer_group_id'] ?? 'single_' . $transfer['id'];

            if (!isset($groups[$groupId])) {
                $groups[$groupId] = [
                    'group_id' => $transfer['transfer_group_id'],
                    'group_created_at' => $transfer['group_created_at'],
                    'group_created_by' => $transfer['group_created_by'],
                    'group_created_by_name' => $transfer['group_created_by_firstname'] && $transfer['group_created_by_lastname']
                        ? $transfer['group_created_by_firstname'] . ' ' . $transfer['group_created_by_lastname']
                        : 'System',
                    'transfers' => [],
                    'total_transfers' => 0,
                    'cancelled_count' => 0,
                    'is_all_cancelled' => true
                ];
            }

            $groups[$groupId]['transfers'][] = [
                'id' => $transfer['id'],
                'device_id' => $transfer['device_id'],
                'device_name' => $transfer['device_name'],
                'device_type' => $type,
                'device_type_name' => $typeName,
                'magazine_id' => $transfer['sub_magazine_id'],
                'magazine_name' => $transfer['magazine_name'],
                'qty' => $transfer['qty']+0,
                'timestamp' => $transfer['timestamp'],
                'comment' => $transfer['comment'],
                'input_type_id' => $transfer['input_type_id'],
                'input_type_name' => $transfer['input_type_name'],
                'is_cancelled' => (bool)$transfer['is_cancelled'],
                'cancelled_at' => $transfer['cancelled_at'],
                'cancelled_by_name' => $transfer['cancelled_by_firstname'] && $transfer['cancelled_by_lastname']
                    ? $transfer['cancelled_by_firstname'] . ' ' . $transfer['cancelled_by_lastname']
                    : null,
                'flowpin_event_id' => $transfer['flowpin_event_id']
            ];

            $groups[$groupId]['total_transfers']++;

            if ($transfer['is_cancelled']) {
                $groups[$groupId]['cancelled_count']++;
            } else {
                $groups[$groupId]['is_all_cancelled'] = false;
            }
        }
    }

    // Convert groups array to indexed array and sort by date
    $groupsArray = array_values($groups);
    usort($groupsArray, function($a, $b) {
        $dateA = $a['group_created_at'] ?? ($a['transfers'][0]['timestamp'] ?? '');
        $dateB = $b['group_created_at'] ?? ($b['transfers'][0]['timestamp'] ?? '');
        return strtotime($dateB) - strtotime($dateA);
    });

    echo json_encode([
        'success' => true,
        'session' => $session,
        'groups' => $groupsArray,
        'total_groups' => count($groupsArray)
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}
