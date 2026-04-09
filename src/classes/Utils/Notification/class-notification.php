<?php

namespace Atte\Utils;

use Atte\DB\FlowpinDB;
use Atte\DB\MsaDB;
use Atte\Utils\Production\SkuProductionProcessor;
use Atte\Utils\UserRepository;
use Atte\Utils\NotificationRepository;
use Atte\Utils\TransferGroupManager;
use Atte\Utils\BomRepository;

class Notification {
    private $MsaDB;
    public $notificationValues;

    public function __construct(MsaDB $MsaDB, array $notificationValues){
        $this -> MsaDB = $MsaDB;
        $this -> notificationValues = $notificationValues;
    }

    public function getValuesToResolve() {
        $notificationId = $this -> notificationValues["id"];
        $query = "SELECT id, values_to_resolve, flowpin_query_type_id FROM `notification__queries_affected` 
                    WHERE notification_id = $notificationId";
        return $this -> MsaDB -> query($query, \PDO::FETCH_ASSOC);
    }

    public function addValuesToResolve($query, $exceptionValues, $flowpinQueryTypeId) {
        $notificationId = $this -> notificationValues["id"];
        return $this -> MsaDB -> insert(
            "notification__queries_affected",
            ["notification_id", "values_to_resolve", "exception_values_serialized", "flowpin_query_type_id"],
            [$notificationId, json_encode($query, JSON_UNESCAPED_UNICODE), $exceptionValues, $flowpinQueryTypeId]
        );
    }

    public function tryToResolveNotification($userId) {
        $isResolved = $this -> notificationValues["isResolved"] == 1;
        if($isResolved) throw new \Exception("Cannot resolve notification that is already resolved.");

        if (!$userId) {
            throw new \Exception("User ID is required to resolve notification.");
        }

        $transferGroupManager = new TransferGroupManager($this->MsaDB);
        $notificationId = $this->notificationValues["id"];

        if($this -> retryQueries($transferGroupManager, $notificationId)){
            $remainingQueries = $this->getValuesToResolve();
            if(empty($remainingQueries)) {
                $this -> resolveNotification();
                return true;
            }
        }

        return false;
    }

    public function groupValuesToResolveByFlowpinTypeId($valuesToResolve) {
        $result = [];
        foreach ($valuesToResolve as $valueToResolve) {
            $flowpinQueryTypeId = $valueToResolve["flowpin_query_type_id"];
            $values = [$valueToResolve["id"], json_decode($valueToResolve["values_to_resolve"], true)];
            $result[$flowpinQueryTypeId][] = $values;
        }
        return $result;
    }

    private function retryQueries($transferGroupManager, $notificationId) {
        $MsaDB = $this -> MsaDB;
        $valuesToResolve = $this -> getValuesToResolve();
        $valuesToResolveGroupedByFlowpinTypeId = $this -> groupValuesToResolveByFlowpinTypeId($valuesToResolve);
        $wasSuccessful = true;

        foreach($valuesToResolveGroupedByFlowpinTypeId as $flowpinQueryTypeId => $valuesToResolve) {
            switch($flowpinQueryTypeId) {
                case 1:
                    if(!$this -> resolveSKUProduction($MsaDB, $valuesToResolve, $transferGroupManager, $notificationId)) $wasSuccessful = false;
                    break;
                case 2:
                    if(!$this -> resolveSKUSold($MsaDB, $valuesToResolve, $transferGroupManager, $notificationId)) $wasSuccessful = false;
                    break;
                case 3:
                    if(!$this -> resolveSKUReturnal($MsaDB, $valuesToResolve, $transferGroupManager, $notificationId)) $wasSuccessful = false;
                    break;
                case 4:
                    if(!$this -> resolveSKUTransfer($MsaDB, $valuesToResolve, $transferGroupManager, $notificationId)) $wasSuccessful = false;
                    break;

                default:
                    throw new \Exception("Undefined flowpin query type id. Cannot try to resolve values from notification.");
                    break;
            }
        }
        return $wasSuccessful;
    }

