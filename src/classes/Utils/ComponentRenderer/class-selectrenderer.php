<?php
namespace Atte\Utils\ComponentRenderer;  

use Atte\DB\MsaDB;

class SelectRenderer {
    private $MsaDB;

    public function __construct(MsaDB $MsaDB){
        $this -> MsaDB = $MsaDB;
    }

    public function getSKUBOMValuesForSelect($isLeftJoin = false){
        $MsaDB = $this -> MsaDB;
        $result = [];
        $leftJoinStatement = $isLeftJoin ? " LEFT " : "";
        $possibleVer = $MsaDB -> query("SELECT
                                                l.id,
                                                b.id,
                                                l.name,
                                                l.description,
                                                b.version
                                            FROM list__sku l
                                                     {$leftJoinStatement} JOIN bom__sku b
                                                               ON l.id = b.sku_id AND b.isActive = 1
                                            WHERE l.isActive = 1
                                            ORDER BY l.id, b.version ASC;");
        foreach($possibleVer as $row){
            list($id, $bomId, $name, $description, $version) = $row;
            if(!isset($result[$id])) {
                $result[$id] = [
                    $name, 
                    $description, 
                    "versions" => [],
                    "bomIds" => []
                ];
            }
            $result[$id]["versions"][] = $version;
            $result[$id]["bomIds"][] = $bomId;
        }
        return $result;
    }

    public function getTHTBOMValuesForSelect($isLeftJoin = false){
        $MsaDB = $this -> MsaDB;
        $result = [];
        $leftJoinStatement = $isLeftJoin ? " LEFT " : "";
        $possibleVer = $MsaDB -> query("SELECT
                                                l.id,
                                                b.id as bom_id,
                                                l.name,
                                                l.description,
                                                b.version,
                                                l.circle_checked,
                                                l.triangle_checked,
                                                l.square_checked
                                            FROM
                                                list__tht l
                                                    {$leftJoinStatement} JOIN
                                                bom__tht b
                                                ON l.id = b.tht_id
                                                    AND b.isActive = 1
                                            WHERE
                                                l.isActive = 1
                                            ORDER BY
                                                l.id,
                                                b.version ASC;
                                            ");
        foreach($possibleVer as $row){
            list($id, $bomId, $name, $description, $version, $circleMark, $triangleMark, $squareMark) = $row;
            if(!isset($result[$id])) {
                $result[$id] = [
                    $name,
                    $description,
                    "versions" => [],
                    "marking" => [$circleMark, $triangleMark, $squareMark],
                    "bomIds" => []
                ];
            }
            $result[$id]["versions"][] = $version;
            $result[$id]["bomIds"][] = $bomId;
        }
        return $result;
    }

    public function getSMDBOMValuesForSelect(){
        $MsaDB = $this -> MsaDB;
        $result = [];
        $list__laminate = $MsaDB -> readIdName('list__laminate', 'id', 'name', 'WHERE isActive = 1');
        $possibleLamAndVer = $MsaDB -> query("SELECT 
                                                l.id as device_id, 
                                                l.name, 
                                                l.description, 
                                                b.laminate_id, 
                                                b.version,
                                                b.id as bom_id
                                              FROM `list__smd` l 
                                              JOIN bom__smd b 
                                              ON l.id = b.smd_id 
                                              WHERE l.isActive = 1 
                                              AND b.isActive = 1
                                              ORDER BY l.id, version ASC;");
        foreach($possibleLamAndVer as $row){
            $id = $row['device_id'];
            $name = $row['name'];
            $description = $row['description'];
            $laminate_id = $row['laminate_id'];
            $version = $row['version'];
            $bom_id = $row['bom_id'];

            if(!isset($result[$id]))
                $result[$id] = [$name, $description, "laminate_id" => []];
            if(!isset($result[$id]["laminate_id"][$laminate_id])) 
                $result[$id]["laminate_id"][$laminate_id] = [$list__laminate[$laminate_id], "versions" => [], "bomIds" => []];
            $result[$id]["laminate_id"][$laminate_id]["versions"][] = $version;
            $result[$id]["laminate_id"][$laminate_id]["bomIds"][] = $bom_id;
        }
        return $result;
    }


    public function renderPartsSelect($additionalClause = "ORDER BY id ASC", ?array $used__parts = null) {
        $MsaDB = $this -> MsaDB;
        $list__parts = $MsaDB -> readIdName('list__parts', 'id', 'name', $additionalClause);
        $list__parts_desc = $MsaDB -> readIdName('list__parts', 'id', 'description', $additionalClause);
        $used__parts = is_null($used__parts) ? array_keys($list__parts) : $used__parts;
        foreach($used__parts as $id) {
            $name = $list__parts[$id];
            $description = $list__parts_desc[$id];
            echo '<option data-subtext="'.$description.'"
            data-tokens="'.$name.' '.$description.'" 
            value="'.$id.'">'.$name.'</option>';
        } 
    }


    public function renderSMDSelect($additionalClause = "ORDER BY id ASC", ?array $used__smd = null) {
        $MsaDB = $this -> MsaDB;
        $list__smd = $MsaDB -> readIdName('list__smd','id', 'name', $additionalClause);
        $list__smd_desc = $MsaDB -> readIdName('list__smd', 'id', 'description', $additionalClause);
        $used__smd = is_null($used__smd) ? array_keys($list__smd) : $used__smd;
        foreach($used__smd as $id) {
            $name = $list__smd[$id];
            $description = $list__smd_desc[$id];
            echo '<option data-subtext="'.$description.'"
            data-tokens="'.$name.' '.$description.'" 
            value="'.$id.'">'.$name.'</option>';
        } 
    }

    public function renderTHTSelect($additionalClause = "ORDER BY id ASC", ?array $used__tht = null) {
        $MsaDB = $this -> MsaDB;
        $list__tht = $MsaDB -> readIdName('list__tht', 'id', 'name', $additionalClause);
        $list__tht_desc = $MsaDB -> readIdName('list__tht', 'id', 'description', $additionalClause);
        $used__tht = is_null($used__tht) ? array_keys($list__tht) : $used__tht;
        foreach($used__tht as $id) {
            $name = $list__tht[$id];
            $description = $list__tht_desc[$id];
            echo '<option data-subtext="'.$description.'"
            data-tokens="'.$name.' '.$description.'" 
            value="'.$id.'">'.$name.'</option>';
        } 
    }

    public function renderSKUSelect($additionalClause = "ORDER BY id ASC", ?array $used__sku = null) {
        $MsaDB = $this -> MsaDB;
        $list__sku = $MsaDB -> readIdName('list__sku', 'id', 'name', $additionalClause);
        $list__sku_desc = $MsaDB -> readIdName('list__sku', 'id', 'description', $additionalClause);
        $used__sku = is_null($used__sku) ? array_keys($list__sku) : $used__sku;
        foreach($used__sku as $id) {
            $name = $list__sku[$id];
            $description = $list__sku_desc[$id];
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

    public function renderTHTBOMSelect(?array $used__tht = null, $isLeftJoin = false) {
        $values = $this -> getTHTBOMValuesForSelect($isLeftJoin);
        $used__tht = is_null($used__tht) ? array_keys($values) : $used__tht;
        foreach($used__tht as $id) {
            if(!isset($values[$id])) continue;
            $row = $values[$id];
            list($name, $description) = $row;
            $versions = $row["versions"];
            $marking = $row["marking"];
            $bomIds = $row["bomIds"];
            $jsonVersions = json_encode($versions, JSON_FORCE_OBJECT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
            $jsonMarking = json_encode($marking, JSON_FORCE_OBJECT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
            $jsonBomIds = json_encode($bomIds, JSON_FORCE_OBJECT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
            echo "<option data-subtext='$description'
            data-tokens='$name $description' 
            value='$id' data-jsonVersions = '$jsonVersions'
            data-jsonMarking='$jsonMarking'
            data-bomIds='$jsonBomIds'>
            $name</option>";
        } 
    }

    public function renderSKUBOMSelect(?array $used__sku = null, $isLeftJoin = false) {
        $values = $this -> getSKUBOMValuesForSelect($isLeftJoin);
        $used__sku = is_null($used__sku) ? array_keys($values) : $used__sku;
        foreach($used__sku as $id) {
            if(!isset($values[$id])) continue;
            $row = $values[$id];
            list($name, $description) = $row;
            $versions = $row["versions"];
            $bomIds = $row["bomIds"];
            $jsonVersions = json_encode($versions, JSON_FORCE_OBJECT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
            $jsonBomIds = json_encode($bomIds, JSON_FORCE_OBJECT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
            echo "<option data-subtext='$description'
            data-tokens='$name $description' data-bomIds='$jsonBomIds' 
            value='$id' data-jsonVersions = '$jsonVersions'>
            $name</option>";
        } 
    }

    public function renderUserSelect() {
        $MsaDB = $this -> MsaDB;
        $users_name = $MsaDB -> readIdName('user', 'user_id', 'name');
        $users_surname = $MsaDB -> readIdName('user', 'user_id', 'surname');    
        foreach($users_name as $id => $name) {
            $surname = $users_surname[$id];
            echo "<option value=\"$id\">".$name." ".$surname."</option>";
        } 
    }

    /**
    * Render select options from a simple array
    * Key is the value of an option
    * Corresponding value is the text of an option.
    * @return void Returns nothing, renders options
    */
    public function renderArraySelect(array $array) {
        foreach($array as $id => $value) {
            echo "<option value='$id'>$value</option>";
        } 
    }

    /**
    * Render select options from a simple array
    * Key is the value of an option
    * Second array is for subtext
    * Second array needs to be the same length as initial array.
    * Second array needs to have the same keys as initial array.
    * Corresponding value is the text of an option.
    * @return void Returns nothing, renders options
    */
    public function renderArraySelectWithSubtext(array $array, array $subText) {
        if(count($array) != count($subText)) throw new \Exception("Arrays are not the same size");
        if(array_keys($array) != array_keys($subText)) throw new \Exception("Arrays have different keys.");
        foreach($array as $id => $value) {
            echo "<option data-subtext='{$subText[$id]}' 
                    data-tokens='{$value} {$subText[$id]}'
                    value='$id'>$value</option>";
        } 
    }
}
