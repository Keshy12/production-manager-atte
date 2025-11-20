<?php
/**
 * Get FlowPin Update Sessions API
 *
 * Fetches a list of FlowPin update sessions based on filter criteria
 */

use Atte\DB\MsaDB;

header('Content-Type: application/json');

$MsaDB = MsaDB::getInstance();

// Get filter parameters
$dateFrom = $_POST['date_from'] ?? date('Y-m-d', strtotime('-30 days'));
$dateTo = $_POST['date_to'] ?? date('Y-m-d');
$status = $_POST['status'] ?? 'all';

// Build WHERE conditions
$conditions = [
    "DATE(started_at) >= " . $MsaDB->db->quote($dateFrom),
    "DATE(started_at) <= " . $MsaDB->db->quote($dateTo)
];

if ($status !== 'all') {
    $conditions[] = "status = " . $MsaDB->db->quote($status);
}

$where = implode(' AND ', $conditions);

try {
    // Fetch sessions
    $sessions = $MsaDB->query("
        SELECT
            id,
            session_id,
            status,
            started_at,
            updated_at,
            total_records,
            processed_records,
            starting_event_id,
            finishing_event_id,
            created_transfer_count,
            created_group_count
        FROM ref__flowpin_update_progress
        WHERE $where
        ORDER BY started_at DESC
        LIMIT 100
    ");

    echo json_encode($sessions);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}
