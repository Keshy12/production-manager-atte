<?php
use Atte\DB\MsaDB;

$MsaDB = MsaDB::getInstance();

$dictionaryType = $_POST['dictionaryType'];
$page = $_POST['page'];
$limit = 10;
$offset = ($page-1)*$limit;
$limit++;

$list__tht = $MsaDB -> readIdName('list__tht');
$list__tht_desc = $MsaDB -> readIdName('list__tht', 'id', 'description');
$list__parts = $MsaDB -> readIdName('list__parts');
$list__parts_desc = $MsaDB -> readIdName('list__parts', 'id', 'description');

$searchValue = $_POST['searchValue'];

// Using LIKE BINARY for it to be case-sensitive
$whereClause = 'WHERE '
                .($dictionaryType == 'ref__valuepackage' 
                    ? 'ValuePackage'
                    : 'name')
                .' LIKE BINARY "%'
                .$searchValue
                .'%"';


$result = $MsaDB -> query("SELECT * FROM {$dictionaryType} {$whereClause} ORDER BY id DESC LIMIT {$limit} OFFSET {$offset}", 
                            PDO::FETCH_ASSOC);

$nextPageAvailable = false;

if(isset($result[10])) {
    $nextPageAvailable = true;
    unset($result[10]);
}

$generateComponentInfo = function(&$row) use ($list__tht, $list__tht_desc, $list__parts, $list__parts_desc) {
    if(!is_null($row['parts_id']))
    {
        $componentId = $row['parts_id'];
        $row['componentId'] = $componentId;
        $row['componentType'] = 'parts';
        $row['componentName'] = $list__parts[$componentId];
        $row['componentDescription'] = $list__parts_desc[$componentId];
        unset($row['parts_id'], $row['tht_id']);
        return $row;
    }
    $componentId = $row['tht_id'];
    $row['componentId'] = $componentId;
    $row['componentType'] = 'tht';
    $row['componentName'] = $list__tht[$componentId];
    $row['componentDescription'] = $list__tht_desc[$componentId];
    unset($row['parts_id'], $row['tht_id']);
    return $row;
};

if($dictionaryType == 'ref__valuepackage') array_walk($result, $generateComponentInfo);

echo json_encode([$result, $nextPageAvailable]
                , JSON_FORCE_OBJECT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);