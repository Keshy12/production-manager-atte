<?php
namespace Atte\Utils;  

use Atte\DB\MsaDB;

class Bom {
    private $MsaDB;
    public string $deviceType;
    public int $id;
    public int $deviceId;
    public ?int $laminateId = null;
    public ?int $out_tht_quantity = null;
    public ?string $version = null; 
    public bool $isActive;
    public string $name;
    public string $description;
    public ?string $laminateName = null;



    public function __construct(MsaDB $MsaDB){
        $this -> MsaDB = $MsaDB;
    }

    public function getComponents($quantity) {
        $MsaDB = $this -> MsaDB;
        $id = $this -> id;
        $deviceType = $this -> deviceType;
        $components = $MsaDB -> query("SELECT 
                                                b.id, 
                                                b.sku_id, 
                                                b.tht_id, 
                                                b.smd_id, 
                                                b.parts_id, 
                                                 b.quantity * {$quantity} AS qty,
                                                 IF(t.isAutoProduced = 1 OR s.isAutoProduced = 1, 1, 0) AS isAutoProduced,
                                                 COALESCE(bs.price, bt.price, bt_f.price, bsm.price, bsm_f.price, p.price) AS price_per_item,
                                                 CASE 
                                                    WHEN b.tht_id IS NOT NULL AND bt.id IS NULL THEN 1
                                                    WHEN b.smd_id IS NOT NULL AND bsm.id IS NULL THEN 1
                                                    WHEN b.sku_id IS NOT NULL AND bs.id IS NULL THEN 1
                                                    ELSE 0
                                                 END AS missing_default
                                             FROM 
                                                 bom__flat AS b
                                             LEFT JOIN 
                                                 list__tht AS t ON b.tht_id = t.id
                                             LEFT JOIN
                                                 bom__tht AS bt ON t.default_bom_id = bt.id
                                             LEFT JOIN (
                                                 SELECT tht_id, price FROM bom__tht b1 
                                                 WHERE isActive = 1 AND id = (SELECT MIN(id) FROM bom__tht b2 WHERE b1.tht_id = b2.tht_id AND b2.isActive = 1)
                                             ) AS bt_f ON b.tht_id = bt_f.tht_id
                                             LEFT JOIN 
                                                 list__sku AS s ON b.sku_id = s.id
                                             LEFT JOIN
                                                 bom__sku AS bs ON s.id = bs.sku_id AND bs.isActive = 1
                                             LEFT JOIN 
                                                 list__smd AS sm ON b.smd_id = sm.id
                                             LEFT JOIN
                                                 bom__smd AS bsm ON sm.default_bom_id = bsm.id
                                             LEFT JOIN (
                                                 SELECT smd_id, price FROM bom__smd b1 
                                                 WHERE isActive = 1 AND id = (SELECT MIN(id) FROM bom__smd b2 WHERE b1.smd_id = b2.smd_id AND b2.isActive = 1)
                                             ) AS bsm_f ON b.smd_id = bsm_f.smd_id
                                             LEFT JOIN 
                                                 list__parts AS p ON b.parts_id = p.id
                                             WHERE 
                                                 b.bom_{$deviceType}_id = '{$id}'
                                             ");

        $result = array();
        foreach($components as $component){
            $rowId = $component['id'];
            $type = "sku";
            $device_id = $component['sku_id'];
            if(!empty($component['tht_id'])){
                $type = "tht";
                $device_id = $component['tht_id'];
            }
            else if(!empty($component['smd_id'])){
                $type = "smd";
                $device_id = $component['smd_id'];
            }
            else if(!empty($component['parts_id'])){
                $type = "parts";
                $device_id = $component['parts_id'];
            }
            $pricePerItem = (float)$component["price_per_item"];
            $totalPrice = $component["qty"] * $pricePerItem;
            $result[] = [
                "rowId" => $rowId, 
                "type" => $type, 
                "componentId" => $device_id, 
                "quantity" => $component["qty"]+0, 
                "autoProduce" => $component["isAutoProduced"],
                "pricePerItem" => $pricePerItem,
                "totalPrice" => $totalPrice,
                "missing_default" => $component['missing_default']
            ];
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
