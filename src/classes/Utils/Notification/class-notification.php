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

    private function resolveSKUProduction($MsaDB, $valuesToResolve){ 
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
                $MsaDB -> db -> beginTransaction();
                try {
                    foreach($query as $item) {
                        $MsaDB -> query($item);
                    }
                    $MsaDB -> deleteById("notification__queries_affected",$idToDel);
                    $MsaDB -> db -> commit();
                }
                catch (\Throwable $e) {
                    $MsaDB -> db -> rollBack();
                    $wasSuccessful = false;
                }
            }
        }
        curl_close ($ch);
        return $wasSuccessful;
    }

    private function resolveSKUSold($MsaDB, $valuesToResolve){ 
        $userRepository = new UserRepository($MsaDB);
        $wasSuccessful = true;
        foreach($valuesToResolve as $row)
        {
            $rowId = $row[0];
            list($eventId, $executionDate, $userEmail, $deviceId, $qty) = $row[1];
            try { 
                $userRepository = new UserRepository($MsaDB);
                $user = $userRepository -> getUserByEmail($userEmail);
                $userId = $user -> userId;
            }
            catch (\Throwable $exception) {
                $wasSuccessful = false;
            }
            $comment = "Finalizacja zamówienia, spakowano do wysyłki, EventId: ".$eventId;
            $columns = ["sku_id", "user_id", "sub_magazine_id", "quantity", "timestamp", "input_type_id", "comment"];
            $values = [$deviceId, $userId, "0", $qty, $executionDate, "9", $comment];
            $MsaDB -> insert("inventory__sku", $columns, $values);
            $MsaDB -> deleteById("notification__queries_affected", $rowId);
        }
        return $wasSuccessful;
    }

    private function resolveSKUReturnal($MsaDB, $valuesToResolve){ 
        $userRepository = new UserRepository($MsaDB);
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
            $MsaDB -> insert("inventory__sku", $columns, $values);
            $MsaDB -> deleteById("notification__queries_affected", $rowId);
        }
        return $wasSuccessful;
    }
    private function resolveSKUTransfer($MsaDB, $valuesToResolve){ 
        $userRepository = new UserRepository($MsaDB);
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
                $MsaDB -> insert("inventory__sku", $columns, $values);
            }
            if($warehouseIn == 3 || $warehouseIn  == 4) { 
                $columns = ["sku_id", "user_id", "sub_magazine_id", "quantity", "timestamp", "input_type_id", "comment"];
                $values = [$deviceId, $userId, "0", $qtyIn, $executionDate, "2", $comment];
                $MsaDB -> insert("inventory__sku", $columns, $values);
            }
            $MsaDB -> deleteById("notification__queries_affected", $rowId);
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
        $link = "http://".BASEURL."/atte_ms/views/notification_page.php?id=$id";
        $valueForAction = $notificationValues["value_for_action"];
        $actionNeededId = $notificationValues["action_needed_id"];
        $timestamp = $notificationValues["timestamp"];
        $alert = $alerts[$actionNeededId];
        $message = $MsaDB -> query("SELECT description FROM notification__action_needed WHERE id = $actionNeededId", \PDO::FETCH_COLUMN)[0];
        return "<a class='dropdown-item $alert text-truncate mt-1' href='$link'><b>$valueForAction</b><span class='float-right'><small>$timestamp</small></span>
        <br>$message</a>";
        
    }
}
