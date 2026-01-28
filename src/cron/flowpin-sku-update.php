<?php

use Atte\DB\MsaDB;
use Atte\DB\FlowpinDB;
use Atte\Utils\NotificationRepository;
use Atte\Utils\UserRepository;
use Atte\Utils\Locker;
use Atte\Utils\Production\SkuProductionProcessor;
use Atte\Utils\TransferGroupManager;
use Atte\Utils\BomRepository;
use Atte\Api\GoogleSheets;

set_time_limit(0);

$currentDate = date('Y-m-d');
$currentHour = date('H');
$logDir = __DIR__ . '/logs/' . $currentDate;
if (!file_exists($logDir)) {
    mkdir($logDir, 0755, true);
}

$logFile = $logDir . '/flowpin-sku-update-' . $currentDate . '-' . $currentHour . 'h.log';
$errorLogFile = $logDir . '/flowpin-sku-update-errors-' . $currentDate . '-' . $currentHour . 'h.log';
$inventoryChangesFile = $logDir . '/flowpin-inventory-changes-' . $currentDate . '-' . $currentHour . 'h.log';

// Track inventory changes by SKU ID for summing
$inventoryChanges = [];

function writeLog($message, $level = 'INFO') {
    global $logFile, $errorLogFile;
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[{$timestamp}] [{$level}] {$message}" . PHP_EOL;

    // Write to main log file
    file_put_contents($logFile, $logMessage, FILE_APPEND | LOCK_EX);

    // Write errors to separate error log file
    if (in_array($level, ['ERROR', 'CRITICAL', 'WARNING'])) {
        file_put_contents($errorLogFile, $logMessage, FILE_APPEND | LOCK_EX);
    }

    // Also output to console if running from CLI
    if (php_sapi_name() === 'cli') {
        echo $logMessage;
    }
}

function trackInventoryChange($skuId, $quantity, $operation) {
    global $inventoryChanges;

    if (!isset($inventoryChanges[$skuId])) {
        $inventoryChanges[$skuId] = [
            'total_change' => 0,
            'operations' => []
        ];
    }

    $inventoryChanges[$skuId]['total_change'] += $quantity;

    if (!isset($inventoryChanges[$skuId]['operations'][$operation])) {
        $inventoryChanges[$skuId]['operations'][$operation] = 0;
    }
    $inventoryChanges[$skuId]['operations'][$operation] += $quantity;
}

function writeInventoryChanges() {
    global $inventoryChangesFile, $inventoryChanges, $MsaDB;

    if (empty($inventoryChanges)) {
        writeLog("No inventory changes to write");
        return;
    }

    $timestamp = date('Y-m-d H:i:s');
    $content = "=== INVENTORY CHANGES SUMMARY - {$timestamp} ===" . PHP_EOL;

    $list__sku = $MsaDB->readIdName(table: 'list__sku');

    ksort($inventoryChanges);

    $sheetsData = [];
    $sheetsData[] = ['INVENTORY CHANGES SUMMARY - ' . $timestamp, '', '', ''];
    foreach ($inventoryChanges as $skuId => $data) {
        $skuName = $list__sku[$skuId] ?? 'Unknown SKU';
        $content .= "SKU {$skuId} ({$skuName}): TOTAL {$data['total_change']}" . PHP_EOL;

        // Show breakdown by operation type
        foreach ($data['operations'] as $operation => $qty) {
            $content .= "  {$operation}: {$qty}" . PHP_EOL;
            // Add to sheets data
            $sheetsData[] = [$skuId, $skuName, $operation, $qty];
        }
        $content .= PHP_EOL;
    }

    $content .= "=== END INVENTORY CHANGES ===" . PHP_EOL . PHP_EOL;

    // Write to local log file
    file_put_contents($inventoryChangesFile, $content, FILE_APPEND | LOCK_EX);
    writeLog("Inventory changes summary written with " . count($inventoryChanges) . " SKUs affected");
}

/**
 * Ensures SKU exists in MSA database, creates it from FlowPin if missing
 * @param int $deviceId SKU ID to check/create
 * @param MsaDB $MsaDB MSA database instance
 * @param FlowpinDB $FlowpinDB FlowPin database instance
 * @return bool True if SKU exists or was created successfully, false on error
 */
function ensureSkuExists($deviceId, $MsaDB, $FlowpinDB) {
    $MsaId = $MsaDB->query("SELECT id FROM list__sku WHERE id = " . (int)$deviceId, PDO::FETCH_COLUMN);
    if (!empty($MsaId)) {
        return true;
    }

    try {
        $newSKU = $FlowpinDB->query("SELECT Symbol, Description FROM ProductTypes WHERE Id = " . (int)$deviceId);
        if (empty($newSKU)) {
            writeLog("SKU with ID $deviceId not found in FlowPin database", 'ERROR');
            return false;
        }

        $newSKU = $newSKU[0];
        $MsaDB->insert("list__sku", ["id", "name", "description", "isActive"], [$deviceId, $newSKU["Symbol"], $newSKU["Description"], 1]);
        writeLog("Created missing SKU: ID=$deviceId, Name={$newSKU["Symbol"]}", 'INFO');
        return true;

    } catch (\Throwable $exception) {
        writeLog("Error creating SKU $deviceId: " . $exception->getMessage(), 'ERROR');
        return false;
    }
}

