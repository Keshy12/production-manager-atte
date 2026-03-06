<?php
/**
 * Cancel FlowPin Session API
 *
 * Cancels all transfers created during a specific FlowPin update session
 */

use Atte\DB\MsaDB;

header('Content-Type: application/json');

// Check if user is logged in
// session_start() is already called in config.php
if (!isset($_SESSION['user_id'])) {
    echo json_encode([
        'success' => false,
        'message' => 'User not logged in'
    ]);
    exit;
}

$MsaDB = MsaDB::getInstance();
$sessionId = (int)($_POST['session_id'] ?? 0);
$userId = $_SESSION['user_id'];

if ($sessionId <= 0) {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid session ID'
    ]);
    exit;
}

try {
    $MsaDB->db->beginTransaction();

    $totalCancelled = 0;

    // Cancel transfers from all 4 inventory tables
    $inventoryTypes = ['sku', 'tht', 'smd', 'parts'];

    foreach ($inventoryTypes as $type) {
        // Get all active transfers for this session
        $transfers = $MsaDB->query("
            SELECT id FROM inventory__{$type}
            WHERE flowpin_update_session_id = $sessionId
            AND is_cancelled = 0
        ");

        // Cancel each transfer
        foreach ($transfers as $transfer) {
            $result = $MsaDB->update("inventory__{$type}", [
                'is_cancelled' => 1,
                'cancelled_at' => date('Y-m-d H:i:s'),
                'cancelled_by' => $userId
            ], 'id', $transfer['id']);

            if ($result) {
                $totalCancelled++;
            }
        }
    }

    $MsaDB->db->commit();

    echo json_encode([
        'success' => true,
        'cancelled_count' => $totalCancelled,
        'message' => "Successfully cancelled {$totalCancelled} transfers"
    ]);

} catch (Exception $e) {
    if ($MsaDB->db->inTransaction()) {
        $MsaDB->db->rollBack();
    }

    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}
