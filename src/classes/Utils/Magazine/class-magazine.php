<?php
namespace Atte\Utils;  

use Atte\DB\MsaDB;
use Atte\Utils\{CommissionRepository, BomRepository};
use \PDO;


class Magazine {
    private $MsaDB;
    public int $id;
    public string $name;
    public int $typeId; 


    public function __construct(MsaDB $MsaDB){
        $this -> MsaDB = $MsaDB;
    }

    function getWarehouseQty($deviceType, $deviceId) {
        $query = "SELECT sum(quantity) as quantity
                    FROM `inventory__{$deviceType}`
                    WHERE {$deviceType}_id = $deviceId
                    AND sub_magazine_id = {$this->id}
                    GROUP BY {$deviceType}_id";
        $queryResult = $this -> MsaDB -> query($query);
        if(empty($queryResult)) return 0;
        if(count($queryResult) == 0) return 0;
        if(count($queryResult) > 1) throw new \Exception("Multiple rows found");
        return $queryResult[0]['quantity'];
    }

    public function getActiveCommissions(){
        $MsaDB = $this -> MsaDB;
        $id = $this->id;
        $result = [];
        $queryResult = $MsaDB -> query("SELECT id 
                                           FROM `commission__list` 
                                           WHERE magazine_to = $id
                                           AND isCancelled = 0
                                           AND state_id != 3
                                           ORDER BY priority DESC", 
                                           PDO::FETCH_COLUMN);
        if(empty($queryResult)) return $result;
        $commissionRepository = new CommissionRepository($MsaDB);
        foreach($queryResult as $commissionId)
        {
            $result[] = $commissionRepository -> getCommissionById($commissionId);
        }
        return $result;
    }

    
    //Get which components are reserved to be used for active commissions
    public function getComponentsReserved(){
        $activeCommissions = $this -> getActiveCommissions();
        $result = [
            'sku' => [],
            'tht'=> [],
            'smd'=> [],
            'parts'=> []
        ];
        $MsaDB = $this -> MsaDB;
        $bomRepository = new BomRepository($MsaDB);
        foreach($activeCommissions as $activeCommission)
        {
            $deviceType = $activeCommission -> deviceType;
            $commissionValues = $activeCommission -> commissionValues;
            //If state is not 1, skip the row, state 2 and 3 mean the production is complete
            if($commissionValues["state_id"] != 1) continue;
            $qty = $commissionValues["quantity"] - $commissionValues["quantity_produced"];
            $deviceId = $commissionValues["bom_".$deviceType."_id"];
            $bom = $bomRepository -> getBomById($deviceType, $deviceId);
            $components = $bom -> getComponents($qty);
            foreach($components as $component)
            {
                $componentType = $component['type'];
                $componentId = $component['componentId'];
                
                if(!isset($result[$componentType][$componentId])) {
                    $result[$componentType][$componentId]['quantity'] = 0;
                }

                $result[$componentType][$componentId]['quantity'] += $component['quantity'];
            }
        }
        return $result;
    }
}