    private function resolveSKUProduction($MsaDB, $valuesToResolve, $transferGroupManager, $notificationId) {
        $FlowpinDB = FlowpinDB::getInstance();
        $productionProcessor = new SkuProductionProcessor($MsaDB, $FlowpinDB);
        $notificationRepository = new NotificationRepository($MsaDB);
        $userRepository = new UserRepository($MsaDB);
        $transferGroupCache = [];

        foreach($valuesToResolve as $row) {
            $idToDel = $row[0];
            $rowData = $row[1];
            $data = [$row];

            $eventId = $rowData[0];
            $executionDate = $rowData[1];
            $userEmail = $rowData[2];
            $deviceId = $rowData[3];
            $productionDate = date('Y-m-d', strtotime($executionDate));

            $MsaDB->db->beginTransaction();
            try {
                $user = $userRepository->getUserByEmail($userEmail);
                $userId = $user->userId;

                $cacheKey = "{$notificationId}_{$userId}_{$deviceId}";
                if (!isset($transferGroupCache[$cacheKey])) {
                    $transferGroupCache[$cacheKey] = $transferGroupManager->createTransferGroup($userId, 'notification_resolve', [
                        'notification_id' => $notificationId
                    ]);
                }
                $transferGroupId = $transferGroupCache[$cacheKey];

                $queries = $productionProcessor->processProduction($data, $productionDate, $transferGroupId, null, $eventId);

                foreach($queries as $eventId => $queryList) {
                    foreach($queryList as $query) {
                        $MsaDB->query($query);
                    }
                }

                $MsaDB->deleteById("notification__queries_affected", $idToDel);
                $MsaDB->db->commit();
            } catch (\Throwable $e) {
                $MsaDB->db->rollBack();
                $createdNotification = $notificationRepository -> createNotificationFromException($e, $data, 1);
                if($createdNotification->notificationValues['action_needed_id'] !== 0) {
                    $MsaDB->deleteById("notification__queries_affected", $idToDel);
                    continue;
                }
                return false;
            }
        }

        return true;
    }

    private function resolveSKUSold($MsaDB, $valuesToResolve, $transferGroupManager, $notificationId) {
        $userRepository = new UserRepository($MsaDB);
        $bomRepository = new BomRepository($MsaDB);
        $wasSuccessful = true;
        $notificationRepository = new NotificationRepository($MsaDB);
        $transferGroupCache = [];

        foreach ($valuesToResolve as $row) {
            $rowId = $row[0];
            $data = $row[1];

            try {
                if (count($data) < 5) {
                    throw new \InvalidArgumentException("Insufficient data elements for SKU Sold - expected at least 5, got " . count($data) . ": " . print_r($data, true));
                }
                list($eventId, $executionDate, $userEmail, $deviceId, $qty) = $data;
                $user = $userRepository->getUserByEmail($userEmail);
                $userId = $user->userId;

                $productionDate = date('Y-m-d', strtotime($executionDate));

                $cacheKey = "{$notificationId}_{$userId}_{$deviceId}";
                if (!isset($transferGroupCache[$cacheKey])) {
                    $transferGroupCache[$cacheKey] = $transferGroupManager->createTransferGroup($userId, 'notification_resolve', [
                        'notification_id' => $notificationId
                    ]);
                }
                $transferGroupId = $transferGroupCache[$cacheKey];

                $bomId = null;
                $bomsFound = $bomRepository->getBomByValues('sku', ['sku_id' => (int)$deviceId, 'version' => null]);
                if (!empty($bomsFound)) {
                    $bomId = $bomsFound[0]->id;
                }

                $comment = "Finalizacja zamówienia, spakowano do wysyłki, EventId: " . $eventId;
                $columns = ["sku_id", "sub_magazine_id", "qty", "timestamp", "production_date", "input_type_id", "comment", "transfer_group_id", "sku_bom_id", "flowpin_event_id"];
                $values = [$deviceId, "0", $qty, $executionDate, $productionDate, "9", $comment, $transferGroupId, $bomId, $eventId];
                $MsaDB->insert("inventory__sku", $columns, $values);
                $MsaDB->deleteById("notification__queries_affected", $rowId);
            } catch (\Throwable $exception) {
                $createdNotification = $notificationRepository -> createNotificationFromException($exception, $data, 2);
                if($createdNotification->notificationValues['action_needed_id'] !== 0) {
                    $MsaDB->deleteById("notification__queries_affected", $rowId);
                    continue;
                }
                $wasSuccessful = false;
            }
        }
        return $wasSuccessful;
    }

