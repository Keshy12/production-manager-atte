<?php

use Atte\DB\MsaDB;
use Atte\Utils\Locker;

header('Content-Type: application/json');

try {
    // Get the latest progress session (or specific session_id if provided)
    $sessionId = $_POST['session_id'] ?? null;

    $MsaDB = MsaDB::getInstance();
    $locker = new Locker('flowpin.lock');
    $isLocked = $locker->isLocked();

    if ($sessionId) {

        // Get specific session progress - properly escape the session_id
        $escapedSessionId = $MsaDB->db->quote($sessionId);
        $result = $MsaDB->query(
            "SELECT * FROM ref__flowpin_update_progress WHERE session_id = $escapedSessionId",
            PDO::FETCH_ASSOC
        );
    } else {
        // Get latest session progress
        $result = $MsaDB->query(
            "SELECT * FROM ref__flowpin_update_progress ORDER BY started_at DESC LIMIT 1",
            PDO::FETCH_ASSOC
        );
    }

    if (empty($result)) {
        echo json_encode([
            'success' => false,
            'error' => 'No progress session found'
        ]);
        exit;
    }

    $progress = $result[0];

    // Check for stale session using lock status
    if ($progress['status'] === 'running' && !$isLocked) {
        $staleId = $progress['id'];
        
        // Count transfers and groups for the stale session to have accurate final numbers
        $transferCount = $MsaDB->query("
            SELECT 
                (SELECT COUNT(*) FROM inventory__sku WHERE flowpin_update_session_id = $staleId) +
                (SELECT COUNT(*) FROM inventory__tht WHERE flowpin_update_session_id = $staleId) +
                (SELECT COUNT(*) FROM inventory__smd WHERE flowpin_update_session_id = $staleId) +
                (SELECT COUNT(*) FROM inventory__parts WHERE flowpin_update_session_id = $staleId) as total
        ", PDO::FETCH_COLUMN);
        
        $groupCount = $MsaDB->query("
            SELECT COUNT(DISTINCT id) FROM inventory__transfer_groups 
                WHERE flowpin_update_session_id = $staleId
        ", PDO::FETCH_COLUMN);

        $MsaDB->update("ref__flowpin_update_progress", [
            "status" => "error",
            "updated_at" => date('Y-m-d H:i:s'),
            "created_transfer_count" => $transferCount[0] ?? 0,
            "created_group_count" => $groupCount[0] ?? 0
        ], "id", $staleId);

        // Update progress object for the response
        $progress['status'] = 'error';
        $progress['updated_at'] = date('Y-m-d H:i:s');
    }

    // Calculate percentage

    $percentage = 0;
    if ($progress['total_records'] > 0) {
        $percentage = round(($progress['processed_records'] / $progress['total_records']) * 100, 2);
    }

    echo json_encode([
        'success' => true,
        'session_id' => $progress['session_id'],
        'total_records' => (int)$progress['total_records'],
        'processed_records' => (int)$progress['processed_records'],
        'current_operation_type' => $progress['current_operation_type'],
        'current_event_id' => $progress['current_event_id'],
        'status' => $progress['status'],
        'percentage' => $percentage,
        'started_at' => $progress['started_at'],
        'updated_at' => $progress['updated_at']
    ]);

} catch (\Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
