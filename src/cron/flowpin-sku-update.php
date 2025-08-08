<?php

use Atte\DB\MsaDB;
use Atte\DB\FlowpinDB;
use Atte\Utils\NotificationRepository;
use Atte\Utils\UserRepository;
use Atte\Utils\Locker;
use Atte\Utils\Production\SkuProductionProcessor;
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

    try {
        $googleSheets = new GoogleSheets();
        $spreadsheetId = '1AKW_-aw139SjcpXtPOJHCqKXh4Ihe2T-H4RKgLc_TIY';
        $sheetName = 'MSA_flowpin_update_summary';

        $result = $googleSheets->appendToSheet($spreadsheetId, $sheetName, 'A:D', $sheetsData);

        if ($result !== false) {
            writeLog("Successfully sent inventory changes to Google Sheets. Updated {$result} cells.");
        } else {
            writeLog("Failed to send inventory changes to Google Sheets", 'WARNING');
        }
    } catch (\Exception $e) {
        writeLog("Error sending data to Google Sheets: " . $e->getMessage(), 'ERROR');
    }
}

writeLog("=== Starting FlowPin SKU Update Process ===");

$MsaDB = MsaDB::getInstance();
$FlowpinDB = FlowpinDB::getInstance();
$notificationRepository = new NotificationRepository($MsaDB);
$userRepository = new UserRepository($MsaDB);

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

