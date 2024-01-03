<?php

namespace Atte\Utils;  

require_once(realpath(dirname(__FILE__) . '/class-userrepository.php'));

use Atte\DB\MsaDB;
use Atte\Utils\UserRepository;

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
        };
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
        $database = $this -> MsaDB;
        $valuesToResolve = $this -> getValuesToResolve();
        $valuesToResolveGroupedByFlowpinTypeId = $this -> groupValuesToResolveByFlowpinTypeId($valuesToResolve);
        $wasSuccessful = true;
        foreach($valuesToResolveGroupedByFlowpinTypeId as $flowpinQueryTypeId => $valuesToResolve) {
            switch($flowpinQueryTypeId) {
                case 1:
                    if(!$this -> resolveSKUProduction($database, $valuesToResolve)) $wasSuccessful = false;
                    break;
                case 2:
                    if(!$this -> resolveSKUSold($database, $valuesToResolve)) $wasSuccessful = false;
                    break;
                case 3:
                    if(!$this -> resolveSKUReturnal($database, $valuesToResolve)) $wasSuccessful = false;
                    break;
                case 4:
                    if(!$this -> resolveSKUTransfer($database, $valuesToResolve)) $wasSuccessful = false;
                    break;

                default:
                    throw new \Exception("Undefined flowpin query type id. Cannot try to resolve values from notification.");
                    break;
            }
        }
        return $wasSuccessful;
    }

    private function resolveSKUProduction($database, $valuesToResolve){ 
        $valuesChunked = array_chunk($valuesToResolve, 2500);
        $url = "http://".BASEURL."/atte_ms/production-sku.php";
        $wasSuccessful = true;
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        foreach($valuesChunked as $item){
            $result = [
                'status' => NULL,
                'last_url' => NULL,
                'response' => NULL
            ];
            $data = ["production" => json_encode($item, JSON_UNESCAPED_UNICODE)];
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
            $result['response'] = curl_exec($ch);
            $result['status'] = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $result['last_url'] = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
            $queries = json_decode($result["response"], true);
            if(empty($queries)) {
                echo "Błąd.<br>";
                var_dump($result);
                $wasSuccessful = false;
                return false;
            }
            foreach($queries as $idToDel => $query){
                $database -> db -> beginTransaction();
                try {
                    foreach($query as $item) {
                        $database -> query($item);
                    }
                    $database -> deleteById("notification__queries_affected",$idToDel);
                    $database -> db -> commit();
                }
                catch (\Throwable $e) {
                    $database -> db -> rollBack();
                    $wasSuccessful = false;
                }
            }
        }
        curl_close ($ch);
        return $wasSuccessful;
    }

    private function resolveSKUSold($database, $valuesToResolve){ 
        $userRepository = new UserRepository($database);
        $wasSuccessful = true;
        foreach($valuesToResolve as $row)
        {
            $rowId = $row[0];
            list($eventId, $executionDate, $userEmail, $deviceId, $qty) = $row[1];
            try { 
                $userRepository = new UserRepository($database);
                $user = $userRepository -> getUserByEmail($userEmail);
                $userId = $user -> userId;
            }
            catch (\Throwable $exception) {
                $wasSuccessful = false;
            }
            $comment = "Finalizacja zamówienia, spakowano do wysyłki, EventId: ".$eventId;
            $columns = ["sku_id", "user_id", "sub_magazine_id", "quantity", "timestamp", "input_type_id", "comment"];
            $values = [$deviceId, $userId, "0", $qty, $executionDate, "9", $comment];
            $database -> insert("inventory__sku", $columns, $values);
            $database -> deleteById("notification__queries_affected", $rowId);
        }
        return $wasSuccessful;
    }

    private function resolveSKUReturnal($database, $valuesToResolve){ 
        $userRepository = new UserRepository($database);
        $wasSuccessful = true;
        foreach($valuesToResolve as $row)
        {
            $rowId = $row[0];
            list($eventId, $executionDate, $userEmail, $deviceId, $qty) = $row[1];
            try { 
                $user = $userRepository -> getUserByEmail($userEmail);
                $userId = $user -> userId;
            }
            catch (\Throwable $exception) {
                $wasSuccessful = false;
            }
            $comment = "Zwrot SKU od klienta, EventId: ".$eventId;
            $columns = ["sku_id", "user_id", "sub_magazine_id", "quantity", "timestamp", "input_type_id", "comment"];
            $values = [$deviceId, $userId, "0", $qty, $executionDate, "10", $comment];
            $database -> insert("inventory__sku", $columns, $values);
            $database -> deleteById("notification__queries_affected", $rowId);
        }
        return $wasSuccessful;
    }
    private function resolveSKUTransfer($database, $valuesToResolve){ 
        $userRepository = new UserRepository($database);
        $wasSuccessful = true;
        foreach($valuesToResolve as $row)
        {
            $rowId = $row[0];
            list($eventId, $executionDate, $userEmail, $deviceId, $warehouseOut, $qtyOut, $warehouseIn, $qtyIn) = $row[1];
            try { 
                $user = $userRepository -> getUserByEmail($userEmail);
                $userId = $user -> userId;
            }
            catch (\Throwable $exception) {
                $wasSuccessful = false;
            }
            $comment = "Przesunięcie między magazynowe, EventId: ".$eventId;
            if($warehouseOut == 3 || $warehouseOut == 4) { 
                $columns = ["sku_id", "user_id", "sub_magazine_id", "quantity", "timestamp", "input_type_id", "comment"];
                $values = [$deviceId, $userId, "0", $qtyOut, $executionDate, "2", $comment];
                $database -> insert("inventory__sku", $columns, $values);
            }
            if($warehouseIn == 3 || $warehouseIn  == 4) { 
                $columns = ["sku_id", "user_id", "sub_magazine_id", "quantity", "timestamp", "input_type_id", "comment"];
                $values = [$deviceId, $userId, "0", $qtyIn, $executionDate, "2", $comment];
                $database -> insert("inventory__sku", $columns, $values);
            }
            $database -> deleteById("notification__queries_affected", $rowId);
        }
        return $wasSuccessful;
    }
    
    private function resolveNotification(){
        $database = $this -> MsaDB;
        $id = $this -> notificationValues["id"];
        $database -> update("notification__list", ["isResolved" => 1], "id", $id);
    }
    
    public function returnDropdownItem(){
        $database = $this -> MsaDB;
        $alerts = ["alert-danger", "alert-info", "alert-warning"];
        $notificationValues = $this -> notificationValues;
        $id = $notificationValues["id"];
        $link = "http://".BASEURL."/atte_ms/views/notification_page.php?id=$id";
        $valueForAction = $notificationValues["value_for_action"];
        $actionNeededId = $notificationValues["action_needed_id"];
        $timestamp = $notificationValues["timestamp"];
        $alert = $alerts[$actionNeededId];
        $message = $database -> query("SELECT description FROM notification__action_needed WHERE id = $actionNeededId", \PDO::FETCH_COLUMN)[0];
        return "<a class='dropdown-item $alert text-truncate mt-1' href='$link'><b>$valueForAction</b><span class='float-right'><small>$timestamp</small></span>
        <br>$message</a>";
        
    }
}
