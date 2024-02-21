<?php
namespace Atte\Utils\ComponentRenderer;  

use Atte\DB\MsaDB;

class SelectRenderer {
    private $MsaDB;

    public function __construct(MsaDB $MsaDB){
        $this -> MsaDB = $MsaDB;
    }

    public function getSMDBOMValuesForSelect(){
        $MsaDB = $this -> MsaDB;
        $result = [];
        $list__laminate = $MsaDB -> readIdName('list__laminate', 'id', 'name', 'WHERE isActive = 1');
        $possibleLamAndVer = $MsaDB -> query("SELECT 
                                                l.id, 
                                                l.name, 
                                                l.description, 
                                                b.laminate_id, 
                                                b.version 
                                              FROM `list__smd` l 
                                              JOIN bom__smd b 
                                              ON l.id = b.smd_id 
                                              WHERE l.isActive = 1 
                                              AND b.isActive = 1
                                              ORDER BY version ASC;");
        foreach($possibleLamAndVer as $row){
            list($id, $name, $description, $laminate_id, $version) = $row;
            if(!isset($result[$id]))
                $result[$id] = [$name, $description, "laminate_id" => []];
            if(!isset($result[$id]["laminate_id"][$laminate_id])) 
                $result[$id]["laminate_id"][$laminate_id] = [$list__laminate[$laminate_id], "versions" => []];
            $result[$id]["laminate_id"][$laminate_id]["versions"][] = $version;
        }
        return $result;
    }

    public function getTHTBOMValuesForSelect(){
        $MsaDB = $this -> MsaDB;
        $result = [];
        $possibleVer = $MsaDB -> query("SELECT 
                                                l.id, 
                                                l.name, 
                                                l.description, 
                                                b.version,
                                                l.circle_checked,
                                                l.triangle_checked,
                                                l.square_checked
                                              FROM `list__tht` l 
                                              JOIN bom__tht b 
                                              ON l.id = b.tht_id 
                                              WHERE l.isActive = 1 
                                              AND b.isActive = 1
                                              ORDER BY version ASC;");
        foreach($possibleVer as $row){
            list($id, $name, $description, $version, $circleMark, $triangleMark, $squareMark) = $row;
            if(!isset($result[$id])) {
                $result[$id] = [
                    $name, 
                    $description, 
                    "versions" => [], 
                    "marking" => [$circleMark, $triangleMark, $squareMark]
                ];
            }
            $result[$id]["versions"][] = $version;
        }
        return $result;
    }

    public function renderSMDSelect(?array $used__smd = null) {
        $MsaDB = $this -> MsaDB;
        $list__smd = $MsaDB -> readIdName('list__smd','id', 'name', 'WHERE isActive = 1');
        $list__smd_desc = $MsaDB -> readIdName('list__smd', 'id', 'description', 'WHERE isActive = 1');
        $used__smd = is_null($used__smd) ? array_keys($list__smd) : $used__smd;
        foreach($used__smd as $id) {
            $name = $list__smd[$id];
            $description = $list__smd_desc[$id];
            echo '<option data-subtext="'.$description.'"
            data-tokens="'.$name.' '.$description.'" 
            value="'.$id.'">'.$name.'</option>';
        } 
    }

    public function renderTHTSelect(?array $used__tht = null) {
        $MsaDB = $this -> MsaDB;
        $list__tht = $MsaDB -> readIdName('list__tht', 'id', 'name', 'WHERE isActive = 1');
        $list__tht_desc = $MsaDB -> readIdName('list__tht', 'id', 'description', 'WHERE isActive = 1');
        $used__tht = is_null($used__tht) ? array_keys($list__tht) : $used__tht;
        foreach($used__tht as $id) {
            $name = $list__tht[$id];
            $description = $list__tht_desc[$id];
            echo '<option data-subtext="'.$description.'"
            data-tokens="'.$name.' '.$description.'" 
            value="'.$id.'">'.$name.'</option>';
        } 
    }

    public function renderSMDBOMSelect(?array $used__smd = null) {
        $values = $this -> getSMDBOMValuesForSelect();
        $used__smd = is_null($used__smd) ? array_keys($values) : $used__smd;
        foreach($used__smd as $id) {
            if(!isset($values[$id])) continue;
            $row = $values[$id];
            list($name, $description) = $row;
            $laminates = $row["laminate_id"];
            $jsonLaminates = json_encode($laminates, JSON_FORCE_OBJECT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
            echo "<option data-subtext='$description'
            data-tokens='$name $description' 
            value='$id' data-jsonLaminates = '$jsonLaminates'
            >$name</option>";
        } 
    }

    public function renderTHTBOMSelect(?array $used__tht = null) {
        $values = $this -> getTHTBOMValuesForSelect();
        $used__tht = is_null($used__tht) ? array_keys($values) : $used__tht;
        foreach($used__tht as $id) {
            if(!isset($values[$id])) continue;
            $row = $values[$id];
            list($name, $description) = $row;
            $versions = $row["versions"];
            $marking = $row["marking"];
            $jsonVersions = json_encode($versions, JSON_FORCE_OBJECT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
            $jsonMarking = json_encode($marking, JSON_FORCE_OBJECT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
            echo "<option data-subtext='$description'
            data-tokens='$name $description' 
            value='$id' data-jsonVersions = '$jsonVersions'
            data-jsonMarking='$jsonMarking'>
            $name</option>";
        } 
    }
}
