<?php
namespace Atte\Utils;  

use Atte\DB\BaseDB;
use \PDO;

class Commission 
{
    private $MsaDB;
    public string $deviceType;
    public array $commissionValues;

    public function __construct(BaseDB $MsaDB){
        $this->MsaDB = $MsaDB;
    }

    public function updatePriority($priority){
        $MsaDB = $this -> MsaDB;
        $id = $this->commissionValues["id"];
        $MsaDB -> update('commission__list', ['priority' => $priority], "id", $id);
        $this->commissionValues["priority"] = $priority;
    }
    public function cancel(){
        $MsaDB = $this -> MsaDB;
        $id = $this->commissionValues["id"];
        if($this -> commissionValues["isCancelled"] == 1) throw new \Exception("This commission is already cancelled.");
        $MsaDB -> update('commission__list', ['isCancelled' => 1], "id", $id);
        $this->commissionValues["isCancelled"] = 1;
    }
    public function updateStateId($stateId){
        $MsaDB = $this -> MsaDB;
        $id = $this->commissionValues["id"];
        $now = date("Y-m-d H:i:s",time());
        $finished = $stateId == 3 ? $now : null;
        $MsaDB -> update('commission__list', ['state_id' => $stateId, 'timestamp_finished' => $finished], "id", $id);
        $this->commissionValues["state_id"] = $stateId;
        if(!is_null($finished)) $this->commissionValues["timestamp_finished"] = $finished;
        else unset($this->commissionValues["timestamp_finished"]);
    }
    public function updateStateIdAuto(){
        $quantity = $this->commissionValues["quantity"];
        $quantityProduced = $this->commissionValues["quantity_produced"];
        $quantityReturned = $this->commissionValues["quantity_returned"];
        $state_id = 1;
        if($quantity == $quantityProduced) $state_id = 2;
        if($quantity == $quantityReturned && $state_id == 2) $state_id = 3;
        $this -> updateStateId($state_id);
    }
    public function getReceivers(){
        $MsaDB = $this -> MsaDB;
        $id = $this->commissionValues["id"];
        $receivers = $MsaDB -> query("SELECT user_id 
                                            FROM commission__receivers 
                                            WHERE commission_id = $id", 
                                        PDO::FETCH_COLUMN);
        return $receivers;
    }
    public function updateReceivers($receivers){
        $MsaDB = $this -> MsaDB;
        $id = $this->commissionValues["id"];
        $MsaDB -> query("DELETE FROM commission__receivers
                            WHERE `commission_id` = $id");
        foreach($receivers as $receiver) {
            $MsaDB -> insert('commission__receivers', ['commission_id', 'user_id'], [$id, $receiver]);
        }
    }
    public function addToQuantity($amount) {
        $currentQty = $this->commissionValues['quantity'];
        $newQty = $currentQty + $amount;
        $id = $this->commissionValues['id'];
        $this->MsaDB->update('commission__list', ['quantity' => $newQty], 'id', $id);
        $this->commissionValues['quantity'] = $newQty;
        $this->updateStateIdAuto();
    }
}