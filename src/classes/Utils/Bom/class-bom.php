<?php
namespace Atte\Utils;  

use Atte\DB\MsaDB;

class Bom {
    private $MsaDB;
    public string $deviceType;
    public int $id;
    public int $deviceId;
    public ?int $laminateId = null;
    public ?string $version = null; 
    public bool $isActive;


    public function __construct(MsaDB $MsaDB){
        $this -> MsaDB = $MsaDB;
    }

    public function getComponents($quantity) {
        $MsaDB = $this -> MsaDB;
        $id = $this -> id;
        $deviceType = $this -> deviceType;
        $components = $MsaDB -> query("SELECT sku_id, tht_id, smd_id, parts_id, quantity*{$quantity} as qty FROM bom__flat WHERE bom_{$deviceType}_id = '{$id}'");
        $result = array();
        foreach($components as $component){
            $type = "sku";
            $device_id = $component[0];
            if(!empty($component[1])){
                $type = "tht";
                $device_id = $component[1];
            }
            else if(!empty($component[2])){
                $type = "smd";
                $device_id = $component[2];
            }
            else if(!empty($component[3])){
                $type = "parts";
                $device_id = $component[3];
            }
            $result[] = ["type" => $type, "componentId" => $device_id, "quantity" => $component["qty"]];
        }
        return $result;
    }

    public function getNameAndDescription(){
        $MsaDB = $this -> MsaDB;
        $id = $this -> id;
        $deviceId = $this -> deviceId;
        $laminateId = $this -> laminateId;
        $deviceType = $this -> deviceType;
        $query = $MsaDB -> query("SELECT name, 
                                            description 
                                     FROM list__{$deviceType} 
                                     WHERE id = {$deviceId}");
        $this -> name = $query[0]['name'];
        $this -> description = $query[0]['description'];
        if($deviceType == 'smd')
        {
            $query = $MsaDB -> query("SELECT name
                                            FROM list__laminate 
                                            WHERE id = {$laminateId}");
            $this -> laminateName = $query[0]['name'];
        }
    }
}