    private function resolveSKUReturnal($MsaDB, $valuesToResolve, $transferGroupManager, $notificationId) {
        $userRepository = new UserRepository($MsaDB);
        $bomRepository = new BomRepository($MsaDB);
        $wasSuccessful = true;
        $notificationRepository = new NotificationRepository($MsaDB);
        $transferGroupCache = [];

        foreach ($valuesToResolve as $row) {
            try {
                $rowId = $row[0];
                $data = $row[1];

                if (count($data) < 5) {
                    throw new \InvalidArgumentException("Insufficient data elements for SKU Returnal - expected at least 5, got " . count($data) . ": " . print_r($data, true));
                }

                list($eventId, $executionDate, $userEmail, $deviceId, $qty) = $data;
                $user = $userRepository->getUserByEmail($userEmail);
                $userId = $user->userId;

                $productionDate = date('Y-m-d', strtotime($executionDate));

                $cacheKey = "{$notificationId}_{$userId}_{$deviceId}";
                if (!isset($transferGroupCache[$cacheKey])) {
                    $transferGroupCache[$cacheKey] = $transferGroupManager->createTransferGroup($userId, 'notification_resolve', [
                        'notification_id' => $notificationId
                    ]);
                }
                $transferGroupId = $transferGroupCache[$cacheKey];

                $bomId = null;
                $bomsFound = $bomRepository->getBomByValues('sku', ['sku_id' => (int)$deviceId, 'version' => null]);
                if (!empty($bomsFound)) {
                    $bomId = $bomsFound[0]->id;
                }

                $comment = "Zwrot SKU od klienta, EventId: " . $eventId;
                $columns = ["sku_id", "sub_magazine_id", "qty", "timestamp", "production_date", "input_type_id", "comment", "transfer_group_id", "sku_bom_id", "flowpin_event_id"];
                $values = [$deviceId, "0", $qty, $executionDate, $productionDate, "10", $comment, $transferGroupId, $bomId, $eventId];
                $MsaDB->insert("inventory__sku", $columns, $values);
                $MsaDB->deleteById("notification__queries_affected", $rowId);
            } catch (\Throwable $exception) {
                $createdNotification = $notificationRepository->createNotificationFromException($exception, $data ?? [], 3);
                if($createdNotification->notificationValues['action_needed_id'] !== 0) {
                    $MsaDB->deleteById("notification__queries_affected", $rowId);
                    continue;
                }
                $wasSuccessful = false;
            }
        }
        return $wasSuccessful;
    }

