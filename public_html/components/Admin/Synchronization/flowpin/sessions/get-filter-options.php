<?php
/**
 * Get Session Filter Options API
 *
 * Returns unique users and devices for a session using EventId range
 * Called separately to avoid slowing down the main data loading
 */

use Atte\DB\MsaDB;
use Atte\DB\FlowpinDB;

header('Content-Type: application/json');

$MsaDB = MsaDB::getInstance();
$FlowpinDB = FlowpinDB::getInstance();
$sessionId = (int)($_POST['session_id'] ?? 0);

if ($sessionId <= 0) {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid session ID'
    ]);
    exit;
}

try {
    // Get the EventId range for this session from the progress table
    $sessionRange = $MsaDB->query("
        SELECT starting_event_id, finishing_event_id
        FROM ref__flowpin_update_progress
        WHERE id = $sessionId
        LIMIT 1
    ");
    
    if (empty($sessionRange)) {
        echo json_encode([
            'success' => false,
            'message' => 'Session not found'
        ]);
        exit;
    }
    
    $startEventId = (int)$sessionRange[0]['starting_event_id'];
    $finishEventId = (int)$sessionRange[0]['finishing_event_id'];
    
    // Validate the range
    if ($startEventId <= 0 || $finishEventId <= 0 || $startEventId > $finishEventId) {
        echo json_encode([
            'success' => false,
            'message' => 'Invalid EventId range for this session'
        ]);
        exit;
    }
    
    $filterUsers = [];
    $filterDevices = [];
    
    try {
        // Get unique users with counts from FlowPin using EventId range
        $usersQuery = "
            SELECT ByUserEmail as email, COUNT(*) as count
            FROM [report].[ProductQuantityHistoryView]
            WHERE EventId BETWEEN $startEventId AND $finishEventId
            AND ByUserEmail IS NOT NULL
            GROUP BY ByUserEmail
            ORDER BY count DESC, ByUserEmail
        ";
        $usersResult = $FlowpinDB->query($usersQuery);
        foreach ($usersResult as $row) {
            if ($row['email']) {
                $filterUsers[] = [
                    'email' => $row['email'],
                    'count' => (int)$row['count']
                ];
            }
        }
        
        // Get unique devices with counts from the entire session range
        $devicesQuery = "
            SELECT pt.Symbol as device_name, COUNT(*) as count
            FROM [report].[ProductQuantityHistoryView] ph
            JOIN ProductTypes pt ON ph.ProductTypeId = pt.Id
            WHERE ph.EventId BETWEEN $startEventId AND $finishEventId
            AND pt.Symbol IS NOT NULL
            GROUP BY pt.Symbol
            ORDER BY count DESC, pt.Symbol
        ";
        $devicesResult = $FlowpinDB->query($devicesQuery);
        foreach ($devicesResult as $row) {
            if ($row['device_name']) {
                $filterDevices[] = [
                    'name' => $row['device_name'],
                    'count' => (int)$row['count']
                ];
            }
        }
    } catch (Exception $e) {
        error_log("Filter options query failed: " . $e->getMessage());
    }

    echo json_encode([
        'success' => true,
        'filter_options' => [
            'users' => $filterUsers,
            'devices' => $filterDevices
        ]
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}
