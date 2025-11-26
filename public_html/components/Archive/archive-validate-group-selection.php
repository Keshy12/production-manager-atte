<?php
use Atte\DB\MsaDB;

$MsaDB = MsaDB::getInstance();

// Get parameters
$transferGroupId = isset($_POST["transfer_group_id"]) ? (int)$_POST["transfer_group_id"] : null;
$selectedTransferIdsJson = $_POST["selected_transfer_ids_by_type"] ?? '{}';
$selectedTransferIdsByType = json_decode($selectedTransferIdsJson, true) ?: [];

// Validate required parameters
if (!$transferGroupId) {
    echo json_encode([
        'success' => false,
        'message' => 'Missing transfer_group_id parameter'
    ]);
    exit;
}

// Query all transfers in this group across all device types
// ONLY count active (non-cancelled) transfers for validation
// Cancelled transfers are displayed for information only and should not be part of the group completion calculation
$deviceTypes = ['sku', 'tht', 'smd', 'parts'];
$allTransfersByType = [];
$allTransfersByTypeIncludingCancelled = [];
$totalCount = 0;

foreach ($deviceTypes as $type) {
    $tableName = "inventory__{$type}";

    // Get active (non-cancelled) transfer IDs for validation
    $queryActive = "
        SELECT i.id
        FROM `{$tableName}` i
        WHERE i.transfer_group_id = $transferGroupId
        AND i.is_cancelled = 0
        ORDER BY i.id ASC
    ";

    $resultsActive = $MsaDB->query($queryActive, PDO::FETCH_ASSOC);

    if (!empty($resultsActive)) {
        $ids = array_map(function($row) {
            return (int)$row['id'];
        }, $resultsActive);

        $allTransfersByType[$type] = $ids;
        $totalCount += count($ids);
    }

    // Also get ALL transfer IDs (including cancelled) for auto-loading in modal
    $queryAll = "
        SELECT i.id
        FROM `{$tableName}` i
        WHERE i.transfer_group_id = $transferGroupId
        ORDER BY i.id ASC
    ";

    $resultsAll = $MsaDB->query($queryAll, PDO::FETCH_ASSOC);

    if (!empty($resultsAll)) {
        $idsAll = array_map(function($row) {
            return (int)$row['id'];
        }, $resultsAll);

        $allTransfersByTypeIncludingCancelled[$type] = $idsAll;
    }
}

// Compare with user's selection
$selectedCount = 0;
$missingTransfersByType = [];

foreach ($allTransfersByType as $type => $allIds) {
    $selectedIds = isset($selectedTransferIdsByType[$type]) ? $selectedTransferIdsByType[$type] : [];

    // Only count selected IDs that belong to THIS group (intersection)
    $selectedIdsInThisGroup = array_intersect($allIds, $selectedIds);
    $selectedCount += count($selectedIdsInThisGroup);

    // Find missing IDs
    $missingIds = array_diff($allIds, $selectedIds);
    if (!empty($missingIds)) {
        $missingTransfersByType[$type] = array_values($missingIds);
    }
}

$missingCount = $totalCount - $selectedCount;
$isComplete = ($missingCount === 0);

echo json_encode([
    'success' => true,
    'group_id' => $transferGroupId,
    'all_transfers_by_type' => $allTransfersByType,  // Active transfers only (for validation)
    'all_transfers_by_type_including_cancelled' => $allTransfersByTypeIncludingCancelled,  // All transfers (for auto-loading)
    'selected_transfers_by_type' => $selectedTransferIdsByType,
    'missing_transfers_by_type' => $missingTransfersByType,
    'is_complete' => $isComplete,
    'missing_count' => $missingCount,
    'total_count' => $totalCount,  // Active transfers count only
    'selected_count' => $selectedCount
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
