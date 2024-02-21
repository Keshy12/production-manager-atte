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
        $laminate = $deviceType == 'smd' ? 'laminate_id as laminateId,' : ''; 
        $query = "SELECT id, 
                        {$deviceType}_id as deviceId, 
                        {$laminate} 
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

    private function getBomByValuesLaminate($deviceType, $deviceId, $laminateId, $version) {
        $MsaDB = $this -> MsaDB;
        $query = "SELECT id, 
                        {$deviceType}_id as deviceId, 
                        laminate_id as laminateId, 
                        version, 
                        isActive 
                    FROM bom__{$deviceType} 
                    WHERE {$deviceType}_id = $deviceId 
                    AND laminate_id = $laminateId 
                    AND version = '$version'";

        $result = $MsaDB -> query($query, \PDO::FETCH_CLASS, "Atte\\Utils\\Bom", [$MsaDB]);
        if(isset($result[0])) {
            $result[0] -> deviceType = $deviceType;
            return $result[0];
        } else {
            throw new \Exception("There is no bom with given values.", 9);
        }
    }

    private function getBomByValuesNoLaminate($deviceType, $deviceId, $version) {
        $MsaDB = $this -> MsaDB;
        $query = "SELECT id, 
                        {$deviceType}_id as deviceId, 
                        version, 
                        isActive 
                    FROM bom__{$deviceType} 
                    WHERE {$deviceType}_id = $deviceId 
                    AND version = '$version'";

        $result = $MsaDB -> query($query, \PDO::FETCH_CLASS, "Atte\\Utils\\Bom", [$MsaDB]);
        if(isset($result[0])) {
            $result[0] -> deviceType = $deviceType;
            return $result[0];
        } else {
            throw new \Exception("There is no bom with given values.", 9);
        }
    }


    public function __call($method, $arguments) {
        if($method == 'getBomByValues') {
            if(count($arguments) == 3) {
                return call_user_func_array(array($this,'getBomByValuesNoLaminate'), $arguments);
            }
            else if(count($arguments) == 4) {
                return call_user_func_array(array($this,'getBomByValuesLaminate'), $arguments);
            }
      }
    }
}