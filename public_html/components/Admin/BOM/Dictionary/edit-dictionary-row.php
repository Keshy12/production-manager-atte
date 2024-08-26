<?php
use Atte\DB\MsaDB;

$MsaDB = MsaDB::getInstance();

$dictionaryType = $_POST['dictionaryType'];
$rowId = $_POST['rowId'];
$newRowValues = $_POST['newRowValues'];

switch($dictionaryType)
{
    case 'ref__valuepackage':
        $valuePackage = $newRowValues[0];
        $valuesToInsert = [
            'ValuePackage' => $valuePackage, 
            'parts_id' => null,
            'tht_id' => null
        ];
        $componentType = $newRowValues[1];
        $componentId = $newRowValues[2];

        $valuesToInsert[$componentType.'_id'] = $componentId;
        break;
    case 'ref__package_exclude':
        $packageToExclude = $newRowValues[0];
        $valuesToInsert = [
            'name' => $packageToExclude
        ];
        break;

}

$wasSuccessful = $MsaDB -> update($dictionaryType, $valuesToInsert, 'id', $rowId);


echo json_encode($wasSuccessful);


        