/**
 * Get or create transfer group for FlowPin operations
 * Groups operations by User + Device + Day
 * @param int $userId User ID
 * @param int $deviceId Device/SKU ID
 * @param string $date Date string (Y-m-d format)
 * @param string $operationType Operation type (e.g., 'Production', 'Sold', 'Returned', 'Moved')
 * @param array &$transferGroupCache Cache array to store created transfer groups
 * @param TransferGroupManager $transferGroupManager Transfer group manager instance
 * @param MsaDB $MsaDB MSA database instance
 * @return int Transfer group ID
 */
function getOrCreateTransferGroup($userId, $deviceId, $date, $operationType, &$transferGroupCache, $transferGroupManager, $MsaDB, $sessionRecordId, &$sessionGroupCount) {
    $cacheKey = "{$userId}_{$deviceId}_{$date}_{$operationType}";

    if (isset($transferGroupCache[$cacheKey])) {
        return $transferGroupCache[$cacheKey];
    }

    // Get device name for comment
    $deviceName = $MsaDB->query("SELECT name FROM list__sku WHERE id = " . (int)$deviceId, PDO::FETCH_COLUMN);
    $deviceName = $deviceName[0] ?: "SKU_ID_{$deviceId}";

    // Create transfer group
    $transferGroupId = $transferGroupManager->createTransferGroup($userId, 'flowpin_sync', [
        'operation' => $operationType,
        'date' => $date,
        'device_name' => $deviceName
    ]);


    // Link transfer group to FlowPin update session
    $MsaDB->update("inventory__transfer_groups",
                   ["flowpin_update_session_id" => $sessionRecordId],
                   "id", $transferGroupId);

    // Cache for reuse
    $transferGroupCache[$cacheKey] = $transferGroupId;

    // Increment session group counter
    $sessionGroupCount++;

    writeLog("Created transfer group {$transferGroupId} for {$operationType} - User: {$userId}, Device: {$deviceId}, Date: {$date}, SessionId: {$sessionRecordId}");

    return $transferGroupId;
}

/**
 * Get active BOM ID for a SKU
 * @param int $skuId SKU ID
 * @param BomRepository $bomRepository BOM repository instance
 * @return int|null BOM ID or null if not found
 */
function getSkuBomId($skuId, $bomRepository) {
    try {
        $bomValues = [
            "sku_id" => $skuId,
            "version" => null
        ];
        $bomsFound = $bomRepository->getBomByValues("sku", $bomValues);
        if (count($bomsFound) === 1) {
            return $bomsFound[0]->id;
        }
    } catch (\Throwable $e) {
        writeLog("Could not find BOM for SKU {$skuId}: " . $e->getMessage(), 'WARNING');
    }
    return null;
}

writeLog("=== Starting FlowPin SKU Update Process ===");

$MsaDB = MsaDB::getInstance();
$FlowpinDB = FlowpinDB::getInstance();
$notificationRepository = new NotificationRepository($MsaDB);
$userRepository = new UserRepository($MsaDB);
$transferGroupManager = new TransferGroupManager($MsaDB);
$bomRepository = new BomRepository($MsaDB);

// Cache for transfer groups to avoid creating duplicates
$transferGroupCache = [];

// Get checkpoints for each operation type (to handle crash recovery)
function getCheckpoint($MsaDB, $operationType) {
    $result = $MsaDB->query("SELECT checkpoint_event_id FROM `ref__flowpin_checkpoints` WHERE operation_type = '$operationType'");
    return isset($result[0]) ? $result[0]["checkpoint_event_id"] : 0;
}

function updateCheckpoint($MsaDB, $operationType, $eventId) {
    $MsaDB->update("ref__flowpin_checkpoints", ["checkpoint_event_id" => $eventId], "operation_type", $operationType);
    writeLog("Updated checkpoint for {$operationType} to EventId: {$eventId}");
}

$initialEventID = $MsaDB->query("SELECT params FROM `ref__timestamp` WHERE id = 4")[0]["params"];
writeLog("Starting from EventID: {$initialEventID}");

$updateEventId = function ($newEventId) use (&$MsaDB) {
    $now = date("Y/m/d H:i:s", time());
    $MsaDB->update("ref__timestamp", ["params" => $newEventId, "last_timestamp" => $now], "id", 4);
    writeLog("Updated main EventID to: {$newEventId}");
};

// Get data functions that respect individual operation checkpoints
$getSoldSku = function () use ($FlowpinDB, $MsaDB) {
    $checkpoint = getCheckpoint($MsaDB, 'sold_sku');
    $query = "SELECT EventId, ExecutionDate, ByUserEmail, ProductTypeId,
        CASE WHEN EventTypeValue = 'Modified' 
        AND FieldOldValue = 'InOrder' 
        AND FieldNewValue = 'ContractorHasIt' 
        THEN -1 
        WHEN EventTypeValue = 'Modified' 
        AND FieldOldValue = 'ContractorHasIt' 
        AND FieldNewValue = 'InOrder' 
        THEN 1 END AS SaleQty 
        FROM [report].[ProductQuantityHistoryView] 
        WHERE EventTypeValue = 'Modified' AND (EventId > '$checkpoint') AND IsInter = 0
        AND ((FieldOldValue = 'ContractorHasIt' AND FieldNewValue = 'InOrder') 
        OR (FieldOldValue = 'InOrder' AND FieldNewValue = 'ContractorHasIt')) ORDER BY EventId ASC";
    return $FlowpinDB->query($query);
};

