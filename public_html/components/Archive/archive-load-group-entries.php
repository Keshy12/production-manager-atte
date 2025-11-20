<?php
use Atte\DB\MsaDB;

$MsaDB = MsaDB::getInstance();

// Get parameters
$transferGroupId = isset($_POST["transfer_group_id"]) ? (int)$_POST["transfer_group_id"] : null;
$deviceType = $_POST["device_type"] ?? null;
$offset = isset($_POST["offset"]) ? (int)$_POST["offset"] : 10;
$limit = isset($_POST["limit"]) ? (int)$_POST["limit"] : 50;
$showCancelled = isset($_POST["show_cancelled"]) && $_POST["show_cancelled"] == '1';

// Validate parameters
if (!$transferGroupId || !$deviceType) {
    echo json_encode([
        'success' => false,
        'message' => 'Missing required parameters'
    ]);
    exit;
}

// Cancelled filter
$cancelledCondition = $showCancelled ? "" : "AND i.is_cancelled = 0";

// Build query based on device type
if ($deviceType === 'all') {
    // For "all" device types, we need to UNION across all tables
    $deviceTypes = ['sku', 'tht', 'smd', 'parts'];

    $unionParts = [];
    foreach ($deviceTypes as $type) {
        $unionParts[] = "
            SELECT
                i.id,
                i.{$type}_id as device_id,
                i.sub_magazine_id,
                i.qty,
                i.timestamp,
                i.comment,
                i.input_type_id,
                i.transfer_group_id,
                i.is_cancelled,
                i.cancelled_at,
                l.name as device_name,
                m.sub_magazine_name,
                u.name as user_name,
                u.surname as user_surname,
                it.name as input_type_name,
                '$type' as device_type
            FROM `inventory__{$type}` i
            LEFT JOIN list__{$type} l ON i.{$type}_id = l.id
            LEFT JOIN magazine__list m ON i.sub_magazine_id = m.sub_magazine_id
            LEFT JOIN user u ON u.user_id = (SELECT created_by FROM inventory__transfer_groups WHERE id = $transferGroupId)
            LEFT JOIN inventory__input_type it ON i.input_type_id = it.id
            WHERE i.transfer_group_id = $transferGroupId $cancelledCondition
        ";
    }

    $unionQuery = implode(" UNION ALL ", $unionParts);

    // Count total entries
    $countQuery = "SELECT COUNT(*) as total FROM ($unionQuery) as combined";
    $countResult = $MsaDB->query($countQuery, PDO::FETCH_ASSOC);
    $totalEntries = (int)$countResult[0]['total'];

    // Fetch paginated entries
    $entriesQuery = "
        SELECT * FROM ($unionQuery) as combined
        ORDER BY id DESC
        LIMIT $limit OFFSET $offset
    ";

    $entries = $MsaDB->query($entriesQuery, PDO::FETCH_ASSOC);

} else {
    // Single device type

    // Count total entries
    $countQuery = "
        SELECT COUNT(*) as total
        FROM `inventory__{$deviceType}` i
        WHERE i.transfer_group_id = $transferGroupId $cancelledCondition
    ";
    $countResult = $MsaDB->query($countQuery, PDO::FETCH_ASSOC);
    $totalEntries = (int)$countResult[0]['total'];

    // Fetch paginated entries
    $entriesQuery = "
        SELECT
            i.id,
            i.{$deviceType}_id as device_id,
            i.sub_magazine_id,
            i.qty,
            i.timestamp,
            i.comment,
            i.input_type_id,
            i.transfer_group_id,
            i.is_cancelled,
            i.cancelled_at,
            l.name as device_name,
            m.sub_magazine_name,
            u.name as user_name,
            u.surname as user_surname,
            it.name as input_type_name
        FROM `inventory__{$deviceType}` i
        LEFT JOIN list__{$deviceType} l ON i.{$deviceType}_id = l.id
        LEFT JOIN magazine__list m ON i.sub_magazine_id = m.sub_magazine_id
        LEFT JOIN user u ON u.user_id = (SELECT created_by FROM inventory__transfer_groups WHERE id = $transferGroupId)
        LEFT JOIN inventory__input_type it ON i.input_type_id = it.id
        WHERE i.transfer_group_id = $transferGroupId $cancelledCondition
        ORDER BY i.id DESC
        LIMIT $limit OFFSET $offset
    ";

    $entries = $MsaDB->query($entriesQuery, PDO::FETCH_ASSOC);

    // Add device_type to each entry for consistent structure
    foreach ($entries as &$entry) {
        $entry['device_type'] = $deviceType;
    }
}

// Calculate if there are more entries
$loadedCount = count($entries);
$hasMore = ($offset + $loadedCount) < $totalEntries;
$remaining = $totalEntries - ($offset + $loadedCount);

echo json_encode([
    'success' => true,
    'entries' => $entries,
    'hasMore' => $hasMore,
    'total' => $totalEntries,
    'offset' => $offset,
    'loaded' => $loadedCount,
    'remaining' => $remaining
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
