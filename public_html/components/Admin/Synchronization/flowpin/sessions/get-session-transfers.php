<?php
/**
 * Get Session Transfers API
 *
 * Fetches paginated transfers grouped by FlowPin EventId
 * Returns grouped structure with FlowPin raw data and local system rows
 * Supports filtering by operation type, date range, user, search text, and devices
 */

use Atte\DB\MsaDB;
use Atte\DB\FlowpinDB;

header('Content-Type: application/json');

$MsaDB = MsaDB::getInstance();
$FlowpinDB = FlowpinDB::getInstance();
$sessionId = (int)($_POST['session_id'] ?? 0);
$page = (int)($_POST['page'] ?? 1);
$limit = (int)($_POST['limit'] ?? 5); // Default to 5 EventIds per page

// Filter parameters
$filterOperationType = $_POST['filter_operation_type'] ?? '';
$filterDateFrom = $_POST['filter_date_from'] ?? '';
$filterDateTo = $_POST['filter_date_to'] ?? '';
$filterUser = $_POST['filter_user'] ?? '';
$filterSearch = $_POST['filter_search'] ?? '';
$filterDevices = $_POST['filter_devices'] ?? '';

// Validate inputs
if ($sessionId <= 0) {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid session ID'
    ]);
    exit;
}

if ($page < 1) {
    $page = 1;
}

if ($limit < 1 || $limit > 50) {
    $limit = 5; // Cap at 50 EventIds to prevent abuse
}

$offset = ($page - 1) * $limit;

// Parse devices filter
$selectedDevices = [];
if (!empty($filterDevices)) {
    $selectedDevices = explode(',', $filterDevices);
}

// Parse users filter (now supports multiple)
$selectedUsers = [];
if (!empty($filterUser)) {
    $selectedUsers = explode(',', $filterUser);
}

