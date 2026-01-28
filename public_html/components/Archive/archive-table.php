<?php
use Atte\DB\MsaDB;

$MsaDB = MsaDB::getInstance();

// Get filter parameters
$deviceType = $_POST["device_type"] ?? null;
$userIds = $_POST["user_ids"] ?? [];
$deviceIds = $_POST["device_ids"] ?? [];
$inputTypesIds = $_POST["input_type_id"] ?? [];
$magazineIds = $_POST["magazine_ids"] ?? [];
$flowpinSessionId = !empty($_POST["flowpin_session_id"]) ? (int)$_POST["flowpin_session_id"] : null;
$dateFrom = $_POST["date_from"] ?? null;
$dateTo = $_POST["date_to"] ?? null;
$showCancelled = isset($_POST["show_cancelled"]) && $_POST["show_cancelled"] == '1';
$noGrouping = isset($_POST["no_grouping"]) && $_POST["no_grouping"] == '1';

// Snapshot & Mode
$mode = $_POST["mode"] ?? 'data'; 
$snapshotTs = $_POST["snapshot_ts"] ?? date('Y-m-d H:i:s');

// Pagination
$page = isset($_POST["page"]) ? (int)$_POST["page"] : 1;
$itemsPerPage = 20;
$offset = (max(1, $page) - 1) * $itemsPerPage;

if (!$deviceType) {
    echo json_encode(['groups' => [], 'totalCount' => 0, 'hasNextPage' => false]);
    exit;
}

$deviceTypes = ($deviceType === 'all') ? ['sku', 'tht', 'smd', 'parts'] : [$deviceType];

// Sanitize inputs
$sanitizedUserIds = array_map('intval', $userIds);
$sanitizedInputTypesIds = array_map('intval', $inputTypesIds);
$sanitizedMagazineIds = array_map('intval', $magazineIds);
$sanitizedDeviceIds = array_map('intval', $deviceIds);

$snapshotSql = $MsaDB->db->quote($snapshotTs);

/**
 * Helper to build discovery parts
 */
if (!function_exists('buildDiscoveryUnion')) {
    function buildDiscoveryUnion($deviceTypes, $deviceTypeFilter, $sanitizedDeviceIds, $snapshotSql, $showCancelled, $sanitizedInputTypesIds, $sanitizedMagazineIds, $flowpinSessionId, $sanitizedUserIds, $dateFrom, $dateTo, $MsaDB) {
        $parts = [];
        foreach ($deviceTypes as $type) {
            $conds = ["i.timestamp <= $snapshotSql"];
            if (!$showCancelled) $conds[] = "i.is_cancelled = 0";
            if (!empty($sanitizedInputTypesIds)) $conds[] = "i.input_type_id IN (" . implode(',', $sanitizedInputTypesIds) . ")";
            if (!empty($sanitizedMagazineIds)) $conds[] = "i.sub_magazine_id IN (" . implode(',', $sanitizedMagazineIds) . ")";
            if (!empty($sanitizedDeviceIds) && $deviceTypeFilter !== 'all') $conds[] = "i.{$type}_id IN (" . implode(',', $sanitizedDeviceIds) . ")";
            if ($flowpinSessionId) $conds[] = "i.flowpin_update_session_id = " . (int)$flowpinSessionId;
            
            if (!empty($sanitizedUserIds)) {
                $conds[] = "EXISTS (SELECT 1 FROM inventory__transfer_groups tg WHERE tg.id = i.transfer_group_id AND tg.created_by IN (" . implode(',', $sanitizedUserIds) . "))";
            }
            
            if ($dateFrom) $conds[] = "i.timestamp >= " . $MsaDB->db->quote("$dateFrom 00:00:00");
            if ($dateTo) $conds[] = "i.timestamp <= " . $MsaDB->db->quote("$dateTo 23:59:59");

            $parts[] = "SELECT i.id, i.timestamp, i.transfer_group_id, '$type' as dev_type FROM `inventory__{$type}` i WHERE " . implode(" AND ", $conds);
        }
        return implode(" UNION ALL ", $parts);
    }
}

