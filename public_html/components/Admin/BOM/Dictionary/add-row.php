<?php
use Atte\DB\MsaDB;

$MsaDB = MsaDB::getInstance();

$dictionaryType = $_POST['dictionaryType'];
$_valuesToInsert = $_POST['newRowValues'];

switch($dictionaryType)
{
    case 'ref__valuepackage':
        $valuePackage = $_valuesToInsert[0];
        $valuesToInsert = [
            'ValuePackage' => $valuePackage, 
            'parts_id' => null,
            'tht_id' => null
        ];
        $componentType = $_valuesToInsert[1];
        $componentId = $_valuesToInsert[2];

        $valuesToInsert[$componentType.'_id'] = $componentId;
        break;
    case 'ref__package_exclude':
        $packageToExclude = $_valuesToInsert[0];
        $valuesToInsert = [
            'name' => $packageToExclude
        ];
        break;

}
$columnsToInsert = array_keys($valuesToInsert);
$valuesToInsert = array_values($valuesToInsert);

$wasSuccessful = true;
try{
    $insertedId = $MsaDB -> insert($dictionaryType, $columnsToInsert, $valuesToInsert);
}
catch (\Throwable $e)
{
    $wasSuccessful = false;
}

echo json_encode($wasSuccessful);


        