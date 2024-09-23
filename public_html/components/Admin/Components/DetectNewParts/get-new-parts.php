<?php
use Atte\DB\MsaDB;
use Atte\Api\GoogleSheets;

$MsaDB = MsaDB::getInstance();

$googleSheets = new GoogleSheets();

$ref_mag_parts_sheet = getRefMagParts($googleSheets);
$list__parts = $MsaDB -> readIdName('list__parts');

$newParts = array_filter($ref_mag_parts_sheet, function($ref_mag_part) use ($list__parts) {
    $id = (int)$ref_mag_part[0];
    return !array_key_exists($id, $list__parts);
});

echo json_encode($newParts
            , JSON_FORCE_OBJECT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);

function getRefMagParts($googleSheets){
    $result = $googleSheets -> readSheet('1OowYceg8hWtuCmnqPiqCyg5N3rVaAngEvmnGRhjeOew', 'ref_mag_parts', 'H:M');
    // Remove header row
    unset($result[0]);
    array_walk($result, function(&$row){
        $row[0] = (int)$row[0];
    });
    return $result;
}