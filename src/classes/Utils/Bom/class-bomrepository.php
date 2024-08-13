<?php
namespace Atte\Utils;  

use Atte\DB\MsaDB;
use Atte\Utils\Bom;

class BomRepository {
    private $MsaDB;

    public function __construct(MsaDB $MsaDB){
        $this -> MsaDB = $MsaDB;
    }

    public function getBomById($deviceType, $id) {
        $MsaDB = $this -> MsaDB;
        $laminateCond = $deviceType == 'smd' ? 'laminate_id as laminateId,' : '';  
        $query = "SELECT id, 
                        {$deviceType}_id as deviceId, 
                        {$laminateCond} 
                        version, 
                        isActive 
                    FROM bom__{$deviceType} 
                    WHERE id = $id";

        $result = $MsaDB -> query($query, \PDO::FETCH_CLASS, "Atte\\Utils\\Bom", [$MsaDB]);
        if(isset($result[0])) {
            $result[0] -> deviceType = $deviceType;
            return $result[0];
        } else {
            throw new \Exception("There is no bom with given id and type({$id}, {$deviceType})", 9);
        }
    }

    /**
    * Get bom by values
    * Values are an array, where key is column name.
    * @param string $deviceType
    * @param array $values syntax: ['columnName' => valueToCheck]
    * @return ?object Object of class BOM on success, null otherwise.
    */
    public function getBomByValues($deviceType, $values)
    {
        $MsaDB = $this -> MsaDB;
        $laminateCond = $deviceType == 'smd' ? 'laminate_id as laminateId,' : ''; 
        $query = "SELECT id, 
                        {$deviceType}_id as deviceId, 
                        {$laminateCond} 
                        version, 
                        isActive 
                    FROM bom__{$deviceType}";

        $conditions = [];
        $params = [];

        foreach ($values as $column => $value) {
            $conditions[] = "{$column} <=> :{$column}";
            $params[":{$column}"] = $value;
        }
        
        $query .= " WHERE ".implode(' AND ', $conditions);
        $stmt = $MsaDB->db->prepare($query);
        
        foreach ($params as $param => $val) {
            $stmt->bindValue($param, $val);
        }
        
        $stmt->execute();
        $result = $stmt->fetchAll(\PDO::FETCH_CLASS, 'Atte\\Utils\\Bom', [$MsaDB]);
        foreach($result as $item) $item -> deviceType = $deviceType;
        return $result !== false ? $result : null;
    }
}