$getReturnedSku = function () use ($FlowpinDB, $MsaDB) {
    $checkpoint = getCheckpoint($MsaDB, 'returned_sku');
    $query = "SELECT EventId, ExecutionDate, ByUserEmail, ProductTypeId,
        CASE WHEN ProductTypeId IS NOT NULL
        AND FieldOldValue = 4
        THEN -1
        WHEN ProductTypeId IS NOT NULL
        AND FieldNewValue = 4
        THEN 1 END AS ReturnQty 
        FROM [report].[ProductQuantityHistoryView] 
        WHERE (EventId > '$checkpoint')
        AND EventTypeValue = 'ProductReturn' 
        AND FieldName = 'WarehouseId' AND IsInter = 0 AND (FieldNewValue = 4 OR FieldOldValue = 4) 
        AND FieldOldValue != FieldNewValue 
        AND FieldNewValue != 81
        ORDER BY EventId ASC";
    return $FlowpinDB->query($query);
};

$getMovedSku = function () use ($FlowpinDB, $MsaDB) {
    $checkpoint = getCheckpoint($MsaDB, 'moved_sku');
    $query = "SELECT [EventId], [ExecutionDate] ,[ByUserEmail] ,[ProductTypeId] ,[FieldOldValue] AS WarehouseOut , 
        CASE WHEN EventTypeValue = 'WarehouseChange' 
        AND FieldName = 'WarehouseId' 
        AND State = 1 
        AND (FieldOldValue = 3 OR FieldOldValue = 4) 
        THEN -1 END AS QtyOut ,[FieldNewValue] AS WarehouseIn , 
        CASE WHEN EventTypeValue = 'WarehouseChange' 
        AND FieldName = 'WarehouseId' 
        AND State = 1 
        AND (FieldNewValue = 3 OR FieldNewValue = 4)
        THEN 1 END AS QtyIn 
        FROM [report].[ProductQuantityHistoryView] 
        WHERE (EventId > '$checkpoint') 
        AND EventTypeValue = 'WarehouseChange' 
        AND FieldName = 'WarehouseId' 
        AND State = 1 
        AND ((FieldNewValue = 3 OR FieldNewValue = 4) OR (FieldOldValue = 3 OR FieldOldValue = 4))
        AND ParentId IS NULL ORDER BY EventId ASC";
    return $FlowpinDB->query($query);
};

$getProducedSkuAndInter = function () use ($FlowpinDB, $MsaDB) {
    $checkpoint = getCheckpoint($MsaDB, 'production_sku');
    $query = "SELECT EventId, ExecutionDate, ByUserEmail, ProductTypeId, ProductionQty
        FROM [report].[ProductQuantityHistoryView] 
        WHERE (EventId > '$checkpoint') 
        AND (ProductionQty = 1 OR ProductionQty = -1) 
        AND (WarehouseId = '3' OR WarehouseId = '4')
        ORDER BY EventId ASC";
    return $FlowpinDB->query($query);
};

// Register shutdown handler to catch fatal errors and mark session as error
$sessionId = null; // Will be set after session creation
$scriptCompletedNormally = false; // Will be set to true at the end of try block
register_shutdown_function(function() use (&$sessionId, &$MsaDB, &$locker, &$sessionStartingEventId, &$overallHighestEventId, &$initialEventID, &$sessionRecordId, &$scriptCompletedNormally) {
    $error = error_get_last();
    
    // Check if shutdown was due to a fatal error OR if script didn't complete normally
    $isFatalError = ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR]));
    
    // Also check if session is still "running" (meaning script died unexpectedly)
    $isUnexpectedExit = false;
    if (!$scriptCompletedNormally && $sessionId !== null && $MsaDB !== null) {
        try {
            $sessionStatus = $MsaDB->query(
                "SELECT status FROM ref__flowpin_update_progress WHERE session_id = " . $MsaDB->db->quote($sessionId),
                PDO::FETCH_COLUMN
            );
            if (!empty($sessionStatus) && $sessionStatus[0] === 'running') {
                $isUnexpectedExit = true;
            }
        } catch (\Exception $e) {
            // Ignore errors checking session status
        }
    }
    
    if ($isFatalError || $isUnexpectedExit) {
        if ($isFatalError) {
            writeLog("FATAL ERROR DETECTED: " . $error['message'] . " in " . $error['file'] . " on line " . $error['line'], 'CRITICAL');
        } else {
            writeLog("UNEXPECTED EXIT DETECTED: Script terminated without completing normally", 'CRITICAL');
        }
        
        // Mark session as error if it exists
        if ($sessionId !== null && $MsaDB !== null && isset($sessionRecordId)) {
            try {
                $errorUpdate = [
                    "status" => "error",
                    "updated_at" => date('Y-m-d H:i:s')
                ];
                
                // Add starting_event_id if we captured it
                if (isset($sessionStartingEventId) && $sessionStartingEventId !== null) {
                    $errorUpdate["starting_event_id"] = $sessionStartingEventId;
                }
                
                // Add finishing_event_id if we processed any records
                if (isset($overallHighestEventId) && isset($initialEventID) && $overallHighestEventId > $initialEventID) {
                    $errorUpdate["finishing_event_id"] = $overallHighestEventId;
                }
                
                // Count transfers created in this session across all inventory types
                $transferCount = $MsaDB->query("
                    SELECT 
                        (SELECT COUNT(*) FROM inventory__sku WHERE flowpin_update_session_id = $sessionRecordId) +
                        (SELECT COUNT(*) FROM inventory__tht WHERE flowpin_update_session_id = $sessionRecordId) +
                        (SELECT COUNT(*) FROM inventory__smd WHERE flowpin_update_session_id = $sessionRecordId) +
                        (SELECT COUNT(*) FROM inventory__parts WHERE flowpin_update_session_id = $sessionRecordId) as total
                ", PDO::FETCH_COLUMN);
                
                // Count groups created in this session
                $groupCount = $MsaDB->query("
                    SELECT COUNT(DISTINCT id) FROM inventory__transfer_groups 
                        WHERE flowpin_update_session_id = $sessionRecordId
                ", PDO::FETCH_COLUMN);
                
                $errorUpdate["created_transfer_count"] = $transferCount[0] ?? 0;
                $errorUpdate["created_group_count"] = $groupCount[0] ?? 0;
                
                $MsaDB->update("ref__flowpin_update_progress", $errorUpdate, "session_id", $sessionId);
                writeLog("Session marked as error due to " . ($isFatalError ? "fatal error" : "unexpected exit"), 'CRITICAL');
                writeLog("Transfers created: " . ($transferCount[0] ?? 0) . ", Groups created: " . ($groupCount[0] ?? 0), 'CRITICAL');
            } catch (\Exception $e) {
                writeLog("Failed to update session status on error: " . $e->getMessage(), 'CRITICAL');
            }
        }
        
        // Release lock if held
        if (isset($locker)) {
            try {
                $locker->unlock();
                writeLog("Lock released after error", 'CRITICAL');
            } catch (\Exception $e) {
                // Ignore lock release errors
            }
        }
    }
});