try {
    $processedCount = 0;
    $errorCount = 0;
    $commitBatchSize = 100; // Commit every 100 records
    $overallHighestEventId = $initialEventID;

    // Process Sold SKUs
    writeLog("=== Processing Sold SKUs ===");
    $soldSku = $getSoldSku();
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

            $user = $userRepository->getUserByEmail($userEmail);
            $userId = $user->userId;
            $comment = "Finalizacja zamówienia, spakowano do wysyłki, EventId: " . $eventId;
            $columns = ["sku_id", "user_id", "sub_magazine_id", "quantity", "timestamp", "input_type_id", "comment"];
            $values = [$deviceId, $userId, "0", $qty, $executionDate, "9", $comment];
            $MsaDB->insert("inventory__sku", $columns, $values);

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
        $transactionActive = false;
    } elseif ($transactionActive) {
        $MsaDB->db->rollBack();
        $transactionActive = false;
    }

    $overallHighestEventId = max($overallHighestEventId, $highestProcessedEventId);

    // Process Returned SKUs
    writeLog("=== Processing Returned SKUs ===");
    $returnedSku = $getReturnedSku();
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

            $user = $userRepository->getUserByEmail($userEmail);
            $userId = $user->userId;
            $comment = "Zwrot SKU od klienta, EventId: " . $eventId;
            $columns = ["sku_id", "user_id", "sub_magazine_id", "quantity", "timestamp", "input_type_id", "comment"];
            $values = [$deviceId, $userId, "0", $qty, $executionDate, "10", $comment];
            $MsaDB->insert("inventory__sku", $columns, $values);

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
        $transactionActive = false;
    } elseif ($transactionActive) {
        $MsaDB->db->rollBack();
        $transactionActive = false;
    }

    $overallHighestEventId = max($overallHighestEventId, $highestProcessedEventId);

    // Process Moved SKUs
    writeLog("=== Processing Moved SKUs ===");
    $movedSku = $getMovedSku();
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

            $user = $userRepository->getUserByEmail($userEmail);
            $userId = $user->userId;
            $comment = "Przesunięcie między magazynowe, EventId: " . $eventId;

            if ($warehouseOut == 3 || $warehouseOut == 4) {
                $columns = ["sku_id", "user_id", "sub_magazine_id", "quantity", "timestamp", "input_type_id", "comment"];
                $values = [$deviceId, $userId, "0", $qtyOut, $executionDate, "2", $comment];
                $MsaDB->insert("inventory__sku", $columns, $values);
                writeLog("Inserted warehouse OUT record - EventId: {$eventId}");

                // Track inventory change for summary
                trackInventoryChange($deviceId, $qtyOut, "MOVED_OUT");
            }

            if ($warehouseIn == 3 || $warehouseIn == 4) {
                $columns = ["sku_id", "user_id", "sub_magazine_id", "quantity", "timestamp", "input_type_id", "comment"];
                $values = [$deviceId, $userId, "0", $qtyIn, $executionDate, "2", $comment];
                $MsaDB->insert("inventory__sku", $columns, $values);
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
        $transactionActive = false;
    } elseif ($transactionActive) {
        $MsaDB->db->rollBack();
        $transactionActive = false;
    }

    $overallHighestEventId = max($overallHighestEventId, $highestProcessedEventId);

    // Process Production SKUs
    writeLog("=== Processing Production SKUs ===");
    $producedSkuAndInter = $getProducedSkuAndInter();
    writeLog("Found " . count($producedSkuAndInter) . " production records to process");

    $highestProcessedEventId = getCheckpoint($MsaDB, 'production_sku');
    $productionProcessedCount = 0;
    $productionErrorCount = 0;
    $productionDatabaseErrors = 0;
    $productionProcessingErrors = 0;
    $productionNoDataErrors = 0;
    $productionBomErrors = 0;
    $productionUserErrors = 0;

    $batchSize = 50; // Process in batches for better performance
    $currentBatch = [];
    $batchNumber = 1;

    if (count($producedSkuAndInter) > 0) {
        $productionProcessor = new SkuProductionProcessor($MsaDB, $FlowpinDB);

        // Process records in batches
        for ($i = 0; $i < count($producedSkuAndInter); $i++) {
            $currentBatch[] = $producedSkuAndInter[$i];

            // Process batch when it reaches batchSize or at the end
            if (count($currentBatch) >= $batchSize || $i == count($producedSkuAndInter) - 1) {
                writeLog("Processing production batch {$batchNumber} with " . count($currentBatch) . " records");

                try {
                    $batchResult = $productionProcessor->processAndExecuteProduction($currentBatch);

                    // Update overall counters
                    $productionProcessedCount += $batchResult['overall']['processedCount'];
                    $productionErrorCount += $batchResult['overall']['errorCount'];
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
                            // Debug: Log what errorType we actually got
                            writeLog("DEBUG: EventId {$eventId} has errorType: '{$result['errorType']}'", 'INFO');

                            // Find the original row data for this eventId
                            $row = null;
                            foreach ($currentBatch as $batchRow) {
                                if ($batchRow[0] == $eventId) { // EventId is first element
                                    $row = $batchRow;
                                    break;
                                }
                            }

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

                            if ($row) {
                                writeLog("Row data: " . json_encode($row), 'ERROR');
                                // Use the proper exception from the result instead of creating a new generic one
                                $exception = $result['exception'] ?? new \Exception($result['errorMessage'] ?? 'Unknown error');
                                writeLog("DEBUG: Using exception with code: " . $exception->getCode() . " and message: " . $exception->getMessage(), 'INFO');

                                $notification = $notificationRepository->createNotificationFromException($exception, $row, 1);
                                $notificationId = $notification->notificationValues['id'];
                                writeLog("Created notification ID: {$notificationId} for Production SKU EventId: {$eventId}", 'ERROR');
                            }
                        } else {
                            // Track inventory change for successful processing
                            foreach ($currentBatch as $batchRow) {
                                if ($batchRow[0] == $eventId) {
                                    list($eventId, $executionDate, $userEmail, $deviceId, $productionQty) = $batchRow;
                                    trackInventoryChange($deviceId, $productionQty, "PRODUCED");
                                    break;
                                }
                            }
                        }
                    }

                    writeLog("Batch {$batchNumber} completed - Processed: {$batchResult['overall']['processedCount']}, Errors: {$batchResult['overall']['errorCount']}");

                    // Update checkpoint after each successful batch
                    if ($batchResult['overall']['processedCount'] > 0) {
                        updateCheckpoint($MsaDB, 'production_sku', $highestProcessedEventId);
                        writeLog("Updated production checkpoint after batch {$batchNumber}. EventId: {$highestProcessedEventId}");
                    }

                } catch (\Throwable $exception) {
                    $productionErrorCount += count($currentBatch);
                    $errorCount += count($currentBatch);

                    $errorMessage = "Exception processing Production batch {$batchNumber} - Error: " . $exception->getMessage() .
                        " in " . $exception->getFile() . " on line " . $exception->getLine();
                    writeLog($errorMessage, 'ERROR');
                    writeLog("Batch data: " . json_encode($currentBatch), 'ERROR');

                    // Create notification for the batch error
                    $notification = $notificationRepository->createNotificationFromException($exception, $currentBatch, 1);
                    $notificationId = $notification->notificationValues['id'];
                    writeLog("Created notification ID: {$notificationId} for Production batch {$batchNumber}", 'ERROR');
                }

                // Reset batch
                $currentBatch = [];
                $batchNumber++;
            }
        }

        // Update global counters
        $processedCount += $productionProcessedCount;
        $errorCount += $productionErrorCount;

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
    $totalRecords = count($soldSku) + count($returnedSku) + count($movedSku) + count($producedSkuAndInter);
    writeLog("=== Process Summary ===");
    writeLog("Total records found: {$totalRecords}");
    writeLog("Successfully processed: {$processedCount}");
    writeLog("Errors encountered: {$errorCount}");
    writeLog("Final EventId: {$overallHighestEventId}");

    if ($errorCount > 0) {
        writeLog("Check the error logs above and resolve notifications in the admin panel", 'WARNING');
    }

} catch (\Throwable $exception) {
    if ($MsaDB->db->inTransaction()) {
        $MsaDB->db->rollBack();
        writeLog("Transaction rolled back due to critical error", 'ERROR');
    }

    $criticalError = "Critical error in FlowPin update: " . $exception->getMessage() .
        " in " . $exception->getFile() . " on line " . $exception->getLine();
    writeLog($criticalError, 'CRITICAL');
    writeLog("Stack trace: " . $exception->getTraceAsString(), 'CRITICAL');

} finally {
    $locker->unlock();
    writeLog("Lock released");
    writeLog("=== FlowPin SKU Update Process Completed ===");
}