try {
    // First, get the session details
    $sessionInfo = $MsaDB->query("
        SELECT
            id,
            session_id,
            status,
            started_at,
            updated_at,
            starting_event_id,
            finishing_event_id,
            created_transfer_count,
            created_group_count
        FROM ref__flowpin_update_progress
        WHERE id = $sessionId
        LIMIT 1
    ");

    if (empty($sessionInfo)) {
        echo json_encode([
            'success' => false,
            'message' => 'Session not found'
        ]);
        exit;
    }

    $session = $sessionInfo[0];

    // Step 1: Get unique EventIds for this session (paginated, newest first)
    $eventIdsResult = $MsaDB->query("
        SELECT DISTINCT flowpin_event_id 
        FROM (
            SELECT flowpin_event_id FROM inventory__sku WHERE flowpin_update_session_id = $sessionId
            UNION
            SELECT flowpin_event_id FROM inventory__tht WHERE flowpin_update_session_id = $sessionId
            UNION
            SELECT flowpin_event_id FROM inventory__smd WHERE flowpin_update_session_id = $sessionId
            UNION
            SELECT flowpin_event_id FROM inventory__parts WHERE flowpin_update_session_id = $sessionId
        ) as events
        WHERE flowpin_event_id IS NOT NULL
        ORDER BY flowpin_event_id DESC
        LIMIT $limit OFFSET $offset
    ");

    if (empty($eventIdsResult)) {
        echo json_encode([
            'success' => true,
            'session' => $session,
            'events' => [],
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total_events' => 0,
                'total_pages' => 0,
                'has_next' => false,
                'has_prev' => $page > 1
            ],
            'filter_options' => [
                'users' => [],
                'devices' => []
            ]
        ]);
        exit;
    }

    $eventIds = array_column($eventIdsResult, 'flowpin_event_id');
    $eventIdList = implode(',', $eventIds);

    // Step 2: Get total count of unique EventIds for pagination
    $totalEventsResult = $MsaDB->query("
        SELECT COUNT(DISTINCT flowpin_event_id) as total
        FROM (
            SELECT flowpin_event_id FROM inventory__sku WHERE flowpin_update_session_id = $sessionId
            UNION
            SELECT flowpin_event_id FROM inventory__tht WHERE flowpin_update_session_id = $sessionId
            UNION
            SELECT flowpin_event_id FROM inventory__smd WHERE flowpin_update_session_id = $sessionId
            UNION
            SELECT flowpin_event_id FROM inventory__parts WHERE flowpin_update_session_id = $sessionId
        ) as events
        WHERE flowpin_event_id IS NOT NULL
    ");
    $totalEvents = (int)($totalEventsResult[0]['total'] ?? 0);
    $totalPages = (int)ceil($totalEvents / $limit);

    // Step 3: Query FlowPin for raw data for these EventIds
    $flowpinRawData = [];
    $productTypeIds = [];
    try {
        // Query only columns that actually exist in the view
        // Note: SaleQty and ReturnQty are calculated fields in specific queries, not view columns
        $flowpinQuery = "
            SELECT 
                EventId, 
                ExecutionDate, 
                ByUserEmail, 
                ProductTypeId, 
                EventTypeValue, 
                ProductionQty,
                FieldOldValue, 
                FieldNewValue, 
                State, 
                WarehouseId,
                ParentId,
                IsInter
            FROM [report].[ProductQuantityHistoryView]
            WHERE EventId IN ($eventIdList)
        ";
        $flowpinResults = $FlowpinDB->query($flowpinQuery);
        
        // Index by EventId for easy lookup and collect ProductTypeIds
        foreach ($flowpinResults as $row) {
            $flowpinRawData[$row['EventId']] = $row;
            if ($row['ProductTypeId']) {
                $productTypeIds[] = $row['ProductTypeId'];
            }
        }
    } catch (Exception $e) {
        // FlowPin query failed, continue with empty flowpin data
        error_log("FlowPin query failed: " . $e->getMessage());
    }

    // Step 3b: Query FlowPin ProductTypes to get device names
    $productNames = [];
    if (!empty($productTypeIds)) {
        try {
            $uniqueProductTypeIds = array_unique($productTypeIds);
            $productTypeIdList = implode(',', $uniqueProductTypeIds);
            $productQuery = "
                SELECT Id, Symbol
                FROM ProductTypes
                WHERE Id IN ($productTypeIdList)
            ";
            $productResults = $FlowpinDB->query($productQuery);
            
            foreach ($productResults as $row) {
                $productNames[$row['Id']] = $row['Symbol'];
            }
        } catch (Exception $e) {
            error_log("ProductTypes query failed: " . $e->getMessage());
        }
    }

    // Add device_name to flowpin_raw data
    foreach ($flowpinRawData as $eventId => &$data) {
        $productTypeId = $data['ProductTypeId'];
        $data['device_name'] = $productNames[$productTypeId] ?? 'Unknown';
    }

    // Step 4: Fetch all local rows for these EventIds
    $inventoryTypes = [
        'sku' => 'SKU',
        'tht' => 'THT',
        'smd' => 'SMD',
        'parts' => 'Części'
    ];

    $unionParts = [];
    
    foreach ($inventoryTypes as $type => $typeName) {
        $unionParts[] = "
            SELECT
                i.id,
                i.{$type}_id as device_id,
                i.sub_magazine_id,
                i.qty,
                i.timestamp,
                i.comment,
                i.input_type_id,
                i.is_cancelled,
                i.cancelled_at,
                i.cancelled_by,
                i.transfer_group_id,
                i.flowpin_event_id,
                '{$type}' as device_type,
                '{$typeName}' as device_type_name,
                sm.sub_magazine_name as magazine_name,
                it.name as input_type_name,
                u.name as cancelled_by_firstname,
                u.surname as cancelled_by_lastname,
                l.name as device_name
            FROM inventory__{$type} i
            LEFT JOIN magazine__list sm ON i.sub_magazine_id = sm.sub_magazine_id
            LEFT JOIN inventory__input_type it ON i.input_type_id = it.id
            LEFT JOIN user u ON i.cancelled_by = u.user_id
            LEFT JOIN list__{$type} l ON i.{$type}_id = l.id
            WHERE i.flowpin_update_session_id = $sessionId
            AND i.flowpin_event_id IN ($eventIdList)
        ";
    }

    $unionSql = implode(" UNION ALL ", $unionParts);
    
    $localRows = $MsaDB->query("
        SELECT * FROM (
            {$unionSql}
        ) as combined
        ORDER BY flowpin_event_id DESC, id ASC
    ");

    // Step 5: Group local rows by EventId
    $groupedLocalRows = [];
    foreach ($localRows as $row) {
        $eventId = $row['flowpin_event_id'];
        if (!isset($groupedLocalRows[$eventId])) {
            $groupedLocalRows[$eventId] = [];
        }
        $groupedLocalRows[$eventId][] = [
            'id' => $row['id'],
            'device_id' => $row['device_id'],
            'device_name' => $row['device_name'],
            'device_type' => $row['device_type'],
            'device_type_name' => $row['device_type_name'],
            'magazine_id' => $row['sub_magazine_id'],
            'magazine_name' => $row['magazine_name'],
            'qty' => (float)$row['qty'],
            'timestamp' => $row['timestamp'],
            'comment' => $row['comment'],
            'input_type_id' => $row['input_type_id'],
            'input_type_name' => $row['input_type_name'],
            'is_cancelled' => (bool)$row['is_cancelled'],
            'cancelled_at' => $row['cancelled_at'],
            'cancelled_by_name' => $row['cancelled_by_firstname'] && $row['cancelled_by_lastname']
                ? $row['cancelled_by_firstname'] . ' ' . $row['cancelled_by_lastname']
                : null,
            'flowpin_event_id' => $row['flowpin_event_id'],
            'transfer_group_id' => $row['transfer_group_id']
        ];
    }

    // Step 6: Build final events array with filtering
    $events = [];
    $filteredOutCount = 0;
    
    foreach ($eventIds as $eventId) {
        $localRowsForEvent = $groupedLocalRows[$eventId] ?? [];
        $flowpinData = $flowpinRawData[$eventId] ?? null;
        
        // Get primary local row for filtering
        $primaryRow = $localRowsForEvent[0] ?? null;
        
        // Apply filters
        $passesFilter = true;
        
        // Filter by operation type (input_type_id)
        if (!empty($filterOperationType) && $primaryRow) {
            if ((string)$primaryRow['input_type_id'] !== (string)$filterOperationType) {
                $passesFilter = false;
            }
        }
        
        // Filter by date range (ExecutionDate from FlowPin)
        if ($passesFilter && $flowpinData) {
            if (!empty($filterDateFrom)) {
                $eventDate = strtotime($flowpinData['ExecutionDate']);
                $fromDate = strtotime($filterDateFrom);
                if ($eventDate < $fromDate) {
                    $passesFilter = false;
                }
            }
            if ($passesFilter && !empty($filterDateTo)) {
                $eventDate = strtotime($flowpinData['ExecutionDate']);
                $toDate = strtotime($filterDateTo);
                // Add one day to include the entire end date
                $toDate = strtotime('+1 day', $toDate);
                if ($eventDate >= $toDate) {
                    $passesFilter = false;
                }
            }
        }
        
        // Filter by users (ByUserEmail from FlowPin) - now supports multiple
        if ($passesFilter && !empty($selectedUsers) && $flowpinData) {
            if (!in_array($flowpinData['ByUserEmail'], $selectedUsers)) {
                $passesFilter = false;
            }
        }
        
        // Filter by search (device name or Event ID)
        if ($passesFilter && !empty($filterSearch)) {
            $searchLower = strtolower($filterSearch);
            $found = false;
            
            // Search in device name
            if ($flowpinData && $flowpinData['device_name']) {
                if (stripos($flowpinData['device_name'], $searchLower) !== false) {
                    $found = true;
                }
            }
            
            // Search in Event ID
            if (stripos((string)$eventId, $searchLower) !== false) {
                $found = true;
            }
            
            if (!$found) {
                $passesFilter = false;
            }
        }
        
        // Filter by devices
        if ($passesFilter && !empty($selectedDevices) && $flowpinData) {
            $deviceName = $flowpinData['device_name'] ?? 'Unknown';
            if (!in_array($deviceName, $selectedDevices)) {
                $passesFilter = false;
            }
        }
        
        if ($passesFilter) {
            $events[] = [
                'flowpin_event_id' => $eventId,
                'flowpin_raw' => $flowpinData,
                'local_rows' => $localRowsForEvent
            ];
        } else {
            $filteredOutCount++;
        }
    }

    echo json_encode([
        'success' => true,
        'session' => $session,
        'events' => $events,
        'pagination' => [
            'page' => $page,
            'limit' => $limit,
            'total_events' => $totalEvents,
            'total_pages' => $totalPages,
            'has_next' => $page < $totalPages,
            'has_prev' => $page > 1,
            'filtered_count' => count($events),
            'filtered_out' => $filteredOutCount
        ]
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}