$locker = new Locker('flowpin.lock');
$is_locked = $locker->lock(FALSE);

if (!$is_locked) {
    writeLog("Could not acquire lock. Another process may be running.", 'WARNING');
    exit(1);
}

writeLog("Lock acquired successfully");

// Clean up any stale sessions from previous crashed runs
// Since we have the lock, any session marked as 'running' in the DB is stale/crashed
$runningSessions = $MsaDB->query("SELECT id, session_id FROM ref__flowpin_update_progress WHERE status = 'running'");
if (!empty($runningSessions)) {
    foreach ($runningSessions as $stale) {
        $staleId = $stale['id'];
        
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
        
        writeLog("Marked stale session {$stale['session_id']} (ID: $staleId) as error. Transfers: " . ($transferCount[0] ?? 0), 'WARNING');
    }
}

// Create unique session ID for progress tracking
$sessionId = 'flowpin_' . date('Ymd_His') . '_' . uniqid();

// Initialize progress tracking (will be updated with total after data fetch)
$MsaDB->insert("ref__flowpin_update_progress",
    ["session_id", "total_records", "processed_records", "status", "started_at", "current_operation_type"],
    [$sessionId, 0, 0, "running", date('Y-m-d H:i:s'), "Pobieranie danych z FlowPin..."]);
writeLog("Created progress session: {$sessionId}");

// Capture the auto-increment ID for use as foreign key in transfers
$sessionRecordId = (int)$MsaDB->db->lastInsertId();
writeLog("Session record ID: {$sessionRecordId}");

// Helper function to update progress
$updateProgress = function($processed, $total, $currentOp = null, $currentEventId = null) use ($MsaDB, $sessionId) {
    $updates = [
        "processed_records" => $processed,
        "total_records" => $total,
        "updated_at" => date('Y-m-d H:i:s')
    ];
    if ($currentOp !== null) {
        $updates["current_operation_type"] = $currentOp;
    }
    if ($currentEventId !== null) {
        $updates["current_event_id"] = $currentEventId;
    }
    $MsaDB->update("ref__flowpin_update_progress", $updates, "session_id", $sessionId);
};

/**
 * Validates record data before processing to prevent empty transfer groups
 * @return array [success => bool, user => User|null, bomId => int|null, error => Exception|null]
 */
$validateRecord = function($userEmail, $deviceId, $userRepository, $bomRepository, $MsaDB, $FlowpinDB) {
    try {
        // 1. Validate SKU
        if (!ensureSkuExists($deviceId, $MsaDB, $FlowpinDB)) {
            throw new \Exception("SKU with ID $deviceId not found in FlowPin database", 1);
        }

        // 2. Validate User
        $user = $userRepository->getUserByEmail($userEmail);
        if (!$user || !$user->userId) {
            throw new \Exception("User with email $userEmail not found or invalid", 2);
        }
        
        $userinfo = $user->getUserInfo();
        if (empty($userinfo)) {
            throw new Exception("User info not found for user email: $userEmail", 2);
        }

        // Check if user has no magazine assigned
        if (is_null($userinfo["sub_magazine_id"])) {
            throw new Exception("User has no magazine assigned for user email: $userEmail", 3);
        }

        // Check if user account is disabled
        if ($userinfo["user_isActive"] == 0) {
            throw new Exception("User account is disabled for user email: $userEmail", 4);
        }

        // Check if user's magazine is disabled
        if ($userinfo["magazine_isActive"] == 0) {
            throw new Exception("User's magazine is disabled for user email: $userEmail", 5);
        }

        // 3. Validate BOM
        $bomId = getSkuBomId($deviceId, $bomRepository);
        if (!$bomId) {
            throw new \Exception("Active BOM not found for SKU $deviceId", 1);
        }

        return ['success' => true, 'user' => $user, 'userId' => $user->userId, 'bomId' => $bomId, 'userInfo' => $userinfo];
    } catch (\Throwable $e) {
        return ['success' => false, 'error' => $e];
    }
};