    private function resolveSKUTransfer($MsaDB, $valuesToResolve, $transferGroupManager, $notificationId) {
        $userRepository = new UserRepository($MsaDB);
        $bomRepository = new BomRepository($MsaDB);
        $wasSuccessful = true;
        $notificationRepository = new NotificationRepository($MsaDB);
        $transferGroupCache = [];

        foreach ($valuesToResolve as $row) {
            try {
                $rowId = $row[0];
                $data = $row[1];

                if (count($data) < 8) {
                    throw new \InvalidArgumentException("Insufficient data elements for SKU Transfer - expected at least 8, got " . count($data) . ": " . print_r($data, true));
                }

                list($eventId, $executionDate, $userEmail, $deviceId, $warehouseOut, $qtyOut, $warehouseIn, $qtyIn) = $data;
                $user = $userRepository->getUserByEmail($userEmail);
                $userId = $user->userId;

                $productionDate = date('Y-m-d', strtotime($executionDate));

                $cacheKey = "{$notificationId}_{$userId}_{$deviceId}";
                if (!isset($transferGroupCache[$cacheKey])) {
                    $transferGroupCache[$cacheKey] = $transferGroupManager->createTransferGroup($userId, 'notification_resolve', [
                        'notification_id' => $notificationId
                    ]);
                }
                $transferGroupId = $transferGroupCache[$cacheKey];

                $bomId = null;
                $bomsFound = $bomRepository->getBomByValues('sku', ['sku_id' => (int)$deviceId, 'version' => null]);
                if (!empty($bomsFound)) {
                    $bomId = $bomsFound[0]->id;
                }

                $comment = "Przesunięcie między magazynowe, EventId: " . $eventId;

                $columns = ["sku_id", "sub_magazine_id", "qty", "timestamp", "production_date", "input_type_id", "comment", "transfer_group_id", "sku_bom_id", "flowpin_event_id"];

                if ($warehouseOut == 3 || $warehouseOut == 4) {
                    $values = [$deviceId, "0", $qtyOut, $executionDate, $productionDate, "2", $comment, $transferGroupId, $bomId, $eventId];
                    $MsaDB->insert("inventory__sku", $columns, $values);
                }

                if ($warehouseIn == 3 || $warehouseIn == 4) {
                    $values = [$deviceId, "0", $qtyIn, $executionDate, $productionDate, "2", $comment, $transferGroupId, $bomId, $eventId];
                    $MsaDB->insert("inventory__sku", $columns, $values);
                }

                $MsaDB->deleteById("notification__queries_affected", $rowId);

            } catch (\Throwable $exception) {
                $createdNotification = $notificationRepository->createNotificationFromException($exception, $data ?? [], 4);
                if($createdNotification->notificationValues['action_needed_id'] !== 0) {
                    $MsaDB->deleteById("notification__queries_affected", $rowId);
                    continue;
                }
                $wasSuccessful = false;
            }
        }
        return $wasSuccessful;
    }

    private function resolveNotification(){
        $MsaDB = $this -> MsaDB;
        $id = $this -> notificationValues["id"];
        $MsaDB -> update("notification__list", ["isResolved" => 1], "id", $id);
    }

    public function returnDropdownItem(){
        $MsaDB = $this -> MsaDB;
        $alerts = ["alert-danger", "alert-info", "alert-warning", "alert-secondary", "alert-secondary", "alert-secondary"];
        $notificationValues = $this -> notificationValues;
        $id = $notificationValues["id"];
        $link = "http://".BASEURL."/notification?id=$id";
        $valueForAction = $notificationValues["value_for_action"];
        $actionNeededId = $notificationValues["action_needed_id"];
        $timestamp = $notificationValues["timestamp"];
        $alert = $alerts[$actionNeededId];

        if ($actionNeededId == 1) {
            $skuQuery = "SELECT name FROM list__sku WHERE id = " . intval($valueForAction);
            $skuResult = $MsaDB->query($skuQuery, \PDO::FETCH_COLUMN);
            if (!empty($skuResult)) {
                $valueForAction = $skuResult[0];
            }
        }

        $message = $MsaDB -> query("SELECT description FROM notification__action_needed WHERE id = $actionNeededId", \PDO::FETCH_COLUMN)[0];
        return "<a class='dropdown-item $alert text-truncate mt-1' href='$link'><b>$valueForAction</b><span class='float-right'><small>$timestamp</small></span>
                <br>$message</a>";
    }
}