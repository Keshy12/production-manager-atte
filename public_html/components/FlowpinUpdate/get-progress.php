<?php

use Atte\DB\MsaDB;

header('Content-Type: application/json');

try {
    // Get the latest progress session (or specific session_id if provided)
    $sessionId = $_POST['session_id'] ?? null;

    $MsaDB = MsaDB::getInstance();

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