if ($noGrouping) {
    // --- INDIVIDUAL ENTRIES MODE (Discovery Pattern) ---
    $discoveryUnion = buildDiscoveryUnion($deviceTypes, $deviceType, $sanitizedDeviceIds, $snapshotSql, $showCancelled, $sanitizedInputTypesIds, $sanitizedMagazineIds, $flowpinSessionId, $sanitizedUserIds, $dateFrom, $dateTo, $MsaDB);

    if ($mode === 'count') {
        $countQuery = "SELECT COUNT(*) as total FROM ($discoveryUnion) as discovery";
        $totalCount = (int)$MsaDB->query($countQuery, PDO::FETCH_ASSOC)[0]['total'];
        echo json_encode(['totalCount' => $totalCount]);
        exit;
    }

    $pageQuery = "SELECT id, dev_type FROM ($discoveryUnion) as discovery ORDER BY timestamp DESC LIMIT $itemsPerPage OFFSET $offset";
    $pageItems = $MsaDB->query($pageQuery, PDO::FETCH_ASSOC);

    $records = [];
    if (!empty($pageItems)) {
        // Group by type for bulk fetch
        $idsByType = [];
        foreach ($pageItems as $item) $idsByType[$item['dev_type']][] = (int)$item['id'];

        $allData = [];
        foreach ($idsByType as $type => $ids) {
            $idsStr = implode(',', $ids);
            $dataQuery = "
                SELECT 
                    i.id, i.qty, i.timestamp, i.comment, i.is_cancelled,
                    i.{$type}_id as device_id, l.name as device_name,
                    m.sub_magazine_name, u.name as user_name, u.surname as user_surname,
                    it.name as input_type_name, '$type' as device_type, i.transfer_group_id
                FROM `inventory__{$type}` i
                LEFT JOIN list__{$type} l ON i.{$type}_id = l.id
                LEFT JOIN magazine__list m ON i.sub_magazine_id = m.sub_magazine_id
                LEFT JOIN inventory__transfer_groups tg ON i.transfer_group_id = tg.id
                LEFT JOIN user u ON tg.created_by = u.user_id
                LEFT JOIN inventory__input_type it ON i.input_type_id = it.id
                WHERE i.id IN ($idsStr)
            ";
            foreach ($MsaDB->query($dataQuery, PDO::FETCH_ASSOC) as $row) {
                $allData[$type . '_' . $row['id']] = $row;
            }
        }

        // Re-sort to match pageItems order
        foreach ($pageItems as $item) {
            $key = $item['dev_type'] . '_' . $item['id'];
            if (isset($allData[$key])) $records[] = $allData[$key];
        }
    }

    echo json_encode([
        'entries' => $records,
        'snapshot_ts' => $snapshotTs,
        'currentPage' => $page
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;

} else {
    // --- GROUPED VIEW MODE ---
    // Discovery: Find relevant Transfer Group IDs
    $tgConditions = ["tg.created_at <= $snapshotSql"];
    if (!empty($sanitizedUserIds)) $tgConditions[] = "tg.created_by IN (" . implode(',', $sanitizedUserIds) . ")";
    if ($dateFrom) $tgConditions[] = "tg.created_at >= " . $MsaDB->db->quote("$dateFrom 00:00:00");
    if ($dateTo) $tgConditions[] = "tg.created_at <= " . $MsaDB->db->quote("$dateTo 23:59:59");

    $invFilterExists = [];
    foreach ($deviceTypes as $type) {
        $subConds = ["i.transfer_group_id = tg.id"];
        if (!$showCancelled) $subConds[] = "i.is_cancelled = 0";
        if (!empty($sanitizedInputTypesIds)) $subConds[] = "i.input_type_id IN (" . implode(',', $sanitizedInputTypesIds) . ")";
        if (!empty($sanitizedMagazineIds)) $subConds[] = "i.sub_magazine_id IN (" . implode(',', $sanitizedMagazineIds) . ")";
        if (!empty($sanitizedDeviceIds) && $deviceType !== 'all') $subConds[] = "i.{$type}_id IN (" . implode(',', $sanitizedDeviceIds) . ")";
        if ($flowpinSessionId) $subConds[] = "i.flowpin_update_session_id = " . (int)$flowpinSessionId;
        $invFilterExists[] = "EXISTS (SELECT 1 FROM `inventory__{$type}` i WHERE " . implode(" AND ", $subConds) . ")";
    }
    $tgConditions[] = "(" . implode(" OR ", $invFilterExists) . ")";
    $whereClause = implode(" AND ", $tgConditions);

    if ($mode === 'count') {
        $countQuery = "SELECT COUNT(*) as total FROM inventory__transfer_groups tg WHERE $whereClause";
        $totalCount = (int)$MsaDB->query($countQuery, PDO::FETCH_ASSOC)[0]['total'];
        echo json_encode(['totalCount' => $totalCount]);
        exit;
    }

    $discoveryQuery = "SELECT tg.id FROM inventory__transfer_groups tg WHERE $whereClause ORDER BY tg.created_at DESC LIMIT $itemsPerPage OFFSET $offset";
    $tgIds = array_column($MsaDB->query($discoveryQuery, PDO::FETCH_ASSOC), 'id');

    if (empty($tgIds)) {
        echo json_encode(['groups' => [], 'snapshot_ts' => $snapshotTs, 'hasNextPage' => false]);
        exit;
    }

    $tgIdsStr = implode(',', $tgIds);
    $metadataQuery = "SELECT tg.*, u.name as user_name, u.surname as user_surname, tgt.template as group_template FROM inventory__transfer_groups tg LEFT JOIN user u ON tg.created_by = u.user_id LEFT JOIN ref__transfer_group_types tgt ON tg.type_id = tgt.id WHERE tg.id IN ($tgIdsStr)";
    $metadataMap = [];
    foreach ($MsaDB->query($metadataQuery, PDO::FETCH_ASSOC) as $row) $metadataMap[$row['id']] = $row;

    $deviceSummaries = [];
    foreach ($deviceTypes as $type) {
        $summaryQuery = "SELECT i.transfer_group_id, i.{$type}_id as device_id, l.name as device_name, '$type' as device_type, SUM(i.qty) as total_qty, COUNT(*) as total_entries_count, SUM(i.is_cancelled) as total_cancelled_count FROM `inventory__{$type}` i LEFT JOIN list__{$type} l ON i.{$type}_id = l.id WHERE i.transfer_group_id IN ($tgIdsStr) " . ($showCancelled ? "" : "AND i.is_cancelled = 0") . " GROUP BY i.transfer_group_id, i.{$type}_id";
        foreach ($MsaDB->query($summaryQuery, PDO::FETCH_ASSOC) as $s) $deviceSummaries[$s['transfer_group_id']][] = $s;
    }

    $groups = [];
    foreach ($tgIds as $id) {
        $meta = $metadataMap[$id] ?? null; if (!$meta) continue;
        $summaries = $deviceSummaries[$id] ?? [];
        $tQty = 0; $cCount = 0; $eCount = 0; $devices = [];
        foreach ($summaries as $s) {
            $tQty += $s['total_qty']; $cCount += $s['total_cancelled_count']; $eCount += $s['total_entries_count'];
            $s['all_cancelled'] = $s['total_cancelled_count'] == $s['total_entries_count'];
            $s['has_cancelled'] = $s['total_cancelled_count'] > 0;
            $s['entries_loaded'] = 0;
            $s['has_more_entries'] = true;
            $devices[] = $s;
        }
        $groups[] = [
            'group_id' => $id, 'group_notes' => \Atte\Utils\TransferGroupManager::formatNote($meta['group_template'] ?? '', $meta['params'] ?? '[]'),
            'group_created_at' => $meta['created_at'], 'user_name' => $meta['user_name'], 'user_surname' => $meta['user_surname'],
            'total_qty' => $tQty, 'devices' => $devices, 'entries_count' => $eCount, 'cancelled_count' => $cCount,
            'has_cancelled' => $cCount > 0, 'all_cancelled' => $cCount === $eCount
        ];
    }

    echo json_encode(['groups' => $groups, 'snapshot_ts' => $snapshotTs, 'currentPage' => $page], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}