try {
    $processedCount = 0;
    $errorCount = 0;
    $commitBatchSize = 100; // Commit every 100 records
    $overallHighestEventId = $initialEventID;

    // Session tracking metrics
    $sessionStartingEventId = null;
    $sessionFinishingEventId = null;
    $sessionTransferCount = 0;
    $sessionGroupCount = 0;

    // Fetch all data first to get total count
    writeLog("=== Fetching data from FlowPin ===");
    $soldSku = $getSoldSku();
    $returnedSku = $getReturnedSku();
    $movedSku = $getMovedSku();
    $producedSkuAndInter = $getProducedSkuAndInter();

    $totalRecords = count($soldSku) + count($returnedSku) + count($movedSku) + count($producedSkuAndInter);
    writeLog("Total records to process: {$totalRecords}");

    // Capture starting EventId for session tracking
    if ($totalRecords > 0) {
        $allEventIds = [];
        foreach ($soldSku as $row) $allEventIds[] = $row[0];
        foreach ($returnedSku as $row) $allEventIds[] = $row[0];
        foreach ($movedSku as $row) $allEventIds[] = $row[0];
        foreach ($producedSkuAndInter as $row) $allEventIds[] = $row[0];

        if (!empty($allEventIds)) {
            $sessionStartingEventId = min($allEventIds);
            writeLog("Session starting EventId: {$sessionStartingEventId}");
        }

        // Store starting EventId in database immediately
        if ($sessionStartingEventId !== null) {
            $MsaDB->update("ref__flowpin_update_progress", 
                ["starting_event_id" => $sessionStartingEventId],
                "session_id", $sessionId);
        }
    }

    // Update progress with total count
    $updateProgress(0, $totalRecords);

    // Send session_id to frontend now that we have the total count
    echo json_encode(['session_id' => $sessionId, 'total_records' => $totalRecords]) . "\n";
    if (ob_get_level() > 0) {
        ob_flush();
    }
    flush();

    // Handle zero records case
    if ($totalRecords === 0) {
        writeLog("No new records to process from FlowPin");
        $updateProgress(0, 0, 'no_new_records', $initialEventID);
        $MsaDB->update("ref__flowpin_update_progress",
            ["status" => "completed", "processed_records" => 0, "updated_at" => date('Y-m-d H:i:s')],
            "session_id", $sessionId);
        writeLog("Progress tracking completed for session: {$sessionId} (no new records)");
        $locker->unlock();
        writeLog("Lock released");
        writeLog("=== FlowPin SKU Update Process Completed (No New Records) ===");
        exit(0);
    }

    // Process Sold SKUs
    writeLog("=== Processing Sold SKUs ===");
    writeLog("Found " . count($soldSku) . " sold SKU records to process");

    $highestProcessedEventId = getCheckpoint($MsaDB, 'sold_sku');

    foreach ($soldSku as $row) {
        $eventId = $row[0];
        $MsaDB->db->beginTransaction();
        try {
            list($eventId, $executionDate, $userEmail, $deviceId, $qty) = $row;
            $flowpinQueryTypeId = 2;

            writeLog("Processing Sold SKU - EventId: {$eventId}, DeviceId: {$deviceId}, UserEmail: {$userEmail}, Qty: {$qty}");

            // 1. Validate Record
            $validation = $validateRecord($userEmail, $deviceId, $userRepository, $bomRepository, $MsaDB, $FlowpinDB);
            if (!$validation['success']) {
                throw $validation['error'];
            }

            $user = $validation['user'];
            $userId = $validation['userId'];
            $bomId = $validation['bomId'];

            // 2. All good, now create group and insert
            $productionDate = date('Y-m-d', strtotime($executionDate));
            $transferGroupId = getOrCreateTransferGroup($userId, $deviceId, $productionDate, 'Sold', $transferGroupCache, $transferGroupManager, $MsaDB, $sessionRecordId, $sessionGroupCount);

            $comment = "Finalizacja zamówienia, spakowano do wysyłki, EventId: " . $eventId;
            $columns = ["sku_id", "sub_magazine_id", "qty", "timestamp", "production_date", "input_type_id", "comment", "transfer_group_id", "sku_bom_id", "flowpin_update_session_id", "flowpin_event_id"];
            $values = [$deviceId, "0", $qty, $executionDate, $productionDate, "9", $comment, $transferGroupId, $bomId, $sessionRecordId, $eventId];
            $MsaDB->insert("inventory__sku", $columns, $values);
            $sessionTransferCount++;

            // Track inventory change for summary
            trackInventoryChange($deviceId, $qty, "SOLD");
            
            $MsaDB->db->commit();
            writeLog("Successfully processed Sold SKU - EventId: {$eventId}");

        } catch (\Throwable $exception) {
            if ($MsaDB->db->inTransaction()) {
                $MsaDB->db->rollBack();
            }
            $errorCount++;
            $errorMessage = "Error processing Sold SKU - EventId: {$eventId}, Error: " . $exception->getMessage();
            writeLog($errorMessage, 'ERROR');
            writeLog("Row data: " . json_encode($row), 'ERROR');

            $notification = $notificationRepository->createNotificationFromException($exception, $row, 2); // 2 = Sold SKU
            writeLog("Created/Updated notification for Sold SKU EventId: {$eventId}", 'ERROR');
        }

        $processedCount++;
        $highestProcessedEventId = max($highestProcessedEventId, $eventId);
        
        // Update checkpoint and progress after each record (atomic)
        updateCheckpoint($MsaDB, 'sold_sku', $highestProcessedEventId);
        $updateProgress($processedCount, $totalRecords, 'sold_sku', $highestProcessedEventId);
        
        // Update finishing_event_id in database
        $MsaDB->update("ref__flowpin_update_progress",
            ["finishing_event_id" => $highestProcessedEventId],
            "session_id", $sessionId);
    }

    $overallHighestEventId = max($overallHighestEventId, $highestProcessedEventId);

    // Process Returned SKUs
    writeLog("=== Processing Returned SKUs ===");
    writeLog("Found " . count($returnedSku) . " returned SKU records to process");

    $highestProcessedEventId = getCheckpoint($MsaDB, 'returned_sku');

    foreach ($returnedSku as $row) {
        $eventId = $row[0];
        $MsaDB->db->beginTransaction();
        try {
            list($eventId, $executionDate, $userEmail, $deviceId, $qty) = $row;
            $flowpinQueryTypeId = 3;

            writeLog("Processing Returned SKU - EventId: {$eventId}, DeviceId: {$deviceId}, UserEmail: {$userEmail}, Qty: {$qty}");

            // 1. Validate Record
            $validation = $validateRecord($userEmail, $deviceId, $userRepository, $bomRepository, $MsaDB, $FlowpinDB);
            if (!$validation['success']) {
                throw $validation['error'];
            }

            $user = $validation['user'];
            $userId = $validation['userId'];
            $bomId = $validation['bomId'];

            // 2. All good, now create group and insert
            $productionDate = date('Y-m-d', strtotime($executionDate));
            $transferGroupId = getOrCreateTransferGroup($userId, $deviceId, $productionDate, 'Returned', $transferGroupCache, $transferGroupManager, $MsaDB, $sessionRecordId, $sessionGroupCount);

            $comment = "Zwrot SKU od klienta, EventId: " . $eventId;
            $columns = ["sku_id", "sub_magazine_id", "qty", "timestamp", "production_date", "input_type_id", "comment", "transfer_group_id", "sku_bom_id", "flowpin_update_session_id", "flowpin_event_id"];
            $values = [$deviceId, "0", $qty, $executionDate, $productionDate, "10", $comment, $transferGroupId, $bomId, $sessionRecordId, $eventId];
            $MsaDB->insert("inventory__sku", $columns, $values);
            $sessionTransferCount++;

            // Track inventory change for summary
            trackInventoryChange($deviceId, $qty, "RETURNED");
            
            $MsaDB->db->commit();
            writeLog("Successfully processed Returned SKU - EventId: {$eventId}");

        } catch (\Throwable $exception) {
            if ($MsaDB->db->inTransaction()) {
                $MsaDB->db->rollBack();
            }
            $errorCount++;
            $errorMessage = "Error processing Returned SKU - EventId: {$eventId}, Error: " . $exception->getMessage();
            writeLog($errorMessage, 'ERROR');
            writeLog("Row data: " . json_encode($row), 'ERROR');

            $notification = $notificationRepository->createNotificationFromException($exception, $row, 3); // 3 = Returned SKU
            writeLog("Created/Updated notification for Returned SKU EventId: {$eventId}", 'ERROR');
        }

        $processedCount++;
        $highestProcessedEventId = max($highestProcessedEventId, $eventId);
        
        // Update checkpoint and progress after each record (atomic)
        updateCheckpoint($MsaDB, 'returned_sku', $highestProcessedEventId);
        $updateProgress($processedCount, $totalRecords, 'returned_sku', $highestProcessedEventId);
        
        // Update finishing_event_id in database
        $MsaDB->update("ref__flowpin_update_progress",
            ["finishing_event_id" => $highestProcessedEventId],
            "session_id", $sessionId);
    }

    $overallHighestEventId = max($overallHighestEventId, $highestProcessedEventId);

    // Process Moved SKUs
    writeLog("=== Processing Moved SKUs ===");
    writeLog("Found " . count($movedSku) . " moved SKU records to process");

    $highestProcessedEventId = getCheckpoint($MsaDB, 'moved_sku');

    foreach ($movedSku as $row) {
        $eventId = $row[0];
        $MsaDB->db->beginTransaction();
        try {
            list($eventId, $executionDate, $userEmail, $deviceId, $warehouseOut, $qtyOut, $warehouseIn, $qtyIn) = $row;
            $flowpinQueryTypeId = 4;

            writeLog("Processing Moved SKU - EventId: {$eventId}, DeviceId: {$deviceId}, UserEmail: {$userEmail}, " .
                "WarehouseOut: {$warehouseOut}, QtyOut: {$qtyOut}, WarehouseIn: {$warehouseIn}, QtyIn: {$qtyIn}");

            // 1. Validate Record
            $validation = $validateRecord($userEmail, $deviceId, $userRepository, $bomRepository, $MsaDB, $FlowpinDB);
            if (!$validation['success']) {
                throw $validation['error'];
            }

            $user = $validation['user'];
            $userId = $validation['userId'];
            $bomId = $validation['bomId'];

            // 2. All good, now create group and insert
            $productionDate = date('Y-m-d', strtotime($executionDate));
            $transferGroupId = getOrCreateTransferGroup($userId, $deviceId, $productionDate, 'Moved', $transferGroupCache, $transferGroupManager, $MsaDB, $sessionRecordId, $sessionGroupCount);

            $comment = "Przesunięcie między magazynowe, EventId: " . $eventId;

            if ($warehouseOut == 3 || $warehouseOut == 4) {
                $columns = ["sku_id", "sub_magazine_id", "qty", "timestamp", "production_date", "input_type_id", "comment", "transfer_group_id", "sku_bom_id", "flowpin_update_session_id", "flowpin_event_id"];
                $values = [$deviceId, "0", $qtyOut, $executionDate, $productionDate, "2", $comment, $transferGroupId, $bomId, $sessionRecordId, $eventId];
                $MsaDB->insert("inventory__sku", $columns, $values);
                $sessionTransferCount++;
                writeLog("Inserted warehouse OUT record - EventId: {$eventId}");

                // Track inventory change for summary
                trackInventoryChange($deviceId, $qtyOut, "MOVED_OUT");
            }

            if ($warehouseIn == 3 || $warehouseIn == 4) {
                $columns = ["sku_id", "sub_magazine_id", "qty", "timestamp", "production_date", "input_type_id", "comment", "transfer_group_id", "sku_bom_id", "flowpin_update_session_id", "flowpin_event_id"];
                $values = [$deviceId, "0", $qtyIn, $executionDate, $productionDate, "2", $comment, $transferGroupId, $bomId, $sessionRecordId, $eventId];
                $MsaDB->insert("inventory__sku", $columns, $values);
                $sessionTransferCount++;
                writeLog("Inserted warehouse IN record - EventId: {$eventId}");

                // Track inventory change for summary
                trackInventoryChange($deviceId, $qtyIn, "MOVED_IN");
            }

            $MsaDB->db->commit();
            writeLog("Successfully processed Moved SKU - EventId: {$eventId}");

        } catch (\Throwable $exception) {
            if ($MsaDB->db->inTransaction()) {
                $MsaDB->db->rollBack();
            }
            $errorCount++;
            $errorMessage = "Error processing Moved SKU - EventId: {$eventId}, Error: " . $exception->getMessage();
            writeLog($errorMessage, 'ERROR');
            writeLog("Row data: " . json_encode($row), 'ERROR');

            $notification = $notificationRepository->createNotificationFromException($exception, $row, 4); // 4 = Moved SKU
            writeLog("Created/Updated notification for Moved SKU EventId: {$eventId}", 'ERROR');
        }

        $processedCount++;
        $highestProcessedEventId = max($highestProcessedEventId, $eventId);
        
        // Update checkpoint and progress after each record (atomic)
        updateCheckpoint($MsaDB, 'moved_sku', $highestProcessedEventId);
        $updateProgress($processedCount, $totalRecords, 'moved_sku', $highestProcessedEventId);
        
        // Update finishing_event_id in database
        $MsaDB->update("ref__flowpin_update_progress",
            ["finishing_event_id" => $highestProcessedEventId],
            "session_id", $sessionId);
    }

    $overallHighestEventId = max($overallHighestEventId, $highestProcessedEventId);

    // Process Production SKUs
    writeLog("=== Processing Production SKUs ===");
    writeLog("Found " . count($producedSkuAndInter) . " production records to process");

    $highestProcessedEventId = getCheckpoint($MsaDB, 'production_sku');
    $productionProcessedCount = 0;
    $productionErrorCount = 0;
    $productionDatabaseErrors = 0;
    $productionProcessingErrors = 0;
    $productionNoDataErrors = 0;
    $productionBomErrors = 0;
    $productionUserErrors = 0;

    if (count($producedSkuAndInter) > 0) {
        $productionProcessor = new SkuProductionProcessor($MsaDB, $FlowpinDB);

        // Process records individually to handle transfer groups properly
        foreach ($producedSkuAndInter as $i => $row) {
            $eventId = $row[0];
            $MsaDB->db->beginTransaction();
            try {
                list($eventId, $executionDate, $userEmail, $deviceId, $productionQty) = $row;

                writeLog("Processing production record {$i} - EventId: {$eventId}, DeviceId: {$deviceId}, UserEmail: {$userEmail}, Qty: {$productionQty}");

                // 1. Validate Record
                $validation = $validateRecord($userEmail, $deviceId, $userRepository, $bomRepository, $MsaDB, $FlowpinDB);
                if (!$validation['success']) {
                    throw $validation['error'];
                }

                $user = $validation['user'];
                $userId = $validation['userId'];
                $bomId = $validation['bomId'];

                // 2. Extract date for grouping (format: Y-m-d)
                $productionDate = date('Y-m-d', strtotime($executionDate));

                // 3. Get or create transfer group (Only now, after validations)
                $transferGroupId = getOrCreateTransferGroup($userId, $deviceId, $productionDate, 'Production', $transferGroupCache, $transferGroupManager, $MsaDB, $sessionRecordId, $sessionGroupCount);

                // 4. Process single record with its transfer group
                // processAndExecuteProduction manages its own internal success tracking but we are inside an atomic transaction now
                $batchResult = $productionProcessor->processAndExecuteProduction([$row], $productionDate, $transferGroupId, $sessionRecordId, $eventId);

                // Check for errors in results
                $hasError = false;
                foreach ($batchResult['results'] as $resEventId => $result) {
                    if (!$result['success']) {
                        $hasError = true;
                        $errorType = $result['errorType'];
                        $errorMessage = $result['errorMessage'];
                        throw new \Exception("Internal Production Error ($errorType): $errorMessage");
                    }
                }

                // If we reach here, it was successful
                trackInventoryChange($deviceId, $productionQty, "PRODUCED");
                $sessionTransferCount += $batchResult['overall']['processedCount'];
                $productionProcessedCount += $batchResult['overall']['processedCount'];
                
                $MsaDB->db->commit();
                writeLog("Successfully processed Production record - EventId: {$eventId}");

            } catch (\Throwable $exception) {
                if ($MsaDB->db->inTransaction()) {
                    $MsaDB->db->rollBack();
                }
                $productionErrorCount++;
                $errorCount++;

                $errorMessage = "Exception processing Production record {$i} - EventId: {$eventId} - Error: " . $exception->getMessage();
                writeLog($errorMessage, 'ERROR');
                writeLog("Row data: " . json_encode($row), 'ERROR');

                // Create notification for the error
                $notification = $notificationRepository->createNotificationFromException($exception, $row, 1); // 1 = Production
                writeLog("Created/Updated notification for Production record EventId: {$eventId}", 'ERROR');
            }

            $processedCount++;
            $highestProcessedEventId = max($highestProcessedEventId, $eventId);
            
            // Update checkpoint and progress after each record (atomic)
            updateCheckpoint($MsaDB, 'production_sku', $highestProcessedEventId);
            $updateProgress($processedCount, $totalRecords, 'production_sku', $highestProcessedEventId);
            
            // Update finishing_event_id in database
            $MsaDB->update("ref__flowpin_update_progress",
                ["finishing_event_id" => $highestProcessedEventId],
                "session_id", $sessionId);
        }

        // Final summary
        writeLog("Final production processing summary:");
        writeLog("- Total Processed: {$productionProcessedCount}");
        writeLog("- Total Errors: {$productionErrorCount}");

    } else {
        writeLog("No production records to process");
    }

    $overallHighestEventId = max($overallHighestEventId, $highestProcessedEventId);

    // Update main EventID only after all operations complete successfully
    if ($overallHighestEventId > $initialEventID) {
        $updateEventId($overallHighestEventId);
        writeLog("=== ALL PROCESSING COMPLETE - Updated main EventID to: {$overallHighestEventId} ===", 'INFO');
    } else {
        writeLog("No records processed, main EventID remains unchanged at: {$initialEventID}", 'INFO');
    }

    // Write final inventory changes summary
    writeInventoryChanges();

    // Final summary
    writeLog("=== Process Summary ===");
    writeLog("Total records found: {$totalRecords}");
    writeLog("Successfully processed (success): " . ($processedCount - $errorCount));
    writeLog("Errors encountered: {$errorCount}");
    writeLog("Total attempted: {$processedCount}");
    writeLog("Final EventId: {$overallHighestEventId}");

    if ($errorCount > 0) {
        writeLog("Check the error logs above and resolve notifications in the admin panel", 'WARNING');
    }

    // Final progress update to set operation_type and event_id
    $updateProgress($processedCount, $totalRecords, 'completed', $overallHighestEventId);

    // Set finishing EventId
    $sessionFinishingEventId = $overallHighestEventId;

    // Update progress status to completed with session metadata
    $MsaDB->update("ref__flowpin_update_progress", [
        "status" => "completed",
        "processed_records" => $processedCount,
        "starting_event_id" => $sessionStartingEventId,
        "finishing_event_id" => $sessionFinishingEventId,
        "created_transfer_count" => $sessionTransferCount,
        "created_group_count" => $sessionGroupCount,
        "updated_at" => date('Y-m-d H:i:s')
    ], "session_id", $sessionId);

    writeLog("Progress tracking completed for session: {$sessionId}");
    writeLog("Session metrics: {$sessionTransferCount} transfers, {$sessionGroupCount} groups created");
    writeLog("EventId range: {$sessionStartingEventId} - {$sessionFinishingEventId}");
    // Mark that the script completed normally (prevents shutdown handler from marking as error)
    $scriptCompletedNormally = true;

} catch (\Throwable $exception) {
    if ($MsaDB->db->inTransaction()) {
        $MsaDB->db->rollBack();
        writeLog("Transaction rolled back due to critical error", 'ERROR');
    }

    $criticalError = "Critical error in FlowPin update: " . $exception->getMessage() .
        " in " . $exception->getFile() . " on line " . $exception->getLine();
    writeLog($criticalError, 'CRITICAL');
    writeLog("Stack trace: " . $exception->getTraceAsString(), 'CRITICAL');

    // Update progress status to error, preserving EventId and counting transfers/groups
    $errorUpdate = [
        "status" => "error",
        "updated_at" => date('Y-m-d H:i:s')
    ];

    // Add starting_event_id if we captured it
    if ($sessionStartingEventId !== null) {
        $errorUpdate["starting_event_id"] = $sessionStartingEventId;
    }

    // Add finishing_event_id if we processed any records
    if ($overallHighestEventId > $initialEventID) {
        $errorUpdate["finishing_event_id"] = $overallHighestEventId;
    }

    // Count transfers created in this session across all inventory types
    $transferCount = $MsaDB->query("
        SELECT 
            (SELECT COUNT(*) FROM inventory__sku WHERE flowpin_update_session_id = $sessionRecordId) +
            (SELECT COUNT(*) FROM inventory__tht WHERE flowpin_update_session_id = $sessionRecordId) +
            (SELECT COUNT(*) FROM inventory__smd WHERE flowpin_update_session_id = $sessionRecordId) +
            (SELECT COUNT(*) FROM inventory__parts WHERE flowpin_update_session_id = $sessionRecordId) as total
    ", PDO::FETCH_COLUMN);

    // Count groups created in this session
    $groupCount = $MsaDB->query("
        SELECT COUNT(DISTINCT id) FROM inventory__transfer_groups 
        WHERE flowpin_update_session_id = $sessionRecordId
    ", PDO::FETCH_COLUMN);

    $errorUpdate["created_transfer_count"] = $transferCount[0] ?? 0;
    $errorUpdate["created_group_count"] = $groupCount[0] ?? 0;

    $MsaDB->update("ref__flowpin_update_progress", $errorUpdate, "session_id", $sessionId);
    writeLog("Progress tracking marked as error for session: {$sessionId}");
    writeLog("Transfers created before error: " . ($transferCount[0] ?? 0) . ", Groups created: " . ($groupCount[0] ?? 0));

} finally {
    if (isset($locker)) {
        $locker->unlock();
        writeLog("Lock released");
    }
    writeLog("=== FlowPin SKU Update Process Completed ===");
}
