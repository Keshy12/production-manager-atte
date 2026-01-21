<?php
use Atte\DB\MsaDB;

header('Content-Type: application/json');

try {
    $sessionId = $_POST['session_id'] ?? null;
    
    if (!$sessionId) {
        echo json_encode([
            'success' => false,
            'error' => 'No session_id provided'
        ]);
        exit;
    }
    
    $MsaDB = MsaDB::getInstance();
    
    // Verify session exists and is in 'running' state
    $session = $MsaDB->query(
        "SELECT status FROM ref__flowpin_update_progress WHERE session_id = " . $MsaDB->db->quote($sessionId),
        PDO::FETCH_ASSOC
    );
    
    if (empty($session)) {
        echo json_encode([
            'success' => false,
            'error' => 'Session not found'
        ]);
        exit;
    }
    
    if ($session[0]['status'] !== 'running') {
        echo json_encode([
            'success' => false,
            'error' => 'Session is not in running state'
        ]);
        exit;
    }
    
    // Mark session as error
    $MsaDB->update(
        "ref__flowpin_update_progress",
        ["status" => "error", "updated_at" => date('Y-m-d H:i:s')],
        "session_id",
        $sessionId
    );
    
    echo json_encode([
        'success' => true,
        'message' => 'Session marked as error'
    ]);
    
} catch (\Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
