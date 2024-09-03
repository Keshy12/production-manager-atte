<?php
use Atte\DB\MsaDB;

$MsaDB = MsaDB::getInstance();

$dictionaryType = $_POST['dictionaryType'];
$rowId = $_POST['rowId'];
$valuesToInsert = $_POST['newRowValues'];

switch($dictionaryType)
{
    case 'ref__valuepackage':
        $valuePackage = $valuesToInsert[0];
        $valuesToInsert = [
            'ValuePackage' => $valuePackage, 
            'parts_id' => null,
            'tht_id' => null
        ];
        $componentType = $valuesToInsert[1];
        $componentId = $valuesToInsert[2];

        $valuesToInsert[$componentType.'_id'] = $componentId;
        break;
    case 'ref__package_exclude':
        $packageToExclude = $valuesToInsert[0];
        $valuesToInsert = [
            'name' => $packageToExclude
        ];
        break;

}

$wasSuccessful = $MsaDB -> update($dictionaryType, $valuesToInsert, 'id', $rowId);


echo json_encode($wasSuccessful);


        