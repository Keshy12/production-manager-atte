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

    public function rollbackItems($rollbackOption, $MsaDB) {
        $commissionId = $this->commissionValues["id"];
        $deviceType = $this->deviceType;
        $warehouseFromId = $this->commissionValues['warehouse_from_id'];
        $warehouseToId = $this->commissionValues['warehouse_to_id'];

        // Build WHERE clause based on rollback option
        $whereClause = "commission_id = $commissionId";
        if ($rollbackOption === 'remaining') {
            $quantityReturned = $this->commissionValues['quantity_returned'];
            $whereClause .= " AND id NOT IN (
                SELECT DISTINCT inventory_id FROM (
                    SELECT id as inventory_id, 
                           ROW_NUMBER() OVER (ORDER BY timestamp ASC) as rn
                    FROM inventory__{$deviceType} 
                    WHERE commission_id = $commissionId 
                      AND sub_magazine_id = $warehouseToId 
                      AND quantity > 0
                ) ranked 
                WHERE rn <= $quantityReturned
            )";
        }

        // Get items to rollback
        $itemsToRollback = $MsaDB->query("
            SELECT id, quantity, user_id, production_date, comment, input_type_id,
                   {$deviceType}_id
            FROM inventory__{$deviceType} 
            WHERE $whereClause 
              AND sub_magazine_id = $warehouseToId 
              AND quantity > 0
        ");

        // Create rollback entries
        foreach ($itemsToRollback as $item) {
            // Remove from destination magazine
            $MsaDB->insert("inventory__{$deviceType}", [
                $deviceType . '_id',
                'commission_id',
                'user_id',
                'sub_magazine_id',
                'qty',
                'production_date',
                'input_type_id',
                'comment'
            ], [
                $item[$deviceType . '_id'],
                $commissionId,
                $item['user_id'],
                $warehouseToId,
                -$item['qty'],
                $item['production_date'],
                $item['input_type_id'],
                $item['comment'] . " (Rollback - anulacja zlecenia)"
            ]);

            // Add back to source magazine
            $MsaDB->insert("inventory__{$deviceType}", [
                $deviceType . '_id',
                'commission_id',
                'user_id',
                'sub_magazine_id',
                'quantity',
                'production_date',
                'input_type_id',
                'comment'
            ], [
                $item[$deviceType . '_id'],
                $commissionId,
                $item['user_id'],
                $warehouseFromId,
                $item['quantity'],
                $item['production_date'],
                $item['input_type_id'],
                $item['comment'] . " (Rollback - anulacja zlecenia)"
            ]);
        }
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