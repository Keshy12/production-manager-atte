<?php
namespace Atte\Utils;  

use Atte\DB\MsaDB;
use Atte\Utils\CommissionRepository;
use Atte\Utils\BomRepository;
use \PDO;


class Magazine {
    private $MsaDB;
    public int $id;
    public string $name;
    public int $typeId; 


    public function __construct(MsaDB $MsaDB){
        $this -> database = $MsaDB;
    }

    public function getActiveCommissions(){
        $MsaDB = $this -> database;
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
        $result = array();
        $MsaDB = $this -> database;
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
                $foundDeviceId = array_keys(array_column($result, 1), $component['componentId']);
                foreach($foundDeviceId as $deviceId)
                {
                    if($result[$deviceId][0] == $component['type']){
                        $result[$deviceId][2] += $component['quantity'];
                    } 
                    else{
                        $result[] = $component;
                    }
                }
                if(empty($foundDeviceId))
                {
                    $result[] = $component;
                }
            }
        }
        return $result;
    }
}
