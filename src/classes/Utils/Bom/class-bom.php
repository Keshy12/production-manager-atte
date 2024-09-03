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
        $components = $MsaDB -> query("SELECT id, sku_id, tht_id, smd_id, parts_id, quantity*{$quantity} as qty FROM bom__flat WHERE bom_{$deviceType}_id = '{$id}'");
        $result = array();
        foreach($components as $component){
            $rowId = $component[0];
            $type = "sku";
            $device_id = $component[1];
            if(!empty($component[2])){
                $type = "tht";
                $device_id = $component[2];
            }
            else if(!empty($component[3])){
                $type = "smd";
                $device_id = $component[3];
            }
            else if(!empty($component[4])){
                $type = "parts";
                $device_id = $component[4];
            }
            $result[] = ["rowId" => $rowId, "type" => $type, "componentId" => $device_id, "quantity" => $component["qty"]+0];
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
