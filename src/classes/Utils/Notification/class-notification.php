<?php

namespace Atte\Utils;

use Atte\DB\FlowpinDB;
use Atte\DB\MsaDB;
use Atte\Utils\Production\SkuProductionProcessor;
use Atte\Utils\UserRepository;
use Atte\Utils\NotificationRepository;

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

    public function tryToResolveNotification() {
        $isResolved = $this -> notificationValues["isResolved"] == 1;
        if($isResolved) throw new \Exception("Cannot resolve notification that is already resolved.");
        if($this -> retryQueries()){
            $this -> resolveNotification();
            return true;
        }
        else {
            return false;
        }
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

    private function retryQueries() {
        $MsaDB = $this -> MsaDB;
        $valuesToResolve = $this -> getValuesToResolve();
        $valuesToResolveGroupedByFlowpinTypeId = $this -> groupValuesToResolveByFlowpinTypeId($valuesToResolve);
        $wasSuccessful = true;
        foreach($valuesToResolveGroupedByFlowpinTypeId as $flowpinQueryTypeId => $valuesToResolve) {
            switch($flowpinQueryTypeId) {
                case 1:
                    if(!$this -> resolveSKUProduction($MsaDB, $valuesToResolve)) $wasSuccessful = false;
                    break;
                case 2:
                    if(!$this -> resolveSKUSold($MsaDB, $valuesToResolve)) $wasSuccessful = false;
                    break;
                case 3:
                    if(!$this -> resolveSKUReturnal($MsaDB, $valuesToResolve)) $wasSuccessful = false;
                    break;
                case 4:
                    if(!$this -> resolveSKUTransfer($MsaDB, $valuesToResolve)) $wasSuccessful = false;
                    break;

                default:
                    throw new \Exception("Undefined flowpin query type id. Cannot try to resolve values from notification.");
                    break;
            }
        }
        return $wasSuccessful;
    }

    private function resolveSKUProduction($MsaDB, $valuesToResolve) {
        $FlowpinDB = FlowpinDB::getInstance();
        $productionProcessor = new SkuProductionProcessor($MsaDB, $FlowpinDB);
        $notificationRepository = new NotificationRepository($MsaDB);
        foreach($valuesToResolve as $row) {
            $idToDel = $row[0];
            $data = [$row];

            $MsaDB->db->beginTransaction();
            try {
                $queries = $productionProcessor->processProduction($data);

                foreach($queries as $eventId => $queryList) {
                    foreach($queryList as $query) {
                        $MsaDB->query($query);
                    }
                }

                $MsaDB->deleteById("notification__queries_affected", $idToDel);
                $MsaDB->db->commit();
            } catch (\Throwable $e) {
                $createdNotification = $notificationRepository -> createNotificationFromException($e, $data, 1);
                if($createdNotification->notificationValues['action_needed_id'] !== 0) {
                    $MsaDB->deleteById("notification__queries_affected", $idToDel);
                    continue;
                }
                $MsaDB->db->rollBack();
                return false;
            }
        }

        return true;
    }
    private function resolveSKUSold($MsaDB, $valuesToResolve) {
        $userRepository = new UserRepository($MsaDB);
        $wasSuccessful = true;
        $notificationRepository = new NotificationRepository($MsaDB);
        foreach ($valuesToResolve as $row) {
            $rowId = $row[0];
            $data = $row[1];
            try {
                list($eventId, $executionDate, $userEmail, $deviceId, $qty) = $data;
                $user = $userRepository->getUserByEmail($userEmail);
                $userId = $user->userId;

                $comment = "Finalizacja zamówienia, spakowano do wysyłki, EventId: " . $eventId;
                $columns = ["sku_id", "user_id", "sub_magazine_id", "quantity", "timestamp", "input_type_id", "comment"];
                $values = [$deviceId, $userId, "0", $qty, $executionDate, "9", $comment];
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

    private function resolveSKUReturnal($MsaDB, $valuesToResolve) {
        $userRepository = new UserRepository($MsaDB);
        $wasSuccessful = true;
        $notificationRepository = new NotificationRepository($MsaDB);
        foreach ($valuesToResolve as $row) {
            $rowId = $row[0];
            $data = $row[1];
            try {
                list($eventId, $executionDate, $userEmail, $deviceId, $qty) = $data;
                $user = $userRepository->getUserByEmail($userEmail);
                $userId = $user->userId;

                $comment = "Zwrot SKU od klienta, EventId: " . $eventId;
                $columns = ["sku_id", "user_id", "sub_magazine_id", "quantity", "timestamp", "input_type_id", "comment"];
                $values = [$deviceId, $userId, "0", $qty, $executionDate, "10", $comment];
                $MsaDB->insert("inventory__sku", $columns, $values);
                $MsaDB->deleteById("notification__queries_affected", $rowId);
            } catch (\Throwable $exception) {
                $createdNotification = $notificationRepository -> createNotificationFromException($exception, $data, 3);
                if($createdNotification->notificationValues['action_needed_id'] !== 0) {
                    $MsaDB->deleteById("notification__queries_affected", $rowId);
                    continue;
                }
                $wasSuccessful = false;
            }
        }
        return $wasSuccessful;
    }

    private function resolveSKUTransfer($MsaDB, $valuesToResolve) {
        $userRepository = new UserRepository($MsaDB);
        $wasSuccessful = true;
        $notificationRepository = new NotificationRepository($MsaDB);
        foreach ($valuesToResolve as $row) {
            $rowId = $row[0];
            $data = $row[1];
            try {
                list($eventId, $executionDate, $userEmail, $deviceId, $warehouseOut, $qtyOut, $warehouseIn, $qtyIn) = $data;
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
                $MsaDB->deleteById("notification__queries_affected", $rowId);
            } catch (\Throwable $exception) {
                $createdNotification = $notificationRepository -> createNotificationFromException($exception, $data, 4);
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
        $alerts = ["alert-danger", "alert-info", "alert-warning"];
        $notificationValues = $this -> notificationValues;
        $id = $notificationValues["id"];
        $link = "http://".BASEURL."/notification?id=$id";
        $valueForAction = $notificationValues["value_for_action"];
        $actionNeededId = $notificationValues["action_needed_id"];
        $timestamp = $notificationValues["timestamp"];
        $alert = $alerts[$actionNeededId];
        $message = $MsaDB -> query("SELECT description FROM notification__action_needed WHERE id = $actionNeededId", \PDO::FETCH_COLUMN)[0];
        return "<a class='dropdown-item $alert text-truncate mt-1' href='$link'><b>$valueForAction</b><span class='float-right'><small>$timestamp</small></span>
        <br>$message</a>";
        
    }
}
