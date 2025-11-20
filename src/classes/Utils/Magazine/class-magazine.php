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
        $query = "SELECT sum(qty) as qty
                    FROM `inventory__{$deviceType}`
                    WHERE {$deviceType}_id = $deviceId
                    AND sub_magazine_id = {$this->id}
                    GROUP BY {$deviceType}_id";
        $queryResult = $this -> MsaDB -> query($query);
        if(empty($queryResult)) return 0;
        if(count($queryResult) == 0) return 0;
        if(count($queryResult) > 1) throw new \Exception("Multiple rows found");
        return $queryResult[0]['qty'];
    }

    public function getActiveCommissions(){
        $MsaDB = $this -> MsaDB;
        $id = $this->id;
        $result = [];
        $queryResult = $MsaDB -> query("SELECT id 
                                           FROM `commission__list` 
                                           WHERE warehouse_to_id = $id
                                           AND is_cancelled = 0
                                           AND state != 'returned'
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
            $commissionValues = $activeCommission -> commissionValues;

            // Skip if not active
            if($commissionValues["state"] != "active") continue;

            // Get device type from commission
            $deviceType = $commissionValues["device_type"]; // NEW: use device_type column

            // Calculate remaining quantity
            $qty = $commissionValues["qty"] - $commissionValues["qty_produced"];

            // Skip if no remaining quantity
            if($qty <= 0) continue;

            // Get BOM ID directly (not bom_{type}_id)
            $bomId = $commissionValues["bom_id"]; // NEW: use bom_id column directly

            // Validate BOM ID exists
            if(empty($bomId)) {
                error_log("Warning: Commission {$commissionValues['id']} has no BOM ID");
                continue;
            }

            // Get BOM
            try {
                $bom = $bomRepository -> getBomById($deviceType, $bomId);
            } catch (\Exception $e) {
                error_log("Error getting BOM for commission {$commissionValues['id']}: " . $e->getMessage());
                continue;
            }

            // Get components needed for this commission
            $components = $bom -> getComponents($qty);

            foreach($components as $component)
            {
                $componentType = $component['type'];
                $componentId = $component['componentId'];

                if(!isset($result[$componentType][$componentId])) {
                    $result[$componentType][$componentId] = ['qty' => 0];
                }

                $result[$componentType][$componentId]['qty'] += $component['quantity'];
            }
        }

        return $result;
    }
}
