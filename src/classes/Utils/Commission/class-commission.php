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
        $MsaDB = $this->MsaDB;
        $id = $this->commissionValues["id"];
        $MsaDB->update('commission__list', ['priority' => $priority], "id", $id);
        $this->commissionValues["priority"] = $priority;
    }

    public function cancel(){
        $MsaDB = $this->MsaDB;
        $id = $this->commissionValues["id"];
        if($this->commissionValues["is_cancelled"] == 1) {
            throw new \Exception("This commission is already cancelled.");
        }
        $MsaDB->update('commission__list', ['is_cancelled' => 1, 'state' => 'cancelled'], "id", $id);
        $this->commissionValues["is_cancelled"] = 1;
        $this->commissionValues["state"] = 'cancelled';
    }

    public function updateState($state){
        $MsaDB = $this->MsaDB;
        $id = $this->commissionValues["id"];
        $MsaDB->update('commission__list', ['state' => $state], "id", $id);
        $this->commissionValues["state"] = $state;
    }

    public function updateStateAuto(){
        $quantity = $this->commissionValues["qty"];
        $quantityProduced = $this->commissionValues["qty_produced"];
        $quantityReturned = $this->commissionValues["qty_returned"];

        $state = 'active';
        if($quantity == $quantityProduced) $state = 'completed';
        if($quantity == $quantityReturned && $state == 'completed') $state = 'returned';

        $this->updateState($state);
    }

    public function getReceivers(){
        $MsaDB = $this->MsaDB;
        $id = $this->commissionValues["id"];
        $receivers = $MsaDB->query("SELECT user_id 
                                            FROM commission__receivers 
                                            WHERE commission_id = $id",
            PDO::FETCH_COLUMN);
        return $receivers;
    }

    public function updateReceivers($receivers){
        $MsaDB = $this->MsaDB;
        $id = $this->commissionValues["id"];
        $MsaDB->query("DELETE FROM commission__receivers
                            WHERE `commission_id` = $id");
        foreach($receivers as $receiver) {
            $MsaDB->insert('commission__receivers', ['commission_id', 'user_id'], [$id, $receiver]);
        }
    }

    public function addToQuantity($amount) {
        $currentQty = $this->commissionValues['qty'];
        $newQty = $currentQty + $amount;
        $id = $this->commissionValues['id'];
        $this->MsaDB->update('commission__list', ['qty' => $newQty], 'id', $id);
        $this->commissionValues['qty'] = $newQty;
        $this->updateStateAuto();
    }
}