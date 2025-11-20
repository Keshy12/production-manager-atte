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

    // Create comment with date and device info
    $comment = "FlowPin {$operationType} {$date} - Device: {$deviceName}";

    // Create transfer group
    $transferGroupId = $transferGroupManager->createTransferGroup($userId, $comment);

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

$locker = new Locker('flowpin.lock');
$is_locked = $locker->lock(FALSE);

if (!$is_locked) {
    writeLog("Could not acquire lock. Another process may be running.", 'WARNING');
    exit(1);
}

writeLog("Lock acquired successfully");

// Clean up any stale sessions from previous crashed runs
$MsaDB->query("UPDATE ref__flowpin_update_progress SET status = 'error' WHERE status = 'running'");
writeLog("Marked any previous running sessions as crashed");

// Create unique session ID for progress tracking
$sessionId = 'flowpin_' . date('Ymd_His') . '_' . uniqid();

// Initialize progress tracking (will be updated with total after data fetch)
$MsaDB->insert("ref__flowpin_update_progress",
    ["session_id", "total_records", "processed_records", "status", "started_at"],
    [$sessionId, 0, 0, "running", date('Y-m-d H:i:s')]);
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

    $batchCount = 0;
    $transactionActive = false;
    $highestProcessedEventId = getCheckpoint($MsaDB, 'sold_sku');

    if (count($soldSku) > 0) {
        $MsaDB->db->beginTransaction();
        $transactionActive = true;
    }

    foreach ($soldSku as $row) {
        try {
            list($eventId, $executionDate, $userEmail, $deviceId, $qty) = $row;
            $flowpinQueryTypeId = 2;

            writeLog("Processing Sold SKU - EventId: {$eventId}, DeviceId: {$deviceId}, UserEmail: {$userEmail}, Qty: {$qty}");

            if (!ensureSkuExists($deviceId, $MsaDB, $FlowpinDB)) {
                throw new \Exception("Failed to ensure SKU $deviceId exists in database");
            }

            $user = $userRepository->getUserByEmail($userEmail);
            $userId = $user->userId;

            // Extract date for grouping (format: Y-m-d)
            $productionDate = date('Y-m-d', strtotime($executionDate));

            // Get or create transfer group
            $transferGroupId = getOrCreateTransferGroup($userId, $deviceId, $productionDate, 'Sold', $transferGroupCache, $transferGroupManager, $MsaDB, $sessionRecordId, $sessionGroupCount);

            // Get BOM ID
            $bomId = getSkuBomId($deviceId, $bomRepository);

            $comment = "Finalizacja zamówienia, spakowano do wysyłki, EventId: " . $eventId;
            $columns = ["sku_id", "sub_magazine_id", "qty", "timestamp", "production_date", "input_type_id", "comment", "transfer_group_id", "sku_bom_id", "flowpin_update_session_id", "flowpin_event_id"];
            $values = [$deviceId, "0", $qty, $executionDate, $productionDate, "9", $comment, $transferGroupId, $bomId, $sessionRecordId, $eventId];
            $MsaDB->insert("inventory__sku", $columns, $values);
            $sessionTransferCount++;

            // Track inventory change for summary
            trackInventoryChange($deviceId, $qty, "SOLD");

            $processedCount++;
            $batchCount++;
            $highestProcessedEventId = max($highestProcessedEventId, $eventId);

            writeLog("Successfully processed Sold SKU - EventId: {$eventId}");

            // Commit every batch and update checkpoint
            if ($batchCount >= $commitBatchSize) {
                $MsaDB->db->commit();
                updateCheckpoint($MsaDB, 'sold_sku', $highestProcessedEventId);
                writeLog("Committed batch of {$batchCount} sold SKU records. Checkpoint EventId: {$highestProcessedEventId}");
                $updateProgress($processedCount, $totalRecords, 'sold_sku', $highestProcessedEventId);
                $MsaDB->db->beginTransaction();
                $batchCount = 0;
            }

        } catch (\Throwable $exception) {
            $errorCount++;
            $errorMessage = "Error processing Sold SKU - EventId: {$eventId}, Error: " . $exception->getMessage() .
                " in " . $exception->getFile() . " on line " . $exception->getLine();
            writeLog($errorMessage, 'ERROR');
            writeLog("Row data: " . json_encode($row), 'ERROR');

            $notification = $notificationRepository->createNotificationFromException($exception, $row, $flowpinQueryTypeId);
            $notificationId = $notification->notificationValues['id'];
            writeLog("Created notification ID: {$notificationId} for Sold SKU EventId: {$eventId}", 'ERROR');

            continue;
        }
    }

    // Commit remaining records and update checkpoint
    if ($batchCount > 0 && $transactionActive) {
        $MsaDB->db->commit();
        updateCheckpoint($MsaDB, 'sold_sku', $highestProcessedEventId);
        writeLog("Committed final batch of {$batchCount} sold SKU records");
        $updateProgress($processedCount, $totalRecords, 'sold_sku', $highestProcessedEventId);
        $transactionActive = false;
    } elseif ($transactionActive) {
        $MsaDB->db->rollBack();
        $transactionActive = false;
    }

    $overallHighestEventId = max($overallHighestEventId, $highestProcessedEventId);

    // Process Returned SKUs
    writeLog("=== Processing Returned SKUs ===");
    writeLog("Found " . count($returnedSku) . " returned SKU records to process");

    $batchCount = 0;
    $transactionActive = false;
    $highestProcessedEventId = getCheckpoint($MsaDB, 'returned_sku');

    if (count($returnedSku) > 0) {
        $MsaDB->db->beginTransaction();
        $transactionActive = true;
    }

    foreach ($returnedSku as $row) {
        try {
            list($eventId, $executionDate, $userEmail, $deviceId, $qty) = $row;
            $flowpinQueryTypeId = 3;

            writeLog("Processing Returned SKU - EventId: {$eventId}, DeviceId: {$deviceId}, UserEmail: {$userEmail}, Qty: {$qty}");

            if (!ensureSkuExists($deviceId, $MsaDB, $FlowpinDB)) {
                throw new \Exception("Failed to ensure SKU $deviceId exists in database");
            }

            $user = $userRepository->getUserByEmail($userEmail);
            $userId = $user->userId;

            // Extract date for grouping (format: Y-m-d)
            $productionDate = date('Y-m-d', strtotime($executionDate));

            // Get or create transfer group
            $transferGroupId = getOrCreateTransferGroup($userId, $deviceId, $productionDate, 'Returned', $transferGroupCache, $transferGroupManager, $MsaDB, $sessionRecordId, $sessionGroupCount);

            // Get BOM ID
            $bomId = getSkuBomId($deviceId, $bomRepository);

            $comment = "Zwrot SKU od klienta, EventId: " . $eventId;
            $columns = ["sku_id", "sub_magazine_id", "qty", "timestamp", "production_date", "input_type_id", "comment", "transfer_group_id", "sku_bom_id", "flowpin_update_session_id", "flowpin_event_id"];
            $values = [$deviceId, "0", $qty, $executionDate, $productionDate, "10", $comment, $transferGroupId, $bomId, $sessionRecordId, $eventId];
            $MsaDB->insert("inventory__sku", $columns, $values);
            $sessionTransferCount++;

            // Track inventory change for summary
            trackInventoryChange($deviceId, $qty, "RETURNED");

            $processedCount++;
            $batchCount++;
            $highestProcessedEventId = max($highestProcessedEventId, $eventId);

            writeLog("Successfully processed Returned SKU - EventId: {$eventId}");

            if ($batchCount >= $commitBatchSize) {
                $MsaDB->db->commit();
                updateCheckpoint($MsaDB, 'returned_sku', $highestProcessedEventId);
                writeLog("Committed batch of {$batchCount} returned SKU records. Checkpoint EventId: {$highestProcessedEventId}");
                $updateProgress($processedCount, $totalRecords, 'returned_sku', $highestProcessedEventId);
                $MsaDB->db->beginTransaction();
                $batchCount = 0;
            }

        } catch (\Throwable $exception) {
            $errorCount++;
            $errorMessage = "Error processing Returned SKU - EventId: {$eventId}, Error: " . $exception->getMessage() .
                " in " . $exception->getFile() . " on line " . $exception->getLine();
            writeLog($errorMessage, 'ERROR');
            writeLog("Row data: " . json_encode($row), 'ERROR');

            $notification = $notificationRepository->createNotificationFromException($exception, $row, $flowpinQueryTypeId);
            $notificationId = $notification->notificationValues['id'];
            writeLog("Created notification ID: {$notificationId} for Returned SKU EventId: {$eventId}", 'ERROR');

            continue;
        }
    }

    if ($batchCount > 0 && $transactionActive) {
        $MsaDB->db->commit();
        updateCheckpoint($MsaDB, 'returned_sku', $highestProcessedEventId);
        writeLog("Committed final batch of {$batchCount} returned SKU records");
        $updateProgress($processedCount, $totalRecords, 'returned_sku', $highestProcessedEventId);
        $transactionActive = false;
    } elseif ($transactionActive) {
        $MsaDB->db->rollBack();
        $transactionActive = false;
    }

    $overallHighestEventId = max($overallHighestEventId, $highestProcessedEventId);

    // Process Moved SKUs
    writeLog("=== Processing Moved SKUs ===");
    writeLog("Found " . count($movedSku) . " moved SKU records to process");

    $batchCount = 0;
    $transactionActive = false;
    $highestProcessedEventId = getCheckpoint($MsaDB, 'moved_sku');

    if (count($movedSku) > 0) {
        $MsaDB->db->beginTransaction();
        $transactionActive = true;
    }

    foreach ($movedSku as $row) {
        try {
            list($eventId, $executionDate, $userEmail, $deviceId, $warehouseOut, $qtyOut, $warehouseIn, $qtyIn) = $row;
            $flowpinQueryTypeId = 4;

            writeLog("Processing Moved SKU - EventId: {$eventId}, DeviceId: {$deviceId}, UserEmail: {$userEmail}, " .
                "WarehouseOut: {$warehouseOut}, QtyOut: {$qtyOut}, WarehouseIn: {$warehouseIn}, QtyIn: {$qtyIn}");

            if (!ensureSkuExists($deviceId, $MsaDB, $FlowpinDB)) {
                throw new \Exception("Failed to ensure SKU $deviceId exists in database");
            }

            $user = $userRepository->getUserByEmail($userEmail);
            $userId = $user->userId;

            // Extract date for grouping (format: Y-m-d)
            $productionDate = date('Y-m-d', strtotime($executionDate));

            // Get or create transfer group (same group for OUT and IN)
            $transferGroupId = getOrCreateTransferGroup($userId, $deviceId, $productionDate, 'Moved', $transferGroupCache, $transferGroupManager, $MsaDB, $sessionRecordId, $sessionGroupCount);

            // Get BOM ID
            $bomId = getSkuBomId($deviceId, $bomRepository);

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

            $processedCount++;
            $batchCount++;
            $highestProcessedEventId = max($highestProcessedEventId, $eventId);

            writeLog("Successfully processed Moved SKU - EventId: {$eventId}");

            if ($batchCount >= $commitBatchSize) {
                $MsaDB->db->commit();
                updateCheckpoint($MsaDB, 'moved_sku', $highestProcessedEventId);
                writeLog("Committed batch of {$batchCount} moved SKU records. Checkpoint EventId: {$highestProcessedEventId}");
                $updateProgress($processedCount, $totalRecords, 'moved_sku', $highestProcessedEventId);
                $MsaDB->db->beginTransaction();
                $batchCount = 0;
            }

        } catch (\Throwable $exception) {
            $errorCount++;
            $errorMessage = "Error processing Moved SKU - EventId: {$eventId}, Error: " . $exception->getMessage() .
                " in " . $exception->getFile() . " on line " . $exception->getLine();
            writeLog($errorMessage, 'ERROR');
            writeLog("Row data: " . json_encode($row), 'ERROR');

            $notification = $notificationRepository->createNotificationFromException($exception, $row, $flowpinQueryTypeId);
            $notificationId = $notification->notificationValues['id'];
            writeLog("Created notification ID: {$notificationId} for Moved SKU EventId: {$eventId}", 'ERROR');

            continue;
        }
    }

    if ($batchCount > 0 && $transactionActive) {
        $MsaDB->db->commit();
        updateCheckpoint($MsaDB, 'moved_sku', $highestProcessedEventId);
        writeLog("Committed final batch of {$batchCount} moved SKU records");
        $updateProgress($processedCount, $totalRecords, 'moved_sku', $highestProcessedEventId);
        $transactionActive = false;
    } elseif ($transactionActive) {
        $MsaDB->db->rollBack();
        $transactionActive = false;
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

    $batchSize = 10; // Commit every 10 records
    $batchCount = 0;
    $transactionActive = false;

    if (count($producedSkuAndInter) > 0) {
        $productionProcessor = new SkuProductionProcessor($MsaDB, $FlowpinDB);

        // Begin transaction
        $MsaDB->db->beginTransaction();
        $transactionActive = true;

        // Process records individually to handle transfer groups properly
        foreach ($producedSkuAndInter as $i => $row) {
            try {
                list($eventId, $executionDate, $userEmail, $deviceId, $productionQty) = $row;

                writeLog("Processing production record {$i} - EventId: {$eventId}, DeviceId: {$deviceId}, UserEmail: {$userEmail}, Qty: {$productionQty}");

                // Get user for transfer group creation
                try {
                    $user = $userRepository->getUserByEmail($userEmail);
                    $userId = $user->userId;
                } catch (\Throwable $e) {
                    writeLog("Could not get user for email {$userEmail}: " . $e->getMessage(), 'ERROR');
                    $productionErrorCount++;
                    $productionUserErrors++;
                    continue;
                }

                // Extract date for grouping (format: Y-m-d)
                $productionDate = date('Y-m-d', strtotime($executionDate));

                // Get or create transfer group
                $transferGroupId = getOrCreateTransferGroup($userId, $deviceId, $productionDate, 'Production', $transferGroupCache, $transferGroupManager, $MsaDB, $sessionRecordId, $sessionGroupCount);

                // Process single record with its transfer group
                $batchResult = $productionProcessor->processAndExecuteProduction([$row], $productionDate, $transferGroupId, $sessionRecordId, $eventId);

                // Update overall counters
                $productionProcessedCount += $batchResult['overall']['processedCount'];
                $productionErrorCount += $batchResult['overall']['errorCount'];

                // Track session transfer count (production creates multiple transfers via BOM explosion)
                $sessionTransferCount += $batchResult['overall']['processedCount'];
                $productionDatabaseErrors += $batchResult['errorSummary']['DATABASE_ERROR'];
                $productionProcessingErrors += $batchResult['errorSummary']['PROCESSING_ERROR'];
                $productionNoDataErrors += $batchResult['errorSummary']['NO_DATA'];
                $productionBomErrors += $batchResult['errorSummary']['BOM_ERROR'];
                $productionUserErrors += $batchResult['errorSummary']['USER_ERROR'];

                if ($batchResult['overall']['highestEventId'] > 0) {
                    $highestProcessedEventId = max($highestProcessedEventId, $batchResult['overall']['highestEventId']);
                }

                // Log individual results for errors
                foreach ($batchResult['results'] as $eventId => $result) {
                    if (!$result['success']) {
                        writeLog("DEBUG: EventId {$eventId} has errorType: '{$result['errorType']}'", 'INFO');

                        switch ($result['errorType']) {
                            case 'DATABASE_ERROR':
                                writeLog("Database error processing Production SKU - EventId: {$eventId}, Queries executed: {$result['queriesExecuted']}/{$result['totalQueries']}, Error: {$result['errorMessage']}", 'ERROR');
                                break;
                            case 'PROCESSING_ERROR':
                                writeLog("Critical processing error for Production SKU - EventId: {$eventId}, Error: {$result['errorMessage']}", 'ERROR');
                                break;
                            case 'BOM_ERROR':
                                writeLog("BOM/SKU error processing Production SKU - EventId: {$eventId}, Error: {$result['errorMessage']}", 'ERROR');
                                break;
                            case 'USER_ERROR':
                                writeLog("User lookup error processing Production SKU - EventId: {$eventId}, Error: {$result['errorMessage']}", 'ERROR');
                                break;
                            case 'NO_DATA':
                                writeLog("No data error processing Production SKU - EventId: {$eventId}, Error: {$result['errorMessage']}", 'WARNING');
                                break;
                            default:
                                writeLog("Unknown error processing Production SKU - EventId: {$eventId}, ErrorType: '{$result['errorType']}', Error: {$result['errorMessage']}", 'ERROR');
                        }

                        writeLog("Row data: " . json_encode($row), 'ERROR');
                        $exception = $result['exception'] ?? new \Exception($result['errorMessage'] ?? 'Unknown error');
                        $notification = $notificationRepository->createNotificationFromException($exception, $row, 1);
                        $notificationId = $notification->notificationValues['id'];
                        writeLog("Created notification ID: {$notificationId} for Production SKU EventId: {$eventId}", 'ERROR');
                    } else {
                        // Track inventory change for successful processing
                        trackInventoryChange($deviceId, $productionQty, "PRODUCED");
                        $batchCount++;
                    }
                }

                // Commit every batch and update checkpoint
                if ($batchCount >= $batchSize) {
                    $MsaDB->db->commit();
                    updateCheckpoint($MsaDB, 'production_sku', $highestProcessedEventId);
                    writeLog("Committed batch of {$batchCount} production records. Checkpoint EventId: {$highestProcessedEventId}");
                    $updateProgress($processedCount + $productionProcessedCount, $totalRecords, 'production_sku', $highestProcessedEventId);
                    $MsaDB->db->beginTransaction();
                    $batchCount = 0;
                }

            } catch (\Throwable $exception) {
                $productionErrorCount++;
                $errorCount++;

                $errorMessage = "Exception processing Production record {$i} - EventId: {$eventId} - Error: " . $exception->getMessage() .
                    " in " . $exception->getFile() . " on line " . $exception->getLine();
                writeLog($errorMessage, 'ERROR');
                writeLog("Row data: " . json_encode($row), 'ERROR');

                // Create notification for the error
                $notification = $notificationRepository->createNotificationFromException($exception, $row, 1);
                $notificationId = $notification->notificationValues['id'];
                writeLog("Created notification ID: {$notificationId} for Production record EventId: {$eventId}", 'ERROR');

                // Rollback current batch on critical error
                if ($transactionActive) {
                    $MsaDB->db->rollBack();
                    writeLog("Rolled back current production batch due to exception", 'WARNING');
                    $MsaDB->db->beginTransaction();
                    $batchCount = 0;
                }

                continue;
            }
        }

        // Commit remaining records and update checkpoint
        if ($batchCount > 0 && $transactionActive) {
            $MsaDB->db->commit();
            updateCheckpoint($MsaDB, 'production_sku', $highestProcessedEventId);
            writeLog("Committed final batch of {$batchCount} production records. Checkpoint EventId: {$highestProcessedEventId}");
            $updateProgress($processedCount + $productionProcessedCount, $totalRecords, 'production_sku', $highestProcessedEventId);
            $transactionActive = false;
        } elseif ($transactionActive) {
            $MsaDB->db->rollBack();
            $transactionActive = false;
        }

        // Update global counters
        $processedCount += $productionProcessedCount;
        $errorCount += $productionErrorCount;

        // Update progress after production completes
        $updateProgress($processedCount, $totalRecords, 'production_sku', $highestProcessedEventId);

        // Final summary
        writeLog("Final production processing summary:");
        writeLog("- Total Processed: {$productionProcessedCount}");
        writeLog("- Total Errors: {$productionErrorCount}");

        if ($productionErrorCount > 0) {
            writeLog("- Database Errors: {$productionDatabaseErrors}");
            writeLog("- Processing Errors: {$productionProcessingErrors}");
            writeLog("- BOM Errors: {$productionBomErrors}");
            writeLog("- User Errors: {$productionUserErrors}");
            writeLog("- No Data Errors: {$productionNoDataErrors}");
        }

        if ($productionProcessedCount > 0) {
            writeLog("- Highest EventId: {$highestProcessedEventId}");
        }

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
    writeLog("Successfully processed: {$processedCount}");
    writeLog("Errors encountered: {$errorCount}");
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

} catch (\Throwable $exception) {
    if ($MsaDB->db->inTransaction()) {
        $MsaDB->db->rollBack();
        writeLog("Transaction rolled back due to critical error", 'ERROR');
    }

    $criticalError = "Critical error in FlowPin update: " . $exception->getMessage() .
        " in " . $exception->getFile() . " on line " . $exception->getLine();
    writeLog($criticalError, 'CRITICAL');
    writeLog("Stack trace: " . $exception->getTraceAsString(), 'CRITICAL');

    // Update progress status to error
    $MsaDB->update("ref__flowpin_update_progress",
        ["status" => "error", "updated_at" => date('Y-m-d H:i:s')],
        "session_id", $sessionId);
    writeLog("Progress tracking marked as error for session: {$sessionId}");

} finally {
    $locker->unlock();
    writeLog("Lock released");
    writeLog("=== FlowPin SKU Update Process Completed ===");
}