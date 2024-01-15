<?php
namespace Atte\Utils;  

use Atte\DB\MsaDB;
use Atte\Utils\Commission;

class CommissionRepository {
    private $MsaDB;

    public function __construct(MsaDB $MsaDB){
        $this -> MsaDB = $MsaDB;
    }

    /**
    * Get Commission class by id from DB.
    * @param int $id Id of commission.
    * @return Commission Commission class
    */
    public function getCommissionById($id) {
        $database = $this -> MsaDB;
        $query = "SELECT * FROM `commission__list` WHERE `id` = $id";
        $queryResult = $database -> query($query, \PDO::FETCH_ASSOC);
        if(isset($queryResult[0])) {
            $row = $queryResult[0];
            $row = array_filter($row, fn ($value) => !is_null($value));
            $type = null;
            $type = isset($row["bom_sku_id"]) ? "sku" : $type;
            $type = isset($row["bom_tht_id"]) ? "tht" : $type;
            $type = isset($row["bom_smd_id"]) ? "smd" : $type;
            $row["deviceBomId"] = $row["bom_{$type}_id"];
            $commission = new Commission($database);
            $commission -> deviceType = $type;
            $commission -> commissionValues = $row;
            return $commission;
        } else {
            throw new \Exception("There is no commission with given id($id)");
        }
    }

}
