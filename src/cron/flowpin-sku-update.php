<?php

use Atte\DB\MsaDB;
use Atte\DB\FlowpinDB;
use Atte\Utils\NotificationRepository;
use Atte\Utils\UserRepository;
use Atte\Utils\Locker;
use Atte\Utils\Production\SkuProductionProcessor;

set_time_limit(3600);

$MsaDB = MsaDB::getInstance();
$FlowpinDB = FlowpinDB::getInstance();
$MsaDB->db->beginTransaction();
$notificationRepository = new NotificationRepository($MsaDB);
$userRepository = new UserRepository($MsaDB);
$lastEventID = $MsaDB->query("SELECT params FROM `ref__timestamp` WHERE id = 4")[0]["params"];
$updateEventId = function () use (&$lastEventID, $MsaDB) {
    $now = date("Y/m/d H:i:s", time());
    $MsaDB->update("ref__timestamp", ["params" => $lastEventID, "last_timestamp" => $now], "id", 4);
};

$getProducedSkuAndInter = function () use ($FlowpinDB, $lastEventID) {
    $query = "SELECT EventId, ExecutionDate, ByUserEmail, ProductTypeId, ProductionQty
        FROM [report].[ProductQuantityHistoryView] 
        WHERE (EventId > '$lastEventID') 
        AND (ProductionQty = 1 OR ProductionQty = -1) 
        AND (WarehouseId = '3' OR WarehouseId = '4')
        ORDER BY EventId ASC";
    $result = $FlowpinDB->query($query);
    return $result;
};
$getSoldSku = function () use ($FlowpinDB, $lastEventID) {
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
        WHERE EventTypeValue = 'Modified' AND (EventId > '$lastEventID') AND IsInter = 0
        AND ((FieldOldValue = 'ContractorHasIt' AND FieldNewValue = 'InOrder') 
        OR (FieldOldValue = 'InOrder' AND FieldNewValue = 'ContractorHasIt')) ORDER BY EventId ASC";
    $result = $FlowpinDB->query($query);
    return $result;
};
$getReturnedSku = function () use ($FlowpinDB, $lastEventID) {
    $query = "SELECT EventId, ExecutionDate, ByUserEmail, ProductTypeId,
        CASE WHEN ProductTypeId IS NOT NULL
        AND FieldOldValue = 4
        THEN -1
        WHEN ProductTypeId IS NOT NULL
        AND FieldNewValue = 4
        THEN 1 END AS ReturnQty 
        FROM [report].[ProductQuantityHistoryView] 
        WHERE (EventId > '$lastEventID')
        AND EventTypeValue = 'ProductReturn' 
        AND FieldName = 'WarehouseId' AND IsInter = 0 AND (FieldNewValue = 4 OR FieldOldValue = 4) 
        AND FieldOldValue != FieldNewValue 
        AND FieldNewValue != 81
        ORDER BY EventId ASC";
    $result = $FlowpinDB->query($query);
    return $result;
};
$getMovedSku = function () use ($FlowpinDB, $lastEventID) {
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
        WHERE (EventId > '$lastEventID') 
        AND EventTypeValue = 'WarehouseChange' 
        AND FieldName = 'WarehouseId' 
        AND State = 1 
        AND ((FieldNewValue = 3 OR FieldNewValue = 4) OR (FieldOldValue = 3 OR FieldOldValue = 4))
        AND ParentId IS NULL ORDER BY EventId ASC";
    $result = $FlowpinDB->query($query);
    return $result;
};

$locker = new Locker('flowpin.lock');
$is_locked = $locker->lock(FALSE);
if ($is_locked) {
    $eventId = 0;
    $producedSkuAndInter = $getProducedSkuAndInter();

    $soldSku = $getSoldSku();
    $returnedSku = $getReturnedSku();
    $movedSku = $getMovedSku();

    foreach ($soldSku as $row) {
        list($eventId, $executionDate, $userEmail, $deviceId, $qty) = $row;
        $flowpinQueryTypeId = 2;
        try {
            $user = $userRepository->getUserByEmail($userEmail);
            $userId = $user->userId;
            $comment = "Finalizacja zamówienia, spakowano do wysyłki, EventId: " . $eventId;
            $columns = ["sku_id", "user_id", "sub_magazine_id", "quantity", "timestamp", "input_type_id", "comment"];
            $values = [$deviceId, $userId, "0", $qty, $executionDate, "9", $comment];
            $MsaDB->insert("inventory__sku", $columns, $values);
        } catch (\Throwable $exception) {
            $notificationRepository->createNotificationFromException($exception, $row, $flowpinQueryTypeId);
            continue;
        }
    }
    $lastEventID = max($eventId, $lastEventID);

    foreach ($returnedSku as $row) {
        list($eventId, $executionDate, $userEmail, $deviceId, $qty) = $row;
        $flowpinQueryTypeId = 3;
        try {
            $user = $userRepository->getUserByEmail($userEmail);
            $userId = $user->userId;
            $comment = "Zwrot SKU od klienta, EventId: " . $eventId;
            $columns = ["sku_id", "user_id", "sub_magazine_id", "quantity", "timestamp", "input_type_id", "comment"];
            $values = [$deviceId, $userId, "0", $qty, $executionDate, "10", $comment];
            $MsaDB->insert("inventory__sku", $columns, $values);
        } catch (\Throwable $exception) {
            $notificationRepository->createNotificationFromException($exception, $row, $flowpinQueryTypeId);
            continue;
        }
    }
    $lastEventID = max($eventId, $lastEventID);

    foreach ($movedSku as $row) {
        list($eventId, $executionDate, $userEmail, $deviceId, $warehouseOut, $qtyOut, $warehouseIn, $qtyIn) = $row;
        $flowpinQueryTypeId = 4;
        try {
            $user = $userRepository->getUserByEmail($userEmail);
            $userId = $user->userId;
            $comment = "Przesunięcie między magazynowe, EventId: " . $eventId;
            if ($warehouseOut == 3 || $warehouseOut == 4) {
                $columns = ["sku_id", "user_id", "sub_magazine_id", "quantity", "timestamp", "input_type_id", "comment"];
                $values = [$deviceId, $userId, "0", $qtyOut, $executionDate, "2", $comment];
                $MsaDB->insert("inventory__sku", $columns, $values);
            }
            if ($warehouseIn == 3 || $warehouseIn == 4) {
                $columns = ["sku_id", "user_id", "sub_magazine_id", "quantity", "timestamp", "input_type_id", "comment"];
                $values = [$deviceId, $userId, "0", $qtyIn, $executionDate, "2", $comment];
                $MsaDB->insert("inventory__sku", $columns, $values);
            }
        } catch (\Throwable $exception) {
            $notificationRepository->createNotificationFromException($exception, $row, $flowpinQueryTypeId);
            continue;
        }
    }
    $lastEventID = max($eventId, $lastEventID);
    $updateEventId();

    // Create production processor and process production data directly
    // This replaces the CURL call to production-sku.php
    $productionProcessor = new SkuProductionProcessor($MsaDB, $FlowpinDB);
    $productionEventId = $productionProcessor->processAndExecuteProduction($producedSkuAndInter);
    $lastEventID = max($productionEventId, $lastEventID);

    $updateEventId();
    $MsaDB->db->commit();

    $locker->unlock();
}