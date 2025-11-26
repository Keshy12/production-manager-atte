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

// Pagination
$page = isset($_POST["page"]) ? (int)$_POST["page"] : 1;
$page = max(1, $page);
$itemsPerPage = 20;
$offset = ($page - 1) * $itemsPerPage;

// If no device type selected, return empty
if (!$deviceType) {
    echo json_encode([
        'groups' => [],
        'totalCount' => 0,
        'hasNextPage' => false
    ]);
    exit;
}

// Handle "all" device types separately
if ($deviceType === 'all') {
    $deviceTypes = ['sku', 'tht', 'smd', 'parts'];

    // Build WHERE conditions (without device_ids filter)
    $conditions = ["1=1"];

    // User IDs filter
    if (!empty($userIds)) {
        $userIdsStr = implode(',', array_map('intval', $userIds));
        $conditions[] = "tg.created_by IN ($userIdsStr)";
    }

    // Input types filter
    if (!empty($inputTypesIds)) {
        $inputTypesStr = implode(',', array_map('intval', $inputTypesIds));
        $conditions[] = "i.input_type_id IN ($inputTypesStr)";
    }

    // Magazine filter
    if (!empty($magazineIds)) {
        $magazineIdsStr = implode(',', array_map('intval', $magazineIds));
        $conditions[] = "i.sub_magazine_id IN ($magazineIdsStr)";
    }

    // FlowPin session filter
    if ($flowpinSessionId) {
        $conditions[] = "i.flowpin_update_session_id = $flowpinSessionId";
    }

    // Date range filter
    if ($dateFrom) {
        if ($noGrouping) {
            $conditions[] = "DATE(i.timestamp) >= '$dateFrom'";
        } else {
            $conditions[] = "DATE(COALESCE(tg.created_at, i.timestamp)) >= '$dateFrom'";
        }
    }
    if ($dateTo) {
        if ($noGrouping) {
            $conditions[] = "DATE(i.timestamp) <= '$dateTo'";
        } else {
            $conditions[] = "DATE(COALESCE(tg.created_at, i.timestamp)) <= '$dateTo'";
        }
    }

    // Cancelled filter
    $cancelledCondition = $showCancelled ? "" : "AND i.is_cancelled = 0";
    $whereClause = implode(" AND ", $conditions);

    // Build UNION query for all device types
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
                i.flowpin_update_session_id,
                tg.created_by as group_created_by,
                tg.notes as group_notes,
                tg.created_at as group_created_at,
                l.name as device_name,
                m.sub_magazine_name,
                u.name as user_name,
                u.surname as user_surname,
                it.name as input_type_name,
                '$type' as device_type
            FROM `inventory__{$type}` i
            LEFT JOIN inventory__transfer_groups tg ON i.transfer_group_id = tg.id
            LEFT JOIN list__{$type} l ON i.{$type}_id = l.id
            LEFT JOIN magazine__list m ON i.sub_magazine_id = m.sub_magazine_id
            LEFT JOIN user u ON u.user_id = tg.created_by
            LEFT JOIN inventory__input_type it ON i.input_type_id = it.id
            WHERE $whereClause $cancelledCondition
        ";
    }

    $unionQuery = implode(" UNION ALL ", $unionParts);

    if ($noGrouping) {
        // NO GROUPING MODE - Count and fetch
        $countQuery = "SELECT COUNT(*) as total FROM ($unionQuery) as combined";
        $countResult = $MsaDB->query($countQuery, PDO::FETCH_ASSOC);
        $totalCount = (int)$countResult[0]['total'];

        $dataQuery = "
            SELECT * FROM ($unionQuery) as combined
            ORDER BY timestamp DESC
            LIMIT $itemsPerPage OFFSET $offset
        ";

        $records = $MsaDB->query($dataQuery, PDO::FETCH_ASSOC);

        // Format as groups
        $groups = [];
        foreach ($records as $record) {
            $groups[] = [
                'group_id' => null,
                'group_notes' => '',
                'group_created_at' => $record['timestamp'],
                'user_name' => $record['user_name'],
                'user_surname' => $record['user_surname'],
                'total_qty' => $record['qty'],
                'entries_count' => 1,
                'cancelled_count' => $record['is_cancelled'] ? 1 : 0,
                'has_cancelled' => (bool)$record['is_cancelled'],
                'all_cancelled' => (bool)$record['is_cancelled'],
                'entries' => [$record]
            ];
        }

        $hasNextPage = $totalCount > ($offset + $itemsPerPage);

    } else {
        // GROUPING MODE - Group by transfer_group_id
        $groupQuery = "
            SELECT
                COALESCE(transfer_group_id, CONCAT('no_group_', id)) as group_key,
                transfer_group_id,
                COALESCE(group_created_at, timestamp) as sort_timestamp,
                group_notes,
                group_created_at,
                user_name,
                user_surname
            FROM ($unionQuery) as combined
            GROUP BY group_key
            ORDER BY sort_timestamp DESC
            LIMIT " . ($itemsPerPage + 1) . " OFFSET $offset
        ";

        $groupResults = $MsaDB->query($groupQuery, PDO::FETCH_ASSOC);

        // Check if there's a next page
        $hasNextPage = count($groupResults) > $itemsPerPage;
        if ($hasNextPage) {
            array_pop($groupResults);
        }

        // Count total groups
        $countQuery = "
            SELECT COUNT(DISTINCT COALESCE(transfer_group_id, CONCAT('no_group_', id))) as total
            FROM ($unionQuery) as combined
        ";
        $countResult = $MsaDB->query($countQuery, PDO::FETCH_ASSOC);
        $totalCount = (int)$countResult[0]['total'];

        // Fetch entries for each group
        $groups = [];
        foreach ($groupResults as $groupInfo) {
            $transferGroupId = $groupInfo['transfer_group_id'];

            if ($transferGroupId) {
                // First get total count for this group
                $countEntriesQuery = "
                    SELECT COUNT(*) as total FROM ($unionQuery) as combined
                    WHERE transfer_group_id = $transferGroupId
                ";
                $countEntries = $MsaDB->query($countEntriesQuery, PDO::FETCH_ASSOC);
                $totalEntriesInGroup = (int)$countEntries[0]['total'];

                // Fetch all entries for this group (will be aggregated by device)
                $entriesQuery = "
                    SELECT * FROM ($unionQuery) as combined
                    WHERE transfer_group_id = $transferGroupId
                    ORDER BY id DESC
                ";
            } else {
                $noGroupId = (int)str_replace('no_group_', '', $groupInfo['group_key']);
                $totalEntriesInGroup = 1; // Single ungrouped entry
                $entriesQuery = "
                    SELECT * FROM ($unionQuery) as combined
                    WHERE id = $noGroupId
                ";
            }

            $entries = $MsaDB->query($entriesQuery, PDO::FETCH_ASSOC);

            // Calculate group totals and collect device types
            $totalQty = 0;
            $cancelledCount = 0;
            $deviceTypesInGroup = [];
            $qtyByDeviceType = [];
            $deviceAggregation = []; // Aggregate by device_id

            foreach ($entries as $entry) {
                $totalQty += $entry['qty'];
                if ($entry['is_cancelled']) {
                    $cancelledCount++;
                }
                if (!in_array($entry['device_type'], $deviceTypesInGroup)) {
                    $deviceTypesInGroup[] = $entry['device_type'];
                }

                // Aggregate qty by device type
                $devType = $entry['device_type'];
                if (!isset($qtyByDeviceType[$devType])) {
                    $qtyByDeviceType[$devType] = 0;
                }
                $qtyByDeviceType[$devType] += $entry['qty'];

                // Aggregate by device_id (for Level 2 display)
                $deviceKey = $devType . '_' . $entry['device_id'];
                if (!isset($deviceAggregation[$deviceKey])) {
                    $deviceAggregation[$deviceKey] = [
                        'device_id' => $entry['device_id'],
                        'device_type' => $devType,
                        'device_name' => $entry['device_name'],
                        'total_qty' => 0,
                        'entries' => [],
                        'entries_count' => 0
                    ];
                }
                $deviceAggregation[$deviceKey]['total_qty'] += $entry['qty'];
                $deviceAggregation[$deviceKey]['entries'][] = $entry;
                $deviceAggregation[$deviceKey]['entries_count']++;
            }

            // Process devices - limit entries per device to 3
            $devices = [];
            foreach ($deviceAggregation as $deviceData) {
                $allEntries = $deviceData['entries'];
                $deviceData['total_entries_count'] = count($allEntries);
                $deviceData['entries'] = array_slice($allEntries, 0, 3);
                $deviceData['entries_loaded'] = count($deviceData['entries']);
                $deviceData['has_more_entries'] = $deviceData['total_entries_count'] > 3;
                $devices[] = $deviceData;
            }

            $groups[] = [
                'group_id' => $transferGroupId,
                'group_notes' => $groupInfo['group_notes'] ?? '',
                'group_created_at' => $groupInfo['group_created_at'] ?? $groupInfo['sort_timestamp'],
                'user_name' => $groupInfo['user_name'],
                'user_surname' => $groupInfo['user_surname'],
                'total_qty' => $totalQty,
                'qty_by_device_type' => $qtyByDeviceType,
                'devices' => $devices,
                'entries_count' => $totalEntriesInGroup,
                'entries_loaded' => count($entries),
                'cancelled_count' => $cancelledCount,
                'has_cancelled' => $cancelledCount > 0,
                'all_cancelled' => $cancelledCount === count($entries),
                'device_types' => $deviceTypesInGroup,
                'has_more_entries' => $totalEntriesInGroup > 10,
                'entries' => $entries
            ];
        }
    }

    echo json_encode([
        'groups' => $groups,
        'totalCount' => $totalCount,
        'hasNextPage' => $hasNextPage,
        'currentPage' => $page
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

// Build WHERE conditions
$conditions = ["1=1"];

// Device IDs filter
if (!empty($deviceIds)) {
    $deviceIdsStr = implode(',', array_map('intval', $deviceIds));
    $conditions[] = "i.{$deviceType}_id IN ($deviceIdsStr)";
}

// User IDs filter (created_by in transfer_groups)
if (!empty($userIds)) {
    $userIdsStr = implode(',', array_map('intval', $userIds));
    $conditions[] = "tg.created_by IN ($userIdsStr)";
}

// Input types filter
if (!empty($inputTypesIds)) {
    $inputTypesStr = implode(',', array_map('intval', $inputTypesIds));
    $conditions[] = "i.input_type_id IN ($inputTypesStr)";
}

// Magazine filter
if (!empty($magazineIds)) {
    $magazineIdsStr = implode(',', array_map('intval', $magazineIds));
    $conditions[] = "i.sub_magazine_id IN ($magazineIdsStr)";
}

// FlowPin session filter
if ($flowpinSessionId) {
    $conditions[] = "i.flowpin_update_session_id = $flowpinSessionId";
}

// Date range filter
if ($dateFrom) {
    if ($noGrouping) {
        $conditions[] = "DATE(i.timestamp) >= '$dateFrom'";
    } else {
        $conditions[] = "DATE(COALESCE(tg.created_at, i.timestamp)) >= '$dateFrom'";
    }
}
if ($dateTo) {
    if ($noGrouping) {
        $conditions[] = "DATE(i.timestamp) <= '$dateTo'";
    } else {
        $conditions[] = "DATE(COALESCE(tg.created_at, i.timestamp)) <= '$dateTo'";
    }
}

// Cancelled filter
$cancelledCondition = $showCancelled ? "" : "AND i.is_cancelled = 0";

$whereClause = implode(" AND ", $conditions);

if ($noGrouping) {
    // NO GROUPING MODE - Fetch individual records with pagination

    // Count total
    $countQuery = "
        SELECT COUNT(*) as total
        FROM `inventory__{$deviceType}` i
        LEFT JOIN inventory__transfer_groups tg ON i.transfer_group_id = tg.id
        WHERE $whereClause $cancelledCondition
    ";
    $countResult = $MsaDB->query($countQuery, PDO::FETCH_ASSOC);
    $totalCount = (int)$countResult[0]['total'];

    // Fetch paginated records
    $query = "
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
            tg.created_by as group_created_by,
            tg.notes as group_notes,
            tg.created_at as group_created_at,
            l.name as device_name,
            m.sub_magazine_name,
            u.name as user_name,
            u.surname as user_surname,
            it.name as input_type_name,
            '$deviceType' as device_type
        FROM `inventory__{$deviceType}` i
        LEFT JOIN inventory__transfer_groups tg ON i.transfer_group_id = tg.id
        LEFT JOIN list__{$deviceType} l ON i.{$deviceType}_id = l.id
        LEFT JOIN magazine__list m ON i.sub_magazine_id = m.sub_magazine_id
        LEFT JOIN user u ON u.user_id = tg.created_by
        LEFT JOIN inventory__input_type it ON i.input_type_id = it.id
        WHERE $whereClause $cancelledCondition
        ORDER BY i.timestamp DESC
        LIMIT $itemsPerPage OFFSET $offset
    ";

    $records = $MsaDB->query($query, PDO::FETCH_ASSOC);

    // Format as groups (each record is its own group)
    $groups = [];
    foreach ($records as $record) {
        $groups[] = [
            'group_id' => null,
            'group_notes' => '',
            'group_created_at' => $record['timestamp'],
            'user_name' => $record['user_name'],
            'user_surname' => $record['user_surname'],
            'total_qty' => $record['qty'],
            'entries_count' => 1,
            'cancelled_count' => $record['is_cancelled'] ? 1 : 0,
            'has_cancelled' => (bool)$record['is_cancelled'],
            'all_cancelled' => (bool)$record['is_cancelled'],
            'entries' => [$record]
        ];
    }

    $hasNextPage = $totalCount > ($offset + $itemsPerPage);

} else {
    // GROUPING MODE - Group by transfer_group_id

    // First, get distinct groups with pagination
    $groupQuery = "
        SELECT
            COALESCE(i.transfer_group_id, CONCAT('no_group_', i.id)) as group_key,
            i.transfer_group_id,
            COALESCE(tg.created_at, i.timestamp) as sort_timestamp,
            tg.notes as group_notes,
            tg.created_at as group_created_at,
            u.name as user_name,
            u.surname as user_surname
        FROM `inventory__{$deviceType}` i
        LEFT JOIN inventory__transfer_groups tg ON i.transfer_group_id = tg.id
        LEFT JOIN user u ON u.user_id = tg.created_by
        WHERE $whereClause $cancelledCondition
        GROUP BY group_key
        ORDER BY sort_timestamp DESC
        LIMIT " . ($itemsPerPage + 1) . " OFFSET $offset
    ";

    $groupResults = $MsaDB->query($groupQuery, PDO::FETCH_ASSOC);

    // Check if there's a next page
    $hasNextPage = count($groupResults) > $itemsPerPage;
    if ($hasNextPage) {
        array_pop($groupResults); // Remove the extra record
    }

    // Count total groups
    $countQuery = "
        SELECT COUNT(DISTINCT COALESCE(i.transfer_group_id, CONCAT('no_group_', i.id))) as total
        FROM `inventory__{$deviceType}` i
        LEFT JOIN inventory__transfer_groups tg ON i.transfer_group_id = tg.id
        WHERE $whereClause $cancelledCondition
    ";
    $countResult = $MsaDB->query($countQuery, PDO::FETCH_ASSOC);
    $totalCount = (int)$countResult[0]['total'];

    // Now fetch all entries for these groups
    $groups = [];
    foreach ($groupResults as $groupInfo) {
        $transferGroupId = $groupInfo['transfer_group_id'];

        if ($transferGroupId) {
            // First get total count for this group
            $countEntriesQuery = "
                SELECT COUNT(*) as total
                FROM `inventory__{$deviceType}` i
                LEFT JOIN inventory__transfer_groups tg ON i.transfer_group_id = tg.id
                WHERE i.transfer_group_id = $transferGroupId
                AND $whereClause $cancelledCondition
            ";
            $countEntries = $MsaDB->query($countEntriesQuery, PDO::FETCH_ASSOC);
            $totalEntriesInGroup = (int)$countEntries[0]['total'];

            // Fetch all entries for this group (will be aggregated by device)
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
                    it.name as input_type_name,
                    '$deviceType' as device_type
                FROM `inventory__{$deviceType}` i
                LEFT JOIN inventory__transfer_groups tg ON i.transfer_group_id = tg.id
                LEFT JOIN list__{$deviceType} l ON i.{$deviceType}_id = l.id
                LEFT JOIN magazine__list m ON i.sub_magazine_id = m.sub_magazine_id
                LEFT JOIN user u ON u.user_id = (SELECT created_by FROM inventory__transfer_groups WHERE id = $transferGroupId)
                LEFT JOIN inventory__input_type it ON i.input_type_id = it.id
                WHERE i.transfer_group_id = $transferGroupId
                AND $whereClause $cancelledCondition
                ORDER BY i.id DESC
            ";
        } else {
            // Single ungrouped entry - extract ID from group_key
            $noGroupId = (int)str_replace('no_group_', '', $groupInfo['group_key']);
            $totalEntriesInGroup = 1;
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
                    it.name as input_type_name,
                    '$deviceType' as device_type
                FROM `inventory__{$deviceType}` i
                LEFT JOIN list__{$deviceType} l ON i.{$deviceType}_id = l.id
                LEFT JOIN magazine__list m ON i.sub_magazine_id = m.sub_magazine_id
                LEFT JOIN user u ON u.user_id = (SELECT created_by FROM inventory__transfer_groups WHERE id = i.transfer_group_id)
                LEFT JOIN inventory__input_type it ON i.input_type_id = it.id
                WHERE i.id = $noGroupId
            ";
        }

        $entries = $MsaDB->query($entriesQuery, PDO::FETCH_ASSOC);

        // Calculate group totals
        $totalQty = 0;
        $cancelledCount = 0;
        $deviceAggregation = []; // Aggregate by device_id

        foreach ($entries as $entry) {
            $totalQty += $entry['qty'];
            if ($entry['is_cancelled']) {
                $cancelledCount++;
            }

            // Aggregate by device_id (for Level 2 display)
            $deviceKey = $deviceType . '_' . $entry['device_id'];
            if (!isset($deviceAggregation[$deviceKey])) {
                $deviceAggregation[$deviceKey] = [
                    'device_id' => $entry['device_id'],
                    'device_type' => $deviceType,
                    'device_name' => $entry['device_name'],
                    'total_qty' => 0,
                    'entries' => [],
                    'entries_count' => 0
                ];
            }
            $deviceAggregation[$deviceKey]['total_qty'] += $entry['qty'];
            $deviceAggregation[$deviceKey]['entries'][] = $entry;
            $deviceAggregation[$deviceKey]['entries_count']++;
        }

        // Process devices - limit entries per device to 3
        $devices = [];
        foreach ($deviceAggregation as $deviceData) {
            $allEntries = $deviceData['entries'];
            $deviceData['total_entries_count'] = count($allEntries);
            $deviceData['entries'] = array_slice($allEntries, 0, 3);
            $deviceData['entries_loaded'] = count($deviceData['entries']);
            $deviceData['has_more_entries'] = $deviceData['total_entries_count'] > 3;
            $devices[] = $deviceData;
        }

        $groups[] = [
            'group_id' => $transferGroupId,
            'group_notes' => $groupInfo['group_notes'] ?? '',
            'group_created_at' => $groupInfo['group_created_at'] ?? $groupInfo['sort_timestamp'],
            'user_name' => $groupInfo['user_name'],
            'user_surname' => $groupInfo['user_surname'],
            'total_qty' => $totalQty,
            'devices' => $devices,
            'entries_count' => $totalEntriesInGroup,
            'entries_loaded' => count($entries),
            'cancelled_count' => $cancelledCount,
            'has_cancelled' => $cancelledCount > 0,
            'all_cancelled' => $cancelledCount === count($entries),
            'has_more_entries' => $totalEntriesInGroup > 10,
            'entries' => $entries
        ];
    }
}

echo json_encode([
    'groups' => $groups,
    'totalCount' => $totalCount,
    'hasNextPage' => $hasNextPage,
    'currentPage' => $page
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);